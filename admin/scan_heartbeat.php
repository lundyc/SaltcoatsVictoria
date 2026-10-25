<?php
declare(strict_types=1);

/** A scanner phone says "I am here" every 20 seconds, so the door dashboard can show which gates are live or offline. */
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/lib/functions.php';
    require_once __DIR__ . '/lib/scan_processor.php';

    if (!hub_auth_is_authenticated() || !hub_auth_has_capability('tickets_scan')) {
        http_response_code(401);
        exit(json_encode(['ok' => false]));
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrf_check()) {
        http_response_code(419);
        exit(json_encode(['ok' => false]));
    }
    ensureScanGateSchema($pdo);
    $user = hub_auth_current_user();
    $device = scan_clean_label((string) ($_POST['device'] ?? ''), 40);
    if ($device === '') {
        exit(json_encode(['ok' => false]));
    }
    $kind = ($_POST['kind'] ?? '') === 'event' ? 'event' : 'match';
    $pdo->prepare('INSERT INTO scan_gate_heartbeats (device_id, gate, account_id, target_kind, target_id, queued, online, last_seen)
        VALUES (:d, :g, :a, :k, :t, :q, :o, NOW())
        ON DUPLICATE KEY UPDATE gate = VALUES(gate), account_id = VALUES(account_id), target_kind = VALUES(target_kind), target_id = VALUES(target_id), queued = VALUES(queued), online = VALUES(online), last_seen = NOW()')
        ->execute([':d' => $device, ':g' => scan_clean_label((string) ($_POST['gate'] ?? ''), 40), ':a' => !empty($user['account_id']) ? (int) $user['account_id'] : null,
            ':k' => $kind, ':t' => (int) ($_POST['target'] ?? 0), ':q' => max(0, min(100000, (int) ($_POST['queued'] ?? 0))), ':o' => !empty($_POST['online']) ? 1 : 0]);
    echo json_encode(['ok' => true, 'time' => time()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}
