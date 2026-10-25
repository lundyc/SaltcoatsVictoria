<?php
declare(strict_types=1);

/**
 * Public Matchday / Match Ball sponsorship shop (/matchday-sponsorship): anyone can add games to a
 * basket and pay by card. Mirrors the Sponsor-a-Player shop: a pending order is created at checkout,
 * and the sponsorship agreements are only created once Stripe confirms payment (webhook). An item
 * somebody else bought first is marked conflict_refund_due and is not charged to the agreement.
 */

require_once __DIR__ . '/sponsorship_catalog.php';
require_once __DIR__ . '/member_sponsorship.php';
require_once __DIR__ . '/player_sponsorship_shop.php';   // find_or_create_sponsor + email shell
require_once __DIR__ . '/stripe.php';

const MATCH_SPONSORSHIP_BASKET_KEY = 'match_sponsorship_basket';

function match_sponsorship_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    ensureSponsorshipCatalogSchema($pdo);
    ensureStripeSchema($pdo);
    ensureMemberSponsorshipPackages($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS match_sponsorship_orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        season_id INT UNSIGNED NOT NULL,
        buyer_name VARCHAR(190) NOT NULL,
        buyer_email VARCHAR(190) NOT NULL,
        buyer_phone VARCHAR(60) NULL,
        buyer_is_business TINYINT(1) NOT NULL DEFAULT 0,
        buyer_address VARCHAR(255) NULL,
        buyer_website_url VARCHAR(255) NULL,
        consent_email TINYINT(1) NOT NULL DEFAULT 0,
        consent_sms TINYINT(1) NOT NULL DEFAULT 0,
        consent_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
        consent_at DATETIME NULL,
        sponsor_id INT UNSIGNED NULL,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
        access_token VARCHAR(64) NOT NULL,
        stripe_checkout_session_id VARCHAR(190) NULL,
        receipt_email_sent_at DATETIME NULL,
        paid_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_match_sponsorship_orders_token (access_token),
        KEY idx_match_sponsorship_orders_session (stripe_checkout_session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS match_sponsorship_order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        package_id INT UNSIGNED NOT NULL,
        package_code VARCHAR(60) NOT NULL,
        fixture_id INT UNSIGNED NOT NULL,
        label VARCHAR(190) NOT NULL,
        unit_amount DECIMAL(10,2) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        sponsorship_agreement_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_match_sponsorship_item (order_id, package_id, fixture_id),
        KEY idx_match_sponsorship_item_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $have = $pdo->query("SHOW COLUMNS FROM match_sponsorship_orders LIKE 'sponsor_display_name'")->fetch();
    if (!$have) {
        $pdo->exec('ALTER TABLE match_sponsorship_orders ADD COLUMN sponsor_display_name VARCHAR(190) NULL DEFAULT NULL');
    }
    $done = true;
}

/* ---------------- basket (session) ---------------- */

function match_sponsorship_basket_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION[MATCH_SPONSORSHIP_BASKET_KEY]) || !is_array($_SESSION[MATCH_SPONSORSHIP_BASKET_KEY])) {
        $_SESSION[MATCH_SPONSORSHIP_BASKET_KEY] = [];
    }
}

/** @return list<array{id:string,fixture_id:int,package_code:string}> */
function match_sponsorship_basket_items(): array
{
    match_sponsorship_basket_start();
    return $_SESSION[MATCH_SPONSORSHIP_BASKET_KEY];
}

function match_sponsorship_basket_add(int $fixtureId, string $packageCode): void
{
    match_sponsorship_basket_start();
    foreach ($_SESSION[MATCH_SPONSORSHIP_BASKET_KEY] as $item) {
        if ((int) $item['fixture_id'] === $fixtureId && (string) $item['package_code'] === $packageCode) {
            return;
        }
    }
    $_SESSION[MATCH_SPONSORSHIP_BASKET_KEY][] = ['id' => bin2hex(random_bytes(6)), 'fixture_id' => $fixtureId, 'package_code' => $packageCode];
}

function match_sponsorship_basket_remove(string $itemId): void
{
    match_sponsorship_basket_start();
    $_SESSION[MATCH_SPONSORSHIP_BASKET_KEY] = array_values(array_filter(
        $_SESSION[MATCH_SPONSORSHIP_BASKET_KEY], static fn(array $i): bool => $i['id'] !== $itemId));
}

function match_sponsorship_basket_clear(): void
{
    match_sponsorship_basket_start();
    $_SESSION[MATCH_SPONSORSHIP_BASKET_KEY] = [];
}

/** "fixtureId:packageCode" => true, for "already in basket" checks. */
function match_sponsorship_basket_key_set(): array
{
    $set = [];
    foreach (match_sponsorship_basket_items() as $i) {
        $set[(int) $i['fixture_id'] . ':' . $i['package_code']] = true;
    }
    return $set;
}

/**
 * Re-validates every basket line against live availability (dropping anything taken or past).
 * @return array{items: list<array<string,mixed>>, total: float}
 */
function match_sponsorship_basket_summary(PDO $pdo, int $seasonId): array
{
    match_sponsorship_ensure_schema($pdo);
    $options = [];
    foreach (member_sponsorship_match_options($pdo, $seasonId) as $opt) {
        $open = [];
        foreach ($opt['open_fixtures'] as $f) {
            $open[(int) $f['id']] = $f;
        }
        $options[(string) $opt['package']['code']] = ['package' => $opt['package'], 'open' => $open];
    }
    $items = [];
    $total = 0.0;
    foreach (match_sponsorship_basket_items() as $b) {
        $opt = $options[$b['package_code']] ?? null;
        $f = $opt['open'][(int) $b['fixture_id']] ?? null;
        if (!$opt || !$f) {
            continue;
        }
        $price = member_sponsorship_package_price($pdo, $opt['package'], (int) $f['id']);
        $isHome = (int) $f['is_home'] === 1;
        $items[] = [
            'id' => $b['id'],
            'fixture_id' => (int) $f['id'],
            'package_id' => (int) $opt['package']['id'],
            'package_code' => (string) $opt['package']['code'],
            'package_name' => (string) $opt['package']['name'],
            'label' => (string) $opt['package']['name'] . ' - ' . ($isHome ? 'Home v ' : 'Away at ') . $f['opponent'] . ' (' . date('D j M Y', strtotime((string) $f['match_date'])) . ')',
            'price' => $price,
        ];
        $total += $price;
    }
    return ['items' => $items, 'total' => round($total, 2)];
}

/* ---------------- orders ---------------- */

function match_sponsorship_get_order(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM match_sponsorship_orders WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function match_sponsorship_get_order_by_token(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM match_sponsorship_orders WHERE access_token = :t');
    $stmt->execute([':t' => $token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function match_sponsorship_get_items(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM match_sponsorship_order_items WHERE order_id = :id ORDER BY id');
    $stmt->execute([':id' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @param array{name:string,email:string,phone:string,is_business:bool,address:string,website_url:string,consent_email:bool,consent_sms:bool,consent_whatsapp:bool} $buyer
 * @param list<array<string,mixed>> $summaryItems from match_sponsorship_basket_summary()
 */
function match_sponsorship_create_order(PDO $pdo, array $buyer, int $seasonId, array $summaryItems, string $status = 'pending_payment'): int
{
    match_sponsorship_ensure_schema($pdo);
    if (!$summaryItems) {
        throw new RuntimeException('Your basket is empty (or everything in it has just been taken). Please choose your games again.');
    }
    $total = round(array_sum(array_column($summaryItems, 'price')), 2);
    $any = !empty($buyer['consent_email']) || !empty($buyer['consent_sms']) || !empty($buyer['consent_whatsapp']);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO match_sponsorship_orders
            (season_id, buyer_name, buyer_email, buyer_phone, buyer_is_business, buyer_address, buyer_website_url,
             consent_email, consent_sms, consent_whatsapp, consent_at, total_amount, status, access_token)
            VALUES (:season, :name, :email, :phone, :biz, :addr, :web, :ce, :cs, :cw, :cat, :total, :status, :token)')
            ->execute([
                ':season' => $seasonId, ':name' => $buyer['name'], ':email' => $buyer['email'],
                ':phone' => $buyer['phone'] !== '' ? $buyer['phone'] : null, ':biz' => $buyer['is_business'] ? 1 : 0,
                ':addr' => $buyer['address'] !== '' ? $buyer['address'] : null, ':web' => $buyer['website_url'] !== '' ? $buyer['website_url'] : null,
                ':ce' => !empty($buyer['consent_email']) ? 1 : 0, ':cs' => !empty($buyer['consent_sms']) ? 1 : 0, ':cw' => !empty($buyer['consent_whatsapp']) ? 1 : 0,
                ':cat' => $any ? date('Y-m-d H:i:s') : null, ':total' => $total, ':status' => $status, ':token' => bin2hex(random_bytes(32)),
            ]);
        $orderId = (int) $pdo->lastInsertId();
        $itemStmt = $pdo->prepare('INSERT INTO match_sponsorship_order_items (order_id, package_id, package_code, fixture_id, label, unit_amount)
            VALUES (:o, :p, :code, :f, :label, :amt)');
        foreach ($summaryItems as $it) {
            $itemStmt->execute([':o' => $orderId, ':p' => $it['package_id'], ':code' => $it['package_code'], ':f' => $it['fixture_id'],
                ':label' => mb_substr((string) $it['label'], 0, 190), ':amt' => $it['price']]);
        }
        $pdo->commit();
        return $orderId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function match_sponsorship_stripe_checkout(PDO $pdo, int $orderId, string $successUrl, string $cancelUrl): array
{
    $order = match_sponsorship_get_order($pdo, $orderId);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    $currency = stripe_default_currency();
    $lineItems = [];
    foreach (match_sponsorship_get_items($pdo, $orderId) as $it) {
        if ((float) $it['unit_amount'] <= 0) {
            continue;
        }
        $lineItems[] = ['quantity' => 1, 'price_data' => [
            'currency' => $currency,
            'unit_amount' => stripe_amount_to_minor_units((float) $it['unit_amount'], $currency),
            'product_data' => ['name' => mb_substr((string) $it['label'], 0, 120), 'description' => 'Sponsorship - Saltcoats Victoria FC'],
        ]];
    }
    if (!$lineItems) {
        throw new RuntimeException('Nothing to pay for.');
    }
    $session = stripe_request('POST', '/checkout/sessions', [
        'mode' => 'payment', 'success_url' => $successUrl, 'cancel_url' => $cancelUrl,
        'customer_email' => (string) $order['buyer_email'], 'line_items' => $lineItems,
        'metadata' => ['kind' => 'match_sponsorship', 'order_id' => (string) $orderId],
        'payment_intent_data' => ['metadata' => ['kind' => 'match_sponsorship', 'order_id' => (string) $orderId]],
    ]);
    $pdo->prepare('UPDATE match_sponsorship_orders SET stripe_checkout_session_id = :s WHERE id = :id')
        ->execute([':s' => (string) $session['id'], ':id' => $orderId]);
    return ['url' => (string) $session['url']];
}

function match_sponsorship_handle_checkout_expired(PDO $pdo, array $session): void
{
    $id = (int) ($session['metadata']['order_id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("UPDATE match_sponsorship_orders SET status = 'expired' WHERE id = :id AND status = 'pending_payment'")->execute([':id' => $id]);
    }
}

function match_sponsorship_handle_checkout_completed(PDO $pdo, array $session): void
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '' || (string) ($session['payment_status'] ?? '') !== 'paid') {
        return;
    }
    $orderId = (int) ($session['metadata']['order_id'] ?? 0);
    $order = $orderId > 0 ? match_sponsorship_get_order($pdo, $orderId) : null;
    if (!$order) {
        error_log('[match_sponsorship] Paid session ' . $sessionId . ' has no matching order.');
        return;
    }
    if (in_array((string) $order['status'], ['paid', 'partially_conflicted'], true)) {
        return; // webhook retry
    }
    ensureSponsorshipCatalogSchema($pdo);
    $paymentIntentId = (string) ($session['payment_intent'] ?? '') ?: ('unknown_' . $sessionId);
    $currency = (string) ($session['currency'] ?? 'gbp');

    $sponsorId = player_sponsorship_shop_find_or_create_sponsor($pdo, $order);
    $pdo->prepare('UPDATE match_sponsorship_orders SET sponsor_id = :s WHERE id = :id')->execute([':s' => $sponsorId, ':id' => $orderId]);

    $anyConfirmed = false;
    $anyConflict = false;
    foreach (match_sponsorship_get_items($pdo, $orderId) as $item) {
        if ((string) $item['status'] !== 'pending') {
            $anyConfirmed = $anyConfirmed || (string) $item['status'] === 'confirmed';
            $anyConflict = $anyConflict || (string) $item['status'] === 'conflict_refund_due';
            continue;
        }
        $pdo->beginTransaction();
        try {
            $package = getSponsorshipPackage($pdo, (int) $item['package_id']);
            if (!$package) {
                throw new RuntimeException('Package missing');
            }
            member_sponsorship_assert_slot_open($pdo, $package, null, (int) $item['fixture_id'], (int) $order['season_id']);
            $agreementId = member_sponsorship_create_agreement($pdo, $sponsorId, $package, null, (int) $item['fixture_id'], (int) $order['season_id'],
                'match_sponsorship_shop (order #' . $orderId . ', session ' . $sessionId . ')');
            $pdo->prepare('INSERT INTO stripe_transactions
                (agreement_id, payment_link_id, stripe_payment_intent_id, stripe_checkout_session_id, amount, currency, status, raw_payload)
                VALUES (:a, NULL, :pi, :sid, :amt, :cur, \'succeeded\', :raw)')
                ->execute([':a' => $agreementId, ':pi' => $paymentIntentId, ':sid' => $sessionId, ':amt' => $item['unit_amount'], ':cur' => $currency, ':raw' => json_encode($session)]);
            $agreement = getSponsorshipAgreement($pdo, $agreementId);
            if ($agreement) {
                stripe_record_agreement_payment($pdo, $agreement, (float) $item['unit_amount'], 'Stripe payment (match sponsorship shop, order #' . $orderId . ')');
            }
            $pdo->prepare("UPDATE match_sponsorship_order_items SET status = 'confirmed', sponsorship_agreement_id = :a WHERE id = :id")
                ->execute([':a' => $agreementId, ':id' => $item['id']]);
            $pdo->commit();
            $anyConfirmed = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->prepare("UPDATE match_sponsorship_order_items SET status = 'conflict_refund_due' WHERE id = :id")->execute([':id' => $item['id']]);
            $anyConflict = true;
            error_log('[match_sponsorship] Conflict: order #' . $orderId . ' item #' . $item['id'] . ': ' . $e->getMessage());
        }
    }
    $pdo->prepare('UPDATE match_sponsorship_orders SET status = :st, paid_at = NOW() WHERE id = :id')
        ->execute([':st' => ($anyConfirmed && !$anyConflict) ? 'paid' : 'partially_conflicted', ':id' => $orderId]);

    match_sponsorship_send_receipt($pdo, $orderId);
    stripe_send_payment_notification($pdo, 'Match sponsorship shop', (string) $order['buyer_name'], (string) $order['buyer_email'],
        (float) $order['total_amount'], 'Match sponsorship order #' . $orderId, stripe_public_base_url() . '/admin/sponsor_orders.php');
}

function match_sponsorship_send_receipt(PDO $pdo, int $orderId): bool
{
    $order = match_sponsorship_get_order($pdo, $orderId);
    if (!$order || !empty($order['receipt_email_sent_at']) || !filter_var((string) $order['buyer_email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $items = match_sponsorship_get_items($pdo, $orderId);
    $rows = '';
    $charged = 0.0;
    $conflicts = [];
    foreach ($items as $it) {
        if ((string) $it['status'] === 'confirmed') {
            $charged += (float) $it['unit_amount'];
            $rows .= '<tr><td style="padding:10px 0;border-bottom:1px solid #eadfdf;font-weight:700;">' . h((string) $it['label']) . '</td><td style="padding:10px 0;border-bottom:1px solid #eadfdf;text-align:right;font-weight:700;">' . h(gbp((float) $it['unit_amount'])) . '</td></tr>';
        } elseif ((string) $it['status'] === 'conflict_refund_due') {
            $conflicts[] = (string) $it['label'];
        }
    }
    $body = '<p style="margin:0 0 16px;font-size:16px;">Hi ' . h((string) $order['buyer_name']) . ',</p>'
        . '<p style="margin:0 0 22px;font-size:16px;color:#4a4046;">Thank you for sponsoring with Saltcoats Victoria FC. Here is your receipt for order #' . $orderId . '.</p>'
        . ($rows !== '' ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 16px;">' . $rows . '</table><p style="margin:0;color:#4b0818;font-size:18px;font-weight:900;">Total charged: ' . h(gbp($charged)) . '</p>' : '<p>None of the games in this order could be completed, so you have not been charged.</p>')
        . ($conflicts ? '<div style="margin-top:18px;padding:14px 16px;border-radius:12px;background:#fff4e5;color:#7a4a06;font-size:14px;">Someone else sponsored these just before your payment: ' . h(implode('; ', $conflicts)) . '. The club will be in touch about a refund or an alternative.</div>' : '')
        . '<p style="margin:22px 0 0;font-size:15px;color:#4a4046;">We will be in touch about your sponsorship details, such as your logo.</p>';
    $subject = 'Your Saltcoats Victoria FC sponsorship receipt';
    $sent = player_sponsorship_shop_send_mail((string) $order['buyer_email'], $subject,
        player_sponsorship_shop_email_wrapper($subject, 'Your match sponsorship receipt.', 'Payment receipt', 'Order #' . $orderId, $body));
    if ($sent) {
        $pdo->prepare('UPDATE match_sponsorship_orders SET receipt_email_sent_at = NOW() WHERE id = :id')->execute([':id' => $orderId]);
    }
    return $sent;
}
