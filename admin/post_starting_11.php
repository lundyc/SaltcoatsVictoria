<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/social_post_settings.php';
require_once __DIR__ . '/lib/publishing_history.php';
require_once __DIR__ . '/lib/facebook_publisher.php';

header('Content-Type: application/json; charset=UTF-8');

/** @return never */
function starting11PostRespond(bool $ok, string $message, int $status = 200, array $details = [], array $extra = []): void
{
          http_response_code($status);
          echo json_encode(array_merge([
                    'ok' => $ok,
                    'message' => $message,
                    'details' => array_values($details),
          ], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
          starting11PostRespond(false, 'Method not allowed.', 405);
}

if (empty($_SESSION['user_id']) && empty($_SESSION['hub_user_id'])) {
          starting11PostRespond(false, 'Authentication required.', 401);
}

if (!csrf_check()) {
          starting11PostRespond(false, 'Invalid CSRF token.', 400);
}

$target = strtolower(trim((string)($_POST['target'] ?? '')));
$fixtureId = (int)($_POST['fixture_id'] ?? 0);
$imageData = (string)($_POST['image_data'] ?? '');
$captionOverride = trim((string)($_POST['caption'] ?? ''));
$allowRepost = (string)($_POST['allow_repost'] ?? '') === '1';

if (!in_array($target, ['facebook', 'instagram'], true)) {
          starting11PostRespond(false, 'Unsupported social platform.', 400);
}
if (!social_publishing_platform_enabled($target)) {
          starting11PostRespond(false, ucfirst($target) . ' publishing is disabled in Settings.', 403);
}

if ($fixtureId <= 0) {
          starting11PostRespond(false, 'Fixture is required.', 400);
}

$fixtureStmt = $pdo->prepare("
          SELECT COALESCE(o.clubname, f.opponent) AS opponent
          FROM match_fixtures f
          LEFT JOIN match_opponents o ON o.id = f.opponent_id
          WHERE f.id = :id
          LIMIT 1
");
$fixtureStmt->execute([':id' => $fixtureId]);
$opponent = trim((string)$fixtureStmt->fetchColumn());
if ($opponent === '') {
          starting11PostRespond(false, 'Fixture not found.', 404);
}

if (preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\r\n]+)$#', $imageData, $matches) !== 1) {
          starting11PostRespond(false, 'The rendered Starting XI image is invalid.', 400);
}

$imageBytes = base64_decode(preg_replace('/\s+/', '', $matches[1]) ?? '', true);
if ($imageBytes === false || $imageBytes === '' || strlen($imageBytes) > 6 * 1024 * 1024) {
          starting11PostRespond(false, 'The rendered Starting XI image is invalid or too large.', 400);
}

$imageInfo = @getimagesizefromstring($imageBytes);
if (!is_array($imageInfo) || ($imageInfo['mime'] ?? '') !== 'image/png') {
          starting11PostRespond(false, 'The rendered file must be a PNG image.', 400);
}

$exportDir = __DIR__ . '/export/matches';
if (!is_dir($exportDir) && !@mkdir($exportDir, 0775, true) && !is_dir($exportDir)) {
          starting11PostRespond(false, 'The social export directory could not be created.', 500);
}

$fileName = 'starting-xi-fixture-' . $fixtureId . '.png';
$exportPath = $exportDir . '/' . $fileName;
if (@file_put_contents($exportPath, $imageBytes, LOCK_EX) === false) {
          starting11PostRespond(false, 'The rendered Starting XI image could not be saved.', 500);
}
@chmod($exportPath, 0664);

$socialDir = __DIR__;
$caption = $captionOverride !== ''
          ? $captionOverride
          : social_post_resolve_caption($target, 'starting_xi', ['opponent' => $opponent]);
$phpBinary = is_file('/usr/bin/php') && is_executable('/usr/bin/php')
          ? '/usr/bin/php'
          : (defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php');

// Facebook: post_to_facebook.php performs its own atomic, fingerprint-based
// dedupe reservation (lib/facebook_publisher.php) covering fixture + caption
// + rendered image bytes, so a single source of truth decides "already
// published" instead of two dedupe schemes racing each other. allow_repost
// is passed through so an operator who deleted the original on Facebook can
// still confirm an intentional replacement post.
if ($target === 'facebook') {
          $hubUserId = (int) ($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null;
          $confirmBurst = (string) ($_POST['confirm_burst'] ?? '') === '1';
          $command = escapeshellarg($phpBinary)
                    . ' ' . escapeshellarg($socialDir . '/post_to_facebook.php')
                    . ' ' . escapeshellarg($exportPath)
                    . ' ' . escapeshellarg('starting_xi')
                    . ' ' . escapeshellarg((string)$fixtureId)
                    . ' ' . escapeshellarg($caption)
                    . ' ' . escapeshellarg($hubUserId !== null ? (string)$hubUserId : '')
                    . ' ' . escapeshellarg($confirmBurst ? '1' : '0')
                    . ' ' . escapeshellarg($allowRepost ? '1' : '0');

          $output = [];
          $exitCode = 0;
          exec($command . ' 2>&1', $output, $exitCode);
          $details = array_values(array_filter(array_map('trim', $output), static fn(string $line): bool => $line !== ''));
          $result = facebook_parse_cli_result($output) ?? [];

          if (!empty($result['requires_confirmation'])) {
                    starting11PostRespond(false, (string) ($result['error'] ?? 'Please confirm.'), 429, $details, ['requires_confirmation' => true, 'recent_count' => $result['recent_count'] ?? null]);
          }
          if (!empty($result['duplicate'])) {
                    starting11PostRespond(
                              false,
                              'This exact Starting XI has already been published to Facebook.',
                              409,
                              ['You can confirm a replacement post if the original was deleted.'],
                              ['duplicate' => true]
                    );
          }
          if (!empty($result['blocked'])) {
                    starting11PostRespond(false, (string) ($result['error'] ?? 'Facebook publishing is disabled for this post type.'), 200, $details, ['blocked' => true]);
          }
          if ($exitCode !== 0 || empty($result['ok'])) {
                    starting11PostRespond(false, 'Facebook post failed.', 500, $details);
          }

          starting11PostRespond(true, 'Starting XI posted to Facebook.', 200, $details);
}

$history = hub_publishing_history_start($pdo, [
          'fixture_id' => $fixtureId,
          'post_type' => 'starting_xi',
          'platform' => $target,
          'caption' => $caption,
          'image_url' => '/match_starting_11_graphic.php?fixture_id=' . $fixtureId,
          'content_hash' => hash('sha256', $imageBytes),
          'allow_repost' => $allowRepost,
]);
if ($history['duplicate']) {
          starting11PostRespond(
                    false,
                    'This exact Starting XI has already been published to ' . ucfirst($target) . '.',
                    409,
                    ['You can confirm a replacement post if the original was deleted.']
          );
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
          starting11PostRespond(false, ucfirst($target) . ' post failed.', 500, $details);
}

$externalPostId = '';
foreach ($details as $detail) {
          if (preg_match('/\\b(?:post|photo)_id=([A-Za-z0-9_:-]+)/', $detail, $idMatch) === 1) {
                    $externalPostId = (string)$idMatch[1];
                    break;
          }
}
hub_publishing_history_finish($pdo, $history['id'], true, $externalPostId !== '' ? $externalPostId : implode(' ', $details));
starting11PostRespond(true, 'Starting XI posted to ' . ucfirst($target) . '.', 200, $details);
