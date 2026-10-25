<?php

declare(strict_types=1);

/**
 * End-of-season archive, run from the season editor (season.php).
 *
 * Saves the current season's live league table to the Historical League
 * Tables and, optionally, locks the season. Only the current season can be
 * archived: the live table (cache/wosfl_table.json) belongs to it.
 */

require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('club_setup')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/league_table_history.php';
require_once __DIR__ . '/lib/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}
if (!csrf_check()) {
    http_response_code(400);
    exit('Invalid CSRF token.');
}

$seasonId = (int) ($_POST['season_id'] ?? 0);
$back = static function (string $query) use ($seasonId): never {
    header('Location: /admin/season.php?id=' . $seasonId . '&' . $query . '#pane-archive');
    exit;
};

$season = $seasonId > 0 ? getSeasonById($pdo, $seasonId) : null;
if (!$season) {
    http_response_code(404);
    exit('Season not found.');
}
if ((int) ($season['is_current'] ?? 0) !== 1) {
    $back('archive_error=not_current');
}

$saveTable = !empty($_POST['archive_table']);
$lockSeason = !empty($_POST['archive_lock']);
if (!$saveTable && !$lockSeason) {
    $back('archive_error=nothing');
}

$done = [];
if ($saveTable) {
    $snapshot = leagueTableHistoryLiveSnapshot($pdo, $season);
    if (count($snapshot['rows']) < 2) {
        $back('archive_error=no_table');
    }
    leagueTableHistorySave(
        $pdo,
        $seasonId,
        $snapshot['rows'],
        $snapshot['title'],
        $snapshot['promotion'],
        $snapshot['relegation'],
        'archive:live'
    );
    $done[] = 'league table saved (' . count($snapshot['rows']) . ' clubs)';
}
if ($lockSeason) {
    $pdo->prepare('UPDATE seasons SET is_locked = 1 WHERE id = :id LIMIT 1')->execute([':id' => $seasonId]);
    $done[] = 'season locked';
}

auditLog($pdo, 'season_archived', 'Archived season "' . ($season['name'] ?? '') . '": ' . implode(', ', $done));
$back('archived=1');
