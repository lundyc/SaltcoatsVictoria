<?php
declare(strict_types=1);

function pos_trading_day_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_trading_days (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        business_date DATE NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'open',
        opening_float DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        closing_notes VARCHAR(255) NULL,
        opened_by_account_id INT UNSIGNED NULL,
        opened_by_name VARCHAR(190) NULL,
        opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_by_account_id INT UNSIGNED NULL,
        closed_by_name VARCHAR(190) NULL,
        closed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pos_trading_days_status_date (status, business_date),
        KEY idx_pos_trading_days_opened_by (opened_by_account_id),
        KEY idx_pos_trading_days_closed_by (closed_by_account_id),
        CONSTRAINT fk_pos_trading_days_opened_by FOREIGN KEY (opened_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_trading_days_closed_by FOREIGN KEY (closed_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM pos_sales') as $row) {
        $columns[(string) $row['Field']] = true;
    }
    if (!isset($columns['trading_day_id'])) {
        $pdo->exec('ALTER TABLE pos_sales ADD COLUMN trading_day_id BIGINT UNSIGNED NULL AFTER sale_ref, ADD KEY idx_pos_sales_trading_day (trading_day_id)');
        $pdo->exec('ALTER TABLE pos_sales ADD CONSTRAINT fk_pos_sales_trading_day FOREIGN KEY (trading_day_id) REFERENCES pos_trading_days(id) ON DELETE SET NULL');
    }
    if (!isset($columns['till_session_id'])) {
        $pdo->exec('ALTER TABLE pos_sales ADD COLUMN till_session_id BIGINT UNSIGNED NULL AFTER trading_day_id, ADD KEY idx_pos_sales_till_session (till_session_id)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_till_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        trading_day_id BIGINT UNSIGNED NOT NULL,
        location_id INT UNSIGNED NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'open',
        opening_float DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        counted_cash DECIMAL(10,2) NULL,
        expected_cash DECIMAL(10,2) NULL,
        cash_variance DECIMAL(10,2) NULL,
        closing_notes VARCHAR(255) NULL,
        opened_by_account_id INT UNSIGNED NULL,
        opened_by_name VARCHAR(190) NULL,
        opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_by_account_id INT UNSIGNED NULL,
        closed_by_name VARCHAR(190) NULL,
        closed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pos_till_sessions_day_location (trading_day_id, location_id, status),
        KEY idx_pos_till_sessions_location (location_id),
        KEY idx_pos_till_sessions_opened_by (opened_by_account_id),
        KEY idx_pos_till_sessions_closed_by (closed_by_account_id),
        CONSTRAINT fk_pos_till_sessions_day FOREIGN KEY (trading_day_id) REFERENCES pos_trading_days(id) ON DELETE CASCADE,
        CONSTRAINT fk_pos_till_sessions_location FOREIGN KEY (location_id) REFERENCES pos_locations(id) ON DELETE RESTRICT,
        CONSTRAINT fk_pos_till_sessions_opened_by FOREIGN KEY (opened_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
        CONSTRAINT fk_pos_till_sessions_closed_by FOREIGN KEY (closed_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $constraints = [];
    foreach ($pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_sales' AND COLUMN_NAME = 'till_session_id' AND REFERENCED_TABLE_NAME = 'pos_till_sessions'") as $row) {
        $constraints[(string) $row['CONSTRAINT_NAME']] = true;
    }
    if (!$constraints) {
        $pdo->exec('ALTER TABLE pos_sales ADD CONSTRAINT fk_pos_sales_till_session FOREIGN KEY (till_session_id) REFERENCES pos_till_sessions(id) ON DELETE SET NULL');
    }

    $done = true;
}

function pos_trading_day_business_date(?string $date = null): string
{
    $date = trim((string) $date);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
}

function pos_trading_day_actor_account_id(array $actor): ?int
{
    $accountId = (int) ($actor['account_id'] ?? 0);
    return $accountId > 0 ? $accountId : null;
}

function pos_trading_day_active(PDO $pdo): ?array
{
    pos_trading_day_ensure_schema($pdo);
    $stmt = $pdo->query("SELECT * FROM pos_trading_days WHERE status = 'open' ORDER BY opened_at DESC, id DESC LIMIT 1");
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pos_trading_day_open(PDO $pdo, array $actor, string $businessDate, float $openingFloat = 0.0): int
{
    pos_trading_day_ensure_schema($pdo);
    if (pos_trading_day_active($pdo)) {
        throw new RuntimeException('A POS day is already open.');
    }

    $stmt = $pdo->prepare('INSERT INTO pos_trading_days (business_date, status, opening_float, opened_by_account_id, opened_by_name, opened_at) VALUES (:business_date, "open", :opening_float, :account_id, :actor_name, NOW())');
    $stmt->execute([
        ':business_date' => pos_trading_day_business_date($businessDate),
        ':opening_float' => max(0, $openingFloat),
        ':account_id' => pos_trading_day_actor_account_id($actor),
        ':actor_name' => (string) ($actor['name'] ?? ''),
    ]);

    return (int) $pdo->lastInsertId();
}

function pos_trading_day_close(PDO $pdo, int $id, array $actor, string $notes = ''): void
{
    pos_trading_day_ensure_schema($pdo);
    if (pos_till_session_open_count($pdo, $id) > 0) {
        throw new RuntimeException('Close all open tills before closing the POS day.');
    }

    $stmt = $pdo->prepare("UPDATE pos_trading_days SET status = 'closed', closed_by_account_id = :account_id, closed_by_name = :actor_name, closed_at = NOW(), closing_notes = :notes WHERE id = :id AND status = 'open'");
    $stmt->execute([
        ':id' => $id,
        ':account_id' => pos_trading_day_actor_account_id($actor),
        ':actor_name' => (string) ($actor['name'] ?? ''),
        ':notes' => trim($notes) !== '' ? trim($notes) : null,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('There is no open POS day to close.');
    }
}

function pos_trading_day_require_open(PDO $pdo): array
{
    $day = pos_trading_day_active($pdo);
    if (!$day) {
        throw new RuntimeException('The POS day has not been opened.');
    }
    return $day;
}

function pos_till_session_open_count(PDO $pdo, int $tradingDayId): int
{
    pos_trading_day_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM pos_till_sessions WHERE trading_day_id = :trading_day_id AND status = 'open'");
    $stmt->execute([':trading_day_id' => $tradingDayId]);
    return (int) $stmt->fetchColumn();
}

function pos_till_session_active(PDO $pdo, int $tradingDayId, int $locationId): ?array
{
    pos_trading_day_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM pos_till_sessions WHERE trading_day_id = :trading_day_id AND location_id = :location_id AND status = 'open' ORDER BY opened_at DESC, id DESC LIMIT 1");
    $stmt->execute([':trading_day_id' => $tradingDayId, ':location_id' => $locationId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function pos_till_session_require_open(PDO $pdo, int $tradingDayId, int $locationId): array
{
    $session = pos_till_session_active($pdo, $tradingDayId, $locationId);
    if (!$session) {
        throw new RuntimeException('This till has not been opened for the current POS day.');
    }
    return $session;
}

function pos_till_session_open(PDO $pdo, int $tradingDayId, int $locationId, array $actor, float $openingFloat): int
{
    pos_trading_day_ensure_schema($pdo);
    $dayStmt = $pdo->prepare("SELECT id FROM pos_trading_days WHERE id = :id AND status = 'open' LIMIT 1");
    $dayStmt->execute([':id' => $tradingDayId]);
    if (!$dayStmt->fetchColumn()) {
        throw new RuntimeException('Open the POS day before opening tills.');
    }
    if (pos_till_session_active($pdo, $tradingDayId, $locationId)) {
        throw new RuntimeException('This till is already open.');
    }

    $stmt = $pdo->prepare('INSERT INTO pos_till_sessions (trading_day_id, location_id, status, opening_float, opened_by_account_id, opened_by_name, opened_at) VALUES (:trading_day_id, :location_id, "open", :opening_float, :account_id, :actor_name, NOW())');
    $stmt->execute([
        ':trading_day_id' => $tradingDayId,
        ':location_id' => $locationId,
        ':opening_float' => max(0, $openingFloat),
        ':account_id' => pos_trading_day_actor_account_id($actor),
        ':actor_name' => (string) ($actor['name'] ?? ''),
    ]);

    return (int) $pdo->lastInsertId();
}

function pos_till_session_expected_cash(PDO $pdo, int $sessionId): float
{
    pos_trading_day_ensure_schema($pdo);
    $hasCashMovements = (bool) $pdo->query("SHOW TABLES LIKE 'pos_cash_movements'")->fetchColumn();
    $movementSql = $hasCashMovements ? "
            + COALESCE((
                SELECT SUM(CASE WHEN cm.movement_type = 'paid_in' THEN cm.amount ELSE -cm.amount END)
                FROM pos_cash_movements cm
                WHERE cm.till_session_id = ts.id
            ), 0)" : '';
    $stmt = $pdo->prepare("
        SELECT ts.opening_float
            + COALESCE(SUM(CASE WHEN s.status IN ('complete', 'refund') AND s.payment_method = 'cash' THEN s.total ELSE 0 END), 0)
            " . $movementSql . " AS expected_cash
        FROM pos_till_sessions ts
        LEFT JOIN pos_sales s ON s.till_session_id = ts.id
        WHERE ts.id = :id
        GROUP BY ts.id, ts.opening_float
    ");
    $stmt->execute([':id' => $sessionId]);
    return round((float) $stmt->fetchColumn(), 2);
}

function pos_till_session_close(PDO $pdo, int $sessionId, array $actor, float $countedCash, string $notes = ''): void
{
    pos_trading_day_ensure_schema($pdo);
    $pendingStmt = $pdo->prepare("SELECT COUNT(*) FROM pos_sales WHERE till_session_id = :id AND status IN ('pending_card', 'pending_cash')");
    $pendingStmt->execute([':id' => $sessionId]);
    if ((int) $pendingStmt->fetchColumn() > 0) {
        throw new RuntimeException('Complete or cancel pending sales before closing this till.');
    }

    $expectedCash = pos_till_session_expected_cash($pdo, $sessionId);
    $countedCash = max(0, round($countedCash, 2));
    $variance = round($countedCash - $expectedCash, 2);
    $stmt = $pdo->prepare("UPDATE pos_till_sessions SET status = 'closed', counted_cash = :counted_cash, expected_cash = :expected_cash, cash_variance = :cash_variance, closed_by_account_id = :account_id, closed_by_name = :actor_name, closed_at = NOW(), closing_notes = :notes WHERE id = :id AND status = 'open'");
    $stmt->execute([
        ':id' => $sessionId,
        ':counted_cash' => $countedCash,
        ':expected_cash' => $expectedCash,
        ':cash_variance' => $variance,
        ':account_id' => pos_trading_day_actor_account_id($actor),
        ':actor_name' => (string) ($actor['name'] ?? ''),
        ':notes' => trim($notes) !== '' ? trim($notes) : null,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('There is no open till session to close.');
    }
}

function pos_till_sessions_for_day(PDO $pdo, int $tradingDayId): array
{
    pos_trading_day_ensure_schema($pdo);
    $stmt = $pdo->prepare("
        SELECT ts.*, l.name AS location_name,
               COUNT(s.id) AS sales_count,
               COALESCE(SUM(CASE WHEN s.status = 'complete' THEN s.total ELSE 0 END), 0) AS total,
               COALESCE(SUM(CASE WHEN s.status = 'complete' AND s.payment_method = 'cash' THEN s.total ELSE 0 END), 0) AS cash_sales
        FROM pos_till_sessions ts
        JOIN pos_locations l ON l.id = ts.location_id
        LEFT JOIN pos_sales s ON s.till_session_id = ts.id
        WHERE ts.trading_day_id = :trading_day_id
        GROUP BY ts.id, l.name
        ORDER BY ts.opened_at DESC, ts.id DESC
    ");
    $stmt->execute([':trading_day_id' => $tradingDayId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
