<?php
declare(strict_types=1);

/** CSV of the payout reconciliation (one row per Stripe transaction). Needs finance_view. */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/payout_reconciliation.php';
require_once __DIR__ . '/lib/audit.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
hub_auth_require_capability('finance_view');

$days = in_array((int) ($_GET['days'] ?? 0), [30, 60, 90, 180, 365], true) ? (int) $_GET['days'] : 90;
try {
    $report = payout_build_report($pdo, time() - $days * 86400);
} catch (Throwable $e) {
    http_response_code(502);
    exit('Could not read payouts from Stripe: ' . $e->getMessage());
}
auditLog($pdo, 'payout_reconciliation_exported', 'Downloaded the payout reconciliation CSV for the last ' . $days . ' days.');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="payout-reconciliation-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');
echo "\xEF\xBB\xBF" . payout_report_csv($report);
