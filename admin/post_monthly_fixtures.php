<?php
declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/social_post_settings.php';
require_once __DIR__ . '/lib/publishing_history.php';
require_once __DIR__ . '/lib/facebook_publisher.php';

/** @return never */
function monthlyFixturesPostRespond(bool $ok, string $message, int $status = 200, array $details = [], array $extra = []): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message, 'details' => array_values($details)], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(static function (Throwable $error): void {
    error_log('Monthly Fixtures social post failed: ' . $error->getMessage());
    monthlyFixturesPostRespond(false, 'The social post could not be processed. Please try again.', 500);
});

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    monthlyFixturesPostRespond(false, 'Method not allowed.', 405);
}
if (empty($_SESSION['user_id']) && empty($_SESSION['hub_user_id'])) {
    monthlyFixturesPostRespond(false, 'Authentication required.', 401);
}
if (!csrf_check()) {
    monthlyFixturesPostRespond(false, 'Invalid CSRF token.', 400);
}

$target = strtolower(trim((string)($_POST['target'] ?? '')));
$seasonId = (int)($_POST['season_id'] ?? 0);
$month = trim((string)($_POST['month'] ?? ''));
$layout = (string)($_POST['layout'] ?? '') === 'calendar' ? 'calendar' : 'block';
$caption = trim((string)($_POST['caption'] ?? ''));
$imageData = (string)($_POST['image_data'] ?? '');

if (!in_array($target, ['facebook', 'instagram'], true)) {
    monthlyFixturesPostRespond(false, 'Unsupported social platform.', 400);
}
if (!social_publishing_platform_enabled($target)) {
    monthlyFixturesPostRespond(false, ucfirst($target) . ' publishing is disabled in Settings.', 403);
}
if (!$seasonId || !getSeasonById($pdo, $seasonId) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1 || $caption === '') {
    monthlyFixturesPostRespond(false, 'Season, month and post text are required.', 400);
}
if (preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\r\n]+)$#', $imageData, $matches) !== 1) {
    monthlyFixturesPostRespond(false, 'The rendered Monthly Fixtures image is invalid.', 400);
}
$imageBytes = base64_decode(preg_replace('/\s+/', '', $matches[1]) ?? '', true);
if ($imageBytes === false || $imageBytes === '' || strlen($imageBytes) > 12 * 1024 * 1024) {
    monthlyFixturesPostRespond(false, 'The rendered image is invalid or too large.', 400);
}
$imageInfo = @getimagesizefromstring($imageBytes);
if (!is_array($imageInfo) || ($imageInfo['mime'] ?? '') !== 'image/png') {
    monthlyFixturesPostRespond(false, 'The rendered file must be a PNG image.', 400);
}

$exportDirectory = __DIR__ . '/export/monthly-fixtures';
if (!is_dir($exportDirectory) && !@mkdir($exportDirectory, 0775, true) && !is_dir($exportDirectory)) {
    monthlyFixturesPostRespond(false, 'The social export directory could not be created.', 500);
}
$fileName = 'monthly-fixtures-season-' . $seasonId . '-' . $month . '-' . $layout . '.png';
$exportPath = $exportDirectory . '/' . $fileName;
if (@file_put_contents($exportPath, $imageBytes, LOCK_EX) === false) {
    monthlyFixturesPostRespond(false, 'The Monthly Fixtures image could not be saved.', 500);
}
@chmod($exportPath, 0664);

$eventId = 'season:' . $seasonId . ':' . $month . ':' . $layout;
$phpBinary = is_file('/usr/bin/php') && is_executable('/usr/bin/php') ? '/usr/bin/php' : (PHP_BINARY !== '' ? PHP_BINARY : 'php');

// Facebook: post_to_facebook.php performs its own atomic, fingerprint-based
// dedupe reservation (lib/facebook_publisher.php); no separate reservation
// is taken here, so there is a single source of truth for Facebook.
if ($target === 'facebook') {
    $hubUserId = (int) ($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null;
    $confirmBurst = (string) ($_POST['confirm_burst'] ?? '') === '1';
    $command = escapeshellarg($phpBinary) . ' ' . escapeshellarg(__DIR__ . '/post_to_facebook.php') . ' ' . escapeshellarg($exportPath) . ' ' . escapeshellarg('monthly_fixtures') . ' ' . escapeshellarg((string)$seasonId) . ' ' . escapeshellarg($caption)
        . ' ' . escapeshellarg($hubUserId !== null ? (string)$hubUserId : '')
        . ' ' . escapeshellarg($confirmBurst ? '1' : '0');

    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    $details = array_values(array_filter(array_map('trim', $output), static fn(string $line): bool => $line !== ''));
    $result = facebook_parse_cli_result($output) ?? [];

    if (!empty($result['requires_confirmation'])) {
        monthlyFixturesPostRespond(false, (string) ($result['error'] ?? 'Please confirm.'), 429, $details, ['requires_confirmation' => true, 'recent_count' => $result['recent_count'] ?? null]);
    }
    if (!empty($result['duplicate'])) {
        monthlyFixturesPostRespond(false, 'This Monthly Fixtures graphic has already been published to Facebook.', 409, $details, ['duplicate' => true]);
    }
    if (!empty($result['blocked'])) {
        monthlyFixturesPostRespond(false, (string) ($result['error'] ?? 'Facebook publishing is disabled for this post type.'), 200, $details, ['blocked' => true]);
    }
    if ($exitCode !== 0 || empty($result['ok'])) {
        monthlyFixturesPostRespond(false, 'Facebook post failed.', 500, $details);
    }

    monthlyFixturesPostRespond(true, 'Monthly Fixtures graphic posted to Facebook.', 200, $details);
}

$history = hub_publishing_history_start($pdo, [
    'event_id' => $eventId,
    'post_type' => 'monthly_fixtures',
    'platform' => $target,
    'caption' => $caption,
    'image_url' => '/monthly_fixtures.php?season_id=' . $seasonId . '&month=' . rawurlencode($month) . '&layout=' . $layout,
    'content_hash' => hash('sha256', $imageBytes),
]);
if ($history['duplicate']) {
    monthlyFixturesPostRespond(false, 'This Monthly Fixtures graphic has already been published to ' . ucfirst($target) . '.', 409);
}

$publicImageUrl = APP_ORIGIN . '/admin/export/monthly-fixtures/' . rawurlencode($fileName) . '?v=' . time();
$command = escapeshellarg($phpBinary) . ' ' . escapeshellarg(__DIR__ . '/post_to_instagram.php') . ' ' . escapeshellarg($exportPath) . ' ' . escapeshellarg($publicImageUrl) . ' ' . escapeshellarg('monthly_fixtures') . ' ' . escapeshellarg((string)$seasonId) . ' ' . escapeshellarg($caption);

$output = [];
$exitCode = 0;
exec($command . ' 2>&1', $output, $exitCode);
$details = array_values(array_filter(array_map('trim', $output), static fn(string $line): bool => $line !== ''));
if ($exitCode !== 0) {
    hub_publishing_history_finish($pdo, $history['id'], false, '', implode("\n", $details));
    monthlyFixturesPostRespond(false, ucfirst($target) . ' post failed.', 500, $details);
}
hub_publishing_history_finish($pdo, $history['id'], true, implode(' ', $details));
monthlyFixturesPostRespond(true, 'Monthly Fixtures graphic posted to ' . ucfirst($target) . '.', 200, $details);
