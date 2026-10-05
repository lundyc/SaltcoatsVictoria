<?php
declare(strict_types=1);

require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/season_tickets.php';
require_once __DIR__ . '/admissions.php';

function ensureMatchTicketSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ensureSeasonTicketSchema($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS match_ticket_packages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        code VARCHAR(60) NOT NULL,
        description VARCHAR(255) NULL,
        default_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        package_group VARCHAR(60) NOT NULL DEFAULT 'General',
        admits_count INT UNSIGNED NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_match_ticket_packages_code (code),
        KEY idx_match_ticket_packages_active (is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fixture_ticket_packages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        fixture_id INT UNSIGNED NOT NULL,
        package_id INT UNSIGNED NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        allocation INT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_fixture_ticket_packages_fixture_package (fixture_id, package_id),
        KEY idx_fixture_ticket_packages_fixture (fixture_id, is_active),
        CONSTRAINT fk_fixture_ticket_packages_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE,
        CONSTRAINT fk_fixture_ticket_packages_package FOREIGN KEY (package_id) REFERENCES match_ticket_packages(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS match_ticket_orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        buyer_holder_id INT UNSIGNED NULL,
        buyer_name VARCHAR(190) NOT NULL,
        buyer_email VARCHAR(190) NOT NULL,
        buyer_phone VARCHAR(60) NULL,
        fixture_id INT UNSIGNED NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        paid TINYINT(1) NOT NULL DEFAULT 0,
        paid_at DATETIME NULL,
        payment_method VARCHAR(40) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
        checkout_mode VARCHAR(30) NOT NULL DEFAULT 'guest',
        group_token VARCHAR(64) NOT NULL,
        stripe_checkout_session_id VARCHAR(190) NULL,
        confirmation_email_sent_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        cancelled_by_user_id INT UNSIGNED NULL,
        cancel_reason TEXT NULL,
        refunded_at DATETIME NULL,
        refunded_by_user_id INT UNSIGNED NULL,
        refund_reason TEXT NULL,
        refund_amount DECIMAL(10,2) NULL,
        stripe_refund_id VARCHAR(190) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_match_ticket_orders_group_token (group_token),
        KEY idx_match_ticket_orders_fixture (fixture_id, status),
        KEY idx_match_ticket_orders_buyer_holder (buyer_holder_id),
        KEY idx_match_ticket_orders_stripe_session (stripe_checkout_session_id),
        CONSTRAINT fk_match_ticket_orders_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE,
        CONSTRAINT fk_match_ticket_orders_holder FOREIGN KEY (buyer_holder_id) REFERENCES season_ticket_holders(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS match_ticket_order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        fixture_package_id INT UNSIGNED NOT NULL,
        package_id INT UNSIGNED NOT NULL,
        package_name VARCHAR(120) NOT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        admits_count INT UNSIGNED NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_match_ticket_order_items_order (order_id),
        CONSTRAINT fk_match_ticket_order_items_order FOREIGN KEY (order_id) REFERENCES match_ticket_orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_match_ticket_order_items_fixture_package FOREIGN KEY (fixture_package_id) REFERENCES fixture_ticket_packages(id) ON DELETE RESTRICT,
        CONSTRAINT fk_match_ticket_order_items_package FOREIGN KEY (package_id) REFERENCES match_ticket_packages(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS match_tickets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        order_item_id INT UNSIGNED NOT NULL,
        fixture_id INT UNSIGNED NOT NULL,
        package_id INT UNSIGNED NOT NULL,
        ticket_label VARCHAR(120) NOT NULL,
        ticket_token VARCHAR(64) NOT NULL,
        manual_code VARCHAR(9) NOT NULL,
        admits_count INT UNSIGNED NOT NULL DEFAULT 1,
        checked_in_at DATETIME NULL,
        checked_in_by_user_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_match_tickets_token (ticket_token),
        UNIQUE KEY uq_match_tickets_manual_code (manual_code),
        KEY idx_match_tickets_order (order_id),
        KEY idx_match_tickets_fixture (fixture_id, checked_in_at),
        CONSTRAINT fk_match_tickets_order FOREIGN KEY (order_id) REFERENCES match_ticket_orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_match_tickets_order_item FOREIGN KEY (order_item_id) REFERENCES match_ticket_order_items(id) ON DELETE CASCADE,
        CONSTRAINT fk_match_tickets_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE,
        CONSTRAINT fk_match_tickets_package FOREIGN KEY (package_id) REFERENCES match_ticket_packages(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS match_ticket_attendance (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        fixture_id INT UNSIGNED NOT NULL,
        ticket_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        scanned_by_user_id INT UNSIGNED NULL,
        scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        scan_source VARCHAR(30) NOT NULL DEFAULT 'match_ticket_qr',
        PRIMARY KEY (id),
        UNIQUE KEY uq_match_ticket_attendance_fixture_ticket (fixture_id, ticket_id),
        KEY idx_match_ticket_attendance_fixture (fixture_id, scanned_at),
        CONSTRAINT fk_match_ticket_attendance_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE,
        CONSTRAINT fk_match_ticket_attendance_ticket FOREIGN KEY (ticket_id) REFERENCES match_tickets(id) ON DELETE CASCADE,
        CONSTRAINT fk_match_ticket_attendance_order FOREIGN KEY (order_id) REFERENCES match_ticket_orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM season_ticket_holders') as $row) {
        $columns[(string) $row['Field']] = true;
    }
    if (!isset($columns['member_access_level'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN member_access_level VARCHAR(30) NOT NULL DEFAULT 'ticket_member' AFTER password_hash");
    }

    $orderColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM match_ticket_orders') as $row) {
        $orderColumns[(string) $row['Field']] = true;
    }
    $orderColumnDefs = [
        'confirmation_email_sent_at' => 'DATETIME NULL AFTER stripe_checkout_session_id',
        'cancelled_at' => 'DATETIME NULL AFTER confirmation_email_sent_at',
        'cancelled_by_user_id' => 'INT UNSIGNED NULL AFTER cancelled_at',
        'cancel_reason' => 'TEXT NULL AFTER cancelled_by_user_id',
        'refunded_at' => 'DATETIME NULL AFTER cancel_reason',
        'refunded_by_user_id' => 'INT UNSIGNED NULL AFTER refunded_at',
        'refund_reason' => 'TEXT NULL AFTER refunded_by_user_id',
        'refund_amount' => 'DECIMAL(10,2) NULL AFTER refund_reason',
        'stripe_refund_id' => 'VARCHAR(190) NULL AFTER refund_amount',
    ];
    foreach ($orderColumnDefs as $column => $definition) {
        if (!isset($orderColumns[$column])) {
            $pdo->exec("ALTER TABLE match_ticket_orders ADD COLUMN {$column} {$definition}");
        }
    }

    $ticketColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM match_tickets') as $row) {
        $ticketColumns[(string) $row['Field']] = true;
    }
    if (!isset($ticketColumns['entitlement_id'])) {
        $pdo->exec('ALTER TABLE match_tickets ADD COLUMN entitlement_id INT UNSIGNED NULL AFTER id, ADD UNIQUE KEY uq_match_tickets_entitlement (entitlement_id), ADD KEY idx_match_tickets_entitlement (entitlement_id)');
    }
    $ticketNullability = [];
    foreach ($pdo->query('SHOW COLUMNS FROM match_tickets') as $row) {
        $ticketNullability[(string) $row['Field']] = (string) $row['Null'];
    }
    if (($ticketNullability['order_id'] ?? 'NO') === 'NO') {
        $pdo->exec('ALTER TABLE match_tickets MODIFY order_id INT UNSIGNED NULL');
    }
    if (($ticketNullability['order_item_id'] ?? 'NO') === 'NO') {
        $pdo->exec('ALTER TABLE match_tickets MODIFY order_item_id INT UNSIGNED NULL');
    }
    if (($ticketNullability['ticket_token'] ?? 'NO') === 'NO') {
        $pdo->exec('ALTER TABLE match_tickets MODIFY ticket_token VARCHAR(64) NULL');
    }
    if (($ticketNullability['manual_code'] ?? 'NO') === 'NO') {
        $pdo->exec('ALTER TABLE match_tickets MODIFY manual_code VARCHAR(20) NULL');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_access_tokens (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        token VARCHAR(80) NOT NULL,
        purpose VARCHAR(60) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL,
        revoked_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_order_access_tokens_token (token),
        KEY idx_order_access_tokens_order_purpose (order_id, purpose),
        CONSTRAINT fk_order_access_tokens_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    ensureAdmissionsSchema($pdo);

    match_tickets_seed_default_packages($pdo);
    $done = true;
}

function match_tickets_seed_default_packages(PDO $pdo): void
{
    $packages = [
        ['Adult', 'adult', 'General admission adult ticket.', 8.00, 'General', 1, 1, 10],
        ['Concession', 'concession', 'General admission concession ticket.', 5.00, 'General', 1, 1, 20],
        ['Child', 'child', 'General admission child ticket.', 3.00, 'General', 1, 1, 30],
        ['Family Ticket', 'family', 'Two adults and two children.', 18.00, 'General', 4, 0, 40],
        ['Hospitality', 'hospitality', 'Matchday hospitality ticket.', 35.00, 'Hospitality', 1, 1, 50],
    ];
    $stmt = $pdo->prepare("INSERT INTO match_ticket_packages
        (name, code, description, default_price, package_group, admits_count, is_active, sort_order)
        VALUES (:name, :code, :description, :price, :group_name, :admits, :active, :sort_order)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            default_price = VALUES(default_price),
            package_group = VALUES(package_group),
            admits_count = VALUES(admits_count),
            is_active = VALUES(is_active),
            sort_order = VALUES(sort_order)");
    foreach ($packages as $package) {
        $stmt->execute([
            ':name' => $package[0],
            ':code' => $package[1],
            ':description' => $package[2],
            ':price' => $package[3],
            ':group_name' => $package[4],
            ':admits' => $package[5],
            ':active' => $package[6],
            ':sort_order' => $package[7],
        ]);
    }
}

function match_ticket_generate_manual_code(PDO $pdo): string
{
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    do {
        $code = '';
        for ($i = 0; $i < 7; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $formatted = substr($code, 0, 3) . '-' . substr($code, 3);
        $stmt = $pdo->prepare('SELECT 1 FROM match_tickets WHERE manual_code = :code LIMIT 1');
        $stmt->execute([':code' => $formatted]);
        $credentialStmt = $pdo->prepare('SELECT 1 FROM ticket_credentials WHERE manual_code = :code LIMIT 1');
        $credentialStmt->execute([':code' => $formatted]);
    } while ($stmt->fetchColumn() || $credentialStmt->fetchColumn());
    return $formatted;
}

function match_ticket_normalize_manual_code(string $input): string
{
    $clean = strtoupper(trim($input));
    $clean = preg_replace('/[^A-Z0-9]/', '', $clean) ?? '';
    return strlen($clean) === 7 ? substr($clean, 0, 3) . '-' . substr($clean, 3) : '';
}

function match_ticket_extract_token(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    $parts = parse_url($input);
    if (is_array($parts) && isset($parts['query'])) {
        parse_str((string) $parts['query'], $query);
        foreach (['access', 'ticket', 'group', 'token'] as $key) {
            if (!empty($query[$key]) && is_string($query[$key])) {
                return preg_match('/^[a-f0-9]{32,64}$/i', $query[$key]) ? strtolower($query[$key]) : '';
            }
        }
    }
    return preg_match('/^[a-f0-9]{32,64}$/i', $input) ? strtolower($input) : '';
}

function matchTicketOrderStatusFromLegacy(array $legacy): string
{
    $status = (string) ($legacy['status'] ?? '');
    if (in_array($status, ['cancelled', 'refunded'], true)) {
        return $status;
    }
    return (int) ($legacy['paid'] ?? 0) === 1 ? 'paid' : 'pending_payment';
}

function matchTicketEntitlementStatusFromLegacy(array $legacy): string
{
    $status = (string) ($legacy['status'] ?? '');
    if (in_array($status, ['cancelled', 'refunded'], true)) {
        return $status;
    }
    return (int) ($legacy['paid'] ?? 0) === 1 && $status === 'complete' ? 'active' : 'pending';
}

function matchTicketProviderForMethod(string $method): string
{
    return $method === 'stripe' ? 'stripe' : (in_array($method, ['cash', 'bank_transfer', 'other', ''], true) ? 'manual' : 'manual');
}

function matchTicketNewToken(PDO $pdo, string $table, string $column, int $bytes = 24): string
{
    do {
        $token = bin2hex(random_bytes($bytes));
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$column} = :token LIMIT 1");
        $stmt->execute([':token' => $token]);
    } while ($stmt->fetchColumn());
    return $token;
}

function ensureOrderAccessToken(PDO $pdo, int $orderId, string $purpose = 'public_ticket_order'): string
{
    ensureMatchTicketSchema($pdo);
    $stmt = $pdo->prepare('SELECT token FROM order_access_tokens WHERE order_id = :order_id AND purpose = :purpose AND revoked_at IS NULL ORDER BY id DESC LIMIT 1');
    $stmt->execute([':order_id' => $orderId, ':purpose' => $purpose]);
    $existing = $stmt->fetchColumn();
    if ($existing !== false && (string) $existing !== '') {
        return (string) $existing;
    }
    $token = matchTicketNewToken($pdo, 'order_access_tokens', 'token', 24);
    $pdo->prepare('INSERT INTO order_access_tokens (order_id, token, purpose) VALUES (:order_id, :token, :purpose)')
        ->execute([':order_id' => $orderId, ':token' => $token, ':purpose' => $purpose]);
    return $token;
}

function matchTicketMetadata(array $row): array
{
    $json = (string) ($row['metadata_json'] ?? '');
    if ($json === '') {
        return [];
    }
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        return [];
    }
}

function matchTicketRowsWithAccessTokens(PDO $pdo, array $rows): array
{
    foreach ($rows as &$row) {
        if (empty($row['access_token']) && !empty($row['id'])) {
            $row['access_token'] = ensureOrderAccessToken($pdo, (int) $row['id']);
        }
        if (isset($row['status'])) {
            $row['status'] = matchTicketPublicStatus((string) $row['status']);
        }
    }
    unset($row);
    return $rows;
}

function migrateMatchTicketsToEntitlements(PDO $pdo): array
{
    ensureMatchTicketSchema($pdo);
    $orders = $pdo->query("SELECT o.*, f.match_date
        FROM match_ticket_orders o
        JOIN match_fixtures f ON f.id = o.fixture_id
        WHERE EXISTS (SELECT 1 FROM match_tickets t WHERE t.order_id = o.id)
        ORDER BY o.id")->fetchAll(PDO::FETCH_ASSOC);
    $createdOrders = 0;
    $createdTickets = 0;

    foreach ($orders as $legacy) {
        $legacyOrderId = (int) $legacy['id'];
        $exists = $pdo->prepare('SELECT order_id FROM match_ticket_migration_map WHERE legacy_order_id = :id LIMIT 1');
        $exists->execute([':id' => $legacyOrderId]);
        if ($exists->fetchColumn()) {
            continue;
        }

        $legacyItems = getMatchTicketOrderItems($pdo, $legacyOrderId);
        $legacyTicketsStmt = $pdo->prepare('SELECT * FROM match_tickets WHERE order_id = :order_id ORDER BY id ASC');
        $legacyTicketsStmt->execute([':order_id' => $legacyOrderId]);
        $legacyTickets = $legacyTicketsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$legacyTickets) {
            continue;
        }

        $orderStatus = matchTicketOrderStatusFromLegacy($legacy);
        $entitlementStatus = matchTicketEntitlementStatusFromLegacy($legacy);
        $paymentMethod = trim((string) ($legacy['payment_method'] ?? '')) ?: 'other';
        $paid = (int) ($legacy['paid'] ?? 0) === 1;
        $total = (float) $legacy['total_amount'];
        $itemMap = [];

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO orders
                (person_id, customer_name, customer_email, customer_phone, status, subtotal, discount_total, total_amount, currency, source, confirmation_email_sent_at, created_at, completed_at, cancelled_at)
                VALUES (NULL, :name, :email, :phone, :status, :subtotal, 0.00, :total, "GBP", :source, :email_sent_at, :created_at, :completed_at, :cancelled_at)')
                ->execute([
                    ':name' => (string) $legacy['buyer_name'],
                    ':email' => (string) $legacy['buyer_email'],
                    ':phone' => $legacy['buyer_phone'] ?: null,
                    ':status' => $orderStatus,
                    ':subtotal' => $total,
                    ':total' => $total,
                    ':source' => 'match_ticket_' . (string) ($legacy['checkout_mode'] ?? 'guest'),
                    ':email_sent_at' => $legacy['confirmation_email_sent_at'] ?: null,
                    ':created_at' => $legacy['created_at'] ?: date('Y-m-d H:i:s'),
                    ':completed_at' => $paid ? (($legacy['paid_at'] ?? '') ?: null) : null,
                    ':cancelled_at' => $legacy['cancelled_at'] ?: null,
                ]);
            $orderId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO payments
                (order_id, provider, provider_checkout_session_id, payment_method, amount, currency, status, paid_at)
                VALUES (:order_id, :provider, :session_id, :method, :amount, "GBP", :status, :paid_at)')
                ->execute([
                    ':order_id' => $orderId,
                    ':provider' => matchTicketProviderForMethod($paymentMethod),
                    ':session_id' => $legacy['stripe_checkout_session_id'] ?: null,
                    ':method' => $paymentMethod,
                    ':amount' => $total,
                    ':status' => $paid ? 'paid' : ($orderStatus === 'cancelled' ? 'cancelled' : 'pending'),
                    ':paid_at' => $paid ? (($legacy['paid_at'] ?? '') ?: null) : null,
                ]);
            $paymentId = (int) $pdo->lastInsertId();

            if (!empty($legacy['refunded_at'])) {
                $pdo->prepare('INSERT INTO refunds
                    (payment_id, amount, reason, provider_refund_id, status, requested_by_account_id, created_at, completed_at)
                    VALUES (:payment_id, :amount, :reason, :provider_refund_id, "succeeded", :account_id, :created_at, :completed_at)')
                    ->execute([
                        ':payment_id' => $paymentId,
                        ':amount' => (float) ($legacy['refund_amount'] ?? $total),
                        ':reason' => $legacy['refund_reason'] ?: null,
                        ':provider_refund_id' => $legacy['stripe_refund_id'] ?: null,
                        ':account_id' => $legacy['refunded_by_user_id'] ?: null,
                        ':created_at' => $legacy['refunded_at'],
                        ':completed_at' => $legacy['refunded_at'],
                    ]);
            }

            foreach ($legacyItems as $legacyItem) {
                $metadata = [
                    'legacy_order_id' => $legacyOrderId,
                    'legacy_order_item_id' => (int) $legacyItem['id'],
                    'fixture_id' => (int) $legacy['fixture_id'],
                    'fixture_package_id' => (int) $legacyItem['fixture_package_id'],
                    'package_id' => (int) $legacyItem['package_id'],
                    'admits_count' => (int) $legacyItem['admits_count'],
                    'checkout_mode' => (string) ($legacy['checkout_mode'] ?? 'guest'),
                    'group_token' => (string) $legacy['group_token'],
                ];
                $lineTotal = (float) $legacyItem['unit_price'] * (int) $legacyItem['quantity'];
                $pdo->prepare('INSERT INTO order_items
                    (order_id, product_type, product_reference_id, description_snapshot, quantity, unit_price, line_total, metadata_json)
                    VALUES (:order_id, "match_ticket", :fixture_package_id, :description, :quantity, :unit_price, :line_total, :metadata)')
                    ->execute([
                        ':order_id' => $orderId,
                        ':fixture_package_id' => (int) $legacyItem['fixture_package_id'],
                        ':description' => (string) $legacyItem['package_name'],
                        ':quantity' => (int) $legacyItem['quantity'],
                        ':unit_price' => (float) $legacyItem['unit_price'],
                        ':line_total' => $lineTotal,
                        ':metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    ]);
                $itemMap[(int) $legacyItem['id']] = (int) $pdo->lastInsertId();
            }

            foreach ($legacyTickets as $ticket) {
                $legacyItemId = (int) $ticket['order_item_id'];
                $itemId = (int) ($itemMap[$legacyItemId] ?? reset($itemMap));
                $pdo->prepare('INSERT INTO entitlements
                    (order_item_id, person_id, type, status, valid_from, valid_until, cancelled_at)
                    VALUES (:item_id, NULL, "match_ticket", :status, :valid_from, :valid_until, :cancelled_at)')
                    ->execute([
                        ':item_id' => $itemId,
                        ':status' => $entitlementStatus,
                        ':valid_from' => $legacy['match_date'] ?: null,
                        ':valid_until' => $legacy['match_date'] ?: null,
                        ':cancelled_at' => $legacy['cancelled_at'] ?: ($legacy['refunded_at'] ?: null),
                    ]);
                $entitlementId = (int) $pdo->lastInsertId();

                $pdo->prepare('UPDATE match_tickets SET entitlement_id = :entitlement_id WHERE id = :id')
                    ->execute([':entitlement_id' => $entitlementId, ':id' => (int) $ticket['id']]);

                $pdo->prepare('INSERT INTO ticket_credentials
                    (entitlement_id, token, manual_code, is_active, issued_at, revoked_at, legacy_source)
                    VALUES (:entitlement_id, :token, :manual_code, :active, :issued_at, :revoked_at, :legacy_source)')
                    ->execute([
                        ':entitlement_id' => $entitlementId,
                        ':token' => (string) $ticket['ticket_token'],
                        ':manual_code' => (string) $ticket['manual_code'],
                        ':active' => $entitlementStatus === 'active' ? 1 : 0,
                        ':issued_at' => $ticket['created_at'] ?: ($legacy['created_at'] ?: date('Y-m-d H:i:s')),
                        ':revoked_at' => in_array($entitlementStatus, ['cancelled', 'refunded'], true) ? ($legacy['cancelled_at'] ?: ($legacy['refunded_at'] ?: date('Y-m-d H:i:s'))) : null,
                        ':legacy_source' => 'match_tickets:' . (int) $ticket['id'],
                    ]);
                $credentialId = (int) $pdo->lastInsertId();

                $pdo->prepare('INSERT INTO match_ticket_migration_map
                    (legacy_order_id, order_id, legacy_order_item_id, order_item_id, legacy_ticket_id, entitlement_id, match_ticket_id, credential_id)
                    VALUES (:legacy_order_id, :order_id, :legacy_item_id, :item_id, :legacy_ticket_id, :entitlement_id, :match_ticket_id, :credential_id)')
                    ->execute([
                        ':legacy_order_id' => $legacyOrderId,
                        ':order_id' => $orderId,
                        ':legacy_item_id' => $legacyItemId,
                        ':item_id' => $itemId,
                        ':legacy_ticket_id' => (int) $ticket['id'],
                        ':entitlement_id' => $entitlementId,
                        ':match_ticket_id' => (int) $ticket['id'],
                        ':credential_id' => $credentialId,
                    ]);
                $createdTickets++;
            }

            $pdo->commit();
            $createdOrders++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    migrateLegacyAttendanceToAdmissions($pdo);
    return matchTicketMigrationReconciliation($pdo) + ['created_orders_this_run' => $createdOrders, 'created_tickets_this_run' => $createdTickets];
}

function migrateLegacyAttendanceToAdmissions(PDO $pdo): void
{
    ensureMatchTicketSchema($pdo);
    $seasonRows = $pdo->query("SELECT a.*, e.person_id
        FROM season_ticket_attendance a
        JOIN season_ticket_migration_map m ON m.legacy_order_id = a.order_id
        JOIN entitlements e ON e.id = m.entitlement_id")->fetchAll(PDO::FETCH_ASSOC);
    $insert = $pdo->prepare('INSERT IGNORE INTO admissions
        (fixture_id, entitlement_id, person_id, quantity, source, admitted_by_account_id, admitted_at, legacy_source)
        VALUES (:fixture, :entitlement, :person, 1, :source, :scanner, :admitted_at, :legacy_source)');
    foreach ($seasonRows as $row) {
        $mapStmt = $pdo->prepare('SELECT entitlement_id FROM season_ticket_migration_map WHERE legacy_order_id = :order_id LIMIT 1');
        $mapStmt->execute([':order_id' => (int) $row['order_id']]);
        $entitlementId = (int) $mapStmt->fetchColumn();
        if ($entitlementId <= 0) {
            continue;
        }
        $insert->execute([
            ':fixture' => (int) $row['fixture_id'],
            ':entitlement' => $entitlementId,
            ':person' => (int) ($row['person_id'] ?? 0) ?: null,
            ':source' => (string) ($row['scan_source'] ?? 'season_legacy'),
            ':scanner' => $row['scanned_by_user_id'] ?: null,
            ':admitted_at' => $row['scanned_at'],
            ':legacy_source' => 'season_ticket_attendance:' . (int) $row['id'],
        ]);
    }

    $matchRows = $pdo->query("SELECT a.*, m.entitlement_id, e.person_id, t.admits_count
        FROM match_ticket_attendance a
        JOIN match_ticket_migration_map m ON m.legacy_ticket_id = a.ticket_id
        JOIN entitlements e ON e.id = m.entitlement_id
        JOIN match_tickets t ON t.id = a.ticket_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($matchRows as $row) {
        $insert->execute([
            ':fixture' => (int) $row['fixture_id'],
            ':entitlement' => (int) $row['entitlement_id'],
            ':person' => (int) ($row['person_id'] ?? 0) ?: null,
            ':source' => (string) ($row['scan_source'] ?? 'match_ticket_legacy'),
            ':scanner' => $row['scanned_by_user_id'] ?: null,
            ':admitted_at' => $row['scanned_at'],
            ':legacy_source' => 'match_ticket_attendance:' . (int) $row['id'],
        ]);
    }

    $checkedRows = $pdo->query("SELECT t.*, m.entitlement_id, e.person_id
        FROM match_tickets t
        JOIN match_ticket_migration_map m ON m.legacy_ticket_id = t.id
        JOIN entitlements e ON e.id = m.entitlement_id
        WHERE t.checked_in_at IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($checkedRows as $row) {
        $insert->execute([
            ':fixture' => (int) $row['fixture_id'],
            ':entitlement' => (int) $row['entitlement_id'],
            ':person' => (int) ($row['person_id'] ?? 0) ?: null,
            ':source' => 'match_ticket_checked_in_at',
            ':scanner' => $row['checked_in_by_user_id'] ?: null,
            ':admitted_at' => $row['checked_in_at'],
            ':legacy_source' => 'match_tickets.checked_in_at:' . (int) $row['id'],
        ]);
    }

    $logInsert = $pdo->prepare('INSERT IGNORE INTO scan_logs
        (fixture_id, credential_id, entitlement_id, scanner_account_id, ticket_kind, ticket_label, holder_name, result, message, accepted, scanned_at, metadata_json, legacy_source)
        VALUES (:fixture, :credential, :entitlement, :scanner, :kind, :label, :holder, :result, :message, :accepted, :scanned_at, :metadata, :legacy_source)');
    $seasonLogs = $pdo->query("SELECT l.*, m.credential_id, m.entitlement_id
        FROM season_ticket_scan_logs l
        LEFT JOIN season_ticket_migration_map m ON m.legacy_order_id = l.order_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($seasonLogs as $row) {
        $logInsert->execute([
            ':fixture' => (int) $row['fixture_id'],
            ':credential' => (int) ($row['credential_id'] ?? 0) ?: null,
            ':entitlement' => (int) ($row['entitlement_id'] ?? 0) ?: null,
            ':scanner' => $row['scanned_by_user_id'] ?: null,
            ':kind' => (string) ($row['ticket_kind'] ?? 'season_ticket'),
            ':label' => $row['ticket_label'] ?: null,
            ':holder' => $row['holder_name'] ?: null,
            ':result' => (string) $row['status'],
            ':message' => (string) $row['message'],
            ':accepted' => (int) $row['accepted'] === 1 ? 1 : 0,
            ':scanned_at' => $row['scanned_at'],
            ':metadata' => json_encode(['legacy_table' => 'season_ticket_scan_logs', 'legacy_id' => (int) $row['id']], JSON_THROW_ON_ERROR),
            ':legacy_source' => 'season_ticket_scan_logs:' . (int) $row['id'],
        ]);
    }
}

function matchTicketMigrationReconciliation(PDO $pdo): array
{
    ensureMatchTicketSchema($pdo);
    return [
        'legacy_orders' => (int) $pdo->query('SELECT COUNT(*) FROM match_ticket_orders')->fetchColumn(),
        'legacy_items' => (int) $pdo->query('SELECT COUNT(*) FROM match_ticket_order_items')->fetchColumn(),
        'legacy_tickets' => (int) $pdo->query('SELECT COUNT(*) FROM match_tickets')->fetchColumn(),
        'mapped_tickets' => (int) $pdo->query('SELECT COUNT(*) FROM match_ticket_migration_map')->fetchColumn(),
        'match_entitlements' => (int) $pdo->query("SELECT COUNT(*) FROM entitlements WHERE type = 'match_ticket'")->fetchColumn(),
        'match_credentials' => (int) $pdo->query("SELECT COUNT(*) FROM ticket_credentials WHERE legacy_source LIKE 'match_tickets:%'")->fetchColumn(),
        'token_mismatches' => (int) $pdo->query('SELECT COUNT(*) FROM match_ticket_migration_map m JOIN match_tickets t ON t.id = m.legacy_ticket_id JOIN ticket_credentials c ON c.id = m.credential_id WHERE t.ticket_token <> c.token')->fetchColumn(),
        'manual_code_mismatches' => (int) $pdo->query('SELECT COUNT(*) FROM match_ticket_migration_map m JOIN match_tickets t ON t.id = m.legacy_ticket_id JOIN ticket_credentials c ON c.id = m.credential_id WHERE t.manual_code <> c.manual_code')->fetchColumn(),
        'admissions' => (int) $pdo->query('SELECT COUNT(*) FROM admissions')->fetchColumn(),
        'scan_logs' => (int) $pdo->query('SELECT COUNT(*) FROM scan_logs')->fetchColumn(),
    ];
}

function getMatchTicketPackages(PDO $pdo, bool $activeOnly = false): array
{
    ensureMatchTicketSchema($pdo);
    $sql = 'SELECT * FROM match_ticket_packages' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order ASC, name ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getMatchTicketPackage(PDO $pdo, int $id): ?array
{
    ensureMatchTicketSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM match_ticket_packages WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function saveMatchTicketPackage(PDO $pdo, ?int $id, array $data): int
{
    ensureMatchTicketSchema($pdo);
    $code = strtolower(trim((string) ($data['code'] ?? '')));
    $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';
    $params = [
        ':name' => trim((string) ($data['name'] ?? '')),
        ':code' => $code,
        ':description' => trim((string) ($data['description'] ?? '')) ?: null,
        ':default_price' => (float) ($data['default_price'] ?? 0),
        ':package_group' => trim((string) ($data['package_group'] ?? 'General')) ?: 'General',
        ':admits_count' => max(1, (int) ($data['admits_count'] ?? 1)),
        ':is_active' => !empty($data['is_active']) ? 1 : 0,
        ':sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
    ];
    if ($params[':name'] === '' || $params[':code'] === '') {
        throw new RuntimeException('Package name and code are required.');
    }
    if ($id) {
        $params[':id'] = $id;
        $pdo->prepare('UPDATE match_ticket_packages SET name=:name, code=:code, description=:description, default_price=:default_price, package_group=:package_group, admits_count=:admits_count, is_active=:is_active, sort_order=:sort_order WHERE id=:id')->execute($params);
        return $id;
    }
    $pdo->prepare('INSERT INTO match_ticket_packages (name, code, description, default_price, package_group, admits_count, is_active, sort_order) VALUES (:name, :code, :description, :default_price, :package_group, :admits_count, :is_active, :sort_order)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function getTicketedHomeFixtures(PDO $pdo, int $seasonId, bool $upcomingOnly = true): array
{
    ensureMatchTicketSchema($pdo);
    $sql = "SELECT f.*, mc.competition_type
        FROM match_fixtures f
        LEFT JOIN competition_seasons cs ON cs.id = f.competition_season_id
        LEFT JOIN match_competitions mc ON mc.id = cs.competition_id
        WHERE f.season_id = :season AND f.is_home = 1";
    if ($upcomingOnly) {
        $sql .= " AND f.match_date >= CURDATE() AND f.status NOT IN ('played','completed','cancelled','postponed')";
    }
    $sql .= ' ORDER BY f.match_date ASC, f.kickoff_time ASC, f.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':season' => $seasonId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getFixtureTicketPackages(PDO $pdo, int $fixtureId, bool $activeOnly = false): array
{
    ensureMatchTicketSchema($pdo);
    $sql = "SELECT ftp.*, p.name, p.code, p.description, p.package_group, p.admits_count,
               COALESCE(sold.sold_qty, 0) AS sold_qty
        FROM fixture_ticket_packages ftp
        JOIN match_ticket_packages p ON p.id = ftp.package_id
        LEFT JOIN (
            SELECT oi.product_reference_id AS fixture_package_id, COALESCE(SUM(oi.quantity),0) AS sold_qty
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            WHERE oi.product_type = 'match_ticket'
              AND o.status IN ('paid','pending_payment','complete')
            GROUP BY oi.product_reference_id
        ) sold ON sold.fixture_package_id = ftp.id
        WHERE ftp.fixture_id = :fixture";
    if ($activeOnly) {
        $sql .= ' AND ftp.is_active = 1 AND p.is_active = 1';
    }
    $sql .= ' ORDER BY p.sort_order ASC, p.name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':fixture' => $fixtureId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function saveFixtureTicketPackage(PDO $pdo, int $fixtureId, int $packageId, array $data): void
{
    ensureMatchTicketSchema($pdo);
    $stmt = $pdo->prepare('INSERT INTO fixture_ticket_packages
        (fixture_id, package_id, price, allocation, is_active)
        VALUES (:fixture, :package, :price, :allocation, :active)
        ON DUPLICATE KEY UPDATE price = VALUES(price), allocation = VALUES(allocation), is_active = VALUES(is_active)');
    $stmt->execute([
        ':fixture' => $fixtureId,
        ':package' => $packageId,
        ':price' => (float) ($data['price'] ?? 0),
        ':allocation' => max(0, (int) ($data['allocation'] ?? 0)),
        ':active' => !empty($data['is_active']) ? 1 : 0,
    ]);
}

function getMatchTicketOrder(PDO $pdo, int $orderId): ?array
{
    ensureMatchTicketSchema($pdo);
    $stmt = $pdo->prepare("SELECT o.*, o.id AS shared_order_id,
            o.customer_name AS buyer_name, o.customer_email AS buyer_email, o.customer_phone AS buyer_phone,
            CASE WHEN o.status IN ('paid','complete','partially_refunded') THEN 1 ELSE 0 END AS paid,
            COALESCE(o.completed_at, pay.paid_at) AS paid_at,
            pay.payment_method, pay.provider_checkout_session_id AS stripe_checkout_session_id,
            o.confirmation_email_sent_at,
            pay.status AS payment_status,
            f.id AS fixture_id, f.opponent, f.match_date, f.kickoff_time, f.is_home,
            COALESCE(access.token, legacy.group_token) AS access_token,
            legacy.group_token,
            legacy.legacy_order_id,
            legacy.refunded_at, legacy.refunded_by_user_id, legacy.refund_reason, legacy.refund_amount, legacy.stripe_refund_id,
            legacy.cancel_reason, legacy.cancelled_by_user_id
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id AND oi.product_type = 'match_ticket'
        JOIN fixture_ticket_packages ftp ON ftp.id = oi.product_reference_id
        JOIN match_fixtures f ON f.id = ftp.fixture_id
        LEFT JOIN payments pay ON pay.order_id = o.id
        LEFT JOIN order_access_tokens access ON access.order_id = o.id AND access.purpose = 'public_ticket_order' AND access.revoked_at IS NULL
        LEFT JOIN (
            SELECT m.order_id, MIN(m.legacy_order_id) AS legacy_order_id, MIN(lo.group_token) AS group_token,
                   MAX(lo.refunded_at) AS refunded_at, MAX(lo.refunded_by_user_id) AS refunded_by_user_id,
                   MAX(lo.refund_reason) AS refund_reason, MAX(lo.refund_amount) AS refund_amount, MAX(lo.stripe_refund_id) AS stripe_refund_id,
                   MAX(lo.cancel_reason) AS cancel_reason, MAX(lo.cancelled_by_user_id) AS cancelled_by_user_id
            FROM match_ticket_migration_map m
            JOIN match_ticket_orders lo ON lo.id = m.legacy_order_id
            GROUP BY m.order_id
        ) legacy ON legacy.order_id = o.id
        WHERE o.id = :id
        LIMIT 1");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$order) {
        $map = $pdo->prepare('SELECT order_id FROM match_ticket_migration_map WHERE legacy_order_id = :id LIMIT 1');
        $map->execute([':id' => $orderId]);
        $sharedOrderId = (int) $map->fetchColumn();
        return $sharedOrderId > 0 ? getMatchTicketOrder($pdo, $sharedOrderId) : null;
    }
    if (empty($order['access_token'])) {
        $order['access_token'] = ensureOrderAccessToken($pdo, (int) $order['id']);
    }
    $order['status'] = matchTicketPublicStatus((string) $order['status']);
    return $order;
}

function getMatchTicketOrderByGroupToken(PDO $pdo, string $token): ?array
{
    ensureMatchTicketSchema($pdo);
    $stmt = $pdo->prepare('SELECT order_id FROM order_access_tokens WHERE token = :token AND purpose = "public_ticket_order" AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1');
    $stmt->execute([':token' => $token]);
    $orderId = (int) $stmt->fetchColumn();
    if ($orderId <= 0) {
        $stmt = $pdo->prepare('SELECT m.order_id FROM match_ticket_orders lo JOIN match_ticket_migration_map m ON m.legacy_order_id = lo.id WHERE lo.group_token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $orderId = (int) $stmt->fetchColumn();
    }
    return $orderId > 0 ? getMatchTicketOrder($pdo, $orderId) : null;
}

function getMatchTicketOrderByAccessToken(PDO $pdo, string $token): ?array
{
    return getMatchTicketOrderByGroupToken($pdo, $token);
}

function matchTicketPublicStatus(string $status): string
{
    return match ($status) {
        'paid' => 'complete',
        default => $status,
    };
}

function getMatchTicketByToken(PDO $pdo, string $token): ?array
{
    ensureMatchTicketSchema($pdo);
    $stmt = $pdo->prepare("SELECT t.*, c.token AS ticket_token, c.manual_code, c.is_active AS credential_is_active,
               o.customer_name AS buyer_name, o.customer_email AS buyer_email,
               CASE WHEN o.status IN ('paid','complete','partially_refunded') THEN 1 ELSE 0 END AS paid,
               o.status AS order_status, access.token AS access_token, legacy.group_token,
               f.opponent, f.match_date, f.kickoff_time
        FROM ticket_credentials c
        JOIN entitlements e ON e.id = c.entitlement_id
        JOIN match_tickets t ON t.entitlement_id = e.id
        JOIN order_items oi ON oi.id = e.order_item_id
        JOIN orders o ON o.id = oi.order_id
        JOIN match_fixtures f ON f.id = t.fixture_id
        LEFT JOIN order_access_tokens access ON access.order_id = o.id AND access.purpose = 'public_ticket_order' AND access.revoked_at IS NULL
        LEFT JOIN (
            SELECT order_id, MIN(legacy_order_id) AS legacy_order_id, MIN(lo.group_token) AS group_token
            FROM match_ticket_migration_map m
            JOIN match_ticket_orders lo ON lo.id = m.legacy_order_id
            GROUP BY order_id
        ) legacy ON legacy.order_id = o.id
        WHERE c.token = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getMatchTicketByManualCode(PDO $pdo, string $code): ?array
{
    ensureMatchTicketSchema($pdo);
    $code = match_ticket_normalize_manual_code($code);
    if ($code === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT t.*, c.token AS ticket_token, c.manual_code, c.is_active AS credential_is_active,
               o.customer_name AS buyer_name, o.customer_email AS buyer_email,
               CASE WHEN o.status IN ('paid','complete','partially_refunded') THEN 1 ELSE 0 END AS paid,
               o.status AS order_status, access.token AS access_token, legacy.group_token,
               f.opponent, f.match_date, f.kickoff_time
        FROM ticket_credentials c
        JOIN entitlements e ON e.id = c.entitlement_id
        JOIN match_tickets t ON t.entitlement_id = e.id
        JOIN order_items oi ON oi.id = e.order_item_id
        JOIN orders o ON o.id = oi.order_id
        JOIN match_fixtures f ON f.id = t.fixture_id
        LEFT JOIN order_access_tokens access ON access.order_id = o.id AND access.purpose = 'public_ticket_order' AND access.revoked_at IS NULL
        LEFT JOIN (
            SELECT order_id, MIN(legacy_order_id) AS legacy_order_id, MIN(lo.group_token) AS group_token
            FROM match_ticket_migration_map m
            JOIN match_ticket_orders lo ON lo.id = m.legacy_order_id
            GROUP BY order_id
        ) legacy ON legacy.order_id = o.id
        WHERE c.manual_code = :code OR REPLACE(c.manual_code, '-', '') = :clean LIMIT 1");
    $stmt->execute([':code' => $code, ':clean' => str_replace('-', '', $code)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getMatchTicketOrderItems(PDO $pdo, int $orderId): array
{
    ensureMatchTicketSchema($pdo);
    $stmt = $pdo->prepare("SELECT oi.id, oi.order_id, oi.product_reference_id AS fixture_package_id,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(oi.metadata_json, '$.package_id')) AS UNSIGNED) AS package_id,
            oi.description_snapshot AS package_name, oi.quantity, oi.unit_price,
            CAST(JSON_UNQUOTE(JSON_EXTRACT(oi.metadata_json, '$.admits_count')) AS UNSIGNED) AS admits_count,
            oi.metadata_json, oi.created_at
        FROM order_items oi
        WHERE oi.order_id = :order_id AND oi.product_type = 'match_ticket'
        ORDER BY oi.id ASC");
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMatchTicketOrdersForMember(PDO $pdo, array $holder): array
{
    ensureMatchTicketSchema($pdo);
    $holderId = (int) ($holder['id'] ?? 0);
    $personId = $holderId > 0 ? (personIdFromLegacyHolderId($pdo, $holderId) ?? 0) : 0;
    $email = trim((string) ($holder['email'] ?? ''));
    $stmt = $pdo->prepare("SELECT o.*, CASE WHEN o.status = 'paid' THEN 'complete' ELSE o.status END AS status,
            o.customer_name AS buyer_name, o.customer_email AS buyer_email,
            CASE WHEN o.status IN ('paid','complete','partially_refunded') THEN 1 ELSE 0 END AS paid,
            f.opponent, f.match_date, f.kickoff_time, access.token AS access_token, legacy.group_token,
            COALESCE(ticket_counts.ticket_count, 0) AS ticket_count,
            COALESCE(used_counts.used_count, 0) AS used_count
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id AND oi.product_type = 'match_ticket'
        JOIN fixture_ticket_packages ftp ON ftp.id = oi.product_reference_id
        JOIN match_fixtures f ON f.id = ftp.fixture_id
        LEFT JOIN order_access_tokens access ON access.order_id = o.id AND access.purpose = 'public_ticket_order' AND access.revoked_at IS NULL
        LEFT JOIN (
            SELECT m.order_id, MIN(lo.group_token) AS group_token
            FROM match_ticket_migration_map m
            JOIN match_ticket_orders lo ON lo.id = m.legacy_order_id
            GROUP BY m.order_id
        ) legacy ON legacy.order_id = o.id
        LEFT JOIN (
            SELECT oi.order_id, COUNT(*) AS ticket_count
            FROM entitlements e JOIN order_items oi ON oi.id = e.order_item_id
            WHERE e.type = 'match_ticket'
            GROUP BY oi.order_id
        ) ticket_counts ON ticket_counts.order_id = o.id
        LEFT JOIN (
            SELECT oi.order_id, COUNT(*) AS used_count
            FROM entitlements e JOIN order_items oi ON oi.id = e.order_item_id JOIN admissions a ON a.entitlement_id = e.id
            WHERE e.type = 'match_ticket'
            GROUP BY oi.order_id
        ) used_counts ON used_counts.order_id = o.id
        WHERE (:person_id > 0 AND o.person_id = :person_id)
           OR (:email != '' AND LOWER(o.customer_email) = LOWER(:email))
        ORDER BY f.match_date DESC, o.created_at DESC, o.id DESC");
    $stmt->execute([':person_id' => $personId, ':email' => $email]);
    return matchTicketRowsWithAccessTokens($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getMatchTicketOrderList(PDO $pdo, int $seasonId): array
{
    ensureMatchTicketSchema($pdo);
    $stmt = $pdo->prepare("SELECT o.*, CASE WHEN o.status = 'paid' THEN 'complete' ELSE o.status END AS status,
            o.customer_name AS buyer_name, o.customer_email AS buyer_email,
            CASE WHEN o.status IN ('paid','complete','partially_refunded') THEN 1 ELSE 0 END AS paid,
            pay.payment_method, pay.paid_at, access.token AS access_token,
            f.opponent, f.match_date, f.kickoff_time,
            COALESCE(ticket_counts.ticket_count, 0) AS ticket_count,
            COALESCE(used_counts.used_count, 0) AS used_count
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id AND oi.product_type = 'match_ticket'
        JOIN fixture_ticket_packages ftp ON ftp.id = oi.product_reference_id
        JOIN match_fixtures f ON f.id = ftp.fixture_id
        LEFT JOIN payments pay ON pay.order_id = o.id
        LEFT JOIN order_access_tokens access ON access.order_id = o.id AND access.purpose = 'public_ticket_order' AND access.revoked_at IS NULL
        LEFT JOIN (
            SELECT oi.order_id, COUNT(*) AS ticket_count
            FROM entitlements e
            JOIN order_items oi ON oi.id = e.order_item_id
            WHERE e.type = 'match_ticket'
            GROUP BY oi.order_id
        ) ticket_counts ON ticket_counts.order_id = o.id
        LEFT JOIN (
            SELECT oi.order_id, COUNT(*) AS used_count
            FROM entitlements e
            JOIN order_items oi ON oi.id = e.order_item_id
            JOIN admissions a ON a.entitlement_id = e.id
            WHERE e.type = 'match_ticket'
            GROUP BY oi.order_id
        ) used_counts ON used_counts.order_id = o.id
        WHERE f.season_id = :season
        GROUP BY o.id
        ORDER BY o.created_at DESC, o.id DESC");
    $stmt->execute([':season' => $seasonId]);
    return matchTicketRowsWithAccessTokens($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getMatchTicketsForOrder(PDO $pdo, int $orderId): array
{
    ensureMatchTicketSchema($pdo);
    $stmt = $pdo->prepare("SELECT t.*, c.token AS ticket_token, c.manual_code,
            a.admitted_at AS checked_in_at,
            a.admitted_by_account_id AS checked_in_by_user_id
        FROM entitlements e
        JOIN order_items oi ON oi.id = e.order_item_id
        JOIN match_tickets t ON t.entitlement_id = e.id
        JOIN ticket_credentials c ON c.entitlement_id = e.id
        LEFT JOIN admissions a ON a.entitlement_id = t.entitlement_id AND a.fixture_id = t.fixture_id
        WHERE oi.order_id = :order_id AND e.type = 'match_ticket'
        ORDER BY t.id ASC");
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function match_ticket_sold_count(PDO $pdo, int $fixturePackageId): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(oi.quantity),0)
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.product_type = 'match_ticket'
          AND oi.product_reference_id = :id
          AND o.status IN ('paid','complete','pending_payment')");
    $stmt->execute([':id' => $fixturePackageId]);
    return (int) $stmt->fetchColumn();
}

function createMatchTicketOrder(PDO $pdo, array $buyer, int $fixtureId, array $cart, string $paymentMethod, string $checkoutMode): int
{
    ensureMatchTicketSchema($pdo);
    if ($cart === []) {
        throw new RuntimeException('Your basket is empty.');
    }

    $packagesById = [];
    foreach (getFixtureTicketPackages($pdo, $fixtureId, true) as $package) {
        $packagesById[(int) $package['id']] = $package;
    }

    $lines = [];
    $total = 0.0;
    foreach ($cart as $fixturePackageId => $qty) {
        $fixturePackageId = (int) $fixturePackageId;
        $qty = max(0, (int) $qty);
        if ($qty <= 0) {
            continue;
        }
        $package = $packagesById[$fixturePackageId] ?? null;
        if (!$package) {
            throw new RuntimeException('One of the selected tickets is no longer available.');
        }
        $allocation = (int) $package['allocation'];
        $sold = match_ticket_sold_count($pdo, $fixturePackageId);
        if ($allocation > 0 && $sold + $qty > $allocation) {
            throw new RuntimeException((string) $package['name'] . ' does not have enough tickets left.');
        }
        $price = (float) $package['price'];
        $lines[] = ['package' => $package, 'qty' => $qty, 'price' => $price];
        $total += $price * $qty;
    }
    if ($lines === []) {
        throw new RuntimeException('Choose at least one ticket.');
    }

    $personId = !empty($buyer['person_id']) ? (int) $buyer['person_id'] : 0;
    if ($personId <= 0 && !empty($buyer['holder_id'])) {
        $personId = personIdFromLegacyHolderId($pdo, (int) $buyer['holder_id']) ?? 0;
    }
    $paidNow = $total <= 0 || $paymentMethod !== 'online';
    $orderStatus = $paidNow ? 'paid' : 'pending_payment';
    $entitlementStatus = $paidNow ? 'active' : 'pending';

    $pdo->prepare('INSERT INTO orders
        (person_id, customer_name, customer_email, customer_phone, status, subtotal, discount_total, total_amount, currency, source, completed_at)
        VALUES (:person_id, :name, :email, :phone, :status, :subtotal, 0.00, :total, "GBP", :source, :completed_at)')
        ->execute([
            ':person_id' => $personId > 0 ? $personId : null,
            ':name' => trim((string) $buyer['name']),
            ':email' => trim((string) $buyer['email']),
            ':phone' => trim((string) ($buyer['phone'] ?? '')) ?: null,
            ':status' => $orderStatus,
            ':subtotal' => $total,
            ':total' => $total,
            ':source' => 'match_ticket_' . $checkoutMode,
            ':completed_at' => $paidNow ? date('Y-m-d H:i:s') : null,
        ]);
    $orderId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO payments
        (order_id, provider, payment_method, amount, currency, status, paid_at)
        VALUES (:order_id, :provider, :method, :amount, "GBP", :status, :paid_at)')
        ->execute([
            ':order_id' => $orderId,
            ':provider' => matchTicketProviderForMethod($paymentMethod === 'online' ? 'stripe' : $paymentMethod),
            ':method' => $paymentMethod === 'online' ? 'stripe' : $paymentMethod,
            ':amount' => $total,
            ':status' => $paidNow ? 'paid' : 'pending',
            ':paid_at' => $paidNow ? date('Y-m-d H:i:s') : null,
        ]);
    ensureOrderAccessToken($pdo, $orderId);

    foreach ($lines as $line) {
        $package = $line['package'];
        $metadata = [
            'fixture_id' => $fixtureId,
            'fixture_package_id' => (int) $package['id'],
            'package_id' => (int) $package['package_id'],
            'package_name' => (string) $package['name'],
            'admits_count' => max(1, (int) $package['admits_count']),
            'checkout_mode' => $checkoutMode,
        ];
        $pdo->prepare('INSERT INTO order_items
            (order_id, product_type, product_reference_id, description_snapshot, quantity, unit_price, line_total, metadata_json)
            VALUES (:order_id, "match_ticket", :fixture_package, :name, :qty, :price, :line_total, :metadata)')
            ->execute([
                ':order_id' => $orderId,
                ':fixture_package' => (int) $package['id'],
                ':name' => (string) $package['name'],
                ':qty' => (int) $line['qty'],
                ':price' => (float) $line['price'],
                ':line_total' => (float) $line['price'] * (int) $line['qty'],
                ':metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);
        $itemId = (int) $pdo->lastInsertId();
        for ($i = 1; $i <= (int) $line['qty']; $i++) {
            $pdo->prepare('INSERT INTO entitlements
                (order_item_id, person_id, type, status)
                VALUES (:item_id, :person_id, "match_ticket", :status)')
                ->execute([
                    ':item_id' => $itemId,
                    ':person_id' => $personId > 0 ? $personId : null,
                    ':status' => $entitlementStatus,
                ]);
            $entitlementId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO match_tickets
                (entitlement_id, order_id, order_item_id, fixture_id, package_id, ticket_label, ticket_token, manual_code, admits_count)
                VALUES (:entitlement_id, NULL, NULL, :fixture, :package, :label, NULL, NULL, :admits)')
                ->execute([
                    ':entitlement_id' => $entitlementId,
                    ':fixture' => $fixtureId,
                    ':package' => (int) $package['package_id'],
                    ':label' => (string) $package['name'],
                    ':admits' => max(1, (int) $package['admits_count']),
                ]);
            $pdo->prepare('INSERT INTO ticket_credentials
                (entitlement_id, token, manual_code, is_active, legacy_source)
                VALUES (:entitlement_id, :token, :manual_code, :active, "match_tickets")')
                ->execute([
                    ':entitlement_id' => $entitlementId,
                    ':token' => matchTicketNewToken($pdo, 'ticket_credentials', 'token', 20),
                    ':manual_code' => match_ticket_generate_manual_code($pdo),
                    ':active' => $entitlementStatus === 'active' ? 1 : 0,
                ]);
        }
    }
    if ($paidNow) {
        sendMatchTicketConfirmationEmail($pdo, $orderId);
    }
    return $orderId;
}

function markMatchTicketOrderPaid(PDO $pdo, int $orderId, string $method = 'stripe'): void
{
    ensureMatchTicketSchema($pdo);
    $order = getMatchTicketOrder($pdo, $orderId);
    if (!$order) {
        return;
    }
    $sharedOrderId = (int) $order['id'];
    $pdo->prepare("UPDATE orders SET status = 'paid', completed_at = COALESCE(completed_at, NOW()) WHERE id = :id")
        ->execute([':id' => $sharedOrderId]);
    $pdo->prepare("UPDATE payments SET status = 'paid', paid_at = COALESCE(paid_at, NOW()), payment_method = :method WHERE order_id = :id")
        ->execute([':method' => $method, ':id' => $sharedOrderId]);
    $pdo->prepare("UPDATE entitlements e JOIN order_items oi ON oi.id = e.order_item_id SET e.status = 'active' WHERE oi.order_id = :id AND e.type = 'match_ticket' AND e.status = 'pending'")
        ->execute([':id' => $sharedOrderId]);
    $pdo->prepare("UPDATE ticket_credentials c JOIN entitlements e ON e.id = c.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id SET c.is_active = 1, c.revoked_at = NULL WHERE oi.order_id = :id AND e.type = 'match_ticket'")
        ->execute([':id' => $sharedOrderId]);
}

function match_ticket_public_order_url(array $order): string
{
    $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk'));
    if ($host === '') {
        $host = 'lundy.me.uk';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'https';
    $token = (string) ($order['access_token'] ?? $order['group_token'] ?? '');
    $param = !empty($order['access_token']) ? 'access' : 'group';
    return $scheme . '://' . $host . '/ticket_order.php?' . $param . '=' . rawurlencode($token);
}

function sendMatchTicketConfirmationEmail(PDO $pdo, int $orderId, bool $force = false): bool
{
    $order = getMatchTicketOrder($pdo, $orderId);
    if (!$order || (int) $order['paid'] !== 1 || (string) $order['status'] !== 'complete') {
        return false;
    }
    if (!$force && !empty($order['confirmation_email_sent_at'])) {
        return true;
    }
    $email = trim((string) $order['buyer_email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $items = getMatchTicketOrderItems($pdo, $orderId);
    $tickets = getMatchTicketsForOrder($pdo, $orderId);
    $host = preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk'))));
    $subject = 'Your Saltcoats Victoria FC match ticket';
    $fixtureDate = date('D j M Y', strtotime((string) $order['match_date']));
    $kickoff = !empty($order['kickoff_time']) ? ' at ' . date('H:i', strtotime((string) $order['kickoff_time'])) : '';

    $lineRows = [];
    $textLines = [];
    foreach ($items as $item) {
        $lineTotal = gbp((float) $item['unit_price'] * (int) $item['quantity']);
        $textLines[] = '- ' . (string) $item['package_name'] . ' x ' . (int) $item['quantity'] . ': ' . $lineTotal;
        $lineRows[] = '<tr>
            <td style="padding:12px 0;border-bottom:1px solid #eadfdf;color:#21141a;font-weight:700;">' . h((string) $item['package_name']) . '</td>
            <td style="padding:12px 0;border-bottom:1px solid #eadfdf;color:#6f6470;text-align:center;">' . (int) $item['quantity'] . '</td>
            <td style="padding:12px 0;border-bottom:1px solid #eadfdf;color:#21141a;text-align:right;font-weight:700;">' . h($lineTotal) . '</td>
        </tr>';
    }
    $ticketCards = [];
    $textCodes = [];
    foreach ($tickets as $ticket) {
        $textCodes[] = '- ' . (string) $ticket['ticket_label'] . ': ' . (string) $ticket['manual_code'];
        $ticketCards[] = '<div style="padding:12px 14px;margin:0 0 10px;border:1px solid #eadfdf;border-radius:12px;background:#fffdf9;">
            <div style="font-size:13px;color:#6f6470;font-weight:700;text-transform:uppercase;letter-spacing:.06em;">' . h((string) $ticket['ticket_label']) . '</div>
            <div style="font-family:Menlo,Consolas,monospace;font-size:22px;letter-spacing:.08em;color:#4b0818;font-weight:900;margin-top:3px;">' . h((string) $ticket['manual_code']) . '</div>
        </div>';
    }

    $ticketUrl = match_ticket_public_order_url($order);
    $preheader = 'Your digital tickets for Saltcoats Victoria FC vs ' . (string) $order['opponent'] . ' are ready.';
    $message = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($subject) . '</title></head>
    <body style="margin:0;padding:0;background:#f6ecde;font-family:Inter,Arial,sans-serif;color:#21141a;">
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . h($preheader) . '</div>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6ecde;padding:28px 12px;">
            <tr><td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 18px 45px rgba(75,8,24,.14);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#4b0818,#7a1730 62%,#a6791d);padding:28px 26px;color:#ffffff;">
                            <div style="font-size:12px;text-transform:uppercase;letter-spacing:.14em;font-weight:900;color:rgba(255,255,255,.74);">Saltcoats Victoria FC</div>
                            <h1 style="margin:8px 0 8px;font-size:30px;line-height:1.1;color:#ffffff;">Your Match Tickets</h1>
                            <p style="margin:0;color:rgba(255,255,255,.84);font-size:16px;">vs ' . h((string) $order['opponent']) . ' · ' . h($fixtureDate . $kickoff) . '</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px;">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.55;">Hi ' . h((string) $order['buyer_name']) . ',</p>
                            <p style="margin:0 0 22px;font-size:16px;line-height:1.55;color:#4a4046;">Thanks for your order. Your digital tickets are ready to show at the gate.</p>
                            <div style="margin:0 0 24px;text-align:center;">
                                <a href="' . h($ticketUrl) . '" style="display:inline-block;background:#4b0818;color:#ffffff;text-decoration:none;font-weight:900;border-radius:999px;padding:13px 22px;">Open Digital Tickets</a>
                            </div>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 22px;">
                                <tr>
                                    <th align="left" style="padding:0 0 8px;color:#6f6470;font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Ticket</th>
                                    <th align="center" style="padding:0 0 8px;color:#6f6470;font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Qty</th>
                                    <th align="right" style="padding:0 0 8px;color:#6f6470;font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Total</th>
                                </tr>
                                ' . implode('', $lineRows) . '
                                <tr>
                                    <td colspan="2" style="padding:14px 0 0;color:#21141a;font-size:18px;font-weight:900;">Order total</td>
                                    <td style="padding:14px 0 0;color:#4b0818;font-size:18px;font-weight:900;text-align:right;">' . h(gbp((float) $order['total_amount'])) . '</td>
                                </tr>
                            </table>
                            <h2 style="margin:0 0 12px;color:#4b0818;font-size:18px;">Manual Codes</h2>
                            <p style="margin:0 0 12px;color:#6f6470;font-size:14px;line-height:1.45;">If a QR code will not scan, give the gate operator the matching manual code.</p>
                            ' . implode('', $ticketCards) . '
                            <div style="margin-top:24px;padding:16px;border-radius:14px;background:#faf5ed;color:#4a4046;font-size:14px;line-height:1.5;">
                                Each ticket can only be scanned once. If you bought multiple tickets, swipe between QR codes on the digital ticket page.
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 26px;background:#21141a;color:rgba(255,255,255,.72);font-size:13px;text-align:center;">
                            Saltcoats Victoria FC · Thank you for your support
                        </td>
                    </tr>
                </table>
                <div style="max-width:640px;margin:14px auto 0;color:#766b70;font-size:12px;line-height:1.45;text-align:center;">
                    Trouble opening the button? Use this link:<br><a href="' . h($ticketUrl) . '" style="color:#4b0818;">' . h($ticketUrl) . '</a>
                </div>
            </td></tr>
        </table>
    </body></html>';

    $sent = hub_send_mail($email, $subject, $message, true);
    if ($sent) {
        $pdo->prepare('UPDATE orders SET confirmation_email_sent_at = NOW() WHERE id = :id')->execute([':id' => $orderId]);
    }
    return $sent;
}

function attachMatchTicketOrderStripeSession(PDO $pdo, int $orderId, string $sessionId): void
{
    ensureMatchTicketSchema($pdo);
    $order = getMatchTicketOrder($pdo, $orderId);
    if (!$order) {
        return;
    }
    $pdo->prepare('UPDATE payments SET provider_checkout_session_id = :session_id WHERE order_id = :id')
        ->execute([':session_id' => $sessionId, ':id' => (int) $order['id']]);
}

function match_ticket_stripe_create_checkout_session(PDO $pdo, int $orderId, string $successUrl, string $cancelUrl): array
{
    $order = getMatchTicketOrder($pdo, $orderId);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    $items = getMatchTicketOrderItems($pdo, $orderId);
    $currency = stripe_default_currency();
    $lineItems = [];
    foreach ($items as $item) {
        if ((float) $item['unit_price'] <= 0) {
            continue;
        }
        $lineItems[] = [
            'quantity' => (int) $item['quantity'],
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => stripe_amount_to_minor_units((float) $item['unit_price'], $currency),
                'product_data' => [
                    'name' => (string) $item['package_name'] . ' - vs ' . (string) $order['opponent'],
                    'description' => 'Saltcoats Victoria FC match ticket',
                ],
            ],
        ];
    }
    if ($lineItems === []) {
        markMatchTicketOrderPaid($pdo, $orderId);
        return ['url' => $successUrl, 'session_id' => ''];
    }
    $session = stripe_request('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'customer_email' => (string) $order['buyer_email'],
        'line_items' => $lineItems,
        'metadata' => ['kind' => 'match_ticket', 'order_id' => (string) $orderId, 'shared_order_id' => (string) $orderId],
        'payment_intent_data' => ['metadata' => ['kind' => 'match_ticket', 'order_id' => (string) $orderId, 'shared_order_id' => (string) $orderId]],
    ]);
    attachMatchTicketOrderStripeSession($pdo, $orderId, (string) $session['id']);
    return ['url' => (string) $session['url'], 'session_id' => (string) $session['id']];
}

function match_ticket_stripe_handle_checkout_completed(PDO $pdo, array $session): void
{
    if ((string) ($session['payment_status'] ?? '') !== 'paid') {
        return;
    }
    $orderId = (int) ($session['metadata']['order_id'] ?? 0);
    if ($orderId > 0) {
        markMatchTicketOrderPaid($pdo, $orderId, 'stripe');
        sendMatchTicketConfirmationEmail($pdo, $orderId);
        $order = getMatchTicketOrder($pdo, $orderId);
        if ($order) {
            stripe_send_payment_notification(
                $pdo,
                'Match tickets',
                (string) ($order['buyer_name'] ?? $order['customer_name'] ?? ''),
                (string) ($order['buyer_email'] ?? $order['customer_email'] ?? ''),
                (float) ($order['total_amount'] ?? 0),
                'Match ticket order #' . $orderId . (!empty($order['opponent']) ? ' vs ' . (string) $order['opponent'] : ''),
                stripe_public_base_url() . '/ticket_orders.php?id=' . $orderId
            );
        }
    }
}

function cancelMatchTicketOrder(PDO $pdo, int $orderId, string $reason, ?int $userId): void
{
    ensureMatchTicketSchema($pdo);
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A cancellation reason is required.');
    }
    $order = getMatchTicketOrder($pdo, $orderId);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    $sharedOrderId = (int) $order['id'];
    $pdo->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = NOW() WHERE id = :id")->execute([':id' => $sharedOrderId]);
    $pdo->prepare("UPDATE payments SET status = 'cancelled' WHERE order_id = :id AND status <> 'paid'")->execute([':id' => $sharedOrderId]);
    $pdo->prepare("UPDATE entitlements e JOIN order_items oi ON oi.id = e.order_item_id SET e.status = 'cancelled', e.cancelled_at = NOW() WHERE oi.order_id = :id AND e.type = 'match_ticket'")->execute([':id' => $sharedOrderId]);
    $pdo->prepare("UPDATE ticket_credentials c JOIN entitlements e ON e.id = c.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id SET c.is_active = 0, c.revoked_at = NOW() WHERE oi.order_id = :id AND e.type = 'match_ticket'")->execute([':id' => $sharedOrderId]);
}

function refundMatchTicketOrder(PDO $pdo, int $orderId, string $reason, ?int $userId): array
{
    ensureMatchTicketSchema($pdo);
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A refund reason is required.');
    }
    $order = getMatchTicketOrder($pdo, $orderId);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    if ((int) $order['paid'] !== 1 || !in_array((string) $order['status'], ['complete', 'cancelled'], true)) {
        throw new RuntimeException('Only paid ticket orders can be refunded.');
    }
    if (!empty($order['refunded_at'])) {
        throw new RuntimeException('This order has already been refunded.');
    }

    $refundId = null;
    if (!empty($order['stripe_checkout_session_id'])) {
        $session = stripe_request('GET', '/checkout/sessions/' . rawurlencode((string) $order['stripe_checkout_session_id']), ['expand' => ['payment_intent']]);
        $paymentIntent = $session['payment_intent'] ?? null;
        $paymentIntentId = is_array($paymentIntent) ? (string) ($paymentIntent['id'] ?? '') : (string) $paymentIntent;
        if ($paymentIntentId === '') {
            throw new RuntimeException('Stripe did not return a payment intent for this order.');
        }
        $refund = stripe_request('POST', '/refunds', [
            'payment_intent' => $paymentIntentId,
            'reason' => 'requested_by_customer',
            'metadata' => ['kind' => 'match_ticket', 'order_id' => (string) $orderId],
        ]);
        $refundId = (string) ($refund['id'] ?? '');
    }

    $sharedOrderId = (int) $order['id'];
    $paymentStmt = $pdo->prepare('SELECT id FROM payments WHERE order_id = :id ORDER BY id LIMIT 1');
    $paymentStmt->execute([':id' => $sharedOrderId]);
    $paymentId = (int) $paymentStmt->fetchColumn();
    if ($paymentId > 0) {
        $pdo->prepare('INSERT INTO refunds
            (payment_id, amount, reason, provider_refund_id, status, requested_by_account_id, completed_at)
            VALUES (:payment_id, :amount, :reason, :provider_refund_id, "succeeded", :account_id, NOW())')
            ->execute([
                ':payment_id' => $paymentId,
                ':amount' => (float) $order['total_amount'],
                ':reason' => $reason,
                ':provider_refund_id' => $refundId,
                ':account_id' => $userId,
            ]);
    }
    $pdo->prepare("UPDATE orders SET status = 'refunded' WHERE id = :id")->execute([':id' => $sharedOrderId]);
    $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE order_id = :id")->execute([':id' => $sharedOrderId]);
    $pdo->prepare("UPDATE entitlements e JOIN order_items oi ON oi.id = e.order_item_id SET e.status = 'refunded', e.cancelled_at = NOW() WHERE oi.order_id = :id AND e.type = 'match_ticket'")->execute([':id' => $sharedOrderId]);
    $pdo->prepare("UPDATE ticket_credentials c JOIN entitlements e ON e.id = c.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id SET c.is_active = 0, c.revoked_at = NOW() WHERE oi.order_id = :id AND e.type = 'match_ticket'")->execute([':id' => $sharedOrderId]);

    return ['refund_id' => $refundId, 'amount' => (float) $order['total_amount']];
}

function match_ticket_stripe_handle_checkout_expired(PDO $pdo, array $session): void
{
    $orderId = (int) ($session['metadata']['order_id'] ?? 0);
    if ($orderId > 0) {
        $order = getMatchTicketOrder($pdo, $orderId);
        if ($order) {
            $pdo->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = COALESCE(cancelled_at, NOW()) WHERE id = :id AND status = 'pending_payment'")->execute([':id' => (int) $order['id']]);
            $pdo->prepare("UPDATE payments SET status = 'cancelled' WHERE order_id = :id AND status = 'pending'")->execute([':id' => (int) $order['id']]);
        }
    }
}

function match_ticket_scan_log(PDO $pdo, int $fixtureId, ?array $ticket, string $status, string $message, bool $accepted, ?int $scannedByUserId): array
{
    $row = admissionsLog($pdo, $fixtureId, null, $status, $message, $accepted, $scannedByUserId, [
        'legacy_table' => 'season_ticket_scan_logs',
        'ticket_kind' => 'match_ticket',
        'ticket_label' => is_array($ticket) ? (string) ($ticket['ticket_label'] ?? 'Match Ticket') : '',
    ]);
    $row['status'] = (string) ($row['result'] ?? $status);
    return $row;
}

function match_ticket_check_and_mark_attendance(PDO $pdo, int $fixtureId, string $scanInput, ?int $scannedByUserId = null, bool $allowFixtureStatusOverride = false): array
{
    ensureMatchTicketSchema($pdo);
    if ($fixtureId <= 0) {
        return ['found' => true, 'valid' => false, 'status' => 'invalid_fixture', 'message' => 'Fixture not found.'];
    }
    $fixtureStmt = $pdo->prepare('SELECT id, status FROM match_fixtures WHERE id = :id LIMIT 1');
    $fixtureStmt->execute([':id' => $fixtureId]);
    $fixture = $fixtureStmt->fetch(PDO::FETCH_ASSOC);
    if (!$fixture) {
        return ['found' => true, 'valid' => false, 'status' => 'invalid_fixture', 'message' => 'Fixture not found.'];
    }
    if (!$allowFixtureStatusOverride && in_array((string) ($fixture['status'] ?? ''), ['postponed', 'cancelled'], true)) {
        $log = match_ticket_scan_log($pdo, $fixtureId, null, 'fixture_not_admitting', 'Tickets cannot be admitted for a postponed or cancelled fixture.', false, $scannedByUserId);
        return ['found' => true, 'valid' => false, 'status' => 'fixture_not_admitting', 'message' => 'Tickets cannot be admitted for a postponed or cancelled fixture.', 'scan_log' => $log];
    }

    $result = recordAdmission($pdo, $fixtureId, $scanInput, $scannedByUserId, 'match_ticket_qr', $allowFixtureStatusOverride);
    if (empty($result['found'])) {
        $token = match_ticket_extract_token($scanInput);
        $orderLink = $token !== '' ? getMatchTicketOrderByGroupToken($pdo, $token) : null;
        if ($orderLink) {
            $log = match_ticket_scan_log($pdo, $fixtureId, ['buyer_name' => (string) $orderLink['buyer_name'], 'ticket_label' => 'Order Link'], 'individual_required', 'Please scan an individual ticket QR code.', false, $scannedByUserId);
            return ['found' => true, 'valid' => false, 'status' => 'individual_required', 'message' => 'Please scan an individual ticket QR code.', 'scan_log' => $log];
        }
        return ['found' => false];
    }
    $credential = $result['credential'] ?? [];
    if (($credential['entitlement_type'] ?? '') !== 'match_ticket') {
        return ['found' => false];
    }
    $ticket = getMatchTicketByToken($pdo, (string) ($credential['token'] ?? '')) ?: getMatchTicketByManualCode($pdo, (string) ($credential['manual_code'] ?? ''));
    if ((string) ($result['status'] ?? '') === 'checked_in' && $ticket) {
        $ticket['checked_in_at'] = date('Y-m-d H:i:s');
        $ticket['checked_in_by_user_id'] = $scannedByUserId;
    }
    $log = $result['scan_log'] ?? [];
    if ($log && isset($log['result']) && !isset($log['status'])) {
        $log['status'] = $log['result'];
    }
    return ['found' => true, 'valid' => (bool) ($result['valid'] ?? false), 'status' => (string) $result['status'], 'message' => (string) $result['message'], 'ticket' => $ticket ?: [], 'scan_log' => $log];
}
