<?php
declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: application/json');

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Login required.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_check()) {
    http_response_code(419);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

$playerId = (int) ($_POST['player_id'] ?? 0);
$status = trim((string) ($_POST['status'] ?? ''));
if ($playerId <= 0 || !in_array($status, ['accepted', 'rejected'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid review status.']);
    exit;
}

$playerStmt = $pdo->prepare('SELECT id FROM players WHERE id = :id LIMIT 1');
$playerStmt->execute([':id' => $playerId]);
if (!$playerStmt->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Player not found.']);
    exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS player_photo_reviews (
  player_id INT UNSIGNED NOT NULL,
  status ENUM('accepted','rejected') NOT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id),
  CONSTRAINT fk_player_photo_reviews_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$currentUser = hub_auth_current_user();
$reviewerId = isset($currentUser['id']) ? (int) $currentUser['id'] : null;

$saveStmt = $pdo->prepare('INSERT INTO player_photo_reviews (player_id, status, reviewed_by)
  VALUES (:player_id, :status, :reviewed_by)
  ON DUPLICATE KEY UPDATE status = VALUES(status), reviewed_by = VALUES(reviewed_by), reviewed_at = CURRENT_TIMESTAMP');
$saveStmt->execute([
    ':player_id' => $playerId,
    ':status' => $status,
    ':reviewed_by' => $reviewerId,
]);

auditLog($pdo, 'player_photo_review_updated', "Set photo review status '{$status}' for player #{$playerId}");

echo json_encode(['success' => true, 'status' => $status]);
