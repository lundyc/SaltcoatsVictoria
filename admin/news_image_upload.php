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
$pdo = $hubDb['pdo'] ?? null;

/* Attachment details (alt / title / caption / description) for library images. */
function nbm_meta_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS hub_media_meta (
        path VARCHAR(255) NOT NULL PRIMARY KEY,
        alt_text VARCHAR(500) NOT NULL DEFAULT '',
        title VARCHAR(255) NOT NULL DEFAULT '',
        caption TEXT NULL,
        description TEXT NULL,
        updated_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function nbm_item(array $m, array $meta = []): array
{
    return [
        'path' => $m['path'],
        'url' => $m['url'],
        'name' => $m['name'],
        'filename' => $m['filename'] ?? basename((string) $m['path']),
        'category' => $m['category'],
        'width' => (int) ($m['width'] ?? 0),
        'height' => (int) ($m['height'] ?? 0),
        'mime' => (string) ($m['mime'] ?? ''),
        'size' => (int) ($m['size'] ?? 0),
        'modified' => (int) ($m['modified'] ?? 0),
        'alt' => (string) ($meta['alt_text'] ?? ''),
        'title' => (string) (($meta['title'] ?? '') !== '' ? $meta['title'] : $m['name']),
        'caption' => (string) ($meta['caption'] ?? ''),
        'description' => (string) ($meta['description'] ?? ''),
    ];
}

/* Media Library catalogue for the news-editor picker (WordPress-style media modal). */
if ($action === 'catalogue') {
    require_once __DIR__ . '/lib/media_library.php';
    nbm_meta_table($pdo);
    $metaRows = [];
    foreach ($pdo->query('SELECT path, alt_text, title, caption, description FROM hub_media_meta')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $metaRows[$r['path']] = $r;
    }
    $items = [];
    foreach (hub_media_catalogue() as $m) {
        if (str_contains(strtolower((string) $m['path']), '/thumb/')) {
            continue;
        }
        $items[] = nbm_item($m, $metaRows[$m['path']] ?? []);
    }
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* Upload straight into the Media Library (uploads/media), as WordPress does. */
if ($action === 'library_upload') {
    require_once __DIR__ . '/lib/media_library.php';
    $file = $_FILES['image'] ?? null;
    $valid = is_array($file) ? hub_media_validate_upload($file) : ['ok' => false, 'message' => 'No image received.'];
    if (!$valid['ok']) {
        http_response_code(422);
        echo json_encode(['error' => $valid['message']]);
        exit;
    }
    $directory = __DIR__ . '/' . hub_media_upload_targets()['library']['path'];
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        http_response_code(500);
        echo json_encode(['error' => 'The media folder could not be created.']);
        exit;
    }
    $stem = hub_media_safe_stem((string) ($file['name'] ?? 'image'));
    $destination = hub_media_unique_path($directory, $stem, $valid['extension']);
    if (!move_uploaded_file($valid['temp'], $destination)) {
        http_response_code(500);
        echo json_encode(['error' => 'The image could not be stored.']);
        exit;
    }
    @chmod($destination, 0664);
    $relative = hub_media_upload_targets()['library']['path'] . '/' . basename($destination);
    $info = @getimagesize($destination) ?: [0, 0, 'mime' => $valid['mime']];
    $item = nbm_item([
        'path' => $relative, 'url' => hub_media_web_url($relative), 'name' => pathinfo($destination, PATHINFO_FILENAME),
        'filename' => basename($destination), 'category' => hub_media_category($relative),
        'width' => $info[0] ?? 0, 'height' => $info[1] ?? 0, 'mime' => $info['mime'] ?? $valid['mime'],
        'size' => (int) @filesize($destination), 'modified' => time(),
    ]);
    echo json_encode(['ok' => true, 'item' => $item], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/* Save attachment details for a library image. */
if ($action === 'media_meta_save') {
    require_once __DIR__ . '/lib/media_library.php';
    $path = (string) ($_POST['path'] ?? '');
    if (hub_media_resolve($path) === null) {
        http_response_code(422);
        echo json_encode(['error' => 'That media item could not be found.']);
        exit;
    }
    nbm_meta_table($pdo);
    $st = $pdo->prepare('INSERT INTO hub_media_meta (path, alt_text, title, caption, description, updated_at)
        VALUES (:p, :a, :t, :c, :d, NOW())
        ON DUPLICATE KEY UPDATE alt_text = VALUES(alt_text), title = VALUES(title), caption = VALUES(caption), description = VALUES(description), updated_at = NOW()');
    $st->execute([
        ':p' => $path,
        ':a' => mb_substr(trim((string) ($_POST['alt'] ?? '')), 0, 500),
        ':t' => mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 255),
        ':c' => trim((string) ($_POST['caption'] ?? '')),
        ':d' => trim((string) ($_POST['description'] ?? '')),
    ]);
    echo json_encode(['ok' => true]);
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
