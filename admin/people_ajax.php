<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// people_ajax.php — CRUD for tagged_people (manager/staff/fan/other — not players,
// which stay managed from the Players pages and are only ever read here).
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/tagged_people.php';

header('Content-Type: application/json');

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

if (!csrf_check()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

tagged_people_ensure_schema($pdo);

$action = $_POST['action'] ?? '';
$response = ['success' => false];
$nonPlayerCategories = array_diff(array_keys(TAGGED_PEOPLE_CATEGORIES), ['player']);

try {
    if ($action === 'create_person') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = $_POST['category'] ?? 'other';

        if ($name === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if (!in_array($category, $nonPlayerCategories, true)) {
            throw new InvalidArgumentException('Choose a valid category.');
        }

        $stmt = $pdo->prepare('INSERT INTO tagged_people (name, category) VALUES (:name, :category)');
        $stmt->execute([':name' => $name, ':category' => $category]);

        $response['success'] = true;
        $response['id'] = (int) $pdo->lastInsertId();
        $response['name'] = $name;
        $response['category'] = $category;
    } elseif ($action === 'update_person') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = $_POST['category'] ?? '';

        if ($id <= 0 || $name === '') {
            throw new InvalidArgumentException('Name is required.');
        }
        if (!in_array($category, $nonPlayerCategories, true)) {
            throw new InvalidArgumentException('Choose a valid category.');
        }

        $stmt = $pdo->prepare("SELECT category FROM tagged_people WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            throw new InvalidArgumentException('Person not found.');
        }
        if ($existing['category'] === 'player') {
            throw new InvalidArgumentException('Players are managed from the Players page.');
        }

        $update = $pdo->prepare('UPDATE tagged_people SET name = :name, category = :category WHERE id = :id');
        $update->execute([':name' => $name, ':category' => $category, ':id' => $id]);
        $response['success'] = true;
    } else {
        throw new InvalidArgumentException('Unknown action.');
    }
} catch (InvalidArgumentException $e) {
    $response['error'] = $e->getMessage();
} catch (Throwable $e) {
    $response['error'] = 'Something went wrong. Please try again.';
}

echo json_encode($response);
