<?php

declare(strict_types=1);

/**
 * Add or delete a substitution for a fixture. POST target of the small
 * "Add sub" / remove forms on match_lineups.php. Writes matchday_subs (+ a
 * linked matchday_events row) via lib/matchday_record.php.
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

$embedded = (string) ($_POST['embedded'] ?? '') === '1';
$fixtureId = (int) ($_POST['fixture_id'] ?? 0);
$seasonId = (int) ($_POST['season_id'] ?? 0);
$action = (string) ($_POST['action'] ?? 'add');

function ms_back(int $fixtureId, int $seasonId, bool $embedded, string $key, string $value): never
{
    $params = ['fixture_id' => $fixtureId, 'season_id' => $seasonId, $key => $value];
    if ($embedded) {
        $params['embedded'] = '1';
    }
    header('Location: match_lineups.php?' . http_build_query($params));
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

$season = function_exists('getSeasonById') ? getSeasonById($pdo, $seasonId) : null;
if (is_array($season) && (int) ($season['is_locked'] ?? 0) === 1) {
    ms_back($fixtureId, $seasonId, $embedded, 'error', 'This season is locked.');
}

try {
    if ($action === 'delete') {
        $subId = (int) ($_POST['sub_id'] ?? 0);
        if ($subId > 0) {
            $owner = $pdo->prepare('SELECT fixture_id FROM matchday_subs WHERE id = :id');
            $owner->execute([':id' => $subId]);
            if ((int) $owner->fetchColumn() === $fixtureId) {
                matchday_sub_delete($pdo, $subId);
                auditLog($pdo, 'matchday_sub_deleted', 'Removed a substitution from fixture #' . $fixtureId);
            }
        }
        ms_back($fixtureId, $seasonId, $embedded, 'saved', 'sub_deleted');
    }

    $side = matchday_record_side((string) ($_POST['side'] ?? ''));
    matchday_sub_add($pdo, $fixtureId, $side, [
        'player_off_name' => (string) ($_POST['player_off_name'] ?? ''),
        'player_on_name' => (string) ($_POST['player_on_name'] ?? ''),
        'minute' => $_POST['minute'] ?? null,
        'minute_extra' => $_POST['minute_extra'] ?? 0,
        'reason' => (string) ($_POST['reason'] ?? ''),
    ], (int) ($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null);
    auditLog($pdo, 'matchday_sub_added', 'Recorded a substitution for fixture #' . $fixtureId);
} catch (InvalidArgumentException $e) {
    ms_back($fixtureId, $seasonId, $embedded, 'error', $e->getMessage());
} catch (Throwable $e) {
    error_log('match_sub_save: ' . $e->getMessage());
    ms_back($fixtureId, $seasonId, $embedded, 'error', 'The substitution could not be saved.');
}

ms_back($fixtureId, $seasonId, $embedded, 'saved', 'sub');
