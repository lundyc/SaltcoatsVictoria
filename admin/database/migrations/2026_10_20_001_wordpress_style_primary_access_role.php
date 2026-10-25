<?php
declare(strict_types=1);

/**
 * Establish one WordPress-style primary access role per person with an account.
 *
 * Member is the default role and deliberately grants no Hub capabilities.
 * Administrator assignments are collapsed to Administrator alone because its
 * bypass already includes every capability. Any other multi-role combination
 * is ambiguous and aborts instead of silently changing access.
 */
return static function (PDO $pdo): void {
    // Read-only preflight must happen before DDL or data changes because MySQL
    // implicitly commits DDL. Administrator + another role is safe to collapse
    // (Administrator already grants everything); other combinations are not.
    $ambiguousBeforeWrite = (int) $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT pat.person_id
            FROM person_access_templates pat
            GROUP BY pat.person_id
            HAVING COUNT(*) > 1
               AND SUM(EXISTS(
                    SELECT 1 FROM access_templates t
                    WHERE t.id = pat.template_id AND t.bypass_all = 1
               )) = 0
        ) role_conflicts'
    )->fetchColumn();
    if ($ambiguousBeforeWrite > 0) {
        throw new RuntimeException("Migration preflight failed: {$ambiguousBeforeWrite} people have multiple non-Administrator roles and require review.");
    }

    $authVersionColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts' AND COLUMN_NAME = 'auth_version'"
    );
    if ((int) $authVersionColumn->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE accounts ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER is_active');
    }

    $pdo->exec(
        "INSERT INTO access_templates (slug, name, bypass_all, is_system, sort_order)
         VALUES ('member', 'Member', 0, 1, 0)
         ON DUPLICATE KEY UPDATE name = VALUES(name), is_system = 1, sort_order = VALUES(sort_order)"
    );

    $pdo->exec(
        "DELETE pat
         FROM person_access_templates pat
         JOIN person_access_templates admin_assignment ON admin_assignment.person_id = pat.person_id
         JOIN access_templates admin_template ON admin_template.id = admin_assignment.template_id
            AND admin_template.bypass_all = 1
         WHERE pat.template_id <> admin_assignment.template_id"
    );

    $ambiguous = (int) $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT person_id
            FROM person_access_templates
            GROUP BY person_id
            HAVING COUNT(*) > 1
        ) role_conflicts'
    )->fetchColumn();
    if ($ambiguous > 0) {
        throw new RuntimeException("Cannot establish one primary access role: {$ambiguous} people still have multiple non-Administrator roles.");
    }

    $pdo->exec(
        "INSERT IGNORE INTO person_access_templates (person_id, template_id)
         SELECT a.person_id, member_template.id
         FROM accounts a
         JOIN access_templates member_template ON member_template.slug = 'member'
         LEFT JOIN person_access_templates pat ON pat.person_id = a.person_id
         WHERE pat.person_id IS NULL"
    );

    $indexStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'person_access_templates'
           AND INDEX_NAME = 'uq_person_primary_access_template'"
    );
    $indexStmt->execute();
    if ((int) $indexStmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE person_access_templates ADD UNIQUE KEY uq_person_primary_access_template (person_id)');
    }

    // Keep the old base role as a compatibility mirror during cutover. It is
    // no longer the authority: Member maps to public, Administrator to admin,
    // and every capability-bearing role maps to staff so old consumers work.
    $roleIds = [];
    foreach ($pdo->query("SELECT id, code FROM roles WHERE code IN ('public', 'staff', 'admin')") as $row) {
        $roleIds[(string) $row['code']] = (int) $row['id'];
    }
    foreach (['public', 'staff', 'admin'] as $requiredRole) {
        if (($roleIds[$requiredRole] ?? 0) <= 0) {
            throw new RuntimeException("Missing compatibility account role: {$requiredRole}");
        }
    }

    $assignments = $pdo->query(
        'SELECT a.id AS account_id, t.slug, t.bypass_all
         FROM accounts a
         JOIN person_access_templates pat ON pat.person_id = a.person_id
         JOIN access_templates t ON t.id = pat.template_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $delete = $pdo->prepare('DELETE FROM account_roles WHERE account_id = :account');
    $insert = $pdo->prepare('INSERT INTO account_roles (account_id, role_id) VALUES (:account, :role)');
    foreach ($assignments as $assignment) {
        $compatibilityRole = (int) $assignment['bypass_all'] === 1
            ? 'admin'
            : ((string) $assignment['slug'] === 'member' ? 'public' : 'staff');
        $delete->execute([':account' => (int) $assignment['account_id']]);
        $insert->execute([
            ':account' => (int) $assignment['account_id'],
            ':role' => $roleIds[$compatibilityRole],
        ]);
    }
};
