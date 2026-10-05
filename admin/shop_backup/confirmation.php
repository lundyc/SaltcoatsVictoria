<?php

declare(strict_types=1);

// Public order confirmation — /shop/order/<token>  (see .htaccess).
// The access token is the only credential; guests reach this straight from
// Stripe's success redirect and from the confirmation email.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/shop.php';
require_once __DIR__ . '/../lib/shop_basket.php';
require_once __DIR__ . '/_layout.php';

shop_ensure_schema($pdo);
$settings = shop_get_settings($pdo);

$token = trim((string) ($_GET['token'] ?? ''));
$order = $token !== '' ? shop_get_order_by_token($pdo, $token) : null;

if (!$order) {
    http_response_code(404);
    shop_layout_top(['title' => 'Order not found', 'basket_count' => shop_basket_count()]);
    echo '<div class="shop-empty"><i class="fa-solid fa-receipt"></i><p>We couldn\'t find that order.</p><p><a class="shop-btn shop-btn--ghost" href="/shop/">Back to the shop</a></p></div>';
    shop_layout_bottom();
    exit;
}

// Webhook may not have landed yet — confirm straight from Stripe as a fallback.
if ((string) $order['status'] === 'pending_payment') {
    $order = shop_finalize_pending_order_from_stripe($pdo, $order);
}

$items = shop_order_items($pdo, (int) $order['id']);
$paid = in_array((string) $order['status'], ['paid', 'collected'], true);
$cancelled = in_array((string) $order['status'], ['cancelled', 'refunded'], true);
$collectionPoint = (string) ($order['collection_point'] ?: ($settings['collection_point'] ?? 'Campbell Park'));
$lead = (string) ($settings['lead_time'] ?? '6–8 weeks');

shop_layout_top([
    'title' => 'Order ' . (string) $order['order_ref'],
    'active' => '',
    'basket_count' => shop_basket_count(),
]);
?>
<div class="shop-confirm">
    <div class="shop-confirm__head">
        <?php if ($paid): ?>
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <h1>Thank you — order confirmed</h1>
            <p><?= h((string) $order['order_ref']) ?></p>
        <?php elseif ($cancelled): ?>
            <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i>
            <h1>Order <?= h((string) $order['status']) ?></h1>
            <p><?= h((string) $order['order_ref']) ?></p>
        <?php else: ?>
            <i class="fa-solid fa-hourglass-half" aria-hidden="true"></i>
            <h1>Payment processing</h1>
            <p><?= h((string) $order['order_ref']) ?></p>
        <?php endif; ?>
    </div>
    <div class="shop-confirm__body">
        <?php if ($paid): ?>
            <p>We've received your payment in full and emailed a confirmation to
               <strong><?= h((string) $order['customer_email']) ?></strong>.</p>
        <?php elseif (!$cancelled): ?>
            <div class="shop-alert shop-alert--warn">Your payment is still being confirmed. This page will update once it's done —
                refresh in a moment, or check the email we'll send you.</div>
        <?php endif; ?>

        <h2 style="font-size:1.05rem;font-weight:800;margin:1.2rem 0 .5rem;">Your order</h2>
        <?php foreach ($items as $item): ?>
            <div class="shop-kv">
                <span><?= h((string) $item['product_name']) ?>
                    <?php if (($item['options_label'] ?? '') !== ''): ?><br><small style="color:#8a7d84;"><?= h((string) $item['options_label']) ?></small><?php endif; ?>
                    &nbsp;×<?= (int) $item['quantity'] ?></span>
                <span><?= h(gbp((float) $item['line_total'])) ?></span>
            </div>
        <?php endforeach; ?>
        <?php if ((float) $order['discount_total'] > 0): ?>
            <div class="shop-kv"><span>Discount (<?= h((string) $order['discount_code']) ?>)</span><span>−<?= h(gbp((float) $order['discount_total'])) ?></span></div>
        <?php endif; ?>
        <div class="shop-kv" style="font-weight:900;color:var(--shop-maroon);">
            <span><?= $paid ? 'Total paid' : 'Total' ?></span><span><?= h(gbp((float) $order['total'])) ?></span>
        </div>
        <?php if ((float) $order['amount_refunded'] > 0): ?>
            <div class="shop-kv"><span>Refunded</span><span>−<?= h(gbp((float) $order['amount_refunded'])) ?></span></div>
        <?php endif; ?>

        <?php if ($paid): ?>
            <h2 style="font-size:1.05rem;font-weight:800;margin:1.4rem 0 .3rem;">What happens next</h2>
            <ul class="shop-timeline">
                <li><i class="fa-solid fa-check" aria-hidden="true"></i><span>Payment received today.</span></li>
                <?php if ((int) $order['is_preorder'] === 1): ?>
                    <li><i class="fa-solid fa-calendar-day" aria-hidden="true"></i><span>Pre-orders close <strong>14 September 2026</strong>. The full club order is then placed with VSN.</span></li>
                    <li><i class="fa-solid fa-industry" aria-hidden="true"></i><span>VSN manufacturing typically takes <strong><?= h($lead) ?></strong> from that date.</span></li>
                <?php endif; ?>
                <li><i class="fa-solid fa-envelope" aria-hidden="true"></i><span>We'll email you as soon as your order is ready.</span></li>
                <li><i class="fa-solid fa-location-dot" aria-hidden="true"></i><span>Collect from <strong><?= h($collectionPoint) ?></strong>. There is no delivery option.</span></li>
            </ul>
            <?php if (($settings['collection_details'] ?? '') !== ''): ?>
                <p class="shop-note"><?= h((string) $settings['collection_details']) ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <p style="margin-top:1.4rem;">
            <a class="shop-btn shop-btn--ghost" href="/shop/">Back to the shop</a>
        </p>
        <p class="shop-note">Questions about this order? Contact the club and quote <strong><?= h((string) $order['order_ref']) ?></strong>.</p>
    </div>
</div>

<?php shop_layout_bottom(); ?>
