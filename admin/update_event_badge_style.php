<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/social_auth.php';
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/event_share_lib.php';

header('Content-Type: application/json; charset=UTF-8');

function event_badge_style_respond(bool $ok, string $summary, int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'ok' => $ok,
        'summary' => $summary,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    event_badge_style_respond(false, 'Method not allowed. Use POST.', 405);
}

auth_require_json();
if (!auth_verify_csrf_token(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    event_badge_style_respond(false, 'Your session expired. Please reload the page and try again.', 419);
}

$matchId = isset($_POST['match_id']) && is_string($_POST['match_id']) ? trim($_POST['match_id']) : '';
$eventId = isset($_POST['event_id']) && is_string($_POST['event_id']) ? trim($_POST['event_id']) : '';
$useWhiteBadges = isset($_POST['use_white_badges']) && (string) $_POST['use_white_badges'] === '1';
$match = $matchId !== '' ? matches_find_by_id(matches_load_all(), $matchId) : null;
$event = $match !== null && $eventId !== '' ? event_share_find_event($match, $eventId) : null;

if ($match === null || $event === null) {
    event_badge_style_respond(false, 'Match event not found.', 404);
}

if (!event_share_set_white_badges($match, $event, $useWhiteBadges)) {
    event_badge_style_respond(false, 'The badge style could not be saved.', 500);
}

event_badge_style_respond(true, $useWhiteBadges ? 'White badges selected.' : 'Coloured badges selected.');
