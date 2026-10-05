<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/permissions.php';
    require_once __DIR__ . '/../../lib/admissions.php';
    require_once __DIR__ . '/../../lib/season_pass_rules.php';
    require_once __DIR__ . '/../../lib/pos.php';

    ensurePermissionSchema($pdo);
    ensureAdmissionsSchema($pdo);
    ensureSeasonPassRuleSchema($pdo);
    pos_ensure_schema($pdo);
};
