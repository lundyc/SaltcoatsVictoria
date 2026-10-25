<?php
declare(strict_types=1);

/** Serves a Help Centre image to signed-in users only: ?f=<hash>.<png|jpg|webp> */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (hub_auth_current_user() === null) {
    http_response_code(401);
    exit;
}

$file = (string) ($_GET['f'] ?? '');
if (!preg_match('/^[a-f0-9]{8,40}\.(png|jpg|webp)$/', $file, $m)) {
    http_response_code(404);
    exit;
}
$path = __DIR__ . '/storage/help_images/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[$m[1]]);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
