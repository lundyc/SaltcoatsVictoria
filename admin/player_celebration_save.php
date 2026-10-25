<?php

declare(strict_types=1);

// Upload or remove a player's goal-celebration picture (used on the Goal graphic instead of the profile photo).
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/audit.php';

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    exit('Authentication required.');
}
verify_csrf();

$id = (int)($_POST['player_id'] ?? 0);
$back = 'player_edit.php?id=' . $id;
$stmt = $pdo->prepare('SELECT id, celebration_image FROM players WHERE id = :id');
try {
    $stmt->execute([':id' => $id]);
} catch (Throwable $e) {
    $pdo->exec('ALTER TABLE players ADD COLUMN celebration_image VARCHAR(255) NULL DEFAULT NULL');
    $stmt->execute([':id' => $id]);
}
$player = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$player) {
    header('Location: players.php');
    exit;
}
$dir = __DIR__ . '/uploads/players';
$old = basename((string)($player['celebration_image'] ?? ''));

if (($_POST['remove'] ?? '') === '1') {
    $pdo->prepare('UPDATE players SET celebration_image = NULL WHERE id = :id')->execute([':id' => $id]);
    if ($old !== '' && is_file($dir . '/' . $old)) {
        @unlink($dir . '/' . $old);
    }
    auditLog($pdo, 'player_celebration_removed', "Removed celebration picture for player #{$id}");
    header('Location: ' . $back . '&celebration=removed');
    exit;
}

$file = $_FILES['celebration'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
    header('Location: ' . $back . '&celebration=error');
    exit;
}
if ((int)$file['size'] > 10 * 1024 * 1024) {
    header('Location: ' . $back . '&celebration=toolarge');
    exit;
}
$mime = (string)(function_exists('finfo_open') ? finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) : '');
$ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? '';
if ($ext === '') {
    header('Location: ' . $back . '&celebration=badtype');
    exit;
}
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    header('Location: ' . $back . '&celebration=error');
    exit;
}
$name = 'celebration_' . $id . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
    header('Location: ' . $back . '&celebration=error');
    exit;
}
@chmod($dir . '/' . $name, 0664);
$pdo->prepare('UPDATE players SET celebration_image = :f WHERE id = :id')->execute([':f' => $name, ':id' => $id]);
if ($old !== '' && is_file($dir . '/' . $old)) {
    @unlink($dir . '/' . $old);
}
auditLog($pdo, 'player_celebration_saved', "Uploaded celebration picture for player #{$id}");
header('Location: ' . $back . '&celebration=saved');
exit;
