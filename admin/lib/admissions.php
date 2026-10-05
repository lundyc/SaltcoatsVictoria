<?php
declare(strict_types=1);

require_once __DIR__ . '/season_passes.php';

function ensureAdmissionsSchema(PDO $pdo): void
{
    ensureSeasonPassSchema($pdo);

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM entitlements') as $row) {
        $columns[(string) $row['Field']] = (string) $row['Null'];
    }
    if (($columns['person_id'] ?? 'NO') === 'NO') {
        $pdo->exec('ALTER TABLE entitlements MODIFY person_id INT UNSIGNED NULL');
    }

    $matchTicketsExists = $pdo->query("SHOW TABLES LIKE 'match_tickets'")->fetchColumn();
    if ($matchTicketsExists) {
        $ticketColumns = [];
        foreach ($pdo->query('SHOW COLUMNS FROM match_tickets') as $row) {
            $ticketColumns[(string) $row['Field']] = true;
        }
        if (!isset($ticketColumns['entitlement_id'])) {
            $pdo->exec('ALTER TABLE match_tickets ADD COLUMN entitlement_id INT UNSIGNED NULL AFTER id, ADD UNIQUE KEY uq_match_tickets_entitlement (entitlement_id), ADD KEY idx_match_tickets_entitlement (entitlement_id)');
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS match_ticket_migration_map (
        legacy_order_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        legacy_order_item_id INT UNSIGNED NOT NULL,
        order_item_id INT UNSIGNED NOT NULL,
        legacy_ticket_id INT UNSIGNED NOT NULL,
        entitlement_id INT UNSIGNED NOT NULL,
        match_ticket_id INT UNSIGNED NOT NULL,
        credential_id INT UNSIGNED NOT NULL,
        migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (legacy_ticket_id),
        KEY idx_match_ticket_map_legacy_order (legacy_order_id),
        KEY idx_match_ticket_map_order (order_id),
        UNIQUE KEY uq_match_ticket_map_entitlement (entitlement_id),
        UNIQUE KEY uq_match_ticket_map_credential (credential_id),
        CONSTRAINT fk_match_ticket_map_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_match_ticket_map_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
        CONSTRAINT fk_match_ticket_map_entitlement FOREIGN KEY (entitlement_id) REFERENCES entitlements(id) ON DELETE CASCADE,
        CONSTRAINT fk_match_ticket_map_credential FOREIGN KEY (credential_id) REFERENCES ticket_credentials(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS admissions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        fixture_id INT UNSIGNED NOT NULL,
        entitlement_id INT UNSIGNED NULL,
        person_id INT UNSIGNED NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        source VARCHAR(40) NOT NULL DEFAULT 'matchday_qr',
        category VARCHAR(80) NULL,
        reason VARCHAR(255) NULL,
        external_source VARCHAR(40) NULL,
        external_reference VARCHAR(120) NULL,
        unit_amount DECIMAL(10,2) NULL,
        admitted_by_account_id INT UNSIGNED NULL,
        admitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        legacy_source VARCHAR(80) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_admissions_fixture_entitlement (fixture_id, entitlement_id),
        KEY idx_admissions_fixture (fixture_id, admitted_at),
        KEY idx_admissions_person (person_id),
        CONSTRAINT fk_admissions_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE,
        CONSTRAINT fk_admissions_entitlement FOREIGN KEY (entitlement_id) REFERENCES entitlements(id) ON DELETE CASCADE,
        CONSTRAINT fk_admissions_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL,
        CONSTRAINT fk_admissions_account FOREIGN KEY (admitted_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $admissionColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM admissions') as $row) {
        $admissionColumns[(string) $row['Field']] = (string) $row['Null'];
    }
    if (($admissionColumns['entitlement_id'] ?? 'NO') === 'NO') {
        $pdo->exec('ALTER TABLE admissions MODIFY entitlement_id INT UNSIGNED NULL');
    }
    $admissionColumnDefs = [
        'category' => 'VARCHAR(80) NULL AFTER source',
        'reason' => 'VARCHAR(255) NULL AFTER category',
        'external_source' => 'VARCHAR(40) NULL AFTER reason',
        'external_reference' => 'VARCHAR(120) NULL AFTER external_source',
        'unit_amount' => 'DECIMAL(10,2) NULL AFTER external_reference',
    ];
    foreach ($admissionColumnDefs as $column => $definition) {
        if (!isset($admissionColumns[$column])) {
            $pdo->exec("ALTER TABLE admissions ADD COLUMN {$column} {$definition}");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS scan_logs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        fixture_id INT UNSIGNED NOT NULL,
        credential_id INT UNSIGNED NULL,
        entitlement_id INT UNSIGNED NULL,
        scanner_account_id INT UNSIGNED NULL,
        ticket_kind VARCHAR(60) NOT NULL DEFAULT 'ticket',
        ticket_label VARCHAR(120) NULL,
        holder_name VARCHAR(190) NULL,
        result VARCHAR(40) NOT NULL,
        message VARCHAR(255) NOT NULL,
        accepted TINYINT(1) NOT NULL DEFAULT 0,
        scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        metadata_json JSON NULL,
        legacy_source VARCHAR(80) NULL,
        PRIMARY KEY (id),
        KEY idx_scan_logs_fixture (fixture_id, scanned_at),
        KEY idx_scan_logs_credential (credential_id),
        KEY idx_scan_logs_entitlement (entitlement_id),
        UNIQUE KEY uq_scan_logs_legacy_source (legacy_source),
        CONSTRAINT fk_scan_logs_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE,
        CONSTRAINT fk_scan_logs_credential FOREIGN KEY (credential_id) REFERENCES ticket_credentials(id) ON DELETE SET NULL,
        CONSTRAINT fk_scan_logs_entitlement FOREIGN KEY (entitlement_id) REFERENCES entitlements(id) ON DELETE SET NULL,
        CONSTRAINT fk_scan_logs_account FOREIGN KEY (scanner_account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $scanLogColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM scan_logs') as $row) {
        $scanLogColumns[(string) $row['Field']] = true;
    }
    if (!isset($scanLogColumns['legacy_source'])) {
        $pdo->exec('ALTER TABLE scan_logs ADD COLUMN legacy_source VARCHAR(80) NULL AFTER metadata_json, ADD UNIQUE KEY uq_scan_logs_legacy_source (legacy_source)');
    }
}

function admissionsCredentialToken(string $scanInput): string
{
    $scanInput = trim($scanInput);
    if ($scanInput === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $scanInput) === 1) {
        $query = (string) parse_url($scanInput, PHP_URL_QUERY);
        parse_str($query, $params);
        foreach (['ticket', 'token'] as $key) {
            $token = is_string($params[$key] ?? null) ? trim($params[$key]) : '';
            if ($token !== '' && preg_match('/^[a-f0-9]{20,80}$/i', $token) === 1) {
                return $token;
            }
        }
    }
    if (preg_match('/(?:ticket|token)=([a-f0-9]{20,80})/i', $scanInput, $matches) === 1) {
        return $matches[1];
    }
    return preg_match('/^[a-f0-9]{20,80}$/i', $scanInput) === 1 ? $scanInput : '';
}

function admissionsNormalizeManualCode(string $scanInput): string
{
    return strtoupper(trim(preg_replace('/[^A-Z0-9]/i', '', $scanInput) ?? ''));
}

function admissionsCredential(PDO $pdo, string $scanInput): ?array
{
    ensureAdmissionsSchema($pdo);
    $token = admissionsCredentialToken($scanInput);
    $manualCode = admissionsNormalizeManualCode($scanInput);
    if ($token === '' && $manualCode === '') {
        return null;
    }
    $where = $token !== '' ? 'c.token = :value' : "REPLACE(c.manual_code, '-', '') = :value";
    $value = $token !== '' ? $token : $manualCode;
    $stmt = $pdo->prepare("SELECT c.id AS credential_id, c.token, c.manual_code, c.is_active AS credential_is_active, c.revoked_at,
            e.id AS entitlement_id, e.person_id, e.type AS entitlement_type, e.status AS entitlement_status,
            oi.id AS order_item_id, oi.product_reference_id, oi.description_snapshot, oi.quantity AS item_quantity, oi.unit_price, oi.line_total, oi.metadata_json,
            o.id AS order_id, o.customer_name, o.customer_email, o.status AS order_status, o.total_amount, o.confirmation_email_sent_at,
            sp.id AS season_pass_id, sp.season_id, sp.status AS season_pass_status,
            mt.id AS match_ticket_id, mt.fixture_id AS ticket_fixture_id, mt.ticket_label, mt.admits_count, mt.checked_in_at,
            m.legacy_order_id AS legacy_match_order_id
        FROM ticket_credentials c
        JOIN entitlements e ON e.id = c.entitlement_id
        LEFT JOIN order_items oi ON oi.id = e.order_item_id
        LEFT JOIN orders o ON o.id = oi.order_id
        LEFT JOIN season_passes sp ON sp.entitlement_id = e.id
        LEFT JOIN match_tickets mt ON mt.entitlement_id = e.id
        LEFT JOIN match_ticket_migration_map m ON m.entitlement_id = e.id
        WHERE {$where}
        LIMIT 1");
    $stmt->execute([':value' => $value]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function admissionsLog(PDO $pdo, int $fixtureId, ?array $credential, string $result, string $message, bool $accepted, ?int $scannerAccountId, array $metadata = []): array
{
    ensureAdmissionsSchema($pdo);
    $stmt = $pdo->prepare('INSERT INTO scan_logs
        (fixture_id, credential_id, entitlement_id, scanner_account_id, ticket_kind, ticket_label, holder_name, result, message, accepted, metadata_json)
        VALUES (:fixture, :credential_id, :entitlement_id, :scanner, :kind, :label, :holder, :result, :message, :accepted, :metadata)');
    $stmt->execute([
        ':fixture' => $fixtureId,
        ':credential_id' => is_array($credential) ? ((int) ($credential['credential_id'] ?? 0) ?: null) : null,
        ':entitlement_id' => is_array($credential) ? ((int) ($credential['entitlement_id'] ?? 0) ?: null) : null,
        ':scanner' => $scannerAccountId,
        ':kind' => is_array($credential) ? ((string) ($credential['entitlement_type'] ?? 'ticket')) : 'ticket',
        ':label' => is_array($credential) ? ((string) (($credential['ticket_label'] ?? '') ?: ($credential['description_snapshot'] ?? ''))) : null,
        ':holder' => is_array($credential) ? ((string) ($credential['customer_name'] ?? '')) : null,
        ':result' => $result,
        ':message' => $message,
        ':accepted' => $accepted ? 1 : 0,
        ':metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
    ]);
    $fetch = $pdo->prepare('SELECT * FROM scan_logs WHERE id = :id LIMIT 1');
    $fetch->execute([':id' => $pdo->lastInsertId()]);
    return $fetch->fetch(PDO::FETCH_ASSOC) ?: [];
}

function recordAdmission(PDO $pdo, int $fixtureId, string $scanInput, ?int $scannerAccountId = null, string $source = 'matchday_qr', bool $allowFixtureStatusOverride = false): array
{
    ensureAdmissionsSchema($pdo);
    if ($fixtureId <= 0) {
        return ['found' => true, 'valid' => false, 'status' => 'invalid_fixture', 'message' => 'Fixture not found.'];
    }
    $fixtureStmt = $pdo->prepare('SELECT id, season_id, is_home, status FROM match_fixtures WHERE id = :id LIMIT 1');
    $fixtureStmt->execute([':id' => $fixtureId]);
    $fixture = $fixtureStmt->fetch(PDO::FETCH_ASSOC);
    if (!$fixture) {
        return ['found' => true, 'valid' => false, 'status' => 'invalid_fixture', 'message' => 'Fixture not found.'];
    }
    if (!$allowFixtureStatusOverride && in_array((string) ($fixture['status'] ?? ''), ['postponed', 'cancelled'], true)) {
        $log = admissionsLog($pdo, $fixtureId, null, 'fixture_not_admitting', 'Tickets cannot be admitted for a postponed or cancelled fixture.', false, $scannerAccountId);
        return ['found' => true, 'valid' => false, 'status' => 'fixture_not_admitting', 'message' => 'Tickets cannot be admitted for a postponed or cancelled fixture.', 'scan_log' => $log];
    }

    $credential = admissionsCredential($pdo, $scanInput);
    if (!$credential) {
        return ['found' => false];
    }
    $type = (string) ($credential['entitlement_type'] ?? '');
    if ($type === 'season_pass') {
        $decision = seasonPassRuleDecision($pdo, (int) ($credential['product_reference_id'] ?? 0), $fixtureId, (int) ($credential['season_id'] ?? 0));
        if (empty($decision['allowed'])) {
            $status = (string) ($decision['status'] ?? 'season_pass_not_valid');
            if ($status === 'included_rule' || $status === 'included_override') {
                $status = 'season_pass_not_valid';
            }
            $message = (string) ($decision['reason'] ?? 'This season ticket is not valid for this fixture.');
            $log = admissionsLog($pdo, $fixtureId, $credential, $status, $message, false, $scannerAccountId);
            return ['found' => true, 'valid' => false, 'status' => $status, 'message' => $message, 'credential' => $credential, 'scan_log' => $log];
        }
    }
    if ($type === 'match_ticket' && (int) ($credential['ticket_fixture_id'] ?? 0) !== $fixtureId) {
        $log = admissionsLog($pdo, $fixtureId, $credential, 'wrong_fixture', 'This match ticket is for another fixture.', false, $scannerAccountId);
        return ['found' => true, 'valid' => false, 'status' => 'wrong_fixture', 'message' => 'This match ticket is for another fixture.', 'credential' => $credential, 'scan_log' => $log];
    }
    if ((int) ($credential['credential_is_active'] ?? 0) !== 1 || (string) ($credential['entitlement_status'] ?? '') !== 'active' || (string) ($credential['order_status'] ?? '') !== 'paid') {
        $log = admissionsLog($pdo, $fixtureId, $credential, 'not_active', 'This ticket is not active or paid.', false, $scannerAccountId);
        return ['found' => true, 'valid' => false, 'status' => 'not_active', 'message' => 'This ticket is not active or paid.', 'credential' => $credential, 'scan_log' => $log];
    }

    $quantity = $type === 'match_ticket' ? max(1, (int) ($credential['admits_count'] ?? 1)) : 1;
    $insert = $pdo->prepare('INSERT IGNORE INTO admissions
        (fixture_id, entitlement_id, person_id, quantity, source, admitted_by_account_id)
        VALUES (:fixture, :entitlement, :person, :quantity, :source, :scanner)');
    $insert->execute([
        ':fixture' => $fixtureId,
        ':entitlement' => (int) $credential['entitlement_id'],
        ':person' => (int) ($credential['person_id'] ?? 0) ?: null,
        ':quantity' => $quantity,
        ':source' => $source,
        ':scanner' => $scannerAccountId,
    ]);
    $accepted = $insert->rowCount() === 1;
    $status = $accepted ? 'checked_in' : 'already_scanned';
    $message = $accepted ? 'Ticket checked in.' : 'This ticket has already been scanned.';
    $log = admissionsLog($pdo, $fixtureId, $credential, $status, $message, $accepted, $scannerAccountId, ['source' => $source]);

    return ['found' => true, 'valid' => true, 'status' => $status, 'message' => $message, 'credential' => $credential, 'scan_log' => $log];
}

function recordDirectAdmission(PDO $pdo, int $fixtureId, int $quantity, string $source, ?int $accountId = null, array $metadata = []): array
{
    ensureAdmissionsSchema($pdo);
    if ($fixtureId <= 0) {
        throw new RuntimeException('Fixture not found.');
    }
    $fixtureStmt = $pdo->prepare('SELECT id, status FROM match_fixtures WHERE id = :id LIMIT 1');
    $fixtureStmt->execute([':id' => $fixtureId]);
    $fixture = $fixtureStmt->fetch(PDO::FETCH_ASSOC);
    if (!$fixture) {
        throw new RuntimeException('Fixture not found.');
    }
    if (in_array((string) ($fixture['status'] ?? ''), ['postponed', 'cancelled'], true)) {
        throw new RuntimeException('Admissions cannot be recorded for a postponed or cancelled fixture.');
    }
    $quantity = max(1, min(999, $quantity));
    $stmt = $pdo->prepare('INSERT INTO admissions
        (fixture_id, entitlement_id, person_id, quantity, source, category, reason, external_source, external_reference, unit_amount, admitted_by_account_id)
        VALUES (:fixture, NULL, :person_id, :quantity, :source, :category, :reason, :external_source, :external_reference, :unit_amount, :account_id)');
    $stmt->execute([
        ':fixture' => $fixtureId,
        ':person_id' => (int) ($metadata['person_id'] ?? 0) ?: null,
        ':quantity' => $quantity,
        ':source' => $source,
        ':category' => isset($metadata['category']) ? (string) $metadata['category'] : null,
        ':reason' => isset($metadata['reason']) ? (string) $metadata['reason'] : null,
        ':external_source' => isset($metadata['external_source']) ? (string) $metadata['external_source'] : null,
        ':external_reference' => isset($metadata['external_reference']) ? (string) $metadata['external_reference'] : null,
        ':unit_amount' => isset($metadata['unit_amount']) ? (float) $metadata['unit_amount'] : null,
        ':account_id' => $accountId,
    ]);
    $admissionId = (int) $pdo->lastInsertId();
    admissionsLog($pdo, $fixtureId, null, 'checked_in', 'Admission recorded.', true, $accountId, [
        'admission_id' => $admissionId,
        'quantity' => $quantity,
        'source' => $source,
        'metadata' => $metadata,
    ]);
    return ['admission_id' => $admissionId, 'quantity' => $quantity, 'source' => $source];
}

function admissionsCount(PDO $pdo, int $fixtureId): int
{
    ensureAdmissionsSchema($pdo);
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity),0) FROM admissions WHERE fixture_id = :fixture');
    $stmt->execute([':fixture' => $fixtureId]);
    return (int) $stmt->fetchColumn();
}

function admissionsScanLogSummary(PDO $pdo, int $fixtureId): array
{
    ensureAdmissionsSchema($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(accepted = 1),0) AS valid, COALESCE(SUM(accepted = 0),0) AS invalid FROM scan_logs WHERE fixture_id = :fixture');
    $stmt->execute([':fixture' => $fixtureId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'valid' => 0, 'invalid' => 0];
    return ['total' => (int) $row['total'], 'valid' => (int) $row['valid'], 'invalid' => (int) $row['invalid']];
}

function admissionsRecentScanLogs(PDO $pdo, int $fixtureId, int $limit = 20): array
{
    ensureAdmissionsSchema($pdo);
    $stmt = $pdo->prepare('SELECT result AS status, message, accepted, ticket_kind, ticket_label, holder_name, scanned_at FROM scan_logs WHERE fixture_id = :fixture ORDER BY scanned_at DESC, id DESC LIMIT ' . max(1, min(100, $limit)));
    $stmt->execute([':fixture' => $fixtureId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
