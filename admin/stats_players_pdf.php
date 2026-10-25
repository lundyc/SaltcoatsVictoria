<?php
declare(strict_types=1);

/**
 * PDF export of the Players tab (stats.php?tab=players): the same rows, sort order and Current / Left
 * split as the screen. Reuses hub_stats_player_contributions() so the numbers match.
 *
 *   /admin/stats_players_pdf.php?season_id=2&competitions[]=...&venue=&sort=minutes_played&direction=desc
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
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

$seasonId = (int)($_GET['season_id'] ?? 0);
$seasonStmt = $pdo->prepare('SELECT name FROM seasons WHERE id = ?');
$seasonStmt->execute([$seasonId]);
$seasonName = (string)$seasonStmt->fetchColumn();
if ($seasonName === '') {
    http_response_code(400);
    exit('Missing or invalid parameters.');
}

$competitions = array_values(array_filter(array_map('strval', is_array($_GET['competitions'] ?? null) ? $_GET['competitions'] : [])));
$venue = is_string($_GET['venue'] ?? null) && in_array($_GET['venue'], ['home', 'away'], true) ? $_GET['venue'] : '';
$dataFile = __DIR__ . '/data/matches.json';

$table = hub_stats_player_contributions(
    $pdo,
    $seasonId,
    hub_stats_player_fixtures($pdo, $seasonId, $competitions, $venue),
    hub_stats_events_by_fixture($dataFile),
    $dataFile,
    $_GET['sort'] ?? null,
    $_GET['direction'] ?? null
);

$venueLabel = ['' => 'Home and away', 'home' => 'Home games', 'away' => 'Away games'][$venue];
$subtitle = implode('  ·  ', array_filter([
    $seasonName,
    implode(', ', $competitions),
    $venueLabel,
    'Sorted by ' . $table['sorts'][$table['sort']] . ' (' . ($table['direction'] === 'asc' ? 'ascending' : 'descending') . ')',
]));

hub_stats_players_pdf_render($pdo, $subtitle, $table);
