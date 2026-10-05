<?php
/** Route: /shop — storefront home. Logic mirrors the live shop/index.php;
 *  presentation is the public-site design. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/shop.php';
require_once HUB_ROOT . '/lib/shop_basket.php';

shop_ensure_schema(db());
$settings = shop_get_settings(db());
$shopEnabled = ($settings['shop_enabled'] ?? '1') === '1';

$activeCategoryId = (int) ($_GET['category'] ?? 0);
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$categories = shop_category_tree(db(), false);

$filters = ['active_only' => true];
if ($activeCategoryId > 0) {
    $filters['category_id'] = $activeCategoryId;
}
if ($searchQuery !== '') {
    $filters['search'] = $searchQuery;
}
$products = $shopEnabled ? shop_get_products(db(), $filters) : [];
$basketCount = shop_basket_count();

// Heading for the product list: the category being browsed, or a search / "all" title.
$activeCategoryName = '';
foreach ($categories as $c) {
    if ((int) $c['id'] === $activeCategoryId) { $activeCategoryName = (string) $c['name']; break; }
    foreach (($c['children'] ?? []) as $sub) {
        if ((int) $sub['id'] === $activeCategoryId) { $activeCategoryName = (string) $sub['name']; break 2; }
    }
}
$listTitle = $searchQuery !== '' ? "Results for “" . $searchQuery . "”" : ($activeCategoryName !== '' ? $activeCategoryName : 'All products');
$listEyebrow = $searchQuery !== '' ? 'Search' : ($activeCategoryName !== '' ? 'Category' : 'Browse');

$shopIntro = trim((string) ($settings['shop_intro'] ?? ''));
set_meta([
    'title' => ($activeCategoryName !== '' ? $activeCategoryName . ' | ' : '') . 'Official Club Shop',
    'description' => mb_strlen($shopIntro) >= 60
        ? $shopIntro
        : 'Buy official ' . club('club_name') . ' merchandise from the club shop — kits, clothing and more. Every purchase supports the club.',
    'canonical' => $activeCategoryId > 0 || $searchQuery !== '' ? url('shop') : '',
    'robots' => $searchQuery !== '' ? 'noindex, follow' : '',
]);
seo_breadcrumbs([['Club shop', url('shop')]]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Official merchandise',
    'title'   => 'Club Shop',
    'sub'     => 'Support the Seasiders every day of the week. Every purchase helps the club.',
]); ?>

<?php partial('shop_bar', ['basketCount' => $basketCount, 'categories' => $categories, 'activeCategoryId' => $activeCategoryId, 'showSearch' => $shopEnabled, 'searchQuery' => $searchQuery]); ?>

<div class="page">
  <div class="container">
    <?php if ($shopEnabled): ?>
      <div class="band__head shoplist-head">
        <div><span class="eyebrow"><?= e($listEyebrow) ?></span><h2><?= e($listTitle) ?></h2></div>
      </div>
    <?php endif; ?>
    <?php if (function_exists('shop_render_preorder_notice')): ?>
      <div class="shopnote">
        <?php ob_start(); shop_render_preorder_notice($settings, 'full'); $raw = ob_get_clean();
          // strip the live shop's own markup wrappers, keep the sentences
          echo strip_tags($raw, '<p><strong>'); ?>
      </div>
    <?php endif; ?>

    <?php if (!$shopEnabled): ?>
      <div class="notice notice--warn"><p>The club shop is temporarily closed. Please check back soon.</p></div>
    <?php elseif (!$products): ?>
      <div class="emptystate"><p><?= $searchQuery !== '' ? 'No products match your search.' : 'There are no products on sale right now.' ?></p></div>
    <?php else: ?>
      <div class="prodgrid">
        <?php foreach ($products as $product): ?>
          <?php
          $orderable = shop_product_is_orderable(db(), $product);
          $close = shop_product_preorder_close(db(), $product);
          $closed = $close !== null && new DateTimeImmutable('now') > $close;
          $img = trim((string) ($product['image_path'] ?? ''));
          ?>
          <a class="prodcard" href="<?= e(url('shop/p/' . rawurlencode((string) $product['slug']))) ?>">
            <div class="prodcard__media<?= $img === '' ? ' is-placeholder' : '' ?>"
                 <?= $img !== '' ? 'style="background-image:url(\'' . e($img) . '\')"' : '' ?>>
              <div class="prodcard__badges">
                <?php if ((int) $product['is_preorder'] === 1 && !$closed): ?><span class="tag tag--gold">Pre-order</span><?php endif; ?>
                <?php if ($closed): ?><span class="tag tag--muted">Pre-order closed</span><?php endif; ?>
                <?php if (!$orderable && !$closed): ?><span class="tag tag--muted">Unavailable</span><?php endif; ?>
              </div>
            </div>
            <div class="prodcard__body">
              <span class="prodcard__cat"><?= e((string) $product['category_name']) ?></span>
              <span class="prodcard__name"><?= e((string) $product['name']) ?></span>
              <span class="prodcard__price"><?= e(gbp((float) $product['price'])) ?></span>
              <span class="prodcard__cta"><?= $closed ? 'View details' : 'Choose options →' ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
