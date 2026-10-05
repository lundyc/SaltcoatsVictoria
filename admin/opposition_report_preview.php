<?php
declare(strict_types=1);

/**
 * JSON preview of an opponent scouting report, fetched by stats.php's inline
 * "Uploaded reports" preview panel so a report can be reviewed on the page
 * before actually downloading the PDF.
 *
 *   GET /admin/opposition_report_preview.php?file=east-kilbride-ym.json
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/opposition_report.php';

header('Content-Type: application/json');

if (!hub_auth_is_authenticated()) {
    echo json_encode(['ok' => false, 'message' => 'Your session has expired — please reload the page and log in again.']);
    exit;
}
if (function_exists('hub_auth_has_capability') && !hub_auth_has_capability('matchday')) {
    echo json_encode(['ok' => false, 'message' => 'Access denied.']);
    exit;
}

$path = hub_opposition_report_resolve((string)($_GET['file'] ?? ''));
if ($path === null) {
    echo json_encode(['ok' => false, 'message' => 'That report could not be found.']);
    exit;
}

$data = json_decode((string)file_get_contents($path), true);
if (!is_array($data) || !isset($data['matches']) || !is_array($data['matches'])) {
    echo json_encode(['ok' => false, 'message' => 'That file is not a readable opponent report.']);
    exit;
}

$report = hub_opposition_report_build($data);
echo json_encode(['ok' => true, 'file' => basename($path), 'report' => $report]);
