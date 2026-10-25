<?php

declare(strict_types=1);

// Club Shop — read-only list of Media Library images (JSON), for the image picker
// on Shop settings → Emails. Same catalogue the rest of the Hub uses.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('shop');
require_once __DIR__ . '/lib/media_library.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_check()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Your session expired — reload the page and try again.']);
    exit;
}

try {
    $items = array_map(static fn(array $m): array => [
        'path' => $m['path'], 'url' => $m['url'], 'name' => $m['name'], 'category' => $m['category'],
        'width' => $m['width'], 'height' => $m['height'],
    ], hub_media_catalogue());
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[shop media catalogue] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'message' => 'Could not load the media library.']);
}
