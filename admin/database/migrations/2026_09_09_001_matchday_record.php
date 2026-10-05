<?php

declare(strict_types=1);

/**
 * Matchday record — normalised store for both teams' starting XI, subs,
 * substitutions, periods, formations and match events (goals, cards, ...),
 * keyed to a match_fixtures row. Modelled on the old "veo" analytics app.
 *
 * Tables are defined in lib/matchday_record.php (matchday_record_ensure_schema),
 * which the pages also call on first use, so this migration is just the formal
 * record of when it landed. The legacy stores (match_fixtures.starting11_*_json
 * + admin/data/matches.json) are kept as a derived projection by that lib.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/matchday_record.php';

    matchday_record_ensure_schema($pdo);
};
