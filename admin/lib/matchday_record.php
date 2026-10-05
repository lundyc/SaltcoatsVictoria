<?php

declare(strict_types=1);

/**
 * Matchday record — the normalised store for what actually happened in a
 * fixture: BOTH teams' starting XI, substitutes, substitutions, periods,
 * formations and match events (goals, cards, ...). Modelled on the old
 * "veo" analytics app (project_1), keyed to a match_fixtures row.
 *
 * This lib is the single source of truth. The pre-existing stores
 * (match_fixtures.starting11_*_json + admin/data/matches.json) are kept as a
 * DERIVED PROJECTION by matchday_record_sync_derived(), so Match Graphics,
 * the event social graphics and the public /match/{id} page keep working
 * unchanged. Every write function calls the projection before returning.
 *
 * Side is 'svfc' | 'opponent' (not home/away) to match the Hub's existing
 * event model; home/away is derived from match_fixtures.is_home only where a
 * graphic needs it (see matchday_record_side_to_venue()).
 *
 * Schema is owned here (matchday_record_ensure_schema), self-healing on first
 * use like lib/matchday_finance.php and lib/news.php; the formal migration is
 * database/migrations/2026_09_09_001_matchday_record.php.
 */

/** matches.json event types that this record now owns (projection replaces them). */
const MATCHDAY_RECORD_OWNED_MJ_TYPES = [
    'goal', 'shot', 'chance', 'corner', 'free_kick', 'penalty', 'off_side',
    'yellow_card', 'red_card', 'substitution', 'mistake', 'good_play', 'highlight',
];

/** matches.json event types left alone (structural markers / manual notes). */
const MATCHDAY_RECORD_KEEP_MJ_TYPES = [
    'kickoff', 'half_time', 'second_half', 'full_time', 'player_of_match', 'note',
];

function matchday_record_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS matchday_lineups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fixture_id INT UNSIGNED NOT NULL,
            side ENUM('svfc','opponent') NOT NULL,
            player_id INT UNSIGNED NULL,
            player_name VARCHAR(160) NOT NULL DEFAULT '',
            shirt_number SMALLINT UNSIGNED NULL,
            position_label VARCHAR(24) NULL,
            is_starting TINYINT(1) NOT NULL DEFAULT 1,
            is_captain TINYINT(1) NOT NULL DEFAULT 0,
            is_trialist TINYINT(1) NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ml_fixture_side (fixture_id, side, is_starting),
            KEY idx_ml_player (player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS matchday_periods (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fixture_id INT UNSIGNED NOT NULL,
            period_key VARCHAR(24) NOT NULL,
            label VARCHAR(48) NOT NULL,
            start_minute SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            end_minute SMALLINT UNSIGNED NULL,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mp_fixture_key (fixture_id, period_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS matchday_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fixture_id INT UNSIGNED NOT NULL,
            period_id BIGINT UNSIGNED NULL,
            minute SMALLINT UNSIGNED NULL,
            minute_extra SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            side ENUM('svfc','opponent','none') NOT NULL DEFAULT 'svfc',
            type VARCHAR(32) NOT NULL,
            player_lineup_id BIGINT UNSIGNED NULL,
            player_name VARCHAR(160) NOT NULL DEFAULT '',
            secondary_player_lineup_id BIGINT UNSIGNED NULL,
            secondary_player_name VARCHAR(160) NOT NULL DEFAULT '',
            own_goal TINYINT(1) NOT NULL DEFAULT 0,
            card_type VARCHAR(16) NOT NULL DEFAULT '',
            participant_type VARCHAR(16) NOT NULL DEFAULT 'player',
            participant_role VARCHAR(80) NOT NULL DEFAULT '',
            outcome VARCHAR(48) NOT NULL DEFAULT '',
            note VARCHAR(500) NOT NULL DEFAULT '',
            sequence INT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_me_fixture (fixture_id, minute, minute_extra, sequence)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS matchday_subs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fixture_id INT UNSIGNED NOT NULL,
            side ENUM('svfc','opponent') NOT NULL,
            minute SMALLINT UNSIGNED NULL,
            minute_extra SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            player_off_lineup_id BIGINT UNSIGNED NULL,
            player_on_lineup_id BIGINT UNSIGNED NULL,
            player_off_name VARCHAR(160) NOT NULL DEFAULT '',
            player_on_name VARCHAR(160) NOT NULL DEFAULT '',
            reason VARCHAR(120) NOT NULL DEFAULT '',
            event_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_ms_fixture (fixture_id, side)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS matchday_formations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fixture_id INT UNSIGNED NOT NULL,
            side ENUM('svfc','opponent') NOT NULL,
            formation_key VARCHAR(24) NOT NULL DEFAULT '',
            layout_json LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mf_fixture_side (fixture_id, side)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS matchday_event_types (
            id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            type_key VARCHAR(32) NOT NULL,
            label VARCHAR(48) NOT NULL,
            category ENUM('goal','card','sub','other') NOT NULL DEFAULT 'other',
            projects_to VARCHAR(24) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            UNIQUE KEY uq_met_key (type_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $count = (int) $pdo->query('SELECT COUNT(*) FROM matchday_event_types')->fetchColumn();
    if ($count === 0) {
        $ins = $pdo->prepare(
            'INSERT INTO matchday_event_types (type_key, label, category, projects_to, sort_order)
             VALUES (:k, :l, :c, :p, :s)'
        );
        $sort = 0;
        foreach (matchday_record_event_type_defaults() as $key => $def) {
            $ins->execute([
                ':k' => $key,
                ':l' => $def['label'],
                ':c' => $def['category'],
                ':p' => $def['projects_to'],
                ':s' => $sort += 10,
            ]);
        }
    }

    // Columns added after the tables shipped — self-heal existing installs.
    try {
        if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'matchday_lineups' AND COLUMN_NAME = 'is_trialist'")->fetchColumn()) {
            $pdo->exec("ALTER TABLE matchday_lineups ADD COLUMN is_trialist TINYINT(1) NOT NULL DEFAULT 0 AFTER is_captain");
        }
    } catch (Throwable $e) {
        // non-fatal: a read on a fresh DB where the CREATE above already added the column
    }
    try {
        if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'matchday_events' AND COLUMN_NAME = 'participant_type'")->fetchColumn()) {
            $pdo->exec("ALTER TABLE matchday_events ADD COLUMN participant_type VARCHAR(16) NOT NULL DEFAULT 'player' AFTER card_type");
        }
        if (!(int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'matchday_events' AND COLUMN_NAME = 'participant_role'")->fetchColumn()) {
            $pdo->exec("ALTER TABLE matchday_events ADD COLUMN participant_role VARCHAR(80) NOT NULL DEFAULT '' AFTER participant_type");
        }
    } catch (Throwable $e) {
        // non-fatal: existing installations can retry the self-healing columns
    }

    $ensured = true;
}

/**
 * The event-type dictionary. Also the source of truth for how each type
 * projects into a matches.json event `type` (must be one the old
 * matches_normalize_events() accepts, or '' to omit from the projection).
 *
 * @return array<string, array{label:string, category:string, projects_to:string}>
 */
function matchday_record_event_type_defaults(): array
{
    return [
        'goal'           => ['label' => 'Goal',                 'category' => 'goal',  'projects_to' => 'goal'],
        'own_goal'       => ['label' => 'Own goal',             'category' => 'goal',  'projects_to' => 'goal'],
        'penalty_scored' => ['label' => 'Penalty scored',       'category' => 'goal',  'projects_to' => 'goal'],
        'penalty_missed' => ['label' => 'Penalty missed',       'category' => 'other', 'projects_to' => 'penalty'],
        'yellow_card'    => ['label' => 'Yellow card',          'category' => 'card',  'projects_to' => 'yellow_card'],
        'second_yellow'  => ['label' => 'Second yellow (→ red)', 'category' => 'card', 'projects_to' => 'red_card'],
        'red_card'       => ['label' => 'Red card',             'category' => 'card',  'projects_to' => 'red_card'],
        'substitution'   => ['label' => 'Substitution',         'category' => 'sub',   'projects_to' => 'substitution'],
        'assist'         => ['label' => 'Assist',               'category' => 'other', 'projects_to' => 'good_play'],
        'shot'           => ['label' => 'Shot',                 'category' => 'other', 'projects_to' => 'shot'],
        'chance'         => ['label' => 'Big chance',           'category' => 'other', 'projects_to' => 'chance'],
        'corner'         => ['label' => 'Corner',               'category' => 'other', 'projects_to' => 'corner'],
        'free_kick'      => ['label' => 'Free kick',            'category' => 'other', 'projects_to' => 'free_kick'],
        'offside'        => ['label' => 'Offside',              'category' => 'other', 'projects_to' => 'off_side'],
        'save'           => ['label' => 'Save',                 'category' => 'other', 'projects_to' => 'good_play'],
        'good_play'      => ['label' => 'Good play',            'category' => 'other', 'projects_to' => 'good_play'],
        'mistake'        => ['label' => 'Mistake',              'category' => 'other', 'projects_to' => 'mistake'],
        'highlight'      => ['label' => 'Highlight',            'category' => 'other', 'projects_to' => 'highlight'],
        'note'           => ['label' => 'Note',                 'category' => 'other', 'projects_to' => 'note'],
    ];
}

/**
 * Active event types, keyed by type_key. Merges the DB rows over the code
 * defaults so a type added in code before the seed runs still resolves.
 *
 * @return array<string, array{type_key:string, label:string, category:string, projects_to:string}>
 */
function matchday_record_event_types(PDO $pdo): array
{
    matchday_record_ensure_schema($pdo);
    $rows = $pdo->query(
        'SELECT type_key, label, category, projects_to FROM matchday_event_types WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byKey = [];
    foreach (matchday_record_event_type_defaults() as $key => $def) {
        $byKey[$key] = ['type_key' => $key] + $def;
    }
    foreach ($rows as $row) {
        $byKey[(string) $row['type_key']] = [
            'type_key' => (string) $row['type_key'],
            'label' => (string) $row['label'],
            'category' => (string) $row['category'],
            'projects_to' => (string) $row['projects_to'],
        ];
    }
    return $byKey;
}

/* -------------------------------------------------------------------------
 * Reads
 * ---------------------------------------------------------------------- */

/** The match_fixtures row this record hangs off, or null. */
function matchday_record_fixture(PDO $pdo, int $fixtureId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT f.id, f.season_id, f.opponent_id, f.is_home, f.status,
                f.match_date, f.kickoff_time,
                f.full_time_home_score, f.full_time_away_score,
                f.half_time_home_score, f.half_time_away_score,
                COALESCE(o.clubname, f.opponent) AS opponent
         FROM match_fixtures f
         LEFT JOIN match_opponents o ON o.id = f.opponent_id
         WHERE f.id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $fixtureId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Lineup rows for a fixture (both sides, or one side), squad order.
 *
 * @return list<array<string,mixed>>
 */
function matchday_lineup_get(PDO $pdo, int $fixtureId, ?string $side = null): array
{
    matchday_record_ensure_schema($pdo);
    $sql = 'SELECT * FROM matchday_lineups WHERE fixture_id = :f';
    $params = [':f' => $fixtureId];
    if ($side !== null) {
        $sql .= ' AND side = :s';
        $params[':s'] = matchday_record_side($side);
    }
    $sql .= ' ORDER BY side ASC, is_starting DESC, sort_order ASC, shirt_number ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function matchday_periods_get(PDO $pdo, int $fixtureId): array
{
    matchday_record_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM matchday_periods WHERE fixture_id = :f ORDER BY sort_order ASC, id ASC');
    $stmt->execute([':f' => $fixtureId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Seed the standard two halves if this fixture has no periods yet. @return list<array<string,mixed>> */
function matchday_periods_ensure(PDO $pdo, int $fixtureId): array
{
    $existing = matchday_periods_get($pdo, $fixtureId);
    if ($existing !== []) {
        return $existing;
    }
    $defaults = [
        ['period_key' => 'first_half',  'label' => 'First half',  'start_minute' => 0,  'end_minute' => 45, 'sort_order' => 10],
        ['period_key' => 'second_half', 'label' => 'Second half', 'start_minute' => 45, 'end_minute' => 90, 'sort_order' => 20],
    ];
    $ins = $pdo->prepare(
        'INSERT INTO matchday_periods (fixture_id, period_key, label, start_minute, end_minute, sort_order)
         VALUES (:f, :k, :l, :sm, :em, :so)'
    );
    foreach ($defaults as $d) {
        $ins->execute([
            ':f' => $fixtureId, ':k' => $d['period_key'], ':l' => $d['label'],
            ':sm' => $d['start_minute'], ':em' => $d['end_minute'], ':so' => $d['sort_order'],
        ]);
    }
    return matchday_periods_get($pdo, $fixtureId);
}

/** @return list<array<string,mixed>> minute-sorted */
function matchday_events_get(PDO $pdo, int $fixtureId): array
{
    matchday_record_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM matchday_events WHERE fixture_id = :f
         ORDER BY (minute IS NULL) ASC, minute ASC, minute_extra ASC, sequence ASC, id ASC'
    );
    $stmt->execute([':f' => $fixtureId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function matchday_subs_get(PDO $pdo, int $fixtureId, ?string $side = null): array
{
    matchday_record_ensure_schema($pdo);
    $sql = 'SELECT * FROM matchday_subs WHERE fixture_id = :f';
    $params = [':f' => $fixtureId];
    if ($side !== null) {
        $sql .= ' AND side = :s';
        $params[':s'] = matchday_record_side($side);
    }
    $sql .= ' ORDER BY (minute IS NULL) ASC, minute ASC, minute_extra ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function matchday_formation_get(PDO $pdo, int $fixtureId, string $side): ?array
{
    matchday_record_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM matchday_formations WHERE fixture_id = :f AND side = :s LIMIT 1');
    $stmt->execute([':f' => $fixtureId, ':s' => matchday_record_side($side)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Everything about a fixture's record in one call.
 *
 * @return array{
 *   fixture: array<string,mixed>|null,
 *   lineups: array{svfc:list<array<string,mixed>>, opponent:list<array<string,mixed>>},
 *   subs: array{svfc:list<array<string,mixed>>, opponent:list<array<string,mixed>>},
 *   events: list<array<string,mixed>>,
 *   periods: list<array<string,mixed>>,
 *   formations: array{svfc:array<string,mixed>|null, opponent:array<string,mixed>|null},
 *   summary: array<string,mixed>
 * }
 */
function matchday_record_load(PDO $pdo, int $fixtureId): array
{
    $lineups = ['svfc' => [], 'opponent' => []];
    foreach (matchday_lineup_get($pdo, $fixtureId) as $row) {
        $lineups[(string) $row['side']][] = $row;
    }
    $subs = ['svfc' => [], 'opponent' => []];
    foreach (matchday_subs_get($pdo, $fixtureId) as $row) {
        $subs[(string) $row['side']][] = $row;
    }

    return [
        'fixture' => matchday_record_fixture($pdo, $fixtureId),
        'lineups' => $lineups,
        'subs' => $subs,
        'events' => matchday_events_get($pdo, $fixtureId),
        'periods' => matchday_periods_get($pdo, $fixtureId),
        'formations' => [
            'svfc' => matchday_formation_get($pdo, $fixtureId, 'svfc'),
            'opponent' => matchday_formation_get($pdo, $fixtureId, 'opponent'),
        ],
        'summary' => matchday_record_summary($pdo, $fixtureId),
    ];
}

/**
 * Score + goalscorers + card / sub counts, derived from the event rows.
 *
 * @return array{
 *   svfc_score:int, opponent_score:int, home_score:int, away_score:int,
 *   has_result:bool, scorers:list<array{side:string,name:string,minute:string,own_goal:bool}>,
 *   cards:int, subs:int
 * }
 */
function matchday_record_summary(PDO $pdo, int $fixtureId): array
{
    $fixture = matchday_record_fixture($pdo, $fixtureId);
    $isHome = $fixture === null || (int) ($fixture['is_home'] ?? 1) === 1;
    $types = matchday_record_event_types($pdo);
    $events = matchday_events_get($pdo, $fixtureId);

    $svfc = 0;
    $opponent = 0;
    $cards = 0;
    $subs = 0;
    $scorers = [];
    foreach ($events as $ev) {
        $projectsTo = $types[(string) $ev['type']]['projects_to'] ?? '';
        $category = $types[(string) $ev['type']]['category'] ?? 'other';
        if ($projectsTo === 'goal') {
            if ((string) $ev['side'] === 'svfc') {
                $svfc++;
            } elseif ((string) $ev['side'] === 'opponent') {
                $opponent++;
            }
            $scorers[] = [
                'side' => (string) $ev['side'],
                'name' => (string) $ev['player_name'],
                'minute' => matchday_record_minute_label((int) $ev['minute'], (int) $ev['minute_extra'], $ev['minute'] === null),
                'own_goal' => (bool) $ev['own_goal'],
            ];
        } elseif ($category === 'card') {
            $cards++;
        } elseif ($category === 'sub') {
            $subs++;
        }
    }

    $hasResult = $events !== [] && (
        $svfc > 0 || $opponent > 0 || (string) ($fixture['status'] ?? '') === 'played'
    );

    return [
        'svfc_score' => $svfc,
        'opponent_score' => $opponent,
        'home_score' => $isHome ? $svfc : $opponent,
        'away_score' => $isHome ? $opponent : $svfc,
        'has_result' => $hasResult,
        'scorers' => $scorers,
        'cards' => $cards,
        'subs' => $subs,
    ];
}

/* -------------------------------------------------------------------------
 * Writes — each ends with matchday_record_sync_derived()
 * ---------------------------------------------------------------------- */

/**
 * Replace one side's whole lineup (starters + subs). Enforces a single
 * captain on that side.
 *
 * @param list<array{
 *   player_id?:int|null, player_name:string, shirt_number?:int|null,
 *   position_label?:string|null, is_starting?:bool, is_captain?:bool, sort_order?:int
 * }> $players
 */
function matchday_lineup_replace(PDO $pdo, int $fixtureId, string $side, array $players): void
{
    matchday_record_ensure_schema($pdo);
    $side = matchday_record_side($side);

    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) {
        $pdo->beginTransaction();
    }
    try {
        $del = $pdo->prepare('DELETE FROM matchday_lineups WHERE fixture_id = :f AND side = :s');
        $del->execute([':f' => $fixtureId, ':s' => $side]);

        $ins = $pdo->prepare(
            'INSERT INTO matchday_lineups
                (fixture_id, side, player_id, player_name, shirt_number, position_label, is_starting, is_captain, sort_order)
             VALUES (:f, :s, :pid, :pn, :sn, :pos, :start, :cap, :sort)'
        );
        $captainTaken = false;
        $order = 0;
        foreach ($players as $p) {
            $name = trim((string) ($p['player_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $isCaptain = !$captainTaken && !empty($p['is_captain']);
            if ($isCaptain) {
                $captainTaken = true;
            }
            $ins->execute([
                ':f' => $fixtureId,
                ':s' => $side,
                ':pid' => isset($p['player_id']) && $p['player_id'] ? (int) $p['player_id'] : null,
                ':pn' => mb_substr($name, 0, 160),
                ':sn' => isset($p['shirt_number']) && $p['shirt_number'] !== null && $p['shirt_number'] !== ''
                    ? max(0, (int) $p['shirt_number']) : null,
                ':pos' => isset($p['position_label']) && $p['position_label'] !== ''
                    ? mb_substr((string) $p['position_label'], 0, 24) : null,
                ':start' => array_key_exists('is_starting', $p) ? (int) (bool) $p['is_starting'] : 1,
                ':cap' => (int) $isCaptain,
                ':sort' => isset($p['sort_order']) ? (int) $p['sort_order'] : ($order += 10),
            ]);
        }

        if ($ownTxn) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    matchday_record_sync_derived($pdo, $fixtureId);
}

/**
 * Add or update a single lineup row (used by the "make substitution" flow to
 * register an incoming player who wasn't on the named bench). Returns the
 * lineup row id. Does NOT run the projection on its own — callers that need
 * it (e.g. a bare add) should call matchday_record_sync_derived().
 *
 * @param array{
 *   id?:int, player_id?:int|null, player_name:string, shirt_number?:int|null,
 *   position_label?:string|null, is_starting?:bool, is_captain?:bool, sort_order?:int
 * } $player
 */
function matchday_lineup_upsert_player(PDO $pdo, int $fixtureId, string $side, array $player): int
{
    matchday_record_ensure_schema($pdo);
    $side = matchday_record_side($side);
    $name = trim((string) ($player['player_name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('A player name is required.');
    }

    $shirt = isset($player['shirt_number']) && $player['shirt_number'] !== null && $player['shirt_number'] !== ''
        ? max(0, (int) $player['shirt_number']) : null;
    $pos = isset($player['position_label']) && $player['position_label'] !== ''
        ? mb_substr((string) $player['position_label'], 0, 24) : null;
    $pid = isset($player['player_id']) && $player['player_id'] ? (int) $player['player_id'] : null;
    $starting = array_key_exists('is_starting', $player) ? (int) (bool) $player['is_starting'] : 0;

    if (!empty($player['id'])) {
        $stmt = $pdo->prepare(
            'UPDATE matchday_lineups
                SET player_id = :pid, player_name = :pn, shirt_number = :sn,
                    position_label = :pos, is_starting = :start
              WHERE id = :id AND fixture_id = :f AND side = :s LIMIT 1'
        );
        $stmt->execute([
            ':pid' => $pid, ':pn' => mb_substr($name, 0, 160), ':sn' => $shirt, ':pos' => $pos,
            ':start' => $starting, ':id' => (int) $player['id'], ':f' => $fixtureId, ':s' => $side,
        ]);
        return (int) $player['id'];
    }

    $maxSort = (int) $pdo->query(
        'SELECT COALESCE(MAX(sort_order), 0) FROM matchday_lineups WHERE fixture_id = ' . $fixtureId
        . " AND side = " . $pdo->quote($side)
    )->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO matchday_lineups
            (fixture_id, side, player_id, player_name, shirt_number, position_label, is_starting, is_captain, sort_order)
         VALUES (:f, :s, :pid, :pn, :sn, :pos, :start, 0, :sort)'
    );
    $stmt->execute([
        ':f' => $fixtureId, ':s' => $side, ':pid' => $pid, ':pn' => mb_substr($name, 0, 160),
        ':sn' => $shirt, ':pos' => $pos, ':start' => $starting, ':sort' => $maxSort + 10,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Remove a lineup row. Refuses if a substitution references it. */
function matchday_lineup_delete_player(PDO $pdo, int $lineupId): void
{
    matchday_record_ensure_schema($pdo);
    $row = $pdo->prepare('SELECT fixture_id FROM matchday_lineups WHERE id = :id LIMIT 1');
    $row->execute([':id' => $lineupId]);
    $fixtureId = $row->fetchColumn();
    if ($fixtureId === false) {
        return;
    }

    $ref = $pdo->prepare(
        'SELECT COUNT(*) FROM matchday_subs
         WHERE player_off_lineup_id = :id OR player_on_lineup_id = :id'
    );
    $ref->execute([':id' => $lineupId]);
    if ((int) $ref->fetchColumn() > 0) {
        throw new RuntimeException('This player has substitution history and cannot be removed.');
    }

    $pdo->prepare('DELETE FROM matchday_lineups WHERE id = :id LIMIT 1')->execute([':id' => $lineupId]);
    matchday_record_sync_derived($pdo, (int) $fixtureId);
}

/**
 * Record a substitution: a matchday_subs row plus a linked matchday_events
 * row (type 'substitution') so it flows into the projection and the event
 * timeline. Returns the sub id.
 *
 * @param array{
 *   minute?:int|null, minute_extra?:int, reason?:string,
 *   player_off_lineup_id?:int|null, player_off_name?:string,
 *   player_on_lineup_id?:int|null, player_on_name?:string
 * } $data
 */
function matchday_sub_add(PDO $pdo, int $fixtureId, string $side, array $data, ?int $userId = null): int
{
    matchday_record_ensure_schema($pdo);
    $side = matchday_record_side($side);

    $offName = trim((string) ($data['player_off_name'] ?? ''));
    $onName = trim((string) ($data['player_on_name'] ?? ''));
    $offId = isset($data['player_off_lineup_id']) && $data['player_off_lineup_id'] ? (int) $data['player_off_lineup_id'] : null;
    $onId = isset($data['player_on_lineup_id']) && $data['player_on_lineup_id'] ? (int) $data['player_on_lineup_id'] : null;
    if ($offName === '' && $offId !== null) {
        $offName = matchday_record_lineup_name($pdo, $offId);
    }
    if ($onName === '' && $onId !== null) {
        $onName = matchday_record_lineup_name($pdo, $onId);
    }
    if ($offName === '' || $onName === '') {
        throw new InvalidArgumentException('Both the player coming off and the player coming on are required.');
    }

    [$minute, $extra] = matchday_record_parse_minute($data['minute'] ?? null, $data['minute_extra'] ?? 0);
    $reason = mb_substr(trim((string) ($data['reason'] ?? '')), 0, 120);

    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) {
        $pdo->beginTransaction();
    }
    try {
        $eventId = matchday_event_save($pdo, $fixtureId, [
            'type' => 'substitution',
            'side' => $side,
            'minute' => $minute,
            'minute_extra' => $extra,
            'player_lineup_id' => $offId,
            'player_name' => $offName,
            'secondary_player_lineup_id' => $onId,
            'secondary_player_name' => $onName,
            'note' => $reason,
        ], $userId, false);

        $ins = $pdo->prepare(
            'INSERT INTO matchday_subs
                (fixture_id, side, minute, minute_extra, player_off_lineup_id, player_on_lineup_id,
                 player_off_name, player_on_name, reason, event_id)
             VALUES (:f, :s, :m, :me, :off, :on, :offn, :onn, :r, :eid)'
        );
        $ins->execute([
            ':f' => $fixtureId, ':s' => $side, ':m' => $minute, ':me' => $extra,
            ':off' => $offId, ':on' => $onId,
            ':offn' => mb_substr($offName, 0, 160), ':onn' => mb_substr($onName, 0, 160),
            ':r' => $reason, ':eid' => $eventId,
        ]);
        $subId = (int) $pdo->lastInsertId();

        if ($ownTxn) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    matchday_record_sync_derived($pdo, $fixtureId);
    return $subId;
}

/** Delete a substitution and its linked event row. */
function matchday_sub_delete(PDO $pdo, int $subId): void
{
    matchday_record_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT fixture_id, event_id FROM matchday_subs WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $subId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('DELETE FROM matchday_subs WHERE id = :id LIMIT 1')->execute([':id' => $subId]);
        if (!empty($row['event_id'])) {
            $pdo->prepare('DELETE FROM matchday_events WHERE id = :id LIMIT 1')->execute([':id' => (int) $row['event_id']]);
        }
        if ($ownTxn) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    matchday_record_sync_derived($pdo, (int) $row['fixture_id']);
}

/**
 * Insert or update one event. Pass 'id' to update. Returns the event id.
 * $runSync=false lets a caller batch several writes then sync once.
 *
 * @param array{
 *   id?:int, type:string, side?:string, minute?:int|null, minute_extra?:int,
 *   period_id?:int|null, player_lineup_id?:int|null, player_name?:string,
 *   secondary_player_lineup_id?:int|null, secondary_player_name?:string,
 *   own_goal?:bool, card_type?:string, outcome?:string, note?:string, sequence?:int
 * } $data
 */
function matchday_event_save(PDO $pdo, int $fixtureId, array $data, ?int $userId = null, bool $runSync = true): int
{
    matchday_record_ensure_schema($pdo);

    $type = trim((string) ($data['type'] ?? ''));
    $types = matchday_record_event_types($pdo);
    if ($type === '' || !isset($types[$type])) {
        throw new InvalidArgumentException('Unknown event type: ' . $type);
    }

    $side = matchday_record_side((string) ($data['side'] ?? 'svfc'), true);
    [$minute, $extra] = matchday_record_parse_minute($data['minute'] ?? null, $data['minute_extra'] ?? 0);

    // own_goal: the 'own_goal' type OR an explicit flag; a Saltcoats player's OG
    // counts for the opponent and vice-versa (matches the existing convention).
    $ownGoal = $type === 'own_goal' || !empty($data['own_goal']);

    $cardType = trim((string) ($data['card_type'] ?? ''));
    if ($cardType === '') {
        if ($type === 'yellow_card') {
            $cardType = 'yellow';
        } elseif ($type === 'red_card' || $type === 'second_yellow') {
            $cardType = 'red';
        }
    }

    $fields = [
        'fixture_id' => $fixtureId,
        'period_id' => isset($data['period_id']) && $data['period_id'] ? (int) $data['period_id'] : null,
        'minute' => $minute,
        'minute_extra' => $extra,
        'side' => $side,
        'type' => $type,
        'player_lineup_id' => isset($data['player_lineup_id']) && $data['player_lineup_id'] ? (int) $data['player_lineup_id'] : null,
        'player_name' => mb_substr(trim((string) ($data['player_name'] ?? '')), 0, 160),
        'secondary_player_lineup_id' => isset($data['secondary_player_lineup_id']) && $data['secondary_player_lineup_id'] ? (int) $data['secondary_player_lineup_id'] : null,
        'secondary_player_name' => mb_substr(trim((string) ($data['secondary_player_name'] ?? '')), 0, 160),
        'own_goal' => (int) $ownGoal,
        'card_type' => mb_substr($cardType, 0, 16),
        'outcome' => mb_substr(trim((string) ($data['outcome'] ?? '')), 0, 48),
        'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 500),
    ];

    if (!empty($data['id'])) {
        $set = [];
        foreach ($fields as $k => $_) {
            if ($k === 'fixture_id') {
                continue;
            }
            $set[] = "{$k} = :{$k}";
        }
        $sql = 'UPDATE matchday_events SET ' . implode(', ', $set) . ' WHERE id = :id AND fixture_id = :fixture_id LIMIT 1';
        $params = [];
        foreach ($fields as $k => $v) {
            $params[':' . $k] = $v;
        }
        $params[':id'] = (int) $data['id'];
        $pdo->prepare($sql)->execute($params);
        $eventId = (int) $data['id'];
    } else {
        $fields['sequence'] = isset($data['sequence']) && (int) $data['sequence'] > 0
            ? (int) $data['sequence']
            : (int) $pdo->query('SELECT COALESCE(MAX(sequence), 0) + 1 FROM matchday_events WHERE fixture_id = ' . $fixtureId)->fetchColumn();
        $fields['created_by'] = $userId;

        $cols = array_keys($fields);
        $sql = 'INSERT INTO matchday_events (' . implode(', ', $cols) . ') VALUES ('
            . implode(', ', array_map(static fn($c) => ':' . $c, $cols)) . ')';
        $params = [];
        foreach ($fields as $k => $v) {
            $params[':' . $k] = $v;
        }
        $pdo->prepare($sql)->execute($params);
        $eventId = (int) $pdo->lastInsertId();
    }

    if ($runSync) {
        matchday_record_sync_derived($pdo, $fixtureId);
    }
    return $eventId;
}

function matchday_event_delete(PDO $pdo, int $eventId): void
{
    matchday_record_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT fixture_id FROM matchday_events WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $eventId]);
    $fixtureId = $stmt->fetchColumn();
    if ($fixtureId === false) {
        return;
    }
    // A substitution event owns its matchday_subs row — remove both together.
    $pdo->prepare('DELETE FROM matchday_subs WHERE event_id = :id')->execute([':id' => $eventId]);
    $pdo->prepare('DELETE FROM matchday_events WHERE id = :id LIMIT 1')->execute([':id' => $eventId]);
    matchday_record_sync_derived($pdo, (int) $fixtureId);
}

/**
 * @param array{id?:int, period_key:string, label?:string, start_minute?:int, end_minute?:int|null, sort_order?:int} $data
 */
function matchday_period_save(PDO $pdo, int $fixtureId, array $data): int
{
    matchday_record_ensure_schema($pdo);
    $key = mb_substr(trim((string) ($data['period_key'] ?? '')), 0, 24);
    if ($key === '') {
        throw new InvalidArgumentException('A period key is required.');
    }
    $label = mb_substr(trim((string) ($data['label'] ?? ucfirst(str_replace('_', ' ', $key)))), 0, 48);
    $start = max(0, (int) ($data['start_minute'] ?? 0));
    $end = array_key_exists('end_minute', $data) && $data['end_minute'] !== null && $data['end_minute'] !== ''
        ? max(0, (int) $data['end_minute']) : null;
    $sort = (int) ($data['sort_order'] ?? 0);

    $stmt = $pdo->prepare(
        'INSERT INTO matchday_periods (fixture_id, period_key, label, start_minute, end_minute, sort_order)
         VALUES (:f, :k, :l, :sm, :em, :so)
         ON DUPLICATE KEY UPDATE label = VALUES(label), start_minute = VALUES(start_minute),
                                 end_minute = VALUES(end_minute), sort_order = VALUES(sort_order)'
    );
    $stmt->execute([':f' => $fixtureId, ':k' => $key, ':l' => $label, ':sm' => $start, ':em' => $end, ':so' => $sort]);

    $id = $pdo->prepare('SELECT id FROM matchday_periods WHERE fixture_id = :f AND period_key = :k LIMIT 1');
    $id->execute([':f' => $fixtureId, ':k' => $key]);
    return (int) $id->fetchColumn();
}

/**
 * Slot coordinates (percent of the pitch, GK end at the bottom) for the
 * common 11-a-side shapes, ported from the old veo formation_positions
 * table. Index 0 is the GK; 1..10 run defence -> attack. x = left%, y = top%
 * (0 = opponent goal line, 100 = own goal line).
 *
 * @return array<string, list<array{x:int,y:int}>>
 */
function matchday_record_formation_templates(): array
{
    $mk = static fn(array $pairs): array => array_map(static fn($p) => ['x' => $p[0], 'y' => $p[1]], $pairs);

    return [
        '4-4-2' => $mk([[50, 92], [16, 74], [38, 78], [62, 78], [84, 74], [12, 48], [37, 50], [63, 50], [88, 48], [38, 22], [62, 22]]),
        '4-3-3' => $mk([[50, 92], [16, 74], [38, 78], [62, 78], [84, 74], [30, 52], [50, 56], [70, 52], [20, 24], [50, 20], [80, 24]]),
        '4-2-3-1' => $mk([[50, 92], [16, 74], [38, 78], [62, 78], [84, 74], [38, 58], [62, 58], [22, 36], [50, 38], [78, 36], [50, 18]]),
        '4-1-4-1' => $mk([[50, 92], [16, 74], [38, 78], [62, 78], [84, 74], [50, 60], [14, 42], [38, 44], [62, 44], [86, 42], [50, 20]]),
        '3-5-2' => $mk([[50, 92], [28, 78], [50, 80], [72, 78], [12, 54], [35, 52], [50, 56], [65, 52], [88, 54], [40, 24], [60, 24]]),
        '3-4-3' => $mk([[50, 92], [28, 78], [50, 80], [72, 78], [16, 52], [40, 54], [60, 54], [84, 52], [22, 24], [50, 20], [78, 24]]),
        '5-3-2' => $mk([[50, 92], [10, 70], [30, 76], [50, 78], [70, 76], [90, 70], [32, 50], [50, 52], [68, 50], [40, 24], [60, 24]]),
        '4-5-1' => $mk([[50, 92], [16, 74], [38, 78], [62, 78], [84, 74], [12, 48], [32, 50], [50, 52], [68, 50], [88, 48], [50, 22]]),
        '4-4-1-1' => $mk([[50, 92], [16, 74], [38, 78], [62, 78], [84, 74], [12, 50], [37, 52], [63, 52], [88, 50], [50, 34], [50, 16]]),
    ];
}

/** Set just the formation key for a side, leaving any dragged layout intact. */
function matchday_formation_set_key(PDO $pdo, int $fixtureId, string $side, string $formationKey): void
{
    matchday_record_ensure_schema($pdo);
    $pdo->prepare(
        'INSERT INTO matchday_formations (fixture_id, side, formation_key)
         VALUES (:f, :s, :k)
         ON DUPLICATE KEY UPDATE formation_key = VALUES(formation_key)'
    )->execute([':f' => $fixtureId, ':s' => matchday_record_side($side), ':k' => mb_substr(trim($formationKey), 0, 24)]);
}

function matchday_formation_save(PDO $pdo, int $fixtureId, string $side, string $formationKey, ?array $layout): void
{
    matchday_record_ensure_schema($pdo);
    $side = matchday_record_side($side);
    $stmt = $pdo->prepare(
        'INSERT INTO matchday_formations (fixture_id, side, formation_key, layout_json)
         VALUES (:f, :s, :k, :j)
         ON DUPLICATE KEY UPDATE formation_key = VALUES(formation_key), layout_json = VALUES(layout_json)'
    );
    $stmt->execute([
        ':f' => $fixtureId,
        ':s' => $side,
        ':k' => mb_substr(trim($formationKey), 0, 24),
        ':j' => $layout !== null ? json_encode($layout, JSON_UNESCAPED_SLASHES) : null,
    ]);
    matchday_record_sync_derived($pdo, $fixtureId);
}

/* -------------------------------------------------------------------------
 * The projection: new tables -> match_fixtures.starting11_*_json + matches.json
 * ---------------------------------------------------------------------- */

/**
 * Rebuild the legacy stores for one fixture from the matchday_* tables.
 *
 * Safe to call any time: it is a no-op for a fixture that has no matchday_*
 * rows at all, and it only overwrites the lineup projection when svfc lineup
 * rows exist and the events projection when event rows exist — so a
 * half-entered record never wipes the other half.
 */
function matchday_record_sync_derived(PDO $pdo, int $fixtureId): void
{
    matchday_record_ensure_schema($pdo);

    $fixture = matchday_record_fixture($pdo, $fixtureId);
    if ($fixture === null) {
        return;
    }
    $isHome = (int) ($fixture['is_home'] ?? 1) === 1;

    $lineups = matchday_lineup_get($pdo, $fixtureId);
    $events = matchday_events_get($pdo, $fixtureId);
    $subs = matchday_subs_get($pdo, $fixtureId);
    if ($lineups === [] && $events === [] && $subs === []) {
        return; // fixture not managed here yet — leave the legacy stores untouched
    }

    $types = matchday_record_event_types($pdo);

    $svfcLineup = array_values(array_filter($lineups, static fn($r) => (string) $r['side'] === 'svfc'));
    $haveSvfcLineup = $svfcLineup !== [];
    $haveEvents = $events !== [];

    /* ---- match_fixtures column projection -------------------------------- */
    $set = [];
    $params = [':id' => $fixtureId];

    if ($haveSvfcLineup) {
        $starterSlots = array_fill(0, 11, '');
        $i = 0;
        foreach ($svfcLineup as $row) {
            if ((int) $row['is_starting'] === 1 && $i < 11) {
                $starterSlots[$i++] = (string) $row['player_name'];
            }
        }
        $substitutes = [];
        foreach ($svfcLineup as $row) {
            if ((int) $row['is_starting'] === 0) {
                $name = (string) $row['player_name'];
                if ($name !== '' && !in_array($name, $substitutes, true)) {
                    $substitutes[] = $name;
                }
            }
        }
        $captain = '';
        foreach ($svfcLineup as $row) {
            if ((int) $row['is_captain'] === 1) {
                $captain = (string) $row['player_name'];
                break;
            }
        }
        if ($captain !== '' && !in_array($captain, $starterSlots, true)) {
            $captain = '';
        }

        $set[] = 'starting11_starters_json = :starters';
        $set[] = 'starting11_substitutes_json = :subs';
        $set[] = 'starting11_squad_numbers_json = :nums';
        $set[] = 'starting11_captain = :captain';
        $params[':starters'] = json_encode(array_values($starterSlots), JSON_UNESCAPED_SLASHES);
        $params[':subs'] = json_encode(array_values($substitutes), JSON_UNESCAPED_SLASHES);
        $params[':nums'] = json_encode(
            matchday_record_positional_squad_numbers($starterSlots, $substitutes),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $params[':captain'] = $captain !== '' ? $captain : null;
    }

    // NOTE: the projection deliberately does NOT touch full_time_*_score or
    // status. That column is shared with the Overview score box, the COMET
    // import and manual entry, and a fixture whose events were only backfilled
    // usually has fewer logged goals than its real score. Pushing the derived
    // score is an explicit action — see matchday_record_apply_score().
    if ($set !== []) {
        $pdo->prepare('UPDATE match_fixtures SET ' . implode(', ', $set) . ' WHERE id = :id LIMIT 1')->execute($params);
    }

    /* ---- matches.json overlay projection ------------------------------- */
    if (!function_exists('matches_load_all')) {
        require_once __DIR__ . '/../matches_lib.php';
    }
    $all = matches_load_all();
    $idx = matches_find_index($all, (string) $fixtureId);
    if ($idx < 0) {
        return; // brand-new fixture with no master row yet
    }
    $existing = $all[$idx];
    $timestamp = date(DATE_ATOM);
    $patch = ['updated_at' => $timestamp];

    if ($haveSvfcLineup) {
        $compactStarters = [];
        foreach ($starterSlots as $name) {
            if ($name !== '') {
                $compactStarters[] = $name;
            }
        }
        $patch['starters'] = $compactStarters;
        $patch['substitutes'] = $substitutes;
        $patch['captain'] = $captain;
    }

    if ($haveEvents) {
        // Keep only the structural / manual events the record does not own.
        $kept = [];
        foreach (is_array($existing['events'] ?? null) ? $existing['events'] : [] as $ev) {
            $evType = (string) ($ev['type'] ?? '');
            $evId = (string) ($ev['id'] ?? '');
            if (strncmp($evId, 'md', 2) === 0) {
                continue; // previously-projected row — always rebuilt below
            }
            if (in_array($evType, MATCHDAY_RECORD_OWNED_MJ_TYPES, true)) {
                continue;
            }
            $kept[] = $ev;
        }

        $sequence = matches_next_event_sequence($kept);
        $projected = [];
        foreach ($events as $ev) {
            $meta = $types[(string) $ev['type']] ?? null;
            $projectsTo = $meta['projects_to'] ?? '';
            if ($projectsTo === '') {
                continue;
            }
            $team = (string) $ev['side'] === 'none' ? 'match' : (string) $ev['side'];
            $minuteLabel = matchday_record_minute_label((int) $ev['minute'], (int) $ev['minute_extra'], $ev['minute'] === null);
            $entry = [
                'id' => 'md' . (int) $ev['id'],
                'type' => $projectsTo,
                'minute' => $minuteLabel,
                'team' => $team,
                'player' => (string) $ev['player_name'],
                'own_goal' => (bool) $ev['own_goal'],
                'secondary_player' => (string) $ev['secondary_player_name'],
                'substitutions' => [],
                'card_type' => (string) $ev['card_type'],
                'outcome' => (string) $ev['outcome'],
                'note' => (string) $ev['note'],
                'created_at' => (string) ($ev['created_at'] ?? $timestamp),
                'updated_at' => (string) ($ev['updated_at'] ?? $timestamp),
                'sequence' => $sequence++,
            ];
            if ($projectsTo === 'substitution') {
                $entry['substitutions'] = [[
                    'off' => (string) $ev['player_name'],
                    'on' => (string) $ev['secondary_player_name'],
                ]];
            }
            $projected[] = $entry;
        }
        $patch['events'] = array_merge($kept, $projected);
    }

    $all[$idx] = matches_normalize(array_merge($existing, $patch));
    if (!matches_save_all($all)) {
        error_log('matchday_record_sync_derived: matches.json save failed for fixture ' . $fixtureId);
    }
}

/**
 * Explicitly push the event-derived score onto the fixture: writes
 * match_fixtures.full_time_*_score + status='played', and the matches.json
 * status. Unlike the projection this is a deliberate action (the Events UI
 * offers it as a button) — nothing calls it automatically.
 *
 * @return array{home:int, away:int, svfc:int, opponent:int}
 */
function matchday_record_apply_score(PDO $pdo, int $fixtureId): array
{
    matchday_record_ensure_schema($pdo);
    $summary = matchday_record_summary($pdo, $fixtureId);
    $home = (int) $summary['home_score'];
    $away = (int) $summary['away_score'];

    $pdo->prepare(
        "UPDATE match_fixtures
            SET full_time_home_score = :fh, full_time_away_score = :fa, status = 'played'
          WHERE id = :id LIMIT 1"
    )->execute([':fh' => $home, ':fa' => $away, ':id' => $fixtureId]);

    if (!function_exists('matches_load_all')) {
        require_once __DIR__ . '/../matches_lib.php';
    }
    $all = matches_load_all();
    $idx = matches_find_index($all, (string) $fixtureId);
    if ($idx >= 0) {
        $all[$idx] = matches_normalize(array_merge($all[$idx], ['status' => 'played']));
        matches_save_all($all);
    }

    return [
        'home' => $home,
        'away' => $away,
        'svfc' => (int) $summary['svfc_score'],
        'opponent' => (int) $summary['opponent_score'],
    ];
}

/* -------------------------------------------------------------------------
 * One-off backfill: legacy stores -> matchday_* tables (Stage 2)
 * ---------------------------------------------------------------------- */

/** Legacy matches.json event `type` -> matchday_event_types key. */
const MATCHDAY_RECORD_LEGACY_EVENT_MAP = [
    'goal' => 'goal',
    'shot' => 'shot',
    'chance' => 'chance',
    'corner' => 'corner',
    'free_kick' => 'free_kick',
    'penalty' => 'penalty_missed',
    'off_side' => 'offside',
    'yellow_card' => 'yellow_card',
    'red_card' => 'red_card',
    'substitution' => 'substitution',
    'mistake' => 'mistake',
    'good_play' => 'good_play',
    'highlight' => 'highlight',
];

/**
 * Populate the matchday_* tables for every fixture that has a stored
 * starting11_*_json line-up or a matches.json events array but no matchday_*
 * rows yet. Idempotent (fixtures that already have rows are skipped). The
 * legacy stores are read, not written — the projection is NOT run here.
 *
 * Respects an already-open transaction (e.g. the migration runner's): in
 * that case a single fixture's failure propagates; run standalone and each
 * fixture gets its own transaction.
 *
 * @return array<string, array<string,mixed>> per-fixture report
 */
function matchday_record_backfill(PDO $pdo, bool $dryRun = false): array
{
    matchday_record_ensure_schema($pdo);
    if (!function_exists('matches_load_all')) {
        require_once __DIR__ . '/../matches_lib.php';
    }

    $playerByName = [];
    foreach ($pdo->query('SELECT id, name FROM players ORDER BY active DESC, id DESC') as $p) {
        $key = mb_strtolower(trim((string) $p['name']), 'UTF-8');
        if ($key !== '' && !isset($playerByName[$key])) {
            $playerByName[$key] = (int) $p['id'];
        }
    }

    $mjEvents = [];
    foreach (matches_load_all() as $m) {
        $fid = (string) ($m['id'] ?? '');
        if ($fid !== '' && !empty($m['events']) && is_array($m['events'])) {
            $mjEvents[$fid] = $m['events'];
        }
    }

    $fxById = [];
    foreach ($pdo->query(
        'SELECT id, opponent, is_home, starting11_starters_json, starting11_substitutes_json,
                starting11_captain, starting11_squad_numbers_json
         FROM match_fixtures'
    ) as $r) {
        $fxById[(string) $r['id']] = $r;
    }

    $withLineup = [];
    foreach ($fxById as $fid => $r) {
        $raw = trim((string) ($r['starting11_starters_json'] ?? ''));
        if ($raw !== '' && !in_array($raw, ['[]', '""'], true)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && array_filter($decoded, static fn($n) => trim((string) $n) !== '')) {
                $withLineup[$fid] = true;
            }
        }
    }

    $candidates = array_values(array_unique(array_merge(array_keys($mjEvents), array_keys($withLineup))));
    sort($candidates, SORT_NUMERIC);

    $useOwnTxn = !$pdo->inTransaction();
    $report = [];

    foreach ($candidates as $fid) {
        $fidInt = (int) $fid;
        if ($fidInt <= 0) {
            continue;
        }

        $existing = (int) $pdo->query(
            "SELECT (SELECT COUNT(*) FROM matchday_lineups WHERE fixture_id = {$fidInt})
                  + (SELECT COUNT(*) FROM matchday_events  WHERE fixture_id = {$fidInt})
                  + (SELECT COUNT(*) FROM matchday_subs    WHERE fixture_id = {$fidInt})"
        )->fetchColumn();
        if ($existing > 0) {
            $report[$fid] = ['skipped' => 'already has matchday_* rows'];
            continue;
        }

        $a = ['lineup' => 0, 'bench' => 0, 'events' => 0, 'subs' => 0, 'periods' => 0, 'unmatched' => []];

        if (!$dryRun && $useOwnTxn) {
            $pdo->beginTransaction();
        }
        try {
            $lineupIdByName = [];
            $fx = $fxById[$fid] ?? null;

            if ($fx !== null && isset($withLineup[$fid])) {
                $starters = json_decode((string) ($fx['starting11_starters_json'] ?? '[]'), true) ?: [];
                $bench = json_decode((string) ($fx['starting11_substitutes_json'] ?? '[]'), true) ?: [];
                $nums = json_decode((string) ($fx['starting11_squad_numbers_json'] ?? '{}'), true) ?: [];
                $captain = trim((string) ($fx['starting11_captain'] ?? ''));

                $insL = $pdo->prepare(
                    "INSERT INTO matchday_lineups
                        (fixture_id, side, player_id, player_name, shirt_number, position_label, is_starting, is_captain, sort_order)
                     VALUES (:f, 'svfc', :pid, :pn, :sn, :pos, :st, :cap, :so)"
                );

                $slot = 0;
                foreach ($starters as $i => $name) {
                    $name = trim((string) $name);
                    if ($name === '') {
                        continue;
                    }
                    $slot++;
                    $pid = $playerByName[mb_strtolower($name, 'UTF-8')] ?? null;
                    if ($pid === null) {
                        $a['unmatched'][] = $name;
                    }
                    $shirt = isset($nums[$name]) ? (int) $nums[$name] : ($i + 1);
                    if (!$dryRun) {
                        $insL->execute([
                            ':f' => $fidInt, ':pid' => $pid, ':pn' => $name, ':sn' => $shirt,
                            ':pos' => $i === 0 ? 'GK' : null, ':st' => 1, ':cap' => $name === $captain ? 1 : 0,
                            ':so' => $slot * 10,
                        ]);
                        $lineupIdByName[mb_strtolower($name, 'UTF-8')] = (int) $pdo->lastInsertId();
                    }
                    $a['lineup']++;
                }

                $bi = 0;
                foreach ($bench as $name) {
                    $name = trim((string) $name);
                    if ($name === '') {
                        continue;
                    }
                    $bi++;
                    $pid = $playerByName[mb_strtolower($name, 'UTF-8')] ?? null;
                    if ($pid === null) {
                        $a['unmatched'][] = $name;
                    }
                    if (!$dryRun) {
                        $insL->execute([
                            ':f' => $fidInt, ':pid' => $pid, ':pn' => $name,
                            ':sn' => isset($nums[$name]) ? (int) $nums[$name] : null,
                            ':pos' => null, ':st' => 0, ':cap' => 0, ':so' => 200 + $bi * 10,
                        ]);
                        $lineupIdByName[mb_strtolower($name, 'UTF-8')] = (int) $pdo->lastInsertId();
                    }
                    $a['bench']++;
                }
            }

            $insE = $pdo->prepare(
                "INSERT INTO matchday_events
                    (fixture_id, minute, minute_extra, side, type, player_lineup_id, player_name,
                     secondary_player_lineup_id, secondary_player_name, own_goal, card_type, outcome, note, sequence, created_by)
                 VALUES (:f, :m, :me, :side, :type, :pl, :pn, :spl, :spn, :og, :ct, :oc, :nt, :seq, NULL)"
            );
            $insS = $pdo->prepare(
                "INSERT INTO matchday_subs
                    (fixture_id, side, minute, minute_extra, player_off_lineup_id, player_on_lineup_id,
                     player_off_name, player_on_name, reason, event_id)
                 VALUES (:f, :side, :m, :me, :off, :on, :offn, :onn, '', :eid)"
            );

            $seq = 0;
            foreach ($mjEvents[$fid] ?? [] as $ev) {
                $legacyType = (string) ($ev['type'] ?? '');
                if (!isset(MATCHDAY_RECORD_LEGACY_EVENT_MAP[$legacyType])) {
                    continue; // structural marker / note — stays in matches.json
                }
                $seq++;
                $ownGoal = !empty($ev['own_goal']);
                $key = MATCHDAY_RECORD_LEGACY_EVENT_MAP[$legacyType];
                if ($legacyType === 'goal' && $ownGoal) {
                    $key = 'own_goal';
                }
                $team = (string) ($ev['team'] ?? 'svfc');
                $side = $team === 'opponent' ? 'opponent' : ($team === 'match' ? 'none' : 'svfc');
                [$min, $mex] = matchday_record_parse_minute($ev['minute'] ?? null);

                $player = trim((string) ($ev['player'] ?? ''));
                $secondary = trim((string) ($ev['secondary_player'] ?? ''));
                if ($legacyType === 'substitution') {
                    $subArr = is_array($ev['substitutions'] ?? null) ? $ev['substitutions'] : [];
                    $player = trim((string) ($subArr[0]['off'] ?? $player));
                    $secondary = trim((string) ($subArr[0]['on'] ?? $secondary));
                }

                $cardType = (string) ($ev['card_type'] ?? '');
                if ($cardType === '' && $legacyType === 'yellow_card') {
                    $cardType = 'yellow';
                } elseif ($cardType === '' && $legacyType === 'red_card') {
                    $cardType = 'red';
                }

                $plId = $side === 'svfc' ? ($lineupIdByName[mb_strtolower($player, 'UTF-8')] ?? null) : null;
                $splId = $side === 'svfc' ? ($lineupIdByName[mb_strtolower($secondary, 'UTF-8')] ?? null) : null;

                if (!$dryRun) {
                    $insE->execute([
                        ':f' => $fidInt, ':m' => $min, ':me' => $mex, ':side' => $side, ':type' => $key,
                        ':pl' => $plId, ':pn' => mb_substr($player, 0, 160),
                        ':spl' => $splId, ':spn' => mb_substr($secondary, 0, 160),
                        ':og' => $ownGoal ? 1 : 0, ':ct' => $cardType,
                        ':oc' => mb_substr((string) ($ev['outcome'] ?? ''), 0, 48),
                        ':nt' => mb_substr((string) ($ev['note'] ?? ''), 0, 500),
                        ':seq' => $seq,
                    ]);
                    $eventId = (int) $pdo->lastInsertId();
                    if ($legacyType === 'substitution') {
                        $insS->execute([
                            ':f' => $fidInt, ':side' => $side, ':m' => $min, ':me' => $mex,
                            ':off' => $plId, ':on' => $splId,
                            ':offn' => mb_substr($player, 0, 160), ':onn' => mb_substr($secondary, 0, 160),
                            ':eid' => $eventId,
                        ]);
                    }
                }
                if ($legacyType === 'substitution') {
                    $a['subs']++;
                }
                $a['events']++;
            }

            if (!$dryRun) {
                matchday_periods_ensure($pdo, $fidInt);
            }
            $a['periods'] = 2;

            if (!$dryRun && $useOwnTxn) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if (!$dryRun && $useOwnTxn && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $a['error'] = $e->getMessage();
            $report[$fid] = $a;
            if (!$useOwnTxn) {
                throw $e; // inside the migration runner's transaction: fail loudly
            }
            continue;
        }

        $a['unmatched'] = array_values(array_unique($a['unmatched']));
        $report[$fid] = $a;
    }

    return $report;
}

/* -------------------------------------------------------------------------
 * Small helpers
 * ---------------------------------------------------------------------- */

/** Normalise a side token to the ENUM values. */
function matchday_record_side(string $side, bool $allowNone = false): string
{
    $side = strtolower(trim($side));
    if (in_array($side, ['svfc', 'home_club', 'saltcoats', 'club'], true)) {
        return 'svfc';
    }
    if (in_array($side, ['opponent', 'away', 'them', 'opp'], true)) {
        return 'opponent';
    }
    if ($allowNone && ($side === 'none' || $side === 'match' || $side === '')) {
        return 'none';
    }
    return 'svfc';
}

/** svfc/opponent -> home/away for the given fixture. */
function matchday_record_side_to_venue(string $side, bool $fixtureIsHome): string
{
    $side = matchday_record_side($side);
    if ($side === 'svfc') {
        return $fixtureIsHome ? 'home' : 'away';
    }
    return $fixtureIsHome ? 'away' : 'home';
}

/**
 * @param mixed $minute
 * @param mixed $extra
 * @return array{0:int|null,1:int}
 */
function matchday_record_parse_minute($minute, $extra = 0): array
{
    if (is_string($minute) && str_contains($minute, '+')) {
        [$base, $plus] = array_pad(explode('+', $minute, 2), 2, '');
        $minute = $base;
        if ($extra === 0 || $extra === '' || $extra === null) {
            $extra = $plus;
        }
    }
    $m = ($minute === null || $minute === '') ? null : max(0, min(200, (int) $minute));
    $e = ($extra === null || $extra === '') ? 0 : max(0, min(30, (int) $extra));
    return [$m, $e];
}

function matchday_record_minute_label(int $minute, int $extra, bool $isNull = false): string
{
    if ($isNull) {
        return '';
    }
    return $extra > 0 ? $minute . '+' . $extra : (string) $minute;
}

function matchday_record_lineup_name(PDO $pdo, int $lineupId): string
{
    $stmt = $pdo->prepare('SELECT player_name FROM matchday_lineups WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $lineupId]);
    return (string) ($stmt->fetchColumn() ?: '');
}

/**
 * Positional squad numbers for the projected starting11_squad_numbers_json —
 * 1..11 for the starter slots, then 12, 14, 15, ... for the bench (13 is
 * skipped), matching the pre-existing matchStarting11BuildSquadNumbers().
 * The record keeps real shirt numbers; graphics still expect positional.
 *
 * @param list<string> $starterSlots
 * @param list<string> $substitutes
 * @return array<string,int>
 */
function matchday_record_positional_squad_numbers(array $starterSlots, array $substitutes): array
{
    $numbers = [];
    foreach (array_values($starterSlots) as $index => $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $numbers[$name] = $index + 1;
        }
    }
    foreach (array_values($substitutes) as $index => $name) {
        $name = trim((string) $name);
        if ($name === '' || isset($numbers[$name])) {
            continue;
        }
        $candidate = 12 + $index;
        $numbers[$name] = $candidate >= 13 ? $candidate + 1 : $candidate;
    }
    return $numbers;
}
