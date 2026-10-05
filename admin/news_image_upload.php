<?php

declare(strict_types=1);

/**
 * Image upload endpoint for the news editor (hero image + inline EasyMDE
 * images). Returns JSON: {"url": "/uploads/news/YYYY/MM/xxxx.jpg"} or
 * {"error": "..."}.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/news.php';

header('Content-Type: application/json; charset=utf-8');

if (!hub_auth_is_authenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not signed in.']);
    exit;
}
if (!csrf_check()) {
    http_response_code(400);
    echo json_encode(['error' => 'Session expired — reload the page.']);
    exit;
}

$action = (string) ($_POST['action'] ?? '');

/* Media Library catalogue for the news-editor picker (gallery + hero). */
if ($action === 'catalogue') {
    require_once __DIR__ . '/lib/media_library.php';
    $items = [];
    foreach (hub_media_catalogue() as $m) {
        if (str_contains(strtolower((string) $m['path']), '/thumb/')) {
            continue;
        }
        $items[] = [
            'path' => $m['path'],
            'url' => $m['url'],
            'name' => $m['name'],
            'category' => $m['category'],
        ];
    }
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES);
    exit;
}

/* Reference a chosen Media Library image (no copy) — used by the hero picker. */
if ($action === 'copy_from_library') {
    $res = news_library_image_ref((string) ($_POST['source'] ?? ''));
    if (!$res['ok']) {
        http_response_code(422);
        echo json_encode(['error' => $res['error']]);
        exit;
    }
    echo json_encode(['url' => $res['url'], 'path' => $res['path']]);
    exit;
}

$file = $_FILES['image'] ?? null;
if (!is_array($file)) {
    http_response_code(400);
    echo json_encode(['error' => 'No image received.']);
    exit;
}

$result = news_store_upload($file);
if (!$result['ok']) {
    http_response_code(422);
    echo json_encode(['error' => $result['error']]);
    exit;
}

echo json_encode(['url' => $result['url'], 'path' => $result['path']]);
