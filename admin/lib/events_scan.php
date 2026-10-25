<?php
declare(strict_types=1);

/**
 * Door scanning for events. Uses the same ticket credentials as match tickets, so the one gate scanner can
 * scan anything, but records entry in its own tables (event_admissions, event_scan_logs) so no match table
 * changes. A ticket that admits several people (a Table of 8) can be scanned once per person until everyone
 * is in; a simple one-person ticket is single use.
 */

require_once __DIR__ . '/events_orders.php';
require_once __DIR__ . '/admissions.php';

function ensureEventScanSchema(PDO $pdo): void
{
    ensureEventOrdersSchema($pdo);
    ensureAdmissionsSchema($pdo);
    if (schema_guard_done('event_scan')) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_admissions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id INT UNSIGNED NOT NULL,
        entitlement_id INT UNSIGNED NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        source VARCHAR(40) NOT NULL DEFAULT 'event_qr',
        reason VARCHAR(255) NULL,
        admitted_by_account_id INT UNSIGNED NULL,
        admitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_admissions_event (event_id, admitted_at),
        KEY idx_event_admissions_entitlement (entitlement_id),
        CONSTRAINT fk_event_admissions_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
        CONSTRAINT fk_event_admissions_entitlement FOREIGN KEY (entitlement_id) REFERENCES entitlements(id) ON DELETE SET NULL,
        CONSTRAINT fk_event_admissions_account FOREIGN KEY (admitted_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_scan_logs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id INT UNSIGNED NOT NULL,
        credential_id INT UNSIGNED NULL,
        entitlement_id INT UNSIGNED NULL,
        scanner_account_id INT UNSIGNED NULL,
        ticket_label VARCHAR(120) NULL,
        holder_name VARCHAR(190) NULL,
        result VARCHAR(40) NOT NULL,
        message VARCHAR(255) NOT NULL,
        accepted TINYINT(1) NOT NULL DEFAULT 0,
        scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_event_scan_logs_event (event_id, scanned_at),
        CONSTRAINT fk_event_scan_logs_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
        CONSTRAINT fk_event_scan_logs_account FOREIGN KEY (scanner_account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    schema_guard_mark('event_scan');
}

/** Events the gate scanner can open today: on the day, and not cancelled, postponed, finished or draft. */
function events_scannable_today(PDO $pdo): array
{
    ensureEventScanSchema($pdo);
    $stmt = $pdo->prepare("SELECT e.*, v.name AS venue_name, v.capacity AS venue_capacity
        FROM events e JOIN event_venues v ON v.id = e.venue_id
        WHERE DATE(e.starts_at) = :today AND e.status IN ('on_sale', 'sold_out')
        ORDER BY e.starts_at ASC, e.id ASC");
    $stmt->execute([':today' => scan_business_date()]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** People admitted so far (not tickets): a Table of 4 scanned twice counts as 2. */
function events_admitted_count(PDO $pdo, int $eventId): int
{
    ensureEventScanSchema($pdo);
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM event_admissions WHERE event_id = :event');
    $stmt->execute([':event' => $eventId]);
    return (int) $stmt->fetchColumn();
}

/** @return array{total:int,valid:int,invalid:int} */
function events_scan_summary(PDO $pdo, int $eventId): array
{
    ensureEventScanSchema($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) AS total, COALESCE(SUM(accepted = 1), 0) AS valid, COALESCE(SUM(accepted = 0), 0) AS invalid FROM event_scan_logs WHERE event_id = :event AND result NOT IN (\'wrong_event\', \'wrong_ticket_type\')');
    $stmt->execute([':event' => $eventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['total' => (int) ($row['total'] ?? 0), 'valid' => (int) ($row['valid'] ?? 0), 'invalid' => (int) ($row['invalid'] ?? 0)];
}

/** @return list<array<string,mixed>> */
function events_recent_scan_logs(PDO $pdo, int $eventId, int $limit = 25): array
{
    ensureEventScanSchema($pdo);
    $stmt = $pdo->prepare('SELECT result AS status, message, accepted, ticket_label, holder_name, scanned_at FROM event_scan_logs WHERE event_id = :event AND result NOT IN (\'wrong_event\', \'wrong_ticket_type\') ORDER BY scanned_at DESC, id DESC LIMIT ' . max(1, min(100, $limit)));
    $stmt->execute([':event' => $eventId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function events_scan_log(PDO $pdo, int $eventId, ?array $credential, ?array $ticket, string $result, string $message, bool $accepted, ?int $accountId, ?string $scannedAt = null): array
{
    $holder = $ticket && !empty($ticket['attendee_name']) ? (string) $ticket['attendee_name'] : (is_array($credential) ? (string) ($credential['customer_name'] ?? '') : '');
    $pdo->prepare('INSERT INTO event_scan_logs (event_id, credential_id, entitlement_id, scanner_account_id, ticket_label, holder_name, result, message, accepted, scanned_at)
        VALUES (:event, :cred, :ent, :scanner, :label, :holder, :result, :message, :accepted, COALESCE(:at, NOW()))')->execute([
        ':event' => $eventId,
        ':cred' => is_array($credential) ? ((int) ($credential['credential_id'] ?? 0) ?: null) : null,
        ':ent' => is_array($credential) ? ((int) ($credential['entitlement_id'] ?? 0) ?: null) : null,
        ':scanner' => $accountId,
        ':label' => $ticket ? (string) $ticket['ticket_label'] : (is_array($credential) ? mb_substr((string) ($credential['description_snapshot'] ?? ''), 0, 120) : null),
        ':holder' => $holder !== '' ? mb_substr($holder, 0, 190) : null,
        ':result' => $result,
        ':message' => mb_substr($message, 0, 255),
        ':accepted' => $accepted ? 1 : 0,
        ':at' => $scannedAt,
    ]);
    $row = $pdo->prepare('SELECT * FROM event_scan_logs WHERE id = :id');
    $row->execute([':id' => $pdo->lastInsertId()]);
    return $row->fetch(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Check a scanned code against an event and admit one person if it is valid.
 *
 * @return array{found:bool,valid?:bool,status?:string,message?:string,ticket?:array,scan_log?:array,progress?:string,admitted?:int,of?:int}
 */
function events_check_and_admit(PDO $pdo, int $eventId, string $scanInput, ?int $accountId, ?string $scannedAt = null): array
{
    ensureEventScanSchema($pdo); // DDL first: it would commit the transaction below
    $event = events_get($pdo, $eventId);
    if (!$event) {
        return ['found' => true, 'valid' => false, 'status' => 'invalid_event', 'message' => 'Event not found.'];
    }
    if (!in_array($event['status'], ['on_sale', 'sold_out'], true)) {
        $msg = $event['status'] === 'cancelled' ? 'This event has been cancelled.' : ($event['status'] === 'postponed' ? 'This event has been postponed, so tickets cannot be admitted.' : 'This event is not open for entry.');
        $log = events_scan_log($pdo, $eventId, null, null, 'event_not_admitting', $msg, false, $accountId, $scannedAt);
        return ['found' => true, 'valid' => false, 'status' => 'event_not_admitting', 'message' => $msg, 'scan_log' => $log];
    }

    $credential = admissionsCredential($pdo, $scanInput);
    if (!$credential) {
        return ['found' => false];
    }
    $type = (string) ($credential['entitlement_type'] ?? '');
    if ($type !== 'event_ticket') {
        $what = $type === 'match_ticket' ? 'a match ticket' : ($type === 'season_pass' ? 'a season ticket' : 'not an event ticket');
        $msg = 'This is ' . $what . ', not a ticket for this event.';
        $log = events_scan_log($pdo, $eventId, $credential, null, 'wrong_ticket_type', $msg, false, $accountId, $scannedAt);
        return ['found' => true, 'valid' => false, 'status' => 'wrong_ticket_type', 'message' => $msg, 'credential' => $credential, 'scan_log' => $log];
    }
    $tk = $pdo->prepare('SELECT * FROM event_tickets WHERE entitlement_id = :ent');
    $tk->execute([':ent' => (int) $credential['entitlement_id']]);
    $ticket = $tk->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$ticket) {
        return ['found' => false];
    }
    if ((int) $ticket['event_id'] !== $eventId) {
        $msg = 'This ticket is for a different event.';
        $log = events_scan_log($pdo, $eventId, $credential, $ticket, 'wrong_event', $msg, false, $accountId, $scannedAt);
        return ['found' => true, 'valid' => false, 'status' => 'wrong_event', 'message' => $msg, 'ticket' => $ticket, 'credential' => $credential, 'scan_log' => $log];
    }
    $orderStatus = (string) ($credential['order_status'] ?? '');
    if ((int) ($credential['credential_is_active'] ?? 0) !== 1 || (string) ($credential['entitlement_status'] ?? '') !== 'active' || !in_array($orderStatus, ['paid', 'complete'], true)) {
        $cancelled = in_array('cancelled', [(string) ($credential['entitlement_status'] ?? ''), $orderStatus], true) || $orderStatus === 'expired';
        $msg = $cancelled ? 'This ticket has been cancelled.' : 'This ticket is not active or paid.';
        $log = events_scan_log($pdo, $eventId, $credential, $ticket, 'not_active', $msg, false, $accountId, $scannedAt);
        return ['found' => true, 'valid' => false, 'status' => 'not_active', 'message' => $msg, 'ticket' => $ticket, 'credential' => $credential, 'scan_log' => $log];
    }

    // Admit one person under a lock on the ticket, so two scanners cannot both let the last person in.
    $admits = max(1, (int) $ticket['admits_count']);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT id FROM entitlements WHERE id = :id FOR UPDATE')->execute([':id' => (int) $credential['entitlement_id']]);
        $sum = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM event_admissions WHERE entitlement_id = :ent AND event_id = :event');
        $sum->execute([':ent' => (int) $credential['entitlement_id'], ':event' => $eventId]);
        $already = (int) $sum->fetchColumn();
        if ($already >= $admits) {
            $msg = $admits > 1 ? 'All ' . $admits . ' people on this ticket are already in.' : 'This ticket has already been scanned.';
            $log = events_scan_log($pdo, $eventId, $credential, $ticket, 'already_scanned', $msg, false, $accountId, $scannedAt);
            $pdo->commit();
            return ['found' => true, 'valid' => true, 'status' => 'already_scanned', 'message' => $msg, 'ticket' => $ticket, 'credential' => $credential, 'scan_log' => $log, 'admitted' => $already, 'of' => $admits];
        }
        $pdo->prepare('INSERT INTO event_admissions (event_id, entitlement_id, quantity, source, admitted_by_account_id, admitted_at) VALUES (:event, :ent, 1, "event_qr", :scanner, COALESCE(:at, NOW()))')
            ->execute([':event' => $eventId, ':ent' => (int) $credential['entitlement_id'], ':scanner' => $accountId, ':at' => $scannedAt]);
        $now = $already + 1;
        $msg = $admits > 1 ? 'Checked in: ' . $now . ' of ' . $admits . ' people.' : 'Ticket checked in.';
        $log = events_scan_log($pdo, $eventId, $credential, $ticket, 'checked_in', $msg, true, $accountId, $scannedAt);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $out = ['found' => true, 'valid' => true, 'status' => 'checked_in', 'message' => $msg, 'ticket' => $ticket, 'credential' => $credential, 'scan_log' => $log, 'admitted' => $now, 'of' => $admits];
    if ($admits > 1) {
        $out['progress'] = $now . ' of ' . $admits . ' in';
    }
    return $out;
}
