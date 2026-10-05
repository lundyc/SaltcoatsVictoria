<?php
declare(strict_types=1);

// WordPress-style roles & capabilities layer. Adds a DB-backed grant matrix
// (which access role has which capability) on top of the existing
// admin/staff/volunteer/public account role and committee-position
// capability systems, without removing either — hub_auth_has_capability()
// now checks this matrix in addition to committee positions, so nothing
// currently working stops working. See
// /root/.claude/projects/-var-www-vhosts-myclubhub-co-uk/memory/admin-access-control-simplification.md
// for the full plan.
//
// - `capabilities` is the fixed, code-defined catalog (like WordPress's
//   capability strings) — one per feature area, not one per page.
// - `access_roles` are named, admin-manageable roles (Treasurer, Football
//   Ops, ...). `bypass_all` is how the Admin role gets everything without
//   needing every capability explicitly ticked (avoids ever locking out
//   admin by forgetting a checkbox when a new capability is added later).
// - `access_role_capabilities` is the actual grant matrix the new
//   Roles & Capabilities screen edits.
// - `person_access_roles` assigns roles to people (many-to-many, like
//   WordPress users can hold more than one role via a plugin).

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS capabilities (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(60) NOT NULL,
        label VARCHAR(190) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_capabilities_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS access_roles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(60) NOT NULL,
        name VARCHAR(120) NOT NULL,
        bypass_all TINYINT(1) NOT NULL DEFAULT 0,
        is_system TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_access_roles_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS access_role_capabilities (
        role_id INT UNSIGNED NOT NULL,
        capability_id INT UNSIGNED NOT NULL,
        granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (role_id, capability_id),
        KEY idx_access_role_capabilities_capability (capability_id),
        CONSTRAINT fk_arc_role FOREIGN KEY (role_id) REFERENCES access_roles(id) ON DELETE CASCADE,
        CONSTRAINT fk_arc_capability FOREIGN KEY (capability_id) REFERENCES capabilities(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS person_access_roles (
        person_id INT UNSIGNED NOT NULL,
        role_id INT UNSIGNED NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (person_id, role_id),
        KEY idx_person_access_roles_role (role_id),
        CONSTRAINT fk_par_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
        CONSTRAINT fk_par_role FOREIGN KEY (role_id) REFERENCES access_roles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $capabilities = [
        'finance' => ['Finance (payments, refunds, sponsorship money, financial reports)', 10],
        'football_ops' => ['Football & team operations (fixtures, players, results, football reports)', 20],
        'tickets_ops' => ['Ticketing & gate operations (incl. POS)', 30],
        'content_social' => ['Content & social media tools', 40],
        'secretary_ops' => ['Secretary & club administration (discipline, registrations, correspondence, governance)', 50],
        'shop' => ['Club shop (orders, products, storefront settings)', 60],
        'admin_settings' => ['Site administration (people, accounts, roles, global settings)', 70],
    ];
    $insertCapability = $pdo->prepare('INSERT INTO capabilities (slug, label, sort_order) VALUES (:slug, :label, :sort_order)
        ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order)');
    foreach ($capabilities as $slug => [$label, $sort]) {
        $insertCapability->execute([':slug' => $slug, ':label' => $label, ':sort_order' => $sort]);
    }

    $roles = [
        'admin' => ['Administrator', 1, 1, 10],
        'staff' => ['Staff', 0, 1, 20],
        'volunteer' => ['Volunteer', 0, 1, 30],
        'treasurer' => ['Treasurer', 0, 0, 40],
        'football_ops' => ['Football Ops', 0, 0, 50],
        'content_social' => ['Content & Social', 0, 0, 60],
        'secretary_ops' => ['Secretary Ops', 0, 0, 70],
    ];
    $insertRole = $pdo->prepare('INSERT INTO access_roles (slug, name, bypass_all, is_system, sort_order) VALUES (:slug, :name, :bypass_all, :is_system, :sort_order)
        ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order)');
    foreach ($roles as $slug => [$name, $bypassAll, $isSystem, $sort]) {
        $insertRole->execute([
            ':slug' => $slug,
            ':name' => $name,
            ':bypass_all' => $bypassAll,
            ':is_system' => $isSystem,
            ':sort_order' => $sort,
        ]);
    }

    // Default grants — matches today's real access exactly, so cutover
    // doesn't change what anyone can see. tickets_ops and shop and
    // admin_settings are intentionally left ungranted here: only the
    // Administrator role (bypass_all) reaches them until someone ticks a
    // box in the new Roles & Capabilities screen.
    $defaultGrants = [
        'treasurer' => ['finance'],
        'football_ops' => ['football_ops'],
        'content_social' => ['content_social'],
        'secretary_ops' => ['secretary_ops'],
    ];
    $grant = $pdo->prepare('INSERT IGNORE INTO access_role_capabilities (role_id, capability_id)
        SELECT ar.id, c.id FROM access_roles ar, capabilities c
        WHERE ar.slug = :role_slug AND c.slug = :capability_slug');
    foreach ($defaultGrants as $roleSlug => $capabilitySlugs) {
        foreach ($capabilitySlugs as $capabilitySlug) {
            $grant->execute([':role_slug' => $roleSlug, ':capability_slug' => $capabilitySlug]);
        }
    }
};
