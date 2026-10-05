<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/player_availability.php';
require_once __DIR__ . '/lib/audit.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
if (!csrf_check()) {
    http_response_code(419);
    exit('Your session expired. Reload the fixture and try again.');
}

$fixtureId = (int) ($_POST['fixture_id'] ?? 0);
$seasonId = (int) ($_POST['season_id'] ?? 0);
$playerId = (int) ($_POST['player_id'] ?? 0);
$status = trim((string) ($_POST['status'] ?? 'unknown'));
$reason = trim((string) ($_POST['reason'] ?? ''));
$notes = trim((string) ($_POST['notes'] ?? ''));
$accountId = (int) ($_SESSION['hub_account_id'] ?? 0) ?: null;

try {
    player_availability_save($pdo, $fixtureId, $playerId, $status, $reason, $notes, $accountId);
    auditLog($pdo, 'player_availability_saved', "Set player #{$playerId} availability to {$status} for fixture #{$fixtureId}");
    header('Location: match_starting_11.php?fixture_id=' . $fixtureId . '&season_id=' . $seasonId . '&availability_saved=1#playerAvailabilityPanel');
    exit;
} catch (Throwable $exception) {
    error_log('Player availability save failed: ' . $exception->getMessage());
    header('Location: match_starting_11.php?fixture_id=' . $fixtureId . '&season_id=' . $seasonId . '&availability_error=' . rawurlencode($exception->getMessage()) . '#playerAvailabilityPanel');
    exit;
}
