<?php
declare(strict_types=1);

$scanStartedAt = microtime(true);

header('Content-Type: application/json');
header('Cache-Control: no-store');

/** Send the JSON reply (with how long the server took) and stop. */
function scan_reply(int $status, array $payload): never
{
    global $scanStartedAt;
    http_response_code($status);
    $payload['ms'] = (int) round((microtime(true) - $scanStartedAt) * 1000);
    echo json_encode($payload);
    exit;
}

$pdo ??= null;
$user = null;
$fixtureId = 0;

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/lib/functions.php';
    require_once __DIR__ . '/lib/season_ticket_attendance.php';
    require_once __DIR__ . '/lib/match_tickets.php';
    require_once __DIR__ . '/lib/scan_problems.php';
    require_once __DIR__ . '/lib/events_scan.php';
    require_once __DIR__ . '/lib/scan_processor.php';

    $fixtureId = (int) ($_POST['fixture_id'] ?? 0);
    $eventId = (int) ($_POST['event_id'] ?? 0);

    if (!hub_auth_is_authenticated()) {
        // Throttled: one entry per address per minute, so a logged-out phone retrying does not flood the log.
        scan_problems_ensure_schema($pdo);
        $recent = $pdo->prepare("SELECT COUNT(*) FROM scan_problems WHERE kind = 'not_logged_in' AND ip = :ip AND created_at > (NOW() - INTERVAL 1 MINUTE)");
        $recent->execute([':ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '')]);
        $ref = (int) $recent->fetchColumn() === 0
            ? scan_problem_log($pdo, 'not_logged_in', 'Scan attempted without a login (session expired or logged out).', ['fixture_id' => $fixtureId, 'http_status' => 401])
            : '';
        scan_reply(401, ['ok' => false, 'code' => 'not_logged_in', 'ref' => $ref, 'message' => 'You are logged out. Log in again to keep scanning.']);
    }

    $user = hub_auth_current_user();
    // The admissions tables reference accounts.id, not the old season-ticket holder id.
    $userId = !empty($user['account_id']) ? (int) $user['account_id'] : null;
    $problemCtx = ['fixture_id' => $fixtureId, 'user_id' => (int) $userId];

    if (!hub_auth_has_capability('tickets_scan')) {
        $ref = scan_problem_log($pdo, 'no_permission', 'User without the scan permission tried to scan.', $problemCtx + ['http_status' => 403]);
        scan_reply(403, ['ok' => false, 'code' => 'no_permission', 'ref' => $ref, 'message' => 'You do not have permission to scan tickets.']);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $ref = scan_problem_log($pdo, 'bad_request', 'Scan endpoint called with ' . ($_SERVER['REQUEST_METHOD'] ?? 'GET') . ' instead of POST.', $problemCtx + ['http_status' => 405]);
        scan_reply(405, ['ok' => false, 'code' => 'bad_request', 'ref' => $ref, 'message' => 'Method not allowed.']);
    }

    if (!csrf_check()) {
        $ref = scan_problem_log($pdo, 'session_expired', 'Security token did not match (the scanner page is older than the login session).', $problemCtx + ['http_status' => 419]);
        scan_reply(419, ['ok' => false, 'code' => 'session_expired', 'ref' => $ref, 'message' => 'The scanner has expired. Reload this page and scan again.']);
    }

    // All the scanning rules live in lib/scan_processor.php (shared with offline sync and the door dashboard).
    $outcome = scan_process_ticket($pdo, [
        'kind' => $eventId > 0 ? 'event' : 'match',
        'id' => $eventId > 0 ? $eventId : $fixtureId,
        'value' => (string) ($_POST['scan_value'] ?? ''),
        'gate' => (string) ($_POST['gate'] ?? ''),
        'device' => (string) ($_POST['device'] ?? ''),
    ], $userId);
    $elapsed = (int) round((microtime(true) - $scanStartedAt) * 1000);
    if ($outcome['http'] === 200 && $elapsed > 1500) {
        scan_problem_log($pdo, 'slow_scan', 'Scan took ' . $elapsed . ' ms on the server.', $problemCtx + ['duration_ms' => $elapsed]);
    }
    scan_reply($outcome['http'], $outcome['payload']);
} catch (Throwable $e) {
    $ref = '';
    try {
        require_once __DIR__ . '/lib/scan_problems.php';
        $ref = scan_problem_log($pdo instanceof PDO ? $pdo : null, 'server_error', get_class($e) . ': ' . $e->getMessage(), [
            'fixture_id' => $fixtureId,
            'user_id' => (int) ($user['account_id'] ?? 0),
            'http_status' => 500,
            'detail' => $e->getFile() . ':' . $e->getLine() . "\n" . mb_substr($e->getTraceAsString(), 0, 1500),
        ]);
    } catch (Throwable $inner) {
        error_log('scan endpoint failed: ' . $e->getMessage());
    }
    scan_reply(500, ['ok' => false, 'code' => 'server_error', 'ref' => $ref, 'message' => 'Something went wrong on the server. Try the ticket again or use Manual entry.']);
}
