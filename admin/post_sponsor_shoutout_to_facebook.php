<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/social_auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/match_sponsor_share.php';
require_once __DIR__ . '/lib/facebook_publisher.php';

header('Content-Type: application/json; charset=UTF-8');
$sponsorFacebookHistoryId = 0;

function sponsor_fb_error(string $summary, int $statusCode = 500, array $details = [], array $extra = []): void
{
    global $pdo, $sponsorFacebookHistoryId;
    if ($sponsorFacebookHistoryId > 0) {
        facebook_finish($pdo, $sponsorFacebookHistoryId, false, ['error' => implode("\n", $details !== [] ? $details : [$summary])]);
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
    sponsor_fb_error('Method not allowed. Use POST.', 405);
}

auth_require_json();
if (!auth_verify_csrf_token(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
    sponsor_fb_error('Your session expired. Reload the fixture and try again.', 419);
}
if (!social_publishing_platform_enabled('facebook')) {
    sponsor_fb_error('Facebook publishing is disabled in Settings.', 403);
}

$postType = 'sponsor_shoutout';
$typeConfig = facebook_post_type_config($postType);
if (!$typeConfig['facebook_enabled']) {
    sponsor_fb_error('Facebook publishing is disabled for sponsor shoutouts in Settings (Publishing → Automation).', 200, [], ['blocked' => true]);
}

$logFile = __DIR__ . '/logs/facebook_post.log';
$fixtureId = isset($_POST['fixture_id']) ? (int) $_POST['fixture_id'] : 0;
$captionOverride = isset($_POST['caption']) && is_string($_POST['caption']) ? trim($_POST['caption']) : '';
$confirmBurst = (string) ($_POST['confirm_burst'] ?? '') === '1';

if ($fixtureId <= 0) {
    sponsor_fb_error('Fixture id is required.', 422);
}

$fixture = getMatchFixtureById($pdo, $fixtureId);
if ($fixture === null) {
    sponsor_fb_error('Fixture not found.', 404);
}

$rows = match_sponsor_share_rows($pdo, $fixtureId);
if ($rows === []) {
    sponsor_fb_error('No confirmed Match Day or Match Ball sponsors are set for this fixture yet.', 422);
}

$env = facebook_env();
if ($env['page_id'] === '' || $env['page_access_token'] === '' || $env['app_secret'] === '') {
    sponsor_fb_error('Facebook posting is not configured.', 500, ['Missing PAGE_ID, PAGE_ACCESS_TOKEN, or APP_SECRET in .env']);
}

$burst = facebook_burst_check($pdo);
if ($burst['warn'] && !$confirmBurst) {
    sponsor_fb_error(
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

$message = $captionOverride !== '' ? $captionOverride : match_sponsor_share_build_text($fixture, $rows, 'facebook');
$hubUserId = (int) ($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null;

$reservation = facebook_reserve_or_reject($pdo, [
    'page_id' => $env['page_id'],
    'match_id' => $fixtureId,
    'post_type' => $postType,
    'event_type' => $postType,
    'caption_hash' => hash('sha256', $message),
], [
    'fixture_id' => $fixtureId,
    'post_type' => $postType,
    'event_type' => $postType,
    'platform' => 'facebook',
    'page_id' => $env['page_id'],
    'caption' => $message,
    'image_url' => '/match.php?id=' . rawurlencode((string) $fixtureId) . '&tab=sponsorships',
    'is_automatic' => false,
    'created_by' => $hubUserId,
]);

if ($reservation['duplicate']) {
    sponsor_fb_error('A sponsor shoutout for this fixture has already been published to Facebook.', 409, [], ['duplicate' => true]);
}
$sponsorFacebookHistoryId = $reservation['id'];

$imageResult = match_sponsor_share_generate_image($fixture);
if (!$imageResult['ok']) {
    $errorDetail = isset($imageResult['error']) && is_string($imageResult['error'])
        ? $imageResult['error']
        : 'Sponsor image generation failed.';
    sponsor_fb_error('Facebook post failed.', 500, [$errorDetail]);
}

$imagePath = isset($imageResult['path']) && is_string($imageResult['path']) ? $imageResult['path'] : '';
if ($imagePath === '' || !is_file($imagePath)) {
    sponsor_fb_error('Facebook post failed.', 500, ['Generated sponsor image is missing.']);
}

$publishResult = facebook_send_photo_post($pdo, $sponsorFacebookHistoryId, $imagePath, $message, $logFile);
$sponsorFacebookHistoryId = 0;

if (!$publishResult['ok']) {
    sponsor_fb_error('Facebook post failed.', 500, [(string) $publishResult['error']]);
}

echo json_encode([
    'ok' => true,
    'summary' => 'Facebook sponsor shoutout completed.',
    'details' => [
        'Sponsor graphic posted to Facebook successfully.',
    ],
    'post_id' => $publishResult['post_id'],
    'photo_id' => $publishResult['media_id'],
], JSON_UNESCAPED_SLASHES);
