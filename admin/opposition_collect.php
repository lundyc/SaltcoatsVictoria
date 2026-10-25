<?php
declare(strict_types=1);

/**
 * Queue / status endpoint for the server-side COMET collector, used by the
 * "Fetch from COMET" card on stats.php?tab=opposition.
 *
 * POST action=start&team=...  -> drops a request file in data/comet/queue/;
 *                                a root cron job (ops/comet-collector/run-queue.sh)
 *                                picks it up and runs collect.mjs.
 * GET  action=status          -> JSON progress of the current/last run.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/functions.php';

header('Content-Type: application/json');

const COMET_TEAMS = [
    'East Kilbride Thistle', 'Glenvale', 'East Kilbride YM', 'Royal Albert', 'Carluke Rovers',
    'West Park United', 'Kello Rovers', 'Glasgow Perthshire', 'Eglinton', 'Vale of Leven',
    'Lugar Boswell Thistle', 'Giffnock', 'Newmains United', 'Irvine Victoria', "St. Anthony's",
];

$dir = __DIR__ . '/data/comet';
$reply = static function (array $body, int $code = 200): never {
    http_response_code($code);
    echo json_encode($body);
    exit;
};

if (!hub_auth_is_authenticated()) {
    $reply(['ok' => false, 'message' => 'Your session has expired — please reload and log in again.'], 401);
}
if (function_exists('hub_auth_has_capability') && !hub_auth_has_capability('matchday')) {
    $reply(['ok' => false, 'message' => 'Access denied.'], 403);
}

$statusFile = $dir . '/status.json';
$queued = glob($dir . '/queue/*.req') ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $status = is_file($statusFile) ? json_decode((string)file_get_contents($statusFile), true) : null;
    $reply(['ok' => true, 'status' => is_array($status) ? $status : null, 'queued' => count($queued)]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) {
    $reply(['ok' => false, 'message' => 'Invalid request. Please reload the page.'], 400);
}

$team = trim((string)($_POST['team'] ?? ''));
if (!in_array($team, COMET_TEAMS, true)) {
    $reply(['ok' => false, 'message' => 'Please choose a team from the list.'], 422);
}
if ($queued) {
    $reply(['ok' => false, 'message' => 'A fetch is already waiting to start. Please wait a minute.'], 409);
}
$status = is_file($statusFile) ? json_decode((string)file_get_contents($statusFile), true) : null;
if (is_array($status) && in_array($status['phase'] ?? '', ['login', 'matches', 'collecting'], true)
    && (time() - (int)strtotime((string)($status['updated'] ?? ''))) < 600) {
    $reply(['ok' => false, 'message' => 'A fetch is already running. Please wait for it to finish.'], 409);
}

$file = $dir . '/queue/' . bin2hex(random_bytes(6)) . '.req';
file_put_contents($file, $team, LOCK_EX);
file_put_contents($statusFile, json_encode(['team' => $team, 'phase' => 'queued', 'updated' => gmdate('c')]));
$reply(['ok' => true, 'message' => 'Queued — it starts within a minute.']);
