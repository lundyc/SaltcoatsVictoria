<?php
/** News listing. Route: /news  and  /news/category/{slug}. */
declare(strict_types=1);

/** @var string $slug category slug when arriving from /news/category/{slug} */
$categorySlug = $slug ?? '';
$activeCategory = '';
foreach (news_categories() as $cat) {
    if (news_slugify($cat) === $categorySlug) {
        $activeCategory = $cat;
        break;
    }
}
if ($categorySlug !== '' && $activeCategory === '') {
    // Unknown category slug — treat as not found.
    http_response_code(404);
    set_meta(['title' => 'News']);
    echo '<div class="page"><div class="container"><h1>Category not found</h1>'
        . '<p><a class="linkarrow" href="' . e(url('news')) . '">All news</a></p></div></div>';
    return;
}

$perPage = 12;
$page = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$total = news_published_count(db(), $activeCategory ?: null);
$articles = news_published(db(), [
    'category' => $activeCategory ?: null,
    'limit' => $perPage,
    'offset' => $offset,
]);
$lastPage = max(1, (int) ceil($total / $perPage));

$newsBase = $activeCategory !== '' ? url('news/category/' . news_slugify($activeCategory)) : url('news');
if ($page > $lastPage && $total > 0) {
    http_response_code(404);
}
set_meta([
    'title' => $activeCategory !== '' ? ucwords(strtolower($activeCategory)) . ' News' : club('club_name') . ' News',
    'description' => 'The latest ' . ($activeCategory !== '' ? strtolower($activeCategory) . ' ' : '') . 'news from ' . club('club_name')
        . ($page > 1 ? ' — page ' . $page . ' of the archive.' : ' — match reports, club announcements and updates.'),
    'rel_prev' => $page > 1 ? $newsBase . ($page > 2 ? '?page=' . ($page - 1) : '') : '',
    'rel_next' => $page < $lastPage ? $newsBase . '?page=' . ($page + 1) : '',
]);
seo_breadcrumbs($activeCategory !== '' ? [['News', url('news')], [$activeCategory, $newsBase]] : [['News', url('news')]]);
?>
<?php partial('page_hero', ['eyebrow' => 'Latest', 'title' => $activeCategory !== '' ? $activeCategory : 'Club news']); ?>

<div class="page">
  <div class="container">
    <nav class="chipnav" aria-label="News categories">
      <a class="chipnav__item <?= $activeCategory === '' ? 'is-active' : '' ?>" href="<?= e(url('news')) ?>">All</a>
      <?php foreach (news_categories() as $cat): ?>
        <a class="chipnav__item <?= $activeCategory === $cat ? 'is-active' : '' ?>"
           href="<?= e(url('news/category/' . news_slugify($cat))) ?>"><?= e($cat) ?></a>
      <?php endforeach; ?>
    </nav>

    <?php if ($articles === []): ?>
      <div class="emptystate"><p>No articles here yet — check back soon.</p></div>
    <?php else: ?>
      <div class="news-grid">
        <?php foreach ($articles as $article): ?>
          <?php partial('news_card', ['article' => $article]); ?>
        <?php endforeach; ?>
      </div>

      <?php if ($lastPage > 1): ?>
        <nav class="pager" aria-label="News pages">
          <?php $base = $activeCategory !== '' ? url('news/category/' . news_slugify($activeCategory)) : url('news'); ?>
          <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" rel="prev" href="<?= e($base . ($page > 2 ? '?page=' . ($page - 1) : '')) ?>">← Newer</a><?php endif; ?>
          <span class="pager__count">Page <?= $page ?> of <?= $lastPage ?></span>
          <?php if ($page < $lastPage): ?><a class="btn btn--ghost btn--sm" rel="next" href="<?= e($base . '?page=' . ($page + 1)) ?>">Older →</a><?php endif; ?>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
