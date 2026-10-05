<?php
declare(strict_types=1);

/**
 * MP4 export of an opponent scouting report (stats.php?tab=opposition).
 *
 * Rendering takes about a minute, so it runs as a background job:
 *   POST action=start    file=x.json  -> starts lib/opposition_pptx/video_job.py, JSON {ok}
 *   GET  action=status   file=x.json  -> JSON {state: none|running|done|error, step?, message?}
 *   GET  action=download file=x.json  -> the finished .mp4
 * Output lives in data/opposition_videos/ (not web-served).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/opposition_report.php';

const HUB_OPP_VIDEO_STALE_SECONDS = 900;

$json = static function (array $body, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($body);
    exit;
};

if (!hub_auth_is_authenticated()) {
    $json(['ok' => false, 'message' => 'Your session has expired — please reload the page and log in again.'], 401);
}
if (function_exists('hub_auth_has_capability') && !hub_auth_has_capability('matchday')) {
    $json(['ok' => false, 'message' => 'Access denied.'], 403);
}

$action = (string)($_REQUEST['action'] ?? '');
$path = hub_opposition_report_resolve((string)($_REQUEST['file'] ?? ''));
if ($path === null) {
    $json(['ok' => false, 'message' => 'That report could not be found.'], 404);
}

$dir = __DIR__ . '/data/opposition_videos';
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    $json(['ok' => false, 'message' => 'Could not create the video folder.'], 500);
}
$name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($path, '.json')) ?: md5($path);
$mp4 = $dir . '/' . $name . '.mp4';
$statusFile = $dir . '/' . $name . '.status.json';

$readStatus = static function () use ($statusFile, $mp4): array {
    $s = is_file($statusFile) ? json_decode((string)file_get_contents($statusFile), true) : null;
    if (!is_array($s) || !isset($s['state'])) {
        return ['state' => 'none'];
    }
    if ($s['state'] === 'running' && time() - (int)($s['started'] ?? 0) > HUB_OPP_VIDEO_STALE_SECONDS) {
        return ['state' => 'error', 'message' => 'The video took too long and was abandoned. Please try again.'];
    }
    if ($s['state'] === 'done' && !is_file($mp4)) {
        return ['state' => 'none'];
    }
    return $s;
};

if ($action === 'status') {
    $json(['ok' => true] + $readStatus());
}

if ($action === 'download') {
    if (($readStatus()['state'] ?? '') !== 'done' || !is_file($mp4)) {
        http_response_code(404);
        exit('No video has been created for that report yet.');
    }
    $team = 'Opposition';
    $data = json_decode((string)file_get_contents($path), true);
    if (is_array($data) && trim((string)($data['team'] ?? '')) !== '') {
        $team = trim((string)$data['team']);
    }
    $label = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $team) ?: 'Opposition', '_');
    header('Content-Type: video/mp4');
    header('Content-Disposition: attachment; filename="' . $label . '_Opposition_Report.mp4"');
    header('Content-Length: ' . filesize($mp4));
    header('Cache-Control: private, no-store');
    readfile($mp4);
    exit;
}

if ($action === 'start') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
        $json(['ok' => false, 'message' => 'Your session has expired — please reload the page and try again.'], 400);
    }
    if (($readStatus()['state'] ?? '') === 'running') {
        $json(['ok' => true, 'already' => true]);
    }
    $python = trim((string)shell_exec('command -v python3 2>/dev/null'));
    $template = __DIR__ . '/data/opposition_template.pptx';
    if ($python === '' || !is_file($template)) {
        $json(['ok' => false, 'message' => 'Video export is unavailable.'], 500);
    }
    $league = __DIR__ . '/cache/wosfl_table.json';
    file_put_contents($statusFile, json_encode(['state' => 'running', 'started' => time(), 'step' => 'Starting']));
    $cmd = 'nohup ' . escapeshellarg($python) . ' ' . escapeshellarg(__DIR__ . '/lib/opposition_pptx/video_job.py')
        . ' --json ' . escapeshellarg($path)
        . ' --template ' . escapeshellarg($template)
        . (is_file($league) ? ' --league ' . escapeshellarg($league) : '')
        . ' --mp4 ' . escapeshellarg($mp4)
        . ' --status ' . escapeshellarg($statusFile)
        . ' > /dev/null 2>&1 &';
    exec($cmd);
    $json(['ok' => true]);
}

$json(['ok' => false, 'message' => 'Unknown action.'], 400);
