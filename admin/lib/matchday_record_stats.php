<?php

declare(strict_types=1);

/**
 * Hub-native stats over the matchday_* tables (Stage 6 of the veo port).
 * Everything here is derived from matchday_lineups / matchday_events /
 * matchday_subs — no separate store. Only fixtures that actually have
 * matchday_* rows are counted.
 *
 * Not a port of the old veo stats engine (that needed video-tagged events);
 * this is appearances / goals / assists / cards / minutes / clean sheets.
 */

require_once __DIR__ . '/matchday_record.php';

/**
 * Season IDs that have at least one fixture with matchday_* data, plus the
 * fixture ids per season.
 *
 * @return array<int, list<int>> season_id => [fixture_id, ...]
 */
function matchday_stats_seasons_with_data(PDO $pdo): array
{
    matchday_record_ensure_schema($pdo);
    $rows = $pdo->query(
        "SELECT DISTINCT f.season_id, f.id
         FROM match_fixtures f
         WHERE EXISTS (SELECT 1 FROM matchday_lineups l WHERE l.fixture_id = f.id)
            OR EXISTS (SELECT 1 FROM matchday_events e WHERE e.fixture_id = f.id)
         ORDER BY f.season_id, f.match_date"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['season_id']][] = (int) $r['id'];
    }
    return $out;
}

/**
 * Per-fixture summary for the stats view: score, scorers, assisters, cards,
 * substitutions. Wraps matchday_record_summary() with a little more detail.
 *
 * @return array<string,mixed>
 */
function matchday_stats_fixture(PDO $pdo, int $fixtureId): array
{
    $summary = matchday_record_summary($pdo, $fixtureId);
    $fx = matchday_record_fixture($pdo, $fixtureId);
    $fixtureScore = null;
    if ($fx !== null && $fx['full_time_home_score'] !== null && $fx['full_time_away_score'] !== null) {
        $isHome = (int) ($fx['is_home'] ?? 1) === 1;
        $fixtureScore = [
            'svfc' => (int) ($isHome ? $fx['full_time_home_score'] : $fx['full_time_away_score']),
            'opponent' => (int) ($isHome ? $fx['full_time_away_score'] : $fx['full_time_home_score']),
        ];
    }
    $types = matchday_record_event_types($pdo);
    $events = matchday_events_get($pdo, $fixtureId);

    $assists = [];
    $cards = ['svfc' => [], 'opponent' => []];
    foreach ($events as $e) {
            if (($e['participant_type'] ?? 'player') === 'staff') {
                continue;
            }
        $cat = $types[(string) $e['type']]['category'] ?? 'other';
        $proj = $types[(string) $e['type']]['projects_to'] ?? '';
        if ($proj === 'goal' && (string) $e['side'] === 'svfc' && trim((string) $e['secondary_player_name']) !== '' && (int) $e['own_goal'] === 0) {
            $assists[] = trim((string) $e['secondary_player_name']);
        }
        if ($cat === 'card') {
            $side = (string) $e['side'] === 'opponent' ? 'opponent' : 'svfc';
            $cards[$side][] = [
                'name' => (string) $e['player_name'],
                'card' => (string) $e['card_type'] ?: ($e['type'] === 'red_card' || $e['type'] === 'second_yellow' ? 'red' : 'yellow'),
                'minute' => matchday_record_minute_label((int) $e['minute'], (int) $e['minute_extra'], $e['minute'] === null),
            ];
        }
    }

    return [
        'summary' => $summary,          // event-derived (advisory)
        'fixture_score' => $fixtureScore, // authoritative, or null
        'assists' => $assists,
        'cards' => $cards,
        'subs' => matchday_subs_get($pdo, $fixtureId),
    ];
}

/**
 * Per-player Saltcoats aggregates across a season's fixtures with matchday
 * data. Keyed by player_id when known, else by lower(player_name).
 *
 * @return array{
 *   players: list<array<string,mixed>>,
 *   team: array{P:int,W:int,D:int,L:int,GF:int,GA:int,clean_sheets:int}
 * }
 */
function matchday_stats_season(PDO $pdo, int $seasonId): array
{
    matchday_record_ensure_schema($pdo);
    $bySeason = matchday_stats_seasons_with_data($pdo);
    $fixtureIds = $bySeason[$seasonId] ?? [];

    $team = ['P' => 0, 'W' => 0, 'D' => 0, 'L' => 0, 'GF' => 0, 'GA' => 0, 'clean_sheets' => 0];
    $agg = [];

    $key = static fn(?int $pid, string $name): string => $pid ? 'id:' . $pid : 'nm:' . mb_strtolower(trim($name), 'UTF-8');
    $touch = static function (array &$agg, string $k, string $name) {
        if (!isset($agg[$k])) {
            $agg[$k] = [
                'name' => $name, 'apps' => 0, 'starts' => 0, 'sub_apps' => 0,
                'goals' => 0, 'assists' => 0, 'own_goals' => 0,
                'yellow' => 0, 'red' => 0, 'minutes' => 0, 'clean_sheets' => 0,
            ];
        } elseif ($agg[$k]['name'] === '' && $name !== '') {
            $agg[$k]['name'] = $name;
        }
    };

    foreach ($fixtureIds as $fid) {
        // Team record comes from the AUTHORITATIVE fixture score (Overview box /
        // COMET / apply-score), not from counting goal events — legacy fixtures
        // whose events were only backfilled have far fewer logged goals than
        // their real score. Only the per-player numbers below come from events.
        $fx = matchday_record_fixture($pdo, $fid);
        $hasScore = $fx !== null && $fx['full_time_home_score'] !== null && $fx['full_time_away_score'] !== null;
        $cleanSheet = false;
        if ($hasScore) {
            $isHome = (int) ($fx['is_home'] ?? 1) === 1;
            $us = (int) ($isHome ? $fx['full_time_home_score'] : $fx['full_time_away_score']);
            $them = (int) ($isHome ? $fx['full_time_away_score'] : $fx['full_time_home_score']);
            $team['P']++;
            $team['GF'] += $us;
            $team['GA'] += $them;
            if ($us > $them) {
                $team['W']++;
            } elseif ($us < $them) {
                $team['L']++;
            } else {
                $team['D']++;
            }
            if ($them === 0) {
                $team['clean_sheets']++;
                $cleanSheet = true;
            }
        }

        $lineup = matchday_lineup_get($pdo, $fid, 'svfc');
        $lineupById = [];
        $offMinuteByLineupId = [];
        $onMinuteByLineupId = [];
        foreach (matchday_subs_get($pdo, $fid, 'svfc') as $s) {
            if ($s['player_off_lineup_id']) {
                $offMinuteByLineupId[(int) $s['player_off_lineup_id']] = $s['minute'] === null ? 90 : (int) $s['minute'];
            }
            if ($s['player_on_lineup_id']) {
                $onMinuteByLineupId[(int) $s['player_on_lineup_id']] = $s['minute'] === null ? 0 : (int) $s['minute'];
            }
        }
        $fullTime = 90;
        foreach (matchday_periods_get($pdo, $fid) as $p) {
            if ($p['end_minute'] !== null) {
                $fullTime = max($fullTime, (int) $p['end_minute']);
            }
        }

        foreach ($lineup as $row) {
            $lid = (int) $row['id'];
            $lineupById[$lid] = $row;
            $k = $key($row['player_id'] ? (int) $row['player_id'] : null, (string) $row['player_name']);
            $touch($agg, $k, (string) $row['player_name']);

            $started = (int) $row['is_starting'] === 1;
            $cameOn = isset($onMinuteByLineupId[$lid]);
            if (!$started && !$cameOn) {
                continue; // unused sub
            }
            $agg[$k]['apps']++;
            if ($started) {
                $agg[$k]['starts']++;
                $end = $offMinuteByLineupId[$lid] ?? $fullTime;
                $agg[$k]['minutes'] += max(0, $end);
            } else {
                $agg[$k]['sub_apps']++;
                $agg[$k]['minutes'] += max(0, $fullTime - ($onMinuteByLineupId[$lid] ?? $fullTime));
            }
            if ($cleanSheet) {
                $agg[$k]['clean_sheets']++;
            }
        }

        $types = matchday_record_event_types($pdo);
        foreach (matchday_events_get($pdo, $fid) as $e) {
            $meta = $types[(string) $e['type']] ?? null;
            $proj = $meta['projects_to'] ?? '';
            $cat = $meta['category'] ?? 'other';
                    if (($e['participant_type'] ?? 'player') === 'staff') {
                        continue;
                    }
            $name = trim((string) $e['player_name']);
            $lid = $e['player_lineup_id'] ? (int) $e['player_lineup_id'] : 0;
            $pid = $lid && isset($lineupById[$lid]) && $lineupById[$lid]['player_id'] ? (int) $lineupById[$lid]['player_id'] : null;

            if ((int) $e['own_goal'] === 1 && $e['type'] === 'own_goal' && (string) $e['side'] === 'opponent') {
                // a Saltcoats player's own goal (counts for the opponent)
                $k = $key($pid, $name);
                $touch($agg, $k, $name);
                $agg[$k]['own_goals']++;
                continue;
            }
            if ((string) $e['side'] !== 'svfc') {
                continue;
            }
            if ($proj === 'goal' && (int) $e['own_goal'] === 0) {
                $k = $key($pid, $name);
                $touch($agg, $k, $name);
                $agg[$k]['goals']++;
                $sec = trim((string) $e['secondary_player_name']);
                if ($sec !== '') {
                    $sk = $key(null, $sec);
                    $touch($agg, $sk, $sec);
                    $agg[$sk]['assists']++;
                }
            } elseif ($cat === 'card') {
                $k = $key($pid, $name);
                $touch($agg, $k, $name);
                if ((string) $e['card_type'] === 'red' || $e['type'] === 'red_card' || $e['type'] === 'second_yellow') {
                    $agg[$k]['red']++;
                } else {
                    $agg[$k]['yellow']++;
                }
            }
        }
    }

    $players = array_values($agg);
    usort($players, static function (array $a, array $b): int {
        return [$b['goals'], $b['assists'], $b['apps']] <=> [$a['goals'], $a['assists'], $a['apps']]
            ?: strcmp((string) $a['name'], (string) $b['name']);
    });

    return ['players' => $players, 'team' => $team, 'fixtures' => count($fixtureIds)];
}
