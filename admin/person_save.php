<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// person_save.php — Adds a new taggable person (manager/staff/fan/other).
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/tagged_people.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}

verify_csrf();
tagged_people_ensure_schema($pdo);

$name = trim((string) ($_POST['name'] ?? ''));
$category = $_POST['category'] ?? 'other';
$nonPlayerCategories = array_diff(array_keys(TAGGED_PEOPLE_CATEGORIES), ['player']);

if ($name === '' || !in_array($category, $nonPlayerCategories, true)) {
    $_SESSION['flash_toast'] = [
        'type' => 'danger',
        'message' => 'Enter a name and choose a category.',
    ];
    header('Location: /admin/people.php');
    exit;
}

$stmt = $pdo->prepare('INSERT INTO tagged_people (name, category) VALUES (:name, :category)');
$stmt->execute([':name' => $name, ':category' => $category]);

$_SESSION['flash_toast'] = [
    'type' => 'success',
    'message' => $name . ' added.',
];
header('Location: /admin/people.php');
exit;
