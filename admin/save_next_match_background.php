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

header('Content-Type: application/json; charset=UTF-8');

/** @return never */
function nextMatchBackgroundRespond(bool $ok, string $message, int $status = 200, string $imageUrl = ''): void
{
          http_response_code($status);
          echo json_encode([
                    'ok' => $ok,
                    'message' => $message,
                    'image_url' => $imageUrl,
          ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
          exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
          nextMatchBackgroundRespond(false, 'Method not allowed.', 405);
}
if (empty($_SESSION['user_id']) && empty($_SESSION['hub_user_id'])) {
          nextMatchBackgroundRespond(false, 'Authentication required.', 401);
}
if (!csrf_check()) {
          nextMatchBackgroundRespond(false, 'Invalid security token. Please refresh and try again.', 400);
}

$fixtureId = (int)($_POST['fixture_id'] ?? 0);
if ($fixtureId <= 0) {
          nextMatchBackgroundRespond(false, 'Fixture not found.', 404);
}

$fixture = getMatchFixtureById($pdo, $fixtureId);
if (!$fixture) {
          nextMatchBackgroundRespond(false, 'Fixture not found.', 404);
}
$season = getSeasonById($pdo, (int)$fixture['season_id']);
if (!$season) {
          nextMatchBackgroundRespond(false, 'Season not found.', 404);
}
if ((int)($season['is_locked'] ?? 0) === 1) {
          nextMatchBackgroundRespond(false, 'This season is locked.', 400);
}

$file = $_FILES['background_image'] ?? null;
if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
          nextMatchBackgroundRespond(false, 'Choose a background image first.', 400);
}
if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
          nextMatchBackgroundRespond(false, 'The background upload failed.', 400);
}
if ((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > 10 * 1024 * 1024) {
          nextMatchBackgroundRespond(false, 'The background image must be 10MB or smaller.', 400);
}

$tmpPath = (string)($file['tmp_name'] ?? '');
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
          nextMatchBackgroundRespond(false, 'The uploaded background is invalid.', 400);
}
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($tmpPath);
$extensionMap = [
          'image/jpeg' => 'jpg',
          'image/png' => 'png',
          'image/webp' => 'webp',
];
if (!isset($extensionMap[$mime]) || @getimagesize($tmpPath) === false) {
          nextMatchBackgroundRespond(false, 'Background must be a JPG, PNG, or WEBP image.', 400);
}

$uploadDir = __DIR__ . '/uploads/matches';
if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
          nextMatchBackgroundRespond(false, 'The upload folder could not be prepared.', 500);
}

try {
          $suffix = bin2hex(random_bytes(6));
} catch (Throwable $e) {
          nextMatchBackgroundRespond(false, 'The background filename could not be prepared.', 500);
}
$filename = sprintf('fixture_%d_next_match_%s_%s.%s', $fixtureId, date('YmdHis'), $suffix, $extensionMap[$mime]);
$relativePath = 'uploads/matches/' . $filename;
$targetPath = $uploadDir . '/' . $filename;
if (!move_uploaded_file($tmpPath, $targetPath)) {
          nextMatchBackgroundRespond(false, 'The background image could not be stored.', 500);
}
@chmod($targetPath, 0664);

$previousPath = trim((string)($fixture['next_match_background_image'] ?? ''));
try {
          $stmt = $pdo->prepare('UPDATE match_fixtures SET next_match_background_image = :background_image WHERE id = :id LIMIT 1');
          $stmt->execute([
                    ':background_image' => $relativePath,
                    ':id' => $fixtureId,
          ]);
} catch (Throwable $e) {
          @unlink($targetPath);
          nextMatchBackgroundRespond(false, 'The fixture could not be updated.', 500);
}

if ($previousPath !== '' && str_starts_with($previousPath, 'uploads/matches/')) {
          $previousAbsolutePath = __DIR__ . '/' . $previousPath;
          if (is_file($previousAbsolutePath)) {
                    @unlink($previousAbsolutePath);
          }
}

$imageUrl = '/' . $relativePath . '?v=' . time();
nextMatchBackgroundRespond(true, 'Background image saved for this fixture.', 200, $imageUrl);
