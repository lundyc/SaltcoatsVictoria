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
require_once __DIR__ . '/lib/publishing_history.php';

header('Content-Type: application/json; charset=UTF-8');
$eventInstagramHistoryId = 0;

function event_ig_respond(bool $ok, string $summary, array $details = [], int $status = 200): void
{
    global $pdo, $eventInstagramHistoryId;
    if (!$ok && $eventInstagramHistoryId > 0) {
        hub_publishing_history_finish($pdo, $eventInstagramHistoryId, false, '', implode("\n", $details !== [] ? $details : [$summary]));
        $eventInstagramHistoryId = 0;
    }
    http_response_code($status);
    echo json_encode([
        'ok' => $ok,
        'summary' => $summary,
        'details' => array_values($details),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    event_ig_respond(false, 'Method not allowed. Use POST.', [], 405);
}

auth_require_json();
if (!auth_verify_csrf_token(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    event_ig_respond(false, 'Your session expired. Reload the match and try again.', [], 419);
}
if (!social_publishing_platform_enabled('instagram')) {
    event_ig_respond(false, 'Instagram publishing is disabled in Settings.', [], 403);
}

$matchId = isset($_POST['match_id']) && is_string($_POST['match_id']) ? trim($_POST['match_id']) : '';
$eventId = isset($_POST['event_id']) && is_string($_POST['event_id']) ? trim($_POST['event_id']) : '';
$captionOverride = isset($_POST['caption']) && is_string($_POST['caption']) ? trim($_POST['caption']) : '';

if ($matchId === '' || $eventId === '') {
    event_ig_respond(false, 'Match id and event id are required.', [], 422);
}

$match = matches_find_by_id(matches_load_all(), $matchId);
$event = $match !== null ? event_share_find_event($match, $eventId) : null;
if ($match === null || $event === null) {
    event_ig_respond(false, 'Match event not found.', [], 404);
}

$imageResult = event_share_generate_image($match, $event);
if (empty($imageResult['ok'])) {
    event_ig_respond(false, 'Instagram post failed.', [(string) ($imageResult['error'] ?? 'Event image generation failed.')], 500);
}

$imagePath = (string) ($imageResult['path'] ?? '');
$publicImageUrl = (string) ($imageResult['download_url'] ?? '');
if ($imagePath === '' || $publicImageUrl === '' || !is_file($imagePath)) {
    event_ig_respond(false, 'Instagram post failed.', ['Generated event image is missing.'], 500);
}

$caption = $captionOverride !== '' ? $captionOverride : event_share_build_text($match, $event, 'instagram');
$history = hub_publishing_history_start($pdo, [
    'fixture_id' => (int)$matchId,
    'event_id' => (string)($event['id'] ?? ''),
    'post_type' => (string)($event['type'] ?? 'match_update'),
    'platform' => 'instagram',
    'caption' => $caption,
    'image_url' => '/match_graphics.php?fixture_id=' . rawurlencode($matchId),
]);
if ($history['duplicate']) {
    event_ig_respond(false, 'This event has already been published to Instagram.', [], 409);
}
$eventInstagramHistoryId = $history['id'];
$phpCandidates = [PHP_BINDIR . DIRECTORY_SEPARATOR . 'php', '/usr/bin/php', '/usr/local/bin/php'];
$phpBinary = '';
foreach ($phpCandidates as $candidate) {
    if (is_file($candidate) && is_executable($candidate) && stripos(basename($candidate), 'php-fpm') === false) {
        $phpBinary = $candidate;
        break;
    }
}
if ($phpBinary === '') {
    event_ig_respond(false, 'Instagram post failed.', ['PHP CLI could not be found.'], 500);
}

$command = escapeshellarg($phpBinary)
    . ' ' . escapeshellarg(__DIR__ . '/post_to_instagram.php')
    . ' ' . escapeshellarg($imagePath)
    . ' ' . escapeshellarg($publicImageUrl)
    . ' ' . escapeshellarg('event')
    . ' ' . escapeshellarg($matchId)
    . ' ' . escapeshellarg($caption)
    . ' 2>&1';
$output = [];
$exitCode = 0;
exec($command, $output, $exitCode);

if ($exitCode !== 0) {
    $details = array_values(array_filter(array_map('trim', $output)));
    event_ig_respond(false, 'Instagram post failed.', $details !== [] ? $details : ['Instagram did not return an error message.'], 500);
}

hub_publishing_history_finish($pdo, $eventInstagramHistoryId, true, implode(' ', $output));
$eventInstagramHistoryId = 0;
event_ig_respond(true, 'Instagram event post completed.', ['Event graphic posted to Instagram successfully.']);
