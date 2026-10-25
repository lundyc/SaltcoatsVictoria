<?php
declare(strict_types=1);

/**
 * PDF of a stats.php tab as it appears on screen. stats.php is rendered here in-process, so the data,
 * filters and sort are the page's own; the captured page is then printed by the shared headless Chrome
 * (render_pdf.js). The players and compare tabs have their own PDFs (stats_players_pdf.php, stats_compare_pdf.php).
 *
 *   /admin/stats_pdf.php?tab=team&season_id=2&competition=league&venue=
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/render_lib.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
if (function_exists('hub_auth_has_capability') && !hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}

// stats.php reads this to print the filter summary and to leave out the on-screen export row.
$statsPdfExport = true;
set_time_limit(180);
ob_start();
require __DIR__ . '/stats.php';
$pdfPage = (string)ob_get_clean();
header_remove('Location');

if ((int)http_response_code() >= 400 || stripos($pdfPage, '<html') === false) {
    http_response_code(500);
    exit('The page could not be prepared for PDF export.');
}

$host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? 'saltcoatsvictoria.co.uk')));
$host = preg_replace('/:\d+$/', '', $host) ?? '';
if (!preg_match('/^(?:www\.)?saltcoatsvictoria\.co\.uk$/', $host)) {
    $host = 'saltcoatsvictoria.co.uk';
}

// The captured page is printed from a file, so its site-relative assets need the site as base URL.
$pdfPage = preg_replace('~<head\b[^>]*>~i', '$0<base href="https://' . $host . '/">', $pdfPage, 1) ?? $pdfPage;
$pdfPage = str_ireplace('</head>', '<style>
@page { size: A4 landscape; margin: 0; }
.stats-pdf-summary { margin: 0 0 12px; font-size: 12px; color: #6f6470; }
@media print {
  #hubSideNavigation, #hubSidebarRail, #hubPalette, #hubHelpPanel, #hubConfirmDialog, #hubLiveStatus,
  .hub-help-fab, .hub-tour-prompt, .hub-toast-region, .stats-export-row, nav.rpt-picker, .rpt-main > form.card, .btn,
  .form-control, .form-select, .form-switch, .form-check, input[type="search"] {
    display: none !important;
  }
  body.hub-shell, body.hub-shell main.hub-main, main.hub-main {
    margin: 0 !important;
    padding: 0 !important;
  }
  .rpt-layout { display: block !important; }
  .rpt-main { width: auto !important; max-width: none !important; margin: 0 !important; padding: 0 !important; }
  .card { box-shadow: none !important; }
  tr { break-inside: avoid; }
  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
}
</style></head>', $pdfPage);

$tmpBase = tempnam(sys_get_temp_dir(), 'stats-pdf-');
if ($tmpBase === false) {
    http_response_code(500);
    exit('The export could not be prepared.');
}
$htmlPath = $tmpBase . '.html';
$pdfPath = $tmpBase . '.pdf';
rename($tmpBase, $htmlPath);
file_put_contents($htmlPath, $pdfPage);

$pdfResult = render_capture_pdf(
    'file://' . $htmlPath,
    $pdfPath,
    htmlspecialchars('Saltcoats Victoria  ·  ' . $statsPdfSummary, ENT_QUOTES, 'UTF-8')
);
@unlink($htmlPath);

if (!$pdfResult['ok']) {
    @unlink($pdfPath);
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $pdfResult['error'];
    if ($pdfResult['output'] !== []) {
        echo "\n\n" . implode("\n", $pdfResult['output']);
    }
    exit;
}

$fileName = 'stats-' . trim((string)preg_replace('/[^a-z]+/', '-', $tab), '-') . '-' . date('Ymd') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Length: ' . (string)filesize($pdfPath));
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: private, no-store');
readfile($pdfPath);
@unlink($pdfPath);
