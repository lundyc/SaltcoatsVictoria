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
$sort = (string) ($_GET['sort'] ?? '');
$sortOptions = ['' => 'Featured', 'newest' => 'Newest', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'name' => 'Name A–Z'];
if (!isset($sortOptions[$sort])) { $sort = ''; }
if ($sort !== '') {
    usort($products, static function (array $a, array $b) use ($sort): int {
        return match ($sort) {
            'price_asc' => shop_product_price($a) <=> shop_product_price($b),
            'price_desc' => shop_product_price($b) <=> shop_product_price($a),
            'name' => strcasecmp((string) $a['name'], (string) $b['name']),
            default => (int) $b['id'] <=> (int) $a['id'],
        };
    });
} else {
    // Default: featured items first, otherwise keep the admin's ordering.
    usort($products, static fn(array $a, array $b): int => (int) $b['is_featured'] <=> (int) $a['is_featured']);
}
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

<?php if ($shopEnabled): ?>
<?php partial('shop_bar', ['basketCount' => $basketCount, 'categories' => $categories, 'activeCategoryId' => $activeCategoryId, 'showSearch' => $shopEnabled, 'searchQuery' => $searchQuery]); ?>
<?php endif; ?>

<div class="page">
  <div class="container">
    <?php if ($shopEnabled): ?>
      <div class="band__head shoplist-head">
        <div><span class="eyebrow"><?= e($listEyebrow) ?></span><h2><?= e($listTitle) ?></h2></div>
        <form method="get" class="shopsort">
          <?php if ($activeCategoryId > 0): ?><input type="hidden" name="category" value="<?= (int) $activeCategoryId ?>"><?php endif; ?>
          <?php if ($searchQuery !== ''): ?><input type="hidden" name="q" value="<?= e($searchQuery) ?>"><?php endif; ?>
          <label for="shop-sort">Sort by</label>
          <select id="shop-sort" name="sort" onchange="this.form.submit()">
            <?php foreach ($sortOptions as $k => $label): ?><option value="<?= e($k) ?>"<?= $sort === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
          <span class="shopsort__count"><?= count($products) ?> product<?= count($products) === 1 ? '' : 's' ?></span>
        </form>
      </div>
    <?php endif; ?>
    <?php if (!$shopEnabled): ?>
      <section class="shopclosed" aria-labelledby="shopclosed-title">
        <div class="shopclosed__icon" aria-hidden="true"><?= pub_icon('basket') ?></div>
        <span class="eyebrow">Temporarily closed</span>
        <h2 id="shopclosed-title">The club shop is currently closed</h2>
        <p class="shopclosed__lead">We&rsquo;re not taking orders at the moment. We&rsquo;ll reopen when our next range is ready, so please check back soon.</p>
        <ul class="shopclosed__points">
          <li><strong>Existing orders</strong><span>Orders already placed are not affected. We&rsquo;ll be in touch about collection or delivery.</span></li>
          <li><strong>Stay up to date</strong><span>Reopening dates and new kit announcements are posted in our latest news and on our social channels.</span></li>
          <li><strong>Need help?</strong><span>Questions about an order or the shop? Get in touch and we&rsquo;ll help.</span></li>
        </ul>
        <div class="shopclosed__actions">
          <a class="btn btn--lg" href="<?= e(url('news')) ?>">Latest club news</a>
          <a class="btn btn--ghost btn--lg" href="<?= e(url('contact')) ?>">Contact the club</a>
        </div>
      </section>
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
                <?php if (shop_product_on_sale($product)): ?><span class="tag tag--gold">Sale</span><?php endif; ?>
                <?php if ((int) $product['is_featured'] === 1 && (int) $product['is_preorder'] !== 1 && !shop_product_on_sale($product)): ?><span class="tag">Featured</span><?php endif; ?>
                <?php if (shop_product_availability($product) === 'onbackorder'): ?><span class="tag tag--muted">On backorder</span><?php endif; ?>
                <?php if (!$orderable && !$closed): ?><span class="tag tag--muted"><?= shop_product_availability($product) === 'outofstock' ? 'Sold out' : 'Unavailable' ?></span><?php endif; ?>
              </div>
            </div>
            <div class="prodcard__body">
              <span class="prodcard__cat"><?= e((string) $product['category_name']) ?></span>
              <span class="prodcard__name"><?= e((string) $product['name']) ?></span>
              <span class="prodcard__price"><?php if (shop_product_on_sale($product)): ?><s class="prodcard__was"><?= e(gbp((float) $product['price'])) ?></s> <?php endif; ?><?= e(gbp(shop_product_price($product))) ?></span>
              <?php if ((int) $product['is_preorder'] === 1 && !$closed && $close !== null): ?><span class="prodcard__meta">Pre-order · closes <?= e($close->format('j M')) ?></span><?php endif; ?>
              <span class="prodcard__cta"><?= $closed || !$orderable ? 'View details' : 'Choose options →' ?></span>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
