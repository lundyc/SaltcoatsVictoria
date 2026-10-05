<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/match_tickets.php';
    require_once __DIR__ . '/../../lib/season_ticket_attendance.php';

    ensureMatchTicketSchema($pdo);
    migrateMatchTicketsToEntitlements($pdo);
    ensureSeasonTicketAttendanceSchema($pdo);
};
