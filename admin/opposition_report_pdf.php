<?php
declare(strict_types=1);

/**
 * A4 PDF export of an opponent scouting report built from an uploaded COMET
 * JSON export (stats.php?tab=opposition). Layout mirrors the reference
 * "Opposition report" PDF the club already produces by hand.
 *
 *   /admin/opposition_report_pdf.php?file=east-kilbride-ym.json
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/opposition_report.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
if (function_exists('hub_auth_has_capability') && !hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}

$path = hub_opposition_report_resolve((string)($_GET['file'] ?? ''));
if ($path === null) {
    http_response_code(404);
    exit('Report not found.');
}

$data = json_decode((string)file_get_contents($path), true);
if (!is_array($data) || !isset($data['matches']) || !is_array($data['matches'])) {
    http_response_code(422);
    exit('That file is not a readable opponent report.');
}

$report = hub_opposition_report_build($data);

if (!class_exists(Dompdf\Dompdf::class)) {
    foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/project_1/vendor/autoload.php'] as $autoloader) {
        if (is_file($autoloader)) {
            require_once $autoloader;
            break;
        }
    }
}
if (!class_exists(Dompdf\Dompdf::class)) {
    http_response_code(500);
    exit('PDF export is unavailable.');
}

$h = static fn(mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$s = $report['summary'];
$gd = $s['gf'] - $s['ga'];

$css = '
@page { margin: 34px 40px 54px; }
body { font-family: "DejaVu Sans", sans-serif; color: #212529; font-size: 10px; margin: 0; }
h1 { font-size: 19px; margin: 0 0 2px; letter-spacing: .3px; }
.sub { color: #6c757d; font-size: 9px; margin: 0 0 14px; }
.foot { position: fixed; bottom: -38px; left: 0; right: 0; color: #9a9a9a; font-size: 8px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
th, td { padding: 5px 9px; font-size: 9.5px; text-align: left; }
.tc { text-align: center; }
.summary th, .summary td { text-align: center; border: 1px solid #3a3a3f; }
.summary thead th { background: #3a3a3f; color: #fff; font-weight: bold; }
.summary tbody td { background: #f4f4f5; border-color: #3a3a3f; font-weight: bold; }
.summary { margin-bottom: 16px; }
h2.section { font-size: 11.5px; margin: 0 0 6px; letter-spacing: .3px; }
.personnel { border: 1px solid #e3e3e6; border-radius: 4px; margin-bottom: 16px; overflow: hidden; }
.personnel table { margin-bottom: 0; }
.personnel th { width: 90px; background: #f4f4f5; font-weight: bold; border-bottom: 1px solid #eeeef1; }
.personnel td { border-bottom: 1px solid #eeeef1; }
.personnel tr:last-child th, .personnel tr:last-child td { border-bottom: none; }
.row-2col { width: 100%; }
.row-2col + .row-2col { margin-top: 12px; }
.col { width: 48%; display: inline-block; vertical-align: top; }
.row-2col .col + .col { margin-left: 3%; }
.page-break { page-break-before: always; }
.card-table thead th { background: #3a3a3f; color: #fff; font-weight: bold; }
.card-table tbody tr:nth-child(even) td { background: #f4f4f5; }
.card-table tbody td { border-bottom: 1px solid #eeeef1; }
.card-table { margin-bottom: 18px; }
.outcome-W { color: #1a7f37; font-weight: bold; }
.outcome-D { color: #6c757d; font-weight: bold; }
.outcome-L { color: #c1121f; font-weight: bold; }
.facts-box { border: 1px solid #e3e3e6; border-radius: 4px; padding: 2px 12px; margin-bottom: 14px; }
.facts-list { list-style: none; margin: 0; padding: 0; }
.facts-list li { padding: 7px 0 7px 15px; position: relative; border-bottom: 1px solid #eeeef1; font-size: 9.5px; line-height: 1.4; }
.facts-list li:last-child { border-bottom: none; }
.facts-list li:before { content: "\2022"; position: absolute; left: 0; color: #3a3a3f; font-weight: bold; }
.note { color: #6c757d; font-size: 8.5px; margin-top: 10px; }
.empty { color: #8c8c90; font-style: italic; }
';

$html = '<!doctype html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';
$html .= '<div class="foot">Generated ' . $h(date('d/m/Y H:i')) . '</div>';
$html .= '<h1>' . $h(mb_strtoupper($report['team'] !== '' ? $report['team'] : 'OPPOSITION')) . ' - OPPOSITION REPORT</h1>';
$html .= '<div class="sub">' . $h($report['competition']) . ($report['competition'] !== '' ? ' | ' : '') . $report['total_matches'] . ' match' . ($report['total_matches'] === 1 ? '' : 'es') . ' analysed</div>';

$html .= '<table class="summary"><thead><tr><th>P</th><th>W</th><th>D</th><th>L</th><th>GF</th><th>GA</th><th>GD</th><th>CS</th></tr></thead><tbody><tr>'
    . '<td>' . $s['p'] . '</td><td>' . $s['w'] . '</td><td>' . $s['d'] . '</td><td>' . $s['l'] . '</td>'
    . '<td>' . $s['gf'] . '</td><td>' . $s['ga'] . '</td><td>' . ($gd > 0 ? '+' : '') . $gd . '</td><td>' . $s['cs'] . '</td>'
    . '</tr></tbody></table>';

$numTag = static fn(string $number): string => $number !== '' ? '#' . $h($number) : '&mdash;';

/* Key personnel: captain and goalkeeper, as their own labelled section. --- */
if ($report['captain'] || $report['goalkeeper']) {
    $html .= '<h2 class="section">KEY PERSONNEL</h2><div class="personnel"><table><tbody>';
    if ($report['captain']) {
        $c = $report['captain'];
        $html .= '<tr><th>Captain</th><td>' . $h($c['player']) . ' (' . $numTag($c['number']) . ') &mdash; armband in ' . $c['matches'] . '/' . $report['total_matches'] . ' matches</td></tr>';
    }
    if ($report['goalkeeper']) {
        $g = $report['goalkeeper'];
        $html .= '<tr><th>Goalkeeper</th><td>' . $h($g['player']) . ' (' . $numTag($g['number']) . ') &mdash; started in goal ' . $g['matches'] . '/' . $report['total_matches'] . ' matches</td></tr>';
    }
    $html .= '</tbody></table></div>';
}

/* Results + Goal threats side by side ------------------------------------ */
$html .= '<div class="row-2col">';
$html .= '<div class="col"><h2 class="section">RESULTS</h2><table class="card-table"><thead><tr><th>Opponent</th><th class="tc">Result</th></tr></thead><tbody>';
if (!$report['results']) {
    $html .= '<tr><td colspan="2" class="empty">No played matches recorded.</td></tr>';
}
foreach ($report['results'] as $r) {
    $html .= '<tr><td>' . $h($r['opponent']) . '</td><td class="tc outcome-' . $h($r['outcome']) . '">' . $r['for'] . '-' . $r['against'] . ' ' . $h($r['outcome']) . '</td></tr>';
}
$html .= '</tbody></table></div>';

$html .= '<div class="col"><h2 class="section">GOAL THREATS</h2><table class="card-table"><thead><tr><th class="tc" style="width:44px">No.</th><th>Player</th><th class="tc">Goals</th></tr></thead><tbody>';
if (!$report['goal_threats']) {
    $html .= '<tr><td colspan="3" class="empty">No goals recorded.</td></tr>';
}
foreach ($report['goal_threats'] as $g) {
    $html .= '<tr><td class="tc">' . $numTag($g['number']) . '</td><td>' . $h($g['player']) . '</td><td class="tc">' . $g['goals'] . '</td></tr>';
}
$html .= '</tbody></table></div>';
$html .= '</div>';

/* Player usage + Discipline side by side ---------------------------------- */
$html .= '<div class="row-2col">';
$html .= '<div class="col"><h2 class="section">PLAYER USAGE</h2><table class="card-table"><thead><tr><th class="tc" style="width:44px">No.</th><th>Player</th><th class="tc">Off</th><th class="tc">On</th></tr></thead><tbody>';
if (!$report['usage']) {
    $html .= '<tr><td colspan="4" class="empty">No substitutions recorded.</td></tr>';
}
foreach ($report['usage'] as $u) {
    $html .= '<tr><td class="tc">' . $numTag($u['number']) . '</td><td>' . $h($u['player']) . '</td><td class="tc">' . ($u['off'] > 0 ? $u['off'] : '-') . '</td><td class="tc">' . ($u['on'] > 0 ? $u['on'] : '-') . '</td></tr>';
}
$html .= '</tbody></table></div>';

$html .= '<div class="col"><h2 class="section">DISCIPLINE</h2><table class="card-table"><thead><tr><th class="tc" style="width:44px">No.</th><th>Player</th><th class="tc">YC</th><th class="tc">RC</th></tr></thead><tbody>';
if (!$report['discipline']) {
    $html .= '<tr><td colspan="4" class="empty">No notable discipline record.</td></tr>';
}
foreach ($report['discipline'] as $d) {
    $html .= '<tr><td class="tc">' . $numTag($d['number']) . '</td><td>' . $h($d['player']) . '</td><td class="tc">' . $d['yc'] . '</td><td class="tc">' . $d['rc'] . '</td></tr>';
}
$html .= '</tbody></table></div>';
$html .= '</div>';

/* Regular starters, ranked by how often they've started — own page. ------- */
$regulars = array_slice($report['regulars'], 0, 11);
if ($regulars) {
    $html .= '<h2 class="section page-break">REGULAR STARTERS</h2><table class="card-table"><thead><tr><th class="tc" style="width:44px">No.</th><th>Player</th><th class="tc">Starts</th><th class="tc">Goals</th><th class="tc">YC</th><th class="tc">RC</th></tr></thead><tbody>';
    foreach ($regulars as $reg) {
        $html .= '<tr><td class="tc">' . $numTag($reg['number']) . '</td><td>' . $h($reg['player']) . '</td><td class="tc">' . $reg['starts'] . '/' . $report['total_matches'] . '</td><td class="tc">' . $reg['goals'] . '</td><td class="tc">' . $reg['yc'] . '</td><td class="tc">' . $reg['rc'] . '</td></tr>';
    }
    $html .= '</tbody></table>';
}

if ($report['key_facts']) {
    $html .= '<h2 class="section">KEY FACTS</h2><div class="facts-box"><ul class="facts-list">';
    foreach ($report['key_facts'] as $fact) {
        [$lead, $rest] = array_pad(explode(':', $fact, 2), 2, '');
        $html .= '<li><strong>' . $h($lead) . ':</strong>' . $h($rest) . '</li>';
    }
    $html .= '</ul></div>';
}

$html .= '<p class="note">Source: uploaded COMET export. Data reflects played matches only. Shirt numbers are the number most often recorded for that player across these matches, not a fixed squad number — grassroots sides commonly rotate numbers matchday to matchday, so treat "#9" etc. as a strong hint, not a guarantee. COMET\'s export only lists the starting XI by name and number, never the bench, so substitutes who never started show no number and their "Off"/"On" totals come from the separate match event log.</p>';

$html .= '</body></html>';

if (ob_get_length() !== false) {
    ob_clean();
}

$dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false]);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$slug = preg_replace('/[^A-Za-z0-9]+/', '-', $report['team'] !== '' ? $report['team'] : 'opposition-report');
$dompdf->stream(trim((string)$slug, '-') . '-opposition-report-' . date('Ymd') . '.pdf', ['Attachment' => true]);
exit;
