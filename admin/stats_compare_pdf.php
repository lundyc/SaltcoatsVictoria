<?php
declare(strict_types=1);

/**
 * A4 PDF export of the "Compare seasons" view (stats.php?tab=compare).
 * Reuses hub_stats_compare_build() so the numbers match the screen exactly.
 *
 *   /admin/stats_compare_pdf.php?seasons[]=15&seasons[]=1&pmetric[]=goals&venue=&competitions[]=...
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/player_match_stats.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
if (function_exists('hub_auth_has_capability') && !hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}

$seasonContext = getSeasonContext($pdo);

$reqMetrics = $_GET['pmetric'] ?? null;
$cmp = hub_stats_compare_build($pdo, $seasonContext['seasons'], [
    'season_ids'   => is_array($_GET['seasons'] ?? null) ? $_GET['seasons'] : [],
    'competitions' => is_array($_GET['competitions'] ?? null) ? $_GET['competitions'] : [],
    'venue'        => is_string($_GET['venue'] ?? null) ? $_GET['venue'] : '',
    'metrics'      => is_array($reqMetrics) ? $reqMetrics : (is_string($reqMetrics) ? [$reqMetrics] : []),
    'data_file'    => __DIR__ . '/data/matches.json',
]);

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

/* --------------------------------------------------------------- helpers */

$h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$sids = $cmp['season_ids'];
$playerSeasons = $cmp['player_seasons'];
$labels = $cmp['metric_labels'];
$seasonName = static fn(int $sid): string => (string) ($cmp['season_by_id'][$sid]['name'] ?? $sid);

$delta = static function (?float $cur, ?float $prev, ?string $better): string {
    if ($cur === null || $prev === null || $better === null) {
        return '';
    }
    $d = $cur - $prev;
    if (abs($d) < 0.005) {
        return ' <span class="d0">&#177;0</span>';
    }
    $good = $better === 'high' ? $d > 0 : $d < 0;
    $num = fmod($d, 1.0) === 0.0 ? (string) (int) round($d) : number_format($d, 2);
    $num = htmlspecialchars($num, ENT_QUOTES, 'UTF-8');
    return ' <span class="' . ($good ? 'up' : 'dn') . '">' . ($d > 0 ? '&#9650; +' : '&#9660; ') . $num . '</span>';
};

$intDelta = static function (int $v, ?int $prev, string $better): string {
    if ($prev === null || $v === $prev) {
        return '';
    }
    $d = $v - $prev;
    $good = $better === 'high' ? $d > 0 : $d < 0;
    return ' <span class="' . ($good ? 'up' : 'dn') . '">' . ($d > 0 ? '&#9650; +' : '&#9660; ') . $d . '</span>';
};

$filterBits = array_values(array_filter([
    count($sids) . ' season' . (count($sids) === 1 ? '' : 's'),
    $cmp['venue'] !== '' ? ucfirst($cmp['venue']) . ' matches only' : '',
    $cmp['competitions'] ? implode(', ', $cmp['competitions']) : '',
]));

/* --------------------------------------------------------------- markup */

$css = '
@page { margin: 34px 40px 54px; }
body { font-family: "DejaVu Sans", sans-serif; color: #212529; font-size: 10px; margin: 0; }
h1 { font-size: 17px; margin: 0 0 3px; color: #1b1b1f; }
.sub { color: #6c757d; font-size: 9px; margin: 0 0 16px; }
.foot { position: fixed; bottom: -38px; left: 0; right: 0; color: #9a9a9a; font-size: 8px; }
.card { border: 1px solid #e3e3e6; border-radius: 6px; margin-bottom: 16px; }
.card--keep { page-break-inside: avoid; }
.card h2 { font-size: 12px; margin: 0; padding: 9px 11px 1px; }
.card .note { color: #6c757d; font-size: 8.5px; margin: 0; padding: 1px 11px 9px; }
table { width: 100%; border-collapse: collapse; }
thead { display: table-header-group; }
th, td { padding: 5px 9px; border-top: 1px solid #eeeef1; font-size: 9px; text-align: right; vertical-align: top; }
thead th { background: #f6f6f7; border-top: 0; border-bottom: 1px solid #e0e0e3; }
thead th:first-child, tbody th { text-align: left; }
tbody th { font-weight: bold; }
tbody tr:first-child td, tbody tr:first-child th { border-top: 0; }
tr.left td, tr.left th { color: #8c8c90; }
.pill { border: 1px solid #d3d3d8; border-radius: 8px; padding: 0 4px; font-size: 7.5px; color: #6c757d; white-space: nowrap; }
.tot { font-weight: bold; }
.up { color: #1a7f37; }
.dn { color: #c1121f; }
.d0 { color: #9a9a9a; }
.empty { text-align: center; color: #8c8c90; padding: 14px; }
';

$html = '<!doctype html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';
$html .= '<div class="foot">Saltcoats Victoria &#183; generated ' . $h(date('d/m/Y H:i')) . '</div>';
$html .= '<h1>Season comparison</h1>';
$html .= '<div class="sub">' . $h(implode('  &#183;  ', $filterBits)) . '</div>';

if (!$sids) {
    $html .= '<p>No seasons selected.</p>';
} else {
    /* Team stats -------------------------------------------------------- */
    $html .= '<div class="card card--keep"><h2>Team stats year on year</h2>'
        . '<p class="note">Rate metrics use matches with a recorded full-time score. Points are 3 for a win, 1 for a draw. The arrow compares each season with the one to its left.</p>'
        . '<table><thead><tr><th>Metric</th>';
    foreach ($sids as $sid) {
        $html .= '<th>' . $h($seasonName($sid)) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach (hub_stats_compare_team_rows() as [$rowLabel, $getStr, $getRaw, $better]) {
        $html .= '<tr><th>' . $h($rowLabel) . '</th>';
        $prevRaw = null;
        foreach ($sids as $i => $sid) {
            $c = $cmp['team'][$sid];
            $raw = $getRaw ? $getRaw($c) : null;
            $html .= '<td>' . $h($getStr($c)) . ($i > 0 ? $delta($raw, $prevRaw, $better) : '') . '</td>';
            $prevRaw = $raw;
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    /* Player stats, one table per metric ----------------------------- */
    if ($cmp['no_player_seasons']) {
        $html .= '<div class="sub">No player lineup data recorded for '
            . $h(implode(', ', array_map($seasonName, $cmp['no_player_seasons'])))
            . ' &#8212; those seasons are left out of the player tables.</div>';
    }

    if (!$playerSeasons) {
        $html .= '<div class="card"><h2>Player stats year on year</h2><p class="empty">No player lineups or events recorded for the selected seasons.</p></div>';
    } else {
        foreach ($cmp['metrics'] as $metric) {
            $pm = $cmp['players'][$metric];
            $label = mb_strtolower($labels[$metric]);
            $note = 'Players who registered any ' . $label . ' across the chosen seasons.';
            if ($metric === 'clean_sheets') {
                $note .= ' Clean sheets count only for the starting goalkeeper.';
            }
            if ($pm['any_left']) {
                $note .= ' Players who have left the club are listed last and marked.';
            }
            if ($pm['hidden_zero'] > 0) {
                $note .= ' (' . (int) $pm['hidden_zero'] . ' with none are hidden.)';
            }

            $html .= '<div class="card"><h2>Player ' . $h($labels[$metric]) . ' year on year</h2>'
                . '<p class="note">' . $h($note) . '</p><table><thead><tr><th>Player</th>';
            foreach ($playerSeasons as $sid) {
                $html .= '<th>' . $h($seasonName($sid)) . '</th>';
            }
            if (count($playerSeasons) > 1) {
                $html .= '<th>Total</th>';
            }
            $html .= '</tr></thead><tbody>';

            if (!$pm['rows']) {
                $html .= '<tr><td class="empty" colspan="' . (count($playerSeasons) + 2) . '">No players registered any ' . $h($label) . ' in these seasons.</td></tr>';
            }
            foreach ($pm['rows'] as $row) {
                $html .= '<tr class="' . ($row['left'] ? 'left' : '') . '"><th>' . $h($row['name']);
                if ($row['left']) {
                    $html .= ' <span class="pill">' . ($row['status'] === 'retired' ? 'Retired' : 'Left club') . '</span>';
                }
                $html .= '</th>';
                $prev = null;
                foreach ($playerSeasons as $i => $sid) {
                    $v = (int) ($row['vals'][$sid] ?? 0);
                    $html .= '<td>' . $v . ($i > 0 ? $intDelta($v, $prev, $pm['better']) : '') . '</td>';
                    $prev = $v;
                }
                if (count($playerSeasons) > 1) {
                    $html .= '<td class="tot">' . (int) $row['total'] . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div>';
        }
    }
}

$html .= '</body></html>';

if (ob_get_length() !== false) {
    ob_clean();
}

$dompdf = new Dompdf\Dompdf(['isRemoteEnabled' => false]);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('season-comparison-' . date('Ymd') . '.pdf', ['Attachment' => true]);
exit;
