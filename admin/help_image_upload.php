<?php
declare(strict_types=1);

/** Admin-only image upload for Help articles. POST file=<image>, csrf_token. Returns JSON {markdown}. */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/help.php';

header('Content-Type: application/json; charset=utf-8');

if (hub_auth_current_user() === null || !hub_auth_is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Only Site Admins can add help images.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hub_auth_verify_csrf_token($_POST['csrf_token'] ?? null)) {
    http_response_code(400);
    echo json_encode(['error' => 'Session expired. Reload the page and try again.']);
    exit;
}
$upload = $_FILES['file'] ?? null;
if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No image received (images must be under 2 MB).']);
    exit;
}
$info = @getimagesize((string) $upload['tmp_name']);
$ext = match ($info[2] ?? 0) { IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', default => '' };
if ($ext === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Use a PNG, JPG or WebP image.']);
    exit;
}
$dir = __DIR__ . '/storage/help_images';
$name = help_store_image((string) $upload['tmp_name'], $ext, $dir);
$alt = preg_replace('/[^A-Za-z0-9 ]+/', ' ', pathinfo((string) $upload['name'], PATHINFO_FILENAME));
echo json_encode(['ok' => true, 'file' => $name, 'markdown' => '![' . trim((string) $alt) . '](' . help_image_url($name) . ')']);
