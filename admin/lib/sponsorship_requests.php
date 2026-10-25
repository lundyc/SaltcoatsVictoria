<?php

declare(strict_types=1);

/**
 * Sponsorship requests: the public matchday / match ball (and player) pages no longer take
 * payment up front. A visitor sends a request, which lands in the Hub Inbox as a Commercial
 * enquiry. The club vets it; only when it is approved does the visitor get an emailed link to pay.
 *
 * Order status flow:  enquiry -> approved -> paid (Stripe webhook, unchanged)
 *                           \-> declined
 * Nothing holds a game or player while a request is open; approval re-checks the slot is free.
 */

require_once __DIR__ . '/enquiries.php';
require_once __DIR__ . '/match_sponsorship_shop.php';
require_once __DIR__ . '/player_sponsorship_shop.php';

const SPONSORSHIP_REQUEST_REPLY_DAYS = 3;

/** @return array{orders:string,items:string,label:string,pay_path:string} */
function sponsorship_request_kind(string $kind): array
{
    if ($kind === 'match') {
        return ['orders' => 'match_sponsorship_orders', 'items' => 'match_sponsorship_order_items', 'label' => 'matchday / match ball sponsorship', 'pay_path' => '/matchday-sponsorship/pay'];
    }
    if ($kind === 'player') {
        return ['orders' => 'player_sponsorship_orders', 'items' => 'player_sponsorship_order_items', 'label' => 'player sponsorship', 'pay_path' => '/playersponsors/pay'];
    }
    throw new InvalidArgumentException('Unknown sponsorship kind.');
}

/** Make sure the order table has the column that carries a business name through to the sponsor record. */
function sponsorship_request_ensure_columns(PDO $pdo, string $kind): void
{
    $cfg = sponsorship_request_kind($kind);
    if ($kind === 'player') {
        ensurePlayerSponsorshipShopSchema($pdo);
    } else {
        match_sponsorship_ensure_schema($pdo);
    }
    if (!$pdo->query("SHOW COLUMNS FROM {$cfg['orders']} LIKE 'sponsor_display_name'")->fetch()) {
        $pdo->exec("ALTER TABLE {$cfg['orders']} ADD COLUMN sponsor_display_name VARCHAR(190) NULL DEFAULT NULL");
    }
}

/** An order's items, each with a readable 'label' (player orders store the name and package separately). @return list<array<string,mixed>> */
function sponsorship_request_items(PDO $pdo, string $kind, int $orderId): array
{
    $cfg = sponsorship_request_kind($kind);
    $rows = $pdo->query("SELECT * FROM {$cfg['items']} WHERE order_id = " . (int) $orderId . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($kind === 'player') {
        foreach ($rows as &$r) {
            $r['label'] = (string) $r['player_name_snapshot'] . ' - ' . str_replace(' Sponsorship', '', player_sponsorship_shop_package_label((string) $r['package_code']));
        }
        unset($r);
    }
    return $rows;
}

function sponsorship_request_ack_note(): string
{
    return 'We will review your sponsorship request and come back to you within ' . SPONSORSHIP_REQUEST_REPLY_DAYS
        . ' working days. Nothing has been charged, and you will only be asked to pay once the club has approved your request.';
}

/**
 * Save a request: an order in 'enquiry' status plus a Commercial enquiry for the Inbox.
 *
 * @param array<string,mixed> $buyer   name,email,phone,is_business,business_name,address,website_url,consent_*
 * @param list<array<string,mixed>> $items basket summary items
 * @return array{ok:bool,errors:list<string>,ref?:string,token?:string}
 */
function sponsorship_request_submit(PDO $pdo, string $kind, array $buyer, int $seasonId, array $items, string $message): array
{
    $cfg = sponsorship_request_kind($kind);
    enquiry_ensure_schema($pdo);

    sponsorship_request_ensure_columns($pdo, $kind);
    $businessName = trim((string) ($buyer['business_name'] ?? ''));
    $isBusiness = !empty($buyer['is_business']);
    $buyer['is_business'] = $isBusiness;
    try {
        if ($kind === 'player') {
            $created = player_sponsorship_shop_create_order($pdo, $buyer, $seasonId,
                array_map(static fn(array $i): array => ['player_id' => (int) $i['player_id'], 'package_code' => (string) $i['package_code']], $items), 'enquiry');
            $orderId = (int) $created['order_id'];
        } else {
            $orderId = match_sponsorship_create_order($pdo, $buyer, $seasonId, $items, 'enquiry');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => [$e->getMessage()]];
    }
    if ($isBusiness && $businessName !== '') {
        $pdo->prepare('UPDATE ' . $cfg['orders'] . ' SET sponsor_display_name = :n WHERE id = :id')->execute([':n' => $businessName, ':id' => $orderId]);
    }
    $order = $pdo->query('SELECT * FROM ' . $cfg['orders'] . ' WHERE id = ' . $orderId)->fetch(PDO::FETCH_ASSOC);

    $total = 0.0;
    $lines = [];
    foreach ($items as $it) {
        $label = $kind === 'player'
            ? (string) $it['player_name'] . ' - ' . str_replace(' Sponsorship', '', player_sponsorship_shop_package_label((string) $it['package_code']))
            : (string) $it['label'];
        $lines[] = '- ' . $label . ' (guide price ' . gbp((float) $it['price']) . ')';
        $total += (float) $it['price'];
    }
    $text = "Sponsorship request - no payment taken.\n\n" . implode("\n", $lines)
        . "\n\nGuide total: " . gbp($total) . ($isBusiness && $businessName !== '' ? "\nBusiness: $businessName" : '')
        . ($message !== '' ? "\n\nMessage from them:\n$message" : '');

    $interest = $kind === 'player' ? 'Player sponsorship' : 'Match sponsorship';
    $codes = array_unique(array_map(static fn(array $i): string => (string) ($i['package_code'] ?? ''), $items));
    if ($kind === 'match' && $codes === ['match_ball']) {
        $interest = 'Match ball sponsorship';
    }

    $res = enquiry_submit($pdo, 'commercial', [
        'name' => (string) $buyer['name'], 'email' => (string) $buyer['email'], 'phone' => (string) ($buyer['phone'] ?? ''),
        'business' => $isBusiness && $businessName !== '' ? $businessName : 'Individual',
        'interest' => $interest,
        'subject' => ($kind === 'player' ? 'Player sponsorship request: ' : 'Sponsorship request: ') . count($items) . ' item' . (count($items) === 1 ? '' : 's') . ' (' . gbp($total) . ')',
        'message' => $text,
        '_ack_note' => sponsorship_request_ack_note(),
    ]);
    if (!$res['ok']) {
        $pdo->prepare("UPDATE {$cfg['orders']} SET status = 'declined' WHERE id = :id")->execute([':id' => $orderId]);
        return ['ok' => false, 'errors' => $res['errors']];
    }
    $pdo->prepare('UPDATE enquiries SET sponsorship_order_id = :o, sponsorship_kind = :k WHERE ref = :r')
        ->execute([':o' => $orderId, ':k' => $kind, ':r' => $res['ref']]);

    return ['ok' => true, 'errors' => [], 'ref' => (string) $res['ref'], 'token' => (string) $order['access_token']];
}

/** The request attached to an enquiry, if any. @return array{kind:string,order:array<string,mixed>,items:list<array<string,mixed>>}|null */
function sponsorship_request_for_enquiry(PDO $pdo, array $enquiry): ?array
{
    $orderId = (int) ($enquiry['sponsorship_order_id'] ?? 0);
    $kind = (string) ($enquiry['sponsorship_kind'] ?? '');
    if ($orderId <= 0 || $kind === '') {
        return null;
    }
    try {
        $cfg = sponsorship_request_kind($kind);
    } catch (InvalidArgumentException) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM {$cfg['orders']} WHERE id = :id");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        return null;
    }
    return ['kind' => $kind, 'order' => $order, 'items' => sponsorship_request_items($pdo, $kind, $orderId)];
}

/** Is each item's game (or player) still free? @return array<int,string> item id => problem, empty when all clear */
function sponsorship_request_conflicts(PDO $pdo, string $kind, array $order, array $items): array
{
    $problems = [];
    ensureSponsorshipCatalogSchema($pdo);
    foreach ($items as $it) {
        try {
            $package = getSponsorshipPackage($pdo, (int) $it['package_id']);
            if (!$package) {
                throw new RuntimeException('That package no longer exists.');
            }
            if ($kind === 'match') {
                member_sponsorship_assert_slot_open($pdo, $package, null, (int) $it['fixture_id'], (int) $order['season_id']);
            } else {
                member_sponsorship_assert_slot_open($pdo, $package, (int) $it['player_id'], null, (int) $order['season_id']);
            }
        } catch (Throwable $e) {
            $problems[(int) $it['id']] = $e->getMessage();
        }
    }
    return $problems;
}

function sponsorship_request_pay_url(string $kind, string $token): string
{
    $cfg = sponsorship_request_kind($kind);
    return stripe_public_base_url() . $cfg['pay_path'] . '?access=' . rawurlencode($token);
}

/** Wrap plain-text paragraphs in the shop's branded email shell and send. */
function sponsorship_request_send(string $to, string $subject, string $heroTitle, string $text, ?string $buttonUrl = null, string $buttonLabel = ''): bool
{
    $html = '';
    foreach (preg_split("/\n{2,}/", trim($text)) ?: [] as $para) {
        $html .= '<p style="margin:0 0 16px;font-size:16px;color:#4a4046;line-height:1.5;">' . nl2br(h($para)) . '</p>';
    }
    if ($buttonUrl !== null) {
        $html .= '<p style="margin:24px 0;"><a href="' . h($buttonUrl) . '" style="display:inline-block;padding:14px 26px;border-radius:10px;background:#6f1d2b;color:#ffffff;font-weight:700;text-decoration:none;font-size:16px;">'
            . h($buttonLabel) . '</a></p><p style="margin:0 0 8px;font-size:13px;color:#7a6f75;">If the button does not work, copy this link into your browser:<br>' . h($buttonUrl) . '</p>';
    }
    return player_sponsorship_shop_send_mail($to, $subject, player_sponsorship_shop_email_wrapper($subject, $heroTitle, $heroTitle, 'Saltcoats Victoria FC', $html));
}

/**
 * Approve a request: lock in the agreed prices, mark it approved and email the payment link.
 *
 * @param array<int,float> $amounts item id => agreed amount
 * @return array{ok:bool,message:string}
 */
function sponsorship_request_approve(PDO $pdo, string $kind, int $orderId, array $amounts, string $note): array
{
    $cfg = sponsorship_request_kind($kind);
    $order = $pdo->query("SELECT * FROM {$cfg['orders']} WHERE id = " . (int) $orderId)->fetch(PDO::FETCH_ASSOC);
    if (!$order || !in_array((string) $order['status'], ['enquiry', 'approved'], true)) {
        return ['ok' => false, 'message' => 'This request has already been dealt with.'];
    }
    $items = sponsorship_request_items($pdo, $kind, $orderId);
    $problems = sponsorship_request_conflicts($pdo, $kind, $order, $items);
    if ($problems) {
        $names = [];
        foreach ($items as $it) {
            if (isset($problems[(int) $it['id']])) {
                $names[] = $it['label'] . ' (' . $problems[(int) $it['id']] . ')';
            }
        }
        return ['ok' => false, 'message' => 'Not approved: ' . implode('; ', $names)];
    }

    $total = 0.0;
    $upd = $pdo->prepare("UPDATE {$cfg['items']} SET unit_amount = :a WHERE id = :id AND order_id = :o");
    foreach ($items as $it) {
        $amount = round(max(0.0, (float) ($amounts[(int) $it['id']] ?? $it['unit_amount'])), 2);
        $upd->execute([':a' => $amount, ':id' => $it['id'], ':o' => $orderId]);
        $total += $amount;
    }
    if ($total <= 0) {
        return ['ok' => false, 'message' => 'The total is £0. To give a free sponsorship, add it from the Sponsorship area instead, or enter a price here.'];
    }
    $total = round($total, 2);
    $pdo->prepare("UPDATE {$cfg['orders']} SET total_amount = :t, status = 'approved' WHERE id = :id")->execute([':t' => $total, ':id' => $orderId]);

    $order = $pdo->query("SELECT * FROM {$cfg['orders']} WHERE id = " . (int) $orderId)->fetch(PDO::FETCH_ASSOC);
    $items = sponsorship_request_items($pdo, $kind, $orderId);
    $lines = [];
    foreach ($items as $it) {
        $lines[] = '- ' . $it['label'] . ': ' . gbp((float) $it['unit_amount']);
    }
    $first = trim(explode(' ', trim((string) $order['buyer_name']))[0] ?? '');
    $text = 'Hello ' . ($first !== '' ? $first : 'there') . ",\n\nGood news - the club is happy to go ahead with your sponsorship:\n\n" . implode("\n", $lines)
        . "\n\nTotal to pay: " . gbp($total) . ($note !== '' ? "\n\n" . trim($note) : '')
        . "\n\nYou can pay securely by card using the button below. Nothing is taken until you do, and you will get a receipt straight away.";
    $sent = sponsorship_request_send((string) $order['buyer_email'], 'Your sponsorship is approved - ready to pay', 'Your sponsorship is approved', $text,
        sponsorship_request_pay_url($kind, (string) $order['access_token']), 'Pay ' . gbp($total) . ' securely');

    return ['ok' => true, 'message' => $sent ? 'Approved. The payment link has been emailed to ' . $order['buyer_email'] . '.' : 'Approved, but the email could not be sent. Please contact them directly.', 'sent' => $sent, 'total' => $total];
}

/** Decline a request and tell them politely. @return array{ok:bool,message:string} */
function sponsorship_request_decline(PDO $pdo, string $kind, int $orderId, string $reason): array
{
    $cfg = sponsorship_request_kind($kind);
    $order = $pdo->query("SELECT * FROM {$cfg['orders']} WHERE id = " . (int) $orderId)->fetch(PDO::FETCH_ASSOC);
    if (!$order || !in_array((string) $order['status'], ['enquiry', 'approved'], true)) {
        return ['ok' => false, 'message' => 'This request has already been dealt with.'];
    }
    $pdo->prepare("UPDATE {$cfg['orders']} SET status = 'declined' WHERE id = :id")->execute([':id' => $orderId]);
    $first = trim(explode(' ', trim((string) $order['buyer_name']))[0] ?? '');
    $text = 'Hello ' . ($first !== '' ? $first : 'there') . ",\n\nThank you for your interest in sponsoring Saltcoats Victoria FC. Unfortunately we are not able to go ahead with this request."
        . ($reason !== '' ? "\n\n" . trim($reason) : '')
        . "\n\nNothing has been charged. If you would like to talk about other ways to get involved, just reply to this email.\n\nKind regards,\nSaltcoats Victoria FC";
    $sent = sponsorship_request_send((string) $order['buyer_email'], 'Your Saltcoats Victoria FC sponsorship request', 'About your sponsorship request', $text);
    return ['ok' => true, 'message' => $sent ? 'Declined. They have been told by email.' : 'Declined, but the email could not be sent.', 'sent' => $sent];
}
