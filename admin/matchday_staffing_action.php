<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/matchday_staffing.php';
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
$action = trim((string) ($_POST['action'] ?? 'save'));
$assignmentId = (int) ($_POST['assignment_id'] ?? 0);
$accountId = (int) ($_SESSION['hub_account_id'] ?? 0) ?: null;

if ($fixtureId <= 0) {
    http_response_code(422);
    exit('Fixture is required.');
}

try {
    if ($action === 'delete') {
        matchday_staffing_delete_assignment($pdo, $fixtureId, $assignmentId);
        auditLog($pdo, 'matchday_staff_deleted', "Deleted matchday staff assignment #{$assignmentId} for fixture #{$fixtureId}");
        header('Location: match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&tab=overview&staffing_deleted=1#matchdayStaffingCard');
        exit;
    }

    if ($action === 'status') {
        $status = trim((string) ($_POST['status'] ?? 'planned'));
        matchday_staffing_update_status($pdo, $fixtureId, $assignmentId, $status);
        auditLog($pdo, 'matchday_staff_status_updated', "Set assignment #{$assignmentId} to {$status} for fixture #{$fixtureId}");
        header('Location: match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&tab=overview&staffing_saved=1#matchdayStaffingCard');
        exit;
    }

    $savedId = matchday_staffing_save_assignment($pdo, $fixtureId, $assignmentId > 0 ? $assignmentId : null, [
        'role_key' => $_POST['role_key'] ?? '',
        'role_label' => $_POST['role_label'] ?? '',
        'person_id' => $_POST['person_id'] ?? 0,
        'report_time' => $_POST['report_time'] ?? '',
        'status' => $_POST['status'] ?? 'planned',
        'notes' => $_POST['notes'] ?? '',
    ], $accountId);
    auditLog($pdo, 'matchday_staff_saved', "Saved matchday staff assignment #{$savedId} for fixture #{$fixtureId}");
    header('Location: match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&tab=overview&staffing_saved=1#matchdayStaffingCard');
    exit;
} catch (Throwable $exception) {
    error_log('Matchday staffing action failed: ' . $exception->getMessage());
    header('Location: match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&tab=overview&staffing_error=' . rawurlencode($exception->getMessage()) . '#matchdayStaffingCard');
    exit;
}
