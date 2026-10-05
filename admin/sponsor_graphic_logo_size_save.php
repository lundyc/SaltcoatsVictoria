<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// ==========================================
// sponsor_graphic_logo_size_save.php — persists a sponsor's logo display
// size for the sponsor graphic (see lib/player_graphic_html.php). Stored
// against the sponsor, not the player/slot, so it follows the sponsor if
// reassigned.
// ==========================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/player_graphic_html.php';
header('Content-Type: application/json');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new Exception('Invalid request method.');
  }

  $sponsorId = isset($_POST['sponsor_id']) ? (int)$_POST['sponsor_id'] : 0;
  if ($sponsorId <= 0) {
    throw new Exception('Missing or invalid sponsor_id.');
  }
  if (!isset($_POST['size']) || !is_numeric($_POST['size'])) {
    throw new Exception('Missing or invalid size.');
  }
  $size = clampSponsorLogoSize((int)$_POST['size']);

  ensureSponsorGraphicLogoSizeColumn($pdo);

  $stmt = $pdo->prepare('UPDATE sponsors SET graphic_logo_size = :size WHERE id = :id');
  $stmt->execute([':size' => $size, ':id' => $sponsorId]);

  echo json_encode(['ok' => true, 'size' => $size]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}
