<?php
declare(strict_types=1);

require_once __DIR__ . '/people.php';
require_once __DIR__ . '/season_tickets.php';
require_once __DIR__ . '/season_pass_rules.php';

function ensureSeasonPassSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    ensureSeasonTicketSchema($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id INT UNSIGNED NULL,
        customer_name VARCHAR(190) NOT NULL,
        customer_email VARCHAR(190) NULL,
        customer_phone VARCHAR(50) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'draft',
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        discount_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency CHAR(3) NOT NULL DEFAULT 'GBP',
        source VARCHAR(50) NOT NULL DEFAULT 'admin',
        terms_accepted_at DATETIME NULL,
        terms_text_snapshot MEDIUMTEXT NULL,
        confirmation_email_sent_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_orders_person (person_id),
        KEY idx_orders_status (status, created_at),
        CONSTRAINT fk_orders_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        product_type VARCHAR(60) NOT NULL,
        product_reference_id INT UNSIGNED NULL,
        description_snapshot VARCHAR(255) NOT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        metadata_json JSON NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_order_items_order (order_id),
        KEY idx_order_items_product (product_type, product_reference_id),
        CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        provider VARCHAR(40) NOT NULL,
        provider_payment_id VARCHAR(190) NULL,
        provider_checkout_session_id VARCHAR(190) NULL,
        payment_method VARCHAR(40) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        currency CHAR(3) NOT NULL DEFAULT 'GBP',
        status VARCHAR(40) NOT NULL DEFAULT 'pending',
        paid_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_payments_order (order_id),
        KEY idx_payments_checkout_session (provider_checkout_session_id),
        CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS refunds (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        payment_id INT UNSIGNED NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        reason TEXT NULL,
        provider_refund_id VARCHAR(190) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'pending',
        requested_by_account_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_refunds_payment (payment_id),
        KEY idx_refunds_account (requested_by_account_id),
        CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
        CONSTRAINT fk_refunds_account FOREIGN KEY (requested_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS entitlements (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_item_id INT UNSIGNED NULL,
        person_id INT UNSIGNED NOT NULL,
        type VARCHAR(60) NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'pending',
        valid_from DATE NULL,
        valid_until DATE NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        cancelled_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_entitlements_person (person_id, type, status),
        KEY idx_entitlements_order_item (order_item_id),
        CONSTRAINT fk_entitlements_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT,
        CONSTRAINT fk_entitlements_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS season_passes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        entitlement_id INT UNSIGNED NOT NULL,
        season_id INT UNSIGNED NOT NULL,
        season_ticket_type_id INT UNSIGNED NOT NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'pending',
        legacy_season_ticket_order_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_season_passes_entitlement (entitlement_id),
        UNIQUE KEY uq_season_passes_legacy_order (legacy_season_ticket_order_id),
        KEY idx_season_passes_season (season_id, status),
        KEY idx_season_passes_type (season_ticket_type_id),
        CONSTRAINT fk_season_passes_entitlement FOREIGN KEY (entitlement_id) REFERENCES entitlements(id) ON DELETE CASCADE,
        CONSTRAINT fk_season_passes_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE RESTRICT,
        CONSTRAINT fk_season_passes_type FOREIGN KEY (season_ticket_type_id) REFERENCES season_ticket_types(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_credentials (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        entitlement_id INT UNSIGNED NOT NULL,
        token VARCHAR(80) NOT NULL,
        manual_code VARCHAR(20) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        revoked_at DATETIME NULL,
        legacy_source VARCHAR(80) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_ticket_credentials_token (token),
        UNIQUE KEY uq_ticket_credentials_manual_code (manual_code),
        KEY idx_ticket_credentials_entitlement (entitlement_id),
        CONSTRAINT fk_ticket_credentials_entitlement FOREIGN KEY (entitlement_id) REFERENCES entitlements(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS season_ticket_migration_map (
        legacy_order_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        order_item_id INT UNSIGNED NOT NULL,
        payment_id INT UNSIGNED NOT NULL,
        entitlement_id INT UNSIGNED NOT NULL,
        season_pass_id INT UNSIGNED NOT NULL,
        credential_id INT UNSIGNED NOT NULL,
        migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (legacy_order_id),
        UNIQUE KEY uq_st_map_order (order_id),
        UNIQUE KEY uq_st_map_pass (season_pass_id),
        UNIQUE KEY uq_st_map_credential (credential_id),
        CONSTRAINT fk_st_map_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_st_map_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
        CONSTRAINT fk_st_map_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
        CONSTRAINT fk_st_map_entitlement FOREIGN KEY (entitlement_id) REFERENCES entitlements(id) ON DELETE CASCADE,
        CONSTRAINT fk_st_map_pass FOREIGN KEY (season_pass_id) REFERENCES season_passes(id) ON DELETE CASCADE,
        CONSTRAINT fk_st_map_credential FOREIGN KEY (credential_id) REFERENCES ticket_credentials(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function seasonPassOrderStatusFromLegacy(array $legacy): string
{
    if ((string) ($legacy['status'] ?? '') === 'cancelled') {
        return 'cancelled';
    }
    return (int) ($legacy['paid'] ?? 0) === 1 ? 'paid' : 'pending_payment';
}

function seasonPassEntitlementStatusFromLegacy(array $legacy): string
{
    if ((string) ($legacy['status'] ?? '') === 'cancelled') {
        return 'cancelled';
    }
    return ((int) ($legacy['paid'] ?? 0) === 1 && in_array((string) ($legacy['status'] ?? ''), ['complete', 'posted'], true)) ? 'active' : 'pending';
}

function seasonPassProviderForMethod(string $method): string
{
    return match ($method) {
        'stripe', 'jotform' => 'stripe',
        'free_code' => 'internal',
        'cash', 'bank_transfer', 'other', '' => 'manual',
        default => 'manual',
    };
}

function seasonPassPaymentStatusFromOrderStatus(string $orderStatus, bool $paid): string
{
    if ($orderStatus === 'cancelled') {
        return 'cancelled';
    }
    return $paid ? 'paid' : 'pending';
}

function seasonPassCredentialToken(PDO $pdo): string
{
    do {
        $token = bin2hex(random_bytes(20));
        $stmt = $pdo->prepare('SELECT 1 FROM ticket_credentials WHERE token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
    } while ($stmt->fetchColumn());
    return $token;
}

function seasonPassManualCode(PDO $pdo): string
{
    do {
        $code = season_ticket_generate_manual_code($pdo);
        $stmt = $pdo->prepare('SELECT 1 FROM ticket_credentials WHERE manual_code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
    } while ($stmt->fetchColumn());
    return $code;
}

function migrateSeasonTicketOrdersToPasses(PDO $pdo): array
{
    ensureSeasonPassSchema($pdo);
    $legacyRows = $pdo->query("SELECT o.*, h.name AS holder_name, h.email AS holder_email, h.phone AS holder_phone,
            t.name AS type_name, t.code AS type_code, se.name AS season_name, se.start_date, se.end_date,
            m.person_id
        FROM season_ticket_orders o
        JOIN season_ticket_holders h ON h.id = o.holder_id
        JOIN season_ticket_types t ON t.id = o.ticket_type_id
        JOIN seasons se ON se.id = o.season_id
        LEFT JOIN identity_migration_map m ON m.old_holder_id = o.holder_id
        ORDER BY o.id")->fetchAll(PDO::FETCH_ASSOC);
    $created = 0;
    $mappingFailures = 0;

    foreach ($legacyRows as $legacy) {
        $legacyOrderId = (int) $legacy['id'];
        $exists = $pdo->prepare('SELECT 1 FROM season_ticket_migration_map WHERE legacy_order_id = :id LIMIT 1');
        $exists->execute([':id' => $legacyOrderId]);
        if ($exists->fetchColumn()) {
            continue;
        }
        $personId = (int) ($legacy['person_id'] ?? 0);
        if ($personId <= 0) {
            $mappingFailures++;
            continue;
        }

        $price = (float) $legacy['price'];
        $orderStatus = seasonPassOrderStatusFromLegacy($legacy);
        $entitlementStatus = seasonPassEntitlementStatusFromLegacy($legacy);
        $paymentMethod = trim((string) ($legacy['payment_method'] ?? '')) ?: 'other';
        $paid = (int) ($legacy['paid'] ?? 0) === 1;

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO orders
                (person_id, customer_name, customer_email, customer_phone, status, subtotal, discount_total, total_amount, currency, source, terms_accepted_at, terms_text_snapshot, created_at, completed_at, cancelled_at)
                VALUES (:person_id, :name, :email, :phone, :status, :subtotal, 0.00, :total, "GBP", :source, :terms_at, :terms_text, :created_at, :completed_at, :cancelled_at)')
                ->execute([
                    ':person_id' => $personId,
                    ':name' => (string) $legacy['holder_name'],
                    ':email' => $legacy['holder_email'] ?: null,
                    ':phone' => $legacy['holder_phone'] ?: null,
                    ':status' => $orderStatus,
                    ':subtotal' => $price,
                    ':total' => $price,
                    ':source' => (string) ($legacy['source'] ?? 'legacy'),
                    ':terms_at' => $legacy['terms_accepted_at'] ?: null,
                    ':terms_text' => $legacy['terms_text_snapshot'] ?: null,
                    ':created_at' => $legacy['created_at'] ?: date('Y-m-d H:i:s'),
                    ':completed_at' => $paid ? (($legacy['paid_at'] ?? '') ?: null) : null,
                    ':cancelled_at' => $legacy['cancelled_at'] ?: null,
                ]);
            $orderId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO order_items
                (order_id, product_type, product_reference_id, description_snapshot, quantity, unit_price, line_total, metadata_json)
                VALUES (:order_id, "season_ticket", :type_id, :description, 1, :unit_price, :line_total, :metadata)')
                ->execute([
                    ':order_id' => $orderId,
                    ':type_id' => (int) $legacy['ticket_type_id'],
                    ':description' => (string) $legacy['season_name'] . ' ' . (string) $legacy['type_name'] . ' Season Ticket',
                    ':unit_price' => $price,
                    ':line_total' => $price,
                    ':metadata' => json_encode([
                        'legacy_order_id' => $legacyOrderId,
                        'legacy_holder_id' => (int) $legacy['holder_id'],
                        'ticket_type_name' => (string) $legacy['type_name'],
                        'season_name' => (string) $legacy['season_name'],
                        'special_notes' => (string) ($legacy['special_notes'] ?? ''),
                        'delivery_method' => (string) ($legacy['delivery_method'] ?? ''),
                        'delivery_address' => (string) ($legacy['delivery_address'] ?? ''),
                        'cancel_reason' => (string) ($legacy['cancel_reason'] ?? ''),
                        'cancelled_by_user_id' => $legacy['cancelled_by_user_id'] ?? null,
                    ], JSON_THROW_ON_ERROR),
                ]);
            $itemId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO payments
                (order_id, provider, provider_checkout_session_id, payment_method, amount, currency, status, paid_at)
                VALUES (:order_id, :provider, :session_id, :method, :amount, "GBP", :status, :paid_at)')
                ->execute([
                    ':order_id' => $orderId,
                    ':provider' => seasonPassProviderForMethod($paymentMethod),
                    ':session_id' => $legacy['stripe_checkout_session_id'] ?: null,
                    ':method' => $paymentMethod,
                    ':amount' => $price,
                    ':status' => seasonPassPaymentStatusFromOrderStatus($orderStatus, $paid),
                    ':paid_at' => $paid ? (($legacy['paid_at'] ?? '') ?: null) : null,
                ]);
            $paymentId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO entitlements
                (order_item_id, person_id, type, status, valid_from, valid_until, cancelled_at)
                VALUES (:item_id, :person_id, "season_pass", :status, :valid_from, :valid_until, :cancelled_at)')
                ->execute([
                    ':item_id' => $itemId,
                    ':person_id' => $personId,
                    ':status' => $entitlementStatus,
                    ':valid_from' => $legacy['start_date'] ?: null,
                    ':valid_until' => $legacy['end_date'] ?: null,
                    ':cancelled_at' => $legacy['cancelled_at'] ?: null,
                ]);
            $entitlementId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO season_passes
                (entitlement_id, season_id, season_ticket_type_id, status, legacy_season_ticket_order_id)
                VALUES (:entitlement_id, :season_id, :type_id, :status, :legacy_order_id)')
                ->execute([
                    ':entitlement_id' => $entitlementId,
                    ':season_id' => (int) $legacy['season_id'],
                    ':type_id' => (int) $legacy['ticket_type_id'],
                    ':status' => $entitlementStatus,
                    ':legacy_order_id' => $legacyOrderId,
                ]);
            $passId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO ticket_credentials
                (entitlement_id, token, manual_code, is_active, issued_at, revoked_at, legacy_source)
                VALUES (:entitlement_id, :token, :manual_code, :active, :issued_at, :revoked_at, :legacy_source)')
                ->execute([
                    ':entitlement_id' => $entitlementId,
                    ':token' => (string) $legacy['ticket_token'],
                    ':manual_code' => (string) $legacy['manual_code'],
                    ':active' => $entitlementStatus === 'active' ? 1 : 0,
                    ':issued_at' => $legacy['created_at'] ?: date('Y-m-d H:i:s'),
                    ':revoked_at' => in_array($entitlementStatus, ['cancelled', 'refunded'], true) ? ($legacy['cancelled_at'] ?: date('Y-m-d H:i:s')) : null,
                    ':legacy_source' => 'season_ticket_orders:' . $legacyOrderId,
                ]);
            $credentialId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO season_ticket_migration_map
                (legacy_order_id, order_id, order_item_id, payment_id, entitlement_id, season_pass_id, credential_id)
                VALUES (:legacy_order_id, :order_id, :item_id, :payment_id, :entitlement_id, :pass_id, :credential_id)')
                ->execute([
                    ':legacy_order_id' => $legacyOrderId,
                    ':order_id' => $orderId,
                    ':item_id' => $itemId,
                    ':payment_id' => $paymentId,
                    ':entitlement_id' => $entitlementId,
                    ':pass_id' => $passId,
                    ':credential_id' => $credentialId,
                ]);

            $pdo->commit();
            $created++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    return seasonPassMigrationReconciliation($pdo) + ['created_this_run' => $created, 'person_mapping_failures' => $mappingFailures];
}

function seasonPassMigrationReconciliation(PDO $pdo): array
{
    ensureSeasonPassSchema($pdo);
    $counts = [];
    foreach ([
        'legacy_orders' => 'season_ticket_orders',
        'orders' => 'orders',
        'order_items' => 'order_items',
        'payments' => 'payments',
        'entitlements' => 'entitlements',
        'season_passes' => 'season_passes',
        'ticket_credentials' => 'ticket_credentials',
        'migration_map' => 'season_ticket_migration_map',
    ] as $key => $table) {
        $counts[$key] = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
    $counts['token_mismatches'] = (int) $pdo->query("SELECT COUNT(*) FROM season_ticket_migration_map m JOIN season_ticket_orders lo ON lo.id = m.legacy_order_id JOIN ticket_credentials c ON c.id = m.credential_id WHERE lo.ticket_token <> c.token")->fetchColumn();
    $counts['manual_code_mismatches'] = (int) $pdo->query("SELECT COUNT(*) FROM season_ticket_migration_map m JOIN season_ticket_orders lo ON lo.id = m.legacy_order_id JOIN ticket_credentials c ON c.id = m.credential_id WHERE lo.manual_code <> c.manual_code")->fetchColumn();
    $counts['price_mismatches'] = (int) $pdo->query("SELECT COUNT(*) FROM season_ticket_migration_map m JOIN season_ticket_orders lo ON lo.id = m.legacy_order_id JOIN order_items oi ON oi.id = m.order_item_id WHERE lo.price <> oi.line_total")->fetchColumn();
    $counts['paid_legacy_orders'] = (int) $pdo->query('SELECT COUNT(*) FROM season_ticket_orders WHERE paid = 1')->fetchColumn();
    $counts['paid_new_payments'] = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE status = 'paid'")->fetchColumn();
    $counts['cancelled_legacy_orders'] = (int) $pdo->query("SELECT COUNT(*) FROM season_ticket_orders WHERE status = 'cancelled'")->fetchColumn();
    $counts['cancelled_new_entitlements'] = (int) $pdo->query("SELECT COUNT(*) FROM entitlements WHERE type = 'season_pass' AND status = 'cancelled'")->fetchColumn();
    $counts['person_mapping_failures'] = (int) $pdo->query("SELECT COUNT(*) FROM season_ticket_orders lo LEFT JOIN identity_migration_map m ON m.old_holder_id = lo.holder_id WHERE m.person_id IS NULL")->fetchColumn();
    return $counts;
}

function getSeasonPassByCredential(PDO $pdo, string $scanInput): ?array
{
    ensureSeasonPassSchema($pdo);
    $scanInput = trim($scanInput);
    $token = '';
    if (function_exists('season_ticket_extract_token')) {
        $token = season_ticket_extract_token($scanInput);
    } elseif ($scanInput !== '') {
        if (preg_match('#^https?://#i', $scanInput) === 1) {
            $query = (string) parse_url($scanInput, PHP_URL_QUERY);
            parse_str($query, $params);
            $token = is_string($params['token'] ?? null) ? trim($params['token']) : '';
        } elseif (preg_match('/token=([a-f0-9]{20,80})/i', $scanInput, $matches)) {
            $token = $matches[1];
        } elseif (preg_match('/^[a-f0-9]{20,80}$/i', $scanInput) === 1) {
            $token = $scanInput;
        }
    }
    $manualCode = season_ticket_normalize_manual_code($scanInput);
    if ($token === '' && $manualCode === '') {
        return null;
    }
    $where = $token !== '' ? 'c.token = :value' : 'c.manual_code = :value';
    $stmt = $pdo->prepare("SELECT sp.*, e.person_id, e.status AS entitlement_status, e.valid_from, e.valid_until,
            c.id AS credential_id, c.token, c.manual_code, c.is_active AS credential_is_active, c.revoked_at,
            oi.description_snapshot, oi.unit_price, oi.line_total,
            o.id AS order_id, o.status AS order_status, o.total_amount, o.confirmation_email_sent_at,
            p.display_name AS holder_name, p.email AS holder_email, p.phone AS holder_phone,
            t.name AS type_name, se.name AS season_name, se.start_date, se.end_date,
            m.legacy_order_id, lm.old_holder_id AS holder_id
        FROM ticket_credentials c
        JOIN entitlements e ON e.id = c.entitlement_id
        JOIN season_passes sp ON sp.entitlement_id = e.id
        LEFT JOIN order_items oi ON oi.id = e.order_item_id
        LEFT JOIN orders o ON o.id = oi.order_id
        JOIN people p ON p.id = e.person_id
        JOIN season_ticket_types t ON t.id = sp.season_ticket_type_id
        JOIN seasons se ON se.id = sp.season_id
        LEFT JOIN season_ticket_migration_map m ON m.season_pass_id = sp.id
        LEFT JOIN identity_migration_map lm ON lm.person_id = e.person_id
        WHERE {$where}
        LIMIT 1");
    $stmt->execute([':value' => $token !== '' ? $token : $manualCode]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function getSeasonPassByLegacyOrderId(PDO $pdo, int $legacyOrderId): ?array
{
    ensureSeasonPassSchema($pdo);
    $stmt = $pdo->prepare('SELECT c.token FROM season_ticket_migration_map m JOIN ticket_credentials c ON c.id = m.credential_id WHERE m.legacy_order_id = :id LIMIT 1');
    $stmt->execute([':id' => $legacyOrderId]);
    $token = $stmt->fetchColumn();
    return $token !== false ? getSeasonPassByCredential($pdo, (string) $token) : null;
}

function getSeasonPassesForPerson(PDO $pdo, int $personId, array $filters = []): array
{
    ensureSeasonPassSchema($pdo);
    $where = ['e.person_id = :person_id'];
    $params = [':person_id' => $personId];
    if (!empty($filters['season_id'])) {
        $where[] = 'sp.season_id = :season_id';
        $params[':season_id'] = (int) $filters['season_id'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'e.status = :status';
        $params[':status'] = (string) $filters['status'];
    }
    $stmt = $pdo->prepare("SELECT sp.*, e.person_id, e.status AS entitlement_status, e.valid_from, e.valid_until,
            c.id AS credential_id, c.token, c.manual_code, c.is_active AS credential_is_active,
            oi.description_snapshot, oi.unit_price, oi.line_total,
            o.id AS order_id, o.status AS order_status, o.total_amount,
            p.display_name AS holder_name, p.email AS holder_email, p.phone AS holder_phone,
            t.name AS type_name, se.name AS season_name,
            m.legacy_order_id, lm.old_holder_id AS holder_id
        FROM season_passes sp
        JOIN entitlements e ON e.id = sp.entitlement_id
        JOIN ticket_credentials c ON c.entitlement_id = e.id
        LEFT JOIN order_items oi ON oi.id = e.order_item_id
        LEFT JOIN orders o ON o.id = oi.order_id
        JOIN people p ON p.id = e.person_id
        JOIN season_ticket_types t ON t.id = sp.season_ticket_type_id
        JOIN seasons se ON se.id = sp.season_id
        LEFT JOIN season_ticket_migration_map m ON m.season_pass_id = sp.id
        LEFT JOIN identity_migration_map lm ON lm.person_id = e.person_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY se.start_date DESC, sp.id DESC");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function seasonPassAllowsFixture(PDO $pdo, int $seasonPassId, int $fixtureId): bool
{
    ensureSeasonPassSchema($pdo);
    $stmt = $pdo->prepare('SELECT sp.season_id, sp.season_ticket_type_id
        FROM season_passes sp
        WHERE sp.id = :pass_id
        LIMIT 1');
    $stmt->execute([':pass_id' => $seasonPassId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $decision = seasonPassRuleDecision($pdo, (int) $row['season_ticket_type_id'], $fixtureId, (int) $row['season_id']);
    return (bool) ($decision['allowed'] ?? false);
}

function createSeasonPassOrder(PDO $pdo, int $buyerPersonId, int $ownerPersonId, int $seasonTicketTypeId, string $source, string $paymentMethod, float $price, ?string $termsAcceptedAt = null, ?string $termsText = null, ?string $specialNotes = null, ?bool $paidNowOverride = null): array
{
    ensureSeasonPassSchema($pdo);
    $buyer = getPerson($pdo, $buyerPersonId);
    $owner = getPerson($pdo, $ownerPersonId);
    $type = getSeasonTicketType($pdo, $seasonTicketTypeId);
    if (!$buyer || !$owner || !$type) {
        throw new RuntimeException('Cannot create season ticket order: missing buyer, owner or ticket type.');
    }

    $paidNow = $paidNowOverride ?? in_array($paymentMethod, ['cash', 'bank_transfer', 'free_code'], true);
    $orderStatus = $paidNow ? 'paid' : 'pending_payment';
    $entitlementStatus = $paidNow ? 'active' : 'pending';
    $provider = seasonPassProviderForMethod($paymentMethod);
    $amount = $paymentMethod === 'free_code' ? 0.0 : $price;
    $duplicate = $pdo->prepare("SELECT sp.id
        FROM season_passes sp
        JOIN entitlements e ON e.id = sp.entitlement_id
        WHERE e.person_id = :person_id
          AND sp.season_id = :season_id
          AND sp.season_ticket_type_id = :type_id
          AND e.status IN ('active', 'pending')
          AND sp.status IN ('active', 'pending')
        LIMIT 1");
    $duplicate->execute([
        ':person_id' => $ownerPersonId,
        ':season_id' => (int) $type['season_id'],
        ':type_id' => $seasonTicketTypeId,
    ]);
    if ($duplicate->fetchColumn()) {
        throw new RuntimeException('This person already has an active or pending pass for that season and ticket type.');
    }

    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $pdo->prepare('INSERT INTO orders
            (person_id, customer_name, customer_email, customer_phone, status, subtotal, discount_total, total_amount, currency, source, terms_accepted_at, terms_text_snapshot, completed_at)
            VALUES (:person_id, :name, :email, :phone, :status, :subtotal, 0.00, :total, "GBP", :source, :terms_at, :terms_text, :completed_at)')
            ->execute([
                ':person_id' => $buyerPersonId,
                ':name' => (string) $buyer['display_name'],
                ':email' => $buyer['email'] ?: null,
                ':phone' => $buyer['phone'] ?: null,
                ':status' => $orderStatus,
                ':subtotal' => $amount,
                ':total' => $amount,
                ':source' => $source,
                ':terms_at' => $termsAcceptedAt,
                ':terms_text' => $termsText,
                ':completed_at' => $paidNow ? date('Y-m-d H:i:s') : null,
            ]);
        $orderId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO order_items
            (order_id, product_type, product_reference_id, description_snapshot, quantity, unit_price, line_total, metadata_json)
            VALUES (:order_id, "season_ticket", :type_id, :description, 1, :unit_price, :line_total, :metadata)')
            ->execute([
                ':order_id' => $orderId,
                ':type_id' => $seasonTicketTypeId,
                ':description' => (string) $type['name'] . ' Season Ticket',
                ':unit_price' => $amount,
                ':line_total' => $amount,
                ':metadata' => json_encode(['owner_person_id' => $ownerPersonId, 'special_notes' => $specialNotes], JSON_THROW_ON_ERROR),
            ]);
        $itemId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO payments
            (order_id, provider, payment_method, amount, currency, status, paid_at)
            VALUES (:order_id, :provider, :method, :amount, "GBP", :status, :paid_at)')
            ->execute([
                ':order_id' => $orderId,
                ':provider' => $provider,
                ':method' => $paymentMethod,
                ':amount' => $amount,
                ':status' => $paidNow ? 'paid' : 'pending',
                ':paid_at' => $paidNow ? date('Y-m-d H:i:s') : null,
            ]);
        $paymentId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO entitlements
            (order_item_id, person_id, type, status)
            VALUES (:item_id, :person_id, "season_pass", :status)')
            ->execute([':item_id' => $itemId, ':person_id' => $ownerPersonId, ':status' => $entitlementStatus]);
        $entitlementId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO season_passes
            (entitlement_id, season_id, season_ticket_type_id, status)
            VALUES (:entitlement_id, :season_id, :type_id, :status)')
            ->execute([
                ':entitlement_id' => $entitlementId,
                ':season_id' => (int) $type['season_id'],
                ':type_id' => $seasonTicketTypeId,
                ':status' => $entitlementStatus,
            ]);
        $passId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO ticket_credentials
            (entitlement_id, token, manual_code, is_active, legacy_source)
            VALUES (:entitlement_id, :token, :manual_code, :active, "season_passes")')
            ->execute([
                ':entitlement_id' => $entitlementId,
                ':token' => seasonPassCredentialToken($pdo),
                ':manual_code' => seasonPassManualCode($pdo),
                ':active' => $entitlementStatus === 'active' ? 1 : 0,
            ]);
        $credentialId = (int) $pdo->lastInsertId();

        if ($ownTransaction) {
            $pdo->commit();
        }
        return getSeasonPassOrderBundle($pdo, $orderId) + ['payment_id' => $paymentId, 'season_pass_id' => $passId, 'credential_id' => $credentialId];
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function getSeasonPassOrderBundle(PDO $pdo, int $orderId): array
{
    ensureSeasonPassSchema($pdo);
    $stmt = $pdo->prepare("SELECT o.*, oi.id AS order_item_id, oi.description_snapshot, oi.product_reference_id, oi.unit_price, oi.line_total,
            pay.id AS payment_id, pay.provider, pay.provider_checkout_session_id, pay.payment_method, pay.status AS payment_status,
            e.id AS entitlement_id, e.person_id AS owner_person_id, e.status AS entitlement_status,
            sp.id AS season_pass_id, sp.season_id, sp.season_ticket_type_id, sp.status AS pass_status,
            c.id AS credential_id, c.token, c.manual_code, c.is_active AS credential_is_active,
            p.display_name AS holder_name, p.email AS holder_email,
            t.name AS type_name, se.name AS season_name
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        JOIN payments pay ON pay.order_id = o.id
        JOIN entitlements e ON e.order_item_id = oi.id
        JOIN season_passes sp ON sp.entitlement_id = e.id
        JOIN ticket_credentials c ON c.entitlement_id = e.id
        JOIN people p ON p.id = e.person_id
        JOIN season_ticket_types t ON t.id = sp.season_ticket_type_id
        JOIN seasons se ON se.id = sp.season_id
        WHERE o.id = :order_id
        LIMIT 1");
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function getSeasonPassOrderList(PDO $pdo, array $filters = []): array
{
    ensureSeasonPassSchema($pdo);
    $where = ["oi.product_type = 'season_ticket'"];
    $params = [];
    if (!empty($filters['season_id'])) {
        $where[] = 'sp.season_id = :season_id';
        $params[':season_id'] = (int) $filters['season_id'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'o.status = :status';
        $params[':status'] = (string) $filters['status'];
    }
    if (!empty($filters['ticket_type_id'])) {
        $where[] = 'sp.season_ticket_type_id = :ticket_type_id';
        $params[':ticket_type_id'] = (int) $filters['ticket_type_id'];
    }
    if (!empty($filters['payment_status'])) {
        $where[] = 'pay.status = :payment_status';
        $params[':payment_status'] = (string) $filters['payment_status'];
    }
    if (!empty($filters['payment_method'])) {
        $where[] = 'pay.payment_method = :payment_method';
        $params[':payment_method'] = (string) $filters['payment_method'];
    }
    if (!empty($filters['ticket_status'])) {
        $where[] = 'e.status = :ticket_status';
        $params[':ticket_status'] = (string) $filters['ticket_status'];
    }
    if (isset($filters['credential_active']) && $filters['credential_active'] !== '') {
        $where[] = 'c.is_active = :credential_active';
        $params[':credential_active'] = (int) $filters['credential_active'];
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'DATE(o.created_at) >= :date_from';
        $params[':date_from'] = (string) $filters['date_from'];
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'DATE(o.created_at) <= :date_to';
        $params[':date_to'] = (string) $filters['date_to'];
    }
    if (!empty($filters['q'])) {
        $where[] = '(p.display_name LIKE :q OR p.email LIKE :q OR c.manual_code LIKE :q OR c.token LIKE :q OR CAST(o.id AS CHAR) LIKE :q OR t.name LIKE :q)';
        $params[':q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    $stmt = $pdo->prepare("SELECT o.*, oi.description_snapshot, oi.unit_price, oi.line_total,
            pay.payment_method, pay.status AS payment_status, pay.paid_at,
            e.status AS entitlement_status, sp.id AS season_pass_id, sp.season_id, sp.legacy_season_ticket_order_id,
            c.token, c.manual_code, c.is_active AS credential_is_active,
            p.display_name AS holder_name, p.email AS holder_email,
            lm.old_holder_id AS holder_id,
            t.name AS type_name, se.name AS season_name
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        JOIN payments pay ON pay.order_id = o.id
        JOIN entitlements e ON e.order_item_id = oi.id
        JOIN season_passes sp ON sp.entitlement_id = e.id
        JOIN ticket_credentials c ON c.entitlement_id = e.id
        JOIN people p ON p.id = e.person_id
        JOIN season_ticket_types t ON t.id = sp.season_ticket_type_id
        JOIN seasons se ON se.id = sp.season_id
        LEFT JOIN identity_migration_map lm ON lm.person_id = e.person_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY o.created_at DESC, o.id DESC");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function attachSeasonPassOrderStripeSession(PDO $pdo, int $orderId, string $sessionId): void
{
    ensureSeasonPassSchema($pdo);
    $pdo->prepare('UPDATE payments SET provider_checkout_session_id = :session_id WHERE order_id = :order_id')
        ->execute([':session_id' => $sessionId, ':order_id' => $orderId]);
}

function getSeasonPassOrdersByStripeSession(PDO $pdo, string $sessionId): array
{
    ensureSeasonPassSchema($pdo);
    $stmt = $pdo->prepare('SELECT order_id FROM payments WHERE provider_checkout_session_id = :session_id');
    $stmt->execute([':session_id' => $sessionId]);
    return array_map(static fn($id): array => getSeasonPassOrderBundle($pdo, (int) $id), $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function markSeasonPassOrderPaid(PDO $pdo, int $orderId, ?string $providerPaymentId = null): void
{
    ensureSeasonPassSchema($pdo);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE payments SET status = 'paid', provider_payment_id = COALESCE(:provider_payment_id, provider_payment_id), paid_at = COALESCE(paid_at, NOW()) WHERE order_id = :order_id")
            ->execute([':provider_payment_id' => $providerPaymentId, ':order_id' => $orderId]);
        $pdo->prepare("UPDATE orders SET status = 'paid', completed_at = COALESCE(completed_at, NOW()) WHERE id = :order_id AND status <> 'paid'")
            ->execute([':order_id' => $orderId]);
        $pdo->prepare("UPDATE entitlements e JOIN order_items oi ON oi.id = e.order_item_id SET e.status = 'active' WHERE oi.order_id = :order_id AND e.status = 'pending'")
            ->execute([':order_id' => $orderId]);
        $pdo->prepare("UPDATE season_passes sp JOIN entitlements e ON e.id = sp.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id SET sp.status = 'active' WHERE oi.order_id = :order_id AND sp.status = 'pending'")
            ->execute([':order_id' => $orderId]);
        $pdo->prepare("UPDATE ticket_credentials c JOIN entitlements e ON e.id = c.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id SET c.is_active = 1, c.revoked_at = NULL WHERE oi.order_id = :order_id")
            ->execute([':order_id' => $orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function sendSeasonPassConfirmationIfNeeded(PDO $pdo, int $orderId, bool $manualPayment): bool
{
    ensureSeasonPassSchema($pdo);
    $bundle = getSeasonPassOrderBundle($pdo, $orderId);
    if (!$bundle || !empty($bundle['confirmation_email_sent_at'])) {
        return false;
    }
    if ((string) ($bundle['payment_status'] ?? '') !== 'paid') {
        return false;
    }
    $email = trim((string) ($bundle['customer_email'] ?? $bundle['holder_email'] ?? ''));
    if ($email === '') {
        return false;
    }
    $sent = seasonTicketSendConfirmationEmail($email, (string) ($bundle['customer_name'] ?? $bundle['holder_name'] ?? ''), [[
        'id' => (int) $bundle['id'],
        'price' => (float) $bundle['line_total'],
        'holder_name' => (string) $bundle['holder_name'],
        'type_name' => (string) $bundle['type_name'],
        'token' => (string) $bundle['token'],
        'manual_code' => (string) $bundle['manual_code'],
    ]], $manualPayment);
    if ($sent) {
        $pdo->prepare('UPDATE orders SET confirmation_email_sent_at = NOW() WHERE id = :id AND confirmation_email_sent_at IS NULL')
            ->execute([':id' => $orderId]);
    }
    return $sent;
}

function cancelSeasonPassOrder(PDO $pdo, int $orderId, string $reason, ?int $accountId = null): void
{
    ensureSeasonPassSchema($pdo);
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A cancellation reason is required.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE orders SET status = 'cancelled', cancelled_at = COALESCE(cancelled_at, NOW()) WHERE id = :order_id")
            ->execute([':order_id' => $orderId]);
        $pdo->prepare("UPDATE payments SET status = IF(status = 'paid', status, 'cancelled') WHERE order_id = :order_id")
            ->execute([':order_id' => $orderId]);
        $pdo->prepare("UPDATE entitlements e JOIN order_items oi ON oi.id = e.order_item_id SET e.status = 'cancelled', e.cancelled_at = COALESCE(e.cancelled_at, NOW()) WHERE oi.order_id = :order_id")
            ->execute([':order_id' => $orderId]);
        $pdo->prepare("UPDATE season_passes sp JOIN entitlements e ON e.id = sp.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id SET sp.status = 'cancelled' WHERE oi.order_id = :order_id")
            ->execute([':order_id' => $orderId]);
        $pdo->prepare("UPDATE ticket_credentials c JOIN entitlements e ON e.id = c.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id SET c.is_active = 0, c.revoked_at = COALESCE(c.revoked_at, NOW()) WHERE oi.order_id = :order_id")
            ->execute([':order_id' => $orderId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function refundSeasonPassOrder(PDO $pdo, int $orderId, float $amount, string $reason, ?int $accountId = null, ?string $providerRefundId = null): void
{
    ensureSeasonPassSchema($pdo);
    $bundle = getSeasonPassOrderBundle($pdo, $orderId);
    if (!$bundle) {
        throw new RuntimeException('Order not found.');
    }
    $paymentId = (int) $bundle['payment_id'];
    $total = (float) $bundle['total_amount'];
    $fullRefund = $amount >= $total;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO refunds (payment_id, amount, reason, provider_refund_id, status, requested_by_account_id, completed_at)
            VALUES (:payment_id, :amount, :reason, :provider_refund_id, "complete", :account_id, NOW())')
            ->execute([
                ':payment_id' => $paymentId,
                ':amount' => $amount,
                ':reason' => trim($reason) ?: null,
                ':provider_refund_id' => $providerRefundId,
                ':account_id' => $accountId,
            ]);
        $pdo->prepare('UPDATE orders SET status = :status WHERE id = :order_id')
            ->execute([':status' => $fullRefund ? 'refunded' : 'partially_refunded', ':order_id' => $orderId]);
        if ($fullRefund) {
            $pdo->prepare("UPDATE entitlements e JOIN order_items oi ON oi.id = e.order_item_id SET e.status = 'refunded', e.cancelled_at = COALESCE(e.cancelled_at, NOW()) WHERE oi.order_id = :order_id")
                ->execute([':order_id' => $orderId]);
            $pdo->prepare("UPDATE season_passes sp JOIN entitlements e ON e.id = sp.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id SET sp.status = 'refunded' WHERE oi.order_id = :order_id")
                ->execute([':order_id' => $orderId]);
            $pdo->prepare("UPDATE ticket_credentials c JOIN entitlements e ON e.id = c.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id SET c.is_active = 0, c.revoked_at = COALESCE(c.revoked_at, NOW()) WHERE oi.order_id = :order_id")
                ->execute([':order_id' => $orderId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function seasonPassLegacyOrderShape(array $pass): array
{
    $paid = in_array((string) ($pass['order_status'] ?? ''), ['paid', 'partially_refunded'], true) ? 1 : 0;
    return [
        'id' => (int) ($pass['legacy_order_id'] ?? 0),
        'person_id' => (int) ($pass['person_id'] ?? $pass['owner_person_id'] ?? 0),
        'holder_id' => (int) ($pass['holder_id'] ?? 0),
        'season_id' => (int) $pass['season_id'],
        'ticket_type_id' => (int) $pass['season_ticket_type_id'],
        'price' => (float) ($pass['line_total'] ?? $pass['total_amount'] ?? 0),
        'paid' => $paid,
        'status' => (string) ($pass['entitlement_status'] ?? '') === 'active' ? 'complete' : (string) ($pass['entitlement_status'] ?? 'pending_payment'),
        'holder_name' => (string) ($pass['holder_name'] ?? ''),
        'holder_email' => (string) ($pass['holder_email'] ?? ''),
        'type_name' => (string) ($pass['type_name'] ?? ''),
        'season_name' => (string) ($pass['season_name'] ?? ''),
        'ticket_token' => (string) ($pass['token'] ?? ''),
        'manual_code' => (string) ($pass['manual_code'] ?? ''),
        'order_date' => (string) ($pass['created_at'] ?? date('Y-m-d')),
    ];
}
