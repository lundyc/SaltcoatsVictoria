<?php
declare(strict_types=1);

/**
 * Offline sync for the Ticket Scanner. A phone that had no signal posts the scans it queued (oldest first).
 * Each one goes through exactly the same rules as a live scan, keeping the time it really happened. A scan is
 * only ever processed once: the phone's own id for it is remembered, so a retry is harmless.
 *
 * POST: csrf_token, items = JSON list of {id, kind, target, value, ts (ms), gate, device}
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

$pdo ??= null;
try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/lib/functions.php';
    require_once __DIR__ . '/lib/scan_processor.php';

    if (!hub_auth_is_authenticated()) {
        http_response_code(401);
        exit(json_encode(['ok' => false, 'code' => 'not_logged_in']));
    }
    if (!hub_auth_has_capability('tickets_scan')) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'code' => 'no_permission']));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_check()) {
        http_response_code(419);
        exit(json_encode(['ok' => false, 'code' => 'session_expired', 'message' => 'Reload the scanner page while online, then sync again. Your queued scans are kept.']));
    }
    $user = hub_auth_current_user();
    $userId = !empty($user['account_id']) ? (int) $user['account_id'] : null;
    ensureScanGateSchema($pdo);

    $items = json_decode((string) ($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) {
        $items = [];
    }
    usort($items, static fn ($a, $b) => ((int) ($a['ts'] ?? 0)) <=> ((int) ($b['ts'] ?? 0)));
    $items = array_slice($items, 0, 100);

    $seen = $pdo->prepare('SELECT status, accepted, message FROM scan_offline_syncs WHERE client_id = :id');
    $mark = $pdo->prepare('INSERT IGNORE INTO scan_offline_syncs (client_id, target_kind, target_id, device_id, status, accepted, message) VALUES (:id, :k, :t, :d, :s, :a, :m)');
    $results = [];
    foreach ($items as $item) {
        $clientId = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($item['id'] ?? '')) ?? '';
        $clientId = substr($clientId, 0, 48);
        if ($clientId === '') {
            continue;
        }
        $seen->execute([':id' => $clientId]);
        $prior = $seen->fetch(PDO::FETCH_ASSOC);
        if ($prior) {
            $results[] = ['id' => $clientId, 'status' => (string) $prior['status'], 'accepted' => (int) $prior['accepted'] === 1, 'message' => (string) $prior['message'], 'repeat' => true];
            continue;
        }
        $kind = ($item['kind'] ?? '') === 'event' ? 'event' : 'match';
        $target = (int) ($item['target'] ?? 0);
        try {
            $out = scan_process_ticket($pdo, [
                'kind' => $kind, 'id' => $target, 'value' => (string) ($item['value'] ?? ''), 'offline' => true,
                'scanned_at' => (int) floor(((int) ($item['ts'] ?? 0)) / 1000), 'gate' => (string) ($item['gate'] ?? ''), 'device' => (string) ($item['device'] ?? ''),
            ], $userId);
            $payload = $out['payload'];
            $status = (string) ($payload['status'] ?? ($payload['code'] ?? 'unknown'));
            $accepted = !empty($payload['ok']);
            $message = mb_substr((string) ($payload['message'] ?? ''), 0, 255);
        } catch (Throwable $e) {
            // Not recorded as done, so the phone keeps it and tries again next time.
            $results[] = ['id' => $clientId, 'status' => 'error', 'accepted' => false, 'message' => 'The server could not process this scan yet.', 'retry' => true];
            continue;
        }
        $mark->execute([':id' => $clientId, ':k' => $kind, ':t' => $target, ':d' => substr((string) ($item['device'] ?? ''), 0, 40), ':s' => substr($status, 0, 30), ':a' => $accepted ? 1 : 0, ':m' => $message]);
        $results[] = ['id' => $clientId, 'status' => $status, 'accepted' => $accepted, 'message' => $message, 'holder' => (string) ($payload['holder']['name'] ?? '')];
    }
    echo json_encode(['ok' => true, 'results' => $results]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'code' => 'server_error']);
}
