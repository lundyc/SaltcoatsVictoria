<?php

declare(strict_types=1);

/** Delete one match event (and its linked substitution row, if any). */

require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/matchday_record.php';
require_once __DIR__ . '/lib/audit.php';

$fixtureId = (int) ($_POST['fixture_id'] ?? 0);
$seasonId = (int) ($_POST['season_id'] ?? 0);
$eventId = (int) ($_POST['event_id'] ?? 0);

function mred_back(int $fixtureId, int $seasonId, string $key, string $value): never
{
    header('Location: match_record_events.php?' . http_build_query([
        'fixture_id' => $fixtureId,
        'season_id' => $seasonId,
        $key => $value,
    ]));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    http_response_code(400);
    exit('Invalid request.');
}

$fixture = $fixtureId > 0 ? matchday_record_fixture($pdo, $fixtureId) : null;
if ($fixture === null || ($seasonId > 0 && (int) $fixture['season_id'] !== $seasonId)) {
    http_response_code(404);
    exit('Fixture not found.');
}
$seasonId = (int) $fixture['season_id'];

$season = getSeasonById($pdo, $seasonId);
if (is_array($season) && (int) ($season['is_locked'] ?? 0) === 1) {
    mred_back($fixtureId, $seasonId, 'error', 'This season is locked.');
}

if ($eventId > 0) {
    $owner = $pdo->prepare('SELECT fixture_id FROM matchday_events WHERE id = :id');
    $owner->execute([':id' => $eventId]);
    if ((int) $owner->fetchColumn() === $fixtureId) {
        try {
            matchday_event_delete($pdo, $eventId);
            auditLog($pdo, 'matchday_event_deleted', "Deleted an event from fixture #{$fixtureId}");
        } catch (Throwable $e) {
            error_log('match_record_event_delete: ' . $e->getMessage());
            mred_back($fixtureId, $seasonId, 'error', 'The event could not be deleted.');
        }
    }
}

mred_back($fixtureId, $seasonId, 'saved', 'deleted');
