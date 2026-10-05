<?php
declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/players_lib.php';
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
$position = players_normalize_position((string) ($_POST['position'] ?? ''));
$allowedPositions = array_keys(players_position_options());

if ($playerId <= 0 || !in_array($position, $allowedPositions, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid position.']);
    exit;
}

$positionColumn = $pdo->query("SHOW COLUMNS FROM players LIKE 'position'")->fetch(PDO::FETCH_ASSOC);
if (!$positionColumn) {
    $pdo->exec("ALTER TABLE players ADD COLUMN position VARCHAR(80) NULL AFTER name");
}

$playerStmt = $pdo->prepare('SELECT id FROM players WHERE id = :id LIMIT 1');
$playerStmt->execute([':id' => $playerId]);
if (!$playerStmt->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Player not found.']);
    exit;
}

$saveStmt = $pdo->prepare('UPDATE players SET position = :position WHERE id = :id');
$saveStmt->execute([':position' => $position, ':id' => $playerId]);

auditLog($pdo, 'player_position_updated', "Set position '{$position}' for player #{$playerId}");

echo json_encode(['success' => true, 'position' => $position, 'label' => players_position_label($position)]);
