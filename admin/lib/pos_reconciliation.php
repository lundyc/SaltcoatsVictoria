<?php
declare(strict_types=1);

function pos_reconciliation_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_cashup_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        location_id INT UNSIGNED NOT NULL,
        business_date DATE NOT NULL,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'cash',
        expected_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        counted_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        notes VARCHAR(255) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'closed',
        counted_by_account_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pos_cashup_location_date (location_id, business_date),
        KEY idx_pos_cashup_method (payment_method),
        CONSTRAINT fk_pos_cashup_location FOREIGN KEY (location_id) REFERENCES pos_locations(id) ON DELETE RESTRICT,
        CONSTRAINT fk_pos_cashup_account FOREIGN KEY (counted_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function pos_reconciliation_business_date(?string $date = null): string
{
    $date = trim((string) $date);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
}

function pos_reconciliation_payment_label(string $method): string
{
    return match ($method) {
        'cash' => 'Cash',
        'card' => 'Card',
        'card_stripe_app' => 'Card - Stripe app',
        default => ucwords(str_replace('_', ' ', $method)),
    };
}

function pos_reconciliation_variance(float $expected, float $counted): float
{
    return round($counted - $expected, 2);
}

function pos_reconciliation_variance_state(float $variance): string
{
    if (abs($variance) < 0.005) {
        return 'balanced';
    }

    return $variance > 0 ? 'over' : 'short';
}

function pos_reconciliation_sales_totals(PDO $pdo, string $businessDate, ?int $locationId = null): array
{
    pos_reconciliation_ensure_schema($pdo);
    $businessDate = pos_reconciliation_business_date($businessDate);
    $where = ['DATE(s.created_at) = :business_date', 's.status = "complete"'];
    $params = [':business_date' => $businessDate];
    if ($locationId !== null && $locationId > 0) {
        $where[] = 's.location_id = :location_id';
        $params[':location_id'] = $locationId;
    }

    $stmt = $pdo->prepare('SELECT s.payment_method, COUNT(*) AS sales_count, COALESCE(SUM(s.total), 0) AS amount
        FROM pos_sales s
        WHERE ' . implode(' AND ', $where) . '
        GROUP BY s.payment_method
        ORDER BY s.payment_method');
    $stmt->execute($params);

    $totals = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $method = (string) $row['payment_method'];
        $totals[$method] = [
            'payment_method' => $method,
            'label' => pos_reconciliation_payment_label($method),
            'sales_count' => (int) $row['sales_count'],
            'amount' => round((float) $row['amount'], 2),
        ];
    }

    return $totals;
}

function pos_reconciliation_expected_amount(array $totals, string $paymentMethod): float
{
    return round((float) ($totals[$paymentMethod]['amount'] ?? 0), 2);
}

function pos_reconciliation_save(PDO $pdo, array $data, ?int $accountId = null): int
{
    pos_reconciliation_ensure_schema($pdo);
    $locationId = max(0, (int) ($data['location_id'] ?? 0));
    $businessDate = pos_reconciliation_business_date((string) ($data['business_date'] ?? ''));
    $paymentMethod = trim((string) ($data['payment_method'] ?? 'cash'));
    $countedAmount = round(max(0, (float) ($data['counted_amount'] ?? 0)), 2);
    $notes = trim((string) ($data['notes'] ?? ''));

    if ($locationId <= 0) {
        throw new InvalidArgumentException('A POS location is required.');
    }
    if ($paymentMethod === '') {
        throw new InvalidArgumentException('A payment method is required.');
    }

    $totals = pos_reconciliation_sales_totals($pdo, $businessDate, $locationId);
    $expectedAmount = pos_reconciliation_expected_amount($totals, $paymentMethod);
    $stmt = $pdo->prepare('INSERT INTO pos_cashup_sessions
        (location_id, business_date, payment_method, expected_amount, counted_amount, notes, status, counted_by_account_id)
        VALUES (:location_id, :business_date, :payment_method, :expected_amount, :counted_amount, :notes, "closed", :account_id)');
    $stmt->execute([
        ':location_id' => $locationId,
        ':business_date' => $businessDate,
        ':payment_method' => $paymentMethod,
        ':expected_amount' => $expectedAmount,
        ':counted_amount' => $countedAmount,
        ':notes' => $notes !== '' ? $notes : null,
        ':account_id' => $accountId && $accountId > 0 ? $accountId : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function pos_reconciliation_recent(PDO $pdo, string $businessDate, int $limit = 20): array
{
    pos_reconciliation_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT c.*, l.name AS location_name, a.email AS counted_by_email, p.display_name AS counted_by_name
        FROM pos_cashup_sessions c
        JOIN pos_locations l ON l.id = c.location_id
        LEFT JOIN accounts a ON a.id = c.counted_by_account_id
        LEFT JOIN people p ON p.id = a.person_id
        WHERE c.business_date = :business_date
        ORDER BY c.created_at DESC, c.id DESC
        LIMIT ' . max(1, min(100, $limit)));
    $stmt->execute([':business_date' => pos_reconciliation_business_date($businessDate)]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['expected_amount'] = round((float) $row['expected_amount'], 2);
        $row['counted_amount'] = round((float) $row['counted_amount'], 2);
        $row['variance_amount'] = pos_reconciliation_variance((float) $row['expected_amount'], (float) $row['counted_amount']);
        $row['variance_state'] = pos_reconciliation_variance_state((float) $row['variance_amount']);
    }

    return $rows;
}
