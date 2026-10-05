<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('club_setup')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
          http_response_code(405);
          exit('Invalid request method.');
}

if (!csrf_check()) {
          http_response_code(400);
          exit('Invalid CSRF token.');
}

$clubname = trim((string)($_POST['clubname'] ?? ''));
$abbreviation = strtoupper(trim((string)($_POST['abbreviation'] ?? '')));
$groundLocation = trim((string)($_POST['ground_location'] ?? ''));
$fixtureId = (int)($_POST['return_to_fixture_id'] ?? 0);
$seasonId = (int)($_POST['return_to_season_id'] ?? 0);
$action = trim((string)($_POST['return_action'] ?? 'view'));

if ($clubname === '') {
          http_response_code(400);
          exit('Club name is required.');
}

if ($abbreviation === '' || !preg_match('/^[A-Z0-9]{2,16}$/', $abbreviation)) {
          http_response_code(400);
          exit('Abbreviation must be 2-16 letters or numbers with no spaces.');
}

$existing = getMatchOpponentByClubname($pdo, $clubname);
if ($existing) {
          $redirect = 'match.php?' . http_build_query([
                    'id' => $fixtureId,
                    'season_id' => $seasonId,
                    'action' => $action,
                    'opponent_added' => (int)$existing['id'],
          ]);
          header('Location: ' . $redirect);
          exit;
}

$currentLogo = null;
$pendingUpload = null;
$deleteAfterCommit = null;
$newLogoPath = null;

if (!empty($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
          $file = $_FILES['logo'];
          if ($file['error'] !== UPLOAD_ERR_OK) {
                    http_response_code(400);
                    exit('Image upload failed. Please try again.');
          }
          if (!is_uploaded_file($file['tmp_name'])) {
                    http_response_code(400);
                    exit('Invalid image upload.');
          }
          if ($file['size'] > 5 * 1024 * 1024) {
                    http_response_code(400);
                    exit('Image must be 5MB or smaller.');
          }

          $finfo = finfo_open(FILEINFO_MIME_TYPE);
          $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
          if ($finfo) {
                    finfo_close($finfo);
          }
          $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
          ];
          if (!isset($allowed[$mime ?? ''])) {
                    http_response_code(400);
                    exit('Unsupported image format. Please use PNG, JPG, GIF or WebP.');
          }

          $uploadDir = __DIR__ . '/uploads/opponents';
          if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    http_response_code(500);
                    exit('Failed to prepare upload directory.');
          }

          try {
                    $random = bin2hex(random_bytes(8));
          } catch (Throwable $e) {
                    http_response_code(500);
                    exit('Failed to prepare filename for image upload.');
          }

          $filename = 'opponent_' . $random . '.' . $allowed[$mime];
          $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;
          $pendingUpload = [
                    'tmp_name' => $file['tmp_name'],
                    'destination' => $destination,
          ];
          $newLogoPath = $filename;
}

$pdo->beginTransaction();
try {
          $savedId = saveMatchOpponentWithAbbreviation(
                    $pdo,
                    null,
                    $clubname,
                    $abbreviation,
                    $newLogoPath,
                    $groundLocation !== '' ? $groundLocation : null
          );

          if ($pendingUpload) {
                    if (!move_uploaded_file($pendingUpload['tmp_name'], $pendingUpload['destination'])) {
                              throw new RuntimeException('Failed to store uploaded image.');
                    }
          }

          $pdo->commit();

          auditLog($pdo, 'opponent_created', "Created opponent '{$clubname}'");

          $redirect = 'match.php?' . http_build_query([
                    'id' => $fixtureId,
                    'season_id' => $seasonId,
                    'action' => $action,
                    'opponent_added' => $savedId,
          ]);
          header('Location: ' . $redirect);
          exit;
} catch (Throwable $e) {
          if ($pdo->inTransaction()) {
                    $pdo->rollBack();
          }
          if ($pendingUpload && isset($pendingUpload['destination']) && is_file($pendingUpload['destination'])) {
                    @unlink($pendingUpload['destination']);
          }
          http_response_code(400);
          echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
