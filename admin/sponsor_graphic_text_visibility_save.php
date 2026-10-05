<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// ==========================================
// sponsor_graphic_text_visibility_save.php — persists whether a sponsor's
// name, address or contact number text is shown on the sponsor graphic
// (see lib/player_graphic_html.php). Stored against the sponsor, not the
// player/slot, so it follows the sponsor if reassigned.
// ==========================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/player_graphic_html.php';
header('Content-Type: application/json');

try {
  if (!hub_auth_is_authenticated()) {
    throw new Exception('Authentication required.');
  }
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new Exception('Invalid request method.');
  }
  if (!csrf_check()) {
    throw new Exception('Your session expired. Please reload and try again.');
  }

  $sponsorId = isset($_POST['sponsor_id']) ? (int)$_POST['sponsor_id'] : 0;
  if ($sponsorId <= 0) {
    throw new Exception('Missing or invalid sponsor_id.');
  }

  $columns = [
    'name' => 'graphic_name_hidden',
    'address' => 'graphic_address_hidden',
    'contact' => 'graphic_contact_hidden',
  ];
  $field = isset($_POST['field']) && is_string($_POST['field']) ? $_POST['field'] : '';
  if (!isset($columns[$field])) {
    throw new Exception('Missing or invalid field.');
  }
  if (!isset($_POST['hidden'])) {
    throw new Exception('Missing hidden flag.');
  }
  $hidden = filter_var($_POST['hidden'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
  $column = $columns[$field];

  ensureSponsorGraphicLogoSizeColumn($pdo);

  $stmt = $pdo->prepare("UPDATE sponsors SET {$column} = :hidden WHERE id = :id");
  $stmt->execute([':hidden' => $hidden, ':id' => $sponsorId]);

  echo json_encode(['ok' => true, 'field' => $field, 'hidden' => (bool)$hidden]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
  exit;
}
