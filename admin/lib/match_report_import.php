<?php
declare(strict_types=1);

/**
 * Reads a COMET "Match report" PDF and records what it says. The PDF itself is never kept:
 * the caller deletes the upload straight after this runs.
 *
 *  - Match officials go to the internal comet_officials / comet_match_officials tables.
 *  - For a Saltcoats fixture, squad members, goals, cards and substitutions that the database
 *    lacks are added, staff cards are recorded with their role, and empty card-reason notes are
 *    filled. Existing rows are never changed or removed (apart from filling an empty note).
 *  - Referees' written incident narratives are not read: a card reason is cut at " - ".
 */

const HUB_MATCH_REPORT_PARSER = '/var/www/vhosts/saltcoatsvictoria.co.uk/ops/comet-collector/parse_match_report.py';

function hub_mr_key(string $name): string
{
    $n = strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT', $name));
    $n = preg_replace('/\bmc\s+/', 'mc', $n);
    $p = preg_split('/\s+/', trim((string)preg_replace('/[^a-z ]/', '', (string)$n)));
    return $p && $p[0] !== '' ? end($p) . '.' . substr($p[0], 0, 1) : '';
}

/** "45+2'" => [45, 2]; "AM" / unknown => [null, 0] */
function hub_mr_minute(?string $m): array
{
    if ($m !== null && preg_match("/^(\d+)(?:\+(\d+))?/", $m, $x)) { return [(int)$x[1], (int)($x[2] ?? 0)]; }
    return [null, 0];
}

function hub_mr_parse(string $pdfPath): array
{
    $head = (string)file_get_contents($pdfPath, false, null, 0, 5);
    if (!str_starts_with($head, '%PDF')) { return ['error' => 'That file is not a PDF.']; }
    $proc = proc_open(['/usr/bin/python3', HUB_MATCH_REPORT_PARSER, $pdfPath], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) { return ['error' => 'Could not start the PDF reader.']; }
    stream_set_blocking($pipes[1], false);
    $out = ''; $start = time();
    while (true) {
        $chunk = (string)fread($pipes[1], 65536); $out .= $chunk;
        $st = proc_get_status($proc);
        if (!$st['running']) { $out .= (string)stream_get_contents($pipes[1]); break; }
        if (time() - $start > 45) { proc_terminate($proc, 9); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc); return ['error' => 'Reading the PDF took too long.']; }
        if ($chunk === '') { usleep(100000); }
    }
    fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $data = json_decode($out, true);
    if (!is_array($data)) { return ['error' => 'The PDF could not be read.']; }
    if (isset($data['error'])) { return ['error' => 'This does not look like a COMET match report.']; }
    $m = $data['match'] ?? [];
    if (empty($m['home']) || empty($m['away']) || empty($m['date'])) { return ['error' => 'This does not look like a COMET match report (no teams or date found).']; }
    return $data;
}

function hub_mr_official(PDO $pdo, array $o): array   // [id, created]
{
    $name = trim((string)preg_replace('/\s+/', ' ', (string)$o['name']));
    $find = $pdo->prepare('SELECT id, home_club FROM comet_officials WHERE LOWER(TRIM(name)) = LOWER(?) ORDER BY comet_person_id IS NULL, id LIMIT 1');
    $find->execute([$name]);
    if ($row = $find->fetch(PDO::FETCH_ASSOC)) {
        if (empty($row['home_club']) && !empty($o['club'])) { $pdo->prepare('UPDATE comet_officials SET home_club = ? WHERE id = ?')->execute([$o['club'], $row['id']]); }
        return [(int)$row['id'], false];
    }
    $pdo->prepare('INSERT INTO comet_officials (comet_person_id, name, home_club) VALUES (NULL, ?, ?)')->execute([$name, $o['club'] ?: null]);
    return [(int)$pdo->lastInsertId(), true];
}

function hub_mr_import(PDO $pdo, array $r, bool $dryRun = false, ?int $expectFixtureId = null): array
{
    $m = $r['match'];
    $svHome = stripos((string)$m['home'], 'saltcoats') !== false;
    $svAway = stripos((string)$m['away'], 'saltcoats') !== false;
    $sum = ['match' => "{$m['home']} v {$m['away']}", 'date' => $m['date'], 'score' => isset($m['home_score']) ? "{$m['home_score']}-{$m['away_score']}" : null,
        'officials_new' => 0, 'officials_total' => 0, 'fixture' => null, 'players_added' => 0, 'goals_added' => 0, 'cards_added' => 0, 'staff_cards_added' => 0,
        'subs_added' => 0, 'shootout_added' => 0, 'reasons_filled' => 0, 'warnings' => array_values($r['warnings'] ?? [])];

    $fixtureId = null;
    if ($expectFixtureId !== null) {
        // Fetched via a saved COMET link: the report must be for exactly that fixture.
        $st = $pdo->prepare('SELECT id, status, match_date, is_home FROM match_fixtures WHERE id = ?');
        $st->execute([$expectFixtureId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('That fixture no longer exists.'); }
        if ((string)$row['match_date'] !== (string)$m['date'] || (bool)$row['is_home'] !== $svHome || !($svHome || $svAway)) {
            throw new RuntimeException("The COMET page you linked is for {$m['home']} v {$m['away']} on {$m['date']}, which doesn't match this fixture ({$row['match_date']}). Check the link.");
        }
        $fx = [$row];
        $fixtureId = (int)$row['id']; $sum['fixture'] = $fixtureId;
        if ($row['status'] !== 'played') { $sum['warnings'][] = 'The fixture is not marked as played, so squads and events were not touched.'; }
    } elseif ($svHome || $svAway) {
        $st = $pdo->prepare('SELECT id, status FROM match_fixtures WHERE match_date = ? AND is_home = ? ORDER BY id');
        $st->execute([$m['date'], $svHome ? 1 : 0]);
        $fx = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($fx) { $fixtureId = (int)$fx[0]['id']; $sum['fixture'] = $fixtureId; if ($fx[0]['status'] !== 'played') { $sum['warnings'][] = 'The fixture is not marked as played, so squads and events were not touched.'; } }
        else { $sum['warnings'][] = 'No Saltcoats fixture was found for this date, so only the officials were saved.'; }
    } else {
        $sum['warnings'][] = 'Not a Saltcoats Victoria match, so only the officials were saved.';
    }

    $pdo->beginTransaction();
    try {
        // ---- officials
        $up = $pdo->prepare('INSERT INTO comet_match_officials (comet_match_id, fixture_id, official_id, role, match_date, home_team, away_team, competition, source)
            VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, "pdf")
            ON DUPLICATE KEY UPDATE fixture_id = COALESCE(fixture_id, VALUES(fixture_id)), competition = COALESCE(competition, VALUES(competition))');
        foreach ($r['officials'] as $o) {
            [$oid, $created] = hub_mr_official($pdo, $o);
            $up->execute([$fixtureId, $oid, $o['role'], $m['date'], $m['home'], $m['away'], $m['competition'] ?? null]);
            $sum['officials_total']++; if ($up->rowCount() === 1) { $sum['officials_new']++; }
        }

        if ($fixtureId && ($fx[0]['status'] ?? '') === 'played') {
            $side = static fn(string $pdfSide): string => ($pdfSide === 'home') === $svHome ? 'svfc' : 'opponent';
            // ---- lineups
            $lineRows = $pdo->prepare('SELECT id, side, player_name FROM matchday_lineups WHERE fixture_id = ?'); $lineRows->execute([$fixtureId]);
            $byKey = [];
            foreach ($lineRows->fetchAll(PDO::FETCH_ASSOC) as $l) { $byKey[$l['side'] . '|' . hub_mr_key($l['player_name'])] = (int)$l['id']; }
            $insL = $pdo->prepare('INSERT INTO matchday_lineups (fixture_id, side, player_id, player_name, shirt_number, position_label, is_starting, is_captain, is_trialist, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $findP = $pdo->prepare('SELECT id FROM players WHERE LOWER(name) = LOWER(?)');
            foreach (['home', 'away'] as $ps) {
                foreach ($r['lineups'][$ps] as $p) {
                    $sd = $side($ps); $k = $sd . '|' . hub_mr_key($p['name']);
                    if (isset($byKey[$k])) { continue; }
                    $pid = null;
                    if ($sd === 'svfc') { $findP->execute([$p['name']]); $c = $findP->fetchAll(PDO::FETCH_COLUMN); if (count($c) === 1) { $pid = (int)$c[0]; } }
                    $insL->execute([$fixtureId, $sd, $pid, $p['name'], $p['shirt'], $p['gk'] ? 'GK' : null, $p['starter'] ? 1 : 0, $p['captain'] ? 1 : 0, $p['trialist'] ? 1 : 0, ($p['starter'] ? 120 : 300) + (int)$p['shirt']]);
                    $byKey[$k] = (int)$pdo->lastInsertId(); $sum['players_added']++;
                }
            }
            // ---- events
            $evRows = $pdo->prepare('SELECT id, type, side, player_name, note FROM matchday_events WHERE fixture_id = ?'); $evRows->execute([$fixtureId]);
            $have = []; $noteless = [];
            foreach ($evRows->fetchAll(PDO::FETCH_ASSOC) as $e) {
                $kk = ($e['type'] === 'own_goal' ? 'own_goal|*' : $e['type'] . '|' . $e['side']) . '|' . hub_mr_key($e['player_name']);
                $have[$kk] = ($have[$kk] ?? 0) + 1;
                if (in_array($e['type'], ['yellow_card', 'red_card'], true) && trim((string)$e['note']) === '') { $noteless[$kk][] = (int)$e['id']; }
            }
            $seq = (int)$pdo->query("SELECT COALESCE(MAX(sequence),0) FROM matchday_events WHERE fixture_id = $fixtureId")->fetchColumn();
            $insE = $pdo->prepare('INSERT INTO matchday_events (fixture_id, minute, minute_extra, side, type, player_lineup_id, player_name, secondary_player_lineup_id, secondary_player_name, own_goal, card_type, participant_type, participant_role, outcome, note, sequence) VALUES (?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?)');
            $insS = $pdo->prepare('INSERT INTO matchday_subs (fixture_id, side, minute, minute_extra, player_off_lineup_id, player_on_lineup_id, player_off_name, player_on_name, reason, event_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $setNote = $pdo->prepare("UPDATE matchday_events SET note = ? WHERE id = ? AND (note IS NULL OR note = '')");

            foreach ($r['goals'] as $g) {
                $sd = $side($g['side']); $type = $g['kind'] === 'penalty' ? 'penalty_scored' : ($g['kind'] === 'own goal' ? 'own_goal' : 'goal');
                if ($type === 'own_goal') { $kk = 'own_goal|*|' . hub_mr_key($g['name']); if (($have[$kk] ?? 0) <= 0) { $sum['warnings'][] = "Own goal by {$g['name']} is not in the database (own goals are not added automatically)."; } else { $have[$kk]--; } continue; }
                $kk = $type . '|' . $sd . '|' . hub_mr_key($g['name']);
                if (($have[$kk] ?? 0) > 0) { $have[$kk]--; continue; }
                [$mi, $ex] = hub_mr_minute($g['minute']); $seq++;
                $insE->execute([$fixtureId, $mi, $ex, $sd, $type, $byKey[$sd . '|' . hub_mr_key($g['name'])] ?? null, $g['name'], null, '', '', 'player', '', '', $type === 'penalty_scored' ? 'Penalty' : '', $seq]);
                $sum['goals_added']++;
            }
            foreach ($r['cards'] as $c) {
                $sd = $side($c['side']); $kk = $c['type'] . '|' . $sd . '|' . hub_mr_key($c['name']);
                if (($have[$kk] ?? 0) > 0) {
                    $have[$kk]--;
                    if (!empty($c['reason']) && !empty($noteless[$kk])) { $id = array_shift($noteless[$kk]); $setNote->execute([$c['reason'], $id]); if ($setNote->rowCount()) { $sum['reasons_filled']++; } }
                    continue;
                }
                [$mi, $ex] = hub_mr_minute($c['minute']); $seq++;
                $insE->execute([$fixtureId, $mi, $ex, $sd, $c['type'], $c['staff'] ? null : ($byKey[$sd . '|' . hub_mr_key($c['name'])] ?? null), $c['name'], null, '', $c['type'] === 'yellow_card' ? 'yellow' : 'red',
                    $c['staff'] ? 'staff' : 'player', $c['staff'] ? (string)$c['role'] : '', '', (string)($c['reason'] ?? ''), $seq]);
                if ($c['staff']) { $sum['staff_cards_added']++; } else { $sum['cards_added']++; }
            }
            foreach ($r['subs'] as $s) {
                $sd = $side($s['side']); $kk = 'substitution|' . $sd . '|' . hub_mr_key($s['off_name']);
                if (($have[$kk] ?? 0) > 0) { $have[$kk]--; continue; }
                [$mi, $ex] = hub_mr_minute($s['minute']); $seq++;
                $off = $byKey[$sd . '|' . hub_mr_key($s['off_name'])] ?? null; $on = $byKey[$sd . '|' . hub_mr_key($s['on_name'])] ?? null;
                $insE->execute([$fixtureId, $mi, $ex, $sd, 'substitution', $off, $s['off_name'], $on, $s['on_name'], '', 'player', '', '', '', $seq]);
                $insS->execute([$fixtureId, $sd, $mi, $ex, $off, $on, $s['off_name'], $s['on_name'], '', (int)$pdo->lastInsertId()]);
                $sum['subs_added']++;
            }
            // ---- penalty shootout kicks. Stored as 'shootout_penalty' events (outcome scored|missed, in kick order,
            // no minute) so they never count as goals or change the score. Added only when the fixture has none yet.
            $kicks = $r['shootout'] ?? [];
            if (!empty($kicks['home']) || !empty($kicks['away'])) {
                $haveKicks = (int)$pdo->query("SELECT COUNT(*) FROM matchday_events WHERE fixture_id = $fixtureId AND type = 'shootout_penalty'")->fetchColumn();
                if ($haveKicks === 0) {
                    $nameByShirt = [];
                    foreach (['home', 'away'] as $ps) { foreach ($r['lineups'][$ps] as $p) { $nameByShirt[$side($ps) . '|' . (int)$p['shirt']] = $p['name']; } }
                    $scoredBy = ['home' => 0, 'away' => 0];
                    for ($i = 0, $n = max(count($kicks['home'] ?? []), count($kicks['away'] ?? [])); $i < $n; $i++) {
                        foreach (['home', 'away'] as $ps) {
                            $kick = $kicks[$ps][$i] ?? null;
                            if ($kick === null) { continue; }
                            $sd = $side($ps);
                            $kname = $nameByShirt[$sd . '|' . (int)$kick['shirt']] ?? ('No. ' . (int)$kick['shirt']);
                            $seq++;
                            $insE->execute([$fixtureId, null, 0, $sd, 'shootout_penalty', $byKey[$sd . '|' . hub_mr_key($kname)] ?? null, $kname, null, '', '', 'player', '', !empty($kick['scored']) ? 'scored' : 'missed', '', $seq]);
                            if (!empty($kick['scored'])) { $scoredBy[$ps]++; }
                            $sum['shootout_added']++;
                        }
                    }
                    // Fill the shootout score on the fixture when it is not already there.
                    $pdo->prepare('UPDATE match_fixtures SET home_penalties = ?, away_penalties = ? WHERE id = ? AND home_penalties IS NULL AND away_penalties IS NULL')
                        ->execute([$scoredBy['home'], $scoredBy['away'], $fixtureId]);
                }
            }
        }
        if ($dryRun) { $pdo->rollBack(); } else { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    return $sum;
}
