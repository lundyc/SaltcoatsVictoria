<?php
declare(strict_types=1);

/**
 * Lets the scanner page report problems only the phone can see: camera refused or
 * unsupported, no signal, timeouts, unreadable replies, JavaScript errors.
 * Needs a logged-in scanner and the page's CSRF token, and is rate limited.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/lib/functions.php';
    require_once __DIR__ . '/lib/scan_problems.php';

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hub_auth_is_authenticated() || !hub_auth_has_capability('tickets_scan') || !csrf_check()) {
        http_response_code(403);
        echo json_encode(['ok' => false]);
        exit;
    }
    $user = hub_auth_current_user();
    $userId = (int) ($user['account_id'] ?? 0);

    $kind = (string) ($_POST['kind'] ?? '');
    $allowed = ['network', 'timeout', 'bad_response', 'camera_error', 'camera_unsupported', 'client_error', 'session_expired'];
    if (!in_array($kind, $allowed, true)) {
        http_response_code(422);
        echo json_encode(['ok' => false]);
        exit;
    }

    scan_problems_ensure_schema($pdo);
    $count = $pdo->prepare("SELECT COUNT(*) FROM scan_problems WHERE source = 'client' AND user_id = :u AND created_at > (NOW() - INTERVAL 1 MINUTE)");
    $count->execute([':u' => $userId]);
    if ((int) $count->fetchColumn() >= 30) {
        echo json_encode(['ok' => true, 'throttled' => true]);
        exit;
    }

    $ref = scan_problem_log($pdo, $kind, mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 255) ?: (SCAN_PROBLEM_KINDS[$kind] ?? $kind), [
        'source' => 'client',
        'fixture_id' => (int) ($_POST['fixture_id'] ?? 0),
        'user_id' => $userId,
        'http_status' => isset($_POST['http_status']) ? (int) $_POST['http_status'] : null,
        'duration_ms' => isset($_POST['duration_ms']) ? max(0, (int) $_POST['duration_ms']) : null,
        'detail' => (string) ($_POST['detail'] ?? ''),
    ]);
    echo json_encode(['ok' => true, 'ref' => $ref]);
} catch (Throwable $e) {
    error_log('scan_problem_report failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
