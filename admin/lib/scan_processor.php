<?php
declare(strict_types=1);

/**
 * The gate scanner's rules in one place. Live scans, scans queued while a phone had no signal (offline sync) and
 * the door dashboard all use this, so a ticket is judged the same way however the scan arrives.
 *
 *  - scan_process_ticket(): judge one scan for a match or an event and record it.
 *  - scan_gate_list(): what a phone needs to keep scanning with no signal (hashed, not readable tickets).
 *  - gate activity / heartbeat tables feed the multi-gate door dashboard.
 */

require_once __DIR__ . '/season_ticket_attendance.php';
require_once __DIR__ . '/match_tickets.php';
require_once __DIR__ . '/scan_problems.php';
require_once __DIR__ . '/events_scan.php';

/** Scans older than this are not accepted from an offline queue. */
const SCAN_OFFLINE_MAX_AGE_SECONDS = 172800; // 48 hours
/** Iterations for hashing manual codes (they are short, so they are stretched). Must match scan/offline.js. */
const SCAN_GATE_PBKDF2_ITERATIONS = 5000;

function ensureScanGateSchema(PDO $pdo): void
{
    ensureAdmissionsSchema($pdo);
    ensureEventScanSchema($pdo);
    if (schema_guard_done('scan_gate')) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS scan_gate_activity (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        target_kind VARCHAR(8) NOT NULL,
        target_id INT UNSIGNED NOT NULL,
        gate VARCHAR(40) NOT NULL DEFAULT '',
        device_id VARCHAR(40) NOT NULL DEFAULT '',
        account_id INT UNSIGNED NULL,
        status VARCHAR(30) NOT NULL,
        accepted TINYINT(1) NOT NULL DEFAULT 0,
        holder_name VARCHAR(190) NULL,
        ticket_label VARCHAR(120) NULL,
        people TINYINT UNSIGNED NOT NULL DEFAULT 0,
        offline TINYINT(1) NOT NULL DEFAULT 0,
        scanned_at DATETIME NOT NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_scan_gate_target (target_kind, target_id, scanned_at),
        KEY idx_scan_gate_device (device_id, scanned_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS scan_offline_syncs (
        client_id VARCHAR(48) NOT NULL,
        target_kind VARCHAR(8) NOT NULL,
        target_id INT UNSIGNED NOT NULL,
        device_id VARCHAR(40) NOT NULL DEFAULT '',
        status VARCHAR(30) NOT NULL,
        accepted TINYINT(1) NOT NULL DEFAULT 0,
        message VARCHAR(255) NOT NULL DEFAULT '',
        processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (client_id),
        KEY idx_scan_offline_target (target_kind, target_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS scan_gate_heartbeats (
        device_id VARCHAR(40) NOT NULL,
        gate VARCHAR(40) NOT NULL DEFAULT '',
        account_id INT UNSIGNED NULL,
        target_kind VARCHAR(8) NOT NULL DEFAULT '',
        target_id INT UNSIGNED NOT NULL DEFAULT 0,
        queued INT UNSIGNED NOT NULL DEFAULT 0,
        online TINYINT(1) NOT NULL DEFAULT 1,
        last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (device_id),
        KEY idx_scan_heartbeat_target (target_kind, target_id, last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    schema_guard_mark('scan_gate');
}

/** Keep names and ids tidy and short. */
function scan_clean_label(string $value, int $max): string
{
    return mb_substr(trim(preg_replace('/[^\p{L}\p{N} ._\-\/#]/u', '', $value) ?? ''), 0, $max);
}

/**
 * Judge one scan and record it.
 *
 * @param array{kind:string,id:int,value:string,scanned_at?:?int,gate?:string,device?:string,offline?:bool} $in
 *        kind is 'match' or 'event'. scanned_at is a unix time for scans that were made earlier (offline).
 * @return array{http:int,payload:array<string,mixed>}
 */
/**
 * Fingerprint used to match a scanned value to a test-sheet card: letters and digits only, upper case,
 * so "4gv-629a", "4GV629A" and a full link containing the same token all give the same fingerprint.
 */
function scan_card_fingerprint(string $value): string
{
    return sha1(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value)));
}

/** Columns of scan_card_log, added to an existing table when they are missing. */
function scan_card_log_columns(): array
{
    return [
        'value_length' => 'SMALLINT UNSIGNED NULL',
        'value_kind' => 'VARCHAR(12) NULL',
        'value_hint' => 'VARCHAR(20) NULL',
        'path' => 'VARCHAR(30) NULL',
        'ticket_kind' => 'VARCHAR(20) NULL',
        'entitlement_id' => 'INT UNSIGNED NULL',
        'credential_id' => 'INT UNSIGNED NULL',
        'gate' => 'VARCHAR(40) NULL',
        'device' => 'VARCHAR(40) NULL',
        'offline' => 'TINYINT(1) NULL',
        'http_status' => 'SMALLINT UNSIGNED NULL',
        'duration_ms' => 'INT UNSIGNED NULL',
        'request_id' => 'CHAR(12) NULL',
        'ip' => 'VARCHAR(45) NULL',
        'user_agent' => 'VARCHAR(255) NULL',
    ];
}

function scan_card_log_ensure(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS scan_card_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        fixture_id INT UNSIGNED NOT NULL,
        fingerprint CHAR(40) NOT NULL,
        status VARCHAR(30) NOT NULL,
        accepted TINYINT(1) NOT NULL DEFAULT 0,
        message VARCHAR(255) NOT NULL DEFAULT '',
        scanner_account_id INT UNSIGNED NULL,
        scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_scan_card_fixture (fixture_id, fingerprint, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scan_card_log'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (scan_card_log_columns() as $col => $def) {
        if (!in_array($col, $have, true)) {
            $pdo->exec("ALTER TABLE scan_card_log ADD COLUMN `$col` $def");
        }
    }
    $ready = true;
}

/** What kind of thing was scanned, and a safe summary of it (never the whole code). */
function scan_card_value_summary(string $value): array
{
    $v = trim($value);
    if (preg_match('#^https?://#i', $v)) {
        $kind = 'link';
    } elseif (preg_match('/^[A-Za-z0-9]{3}-?[A-Za-z0-9]{4}$/', $v)) {
        $kind = 'manual';
    } elseif (preg_match('/^[a-f0-9]{20,80}$/i', $v)) {
        $kind = 'token';
    } else {
        $kind = 'other';
    }
    $hint = strlen($v) > 12 ? substr($v, 0, 6) . '…' . substr($v, -4) : ($v === '' ? '(empty)' : str_repeat('•', strlen($v)));
    return ['kind' => $kind, 'length' => strlen($value), 'hint' => $hint];
}

/**
 * Records every match-gate scan (accepted or refused) so the scanner test sheet can tick itself and staff can see
 * what happened, from which device, through which code path and how long it took.
 */
function scan_card_log_write(PDO $pdo, int $fixtureId, string $value, string $status, bool $accepted, string $message, ?int $userId, array $ctx = []): void
{
    if ($fixtureId <= 0) {
        return;
    }
    try {
        scan_card_log_ensure($pdo);
        $sum = scan_card_value_summary($value);
        $pdo->prepare('INSERT INTO scan_card_log (fixture_id, fingerprint, status, accepted, message, scanner_account_id, scanned_at,
                value_length, value_kind, value_hint, path, ticket_kind, entitlement_id, credential_id, gate, device, offline,
                http_status, duration_ms, request_id, ip, user_agent)
            VALUES (:f, :fp, :s, :a, :m, :u, NOW(), :vl, :vk, :vh, :path, :tk, :ent, :cred, :gate, :dev, :off, :http, :dur, :rid, :ip, :ua)')->execute([
            ':f' => $fixtureId, ':fp' => scan_card_fingerprint($value), ':s' => mb_substr($status, 0, 30),
            ':a' => $accepted ? 1 : 0, ':m' => mb_substr($message, 0, 255), ':u' => $userId,
            ':vl' => min(65535, $sum['length']), ':vk' => $sum['kind'], ':vh' => mb_substr($sum['hint'], 0, 20),
            ':path' => isset($ctx['path']) ? mb_substr((string) $ctx['path'], 0, 30) : null,
            ':tk' => isset($ctx['ticket_kind']) ? mb_substr((string) $ctx['ticket_kind'], 0, 20) : null,
            ':ent' => $ctx['entitlement_id'] ?? null, ':cred' => $ctx['credential_id'] ?? null,
            ':gate' => isset($ctx['gate']) ? mb_substr((string) $ctx['gate'], 0, 40) : null,
            ':dev' => isset($ctx['device']) ? mb_substr((string) $ctx['device'], 0, 40) : null,
            ':off' => isset($ctx['offline']) ? (int) (bool) $ctx['offline'] : null,
            ':http' => $ctx['http_status'] ?? 200, ':dur' => $ctx['duration_ms'] ?? null,
            ':rid' => $ctx['request_id'] ?? null,
            ':ip' => isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null,
            ':ua' => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        error_log('scan_card_log: ' . $e->getMessage());
    }
}

function scan_process_ticket(PDO $pdo, array $in, ?int $userId): array
{
    $startedAt = microtime(true);
    $requestId = bin2hex(random_bytes(6));
    ensureScanGateSchema($pdo);
    $kind = $in['kind'] === 'event' ? 'event' : 'match';
    $targetId = (int) $in['id'];
    $scanInput = (string) $in['value'];
    $offline = !empty($in['offline']);
    $now = time();
    $scanTs = (int) ($in['scanned_at'] ?? 0) ?: $now;
    $gate = scan_clean_label((string) ($in['gate'] ?? ''), 40);
    $device = scan_clean_label((string) ($in['device'] ?? ''), 40);
    $problemCtx = ['fixture_id' => $kind === 'match' ? $targetId : 0, 'user_id' => (int) $userId];

    if ($scanTs > $now + 300) {
        $scanTs = $now; // a phone clock running fast: treat as now
    }
    if ($offline && $scanTs < $now - SCAN_OFFLINE_MAX_AGE_SECONDS) {
        return ['http' => 200, 'payload' => ['ok' => false, 'code' => 'too_old', 'status' => 'too_old', 'message' => 'This scan is too old to be added (more than 48 hours).']];
    }
    $scannedAt = date('Y-m-d H:i:s', $scanTs);
    $isLate = abs($scanTs - $now) > 5; // an offline scan keeps the time it really happened

    if ($kind === 'event') {
        $evStmt = $pdo->prepare('SELECT id, title, starts_at FROM events WHERE id = :id');
        $evStmt->execute([':id' => $targetId]);
        $evRow = $evStmt->fetch(PDO::FETCH_ASSOC);
        if (!$evRow || substr((string) $evRow['starts_at'], 0, 10) !== scan_business_date($scanTs)) {
            $ref = scan_problem_log($pdo, 'wrong_day', 'Scan refused: that event is not today' . ($evRow ? ' (' . (string) $evRow['title'] . ', ' . substr((string) $evRow['starts_at'], 0, 10) . ')' : ' (unknown event)') . '.', $problemCtx + ['http_status' => 403]);
            return ['http' => 403, 'payload' => ['ok' => false, 'code' => 'wrong_day', 'status' => 'wrong_day', 'ref' => $ref, 'message' => 'This event is not today, so it cannot be scanned.']];
        }
        $res = events_check_and_admit($pdo, $targetId, $scanInput, $userId, $isLate ? $scannedAt : null);
        $evFull = events_get($pdo, $targetId) ?? [];
        if (!empty($res['found'])) {
            $tk = $res['ticket'] ?? null;
            $cred = $res['credential'] ?? [];
            $log = $res['scan_log'] ?? [];
            $holderName = $tk && !empty($tk['attendee_name']) ? (string) $tk['attendee_name'] : (string) ($cred['customer_name'] ?? 'Event ticket');
            $response = [
                'ok' => (string) ($res['status'] ?? '') === 'checked_in',
                'valid_ticket' => (bool) ($res['valid'] ?? false),
                'status' => $res['status'],
                'message' => $res['message'],
                'progress' => $res['progress'] ?? null,
                'holder' => ['name' => $holderName, 'ticket_kind' => 'Event Ticket', 'ticket' => (string) ($tk['ticket_label'] ?? ''), 'order_id' => (int) ($cred['order_id'] ?? 0)],
                'attendance' => null,
                'scan' => $log ? [
                    'accepted' => (int) ($log['accepted'] ?? 0) === 1, 'status' => (string) ($log['result'] ?? ''), 'message' => (string) ($log['message'] ?? ''),
                    'holder_name' => (string) ($log['holder_name'] ?? ''), 'ticket_kind' => 'Event Ticket', 'ticket_label' => (string) ($log['ticket_label'] ?? ''), 'scanned_at' => (string) ($log['scanned_at'] ?? ''),
                ] : null,
                'count' => events_admitted_count($pdo, $targetId),
                'capacity' => events_capacity($evFull),
            ];
        } else {
            $message = 'That QR code or code is not a valid ticket.';
            $log = events_scan_log($pdo, $targetId, null, null, 'invalid_token', $message, false, $userId);
            if ($isLate) {
                $pdo->prepare('UPDATE event_scan_logs SET scanned_at = :t WHERE id = :id')->execute([':t' => $scannedAt, ':id' => (int) $log['id']]);
            }
            $response = [
                'ok' => false, 'valid_ticket' => false, 'status' => 'invalid_token', 'message' => $message, 'holder' => null, 'attendance' => null,
                'scan' => ['accepted' => false, 'status' => 'invalid_token', 'message' => $message, 'holder_name' => '', 'ticket_kind' => 'Event Ticket', 'ticket_label' => '', 'scanned_at' => $scannedAt],
                'count' => events_admitted_count($pdo, $targetId), 'capacity' => events_capacity($evFull),
            ];
        }
    } else {
        $dayStmt = $pdo->prepare('SELECT match_date, opponent FROM match_fixtures WHERE id = :id');
        $dayStmt->execute([':id' => $targetId]);
        $dayRow = $dayStmt->fetch(PDO::FETCH_ASSOC);
        if (!$dayRow || (string) $dayRow['match_date'] !== scan_business_date($scanTs)) {
            $ref = scan_problem_log($pdo, 'wrong_day', 'Scan refused: that match is not today' . ($dayRow ? ' (vs ' . (string) $dayRow['opponent'] . ', ' . (string) $dayRow['match_date'] . ')' : ' (unknown match)') . '.', $problemCtx + ['http_status' => 403]);
            return ['http' => 403, 'payload' => ['ok' => false, 'code' => 'wrong_day', 'status' => 'wrong_day', 'ref' => $ref, 'message' => 'This match is not today, so it cannot be scanned.']];
        }
        $matchResult = match_ticket_check_and_mark_attendance($pdo, $targetId, $scanInput, $userId);
        if (!empty($matchResult['found'])) {
            $ticket = $matchResult['ticket'] ?? null;
            $order = $matchResult['order'] ?? null;
            $scanLog = $matchResult['scan_log'] ?? null;
            $response = [
                'ok' => (string) ($matchResult['status'] ?? '') === 'checked_in',
                'valid_ticket' => (bool) ($matchResult['valid'] ?? false),
                'status' => $matchResult['status'],
                'message' => $matchResult['message'],
                'holder' => [
                    'name' => (string) (($ticket['buyer_name'] ?? null) ?: ($order['buyer_name'] ?? 'Match ticket')),
                    'ticket_kind' => 'Match Ticket',
                    'ticket' => (string) (($ticket['ticket_label'] ?? null) ?: 'Group Order'),
                    'order_id' => (int) (($ticket['order_id'] ?? null) ?: ($order['id'] ?? 0)),
                ],
                'attendance' => null,
                'scan' => is_array($scanLog) ? [
                    'accepted' => (int) ($scanLog['accepted'] ?? 0) === 1, 'status' => (string) ($scanLog['status'] ?? ''), 'message' => (string) ($scanLog['message'] ?? ''),
                    'holder_name' => (string) ($scanLog['holder_name'] ?? ''), 'ticket_kind' => 'Match Ticket', 'ticket_label' => (string) ($scanLog['ticket_label'] ?? ''), 'scanned_at' => (string) ($scanLog['scanned_at'] ?? ''),
                ] : null,
                'count' => admissionsCount($pdo, $targetId),
            ];
        } else {
            $result = season_ticket_check_and_mark_attendance($pdo, $targetId, $scanInput, $userId);
            $order = $result['order'] ?? null;
            $attendance = $result['attendance'] ?? null;
            $scanLog = $result['scan_log'] ?? null;
            $response = [
                'ok' => (string) ($result['status'] ?? '') === 'checked_in',
                'valid_ticket' => (bool) $result['valid'],
                'status' => $result['status'],
                'message' => $result['message'],
                'holder' => is_array($order) ? [
                    'name' => (string) ($order['holder_name'] ?? ''), 'ticket_kind' => 'Season Ticket', 'ticket' => (string) ($order['type_name'] ?? ''),
                    'season' => (string) ($order['season_name'] ?? ''), 'order_id' => (int) ($order['id'] ?? 0),
                ] : null,
                'attendance' => is_array($attendance) ? ['scanned_at' => (string) ($attendance['scanned_at'] ?? '')] : null,
                'scan' => is_array($scanLog) ? [
                    'accepted' => (int) ($scanLog['accepted'] ?? 0) === 1, 'status' => (string) ($scanLog['status'] ?? ''), 'message' => (string) ($scanLog['message'] ?? ''),
                    'holder_name' => (string) ($scanLog['holder_name'] ?? ''), 'ticket_kind' => 'Season Ticket', 'ticket_label' => (string) ($scanLog['ticket_label'] ?? ''), 'scanned_at' => (string) ($scanLog['scanned_at'] ?? ''),
                ] : null,
                'count' => $targetId > 0 ? admissionsCount($pdo, $targetId) : 0,
            ];
        }
        // An offline scan keeps the time it really happened (the checks above stamped "now").
        if ($isLate && is_array($scanLog) && !empty($scanLog['id'])) {
            $pdo->prepare('UPDATE scan_logs SET scanned_at = :t WHERE id = :id')->execute([':t' => $scannedAt, ':id' => (int) $scanLog['id']]);
            $response['scan']['scanned_at'] = $scannedAt;
            if ((string) ($response['status'] ?? '') === 'checked_in' && !empty($scanLog['entitlement_id'])) {
                $pdo->prepare('UPDATE admissions SET admitted_at = :t WHERE fixture_id = :f AND entitlement_id = :e ORDER BY id DESC LIMIT 1')
                    ->execute([':t' => $scannedAt, ':f' => $targetId, ':e' => (int) $scanLog['entitlement_id']]);
            }
        }
    }

    // Codes the system has never seen: keep what was scanned so staff can work out why (tokens are shortened).
    if (in_array((string) ($response['status'] ?? ''), ['not_found', 'invalid_token', 'invalid_fixture'], true)) {
        scan_problem_log($pdo, 'unknown_code', (string) $response['message'], $problemCtx + [
            'detail' => 'Scanned value: ' . mb_substr(preg_replace_callback('/[a-f0-9]{16,}/i', static fn ($m) => substr($m[0], 0, 6) . '...(' . strlen($m[0]) . ' chars)', trim($scanInput)) ?? '', 0, 200),
        ]);
    }
    // A ticket let in at one gate while this phone could not see the server, then scanned again elsewhere.
    if ($offline && (string) ($response['status'] ?? '') === 'already_scanned') {
        scan_problem_log($pdo, 'offline_duplicate', 'An offline scan was a duplicate: the ticket was already admitted before this phone synced.', $problemCtx + [
            'detail' => 'Gate: ' . $gate . ', device: ' . $device . ', scanned at ' . $scannedAt . ', holder: ' . (string) ($response['holder']['name'] ?? ''),
        ]);
    }

    if ($kind === 'match') {
        $cred = null;
        try {
            $cred = admissionsCredential($pdo, $scanInput);
        } catch (Throwable) {
            $cred = null;
        }
        $kindLabel = (string) ($response['holder']['ticket_kind'] ?? '');
        scan_card_log_write($pdo, $targetId, $scanInput, (string) ($response['status'] ?? ''), !empty($response['ok']), (string) ($response['message'] ?? ''), $userId, [
            'path' => $kindLabel === 'Season Ticket' ? 'season_ticket' : ($kindLabel === 'Match Ticket' ? 'match_ticket' : 'unmatched'),
            'ticket_kind' => $cred['entitlement_type'] ?? null,
            'entitlement_id' => isset($cred['entitlement_id']) ? (int) $cred['entitlement_id'] : null,
            'credential_id' => isset($cred['credential_id']) ? (int) $cred['credential_id'] : null,
            'gate' => $gate, 'device' => $device, 'offline' => $offline,
            'http_status' => 200,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'request_id' => $requestId,
        ]);
    }

    // Feed for the door dashboard.
    try {
        $pdo->prepare('INSERT INTO scan_gate_activity (target_kind, target_id, gate, device_id, account_id, status, accepted, holder_name, ticket_label, people, offline, scanned_at)
            VALUES (:k, :id, :gate, :dev, :acct, :status, :acc, :holder, :label, :people, :off, :at)')->execute([
            ':k' => $kind, ':id' => $targetId, ':gate' => $gate, ':dev' => $device, ':acct' => $userId,
            ':status' => mb_substr((string) ($response['status'] ?? ''), 0, 30), ':acc' => !empty($response['ok']) ? 1 : 0,
            ':holder' => mb_substr((string) ($response['holder']['name'] ?? ''), 0, 190) ?: null, ':label' => mb_substr((string) ($response['holder']['ticket'] ?? ''), 0, 120) ?: null,
            ':people' => !empty($response['ok']) ? 1 : 0, ':off' => $offline ? 1 : 0, ':at' => $scannedAt,
        ]);
    } catch (Throwable) {
        // The dashboard feed must never stop a ticket being scanned.
    }

    return ['http' => 200, 'payload' => $response];
}

/* ------------------------------------------------------------------ offline list */

function scan_gate_secret(): string
{
    return hash('sha256', (defined('DB_PASS') ? (string) DB_PASS : '') . (defined('DB_NAME') ? (string) DB_NAME : '') . 'scan-gate');
}

/** The per-day, per-target salt the phone and the server hash tickets with. */
function scan_gate_salt(string $kind, int $targetId, ?int $now = null): string
{
    return substr(hash_hmac('sha256', $kind . ':' . $targetId . ':' . scan_business_date($now), scan_gate_secret()), 0, 24);
}

function scan_gate_token_hash(string $salt, string $token): string
{
    return substr(hash('sha256', $salt . strtolower($token)), 0, 32);
}

function scan_gate_manual_hash(string $salt, string $manualCode): string
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $manualCode) ?? '');
    return hash_pbkdf2('sha256', $code, $salt, SCAN_GATE_PBKDF2_ITERATIONS, 32, false);
}

/** Manual-code hashes are slow on purpose, so they are cached per credential and salt. @return array<int,string> */
function scan_gate_manual_cache(string $salt, array $credentials): array
{
    $dir = dirname(__DIR__, 2) . '/cache/gate';
    $file = $dir . '/manual-' . $salt . '.json';
    $cache = [];
    if (is_file($file)) {
        $cache = json_decode((string) @file_get_contents($file), true) ?: [];
    }
    $changed = false;
    $out = [];
    foreach ($credentials as $credId => $code) {
        if (!isset($cache[$credId]) || ($cache[$credId]['c'] ?? '') !== $code) {
            $cache[$credId] = ['c' => $code, 'h' => scan_gate_manual_hash($salt, $code)];
            $changed = true;
        }
        $out[$credId] = (string) $cache[$credId]['h'];
    }
    if ($changed) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, json_encode($cache), LOCK_EX);
    }
    foreach (glob($dir . '/manual-*.json') ?: [] as $old) {
        if ($old !== $file && filemtime($old) < time() - 3 * 86400) {
            @unlink($old);
        }
    }
    return $out;
}

/**
 * Everything a phone needs to keep checking tickets with no signal. Tickets are sent as hashes, so the list is
 * not a set of usable QR codes if a phone is lost.
 *
 * Entry: t token hash, m manual-code hash, k kind (event|match|season), l ticket label, n holder name,
 * a people the ticket admits, i people already admitted, s state (valid|cancelled).
 *
 * @return array<string,mixed>
 */
function scan_gate_list(PDO $pdo, string $kind, int $targetId): array
{
    ensureScanGateSchema($pdo);
    $kind = $kind === 'event' ? 'event' : 'match';
    $salt = scan_gate_salt($kind, $targetId);
    $rows = [];
    $manual = [];

    if ($kind === 'event') {
        $st = $pdo->prepare("SELECT c.id AS cid, c.token, c.manual_code, c.is_active AS cred_active, t.ticket_label, t.attendee_name, t.admits_count, e.status AS ent_status,
                o.status AS order_status, o.customer_name,
                (SELECT COALESCE(SUM(a.quantity), 0) FROM event_admissions a WHERE a.entitlement_id = t.entitlement_id AND a.event_id = t.event_id) AS admitted
            FROM event_tickets t JOIN entitlements e ON e.id = t.entitlement_id JOIN ticket_credentials c ON c.entitlement_id = e.id JOIN orders o ON o.id = t.order_id
            WHERE t.event_id = :id");
        $st->execute([':id' => $targetId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $valid = (int) $r['cred_active'] === 1 && $r['ent_status'] === 'active' && in_array($r['order_status'], ['paid', 'complete', 'partially_refunded'], true);
            $rows[] = ['cid' => (int) $r['cid'], 'token' => $r['token'], 'code' => $r['manual_code'], 'k' => 'event', 'l' => $r['ticket_label'], 'n' => $r['attendee_name'] ?: $r['customer_name'],
                'a' => max(1, (int) $r['admits_count']), 'i' => (int) $r['admitted'], 's' => $valid ? 'valid' : 'cancelled'];
        }
    } else {
        $fx = $pdo->prepare('SELECT id, season_id FROM match_fixtures WHERE id = :id');
        $fx->execute([':id' => $targetId]);
        $fixture = $fx->fetch(PDO::FETCH_ASSOC);
        if ($fixture) {
            $adm = static function (PDO $pdo, int $fixtureId): array {
                $s = $pdo->prepare('SELECT entitlement_id FROM admissions WHERE fixture_id = :f AND entitlement_id IS NOT NULL');
                $s->execute([':f' => $fixtureId]);
                return array_flip(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
            };
            $in = $adm($pdo, $targetId);
            // Match tickets for this fixture.
            $st = $pdo->prepare("SELECT c.id AS cid, c.token, c.manual_code, c.is_active AS cred_active, mt.ticket_label, mt.entitlement_id, e.status AS ent_status, o.status AS order_status, o.customer_name
                FROM match_tickets mt JOIN entitlements e ON e.id = mt.entitlement_id JOIN ticket_credentials c ON c.entitlement_id = e.id
                JOIN order_items oi ON oi.id = e.order_item_id JOIN orders o ON o.id = oi.order_id WHERE mt.fixture_id = :f");
            $st->execute([':f' => $targetId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $valid = (int) $r['cred_active'] === 1 && $r['ent_status'] === 'active' && $r['order_status'] === 'paid';
                $rows[] = ['cid' => (int) $r['cid'], 'token' => $r['token'], 'code' => $r['manual_code'], 'k' => 'match', 'l' => $r['ticket_label'], 'n' => $r['customer_name'],
                    'a' => 1, 'i' => isset($in[(int) $r['entitlement_id']]) ? 1 : 0, 's' => $valid ? 'valid' : 'cancelled'];
            }
            // Season tickets valid for this fixture's season, kept only where the pass rules allow this match.
            try {
                require_once __DIR__ . '/season_passes.php';
                $sp = $pdo->prepare("SELECT c.id AS cid, c.token, c.manual_code, c.is_active AS cred_active, sp.entitlement_id, sp.season_ticket_type_id, e.status AS ent_status, sp.status AS pass_status,
                        oi.product_reference_id, p.display_name AS holder_name, t.name AS type_name
                    FROM season_passes sp JOIN entitlements e ON e.id = sp.entitlement_id JOIN ticket_credentials c ON c.entitlement_id = e.id
                    LEFT JOIN order_items oi ON oi.id = e.order_item_id LEFT JOIN people p ON p.id = e.person_id LEFT JOIN season_ticket_types t ON t.id = sp.season_ticket_type_id
                    WHERE sp.season_id = :season");
                $sp->execute([':season' => (int) $fixture['season_id']]);
                $decisions = [];
                foreach ($sp->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $ref = (int) ($r['product_reference_id'] ?? 0);
                    if (!array_key_exists($ref, $decisions)) {
                        $d = seasonPassRuleDecision($pdo, $ref, $targetId, (int) $fixture['season_id']);
                        $decisions[$ref] = !empty($d['allowed']);
                    }
                    $valid = (int) $r['cred_active'] === 1 && $r['ent_status'] === 'active' && $r['pass_status'] === 'active' && $decisions[$ref];
                    $rows[] = ['cid' => (int) $r['cid'], 'token' => $r['token'], 'code' => $r['manual_code'], 'k' => 'season', 'l' => (string) $r['type_name'], 'n' => (string) $r['holder_name'],
                        'a' => 1, 'i' => isset($in[(int) $r['entitlement_id']]) ? 1 : 0, 's' => $valid ? 'valid' : 'cancelled'];
                }
            } catch (Throwable) {
                // Season ticket rules unavailable: season tickets are then simply not on the offline list.
            }
        }
    }

    foreach ($rows as $r) {
        $manual[$r['cid']] = (string) $r['code'];
    }
    $manualHashes = scan_gate_manual_cache($salt, $manual);
    $entries = [];
    foreach ($rows as $r) {
        $entries[] = ['t' => scan_gate_token_hash($salt, (string) $r['token']), 'm' => $manualHashes[$r['cid']] ?? '', 'k' => $r['k'], 'l' => (string) $r['l'], 'n' => (string) $r['n'], 'a' => $r['a'], 'i' => $r['i'], 's' => $r['s']];
    }
    return ['kind' => $kind, 'id' => $targetId, 'salt' => $salt, 'iterations' => SCAN_GATE_PBKDF2_ITERATIONS, 'generated_at' => gmdate('c'), 'server_time' => time(), 'count' => count($entries), 'entries' => $entries];
}
