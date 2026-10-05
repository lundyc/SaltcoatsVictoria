<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/facebook_publisher.php';

// Records that Hub prepared/opened an X compose-intent for a manually-shared
// graphic (Next Match, Starting XI, match graphics, sponsor shoutout — see
// callers in match_next_match.php, match_starting_11_graphic.php,
// match_graphics.php, match.php). This is a fire-and-forget beacon: callers
// don't wait on or inspect the response. It shares the exact same atomic
// fingerprint dedupe as every other platform (lib/facebook_publisher.php)
// rather than a separate check — X and Facebook fingerprints are kept apart
// by including 'platform' in the hashed fields.
header('Content-Type: application/json; charset=UTF-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
if (empty($_SESSION['user_id']) && empty($_SESSION['hub_user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
if (!csrf_check()) {
    http_response_code(419);
    echo json_encode(['ok' => false]);
    exit;
}

$fixtureId = (int) ($_POST['fixture_id'] ?? 0);
$eventId = trim((string) ($_POST['event_id'] ?? ''));
$postType = trim((string) ($_POST['post_type'] ?? 'match_update'));
$caption = trim((string) ($_POST['caption'] ?? ''));
$imageUrl = trim((string) ($_POST['image_url'] ?? ''));

if (!facebook_post_type_config($postType)['x_enabled']) {
    echo json_encode(['ok' => true, 'blocked' => true]);
    exit;
}

$hubUserId = (int) ($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null;
$reservation = facebook_reserve_or_reject($pdo, [
    'platform' => 'x',
    'match_id' => $fixtureId,
    'event_id' => $eventId,
    'post_type' => $postType,
    'event_type' => $postType,
    'caption_hash' => hash('sha256', $caption),
], [
    'fixture_id' => $fixtureId,
    'event_id' => $eventId,
    'post_type' => $postType,
    'event_type' => $postType,
    'platform' => 'x',
    'caption' => $caption,
    'image_url' => $imageUrl,
    'is_automatic' => false,
    'created_by' => $hubUserId,
]);

if (!$reservation['duplicate']) {
    facebook_set_status($pdo, $reservation['id'], 'prepared');
}

echo json_encode(['ok' => true, 'duplicate' => $reservation['duplicate']]);
