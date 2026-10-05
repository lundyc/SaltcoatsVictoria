<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// player_avatar_upload.php: Handles avatar upload for players
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/sync_social_directory.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: text/html; charset=utf-8');

if (!hub_auth_is_authenticated()) {
          http_response_code(401);
          echo '<div class="alert alert-danger">Authentication required.</div>';
          exit;
}

verify_csrf();

$id = (int)($_POST['player_id'] ?? 0);
if ($id <= 0) {
          echo '<div class="alert alert-danger">Invalid player ID.</div>';
          exit;
}

$stmt = $pdo->prepare("SELECT * FROM players WHERE id = :id");
$stmt->execute([':id' => $id]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$player) {
          echo '<div class="alert alert-danger">Player not found.</div>';
          exit;
}

$errors = [];
$avatarFilename = $player['avatar'] ?? null;
$uploadDir = __DIR__ . '/uploads/players';

if (isset($_POST['remove_avatar']) && $_POST['remove_avatar'] === '1') {
          if ($avatarFilename && is_file($uploadDir . '/' . $avatarFilename)) {
                    @unlink($uploadDir . '/' . $avatarFilename);
          }

          $stmt = $pdo->prepare("UPDATE players SET avatar = NULL WHERE id = :id");
          $stmt->execute([':id' => $id]);
          auditLog($pdo, 'player_avatar_removed', "Removed avatar for player '{$player['name']}'");
          player_sponsors_sync_social_directory();

          header('Location: player_edit.php?id=' . $id . '&avatar_success=1');
          exit;
}

if (!empty($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
          $file = $_FILES['avatar'];
          if ($file['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = 'Image upload failed. Please try again.';
                    $errors[] = 'Upload error code: ' . $file['error'];
                    $errors[] = 'File array: <pre>' . htmlspecialchars(print_r($file, true)) . '</pre>';
                    $errors[] = 'Upload max filesize (php.ini): ' . ini_get('upload_max_filesize');
                    $errors[] = 'Post max size (php.ini): ' . ini_get('post_max_size');
                    $errors[] = 'Temp dir: ' . (sys_get_temp_dir() ?: 'N/A');
          } elseif (!is_uploaded_file($file['tmp_name'])) {
                    $errors[] = 'Invalid image upload.';
          } elseif ($file['size'] > 5 * 1024 * 1024) {
                    $errors[] = 'Image must be 5MB or smaller.';
          } else {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
                    if ($finfo) finfo_close($finfo);
                    $allowed = [
                              'image/jpeg' => 'jpg',
                              'image/png'  => 'png',
                              'image/gif'  => 'gif',
                              'image/webp' => 'webp',
                    ];
                    if (!isset($allowed[$mime ?? ''])) {
                              $errors[] = 'Unsupported image format. Please use PNG, JPG, GIF or WebP.';
                    } else {
                              if (!is_dir($uploadDir)) {
                                        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                                                  $errors[] = 'Failed to prepare upload directory.';
                                        }
                              }
                              if (!$errors) {
                                        try {
                                                  $random = bin2hex(random_bytes(8));
                                        } catch (Exception $e) {
                                                  $errors[] = 'Failed to prepare filename for image upload.';
                                                  $random = null;
                                        }
                                        if (!$errors && $random !== null) {
                                                  $filename = 'avatar_' . $random . '.' . $allowed[$mime];
                                                  $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;
                                                  if (!move_uploaded_file($file['tmp_name'], $destination)) {
                                                            $errors[] = 'Failed to store uploaded image.';
                                                  } else {
                                                            // Delete old avatar if present
                                                            if ($avatarFilename && is_file($uploadDir . '/' . $avatarFilename)) {
                                                                      @unlink($uploadDir . '/' . $avatarFilename);
                                                            }
                                                            $avatarFilename = $filename;
                                                  }
                                        }
                              }
                    }
          }
}

if (!$errors && $avatarFilename !== $player['avatar']) {
          $stmt = $pdo->prepare("UPDATE players SET avatar = :avatar WHERE id = :id");
          $stmt->execute([
                    ':avatar' => $avatarFilename,
                    ':id' => $id,
          ]);
          auditLog($pdo, 'player_avatar_uploaded', "Uploaded avatar for player '{$player['name']}'");
          player_sponsors_sync_social_directory();
          header('Location: player_edit.php?id=' . $id . '&avatar_success=1');
          exit;
}

if ($errors) {
          // Flatten errors to a single string
          $errorMsg = '';
          foreach ($errors as $e) {
                    $errorMsg .= strip_tags($e) . ' ';
          }
          $errorMsg = urlencode(trim($errorMsg));
          header('Location: player_edit.php?id=' . $id . '&avatar_error=' . $errorMsg);
          exit;
}

header('Location: player_edit.php?id=' . $id);
exit;
