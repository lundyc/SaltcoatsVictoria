<?php
declare(strict_types=1);

/** JSON search for the Help Centre: ?q=<words>&area=<area name> */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/help.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');

if (hub_auth_current_user() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
$area = mb_substr(trim((string) ($_GET['area'] ?? '')), 0, 80);
$results = [];
try {
    $results = $q !== '' ? help_search($pdo, $q, $area) : [];
} catch (Throwable $e) {
    error_log('help_search: ' . $e->getMessage());
}
echo json_encode(['ok' => true, 'query' => $q, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
