<?php
declare(strict_types=1);

// The old permission layer (permissions / role_permissions) had exactly one live
// consumer: scan/index.php required 'tickets.scan', which every account with a
// staff or volunteer ROLE held by default. Make that an ordinary capability so
// the old layer can be retired without changing who can scan the gate:
//
//   - new capability tickets_scan
//   - Staff and Volunteer templates get it (as the roles did)
//   - people who could scan only because of a staff/volunteer account role, and
//     are not already covered by a template that grants it, get it as an
//     individual extra with an explanatory note — visible and removable on
//     their Admin access tab, instead of an invisible baseline.
//
// Idempotent.

return static function (PDO $pdo): void {
    $pdo->exec("INSERT IGNORE INTO capabilities (slug, label, sort_order) VALUES
        ('tickets_scan', 'Gate scanning — check tickets in at the gate', 84)");

    $pdo->exec("INSERT IGNORE INTO access_template_capabilities (template_id, capability_id)
        SELECT t.id, c.id FROM access_templates t JOIN capabilities c ON c.slug = 'tickets_scan'
        WHERE t.slug IN ('staff', 'volunteer')");

    // Accounts holding a staff/volunteer role (not admin: admins bypass anyway).
    $pdo->exec("INSERT IGNORE INTO person_access_overrides (person_id, capability_id, effect, note)
        SELECT DISTINCT a.person_id, c.id, 'grant', 'Kept from the old staff/volunteer account baseline (gate scanning)'
        FROM accounts a
        JOIN account_roles ar ON ar.account_id = a.id
        JOIN roles r ON r.id = ar.role_id AND r.code IN ('staff', 'volunteer')
        JOIN capabilities c ON c.slug = 'tickets_scan'
        WHERE a.is_active = 1");
};
