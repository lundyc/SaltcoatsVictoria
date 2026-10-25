<?php
declare(strict_types=1);

/**
 * Stripe payout reconciliation for the treasurer.
 *
 * For each Stripe payout (money arriving in the club's bank) this lists every transaction inside it, works out
 * what each one was (event ticket, match ticket, season ticket, shop...) and matches it to the Hub record, checks
 * the arithmetic (gross - Stripe fees - refunds = the payout), and flags anything the Hub does not recognise or
 * any Stripe payment the Hub recorded that has not appeared in a payout.
 *
 * READ-ONLY: this only ever asks Stripe questions (GET). All Stripe calls go through $api so the logic can be
 * tested with canned data.
 */

require_once __DIR__ . '/schema_guard.php';
require_once __DIR__ . '/stripe.php';

/** Hub payment kinds, from metadata.kind on the Stripe payment. */
const PAYOUT_KIND_LABELS = [
    'event_ticket' => 'Event tickets',
    'match_ticket' => 'Match tickets',
    'season_ticket' => 'Season tickets',
    'shop' => 'Club shop',
    'hospitality' => 'Hospitality',
    'match_sponsorship' => 'Match sponsorship',
    'member_sponsorship' => 'Member sponsorship',
    'player_sponsorship_shop' => 'Player sponsorship',
    'hidden_team' => 'Hidden Team',
    'sponsorship_link' => 'Sponsorship payment link',
    'adjustment' => 'Stripe adjustment or dispute',
    'stripe_fee' => 'Stripe fee',
    'unknown' => 'Not recognised by the Hub',
];

/** Stripe pays out within this many days of a payment; later than that and a missing payout is worth chasing. */
const PAYOUT_EXPECTED_WITHIN_DAYS = 10;

/** Thrown for a manual payout, whose contents Stripe does not list. */
final class PayoutManualException extends RuntimeException
{
}

function ensurePayoutCacheSchema(PDO $pdo): void
{
    if (schema_guard_done('payout_cache')) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS stripe_payout_cache (
        payout_id VARCHAR(64) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT '',
        data_json LONGTEXT NOT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (payout_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    schema_guard_mark('payout_cache');
}

/** @param null|callable(string,array):array $api (path, params) => decoded Stripe response */
function payout_api(?callable $api, string $path, array $params = []): array
{
    return $api ? $api($path, $params) : stripe_request('GET', $path, $params);
}

/** Payouts created since the given time, newest first. @return list<array<string,mixed>> */
function payout_list(int $sinceTs, ?callable $api = null): array
{
    $out = [];
    $after = null;
    for ($page = 0; $page < 10; $page++) {
        $params = ['limit' => 100, 'created' => ['gte' => $sinceTs]] + ($after ? ['starting_after' => $after] : []);
        $res = payout_api($api, '/payouts', $params);
        foreach ($res['data'] ?? [] as $po) {
            $out[] = $po;
            $after = $po['id'];
        }
        if (empty($res['has_more'])) {
            break;
        }
    }
    return $out;
}

/** One Stripe balance transaction, flattened to what the report needs. @return array<string,mixed> */
function payout_normalise_txn(array $t): array
{
    $src = is_array($t['source'] ?? null) ? $t['source'] : [];
    $isRefund = in_array($t['reporting_category'] ?? '', ['refund'], true) || in_array($t['type'] ?? '', ['refund', 'payment_refund'], true);
    $billing = is_array($src['billing_details'] ?? null) ? $src['billing_details'] : [];
    $charge = $src['charge'] ?? null;
    $chargeObj = is_array($charge) ? $charge : null;
    $meta = is_array($src['metadata'] ?? null) ? $src['metadata'] : [];
    if (!$meta && $chargeObj && is_array($chargeObj['metadata'] ?? null)) {
        $meta = $chargeObj['metadata'];
    }
    return [
        'id' => (string) $t['id'],
        'type' => (string) ($t['type'] ?? ''),
        'reporting' => (string) ($t['reporting_category'] ?? ''),
        'created' => (int) ($t['created'] ?? 0),
        'gross' => (int) ($t['amount'] ?? 0),
        'fee' => (int) ($t['fee'] ?? 0),
        'net' => (int) ($t['net'] ?? 0),
        'description' => mb_substr((string) ($t['description'] ?? ($src['description'] ?? '')), 0, 200),
        'is_refund' => $isRefund,
        'source_id' => (string) ($src['id'] ?? ''),
        'charge_id' => is_array($charge) ? (string) ($charge['id'] ?? '') : (string) ($charge ?? ''),
        'pi' => (string) ($src['payment_intent'] ?? ($chargeObj['payment_intent'] ?? '')),
        'meta' => $meta,
        'name' => (string) ($billing['name'] ?? ($chargeObj['billing_details']['name'] ?? '')),
        'email' => (string) ($billing['email'] ?? ($src['receipt_email'] ?? ($chargeObj['billing_details']['email'] ?? ''))),
    ];
}

/**
 * Every transaction inside an automatic payout (the payout's own line is left out).
 *
 * @throws PayoutManualException for a manual payout
 * @return list<array<string,mixed>>
 */
function payout_transactions(string $payoutId, ?callable $api = null): array
{
    $out = [];
    $after = null;
    for ($page = 0; $page < 30; $page++) {
        $params = ['payout' => $payoutId, 'limit' => 100, 'expand' => ['data.source']] + ($after ? ['starting_after' => $after] : []);
        try {
            $res = payout_api($api, '/balance_transactions', $params);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'not manual') !== false || stripos($e->getMessage(), 'manual') !== false) {
                throw new PayoutManualException('Stripe does not itemise manual payouts.');
            }
            throw $e;
        }
        foreach ($res['data'] ?? [] as $t) {
            $after = $t['id'];
            if (($t['type'] ?? '') === 'payout') {
                continue;
            }
            $out[] = payout_normalise_txn($t);
        }
        if (empty($res['has_more'])) {
            break;
        }
    }
    return $out;
}

/** A refund carries no Hub details of its own: find the payment it belongs to. */
function payout_fill_refund_details(array $txns, ?callable $api = null): array
{
    $seen = [];
    foreach ($txns as &$t) {
        if (!$t['is_refund'] || $t['meta'] || $t['charge_id'] === '') {
            continue;
        }
        if (!isset($seen[$t['charge_id']])) {
            try {
                $seen[$t['charge_id']] = payout_api($api, '/charges/' . rawurlencode($t['charge_id']));
            } catch (Throwable) {
                $seen[$t['charge_id']] = [];
            }
        }
        $c = $seen[$t['charge_id']];
        $t['meta'] = is_array($c['metadata'] ?? null) ? $c['metadata'] : [];
        $t['name'] = $t['name'] ?: (string) ($c['billing_details']['name'] ?? '');
        $t['email'] = $t['email'] ?: (string) ($c['billing_details']['email'] ?? ($c['receipt_email'] ?? ''));
        $t['pi'] = $t['pi'] ?: (string) ($c['payment_intent'] ?? '');
    }
    unset($t);
    return $txns;
}

/** What kind of Hub payment was this? Returns [kind, ids]. @return array{0:string,1:list<int>} */
function payout_categorise(array $txn): array
{
    $meta = $txn['meta'] ?? [];
    $kind = (string) ($meta['kind'] ?? '');
    if ($txn['type'] === 'stripe_fee' || in_array($txn['reporting'], ['fee', 'tax'], true)) {
        return ['stripe_fee', []];
    }
    if (in_array($txn['type'], ['adjustment', 'dispute', 'payment_failure_refund', 'reserve_transaction'], true) || str_contains((string) $txn['reporting'], 'dispute')) {
        return ['adjustment', []];
    }
    if ($kind !== '' && isset(PAYOUT_KIND_LABELS[$kind])) {
        $raw = $meta['order_ids'] ?? ($meta['commerce_order_ids'] ?? ($meta['order_id'] ?? ($meta['booking_id'] ?? '')));
        $ids = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', (string) $raw) ?: []), static fn (int $i): bool => $i > 0));
        return [$kind, $ids];
    }
    if (!empty($meta['agreement_id'])) {
        return ['sponsorship_link', [(int) $meta['agreement_id']]];
    }
    return ['unknown', []];
}

/**
 * Find the Hub record for a payment and check the amount.
 *
 * @return array{label:string,ref:string,url:string,hub_amount:?float,amount_ok:?bool,note:string,order_ids:list<int>,tickets:?float,booking_fee:?float}
 */
function payout_match_hub(PDO $pdo, array $txn, string $kind, array $ids): array
{
    $res = ['label' => PAYOUT_KIND_LABELS[$kind] ?? $kind, 'ref' => '', 'url' => '', 'hub_amount' => null, 'amount_ok' => null, 'note' => '', 'order_ids' => [], 'tickets' => null, 'booking_fee' => null];
    $money = round(abs((int) $txn['gross']) / 100, 2);
    $one = function (string $sql, array $p) use ($pdo) {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };
    try {
        if (in_array($kind, ['event_ticket', 'match_ticket', 'season_ticket'], true) && $ids) {
            $in = implode(',', array_map('intval', $ids));
            $rows = $pdo->query("SELECT id, status, subtotal, total_amount, source FROM orders WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                $res['note'] = 'The Hub has no order with that number.';
                $res['amount_ok'] = false;
                return $res;
            }
            $total = round(array_sum(array_map(static fn ($r) => (float) $r['total_amount'], $rows)), 2);
            $res['order_ids'] = array_map(static fn ($r) => (int) $r['id'], $rows);
            $res['ref'] = 'Order ' . implode(', ', array_map(static fn ($r) => '#' . $r['id'], $rows));
            $res['hub_amount'] = $total;
            if (!$txn['is_refund']) {
                $res['amount_ok'] = abs($total - $money) < 0.005;
                if (!$res['amount_ok']) {
                    $res['note'] = 'The Hub order total is ' . number_format($total, 2) . ' but Stripe took ' . number_format($money, 2) . '.';
                }
            }
            $first = (int) $rows[0]['id'];
            if ($kind === 'event_ticket') {
                $ev = $one('SELECT event_id FROM event_tickets WHERE order_id = :o LIMIT 1', [':o' => $first]);
                $res['url'] = $ev ? 'event_attendees.php?event_id=' . (int) $ev['event_id'] . '&q=' . $first : '';
                $res['tickets'] = round((float) $rows[0]['subtotal'], 2);
                $res['booking_fee'] = round((float) $rows[0]['total_amount'] - (float) $rows[0]['subtotal'], 2);
            } elseif ($kind === 'season_ticket') {
                $res['url'] = 'season_ticket_orders.php?id=' . $first;
            } else {
                $res['url'] = 'ticket_orders.php?id=' . $first;
            }
        } elseif ($kind === 'shop' && $ids) {
            $row = $one('SELECT id, order_ref, status, total FROM shop_orders WHERE id = :id', [':id' => $ids[0]]);
            if ($row) {
                $res['ref'] = 'Shop order ' . $row['order_ref'];
                $res['url'] = 'shop_order.php?id=' . (int) $row['id'];
                $res['hub_amount'] = round((float) $row['total'], 2);
                if (!$txn['is_refund']) {
                    $res['amount_ok'] = abs($res['hub_amount'] - $money) < 0.005;
                    $res['note'] = $res['amount_ok'] ? '' : 'The Hub order total is ' . number_format($res['hub_amount'], 2) . ' but Stripe took ' . number_format($money, 2) . '.';
                }
            } else {
                $res['note'] = 'The Hub has no shop order with that number.';
                $res['amount_ok'] = false;
            }
        } elseif ($kind === 'hospitality' && $ids) {
            $row = $one('SELECT id, total_amount FROM hospitality_bookings WHERE id = :id', [':id' => $ids[0]]);
            $res['ref'] = $row ? 'Hospitality booking #' . $row['id'] : 'Hospitality (record not found)';
            $res['url'] = $row ? 'hospitality_booking.php?id=' . (int) $row['id'] : '';
            $res['amount_ok'] = $row ? null : false;
        } elseif ($kind === 'sponsorship_link' && $ids) {
            $res['ref'] = 'Sponsorship agreement #' . $ids[0];
        } elseif ($ids) {
            $res['ref'] = 'Record #' . implode(', #', $ids);
        } elseif ($kind === 'unknown') {
            $res['note'] = 'Stripe took this payment but it carries nothing the Hub recognises. It may have been made directly in Stripe.';
            $res['amount_ok'] = false;
        }
    } catch (Throwable) {
        $res['note'] = 'Could not look this up in the Hub.';
    }
    return $res;
}

/** Which of up to three manual payouts add up to exactly $target pence? @param array<int,int> $amounts @return list<int>|null keys */
function payout_subset_sum(array $amounts, int $target): ?array
{
    $keys = array_keys($amounts);
    $n = count($keys);
    for ($a = 0; $a < $n; $a++) {
        if ($amounts[$keys[$a]] === $target) {
            return [$keys[$a]];
        }
        for ($b = $a + 1; $b < $n; $b++) {
            if ($amounts[$keys[$a]] + $amounts[$keys[$b]] === $target) {
                return [$keys[$a], $keys[$b]];
            }
            for ($c = $b + 1; $c < $n; $c++) {
                if ($amounts[$keys[$a]] + $amounts[$keys[$b]] + $amounts[$keys[$c]] === $target) {
                    return [$keys[$a], $keys[$b], $keys[$c]];
                }
            }
        }
    }
    return null;
}

/**
 * Build the report for payouts created since $sinceTs.
 *
 * Paid payouts are kept in a small cache (their contents never change). $refresh forces a fresh fetch.
 *
 * @return array<string,mixed>
 */
function payout_build_report(PDO $pdo, int $sinceTs, bool $refresh = false, ?callable $api = null, ?int $now = null): array
{
    ensurePayoutCacheSchema($pdo);
    $now ??= time();
    $payouts = payout_list($sinceTs, $api);
    $matchedOrderIds = [];
    $rows = [];
    $totals = ['received' => 0, 'gross' => 0, 'fees' => 0, 'refunds' => 0, 'unrecognised' => 0, 'unrecognised_amount' => 0, 'mismatched' => 0, 'unexplained' => 0];
    $byKind = [];
    $eventFees = ['booking' => 0.0, 'stripe' => 0.0, 'tickets' => 0.0, 'net' => 0.0, 'count' => 0];

    foreach ($payouts as $po) {
        $row = ['id' => $po['id'], 'status' => (string) $po['status'], 'arrival' => (int) $po['arrival_date'], 'amount' => (int) $po['amount'], 'currency' => strtoupper((string) $po['currency']),
            'method' => (string) ($po['method'] ?? ''), 'automatic' => !empty($po['automatic']), 'lines' => [], 'gross' => 0, 'fees' => 0, 'refunds' => 0, 'net' => 0, 'difference' => 0, 'reconciled' => null, 'note' => ''];
        $txns = null;
        $cached = $pdo->prepare('SELECT status, data_json, fetched_at FROM stripe_payout_cache WHERE payout_id = :id');
        $cached->execute([':id' => $po['id']]);
        $c = $cached->fetch(PDO::FETCH_ASSOC);
        $settled = $po['status'] === 'paid' && (int) $po['arrival_date'] < $now - 2 * 86400;
        $fresh = $c && ($settled || strtotime((string) $c['fetched_at']) > $now - 900);
        if (!$refresh && $c && $fresh) {
            $decoded = json_decode((string) $c['data_json'], true);
            $txns = is_array($decoded) ? $decoded : null;
        }
        if ($txns === null) {
            try {
                $txns = payout_fill_refund_details(payout_transactions((string) $po['id'], $api), $api);
                $pdo->prepare('REPLACE INTO stripe_payout_cache (payout_id, status, data_json, fetched_at) VALUES (:id, :s, :j, NOW())')
                    ->execute([':id' => $po['id'], ':s' => (string) $po['status'], ':j' => json_encode($txns)]);
            } catch (PayoutManualException) {
                $row['note'] = 'Manual payout: Stripe does not list what is inside it, so it cannot be checked line by line.';
                $totals['received'] += $po['status'] === 'paid' ? (int) $po['amount'] : 0;
                $rows[] = $row;
                continue;
            }
        }
        foreach ($txns as $t) {
            [$kind, $ids] = payout_categorise($t);
            $plain = ['label' => PAYOUT_KIND_LABELS[$kind] ?? $kind, 'ref' => '', 'url' => '', 'hub_amount' => null, 'amount_ok' => null, 'note' => '', 'order_ids' => [], 'tickets' => null, 'booking_fee' => null];
            $hub = in_array($kind, ['stripe_fee', 'adjustment'], true) ? $plain : payout_match_hub($pdo, $t, $kind, $ids);
            $line = $t + ['kind' => $kind, 'hub' => $hub];
            $row['lines'][] = $line;
            // Charges in, then (negative) refunds and adjustments, then Stripe's fees out: gross + refunds - fees = net.
            if ($kind === 'stripe_fee') {
                $row['fees'] += -$t['gross'];
            } elseif ($t['is_refund'] || $kind === 'adjustment') {
                $row['refunds'] += $t['gross'];
                $row['fees'] += $t['fee'];
            } else {
                $row['gross'] += $t['gross'];
                $row['fees'] += $t['fee'];
            }
            $row['net'] += $t['net'];
            foreach ($hub['order_ids'] as $oid) {
                $matchedOrderIds[$oid] = true;
            }
            if ($kind === 'unknown') {
                $totals['unrecognised']++;
                $totals['unrecognised_amount'] += $t['gross'];
            }
            if ($hub['amount_ok'] === false && $kind !== 'unknown') {
                $totals['mismatched']++;
            }
            if (!$t['is_refund'] && !in_array($kind, ['stripe_fee', 'adjustment'], true)) {
                $k = $kind;
                $byKind[$k] = ($byKind[$k] ?? ['label' => $hub['label'], 'gross' => 0, 'fees' => 0, 'net' => 0, 'count' => 0]);
                $byKind[$k]['gross'] += $t['gross'];
                $byKind[$k]['fees'] += $t['fee'];
                $byKind[$k]['net'] += $t['net'];
                $byKind[$k]['count']++;
                if ($kind === 'event_ticket' && $hub['tickets'] !== null) {
                    $eventFees['tickets'] += $hub['tickets'];
                    $eventFees['booking'] += (float) $hub['booking_fee'];
                    $eventFees['stripe'] += $t['fee'] / 100;
                    $eventFees['net'] += $t['net'] / 100;
                    $eventFees['count']++;
                }
            }
        }
        $row['difference'] = (int) $po['amount'] - $row['net'];
        $row['reconciled'] = $row['difference'] === 0;
        if ($po['status'] === 'paid') {
            $totals['received'] += (int) $po['amount'];
        }
        $totals['gross'] += $row['gross'];
        $totals['fees'] += $row['fees'];
        $totals['refunds'] += $row['refunds'];
        $rows[] = $row;
    }

    // Stripe also lists, under a later payout, payments that a manual payout had already paid out. If a payout is out
    // by exactly the amount of earlier manual payouts, say so instead of calling it wrong.
    $manual = [];
    foreach ($rows as $i => $r) {
        if (!$r['lines'] && !$r['automatic'] && $r['status'] === 'paid' && $r['amount'] > 0) {
            $manual[$i] = $r['amount'];
        }
    }
    foreach ($rows as $i => &$r) {
        if ($r['reconciled'] === false && $r['difference'] < 0 && $manual) {
            $subset = payout_subset_sum($manual, -$r['difference']);
            if ($subset) {
                $r['reconciled'] = true;
                $r['explained'] = true;
                $names = array_map(static fn (int $k): string => date('j M', $rows[$k]['arrival']) . ' (' . number_format($rows[$k]['amount'] / 100, 2) . ')', $subset);
                $r['note'] = 'Stripe lists payments here that it had already paid out in the manual payout' . (count($names) > 1 ? 's' : '') . ' of ' . implode(', ', $names) . '. Without those this payout adds up.';
            }
        }
        if ($r['reconciled'] === false) {
            $totals['unexplained'] += abs($r['difference']);
        }
    }
    unset($r);

    // Hub payments taken by Stripe that no payout contains.
    $awaiting = [];
    $missing = [];
    try {
        $st = $pdo->prepare("SELECT p.order_id, p.amount, p.paid_at, o.customer_name, o.source FROM payments p JOIN orders o ON o.id = p.order_id
            WHERE p.provider = 'stripe' AND p.status IN ('paid') AND p.amount > 0 AND p.paid_at >= FROM_UNIXTIME(:since)
              AND p.provider_checkout_session_id IS NOT NULL AND p.provider_checkout_session_id <> ''
            ORDER BY p.paid_at DESC");
        $st->execute([':since' => $sinceTs]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (isset($matchedOrderIds[(int) $p['order_id']])) {
                continue;
            }
            $age = $now - strtotime((string) $p['paid_at']);
            $item = ['order_id' => (int) $p['order_id'], 'amount' => (float) $p['amount'], 'paid_at' => (string) $p['paid_at'], 'name' => (string) $p['customer_name'], 'source' => (string) $p['source']];
            if ($age < PAYOUT_EXPECTED_WITHIN_DAYS * 86400) {
                $awaiting[] = $item;
            } else {
                $missing[] = $item;
            }
        }
    } catch (Throwable) {
    }

    return ['since' => $sinceTs, 'generated_at' => $now, 'payouts' => $rows, 'totals' => $totals, 'by_kind' => $byKind, 'event_fees' => $eventFees, 'awaiting' => $awaiting, 'missing' => $missing];
}

/** CSV with one row per transaction. @param array<string,mixed> $report */
function payout_report_csv(array $report): string
{
    $cell = static function ($v): string {
        $s = (string) ($v ?? '');
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $s = "'" . $s;
        }
        return '"' . str_replace('"', '""', $s) . '"';
    };
    $out = implode(',', array_map($cell, ['Payout', 'Arrived', 'Payout amount', 'Date', 'Type', 'What', 'Hub record', 'Customer', 'Gross', 'Stripe fee', 'Net', 'Check'])) . "\r\n";
    foreach ($report['payouts'] as $po) {
        if (!$po['lines']) {
            $out .= implode(',', array_map($cell, [$po['id'], date('Y-m-d', $po['arrival']), number_format($po['amount'] / 100, 2, '.', ''), '', 'payout', $po['note'] ?: 'No detail available', '', '', '', '', '', ''])) . "\r\n";
        }
        foreach ($po['lines'] as $l) {
            $out .= implode(',', array_map($cell, [
                $po['id'], date('Y-m-d', $po['arrival']), number_format($po['amount'] / 100, 2, '.', ''), date('Y-m-d H:i', $l['created']),
                $l['is_refund'] ? 'refund' : $l['type'], $l['hub']['label'], $l['hub']['ref'], trim($l['name'] . ' ' . $l['email']),
                number_format($l['gross'] / 100, 2, '.', ''), number_format($l['fee'] / 100, 2, '.', ''), number_format($l['net'] / 100, 2, '.', ''),
                $l['hub']['amount_ok'] === false ? ($l['hub']['note'] ?: 'CHECK') : '',
            ])) . "\r\n";
        }
    }
    return $out;
}
