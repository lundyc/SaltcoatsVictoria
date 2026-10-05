<?php
declare(strict_types=1);

// AJAX endpoint: marks an invoice paid or void from the buttons rendered by
// invoices_render_admin_section() in lib/invoices.php — mirrors invoice_send.php's
// shape so status changes work identically regardless of which section (shop,
// tickets, sponsorship, player sponsorships) the invoice was raised from.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/invoices.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: application/json');

function invoice_status_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    invoice_status_fail('Invalid request method.', 405);
}
if (!csrf_check()) {
    invoice_status_fail('Your session expired. Please reload and try again.');
}

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$status = (string) ($_POST['status'] ?? '');
if ($invoiceId <= 0 || !in_array($status, ['paid', 'void'], true)) {
    invoice_status_fail('Invalid request.');
}

invoices_ensure_schema($pdo);
$invoice = invoices_get($pdo, $invoiceId);
if (!$invoice) {
    invoice_status_fail('Invoice not found.', 404);
}
if ((string) $invoice['status'] === 'void') {
    invoice_status_fail('This invoice has already been voided.');
}
if (!invoices_has_source_access($pdo, $invoice)) {
    invoice_status_fail('You do not have permission to access this invoice.', 403);
}

try {
    if ($status === 'paid') {
        invoices_mark_paid($pdo, $invoiceId);
        auditLog($pdo, 'invoice_marked_paid', 'Invoice ' . (string) $invoice['invoice_number'] . ' marked paid');
    } else {
        invoices_void($pdo, $invoiceId);
        auditLog($pdo, 'invoice_voided', 'Invoice ' . (string) $invoice['invoice_number'] . ' voided');
    }
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    invoice_status_fail($e->getMessage(), 500);
}
