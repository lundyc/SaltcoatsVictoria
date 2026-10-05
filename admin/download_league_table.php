<?php

declare(strict_types=1);

require_once __DIR__ . '/social_auth.php';
require_once __DIR__ . '/render_lib.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/render_access_token.php';

auth_require_json();

$requestedTableStyle = isset($_GET['table_style']) ? strtolower((string) $_GET['table_style']) : 'compact';
$tableStyle = in_array($requestedTableStyle, ['standard', 'compact', 'expanded'], true)
    ? $requestedTableStyle
    : 'compact';
$renderWidth = 1080;
$renderHeight = 1350;
$renderUrl = APP_ORIGIN . '/admin/league_table_graphic.php?render=1'
    . '&table_style=' . rawurlencode($tableStyle)
    . '&_token=' . rawurlencode(RENDER_ACCESS_TOKEN)
    . '&v=' . rawurlencode((string) time());

$tempBase = tempnam(sys_get_temp_dir(), 'league-table-download-');
if ($tempBase === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "The download could not be prepared.\n";
    exit;
}

@unlink($tempBase);
$outputPath = $tempBase . '.png';
$result = render_capture_image(
    $renderUrl,
    $outputPath,
    '.table-card--render',
    $renderWidth,
    $renderHeight,
    '.table-card--render'
);

if (!$result['ok'] || !is_file($outputPath)) {
    @unlink($outputPath);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "The league table image could not be generated.\n";
    exit;
}

$fileName = 'wosfl-table-' . date('Y-m-d-His') . '.png';
$fileSize = filesize($outputPath);

header('Content-Type: image/png');
if ($fileSize !== false) {
    header('Content-Length: ' . (string) $fileSize);
}
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

readfile($outputPath);
@unlink($outputPath);
exit;
