<?php

declare(strict_types=1);

/**
 * Persistence for a resolved COMET match-report import.
 *
 * Since Stage 4 of the matchday-record port this writes the normalised
 * matchday_* tables for BOTH teams (lib/matchday_record.php) and lets that
 * lib's projection rebuild the legacy starting11_*_json / matches.json
 * stores. The full-time score (authoritative in the report) is written
 * straight onto the fixture.
 *
 * Requires lib/comet_match_report.php + matches_lib.php loaded by the caller.
 */

require_once __DIR__ . '/matchday_record.php';

/**
 * Persist a resolved import plan.
 *
 * Overwrite semantics: the fixture's line-ups, match events and
 * substitutions (both sides) are replaced. Match periods are seeded if
 * absent. player_of_match and any structural markers are left alone.
 *
 * @param array<string,mixed> $fixture   a match_fixtures row (needs id, is_home)
 * @param array<string,mixed> $resolved  comet_report_resolve() output
 * @throws RuntimeException
 */
function comet_report_import_apply(PDO $pdo, array $fixture, array $resolved): void
{
    $fixtureId = (int) $fixture['id'];
    if ($fixtureId <= 0) {
        throw new RuntimeException('The fixture is missing an id.');
    }
    matchday_record_ensure_schema($pdo);

    // Canonical Saltcoats name -> players.id, for linking svfc rows.
    $squadIdByName = [];
    foreach ($pdo->query("SELECT id, name FROM players WHERE active = 1") as $row) {
        $squadIdByName[mb_strtolower(trim((string) $row['name']), 'UTF-8')] = (int) $row['id'];
    }
    $svfcPlayerId = static fn(?string $name): ?int =>
        $name !== null && isset($squadIdByName[mb_strtolower(trim($name), 'UTF-8')])
            ? $squadIdByName[mb_strtolower(trim($name), 'UTF-8')]
            : null;

    $captain = (string) ($resolved['captain'] ?? '');

    /* ---- Saltcoats line-up ------------------------------------------------ */
    $svfc = [];
    foreach ($resolved['starters'] as $s) {
        $name = $s['name'] ?? $s['raw'] ?? '';
        $name = trim((string) $name);
        if ($name === '') {
            continue;
        }
        $svfc[] = [
            'player_id' => $svfcPlayerId($s['name'] ?? null),
            'player_name' => $name,
            'shirt_number' => $s['pdf_number'] ?? null,
            'position_label' => !empty($s['is_gk']) ? 'GK' : null,
            'is_starting' => true,
            'is_captain' => $captain !== '' && $name === $captain,
            'sort_order' => ((int) ($s['slot'] ?? count($svfc)) + 1) * 10,
        ];
    }
    $subOrder = 0;
    foreach ($resolved['substitutes'] as $s) {
        $name = trim((string) ($s['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $svfc[] = [
            'player_id' => $svfcPlayerId($name),
            'player_name' => $name,
            'shirt_number' => $s['pdf_number'] ?? null,
            'position_label' => null,
            'is_starting' => false,
            'is_captain' => false,
            'sort_order' => 200 + (++$subOrder) * 10,
        ];
    }
    matchday_lineup_replace($pdo, $fixtureId, 'svfc', $svfc);

    /* ---- Opponent line-up (names only) ---------------------------------- */
    $oppCaptain = (string) ($resolved['opponent_captain_raw'] ?? '');
    $opp = [];
    foreach ($resolved['opponent_starters'] as $s) {
        $name = trim((string) ($s['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $opp[] = [
            'player_id' => null,
            'player_name' => $name,
            'shirt_number' => $s['pdf_number'] ?? null,
            'position_label' => !empty($s['is_gk']) ? 'GK' : null,
            'is_starting' => true,
            'is_captain' => $oppCaptain !== '' && $name === $oppCaptain,
            'sort_order' => ((int) ($s['slot'] ?? count($opp)) + 1) * 10,
        ];
    }
    $oppSubOrder = 0;
    foreach ($resolved['opponent_substitutes'] as $s) {
        $name = trim((string) ($s['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $opp[] = [
            'player_id' => null,
            'player_name' => $name,
            'shirt_number' => $s['pdf_number'] ?? null,
            'position_label' => null,
            'is_starting' => false,
            'is_captain' => false,
            'sort_order' => 200 + (++$oppSubOrder) * 10,
        ];
    }
    matchday_lineup_replace($pdo, $fixtureId, 'opponent', $opp);

    /* ---- Wipe and rewrite events + substitutions ----------------------- */
    $pdo->prepare('DELETE FROM matchday_subs WHERE fixture_id = :f')->execute([':f' => $fixtureId]);
    $pdo->prepare('DELETE FROM matchday_events WHERE fixture_id = :f')->execute([':f' => $fixtureId]);

    // name(lower) -> matchday_lineups.id, per side, for event/sub linking
    $lineupIdByName = ['svfc' => [], 'opponent' => []];
    $rows = $pdo->prepare('SELECT id, side, player_name FROM matchday_lineups WHERE fixture_id = :f');
    $rows->execute([':f' => $fixtureId]);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $lineupIdByName[(string) $r['side']][mb_strtolower(trim((string) $r['player_name']), 'UTF-8')] = (int) $r['id'];
    }
    $lid = static fn(string $side, string $name): ?int =>
        $lineupIdByName[$side][mb_strtolower(trim($name), 'UTF-8')] ?? null;

    // Goals — each resolved goal already carries the side it counts FOR.
    foreach ($resolved['goals'] as $g) {
        $ownGoal = !empty($g['own_goal']);
        $type = $ownGoal ? 'own_goal' : ((string) ($g['note'] ?? '') === 'Penalty' ? 'penalty_scored' : 'goal');
        $side = (string) ($g['team'] ?? 'svfc');
        matchday_event_save($pdo, $fixtureId, [
            'type' => $type,
            'side' => $side,
            'minute' => $g['minute'] ?? null,
            'player_name' => (string) ($g['name'] ?? ''),
            'player_lineup_id' => $lid($side === 'none' ? 'svfc' : $side, (string) ($g['name'] ?? '')),
            'own_goal' => $ownGoal,
            'note' => (string) ($g['note'] ?? ''),
        ], null, false);
    }
    foreach ($resolved['opponent_goals'] as $g) {
        $type = (string) ($g['note'] ?? '') === 'Penalty' ? 'penalty_scored' : 'goal';
        matchday_event_save($pdo, $fixtureId, [
            'type' => $type,
            'side' => 'opponent',
            'minute' => $g['minute'] ?? null,
            'player_name' => (string) ($g['name'] ?? ''),
            'player_lineup_id' => $lid('opponent', (string) ($g['name'] ?? '')),
            'note' => (string) ($g['note'] ?? ''),
        ], null, false);
    }

    // Cards
    foreach ([['svfc', $resolved['cards']], ['opponent', $resolved['opponent_cards']]] as [$side, $cards]) {
        foreach ($cards as $c) {
            matchday_event_save($pdo, $fixtureId, [
                'type' => ($c['card_type'] ?? 'yellow') === 'red' ? 'red_card' : 'yellow_card',
                'side' => $side,
                'minute' => $c['minute'] ?? null,
                'player_name' => (string) ($c['name'] ?? ''),
                'player_lineup_id' => $lid($side, (string) ($c['name'] ?? '')),
                'card_type' => ($c['card_type'] ?? 'yellow') === 'red' ? 'red' : 'yellow',
                'note' => (string) ($c['reason'] ?? ''),
            ], null, false);
        }
    }

    // Substitutions — matchday_subs row + a linked substitution event.
    $insSub = $pdo->prepare(
        'INSERT INTO matchday_subs
            (fixture_id, side, minute, minute_extra, player_off_lineup_id, player_on_lineup_id,
             player_off_name, player_on_name, reason, event_id)
         VALUES (:f, :s, :m, :me, :off, :on, :offn, :onn, \'\', :eid)'
    );
    foreach ([['svfc', $resolved['substitutions']], ['opponent', $resolved['opponent_substitutions']]] as [$side, $changes]) {
        foreach ($changes as $ch) {
            $offName = trim((string) ($ch['off'] ?? ''));
            $onName = trim((string) ($ch['on'] ?? ''));
            if ($offName === '' || $onName === '') {
                continue;
            }
            [$minute, $extra] = matchday_record_parse_minute($ch['minute'] ?? null);
            $offId = $lid($side, $offName);
            $onId = $lid($side, $onName);
            $eventId = matchday_event_save($pdo, $fixtureId, [
                'type' => 'substitution',
                'side' => $side,
                'minute' => $minute,
                'minute_extra' => $extra,
                'player_name' => $offName,
                'player_lineup_id' => $offId,
                'secondary_player_name' => $onName,
                'secondary_player_lineup_id' => $onId,
            ], null, false);
            $insSub->execute([
                ':f' => $fixtureId, ':s' => $side, ':m' => $minute, ':me' => $extra,
                ':off' => $offId, ':on' => $onId,
                ':offn' => mb_substr($offName, 0, 160), ':onn' => mb_substr($onName, 0, 160),
                ':eid' => $eventId,
            ]);
        }
    }

    matchday_periods_ensure($pdo, $fixtureId);
    matchday_record_sync_derived($pdo, $fixtureId);

    /* ---- Full-time score (the report is authoritative) ----------------- */
    $svfcScore = $resolved['score']['svfc'] ?? null;
    $oppScore = $resolved['score']['opponent'] ?? null;
    if ($svfcScore !== null && $oppScore !== null) {
        $isHome = (int) ($fixture['is_home'] ?? 1) === 1;
        $homeScore = $isHome ? (int) $svfcScore : (int) $oppScore;
        $awayScore = $isHome ? (int) $oppScore : (int) $svfcScore;

        /* ---- Penalty shootout, if the tie needed one (cup only) -------- */
        $svfcPens = $resolved['penalties']['svfc'] ?? null;
        $oppPens = $resolved['penalties']['opponent'] ?? null;
        $homePens = null;
        $awayPens = null;
        if ($svfcPens !== null && $oppPens !== null) {
            $homePens = $isHome ? (int) $svfcPens : (int) $oppPens;
            $awayPens = $isHome ? (int) $oppPens : (int) $svfcPens;
        }

        $pdo->prepare(
            "UPDATE match_fixtures
                SET full_time_home_score = :fh, full_time_away_score = :fa,
                    home_penalties = :hp, away_penalties = :ap, status = 'played'
              WHERE id = :id LIMIT 1"
        )->execute([':fh' => $homeScore, ':fa' => $awayScore, ':hp' => $homePens, ':ap' => $awayPens, ':id' => $fixtureId]);

        $all = matches_load_all();
        $idx = matches_find_index($all, (string) $fixtureId);
        if ($idx >= 0) {
            $all[$idx] = matches_normalize(array_merge($all[$idx], ['status' => 'played']));
            matches_save_all($all);
        }
    }
}
