<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// match_photo_delete.php — Removes a single match-day photo (and its tags via FK cascade).
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

function match_photo_delete_fail(string $message, int $status, bool $isAjax): never
{
    http_response_code($status);
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message]);
    } else {
        echo '<div class="alert alert-danger">' . h($message) . '</div>';
    }
    exit;
}

if (!hub_auth_is_authenticated()) {
    match_photo_delete_fail('Authentication required.', 401, $isAjax);
}

if (!csrf_check()) {
    match_photo_delete_fail('The security token is invalid. Refresh and try again.', 400, $isAjax);
}

$photoId = (int) ($_POST['photo_id'] ?? 0);
if ($photoId <= 0) {
    match_photo_delete_fail('Invalid photo ID.', 422, $isAjax);
}

$stmt = $pdo->prepare('SELECT id, match_fixture_id, filename FROM match_photos WHERE id = :id');
$stmt->execute([':id' => $photoId]);
$photo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$photo) {
    match_photo_delete_fail('Photo not found.', 404, $isAjax);
}

$fixtureId = (int) $photo['match_fixture_id'];
$seasonId = (int) ($_POST['season_id'] ?? 0);
$path = __DIR__ . '/uploads/matches/gallery/' . $fixtureId . '/' . basename((string) $photo['filename']);

$deleteStmt = $pdo->prepare('DELETE FROM match_photos WHERE id = :id');
$deleteStmt->execute([':id' => $photoId]);

if (is_file($path)) {
    @unlink($path);
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'fixture_id' => $fixtureId, 'season_id' => $seasonId]);
    exit;
}

header('Location: /admin/match_media.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&photo_deleted=1');
exit;
