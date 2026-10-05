<?php

declare(strict_types=1);

// Public storefront home — /shop  (see .htaccess). No login required.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/functions.php';
require_once __DIR__ . '/../lib/shop.php';
require_once __DIR__ . '/../lib/shop_basket.php';
require_once __DIR__ . '/_layout.php';

shop_ensure_schema($pdo);
$settings = shop_get_settings($pdo);
$shopEnabled = ($settings['shop_enabled'] ?? '1') === '1';

$activeCategoryId = (int) ($_GET['category'] ?? 0);
$categories = shop_get_categories($pdo, false);

$productFilters = ['active_only' => true];
if ($activeCategoryId > 0) {
    $productFilters['category_id'] = $activeCategoryId;
}
$products = $shopEnabled ? shop_get_products($pdo, $productFilters) : [];

$basketCount = shop_basket_count();

// Use the first available catalogue photograph for the shared shop link.
$previewImage = '';
$previewAlt = '';
foreach ($products as $previewProduct) {
    $previewImage = trim((string) ($previewProduct['image_path'] ?? ''));
    if ($previewImage !== '') {
        $previewAlt = (string) $previewProduct['name'];
        break;
    }
}

shop_layout_top([
    'title' => 'Club Shop',
    'description' => (string) ($settings['shop_intro'] ?? 'Official Saltcoats Victoria FC merchandise.'),
    'active' => 'home',
    'basket_count' => $basketCount,
    'canonical' => stripe_public_base_url() . '/shop/',
    'product_image' => $previewImage,
    'image_alt' => $previewAlt,
]);
?>
<section class="shop-hero">
    <div class="shop-hero__eyebrow">Saltcoats Victoria FC</div>
    <h1><?= h((string) ($settings['shop_name'] ?? 'Club Shop')) ?></h1>
    <p><?= h((string) ($settings['shop_intro'] ?? 'Official Saltcoats Victoria FC merchandise. Every order is made to order by our kit manufacturer VSN.')) ?></p>
</section>

<?php shop_render_preorder_notice($settings, 'full'); ?>

<?php if (!$shopEnabled): ?>
    <div class="shop-alert shop-alert--warn">The Club Shop is temporarily closed. Please check back soon.</div>
<?php elseif (!$products): ?>
    <div class="shop-empty">
        <i class="fa-solid fa-shirt" aria-hidden="true"></i>
        <p>There are no products on sale right now.</p>
    </div>
<?php else: ?>
    <?php if (count($categories) > 1): ?>
        <nav class="shop-catrow" aria-label="Categories">
            <a href="/shop/" class="<?= $activeCategoryId === 0 ? 'is-active' : '' ?>">All</a>
            <?php foreach ($categories as $category): ?>
                <a href="/shop/?category=<?= (int) $category['id'] ?>" class="<?= $activeCategoryId === (int) $category['id'] ? 'is-active' : '' ?>"><?= h((string) $category['name']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <div class="shop-grid">
        <?php foreach ($products as $product): ?>
            <?php
            $orderable = shop_product_is_orderable($pdo, $product);
            $close = shop_product_preorder_close($pdo, $product);
            $closed = $close !== null && new DateTimeImmutable('now') > $close;
            $img = trim((string) ($product['image_path'] ?? ''));
            ?>
            <a class="shop-card" href="/shop/p/<?= h((string) $product['slug']) ?>">
                <div class="shop-card__media <?= $img === '' ? 'shop-card__media--placeholder' : '' ?>"
                     <?= $img !== '' ? 'style="background-image:url(\'' . h($img) . '\')"' : '' ?>>
                    <?php if ($img === ''): ?><i class="fa-solid fa-shirt" aria-hidden="true"></i><?php endif; ?>
                    <div class="shop-card__badges">
                        <?php if ((int) $product['is_preorder'] === 1 && !$closed): ?><span class="shop-badge shop-badge--gold">Pre-order</span><?php endif; ?>
                        <?php if ($closed): ?><span class="shop-badge shop-badge--muted">Pre-order closed</span><?php endif; ?>
                        <?php if (!$orderable && !$closed): ?><span class="shop-badge shop-badge--muted">Unavailable</span><?php endif; ?>
                    </div>
                </div>
                <div class="shop-card__body">
                    <span class="shop-card__cat"><?= h((string) $product['category_name']) ?></span>
                    <span class="shop-card__name"><?= h((string) $product['name']) ?></span>
                    <span class="shop-card__price"><?= h(gbp((float) $product['price'])) ?></span>
                    <span class="shop-card__cta"><?= $closed ? 'View details' : 'Choose options →' ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php shop_layout_bottom(); ?>
