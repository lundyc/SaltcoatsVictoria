<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/social_post_settings.php';
require_once __DIR__ . '/lib/facebook_publisher.php';

$baseDir = __DIR__;
$imagePath = $baseDir . '/export/latest_wosfl.png';
$logFile = $baseDir . '/logs/facebook_post.log';
$graphicType = 'league_table';
$matchId = '';
$captionOverride = '';
$hubUserId = null;
$confirmBurst = false;

global $argv;
if (isset($argv[1]) && is_string($argv[1]) && trim($argv[1]) !== '') {
    $imagePath = trim($argv[1]);
}
if (isset($argv[2]) && is_string($argv[2]) && trim($argv[2]) !== '') {
    $graphicType = strtolower(trim($argv[2]));
}
if (isset($argv[3]) && is_string($argv[3])) {
    $matchId = trim($argv[3]);
}
if (isset($argv[4]) && is_string($argv[4])) {
    $captionOverride = trim($argv[4]);
}
if (isset($argv[5]) && is_string($argv[5]) && ctype_digit(trim($argv[5]))) {
    $hubUserId = (int) trim($argv[5]);
}
if (isset($argv[6]) && is_string($argv[6]) && trim($argv[6]) === '1') {
    $confirmBurst = true;
}
$allowRepost = isset($argv[7]) && is_string($argv[7]) && trim($argv[7]) === '1';

/**
 * Emit a machine-readable result as the last line of stdout, so callers that
 * shell out to this script (generate_and_post.php, post_next_match.php,
 * post_starting_11.php, post_monthly_fixtures.php) can parse a structured
 * outcome instead of regexing free-text output.
 */
function cli_result(bool $ok, array $extra = []): void
{
    echo 'RESULT_JSON:' . json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function cli_fail(string $message, array $context = [], array $resultExtra = []): void
{
    facebook_log('ERROR', $message, $context);
    $output = $message;
    if (isset($context['error_message']) && is_string($context['error_message']) && trim($context['error_message']) !== '') {
        $output .= ': ' . trim($context['error_message']);
    }
    echo 'ERROR: ' . $output . PHP_EOL;
    cli_result(false, array_merge(['error' => $output], $resultExtra));
    exit(1);
}

if (!is_file($imagePath) || !is_readable($imagePath)) {
    cli_fail('Image missing or unreadable', ['path' => $imagePath]);
}

$env = facebook_env();
if ($env['page_id'] === '' || $env['page_access_token'] === '' || $env['app_secret'] === '') {
    cli_fail('Missing required .env keys', ['required' => ['PAGE_ID', 'PAGE_ACCESS_TOKEN', 'APP_SECRET']]);
}

$typeConfig = facebook_post_type_config($graphicType);
if (!$typeConfig['facebook_enabled']) {
    cli_fail('Facebook publishing is disabled for this post type in Settings (Publishing → Automation).', ['graphic_type' => $graphicType], ['blocked' => true]);
}

$burst = facebook_burst_check($pdo);
if ($burst['warn'] && !$confirmBurst) {
    $message = sprintf(
        '%d Facebook posts have already been published in the last %d minutes. Are you sure you want to publish another?',
        $burst['count'],
        $burst['window_minutes']
    );
    facebook_log('WARN', $message, ['graphic_type' => $graphicType]);
    echo 'ERROR: ' . $message . PHP_EOL;
    cli_result(false, ['error' => $message, 'requires_confirmation' => true, 'recent_count' => $burst['count']]);
    exit(1);
}

$match = null;
if ($graphicType === 'match' && $matchId !== '') {
    $match = matches_find_by_id(matches_load_all(), $matchId);
}

$caption = social_post_resolve_caption_with_override('facebook', $graphicType, $match, $captionOverride);
$imageHash = hash_file('sha256', $imagePath) ?: '';

$fingerprintFields = [
    'page_id' => $env['page_id'],
    'match_id' => $matchId,
    'post_type' => $graphicType,
    'event_type' => $graphicType,
    'caption_hash' => hash('sha256', $caption),
    'image_hash' => $imageHash,
];
if ($allowRepost) {
    // Explicit operator confirmation that the original was deleted on
    // Facebook — deliberately bypass the fingerprint match, mirroring the
    // previous allow_repost escape hatch.
    $fingerprintFields['repost_nonce'] = bin2hex(random_bytes(8));
}

$reservation = facebook_reserve_or_reject($pdo, $fingerprintFields, [
    'fixture_id' => ctype_digit($matchId) ? (int) $matchId : 0,
    'post_type' => $graphicType,
    'event_type' => $graphicType,
    'platform' => 'facebook',
    'page_id' => $env['page_id'],
    'caption' => $caption,
    'image_url' => '',
    'image_hash' => $imageHash,
    'is_automatic' => false,
    'created_by' => $hubUserId,
]);

if ($reservation['duplicate']) {
    $message = 'This exact content has already been published to Facebook.';
    echo 'ERROR: ' . $message . PHP_EOL;
    cli_result(false, ['error' => $message, 'duplicate' => true]);
    exit(1);
}

$publishResult = facebook_send_photo_post($pdo, $reservation['id'], $imagePath, $caption, $logFile);

if (!$publishResult['ok']) {
    echo 'ERROR: ' . $publishResult['error'] . PHP_EOL;
    cli_result(false, ['error' => $publishResult['error']]);
    exit(1);
}

echo "SUCCESS post_id={$publishResult['post_id']}\n";
cli_result(true, ['post_id' => $publishResult['post_id'], 'media_id' => $publishResult['media_id']]);
exit(0);
