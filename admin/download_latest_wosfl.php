<?php

declare(strict_types=1);

$filePath = __DIR__ . '/export/latest_wosfl.png';

if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Image not found.\n";
    exit;
}

$mtime = filemtime($filePath);
$datePart = $mtime !== false ? date('Y-m-d-His', $mtime) : date('Y-m-d-His');
$fileName = 'wosfl-table-' . $datePart . '.png';

header('Content-Type: image/png');
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filePath);
exit;

