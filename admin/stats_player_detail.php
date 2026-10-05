<?php
declare(strict_types=1);

/**
 * Match-by-match breakdown behind one player/metric cell on the "Compare
 * seasons" tab (stats.php?tab=compare) — powers the click-a-name modal.
 *
 *   /admin/stats_player_detail.php?player=...&metric=goals&seasons[]=15&seasons[]=1&competitions[]=...&venue=
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/player_match_stats.php';

header('Content-Type: application/json');

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
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
    echo json_encode(['success' => false, 'error' => 'Missing or invalid parameters.']);
    exit;
}

$detail = $metric === 'all'
    ? hub_player_all_events_match_rows($pdo, $seasonIds, $seasonById, $player, $competitions, $venue, __DIR__ . '/data/matches.json')
    : hub_player_stat_match_rows($pdo, $seasonIds, $seasonById, $player, $metric, $competitions, $venue, __DIR__ . '/data/matches.json');

echo json_encode([
    'success' => true,
    'player' => $detail['player'],
    'metric' => $metric,
    'metric_label' => $metric === 'all' ? 'Match-by-match' : $metricLabels[$metric],
    'total' => $detail['total'],
    'summary' => $detail['summary'] ?? null,
    'rows' => $detail['rows'],
]);
