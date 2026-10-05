<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// match_photo_upload.php — Uploads match-day photos against a fixture, then auto-tags
// the players face-matching finds in them against the squad's reference photos.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/face_match.php';
require_once __DIR__ . '/lib/tagged_people.php';

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    echo '<div class="alert alert-danger">Authentication required.</div>';
    exit;
}

verify_csrf();

// Large batches (many photos, each face-detected against the whole reference set)
// can comfortably exceed the default 30s execution time — this endpoint is meant to
// be called with small chunks from match_media.js, but give it headroom regardless.
@set_time_limit(120);

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

$fixtureId = (int) ($_POST['fixture_id'] ?? 0);
$seasonId = (int) ($_POST['season_id'] ?? 0);
$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;

if (!$fixture) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Fixture not found.']);
        exit;
    }
    echo '<div class="alert alert-danger">Fixture not found.</div>';
    exit;
}

$kit = $_POST['kit'] ?? '';
if (!in_array($kit, ['home', 'away', 'third'], true)) {
    $kit = null;
}

$uploadDir = __DIR__ . '/uploads/matches/gallery/' . $fixtureId;
$playersUploadDir = __DIR__ . '/uploads/players';
$peopleUploadDir = __DIR__ . '/uploads/tagged_people';
$matchesUploadDir = __DIR__ . '/uploads/matches/gallery';
$allowedMimes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

$errors = [];
$storedPhotos = []; // [id => absolute path]

$files = $_FILES['photos'] ?? null;
if (!$files || !is_array($files['name'] ?? null) || count(array_filter($files['name'])) === 0) {
    $errors[] = 'Choose at least one photo to upload.';
} elseif (!is_dir($uploadDir) && !(mkdir($uploadDir, 0775, true) && is_dir($uploadDir))) {
    $errors[] = 'Failed to prepare upload directory.';
} else {
    $insertStmt = $pdo->prepare('INSERT INTO match_photos (match_fixture_id, filename, kit) VALUES (:fixture_id, :filename, :kit)');

    $count = count($files['name']);
    for ($i = 0; $i < $count; $i++) {
        $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = htmlspecialchars((string) $files['name'][$i]) . ' failed to upload.';
            continue;
        }

        $tmpName = $files['tmp_name'][$i];
        $size = (int) ($files['size'][$i] ?? 0);
        $originalName = (string) $files['name'][$i];

        if (!is_uploaded_file($tmpName)) {
            $errors[] = htmlspecialchars($originalName) . ' is not a valid upload.';
            continue;
        }
        if ($size > 8 * 1024 * 1024) {
            $errors[] = htmlspecialchars($originalName) . ' is larger than 8MB.';
            continue;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $tmpName) : null;
        if ($finfo) finfo_close($finfo);

        if (!isset($allowedMimes[$mime ?? ''])) {
            $errors[] = htmlspecialchars($originalName) . ' is not a supported image format.';
            continue;
        }

        try {
            $random = bin2hex(random_bytes(8));
        } catch (Exception $e) {
            $errors[] = 'Failed to prepare filename for ' . htmlspecialchars($originalName) . '.';
            continue;
        }

        $filename = 'photo_' . $random . '.' . $allowedMimes[$mime];
        $destination = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($tmpName, $destination)) {
            $errors[] = 'Failed to store ' . htmlspecialchars($originalName) . '.';
            continue;
        }

        $insertStmt->execute([
            ':fixture_id' => $fixtureId,
            ':filename' => $filename,
            ':kit' => $kit,
        ]);
        $storedPhotos[(int) $pdo->lastInsertId()] = $destination;
    }
}

$taggedCount = 0;
if ($storedPhotos) {
    $references = tagged_people_build_references($pdo, $playersUploadDir, $peopleUploadDir, $matchesUploadDir);
    $tagsByPhotoId = face_match_get_tags($references, $storedPhotos);

    if ($tagsByPhotoId) {
        $tagStmt = $pdo->prepare("
            INSERT INTO match_photo_tags (match_photo_id, tagged_person_id, confidence, source)
            VALUES (:photo_id, :person_id, :confidence, 'auto')
            ON DUPLICATE KEY UPDATE confidence = GREATEST(confidence, VALUES(confidence))
        ");

        foreach ($tagsByPhotoId as $photoId => $tags) {
            foreach ($tags as $tag) {
                if ($tag['score'] < FACE_MATCH_LIKELY_THRESHOLD) {
                    continue;
                }
                $tagStmt->execute([
                    ':photo_id' => $photoId,
                    ':person_id' => $tag['person_id'],
                    ':confidence' => $tag['score'],
                ]);
                $taggedCount++;
            }
        }
    }
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'uploaded' => count($storedPhotos),
        'tagged' => $taggedCount,
        'errors' => array_map('strip_tags', $errors),
    ]);
    exit;
}

$_SESSION['flash_toast'] = [
    'type' => $errors ? 'warning' : 'success',
    'message' => count($storedPhotos) . ' photo' . (count($storedPhotos) === 1 ? '' : 's') . ' uploaded'
        . ($taggedCount > 0 ? ', ' . $taggedCount . ' tag' . ($taggedCount === 1 ? '' : 's') . ' suggested automatically' : '')
        . '.' . ($errors ? ' ' . implode(' ', array_map('strip_tags', $errors)) : ''),
];

header('Location: /admin/match_media.php?id=' . $fixtureId . '&season_id=' . $seasonId);
exit;
