<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// person_delete.php — Removes a tagged person (manager/staff/fan/other) along with
// their reference photos and any match-photo tags (both cascade via FK).
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}

verify_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /admin/people.php');
    exit;
}

$stmt = $pdo->prepare('SELECT category FROM tagged_people WHERE id = :id');
$stmt->execute([':id' => $id]);
$person = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$person) {
    header('Location: /admin/people.php');
    exit;
}
if ($person['category'] === 'player') {
    $_SESSION['flash_toast'] = [
        'type' => 'danger',
        'message' => 'Players are managed from the Players page.',
    ];
    header('Location: /admin/people.php');
    exit;
}

$photoStmt = $pdo->prepare('SELECT filename FROM tagged_people_photos WHERE tagged_person_id = :id');
$photoStmt->execute([':id' => $id]);
$filenames = $photoStmt->fetchAll(PDO::FETCH_COLUMN);

$delete = $pdo->prepare('DELETE FROM tagged_people WHERE id = :id');
$delete->execute([':id' => $id]);

foreach ($filenames as $filename) {
    $path = __DIR__ . '/uploads/tagged_people/' . basename((string) $filename);
    if (is_file($path)) {
        @unlink($path);
    }
}

$_SESSION['flash_toast'] = [
    'type' => 'success',
    'message' => 'Person removed.',
];
header('Location: /admin/people.php');
exit;
