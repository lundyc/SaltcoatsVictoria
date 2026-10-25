<?php
declare(strict_types=1);

require_once __DIR__ . '/referee_stats.php';

/** A4 HTML (Dompdf-friendly: tables, no flex/grid) for one referee profile. */
function hub_referee_profile_html(array $r, array $base, array $lg): string
{
    $h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $fmt = static fn(?float $v, int $dp = 1): string => $v === null ? '-' : number_format($v, $dp);
    $uk = static fn(string $d): string => date('d/m/Y', strtotime($d));
    $g = max(1, (int)$r['n_ours']);
    // One card per rate, as on the referee page: the value, a bar with a marker at the league average, and the label.
    // Built from tables and plain cells, because Dompdf does not lay out flex or grid.
    $fillColour = ['high' => '#f0a52e', 'low' => '#2f9e6b', 'mid' => '#8f95a1', 'muted' => '#bbbbbb'];
    $textColour = ['high' => '#a86500', 'low' => '#1d7a50', 'mid' => '#6c757d', 'muted' => '#6c757d'];
    $rate = static function (string $title, string $icon, ?float $v, float $avg, int $matches) use ($h, $fmt, $fillColour, $textColour): string {
        [$label, $mod] = hub_ref_rate_label($v, $avg);
        // Same scale as the referee page: the bar runs to twice the league average, or to the value if that is higher.
        $max = max($avg * 2, ($v ?? 0) * 1.15, 0.1);
        $fill = $v === null ? 0.0 : min(100.0, $v / $max * 100);
        $mark = min(100.0, $avg / $max * 100);
        $markStart = max(0.0, $mark - 0.5);
        $markEnd = min(100.0, $mark + 0.5);
        $points = array_unique(array_merge([0.0, $fill, $markStart, $markEnd, 100.0]));
        sort($points);
        // The bar is a row of segments: filled up to the value, a dark marker at the league average, empty after.
        $segments = '';
        for ($i = 0; $i < count($points) - 1; $i++) {
            $from = (float)$points[$i];
            $to = (float)$points[$i + 1];
            if ($to - $from < 0.01) { continue; }
            $mid = ($from + $to) / 2;
            $colour = ($mid >= $markStart && $mid <= $markEnd) ? '#212529' : ($mid <= $fill ? $fillColour[$mod] : '#e3e6ea');
            $segments .= '<td style="width:' . round($to - $from, 2) . '%;background:' . $colour . '"></td>';
        }
        $bar = '<table class="rf-bar" cellspacing="0" cellpadding="0"><tr>' . $segments . '</tr></table>';
        return '<table class="rf-box" cellspacing="0" cellpadding="0"><tr><td class="rf-box-in">'
            . '<div class="rf-t">' . $icon . $h($title) . '</div>'
            . '<div class="rf-v">' . $h($fmt($v)) . ' <span>/ match</span></div>'
            . $bar
            . '<div class="rf-l" style="color:' . $textColour[$mod] . '">' . $h($label) . '</div>'
            . '<div class="rf-s">League average ' . $h($fmt($avg)) . ' &middot; ' . $matches . ' match' . ($matches === 1 ? '' : 'es') . '</div>'
            . '</td></tr></table>';
    };
    $css = '
@page { margin: 34px 40px 54px; }
body { font-family: "DejaVu Sans", sans-serif; color: #212529; font-size: 10px; margin: 0; }
h1 { font-size: 19px; margin: 0 0 2px; letter-spacing: .3px; }
.sub { color: #6c757d; font-size: 9px; margin: 0 0 14px; }
h2 { font-size: 11.5px; margin: 16px 0 6px; letter-spacing: .3px; }
.small { color: #6c757d; font-size: 8.5px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
th, td { padding: 5px 9px; font-size: 9.5px; text-align: left; }
.tc { text-align: center; } .tr { text-align: right; }
.grid th { background: #3a3a3f; color: #fff; font-weight: bold; border: 1px solid #3a3a3f; }
.grid td { border: 1px solid #d9d9de; }
.grid.rec td.lbl { width: 30%; color: #6c757d; background: #f4f4f5; } .grid.rec td.val { width: 20%; font-weight: bold; }
.grid tr.tot td { background: #f4f4f5; font-weight: bold; }
.rf-row { width: 100%; border-collapse: collapse; margin: 2px 0 4px; }
.rf-col { width: 33.3%; padding: 0 5px; vertical-align: top; }
.rf-col:first-child { padding-left: 0; } .rf-col:last-child { padding-right: 0; }
.rf-box { width: 100%; border-collapse: collapse; }
.rf-box-in { border: 1px solid #dee2e6; padding: 9px 10px; }
.rf-t { font-size: 9px; font-weight: bold; margin-bottom: 5px; }
.rf-v { font-size: 17px; font-weight: bold; line-height: 1.1; } .rf-v span { font-size: 8.5px; font-weight: normal; color: #6c757d; }
.rf-bar { width: 100%; height: 8px; border-collapse: collapse; margin: 6px 0 4px; }
.rf-bar td { height: 8px; padding: 0; font-size: 1px; line-height: 1px; }
.rf-l { font-size: 9px; font-weight: bold; }
.rf-s { font-size: 8px; color: #6c757d; margin-top: 2px; }
.cols td.col { width: 50%; vertical-align: top; padding: 0 6px 0 0; } .cols td.col + td.col { padding: 0 0 0 6px; }
.fair { background: #f4f4f5; border: 1px solid #d9d9de; padding: 8px 10px; margin-top: 8px; }
.fair b { font-size: 16px; }
.foot { position: fixed; bottom: -38px; left: 0; right: 0; color: #9a9a9a; font-size: 8px; }
';
    $o = '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>';
    $o .= '<div class="foot">Saltcoats Victoria FC &middot; Referee profile &middot; internal scouting information &middot; generated ' . date('d/m/Y H:i') . '</div>';
    $o .= '<h1>' . $h($r['name']) . '</h1>';
    $o .= '<p class="sub">Referee' . ($r['club'] ? ' &middot; ' . $h($r['club']) : '') . ' &middot; ' . (int)$r['n_matches'] . ' match' . ($r['n_matches'] === 1 ? '' : 'es') . ' on record, ' . (int)$r['n_ours'] . ' with Saltcoats</p>';

    $o .= '<h2>Cards and penalties per match <span class="small">(every match of his on record, compared with all referees)</span></h2>';
    $matches = (int)$r['n_matches'];
    $o .= '<table class="rf-row" cellspacing="0" cellpadding="0"><tr>'
        . '<td class="rf-col">' . $rate('Yellow cards', '<span style="color:#f5c518">&#9632;</span> ', $r['avg_yellow'], $lg['yellow'], $matches) . '</td>'
        . '<td class="rf-col">' . $rate('Red cards', '<span style="color:#e5484d">&#9632;</span> ', $r['avg_red'], $lg['red'], $matches) . '</td>'
        . '<td class="rf-col">' . $rate('Penalties', '&#9678; ', $r['avg_pens'], $lg['pens'], $matches) . '</td>'
        . '</tr></table>';

    $o .= '<h2>Record with Saltcoats</h2>';
    if ($r['n_ours'] === 0) {
        $o .= '<p><b>' . $h($r['name']) . ' has not refereed a Saltcoats game yet.</b> Below is how he has refereed ' . (int)$r['n_matches'] . ' other match' . ($r['n_matches'] === 1 ? '' : 'es') . ' in the league.</p>';
    } else {
        $rows = [
            ['Games refereed', (string)(int)$r['n_ours'], 'Goals for', (string)(int)$r['gf']],
            ['Won', (string)(int)$r['w'], 'Goals against', (string)(int)$r['ga']],
            ['Drawn', (string)(int)$r['d'], 'Penalties for / against', (int)$r['pen_us'] . ' / ' . (int)$r['pen_opp']],
            ['Lost', (string)(int)$r['l'], 'Points per game with him', $fmt($r['ppg'], 2)],
            ['Last refereed us', $uk((string)$r['last_ours']), 'Our usual points per game', $fmt($base['ppg'], 2)],
        ];
        $o .= '<table class="grid rec"><tbody>';
        foreach ($rows as [$l1, $v1, $l2, $v2]) {
            $o .= '<tr><td class="lbl">' . $h($l1) . '</td><td class="val">' . $h($v1) . '</td><td class="lbl">' . $h($l2) . '</td><td class="val">' . $h($v2) . '</td></tr>';
        }
        $o .= '</tbody></table>';

        $block = static fn(string $title, int $y, int $red, int $staff, int $tot): string => '<h2>' . $title . '</h2><table class="grid"><tbody>'
            . '<tr><td>Yellow cards</td><td class="tr">' . $y . '</td></tr><tr><td>Players sent off (red)</td><td class="tr">' . $red . '</td></tr><tr><td>Staff cards</td><td class="tr">' . $staff . '</td></tr>'
            . '<tr class="tot"><td>Total player cards</td><td class="tr">' . $tot . '</td></tr></tbody></table>';
        $o .= '<table class="cols"><tr><td class="col">' . $block('1. Cards he has given us', (int)$r['y_us'], (int)$r['r_us'], (int)$r['staff_us'], (int)$r['cards_us'])
            . '</td><td class="col">' . $block('2. Cards he has given the other team', (int)$r['y_opp'], (int)$r['r_opp'], (int)$r['staff_opp'], (int)$r['cards_opp']) . '</td></tr></table>';

        $o .= '<h2>3. The averages (per game)</h2><table class="grid"><thead><tr><th></th><th class="tr">Per game with ' . $h($r['name']) . '</th></tr></thead><tbody>'
            . '<tr><td>Yellow cards given to us</td><td class="tr">' . $h($fmt($r['y_us'] / $g)) . '</td></tr>'
            . '<tr><td>Yellow cards given to the other team</td><td class="tr">' . $h($fmt($r['y_opp'] / $g)) . '</td></tr>'
            . '<tr><td>Red cards given to us</td><td class="tr">' . $h($fmt($r['r_us'] / $g, 2)) . '</td></tr>'
            . '<tr><td>Red cards given to the other team</td><td class="tr">' . $h($fmt($r['r_opp'] / $g, 2)) . '</td></tr></tbody></table>';

        if ($r['share'] !== null) {
            $dir = ['even' => 'In line with how we are usually carded', 'harder' => 'Harder on us than usual', 'easier' => 'Easier on us than usual'][$r['fair_dir']];
            $o .= '<div class="fair"><b>' . (int)$r['fair'] . '%</b> card fairness &mdash; <b style="font-size:10px">' . $h($dir) . '</b><br>He gives us ' . $h(number_format($r['share'] * 100, 0)) . '% of the cards in his games (' . (int)$r['cards_us'] . ' of ' . (int)($r['cards_us'] + $r['cards_opp']) . '). Across all referees we get ' . $h(number_format($base['share'] * 100, 0)) . '%.'
                . ($r['n_ours'] < 3 ? '<br><span class="small">Only ' . (int)$r['n_ours'] . ' game' . ($r['n_ours'] === 1 ? '' : 's') . ' - a guide, not a verdict.</span>' : '') . '</div>';
        }

        $o .= '<h2>Our games with ' . $h($r['name']) . '</h2><table class="grid"><thead><tr><th>Date</th><th>Match</th><th class="tc">Result</th><th class="tc">Yellows (us / them)</th><th class="tc">Reds (us / them)</th><th class="tc">Pens (us / them)</th></tr></thead><tbody>';
        foreach ($r['ours'] as $m) {
            $o .= '<tr><td>' . $h($uk($m['date'])) . '</td><td>' . $h($m['home'] ? 'Saltcoats Victoria v ' . $m['opponent'] : $m['opponent'] . ' v Saltcoats Victoria') . '</td><td class="tc">' . $h($m['result']) . ' ' . (int)$m['for'] . '-' . (int)$m['against'] . '</td>'
                . '<td class="tc">' . (int)$m['y_us'] . ' / ' . (int)$m['y_opp'] . '</td><td class="tc">' . (int)$m['r_us'] . ' / ' . (int)$m['r_opp'] . '</td><td class="tc">' . (int)$m['pen_us'] . ' / ' . (int)$m['pen_opp'] . '</td></tr>';
        }
        $o .= '</tbody></table>';
    }
    // what he is like + his other matches (from every match on record)
    $o .= '<h2>What ' . $h($r['name']) . ' is like</h2><ul style="margin:0 0 6px 14px;padding:0">';
    foreach (hub_ref_style_lines($r, $lg) as $line) { $o .= '<li style="margin-bottom:3px">' . $h($line) . '</li>'; }
    $o .= '</ul>';
    if ($r['n_matches'] > 0) {
        $o .= '<table class="grid"><thead><tr><th>Per match</th><th class="tr">With ' . $h($r['name']) . '</th><th class="tr">League average</th></tr></thead><tbody>'
            . '<tr><td>Yellow cards</td><td class="tr">' . $h($fmt($r['avg_yellow'])) . '</td><td class="tr">' . $h($fmt($lg['yellow'])) . '</td></tr>'
            . '<tr><td>Yellow cards to the home team</td><td class="tr">' . $h($fmt($r['avg_yh'])) . '</td><td class="tr">' . $h($fmt($lg['yh'])) . '</td></tr>'
            . '<tr><td>Yellow cards to the away team</td><td class="tr">' . $h($fmt($r['avg_ya'])) . '</td><td class="tr">' . $h($fmt($lg['ya'])) . '</td></tr>'
            . '<tr><td>Red cards</td><td class="tr">' . $h($fmt($r['avg_red'], 2)) . '</td><td class="tr">' . $h($fmt($lg['red'], 2)) . '</td></tr>'
            . '<tr><td>Penalties scored</td><td class="tr">' . $h($fmt($r['avg_pens'], 2)) . '</td><td class="tr">' . $h($fmt($lg['pens'], 2)) . '</td></tr>'
            . '<tr><td>Goals</td><td class="tr">' . $h($fmt($r['avg_goals'])) . '</td><td class="tr">' . $h($fmt($lg['goals'])) . '</td></tr></tbody></table>';
        if (array_sum($r['bands']) > 0) {
            $o .= '<h2>When he gives cards</h2><table class="grid"><thead><tr><th>Minutes</th><th class="tr">Cards</th><th class="tr">Per match</th><th class="tr">League per match</th></tr></thead><tbody>';
            foreach (HUB_REF_BANDS as $bi => $bl) { $o .= '<tr><td>' . $h($bl) . '</td><td class="tr">' . (int)$r['bands'][$bi] . '</td><td class="tr">' . $h($fmt($r['bands'][$bi] / $r['n_matches'], 2)) . '</td><td class="tr">' . $h($fmt($lg['bands'][$bi], 2)) . '</td></tr>'; }
            $o .= '</tbody></table>';
        }
    }
    if ($r['other']) {
        $o .= '<h2>' . ($r['n_ours'] > 0 ? 'His other matches' : 'His matches') . ' (' . count($r['other']) . ')</h2><table class="grid"><thead><tr><th>Date</th><th>Match</th><th class="tc">Score</th><th class="tc">Yellows (h / a)</th><th class="tc">Reds (h / a)</th><th class="tc">Pens</th></tr></thead><tbody>';
        foreach ($r['other'] as $m) {
            $o .= '<tr><td>' . $h($uk($m['date'])) . '</td><td>' . $h($m['home'] . ' v ' . $m['away']) . '</td><td class="tc">' . (int)$m['hg'] . '-' . (int)$m['ag'] . '</td><td class="tc">' . (int)$m['yh'] . ' / ' . (int)$m['ya'] . '</td><td class="tc">' . (int)$m['rh'] . ' / ' . (int)$m['ra'] . '</td><td class="tc">' . (int)$m['pens'] . '</td></tr>';
        }
        $o .= '</tbody></table>';
    }
    $o .= '<p class="small" style="margin-top:10px">Card fairness compares the share of cards (yellows and reds) we get in his games with the share we get from all referees; it cannot say whether the cards were deserved. Fouls are not recorded, so red cards and penalties are shown instead. Penalties are those that were scored. Staff cards are not part of the totals or the fairness score.</p>';
    return $o . '</body></html>';
}
