<?php

declare(strict_types=1);

// Public basket — /shop/basket  (see .htaccess). No login required.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/shop.php';
require_once __DIR__ . '/../lib/shop_basket.php';
require_once __DIR__ . '/_layout.php';

shop_ensure_schema($pdo);
$settings = shop_get_settings($pdo);

$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $notice = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'update_qty') {
            shop_basket_set_quantity($pdo, (string) ($_POST['key'] ?? ''), (int) ($_POST['quantity'] ?? 0));
        } elseif ($action === 'remove') {
            shop_basket_remove((string) ($_POST['key'] ?? ''));
        } elseif ($action === 'clear') {
            shop_basket_clear();
        } elseif ($action === 'apply_promo') {
            $code = strtoupper(trim((string) ($_POST['discount_code'] ?? '')));
            shop_basket_set_discount_code($code);
            if ($code !== '') {
                $summary = shop_basket_summary($pdo);
                $notice = $summary['discount_code'] !== ''
                    ? 'Discount code applied.'
                    : ($summary['discount_error'] ?: 'That code is not valid.');
            } else {
                $notice = 'Discount code removed.';
            }
        }
        header('Location: /shop/basket' . ($notice !== '' ? '?m=' . rawurlencode($notice) : ''));
        exit;
    }
}
if ($notice === '' && isset($_GET['m'])) {
    $notice = (string) $_GET['m'];
}

$summary = shop_basket_summary($pdo);
$csrf = csrf_field();

shop_layout_top([
    'title' => 'Your basket',
    'active' => '',
    'basket_count' => $summary['count'],
]);
?>
<h1 class="shop-section-title">Your basket</h1>

<?php if ($notice !== ''): ?><div class="shop-alert shop-alert--ok"><?= h($notice) ?></div><?php endif; ?>

<?php if (!$summary['items']): ?>
    <div class="shop-empty">
        <i class="fa-solid fa-basket-shopping" aria-hidden="true"></i>
        <p>Your basket is empty.</p>
        <p><a class="shop-btn shop-btn--primary" href="/shop/">Browse the shop</a></p>
    </div>
<?php else: ?>
    <?php shop_render_preorder_notice($settings, 'compact'); ?>

    <?php if ($summary['has_blocked']): ?>
        <div class="shop-alert shop-alert--warn">Some items below can no longer be ordered. Please remove them to continue to checkout.</div>
    <?php endif; ?>

    <div class="shop-basket-layout">
        <div>
            <div class="shop-linelist">
                <?php foreach ($summary['items'] as $item): ?>
                    <div class="shop-line <?= $item['blocked_reason'] !== '' ? 'shop-line--blocked' : '' ?>">
                        <div class="shop-line__media" <?= $item['image_path'] !== '' ? 'style="background-image:url(\'' . h($item['image_path']) . '\')"' : '' ?>>
                            <?php if ($item['image_path'] === ''): ?><i class="fa-solid fa-shirt" aria-hidden="true"></i><?php endif; ?>
                        </div>
                        <div>
                            <div class="shop-line__name"><a href="/shop/p/<?= h((string) $item['product_slug']) ?>" style="color:inherit;text-decoration:none;"><?= h((string) $item['product_name']) ?></a></div>
                            <?php if ($item['options_label'] !== ''): ?><div class="shop-line__opts"><?= h((string) $item['options_label']) ?></div><?php endif; ?>
                            <?php if ($item['is_preorder']): ?><div class="shop-line__opts"><i class="fa-solid fa-clock" aria-hidden="true"></i> Pre-order item</div><?php endif; ?>
                            <div class="shop-line__controls">
                                <form method="post" class="shop-stepper" data-stepper>
                                    <?= $csrf ?>
                                    <input type="hidden" name="action" value="update_qty">
                                    <input type="hidden" name="key" value="<?= h((string) $item['key']) ?>">
                                    <button type="button" data-step="-1" aria-label="Decrease quantity">−</button>
                                    <input type="number" name="quantity" value="<?= (int) $item['quantity'] ?>" min="0" max="<?= (int) $item['max_per_order'] ?>" inputmode="numeric" aria-label="Quantity" onchange="this.form.submit()">
                                    <button type="button" data-step="1" aria-label="Increase quantity">+</button>
                                    <noscript><button type="submit" class="shop-btn shop-btn--ghost" style="border-radius:0;border:0;border-left:1.5px solid var(--shop-line);padding:0 .6rem;">Update</button></noscript>
                                </form>
                                <form method="post">
                                    <?= $csrf ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="key" value="<?= h((string) $item['key']) ?>">
                                    <button type="submit" class="shop-line__remove">Remove</button>
                                </form>
                            </div>
                            <?php if ($item['blocked_reason'] !== ''): ?>
                                <div class="shop-line__flag"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?= h((string) $item['blocked_reason']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="shop-line__price">
                            <?= h(gbp((float) $item['line_total'])) ?>
                            <?php if ((int) $item['quantity'] > 1): ?><div class="shop-line__opts"><?= h(gbp((float) $item['unit_price'])) ?> each</div><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top:1rem;display:flex;gap:.75rem;flex-wrap:wrap;">
                <a class="shop-btn shop-btn--ghost" href="/shop/"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Continue shopping</a>
                <form method="post" onsubmit="return confirm('Empty your basket?');">
                    <?= $csrf ?><input type="hidden" name="action" value="clear">
                    <button type="submit" class="shop-btn shop-btn--ghost">Empty basket</button>
                </form>
            </div>
        </div>

        <aside class="shop-summary" aria-label="Order summary">
            <h2>Order summary</h2>
            <div class="shop-summary__row"><span>Subtotal</span><span><?= h(gbp((float) $summary['subtotal'])) ?></span></div>
            <?php if ((float) $summary['discount_total'] > 0): ?>
                <div class="shop-summary__row"><span>Discount (<?= h((string) $summary['discount_code']) ?>)</span><span>−<?= h(gbp((float) $summary['discount_total'])) ?></span></div>
            <?php endif; ?>
            <div class="shop-summary__row"><span>Delivery</span><span>Collection only</span></div>
            <div class="shop-summary__row shop-summary__row--total"><span>Total</span><span><?= h(gbp((float) $summary['total'])) ?></span></div>

            <form method="post" class="shop-summary__promo">
                <?= $csrf ?><input type="hidden" name="action" value="apply_promo">
                <input type="text" name="discount_code" placeholder="Discount code" value="<?= h((string) $summary['discount_code']) ?>" aria-label="Discount code">
                <button type="submit" class="shop-btn shop-btn--ghost">Apply</button>
            </form>
            <?php if (($summary['discount_error'] ?? '') !== ''): ?>
                <p class="shop-note" style="color:#8a2a17;"><?= h((string) $summary['discount_error']) ?></p>
            <?php endif; ?>

            <?php if ($summary['has_blocked']): ?>
                <button class="shop-btn shop-btn--primary shop-btn--block shop-btn--lg" disabled>Checkout</button>
                <p class="shop-note">Remove the flagged items above to continue.</p>
            <?php else: ?>
                <a class="shop-btn shop-btn--primary shop-btn--block shop-btn--lg" href="/shop/checkout">
                    Checkout <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
            <p class="shop-note">You'll pay securely via Stripe. Payment is taken in full today.</p>
        </aside>
    </div>
<?php endif; ?>

<?php shop_layout_bottom(); ?>
