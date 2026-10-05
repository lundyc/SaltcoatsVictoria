<?php
declare(strict_types=1);

require_once __DIR__ . '/season_tickets.php';
require_once __DIR__ . '/season_passes.php';
require_once __DIR__ . '/admissions.php';

function ensureSeasonTicketAttendanceSchema(PDO $pdo): void
{
    ensureSeasonTicketSchema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS season_ticket_attendance (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        fixture_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NOT NULL,
        holder_id INT UNSIGNED NOT NULL,
        scanned_by_user_id INT UNSIGNED NULL,
        scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        scan_source VARCHAR(30) NOT NULL DEFAULT 'matchday_qr',
        PRIMARY KEY (id),
        UNIQUE KEY uq_season_ticket_attendance_fixture_order (fixture_id, order_id),
        KEY idx_season_ticket_attendance_fixture (fixture_id, scanned_at),
        KEY idx_season_ticket_attendance_holder (holder_id),
        CONSTRAINT fk_season_ticket_attendance_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE,
        CONSTRAINT fk_season_ticket_attendance_order FOREIGN KEY (order_id) REFERENCES season_ticket_orders(id) ON DELETE CASCADE,
        CONSTRAINT fk_season_ticket_attendance_holder FOREIGN KEY (holder_id) REFERENCES season_ticket_holders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $attendanceColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM season_ticket_attendance') as $row) {
        $attendanceColumns[(string) $row['Field']] = (string) $row['Null'];
    }
    if (!isset($attendanceColumns['season_pass_id'])) {
        $pdo->exec('ALTER TABLE season_ticket_attendance ADD COLUMN season_pass_id INT UNSIGNED NULL AFTER order_id, ADD KEY idx_season_ticket_attendance_pass (season_pass_id)');
    }
    if (($attendanceColumns['order_id'] ?? 'NO') === 'NO') {
        $pdo->exec('ALTER TABLE season_ticket_attendance MODIFY order_id INT UNSIGNED NULL');
    }
    $attendanceIndexes = [];
    foreach ($pdo->query('SHOW INDEX FROM season_ticket_attendance') as $row) {
        $attendanceIndexes[(string) $row['Key_name']] = true;
    }
    if (!isset($attendanceIndexes['uq_season_ticket_attendance_fixture_pass'])) {
        $pdo->exec('ALTER TABLE season_ticket_attendance ADD UNIQUE KEY uq_season_ticket_attendance_fixture_pass (fixture_id, season_pass_id)');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS season_ticket_scan_logs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        fixture_id INT UNSIGNED NOT NULL,
        order_id INT UNSIGNED NULL,
        holder_id INT UNSIGNED NULL,
        scanned_by_user_id INT UNSIGNED NULL,
        ticket_kind VARCHAR(40) NOT NULL DEFAULT 'season_ticket',
        ticket_label VARCHAR(120) NULL,
        holder_name VARCHAR(190) NULL,
        status VARCHAR(40) NOT NULL,
        message VARCHAR(255) NOT NULL,
        accepted TINYINT(1) NOT NULL DEFAULT 0,
        scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_season_ticket_scan_logs_fixture (fixture_id, scanned_at),
        KEY idx_season_ticket_scan_logs_order (order_id),
        CONSTRAINT fk_season_ticket_scan_logs_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE,
        CONSTRAINT fk_season_ticket_scan_logs_order FOREIGN KEY (order_id) REFERENCES season_ticket_orders(id) ON DELETE SET NULL,
        CONSTRAINT fk_season_ticket_scan_logs_holder FOREIGN KEY (holder_id) REFERENCES season_ticket_holders(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $logColumns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM season_ticket_scan_logs') as $row) {
        $logColumns[(string) $row['Field']] = true;
    }
    if (!isset($logColumns['season_pass_id'])) {
        $pdo->exec('ALTER TABLE season_ticket_scan_logs ADD COLUMN season_pass_id INT UNSIGNED NULL AFTER order_id, ADD KEY idx_season_ticket_scan_logs_pass (season_pass_id)');
    }
}

function season_ticket_extract_token(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $input) === 1) {
        $query = (string) parse_url($input, PHP_URL_QUERY);
        parse_str($query, $params);
        $token = is_string($params['token'] ?? null) ? trim($params['token']) : '';
        if ($token !== '') {
            return $token;
        }
    }
    if (preg_match('/token=([a-f0-9]{20,80})/i', $input, $matches)) {
        return $matches[1];
    }
    return preg_match('/^[a-f0-9]{20,80}$/i', $input) === 1 ? $input : '';
}

function season_ticket_scan_log(PDO $pdo, int $fixtureId, ?array $order, string $status, string $message, bool $accepted, ?int $scannedByUserId): array
{
    ensureSeasonTicketAttendanceSchema($pdo);
    $row = admissionsLog($pdo, $fixtureId, null, $status, $message, $accepted, $scannedByUserId, [
        'legacy_table' => 'season_ticket_scan_logs',
        'order_id' => is_array($order) ? (int) ($order['id'] ?? 0) : null,
        'season_pass_id' => is_array($order) ? (int) ($order['season_pass_id'] ?? $order['pass_id'] ?? 0) : null,
    ]);
    $row['status'] = (string) ($row['result'] ?? $status);
    return $row;
}

/**
 * @return array{valid: bool, status: string, message: string, order?: array<string,mixed>, attendance?: array<string,mixed>}
 */
function season_ticket_check_and_mark_attendance(PDO $pdo, int $fixtureId, string $scanInput, ?int $scannedByUserId = null, bool $allowFixtureStatusOverride = false): array
{
    ensureSeasonTicketAttendanceSchema($pdo);
    if ($fixtureId <= 0) {
        return ['valid' => false, 'status' => 'invalid_fixture', 'message' => 'Fixture not found.'];
    }
    $fixtureStmt = $pdo->prepare('SELECT id, season_id, opponent, match_date, is_home, status FROM match_fixtures WHERE id = :id LIMIT 1');
    $fixtureStmt->execute([':id' => $fixtureId]);
    $fixture = $fixtureStmt->fetch(PDO::FETCH_ASSOC);
    if (!$fixture) {
        return ['valid' => false, 'status' => 'invalid_fixture', 'message' => 'Fixture not found.'];
    }
    if (!$allowFixtureStatusOverride && in_array((string) ($fixture['status'] ?? ''), ['postponed', 'cancelled'], true)) {
        return ['valid' => false, 'status' => 'fixture_not_admitting', 'message' => 'Tickets cannot be admitted for a postponed or cancelled fixture.'];
    }
    if ((int) ($fixture['is_home'] ?? 0) !== 1) {
        return ['valid' => false, 'status' => 'away_fixture', 'message' => 'Season ticket scanning is only available for home fixtures.'];
    }

    $token = season_ticket_extract_token($scanInput);
    $manualCode = season_ticket_normalize_manual_code($scanInput);
    if ($token === '' && $manualCode === '') {
        $message = 'That QR code or code is not a valid season ticket.';
        $log = season_ticket_scan_log($pdo, $fixtureId, null, 'invalid_token', $message, false, $scannedByUserId);
        return ['valid' => false, 'status' => 'invalid_token', 'message' => $message, 'scan_log' => $log];
    }

    $pass = getSeasonPassByCredential($pdo, $token !== '' ? $token : $manualCode);
    if (!$pass) {
        $message = 'Season ticket not found.';
        $log = season_ticket_scan_log($pdo, $fixtureId, null, 'not_found', $message, false, $scannedByUserId);
        return ['valid' => false, 'status' => 'not_found', 'message' => $message, 'scan_log' => $log];
    }
    $order = seasonPassLegacyOrderShape($pass);
    $order['season_pass_id'] = (int) $pass['id'];
    $order['pass_id'] = (int) $pass['id'];

    if (!seasonPassAllowsFixture($pdo, (int) $pass['id'], $fixtureId)) {
        $message = 'This season ticket is not valid for this fixture season.';
        $log = season_ticket_scan_log($pdo, $fixtureId, $order, 'wrong_season', $message, false, $scannedByUserId);
        return ['valid' => false, 'status' => 'wrong_season', 'message' => $message, 'order' => $order, 'scan_log' => $log];
    }
    if ((int) ($pass['credential_is_active'] ?? 0) !== 1 || (string) ($pass['entitlement_status'] ?? '') !== 'active' || (string) ($pass['order_status'] ?? '') !== 'paid') {
        $message = 'This season ticket is not active or paid.';
        $log = season_ticket_scan_log($pdo, $fixtureId, $order, 'not_active', $message, false, $scannedByUserId);
        return ['valid' => false, 'status' => 'not_active', 'message' => $message, 'order' => $order, 'scan_log' => $log];
    }

    $admission = recordAdmission($pdo, $fixtureId, $scanInput, $scannedByUserId, 'matchday_qr', $allowFixtureStatusOverride);
    $accepted = (string) ($admission['status'] ?? '') === 'checked_in';

    $attendance = $accepted ? ['fixture_id' => $fixtureId, 'season_pass_id' => (int) $pass['id'], 'source' => 'admissions'] : [];
    $alreadyScanned = !$accepted;
    $status = $alreadyScanned ? 'already_scanned' : 'checked_in';
    $message = $alreadyScanned ? 'Valid ticket. Attendance was already marked.' : 'Valid ticket. Attendance marked.';
    $log = $admission['scan_log'] ?? season_ticket_scan_log($pdo, $fixtureId, $order, $status, $message, !$alreadyScanned, $scannedByUserId);
    if ($log && isset($log['result']) && !isset($log['status'])) {
        $log['status'] = $log['result'];
    }

    return [
        'valid' => true,
        'status' => $status,
        'message' => $message,
        'order' => $order,
        'attendance' => $attendance,
        'scan_log' => $log,
    ];
}

function season_ticket_attendance_count(PDO $pdo, int $fixtureId): int
{
    ensureSeasonTicketAttendanceSchema($pdo);
    return admissionsCount($pdo, $fixtureId);
}

function season_ticket_recent_attendance(PDO $pdo, int $fixtureId, int $limit = 20): array
{
    ensureSeasonTicketAttendanceSchema($pdo);
    $stmt = $pdo->prepare('SELECT a.*, h.name AS holder_name, t.name AS type_name, o.id AS order_id
        FROM season_ticket_attendance a
        JOIN season_ticket_holders h ON h.id = a.holder_id
        JOIN season_ticket_orders o ON o.id = a.order_id
        JOIN season_ticket_types t ON t.id = o.ticket_type_id
        WHERE a.fixture_id = :fixture
        ORDER BY a.scanned_at DESC, a.id DESC
        LIMIT ' . max(1, min(100, $limit)));
    $stmt->execute([':fixture' => $fixtureId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function season_ticket_recent_scan_logs(PDO $pdo, int $fixtureId, int $limit = 20): array
{
    ensureSeasonTicketAttendanceSchema($pdo);
    return admissionsRecentScanLogs($pdo, $fixtureId, $limit);
}

/**
 * @return array{total:int, valid:int, invalid:int}
 */
function season_ticket_scan_log_summary(PDO $pdo, int $fixtureId): array
{
    ensureSeasonTicketAttendanceSchema($pdo);
    return admissionsScanLogSummary($pdo, $fixtureId);
}
