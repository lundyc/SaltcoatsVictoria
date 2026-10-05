<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// ==========================================
// save_graphic_settings.php
// ==========================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
header('Content-Type: application/json');

try {
  // -------------------------------------------------------------------------
  // Validate input
  // -------------------------------------------------------------------------
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new Exception('Invalid request method.');
  }

  if (!csrf_check()) {
    throw new Exception('Your session expired. Please refresh the page and try again.');
  }

  $playerId = isset($_POST['player_id']) ? (int)$_POST['player_id'] : 0;
  if ($playerId <= 0) {
    throw new Exception('Missing or invalid player_id.');
  }

  ensurePlayerGraphicLayoutSchema($pdo);

  // -------------------------------------------------------------------------
  // Extract expected fields (we ignore extras gracefully)
  // -------------------------------------------------------------------------
  $fields = [
    'player_x',
    'player_y',
    'player_scale',
    'home_x',
    'home_y',
    'home_width',
    'home_height',
    'home_z',
    'home_locked',
    'away_x',
    'away_y',
    'away_width',
    'away_height',
    'away_z',
    'away_locked',
    'same_x',
    'same_y',
    'same_width',
    'same_height',
    'same_z',
    'same_locked',
    'third_x',
    'third_y',
    'third_width',
    'third_height',
    'third_z',
    'third_locked',
    'template_pack_id'
  ];

  $data = [];
  foreach ($fields as $f) {
    if (!isset($_POST[$f])) {
      continue;
    }
    if ($f === 'template_pack_id') {
      $data[$f] = max(0, (int) $_POST[$f]) ?: null;
    } elseif (str_ends_with($f, '_locked') || str_ends_with($f, '_z')) {
      $data[$f] = (int)$_POST[$f];
    } else {
      $data[$f] = floatval($_POST[$f]);
    }
  }

  // -------------------------------------------------------------------------
  // Check if record exists
  // -------------------------------------------------------------------------
  $check = $pdo->prepare("SELECT id FROM player_graphic_layouts WHERE player_id = :pid LIMIT 1");
  $check->execute([':pid' => $playerId]);
  $exists = $check->fetchColumn();

  // -------------------------------------------------------------------------
  // Insert or Update
  // -------------------------------------------------------------------------
  if ($exists) {
    // Build dynamic update set
    $sets = [];
    foreach ($data as $key => $val) {
      $sets[] = "`$key` = :$key";
    }
    $sql = "UPDATE player_graphic_layouts SET " . implode(', ', $sets) . ", updated_at = NOW() WHERE player_id = :pid LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $data['pid'] = $playerId;
    $stmt->execute($data);
  } else {
    $cols = array_keys($data);
    $cols[] = 'player_id';
    $placeholders = array_map(fn($k) => ":$k", $cols);
    $sql = "INSERT INTO player_graphic_layouts (" . implode(',', $cols) . ", updated_at) VALUES (" . implode(',', $placeholders) . ", NOW())";
    $stmt = $pdo->prepare($sql);
    $data['player_id'] = $playerId;
    $stmt->execute($data);
  }

  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}
