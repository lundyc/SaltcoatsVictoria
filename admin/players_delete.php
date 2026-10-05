<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sync_social_directory.php';
require_once __DIR__ . '/lib/audit.php';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id) {
          $nameStmt = $pdo->prepare("SELECT name FROM players WHERE id = :id");
          $nameStmt->execute([':id' => $id]);
          $playerName = (string) ($nameStmt->fetchColumn() ?: ('#' . $id));

          $st = $pdo->prepare("DELETE FROM players WHERE id = :id");
          $st->execute([':id' => $id]);
          if ($st->rowCount() > 0) {
                    auditLog($pdo, 'player_deleted', "Deleted player '{$playerName}'");
          }
          player_sponsors_sync_social_directory();
}
header('Location: players.php');
