<?php

declare(strict_types=1);

/**
 * Cross-cutting invoice generator. There is no single "invoices" section in the
 * Hub — invoices are generated from wherever the sale already lives (a sponsorship
 * agreement, a shop order, a ticket order, a player-sponsorship order), via a
 * "Generate Invoice" button embedded on that record's own admin page. This file is
 * the shared plumbing those buttons call into: one numbering sequence, one schema,
 * one PDF layout (see invoice_pdf.php), so invoices look and behave identically
 * regardless of which part of the site raised them.
 *
 * Bill-to details are snapshotted onto the invoice row at generation time (not a
 * live foreign key) so a later edit to a sponsor's/customer's contact details
 * doesn't silently rewrite an invoice that has already been issued/sent.
 */

function invoices_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS invoices (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        invoice_number VARCHAR(20) NOT NULL,
        source_type VARCHAR(40) NOT NULL,
        source_id INT UNSIGNED NULL,
        bill_to_name VARCHAR(190) NOT NULL,
        bill_to_email VARCHAR(190) NULL,
        bill_to_address VARCHAR(500) NULL,
        issue_date DATE NOT NULL,
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(20) NOT NULL DEFAULT 'issued',
        notes TEXT NULL,
        created_by VARCHAR(190) NULL,
        sent_at TIMESTAMP NULL DEFAULT NULL,
        sent_to_email VARCHAR(190) NULL,
        voided_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_invoices_number (invoice_number),
        KEY idx_invoices_source (source_type, source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS invoice_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        invoice_id INT UNSIGNED NOT NULL,
        description VARCHAR(255) NOT NULL,
        quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        unit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (id),
        KEY idx_invoice_items_invoice (invoice_id),
        CONSTRAINT fk_invoice_items_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

/**
 * Same "increment from MAX(id), retry on collision, random fallback" idiom as
 * shop_generate_order_ref() in lib/shop.php — kept consistent with the rest of
 * the app rather than inventing a new numbering style.
 */
function invoices_generate_number(PDO $pdo): string
{
    $next = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) + 1 FROM invoices')->fetchColumn();
    for ($i = 0; $i < 20; $i++) {
        $number = 'INV-' . str_pad((string) ($next + $i), 4, '0', STR_PAD_LEFT);
        $check = $pdo->prepare('SELECT 1 FROM invoices WHERE invoice_number = :n');
        $check->execute([':n' => $number]);
        if (!$check->fetchColumn()) {
            return $number;
        }
    }

    return 'INV-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
}

/**
 * Core creator, shared by every per-source generator below.
 *
 * @param array{name:string,email?:?string,address?:?string} $billTo
 * @param list<array{description:string,quantity?:float,unit_amount:float}> $items
 * @param array{notes?:string,created_by?:string,issue_date?:string} $options
 * @return array{id:int,errors:list<string>}
 */
function invoices_create(PDO $pdo, string $sourceType, ?int $sourceId, array $billTo, array $items, array $options = []): array
{
    invoices_ensure_schema($pdo);

    $name = trim((string) ($billTo['name'] ?? ''));
    if ($name === '') {
        return ['id' => 0, 'errors' => ['A bill-to name is required to generate an invoice.']];
    }
    if (!$items) {
        return ['id' => 0, 'errors' => ['This record has nothing to invoice yet.']];
    }

    $lines = [];
    $subtotal = 0.0;
    foreach ($items as $item) {
        $description = trim((string) ($item['description'] ?? ''));
        if ($description === '') {
            continue;
        }
        $quantity = (float) ($item['quantity'] ?? 1);
        $unitAmount = (float) ($item['unit_amount'] ?? 0);
        $lineTotal = round($quantity * $unitAmount, 2);
        $lines[] = ['description' => $description, 'quantity' => $quantity, 'unit_amount' => $unitAmount, 'line_total' => $lineTotal];
        $subtotal += $lineTotal;
    }
    if (!$lines) {
        return ['id' => 0, 'errors' => ['This record has nothing to invoice yet.']];
    }
    $subtotal = round($subtotal, 2);

    $number = invoices_generate_number($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO invoices
            (invoice_number, source_type, source_id, bill_to_name, bill_to_email, bill_to_address, issue_date, subtotal, total, status, notes, created_by)
            VALUES (:number, :source_type, :source_id, :name, :email, :address, :issue_date, :subtotal, :total, \'issued\', :notes, :created_by)');
        $stmt->execute([
            ':number' => $number,
            ':source_type' => $sourceType,
            ':source_id' => $sourceId,
            ':name' => $name,
            ':email' => trim((string) ($billTo['email'] ?? '')) ?: null,
            ':address' => trim((string) ($billTo['address'] ?? '')) ?: null,
            ':issue_date' => $options['issue_date'] ?? date('Y-m-d'),
            ':subtotal' => $subtotal,
            ':total' => $subtotal,
            ':notes' => trim((string) ($options['notes'] ?? '')) ?: null,
            ':created_by' => trim((string) ($options['created_by'] ?? '')) ?: null,
        ]);
        $invoiceId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare('INSERT INTO invoice_items (invoice_id, description, quantity, unit_amount, line_total)
            VALUES (:invoice_id, :description, :quantity, :unit_amount, :line_total)');
        foreach ($lines as $line) {
            $itemStmt->execute([
                ':invoice_id' => $invoiceId,
                ':description' => $line['description'],
                ':quantity' => $line['quantity'],
                ':unit_amount' => $line['unit_amount'],
                ':line_total' => $line['line_total'],
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['id' => 0, 'errors' => [$e->getMessage()]];
    }

    return ['id' => $invoiceId, 'errors' => []];
}

function invoices_get(PDO $pdo, int $id): ?array
{
    invoices_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function invoices_get_items(PDO $pdo, int $invoiceId): array
{
    invoices_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY id');
    $stmt->execute([':id' => $invoiceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return list<array<string,mixed>>
 */
function invoices_list_for_source(PDO $pdo, string $sourceType, int $sourceId): array
{
    invoices_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM invoices WHERE source_type = :type AND source_id = :id ORDER BY id DESC');
    $stmt->execute([':type' => $sourceType, ':id' => $sourceId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function invoices_void(PDO $pdo, int $id): void
{
    invoices_ensure_schema($pdo);
    $stmt = $pdo->prepare("UPDATE invoices SET status = 'void', voided_at = NOW() WHERE id = :id");
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Invoice not found.');
    }
}

function invoices_mark_sent(PDO $pdo, int $id, string $email): void
{
    invoices_ensure_schema($pdo);
    $pdo->prepare('UPDATE invoices SET sent_at = NOW(), sent_to_email = :email WHERE id = :id')
        ->execute([':email' => $email, ':id' => $id]);
}

function invoices_mark_paid(PDO $pdo, int $id): void
{
    invoices_ensure_schema($pdo);
    $pdo->prepare("UPDATE invoices SET status = 'paid' WHERE id = :id")->execute([':id' => $id]);
}

/**
 * True if the current actor has the same access the host page an invoice was
 * raised from would require — sponsorship agreements and player sponsorship
 * orders need the 'finance_view' capability, shop orders need full admin, match
 * ticket and season pass orders need the 'tickets.view' permission. Without
 * this, invoice_pdf.php etc. would only check "is a logged-in Hub user" —
 * weaker than the record itself, since a volunteer with no finance access
 * could otherwise view a sponsor's invoice by guessing its id.
 *
 * POS sales are a deliberate exception: pos/sale.php is gated by
 * pos_actor_is_manager(), a *separate* actor system (till pos_operators can
 * be managers without ever holding a Hub account at all) — not by any
 * hub_auth_*() check. A blanket "must be hub_auth_is_authenticated()" gate
 * here would lock out a legitimate till manager who has no Hub login, so
 * this branch checks POS's own manager gate instead, and every other branch
 * checks hub_auth_is_authenticated() itself rather than relying on a caller
 * to have checked it first.
 */
function invoices_has_source_access(PDO $pdo, array $invoice): bool
{
    $sourceType = (string) ($invoice['source_type'] ?? '');
    if ($sourceType === 'pos_sale') {
        require_once __DIR__ . '/pos.php';
        return pos_actor_is_manager($pdo);
    }
    if (!hub_auth_is_authenticated()) {
        return false;
    }
    return match ($sourceType) {
        'sponsorship_agreement', 'player_sponsorship_order' => hub_auth_has_capability('finance_view'),
        'shop_order' => hub_auth_is_admin(),
        'match_ticket_order', 'season_pass_order' => hub_auth_has_capability('tickets_ops'),
        default => hub_auth_is_admin(),
    };
}

/**
 * Plain-text-exiting wrapper for a top-level page load (invoice_pdf.php);
 * AJAX endpoints use invoices_has_source_access() directly so they can
 * return a JSON error body instead.
 */
function invoices_require_source_access(PDO $pdo, array $invoice): void
{
    if (!invoices_has_source_access($pdo, $invoice)) {
        http_response_code(403);
        exit('You do not have permission to access this invoice.');
    }
}

// ---------------------------------------------------------------------------
// Per-source generators. Each maps that feature's own record shape onto the
// generic invoices_create() call — this is the only place that needs to know
// each silo's column names (see lib/invoices.php's header comment / the
// research this was built from: shop_orders uses customer_name/total,
// match_ticket_orders uses buyer_name/total_amount, sponsorship agreements use
// sponsor_name/agreed_amount — none of the three share a common shape).
// ---------------------------------------------------------------------------

function invoices_generate_for_sponsorship_agreement(PDO $pdo, int $agreementId, string $createdBy = ''): array
{
    require_once __DIR__ . '/sponsorship_catalog.php';
    $agreement = getSponsorshipAgreement($pdo, $agreementId);
    if (!$agreement) {
        return ['id' => 0, 'errors' => ['Agreement not found.']];
    }

    $sponsorStmt = $pdo->prepare('SELECT address, contact_email FROM sponsors WHERE id = :id LIMIT 1');
    $sponsorStmt->execute([':id' => (int) $agreement['sponsor_id']]);
    $sponsor = $sponsorStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $descriptionParts = [(string) $agreement['package_category'], (string) $agreement['package_name']];
    if (!empty($agreement['fixture_opponent'])) {
        $descriptionParts[] = 'vs ' . (string) $agreement['fixture_opponent'] . (!empty($agreement['fixture_date']) ? ' (' . date('d/m/Y', strtotime((string) $agreement['fixture_date'])) . ')' : '');
    }
    if (!empty($agreement['player_name'])) {
        $descriptionParts[] = (string) $agreement['player_name'];
    }
    if (!empty($agreement['team_name'])) {
        $descriptionParts[] = (string) $agreement['team_name'];
    }
    if (!empty($agreement['season_name'])) {
        $descriptionParts[] = (string) $agreement['season_name'] . ' season';
    }

    return invoices_create(
        $pdo,
        'sponsorship_agreement',
        $agreementId,
        [
            'name' => (string) $agreement['sponsor_name'],
            'email' => (string) ($agreement['sponsor_contact_email'] ?? $sponsor['contact_email'] ?? ''),
            'address' => (string) ($sponsor['address'] ?? ''),
        ],
        [[
            'description' => implode(' — ', array_filter($descriptionParts)),
            'quantity' => 1,
            'unit_amount' => (float) $agreement['agreed_amount'],
        ]],
        ['created_by' => $createdBy]
    );
}

function invoices_generate_for_shop_order(PDO $pdo, int $orderId, string $createdBy = ''): array
{
    require_once __DIR__ . '/shop.php';
    $order = shop_get_order($pdo, $orderId);
    if (!$order) {
        return ['id' => 0, 'errors' => ['Order not found.']];
    }
    $orderItems = shop_order_items($pdo, $orderId);

    $items = [];
    foreach ($orderItems as $item) {
        $description = (string) $item['product_name'];
        if (($item['options_label'] ?? '') !== '') {
            $description .= ' (' . (string) $item['options_label'] . ')';
        }
        $items[] = ['description' => $description, 'quantity' => (float) $item['quantity'], 'unit_amount' => (float) $item['unit_price']];
    }
    if ((float) ($order['discount_total'] ?? 0) > 0) {
        $items[] = [
            'description' => 'Discount / credit' . ($order['discount_code'] ? ' (' . (string) $order['discount_code'] . ')' : ''),
            'quantity' => 1,
            'unit_amount' => -1 * (float) $order['discount_total'],
        ];
    }
    if ((float) ($order['delivery_fee'] ?? 0) > 0) {
        $items[] = ['description' => 'Delivery', 'quantity' => 1, 'unit_amount' => (float) $order['delivery_fee']];
    }

    return invoices_create(
        $pdo,
        'shop_order',
        $orderId,
        [
            'name' => (string) $order['customer_name'],
            'email' => (string) $order['customer_email'],
            'address' => (string) ($order['delivery_address'] ?? ''),
        ],
        $items,
        ['created_by' => $createdBy, 'notes' => 'Order ' . (string) $order['order_ref']]
    );
}

function invoices_generate_for_match_ticket_order(PDO $pdo, int $orderId, string $createdBy = ''): array
{
    $orderStmt = $pdo->prepare('SELECT o.*, f.opponent, f.match_date
        FROM match_ticket_orders o
        LEFT JOIN match_fixtures f ON f.id = o.fixture_id
        WHERE o.id = :id LIMIT 1');
    $orderStmt->execute([':id' => $orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return ['id' => 0, 'errors' => ['Order not found.']];
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM match_ticket_order_items WHERE order_id = :id ORDER BY id');
    $itemsStmt->execute([':id' => $orderId]);
    $items = [];
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $items[] = ['description' => (string) $item['package_name'], 'quantity' => (float) $item['quantity'], 'unit_amount' => (float) $item['unit_price']];
    }

    $notes = 'Match ticket order #' . $orderId;
    if (!empty($order['opponent'])) {
        $notes .= ' — vs ' . (string) $order['opponent'] . (!empty($order['match_date']) ? ' (' . date('d/m/Y', strtotime((string) $order['match_date'])) . ')' : '');
    }

    return invoices_create(
        $pdo,
        'match_ticket_order',
        $orderId,
        ['name' => (string) $order['buyer_name'], 'email' => (string) $order['buyer_email']],
        $items,
        ['created_by' => $createdBy, 'notes' => $notes]
    );
}

function invoices_generate_for_player_sponsorship_order(PDO $pdo, int $orderId, string $createdBy = ''): array
{
    $orderStmt = $pdo->prepare('SELECT * FROM player_sponsorship_orders WHERE id = :id LIMIT 1');
    $orderStmt->execute([':id' => $orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return ['id' => 0, 'errors' => ['Order not found.']];
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM player_sponsorship_order_items WHERE order_id = :id ORDER BY id');
    $itemsStmt->execute([':id' => $orderId]);
    $items = [];
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $description = (string) ($item['player_name_snapshot'] ?? '') !== '' ? ((string) $item['player_name_snapshot'] . ' — ' . (string) $item['package_code']) : (string) $item['package_code'];
        $items[] = ['description' => $description, 'quantity' => 1, 'unit_amount' => (float) $item['unit_amount']];
    }

    return invoices_create(
        $pdo,
        'player_sponsorship_order',
        $orderId,
        [
            'name' => (string) $order['buyer_name'],
            'email' => (string) $order['buyer_email'],
            'address' => (string) ($order['buyer_address'] ?? ''),
        ],
        $items,
        ['created_by' => $createdBy, 'notes' => 'Player sponsorship order #' . $orderId]
    );
}

/**
 * POS till sales are the one source with no guaranteed customer identity at
 * all — person_id/holder_name are both nullable, since most sales are
 * anonymous walk-up purchases. Falls back to "Walk-up customer" plus the
 * sale reference so the invoice is still identifiable, rather than refusing
 * to generate one (invoices_create() only hard-requires a non-empty name).
 */
function invoices_generate_for_pos_sale(PDO $pdo, int $saleId, string $createdBy = ''): array
{
    $saleStmt = $pdo->prepare('SELECT * FROM pos_sales WHERE id = :id LIMIT 1');
    $saleStmt->execute([':id' => $saleId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) {
        return ['id' => 0, 'errors' => ['Sale not found.']];
    }

    $email = '';
    if (!empty($sale['person_id'])) {
        $personStmt = $pdo->prepare('SELECT email FROM people WHERE id = :id LIMIT 1');
        $personStmt->execute([':id' => (int) $sale['person_id']]);
        $email = (string) ($personStmt->fetchColumn() ?: '');
    }
    $name = trim((string) ($sale['holder_name'] ?? ''));
    if ($name === '') {
        $name = 'Walk-up customer — sale ' . (string) $sale['sale_ref'];
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM pos_sale_items WHERE sale_id = :id ORDER BY id');
    $itemsStmt->execute([':id' => $saleId]);
    $items = [];
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $items[] = ['description' => (string) $item['product_name'], 'quantity' => (float) $item['qty'], 'unit_amount' => (float) $item['unit_price']];
    }
    if ((float) ($sale['discount_total'] ?? 0) > 0) {
        $items[] = ['description' => 'Discount', 'quantity' => 1, 'unit_amount' => -1 * (float) $sale['discount_total']];
    }
    if ((float) ($sale['points_discount'] ?? 0) > 0) {
        $items[] = ['description' => 'Loyalty points redeemed', 'quantity' => 1, 'unit_amount' => -1 * (float) $sale['points_discount']];
    }

    return invoices_create(
        $pdo,
        'pos_sale',
        $saleId,
        ['name' => $name, 'email' => $email],
        $items,
        ['created_by' => $createdBy, 'notes' => 'POS sale ' . (string) $sale['sale_ref']]
    );
}

function invoices_generate_for_season_pass_order(PDO $pdo, int $orderId, string $createdBy = ''): array
{
    $orderStmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $orderStmt->execute([':id' => $orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return ['id' => 0, 'errors' => ['Order not found.']];
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id');
    $itemsStmt->execute([':id' => $orderId]);
    $items = [];
    foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $description = trim((string) ($item['description_snapshot'] ?? '')) ?: (string) $item['product_type'];
        $items[] = ['description' => $description, 'quantity' => (float) $item['quantity'], 'unit_amount' => (float) $item['unit_price']];
    }

    return invoices_create(
        $pdo,
        'season_pass_order',
        $orderId,
        ['name' => (string) $order['customer_name'], 'email' => (string) ($order['customer_email'] ?? '')],
        $items,
        ['created_by' => $createdBy, 'notes' => 'Season pass order #' . $orderId]
    );
}

/**
 * Builds the invoice HTML/PDF. Shared by invoice_pdf.php (view/download in the
 * browser) and invoice_send.php (same bytes as an email attachment) so there
 * is exactly one place that knows the invoice layout.
 *
 * When $returnBytesOnly is true, renders nothing and returns the raw PDF
 * bytes instead of streaming a response — used by the email path.
 */
function invoice_pdf_render(PDO $pdo, array $invoice, array $items, bool $forceDownload = false, bool $returnBytesOnly = false): string
{
    require_once __DIR__ . '/site_settings.php';
    require_once __DIR__ . '/functions.php';
    $settings = site_settings_all($pdo);
    $clubName = $settings['club_name'] ?: 'MyClubHub';
    $crest = invoice_pdf_image_data_uri(dirname(__DIR__) . ($settings['crest_url'] ?: ''));
    $companyNumber = $settings['company_number'] ?? '';
    $bankDetails = $settings['bank_details'] ?? '';
    $addressLines = array_filter([$settings['ground_address'] ?? '', $settings['contact_address'] ?? '']);

    $html = '<!doctype html><html><head><meta charset="UTF-8"><style>
        @page { margin: 36px 40px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color:#21141a; font-size:12px; margin:0; }
        .header { display:table; width:100%; margin-bottom:24px; }
        .header .col { display:table-cell; vertical-align:top; }
        .header .col.right { text-align:right; }
        .crest { max-height:64px; max-width:180px; }
        h1 { margin:0 0 2px; color:#4b0818; font-size:22px; }
        .muted { color:#6f6470; }
        .invoice-meta td { padding:2px 0; }
        .invoice-meta td:first-child { color:#6f6470; padding-right:12px; }
        .bill-to { margin:20px 0; }
        .bill-to .label { text-transform:uppercase; letter-spacing:.04em; font-size:10px; color:#6f6470; margin-bottom:4px; }
        table.items { width:100%; border-collapse:collapse; margin-top:10px; }
        table.items th { background:#4b0818; color:#fff; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:.03em; padding:6px 8px; }
        table.items td { border-bottom:1px solid #eadfdf; padding:6px 8px; }
        table.items td.num, table.items th.num { text-align:right; }
        table.totals { width:260px; margin-left:auto; margin-top:10px; }
        table.totals td { padding:4px 8px; }
        table.totals tr.total td { font-weight:bold; border-top:2px solid #4b0818; font-size:14px; }
        .footer-note { margin-top:32px; font-size:10px; color:#6f6470; }
        .status-badge { display:inline-block; padding:2px 10px; border-radius:10px; font-size:10px; text-transform:uppercase; font-weight:bold; }
        .status-issued { background:#eadfdf; color:#4b0818; }
        .status-paid { background:#d7f0dd; color:#146c2e; }
        .status-void { background:#e6e6e6; color:#555; }
    </style></head><body>';

    $html .= '<div class="header"><div class="col">';
    if ($crest !== '') {
        $html .= '<img class="crest" src="' . h($crest) . '"><br>';
    }
    $html .= '<strong>' . h($clubName) . '</strong>';
    foreach ($addressLines as $line) {
        $html .= '<br><span class="muted">' . h($line) . '</span>';
    }
    if ($companyNumber !== '') {
        $html .= '<br><span class="muted">Company no. ' . h($companyNumber) . '</span>';
    }
    if (!empty($settings['contact_email'])) {
        $html .= '<br><span class="muted">' . h($settings['contact_email']) . '</span>';
    }
    $html .= '</div><div class="col right">';
    $html .= '<h1>Invoice</h1>';
    $statusClass = 'status-' . ((string) $invoice['status']);
    $html .= '<div class="' . h($statusClass) . ' status-badge">' . h(ucfirst((string) $invoice['status'])) . '</div>';
    $html .= '<table class="invoice-meta" style="margin-left:auto;margin-top:8px;">';
    $html .= '<tr><td>Invoice #</td><td>' . h((string) $invoice['invoice_number']) . '</td></tr>';
    $html .= '<tr><td>Date</td><td>' . h(date('d/m/Y', strtotime((string) $invoice['issue_date']))) . '</td></tr>';
    $html .= '</table>';
    $html .= '</div></div>';

    $html .= '<div class="bill-to"><div class="label">Bill to</div>';
    $html .= '<strong>' . h((string) $invoice['bill_to_name']) . '</strong>';
    if (!empty($invoice['bill_to_address'])) {
        $html .= '<br>' . nl2br(h((string) $invoice['bill_to_address']));
    }
    if (!empty($invoice['bill_to_email'])) {
        $html .= '<br>' . h((string) $invoice['bill_to_email']);
    }
    $html .= '</div>';

    $html .= '<table class="items"><thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Amount</th></tr></thead><tbody>';
    foreach ($items as $item) {
        $html .= '<tr><td>' . h((string) $item['description']) . '</td>';
        $html .= '<td class="num">' . h(rtrim(rtrim(number_format((float) $item['quantity'], 2), '0'), '.')) . '</td>';
        $html .= '<td class="num">' . h(gbp((float) $item['unit_amount'])) . '</td>';
        $html .= '<td class="num">' . h(gbp((float) $item['line_total'])) . '</td></tr>';
    }
    $html .= '</tbody></table>';

    $html .= '<table class="totals"><tr><td>Subtotal</td><td class="num">' . h(gbp((float) $invoice['subtotal'])) . '</td></tr>';
    $html .= '<tr class="total"><td>Total due</td><td class="num">' . h(gbp((float) $invoice['total'])) . '</td></tr></table>';

    if (!empty($invoice['notes'])) {
        $html .= '<div class="footer-note"><strong>Notes:</strong> ' . h((string) $invoice['notes']) . '</div>';
    }
    if ($bankDetails !== '') {
        $html .= '<div class="footer-note"><strong>Payment details:</strong><br>' . nl2br(h($bankDetails)) . '</div>';
    }
    $html .= '<div class="footer-note">Generated ' . h(date('d/m/Y H:i')) . '</div>';

    $html .= '</body></html>';

    if (!class_exists(Dompdf\Dompdf::class)) {
        $autoloaders = [dirname(__DIR__) . '/vendor/autoload.php', dirname(__DIR__, 2) . '/project_1/vendor/autoload.php'];
        foreach ($autoloaders as $autoloader) {
            if (is_file($autoloader)) {
                require_once $autoloader;
                break;
            }
        }
    }
    if (!class_exists(Dompdf\Dompdf::class)) {
        throw new RuntimeException('PDF export is unavailable.');
    }

    $dompdf = new Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    if ($returnBytesOnly) {
        return (string) $dompdf->output();
    }

    if (ob_get_length() !== false) {
        ob_clean();
    }
    $dompdf->stream((string) $invoice['invoice_number'] . '.pdf', ['Attachment' => $forceDownload]);
    return '';
}

function invoice_pdf_image_data_uri(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $mime = mime_content_type($path) ?: 'image/png';
    $data = file_get_contents($path);
    if ($data === false) {
        return '';
    }
    return 'data:' . $mime . ';base64,' . base64_encode($data);
}

/**
 * Small inline admin widget shared by every host page: existing invoices for
 * this record plus a "Generate invoice" button. Callers embed the button's
 * form action themselves (each host page already has its own POST-action
 * switch); this just renders the read-only list + links to invoice_pdf.php /
 * invoice_send.php, which are the same for every source type.
 *
 * @param list<array<string,mixed>> $invoices
 */
function invoices_render_admin_section(array $invoices, string $generateButtonHtml): string
{
    ob_start();
    $activeCount = count(array_filter($invoices, static fn(array $inv): bool => (string) $inv['status'] !== 'void'));
    ?>
    <h2 class="h6 fw-bold text-uppercase text-muted">Invoices</h2>
    <?php if ($activeCount > 0): ?>
    <div class="alert alert-warning py-2 px-3 small mb-2"><?= $activeCount === 1 ? 'An invoice has already been generated for this record.' : ($activeCount . ' invoices have already been generated for this record.') ?> Generating another will raise an additional invoice rather than replace it — use this for a deposit/balance split or a re-issue, not a routine re-click.</div>
    <?php endif; ?>
    <?php if ($invoices): ?>
    <div class="table-responsive mb-3"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Number</th><th>Date</th><th>Total</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($invoices as $invoice): ?>
            <tr>
                <td><?= h((string) $invoice['invoice_number']) ?></td>
                <td><?= h(date('d/m/Y', strtotime((string) $invoice['issue_date']))) ?></td>
                <td><?= gbp((float) $invoice['total']) ?></td>
                <td>
                    <?php
                    $badge = ['issued' => 'secondary', 'paid' => 'success', 'void' => 'dark'][(string) $invoice['status']] ?? 'secondary';
                    ?>
                    <span class="badge text-bg-<?= h($badge) ?>"><?= h(ucfirst((string) $invoice['status'])) ?></span>
                    <?php if (!empty($invoice['sent_at'])): ?><span class="small text-muted d-block">Emailed <?= h(date('d/m/Y', strtotime((string) $invoice['sent_at']))) ?></span><?php endif; ?>
                </td>
                <td class="text-end">
                    <div class="hub-actions hub-actions--end d-inline-flex gap-1">
                        <a class="btn btn-sm btn-outline-secondary" href="/admin/invoice_pdf.php?id=<?= (int) $invoice['id'] ?>" target="_blank" rel="noopener">View PDF</a>
                        <button type="button" class="btn btn-sm btn-outline-secondary invoice-email-btn" data-invoice-id="<?= (int) $invoice['id'] ?>" data-default-email="<?= h((string) ($invoice['bill_to_email'] ?? '')) ?>">Email</button>
                        <?php if ((string) $invoice['status'] === 'issued'): ?>
                        <button type="button" class="btn btn-sm btn-outline-success invoice-status-btn" data-invoice-id="<?= (int) $invoice['id'] ?>" data-status="paid">Mark paid</button>
                        <?php endif; ?>
                        <?php if ((string) $invoice['status'] !== 'void'): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger invoice-status-btn" data-invoice-id="<?= (int) $invoice['id'] ?>" data-status="void">Void</button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <?= $generateButtonHtml ?>
    <div class="invoice-email-status small mt-2"></div>
    <script>
    (() => {
        document.querySelectorAll('.invoice-email-btn').forEach((btn) => {
            if (btn.dataset.wired) return;
            btn.dataset.wired = '1';
            btn.addEventListener('click', async () => {
                const status = btn.closest('section, div')?.querySelector('.invoice-email-status');
                const email = prompt('Send this invoice to which email address?', btn.dataset.defaultEmail || '');
                if (!email) return;
                btn.disabled = true;
                try {
                    const response = await fetch('/admin/invoice_send.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: 'invoice_id=' + encodeURIComponent(btn.dataset.invoiceId) + '&email=' + encodeURIComponent(email) + '&csrf_token=' + encodeURIComponent(document.querySelector('input[name="csrf_token"]')?.value || '')
                    });
                    const json = await response.json().catch(() => null);
                    if (!response.ok || !json || !json.ok) throw new Error((json && json.error) ? json.error : 'Could not send the invoice.');
                    if (status) { status.textContent = 'Invoice emailed to ' + email + '.'; status.className = 'invoice-email-status small mt-2 text-success'; }
                } catch (error) {
                    if (status) { status.textContent = error.message || 'Could not send the invoice.'; status.className = 'invoice-email-status small mt-2 text-danger'; }
                } finally {
                    btn.disabled = false;
                }
            });
        });
        document.querySelectorAll('.invoice-status-btn').forEach((btn) => {
            if (btn.dataset.wired) return;
            btn.dataset.wired = '1';
            btn.addEventListener('click', async () => {
                const newStatus = btn.dataset.status;
                if (newStatus === 'void' && !(await window.hubConfirm('Void this invoice? This cannot be undone.', { actionLabel: 'Void invoice' }))) return;
                btn.disabled = true;
                try {
                    const response = await fetch('/admin/invoice_status.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: 'invoice_id=' + encodeURIComponent(btn.dataset.invoiceId) + '&status=' + encodeURIComponent(newStatus) + '&csrf_token=' + encodeURIComponent(document.querySelector('input[name="csrf_token"]')?.value || '')
                    });
                    const json = await response.json().catch(() => null);
                    if (!response.ok || !json || !json.ok) throw new Error((json && json.error) ? json.error : 'Could not update the invoice.');
                    window.location.reload();
                } catch (error) {
                    window.hubToast(error.message || 'Could not update the invoice.', 'danger');
                    btn.disabled = false;
                }
            });
        });
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}
