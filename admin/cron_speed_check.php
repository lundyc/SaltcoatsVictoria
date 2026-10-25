<?php

declare(strict_types=1);

/**
 * Daily page-speed check. Loads key public pages in headless Chrome (see
 * speed_check.js) on a throttled phone profile and on desktop, and appends the
 * results to secrets/speed_history.json, which the Site Analytics tab reads.
 *
 * Command line only. Takes a few minutes, so it runs from cron, never from a
 * web request:  php cron_speed_check.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const SPEED_BASE = 'https://saltcoatsvictoria.co.uk';
const SPEED_PATHS = ['/', '/fixtures', '/results', '/team', '/news'];
const SPEED_RUNS_PER_PAGE = 3;
const SPEED_HISTORY_LIMIT = 90;
const SPEED_NODE = '/opt/myclubhub-node24/bin/node';

$historyFile = __DIR__ . '/../../secrets/speed_history.json';
$lockFile = fopen(sys_get_temp_dir() . '/sv_speed_check.lock', 'c');
if ($lockFile === false || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another speed check is already running.\n");
    exit(0);
}

$run = ['at' => date('c'), 'pages' => []];
foreach (SPEED_PATHS as $path) {
    foreach (['mobile', 'desktop'] as $strategy) {
        $cmd = 'timeout 240 ' . escapeshellarg(SPEED_NODE) . ' ' . escapeshellarg(__DIR__ . '/speed_check.js')
            . ' ' . escapeshellarg(SPEED_BASE . $path) . ' ' . $strategy . ' ' . SPEED_RUNS_PER_PAGE . ' 2>&1';
        $out = [];
        $status = 0;
        exec($cmd, $out, $status);
        $json = $status === 0 ? json_decode((string) end($out), true) : null;
        if (!is_array($json)) {
            fwrite(STDERR, date('c') . " {$path} {$strategy} failed: " . implode(' | ', array_slice($out, -2)) . "\n");
            continue;
        }
        unset($json['url'], $json['strategy']);
        $run['pages'][$path][$strategy] = $json;
    }
}

if ($run['pages'] === []) {
    fwrite(STDERR, "No results were collected.\n");
    exit(1);
}

$history = [];
if (is_file($historyFile)) {
    $decoded = json_decode((string) file_get_contents($historyFile), true);
    $history = is_array($decoded['runs'] ?? null) ? $decoded['runs'] : [];
}
$history[] = $run;
$history = array_slice($history, -SPEED_HISTORY_LIMIT);

$tmp = $historyFile . '.tmp';
file_put_contents($tmp, json_encode(['runs' => $history], JSON_UNESCAPED_SLASHES), LOCK_EX);
chmod($tmp, 0600);
rename($tmp, $historyFile);
echo date('c') . ' speed check saved: ' . count($run['pages']) . " pages\n";
