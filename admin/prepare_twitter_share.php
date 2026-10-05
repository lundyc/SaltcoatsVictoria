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
require_once __DIR__ . '/render_lib.php';
require_once __DIR__ . '/social_post_settings.php';
require_once __DIR__ . '/lib/facebook_publisher.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/render_access_token.php';

header('Content-Type: application/json; charset=UTF-8');
$twitterShareHistoryId = 0;

function writeLog(string $path, string $message): void
{
    @file_put_contents($path, '[' . date('Y-m-d H:i:s') . "] $message\n", FILE_APPEND);
}

function twitter_share_error(string $error, int $statusCode = 500, array $extra = []): void
{
    global $pdo, $twitterShareHistoryId;
    if ($twitterShareHistoryId > 0) {
        facebook_set_status($pdo, $twitterShareHistoryId, 'failed', ['error' => $error]);
    }
    http_response_code($statusCode);
    echo json_encode(array_merge(['ok' => false, 'error' => $error], $extra));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed. Use POST.',
    ]);
    exit;
}

auth_require_json();

if (!social_publishing_platform_enabled('x')) {
    twitter_share_error('X publishing is disabled in Settings.', 403);
}

$socialBaseUrl = APP_ORIGIN . '/admin';
$exportPath = __DIR__ . '/export/latest_wosfl.png';
$renderUrl = $socialBaseUrl . '/league_table_graphic.php?render=1&_token=' . rawurlencode(RENDER_ACCESS_TOKEN);
$downloadUrl = $socialBaseUrl . '/download_latest_wosfl.php?v=' . rawurlencode((string) time());
$logFile = __DIR__ . '/logs/twitter_share.log';
$graphicType = isset($_POST['graphic']) && is_string($_POST['graphic']) && trim($_POST['graphic']) !== ''
    ? strtolower(trim($_POST['graphic']))
    : 'league_table';
$matchId = isset($_POST['match_id']) && is_string($_POST['match_id']) ? trim($_POST['match_id']) : '';
$captionOverride = isset($_POST['caption']) && is_string($_POST['caption']) ? trim($_POST['caption']) : '';
$tableStyle = isset($_POST['table_style']) && is_string($_POST['table_style'])
    ? strtolower(trim($_POST['table_style']))
    : 'standard';
if (!in_array($tableStyle, ['standard', 'compact', 'expanded'], true)) {
    $tableStyle = 'standard';
}
$renderUrl .= '&table_style=' . rawurlencode($tableStyle);
$caption = social_post_resolve_caption_with_override('x', $graphicType, null, $captionOverride);

if ($graphicType === 'match') {
    $match = $matchId !== '' ? matches_find_by_id(matches_load_all(), $matchId) : null;
    if ($match === null) {
        http_response_code(404);
        echo json_encode([
            'ok' => false,
            'error' => 'Match not found.',
        ]);
        exit;
    }

    if (!is_dir(MATCHES_EXPORT_DIR) && !@mkdir(MATCHES_EXPORT_DIR, 0775, true) && !is_dir(MATCHES_EXPORT_DIR)) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Export directory could not be created.',
        ]);
        exit;
    }

    $baseName = matches_slugify(matches_fixture_label($match)) . '-' . ((string) ($match['match_date'] ?? date('Y-m-d')));
    $exportPath = MATCHES_EXPORT_DIR . '/' . $baseName . '.png';
    $renderUrl = $socialBaseUrl . '/match_graphic.php?id=' . rawurlencode((string) $match['id'])
        . '&render=1&_token=' . rawurlencode(RENDER_ACCESS_TOKEN);
    $downloadUrl = $socialBaseUrl . '/export/matches/' . rawurlencode($baseName) . '.png?v=' . rawurlencode((string) time());
    $caption = social_post_resolve_caption_with_override('x', 'match', $match, $captionOverride);
}

$configType = $graphicType === 'match' ? 'starting_xi' : $graphicType;
if (!facebook_post_type_config($configType)['x_enabled']) {
    twitter_share_error('X publishing is disabled for this post type in Settings (Publishing → Automation).', 200, ['blocked' => true]);
}

// X dedupe: same atomic fingerprint reservation as Facebook, scoped to
// platform='x' so the two never collide.
$hubUserId = (int) ($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null;
$reservation = facebook_reserve_or_reject($pdo, [
    'platform' => 'x',
    'match_id' => $matchId,
    'post_type' => $configType,
    'event_type' => $configType,
    'caption_hash' => hash('sha256', $caption),
], [
    'fixture_id' => ctype_digit($matchId) ? (int) $matchId : 0,
    'post_type' => $configType,
    'event_type' => $configType,
    'platform' => 'x',
    'caption' => $caption,
    'image_url' => '',
    'is_automatic' => false,
    'created_by' => $hubUserId,
]);
if ($reservation['duplicate']) {
    twitter_share_error('This exact content has already been prepared for X.', 409, ['duplicate' => true]);
}
$twitterShareHistoryId = $reservation['id'];

$result = $graphicType === 'match'
    ? render_capture_image($renderUrl, $exportPath, '.match-preview-wrap', 1080, 1080, '.match-preview-wrap')
    : render_capture_image(
        $renderUrl,
        $exportPath,
        '.table-card--render',
        1080,
        1350,
        '.table-card--render'
    );

if (!$result['ok']) {
    writeLog($logFile, 'Image generation failed');
    twitter_share_error($result['error']);
}

$composeUrl = 'https://x.com/intent/tweet?text=' . rawurlencode($caption);
facebook_set_status($pdo, $twitterShareHistoryId, 'prepared', ['api_response' => ['compose_url' => $composeUrl]]);

writeLog($logFile, 'Twitter manual share assets prepared');

echo json_encode([
    'ok' => true,
    'summary' => 'Twitter share prepared.',
    'message' => "Twitter share prepared.\n\n- Twitter composer opened in a new tab.\n- Fresh image downloaded to your device.\n- Caption prepared automatically.",
    'details' => [
        'Twitter composer opened in a new tab.',
        'Fresh image downloaded to your device.',
        'Caption prepared automatically.',
    ],
    'compose_url' => $composeUrl,
    'download_url' => $downloadUrl,
    'caption' => $caption,
]);
