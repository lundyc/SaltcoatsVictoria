<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/match_tickets.php';

    ensureMatchTicketSchema($pdo);
};
