<?php
declare(strict_types=1);

require_once __DIR__ . '/pos.php';
require_once __DIR__ . '/pos_trading_days.php';
require_once __DIR__ . '/audit.php';

function pos_controls_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    pos_ensure_schema($pdo);
    pos_trading_day_ensure_schema($pdo);

    $saleColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM pos_sales') as $row) {
        $saleColumns[(string) $row['Field']] = $row;
    }
    if (!isset($saleColumns['payment_reference'])) {
        $pdo->exec('ALTER TABLE pos_sales ADD COLUMN payment_reference VARCHAR(120) NULL AFTER payment_method');
    }
    if (!isset($saleColumns['receipt_number'])) {
        $pdo->exec('ALTER TABLE pos_sales ADD COLUMN receipt_number VARCHAR(40) NULL AFTER sale_ref, ADD UNIQUE KEY uq_pos_sales_receipt (receipt_number)');
    }
    if (!isset($saleColumns['completed_at'])) {
        $pdo->exec('ALTER TABLE pos_sales ADD COLUMN completed_at DATETIME NULL AFTER created_at');
        $pdo->exec("UPDATE pos_sales SET completed_at = created_at WHERE status IN ('complete', 'refund') AND completed_at IS NULL");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_audit_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        trading_day_id BIGINT UNSIGNED NULL,
        till_session_id BIGINT UNSIGNED NULL,
        location_id INT UNSIGNED NULL,
        operator_id INT UNSIGNED NULL,
        hub_account_id INT UNSIGNED NULL,
        action VARCHAR(80) NOT NULL,
        details VARCHAR(255) NULL,
        amount DECIMAL(10,2) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pos_audit_day_action (trading_day_id, action, created_at),
        KEY idx_pos_audit_till (till_session_id, created_at),
        KEY idx_pos_audit_location (location_id, created_at),
        KEY idx_pos_audit_operator (operator_id, created_at),
        CONSTRAINT fk_pos_audit_day FOREIGN KEY (trading_day_id) REFERENCES pos_trading_days(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_audit_till FOREIGN KEY (till_session_id) REFERENCES pos_till_sessions(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_audit_location FOREIGN KEY (location_id) REFERENCES pos_locations(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_audit_operator FOREIGN KEY (operator_id) REFERENCES pos_operators(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_audit_account FOREIGN KEY (hub_account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_refunds (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        original_sale_id BIGINT UNSIGNED NOT NULL,
        refund_sale_id BIGINT UNSIGNED NULL,
        trading_day_id BIGINT UNSIGNED NULL,
        till_session_id BIGINT UNSIGNED NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        payment_method VARCHAR(30) NOT NULL,
        reason VARCHAR(255) NOT NULL,
        refunded_by_account_id INT UNSIGNED NULL,
        refunded_by_operator_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pos_refunds_original (original_sale_id),
        KEY idx_pos_refunds_day (trading_day_id, created_at),
        CONSTRAINT fk_pos_refunds_original FOREIGN KEY (original_sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_refunds_refund_sale FOREIGN KEY (refund_sale_id) REFERENCES pos_sales(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_refunds_day FOREIGN KEY (trading_day_id) REFERENCES pos_trading_days(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_refunds_till FOREIGN KEY (till_session_id) REFERENCES pos_till_sessions(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_refunds_account FOREIGN KEY (refunded_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_refunds_operator FOREIGN KEY (refunded_by_operator_id) REFERENCES pos_operators(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_cash_movements (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        trading_day_id BIGINT UNSIGNED NOT NULL,
        till_session_id BIGINT UNSIGNED NOT NULL,
        location_id INT UNSIGNED NOT NULL,
        movement_type VARCHAR(30) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        reason VARCHAR(255) NOT NULL,
        created_by_account_id INT UNSIGNED NULL,
        created_by_operator_id INT UNSIGNED NULL,
        created_by_name VARCHAR(190) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pos_cash_movements_day (trading_day_id, created_at),
        KEY idx_pos_cash_movements_till (till_session_id, created_at),
        CONSTRAINT fk_pos_cash_movements_day FOREIGN KEY (trading_day_id) REFERENCES pos_trading_days(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_cash_movements_till FOREIGN KEY (till_session_id) REFERENCES pos_till_sessions(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_cash_movements_location FOREIGN KEY (location_id) REFERENCES pos_locations(id) ON DELETE RESTRICT,
        CONSTRAINT fk_pos_cash_movements_account FOREIGN KEY (created_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_cash_movements_operator FOREIGN KEY (created_by_operator_id) REFERENCES pos_operators(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $productColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM pos_products') as $row) {
        $productColumns[(string) $row['Field']] = true;
    }
    if (!isset($productColumns['stock_on_hand'])) {
        $pdo->exec('ALTER TABLE pos_products ADD COLUMN stock_on_hand DECIMAL(10,2) NULL AFTER price');
    }
    $saleItemColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM pos_sale_items') as $row) {
        $saleItemColumns[(string) $row['Field']] = $row;
    }
    if (isset($saleItemColumns['qty']) && stripos((string) $saleItemColumns['qty']['Type'], 'unsigned') !== false) {
        $pdo->exec('ALTER TABLE pos_sale_items MODIFY qty DECIMAL(10,2) NOT NULL DEFAULT 1.00');
    }
    if (!isset($saleItemColumns['original_sale_item_id'])) {
        $pdo->exec('ALTER TABLE pos_sale_items ADD COLUMN original_sale_item_id BIGINT UNSIGNED NULL AFTER sale_id, ADD KEY idx_pos_sale_items_original (original_sale_item_id)');
        $pdo->exec('ALTER TABLE pos_sale_items ADD CONSTRAINT fk_pos_sale_items_original FOREIGN KEY (original_sale_item_id) REFERENCES pos_sale_items(id) ON DELETE SET NULL');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_stock_movements (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id INT UNSIGNED NOT NULL,
        sale_id BIGINT UNSIGNED NULL,
        sale_item_id BIGINT UNSIGNED NULL,
        refund_id BIGINT UNSIGNED NULL,
        movement_type VARCHAR(30) NOT NULL,
        qty_delta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        reason VARCHAR(190) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pos_stock_sale_item_type (sale_item_id, movement_type),
        KEY idx_pos_stock_product (product_id, created_at),
        CONSTRAINT fk_pos_stock_product FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE RESTRICT,
        CONSTRAINT fk_pos_stock_sale FOREIGN KEY (sale_id) REFERENCES pos_sales(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_stock_sale_item FOREIGN KEY (sale_item_id) REFERENCES pos_sale_items(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_stock_refund FOREIGN KEY (refund_id) REFERENCES pos_refunds(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_refund_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        refund_id BIGINT UNSIGNED NOT NULL,
        original_sale_item_id BIGINT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NOT NULL,
        qty DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pos_refund_items_original (original_sale_item_id),
        CONSTRAINT fk_pos_refund_items_refund FOREIGN KEY (refund_id) REFERENCES pos_refunds(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_refund_items_original FOREIGN KEY (original_sale_item_id) REFERENCES pos_sale_items(id) ON DELETE RESTRICT,
        CONSTRAINT fk_pos_refund_items_product FOREIGN KEY (product_id) REFERENCES pos_products(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_z_report_snapshots (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        trading_day_id BIGINT UNSIGNED NOT NULL,
        till_session_id BIGINT UNSIGNED NULL,
        report_type VARCHAR(20) NOT NULL DEFAULT 'z',
        report_data JSON NOT NULL,
        created_by_account_id INT UNSIGNED NULL,
        created_by_operator_id INT UNSIGNED NULL,
        created_by_name VARCHAR(190) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pos_z_report_scope (trading_day_id, till_session_id, report_type),
        CONSTRAINT fk_pos_z_snapshot_day FOREIGN KEY (trading_day_id) REFERENCES pos_trading_days(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_z_snapshot_till FOREIGN KEY (till_session_id) REFERENCES pos_till_sessions(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_z_snapshot_account FOREIGN KEY (created_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_z_snapshot_operator FOREIGN KEY (created_by_operator_id) REFERENCES pos_operators(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function pos_receipt_number(int $saleId): string
{
    return 'R' . date('ymd') . '-' . str_pad((string) $saleId, 6, '0', STR_PAD_LEFT);
}

function pos_assign_receipt_number(PDO $pdo, int $saleId): string
{
    pos_controls_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT receipt_number FROM pos_sales WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $saleId]);
    $existing = (string) ($stmt->fetchColumn() ?: '');
    if ($existing !== '') {
        return $existing;
    }
    $receipt = pos_receipt_number($saleId);
    $pdo->prepare('UPDATE pos_sales SET receipt_number = :receipt WHERE id = :id AND receipt_number IS NULL')
        ->execute([':receipt' => $receipt, ':id' => $saleId]);
    return $receipt;
}

function pos_controls_actor_account_id(array $actor): ?int
{
    $accountId = (int) ($actor['account_id'] ?? 0);
    return $accountId > 0 ? $accountId : null;
}

function pos_controls_actor_operator_id(array $actor): ?int
{
    return (string) ($actor['type'] ?? '') === 'operator' ? (int) ($actor['id'] ?? 0) ?: null : null;
}

function pos_audit_event(PDO $pdo, string $action, string $details, array $context = []): void
{
    pos_controls_ensure_schema($pdo);
    $stmt = $pdo->prepare('INSERT INTO pos_audit_events (trading_day_id, till_session_id, location_id, operator_id, hub_account_id, action, details, amount) VALUES (:trading_day_id, :till_session_id, :location_id, :operator_id, :hub_account_id, :action, :details, :amount)');
    $stmt->execute([
        ':trading_day_id' => (int) ($context['trading_day_id'] ?? 0) ?: null,
        ':till_session_id' => (int) ($context['till_session_id'] ?? 0) ?: null,
        ':location_id' => (int) ($context['location_id'] ?? 0) ?: null,
        ':operator_id' => (int) ($context['operator_id'] ?? 0) ?: null,
        ':hub_account_id' => (int) ($context['hub_account_id'] ?? 0) ?: null,
        ':action' => $action,
        ':details' => $details !== '' ? $details : null,
        ':amount' => isset($context['amount']) ? round((float) $context['amount'], 2) : null,
    ]);
    auditLog($pdo, $action, $details);
}

function pos_record_stock_for_sale(PDO $pdo, int $saleId, string $movementType = 'sale'): void
{
    pos_controls_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT i.* FROM pos_sale_items i JOIN pos_sales s ON s.id = i.sale_id WHERE i.sale_id = :sale_id AND s.status IN ("complete", "refunded", "refund")');
    $stmt->execute([':sale_id' => $saleId]);
    $insert = $pdo->prepare('INSERT IGNORE INTO pos_stock_movements (product_id, sale_id, sale_item_id, movement_type, qty_delta, reason) VALUES (:product_id, :sale_id, :sale_item_id, :movement_type, :qty_delta, :reason)');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $qty = abs((float) $item['qty']);
        $delta = $movementType === 'refund' ? $qty : -$qty;
        $insert->execute([
            ':product_id' => (int) $item['product_id'],
            ':sale_id' => $saleId,
            ':sale_item_id' => (int) $item['id'],
            ':movement_type' => $movementType,
            ':qty_delta' => $delta,
            ':reason' => $movementType === 'refund' ? 'Refund return' : 'POS sale',
        ]);
        $pdo->prepare('UPDATE pos_products SET stock_on_hand = CASE WHEN stock_on_hand IS NULL THEN NULL ELSE stock_on_hand + :delta END WHERE id = :id')
            ->execute([':delta' => $delta, ':id' => (int) $item['product_id']]);
    }
}

function pos_cash_movement_create(PDO $pdo, int $locationId, array $actor, string $type, float $amount, string $reason): int
{
    pos_controls_ensure_schema($pdo);
    $type = strtolower(trim($type));
    if (!in_array($type, ['skim', 'safe_drop', 'paid_in', 'paid_out'], true)) {
        throw new InvalidArgumentException('Select a valid cash movement type.');
    }
    $amount = round(max(0, $amount), 2);
    $reason = trim($reason);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Enter an amount greater than zero.');
    }
    if ($reason === '') {
        throw new InvalidArgumentException('A reason is required.');
    }

    $day = pos_trading_day_require_open($pdo);
    $session = pos_till_session_require_open($pdo, (int) $day['id'], $locationId);
    $stmt = $pdo->prepare('INSERT INTO pos_cash_movements (trading_day_id, till_session_id, location_id, movement_type, amount, reason, created_by_account_id, created_by_operator_id, created_by_name) VALUES (:trading_day_id, :till_session_id, :location_id, :movement_type, :amount, :reason, :account_id, :operator_id, :actor_name)');
    $stmt->execute([
        ':trading_day_id' => (int) $day['id'],
        ':till_session_id' => (int) $session['id'],
        ':location_id' => $locationId,
        ':movement_type' => $type,
        ':amount' => $amount,
        ':reason' => $reason,
        ':account_id' => pos_controls_actor_account_id($actor),
        ':operator_id' => pos_controls_actor_operator_id($actor),
        ':actor_name' => (string) ($actor['name'] ?? ''),
    ]);
    $id = (int) $pdo->lastInsertId();
    pos_audit_event($pdo, 'pos_cash_movement_created', ucfirst(str_replace('_', ' ', $type)) . ' ' . gbp($amount) . ': ' . $reason, [
        'trading_day_id' => (int) $day['id'],
        'till_session_id' => (int) $session['id'],
        'location_id' => $locationId,
        'operator_id' => pos_controls_actor_operator_id($actor),
        'hub_account_id' => pos_controls_actor_account_id($actor),
        'amount' => $amount,
    ]);
    return $id;
}

function pos_till_cash_movement_total(PDO $pdo, int $sessionId): float
{
    pos_controls_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN movement_type = 'paid_in' THEN amount ELSE -amount END), 0) FROM pos_cash_movements WHERE till_session_id = :id");
    $stmt->execute([':id' => $sessionId]);
    return round((float) $stmt->fetchColumn(), 2);
}

function pos_stock_adjust(PDO $pdo, int $productId, float $qtyDelta, string $reason, array $actor): void
{
    pos_controls_ensure_schema($pdo);
    $reason = trim($reason);
    $qtyDelta = round($qtyDelta, 2);
    if ($productId <= 0 || abs($qtyDelta) < 0.005) {
        throw new InvalidArgumentException('Select a product and enter a non-zero stock adjustment.');
    }
    if ($reason === '') {
        throw new InvalidArgumentException('A stock adjustment reason is required.');
    }
    $stmt = $pdo->prepare('SELECT id, name, stock_on_hand FROM pos_products WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        throw new RuntimeException('Product was not found.');
    }
    $pdo->beginTransaction();
    try {
        if ($product['stock_on_hand'] === null) {
            $pdo->prepare('UPDATE pos_products SET stock_on_hand = :qty WHERE id = :id')->execute([':qty' => max(0, $qtyDelta), ':id' => $productId]);
        } else {
            $pdo->prepare('UPDATE pos_products SET stock_on_hand = GREATEST(0, stock_on_hand + :delta) WHERE id = :id')->execute([':delta' => $qtyDelta, ':id' => $productId]);
        }
        $pdo->prepare('INSERT INTO pos_stock_movements (product_id, movement_type, qty_delta, reason) VALUES (:product_id, "adjustment", :qty_delta, :reason)')
            ->execute([':product_id' => $productId, ':qty_delta' => $qtyDelta, ':reason' => $reason]);
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }
    pos_audit_event($pdo, 'pos_stock_adjusted', 'Adjusted stock for ' . (string) $product['name'] . ' by ' . number_format($qtyDelta, 2) . ': ' . $reason, [
        'operator_id' => pos_controls_actor_operator_id($actor),
        'hub_account_id' => pos_controls_actor_account_id($actor),
    ]);
}

function pos_refundable_items(PDO $pdo, int $saleId): array
{
    pos_controls_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT i.*,
               COALESCE(SUM(ri.qty), 0) AS refunded_qty,
               COALESCE(SUM(ri.amount), 0) AS refunded_amount
        FROM pos_sale_items i
        LEFT JOIN pos_refund_items ri ON ri.original_sale_item_id = i.id
        WHERE i.sale_id = :sale_id
        GROUP BY i.id
        ORDER BY i.id
    ");
    $stmt->execute([':sale_id' => $saleId]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $qty = (float) $item['qty'];
        $lineTotal = (float) $item['line_total'];
        $refundedQty = (float) $item['refunded_qty'];
        $refundedAmount = (float) $item['refunded_amount'];
        $item['refundable_qty'] = max(0.0, $qty - $refundedQty);
        $item['refundable_amount'] = max(0.0, $lineTotal - $refundedAmount);
        $items[] = $item;
    }
    return $items;
}

function pos_reverse_points_for_refund(PDO $pdo, array $sale, int $refundSaleId, float $refundAmount): void
{
    $personId = (int) ($sale['person_id'] ?? 0);
    if ($personId <= 0 || (float) ($sale['total'] ?? 0) <= 0) {
        return;
    }
    $ratio = min(1.0, max(0.0, $refundAmount / (float) $sale['total']));
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(points_delta), 0) FROM member_points_ledger WHERE sale_id = :sale_id');
    $stmt->execute([':sale_id' => (int) $sale['id']]);
    $originalNet = (int) $stmt->fetchColumn();
    $reverse = (int) round($originalNet * -$ratio);
    if ($reverse === 0) {
        return;
    }
    $pdo->prepare('INSERT INTO member_points_ledger (person_id, holder_id, sale_id, points_delta, reason) VALUES (:person_id, :holder_id, :sale_id, :points_delta, :reason)')
        ->execute([
            ':person_id' => $personId,
            ':holder_id' => (int) ($sale['holder_id'] ?? 0) ?: null,
            ':sale_id' => $refundSaleId,
            ':points_delta' => $reverse,
            ':reason' => 'Reversed by POS refund',
        ]);
}

function pos_refund_sale(PDO $pdo, int $saleId, array $actor, string $reason, ?float $amount = null): int
{
    pos_controls_ensure_schema($pdo);
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('A refund reason is required.');
    }
    $saleStmt = $pdo->prepare('SELECT * FROM pos_sales WHERE id = :id LIMIT 1');
    $saleStmt->execute([':id' => $saleId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale || (string) $sale['status'] !== 'complete') {
        throw new RuntimeException('Only completed POS sales can be refunded.');
    }

    $amount = round($amount !== null ? max(0, $amount) : (float) $sale['total'], 2);
    if ($amount <= 0 || $amount > (float) $sale['total']) {
        throw new InvalidArgumentException('Refund amount must be greater than zero and no more than the sale total.');
    }

    $pdo->beginTransaction();
    try {
        $refundRef = (string) $sale['sale_ref'] . '-RF';
        $pdo->prepare('INSERT INTO pos_sales (sale_ref, trading_day_id, till_session_id, location_id, operator_id, hub_user_id, person_id, holder_name, subtotal, discount_total, points_redeemed, points_discount, total, payment_method, status, created_at, completed_at) VALUES (:sale_ref, :trading_day_id, :till_session_id, :location_id, :operator_id, :hub_user_id, :person_id, :holder_name, :subtotal, 0, 0, 0, :total, :payment_method, "refund", NOW(), NOW())')
            ->execute([
                ':sale_ref' => $refundRef . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
                ':trading_day_id' => (int) ($sale['trading_day_id'] ?? 0) ?: null,
                ':till_session_id' => (int) ($sale['till_session_id'] ?? 0) ?: null,
                ':location_id' => (int) $sale['location_id'],
                ':operator_id' => pos_controls_actor_operator_id($actor),
                ':hub_user_id' => (string) ($actor['type'] ?? '') === 'hub_user' ? (int) ($actor['id'] ?? 0) ?: null : null,
                ':person_id' => (int) ($sale['person_id'] ?? 0) ?: null,
                ':holder_name' => $sale['holder_name'] ?: null,
                ':subtotal' => -$amount,
                ':total' => -$amount,
                ':payment_method' => (string) $sale['payment_method'],
            ]);
        $refundSaleId = (int) $pdo->lastInsertId();
        pos_assign_receipt_number($pdo, $refundSaleId);
        $itemsStmt = $pdo->prepare('SELECT * FROM pos_sale_items WHERE sale_id = :sale_id ORDER BY id');
        $itemsStmt->execute([':sale_id' => $saleId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        $itemInsert = $pdo->prepare('INSERT INTO pos_sale_items (sale_id, original_sale_item_id, product_id, product_name, category_name, qty, unit_price, discount_amount, line_total) VALUES (:sale_id, :original_sale_item_id, :product_id, :product_name, :category_name, :qty, :unit_price, :discount_amount, :line_total)');
        $remaining = $amount;
        $originalTotal = max(0.01, (float) $sale['total']);
        foreach ($items as $index => $item) {
            $lineTotal = round($amount >= (float) $sale['total'] ? (float) $item['line_total'] : ((float) $item['line_total'] / $originalTotal) * $amount, 2);
            if ($index === array_key_last($items)) {
                $lineTotal = $remaining;
            }
            $remaining = round($remaining - $lineTotal, 2);
            if ($lineTotal <= 0) {
                continue;
            }
            $qtyRatio = min(1.0, $lineTotal / max(0.01, (float) $item['line_total']));
            $itemInsert->execute([
                ':sale_id' => $refundSaleId,
                ':original_sale_item_id' => (int) $item['id'],
                ':product_id' => (int) $item['product_id'],
                ':product_name' => (string) $item['product_name'],
                ':category_name' => (string) $item['category_name'],
                ':qty' => -round(((float) $item['qty']) * $qtyRatio, 2),
                ':unit_price' => (float) $item['unit_price'],
                ':discount_amount' => -round(((float) $item['discount_amount']) * $qtyRatio, 2),
                ':line_total' => -$lineTotal,
            ]);
        }
        $refundStmt = $pdo->prepare('INSERT INTO pos_refunds (original_sale_id, refund_sale_id, trading_day_id, till_session_id, amount, payment_method, reason, refunded_by_account_id, refunded_by_operator_id) VALUES (:original_sale_id, :refund_sale_id, :trading_day_id, :till_session_id, :amount, :payment_method, :reason, :account_id, :operator_id)');
        $refundStmt->execute([
            ':original_sale_id' => $saleId,
            ':refund_sale_id' => $refundSaleId,
            ':trading_day_id' => (int) ($sale['trading_day_id'] ?? 0) ?: null,
            ':till_session_id' => (int) ($sale['till_session_id'] ?? 0) ?: null,
            ':amount' => $amount,
            ':payment_method' => (string) $sale['payment_method'],
            ':reason' => $reason,
            ':account_id' => pos_controls_actor_account_id($actor),
            ':operator_id' => pos_controls_actor_operator_id($actor),
        ]);
        $refundId = (int) $pdo->lastInsertId();
        $refundItemStmt = $pdo->prepare('INSERT INTO pos_refund_items (refund_id, original_sale_item_id, product_id, qty, amount) VALUES (:refund_id, :original_sale_item_id, :product_id, :qty, :amount)');
        $refundLinesStmt = $pdo->prepare('SELECT * FROM pos_sale_items WHERE sale_id = :sale_id AND original_sale_item_id IS NOT NULL');
        $refundLinesStmt->execute([':sale_id' => $refundSaleId]);
        foreach ($refundLinesStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $refundItemStmt->execute([
                ':refund_id' => $refundId,
                ':original_sale_item_id' => (int) $line['original_sale_item_id'],
                ':product_id' => (int) $line['product_id'],
                ':qty' => abs((float) $line['qty']),
                ':amount' => abs((float) $line['line_total']),
            ]);
        }
        $pdo->prepare("UPDATE pos_sales SET status = 'refunded' WHERE id = :id AND status = 'complete'")->execute([':id' => $saleId]);
        pos_reverse_points_for_refund($pdo, $sale, $refundSaleId, $amount);
        pos_record_stock_for_sale($pdo, $refundSaleId, 'refund');
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    pos_audit_event($pdo, 'pos_sale_refunded', 'Refunded sale #' . $saleId . ' by ' . gbp($amount) . ': ' . $reason, [
        'trading_day_id' => (int) ($sale['trading_day_id'] ?? 0) ?: null,
        'till_session_id' => (int) ($sale['till_session_id'] ?? 0) ?: null,
        'location_id' => (int) $sale['location_id'],
        'operator_id' => pos_controls_actor_operator_id($actor),
        'hub_account_id' => pos_controls_actor_account_id($actor),
        'amount' => -$amount,
    ]);
    return $refundId;
}

function pos_refund_sale_items(PDO $pdo, int $saleId, array $actor, string $reason, array $itemQtys): int
{
    pos_controls_ensure_schema($pdo);
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('A refund reason is required.');
    }
    $saleStmt = $pdo->prepare('SELECT * FROM pos_sales WHERE id = :id LIMIT 1');
    $saleStmt->execute([':id' => $saleId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale || !in_array((string) $sale['status'], ['complete', 'refunded'], true)) {
        throw new RuntimeException('Only completed POS sales can be refunded.');
    }

    $refundable = pos_refundable_items($pdo, $saleId);
    $lines = [];
    foreach ($refundable as $item) {
        $requested = round(max(0, (float) ($itemQtys[(int) $item['id']] ?? 0)), 2);
        $qty = min($requested, (float) $item['refundable_qty']);
        if ($qty <= 0) {
            continue;
        }
        $unitRefund = (float) $item['line_total'] / max(0.01, (float) $item['qty']);
        $amount = min((float) $item['refundable_amount'], round($unitRefund * $qty, 2));
        if ($amount <= 0) {
            continue;
        }
        $lines[] = ['item' => $item, 'qty' => $qty, 'amount' => $amount];
    }
    if (!$lines) {
        throw new InvalidArgumentException('Select at least one refundable item quantity.');
    }

    $amount = round(array_sum(array_column($lines, 'amount')), 2);
    $pdo->beginTransaction();
    try {
        $refundRef = (string) $sale['sale_ref'] . '-RF';
        $pdo->prepare('INSERT INTO pos_sales (sale_ref, trading_day_id, till_session_id, location_id, operator_id, hub_user_id, person_id, holder_name, subtotal, discount_total, points_redeemed, points_discount, total, payment_method, status, created_at, completed_at) VALUES (:sale_ref, :trading_day_id, :till_session_id, :location_id, :operator_id, :hub_user_id, :person_id, :holder_name, :subtotal, 0, 0, 0, :total, :payment_method, "refund", NOW(), NOW())')
            ->execute([
                ':sale_ref' => $refundRef . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
                ':trading_day_id' => (int) ($sale['trading_day_id'] ?? 0) ?: null,
                ':till_session_id' => (int) ($sale['till_session_id'] ?? 0) ?: null,
                ':location_id' => (int) $sale['location_id'],
                ':operator_id' => pos_controls_actor_operator_id($actor),
                ':hub_user_id' => (string) ($actor['type'] ?? '') === 'hub_user' ? (int) ($actor['id'] ?? 0) ?: null : null,
                ':person_id' => (int) ($sale['person_id'] ?? 0) ?: null,
                ':holder_name' => $sale['holder_name'] ?: null,
                ':subtotal' => -$amount,
                ':total' => -$amount,
                ':payment_method' => (string) $sale['payment_method'],
            ]);
        $refundSaleId = (int) $pdo->lastInsertId();
        pos_assign_receipt_number($pdo, $refundSaleId);
        $refundStmt = $pdo->prepare('INSERT INTO pos_refunds (original_sale_id, refund_sale_id, trading_day_id, till_session_id, amount, payment_method, reason, refunded_by_account_id, refunded_by_operator_id) VALUES (:original_sale_id, :refund_sale_id, :trading_day_id, :till_session_id, :amount, :payment_method, :reason, :account_id, :operator_id)');
        $refundStmt->execute([
            ':original_sale_id' => $saleId,
            ':refund_sale_id' => $refundSaleId,
            ':trading_day_id' => (int) ($sale['trading_day_id'] ?? 0) ?: null,
            ':till_session_id' => (int) ($sale['till_session_id'] ?? 0) ?: null,
            ':amount' => $amount,
            ':payment_method' => (string) $sale['payment_method'],
            ':reason' => $reason,
            ':account_id' => pos_controls_actor_account_id($actor),
            ':operator_id' => pos_controls_actor_operator_id($actor),
        ]);
        $refundId = (int) $pdo->lastInsertId();
        $itemInsert = $pdo->prepare('INSERT INTO pos_sale_items (sale_id, original_sale_item_id, product_id, product_name, category_name, qty, unit_price, discount_amount, line_total) VALUES (:sale_id, :original_sale_item_id, :product_id, :product_name, :category_name, :qty, :unit_price, :discount_amount, :line_total)');
        $refundItemInsert = $pdo->prepare('INSERT INTO pos_refund_items (refund_id, original_sale_item_id, product_id, qty, amount) VALUES (:refund_id, :original_sale_item_id, :product_id, :qty, :amount)');
        foreach ($lines as $line) {
            $item = $line['item'];
            $ratio = (float) $line['qty'] / max(0.01, (float) $item['qty']);
            $itemInsert->execute([
                ':sale_id' => $refundSaleId,
                ':original_sale_item_id' => (int) $item['id'],
                ':product_id' => (int) $item['product_id'],
                ':product_name' => (string) $item['product_name'],
                ':category_name' => (string) $item['category_name'],
                ':qty' => -$line['qty'],
                ':unit_price' => (float) $item['unit_price'],
                ':discount_amount' => -round((float) $item['discount_amount'] * $ratio, 2),
                ':line_total' => -$line['amount'],
            ]);
            $refundItemInsert->execute([
                ':refund_id' => $refundId,
                ':original_sale_item_id' => (int) $item['id'],
                ':product_id' => (int) $item['product_id'],
                ':qty' => $line['qty'],
                ':amount' => $line['amount'],
            ]);
        }
        $remaining = array_sum(array_map(static fn(array $item): float => (float) $item['refundable_qty'], pos_refundable_items($pdo, $saleId)));
        $pdo->prepare("UPDATE pos_sales SET status = :status WHERE id = :id AND status IN ('complete', 'refunded')")
            ->execute([':id' => $saleId, ':status' => $remaining <= 0.005 ? 'refunded' : 'complete']);
        pos_reverse_points_for_refund($pdo, $sale, $refundSaleId, $amount);
        pos_record_stock_for_sale($pdo, $refundSaleId, 'refund');
        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    pos_audit_event($pdo, 'pos_sale_refunded', 'Refunded sale #' . $saleId . ' by ' . gbp($amount) . ': ' . $reason, [
        'trading_day_id' => (int) ($sale['trading_day_id'] ?? 0) ?: null,
        'till_session_id' => (int) ($sale['till_session_id'] ?? 0) ?: null,
        'location_id' => (int) $sale['location_id'],
        'operator_id' => pos_controls_actor_operator_id($actor),
        'hub_account_id' => pos_controls_actor_account_id($actor),
        'amount' => -$amount,
    ]);
    return $refundId;
}
