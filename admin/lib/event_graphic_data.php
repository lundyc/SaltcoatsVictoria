<?php

declare(strict_types=1);

/**
 * Data for the in-game graphics (goal, yellow card, red card, penalty): which recorded match event a
 * graphic is about, and the facts the card and post text need (player, minute, score at that moment).
 * The choice is stored per fixture on match_fixtures.event_graphic_json, e.g.
 *   {"goal": {"event_id": 12}, "penalty": {"manual": {"side": "svfc", "player_id": 94, "minute": "34", "outcome": "scored"}}}
 * With nothing chosen the latest matching event is used.
 */

require_once __DIR__ . '/matchday_record.php';

/** Event types (as recorded on the Events page) that each graphic can be about. */
function nm_event_graphic_event_types(string $graphicType): array
{
    return match ($graphicType) {
        'goal' => ['goal', 'own_goal', 'penalty_scored'],
        'yellow_card' => ['yellow_card'],
        'red_card' => ['red_card', 'second_yellow'],
        'penalty' => ['penalty_scored', 'penalty_missed'],
        'penalty_awarded' => ['penalty_scored', 'penalty_missed'],
        'penalty_saved', 'penalty_missed' => ['penalty_missed'],
        default => [],
    };
}

function nm_event_graphic_is_event_type(string $graphicType): bool
{
    return nm_event_graphic_event_types($graphicType) !== [];
}

/** Matching events for a graphic, in match order, each with a readable label and the score after it. */
function nm_event_graphic_candidates(PDO $pdo, int $fixtureId, string $graphicType): array
{
    $want = nm_event_graphic_event_types($graphicType);
    if ($want === []) {
        return [];
    }
    $fixture = matchday_record_fixture($pdo, $fixtureId);
    $isHome = $fixture === null || (int)($fixture['is_home'] ?? 1) === 1;
    $types = matchday_record_event_types($pdo);
    $home = 0;
    $away = 0;
    $out = [];
    foreach (matchday_events_get($pdo, $fixtureId) as $ev) {
        $isGoal = ($types[(string)$ev['type']]['projects_to'] ?? '') === 'goal';
        if ($isGoal) {
            $creditsUs = (string)$ev['side'] === 'svfc';
            if ($creditsUs === $isHome) {
                $home++;
            } else {
                $away++;
            }
        }
        if (!in_array((string)$ev['type'], $want, true)) {
            continue;
        }
        // Penalty graphics: awarded = either team's penalty; saved = opposition miss our keeper saved; missed = any other miss.
        $evOutcome = (string)$ev['outcome'];
        if ($graphicType === 'penalty_saved' && !($evOutcome === 'saved' || ((string)$ev['side'] === 'opponent' && $evOutcome === ''))) {
            continue;
        }
        if ($graphicType === 'penalty_missed' && $evOutcome === 'saved') {
            continue;
        }
        $minute = $ev['minute'] === null ? '' : matchday_record_minute_label((int)$ev['minute'], (int)$ev['minute_extra']);
        $out[] = [
            'id' => (int)$ev['id'],
            'type' => (string)$ev['type'],
            'side' => (string)$ev['side'],
            'player_name' => trim((string)$ev['player_name']),
            'minute' => $minute,
            'own_goal' => !empty($ev['own_goal']) || (string)$ev['type'] === 'own_goal',
            'outcome' => (string)$ev['outcome'] !== '' ? (string)$ev['outcome'] : ((string)$ev['type'] === 'penalty_missed' ? 'missed' : 'scored'),
            'score' => [$home, $away],
            'label' => ($minute !== '' ? $minute . "' " : '') . ((string)$ev['player_name'] !== '' ? $ev['player_name'] : 'Unknown player')
                . ' (' . ((string)$ev['side'] === 'svfc' ? 'Saltcoats' : 'Opposition') . ' · ' . ($types[(string)$ev['type']]['label'] ?? (string)$ev['type']) . ')',
        ];
    }
    return $out;
}

function nm_event_graphic_choice(PDO $pdo, int $fixtureId, string $graphicType): array
{
    $raw = (string)$pdo->query('SELECT event_graphic_json FROM match_fixtures WHERE id = ' . (int)$fixtureId)->fetchColumn();
    $all = json_decode($raw, true);
    $choice = is_array($all) ? ($all[$graphicType] ?? []) : [];
    return is_array($choice) ? $choice : [];
}

/**
 * What the card shows. Returns null when there is nothing to show yet.
 *
 * @return array{side:string,team:string,player_name:string,player_id:int,minute:string,own_goal:bool,outcome:string,type:string,score:array{0:int,1:int},home_name:string,away_name:string,is_home:bool,manual:bool}|null
 */
function nm_event_graphic_resolve(PDO $pdo, int $fixtureId, string $graphicType): ?array
{
    $fixture = matchday_record_fixture($pdo, $fixtureId);
    if ($fixture === null) {
        return null;
    }
    $isHome = (int)($fixture['is_home'] ?? 1) === 1;
    $club = 'Saltcoats Victoria';
    $opp = trim((string)($fixture['opponent'] ?? ''));
    $choice = nm_event_graphic_choice($pdo, $fixtureId, $graphicType);
    $candidates = nm_event_graphic_candidates($pdo, $fixtureId, $graphicType);

    $picked = null;
    $manual = false;
    if (isset($choice['manual']) && is_array($choice['manual'])) {
        $m = $choice['manual'];
        $side = ($m['side'] ?? '') === 'opponent' ? 'opponent' : 'svfc';
        $name = trim((string)($m['player_name'] ?? ''));
        $pid = (int)($m['player_id'] ?? 0);
        if ($side === 'svfc' && $pid > 0) {
            $stmt = $pdo->prepare('SELECT name FROM players WHERE id = :id');
            $stmt->execute([':id' => $pid]);
            $name = (string)($stmt->fetchColumn() ?: $name);
        }
        $default = ['penalty_saved' => 'saved', 'penalty_missed' => 'missed', 'penalty_awarded' => 'awarded'][$graphicType] ?? 'scored';
        $picked = [
            'type' => $graphicType === 'red_card' ? 'red_card' : ($graphicType === 'yellow_card' ? 'yellow_card' : ($graphicType === 'penalty' ? 'penalty_scored' : (in_array($graphicType, ['penalty_saved', 'penalty_missed'], true) ? 'penalty_missed' : ($graphicType === 'penalty_awarded' ? 'penalty_scored' : 'goal')))),
            'side' => $side, 'player_name' => $name, 'minute' => trim((string)($m['minute'] ?? '')),
            'own_goal' => !empty($m['own_goal']), 'outcome' => (string)($m['outcome'] ?? $default) ?: $default,
            'score' => isset($m['score_home'], $m['score_away']) ? [(int)$m['score_home'], (int)$m['score_away']] : nm_event_graphic_live_score($pdo, $fixtureId),
        ];
        $manual = true;
    } else {
        $wantId = (int)($choice['event_id'] ?? 0);
        foreach ($candidates as $c) {
            if ($wantId > 0 && $c['id'] === $wantId) {
                $picked = $c;
                break;
            }
        }
        if ($picked === null && $candidates !== []) {
            $picked = $candidates[count($candidates) - 1];
        }
    }
    if ($picked === null) {
        return null;
    }
    $playerId = 0;
    if ($picked['side'] === 'svfc' && $picked['player_name'] !== '') {
        $stmt = $pdo->prepare('SELECT id FROM players WHERE TRIM(name) = :n AND active = 1 LIMIT 1');
        $stmt->execute([':n' => $picked['player_name']]);
        $playerId = (int)($stmt->fetchColumn() ?: 0);
    }
    return [
        'side' => $picked['side'],
        'team' => $picked['side'] === 'svfc' ? $club : $opp,
        'player_name' => $picked['player_name'],
        'player_id' => $playerId,
        'minute' => $picked['minute'],
        'own_goal' => (bool)$picked['own_goal'],
        'outcome' => $picked['outcome'],
        'type' => $picked['type'],
        'score' => $picked['score'],
        'home_name' => $isHome ? $club : $opp,
        'away_name' => $isHome ? $opp : $club,
        'is_home' => $isHome,
        'manual' => $manual,
    ];
}


/**
 * Our goalkeeper for a fixture: the starter marked GK on the line-up, else a starter registered as a goalkeeper.
 *
 * @return array{id:int,name:string,num:int|null}|null
 */
function nm_event_graphic_keeper(PDO $pdo, int $fixtureId): ?array
{
    // A goalkeeper picked by hand on the Penalty saved page wins over the line-up.
    $choice = nm_event_graphic_choice($pdo, $fixtureId, 'penalty_saved');
    $pickedId = (int)($choice['manual']['player_id'] ?? 0);
    if ($pickedId > 0) {
        $n = $pdo->prepare('SELECT p.name, l.shirt_number FROM players p LEFT JOIN matchday_lineups l ON l.player_id = p.id AND l.fixture_id = :f AND l.side = \'svfc\' WHERE p.id = :id LIMIT 1');
        $n->execute([':f' => $fixtureId, ':id' => $pickedId]);
        $row = $n->fetch(PDO::FETCH_ASSOC);
        if ($row && trim((string)$row['name']) !== '') {
            return ['id' => $pickedId, 'name' => trim((string)$row['name']), 'num' => $row['shirt_number'] !== null ? (int)$row['shirt_number'] : null];
        }
    }
    $rows = $pdo->prepare("SELECT l.player_id, l.player_name, l.shirt_number, l.position_label, p.position AS player_position
        FROM matchday_lineups l LEFT JOIN players p ON p.id = l.player_id
        WHERE l.fixture_id = :f AND l.side = 'svfc' AND l.is_starting = 1 ORDER BY l.sort_order ASC, l.id ASC");
    $rows->execute([':f' => $fixtureId]);
    $all = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ([static fn(array $r): bool => strtoupper(trim((string)$r['position_label'])) === 'GK',
              static fn(array $r): bool => stripos((string)$r['player_position'], 'goalkeeper') !== false] as $test) {
        foreach ($all as $r) {
            if ($test($r)) {
                $name = trim((string)$r['player_name']);
                if ($name === '' && (int)$r['player_id'] > 0) {
                    $n = $pdo->prepare('SELECT name FROM players WHERE id = :id');
                    $n->execute([':id' => (int)$r['player_id']]);
                    $name = trim((string)$n->fetchColumn());
                }
                return ['id' => (int)$r['player_id'], 'name' => $name, 'num' => $r['shirt_number'] !== null ? (int)$r['shirt_number'] : null];
            }
        }
    }
    return null;
}


/** The score so far on the recorded events, as [home, away]. */
function nm_event_graphic_live_score(PDO $pdo, int $fixtureId): array
{
    $sum = matchday_record_summary($pdo, $fixtureId);
    return [(int)$sum['home_score'], (int)$sum['away_score']];
}
