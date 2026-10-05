<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
// ==========================================
// sponsor_logo_select.php — assigns a sponsor's graphic logo, either from a
// freshly uploaded image or an existing media library image. Used by the
// "Add sponsor image" modal on player_graphics.php. Logos always end up
// living in uploads/sponsors/ (basename-only, matching how
// buildPlayerSponsorGraphic() resolves them) — a library pick from another
// category is copied in rather than referenced in place.
// ==========================================
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/media_library.php';

header('Content-Type: application/json');

function sponsor_logo_select_respond(bool $ok, array $data = [], int $status = 200): never
{
  http_response_code($status);
  echo json_encode(array_merge(['ok' => $ok], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

if (!hub_auth_is_authenticated()) {
  sponsor_logo_select_respond(false, ['error' => 'Authentication required.'], 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  sponsor_logo_select_respond(false, ['error' => 'Invalid request method.'], 405);
}

if (!csrf_check()) {
  sponsor_logo_select_respond(false, ['error' => 'Your session expired. Please reload and try again.'], 419);
}

$sponsorId = isset($_POST['sponsor_id']) ? (int)$_POST['sponsor_id'] : 0;
if ($sponsorId <= 0) {
  sponsor_logo_select_respond(false, ['error' => 'Missing or invalid sponsor_id.'], 422);
}

$stmt = $pdo->prepare('SELECT id, name, logo_path FROM sponsors WHERE id = :id');
$stmt->execute([':id' => $sponsorId]);
$sponsor = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sponsor) {
  sponsor_logo_select_respond(false, ['error' => 'Sponsor not found.'], 404);
}

$destinationDir = __DIR__ . '/uploads/sponsors';
if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
  sponsor_logo_select_respond(false, ['error' => 'Could not prepare the sponsors upload folder.'], 500);
}
$destinationDirReal = realpath($destinationDir) ?: $destinationDir;

$newFilename = null;

if (!empty($_FILES['image']['name'])) {
  $valid = hub_media_validate_upload($_FILES['image']);
  if (!$valid['ok']) {
    sponsor_logo_select_respond(false, ['error' => $valid['message']], 422);
  }
  $stem = hub_media_safe_stem($sponsor['name'] . '-' . bin2hex(random_bytes(4)));
  $destination = hub_media_unique_path($destinationDir, $stem, $valid['extension']);
  if (!move_uploaded_file($valid['temp'], $destination)) {
    sponsor_logo_select_respond(false, ['error' => 'Could not store the uploaded image.'], 500);
  }
  $newFilename = basename($destination);
} elseif (isset($_POST['media_path']) && is_string($_POST['media_path']) && trim($_POST['media_path']) !== '') {
  $sourcePath = hub_media_resolve($_POST['media_path'], true);
  if ($sourcePath === null) {
    sponsor_logo_select_respond(false, ['error' => 'The selected image could not be found.'], 404);
  }

  $alreadyInSponsorsFolder = str_replace('\\', '/', dirname($sourcePath)) === str_replace('\\', '/', $destinationDirReal);
  if ($alreadyInSponsorsFolder) {
    $newFilename = basename($sourcePath);
  } else {
    $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    $stem = hub_media_safe_stem($sponsor['name'] . '-' . bin2hex(random_bytes(4)));
    $destination = hub_media_unique_path($destinationDir, $stem, $extension);
    if (!copy($sourcePath, $destination)) {
      sponsor_logo_select_respond(false, ['error' => 'Could not copy the selected image.'], 500);
    }
    $newFilename = basename($destination);
  }
} else {
  sponsor_logo_select_respond(false, ['error' => 'Choose an image to upload or select from the library.'], 422);
}

$updateStmt = $pdo->prepare('UPDATE sponsors SET logo_path = :logo_path WHERE id = :id');
$updateStmt->execute([':logo_path' => $newFilename, ':id' => $sponsorId]);
auditLog($pdo, 'sponsor_logo_updated', "Updated logo for sponsor '{$sponsor['name']}' (#{$sponsorId})");

sponsor_logo_select_respond(true, [
  'filename' => $newFilename,
  'url' => '/uploads/sponsors/' . rawurlencode((string)$newFilename),
]);
