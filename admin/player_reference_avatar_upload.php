<?php
declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/sync_social_directory.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: application/json');

function playerReferenceUploadError(int $statusCode, string $message): void
{
    http_response_code($statusCode);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if (!hub_auth_is_authenticated()) {
    playerReferenceUploadError(401, 'Login required.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_check()) {
    playerReferenceUploadError(419, 'Invalid CSRF token.');
}

$playerId = (int) ($_POST['player_id'] ?? 0);
if ($playerId <= 0) {
    playerReferenceUploadError(400, 'Invalid player ID.');
}

$playerStmt = $pdo->prepare('SELECT id, avatar FROM players WHERE id = :id LIMIT 1');
$playerStmt->execute([':id' => $playerId]);
$player = $playerStmt->fetch(PDO::FETCH_ASSOC);
if (!$player) {
    playerReferenceUploadError(404, 'Player not found.');
}

if (empty($_FILES['avatar']) || !is_array($_FILES['avatar'])) {
    playerReferenceUploadError(400, 'No image was uploaded.');
}

$file = $_FILES['avatar'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    playerReferenceUploadError(400, 'Image upload failed.');
}

if (!is_uploaded_file((string) $file['tmp_name'])) {
    playerReferenceUploadError(400, 'Invalid image upload.');
}

if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
    playerReferenceUploadError(400, 'Image must be 5MB or smaller.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, (string) $file['tmp_name']) : null;
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
    playerReferenceUploadError(400, 'Unsupported image format. Please use PNG, JPG, GIF or WebP.');
}

$uploadDir = __DIR__ . '/uploads/players';
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
    playerReferenceUploadError(500, 'Failed to prepare upload directory.');
}

try {
    $filename = 'avatar_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
} catch (Throwable $error) {
    playerReferenceUploadError(500, 'Failed to prepare image filename.');
}

$destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;
if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
    playerReferenceUploadError(500, 'Failed to store uploaded image.');
}

$oldAvatar = trim((string) ($player['avatar'] ?? ''));

$pdo->exec("CREATE TABLE IF NOT EXISTS player_photo_reviews (
  player_id INT UNSIGNED NOT NULL,
  status ENUM('accepted','rejected') NOT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id),
  CONSTRAINT fk_player_photo_reviews_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$currentUser = hub_auth_current_user();
$reviewerId = isset($currentUser['id']) ? (int) $currentUser['id'] : null;

$pdo->beginTransaction();
try {
    $avatarStmt = $pdo->prepare('UPDATE players SET avatar = :avatar WHERE id = :id');
    $avatarStmt->execute([
        ':avatar' => $filename,
        ':id' => $playerId,
    ]);

    $reviewStmt = $pdo->prepare("INSERT INTO player_photo_reviews (player_id, status, reviewed_by)
      VALUES (:player_id, 'accepted', :reviewed_by)
      ON DUPLICATE KEY UPDATE status = 'accepted', reviewed_by = VALUES(reviewed_by), reviewed_at = CURRENT_TIMESTAMP");
    $reviewStmt->execute([
        ':player_id' => $playerId,
        ':reviewed_by' => $reviewerId,
    ]);

    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    @unlink($destination);
    playerReferenceUploadError(500, 'Failed to save player photo.');
}

auditLog($pdo, 'player_avatar_uploaded', "Uploaded avatar for player #{$playerId}");

if ($oldAvatar !== '' && is_file($uploadDir . DIRECTORY_SEPARATOR . $oldAvatar)) {
    @unlink($uploadDir . DIRECTORY_SEPARATOR . $oldAvatar);
}

player_sponsors_sync_social_directory();

echo json_encode([
    'success' => true,
    'status' => 'accepted',
    'avatar_url' => '/uploads/players/' . rawurlencode($filename),
]);
