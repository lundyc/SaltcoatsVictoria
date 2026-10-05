<?php

declare(strict_types=1);

// PHASE3B_GUARD_MARKER
$isCli = PHP_SAPI === 'cli';

require_once __DIR__ . '/auth.php';
if (!$isCli && !hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/social_auth.php';
require_once __DIR__ . '/lib/functions.php';

$cacheFile = __DIR__ . '/cache/wosfl_table.json';
$exportFile = __DIR__ . '/export/latest_wosfl.png';
$generatorScript = __DIR__ . '/generate_table_image.php';
$historyFile = __DIR__ . '/logs/refresh_history.json';

/**
 * @return array<int, array<string, mixed>>
 */
function loadRefreshHistory(string $historyFile): array
{
    if (!file_exists($historyFile)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($historyFile), true);
    if (!is_array($decoded)) {
        return [];
    }

    $history = [];
    foreach ($decoded as $entry) {
        if (is_array($entry)) {
            $history[] = $entry;
        }
    }

    return $history;
}

/**
 * @param array<int, array<string, mixed>> $history
 */
function saveRefreshHistory(string $historyFile, array $history): void
{
    $historyDir = dirname($historyFile);
    if (!is_dir($historyDir)) {
        mkdir($historyDir, 0777, true);
    }

    file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
}

function resolvePhpCliBinary(): ?string
{
    $candidates = [];

    if (defined('PHP_BINARY') && PHP_BINARY !== '') {
        $candidates[] = PHP_BINARY;
    }

    if (defined('PHP_BINDIR') && PHP_BINDIR !== '') {
        $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
        $candidates[] = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe';
    }

    $whichPhp = trim((string) shell_exec('command -v php 2>/dev/null'));
    if ($whichPhp !== '') {
        $candidates[] = $whichPhp;
    }

    $seen = [];
    foreach ($candidates as $candidate) {
        if ($candidate === '' || isset($seen[$candidate])) {
            continue;
        }
        $seen[$candidate] = true;

        $base = strtolower((string) basename($candidate));
        if (strpos($base, 'php-fpm') !== false) {
            continue;
        }

        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        return 'php';
    }

    return null;
}

/**
 * @param array<int, string> $details
 * @param array<int, array<string, mixed>> $history
 * @return never
 */
function respond(bool $isCli, bool $ok, string $summary, array $details, array $history, int $statusCode): void
{
    if ($isCli) {
        echo $summary . PHP_EOL;
        foreach ($details as $detail) {
            if ($detail !== '') {
                echo '- ' . $detail . PHP_EOL;
            }
        }
        exit($ok ? 0 : 1);
    }

    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => $ok,
        'summary' => $summary,
        'details' => array_values(array_filter($details, static function (string $detail): bool {
            return $detail !== '';
        })),
        'history' => $history,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$isCli && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respond(false, false, 'Method not allowed.', ['Use POST to trigger a manual refresh.'], loadRefreshHistory($historyFile), 405);
}

if (!$isCli) {
    auth_require_json();
    if (!csrf_check()) {
        respond(false, false, 'Your session expired. Please refresh the page and try again.', [], loadRefreshHistory($historyFile), 400);
    }
}

include __DIR__ . '/wosfl-table.php';

$history = loadRefreshHistory($historyFile);
$cacheTimestamp = file_exists($cacheFile) ? (int) filemtime($cacheFile) : time();
$entryTimestamp = date(DATE_ATOM);
$entryTimestampLabel = date('j M Y, H:i');

$isLiveRefresh = ($tableDataSource ?? 'live') === 'live';
$status = $isLiveRefresh ? 'success' : 'warning';
$summary = $isLiveRefresh ? 'Standings refreshed.' : 'Live refresh unavailable.';
$details = [
    'Live WOSFL data fetched successfully.',
    'Cache updated at ' . date('j M Y, H:i', $cacheTimestamp) . '.',
];
$statusCode = $isLiveRefresh ? 200 : 503;

if (!$isLiveRefresh) {
    $details = [
        ($tableStatusMessage ?? '') !== ''
            ? (string) $tableStatusMessage
            : 'The existing cached table remains in place.',
    ];
}

$phpCliBinary = resolvePhpCliBinary();
$imageGenerateStatus = 0;
$imageGenerateLines = [];

if ($phpCliBinary === null) {
    $status = 'warning';
    if ($statusCode === 200) {
        $statusCode = 500;
    }

    if ($isLiveRefresh) {
        $summary = 'Standings refreshed, but the image generator could not be started.';
    }
    $details[] = 'Could not locate a PHP CLI binary to regenerate the export image.';
} else {
    $generateCmd = escapeshellarg($phpCliBinary) . ' ' . escapeshellarg($generatorScript) . ' 2>&1';
    exec($generateCmd, $imageGenerateLines, $imageGenerateStatus);

    $imageGenerateDetails = [];
    foreach ($imageGenerateLines as $line) {
        $line = trim((string) $line);
        if ($line !== '') {
            $imageGenerateDetails[] = $line;
        }
    }

    if ($imageGenerateStatus !== 0 || !is_file($exportFile)) {
        $status = 'warning';
        if ($statusCode === 200) {
            $statusCode = 500;
        }

        if ($isLiveRefresh) {
            $summary = 'Standings refreshed, but the export image could not be regenerated.';
        }

        $details = array_merge(
            $details,
            $imageGenerateDetails !== [] ? $imageGenerateDetails : ['No output was produced by the image generator.']
        );
    } else {
        $details[] = 'Export image refreshed at ' . date('j M Y, H:i', (int) filemtime($exportFile)) . '.';
    }
}

array_unshift($history, [
    'timestamp' => $entryTimestamp,
    'timestamp_label' => $entryTimestampLabel,
    'status' => $status,
    'summary' => $summary,
]);
$history = array_slice($history, 0, 10);
saveRefreshHistory($historyFile, $history);

respond($isCli, $status === 'success', $summary, $details, $history, $statusCode);
