<?php
/** Route: /shop/basket. Mirrors live shop/basket.php. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/shop.php';
require_once HUB_ROOT . '/lib/shop_basket.php';

shop_ensure_schema(db());
$settings = shop_get_settings(db());

$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $notice = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'update_qty') {
            shop_basket_set_quantity(db(), (string) ($_POST['key'] ?? ''), (int) ($_POST['quantity'] ?? 0));
        } elseif ($action === 'remove') {
            shop_basket_remove((string) ($_POST['key'] ?? ''));
        } elseif ($action === 'clear') {
            shop_basket_clear();
        } elseif ($action === 'apply_promo') {
            $code = strtoupper(trim((string) ($_POST['discount_code'] ?? '')));
            shop_basket_set_discount_code($code);
            if ($code !== '') {
                $s = shop_basket_summary(db());
                $notice = $s['discount_code'] !== '' ? 'Discount code applied.' : ($s['discount_error'] ?: 'That code is not valid.');
            } else {
                $notice = 'Discount code removed.';
            }
        }
        redirect(url('shop/basket') . ($notice !== '' ? '?m=' . rawurlencode($notice) : ''));
    }
}
if ($notice === '' && isset($_GET['m'])) {
    $notice = (string) $_GET['m'];
}

$summary = shop_basket_summary(db());
$csrf = csrf_field();

set_meta(['title' => 'Your basket']);
?>
<?php partial('shop_bar', ['basketCount' => $summary['count']]); ?>

<?php seo_breadcrumbs([['Club shop', url('shop')], ['Basket', url('shop/basket')]]); ?>
<?php partial('page_hero', ['eyebrow' => 'Club shop', 'title' => 'Your basket']); ?>

<div class="page">
  <div class="container">
    <?php if ($notice !== ''): ?><div class="notice notice--ok"><p><?= e($notice) ?></p></div><?php endif; ?>

    <?php if (!$summary['items']): ?>
      <div class="emptystate">
        <p>Your basket is empty.</p>
        <p><a class="btn btn--sm" href="<?= e(url('shop')) ?>">Browse the shop</a></p>
      </div>
    <?php else: ?>
      <?php if ($summary['has_blocked']): ?>
        <div class="notice notice--warn"><p>Some items below can no longer be ordered. Please remove them to continue to checkout.</p></div>
      <?php endif; ?>

      <div class="basket-layout">
        <div class="blines">
          <?php foreach ($summary['items'] as $item): ?>
            <div class="bline<?= $item['blocked_reason'] !== '' ? ' is-blocked' : '' ?>">
              <div class="bline__media" <?= $item['image_path'] !== '' ? 'style="background-image:url(\'' . e($item['image_path']) . '\')"' : '' ?>></div>
              <div class="bline__info">
                <a class="bline__name" href="<?= e(url('shop/p/' . rawurlencode((string) $item['product_slug']))) ?>"><?= e((string) $item['product_name']) ?></a>
                <?php if ($item['options_label'] !== ''): ?><div class="bline__opts"><?= e((string) $item['options_label']) ?></div><?php endif; ?>
                <?php if ($item['is_preorder']): ?><div class="bline__opts">Pre-order item</div><?php endif; ?>
                <div class="bline__controls">
                  <form method="post" class="stepper" data-stepper>
                    <?= $csrf ?><input type="hidden" name="action" value="update_qty"><input type="hidden" name="key" value="<?= e((string) $item['key']) ?>">
                    <button type="button" data-step="-1" aria-label="Decrease quantity">−</button>
                    <input type="number" name="quantity" value="<?= (int) $item['quantity'] ?>" min="0" max="<?= (int) $item['max_per_order'] ?>" inputmode="numeric" aria-label="Quantity" onchange="this.form.submit()">
                    <button type="button" data-step="1" aria-label="Increase quantity">+</button>
                    <noscript><button type="submit">Update</button></noscript>
                  </form>
                  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="remove"><input type="hidden" name="key" value="<?= e((string) $item['key']) ?>">
                    <button type="submit" class="bline__remove">Remove</button>
                  </form>
                </div>
                <?php if ($item['blocked_reason'] !== ''): ?><div class="bline__flag"><?= e((string) $item['blocked_reason']) ?></div><?php endif; ?>
              </div>
              <div class="bline__price">
                <?= e(gbp((float) $item['line_total'])) ?>
                <?php if ((int) $item['quantity'] > 1): ?><span><?= e(gbp((float) $item['unit_price'])) ?> each</span><?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>

          <div class="basket-actions">
            <a class="btn btn--ghost btn--sm" href="<?= e(url('shop')) ?>">← Continue shopping</a>
            <form method="post" onsubmit="return confirm('Empty your basket?');">
              <?= $csrf ?><input type="hidden" name="action" value="clear">
              <button type="submit" class="btn btn--ghost btn--sm">Empty basket</button>
            </form>
          </div>
        </div>

        <aside class="osummary" aria-label="Order summary">
          <h2>Order summary</h2>
          <div class="osummary__row"><span>Subtotal</span><span><?= e(gbp((float) $summary['subtotal'])) ?></span></div>
          <?php if ((float) $summary['discount_total'] > 0): ?>
            <div class="osummary__row"><span>Discount (<?= e((string) $summary['discount_code']) ?>)</span><span>−<?= e(gbp((float) $summary['discount_total'])) ?></span></div>
          <?php endif; ?>
          <div class="osummary__row"><span>Delivery</span><span><?= shop_delivery_enabled(db()) ? 'Calculated at checkout' : 'Collection only' ?></span></div>
          <?php $freeOverBasket = (float) shop_setting(db(), 'free_delivery_over', '0'); ?>
          <?php if (shop_delivery_enabled(db()) && $freeOverBasket > 0): ?>
            <p class="pdp__note">Free delivery on orders over <?= e(gbp($freeOverBasket)) ?><?= (float) $summary['total'] < $freeOverBasket ? ' — add ' . e(gbp($freeOverBasket - (float) $summary['total'])) . ' more to qualify' : ' — you qualify!' ?>.</p>
          <?php endif; ?>
          <div class="osummary__row osummary__row--total"><span>Total</span><span><?= e(gbp((float) $summary['total'])) ?></span></div>

          <form method="post" class="osummary__promo">
            <?= $csrf ?><input type="hidden" name="action" value="apply_promo">
            <input type="text" name="discount_code" placeholder="Discount code" value="<?= e((string) $summary['discount_code']) ?>" aria-label="Discount code">
            <button type="submit" class="btn btn--ghost btn--sm">Apply</button>
          </form>
          <?php if (($summary['discount_error'] ?? '') !== ''): ?><p class="osummary__err"><?= e((string) $summary['discount_error']) ?></p><?php endif; ?>

          <?php if ($summary['has_blocked']): ?>
            <button class="btn btn--lg" style="width:100%;margin-top:1rem" disabled>Checkout</button>
            <p class="pdp__note">Remove the flagged items above to continue.</p>
          <?php else: ?>
            <a class="btn btn--lg" style="width:100%;margin-top:1rem" href="<?= e(url('shop/checkout')) ?>">Checkout →</a>
          <?php endif; ?>
          <p class="pdp__note">You'll pay securely via Stripe. Payment is taken in full today.</p>
        </aside>
      </div>
      <?php
      $inBasket = array_map(static fn(array $i): int => (int) $i['product_id'], $summary['items']);
      $cross = shop_linked_products(db(), $inBasket, 'crosssell', $inBasket, 4);
      ?>
      <?php if ($cross): ?>
        <div class="pdp__related">
          <h2>You may also be interested in&hellip;</h2>
          <div class="prodgrid">
            <?php foreach ($cross as $rp): ?>
              <?php $rimg = trim((string) ($rp['image_path'] ?? '')); ?>
              <a class="prodcard" href="<?= e(url('shop/p/' . rawurlencode((string) $rp['slug']))) ?>">
                <div class="prodcard__media<?= $rimg === '' ? ' is-placeholder' : '' ?>" <?= $rimg !== '' ? 'style="background-image:url(\'' . e($rimg) . '\')"' : '' ?>></div>
                <div class="prodcard__body">
                  <span class="prodcard__name"><?= e((string) $rp['name']) ?></span>
                  <span class="prodcard__price"><?= e(gbp(shop_product_price($rp))) ?></span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
