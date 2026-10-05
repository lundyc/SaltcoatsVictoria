<?php
/** Secondary bar for shop pages: shop home link + basket link with count.
 *  $basketCount (int), $categories (list, optional), $activeCategoryId (int). */
declare(strict_types=1);

$basketCount = (int) ($basketCount ?? 0);
$categories = $categories ?? [];
$activeCategoryId = (int) ($activeCategoryId ?? 0);
$crumbs = $crumbs ?? [];
$showSearch = !empty($showSearch);
$searchQuery = (string) ($searchQuery ?? '');
?>
<div class="shopbar">
  <div class="container shopbar__inner">
    <a class="shopbar__home" href="<?= e(url('shop')) ?>">Club shop</a>
    <?php
      $flatCats = [];
      foreach ($categories as $c) {
          $flatCats[] = $c;
          foreach (($c['children'] ?? []) as $sub) {
              $flatCats[] = $sub;
          }
      }
    ?>
    <?php if ($crumbs): ?>
      <nav class="crumbs shopbar__crumbs" aria-label="Breadcrumb">
        <?php foreach ($crumbs as $i => [$label, $href]): ?>
          <?php if ($i > 0): ?><span class="crumbs__sep" aria-hidden="true">›</span><?php endif; ?>
          <?php if ($href !== null): ?><a href="<?= e($href) ?>"><?= e($label) ?></a><?php else: ?><span aria-current="page"><?= e($label) ?></span><?php endif; ?>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <?php if (count($flatCats) > 1): ?>
      <nav class="shopbar__cats" aria-label="Shop categories">
        <a class="<?= $activeCategoryId === 0 ? 'is-active' : '' ?>" href="<?= e(url('shop')) ?>">All</a>
        <?php foreach ($categories as $c): ?>
          <a class="<?= $activeCategoryId === (int) $c['id'] ? 'is-active' : '' ?>" href="<?= e(url('shop') . '?category=' . (int) $c['id']) ?>"><?= e($c['name']) ?></a>
          <?php foreach (($c['children'] ?? []) as $sub): ?>
            <a class="shopbar__subcat <?= $activeCategoryId === (int) $sub['id'] ? 'is-active' : '' ?>" href="<?= e(url('shop') . '?category=' . (int) $sub['id']) ?>"><?= e($sub['name']) ?></a>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
    <?php if ($showSearch): ?>
      <form class="shopsearch<?= $searchQuery === '' ? ' is-collapsed' : '' ?>" method="get" action="<?= e(url('shop')) ?>" role="search" data-shopsearch>
        <?php if ($activeCategoryId > 0): ?><input type="hidden" name="category" value="<?= (int) $activeCategoryId ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="Search products&hellip;" aria-label="Search products">
        <?php if ($searchQuery !== ''): ?><a class="shopsearch__clear" href="<?= e(url('shop') . ($activeCategoryId > 0 ? '?category=' . (int) $activeCategoryId : '')) ?>">Clear</a><?php endif; ?>
        <button type="submit" class="shopsearch__btn" aria-label="Search products"><?= pub_icon('search') ?></button>
      </form>
      <script>
      (function () {
        var f = document.querySelector('[data-shopsearch]');
        if (!f) return;
        var i = f.querySelector('input[type="search"]');
        f.querySelector('.shopsearch__btn').addEventListener('click', function (e) {
          if (f.classList.contains('is-collapsed')) {
            e.preventDefault();
            f.classList.remove('is-collapsed');
            i.focus();
          } else if (i.value.trim() === '') {
            e.preventDefault();
            f.classList.add('is-collapsed');
          }
        });
        i.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && i.value.trim() === '') { f.classList.add('is-collapsed'); }
        });
      })();
      </script>
    <?php endif; ?>
    <a class="shopbar__basket" href="<?= e(url('shop/basket')) ?>">
      <?= pub_icon('basket') ?: '🧺' ?>
      <span>Basket<?php if ($basketCount > 0): ?> <b><?= $basketCount ?></b><?php endif; ?></span>
    </a>
  </div>
</div>
