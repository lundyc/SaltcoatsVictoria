<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// player_action_shot_upload.php: Handles action-shot photo uploads for a player.
// Action shots are extra photos (beyond the single profile picture) used when
// generating graphics such as Player of the Match, Goal and Player Sponsor posters.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: text/html; charset=utf-8');

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    echo '<div class="alert alert-danger">Authentication required.</div>';
    exit;
}

verify_csrf();

$id = (int)($_POST['player_id'] ?? 0);
$returnPage = ($_POST['return_to'] ?? '') === 'player_photos' ? 'player_photos.php' : 'player_edit.php';
if ($id <= 0) {
    echo '<div class="alert alert-danger">Invalid player ID.</div>';
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM players WHERE id = :id");
$stmt->execute([':id' => $id]);
if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
    echo '<div class="alert alert-danger">Player not found.</div>';
    exit;
}

$uploadDir = __DIR__ . '/uploads/players/action_shots';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        echo '<div class="alert alert-danger">Failed to prepare upload directory.</div>';
        exit;
    }
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

$files = $_FILES['action_shots'] ?? null;
$errors = [];
$savedCount = 0;

if ($files && is_array($files['name'] ?? null)) {
    $maxOrderRow = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) AS max_order FROM player_action_shots WHERE player_id = :pid");
    $maxOrderRow->execute([':pid' => $id]);
    $nextOrder = (int)($maxOrderRow->fetch(PDO::FETCH_ASSOC)['max_order'] ?? -1) + 1;

    $insertStmt = $pdo->prepare("INSERT INTO player_action_shots (player_id, filename, sort_order) VALUES (:pid, :filename, :sort_order)");

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = 'One of the photos failed to upload (error code ' . (int)$error . ').';
            continue;
        }

        $tmpName = $files['tmp_name'][$i];
        $size = (int)($files['size'][$i] ?? 0);

        if (!is_uploaded_file($tmpName)) {
            $errors[] = 'Invalid image upload.';
            continue;
        }
        if ($size > 5 * 1024 * 1024) {
            $errors[] = 'Each photo must be 5MB or smaller.';
            continue;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmpName) : null;
        if ($finfo) finfo_close($finfo);

        if (!isset($allowed[$mime ?? ''])) {
            $errors[] = 'Unsupported image format. Please use PNG, JPG, GIF or WebP.';
            continue;
        }

        try {
            $random = bin2hex(random_bytes(8));
        } catch (Exception $e) {
            $errors[] = 'Failed to prepare filename for image upload.';
            continue;
        }

        $filename = 'action_' . $id . '_' . $random . '.' . $allowed[$mime];
        $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            $errors[] = 'Failed to store one of the uploaded photos.';
            continue;
        }

        $insertStmt->execute([
            ':pid' => $id,
            ':filename' => $filename,
            ':sort_order' => $nextOrder++,
        ]);
        $savedCount++;
    }
} else {
    $errors[] = 'No photos were selected.';
}

if ($savedCount > 0) {
    auditLog($pdo, 'player_action_shot_uploaded', "Uploaded {$savedCount} action shot(s) for player #{$id}");
}

if ($errors) {
    $errorMsg = urlencode(trim(implode(' ', array_map('strip_tags', $errors))));
    header('Location: ' . $returnPage . '?id=' . $id . '&action_shot_error=' . $errorMsg . ($savedCount > 0 ? '&action_shot_success=' . $savedCount : ''));
    exit;
}

header('Location: ' . $returnPage . '?id=' . $id . '&action_shot_success=' . $savedCount);
exit;
