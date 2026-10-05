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
require_once __DIR__ . '/lib/facebook_publisher.php';

header('Content-Type: application/json; charset=UTF-8');

// This is a pure, side-effect-free content/image generator: it does not
// reserve a fingerprint or write to social_posts. It's called both by
// match_events.php's one-shot "Share to X" button AND by match_graphics.php's
// preview modal, which re-calls it every time an operator opens or refreshes
// a preview — it must stay safely repeatable. The actual X-share dedupe/
// audit record is written by record_manual_share.php once a share is
// genuinely dispatched (window.open to x.com), matching the pattern already
// used by match_graphics.php, match_next_match.php, match_starting_11_graphic.php,
// and match.php.

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed. Use POST.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

auth_require_json();

$matchId = isset($_POST['match_id']) && is_string($_POST['match_id']) ? trim($_POST['match_id']) : '';
$eventId = isset($_POST['event_id']) && is_string($_POST['event_id']) ? trim($_POST['event_id']) : '';

if ($matchId === '') {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => 'Match id is required.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$match = matches_find_by_id(matches_load_all(), $matchId);
if ($match === null) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => 'Match not found.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$event = $eventId !== '' ? event_share_find_event($match, $eventId) : event_share_latest_event($match);
if ($event === null) {
    http_response_code(404);
    echo json_encode([
        'ok' => false,
        'error' => $eventId !== '' ? 'Event not found.' : 'No events recorded yet.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$captions = [
    'facebook' => event_share_build_text($match, $event, 'facebook'),
    'instagram' => event_share_build_text($match, $event, 'instagram'),
    'x' => event_share_build_text($match, $event, 'x'),
];
$text = $captions['x'];
$gradientSettings = event_share_gradient_settings($match, $event);
$imageResult = event_share_generate_image($match, $event);
if (!$imageResult['ok']) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => isset($imageResult['error']) && is_string($imageResult['error'])
            ? $imageResult['error']
            : 'Event image generation failed.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$composeUrl = 'https://x.com/intent/tweet?text=' . rawurlencode($text);
$typeConfig = facebook_post_type_config((string) ($event['type'] ?? 'match_update'));

echo json_encode([
    'ok' => true,
    'summary' => 'Social post prepared.',
    'details' => [
        'Event text and graphic are ready to review.',
    ],
    'compose_url' => $composeUrl,
    'download_url' => (string) ($imageResult['download_url'] ?? ''),
    'text' => $text,
    'captions' => $captions,
    'event_type' => (string) ($event['type'] ?? ''),
    'use_white_badges' => event_share_uses_white_badges($match, $event),
    'gradient_level' => $gradientSettings['level'],
    'gradient_color' => $gradientSettings['color'],
    'facebook_enabled' => $typeConfig['facebook_enabled'],
    'x_enabled' => $typeConfig['x_enabled'],
], JSON_UNESCAPED_SLASHES);
