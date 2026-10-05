<?php
declare(strict_types=1);

// Access-control refactor, phase 9. All code now uses the new table and column
// names (club_roles, person_club_roles, access_templates, ...), so the
// same-named compatibility views created by migration 2026_09_25_001 are no
// longer needed. Idempotent; the views can be recreated by re-running the view
// section of migration 001 if ever required.

return static function (PDO $pdo): void {
    foreach (['hub_positions', 'person_positions', 'access_roles', 'access_role_capabilities', 'person_access_roles'] as $name) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n AND TABLE_TYPE = 'VIEW'");
        $stmt->execute([':n' => $name]);
        if ((int) $stmt->fetchColumn() > 0) {
            $pdo->exec("DROP VIEW `{$name}`");
        }
    }
};
