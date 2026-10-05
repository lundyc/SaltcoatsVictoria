<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_checklist.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: application/json; charset=UTF-8');

function match_checklist_action_fail(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    match_checklist_action_fail('Method not allowed. Use POST.', 405);
}
if (!hub_auth_is_authenticated()) {
    match_checklist_action_fail('Authentication required.', 401);
}
if (!csrf_check()) {
    match_checklist_action_fail('Your session expired. Reload the fixture and try again.', 419);
}

$fixtureId = (int) ($_POST['fixture_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
if ($fixtureId <= 0) {
    match_checklist_action_fail('Fixture id is required.', 422);
}

$userId = (int) ($_SESSION['hub_user_id'] ?? 0) ?: null;

try {
    if ($action === 'toggle_system') {
        $itemKey = trim((string) ($_POST['item_key'] ?? ''));
        $checked = (string) ($_POST['checked'] ?? '') === '1';
        if ($itemKey === '') {
            match_checklist_action_fail('Item key is required.', 422);
        }
        match_checklist_set_system_checked($pdo, $fixtureId, $itemKey, $checked, $userId);
        auditLog($pdo, 'match_checklist_item_toggled', ($checked ? 'Checked' : 'Unchecked') . " checklist item '{$itemKey}' for fixture #{$fixtureId}");
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'toggle_custom') {
        $id = (int) ($_POST['id'] ?? 0);
        $checked = (string) ($_POST['checked'] ?? '') === '1';
        if ($id <= 0) {
            match_checklist_action_fail('Item id is required.', 422);
        }
        $updated = match_checklist_set_custom_checked($pdo, $fixtureId, $id, $checked);
        if (!$updated) {
            match_checklist_action_fail('Checklist item not found.', 404);
        }
        auditLog($pdo, 'match_checklist_item_toggled', ($checked ? 'Checked' : 'Unchecked') . " custom checklist item #{$id} for fixture #{$fixtureId}");
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'add_custom') {
        $label = trim((string) ($_POST['label'] ?? ''));
        if ($label === '') {
            match_checklist_action_fail('Enter some text for the checklist item.', 422);
        }
        $id = match_checklist_add_custom($pdo, $fixtureId, $label, $userId);
        auditLog($pdo, 'match_checklist_item_added', "Added checklist item '" . mb_substr($label, 0, 255) . "' to fixture #{$fixtureId}");
        echo json_encode(['ok' => true, 'id' => $id, 'label' => mb_substr($label, 0, 255)]);
        exit;
    }

    if ($action === 'delete_custom') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            match_checklist_action_fail('Item id is required.', 422);
        }
        $deleted = match_checklist_delete_custom($pdo, $fixtureId, $id);
        if (!$deleted) {
            match_checklist_action_fail('Checklist item not found.', 404);
        }
        auditLog($pdo, 'match_checklist_item_deleted', "Deleted custom checklist item #{$id} from fixture #{$fixtureId}");
        echo json_encode(['ok' => true]);
        exit;
    }

    match_checklist_action_fail('Unknown checklist action.', 422);
} catch (InvalidArgumentException $e) {
    match_checklist_action_fail($e->getMessage(), 422);
} catch (Throwable $e) {
    error_log('Match checklist action failed: ' . $e->getMessage());
    match_checklist_action_fail('The checklist could not be updated.', 500);
}
