<?php
declare(strict_types=1);

/**
 * Events: after-the-sale tools. Sales dashboard, attendee list and CSV, resending tickets, refunds and
 * cancellation, name changes, and messages to ticket holders.
 *
 * Money movements go through ONE swappable function ($refundFn) so the database logic can be tested without
 * touching Stripe. The default sends a real refund to Stripe (the site's Stripe account is in live mode).
 */

require_once __DIR__ . '/events_orders.php';
require_once __DIR__ . '/events_mail.php';
require_once __DIR__ . '/events_scan.php';

/** Hours before the start after which a buyer can no longer change names on tickets. */
const EVENT_NAME_CHANGE_CUTOFF_HOURS = 24;

/** Statuses of an order whose tickets are live (or were paid and may need refunding). */
const EVENT_PAID_ORDER_STATUSES = ['paid', 'complete', 'partially_refunded'];

/* ------------------------------------------------------------------ dashboard */

/**
 * @return array{
 *   types:list<array<string,mixed>>, tickets_sold:int, comps:int, people_expected:int, admitted:int, no_shows:int,
 *   revenue_tickets:float, fees:float, refunded:float, net:float, orders_paid:int, orders_pending:int,
 *   needs_refund:int, capacity:int, seats_taken:int, daily:list<array{day:string,tickets:int,orders:int}>
 * }
 */
function events_dashboard(PDO $pdo, int $eventId): array
{
    ensureEventScanSchema($pdo);
    $event = events_get($pdo, $eventId);
    if (!$event) {
        throw new RuntimeException('Event not found.');
    }
    $q = static function (PDO $pdo, string $sql, array $p) {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st;
    };

    $types = [];
    foreach (events_ticket_types($pdo, $eventId) as $type) {
        $tid = (int) $type['id'];
        $held = (int) $q($pdo, "SELECT COUNT(*) FROM event_tickets t JOIN entitlements e ON e.id = t.entitlement_id JOIN orders o ON o.id = t.order_id
            JOIN event_order_holds h ON h.order_id = o.id WHERE t.ticket_type_id = :t AND e.status = 'pending' AND o.status = 'pending_payment' AND h.state = 'held' AND h.expires_at > NOW()", [':t' => $tid])->fetchColumn();
        $row = $q($pdo, "SELECT COUNT(*) AS n, COALESCE(SUM(oi.unit_price), 0) AS revenue,
                COALESCE(SUM(p.payment_method = 'complimentary'), 0) AS comps
            FROM event_tickets t JOIN entitlements e ON e.id = t.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id JOIN orders o ON o.id = t.order_id
            LEFT JOIN payments p ON p.order_id = o.id
            WHERE t.ticket_type_id = :t AND e.status = 'active' AND o.status IN ('paid', 'complete', 'partially_refunded')", [':t' => $tid])->fetch(PDO::FETCH_ASSOC);
        $sold = (int) ($row['n'] ?? 0);
        $types[] = $type + [
            'sold' => $sold, 'held' => $held, 'comps' => (int) ($row['comps'] ?? 0), 'revenue' => (float) ($row['revenue'] ?? 0),
            'remaining' => max(0, (int) $type['allocation'] - $sold - $held),
        ];
    }

    $tot = $q($pdo, "SELECT COUNT(*) AS tickets, COALESCE(SUM(t.admits_count), 0) AS people, COALESCE(SUM(oi.unit_price), 0) AS revenue
        FROM event_tickets t JOIN entitlements e ON e.id = t.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id JOIN orders o ON o.id = t.order_id
        WHERE t.event_id = :e AND e.status = 'active' AND o.status IN ('paid', 'complete', 'partially_refunded')", [':e' => $eventId])->fetch(PDO::FETCH_ASSOC);
    $comps = (int) $q($pdo, "SELECT COUNT(*) FROM event_tickets t JOIN entitlements e ON e.id = t.entitlement_id JOIN orders o ON o.id = t.order_id JOIN payments p ON p.order_id = o.id
        WHERE t.event_id = :e AND e.status = 'active' AND o.status IN ('paid', 'complete', 'partially_refunded') AND p.payment_method = 'complimentary'", [':e' => $eventId])->fetchColumn();
    $orderIds = "SELECT DISTINCT order_id FROM event_tickets WHERE event_id = :e";
    $fees = (float) $q($pdo, "SELECT COALESCE(SUM(oi.line_total), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
        WHERE oi.product_type = 'booking_fee' AND o.id IN ($orderIds) AND o.status IN ('paid', 'complete', 'partially_refunded', 'refunded')", [':e' => $eventId])->fetchColumn();
    $refunded = (float) $q($pdo, "SELECT COALESCE(SUM(r.amount), 0) FROM refunds r JOIN payments p ON p.id = r.payment_id WHERE r.status = 'succeeded' AND p.order_id IN ($orderIds)", [':e' => $eventId])->fetchColumn();
    $ordersPaid = (int) $q($pdo, "SELECT COUNT(*) FROM orders WHERE id IN ($orderIds) AND status IN ('paid', 'complete', 'partially_refunded')", [':e' => $eventId])->fetchColumn();
    $ordersPending = (int) $q($pdo, "SELECT COUNT(*) FROM orders o JOIN event_order_holds h ON h.order_id = o.id WHERE o.id IN ($orderIds) AND o.status = 'pending_payment' AND h.state = 'held' AND h.expires_at > NOW()", [':e' => $eventId])->fetchColumn();
    $needsRefund = (int) $q($pdo, "SELECT COUNT(*) FROM orders WHERE id IN ($orderIds) AND status = 'needs_refund'", [':e' => $eventId])->fetchColumn();
    $daily = $q($pdo, "SELECT DATE(o.completed_at) AS day, COUNT(DISTINCT o.id) AS orders, COUNT(t.id) AS tickets FROM orders o JOIN event_tickets t ON t.order_id = o.id
        JOIN entitlements e ON e.id = t.entitlement_id WHERE t.event_id = :e AND e.status = 'active' AND o.status IN ('paid', 'complete', 'partially_refunded') AND o.completed_at IS NOT NULL
        GROUP BY DATE(o.completed_at) ORDER BY day", [':e' => $eventId])->fetchAll(PDO::FETCH_ASSOC);

    $people = (int) ($tot['people'] ?? 0);
    $admitted = events_admitted_count($pdo, $eventId);
    $revenue = (float) ($tot['revenue'] ?? 0);
    return [
        'types' => $types, 'tickets_sold' => (int) ($tot['tickets'] ?? 0), 'comps' => $comps, 'people_expected' => $people, 'admitted' => $admitted,
        'no_shows' => strtotime((string) $event['starts_at']) < time() ? max(0, $people - $admitted) : 0,
        'revenue_tickets' => $revenue, 'fees' => $fees, 'refunded' => $refunded, 'net' => round($revenue + $fees - $refunded, 2),
        'orders_paid' => $ordersPaid, 'orders_pending' => $ordersPending, 'needs_refund' => $needsRefund,
        'capacity' => events_capacity($event), 'seats_taken' => events_seats_sold($pdo, $eventId),
        'access_places' => (int) ($event['access_places'] ?? 0), 'access_taken' => events_seats_by_pool($pdo, $eventId)['access'],
        'access_released' => events_access_released($event),
        'access_needs' => (int) $q($pdo, 'SELECT COUNT(*) FROM event_access_needs WHERE event_id = :e', [':e' => $eventId])->fetchColumn(),
        'daily' => array_map(static fn (array $d): array => ['day' => (string) $d['day'], 'tickets' => (int) $d['tickets'], 'orders' => (int) $d['orders']], $daily),
    ];
}

/* ------------------------------------------------------------------ attendees */

/**
 * One row per ticket. $filters: q (name, email, code, order number), show (all|live|admitted|waiting|cancelled).
 *
 * @return list<array<string,mixed>>
 */
function events_attendee_rows(PDO $pdo, int $eventId, array $filters = []): array
{
    ensureEventScanSchema($pdo);
    $where = ['t.event_id = :event', "o.status <> 'expired'"];
    $params = [':event' => $eventId];
    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(t.attendee_name LIKE :q OR o.customer_name LIKE :q2 OR o.customer_email LIKE :q3 OR c.manual_code LIKE :q4 OR o.id = :qid)';
        $params += [':q' => "%$q%", ':q2' => "%$q%", ':q3' => "%$q%", ':q4' => '%' . strtoupper($q) . '%', ':qid' => ctype_digit($q) ? (int) $q : 0];
    }
    $show = (string) ($filters['show'] ?? 'all');
    $live = "(e.status = 'active' AND o.status IN ('paid', 'complete', 'partially_refunded'))";
    if ($show === 'live') {
        $where[] = $live;
    } elseif ($show === 'cancelled') {
        $where[] = "(e.status IN ('refunded', 'cancelled') OR o.status IN ('refunded', 'cancelled', 'needs_refund'))";
    } elseif ($show === 'access' && !empty($filters['with_needs'])) {
        $where[] = 'EXISTS (SELECT 1 FROM event_access_needs n WHERE n.order_id = o.id)';
    }
    // Access needs are sensitive (health information): only included when the caller has been allowed to see them.
    $needsSql = !empty($filters['with_needs']) ? '(SELECT n.needs FROM event_access_needs n WHERE n.order_id = o.id)' : 'NULL';
    $sql = "SELECT t.id AS ticket_id, t.ticket_label, t.admits_count, t.attendee_name, t.entitlement_id, c.manual_code, c.token AS ticket_token,
            e.status AS ticket_status, o.id AS order_id, o.customer_name, o.customer_email, o.status AS order_status, o.total_amount, o.completed_at, o.created_at,
            oi.unit_price, p.payment_method,
            (SELECT COALESCE(SUM(a.quantity), 0) FROM event_admissions a WHERE a.entitlement_id = t.entitlement_id AND a.event_id = t.event_id) AS admitted,
            (SELECT COUNT(*) FROM event_access_needs n2 WHERE n2.order_id = o.id) AS has_needs, $needsSql AS access_needs,
            (SELECT token FROM order_access_tokens a2 WHERE a2.order_id = o.id AND a2.purpose = 'public_ticket_order' AND a2.revoked_at IS NULL ORDER BY a2.id DESC LIMIT 1) AS access_token
        FROM event_tickets t
        JOIN entitlements e ON e.id = t.entitlement_id
        JOIN ticket_credentials c ON c.entitlement_id = e.id
        JOIN order_items oi ON oi.id = e.order_item_id
        JOIN orders o ON o.id = t.order_id
        LEFT JOIN payments p ON p.order_id = o.id
        WHERE " . implode(' AND ', $where) . ' ORDER BY o.id DESC, t.id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['is_live'] = $r['ticket_status'] === 'active' && in_array($r['order_status'], EVENT_PAID_ORDER_STATUSES, true);
        $r['fully_in'] = (int) $r['admitted'] >= (int) $r['admits_count'];
    }
    unset($r);
    if ($show === 'admitted') {
        $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['admitted'] > 0));
    } elseif ($show === 'waiting') {
        $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['is_live'] && !$r['fully_in']));
    }
    return $rows;
}

/** A spreadsheet cell that cannot run as a formula when opened in Excel or Sheets. */
function events_csv_cell(mixed $value): string
{
    $s = (string) ($value ?? '');
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        $s = "'" . $s;
    }
    return '"' . str_replace('"', '""', $s) . '"';
}

/** @param list<array<string,mixed>> $rows */
function events_attendees_csv(array $rows): string
{
    $out = implode(',', array_map('events_csv_cell', ['Order', 'Ticket', 'Attendee', 'Buyer', 'Email', 'Code', 'Paid', 'Status', 'People', 'Admitted', 'Bought']));
    foreach ($rows as $r) {
        $status = $r['is_live'] ? 'Valid' : ucfirst((string) ($r['ticket_status'] === 'active' ? $r['order_status'] : $r['ticket_status']));
        $out .= "\r\n" . implode(',', array_map('events_csv_cell', [
            $r['order_id'], $r['ticket_label'], $r['attendee_name'], $r['customer_name'], $r['customer_email'], $r['manual_code'],
            number_format((float) $r['unit_price'], 2, '.', ''), $status, $r['admits_count'], $r['admitted'], $r['completed_at'] ?: $r['created_at'],
        ]));
    }
    return $out . "\r\n";
}

/* ------------------------------------------------------------------ refunds */

/**
 * The default way money goes back: a real Stripe refund. Replace this (via the $refundFn parameters) in tests.
 *
 * @return string the provider's refund id
 */
function events_stripe_refund(string $checkoutSessionId, float $amount, string $idempotencyKey, string $reason, int $orderId): string
{
    $session = stripe_request('GET', '/checkout/sessions/' . rawurlencode($checkoutSessionId), ['expand' => ['payment_intent']]);
    $pi = $session['payment_intent'] ?? null;
    $piId = is_array($pi) ? (string) ($pi['id'] ?? '') : (string) $pi;
    if ($piId === '') {
        throw new RuntimeException('Stripe did not return a payment for this order.');
    }
    $refund = stripe_request('POST', '/refunds', [
        'payment_intent' => $piId,
        'amount' => stripe_amount_to_minor_units($amount, stripe_default_currency()),
        'reason' => 'requested_by_customer',
        'metadata' => ['kind' => 'event_ticket', 'order_id' => (string) $orderId, 'note' => mb_substr($reason, 0, 200)],
        '_idempotency_key' => $idempotencyKey,
    ]);
    return (string) ($refund['id'] ?? '');
}

/** Money already returned on an order. */
function events_order_refunded_total(PDO $pdo, int $orderId): float
{
    $st = $pdo->prepare("SELECT COALESCE(SUM(r.amount), 0) FROM refunds r JOIN payments p ON p.id = r.payment_id WHERE p.order_id = :o AND r.status = 'succeeded'");
    $st->execute([':o' => $orderId]);
    return round((float) $st->fetchColumn(), 2);
}

function events_record_refund(PDO $pdo, int $orderId, float $amount, string $reason, string $providerId, ?int $accountId): void
{
    $pay = $pdo->prepare('SELECT id FROM payments WHERE order_id = :o ORDER BY id LIMIT 1');
    $pay->execute([':o' => $orderId]);
    $paymentId = (int) $pay->fetchColumn();
    if ($paymentId > 0 && $amount > 0) {
        $pdo->prepare('INSERT INTO refunds (payment_id, amount, reason, provider_refund_id, status, requested_by_account_id, completed_at) VALUES (:p, :a, :r, :id, "succeeded", :acct, NOW())')
            ->execute([':p' => $paymentId, ':a' => $amount, ':r' => mb_substr($reason, 0, 1000), ':id' => $providerId !== '' ? $providerId : null, ':acct' => $accountId]);
    }
}

/**
 * Cancel an order and give the money back in full (tickets and booking fee). Free and complimentary orders are
 * simply cancelled. Safe to repeat: the money is only ever returned once.
 *
 * @param null|callable(string,float,string,string,int):string $refundFn (sessionId, amount, idempotencyKey, reason, orderId) => refund id
 * @return array{refunded:float,refund_id:string,cancelled:bool}
 * @throws RuntimeException with a message for the admin
 */
function events_refund_order(PDO $pdo, int $orderId, string $reason, ?int $accountId, ?callable $refundFn = null): array
{
    ensureEventOrdersSchema($pdo);
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A reason is required.');
    }
    $st = $pdo->prepare("SELECT o.*, (SELECT p.provider_checkout_session_id FROM payments p WHERE p.order_id = o.id ORDER BY p.id LIMIT 1) AS session_id,
            (SELECT p.payment_method FROM payments p WHERE p.order_id = o.id ORDER BY p.id LIMIT 1) AS pay_method
        FROM orders o WHERE o.id = :id AND o.source = 'event_ticket'");
    $st->execute([':id' => $orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    if (!in_array($order['status'], array_merge(EVENT_PAID_ORDER_STATUSES, ['needs_refund']), true)) {
        throw new RuntimeException($order['status'] === 'refunded' ? 'This order has already been refunded.' : 'This order is ' . str_replace('_', ' ', (string) $order['status']) . ', so there is nothing to refund.');
    }
    $remaining = round((float) $order['total_amount'] - events_order_refunded_total($pdo, $orderId), 2);
    $providerId = '';
    $refunded = 0.0;
    if ($remaining > 0 && (float) $order['total_amount'] > 0) {
        if (empty($order['session_id'])) {
            throw new RuntimeException('This order has no card payment on record, so it cannot be refunded here. Refund it in the Stripe dashboard instead.');
        }
        $fn = $refundFn ?? 'events_stripe_refund';
        $providerId = (string) $fn((string) $order['session_id'], $remaining, 'event-order-' . $orderId . '-' . (int) round($remaining * 100), $reason, $orderId);
        $refunded = $remaining;
    }

    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        events_record_refund($pdo, $orderId, $refunded, $reason, $providerId, $accountId);
        $money = (float) $order['total_amount'] > 0;
        $pdo->prepare('UPDATE orders SET status = :s, cancelled_at = COALESCE(cancelled_at, NOW()) WHERE id = :id')->execute([':s' => $money ? 'refunded' : 'cancelled', ':id' => $orderId]);
        if ($money) {
            $pdo->prepare("UPDATE payments SET status = 'refunded' WHERE order_id = :id")->execute([':id' => $orderId]);
        }
        $pdo->prepare("UPDATE entitlements e JOIN order_items oi ON oi.id = e.order_item_id SET e.status = :s, e.cancelled_at = NOW() WHERE oi.order_id = :id AND e.type = 'event_ticket' AND e.status IN ('active', 'pending')")
            ->execute([':s' => $money ? 'refunded' : 'cancelled', ':id' => $orderId]);
        $pdo->prepare("UPDATE ticket_credentials c JOIN entitlements e ON e.id = c.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id SET c.is_active = 0, c.revoked_at = NOW() WHERE oi.order_id = :id AND e.type = 'event_ticket'")->execute([':id' => $orderId]);
        $pdo->prepare("UPDATE event_order_holds SET state = 'released' WHERE order_id = :id")->execute([':id' => $orderId]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    events_after_seats_freed($pdo, events_order_event_id($pdo, $orderId));
    return ['refunded' => $refunded, 'refund_id' => $providerId, 'cancelled' => true];
}

/**
 * Cancel ONE ticket and refund its price (the booking fee is not refunded for a single ticket). Free and
 * complimentary tickets are simply cancelled.
 *
 * @param null|callable(string,float,string,string,int):string $refundFn
 * @return array{refunded:float,refund_id:string,order_status:string}
 */
function events_refund_ticket(PDO $pdo, int $ticketId, string $reason, ?int $accountId, ?callable $refundFn = null): array
{
    ensureEventOrdersSchema($pdo);
    $reason = trim($reason);
    if ($reason === '') {
        throw new RuntimeException('A reason is required.');
    }
    $st = $pdo->prepare("SELECT t.id, t.order_id, t.entitlement_id, e.status AS ent_status, oi.unit_price, o.status AS order_status, o.total_amount,
            (SELECT p.provider_checkout_session_id FROM payments p WHERE p.order_id = o.id ORDER BY p.id LIMIT 1) AS session_id
        FROM event_tickets t JOIN entitlements e ON e.id = t.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id JOIN orders o ON o.id = t.order_id WHERE t.id = :id");
    $st->execute([':id' => $ticketId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t) {
        throw new RuntimeException('Ticket not found.');
    }
    if ($t['ent_status'] !== 'active' || !in_array($t['order_status'], EVENT_PAID_ORDER_STATUSES, true)) {
        throw new RuntimeException('This ticket is not live, so it cannot be refunded.');
    }
    $amount = round((float) $t['unit_price'], 2);
    $providerId = '';
    if ($amount > 0) {
        if (empty($t['session_id'])) {
            throw new RuntimeException('This order has no card payment to refund.');
        }
        $fn = $refundFn ?? 'events_stripe_refund';
        $providerId = (string) $fn((string) $t['session_id'], $amount, 'event-ticket-' . $ticketId . '-' . (int) round($amount * 100), $reason, (int) $t['order_id']);
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        events_record_refund($pdo, (int) $t['order_id'], $amount, $reason, $providerId, $accountId);
        $pdo->prepare('UPDATE entitlements SET status = :s, cancelled_at = NOW() WHERE id = :id')->execute([':s' => $amount > 0 ? 'refunded' : 'cancelled', ':id' => (int) $t['entitlement_id']]);
        $pdo->prepare('UPDATE ticket_credentials SET is_active = 0, revoked_at = NOW() WHERE entitlement_id = :id')->execute([':id' => (int) $t['entitlement_id']]);
        $left = $pdo->prepare("SELECT COUNT(*) FROM event_tickets x JOIN entitlements ex ON ex.id = x.entitlement_id WHERE x.order_id = :o AND ex.status = 'active'");
        $left->execute([':o' => (int) $t['order_id']]);
        $none = (int) $left->fetchColumn() === 0;
        $refundedTotal = events_order_refunded_total($pdo, (int) $t['order_id']);
        $newStatus = $none ? ($refundedTotal > 0 ? 'refunded' : 'cancelled') : ($refundedTotal > 0 ? 'partially_refunded' : (string) $t['order_status']);
        if ($none && $refundedTotal > 0 && $refundedTotal < (float) $t['total_amount']) {
            $newStatus = 'partially_refunded'; // every ticket is gone but the booking fee was kept
        }
        $pdo->prepare('UPDATE orders SET status = :s WHERE id = :id')->execute([':s' => $newStatus, ':id' => (int) $t['order_id']]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    events_after_seats_freed($pdo, (int) $t['order_id'] > 0 ? events_order_event_id($pdo, (int) $t['order_id']) : 0);
    return ['refunded' => $amount, 'refund_id' => $providerId, 'order_status' => $newStatus];
}

/**
 * Cancel the whole event: stop sales, release unpaid holds. Refunds are then done in batches with
 * events_refund_order() so a timeout cannot leave things half done.
 *
 * @return array{refundable:int,released:int}
 */
function events_cancel_event(PDO $pdo, int $eventId): array
{
    ensureEventOrdersSchema($pdo);
    $event = events_get($pdo, $eventId);
    if (!$event) {
        throw new RuntimeException('Event not found.');
    }
    $pdo->prepare("UPDATE events SET status = 'cancelled' WHERE id = :id")->execute([':id' => $eventId]);
    $pending = $pdo->prepare("SELECT DISTINCT o.id FROM orders o JOIN event_tickets t ON t.order_id = o.id WHERE t.event_id = :e AND o.status = 'pending_payment'");
    $pending->execute([':e' => $eventId]);
    $released = 0;
    foreach ($pending->fetchAll(PDO::FETCH_COLUMN) as $oid) {
        events_expire_order($pdo, (int) $oid);
        $released++;
    }
    return ['refundable' => count(events_orders_to_refund($pdo, $eventId)), 'released' => $released];
}

/** Paid orders on the event that still hold money or live tickets (what "Refund all" will work through). @return list<int> */
function events_orders_to_refund(PDO $pdo, int $eventId): array
{
    $st = $pdo->prepare("SELECT DISTINCT o.id FROM orders o JOIN event_tickets t ON t.order_id = o.id
        WHERE t.event_id = :e AND o.status IN ('paid', 'complete', 'partially_refunded', 'needs_refund') ORDER BY o.id");
    $st->execute([':e' => $eventId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/* ------------------------------------------------------------------ name changes */

function events_name_change_deadline(array $event): int
{
    return strtotime((string) $event['starts_at']) - EVENT_NAME_CHANGE_CUTOFF_HOURS * 3600;
}

/** Can a buyer still change the names on their tickets? (Staff can always.) */
function events_buyer_can_change_names(array $event, ?int $now = null): bool
{
    return ($now ?? time()) < events_name_change_deadline($event) && !in_array($event['status'], ['cancelled', 'finished'], true);
}

/**
 * Change the name on a ticket. A buyer (checked by order) is stopped by the cut-off and by a ticket that has
 * already been used; staff are not. Every change is recorded.
 *
 * @throws RuntimeException with a customer-friendly message
 */
function events_change_attendee_name(PDO $pdo, int $ticketId, string $newName, string $by, ?int $accountId = null, ?int $mustBelongToOrder = null): void
{
    ensureEventScanSchema($pdo);
    $newName = trim(preg_replace('/\s+/', ' ', $newName) ?? '');
    if ($newName === '' || mb_strlen($newName) > 150) {
        throw new RuntimeException('Enter a name (up to 150 characters).');
    }
    $st = $pdo->prepare("SELECT t.id, t.order_id, t.attendee_name, t.entitlement_id, t.event_id, e.status AS ent_status, o.status AS order_status
        FROM event_tickets t JOIN entitlements e ON e.id = t.entitlement_id JOIN orders o ON o.id = t.order_id WHERE t.id = :id");
    $st->execute([':id' => $ticketId]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    if (!$t || ($mustBelongToOrder !== null && (int) $t['order_id'] !== $mustBelongToOrder)) {
        throw new RuntimeException('Ticket not found.');
    }
    if ($t['ent_status'] !== 'active' || !in_array($t['order_status'], EVENT_PAID_ORDER_STATUSES, true)) {
        throw new RuntimeException('This ticket is not valid, so its name cannot be changed.');
    }
    if ($by === 'buyer') {
        $event = events_get($pdo, (int) $t['event_id']);
        if (!$event || !events_buyer_can_change_names($event)) {
            throw new RuntimeException('Names can only be changed until ' . EVENT_NAME_CHANGE_CUTOFF_HOURS . ' hours before the event. Please contact the club.');
        }
        $in = $pdo->prepare('SELECT COALESCE(SUM(quantity), 0) FROM event_admissions WHERE entitlement_id = :e');
        $in->execute([':e' => (int) $t['entitlement_id']]);
        if ((int) $in->fetchColumn() > 0) {
            throw new RuntimeException('This ticket has already been used, so its name cannot be changed.');
        }
    }
    if ((string) $t['attendee_name'] === $newName) {
        return;
    }
    $pdo->prepare('UPDATE event_tickets SET attendee_name = :n WHERE id = :id')->execute([':n' => $newName, ':id' => $ticketId]);
    $pdo->prepare('INSERT INTO event_ticket_changes (event_ticket_id, old_name, new_name, changed_by, changed_by_account_id) VALUES (:t, :o, :n, :by, :acct)')
        ->execute([':t' => $ticketId, ':o' => $t['attendee_name'], ':n' => $newName, ':by' => $by === 'buyer' ? 'buyer' : 'staff', ':acct' => $accountId]);
}

/* ------------------------------------------------------------------ messages */

/** Distinct email addresses of people holding live tickets. @return list<string> */
function events_holder_emails(PDO $pdo, int $eventId): array
{
    $st = $pdo->prepare("SELECT DISTINCT LOWER(o.customer_email) FROM orders o JOIN event_tickets t ON t.order_id = o.id JOIN entitlements e ON e.id = t.entitlement_id
        WHERE t.event_id = :e AND e.status = 'active' AND o.status IN ('paid', 'complete', 'partially_refunded') AND o.customer_email IS NOT NULL AND o.customer_email <> ''");
    $st->execute([':e' => $eventId]);
    return array_values(array_filter($st->fetchAll(PDO::FETCH_COLUMN), static fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
}

/**
 * Email everyone holding tickets for the event. With $onlyTo set, sends a single test copy to that address and
 * records nothing.
 *
 * @param null|callable(string,string,string,bool,?string):bool $sendFn (to, subject, body, isHtml, replyTo) => sent
 * @return array{sent:int,failed:int,recipients:int}
 */
function events_send_message(PDO $pdo, int $eventId, string $subject, string $body, ?int $accountId, ?string $onlyTo = null, ?callable $sendFn = null): array
{
    ensureEventOrdersSchema($pdo);
    $event = events_get($pdo, $eventId);
    if (!$event) {
        throw new RuntimeException('Event not found.');
    }
    $subject = trim($subject);
    $body = trim($body);
    if ($subject === '' || $body === '') {
        throw new RuntimeException('Enter a subject and a message.');
    }
    $to = $onlyTo !== null ? [$onlyTo] : events_holder_emails($pdo, $eventId);
    if (!$to) {
        throw new RuntimeException('Nobody holds tickets for this event yet.');
    }
    $contact = events_contact_email($pdo, $event);
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $when = date('l j F Y, H:i', strtotime((string) $event['starts_at']));
    $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#24151b">'
        . '<p style="color:#746872;margin-bottom:4px">' . $h((string) $event['title']) . ' · ' . $h($when) . '</p>'
        . '<div style="font-size:15px;line-height:1.5">' . nl2br($h($body)) . '</div>'
        . ($contact !== '' ? '<p style="color:#746872;font-size:13px;margin-top:24px">A problem or a question? Email <a href="mailto:' . $h($contact) . '">' . $h($contact) . '</a>.</p>' : '')
        . '</div>';
    $fn = $sendFn ?? static fn (string $t, string $s, string $b, bool $isHtml, ?string $reply): bool => hub_send_mail($t, $s, $b, $isHtml, $reply);
    $sent = 0;
    $failed = 0;
    foreach ($to as $address) {
        $ok = false;
        try {
            $ok = (bool) $fn($address, $subject, $html, true, $contact !== '' ? $contact : null);
        } catch (Throwable) {
            $ok = false;
        }
        $ok ? $sent++ : $failed++;
    }
    if ($onlyTo === null) {
        $pdo->prepare('INSERT INTO event_messages (event_id, subject, body, recipients, failed, sent_by_account_id) VALUES (:e, :s, :b, :r, :f, :a)')
            ->execute([':e' => $eventId, ':s' => mb_substr($subject, 0, 200), ':b' => $body, ':r' => $sent, ':f' => $failed, ':a' => $accountId]);
    }
    return ['sent' => $sent, 'failed' => $failed, 'recipients' => count($to)];
}
