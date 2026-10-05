<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/social_auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/event_share_lib.php';

header('Content-Type: application/json; charset=UTF-8');

function event_gradient_respond(bool $ok, string $summary, int $status = 200, array $data = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'summary' => $summary], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    event_gradient_respond(false, 'Method not allowed. Use POST.', 405);
}

auth_require_json();

if (!csrf_check()) {
    event_gradient_respond(false, 'Your session expired. Please refresh the page and try again.', 400);
}

$matchId = isset($_POST['match_id']) && is_string($_POST['match_id']) ? trim($_POST['match_id']) : '';
$eventId = isset($_POST['event_id']) && is_string($_POST['event_id']) ? trim($_POST['event_id']) : '';
$level = isset($_POST['gradient_level']) ? (int) $_POST['gradient_level'] : -1;
$color = isset($_POST['gradient_color']) && is_string($_POST['gradient_color']) ? strtolower(trim($_POST['gradient_color'])) : '';
$match = $matchId !== '' ? matches_find_by_id(matches_load_all(), $matchId) : null;
$event = $match !== null && $eventId !== '' ? event_share_find_event($match, $eventId) : null;

if ($match === null || $event === null) {
    event_gradient_respond(false, 'Match event not found.', 404);
}
if ($level < 0 || $level > 100 || preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
    event_gradient_respond(false, 'Choose a valid gradient level and colour.', 422);
}
if (!event_share_set_gradient_settings($match, $event, $level, $color)) {
    event_gradient_respond(false, 'The gradient settings could not be saved.', 500);
}

$imageResult = event_share_generate_image($match, $event);
if (empty($imageResult['ok'])) {
    event_gradient_respond(false, 'The gradient was saved, but the downloadable graphic could not be refreshed.', 500);
}

event_gradient_respond(true, 'Gradient saved and social graphic refreshed.', 200, [
    'download_url' => (string) ($imageResult['download_url'] ?? ''),
]);
