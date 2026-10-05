<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/analytics.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

$user = hub_auth_current_user();
if ($user === null) {
    http_response_code(204);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $events = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : [$payload];
    $events = array_slice($events, 0, 20);
    foreach ($events as $event) {
        if (is_array($event)) {
            hub_analytics_record_event($pdo, $event, $user);
        }
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('Analytics tracking failed: ' . $e->getMessage());
    http_response_code(204);
}
