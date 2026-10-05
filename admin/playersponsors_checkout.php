<?php
declare(strict_types=1);

// Public page — /playersponsors/checkout. Step 2 of the shopping-cart
// flow: buyer details, then a genuine multi-item Stripe Checkout Session
// (not a reservation — the sponsorship_agreements rows are only created once
// Stripe confirms payment, in the webhook — see
// lib/player_sponsorship_shop.php).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/player_sponsorship_shop.php';
require_once __DIR__ . '/lib/player_sponsorship_basket.php';
require_once __DIR__ . '/lib/stripe.php';
require_once __DIR__ . '/lib/audit.php';
ensurePlayerSponsorshipShopSchema($pdo);
player_sponsorship_basket_start();

$currentSeason = getCurrentSeason($pdo);
$seasonId = (int) ($currentSeason['id'] ?? 0);
$players = $seasonId > 0 ? player_sponsorship_shop_players_with_availability($pdo, $seasonId) : [];
$basket = player_sponsorship_basket_summary($players);

if (!$currentSeason || !$basket['items']) {
    header('Location: /playersponsors');
    exit;
}

$errors = [];
$csrfFieldHtml = csrf_field();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $buyerName = trim((string) ($_POST['buyer_name'] ?? ''));
        $buyerEmail = trim((string) ($_POST['buyer_email'] ?? ''));
        $buyerPhone = trim((string) ($_POST['buyer_phone'] ?? ''));
        $isBusiness = isset($_POST['is_business']);
        $buyerAddress = $isBusiness ? trim((string) ($_POST['buyer_address'] ?? '')) : '';
        $buyerWebsite = $isBusiness ? trim((string) ($_POST['buyer_website'] ?? '')) : '';

        if ($buyerName === '') {
            $errors[] = 'Enter your name.';
        }
        if ($buyerEmail === '' || !filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($buyerPhone === '') {
            $errors[] = 'Enter a contact phone number.';
        }

        if (!$errors) {
            try {
                $order = player_sponsorship_shop_create_order($pdo, [
                    'name' => $buyerName,
                    'email' => $buyerEmail,
                    'phone' => $buyerPhone,
                    'is_business' => $isBusiness,
                    'address' => $buyerAddress,
                    'website_url' => $buyerWebsite,
                ], $seasonId, player_sponsorship_basket_items());

                $orderId = $order['order_id'];
                player_sponsorship_basket_clear();

                $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
                $host = (string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk');
                $orderRow = player_sponsorship_shop_get_order($pdo, $orderId);
                auditLog($pdo, 'player_sponsorship_order_placed', "Placed order #{$orderId} for {$buyerName} (£" . number_format((float) ($orderRow['total_amount'] ?? 0), 2) . ')');
                $thankYouUrl = $scheme . '://' . $host . '/playersponsors_thankyou.php?access=' . rawurlencode((string) $orderRow['access_token']);
                $cancelUrl = $scheme . '://' . $host . '/playersponsors/checkout?cancelled=1';

                if ((float) $orderRow['total_amount'] <= 0 || !stripe_is_configured()) {
                    header('Location: ' . $thankYouUrl);
                    exit;
                }

                $checkout = player_sponsorship_shop_stripe_create_checkout_session($pdo, $orderId, $thankYouUrl, $cancelUrl);
                header('Location: ' . $checkout['url']);
                exit;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                // The basket may have been partly invalidated by
                // player_sponsorship_shop_create_order()'s re-check — refresh it
                // so the summary below reflects what's actually still available.
                $basket = player_sponsorship_basket_summary($players);
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css" rel="stylesheet">
    <style>
        :root { --ps-maroon: #4b0818; --ps-gold: #e0b42a; --ps-cream: #f6ecde; }
        body { background: var(--ps-cream); font-family: 'Inter', sans-serif; margin: 0; }
        .ps-topbar { background: var(--ps-maroon); color: #fff; padding: 1rem; text-align: center; }
        .ps-wrap { max-width: 960px; margin: 0 auto; padding: 1.25rem 1rem 4rem; }
        .ps-checkout-layout { display: grid; gap: 1rem; align-items: start; }
        .ps-checkout-main { order: 2; }
        .ps-checkout-summary { order: 1; }
        .ps-card { background: #fff; border-radius: .75rem; box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 1.25rem; margin-bottom: 1rem; }
        .ps-summary-row { display: flex; justify-content: space-between; padding: .3rem 0; border-bottom: 1px solid #eee; }
        .ps-total-row { display: flex; justify-content: space-between; font-weight: 700; font-size: 1.1rem; margin-top: .5rem; color: var(--ps-maroon); }
        @media (min-width: 900px) {
            .ps-checkout-layout { grid-template-columns: minmax(0, 1fr) 340px; }
            .ps-checkout-main { order: 1; }
            .ps-checkout-summary { order: 2; position: sticky; top: 1rem; }
        }
    </style>
</head>
<body>
<div class="ps-topbar"><a href="/playersponsors" class="text-white text-decoration-none">&larr; Back to basket</a> &middot; Your details</div>
<div class="ps-wrap">
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (isset($_GET['cancelled'])): ?><div class="alert alert-warning">Payment was cancelled — no money was taken. Your basket is still saved below.</div><?php endif; ?>

    <?php if (!$basket['items']): ?>
        <div class="ps-card text-center text-muted">Your basket is empty. <a href="/playersponsors">Choose a player to sponsor</a>.</div>
    <?php else: ?>
    <div class="ps-checkout-layout">
        <form method="post" class="ps-card ps-checkout-main">
            <?= $csrfFieldHtml ?>
            <h2 class="h6">Your details</h2>
            <div class="mb-2"><label class="form-label">Full name</label><input type="text" class="form-control" name="buyer_name" required value="<?= h((string) ($_POST['buyer_name'] ?? '')) ?>"></div>
            <div class="mb-2"><label class="form-label">Email</label><input type="email" class="form-control" name="buyer_email" required value="<?= h((string) ($_POST['buyer_email'] ?? '')) ?>"></div>
            <div class="mb-3"><label class="form-label">Phone</label><input type="text" class="form-control" name="buyer_phone" required value="<?= h((string) ($_POST['buyer_phone'] ?? '')) ?>"></div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="is_business" id="isBusiness" value="1" <?= isset($_POST['is_business']) ? 'checked' : '' ?>>
                <label class="form-check-label" for="isBusiness">I'm sponsoring on behalf of a business</label>
            </div>
            <div id="businessFields" class="<?= isset($_POST['is_business']) ? '' : 'd-none' ?>">
                <div class="mb-2"><label class="form-label">Business address <span class="text-muted">(optional)</span></label><input type="text" class="form-control" name="buyer_address" value="<?= h((string) ($_POST['buyer_address'] ?? '')) ?>"></div>
                <div class="mb-3"><label class="form-label">Website <span class="text-muted">(optional)</span></label><input type="text" class="form-control" name="buyer_website" value="<?= h((string) ($_POST['buyer_website'] ?? '')) ?>"></div>
            </div>

            <div class="alert alert-light border py-2">Payment is by card through Stripe after you place the order.</div>
            <button type="submit" class="btn btn-brand w-100"><?= $basket['total'] > 0 ? 'Continue to payment' : 'Place order' ?></button>
        </form>

        <div class="ps-card ps-checkout-summary">
            <h2 class="h5" style="color:var(--ps-maroon);">Order summary</h2>
            <?php foreach ($basket['items'] as $item): ?>
                <div class="ps-summary-row">
                    <span><?= h((string) $item['player_name']) ?> &mdash; <?= h(str_replace(' Sponsorship', '', (string) player_sponsorship_shop_package_label((string) $item['package_code']))) ?></span>
                    <span><?= gbp((float) $item['price']) ?></span>
                </div>
            <?php endforeach; ?>
            <div class="ps-total-row"><span>Total</span><span><?= gbp((float) $basket['total']) ?></span></div>
        </div>
    </div>
    <?php endif; ?>
</div>
<script>(() => {
    const checkbox = document.getElementById('isBusiness');
    const fields = document.getElementById('businessFields');
    checkbox?.addEventListener('change', () => fields?.classList.toggle('d-none', !checkbox.checked));
})();</script>
</body>
</html>
