<?php
declare(strict_types=1);

/**
 * GET  ?key=<tour>  → JSON steps for one tour (only if the user may take it).
 * POST action=set|reset, key, status, csrf_token → remember Help progress.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/help_tours.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if (hub_auth_current_user() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$tours = help_tours_for_user();
$validKeys = array_merge(array_keys(help_tour_definitions()), ['prompt']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $key = (string) ($_GET['key'] ?? '');
    if (!isset($tours[$key])) {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }
    echo json_encode(['ok' => true, 'title' => $tours[$key]['title'], 'steps' => $tours[$key]['steps']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hub_auth_verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

$key = (string) ($_POST['key'] ?? '');
$accountId = help_account_id();
if ($accountId <= 0 || !in_array($key, $validKeys, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

if (($_POST['action'] ?? '') === 'reset') {
    $pdo->prepare('DELETE FROM user_help_state WHERE account_id = :a AND state_key = :k')->execute([':a' => $accountId, ':k' => $key]);
    echo json_encode(['ok' => true]);
    exit;
}

$status = (string) ($_POST['status'] ?? '');
if (!in_array($status, HELP_STATE_STATUSES, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}
$pdo->prepare('INSERT INTO user_help_state (account_id, state_key, status) VALUES (:a, :k, :s)
    ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = CURRENT_TIMESTAMP')
    ->execute([':a' => $accountId, ':k' => $key, ':s' => $status]);
echo json_encode(['ok' => true]);
