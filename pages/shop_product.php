<?php
/** Route: /shop/p/{slug} — product detail. Mirrors live shop/product.php. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/shop.php';
require_once HUB_ROOT . '/lib/shop_basket.php';

shop_ensure_schema(db());
$settings = shop_get_settings(db());

if (($settings['shop_enabled'] ?? '1') !== '1') {
    redirect(url('shop'));
}

$slugParam = trim((string) ($slug ?? ''));
$product = $slugParam !== '' ? shop_get_product_by_slug(db(), $slugParam) : null;

$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if (!$product || (int) $product['is_active'] !== 1) {
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'That product is no longer available.']);
        exit;
    }
    http_response_code(404);
    set_meta(['title' => 'Product not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Product not found</h1><p><a class="linkarrow" href="' . e(url('shop')) . '">Back to the shop</a></p></div></div>';
    return;
}

$groups = shop_product_groups(db(), (int) $product['id'], true);
$related = shop_related_products(db(), $product, 4);
$gallery = shop_product_gallery(db(), $product);
$close = shop_product_preorder_close(db(), $product);
$closed = $close !== null && new DateTimeImmutable('now') > $close;
$orderable = shop_product_is_orderable(db(), $product);

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Please try again.';
    } elseif ((string) ($_POST['action'] ?? '') === 'add') {
        $selected = [];
        foreach ((array) ($_POST['options'] ?? []) as $groupId => $optionIds) {
            $selected[(int) $groupId] = is_array($optionIds) ? array_map('intval', $optionIds) : (int) $optionIds;
        }
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));
        try {
            shop_basket_add(db(), (int) $product['id'], $selected, $quantity);
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'basket_count' => shop_basket_count(), 'message' => $product['name'] . ' added to your basket.']);
                exit;
            }
            redirect(url('shop/basket'));
        } catch (Throwable $e) {
            $error = $e->getMessage();
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $error]);
                exit;
            }
        }
    }
}

$mainImg = $gallery[0]['path'] ?? '';
$deltaLabel = static function (float $d): string {
    if ($d > 0) return ' (+' . gbp($d) . ')';
    if ($d < 0) return ' (' . gbp($d) . ')';
    return '';
};

set_meta([
    'title' => (string) $product['name'],
    'description' => (string) ($product['summary'] ?: $product['name']),
    'image' => $mainImg !== '' ? $mainImg : '',
]);
seo_breadcrumbs([['Club shop', url('shop')], [(string) $product['name'], url('shop/p/' . $product['slug'])]]);
pub_jsonld([
    '@type' => 'Product',
    'name' => $product['name'],
    'description' => trim(strip_tags((string) ($product['summary'] ?: $product['description'] ?: $product['name']))),
    'sku' => 'P' . (int) $product['id'],
    'image' => array_values(array_map(static fn (array $g): string => seo_absolute((string) $g['path']), $gallery)),
    'brand' => ['@type' => 'Brand', 'name' => club('club_name')],
    'offers' => [
        '@type' => 'Offer',
        'url' => seo_absolute(url('shop/p/' . $product['slug'])),
        'priceCurrency' => 'GBP',
        'price' => number_format((float) $product['price'], 2, '.', ''),
        'availability' => $orderable
            ? ((int) ($product['is_preorder'] ?? 0) === 1 ? 'https://schema.org/PreOrder' : 'https://schema.org/InStock')
            : 'https://schema.org/OutOfStock',
        'seller' => ['@type' => 'Organization', 'name' => club('club_name')],
    ],
]);
?>
<?php partial('shop_bar', [
    'basketCount' => shop_basket_count(),
    'showSearch'  => true,
    'crumbs'      => [
        ['Shop', url('shop')],
        [(string) $product['category_name'], url('shop') . '?category=' . (int) $product['category_id']],
        [(string) $product['name'], null],
    ],
]); ?>

<div class="page">
  <div class="container">
    <div class="pdp">
      <div class="pdp__gallery">
        <div class="pdp__main<?= $mainImg === '' ? ' is-placeholder' : '' ?>" data-gallery-main
             <?= $mainImg !== '' ? 'style="background-image:url(\'' . e($mainImg) . '\')"' : '' ?>></div>
        <?php if (count($gallery) > 1): ?>
          <div class="pdp__thumbs">
            <?php foreach ($gallery as $i => $shot): ?>
              <button type="button" data-gallery-thumb data-src="<?= e($shot['path']) ?>"
                      class="<?= $i === 0 ? 'is-active' : '' ?>"
                      style="background-image:url('<?= e($shot['path']) ?>')" aria-label="View image <?= $i + 1 ?>"></button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="pdp__panel">
        <span class="pdp__cat"><?= e((string) $product['category_name']) ?></span>
        <h1><?= e((string) $product['name']) ?></h1>
        <div class="pdp__price"><?= e(gbp((float) $product['price'])) ?></div>
        <?php if (($product['summary'] ?? '') !== ''): ?><p class="pdp__summary"><?= e((string) $product['summary']) ?></p><?php endif; ?>

        <?php if ((int) $product['is_preorder'] === 1): ?>
          <div class="notice notice--warn">
            <strong><?= $closed ? 'Pre-orders are now closed.' : 'This is a pre-order.' ?></strong>
            <?= e((string) ($product['preorder_message'] ?: $settings['preorder_intro'] ?? '')) ?>
          </div>
        <?php endif; ?>
        <?php if ($error !== ''): ?><div class="notice notice--err"><?= e($error) ?></div><?php endif; ?>

        <?php if ($closed || !$orderable): ?>
          <p class="pdp__note"><?= $closed
            ? 'Pre-orders for this item closed on ' . e($close->format('j F Y')) . '.'
            : 'This item is currently unavailable.' ?></p>
          <a class="btn btn--ghost" href="<?= e(url('shop')) ?>">Back to the shop</a>
        <?php else: ?>
          <form method="post" action="<?= e(url('shop/p/' . rawurlencode((string) $product['slug']))) ?>" data-add-to-basket>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">

            <?php foreach ($groups as $group): ?>
              <?php
              $gid = (int) $group['id'];
              $isMulti = (string) $group['selection_type'] === 'multi';
              $required = (int) $group['is_required'] === 1;
              ?>
              <fieldset class="optgroup">
                <legend><span><?= e((string) $group['name']) ?></span><?php if ($required): ?><em>Required</em><?php endif; ?></legend>
                <?php if (($group['help_text'] ?? '') !== ''): ?><p class="optgroup__help"><?= e((string) $group['help_text']) ?></p><?php endif; ?>
                <div class="optchips">
                  <?php foreach ($group['options'] as $opt): ?>
                    <?php $oos = $opt['stock_qty'] !== null && (int) $opt['stock_qty'] <= 0; $d = (float) $opt['price_delta']; ?>
                    <label class="optchip">
                      <input type="<?= $isMulti ? 'checkbox' : 'radio' ?>"
                             name="options[<?= $gid ?>]<?= $isMulti ? '[]' : '' ?>"
                             value="<?= (int) $opt['id'] ?>"
                             <?= $required && !$isMulti ? 'required' : '' ?> <?= $oos ? 'disabled' : '' ?>>
                      <span>
                        <?php if (trim((string) ($opt['option_group'] ?? '')) !== ''): ?><?= e((string) $opt['option_group']) ?> <?php endif; ?><?= e((string) $opt['label']) ?><?= $oos ? ' — sold out' : $deltaLabel($d) ?>
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>
            <?php endforeach; ?>

            <div class="addrow">
              <div class="stepper" data-stepper>
                <button type="button" data-step="-1" aria-label="Decrease quantity">−</button>
                <input type="number" name="quantity" value="1" min="1" max="<?= (int) $product['max_per_order'] ?>" inputmode="numeric" aria-label="Quantity">
                <button type="button" data-step="1" aria-label="Increase quantity">+</button>
              </div>
              <button type="submit" class="btn">Add to basket</button>
            </div>
            <p class="pdp__note">Maximum <?= (int) $product['max_per_order'] ?> per order. Collection only from
              <?= e((string) ($settings['collection_point'] ?? 'Campbell Park')) ?> — no delivery.</p>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (trim((string) ($product['description'] ?? '')) !== ''): ?>
      <div class="pdp__desc prose">
        <h2>Product details</h2>
        <?= nl2br(e((string) $product['description'])) ?>
      </div>
    <?php endif; ?>

    <?php if ($related): ?>
      <div class="pdp__related">
        <h2>You might also like</h2>
        <div class="prodgrid">
          <?php foreach ($related as $rp): ?>
            <?php $rimg = trim((string) ($rp['image_path'] ?? '')); ?>
            <a class="prodcard" href="<?= e(url('shop/p/' . rawurlencode((string) $rp['slug']))) ?>">
              <div class="prodcard__media<?= $rimg === '' ? ' is-placeholder' : '' ?>"
                   <?= $rimg !== '' ? 'style="background-image:url(\'' . e($rimg) . '\')"' : '' ?>></div>
              <div class="prodcard__body">
                <span class="prodcard__cat"><?= e((string) $rp['category_name']) ?></span>
                <span class="prodcard__name"><?= e((string) $rp['name']) ?></span>
                <span class="prodcard__price"><?= e(gbp((float) $rp['price'])) ?></span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
