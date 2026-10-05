<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
header('Content-Type: application/json');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new Exception('Invalid request method.');
  }

  $sourcePlayerId = isset($_POST['source_player_id']) ? (int)$_POST['source_player_id'] : 0;
  $targetPlayerId = isset($_POST['target_player_id']) ? (int)$_POST['target_player_id'] : 0;
  if ($sourcePlayerId <= 0 || $targetPlayerId <= 0) {
    throw new Exception('Missing source or target player.');
  }
  if ($sourcePlayerId === $targetPlayerId) {
    throw new Exception('Source and target players must be different.');
  }

  ensurePlayerGraphicLayoutSchema($pdo);

  $source = $pdo->prepare("SELECT * FROM player_graphic_layouts WHERE player_id = :pid LIMIT 1");
  $source->execute([':pid' => $sourcePlayerId]);
  $row = $source->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    throw new Exception('Source layout not found.');
  }

  $columns = [];
  $stmt = $pdo->query("SHOW COLUMNS FROM player_graphic_layouts");
  foreach ($stmt as $col) {
    $columns[] = $col['Field'];
  }

  $data = [];
  foreach ($columns as $column) {
    if ($column === 'id' || $column === 'player_id' || $column === 'updated_at') {
      continue;
    }
    if (array_key_exists($column, $row)) {
      $data[$column] = $row[$column];
    }
  }

  $check = $pdo->prepare("SELECT id FROM player_graphic_layouts WHERE player_id = :pid LIMIT 1");
  $check->execute([':pid' => $targetPlayerId]);
  $exists = $check->fetchColumn();

  if ($exists) {
    $sets = [];
    foreach ($data as $key => $value) {
      $sets[] = "`$key` = :$key";
    }
    $sql = "UPDATE player_graphic_layouts SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE player_id = :pid LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $data['pid'] = $targetPlayerId;
    $stmt->execute($data);
  } else {
    $cols = array_keys($data);
    $cols[] = 'player_id';
    $placeholders = array_map(fn($k) => ":$k", $cols);
    $sql = "INSERT INTO player_graphic_layouts (" . implode(',', $cols) . ", updated_at) VALUES (" . implode(',', $placeholders) . ", NOW())";
    $stmt = $pdo->prepare($sql);
    $data['player_id'] = $targetPlayerId;
    $stmt->execute($data);
  }

  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}
