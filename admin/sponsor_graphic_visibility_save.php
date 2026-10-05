<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// ==========================================
// sponsor_graphic_visibility_save.php — persists whether a sponsor's logo
// image is shown on the sponsor graphic (see lib/player_graphic_html.php).
// Stored against the sponsor, not the player/slot, so it follows the
// sponsor if reassigned. Lets sponsors who are individuals rather than a
// business have just their name shown, with no logo image.
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
  if (!isset($_POST['hidden'])) {
    throw new Exception('Missing hidden flag.');
  }
  $hidden = filter_var($_POST['hidden'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;

  ensureSponsorGraphicLogoSizeColumn($pdo);

  $stmt = $pdo->prepare('UPDATE sponsors SET graphic_logo_hidden = :hidden WHERE id = :id');
  $stmt->execute([':hidden' => $hidden, ':id' => $sponsorId]);

  echo json_encode(['ok' => true, 'hidden' => (bool)$hidden]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}
