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
$uploaded = (int) ($_POST['uploaded'] ?? 0) === 1 ? 1 : 0;
if ($playerId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid player.']);
    exit;
}

$playerStmt = $pdo->prepare('SELECT id FROM players WHERE id = :id LIMIT 1');
$playerStmt->execute([':id' => $playerId]);
if (!$playerStmt->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Player not found.']);
    exit;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS player_website_photos (
  player_id INT UNSIGNED NOT NULL,
  uploaded_to_website TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id),
  CONSTRAINT fk_player_website_photos_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$saveStmt = $pdo->prepare('INSERT INTO player_website_photos (player_id, uploaded_to_website)
  VALUES (:player_id, :uploaded)
  ON DUPLICATE KEY UPDATE uploaded_to_website = VALUES(uploaded_to_website)');
$saveStmt->execute([
    ':player_id' => $playerId,
    ':uploaded' => $uploaded,
]);

auditLog($pdo, 'player_website_photo_updated', "Set website photo status (" . ($uploaded ? 'uploaded' : 'not uploaded') . ") for player #{$playerId}");

echo json_encode(['success' => true, 'uploaded' => $uploaded]);
