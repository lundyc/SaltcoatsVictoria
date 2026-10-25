<?php
declare(strict_types=1);

/** JSON for the door dashboard (refreshed every few seconds). GET ?kind=match|event&id=N */
header('Content-Type: application/json');
header('Cache-Control: no-store');

try {
    require_once __DIR__ . '/db.php';
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/lib/functions.php';
    require_once __DIR__ . '/lib/door_dashboard.php';

    if (!hub_auth_is_authenticated()) {
        http_response_code(401);
        exit(json_encode(['ok' => false, 'code' => 'not_logged_in']));
    }
    if (!hub_auth_has_any_capability(['tickets_ops', 'events_view'])) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'code' => 'no_permission']));
    }
    echo json_encode(door_dashboard_data($pdo, (string) ($_GET['kind'] ?? 'match'), (int) ($_GET['id'] ?? 0)));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'code' => 'server_error', 'message' => $e->getMessage()]);
}
