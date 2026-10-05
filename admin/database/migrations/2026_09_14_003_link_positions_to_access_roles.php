<?php
declare(strict_types=1);

// Ties "Roles & Positions" (committee titles with season-dated history) to
// "Roles & Capabilities" (the WordPress-style grant matrix) rather than
// merging them outright — positions keep their own job (who held what title
// when), but stop carrying their own separate capabilities list. Instead
// each position now points at one access role via a new access_role_id
// column, and holding that position grants whatever that role's capability
// grants are, so there's a single place (the grid) where "what does X grant"
// is actually defined.
//
// Every existing position keeps exactly the access it had before this
// migration:
//   - Treasurer position  -> the existing Treasurer access role
//   - Secretary position  -> the existing Secretary Ops access role
//   - Assistant Manager / Coach / Goalkeeping Coach / Manager positions
//                          -> the existing Football Ops access role
//     (all four positions only ever had the single old football_ops
//     capability, which is exactly what Football Ops already grants post
//     capability-split, so this is a like-for-like link, not a policy call)
//   - Committee Member / Volunteer positions -> no access role (they never
//     granted any capability)
//   - Chairman / Vice Chairman -> a new "Chairman" access role, granted the
//     same capabilities their old ["finance","football_ops","content_social"]
//     translates to post-split (finance, matchday, club_setup,
//     content_social, publishing)
//   - Social Media Admin -> a new "Social Media Admin" access role, granted
//     the same content_social + secretary_ops it always had

return static function (PDO $pdo): void {
    $pdo->exec("ALTER TABLE hub_positions ADD COLUMN IF NOT EXISTS access_role_id INT UNSIGNED NULL AFTER department");

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM hub_positions') as $row) {
        $columns[(string) $row['Field']] = true;
    }
    if (!isset($columns['access_role_id'])) {
        // MySQL < 8.0.29 doesn't support ADD COLUMN IF NOT EXISTS.
        $pdo->exec("ALTER TABLE hub_positions ADD COLUMN access_role_id INT UNSIGNED NULL AFTER department");
    }

    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM hub_positions') as $row) {
        $indexes[(string) $row['Key_name']] = true;
    }
    if (!isset($indexes['fk_hub_positions_access_role'])) {
        $pdo->exec('ALTER TABLE hub_positions
            ADD CONSTRAINT fk_hub_positions_access_role FOREIGN KEY (access_role_id) REFERENCES access_roles(id) ON DELETE SET NULL');
    }

    // New roles for the two position groups with no existing 1:1 match.
    $newRoles = [
        'chairman' => ['Chairman', 45],
        'social_media_admin' => ['Social Media Admin', 115],
    ];
    $insertRole = $pdo->prepare('INSERT INTO access_roles (slug, name, bypass_all, is_system, sort_order) VALUES (:slug, :name, 0, 0, :sort_order)
        ON DUPLICATE KEY UPDATE name = VALUES(name)');
    foreach ($newRoles as $slug => [$name, $sort]) {
        $insertRole->execute([':slug' => $slug, ':name' => $name, ':sort_order' => $sort]);
    }

    $grant = $pdo->prepare('INSERT IGNORE INTO access_role_capabilities (role_id, capability_id)
        SELECT ar.id, c.id FROM access_roles ar, capabilities c
        WHERE ar.slug = :role_slug AND c.slug = :capability_slug');
    $newRoleGrants = [
        'chairman' => ['finance', 'matchday', 'club_setup', 'content_social', 'publishing'],
        'social_media_admin' => ['content_social', 'secretary_ops'],
    ];
    foreach ($newRoleGrants as $roleSlug => $capabilitySlugs) {
        foreach ($capabilitySlugs as $capabilitySlug) {
            $grant->execute([':role_slug' => $roleSlug, ':capability_slug' => $capabilitySlug]);
        }
    }

    $link = $pdo->prepare('UPDATE hub_positions SET access_role_id = (SELECT id FROM access_roles WHERE slug = :role_slug) WHERE name = :position_name');
    $positionToRole = [
        'Treasurer' => 'treasurer',
        'Secretary' => 'secretary_ops',
        'Assistant Manager' => 'football_ops',
        'Coach' => 'football_ops',
        'Goalkeeping Coach' => 'football_ops',
        'Manager' => 'football_ops',
        'Chairman' => 'chairman',
        'Vice Chairman' => 'chairman',
        'Social Media Admin' => 'social_media_admin',
    ];
    foreach ($positionToRole as $positionName => $roleSlug) {
        $link->execute([':role_slug' => $roleSlug, ':position_name' => $positionName]);
    }
};
