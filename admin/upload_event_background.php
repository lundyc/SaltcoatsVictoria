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

function event_background_respond(bool $ok, string $summary, array $details = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode([
        'ok' => $ok,
        'summary' => $summary,
        'details' => array_values($details),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    event_background_respond(false, 'Method not allowed. Use POST.', [], 405);
}

auth_require_json();
if (!auth_verify_csrf_token(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    event_background_respond(false, 'Your session expired. Please reload the page and try again.', [], 419);
}

$matchId = isset($_POST['match_id']) && is_string($_POST['match_id']) ? trim($_POST['match_id']) : '';
$eventId = isset($_POST['event_id']) && is_string($_POST['event_id']) ? trim($_POST['event_id']) : '';
$match = $matchId !== '' ? matches_find_by_id(matches_load_all(), $matchId) : null;
$event = $match !== null && $eventId !== '' ? event_share_find_event($match, $eventId) : null;

if ($match === null || $event === null) {
    event_background_respond(false, 'Match event not found.', [], 404);
}

$upload = $_FILES['background_image'] ?? null;
if (!is_array($upload) || (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    event_background_respond(false, 'Choose an image to upload.', [], 422);
}

$size = (int) ($upload['size'] ?? 0);
$temporaryPath = isset($upload['tmp_name']) && is_string($upload['tmp_name']) ? $upload['tmp_name'] : '';
if ($size <= 0 || $size > 10 * 1024 * 1024 || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
    event_background_respond(false, 'The image must be no larger than 10 MB.', [], 422);
}

$imageInfo = @getimagesize($temporaryPath);
$mimeType = is_array($imageInfo) ? (string) ($imageInfo['mime'] ?? '') : '';
$extensions = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
if (!isset($extensions[$mimeType])) {
    event_background_respond(false, 'Upload a JPG, PNG or WebP image.', [], 422);
}

$directory = event_share_background_dir();
if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
    event_background_respond(false, 'The background upload directory could not be created.', [], 500);
}

$prefix = event_share_background_prefix($match, $event);
$destination = $directory . '/' . $prefix . '.' . $extensions[$mimeType];
if (!move_uploaded_file($temporaryPath, $destination)) {
    event_background_respond(false, 'The background image could not be saved.', [], 500);
}
@chmod($destination, 0664);

foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
    $oldPath = $directory . '/' . $prefix . '.' . $extension;
    if ($oldPath !== $destination && is_file($oldPath)) {
        @unlink($oldPath);
    }
}

event_background_respond(true, 'Background image replaced.', ['The social graphic is ready to regenerate.']);
