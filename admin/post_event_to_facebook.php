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
$eventFacebookHistoryId = 0;

function event_fb_error(string $summary, int $statusCode = 500, array $details = [], array $extra = []): void
{
    global $pdo, $eventFacebookHistoryId;
    if ($eventFacebookHistoryId > 0) {
        facebook_finish($pdo, $eventFacebookHistoryId, false, ['error' => implode("\n", $details !== [] ? $details : [$summary])]);
    }
    http_response_code($statusCode);
    echo json_encode(array_merge([
        'ok' => false,
        'summary' => $summary,
        'details' => $details,
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    event_fb_error('Method not allowed. Use POST.', 405);
}

auth_require_json();
if (!auth_verify_csrf_token(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    event_fb_error('Your session expired. Reload the match and try again.', 419);
}
if (!social_publishing_platform_enabled('facebook')) {
    event_fb_error('Facebook publishing is disabled in Settings.', 403);
}

$logFile = __DIR__ . '/logs/facebook_post.log';
$matchId = isset($_POST['match_id']) && is_string($_POST['match_id']) ? trim($_POST['match_id']) : '';
$eventId = isset($_POST['event_id']) && is_string($_POST['event_id']) ? trim($_POST['event_id']) : '';
$captionOverride = isset($_POST['caption']) && is_string($_POST['caption']) ? trim($_POST['caption']) : '';
$confirmBurst = (string) ($_POST['confirm_burst'] ?? '') === '1';
$overrideRequested = (string) ($_POST['override'] ?? '') === '1';

if ($matchId === '') {
    event_fb_error('Match id is required.', 422);
}

$match = matches_find_by_id(matches_load_all(), $matchId);
if ($match === null) {
    event_fb_error('Match not found.', 404);
}

$event = $eventId !== '' ? event_share_find_event($match, $eventId) : event_share_latest_event($match);
if ($event === null) {
    event_fb_error($eventId !== '' ? 'Event not found.' : 'No events recorded yet.', 404);
}

$postType = (string) ($event['type'] ?? 'match_update');
$typeConfig = facebook_post_type_config($postType);
$isOverride = false;

if (!$typeConfig['facebook_enabled']) {
    // Automatic/normal publishing must respect facebook_enabled=false. The
    // one deliberate exception: an authorised administrator can publish this
    // single event to Facebook anyway (historic goal, milestone, etc). That
    // still goes through the exact same dedupe reservation below — the
    // override only bypasses this eligibility check, nothing else. A type
    // that is off does NOT get a social_posts row here (no reservation is
    // made above this point) so routine skipped events don't clutter the
    // publish history/diagnostics with noise.
    if (!$overrideRequested) {
        event_fb_error(
            'Facebook publishing is disabled for this event type in Settings (Publishing → Automation). The event still appears in Hub.',
            200,
            [],
            ['blocked' => true, 'can_override' => true]
        );
    }
    if (!auth_is_admin()) {
        event_fb_error('Only an administrator can manually publish this event type to Facebook.', 403);
    }
    $isOverride = true;
    facebook_log('INFO', 'Manual Facebook override requested', [
        'post_type' => $postType,
        'match_id' => $matchId,
        'event_id' => (string) ($event['id'] ?? $eventId),
        'requested_by' => (int) ($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null,
    ], $logFile);
}

$env = facebook_env();
if ($env['page_id'] === '' || $env['page_access_token'] === '' || $env['app_secret'] === '') {
    event_fb_error('Facebook posting is not configured.', 500, ['Missing PAGE_ID, PAGE_ACCESS_TOKEN, or APP_SECRET in .env']);
}

// Posting-frequency safeguard (guards against accidental bursts from bugs or
// repeated clicks, not an attempt to model Facebook's ranking). Checked
// before any DB reservation so declining leaves nothing "stuck".
$burst = facebook_burst_check($pdo);
if ($burst['warn'] && !$confirmBurst) {
    event_fb_error(
        sprintf(
            '%d Facebook posts have already been published in the last %d minutes. Are you sure you want to publish another?',
            $burst['count'],
            $burst['window_minutes']
        ),
        409,
        [],
        ['requires_confirmation' => true, 'recent_count' => $burst['count']]
    );
}

$mentionTargets = event_share_facebook_fixture_page_targets($match);
$message = $captionOverride !== '' ? $captionOverride : event_share_build_text($match, $event, 'facebook');
$facebookMessage = $message;
foreach ($mentionTargets as $mentionTarget) {
    $sponsorName = ltrim(trim((string)($mentionTarget['name'] ?? '')), '@');
    if ($sponsorName !== '') {
        $facebookMessage = str_replace('@' . $sponsorName, $sponsorName, $facebookMessage);
    }
}

$hubUserId = (int) ($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null;
$scoreLine = event_share_score_line($match, $event);
$minute = trim((string) ($event['minute'] ?? ''));

$reservation = facebook_reserve_or_reject($pdo, [
    'platform' => 'facebook',
    'page_id' => $env['page_id'],
    'match_id' => $matchId,
    'event_id' => (string) ($event['id'] ?? $eventId),
    'post_type' => $postType,
    'event_type' => $postType,
    'score' => $scoreLine,
    'minute' => $minute,
    'caption_hash' => hash('sha256', $facebookMessage),
], [
    'fixture_id' => $matchId,
    'event_id' => (string) ($event['id'] ?? $eventId),
    'post_type' => $postType,
    'event_type' => $postType,
    'platform' => 'facebook',
    'page_id' => $env['page_id'],
    'caption' => $facebookMessage,
    'image_url' => '/match_graphics.php?fixture_id=' . rawurlencode($matchId),
    'is_automatic' => false,
    'is_override' => $isOverride,
    'created_by' => $hubUserId,
]);

if ($reservation['duplicate']) {
    event_fb_error('This event has already been published to Facebook.', 409, [], ['duplicate' => true]);
}
$eventFacebookHistoryId = $reservation['id'];

$imageResult = event_share_generate_image($match, $event);
if (!$imageResult['ok']) {
    $errorDetail = isset($imageResult['error']) && is_string($imageResult['error'])
        ? $imageResult['error']
        : 'Event image generation failed.';
    event_fb_error('Facebook post failed.', 500, [$errorDetail]);
}

$imagePath = isset($imageResult['path']) && is_string($imageResult['path']) ? $imageResult['path'] : '';
if ($imagePath === '' || !is_file($imagePath)) {
    event_fb_error('Facebook post failed.', 500, ['Generated event image is missing.']);
}

$publishResult = facebook_send_photo_post($pdo, $eventFacebookHistoryId, $imagePath, $facebookMessage, $logFile);
$eventFacebookHistoryId = 0; // facebook_send_photo_post has already finished this row either way.

if (!$publishResult['ok']) {
    event_fb_error('Facebook post failed.', 500, [(string) $publishResult['error']]);
}

echo json_encode([
    'ok' => true,
    'summary' => $isOverride ? 'Facebook post published (manual override).' : 'Facebook event post completed.',
    'details' => [
        $isOverride
            ? 'This event type does not auto-publish to Facebook; posted anyway as a manual override.'
            : 'Event graphic posted to Facebook successfully.',
    ],
    'post_id' => $publishResult['post_id'],
    'photo_id' => $publishResult['media_id'],
    'override' => $isOverride,
], JSON_UNESCAPED_SLASHES);
