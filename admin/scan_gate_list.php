<?php
declare(strict_types=1);

/**
 * Offline list for the Ticket Scanner: what a phone keeps so it can still check tickets with no signal.
 * GET ?kind=match|event&id=N. Same login, permission and same-day rule as scanning itself.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

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
    $kind = ($_GET['kind'] ?? '') === 'event' ? 'event' : 'match';
    $id = (int) ($_GET['id'] ?? 0);
    if ($kind === 'event') {
        $st = $pdo->prepare('SELECT starts_at FROM events WHERE id = :id');
        $st->execute([':id' => $id]);
        $when = substr((string) $st->fetchColumn(), 0, 10);
    } else {
        $st = $pdo->prepare('SELECT match_date FROM match_fixtures WHERE id = :id');
        $st->execute([':id' => $id]);
        $when = (string) $st->fetchColumn();
    }
    if ($when === '' || $when !== scan_business_date()) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'code' => 'wrong_day', 'message' => 'That is not scheduled for today.']));
    }
    echo json_encode(['ok' => true] + scan_gate_list($pdo, $kind, $id));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'code' => 'server_error']);
}
