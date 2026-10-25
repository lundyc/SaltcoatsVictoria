<?php
/** Route: /shop/order/{token} — order confirmation. Mirrors live shop/confirmation.php. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/shop.php';
require_once HUB_ROOT . '/lib/shop_basket.php';

shop_ensure_schema(db());
$settings = shop_get_settings(db());

$tok = trim((string) ($token ?? ''));
$order = $tok !== '' ? shop_get_order_by_token(db(), $tok) : null;

if (!$order) {
    http_response_code(404);
    set_meta(['title' => 'Order not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Order not found</h1><p><a class="linkarrow" href="' . e(url('shop')) . '">Back to the shop</a></p></div></div>';
    return;
}

// Webhook may not have landed yet — confirm straight from Stripe as a fallback.
if ((string) $order['status'] === 'pending_payment') {
    $order = shop_finalize_pending_order_from_stripe(db(), $order);
}

$items = shop_order_items(db(), (int) $order['id']);
$paid = in_array((string) $order['status'], ['paid', 'collected'], true);
$cancelled = in_array((string) $order['status'], ['cancelled', 'refunded'], true);
$collectionPoint = (string) ($order['collection_point'] ?: ($settings['collection_point'] ?? 'Campbell Park'));
$preInfo = shop_preorder_info(db(), array_map(static fn(array $it): int => (int) ($it['product_id'] ?? 0), array_filter($items, static fn(array $it): bool => (int) $it['is_preorder'] === 1)));

set_meta(['title' => 'Order ' . (string) $order['order_ref']]);
?>
<?php partial('shop_bar', ['basketCount' => shop_basket_count()]); ?>

<div class="page">
  <div class="container">
    <div class="confirm">
      <div class="confirm__head confirm__head--<?= $paid ? 'ok' : ($cancelled ? 'bad' : 'wait') ?>">
        <?php if ($paid): ?>
          <h1>Thank you — order confirmed</h1>
        <?php elseif ($cancelled): ?>
          <h1>Order <?= e((string) $order['status']) ?></h1>
        <?php else: ?>
          <h1><?= empty($order['stripe_checkout_session_id']) ? 'Awaiting payment' : 'Payment processing' ?></h1>
        <?php endif; ?>
        <p><?= e((string) $order['order_ref']) ?>
          <button type="button" class="copybtn" data-copy="<?= e((string) $order['order_ref']) ?>" data-copy-type="order_reference">Copy</button>
        </p>
      </div>

      <div class="confirm__body">
        <?php if ($paid): ?>
          <p>We've received your payment in full and emailed a confirmation to <strong><?= e((string) $order['customer_email']) ?></strong>.</p>
        <?php elseif (!$cancelled): ?>
          <?php if (empty($order['stripe_checkout_session_id'])): ?>
          <div class="notice notice--warn"><p>Your order has been recorded and is awaiting payment. Please pay using the method agreed with the club.</p></div>
          <?php else: ?>
          <div class="notice notice--warn"><p>Your payment is still being confirmed. This page will update once it's done — refresh in a moment, or check the email we'll send you.</p></div>
          <?php endif; ?>
        <?php endif; ?>

        <h2>Your order</h2>
        <?php foreach ($items as $item): ?>
          <div class="kv">
            <span><?= e((string) $item['product_name']) ?><?php if (($item['options_label'] ?? '') !== ''): ?><br><small><?= e((string) $item['options_label']) ?></small><?php endif; ?> ×<?= (int) $item['quantity'] ?></span>
            <span><?= e(gbp((float) $item['line_total'])) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if ((float) $order['discount_total'] > 0): ?>
          <div class="kv"><span>Discount / credit <?= e((string) $order['discount_code']) ?></span><span>−<?= e(gbp((float) $order['discount_total'])) ?></span></div>
        <?php endif; ?>
        <div class="kv kv--total"><span><?= $paid ? 'Total paid' : 'Total' ?></span><span><?= e(gbp((float) $order['total'])) ?></span></div>
        <?php if ((float) $order['amount_refunded'] > 0): ?>
          <div class="kv"><span>Refunded</span><span>−<?= e(gbp((float) $order['amount_refunded'])) ?></span></div>
        <?php endif; ?>

        <?php if ($paid): ?>
          <h2>What happens next</h2>
          <ul class="timeline">
            <li>Payment received today.</li>
            <?php if ((int) $order['is_preorder'] === 1): ?>
              <?php if ($preInfo['close'] !== null): ?><li>Pre-orders close on <strong><?= e($preInfo['close']->format('j F Y')) ?></strong>, after which the club places its order with the supplier.</li><?php endif; ?>
              <?php if ($preInfo['lead'] !== null): ?><li>VSN manufacturing typically takes <strong><?= e($preInfo['lead']) ?></strong> from then.</li><?php endif; ?>
            <?php endif; ?>
            <li>We'll email you as soon as your order is ready.</li>
            <?php if ((string) ($order['fulfilment_method'] ?? 'collection') === 'delivery'): ?>
              <li>Your order will be delivered<?= trim((string) ($order['shipping_method'] ?? '')) !== '' ? ' (' . e((string) $order['shipping_method']) . ')' : '' ?> to: <strong><span style="white-space:pre-line"><?= e(trim((string) ($order['delivery_address'] ?? '') . "\n" . trim((string) ($order['delivery_postcode'] ?? '') . ' ' . (shop_countries()[(string) ($order['delivery_country'] ?? '')] ?? '')))) ?></span></strong></li>
            <?php else: ?>
              <li>Collect from <strong><?= e($collectionPoint) ?></strong>. There is no delivery option.</li>
            <?php endif; ?>
            <?php if ((string) ($order['fulfilment_method'] ?? 'collection') === 'delivery' && trim((string) ($settings['dispatch_time'] ?? '')) !== ''): ?>
              <li><?= e((string) $settings['dispatch_time']) ?></li>
            <?php endif; ?>
          </ul>
          <?php if ((string) ($order['fulfilment_method'] ?? 'collection') !== 'delivery'): ?>
            <?php if (($settings['collection_address'] ?? '') !== ''): ?><p class="pdp__note"><span style="white-space:pre-line"><?= e((string) $settings['collection_address']) ?></span></p><?php endif; ?>
            <?php if (($settings['collection_details'] ?? '') !== ''): ?><p class="pdp__note"><?= e((string) $settings['collection_details']) ?></p><?php endif; ?>
            <?php if (($settings['collection_map_embed'] ?? '') !== ''): ?><iframe class="shopmap" src="<?= e((string) $settings['collection_map_embed']) ?>" width="100%" height="260" style="border:0" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" title="Collection point map" allowfullscreen></iframe><?php endif; ?>
            <?php if (($settings['collection_map_url'] ?? '') !== ''): ?><p class="pdp__note"><a class="btn btn--ghost btn--sm" href="<?= e((string) $settings['collection_map_url']) ?>" target="_blank" rel="noopener">Get directions</a></p><?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>

        <p style="margin-top:1.4rem"><a class="btn btn--ghost btn--sm" href="<?= e(url('shop')) ?>">Back to the shop</a></p>
        <p class="pdp__note">Questions about this order? Contact the club and quote <strong><?= e((string) $order['order_ref']) ?></strong>.</p>
      </div>
    </div>
  </div>
</div>
