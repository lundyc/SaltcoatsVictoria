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
require_once __DIR__ . '/lib/facebook_publisher.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/render_access_token.php';

$socialBaseUrl = APP_ORIGIN . '/admin';
$exportPath = __DIR__ . '/export/latest_wosfl.png';
$url = $socialBaseUrl . '/league_table_graphic.php?render=1&_token=' . rawurlencode(RENDER_ACCESS_TOKEN);
$publicImageUrl = $socialBaseUrl . '/export/latest_wosfl.png';
$isCli = PHP_SAPI === 'cli';
$defaultTarget = 'facebook';
$requestedTarget = $defaultTarget;
$graphicType = 'league_table';
$matchId = '';
$captionOverride = '';
$tableStyle = 'standard';

if ($isCli) {
    global $argv;
    $requestedTarget = isset($argv[1]) && is_string($argv[1]) && trim($argv[1]) !== ''
        ? strtolower(trim($argv[1]))
        : $defaultTarget;
    $graphicType = isset($argv[2]) && is_string($argv[2]) && trim($argv[2]) !== ''
        ? strtolower(trim($argv[2]))
        : 'league_table';
    $matchId = isset($argv[3]) && is_string($argv[3]) ? trim($argv[3]) : '';
    $captionOverride = isset($argv[4]) && is_string($argv[4]) ? trim($argv[4]) : '';
    $tableStyle = isset($argv[5]) && is_string($argv[5]) ? strtolower(trim($argv[5])) : 'standard';
} else {
    $requestedTarget = isset($_POST['target']) && is_string($_POST['target']) && trim($_POST['target']) !== ''
        ? strtolower(trim($_POST['target']))
        : $defaultTarget;
    $graphicType = isset($_POST['graphic']) && is_string($_POST['graphic']) && trim($_POST['graphic']) !== ''
        ? strtolower(trim($_POST['graphic']))
        : 'league_table';
    $matchId = isset($_POST['match_id']) && is_string($_POST['match_id']) ? trim($_POST['match_id']) : '';
    $captionOverride = isset($_POST['caption']) && is_string($_POST['caption']) ? trim($_POST['caption']) : '';
    $tableStyle = isset($_POST['table_style']) && is_string($_POST['table_style'])
        ? strtolower(trim($_POST['table_style']))
        : 'standard';
}

if (!in_array($tableStyle, ['standard', 'compact', 'expanded'], true)) {
    $tableStyle = 'standard';
}

$targets = [
    'facebook' => [
        'label' => 'Facebook',
        'log_file' => __DIR__ . '/logs/facebook_post.log',
        'post_script' => __DIR__ . '/post_to_facebook.php',
        'success_summary' => 'Facebook post completed.',
        'success_details' => [
            'Fresh export generated successfully.',
            'Upload to Facebook completed.',
        ],
    ],
    'instagram' => [
        'label' => 'Instagram',
        'log_file' => __DIR__ . '/logs/instagram_post.log',
        'post_script' => __DIR__ . '/post_to_instagram.php',
        'success_summary' => 'Instagram post completed.',
        'success_details' => [
            'Fresh export generated successfully.',
            'Upload to Instagram completed.',
        ],
    ],
];

if (!isset($targets[$requestedTarget])) {
    respond($isCli, false, 'Unsupported publishing target.', ['Allowed targets: facebook, instagram.'], 400);
}

$target = $targets[$requestedTarget];
$logFile = $target['log_file'];
$postScript = $target['post_script'];
$targetLabel = $target['label'];

/**
 * Emit either CLI text or JSON, depending on execution context.
 *
 * @param bool $isCli
 * @param bool $ok
 * @param string $summary
 * @param array<int, string> $details
 * @param int $statusCode
 * @return never
 */
function respond(bool $isCli, bool $ok, string $summary, array $details = [], int $statusCode = 200, array $extra = []): void
{
    if ($isCli) {
        echo $summary . PHP_EOL;
        foreach ($details as $detail) {
            if ($detail !== '') {
                echo '- ' . $detail . PHP_EOL;
            }
        }
        exit($ok ? 0 : 1);
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array_merge([
        'ok' => $ok,
        'summary' => $summary,
        'details' => array_values(array_filter($details, static function (string $detail): bool {
            return $detail !== '';
        })),
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @param array<int, string> $lines
 * @return array<int, string>
 */
function normalizeLines(array $lines): array
{
    $normalized = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed !== '') {
            $normalized[] = $trimmed;
        }
    }

    return $normalized;
}

/**
 * @param array<int, string> $details
 * @return array<int, string>
 */
function clarifyPostFailureDetails(array $details, string $target): array
{
    $clarified = [];
    $hasExpiryMessage = false;

    foreach ($details as $detail) {
        $normalized = strtolower($detail);
        if (
            strpos($normalized, 'session has expired') !== false
            || strpos($normalized, 'error validating access token') !== false
        ) {
            $hasExpiryMessage = true;
            continue;
        }

        $clarified[] = $detail;
    }

    if ($hasExpiryMessage && $target === 'facebook') {
        array_unshift($clarified, 'Facebook access token expired. Update PAGE_ACCESS_TOKEN in .env with a fresh Page token and try again.');
    }

    return $clarified === [] ? $details : $clarified;
}

function resolvePhpCliBinary(): ?string
{
    $candidates = [];

    if (PHP_SAPI === 'cli' && defined('PHP_BINARY') && PHP_BINARY !== '') {
        $candidates[] = PHP_BINARY;
    }

    if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
        $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
        $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe';
    }

    $whichPhp = trim((string) shell_exec('command -v php 2>/dev/null'));
    if ($whichPhp !== '') {
        $candidates[] = $whichPhp;
    }

    $seen = [];
    foreach ($candidates as $candidate) {
        if ($candidate === '' || isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;

        $base = strtolower((string) basename($candidate));
        if (strpos($base, 'php-fpm') !== false) {
            continue;
        }

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        return 'php';
    }

    return null;
}

if (!$isCli && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(false, false, 'Method not allowed.', ['Use POST for the publishing action.'], 405);
}

if (!$isCli) {
    auth_require_json();
    if (!auth_verify_csrf_token(isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null)) {
        respond(false, false, 'Your session expired. Please reload the page and try again.', [], 419);
    }
}

if ($graphicType === 'match') {
    $match = $matchId !== '' ? matches_find_by_id(matches_load_all(), $matchId) : null;
    if ($match === null) {
        respond($isCli, false, 'Match not found.', [], 404);
    }

    if (!is_dir(MATCHES_EXPORT_DIR) && !@mkdir(MATCHES_EXPORT_DIR, 0775, true) && !is_dir(MATCHES_EXPORT_DIR)) {
        respond($isCli, false, 'Export directory could not be created.', [], 500);
    }

    $baseName = matches_slugify(matches_fixture_label($match)) . '-' . ((string) ($match['match_date'] ?? date('Y-m-d')));
    $exportPath = MATCHES_EXPORT_DIR . '/' . $baseName . '.png';
    $publicImageUrl = $socialBaseUrl . '/export/matches/' . rawurlencode($baseName) . '.png';
    $renderUrl = $socialBaseUrl . '/match_graphic.php?id=' . rawurlencode((string) $match['id'])
        . '&render=1&_token=' . rawurlencode(RENDER_ACCESS_TOKEN);
    $renderSelector = '.match-preview-wrap';
    $renderWidth = 1080;
    $renderHeight = 1080;
} else {
    $renderUrl = $url . '&table_style=' . rawurlencode($tableStyle);
    $renderSelector = '.table-card--render';
    $renderWidth = 1080;
    $renderHeight = 1350;
}

$renderResult = render_capture_image($renderUrl, $exportPath, $renderSelector, $renderWidth, $renderHeight, $renderSelector);
$generateDetails = $renderResult['output'];

if ($isCli) {
    echo 'Generating image from ' . $renderUrl . '...' . PHP_EOL;
    if ($generateDetails !== []) {
        echo implode(PHP_EOL, $generateDetails) . PHP_EOL;
    }
}

if (!$renderResult['ok'] || !is_file($exportPath)) {
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] Image generation failed\n", FILE_APPEND);
    respond($isCli, false, $renderResult['error'] !== '' ? $renderResult['error'] : 'Image generation failed.', $generateDetails, 500);
}

$phpCliBinary = resolvePhpCliBinary();
if ($phpCliBinary === null) {
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] PHP CLI binary not found for {$targetLabel} post step\n", FILE_APPEND);
    respond($isCli, false, 'Unable to find the PHP CLI binary for the ' . $targetLabel . ' posting step.', [], 500);
}

if ($isCli) {
    echo 'Posting to ' . $targetLabel . '...' . PHP_EOL;
}

$hubUserId = !$isCli ? ((int) ($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null) : null;
$confirmBurst = !$isCli && (string) ($_POST['confirm_burst'] ?? '') === '1';

$postCmd = escapeshellarg($phpCliBinary) . ' ' . escapeshellarg($postScript) . ' ' . escapeshellarg($exportPath);
if ($requestedTarget === 'instagram') {
    $postCmd .= ' ' . escapeshellarg($publicImageUrl);
}
$postCmd .= ' ' . escapeshellarg($graphicType);
$postCmd .= ' ' . escapeshellarg($matchId);
$postCmd .= ' ' . escapeshellarg($captionOverride);
if ($requestedTarget === 'facebook') {
    $postCmd .= ' ' . escapeshellarg($hubUserId !== null ? (string) $hubUserId : '');
    $postCmd .= ' ' . escapeshellarg($confirmBurst ? '1' : '0');
}
$postCmd .= ' 2>&1';
$postLines = [];
$postStatus = 0;
exec($postCmd, $postLines, $postStatus);
$postDetails = normalizeLines($postLines);
$postOutputText = implode(PHP_EOL, $postDetails);
$postResult = $requestedTarget === 'facebook' ? (facebook_parse_cli_result($postLines) ?? []) : [];

if ($isCli && $postOutputText !== '') {
    echo $postOutputText . PHP_EOL;
}

if (!empty($postResult['requires_confirmation'])) {
    respond($isCli, false, (string) ($postResult['error'] ?? 'Please confirm.'), $postDetails, 429, ['requires_confirmation' => true, 'recent_count' => $postResult['recent_count'] ?? null]);
}
if (!empty($postResult['duplicate'])) {
    respond($isCli, false, 'This exact content has already been published to Facebook.', $postDetails, 409, ['duplicate' => true]);
}
if (!empty($postResult['blocked'])) {
    respond($isCli, false, (string) ($postResult['error'] ?? 'Facebook publishing is disabled for this post type.'), $postDetails, 200, ['blocked' => true]);
}

$postFailed = $postStatus !== 0
    || stripos($postOutputText, 'curl error') !== false
    || stripos($postOutputText, 'missing page_access_token') !== false
    || stripos($postOutputText, '.env file not found') !== false
    || stripos($postOutputText, 'image not found') !== false
    || stripos($postOutputText, 'facebook api error') !== false
    || stripos($postOutputText, 'instagram api error') !== false
    || ($requestedTarget === 'facebook' && empty($postResult['ok']));

if ($postFailed) {
    file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $targetLabel . " post failed\n", FILE_APPEND);
    if ($postDetails === []) {
        $postDetails[] = basename($postScript) . ' did not return any details.';
    }
    $postDetails = clarifyPostFailureDetails($postDetails, $requestedTarget);
    respond($isCli, false, $targetLabel . ' post failed.', $postDetails, 500);
}

file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] Image generated and posted to ' . $targetLabel . " successfully\n", FILE_APPEND);
$successDetails = $target['success_details'];
if ($graphicType === 'match') {
    $successDetails[0] = 'Fresh match graphic generated successfully.';
}
respond($isCli, true, $target['success_summary'], $successDetails);
