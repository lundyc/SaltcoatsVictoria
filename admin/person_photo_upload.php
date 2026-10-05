<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// person_photo_upload.php: Handles reference-photo uploads for a tagged person
// (manager/staff/fan/other) — used as face-match candidates for match photo tagging.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';

header('Content-Type: text/html; charset=utf-8');

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    echo '<div class="alert alert-danger">Authentication required.</div>';
    exit;
}

verify_csrf();

$id = (int) ($_POST['person_id'] ?? 0);
if ($id <= 0) {
    echo '<div class="alert alert-danger">Invalid person ID.</div>';
    exit;
}

$stmt = $pdo->prepare("SELECT id, category FROM tagged_people WHERE id = :id");
$stmt->execute([':id' => $id]);
$person = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$person) {
    echo '<div class="alert alert-danger">Person not found.</div>';
    exit;
}
if ($person['category'] === 'player') {
    echo '<div class="alert alert-danger">Players use their profile picture and action shots — manage those from the player\'s edit page.</div>';
    exit;
}

$uploadDir = __DIR__ . '/uploads/tagged_people';
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

$files = $_FILES['photos'] ?? null;
$errors = [];
$savedCount = 0;

if ($files && is_array($files['name'] ?? null)) {
    $insertStmt = $pdo->prepare('INSERT INTO tagged_people_photos (tagged_person_id, filename) VALUES (:pid, :filename)');

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = 'One of the photos failed to upload (error code ' . (int) $error . ').';
            continue;
        }

        $tmpName = $files['tmp_name'][$i];
        $size = (int) ($files['size'][$i] ?? 0);

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

        $filename = 'person_' . $id . '_' . $random . '.' . $allowed[$mime];
        $destination = $uploadDir . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            $errors[] = 'Failed to store one of the uploaded photos.';
            continue;
        }

        $insertStmt->execute([':pid' => $id, ':filename' => $filename]);
        $savedCount++;
    }
} else {
    $errors[] = 'No photos were selected.';
}

if ($errors) {
    $errorMsg = urlencode(trim(implode(' ', array_map('strip_tags', $errors))));
    header('Location: people.php?photo_error=' . $errorMsg . ($savedCount > 0 ? '&photo_success=' . $savedCount : ''));
    exit;
}

header('Location: people.php?photo_success=' . $savedCount);
exit;
