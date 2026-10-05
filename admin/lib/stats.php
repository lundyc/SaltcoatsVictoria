<?php
declare(strict_types=1);

/**
 * Every recorded match event, straight from the matchday_events table,
 * grouped by fixture — the single source of truth stats now read from,
 * instead of the derived data/matches.json projection (which existed only
 * because the stats code predates matchday_events; a sync bug there could
 * silently make stats disagree with the actual match record). Verified
 * 2026-09-14: every fixture with events in matches.json has the identical
 * event set in matchday_events, so this is a like-for-like swap, not a
 * data change.
 *
 * Same shape the old JSON events had (minute as a "45" / "90+2" string,
 * team, type, player, secondary_player, own_goal, card_type, note, outcome,
 * substitutions, sequence) so every existing consumer keeps working
 * unchanged — only the two internal readers in this file and in
 * player_match_stats.php call this; nothing else needs to change.
 *
 * @param list<int> $fixtureIds empty = every fixture
 * @return array<int, list<array<string, mixed>>>
 */
function hub_matchday_events_by_fixture(PDO $pdo, array $fixtureIds = []): array
{
    require_once __DIR__ . '/matchday_record.php';

    $sql = 'SELECT * FROM matchday_events';
    $params = [];
    if ($fixtureIds !== []) {
        $placeholders = implode(',', array_fill(0, count($fixtureIds), '?'));
        $sql .= " WHERE fixture_id IN ($placeholders)";
        $params = $fixtureIds;
    }
    $sql .= ' ORDER BY fixture_id, sequence ASC, id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $byFixture = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $minute = matchday_record_minute_label(
            (int)($row['minute'] ?? 0),
            (int)($row['minute_extra'] ?? 0),
            $row['minute'] === null
        );
        $event = [
            'id' => 'md' . (int)$row['id'],
            'type' => (string)$row['type'],
            'minute' => $minute,
            'team' => (string)$row['side'],
            'player' => (string)$row['player_name'],
            'own_goal' => (bool)$row['own_goal'],
            'secondary_player' => (string)$row['secondary_player_name'],
            'substitutions' => [],
            'card_type' => (string)$row['card_type'],
            'outcome' => (string)($row['outcome'] ?? ''),
            'note' => (string)($row['note'] ?? ''),
            'sequence' => (int)$row['sequence'],
        ];
        if ($event['type'] === 'substitution') {
            $event['substitutions'] = [['off' => $event['player'], 'on' => $event['secondary_player']]];
        }
        $byFixture[(int)$row['fixture_id']][] = $event;
    }
    return $byFixture;
}

/** Results are always from the club's perspective; incomplete scores stay unknown. */
function hub_stats_result(array $fixture): ?array
{
    if (($fixture['status'] ?? '') !== 'played'
        || !isset($fixture['full_time_home_score'], $fixture['full_time_away_score'])) {
        return null;
    }
    $home = (int)$fixture['is_home'] === 1;
    $for = (int)$fixture[$home ? 'full_time_home_score' : 'full_time_away_score'];
    $against = (int)$fixture[$home ? 'full_time_away_score' : 'full_time_home_score'];
    return ['for' => $for, 'against' => $against, 'result' => $for > $against ? 'W' : ($for < $against ? 'L' : 'D')];
}

function hub_stats_summary(array $fixtures): array
{
    $summary = ['played' => 0, 'scored' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'goals_for' => 0, 'goals_against' => 0, 'clean_sheets' => 0];
    foreach ($fixtures as $fixture) {
        if (($fixture['status'] ?? '') !== 'played') continue;
        $summary['played']++;
        $result = hub_stats_result($fixture);
        if ($result === null) continue;
        $summary['scored']++;
        $summary[['W' => 'wins', 'D' => 'draws', 'L' => 'losses'][$result['result']]]++;
        $summary['goals_for'] += $result['for'];
        $summary['goals_against'] += $result['against'];
        if ($result['against'] === 0) $summary['clean_sheets']++;
    }
    return $summary;
}

/* -------------------------------------------------------------------------
 * "Compare seasons" model — year-on-year team + player stats.
 * Shared by stats.php (screen) and stats_compare_pdf.php (A4 export).
 * ---------------------------------------------------------------------- */

/** @return array<string,string> metric key => label, in display order. */
function hub_stats_compare_metric_labels(): array
{
    return [
        'goals' => 'Goals',
        'appearances' => 'Appearances',
        'starts' => 'Starts',
        'substitute_appearances' => 'Sub appearances',
        'minutes_played' => 'Minutes',
        'yellow_cards' => 'Yellow cards',
        'red_cards' => 'Red cards',
        'clean_sheets' => 'Clean sheets',
    ];
}

/**
 * Team-comparison rows. Each entry: [label, fn(row):string, fn(row):?float|null, 'high'|'low'|null].
 * The raw getter (3rd item) drives the season-to-season delta; null = no delta.
 * @return list<array{0:string,1:callable,2:?callable,3:?string}>
 */
function hub_stats_compare_team_rows(): array
{
    $fmt2 = static fn(?float $v): string => $v === null ? '—' : number_format($v, 2);
    $pct  = static fn(?float $v): string => $v === null ? '—' : round($v) . '%';
    return [
        ['Played',            static fn($c) => (string)$c['summary']['played'],        static fn($c) => (float)$c['summary']['played'],        null],
        ['Won',               static fn($c) => (string)$c['summary']['wins'],          static fn($c) => (float)$c['summary']['wins'],          'high'],
        ['Drawn',             static fn($c) => (string)$c['summary']['draws'],         static fn($c) => (float)$c['summary']['draws'],         null],
        ['Lost',              static fn($c) => (string)$c['summary']['losses'],        static fn($c) => (float)$c['summary']['losses'],        'low'],
        ['Win rate',          static fn($c) => $pct($c['win_pct']),                    static fn($c) => $c['win_pct'] === null ? null : round($c['win_pct']), 'high'],
        ['Points',            static fn($c) => (string)$c['points'],                   static fn($c) => (float)$c['points'],                   'high'],
        ['Points per game',   static fn($c) => $fmt2($c['ppg']),                       static fn($c) => $c['ppg'],                             'high'],
        ['Goals for',         static fn($c) => (string)$c['summary']['goals_for'],     static fn($c) => (float)$c['summary']['goals_for'],     'high'],
        ['Goals against',     static fn($c) => (string)$c['summary']['goals_against'], static fn($c) => (float)$c['summary']['goals_against'], 'low'],
        ['Goal difference',   static fn($c) => sprintf('%+d', $c['summary']['goals_for'] - $c['summary']['goals_against']), static fn($c) => (float)($c['summary']['goals_for'] - $c['summary']['goals_against']), 'high'],
        ['Goals per game',    static fn($c) => $fmt2($c['gf_pg']),                     static fn($c) => $c['gf_pg'],                           'high'],
        ['Conceded per game', static fn($c) => $fmt2($c['ga_pg']),                     static fn($c) => $c['ga_pg'],                           'low'],
        ['Clean sheets',      static fn($c) => (string)$c['summary']['clean_sheets'],  static fn($c) => (float)$c['summary']['clean_sheets'],  'high'],
        ['Clean sheet rate',  static fn($c) => $pct($c['cs_pct']),                     static fn($c) => $c['cs_pct'] === null ? null : round($c['cs_pct']), 'high'],
        ['Failed to score',   static fn($c) => (string)$c['failed_to_score'],          static fn($c) => (float)$c['failed_to_score'],          'low'],
        ['Home (P·W·D·L)',    static fn($c) => sprintf('%d·%d·%d·%d', $c['home']['played'], $c['home']['wins'], $c['home']['draws'], $c['home']['losses']), null, null],
        ['Away (P·W·D·L)',    static fn($c) => sprintf('%d·%d·%d·%d', $c['away']['played'], $c['away']['wins'], $c['away']['draws'], $c['away']['losses']), null, null],
        ['Biggest win',       static fn($c) => $c['biggest_win'] ? $c['biggest_win']['score'] . ' v ' . $c['biggest_win']['opp'] : '—', null, null],
        ['Biggest defeat',    static fn($c) => $c['biggest_loss'] ? $c['biggest_loss']['score'] . ' v ' . $c['biggest_loss']['opp'] : '—', null, null],
    ];
}

/**
 * Full data model for the "Compare seasons" view.
 *
 * @param list<array<string,mixed>> $allSeasons  every season row (id, name, start_date, …)
 * @param array{
 *   season_ids?: array<int|string>,
 *   competitions?: array<string>,
 *   venue?: string,
 *   metrics?: array<string>,
 *   events_by_fixture?: array<int, array>,
 *   data_file?: string
 * } $opts
 * @return array<string,mixed>
 */
function hub_stats_compare_build(PDO $pdo, array $allSeasons, array $opts): array
{
    require_once __DIR__ . '/player_match_stats.php';

    $metricLabels = hub_stats_compare_metric_labels();

    $seasonById = [];
    foreach ($allSeasons as $s) {
        $seasonById[(int)$s['id']] = $s;
    }

    // Seasons ---------------------------------------------------------------
    $seasonIds = [];
    foreach ((array)($opts['season_ids'] ?? []) as $rid) {
        $rid = (int)$rid;
        if (isset($seasonById[$rid]) && !in_array($rid, $seasonIds, true)) {
            $seasonIds[] = $rid;
        }
    }
    if ($seasonIds === []) {
        $played = $pdo->query("SELECT season_id, COUNT(*) FROM match_fixtures WHERE status = 'played' GROUP BY season_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        $withPlayed = array_values(array_filter($allSeasons, static fn(array $s): bool => (int)($played[(int)$s['id']] ?? 0) > 0));
        $seasonIds = array_map(static fn(array $s): int => (int)$s['id'], array_slice($withPlayed, -2));
    }
    usort($seasonIds, static fn(int $a, int $b): int =>
        strcmp((string)($seasonById[$a]['start_date'] ?? ''), (string)($seasonById[$b]['start_date'] ?? '')) ?: ($a <=> $b));

    // Venue ---------------------------------------------------------------
    $venue = in_array($opts['venue'] ?? '', ['home', 'away'], true) ? (string)$opts['venue'] : '';

    // Competitions ------------------------------------------------------
    $competitionsAvailable = [];
    if ($seasonIds) {
        $ph = implode(',', array_fill(0, count($seasonIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT DISTINCT competition FROM match_fixtures
             WHERE season_id IN ($ph) AND status = 'played'
               AND competition IS NOT NULL AND competition <> '' ORDER BY competition"
        );
        $stmt->execute($seasonIds);
        $competitionsAvailable = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $competitions = [];
    foreach ((array)($opts['competitions'] ?? []) as $c) {
        $c = (string)$c;
        if (in_array($c, $competitionsAvailable, true) && !in_array($c, $competitions, true)) {
            $competitions[] = $c;
        }
    }

    // Metrics ---------------------------------------------------------
    $metrics = [];
    foreach ((array)($opts['metrics'] ?? []) as $m) {
        if (is_string($m) && isset($metricLabels[$m]) && !in_array($m, $metrics, true)) {
            $metrics[] = $m;
        }
    }
    if ($metrics === []) {
        $metrics = ['goals'];
    }
    $metrics = array_values(array_intersect(array_keys($metricLabels), $metrics));

    // Player status (name -> left the club) --------------------------
    $playerStatusByNorm = [];
    foreach ($pdo->query(
        "SELECT name, status, active FROM players
         ORDER BY (status = 'current' AND active = 1) DESC, active DESC, id ASC"
    ) as $pr) {
        $norm = hub_player_stats_normalize_name((string)$pr['name']);
        if ($norm === '' || isset($playerStatusByNorm[$norm])) {
            continue;
        }
        $status = (string)$pr['status'];
        $playerStatusByNorm[$norm] = [
            'status' => $status,
            'left' => (int)$pr['active'] === 0 || in_array($status, ['left', 'retired'], true),
        ];
    }

    // Match events --------------------------------------------------
    // data_file is accepted for backward compatibility but no longer read —
    // events always come straight from matchday_events now.
    $dataFile = (string)($opts['data_file'] ?? '');
    $eventsByFixture = $opts['events_by_fixture'] ?? null;
    if (!is_array($eventsByFixture)) {
        $eventsByFixture = hub_matchday_events_by_fixture($pdo);
    }

    // Per-season aggregation --------------------------------------
    $team = [];
    $playerCompare = [];
    $playerNames = [];
    $stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE season_id = ? AND status = ? ORDER BY match_date ASC, kickoff_time ASC, id ASC');
    foreach ($seasonIds as $sid) {
        $stmt->execute([$sid, 'played']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($venue !== '') {
            $want = $venue === 'home' ? 1 : 0;
            $rows = array_values(array_filter($rows, static fn(array $f): bool => (int)$f['is_home'] === $want));
        }
        if ($competitions !== []) {
            $rows = array_values(array_filter($rows, static fn(array $f): bool => in_array((string)($f['competition'] ?? ''), $competitions, true)));
        }

        $sum = hub_stats_summary($rows);
        $home = hub_stats_summary(array_filter($rows, static fn(array $f): bool => (int)$f['is_home'] === 1));
        $away = hub_stats_summary(array_filter($rows, static fn(array $f): bool => (int)$f['is_home'] === 0));
        $points = $sum['wins'] * 3 + $sum['draws'];
        $failedToScore = 0;
        $biggestWin = null;
        $biggestLoss = null;
        foreach ($rows as $f) {
            $r = hub_stats_result($f);
            if ($r === null) {
                continue;
            }
            if ($r['for'] === 0) {
                $failedToScore++;
            }
            $margin = $r['for'] - $r['against'];
            if ($r['result'] === 'W' && ($biggestWin === null || $margin > $biggestWin['margin'])) {
                $biggestWin = ['margin' => $margin, 'score' => $r['for'] . '–' . $r['against'], 'opp' => (string)$f['opponent']];
            }
            if ($r['result'] === 'L' && ($biggestLoss === null || $margin < $biggestLoss['margin'])) {
                $biggestLoss = ['margin' => $margin, 'score' => $r['for'] . '–' . $r['against'], 'opp' => (string)$f['opponent']];
            }
        }

        $names = [];
        foreach ($rows as $f) {
            foreach (['starting11_starters_json', 'starting11_substitutes_json'] as $field) {
                $lineup = json_decode((string)($f[$field] ?? '[]'), true);
                foreach (is_array($lineup) ? $lineup : [] as $name) {
                    if (is_string($name) && trim($name) !== '') {
                        $names[hub_player_stats_normalize_name($name)] = $name;
                    }
                }
            }
            foreach ($eventsByFixture[(int)$f['id']] ?? [] as $event) {
                if (hub_player_stats_is_club_player_event($event) && trim((string)($event['player'] ?? '')) !== '') {
                    $names[hub_player_stats_normalize_name((string)$event['player'])] = (string)$event['player'];
                }
            }
        }

        $team[$sid] = [
            'summary' => $sum,
            'points' => $points,
            'ppg' => $sum['scored'] ? $points / $sum['scored'] : null,
            'win_pct' => $sum['scored'] ? 100 * $sum['wins'] / $sum['scored'] : null,
            'gf_pg' => $sum['scored'] ? $sum['goals_for'] / $sum['scored'] : null,
            'ga_pg' => $sum['scored'] ? $sum['goals_against'] / $sum['scored'] : null,
            'cs_pct' => $sum['scored'] ? 100 * $sum['clean_sheets'] / $sum['scored'] : null,
            'failed_to_score' => $failedToScore,
            'home' => $home,
            'away' => $away,
            'biggest_win' => $biggestWin,
            'biggest_loss' => $biggestLoss,
            'has_players' => $names !== [],
        ];

        foreach ($names as $norm => $displayName) {
            $playerCompare[$sid][$norm] = hub_player_match_stats($pdo, $sid, $displayName, $dataFile, $rows, $eventsByFixture);
            if (!isset($playerNames[$norm]) || mb_strlen($displayName) > mb_strlen($playerNames[$norm])) {
                $playerNames[$norm] = $displayName;
            }
        }
    }

    $playerSeasons = array_values(array_filter($seasonIds, static fn(int $sid): bool => !empty($team[$sid]['has_players'])));
    $noPlayerSeasons = array_values(array_filter($seasonIds, static fn(int $sid): bool => empty($team[$sid]['has_players'])));
    $latestPlayerSeason = $playerSeasons ? (int)end($playerSeasons) : 0;

    $players = [];
    foreach ($metrics as $metric) {
        $grid = [];
        foreach ($playerSeasons as $sid) {
            foreach ($playerCompare[$sid] ?? [] as $norm => $p) {
                $grid[$norm][$sid] = (int)($p[$metric] ?? 0);
            }
        }
        $prows = [];
        $hiddenZero = 0;
        foreach ($grid as $norm => $vals) {
            $total = 0;
            foreach ($playerSeasons as $sid) {
                $total += $vals[$sid] ?? 0;
            }
            if ($total === 0) {
                $hiddenZero++;
                continue;
            }
            $st = $playerStatusByNorm[$norm] ?? null;
            $prows[] = [
                'name' => $playerNames[$norm] ?? $norm,
                'vals' => $vals,
                'total' => $total,
                'left' => (bool)($st['left'] ?? false),
                'status' => (string)($st['status'] ?? ''),
            ];
        }
        usort($prows, static fn(array $a, array $b): int =>
            (($a['left'] ? 1 : 0) <=> ($b['left'] ? 1 : 0))
            ?: ($b['total'] <=> $a['total'])
            ?: (($b['vals'][$latestPlayerSeason] ?? 0) <=> ($a['vals'][$latestPlayerSeason] ?? 0))
            ?: strcasecmp($a['name'], $b['name']));
        $players[$metric] = [
            'rows' => $prows,
            'hidden_zero' => $hiddenZero,
            'any_left' => (bool)array_filter($prows, static fn(array $r): bool => $r['left']),
            'better' => in_array($metric, ['yellow_cards', 'red_cards'], true) ? 'low' : 'high',
        ];
    }

    return [
        'season_ids' => $seasonIds,
        'season_by_id' => $seasonById,
        'venue' => $venue,
        'competitions_available' => $competitionsAvailable,
        'competitions' => $competitions,
        'metrics' => $metrics,
        'metric_labels' => $metricLabels,
        'team' => $team,
        'player_seasons' => $playerSeasons,
        'no_player_seasons' => $noPlayerSeasons,
        'latest_player_season' => $latestPlayerSeason,
        'players' => $players,
    ];
}

/**
 * Match-by-match breakdown behind one cell of the "Compare seasons" player
 * tables — e.g. which games a player's goals came in, and at what minute.
 * Walks the same lineup/event data as hub_player_match_stats(), but keeps a
 * row per fixture instead of collapsing to a season total.
 *
 * @param list<int> $seasonIds
 * @param array<int,array<string,mixed>> $seasonById season_id => season row (needs 'name')
 * @param list<string> $competitions empty = all competitions
 * @return array{player:string,metric:string,total:int,rows:list<array<string,mixed>>}
 */
function hub_player_stat_match_rows(
    PDO $pdo,
    array $seasonIds,
    array $seasonById,
    string $playerName,
    string $metric,
    array $competitions,
    string $venue,
    string $dataFile
): array {
    require_once __DIR__ . '/player_match_stats.php';

    $normalizedPlayerName = hub_player_stats_normalize_name($playerName);
    $rows = [];
    if ($normalizedPlayerName === '' || $seasonIds === []) {
        return ['player' => $playerName, 'metric' => $metric, 'total' => 0, 'rows' => []];
    }

    // $dataFile is accepted for backward compatibility but no longer read —
    // events always come straight from matchday_events now.
    $eventsByFixture = hub_matchday_events_by_fixture($pdo);

    $stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE season_id = ? AND status = ? ORDER BY match_date ASC, kickoff_time ASC, id ASC');
    foreach ($seasonIds as $sid) {
        $stmt->execute([$sid, 'played']);
        $fixtures = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($venue !== '') {
            $want = $venue === 'home' ? 1 : 0;
            $fixtures = array_values(array_filter($fixtures, static fn(array $f): bool => (int)$f['is_home'] === $want));
        }
        if ($competitions !== []) {
            $fixtures = array_values(array_filter($fixtures, static fn(array $f): bool => in_array((string)($f['competition'] ?? ''), $competitions, true)));
        }

        foreach ($fixtures as $fixture) {
            $starters = json_decode((string)($fixture['starting11_starters_json'] ?? '[]'), true);
            $substitutes = json_decode((string)($fixture['starting11_substitutes_json'] ?? '[]'), true);
            $starters = is_array($starters) ? array_values($starters) : [];
            $substitutes = is_array($substitutes) ? array_values($substitutes) : [];

            $starterIndex = null;
            foreach ($starters as $index => $starter) {
                if (hub_player_stats_normalize_name((string)$starter) === $normalizedPlayerName) {
                    $starterIndex = (int)$index;
                    break;
                }
            }
            $isStarter = $starterIndex !== null;
            $isNamedSubstitute = false;
            foreach ($substitutes as $substitute) {
                if (hub_player_stats_normalize_name((string)$substitute) === $normalizedPlayerName) {
                    $isNamedSubstitute = true;
                    break;
                }
            }

            $events = array_values(array_filter($eventsByFixture[(int)$fixture['id']] ?? [], 'is_array'));
            usort($events, static fn(array $a, array $b): int => (int)($a['sequence'] ?? 0) <=> (int)($b['sequence'] ?? 0));

            $onMinute = null;
            $offMinute = null;
            $opponentGoalsFromEvents = 0;
            $hasFullTimeEvent = false;
            $goalMinutes = [];
            // Goals don't get their own event type — a penalty is still
            // recorded as type "goal", just with note "Penalty" (set by the
            // COMET PDF importer when the source report says so). Track it
            // alongside each minute so the detail text can call it out.
            $goalIsPenalty = [];
            $yellowMinutes = [];
            $redMinutes = [];

            foreach ($events as $event) {
                $type = (string)($event['type'] ?? '');
                $team = (string)($event['team'] ?? '');
                $eventPlayer = hub_player_stats_normalize_name((string)($event['player'] ?? ''));
                // 'penalty_scored' is matchday_events' real type for a penalty goal — the
                // old matches.json projection collapsed it to plain 'goal' (keeping only a
                // "Penalty" note), which is what the note-based penalty check below still
                // handles for that legacy shape; recognizing the type directly here is the
                // more reliable signal now that events come straight from the DB.
                $isScoringEvent = $type === 'goal' || $type === 'penalty_scored' || ($type === 'penalty' && (string)($event['outcome'] ?? '') === 'scored');
                $minute = hub_player_stats_event_minute($event['minute'] ?? '');

                if ($isScoringEvent && $team === 'opponent') {
                    $opponentGoalsFromEvents++;
                }
                if ($type === 'full_time') {
                    $hasFullTimeEvent = true;
                }
                if ($team === 'svfc' && $eventPlayer === $normalizedPlayerName) {
                    if ($isScoringEvent && empty($event['own_goal'])) {
                        $goalMinutes[] = $minute;
                        $goalIsPenalty[] = $type === 'penalty_scored' || trim((string)($event['note'] ?? '')) === 'Penalty';
                    }
                    if ($type === 'yellow_card' || ($type === 'card' && (string)($event['card_type'] ?? '') === 'yellow')) {
                        $yellowMinutes[] = $minute;
                    }
                    if ($type === 'red_card' || ($type === 'card' && (string)($event['card_type'] ?? '') === 'red')) {
                        $redMinutes[] = $minute;
                    }
                }

                if ($type !== 'substitution' || $team !== 'svfc') {
                    continue;
                }
                $changes = is_array($event['substitutions'] ?? null) ? $event['substitutions'] : [];
                if ($changes === []) {
                    $changes[] = ['off' => (string)($event['player'] ?? ''), 'on' => (string)($event['secondary_player'] ?? '')];
                }
                foreach ($changes as $change) {
                    if (!is_array($change)) {
                        continue;
                    }
                    if ($offMinute === null && hub_player_stats_normalize_name((string)($change['off'] ?? '')) === $normalizedPlayerName) {
                        $offMinute = $minute;
                    }
                    if ($onMinute === null && hub_player_stats_normalize_name((string)($change['on'] ?? '')) === $normalizedPlayerName) {
                        $onMinute = $minute;
                    }
                }
            }

            $played = false;
            $minutesPlayed = 0;
            $apparanceDetail = '';
            if ($isStarter) {
                $played = true;
                $minutesPlayed = $offMinute ?? 90;
                $apparanceDetail = $offMinute !== null ? "Started, off {$offMinute}\u{2032}" : 'Started, played 90 minutes';
            } elseif ($isNamedSubstitute && $onMinute !== null) {
                $played = true;
                $minutesPlayed = max(0, 90 - $onMinute);
                $apparanceDetail = "Came on {$onMinute}\u{2032}" . ($offMinute !== null && $offMinute > $onMinute ? ", off {$offMinute}\u{2032}" : '');
            }

            $cleanSheet = false;
            if ($isStarter && $starterIndex === 0) {
                $opponentScore = null;
                if ($fixture['full_time_home_score'] !== null && $fixture['full_time_away_score'] !== null) {
                    $opponentScore = (int)$fixture[(int)$fixture['is_home'] === 1 ? 'full_time_away_score' : 'full_time_home_score'];
                } elseif ($hasFullTimeEvent) {
                    $opponentScore = $opponentGoalsFromEvents;
                }
                $cleanSheet = $opponentScore === 0;
            }

            $value = 0;
            $detail = '';
            switch ($metric) {
                case 'goals':
                    $value = count($goalMinutes);
                    $goalLabels = array_map(
                        static fn($m, $i) => $m . "\u{2032}" . ($goalIsPenalty[$i] ? ' (pen)' : ''),
                        $goalMinutes,
                        array_keys($goalMinutes)
                    );
                    $detail = $value === 1 ? "Scored \u{2013} {$goalLabels[0]}" : implode(', ', $goalLabels);
                    break;
                case 'yellow_cards':
                    $value = count($yellowMinutes);
                    $detail = 'Yellow card ' . implode(', ', array_map(static fn($m) => $m . "\u{2032}", $yellowMinutes));
                    break;
                case 'red_cards':
                    $value = count($redMinutes);
                    $detail = 'Red card ' . implode(', ', array_map(static fn($m) => $m . "\u{2032}", $redMinutes));
                    break;
                case 'clean_sheets':
                    $value = $cleanSheet ? 1 : 0;
                    $detail = 'Clean sheet';
                    break;
                case 'appearances':
                    $value = $played ? 1 : 0;
                    $detail = $apparanceDetail;
                    break;
                case 'starts':
                    $value = $isStarter ? 1 : 0;
                    $detail = $isStarter ? $apparanceDetail : '';
                    break;
                case 'substitute_appearances':
                    $value = ($isNamedSubstitute && $onMinute !== null) ? 1 : 0;
                    $detail = $value ? $apparanceDetail : '';
                    break;
                case 'minutes_played':
                    $value = $minutesPlayed;
                    $detail = $played ? "{$minutesPlayed} minutes" : '';
                    break;
            }

            if ($value <= 0) {
                continue;
            }

            $result = hub_stats_result($fixture);
            $rows[] = [
                'season_name' => (string)($seasonById[$sid]['name'] ?? $sid),
                'date' => (string)$fixture['match_date'],
                'kickoff' => (string)($fixture['kickoff_time'] ?? ''),
                'opponent' => (string)$fixture['opponent'],
                'venue' => (int)$fixture['is_home'] === 1 ? 'Home' : 'Away',
                'competition' => (string)($fixture['competition'] ?? ''),
                'score' => $result ? $result['for'] . "\u{2013}" . $result['against'] : null,
                'result' => $result['result'] ?? null,
                'value' => $value,
                'detail' => $detail,
            ];
        }
    }

    return [
        'player' => $playerName,
        'metric' => $metric,
        'total' => array_sum(array_column($rows, 'value')),
        'rows' => $rows,
    ];
}

/** @return array{appearances:int,goals:int,yellow_cards:int,red_cards:int,clean_sheets:int} */
function hub_player_all_events_empty_summary(): array
{
    return ['appearances' => 0, 'goals' => 0, 'yellow_cards' => 0, 'red_cards' => 0, 'clean_sheets' => 0];
}

/**
 * Match-by-match breakdown for a player across every metric at once — one
 * row per match, with `detail` a newline-separated list of that match's
 * events in chronological order (e.g. "Started\nScored – 34′\nOff 78′"),
 * for the Players tab's "click a name" modal. Same fixture-walking logic as
 * hub_player_stat_match_rows(), just not filtered down to one metric.
 *
 * `rows` includes every match the player appeared in, scoring or not — so
 * `total` (row count) means "matches played", NOT "goals" or any other
 * single number; use `summary` for the actual per-metric totals.
 *
 * @param list<int> $seasonIds
 * @param array<int,array<string,mixed>> $seasonById season_id => season row (needs 'name')
 * @param list<string> $competitions empty = all competitions
 * @return array{player:string,metric:string,total:int,rows:list<array<string,mixed>>,summary:array{appearances:int,goals:int,yellow_cards:int,red_cards:int,clean_sheets:int}}
 */
function hub_player_all_events_match_rows(
    PDO $pdo,
    array $seasonIds,
    array $seasonById,
    string $playerName,
    array $competitions,
    string $venue,
    string $dataFile
): array {
    require_once __DIR__ . '/player_match_stats.php';

    $normalizedPlayerName = hub_player_stats_normalize_name($playerName);
    $rows = [];
    if ($normalizedPlayerName === '' || $seasonIds === []) {
        return ['player' => $playerName, 'metric' => 'all', 'total' => 0, 'rows' => [], 'summary' => hub_player_all_events_empty_summary()];
    }

    $summary = hub_player_all_events_empty_summary();

    // $dataFile is accepted for backward compatibility but no longer read —
    // events always come straight from matchday_events now.
    $eventsByFixture = hub_matchday_events_by_fixture($pdo);

    $stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE season_id = ? AND status = ? ORDER BY match_date ASC, kickoff_time ASC, id ASC');
    foreach ($seasonIds as $sid) {
        $stmt->execute([$sid, 'played']);
        $fixtures = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($venue !== '') {
            $want = $venue === 'home' ? 1 : 0;
            $fixtures = array_values(array_filter($fixtures, static fn(array $f): bool => (int)$f['is_home'] === $want));
        }
        if ($competitions !== []) {
            $fixtures = array_values(array_filter($fixtures, static fn(array $f): bool => in_array((string)($f['competition'] ?? ''), $competitions, true)));
        }

        foreach ($fixtures as $fixture) {
            $starters = json_decode((string)($fixture['starting11_starters_json'] ?? '[]'), true);
            $substitutes = json_decode((string)($fixture['starting11_substitutes_json'] ?? '[]'), true);
            $starters = is_array($starters) ? array_values($starters) : [];
            $substitutes = is_array($substitutes) ? array_values($substitutes) : [];

            $starterIndex = null;
            foreach ($starters as $index => $starter) {
                if (hub_player_stats_normalize_name((string)$starter) === $normalizedPlayerName) {
                    $starterIndex = (int)$index;
                    break;
                }
            }
            $isStarter = $starterIndex !== null;
            $isNamedSubstitute = false;
            foreach ($substitutes as $substitute) {
                if (hub_player_stats_normalize_name((string)$substitute) === $normalizedPlayerName) {
                    $isNamedSubstitute = true;
                    break;
                }
            }

            $events = array_values(array_filter($eventsByFixture[(int)$fixture['id']] ?? [], 'is_array'));
            usort($events, static fn(array $a, array $b): int => (int)($a['sequence'] ?? 0) <=> (int)($b['sequence'] ?? 0));

            $onMinute = null;
            $offMinute = null;
            $opponentGoalsFromEvents = 0;
            $hasFullTimeEvent = false;
            $goalMinutes = [];
            // Goals don't get their own event type — a penalty is still
            // recorded as type "goal", just with note "Penalty" (set by the
            // COMET PDF importer when the source report says so). Track it
            // alongside each minute so the detail text can call it out.
            $goalIsPenalty = [];
            $yellowMinutes = [];
            $redMinutes = [];

            foreach ($events as $event) {
                $type = (string)($event['type'] ?? '');
                $team = (string)($event['team'] ?? '');
                $eventPlayer = hub_player_stats_normalize_name((string)($event['player'] ?? ''));
                // 'penalty_scored' is matchday_events' real type for a penalty goal — the
                // old matches.json projection collapsed it to plain 'goal' (keeping only a
                // "Penalty" note), which is what the note-based penalty check below still
                // handles for that legacy shape; recognizing the type directly here is the
                // more reliable signal now that events come straight from the DB.
                $isScoringEvent = $type === 'goal' || $type === 'penalty_scored' || ($type === 'penalty' && (string)($event['outcome'] ?? '') === 'scored');
                $minute = hub_player_stats_event_minute($event['minute'] ?? '');

                if ($isScoringEvent && $team === 'opponent') {
                    $opponentGoalsFromEvents++;
                }
                if ($type === 'full_time') {
                    $hasFullTimeEvent = true;
                }
                if ($team === 'svfc' && $eventPlayer === $normalizedPlayerName) {
                    if ($isScoringEvent && empty($event['own_goal'])) {
                        $goalMinutes[] = $minute;
                        $goalIsPenalty[] = $type === 'penalty_scored' || trim((string)($event['note'] ?? '')) === 'Penalty';
                    }
                    if ($type === 'yellow_card' || ($type === 'card' && (string)($event['card_type'] ?? '') === 'yellow')) {
                        $yellowMinutes[] = $minute;
                    }
                    if ($type === 'red_card' || ($type === 'card' && (string)($event['card_type'] ?? '') === 'red')) {
                        $redMinutes[] = $minute;
                    }
                }

                if ($type !== 'substitution' || $team !== 'svfc') {
                    continue;
                }
                $changes = is_array($event['substitutions'] ?? null) ? $event['substitutions'] : [];
                if ($changes === []) {
                    $changes[] = ['off' => (string)($event['player'] ?? ''), 'on' => (string)($event['secondary_player'] ?? '')];
                }
                foreach ($changes as $change) {
                    if (!is_array($change)) {
                        continue;
                    }
                    if ($offMinute === null && hub_player_stats_normalize_name((string)($change['off'] ?? '')) === $normalizedPlayerName) {
                        $offMinute = $minute;
                    }
                    if ($onMinute === null && hub_player_stats_normalize_name((string)($change['on'] ?? '')) === $normalizedPlayerName) {
                        $onMinute = $minute;
                    }
                }
            }

            $played = false;
            $minutesPlayed = 0;
            // Individual timed lines (minute => label), one per event, sorted into
            // match order below rather than grouped by event type on one line.
            $timeline = [];
            if ($isStarter) {
                $played = true;
                $minutesPlayed = $offMinute ?? 90;
                $timeline[] = [0, 'Started'];
                if ($offMinute !== null) {
                    $timeline[] = [$offMinute, "Off {$offMinute}\u{2032}"];
                }
            } elseif ($isNamedSubstitute && $onMinute !== null) {
                $played = true;
                $minutesPlayed = max(0, 90 - $onMinute);
                $timeline[] = [$onMinute, "Came on {$onMinute}\u{2032}"];
                if ($offMinute !== null && $offMinute > $onMinute) {
                    $timeline[] = [$offMinute, "Off {$offMinute}\u{2032}"];
                }
            }

            if (!$played && !$goalMinutes && !$yellowMinutes && !$redMinutes) {
                continue;
            }

            $cleanSheet = false;
            if ($isStarter && $starterIndex === 0) {
                $opponentScore = null;
                if ($fixture['full_time_home_score'] !== null && $fixture['full_time_away_score'] !== null) {
                    $opponentScore = (int)$fixture[(int)$fixture['is_home'] === 1 ? 'full_time_away_score' : 'full_time_home_score'];
                } elseif ($hasFullTimeEvent) {
                    $opponentScore = $opponentGoalsFromEvents;
                }
                $cleanSheet = $opponentScore === 0;
            }

            foreach ($goalMinutes as $i => $m) {
                $timeline[] = [$m, "Scored \u{2013} {$m}\u{2032}" . ($goalIsPenalty[$i] ? ' (pen)' : '')];
            }
            foreach ($yellowMinutes as $m) {
                $timeline[] = [$m, "Yellow card {$m}\u{2032}"];
            }
            foreach ($redMinutes as $m) {
                $timeline[] = [$m, "Red card {$m}\u{2032}"];
            }
            if ($cleanSheet) {
                // Not a single moment in the match — always last regardless of minute.
                $timeline[] = [PHP_INT_MAX, 'Clean sheet'];
            }
            usort($timeline, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $detailParts = array_column($timeline, 1);

            $summary['appearances'] += $played ? 1 : 0;
            $summary['goals'] += count($goalMinutes);
            $summary['yellow_cards'] += count($yellowMinutes);
            $summary['red_cards'] += count($redMinutes);
            $summary['clean_sheets'] += $cleanSheet ? 1 : 0;

            $result = hub_stats_result($fixture);
            $rows[] = [
                'season_name' => (string)($seasonById[$sid]['name'] ?? $sid),
                'date' => (string)$fixture['match_date'],
                'kickoff' => (string)($fixture['kickoff_time'] ?? ''),
                'opponent' => (string)$fixture['opponent'],
                'venue' => (int)$fixture['is_home'] === 1 ? 'Home' : 'Away',
                'competition' => (string)($fixture['competition'] ?? ''),
                'score' => $result ? $result['for'] . "\u{2013}" . $result['against'] : null,
                'result' => $result['result'] ?? null,
                'value' => 1,
                'detail' => implode("\n", $detailParts),
            ];
        }
    }

    return [
        'player' => $playerName,
        'metric' => 'all',
        'summary' => $summary,
        'total' => count($rows),
        'rows' => $rows,
    ];
}

/**
 * Renders the "click a name" modal's match-by-match rows as a PDF — same
 * dompdf pattern as invoice_pdf_render() / shop_vsn_order_form_pdf_render().
 *
 * @param list<array<string, mixed>> $rows from hub_player_stat_match_rows() or hub_player_all_events_match_rows()
 * @param array{appearances:int,goals:int,yellow_cards:int,red_cards:int,clean_sheets:int}|null $summary only set for the "all events" metric
 */
function hub_player_match_detail_pdf_render(
    PDO $pdo,
    string $playerName,
    string $metricLabel,
    array $rows,
    ?array $summary,
    bool $forceDownload = false
): void {
    require_once __DIR__ . '/site_settings.php';
    require_once __DIR__ . '/functions.php';
    $settings = site_settings_all($pdo);
    $clubName = $settings['club_name'] ?: 'MyClubHub';

    $html = '<!doctype html><html><head><meta charset="UTF-8"><style>
        @page { margin: 36px 40px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color:#21141a; font-size:12px; margin:0; }
        h1 { margin:0 0 2px; color:#4b0818; font-size:22px; }
        .muted { color:#6f6470; }
        table.items { width:100%; border-collapse:collapse; margin-top:14px; }
        table.items th { background:#4b0818; color:#fff; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.03em; padding:6px 8px; }
        table.items td { border-bottom:1px solid #eadfdf; padding:6px 8px; vertical-align:top; }
        table.items td.num, table.items th.num { text-align:right; white-space:nowrap; }
    </style></head><body>';

    $html .= '<div><strong>' . h($clubName) . '</strong></div>';
    $html .= '<h1>' . h($metricLabel) . " \u{2014} " . h($playerName) . '</h1>';

    if ($summary !== null) {
        $parts = [$summary['appearances'] . ' appearance' . ($summary['appearances'] === 1 ? '' : 's')];
        if ($summary['goals']) $parts[] = $summary['goals'] . ' goal' . ($summary['goals'] === 1 ? '' : 's');
        if ($summary['yellow_cards']) $parts[] = $summary['yellow_cards'] . ' yellow card' . ($summary['yellow_cards'] === 1 ? '' : 's');
        if ($summary['red_cards']) $parts[] = $summary['red_cards'] . ' red card' . ($summary['red_cards'] === 1 ? '' : 's');
        if ($summary['clean_sheets']) $parts[] = $summary['clean_sheets'] . ' clean sheet' . ($summary['clean_sheets'] === 1 ? '' : 's');
        $html .= '<div class="muted">' . h(implode(', ', $parts)) . '.</div>';
    } else {
        $total = array_sum(array_column($rows, 'value'));
        $html .= '<div class="muted">' . (int)$total . ' total across ' . count($rows) . ' match' . (count($rows) === 1 ? '' : 'es') . '.</div>';
    }
    $html .= '<div class="muted">Generated ' . h(date('d/m/Y H:i')) . '</div>';

    $html .= '<table class="items"><thead><tr>'
        . '<th>Date</th><th>Kick-off</th><th>Opponent</th><th>Venue</th><th>Competition</th><th>Score</th><th>Detail</th>'
        . '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $date = (string)($row['date'] ?? '');
        $dateLabel = $date !== '' ? date('d/m/Y', strtotime($date)) : '—';
        $kickoff = trim((string)($row['kickoff'] ?? ''));
        $kickoffLabel = $kickoff !== '' ? substr($kickoff, 0, 5) : '—';
        $html .= '<tr>'
            . '<td>' . h($dateLabel) . '</td>'
            . '<td>' . h($kickoffLabel) . '</td>'
            . '<td>' . h((string)($row['opponent'] ?? '')) . '</td>'
            . '<td>' . h((string)($row['venue'] ?? '')) . '</td>'
            . '<td>' . h((string)($row['competition'] ?? '')) . '</td>'
            . '<td>' . h((string)($row['score'] ?? '—')) . '</td>'
            . '<td>' . nl2br(h((string)($row['detail'] ?? ''))) . '</td>'
            . '</tr>';
    }
    if ($rows === []) {
        $html .= '<tr><td colspan="7">No matches found for the current filters.</td></tr>';
    }
    $html .= '</tbody></table>';
    $html .= '</body></html>';

    if (!class_exists(Dompdf\Dompdf::class)) {
        $autoloaders = [dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 2) . '/project_1/vendor/autoload.php'];
        foreach ($autoloaders as $autoloader) {
            if (is_file($autoloader)) {
                require_once $autoloader;
                break;
            }
        }
    }
    if (!class_exists(Dompdf\Dompdf::class)) {
        throw new RuntimeException('PDF export is unavailable.');
    }

    $dompdf = new Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    if (ob_get_length() !== false) {
        ob_clean();
    }
    $safeName = (string)preg_replace('/[^A-Za-z0-9]+/', '-', $playerName);
    $dompdf->stream(trim($safeName, '-') . '-match-by-match.pdf', ['Attachment' => $forceDownload]);
}
