<?php

declare(strict_types=1);

/**
 * Add / update one match event, or apply the event-derived score to the
 * fixture. POST target of match_record_events.php.
 */

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
$formAction = (string) ($_POST['form_action'] ?? 'save');

function mre_back(int $fixtureId, int $seasonId, string $key, string $value): never
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
    mre_back($fixtureId, $seasonId, 'error', 'This season is locked.');
}

$userId = (int) ($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null;

try {
    if ($formAction === 'apply_score') {
        $applied = matchday_record_apply_score($pdo, $fixtureId);
        auditLog($pdo, 'matchday_score_applied', sprintf(
            'Applied events-derived score %d-%d to fixture #%d',
            $applied['home'], $applied['away'], $fixtureId
        ));
        mre_back($fixtureId, $seasonId, 'saved', 'score');
    }

    $type = trim((string) ($_POST['type'] ?? ''));
    $data = [
        'type' => $type,
        'side' => (string) ($_POST['side'] ?? 'svfc'),
        'minute' => $_POST['minute'] ?? null,
        'minute_extra' => $_POST['minute_extra'] ?? 0,
        'player_name' => (string) ($_POST['player_name'] ?? ''),
        'secondary_player_name' => (string) ($_POST['secondary_player_name'] ?? ''),
        'own_goal' => !empty($_POST['own_goal']),
        'note' => (string) ($_POST['note'] ?? ''),
    ];
    $eventId = (int) ($_POST['event_id'] ?? 0);
    if ($eventId > 0) {
        $data['id'] = $eventId;
    }

    if ($type === 'substitution') {
        mre_back($fixtureId, $seasonId, 'error', 'Add substitutions on the Line-ups tab.');
    }

    matchday_event_save($pdo, $fixtureId, $data, $userId);
    auditLog($pdo, 'matchday_event_saved', ($eventId > 0 ? 'Updated' : 'Added') . " a '{$type}' event on fixture #{$fixtureId}");
} catch (InvalidArgumentException $e) {
    mre_back($fixtureId, $seasonId, 'error', $e->getMessage());
} catch (Throwable $e) {
    error_log('match_record_event_save: ' . $e->getMessage());
    mre_back($fixtureId, $seasonId, 'error', 'The event could not be saved.');
}

mre_back($fixtureId, $seasonId, 'saved', 'event');
