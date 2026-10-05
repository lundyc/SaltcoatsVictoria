<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// person_photo_delete.php — Removes a single reference photo from a tagged person.
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

$photoId = (int) ($_POST['photo_id'] ?? 0);
if ($photoId <= 0) {
    echo '<div class="alert alert-danger">Invalid photo ID.</div>';
    exit;
}

$stmt = $pdo->prepare('SELECT id, filename FROM tagged_people_photos WHERE id = :id');
$stmt->execute([':id' => $photoId]);
$photo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$photo) {
    echo '<div class="alert alert-danger">Photo not found.</div>';
    exit;
}

$filename = basename((string) $photo['filename']);
$path = __DIR__ . '/uploads/tagged_people/' . $filename;

$deleteStmt = $pdo->prepare('DELETE FROM tagged_people_photos WHERE id = :id');
$deleteStmt->execute([':id' => $photoId]);

if (is_file($path)) {
    @unlink($path);
}

header('Location: people.php?photo_deleted=1');
exit;
