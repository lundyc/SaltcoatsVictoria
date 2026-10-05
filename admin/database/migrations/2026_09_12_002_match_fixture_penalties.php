<?php

declare(strict_types=1);

/**
 * home_penalties/away_penalties on match_fixtures — a cup tie decided by a
 * penalty shootout after a draw. NULL for every league game. Schema lives in
 * ensureMatchSchema() in lib/match_sponsorship.php.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/match_sponsorship.php';
    ensureMatchSchema($pdo);
};
