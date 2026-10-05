<?php
declare(strict_types=1);

/**
 * Upload / delete handler for opponent scouting JSON files, shown on
 * stats.php?tab=opposition. Files land in admin/data/opposition_reports/.
 *
 * The upload action speaks two ways: a plain form POST gets the classic
 * redirect-with-flash-message treatment (for JS-disabled fallback), while an
 * XMLHttpRequest (the drag-and-drop uploader on stats.php) gets a small JSON
 * body back instead, so the page can show its own progress/success/failure
 * state without a full reload.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/opposition_report.php';

$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

// AJAX failures are reported via the JSON body's "ok" flag rather than the
// HTTP status, so a routine validation failure (wrong file, too big, etc.)
// doesn't show up as a scary "Failed to load resource" in devtools — a 200
// carrying ok:false is a normal, expected response for this endpoint.
$fail = static function (string $errorKey) use ($isAjax): never {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => hub_opposition_report_error_messages()[$errorKey] ?? 'Something went wrong.']);
        exit;
    }
    header('Location: /admin/stats.php?tab=opposition&error=' . rawurlencode($errorKey));
    exit;
};

if (!hub_auth_is_authenticated()) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Your session has expired — please reload the page and log in again.']);
        exit;
    }
    header('Location: /admin/login.php');
    exit;
}
if (function_exists('hub_auth_has_capability') && !hub_auth_has_capability('matchday')) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Access denied.']);
    } else {
        http_response_code(403);
        echo 'Access denied.';
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/stats.php?tab=opposition');
    exit;
}
if (!csrf_check()) {
    $fail('csrf');
}

$action = (string)($_POST['action'] ?? 'upload');

if ($action === 'delete') {
    $file = basename((string)($_POST['file'] ?? ''));
    $path = $file !== '' ? hub_opposition_report_dir() . '/' . $file : '';
    if ($path !== '' && str_ends_with($file, '.json') && is_file($path)) {
        unlink($path);
        header('Location: /admin/stats.php?tab=opposition&deleted=1');
        exit;
    }
    header('Location: /admin/stats.php?tab=opposition&error=notfound');
    exit;
}

$upload = $_FILES['json_file'] ?? null;
if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $fail('nofile');
}
if ($upload['error'] !== UPLOAD_ERR_OK) {
    $fail('upload');
}
if ($upload['size'] > 10 * 1024 * 1024) {
    $fail('toobig');
}

$raw = file_get_contents($upload['tmp_name']);
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data) || !isset($data['matches']) || !is_array($data['matches'])) {
    $fail('invalid');
}

$team = trim((string)($data['team'] ?? ''));
$base = $team !== '' ? $team : pathinfo((string)$upload['name'], PATHINFO_FILENAME);
$slug = preg_replace('/[^A-Za-z0-9]+/', '-', $base);
$slug = trim((string)$slug, '-');
if ($slug === '') $slug = 'opponent';
$filename = $slug . '.json';

$dir = hub_opposition_report_dir();
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
if (!move_uploaded_file($upload['tmp_name'], $dir . '/' . $filename)) {
    $fail('save');
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => 'Report uploaded.', 'team' => $team]);
    exit;
}
header('Location: /admin/stats.php?tab=opposition&uploaded=1');
exit;
