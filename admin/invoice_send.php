<?php
declare(strict_types=1);

// AJAX endpoint: emails a generated invoice PDF to an address the admin
// enters, from the "Email" button rendered by invoices_render_admin_section()
// in lib/invoices.php. Shared by every section (sponsorship/shop/tickets) —
// there's nothing section-specific about sending an already-generated invoice.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/invoices.php';
require_once __DIR__ . '/lib/mailer.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: application/json');

function invoice_send_fail(string $message, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    invoice_send_fail('Invalid request method.', 405);
}
if (!csrf_check()) {
    invoice_send_fail('Your session expired. Please reload and try again.');
}

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$email = trim((string) ($_POST['email'] ?? ''));
if ($invoiceId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    invoice_send_fail('Enter a valid email address.');
}

invoices_ensure_schema($pdo);
$invoice = invoices_get($pdo, $invoiceId);
if (!$invoice) {
    invoice_send_fail('Invoice not found.', 404);
}
if (!invoices_has_source_access($pdo, $invoice)) {
    invoice_send_fail('You do not have permission to access this invoice.', 403);
}

try {
    $items = invoices_get_items($pdo, $invoiceId);
    $pdfBytes = invoice_pdf_render($pdo, $invoice, $items, false, true);

    $subject = 'Invoice ' . (string) $invoice['invoice_number'];
    $body = '<p>Please find attached invoice <strong>' . h((string) $invoice['invoice_number']) . '</strong>'
        . ' for ' . h(gbp((float) $invoice['total'])) . '.</p>';

    $sent = hub_send_mail_with_attachment($email, $subject, $body, [
        'filename' => (string) $invoice['invoice_number'] . '.pdf',
        'content' => $pdfBytes,
        'mime' => 'application/pdf',
    ]);

    if (!$sent) {
        invoice_send_fail('Could not send the email. Check the address and mail settings.', 500);
    }

    invoices_mark_sent($pdo, $invoiceId, $email);
    auditLog($pdo, 'invoice_emailed', 'Invoice ' . (string) $invoice['invoice_number'] . ' emailed to ' . $email);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    invoice_send_fail($e->getMessage(), 500);
}
