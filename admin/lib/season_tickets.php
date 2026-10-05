<?php

declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

function ensureSeasonTicketSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS season_ticket_types (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        season_id INT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        code VARCHAR(60) NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_season_ticket_types_season_code (season_id, code),
        KEY idx_season_ticket_types_season (season_id, is_active),
        CONSTRAINT fk_season_ticket_types_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS season_ticket_holders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(190) NOT NULL,
        date_of_birth DATE NULL,
        email VARCHAR(190) NULL,
        phone VARCHAR(50) NULL,
        address VARCHAR(255) NULL,
        address_line1 VARCHAR(190) NULL,
        address_line2 VARCHAR(190) NULL,
        town VARCHAR(120) NULL,
        country VARCHAR(120) NULL,
        postcode VARCHAR(30) NULL,
        profile_image_path VARCHAR(255) NULL,
        sponsor_id INT UNSIGNED NULL,
        managed_by_holder_id INT UNSIGNED NULL,
        marketing_opt_in TINYINT(1) NOT NULL DEFAULT 1,
        unsubscribe_token VARCHAR(64) NOT NULL,
        unsubscribed_at DATETIME NULL,
        notes TEXT NULL,
        password_hash VARCHAR(255) NULL,
        reset_token_hash VARCHAR(255) NULL,
        reset_token_expires_at DATETIME NULL,
        email_verified_at DATETIME NULL,
        last_login_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_season_ticket_holders_token (unsubscribe_token),
        KEY idx_season_ticket_holders_sponsor (sponsor_id),
        KEY idx_season_ticket_holders_managed_by (managed_by_holder_id),
        KEY idx_season_ticket_holders_email (email),
        CONSTRAINT fk_season_ticket_holders_sponsor FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE SET NULL,
        CONSTRAINT fk_season_ticket_holders_managed_by FOREIGN KEY (managed_by_holder_id) REFERENCES season_ticket_holders(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS season_ticket_orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        holder_id INT UNSIGNED NOT NULL,
        season_id INT UNSIGNED NOT NULL,
        ticket_type_id INT UNSIGNED NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        order_date DATE NOT NULL,
        paid TINYINT(1) NOT NULL DEFAULT 0,
        paid_at DATE NULL,
        payment_method VARCHAR(40) NULL,
        delivery_method VARCHAR(60) NULL,
        delivery_address VARCHAR(255) NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
        source VARCHAR(30) NOT NULL DEFAULT 'admin',
        terms_accepted_at DATETIME NULL,
        terms_text_snapshot MEDIUMTEXT NULL,
        special_notes VARCHAR(255) NULL,
        cancelled_at DATETIME NULL,
        cancelled_by_user_id INT UNSIGNED NULL,
        cancel_reason TEXT NULL,
        legacy_order_no INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_season_ticket_orders_holder (holder_id),
        KEY idx_season_ticket_orders_season (season_id, status),
        KEY idx_season_ticket_orders_type (ticket_type_id),
        CONSTRAINT fk_season_ticket_orders_holder FOREIGN KEY (holder_id) REFERENCES season_ticket_holders(id) ON DELETE CASCADE,
        CONSTRAINT fk_season_ticket_orders_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
        CONSTRAINT fk_season_ticket_orders_type FOREIGN KEY (ticket_type_id) REFERENCES season_ticket_types(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS season_ticket_free_signup_codes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        season_id INT UNSIGNED NOT NULL,
        code VARCHAR(64) NOT NULL,
        note VARCHAR(255) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        max_uses INT UNSIGNED NOT NULL DEFAULT 1,
        used_count INT UNSIGNED NOT NULL DEFAULT 0,
        used_by_person_id INT UNSIGNED NULL,
        used_by_holder_id INT UNSIGNED NULL,
        used_at DATETIME NULL,
        expires_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_season_ticket_free_codes_code (code),
        KEY idx_season_ticket_free_codes_season (season_id, active),
        KEY idx_season_ticket_free_codes_person (used_by_person_id),
        CONSTRAINT fk_season_ticket_free_codes_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
        CONSTRAINT fk_season_ticket_free_codes_person FOREIGN KEY (used_by_person_id) REFERENCES people(id) ON DELETE SET NULL,
        CONSTRAINT fk_season_ticket_free_codes_holder FOREIGN KEY (used_by_holder_id) REFERENCES season_ticket_holders(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS season_ticket_holder_addresses (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        holder_id INT UNSIGNED NOT NULL,
        label VARCHAR(80) NULL,
        address_line1 VARCHAR(190) NULL,
        address_line2 VARCHAR(190) NULL,
        town VARCHAR(120) NULL,
        country VARCHAR(120) NULL,
        postcode VARCHAR(30) NULL,
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_holder_addresses_holder (holder_id, is_primary),
        CONSTRAINT fk_holder_addresses_holder FOREIGN KEY (holder_id) REFERENCES season_ticket_holders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $sessionColumn = $pdo->query("SHOW COLUMNS FROM season_ticket_orders LIKE 'stripe_checkout_session_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$sessionColumn) {
        $pdo->exec("ALTER TABLE season_ticket_orders ADD COLUMN stripe_checkout_session_id VARCHAR(190) NULL AFTER source, ADD KEY idx_season_ticket_orders_stripe_session (stripe_checkout_session_id)");
    }

    $reminderColumn = $pdo->query("SHOW COLUMNS FROM season_ticket_holders LIKE 'renewal_reminder_sent_at'")->fetch(PDO::FETCH_ASSOC);
    if (!$reminderColumn) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN renewal_reminder_sent_at DATETIME NULL AFTER unsubscribed_at");
    }

    $holderColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM season_ticket_holders') as $row) {
        $holderColumns[(string) $row['Field']] = true;
    }
    if (!isset($holderColumns['date_of_birth'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN date_of_birth DATE NULL AFTER name");
    }
    if (!isset($holderColumns['address_line1'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN address_line1 VARCHAR(190) NULL AFTER address");
    }
    if (!isset($holderColumns['address_line2'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN address_line2 VARCHAR(190) NULL AFTER address_line1");
    }
    if (!isset($holderColumns['town'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN town VARCHAR(120) NULL AFTER address_line2");
    }
    if (!isset($holderColumns['country'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN country VARCHAR(120) NULL AFTER town");
    }
    if (!isset($holderColumns['postcode'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN postcode VARCHAR(30) NULL AFTER country");
    }
    if (!isset($holderColumns['profile_image_path'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN profile_image_path VARCHAR(255) NULL AFTER postcode");
    }
    if (!isset($holderColumns['role'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'public' AFTER password_hash, ADD KEY idx_season_ticket_holders_role (role)");
    }
    if (!isset($holderColumns['username'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN username VARCHAR(100) NULL AFTER role, ADD UNIQUE KEY uq_season_ticket_holders_username (username)");
    }
    if (!isset($holderColumns['is_active'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER username");
    }
    if (!isset($holderColumns['legacy_staff_role'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN legacy_staff_role VARCHAR(50) NULL AFTER is_active");
    }
    if (!isset($holderColumns['position_id'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN position_id INT UNSIGNED NULL AFTER role, ADD KEY idx_season_ticket_holders_position (position_id)");
    }
    if (!isset($holderColumns['email_normalized'])) {
        $pdo->exec("ALTER TABLE season_ticket_holders ADD COLUMN email_normalized VARCHAR(190) NULL AFTER email");
        $pdo->exec("UPDATE season_ticket_holders SET email_normalized = NULLIF(LOWER(TRIM(email)), '') WHERE email IS NOT NULL");
    }

    $emailIndexes = [];
    foreach ($pdo->query('SHOW INDEX FROM season_ticket_holders') as $row) {
        $emailIndexes[(string) $row['Key_name']][] = (string) $row['Column_name'];
    }
    if (!isset($emailIndexes['uq_season_ticket_holders_email_normalized'])) {
        $duplicateStmt = $pdo->query("SELECT email_normalized FROM season_ticket_holders WHERE email_normalized IS NOT NULL GROUP BY email_normalized HAVING COUNT(*) > 1 LIMIT 1");
        if (!$duplicateStmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE season_ticket_holders ADD UNIQUE KEY uq_season_ticket_holders_email_normalized (email_normalized)");
        }
    }

    $ticketTokenColumn = $pdo->query("SHOW COLUMNS FROM season_ticket_orders LIKE 'ticket_token'")->fetch(PDO::FETCH_ASSOC);
    if (!$ticketTokenColumn) {
        $pdo->exec("ALTER TABLE season_ticket_orders ADD COLUMN ticket_token VARCHAR(64) NULL AFTER stripe_checkout_session_id, ADD UNIQUE KEY uq_season_ticket_orders_token (ticket_token)");
        $stmt = $pdo->query('SELECT id FROM season_ticket_orders WHERE ticket_token IS NULL');
        $backfill = $pdo->prepare('UPDATE season_ticket_orders SET ticket_token = :token WHERE id = :id');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            $backfill->execute([':token' => bin2hex(random_bytes(20)), ':id' => $orderId]);
        }
    }

    $termsAcceptedColumn = $pdo->query("SHOW COLUMNS FROM season_ticket_orders LIKE 'terms_accepted_at'")->fetch(PDO::FETCH_ASSOC);
    if (!$termsAcceptedColumn) {
        $pdo->exec("ALTER TABLE season_ticket_orders ADD COLUMN terms_accepted_at DATETIME NULL AFTER ticket_token");
    }

    $termsSnapshotColumn = $pdo->query("SHOW COLUMNS FROM season_ticket_orders LIKE 'terms_text_snapshot'")->fetch(PDO::FETCH_ASSOC);
    if (!$termsSnapshotColumn) {
        $pdo->exec("ALTER TABLE season_ticket_orders ADD COLUMN terms_text_snapshot MEDIUMTEXT NULL AFTER terms_accepted_at");
    }

    $manualCodeColumn = $pdo->query("SHOW COLUMNS FROM season_ticket_orders LIKE 'manual_code'")->fetch(PDO::FETCH_ASSOC);
    if (!$manualCodeColumn) {
        $pdo->exec("ALTER TABLE season_ticket_orders ADD COLUMN manual_code VARCHAR(9) NULL AFTER ticket_token, ADD UNIQUE KEY uq_season_ticket_orders_manual_code (manual_code)");
        $stmt = $pdo->query('SELECT id FROM season_ticket_orders WHERE manual_code IS NULL');
        $backfill = $pdo->prepare('UPDATE season_ticket_orders SET manual_code = :code WHERE id = :id');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            $backfill->execute([':code' => season_ticket_generate_manual_code($pdo), ':id' => $orderId]);
        }
    }

    $orderColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM season_ticket_orders') as $row) {
        $orderColumns[(string) $row['Field']] = true;
    }
    $orderColumnDefs = [
        'cancelled_at' => 'DATETIME NULL AFTER special_notes',
        'cancelled_by_user_id' => 'INT UNSIGNED NULL AFTER cancelled_at',
        'cancel_reason' => 'TEXT NULL AFTER cancelled_by_user_id',
    ];
    foreach ($orderColumnDefs as $column => $definition) {
        if (!isset($orderColumns[$column])) {
            $pdo->exec("ALTER TABLE season_ticket_orders ADD COLUMN {$column} {$definition}");
        }
    }

    $done = true;
}

/**
 * A short, easy-to-read-aloud-or-type code for gate staff to enter by hand
 * when the QR code won't scan. Deliberately much shorter than ticket_token
 * (which stays long/random since it's embedded in a URL, not typed) and
 * avoids characters that are easily confused (0/O, 1/I/L).
 */
function season_ticket_generate_manual_code(PDO $pdo): string
{
    $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
    do {
        $code = '';
        for ($i = 0; $i < 7; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $formatted = substr($code, 0, 3) . '-' . substr($code, 3);
        $stmt = $pdo->prepare('SELECT 1 FROM season_ticket_orders WHERE manual_code = :code LIMIT 1');
        $stmt->execute([':code' => $formatted]);
    } while ($stmt->fetchColumn());

    return $formatted;
}

function season_ticket_normalize_manual_code(string $input): string
{
    $clean = strtoupper(trim($input));
    $clean = preg_replace('/[^A-Z0-9]/', '', $clean) ?? '';
    if ($clean === '' || strlen($clean) !== 7) {
        return '';
    }

    return substr($clean, 0, 3) . '-' . substr($clean, 3);
}

/**
 * A season ticket is only valid for the season it was bought for, so "who
 * needs to renew" is: paid in the previous season, but has no order yet
 * (paid or otherwise) in the current one.
 *
 * @return list<array<string, mixed>>
 */
function getLapsedSeasonTicketHolders(PDO $pdo, int $previousSeasonId, int $currentSeasonId): array
{
    ensureSeasonTicketSchema($pdo);
    require_once __DIR__ . '/season_passes.php';
    ensureSeasonPassSchema($pdo);
    $stmt = $pdo->prepare("SELECT p.id AS person_id, p.display_name AS name, p.email, p.phone,
            h.id, h.renewal_reminder_sent_at,
            t.name AS previous_type_name, oi.line_total AS previous_price
        FROM people p
        JOIN entitlements e ON e.person_id = p.id AND e.type = 'season_pass' AND e.status = 'active'
        JOIN season_passes sp ON sp.entitlement_id = e.id AND sp.season_id = :previous_season
        JOIN season_ticket_types t ON t.id = sp.season_ticket_type_id
        LEFT JOIN order_items oi ON oi.id = e.order_item_id
        LEFT JOIN identity_migration_map m ON m.person_id = p.id
        LEFT JOIN season_ticket_holders h ON h.id = m.old_holder_id
        WHERE NOT EXISTS (
            SELECT 1
            FROM entitlements cur_e
            JOIN season_passes cur_sp ON cur_sp.entitlement_id = cur_e.id
            WHERE cur_e.person_id = p.id
              AND cur_e.type = 'season_pass'
              AND cur_e.status IN ('active', 'pending')
              AND cur_sp.season_id = :current_season
        )
        AND p.email IS NOT NULL AND p.email != ''
        AND p.unsubscribed_at IS NULL
        GROUP BY p.id, sp.id, t.name, oi.line_total, h.id, h.renewal_reminder_sent_at
        ORDER BY p.display_name");
    $stmt->execute([':previous_season' => $previousSeasonId, ':current_season' => $currentSeasonId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function seasonTicketSendRenewalReminder(array $holder, string $signupUrl): bool
{
    $to = trim((string) ($holder['email'] ?? ''));
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = 'Time to renew your Saltcoats Victoria FC season ticket';
    $message = implode("\n", [
        'Hi ' . (string) $holder['name'] . ',',
        '',
        'Your season ticket was for last season, and it\'s time to renew for the new one.',
        '',
        'Renew here: ' . $signupUrl,
        '',
        'Thanks for your support,',
        'Saltcoats Victoria FC',
    ]);

    return hub_send_mail($to, $subject, $message, false);
}

function markSeasonTicketRenewalReminderSent(PDO $pdo, int $holderId): void
{
    $pdo->prepare('UPDATE season_ticket_holders SET renewal_reminder_sent_at = NOW() WHERE id = :id')->execute([':id' => $holderId]);
}

function seasonTicketOrderStatusOptions(): array
{
    return [
        'pending_payment' => 'Pending payment',
        'complete' => 'Complete',
        'posted' => 'Posted',
        'cancelled' => 'Cancelled',
    ];
}

function seasonTicketPaymentMethodOptions(): array
{
    return [
        'cash' => 'Cash',
        'jotform' => 'Jotform (Online)',
        'stripe' => 'Stripe (Online)',
        'bank_transfer' => 'Bank transfer',
        'free_code' => 'Free signup code',
        'other' => 'Other',
    ];
}

function seasonTicketDeliveryMethodOptions(): array
{
    return [
        '' => 'Not specified',
        'collect_preseason' => 'Collect at PreSeason',
        'collect_open_night' => 'Collect at Open Night',
        'collect_training' => 'Collect at training',
        'post' => 'Posted',
        'other' => 'Other',
    ];
}

function seasonTicketGenerateUnsubscribeToken(): string
{
    return bin2hex(random_bytes(24));
}

function seasonTicketNormalizeEmail(?string $email): ?string
{
    $normalized = strtolower(trim((string) $email));
    return $normalized !== '' ? $normalized : null;
}

/**
 * @return list<array<string, mixed>>
 */
function getSeasonTicketTypes(PDO $pdo, ?int $seasonId = null, bool $activeOnly = false): array
{
    ensureSeasonTicketSchema($pdo);
    $where = [];
    $params = [];
    if ($seasonId !== null) {
        $where[] = 'season_id = :season_id';
        $params[':season_id'] = $seasonId;
    }
    if ($activeOnly) {
        $where[] = 'is_active = 1';
    }
    $sql = 'SELECT * FROM season_ticket_types' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY sort_order, name, id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSeasonTicketType(PDO $pdo, int $id): ?array
{
    ensureSeasonTicketSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM season_ticket_types WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function seasonTicketNormalizeFreeSignupCode(string $code): string
{
    return strtoupper(trim($code));
}

function getSeasonTicketFreeSignupCode(PDO $pdo, string $code, int $seasonId): ?array
{
    ensureSeasonTicketSchema($pdo);
    $normalized = seasonTicketNormalizeFreeSignupCode($code);
    if ($normalized === '') {
        return null;
    }

    $stmt = $pdo->prepare('
        SELECT *
        FROM season_ticket_free_signup_codes
        WHERE code = :code
          AND season_id = :season_id
        LIMIT 1
    ');
    $stmt->execute([':code' => $normalized, ':season_id' => $seasonId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function seasonTicketFreeSignupCodeIsUsable(?array $code): bool
{
    if (!$code || (int) ($code['active'] ?? 0) !== 1) {
        return false;
    }
    if ((int) ($code['used_count'] ?? 0) >= (int) ($code['max_uses'] ?? 1)) {
        return false;
    }
    $expiresAt = trim((string) ($code['expires_at'] ?? ''));
    return $expiresAt === '' || strtotime($expiresAt) === false || strtotime($expiresAt) >= time();
}

function redeemSeasonTicketFreeSignupCode(PDO $pdo, string $code, int $seasonId, int $personId, ?int $legacyHolderId = null): array
{
    ensureSeasonTicketSchema($pdo);
    if ($personId <= 0) {
        throw new RuntimeException('Your member identity could not be resolved.');
    }
    $normalized = seasonTicketNormalizeFreeSignupCode($code);
    if ($normalized === '') {
        throw new RuntimeException('Missing free signup code.');
    }

    $stmt = $pdo->prepare('
        SELECT *
        FROM season_ticket_free_signup_codes
        WHERE code = :code
          AND season_id = :season_id
        LIMIT 1
        FOR UPDATE
    ');
    $stmt->execute([':code' => $normalized, ':season_id' => $seasonId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!seasonTicketFreeSignupCodeIsUsable($row ?: null)) {
        throw new RuntimeException('This free signup code is invalid or has already been used.');
    }

    $update = $pdo->prepare('
        UPDATE season_ticket_free_signup_codes
        SET used_count = used_count + 1,
            used_by_person_id = :person_id,
            used_at = NOW()
        WHERE id = :id
    ');
    $update->execute([':person_id' => $personId, ':id' => (int) $row['id']]);

    return $row;
}

function createSeasonTicketFreeSignupCode(PDO $pdo, int $seasonId, ?string $code = null, ?string $note = null, ?string $expiresAt = null): string
{
    ensureSeasonTicketSchema($pdo);
    $normalized = seasonTicketNormalizeFreeSignupCode($code ?? '');
    if ($normalized === '') {
        $normalized = strtoupper(bin2hex(random_bytes(4)));
    }

    $stmt = $pdo->prepare('
        INSERT INTO season_ticket_free_signup_codes (season_id, code, note, expires_at)
        VALUES (:season_id, :code, :note, :expires_at)
    ');
    $stmt->execute([
        ':season_id' => $seasonId,
        ':code' => $normalized,
        ':note' => trim((string) $note) ?: null,
        ':expires_at' => $expiresAt,
    ]);

    return $normalized;
}

function seasonTicketTypeCode(string $value): string
{
    $code = strtolower(trim($value));
    $code = preg_replace('/[^a-z0-9]+/', '_', $code) ?? '';
    return trim($code, '_');
}

/**
 * @param array<string, mixed> $data
 */
function saveSeasonTicketType(PDO $pdo, ?int $id, array $data): int
{
    ensureSeasonTicketSchema($pdo);
    $params = [
        ':season_id' => (int) $data['season_id'],
        ':name' => trim((string) $data['name']),
        ':code' => seasonTicketTypeCode((string) ($data['code'] !== '' ? $data['code'] : $data['name'])),
        ':price' => (float) $data['price'],
        ':is_active' => !empty($data['is_active']) ? 1 : 0,
        ':sort_order' => max(0, (int) ($data['sort_order'] ?? 0)),
    ];

    if ($id) {
        $params[':id'] = $id;
        $pdo->prepare('UPDATE season_ticket_types SET season_id=:season_id, name=:name, code=:code, price=:price, is_active=:is_active, sort_order=:sort_order WHERE id=:id')->execute($params);
        return $id;
    }

    $pdo->prepare('INSERT INTO season_ticket_types (season_id, name, code, price, is_active, sort_order) VALUES (:season_id, :name, :code, :price, :is_active, :sort_order)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function deleteSeasonTicketType(PDO $pdo, int $id): void
{
    ensureSeasonTicketSchema($pdo);
    $inUse = $pdo->prepare('SELECT COUNT(*) FROM season_ticket_orders WHERE ticket_type_id = :id');
    $inUse->execute([':id' => $id]);
    if ((int) $inUse->fetchColumn() > 0) {
        throw new RuntimeException('This ticket type has orders recorded against it and cannot be deleted. Archive it instead.');
    }
    $pdo->prepare('DELETE FROM season_ticket_types WHERE id = :id')->execute([':id' => $id]);
}

/**
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function getSeasonTicketHolders(PDO $pdo, array $filters = []): array
{
    ensureSeasonTicketSchema($pdo);
    $where = [];
    $params = [];

    if (!empty($filters['search'])) {
        $where[] = '(h.name LIKE :search OR h.email LIKE :search OR h.phone LIKE :search)';
        $params[':search'] = '%' . (string) $filters['search'] . '%';
    }
    if (array_key_exists('marketing_opt_in', $filters)) {
        $where[] = 'h.marketing_opt_in = :opt_in';
        $params[':opt_in'] = (int) $filters['marketing_opt_in'];
    }

    $sql = "SELECT h.*, s.name AS sponsor_name, m.name AS managed_by_name,
            (SELECT COUNT(*) FROM season_ticket_holders d WHERE d.managed_by_holder_id = h.id) AS dependents_count,
            (SELECT COUNT(*) FROM season_ticket_orders o WHERE o.holder_id = h.id) AS orders_count
        FROM season_ticket_holders h
        LEFT JOIN sponsors s ON s.id = h.sponsor_id
        LEFT JOIN season_ticket_holders m ON m.id = h.managed_by_holder_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY h.name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSeasonTicketHolder(PDO $pdo, int $id): ?array
{
    ensureSeasonTicketSchema($pdo);
    $stmt = $pdo->prepare("SELECT h.*, s.name AS sponsor_name, m.name AS managed_by_name
        FROM season_ticket_holders h
        LEFT JOIN sponsors s ON s.id = h.sponsor_id
        LEFT JOIN season_ticket_holders m ON m.id = h.managed_by_holder_id
        WHERE h.id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function findSeasonTicketHolderByEmail(PDO $pdo, string $email): ?array
{
    ensureSeasonTicketSchema($pdo);
    $normalized = seasonTicketNormalizeEmail($email);
    if ($normalized === null) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM season_ticket_holders WHERE email_normalized = :email LIMIT 1');
    $stmt->execute([':email' => $normalized]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * @param array<string, mixed> $data
 */
function saveSeasonTicketHolder(PDO $pdo, ?int $id, array $data): int
{
    ensureSeasonTicketSchema($pdo);
    $managedBy = !empty($data['managed_by_holder_id']) ? (int) $data['managed_by_holder_id'] : null;
    if ($managedBy !== null && $id !== null && $managedBy === $id) {
        throw new InvalidArgumentException('A season ticket holder cannot manage themselves.');
    }

    $addressParts = [
        trim((string) ($data['address_line1'] ?? '')),
        trim((string) ($data['address_line2'] ?? '')),
        trim((string) ($data['town'] ?? '')),
        trim((string) ($data['country'] ?? '')),
        trim((string) ($data['postcode'] ?? '')),
    ];
    $structuredAddress = trim(implode(', ', array_filter($addressParts, static fn(string $part): bool => $part !== '')));

    $email = trim((string) ($data['email'] ?? ''));
    $emailNormalized = seasonTicketNormalizeEmail($email);

    if ($emailNormalized !== null) {
        $duplicate = $pdo->prepare('SELECT id FROM season_ticket_holders WHERE email_normalized = :email AND (:id IS NULL OR id <> :id) LIMIT 1');
        $duplicate->execute([':email' => $emailNormalized, ':id' => $id]);
        if ($duplicate->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('That email address is already in use by another account.');
        }
    }

    $params = [
        ':name' => trim((string) $data['name']),
        ':date_of_birth' => trim((string) ($data['date_of_birth'] ?? '')) ?: null,
        ':email' => $email !== '' ? $email : null,
        ':email_normalized' => $emailNormalized,
        ':phone' => trim((string) ($data['phone'] ?? '')) ?: null,
        ':address' => ($structuredAddress !== '' ? $structuredAddress : trim((string) ($data['address'] ?? ''))) ?: null,
        ':address_line1' => trim((string) ($data['address_line1'] ?? '')) ?: null,
        ':address_line2' => trim((string) ($data['address_line2'] ?? '')) ?: null,
        ':town' => trim((string) ($data['town'] ?? '')) ?: null,
        ':country' => trim((string) ($data['country'] ?? '')) ?: null,
        ':postcode' => trim((string) ($data['postcode'] ?? '')) ?: null,
        ':profile_image_path' => trim((string) ($data['profile_image_path'] ?? '')) ?: null,
        ':sponsor_id' => !empty($data['sponsor_id']) ? (int) $data['sponsor_id'] : null,
        ':managed_by_holder_id' => $managedBy,
        ':marketing_opt_in' => !empty($data['marketing_opt_in']) ? 1 : 0,
        ':notes' => trim((string) ($data['notes'] ?? '')) ?: null,
    ];

    if ($id) {
        $params[':id'] = $id;
        $pdo->prepare('UPDATE season_ticket_holders SET name=:name, date_of_birth=:date_of_birth, email=:email, email_normalized=:email_normalized, phone=:phone, address=:address, address_line1=:address_line1, address_line2=:address_line2, town=:town, country=:country, postcode=:postcode, profile_image_path=:profile_image_path, sponsor_id=:sponsor_id, managed_by_holder_id=:managed_by_holder_id, marketing_opt_in=:marketing_opt_in, notes=:notes WHERE id=:id')->execute($params);
        return $id;
    }

    $params[':unsubscribe_token'] = seasonTicketGenerateUnsubscribeToken();
    $pdo->prepare('INSERT INTO season_ticket_holders (name, date_of_birth, email, email_normalized, phone, address, address_line1, address_line2, town, country, postcode, profile_image_path, sponsor_id, managed_by_holder_id, marketing_opt_in, notes, unsubscribe_token) VALUES (:name, :date_of_birth, :email, :email_normalized, :phone, :address, :address_line1, :address_line2, :town, :country, :postcode, :profile_image_path, :sponsor_id, :managed_by_holder_id, :marketing_opt_in, :notes, :unsubscribe_token)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function deleteSeasonTicketHolder(PDO $pdo, int $id): void
{
    ensureSeasonTicketSchema($pdo);
    $pdo->prepare('UPDATE season_ticket_holders SET is_active = 0 WHERE id = :id')->execute([':id' => $id]);
}

/**
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function getSeasonTicketOrders(PDO $pdo, array $filters = []): array
{
    ensureSeasonTicketSchema($pdo);
    $where = [];
    $params = [];
    foreach (['holder_id', 'season_id', 'ticket_type_id'] as $field) {
        if (!empty($filters[$field])) {
            $where[] = 'o.' . $field . ' = :' . $field;
            $params[':' . $field] = (int) $filters[$field];
        }
    }
    if (!empty($filters['status'])) {
        $where[] = 'o.status = :status';
        $params[':status'] = (string) $filters['status'];
    }

    $sql = "SELECT o.*, h.name AS holder_name, h.email AS holder_email, t.name AS type_name, se.name AS season_name
        FROM season_ticket_orders o
        JOIN season_ticket_holders h ON h.id = o.holder_id
        JOIN season_ticket_types t ON t.id = o.ticket_type_id
        JOIN seasons se ON se.id = o.season_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY o.order_date DESC, o.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getSeasonTicketOrder(PDO $pdo, int $id): ?array
{
    $rows = getSeasonTicketOrders($pdo, []);
    foreach ($rows as $row) {
        if ((int) $row['id'] === $id) {
            return $row;
        }
    }
    return null;
}

/**
 * @param array<string, mixed> $data
 */
function saveSeasonTicketOrder(PDO $pdo, ?int $id, array $data): int
{
    throw new RuntimeException('Legacy season_ticket_orders writes are retired. Use createSeasonPassOrder() and shared orders.');
}

function deleteSeasonTicketOrder(PDO $pdo, int $id): void
{
    throw new RuntimeException('Legacy season_ticket_orders deletes are retired. Cancel the shared season ticket order instead.');
}

function cancelSeasonTicketOrder(PDO $pdo, int $id, string $reason, ?int $userId = null): void
{
    throw new RuntimeException('Legacy season_ticket_orders cancellations are retired. Use cancelSeasonPassOrder().');
}

function attachSeasonTicketOrderStripeSession(PDO $pdo, int $orderId, string $sessionId): void
{
    throw new RuntimeException('Legacy season_ticket_orders Stripe sessions are retired. Use attachSeasonPassOrderStripeSession().');
}

/**
 * @return list<array<string, mixed>>
 */
function getSeasonTicketOrdersByStripeSession(PDO $pdo, string $sessionId): array
{
    ensureSeasonTicketSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM season_ticket_orders WHERE stripe_checkout_session_id = :session_id');
    $stmt->execute([':session_id' => $sessionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function markSeasonTicketOrderPaid(PDO $pdo, int $orderId): void
{
    throw new RuntimeException('Legacy season_ticket_orders payment updates are retired. Use markSeasonPassOrderPaid().');
}

/**
 * The digital ticket for one order — everything members/ticket.php needs to
 * render it plus the QR token a future scanner would look up.
 */
function getSeasonTicketOrderByToken(PDO $pdo, string $token): ?array
{
    ensureSeasonTicketSchema($pdo);
    $stmt = $pdo->prepare("SELECT o.*, h.name AS holder_name, t.name AS type_name, se.name AS season_name
        FROM season_ticket_orders o
        JOIN season_ticket_holders h ON h.id = o.holder_id
        JOIN season_ticket_types t ON t.id = o.ticket_type_id
        JOIN seasons se ON se.id = o.season_id
        WHERE o.ticket_token = :token LIMIT 1");
    $stmt->execute([':token' => $token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Looks up an order by the short manual code shown under the QR code, for
 * gate staff to type in when a phone camera won't scan. Accepts the code
 * with or without its "-" separator and regardless of case.
 */
function getSeasonTicketOrderByManualCode(PDO $pdo, string $code): ?array
{
    ensureSeasonTicketSchema($pdo);
    $normalized = season_ticket_normalize_manual_code($code);
    if ($normalized === '') {
        return null;
    }

    $stmt = $pdo->prepare("SELECT o.*, h.name AS holder_name, t.name AS type_name, se.name AS season_name
        FROM season_ticket_orders o
        JOIN season_ticket_holders h ON h.id = o.holder_id
        JOIN season_ticket_types t ON t.id = o.ticket_type_id
        JOIN seasons se ON se.id = o.season_id
        WHERE o.manual_code = :code LIMIT 1");
    $stmt->execute([':code' => $normalized]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Sponsors of this season's season tickets — modelled the same way as any
 * other club sponsorship (an active "Season Ticket Sponsor" agreement for
 * the season), so who's sponsoring can change season to season from the
 * existing Agreements admin screen without any code change.
 *
 * @return list<array{name: string, logo_path: string}>
 */
function getSeasonTicketSponsors(PDO $pdo, int $seasonId): array
{
    $stmt = $pdo->prepare("SELECT s.name, s.logo_path
        FROM sponsorship_agreements a
        JOIN packages p ON p.id = a.package_id AND p.code = 'season_ticket_sponsor'
        JOIN sponsors s ON s.id = a.sponsor_id AND s.is_active = 1
        WHERE a.status = 'active' AND a.season_id = :season_id AND s.logo_path IS NOT NULL AND s.logo_path != ''
        ORDER BY a.display_order, s.name");
    $stmt->execute([':season_id' => $seasonId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Confirmation email for a completed (or pending-manual-payment) season
 * ticket order — sent right after checkout, matching the mail() approach
 * used elsewhere in the Hub (no SMTP/PHPMailer dependency).
 *
 * @param list<array<string, mixed>> $orders each with holder_name, type_name, price
 */
function seasonTicketSendConfirmationEmail(string $toEmail, string $buyerName, array $orders, bool $isManualPayment): bool
{
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $host = preg_replace('/^www\./', '', preg_replace('/:\d+$/', '', strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk'))));
    $subject = 'Your Saltcoats Victoria FC season ticket order';

    $lineRows = [];
    $total = 0.0;
    foreach ($orders as $order) {
        $price = (float) $order['price'];
        $total += $price;
        $lineRows[] = '<tr>
            <td style="padding:12px 0;border-bottom:1px solid #eadfdf;color:#21141a;font-weight:700;">' . h((string) $order['type_name']) . '</td>
            <td style="padding:12px 0;border-bottom:1px solid #eadfdf;color:#6f6470;">' . h((string) $order['holder_name']) . '</td>
            <td style="padding:12px 0;border-bottom:1px solid #eadfdf;color:#21141a;text-align:right;font-weight:700;">' . h(gbp($price)) . '</td>
        </tr>';
    }

    $accountUrl = 'https://' . $host . '/members/';
    $paymentMessage = $isManualPayment
        ? 'You chose to pay by cash or bank transfer. Your ticket will show as paid once the club confirms payment has been received.'
        : 'Once payment is confirmed, your digital season ticket will be available in your member account.';
    $preheader = 'Your Saltcoats Victoria FC season ticket order has been received.';
    $message = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($subject) . '</title></head>
    <body style="margin:0;padding:0;background:#f6ecde;font-family:Inter,Arial,sans-serif;color:#21141a;">
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . h($preheader) . '</div>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6ecde;padding:28px 12px;">
            <tr><td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 18px 45px rgba(75,8,24,.14);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#4b0818,#7a1730 62%,#a6791d);padding:28px 26px;color:#ffffff;">
                            <div style="font-size:12px;text-transform:uppercase;letter-spacing:.14em;font-weight:900;color:rgba(255,255,255,.74);">Saltcoats Victoria FC</div>
                            <h1 style="margin:8px 0 8px;font-size:30px;line-height:1.1;color:#ffffff;">Your Season Ticket Order</h1>
                            <p style="margin:0;color:rgba(255,255,255,.84);font-size:16px;">Thank you for backing the Vics this season.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:26px;">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.55;">Hi ' . h($buyerName) . ',</p>
                            <p style="margin:0 0 22px;font-size:16px;line-height:1.55;color:#4a4046;">Thanks for your season ticket order. Your order details are below.</p>
                            <div style="margin:0 0 24px;text-align:center;">
                                <a href="' . h($accountUrl) . '" style="display:inline-block;background:#4b0818;color:#ffffff;text-decoration:none;font-weight:900;border-radius:999px;padding:13px 22px;">Open Members Area</a>
                            </div>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 22px;">
                                <tr>
                                    <th align="left" style="padding:0 0 8px;color:#6f6470;font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Ticket</th>
                                    <th align="left" style="padding:0 0 8px;color:#6f6470;font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Holder</th>
                                    <th align="right" style="padding:0 0 8px;color:#6f6470;font-size:12px;text-transform:uppercase;letter-spacing:.08em;">Price</th>
                                </tr>
                                ' . implode('', $lineRows) . '
                                <tr>
                                    <td colspan="2" style="padding:14px 0 0;color:#21141a;font-size:18px;font-weight:900;">Order total</td>
                                    <td style="padding:14px 0 0;color:#4b0818;font-size:18px;font-weight:900;text-align:right;">' . h(gbp($total)) . '</td>
                                </tr>
                            </table>
                            <div style="margin-top:20px;padding:16px;border-radius:14px;background:#faf5ed;color:#4a4046;font-size:14px;line-height:1.5;">
                                ' . h($paymentMessage) . '
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
                    Trouble opening the button? Use this link:<br><a href="' . h($accountUrl) . '" style="color:#4b0818;">' . h($accountUrl) . '</a>
                </div>
            </td></tr>
        </table>
    </body></html>';

    return hub_send_mail($toEmail, $subject, $message, true);
}

/**
 * Reuses an existing dependent of this holder with the same name where
 * possible (repeat checkouts for the same family shouldn't create a fresh
 * duplicate holder every visit), otherwise creates one.
 */
function findOrCreateDependentHolder(PDO $pdo, int $managerHolderId, string $name): int
{
    ensureSeasonTicketSchema($pdo);
    require_once __DIR__ . '/people.php';

    $managerPersonId = personIdFromLegacyHolderId($pdo, $managerHolderId);
    if ($managerPersonId !== null) {
        $stmt = $pdo->prepare("SELECT p.id, m.old_holder_id
            FROM person_relationships pr
            JOIN people p ON p.id = pr.dependent_person_id
            LEFT JOIN identity_migration_map m ON m.person_id = p.id
            WHERE pr.manager_person_id = :manager AND p.display_name = :name
            LIMIT 1");
        $stmt->execute([':manager' => $managerPersonId, ':name' => $name]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            return (int) ($existing['old_holder_id'] ?: ensureLegacyHolderForPerson($pdo, (int) $existing['id']));
        }

        $dependentPersonId = createPerson($pdo, [
            'display_name' => $name,
            'marketing_opt_in' => 0,
            'is_active' => 1,
        ]);
        addPersonRelationship($pdo, $managerPersonId, $dependentPersonId);
        return ensureLegacyHolderForPerson($pdo, $dependentPersonId);
    }

    // Last-resort adapter for old, unmapped ticketing flows.
    return ensureLegacyHolderForPerson($pdo, createPerson($pdo, [
        'name' => $name,
        'marketing_opt_in' => 0,
        'is_active' => 1,
    ]), ['managed_by_holder_id' => $managerHolderId]);
}
