<?php
declare(strict_types=1);

// Access-control refactor, phase 3 — vocabulary + schema.
//
//   Club roles (labels, grant nothing)        Access templates (what a person may do)
//   hub_positions      -> club_roles          access_roles              -> access_templates
//   person_positions   -> person_club_roles   access_role_capabilities  -> access_template_capabilities
//                                             person_access_roles       -> person_access_templates
//
// The old names stay behind as same-shape, single-table (therefore updatable)
// views, with the old column names aliased, so every existing query keeps
// working unchanged until the code is migrated; a later phase drops the views.
//
// Also adds person_access_overrides (per-person extra grants / denials, with
// an optional expiry) and splits two money capabilities: finance ->
// finance_view + finance_refunds, and tickets_refund_comp alongside
// tickets_ops. Everyone who holds finance / tickets_ops today is given the new
// slugs too, so nobody gains or loses anything at this step; the old slugs
// stay until the call sites move over.
//
// Idempotent: safe to re-run after a partial failure.

return static function (PDO $pdo): void {
    $exists = static function (string $name) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n');
        $stmt->execute([':n' => $name]);
        return (int) $stmt->fetchColumn() > 0;
    };
    $isView = static function (string $name) use ($pdo): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n AND TABLE_TYPE = 'VIEW'");
        $stmt->execute([':n' => $name]);
        return (int) $stmt->fetchColumn() > 0;
    };
    $hasColumn = static function (string $table, string $column) use ($pdo): bool {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        $stmt->execute([':t' => $table, ':c' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    };

    // Fail before touching anything if the DB user cannot create views.
    $pdo->exec('CREATE OR REPLACE VIEW zz_access_probe AS SELECT 1 AS ok');
    $pdo->exec('DROP VIEW zz_access_probe');

    // 1. Table renames (only where the old name is still a real table).
    $renames = [
        'hub_positions' => 'club_roles',
        'person_positions' => 'person_club_roles',
        'access_roles' => 'access_templates',
        'access_role_capabilities' => 'access_template_capabilities',
        'person_access_roles' => 'person_access_templates',
    ];
    foreach ($renames as $old => $new) {
        if ($exists($old) && !$isView($old) && !$exists($new)) {
            $pdo->exec("RENAME TABLE `{$old}` TO `{$new}`");
        }
    }

    // 2. Column renames on the renamed tables.
    $columnRenames = [
        ['person_club_roles', 'position_id', 'club_role_id'],
        ['person_access_templates', 'role_id', 'template_id'],
        ['access_template_capabilities', 'role_id', 'template_id'],
        ['club_roles', 'access_role_id', 'access_template_id'],
    ];
    foreach ($columnRenames as [$table, $old, $new]) {
        if ($exists($table) && $hasColumn($table, $old) && !$hasColumn($table, $new)) {
            $pdo->exec("ALTER TABLE `{$table}` RENAME COLUMN `{$old}` TO `{$new}`");
        }
    }

    // 3. Compatibility views under the old names, old column names aliased.
    $views = [
        'hub_positions' => ['club_roles', ['access_template_id' => 'access_role_id']],
        'person_positions' => ['person_club_roles', ['club_role_id' => 'position_id']],
        'access_roles' => ['access_templates', []],
        'access_role_capabilities' => ['access_template_capabilities', ['template_id' => 'role_id']],
        'person_access_roles' => ['person_access_templates', ['template_id' => 'role_id']],
    ];
    foreach ($views as $viewName => [$table, $aliases]) {
        if ($exists($viewName) && !$isView($viewName)) {
            continue; // an old-named real table is still there; leave it alone
        }
        $columns = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($table) . '
             ORDER BY ORDINAL_POSITION'
        )->fetchAll(PDO::FETCH_COLUMN);
        $select = [];
        foreach ($columns as $column) {
            $alias = $aliases[$column] ?? $column;
            $select[] = $alias === $column ? "`{$column}`" : "`{$column}` AS `{$alias}`";
        }
        $pdo->exec("CREATE OR REPLACE VIEW `{$viewName}` AS SELECT " . implode(', ', $select) . " FROM `{$table}`");
    }

    // 4. Per-person overrides: an extra grant, or an explicit denial, of one
    //    capability on top of the person's templates. Optional expiry.
    $pdo->exec("CREATE TABLE IF NOT EXISTS person_access_overrides (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id INT UNSIGNED NOT NULL,
        capability_id INT UNSIGNED NOT NULL,
        effect ENUM('grant','deny') NOT NULL DEFAULT 'grant',
        expires_at DATE NULL DEFAULT NULL,
        note VARCHAR(255) NULL DEFAULT NULL,
        created_by_person_id INT UNSIGNED NULL DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_person_access_override (person_id, capability_id),
        KEY idx_person_access_override_capability (capability_id),
        CONSTRAINT fk_pao_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
        CONSTRAINT fk_pao_capability FOREIGN KEY (capability_id) REFERENCES capabilities(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 5. Split capabilities; whoever holds the parent today gets the children.
    $newCapabilities = [
        ['finance_view', 'Finance — view reports and matchday income', 56, 'finance'],
        ['finance_refunds', 'Finance — refunds and Stripe actions', 57, 'finance'],
        ['tickets_refund_comp', 'Ticketing — refunds and complimentary tickets', 86, 'tickets_ops'],
    ];
    $insertCapability = $pdo->prepare('INSERT IGNORE INTO capabilities (slug, label, sort_order) VALUES (:slug, :label, :sort)');
    $grant = $pdo->prepare(
        'INSERT IGNORE INTO access_template_capabilities (template_id, capability_id)
         SELECT atc.template_id, child.id
         FROM access_template_capabilities atc
         JOIN capabilities parent ON parent.id = atc.capability_id AND parent.slug = :parent
         JOIN capabilities child ON child.slug = :child'
    );
    foreach ($newCapabilities as [$slug, $label, $sort, $parent]) {
        $insertCapability->execute([':slug' => $slug, ':label' => $label, ':sort' => $sort]);
        $grant->execute([':parent' => $parent, ':child' => $slug]);
    }
};
