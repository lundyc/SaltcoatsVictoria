<?php

declare(strict_types=1);

// Public checkout — /shop/checkout  (see .htaccess). No login required.
// Collects guest contact details, then hands off to Stripe Checkout.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/shop.php';
require_once __DIR__ . '/../lib/shop_basket.php';
require_once __DIR__ . '/_layout.php';

shop_ensure_schema($pdo);
$settings = shop_get_settings($pdo);

if (($settings['shop_enabled'] ?? '1') !== '1') {
    header('Location: /shop/');
    exit;
}

$summary = shop_basket_summary($pdo);

if (!$summary['items']) {
    header('Location: /shop/basket');
    exit;
}
if ($summary['has_blocked']) {
    header('Location: /shop/basket?m=' . rawurlencode('Please remove the unavailable items before checking out.'));
    exit;
}

$baseUrl = stripe_public_base_url();
$errors = [];
$form = [
    'name' => trim((string) ($_POST['customer_name'] ?? '')),
    'email' => trim((string) ($_POST['customer_email'] ?? '')),
    'phone' => trim((string) ($_POST['customer_phone'] ?? '')),
    'note' => trim((string) ($_POST['customer_note'] ?? '')),
    'marketing' => !empty($_POST['marketing_opt_in']),
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'place_order') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    }
    if ($form['name'] === '') {
        $errors[] = 'Please enter your name.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($form['phone'] === '') {
        $errors[] = 'Please enter a contact phone number.';
    }
    if (empty($_POST['accept_terms'])) {
        $errors[] = 'Please tick the box to accept the pre-order terms.';
    }
    if (!stripe_is_configured()) {
        $errors[] = 'Online payment is not available right now. Please contact the club to place your order.';
    }

    // Re-validate the basket one last time against the live catalogue.
    $summary = shop_basket_summary($pdo);
    if (!$summary['items']) {
        header('Location: /shop/basket');
        exit;
    }
    if ($summary['has_blocked']) {
        $errors[] = 'Some items are no longer available — please review your basket.';
    }

    if (!$errors) {
        try {
            $order = shop_create_order(
                $pdo,
                [
                    'name' => $form['name'],
                    'email' => $form['email'],
                    'phone' => $form['phone'],
                    'note' => $form['note'],
                    'marketing_opt_in' => $form['marketing'],
                ],
                shop_basket_order_lines($summary),
                ['discount_code' => $summary['discount_code'], 'terms_accepted' => true]
            );

            if ((float) $order['total'] <= 0.0) {
                shop_mark_order_paid($pdo, (int) $order['id'], null);
                shop_basket_clear();
                header('Location: /shop/order/' . rawurlencode((string) $order['access_token']));
                exit;
            }

            $checkout = shop_create_checkout_session(
                $pdo,
                $order,
                $baseUrl . '/shop/order/' . rawurlencode((string) $order['access_token']) . '?paid=1',
                $baseUrl . '/shop/checkout?cancelled=1'
            );
            shop_basket_clear();
            header('Location: ' . $checkout['url']);
            exit;
        } catch (Throwable $e) {
            error_log('[shop checkout] ' . $e->getMessage());
            $errors[] = 'Something went wrong starting your payment. Please try again — you have not been charged.';
        }
    }
}

$cancelled = isset($_GET['cancelled']);

shop_layout_top([
    'title' => 'Checkout',
    'active' => '',
    'basket_count' => $summary['count'],
]);
?>
<h1 class="shop-section-title">Checkout</h1>

<?php if ($cancelled): ?>
    <div class="shop-alert shop-alert--warn">Your payment was cancelled and nothing has been charged. Your basket is still here whenever you're ready.</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?><div class="shop-alert shop-alert--error"><?= h($e) ?></div><?php endforeach; ?>

<?php shop_render_preorder_notice($settings, 'compact'); ?>

<div class="shop-basket-layout">
    <form method="post" action="/shop/checkout" class="shop-summary" style="position:static;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="place_order">
        <h2>Your details</h2>
        <div class="shop-form-grid">
            <div class="shop-field shop-field--full">
                <label for="customer_name">Full name</label>
                <input id="customer_name" name="customer_name" required value="<?= h($form['name']) ?>" autocomplete="name">
            </div>
            <div class="shop-field">
                <label for="customer_email">Email</label>
                <input id="customer_email" name="customer_email" type="email" required value="<?= h($form['email']) ?>" autocomplete="email">
            </div>
            <div class="shop-field">
                <label for="customer_phone">Phone</label>
                <input id="customer_phone" name="customer_phone" required value="<?= h($form['phone']) ?>" autocomplete="tel">
            </div>
            <div class="shop-field shop-field--full">
                <label for="customer_note">Order note <span style="font-weight:400;color:#8a7d84;">(optional)</span></label>
                <textarea id="customer_note" name="customer_note" rows="2" placeholder="Anything we should know — e.g. who this is for"><?= h($form['note']) ?></textarea>
            </div>
        </div>

        <h2 style="margin-top:1.2rem;">Collection &amp; terms</h2>
        <p class="shop-note" style="margin-top:0;">
            <strong>Collection only</strong> from <?= h((string) ($settings['collection_point'] ?? 'Campbell Park')) ?>.
            <?= h((string) ($settings['delivery_note'] ?? 'There is no delivery option for this shop.')) ?>
        </p>
        <div class="shop-terms"><?= h((string) ($settings['terms'] ?? '')) ?></div>
        <label class="shop-check">
            <input type="checkbox" name="accept_terms" value="1" required>
            <span>I understand payment is taken in full today, that pre-order kit is made by VSN (allow
                <?= h((string) ($settings['lead_time'] ?? '6–8 weeks')) ?> after orders close on 14 September 2026),
                and that all orders are collected from <?= h((string) ($settings['collection_point'] ?? 'Campbell Park')) ?>.</span>
        </label>
        <label class="shop-check">
            <input type="checkbox" name="marketing_opt_in" value="1" <?= $form['marketing'] ? 'checked' : '' ?>>
            <span>Keep me updated by email about club news and offers.</span>
        </label>

        <button type="submit" class="shop-btn shop-btn--primary shop-btn--block shop-btn--lg" style="margin-top:1rem;">
            <i class="fa-solid fa-lock" aria-hidden="true"></i> Pay <?= h(gbp((float) $summary['total'])) ?> with Stripe
        </button>
        <p class="shop-note">You'll be taken to Stripe's secure payment page. Card details are never seen by the club.</p>
    </form>

    <aside class="shop-summary" aria-label="Order summary">
        <h2>Order summary</h2>
        <?php foreach ($summary['items'] as $item): ?>
            <div class="shop-summary__row">
                <span><?= h((string) $item['product_name']) ?><?= $item['options_label'] !== '' ? ' <span style="color:#8a7d84;">(' . h((string) $item['options_label']) . ')</span>' : '' ?> × <?= (int) $item['quantity'] ?></span>
                <span><?= h(gbp((float) $item['line_total'])) ?></span>
            </div>
        <?php endforeach; ?>
        <div class="shop-summary__row"><span>Subtotal</span><span><?= h(gbp((float) $summary['subtotal'])) ?></span></div>
        <?php if ((float) $summary['discount_total'] > 0): ?>
            <div class="shop-summary__row"><span>Discount (<?= h((string) $summary['discount_code']) ?>)</span><span>−<?= h(gbp((float) $summary['discount_total'])) ?></span></div>
        <?php endif; ?>
        <div class="shop-summary__row"><span>Collection</span><span><?= h((string) ($settings['collection_point'] ?? 'Campbell Park')) ?></span></div>
        <div class="shop-summary__row shop-summary__row--total"><span>Total due</span><span><?= h(gbp((float) $summary['total'])) ?></span></div>
        <p class="shop-note"><a href="/shop/basket">Edit basket</a></p>
    </aside>
</div>

<?php shop_layout_bottom(); ?>
