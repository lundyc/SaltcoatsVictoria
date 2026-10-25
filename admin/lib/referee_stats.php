<?php
declare(strict_types=1);

/**
 * Referee profiles for stats.php?tab=referees. Everything here is read-only.
 *
 * Sources
 *  - comet_match_officials / comet_officials : who refereed which match (internal tables).
 *  - match_fixtures + matchday_events        : Saltcoats' own matches (authoritative for our games).
 *  - data/opposition_reports/*.json          : other matches scraped from COMET (cards, penalties, goals),
 *                                              used for the league-wide rates. Grows as more teams are fetched.
 *
 * Limits worth knowing: fouls are not recorded anywhere; league-wide red cards come from the scraped
 * reports, which log a second-yellow dismissal as a yellow only; penalties are those that were scored.
 */

function hub_ref_norm(string $name): string
{
    return strtolower(trim((string)preg_replace('/\s+/', ' ', $name)));
}

/** Time-band labels used for "when does he book players". */
const HUB_REF_BANDS = ['0-15', '16-30', '31-45+', '46-60', '61-75', '76-90+'];

/** Band index (0-5) for a card minute such as 37, "90+3'" or null (unknown). */
function hub_ref_band($minute): ?int
{
    if ($minute === null || $minute === '') { return null; }
    if (!preg_match('/^\s*(\d+)(?:\s*\+\s*(\d+))?/', (string)$minute, $m)) { return null; }
    $base = (int)$m[1];
    if (isset($m[2]) && $m[2] !== '') { return $base <= 45 ? 2 : 5; }
    if ($base <= 15) { return 0; } if ($base <= 30) { return 1; } if ($base <= 45) { return 2; }
    if ($base <= 60) { return 3; } if ($base <= 75) { return 4; }
    return 5;
}

/** Per-match metrics from the scraped opposition reports, keyed by COMET match id. */
function hub_ref_league_index(): array
{
    static $index = null;
    if ($index !== null) { return $index; }
    $index = [];
    foreach (glob(__DIR__ . '/../data/opposition_reports/*.json') ?: [] as $file) {
        $d = json_decode((string)file_get_contents($file), true);
        foreach (is_array($d['matches'] ?? null) ? $d['matches'] : [] as $m) {
            if (!is_array($m) || strtoupper((string)($m['status'] ?? '')) !== 'PLAYED' || !is_array($m['score'] ?? null) || empty($m['matchId'])) { continue; }
            $y = $r = $p = $yh = $ya = $rh = $ra = 0; $bk = array_fill(0, 6, 0);
            foreach (is_array($m['events'] ?? null) ? $m['events'] : [] as $e) {
                $t = (string)($e['type'] ?? ''); $home = ($e['side'] ?? '') === 'home';
                if ($t === 'yellow_card' || $t === 'red_card') { $b = hub_ref_band($e['minute'] ?? null); if ($b !== null) { $bk[$b]++; } }
                if ($t === 'yellow_card') { $y++; $home ? $yh++ : $ya++; } elseif ($t === 'red_card') { $r++; $home ? $rh++ : $ra++; } elseif ($t === 'penalty') { $p++; }
            }
            $hg = (int)($m['score']['home'] ?? 0); $ag = (int)($m['score']['away'] ?? 0);
            $index[(int)$m['matchId']] = ['yellow' => $y, 'red' => $r, 'pens' => $p, 'goals' => $hg + $ag, 'yh' => $yh, 'ya' => $ya, 'rh' => $rh, 'ra' => $ra, 'hg' => $hg, 'ag' => $ag, 'bands' => $bk];
        }
    }
    return $index;
}

/** @return array{refs: array<string,array>, baseline: array, league: array} */
function hub_referee_dataset(PDO $pdo): array
{
    $rows = $pdo->query("SELECT m.id, m.comet_match_id, m.fixture_id, m.match_date, m.home_team, m.away_team, m.competition, o.id AS oid, o.name, o.home_club
        FROM comet_match_officials m JOIN comet_officials o ON o.id = m.official_id WHERE m.role = 'Referee' ORDER BY m.match_date")->fetchAll(PDO::FETCH_ASSOC);
    require_once __DIR__ . '/stats.php';
    $rows = array_values(array_filter($rows, static fn(array $r): bool => !hub_stats_is_friendly($pdo, $r['competition'] ?? '')));

    // Our own fixtures and their cards / penalties
    $fixtureIds = array_values(array_unique(array_filter(array_map(static fn($r) => (int)$r['fixture_id'], $rows))));
    $fix = []; $ev = [];
    if ($fixtureIds) {
        $in = implode(',', array_map('intval', $fixtureIds));
        foreach ($pdo->query("SELECT id, season_id, match_date, opponent, is_home, status, competition, full_time_home_score h, full_time_away_score a FROM match_fixtures WHERE id IN ($in)") as $f) { $fix[(int)$f['id']] = $f; }
        foreach ($pdo->query("SELECT fixture_id, side, type, participant_type, COUNT(*) c FROM matchday_events WHERE fixture_id IN ($in) AND type IN ('yellow_card','red_card','penalty_scored') GROUP BY fixture_id, side, type, participant_type") as $e) {
            $ev[(int)$e['fixture_id']][$e['side'] . '|' . $e['type'] . '|' . $e['participant_type']] = (int)$e['c'];
        }
    }
    $ourBands = [];
    if ($fixtureIds) {
        foreach ($pdo->query("SELECT fixture_id, minute FROM matchday_events WHERE fixture_id IN ($in) AND type IN ('yellow_card','red_card') AND minute IS NOT NULL") as $e) {
            $b = hub_ref_band($e['minute']); if ($b !== null) { $ourBands[(int)$e['fixture_id']][$b] = ($ourBands[(int)$e['fixture_id']][$b] ?? 0) + 1; }
        }
    }
    $league = hub_ref_league_index();

    $refs = []; $seenMatch = []; $leagueSum = ['y' => 0, 'r' => 0, 'p' => 0, 'g' => 0, 'n' => 0, 'yh' => 0, 'ya' => 0, 'hw' => 0, 'dr' => 0, 'aw' => 0, 'bands' => array_fill(0, 6, 0)]; $our = ['pen_us' => 0, 'pen_opp' => 0, 'ours_cards' => 0, 'opp_cards' => 0, 'games' => 0, 'pts' => 0, 'ours_y' => 0, 'opp_y' => 0, 'ours_r' => 0, 'opp_r' => 0];
    foreach ($rows as $r) {
        $key = hub_ref_norm((string)$r['name']);
        $refs[$key] ??= ['key' => $key, 'id' => (int)$r['oid'], 'name' => trim((string)preg_replace('/\s+/', ' ', (string)$r['name'])), 'club' => $r['home_club'], 'matches' => [], 'ours' => [], 'seen' => []];
        $ref = &$refs[$key];
        if (!$ref['club'] && $r['home_club']) { $ref['club'] = $r['home_club']; }
        $mk = $r['comet_match_id'] ? 'c' . $r['comet_match_id'] : ($r['fixture_id'] ? 'f' . $r['fixture_id'] : 'd' . $r['match_date'] . $r['home_team'] . $r['away_team']);
        if (isset($ref['seen'][$mk])) { unset($ref); continue; }
        $ref['seen'][$mk] = true;
        $metrics = null; $fid = (int)$r['fixture_id'];
        if ($fid && isset($fix[$fid]) && $fix[$fid]['status'] === 'played') {
            $f = $fix[$fid]; $e = $ev[$fid] ?? [];
            $g = static fn(string $side, string $type, string $pt = 'player') => (int)($e["$side|$type|$pt"] ?? 0);
            $row = ['fixture_id' => $fid, 'date' => (string)$f['match_date'], 'opponent' => (string)$f['opponent'], 'home' => (bool)$f['is_home'], 'competition' => (string)$f['competition'],
                'for' => (int)($f['is_home'] ? $f['h'] : $f['a']), 'against' => (int)($f['is_home'] ? $f['a'] : $f['h']),
                'y_us' => $g('svfc', 'yellow_card'), 'y_opp' => $g('opponent', 'yellow_card'), 'r_us' => $g('svfc', 'red_card'), 'r_opp' => $g('opponent', 'red_card'),
                'staff_us' => $g('svfc', 'yellow_card', 'staff') + $g('svfc', 'red_card', 'staff'), 'staff_opp' => $g('opponent', 'yellow_card', 'staff') + $g('opponent', 'red_card', 'staff'),
                'pen_us' => $g('svfc', 'penalty_scored'), 'pen_opp' => $g('opponent', 'penalty_scored'), 'season_id' => (int)$f['season_id']];
            $row['result'] = $row['for'] > $row['against'] ? 'W' : ($row['for'] < $row['against'] ? 'L' : 'D');
            $ref['ours'][] = $row;
            $h = $row['home'];
            $metrics = ['yellow' => $row['y_us'] + $row['y_opp'], 'red' => $row['r_us'] + $row['r_opp'], 'pens' => $row['pen_us'] + $row['pen_opp'], 'goals' => $row['for'] + $row['against'],
                'yh' => $h ? $row['y_us'] : $row['y_opp'], 'ya' => $h ? $row['y_opp'] : $row['y_us'], 'rh' => $h ? $row['r_us'] : $row['r_opp'], 'ra' => $h ? $row['r_opp'] : $row['r_us'],
                'hg' => $h ? $row['for'] : $row['against'], 'ag' => $h ? $row['against'] : $row['for'], 'bands' => array_replace(array_fill(0, 6, 0), $ourBands[$fid] ?? []), 'ours' => true, 'fixture_id' => $fid, 'season_id' => (int)$f['season_id']];
        } elseif ($r['comet_match_id'] && isset($league[(int)$r['comet_match_id']])) {
            $metrics = $league[(int)$r['comet_match_id']];
        }
        if ($metrics) {
            $ref['matches'][$mk] = ['date' => (string)$r['match_date'], 'home' => (string)$r['home_team'], 'away' => (string)$r['away_team'], 'comp' => (string)$r['competition']] + $metrics;
            if (!isset($seenMatch[$mk])) { $seenMatch[$mk] = true; $leagueSum['y'] += $metrics['yellow']; $leagueSum['r'] += $metrics['red']; $leagueSum['p'] += $metrics['pens']; $leagueSum['g'] += $metrics['goals']; $leagueSum['n']++; foreach ($metrics['bands'] as $bi => $bc) { $leagueSum['bands'][$bi] += $bc; } $leagueSum['yh'] += $metrics['yh']; $leagueSum['ya'] += $metrics['ya'];
                $metrics['hg'] > $metrics['ag'] ? $leagueSum['hw']++ : ($metrics['hg'] === $metrics['ag'] ? $leagueSum['dr']++ : $leagueSum['aw']++); }
        }
        unset($ref);
    }
    // our baseline across every refereed game of ours
    $seenOurs = [];
    foreach ($refs as $ref) { foreach ($ref['ours'] as $o) { if (isset($seenOurs[$o['fixture_id']])) { continue; } $seenOurs[$o['fixture_id']] = true;
        $our['games']++; $our['ours_y'] += $o['y_us']; $our['opp_y'] += $o['y_opp']; $our['ours_r'] += $o['r_us']; $our['opp_r'] += $o['r_opp']; $our['pen_us'] += $o['pen_us']; $our['pen_opp'] += $o['pen_opp'];
        $our['pts'] += $o['result'] === 'W' ? 3 : ($o['result'] === 'D' ? 1 : 0); } }
    $our['ours_cards'] = $our['ours_y'] + $our['ours_r']; $our['opp_cards'] = $our['opp_y'] + $our['opp_r'];
    $totalCards = $our['ours_cards'] + $our['opp_cards'];
    $baseline = ['share' => $totalCards ? $our['ours_cards'] / $totalCards : 0.5, 'ppg' => $our['games'] ? $our['pts'] / $our['games'] : null, 'games' => $our['games'],
        'y_us_pg' => $our['games'] ? $our['ours_y'] / $our['games'] : 0, 'y_opp_pg' => $our['games'] ? $our['opp_y'] / $our['games'] : 0,
        'r_us_pg' => $our['games'] ? $our['ours_r'] / $our['games'] : 0, 'r_opp_pg' => $our['games'] ? $our['opp_r'] / $our['games'] : 0,
        'pen_us_pg' => $our['games'] ? $our['pen_us'] / $our['games'] : 0, 'pen_opp_pg' => $our['games'] ? $our['pen_opp'] / $our['games'] : 0];
    $leagueAvg = ['yellow' => $leagueSum['n'] ? $leagueSum['y'] / $leagueSum['n'] : 0, 'red' => $leagueSum['n'] ? $leagueSum['r'] / $leagueSum['n'] : 0,
        'pens' => $leagueSum['n'] ? $leagueSum['p'] / $leagueSum['n'] : 0, 'goals' => $leagueSum['n'] ? $leagueSum['g'] / $leagueSum['n'] : 0, 'matches' => $leagueSum['n'],
        'bands' => array_map(fn($c) => $leagueSum['n'] ? $c / $leagueSum['n'] : 0, $leagueSum['bands']), 'yh' => $leagueSum['n'] ? $leagueSum['yh'] / $leagueSum['n'] : 0, 'ya' => $leagueSum['n'] ? $leagueSum['ya'] / $leagueSum['n'] : 0,
        'hw' => $leagueSum['n'] ? $leagueSum['hw'] / $leagueSum['n'] : 0, 'dr' => $leagueSum['n'] ? $leagueSum['dr'] / $leagueSum['n'] : 0, 'aw' => $leagueSum['n'] ? $leagueSum['aw'] / $leagueSum['n'] : 0];

    // per-ref summaries
    foreach ($refs as $k => &$ref) {
        $n = count($ref['matches']);
        $ref['n_matches'] = $n;
        foreach (['yellow', 'red', 'pens', 'goals', 'yh', 'ya', 'rh', 'ra'] as $m) { $ref['avg_' . $m] = $n ? array_sum(array_column($ref['matches'], $m)) / $n : null; }
        $ref['bands'] = array_fill(0, 6, 0); foreach ($ref['matches'] as $mm) { foreach ($mm['bands'] ?? [] as $bi => $bc) { $ref['bands'][$bi] += $bc; } }
        $ref['hw'] = count(array_filter($ref['matches'], fn($x) => $x['hg'] > $x['ag'])); $ref['dr'] = count(array_filter($ref['matches'], fn($x) => $x['hg'] === $x['ag'])); $ref['aw'] = $n - $ref['hw'] - $ref['dr'];
        $ref['other'] = array_values(array_filter($ref['matches'], fn($x) => empty($x['ours']))); usort($ref['other'], fn($a, $b) => strcmp($b['date'], $a['date']));
        $g = count($ref['ours']); $ref['n_ours'] = $g;
        $sum = static fn(string $c) => array_sum(array_column($ref['ours'], $c));
        $ref['y_us'] = $sum('y_us'); $ref['y_opp'] = $sum('y_opp'); $ref['r_us'] = $sum('r_us'); $ref['r_opp'] = $sum('r_opp');
        $ref['pen_us'] = $sum('pen_us'); $ref['pen_opp'] = $sum('pen_opp'); $ref['staff_us'] = $sum('staff_us'); $ref['staff_opp'] = $sum('staff_opp');
        $ref['gf'] = $sum('for'); $ref['ga'] = $sum('against');
        $ref['w'] = count(array_filter($ref['ours'], fn($o) => $o['result'] === 'W')); $ref['d'] = count(array_filter($ref['ours'], fn($o) => $o['result'] === 'D')); $ref['l'] = $g - $ref['w'] - $ref['d'];
        $ref['ppg'] = $g ? ($ref['w'] * 3 + $ref['d']) / $g : null;
        $cardsUs = $ref['y_us'] + $ref['r_us']; $cardsOpp = $ref['y_opp'] + $ref['r_opp']; $tot = $cardsUs + $cardsOpp;
        $ref['cards_us'] = $cardsUs; $ref['cards_opp'] = $cardsOpp;
        $ref['share'] = $tot ? $cardsUs / $tot : null;
        // fairness: how far his split of cards is from our usual split (100% = identical, 0% = as far apart as possible)
        $ref['fair'] = $ref['share'] === null ? null : (int)round(100 - min(100, abs($ref['share'] - $baseline['share']) / 0.5 * 100));
        $ref['fair_dir'] = $ref['share'] === null ? null : ($ref['share'] - $baseline['share'] > 0.1 ? 'harder' : ($baseline['share'] - $ref['share'] > 0.1 ? 'easier' : 'even'));
        $ref['last_ours'] = $g ? max(array_column($ref['ours'], 'date')) : null;
        usort($ref['ours'], fn($a, $b) => strcmp($b['date'], $a['date']));
        unset($ref['seen']);
    }
    unset($ref);
    uasort($refs, fn($a, $b) => [$b['n_ours'], $b['n_matches'], $a['name']] <=> [$a['n_ours'], $a['n_matches'], $b['name']]);
    return ['refs' => $refs, 'baseline' => $baseline, 'league' => $leagueAvg];
}

/** [label, css-modifier] for a rate compared with the league average */
function hub_ref_rate_label(?float $value, float $avg): array
{
    if ($value === null || $avg <= 0) { return ['No data', 'muted']; }
    if ($value > $avg * 1.15) { return ['Above average', 'high']; }
    if ($value < $avg * 0.85) { return ['Below average', 'low']; }
    return ['Average', 'mid'];
}


/**
 * "What he's like" sentences for a referee, from all his matches on record vs the league average.
 * @return string[] plain text (not escaped)
 */
function hub_ref_style_lines(array $r, array $lg): array
{
    $n = (int)$r['n_matches'];
    if ($n === 0) { return ['No card data on record for this referee yet.']; }
    $lines = [];
    $cmp = static function (float $v, float $avg, string $high, string $low, string $mid): string { return $avg > 0 && $v > $avg * 1.15 ? $high : ($avg > 0 && $v < $avg * 0.85 ? $low : $mid); };
    $f = static fn(float $v, int $dp = 1): string => number_format($v, $dp);
    $lines[] = $cmp((float)$r['avg_yellow'], $lg['yellow'], 'Quick to book: ', 'Slow to book: ', 'Average with the cards: ') . $f((float)$r['avg_yellow']) . ' yellows a match (league ' . $f($lg['yellow']) . ').';
    $lines[] = $cmp((float)$r['avg_red'], $lg['red'], 'Sends players off more than most: ', 'Rarely sends players off: ', 'Sends players off about as often as most: ') . $f((float)$r['avg_red'], 2) . ' reds a match (league ' . $f($lg['red'], 2) . ').';
    $lines[] = $cmp((float)$r['avg_pens'], $lg['pens'], 'Gives penalties more readily than most: ', 'Gives few penalties: ', 'Gives penalties about as often as most: ') . $f((float)$r['avg_pens'], 2) . ' scored a match (league ' . $f($lg['pens'], 2) . ').';
    if ($n >= 3 && ($r['avg_yh'] + $r['avg_ya']) > 0 && ($lg['yh'] + $lg['ya']) > 0) {
        $share = $r['avg_yh'] / ($r['avg_yh'] + $r['avg_ya']); $lgShare = $lg['yh'] / ($lg['yh'] + $lg['ya']);
        $lines[] = ($share - $lgShare > 0.1 ? 'Books the home side more than usual' : ($lgShare - $share > 0.1 ? 'Books the away side more than usual' : 'Books home and away sides about evenly'))
            . ': home ' . $f((float)$r['avg_yh']) . ' v away ' . $f((float)$r['avg_ya']) . ' yellows a match (league ' . $f($lg['yh']) . ' v ' . $f($lg['ya']) . ').';
    }
    if ($n >= 3) { $lines[] = 'In his matches the home side won ' . round($r['hw'] / $n * 100) . '%, drawn ' . round($r['dr'] / $n * 100) . '%, away side won ' . round($r['aw'] / $n * 100) . '% (league ' . round($lg['hw'] * 100) . '% / ' . round($lg['dr'] * 100) . '% / ' . round($lg['aw'] * 100) . '%).'; }
    if ($n < 3) { $lines[] = 'Only ' . $n . ' match' . ($n === 1 ? '' : 'es') . ' on record, so this is a guide, not a verdict.'; }
    return $lines;
}
