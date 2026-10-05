<?php

declare(strict_types=1);

/**
 * Cross-cutting invoices/invoice_items tables — see lib/invoices.php for the
 * schema and generator functions. Invoices are raised from wherever the sale
 * already lives (sponsorship agreements, shop orders, ticket orders) rather
 * than from a dedicated invoices section.
 */
return static function (PDO $pdo): void {
    require_once __DIR__ . '/../../lib/invoices.php';
    invoices_ensure_schema($pdo);
};
