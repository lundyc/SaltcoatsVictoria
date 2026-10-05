<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// player_action_shot_delete.php: Removes a single action shot photo from a player.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: text/html; charset=utf-8');

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    echo '<div class="alert alert-danger">Authentication required.</div>';
    exit;
}

verify_csrf();

$shotId = (int)($_POST['shot_id'] ?? 0);
if ($shotId <= 0) {
    echo '<div class="alert alert-danger">Invalid photo ID.</div>';
    exit;
}

$stmt = $pdo->prepare("SELECT id, player_id, filename FROM player_action_shots WHERE id = :id");
$stmt->execute([':id' => $shotId]);
$shot = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$shot) {
    echo '<div class="alert alert-danger">Photo not found.</div>';
    exit;
}

$playerId = (int)$shot['player_id'];
$filename = basename((string)$shot['filename']);
$path = __DIR__ . '/uploads/players/action_shots/' . $filename;

$deleteStmt = $pdo->prepare("DELETE FROM player_action_shots WHERE id = :id");
$deleteStmt->execute([':id' => $shotId]);

auditLog($pdo, 'player_action_shot_deleted', "Deleted action shot for player #{$playerId}");

if (is_file($path)) {
    @unlink($path);
}

header('Location: player_edit.php?id=' . $playerId . '&action_shot_deleted=1');
exit;
