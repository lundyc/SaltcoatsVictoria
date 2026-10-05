<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/config.php';
require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/member_auth.php';
require_once __DIR__ . '/../admin/lib/feedback.php';
ensureFeedbackSchema($pdo);

header('Content-Type: application/json; charset=utf-8');

if (!member_auth_is_authenticated()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Please log in first.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !member_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid request.']);
    exit;
}

$rating = (int) ($_POST['rating'] ?? 0);
$comment = trim((string) ($_POST['comment'] ?? ''));
$page = trim((string) ($_POST['page'] ?? ''));

if ($rating < 1 || $rating > 5) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Choose a star rating first.']);
    exit;
}

$personId = member_auth_current_person_id() ?? 0;
$holderId = member_auth_current_legacy_holder_id() ?? 0;
saveFeedback($pdo, $personId, $rating, $comment, $page, $holderId);

echo json_encode(['ok' => true]);
