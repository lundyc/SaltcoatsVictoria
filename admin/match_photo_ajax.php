<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// match_photo_ajax.php — Inline tag management for match-day photos (add/remove a
// tag, or correct the kit) without leaving match_media.php.
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

$action = $_POST['action'] ?? '';
$response = ['success' => false];

try {
    if ($action === 'add_tag') {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $personId = (int) ($_POST['person_id'] ?? 0);

        if ($photoId <= 0 || $personId <= 0) {
            throw new InvalidArgumentException('Choose someone to tag.');
        }

        $personStmt = $pdo->prepare('SELECT id, name FROM tagged_people WHERE id = :id');
        $personStmt->execute([':id' => $personId]);
        $person = $personStmt->fetch(PDO::FETCH_ASSOC);
        if (!$person) {
            throw new InvalidArgumentException('Person not found.');
        }

        $photoStmt = $pdo->prepare('SELECT id FROM match_photos WHERE id = :id');
        $photoStmt->execute([':id' => $photoId]);
        if (!$photoStmt->fetch(PDO::FETCH_ASSOC)) {
            throw new InvalidArgumentException('Photo not found.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO match_photo_tags (match_photo_id, tagged_person_id, confidence, source)
            VALUES (:photo_id, :person_id, NULL, 'manual')
            ON DUPLICATE KEY UPDATE source = source
        ");
        $stmt->execute([':photo_id' => $photoId, ':person_id' => $personId]);

        $tagIdStmt = $pdo->prepare('SELECT id FROM match_photo_tags WHERE match_photo_id = :photo_id AND tagged_person_id = :person_id');
        $tagIdStmt->execute([':photo_id' => $photoId, ':person_id' => $personId]);

        $response['success'] = true;
        $response['tag_id'] = (int) $tagIdStmt->fetchColumn();
        $response['person_name'] = (string) $person['name'];
    } elseif ($action === 'create_and_tag') {
        tagged_people_ensure_schema($pdo);
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = $_POST['category'] ?? 'other';
        $nonPlayerCategories = array_diff(array_keys(TAGGED_PEOPLE_CATEGORIES), ['player']);

        if ($photoId <= 0 || $name === '') {
            throw new InvalidArgumentException('Enter a name.');
        }
        if (!in_array($category, $nonPlayerCategories, true)) {
            throw new InvalidArgumentException('Choose a valid category.');
        }

        $photoStmt = $pdo->prepare('SELECT id FROM match_photos WHERE id = :id');
        $photoStmt->execute([':id' => $photoId]);
        if (!$photoStmt->fetch(PDO::FETCH_ASSOC)) {
            throw new InvalidArgumentException('Photo not found.');
        }

        $createStmt = $pdo->prepare('INSERT INTO tagged_people (name, category) VALUES (:name, :category)');
        $createStmt->execute([':name' => $name, ':category' => $category]);
        $personId = (int) $pdo->lastInsertId();

        $tagStmt = $pdo->prepare("INSERT INTO match_photo_tags (match_photo_id, tagged_person_id, confidence, source) VALUES (:photo_id, :person_id, NULL, 'manual')");
        $tagStmt->execute([':photo_id' => $photoId, ':person_id' => $personId]);

        $response['success'] = true;
        $response['person_id'] = $personId;
        $response['tag_id'] = (int) $pdo->lastInsertId();
        $response['person_name'] = $name;
        $response['category'] = $category;
    } elseif ($action === 'remove_tag') {
        $tagId = (int) ($_POST['tag_id'] ?? 0);
        if ($tagId <= 0) {
            throw new InvalidArgumentException('Invalid tag.');
        }
        $stmt = $pdo->prepare('DELETE FROM match_photo_tags WHERE id = :id');
        $stmt->execute([':id' => $tagId]);
        $response['success'] = true;
    } elseif ($action === 'set_kit') {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $kit = $_POST['kit'] ?? '';
        if (!in_array($kit, ['home', 'away', 'third'], true)) {
            $kit = null;
        }
        if ($photoId <= 0) {
            throw new InvalidArgumentException('Invalid photo.');
        }
        $stmt = $pdo->prepare('UPDATE match_photos SET kit = :kit WHERE id = :id');
        $stmt->execute([':kit' => $kit, ':id' => $photoId]);
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
