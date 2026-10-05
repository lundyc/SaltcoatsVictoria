<?php

declare(strict_types=1);

// Public player kit sponsorship shop — data model, availability/pricing,
// order lifecycle, Stripe checkout + webhook handling, and the two
// idempotent confirmation emails. See hub/playersponsors*.php for the pages
// that use this, and lib/member_sponsorship.php / lib/match_tickets.php for
// the precedents this deliberately mirrors.

require_once __DIR__ . '/sponsorship_catalog.php';
require_once __DIR__ . '/season.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/mailer.php';

/**
 * Idempotent schema guard, same CREATE TABLE IF NOT EXISTS idiom as
 * ensureSponsorshipCatalogSchema() / ensureMatchTicketSchema().
 */
function ensurePlayerSponsorshipShopSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    ensureSponsorshipCatalogSchema($pdo);
    ensureStripeSchema($pdo);
    $done = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS player_sponsorship_orders (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        season_id INT UNSIGNED NOT NULL,
        buyer_name VARCHAR(190) NOT NULL,
        buyer_email VARCHAR(190) NOT NULL,
        buyer_phone VARCHAR(60) NULL,
        buyer_is_business TINYINT(1) NOT NULL DEFAULT 0,
        buyer_address VARCHAR(255) NULL,
        buyer_website_url VARCHAR(255) NULL,
        sponsor_id INT UNSIGNED NULL,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(30) NOT NULL DEFAULT 'pending_payment',
        access_token VARCHAR(64) NOT NULL,
        stripe_checkout_session_id VARCHAR(190) NULL,
        receipt_email_sent_at DATETIME NULL,
        thankyou_email_sent_at DATETIME NULL,
        paid_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_player_sponsorship_orders_token (access_token),
        KEY idx_player_sponsorship_orders_session (stripe_checkout_session_id),
        CONSTRAINT fk_player_sponsorship_orders_season FOREIGN KEY (season_id) REFERENCES seasons (id),
        CONSTRAINT fk_player_sponsorship_orders_sponsor FOREIGN KEY (sponsor_id) REFERENCES sponsors (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS player_sponsorship_order_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT UNSIGNED NOT NULL,
        player_id INT UNSIGNED NOT NULL,
        package_id INT UNSIGNED NOT NULL,
        package_code VARCHAR(60) NOT NULL,
        player_name_snapshot VARCHAR(120) NOT NULL,
        unit_amount DECIMAL(10,2) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'pending',
        sponsorship_agreement_id INT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_player_sponsorship_order_items_combo (order_id, player_id, package_id),
        KEY idx_player_sponsorship_order_items_player (player_id, package_id),
        CONSTRAINT fk_player_sponsorship_order_items_order FOREIGN KEY (order_id) REFERENCES player_sponsorship_orders (id) ON DELETE CASCADE,
        CONSTRAINT fk_player_sponsorship_order_items_player FOREIGN KEY (player_id) REFERENCES players (id),
        CONSTRAINT fk_player_sponsorship_order_items_package FOREIGN KEY (package_id) REFERENCES packages (id),
        CONSTRAINT fk_player_sponsorship_order_items_agreement FOREIGN KEY (sponsorship_agreement_id) REFERENCES sponsorship_agreements (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Active player-scope packages on sale (currently player_home/player_away —
 * player_third is seeded but is_active=0, so it's excluded automatically
 * without any hardcoded code list to keep in sync).
 *
 * @return list<array<string, mixed>>
 */
function player_sponsorship_shop_packages(PDO $pdo): array
{
    return getSponsorshipPackages($pdo, true, 'player');
}

/**
 * The real, season-specific price for a player package — NOT packages.amount,
 * which is only a seed default and can diverge from what the season is
 * actually configured to charge (getSeasonPlayerPricing() reads
 * seasons.player_home_amount/player_away_amount/player_third_amount).
 */
function player_sponsorship_shop_package_price(array $package, array $seasonPricing): float
{
    $slot = str_replace('player_', '', (string) $package['code']);
    $key = 'player_' . $slot . '_amount';
    if (isset($seasonPricing[$key])) {
        return (float) $seasonPricing[$key];
    }
    return (float) $package['amount'];
}

function player_sponsorship_shop_package_label(string $packageCode): string
{
    $slot = str_replace('player_', '', $packageCode);
    return ucfirst($slot) . ' Kit Sponsorship';
}

function player_sponsorship_shop_avatar_url(?string $avatar): string
{
    return $avatar ? '/uploads/players/' . rawurlencode($avatar) : '';
}

/**
 * Every current, active player with their Home/Away availability and price
 * for the given season. Players filtered the same way the real squad admin
 * page does (players.php: status='current' AND active=1) — deliberately
 * stricter than lib/member_sponsorship.php's active=1-only query, which
 * would wrongly include trialists/loan/injured players in a public shop.
 *
 * @return list<array{id: int, name: string, avatar_url: string, packages: list<array{package_id: int, package_code: string, package_name: string, price: float, available: bool}>}>
 */
function player_sponsorship_shop_players_with_availability(PDO $pdo, int $seasonId): array
{
    ensurePlayerSponsorshipShopSchema($pdo);
    $packages = player_sponsorship_shop_packages($pdo);
    if (!$packages || $seasonId <= 0) {
        return [];
    }
    $pricing = getSeasonPlayerPricing($pdo, $seasonId);

    $takenByPackage = [];
    foreach ($packages as $package) {
        $stmt = $pdo->prepare("SELECT player_id FROM sponsorship_agreements WHERE package_id = :package AND season_id = :season AND status IN ('active','scheduled')");
        $stmt->execute([':package' => $package['id'], ':season' => $seasonId]);
        $takenByPackage[(int) $package['id']] = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    $players = $pdo->query("SELECT id, name, avatar FROM players WHERE status = 'current' AND active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($players as $player) {
        $playerId = (int) $player['id'];
        $packageStates = [];
        foreach ($packages as $package) {
            $packageStates[] = [
                'package_id' => (int) $package['id'],
                'package_code' => (string) $package['code'],
                'package_name' => (string) $package['name'],
                'price' => player_sponsorship_shop_package_price($package, $pricing),
                'available' => !in_array($playerId, $takenByPackage[(int) $package['id']], true),
            ];
        }
        $out[] = [
            'id' => $playerId,
            'name' => (string) $player['name'],
            'avatar_url' => player_sponsorship_shop_avatar_url($player['avatar'] ?? null),
            'packages' => $packageStates,
        ];
    }

    return $out;
}

/**
 * Pre-creates the pending order + line items at checkout-submit time (unlike
 * lib/member_sponsorship.php's single-item flow, a real multi-item cart needs
 * a real row the webhook can look up by Stripe session id — see the
 * design notes in lib/member_sponsorship.php and this feature's plan).
 * Re-validates each basket line against live availability one more time
 * (soft check, not a lock — the webhook is still the authority) and silently
 * drops anything gone stale rather than failing the whole order.
 *
 * @param array{name: string, email: string, phone: string, is_business: bool, address: string, website_url: string} $buyer
 * @param list<array{player_id: int, package_code: string}> $basketItems
 * @return array{order_id: int, dropped: list<array<string, mixed>>}
 */
function player_sponsorship_shop_create_order(PDO $pdo, array $buyer, int $seasonId, array $basketItems): array
{
    ensurePlayerSponsorshipShopSchema($pdo);
    if (!$basketItems) {
        throw new RuntimeException('Your basket is empty.');
    }

    $playersById = [];
    foreach (player_sponsorship_shop_players_with_availability($pdo, $seasonId) as $player) {
        $playersById[$player['id']] = $player;
    }

    $rows = [];
    $dropped = [];
    $total = 0.0;
    foreach ($basketItems as $item) {
        $playerId = (int) $item['player_id'];
        $packageCode = (string) $item['package_code'];
        $player = $playersById[$playerId] ?? null;
        $packageState = null;
        if ($player) {
            foreach ($player['packages'] as $candidate) {
                if ($candidate['package_code'] === $packageCode) {
                    $packageState = $candidate;
                    break;
                }
            }
        }
        if (!$player || !$packageState || !$packageState['available']) {
            $dropped[] = $item;
            continue;
        }
        $rows[] = [
            'player_id' => $playerId,
            'player_name' => $player['name'],
            'package_id' => $packageState['package_id'],
            'package_code' => $packageCode,
            'unit_amount' => $packageState['price'],
        ];
        $total += $packageState['price'];
    }

    if (!$rows) {
        throw new RuntimeException('Sorry, everything in your basket has just been sponsored by someone else. Please choose different players.');
    }

    $accessToken = bin2hex(random_bytes(32));

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO player_sponsorship_orders
            (season_id, buyer_name, buyer_email, buyer_phone, buyer_is_business, buyer_address, buyer_website_url, total_amount, status, access_token)
            VALUES (:season_id, :name, :email, :phone, :is_business, :address, :website, :total, :status, :token)');
        $stmt->execute([
            ':season_id' => $seasonId,
            ':name' => $buyer['name'],
            ':email' => $buyer['email'],
            ':phone' => $buyer['phone'] !== '' ? $buyer['phone'] : null,
            ':is_business' => $buyer['is_business'] ? 1 : 0,
            ':address' => $buyer['address'] !== '' ? $buyer['address'] : null,
            ':website' => $buyer['website_url'] !== '' ? $buyer['website_url'] : null,
            ':total' => round($total, 2),
            ':status' => 'pending_payment',
            ':token' => $accessToken,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare("INSERT INTO player_sponsorship_order_items
            (order_id, player_id, package_id, package_code, player_name_snapshot, unit_amount, status)
            VALUES (:order_id, :player_id, :package_id, :package_code, :player_name, :amount, 'pending')");
        foreach ($rows as $row) {
            $itemStmt->execute([
                ':order_id' => $orderId,
                ':player_id' => $row['player_id'],
                ':package_id' => $row['package_id'],
                ':package_code' => $row['package_code'],
                ':player_name' => $row['player_name'],
                ':amount' => $row['unit_amount'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['order_id' => $orderId, 'dropped' => $dropped];
}

function player_sponsorship_shop_get_order(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM player_sponsorship_orders WHERE id = :id');
    $stmt->execute([':id' => $orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function player_sponsorship_shop_get_order_by_access_token(PDO $pdo, string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM player_sponsorship_orders WHERE access_token = :token');
    $stmt->execute([':token' => $token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * @return list<array<string, mixed>>
 */
function player_sponsorship_shop_get_order_items(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM player_sponsorship_order_items WHERE order_id = :id ORDER BY id');
    $stmt->execute([':id' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function player_sponsorship_shop_attach_stripe_session(PDO $pdo, int $orderId, string $sessionId): void
{
    $pdo->prepare('UPDATE player_sponsorship_orders SET stripe_checkout_session_id = :session_id WHERE id = :id')
        ->execute([':session_id' => $sessionId, ':id' => $orderId]);
}

/**
 * One Stripe Checkout Session covering every line item in the order — the
 * genuine multi-item pattern (see match_ticket_stripe_create_checkout_session()
 * in lib/match_tickets.php), unlike lib/member_sponsorship.php which is
 * single-item-only for card payment.
 *
 * @return array{url: string, session_id: string}
 */
function player_sponsorship_shop_stripe_create_checkout_session(PDO $pdo, int $orderId, string $successUrl, string $cancelUrl): array
{
    $order = player_sponsorship_shop_get_order($pdo, $orderId);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    $items = player_sponsorship_shop_get_order_items($pdo, $orderId);
    $currency = stripe_default_currency();
    $lineItems = [];
    foreach ($items as $item) {
        if ((float) $item['unit_amount'] <= 0) {
            continue;
        }
        $lineItems[] = [
            'quantity' => 1,
            'price_data' => [
                'currency' => $currency,
                'unit_amount' => stripe_amount_to_minor_units((float) $item['unit_amount'], $currency),
                'product_data' => [
                    'name' => player_sponsorship_shop_package_label((string) $item['package_code']) . ' — ' . (string) $item['player_name_snapshot'],
                    'description' => 'Player kit sponsorship — Saltcoats Victoria FC',
                ],
            ],
        ];
    }
    if ($lineItems === []) {
        throw new RuntimeException('This order has no chargeable items.');
    }

    $metadata = ['kind' => 'player_sponsorship_shop', 'order_id' => (string) $orderId];
    $session = stripe_request('POST', '/checkout/sessions', [
        'mode' => 'payment',
        'success_url' => $successUrl,
        'cancel_url' => $cancelUrl,
        'customer_email' => (string) $order['buyer_email'],
        'line_items' => $lineItems,
        'metadata' => $metadata,
        'payment_intent_data' => ['metadata' => $metadata],
    ]);

    player_sponsorship_shop_attach_stripe_session($pdo, $orderId, (string) $session['id']);
    return ['url' => (string) $session['url'], 'session_id' => (string) $session['id']];
}

/**
 * Find-or-create the sponsors row for a checkout buyer, matched by contact
 * email (sponsors.name is UNIQUE, so an insert-only approach would crash for
 * a repeat buyer sharing a business/display name with an unrelated sponsor).
 */
function player_sponsorship_shop_find_or_create_sponsor(PDO $pdo, array $order): int
{
    $email = trim((string) $order['buyer_email']);
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id FROM sponsors WHERE contact_email = :email ORDER BY id LIMIT 1');
        $stmt->execute([':email' => $email]);
        $existingId = $stmt->fetchColumn();
        if ($existingId) {
            return (int) $existingId;
        }
    }

    $name = trim((string) $order['buyer_name']);
    $insert = $pdo->prepare('INSERT INTO sponsors (name, contact_email, contact_phone, is_business, address, website_url, is_active)
        VALUES (:name, :email, :phone, :is_business, :address, :website, 1)');
    $params = [
        ':email' => $email,
        ':phone' => (string) ($order['buyer_phone'] ?? ''),
        ':is_business' => (int) $order['buyer_is_business'],
        ':address' => $order['buyer_address'],
        ':website' => $order['buyer_website_url'],
    ];

    $suffix = 1;
    $candidateName = $name;
    while (true) {
        try {
            $insert->execute($params + [':name' => $candidateName]);
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
            // A different sponsor already has this exact name — disambiguate
            // rather than failing the whole webhook over a display-name clash.
            $suffix++;
            $candidateName = $name . ' (' . $suffix . ')';
        }
    }
}

/**
 * The webhook entry point for checkout.session.expired — nothing was
 * reserved, so there's nothing to undo, just mark the order so it stops
 * showing as "awaiting payment" forever.
 */
function player_sponsorship_shop_stripe_handle_checkout_expired(PDO $pdo, array $session): void
{
    $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
    $orderId = (int) ($metadata['order_id'] ?? 0);
    if ($orderId <= 0) {
        return;
    }
    $order = player_sponsorship_shop_get_order($pdo, $orderId);
    if (!$order || (string) $order['status'] !== 'pending_payment') {
        return;
    }
    $pdo->prepare("UPDATE player_sponsorship_orders SET status = 'expired' WHERE id = :id")->execute([':id' => $orderId]);
}

/**
 * The webhook entry point for checkout.session.completed. Per item, re-checks
 * the authoritative slot-taken query (the same one saveSponsorshipAgreement()'s
 * slot guard uses) inside its own small transaction, so one conflicted item
 * can never roll back a sibling that succeeded. A conflicted item is marked
 * conflict_refund_due — no agreement is created and no automatic Stripe
 * refund is attempted (Colin follows up manually via the Stripe dashboard;
 * see playersponsors_orders.php). applySponsorshipAgreementToLegacy() itself
 * throws on a second, independent race (two webhooks interleaving) — that's
 * caught and treated the same as a conflict.
 */
function player_sponsorship_shop_stripe_handle_checkout_completed(PDO $pdo, array $session): void
{
    $sessionId = (string) ($session['id'] ?? '');
    if ($sessionId === '' || (string) ($session['payment_status'] ?? '') !== 'paid') {
        return;
    }

    $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
    $orderId = (int) ($metadata['order_id'] ?? 0);
    if ($orderId <= 0) {
        error_log('[player_sponsorship_shop] Paid session ' . $sessionId . ' is missing order_id metadata.');
        return;
    }

    $order = player_sponsorship_shop_get_order($pdo, $orderId);
    if (!$order) {
        error_log('[player_sponsorship_shop] Paid session ' . $sessionId . ' references missing order ' . $orderId);
        return;
    }
    if (in_array((string) $order['status'], ['paid', 'partially_conflicted'], true)) {
        return; // Already processed (webhook retry).
    }

    ensureSponsorshipCatalogSchema($pdo);
    $paymentIntentId = (string) ($session['payment_intent'] ?? '');
    $currency = (string) ($session['currency'] ?? 'gbp');

    $sponsorId = player_sponsorship_shop_find_or_create_sponsor($pdo, $order);
    $pdo->prepare('UPDATE player_sponsorship_orders SET sponsor_id = :sponsor_id WHERE id = :id')
        ->execute([':sponsor_id' => $sponsorId, ':id' => $orderId]);

    $items = player_sponsorship_shop_get_order_items($pdo, $orderId);
    $anyConfirmed = false;
    $anyConflict = false;

    foreach ($items as $item) {
        if ((string) $item['status'] !== 'pending') {
            // Already handled by an earlier (retried) delivery of this webhook.
            if ((string) $item['status'] === 'confirmed') {
                $anyConfirmed = true;
            } elseif ((string) $item['status'] === 'conflict_refund_due') {
                $anyConflict = true;
            }
            continue;
        }

        $pdo->beginTransaction();
        try {
            $slotCheck = $pdo->prepare("SELECT COUNT(*) FROM sponsorship_agreements WHERE package_id = :package AND player_id = :player AND season_id = :season AND status IN ('active','scheduled')");
            $slotCheck->execute([
                ':package' => $item['package_id'],
                ':player' => $item['player_id'],
                ':season' => $order['season_id'],
            ]);
            if ((int) $slotCheck->fetchColumn() > 0) {
                throw new RuntimeException('Slot already taken by another confirmed agreement.');
            }

            $stmt = $pdo->prepare("INSERT INTO sponsorship_agreements
                (sponsor_id, package_id, season_id, player_id, agreed_amount, status, notes)
                VALUES (:sponsor, :package, :season, :player, :amount, 'active', :notes)");
            $stmt->execute([
                ':sponsor' => $sponsorId,
                ':package' => $item['package_id'],
                ':season' => $order['season_id'],
                ':player' => $item['player_id'],
                ':amount' => $item['unit_amount'],
                ':notes' => 'Player sponsorship shop (order #' . $orderId . ', session ' . $sessionId . ')',
            ]);
            $agreementId = (int) $pdo->lastInsertId();
            applySponsorshipAgreementToLegacy($pdo, $agreementId); // Can itself throw on an interleaved race — caught below.

            $pdo->prepare('INSERT INTO stripe_transactions
                (agreement_id, payment_link_id, stripe_payment_intent_id, stripe_checkout_session_id, amount, currency, status, raw_payload)
                VALUES (:agreement_id, NULL, :payment_intent_id, :session_id, :amount, :currency, \'succeeded\', :raw_payload)')
                ->execute([
                    ':agreement_id' => $agreementId,
                    ':payment_intent_id' => $paymentIntentId ?: ('unknown_' . $sessionId),
                    ':session_id' => $sessionId,
                    ':amount' => $item['unit_amount'],
                    ':currency' => $currency,
                    ':raw_payload' => json_encode($session),
                ]);

            $agreement = getSponsorshipAgreement($pdo, $agreementId);
            if ($agreement) {
                stripe_record_agreement_payment($pdo, $agreement, (float) $item['unit_amount'], 'Stripe payment (player sponsorship shop, order #' . $orderId . ', session ' . $sessionId . ')');
            }

            $pdo->prepare("UPDATE player_sponsorship_order_items SET status = 'confirmed', sponsorship_agreement_id = :agreement_id WHERE id = :id")
                ->execute([':agreement_id' => $agreementId, ':id' => $item['id']]);

            $pdo->commit();
            $anyConfirmed = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->prepare("UPDATE player_sponsorship_order_items SET status = 'conflict_refund_due' WHERE id = :id")
                ->execute([':id' => $item['id']]);
            $anyConflict = true;
            error_log('[player_sponsorship_shop] Conflict: order #' . $orderId . ' item #' . $item['id']
                . ' (player ' . $item['player_id'] . ', package ' . $item['package_id'] . '): ' . $e->getMessage());
        }
    }

    $finalStatus = ($anyConfirmed && !$anyConflict) ? 'paid' : 'partially_conflicted';
    $pdo->prepare('UPDATE player_sponsorship_orders SET status = :status, paid_at = NOW() WHERE id = :id')
        ->execute([':status' => $finalStatus, ':id' => $orderId]);

    sendPlayerSponsorshipReceiptEmail($pdo, $orderId);
    sendPlayerSponsorshipThankyouEmail($pdo, $orderId);
    stripe_send_payment_notification(
        $pdo,
        'Player sponsorship shop',
        (string) ($order['buyer_name'] ?? ''),
        (string) ($order['buyer_email'] ?? ''),
        (float) ($order['total_amount'] ?? 0),
        'Player sponsorship order #' . $orderId,
        stripe_public_base_url() . '/playersponsors_orders.php?id=' . $orderId
    );
}

/**
 * Shared inline-HTML email shell — house maroon/gold style, matching
 * sendMatchTicketConfirmationEmail() in lib/match_tickets.php.
 */
function player_sponsorship_shop_email_wrapper(string $subject, string $preheader, string $heroTitle, string $heroSubtitle, string $bodyHtml): string
{
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . h($subject) . '</title></head>
    <body style="margin:0;padding:0;background:#f6ecde;font-family:Inter,Arial,sans-serif;color:#21141a;">
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . h($preheader) . '</div>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f6ecde;padding:28px 12px;">
            <tr><td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 18px 45px rgba(75,8,24,.14);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#4b0818,#7a1730 62%,#a6791d);padding:28px 26px;color:#ffffff;">
                            <div style="font-size:12px;text-transform:uppercase;letter-spacing:.14em;font-weight:900;color:rgba(255,255,255,.74);">Saltcoats Victoria FC</div>
                            <h1 style="margin:8px 0 8px;font-size:28px;line-height:1.1;color:#ffffff;">' . h($heroTitle) . '</h1>
                            <p style="margin:0;color:rgba(255,255,255,.84);font-size:16px;">' . h($heroSubtitle) . '</p>
                        </td>
                    </tr>
                    <tr><td style="padding:26px;">' . $bodyHtml . '</td></tr>
                    <tr>
                        <td style="padding:18px 26px;background:#21141a;color:rgba(255,255,255,.72);font-size:13px;text-align:center;">
                            Saltcoats Victoria FC &middot; Thank you for your support
                        </td>
                    </tr>
                </table>
            </td></tr>
        </table>
    </body></html>';
}

function player_sponsorship_shop_send_mail(string $toEmail, string $subject, string $html): bool
{
    return hub_send_mail($toEmail, $subject, $html, true);
}

/**
 * Payment receipt — itemises what was actually charged. Idempotent via
 * receipt_email_sent_at, same guard pattern as sendMatchTicketConfirmationEmail().
 */
function sendPlayerSponsorshipReceiptEmail(PDO $pdo, int $orderId, bool $force = false): bool
{
    $order = player_sponsorship_shop_get_order($pdo, $orderId);
    if (!$order || !in_array((string) $order['status'], ['paid', 'partially_conflicted'], true)) {
        return false;
    }
    if (!$force && !empty($order['receipt_email_sent_at'])) {
        return true;
    }
    $email = trim((string) $order['buyer_email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $items = player_sponsorship_shop_get_order_items($pdo, $orderId);
    $confirmed = array_values(array_filter($items, static fn(array $i): bool => (string) $i['status'] === 'confirmed'));
    $conflicted = array_values(array_filter($items, static fn(array $i): bool => (string) $i['status'] === 'conflict_refund_due'));

    $chargedTotal = 0.0;
    $rows = '';
    foreach ($confirmed as $item) {
        $chargedTotal += (float) $item['unit_amount'];
        $rows .= '<tr>
            <td style="padding:10px 0;border-bottom:1px solid #eadfdf;color:#21141a;font-weight:700;">' . h(player_sponsorship_shop_package_label((string) $item['package_code'])) . ' &mdash; ' . h((string) $item['player_name_snapshot']) . '</td>
            <td style="padding:10px 0;border-bottom:1px solid #eadfdf;color:#21141a;text-align:right;font-weight:700;">' . h(gbp((float) $item['unit_amount'])) . '</td>
        </tr>';
    }
    $itemsBlock = $rows !== ''
        ? '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 16px;">' . $rows . '</table>'
            . '<p style="margin:0;color:#4b0818;font-size:18px;font-weight:900;">Total charged: ' . h(gbp($chargedTotal)) . '</p>'
        : '<p style="margin:0 0 16px;color:#4a4046;font-size:15px;">Unfortunately none of the items in this order could be completed — see below. You have not been charged.</p>';

    $conflictNote = '';
    if ($conflicted) {
        $names = array_map(
            static fn(array $i): string => player_sponsorship_shop_package_label((string) $i['package_code']) . ' — ' . (string) $i['player_name_snapshot'],
            $conflicted
        );
        $conflictNote = '<div style="margin-top:18px;padding:14px 16px;border-radius:12px;background:#fff4e5;color:#7a4a06;font-size:14px;line-height:1.5;">
            One or more items had just been sponsored by someone else moments before your payment: ' . h(implode(', ', $names)) . '. You have not been charged for these, and the club will be in touch about a refund or an alternative.
        </div>';
    }

    $body = '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;">Hi ' . h((string) $order['buyer_name']) . ',</p>'
        . '<p style="margin:0 0 22px;font-size:16px;line-height:1.55;color:#4a4046;">Thanks for your payment. Here is your receipt for Order #' . (int) $orderId . '.</p>'
        . $itemsBlock
        . $conflictNote;

    $subject = 'Your Saltcoats Victoria FC sponsorship receipt';
    $html = player_sponsorship_shop_email_wrapper($subject, 'Your player kit sponsorship receipt.', 'Payment Receipt', 'Order #' . $orderId, $body);
    $sent = player_sponsorship_shop_send_mail($email, $subject, $html);
    if ($sent) {
        $pdo->prepare('UPDATE player_sponsorship_orders SET receipt_email_sent_at = NOW() WHERE id = :id')->execute([':id' => $orderId]);
    }
    return $sent;
}

/**
 * A separate, warmer "thank you for supporting the club" email — distinct
 * from the receipt, as asked for. Skipped (returns false, no *_sent_at set,
 * safe to retry) if nothing in the order actually confirmed.
 */
function sendPlayerSponsorshipThankyouEmail(PDO $pdo, int $orderId, bool $force = false): bool
{
    $order = player_sponsorship_shop_get_order($pdo, $orderId);
    if (!$order || !in_array((string) $order['status'], ['paid', 'partially_conflicted'], true)) {
        return false;
    }
    if (!$force && !empty($order['thankyou_email_sent_at'])) {
        return true;
    }
    $email = trim((string) $order['buyer_email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $items = player_sponsorship_shop_get_order_items($pdo, $orderId);
    $confirmed = array_values(array_filter($items, static fn(array $i): bool => (string) $i['status'] === 'confirmed'));
    if (!$confirmed) {
        return false;
    }
    $names = array_map(
        static fn(array $i): string => (string) $i['player_name_snapshot'] . ' (' . player_sponsorship_shop_package_label((string) $i['package_code']) . ')',
        $confirmed
    );

    $body = '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;">Hi ' . h((string) $order['buyer_name']) . ',</p>'
        . '<p style="margin:0 0 16px;font-size:16px;line-height:1.55;color:#4a4046;">On behalf of everyone at Saltcoats Victoria FC, thank you for sponsoring:</p>'
        . '<p style="margin:0 0 22px;font-size:16px;line-height:1.55;font-weight:700;color:#4b0818;">' . h(implode(', ', $names)) . '</p>'
        . '<p style="margin:0;font-size:15px;line-height:1.6;color:#4a4046;">Support like yours makes a real difference to the club and our players. We really appreciate it.</p>';

    $subject = 'Thank you for sponsoring Saltcoats Victoria FC';
    $html = player_sponsorship_shop_email_wrapper($subject, 'Thank you for supporting the club.', 'Thank You!', 'Your support means a lot', $body);
    $sent = player_sponsorship_shop_send_mail($email, $subject, $html);
    if ($sent) {
        $pdo->prepare('UPDATE player_sponsorship_orders SET thankyou_email_sent_at = NOW() WHERE id = :id')->execute([':id' => $orderId]);
    }
    return $sent;
}

/**
 * Shape consumed by playersponsors_status.php's polling JS.
 *
 * @param list<array<string, mixed>> $items
 */
function player_sponsorship_shop_order_status_summary(array $order, array $items): array
{
    return [
        'ok' => true,
        'status' => (string) $order['status'],
        'total_amount' => (float) $order['total_amount'],
        'items' => array_map(static function (array $item): array {
            return [
                'player_name' => (string) $item['player_name_snapshot'],
                'package_label' => player_sponsorship_shop_package_label((string) $item['package_code']),
                'unit_amount' => (float) $item['unit_amount'],
                'status' => (string) $item['status'],
            ];
        }, $items),
    ];
}
