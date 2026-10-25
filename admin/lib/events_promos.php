<?php
declare(strict_types=1);

/**
 * Event promo and invite codes.
 *
 *  percent : a percentage off each ticket (optionally only one ticket type)
 *  amount  : a fixed amount off each ticket (optionally only one ticket type)
 *  unlock  : releases a hidden, code-only ticket type (invites for sponsors, committee, speakers' guests)
 *
 * Discounts are per ticket, so refunds and the sales dashboard stay exact. A code never stacks with the member
 * discount: the buyer gets whichever price is lower. The booking fee is worked out on the discounted total.
 */

require_once __DIR__ . '/events.php';

const EVENT_PROMO_KINDS = [
    'percent' => 'Percentage off',
    'amount' => 'Amount off each ticket',
    'unlock' => 'Unlock a hidden ticket',
];

function events_promo_normalise(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', $code) ?? '');
}

function events_promo_find(PDO $pdo, int $eventId, string $code): ?array
{
    ensureEventsSchema($pdo);
    $code = events_promo_normalise($code);
    if ($code === '') {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM event_promo_codes WHERE event_id = :e AND code = :c');
    $st->execute([':e' => $eventId, ':c' => $code]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Orders using this code: paid ones and unpaid ones whose hold is still running. */
function events_promo_uses(PDO $pdo, int $promoId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM event_order_promos p JOIN orders o ON o.id = p.order_id LEFT JOIN event_order_holds h ON h.order_id = o.id
        WHERE p.promo_id = :id AND (o.status IN ('paid', 'complete', 'partially_refunded') OR (o.status = 'pending_payment' AND h.state = 'held' AND h.expires_at > NOW()))");
    $st->execute([':id' => $promoId]);
    return (int) $st->fetchColumn();
}

/** Why a code cannot be used right now, or '' if it can. */
function events_promo_problem(array $promo, int $uses, ?int $now = null): string
{
    $now ??= time();
    if ((int) $promo['is_active'] !== 1) {
        return 'this code is not active.';
    }
    if (!empty($promo['valid_from']) && $now < strtotime((string) $promo['valid_from'])) {
        return 'this code is not valid yet.';
    }
    if (!empty($promo['valid_to']) && $now > strtotime((string) $promo['valid_to'])) {
        return 'this code has expired.';
    }
    if ($promo['max_uses'] !== null && $promo['max_uses'] !== '' && $uses >= (int) $promo['max_uses']) {
        return 'this code has been used up.';
    }
    return '';
}

/** Does this code take money off the given ticket type? */
function events_promo_applies_to(array $promo, array $type): bool
{
    if (!in_array($promo['kind'], ['percent', 'amount'], true)) {
        return false;
    }
    return empty($promo['ticket_type_id']) || (int) $promo['ticket_type_id'] === (int) $type['id'];
}

/** Does this code release the given hidden ticket type? */
function events_promo_unlocks(array $promo, array $type): bool
{
    return $promo['kind'] === 'unlock' && (int) $promo['unlock_type_id'] === (int) $type['id'];
}

/** A list price after the code. */
function events_promo_price(float $list, array $promo): float
{
    if ($list <= 0) {
        return 0.0;
    }
    if ($promo['kind'] === 'percent') {
        return round(max(0.0, $list * (1 - (float) $promo['value'] / 100)), 2);
    }
    if ($promo['kind'] === 'amount') {
        return round(max(0.0, $list - (float) $promo['value']), 2);
    }
    return $list;
}

/** What one ticket costs this buyer: the lowest of the list price, the member price and the code price. */
function events_final_unit_price(array $type, bool $isMember, ?array $promo): float
{
    $price = events_unit_price($type, $isMember);
    if ($promo && events_promo_applies_to($promo, $type)) {
        $price = min($price, events_promo_price((float) $type['price'], $promo));
    }
    return round($price, 2);
}

/** @return list<array<string,mixed>> codes for an event with how many times each has been used */
function events_promos(PDO $pdo, int $eventId): array
{
    ensureEventsSchema($pdo);
    $st = $pdo->prepare('SELECT c.*, t.name AS type_name, u.name AS unlock_name FROM event_promo_codes c
        LEFT JOIN event_ticket_types t ON t.id = c.ticket_type_id LEFT JOIN event_ticket_types u ON u.id = c.unlock_type_id WHERE c.event_id = :e ORDER BY c.is_active DESC, c.id DESC');
    $st->execute([':e' => $eventId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['uses'] = events_promo_uses($pdo, (int) $r['id']);
    }
    return $rows;
}

/**
 * @param array<string,mixed> $data
 * @throws RuntimeException on invalid input
 */
function events_save_promo(PDO $pdo, int $eventId, ?int $id, array $data): int
{
    ensureEventsSchema($pdo);
    $code = events_promo_normalise((string) ($data['code'] ?? ''));
    if (strlen($code) < 3 || strlen($code) > 30) {
        throw new RuntimeException('The code must be 3 to 30 letters, numbers, dashes or underscores.');
    }
    $kind = (string) ($data['kind'] ?? '');
    if (!isset(EVENT_PROMO_KINDS[$kind])) {
        throw new RuntimeException('Choose what the code does.');
    }
    $value = (float) ($data['value'] ?? 0);
    $unlock = (int) ($data['unlock_type_id'] ?? 0);
    $typeId = (int) ($data['ticket_type_id'] ?? 0);
    if ($kind === 'percent' && ($value < 1 || $value > 100)) {
        throw new RuntimeException('A percentage code needs a value from 1 to 100.');
    }
    if ($kind === 'amount' && ($value <= 0 || $value > 1000)) {
        throw new RuntimeException('An amount-off code needs an amount above 0.');
    }
    $types = [];
    foreach (events_ticket_types($pdo, $eventId) as $t) {
        $types[(int) $t['id']] = $t;
    }
    if ($kind === 'unlock') {
        if (!isset($types[$unlock]) || $types[$unlock]['visibility'] !== 'hidden') {
            throw new RuntimeException('Choose the hidden (code-only) ticket this code unlocks. Set a ticket type to "Hidden (code only)" first.');
        }
        $value = 0.0;
        $typeId = 0;
    } else {
        $unlock = 0;
        if ($typeId > 0 && !isset($types[$typeId])) {
            throw new RuntimeException('That ticket type is not part of this event.');
        }
    }
    $max = trim((string) ($data['max_uses'] ?? ''));
    if ($max !== '' && (!ctype_digit($max) || (int) $max < 1)) {
        throw new RuntimeException('Maximum uses must be blank (no limit) or a whole number of at least 1.');
    }
    $from = events_parse_datetime((string) ($data['valid_from'] ?? ''));
    $to = events_parse_datetime((string) ($data['valid_to'] ?? ''));
    if ($from !== null && $to !== null && $to <= $from) {
        throw new RuntimeException('The code must end after it starts.');
    }
    $dupe = $pdo->prepare('SELECT id FROM event_promo_codes WHERE event_id = :e AND code = :c');
    $dupe->execute([':e' => $eventId, ':c' => $code]);
    $existing = $dupe->fetchColumn();
    if ($existing && (int) $existing !== (int) $id) {
        throw new RuntimeException('This event already has a code called ' . $code . '.');
    }
    $params = [':code' => $code, ':kind' => $kind, ':value' => $value, ':type' => $typeId > 0 ? $typeId : null, ':unlock' => $unlock > 0 ? $unlock : null,
        ':max' => $max !== '' ? (int) $max : null, ':from' => $from, ':to' => $to, ':active' => !empty($data['is_active']) ? 1 : 0,
        ':note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 200) ?: null];
    if ($id) {
        $pdo->prepare('UPDATE event_promo_codes SET code = :code, kind = :kind, value = :value, ticket_type_id = :type, unlock_type_id = :unlock, max_uses = :max,
            valid_from = :from, valid_to = :to, is_active = :active, note = :note WHERE id = :id AND event_id = :e')->execute($params + [':id' => $id, ':e' => $eventId]);
        return $id;
    }
    $pdo->prepare('INSERT INTO event_promo_codes (event_id, code, kind, value, ticket_type_id, unlock_type_id, max_uses, valid_from, valid_to, is_active, note)
        VALUES (:e, :code, :kind, :value, :type, :unlock, :max, :from, :to, :active, :note)')->execute($params + [':e' => $eventId]);
    return (int) $pdo->lastInsertId();
}
