<?php
declare(strict_types=1);

/**
 * PDF version of stats_player_detail.php's match-by-match breakdown — the
 * "Download as PDF" link on the click-a-name modal (stats.php).
 *
 *   /admin/stats_player_detail_pdf.php?player=...&metric=all&seasons[]=2&competitions[]=...&venue=&download=1
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/player_match_stats.php';

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    exit('Authentication required.');
}

$metricLabels = hub_stats_compare_metric_labels();
$metric = is_string($_GET['metric'] ?? null) ? $_GET['metric'] : '';
$player = is_string($_GET['player'] ?? null) ? trim($_GET['player']) : '';
$venue = is_string($_GET['venue'] ?? null) && in_array($_GET['venue'], ['home', 'away'], true) ? $_GET['venue'] : '';
$competitions = array_values(array_filter(array_map('strval', is_array($_GET['competitions'] ?? null) ? $_GET['competitions'] : [])));

$seasons = getSeasons($pdo);
$seasonById = [];
foreach ($seasons as $s) {
    $seasonById[(int)$s['id']] = $s;
}
$seasonIds = [];
foreach (is_array($_GET['seasons'] ?? null) ? $_GET['seasons'] : [] as $rid) {
    $rid = (int)$rid;
    if (isset($seasonById[$rid]) && !in_array($rid, $seasonIds, true)) {
        $seasonIds[] = $rid;
    }
}
usort($seasonIds, static fn(int $a, int $b): int =>
    strcmp((string)($seasonById[$a]['start_date'] ?? ''), (string)($seasonById[$b]['start_date'] ?? '')) ?: ($a <=> $b));

if (($metric !== 'all' && !isset($metricLabels[$metric])) || $player === '' || $seasonIds === []) {
    http_response_code(400);
    exit('Missing or invalid parameters.');
}

$detail = $metric === 'all'
    ? hub_player_all_events_match_rows($pdo, $seasonIds, $seasonById, $player, $competitions, $venue, '')
    : hub_player_stat_match_rows($pdo, $seasonIds, $seasonById, $player, $metric, $competitions, $venue, '');

hub_player_match_detail_pdf_render(
    $pdo,
    $detail['player'],
    $metric === 'all' ? 'Match-by-match' : $metricLabels[$metric],
    $detail['rows'],
    $detail['summary'] ?? null,
    isset($_GET['download'])
);
