<?php
/** News teaser card. $article: row from lib/news.php (arrives in build step 4).
 *  Written now so home/news pages can drop it in unchanged. */
declare(strict_types=1);

/** @var array<string,mixed> $article */
$slug = (string) ($article['slug'] ?? '');
$href = $slug !== '' ? url('news/' . $slug) : url('news');
$hero = trim((string) ($article['hero_image_path'] ?? ''));
$category = trim((string) ($article['category'] ?? ''));
$date = format_date($article['published_at'] ?? ($article['created_at'] ?? ''), 'D j M Y');
?>
<article class="newscard">
  <a href="<?= e($href) ?>">
    <div class="newscard__media">
      <?php if ($hero !== ''): ?>
        <img src="<?= e(news_image_url($hero)) ?>" alt="" loading="lazy"<?= ($v = news_hero_vars($article)) !== '' ? ' style="' . e($v) . '"' : '' ?>>
      <?php endif; ?>
    </div>
    <div class="newscard__body">
      <?php if ($category !== ''): ?><span class="newscard__cat"><?= e($category) ?></span><?php endif; ?>
      <h3><?= e($article['title'] ?? 'Club news') ?></h3>
      <?php if (!empty($article['excerpt'])): ?><p><?= e(excerpt((string) $article['excerpt'], 130)) ?></p><?php endif; ?>
      <?php if ($date !== ''): ?><span class="newscard__date"><?= e($date) ?></span><?php endif; ?>
    </div>
  </a>
</article>
