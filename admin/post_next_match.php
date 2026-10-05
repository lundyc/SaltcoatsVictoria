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

set_exception_handler(static function (Throwable $error): void {
          error_log('Next Match social post failed: ' . $error->getMessage());
          nextMatchPostRespond(false, 'The social post could not be processed. Please try again.', 500);
});

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/social_post_settings.php';
require_once __DIR__ . '/lib/publishing_history.php';
require_once __DIR__ . '/lib/facebook_publisher.php';

/** @return never */
function nextMatchPostRespond(bool $ok, string $message, int $status = 200, array $details = [], array $extra = []): void
{
          if (ob_get_level() > 0) {
                    ob_clean();
          }
          http_response_code($status);
          echo json_encode(array_merge([
                    'ok' => $ok,
                    'message' => $message,
                    'details' => array_values($details),
          ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
          nextMatchPostRespond(false, 'Method not allowed.', 405);
}
if (empty($_SESSION['user_id']) && empty($_SESSION['hub_user_id'])) {
          nextMatchPostRespond(false, 'Authentication required.', 401);
}
if (!csrf_check()) {
          nextMatchPostRespond(false, 'Invalid CSRF token.', 400);
}

$target = strtolower(trim((string)($_POST['target'] ?? '')));
$fixtureId = (int)($_POST['fixture_id'] ?? 0);
$caption = trim((string)($_POST['caption'] ?? ''));
$imageData = (string)($_POST['image_data'] ?? '');
$postType = strtolower(trim((string)($_POST['post_type'] ?? 'next_match')));
if (!in_array($postType, ['next_match', 'matchday'], true)) {
          $postType = 'next_match';
}
$postTypeLabel = $postType === 'matchday' ? 'Matchday' : 'Next Match';

if (!in_array($target, ['facebook', 'instagram'], true)) {
          nextMatchPostRespond(false, 'Unsupported social platform.', 400);
}
if (!social_publishing_platform_enabled($target)) {
          nextMatchPostRespond(false, ucfirst($target) . ' publishing is disabled in Settings.', 403);
}
if ($fixtureId <= 0 || $caption === '') {
          nextMatchPostRespond(false, 'Fixture and post text are required.', 400);
}

$fixtureStmt = $pdo->prepare('SELECT id FROM match_fixtures WHERE id = :id LIMIT 1');
$fixtureStmt->execute([':id' => $fixtureId]);
if (!$fixtureStmt->fetchColumn()) {
          nextMatchPostRespond(false, 'Fixture not found.', 404);
}

if (preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\r\n]+)$#', $imageData, $matches) !== 1) {
          nextMatchPostRespond(false, 'The rendered Next Match image is invalid.', 400);
}
$imageBytes = base64_decode(preg_replace('/\s+/', '', $matches[1]) ?? '', true);
if ($imageBytes === false || $imageBytes === '' || strlen($imageBytes) > 10 * 1024 * 1024) {
          nextMatchPostRespond(false, 'The rendered Next Match image is invalid or too large.', 400);
}
$imageInfo = @getimagesizefromstring($imageBytes);
if (!is_array($imageInfo) || ($imageInfo['mime'] ?? '') !== 'image/png') {
          nextMatchPostRespond(false, 'The rendered file must be a PNG image.', 400);
}

$exportDir = __DIR__ . '/export/matches';
if (!is_dir($exportDir) && !@mkdir($exportDir, 0775, true) && !is_dir($exportDir)) {
          nextMatchPostRespond(false, 'The social export directory could not be created.', 500);
}
$fileName = 'next-match-fixture-' . $fixtureId . '.png';
$exportPath = $exportDir . '/' . $fileName;
if (@file_put_contents($exportPath, $imageBytes, LOCK_EX) === false) {
          nextMatchPostRespond(false, 'The Next Match image could not be saved.', 500);
}
@chmod($exportPath, 0664);

$socialDir = __DIR__;
$hubUserId = (int) ($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null;
$confirmBurst = (string) ($_POST['confirm_burst'] ?? '') === '1';
$phpBinary = is_file('/usr/bin/php') && is_executable('/usr/bin/php') ? '/usr/bin/php' : (PHP_BINARY !== '' ? PHP_BINARY : 'php');

// Facebook: post_to_facebook.php performs its own atomic, fingerprint-based
// dedupe reservation (lib/facebook_publisher.php) — no separate reservation
// is taken here, so there is a single source of truth for "was this already
// published" instead of two dedupe schemes racing each other.
if ($target === 'facebook') {
          $command = escapeshellarg($phpBinary)
                    . ' ' . escapeshellarg($socialDir . '/post_to_facebook.php')
                    . ' ' . escapeshellarg($exportPath)
                    . ' ' . escapeshellarg($postType)
                    . ' ' . escapeshellarg((string)$fixtureId)
                    . ' ' . escapeshellarg($caption)
                    . ' ' . escapeshellarg($hubUserId !== null ? (string)$hubUserId : '')
                    . ' ' . escapeshellarg($confirmBurst ? '1' : '0');

          $output = [];
          $exitCode = 0;
          exec($command . ' 2>&1', $output, $exitCode);
          $details = array_values(array_filter(array_map('trim', $output), static fn(string $line): bool => $line !== ''));
          $result = facebook_parse_cli_result($output) ?? [];

          if (!empty($result['requires_confirmation'])) {
                    nextMatchPostRespond(false, (string) ($result['error'] ?? 'Please confirm.'), 429, $details, ['requires_confirmation' => true, 'recent_count' => $result['recent_count'] ?? null]);
          }
          if (!empty($result['duplicate'])) {
                    nextMatchPostRespond(false, "This $postTypeLabel post has already been published to Facebook.", 409, $details, ['duplicate' => true]);
          }
          if (!empty($result['blocked'])) {
                    nextMatchPostRespond(false, (string) ($result['error'] ?? 'Facebook publishing is disabled for this post type.'), 200, $details, ['blocked' => true]);
          }
          if ($exitCode !== 0 || empty($result['ok'])) {
                    nextMatchPostRespond(false, 'Facebook post failed.', 500, $details);
          }

          nextMatchPostRespond(true, "$postTypeLabel graphic posted to Facebook.", 200, $details);
}

$history = hub_publishing_history_start($pdo, [
          'fixture_id' => $fixtureId,
          'post_type' => $postType,
          'platform' => $target,
          'caption' => $caption,
          'image_url' => '/match_next_match.php?fixture_id=' . $fixtureId,
]);
if ($history['duplicate']) {
          nextMatchPostRespond(false, "This $postTypeLabel post has already been published to " . ucfirst($target) . '.', 409);
}
$publicImageUrl = APP_ORIGIN . '/admin/export/matches/' . rawurlencode($fileName) . '?v=' . time();
$command = escapeshellarg($phpBinary)
          . ' ' . escapeshellarg($socialDir . '/post_to_instagram.php')
          . ' ' . escapeshellarg($exportPath)
          . ' ' . escapeshellarg($publicImageUrl)
          . ' ' . escapeshellarg('match')
          . ' ' . escapeshellarg((string)$fixtureId)
          . ' ' . escapeshellarg($caption);

$output = [];
$exitCode = 0;
exec($command . ' 2>&1', $output, $exitCode);
$details = array_values(array_filter(array_map('trim', $output), static fn(string $line): bool => $line !== ''));
if ($exitCode !== 0) {
          hub_publishing_history_finish($pdo, $history['id'], false, '', implode("\n", $details));
          nextMatchPostRespond(false, ucfirst($target) . ' post failed.', 500, $details);
}

hub_publishing_history_finish($pdo, $history['id'], true, implode(' ', $details));
nextMatchPostRespond(true, "$postTypeLabel graphic posted to " . ucfirst($target) . '.', 200, $details);
