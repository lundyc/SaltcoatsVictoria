<?php
/** Route: /table/pdf — A4 PDF export of the public league table (?season=<id>). */
declare(strict_types=1);

pub_raw();

if (!class_exists(Dompdf\Dompdf::class)) {
    foreach ([__DIR__ . '/../admin/vendor/autoload.php'] as $autoloader) {
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

$seasons = pub_league_table_seasons();
$currentSeasonId = pub_current_season_id();
$seasonId = (int) ($_GET['season'] ?? 0);
$validSeasonIds = array_map(static fn (array $s): int => (int) $s['id'], $seasons);
if ($seasonId <= 0 || !in_array($seasonId, $validSeasonIds, true)) {
    $seasonId = $currentSeasonId;
}
$recentSeasonIds = array_slice($validSeasonIds, 0, 2);
$showBadges = in_array($seasonId, $recentSeasonIds, true);

$table = pub_league_table_for_season($seasonId);
$rowCount = count($table['rows']);

$h = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$css = '
@page { margin: 34px 40px 54px; }
body { font-family: "DejaVu Sans", sans-serif; color: #212529; font-size: 10px; margin: 0; }
h1 { font-size: 17px; margin: 0 0 3px; color: #1b1b1f; }
.sub { color: #6c757d; font-size: 9px; margin: 0 0 16px; }
.foot { position: fixed; bottom: -38px; left: 0; right: 0; color: #9a9a9a; font-size: 8px; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 6px 8px; border-top: 1px solid #eeeef1; font-size: 9.5px; text-align: center; }
th:nth-child(2), td.club { text-align: left; }
thead th { background: #f6f6f7; border-top: 0; border-bottom: 1px solid #e0e0e3; }
tr.is-us td { background: #fff6da; font-weight: bold; }
tr.zone-promo td:first-child { border-left: 3px solid #1a7f37; }
tr.zone-releg td:first-child { border-left: 3px solid #c1121f; }
img.badge { width: 14px; height: 14px; vertical-align: middle; margin-right: 5px; }
.key { margin-top: 10px; font-size: 8.5px; color: #6c757d; }
.key span { margin-right: 14px; }
.empty { text-align: center; color: #8c8c90; padding: 20px; }
';

$html = '<!doctype html><html><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';
$html .= '<div class="foot">Saltcoats Victoria &#183; generated ' . $h(date('d/m/Y H:i')) . '</div>';
$html .= '<h1>' . $h($table['title']) . '</h1>';
if ($table['updated']) {
    $html .= '<div class="sub">Updated ' . $h(date('j M Y', $table['updated'])) . '</div>';
}

if (!$table['ok']) {
    $html .= '<div class="empty">No table has been recorded for this season.</div>';
} else {
    $html .= '<table><thead><tr>';
    $html .= '<th>#</th><th>Club</th><th>P</th><th>W</th><th>D</th><th>L</th><th>F</th><th>A</th><th>GD</th><th>Pts</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($table['rows'] as $i => $row) {
        $pos = (int) ($row['pos'] ?: $i + 1);
        $isUs = pub_league_is_us($row);
        $zone = '';
        if ($table['promotion'] > 0 && $pos <= $table['promotion']) {
            $zone = 'promo';
        } elseif ($table['relegation'] > 0 && $pos > $rowCount - $table['relegation']) {
            $zone = 'releg';
        }
        $logo = trim((string) ($row['logo'] ?? ''));
        $html .= '<tr class="' . ($isUs ? 'is-us ' : '') . ($zone ? 'zone-' . $zone : '') . '">';
        $html .= '<td>' . $pos . '</td>';
        $html .= '<td class="club">';
        if ($logo !== '' && $showBadges) {
            $html .= '<img class="badge" src="' . $h(dirname(__DIR__) . '/' . ltrim($logo, '/')) . '">';
        }
        $html .= $h($row['club'] ?? '') . '</td>';
        $html .= '<td>' . $h($row['p'] ?? '') . '</td>';
        $html .= '<td>' . $h($row['w'] ?? '') . '</td>';
        $html .= '<td>' . $h($row['d'] ?? '') . '</td>';
        $html .= '<td>' . $h($row['l'] ?? '') . '</td>';
        $html .= '<td>' . $h($row['f'] ?? '') . '</td>';
        $html .= '<td>' . $h($row['a'] ?? '') . '</td>';
        $html .= '<td>' . $h($row['gd'] ?? '') . '</td>';
        $html .= '<td><b>' . $h($row['pts'] ?? '') . '</b></td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';

    $keyBits = [];
    if ($table['promotion'] > 0) {
        $keyBits[] = '<span>&#9632; Promotion</span>';
    }
    if ($table['relegation'] > 0) {
        $keyBits[] = '<span>&#9632; Relegation</span>';
    }
    if ($keyBits) {
        $html .= '<div class="key">' . implode('', $keyBits) . '</div>';
    }
}

$html .= '</body></html>';

if (ob_get_length() !== false) {
    ob_clean();
}

$dompdf = new Dompdf\Dompdf([
    'isRemoteEnabled' => false,
    'chroot' => [dirname(__DIR__)],
]);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('league-table-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $table['title']) . '.pdf', ['Attachment' => false]);
exit;
