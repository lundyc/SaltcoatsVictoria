<?php
declare(strict_types=1);

/**
 * PowerPoint export of an opponent scouting report built from an uploaded COMET
 * JSON export (stats.php?tab=opposition). The club's reference deck
 * (data/opposition_template.pptx) is filled in by lib/opposition_pptx/build_deck.py,
 * so every week's deck keeps the same slides, layout and styling.
 *
 *   /admin/opposition_report_pptx.php?file=east-kilbride-ym.json
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/opposition_report.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
if (function_exists('hub_auth_has_capability') && !hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}

$path = hub_opposition_report_resolve((string)($_GET['file'] ?? ''));
if ($path === null) {
    http_response_code(404);
    exit('Report not found.');
}
$data = json_decode((string)file_get_contents($path), true);
if (!is_array($data) || !isset($data['matches']) || !is_array($data['matches'])) {
    http_response_code(422);
    exit('That file is not a readable opponent report.');
}

$script   = __DIR__ . '/lib/opposition_pptx/build_deck.py';
$template = __DIR__ . '/data/opposition_template.pptx';
$league   = __DIR__ . '/cache/wosfl_table.json';
$python   = trim((string)shell_exec('command -v python3 2>/dev/null'));
if ($python === '' || !is_file($template)) {
    http_response_code(500);
    exit('PowerPoint export is unavailable.');
}

$out = tempnam(sys_get_temp_dir(), 'opp_pptx_');
$cmd = escapeshellarg($python) . ' ' . escapeshellarg($script)
    . ' --json ' . escapeshellarg($path)
    . ' --template ' . escapeshellarg($template)
    . (is_file($league) ? ' --league ' . escapeshellarg($league) : '')
    . ' --out ' . escapeshellarg($out) . ' 2>&1';
exec($cmd, $lines, $status);

if ($status !== 0 || !is_file($out) || filesize($out) < 1000) {
    @unlink($out);
    error_log('opposition_report_pptx failed: ' . implode(' | ', array_slice($lines, -5)));
    http_response_code(500);
    exit('Could not build the PowerPoint for that report.');
}

$team = preg_replace('/[^A-Za-z0-9]+/', '_', trim((string)($data['team'] ?? 'Opposition'))) ?: 'Opposition';
header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
header('Content-Disposition: attachment; filename="' . trim($team, '_') . '_Opposition_Report.pptx"');
header('Content-Length: ' . filesize($out));
header('Cache-Control: private, no-store');
readfile($out);
@unlink($out);
