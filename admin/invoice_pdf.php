<?php
declare(strict_types=1);

// Renders one invoice as a PDF — shared by every section that can generate an
// invoice (sponsorship agreements, shop orders, ticket orders, player
// sponsorships). There is deliberately no separate "invoices" admin section;
// this file is only ever reached via a "View PDF" link from the record the
// invoice was raised against, or via invoice_send.php for the emailed copy.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/invoices.php';
require_once __DIR__ . '/lib/site_settings.php';

invoices_ensure_schema($pdo);

$id = (int) ($_GET['id'] ?? 0);
$invoice = $id ? invoices_get($pdo, $id) : null;
if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found.');
}
invoices_require_source_access($pdo, $invoice);
$items = invoices_get_items($pdo, $id);

if (!class_exists(Dompdf\Dompdf::class)) {
    $autoloaders = [__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/project_1/vendor/autoload.php'];
    foreach ($autoloaders as $autoloader) {
        if (is_file($autoloader)) {
            require_once $autoloader;
            break;
        }
    }
}
if (!class_exists(Dompdf\Dompdf::class)) {
    http_response_code(500);
    exit('PDF export is unavailable.');
}

invoice_pdf_render($pdo, $invoice, $items, isset($_GET['download']));
exit;
