<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';

$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
$entry = is_array($_SESSION['hub_dynamic_stylesheets'][$token] ?? null)
    ? $_SESSION['hub_dynamic_stylesheets'][$token]
    : null;

if ($token === '' || $entry === null || (int) ($entry['created'] ?? 0) < time() - 1800) {
    http_response_code(404);
    header('Content-Type: text/css; charset=utf-8');
    echo '/* Dynamic stylesheet expired. Refresh the page. */';
    exit;
}

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: private, max-age=1800');
header('X-Content-Type-Options: nosniff');
echo (string) ($entry['css'] ?? '');
