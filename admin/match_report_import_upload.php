<?php
declare(strict_types=1);

/**
 * Receives one COMET match report PDF from match_report_import.php (AJAX), reads it, saves the
 * details and answers with a JSON summary. The uploaded PDF is deleted before the response is
 * sent, whether or not reading it succeeded — nothing is stored.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/match_report_import.php';

header('Content-Type: application/json');
$reply = static function (array $body, int $code = 200): never {
    http_response_code($code);
    echo json_encode($body);
    exit;
};

if (!hub_auth_is_authenticated()) { $reply(['ok' => false, 'message' => 'Your session has expired — please reload the page and log in again.'], 401); }
if (function_exists('hub_auth_has_capability') && !hub_auth_has_capability('matchday')) { $reply(['ok' => false, 'message' => 'Access denied.'], 403); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) { $reply(['ok' => false, 'message' => 'Invalid request. Please reload the page.'], 400); }

$upload = $_FILES['pdf'] ?? null;
$tmp = is_array($upload) ? (string)($upload['tmp_name'] ?? '') : '';
try {
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        $reply(['ok' => false, 'message' => 'The file did not upload. Please try again.']);
    }
    if ((int)$upload['size'] > 5 * 1024 * 1024) { $reply(['ok' => false, 'message' => 'That file is too large for a match report (limit 5 MB).']); }
    $parsed = hub_mr_parse($tmp);
    if (isset($parsed['error'])) { $reply(['ok' => false, 'message' => $parsed['error']]); }
    $summary = hub_mr_import($pdo, $parsed);
    auditLog($pdo, 'match_report_import', sprintf('Imported match report %s on %s (officials %d, players +%d, goals +%d, cards +%d, staff cards +%d, subs +%d, reasons %d)',
        $summary['match'], $summary['date'], $summary['officials_total'], $summary['players_added'], $summary['goals_added'], $summary['cards_added'], $summary['staff_cards_added'], $summary['subs_added'], $summary['reasons_filled']));
    $reply(['ok' => true, 'summary' => $summary]);
} catch (Throwable $e) {
    error_log('match_report_import failed: ' . $e->getMessage());
    $reply(['ok' => false, 'message' => 'Something went wrong reading that report. Nothing was changed.']);
} finally {
    if ($tmp !== '' && is_file($tmp)) { @unlink($tmp); }   // never keep the PDF
}
