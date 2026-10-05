<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/season_passes.php';
    require_once __DIR__ . '/../../lib/season_ticket_attendance.php';

    ensureSeasonPassSchema($pdo);
    migrateSeasonTicketOrdersToPasses($pdo);
    ensureSeasonTicketAttendanceSchema($pdo);
};
