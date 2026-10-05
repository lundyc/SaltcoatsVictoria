<?php
/** Featured-news hero. $items: rows from news_featured() (1–5). */
declare(strict_types=1);

/** @var list<array<string,mixed>> $items */
$items = $items ?? [];
if ($items === []) {
    return;
}
$lead = $items[0];
$rest = array_slice($items, 1, 3);

$card = static function (array $a, bool $large) {
    $hero = trim((string) ($a['hero_image_path'] ?? ''));
    $img = $hero !== '' ? news_image_url($hero) : '';
    $href = url('news/' . $a['slug']);
    ?>
    <a class="hcard <?= $large ? 'hcard--lead' : '' ?>" href="<?= e($href) ?>">
      <span class="hcard__imgwrap">
        <?php if ($img !== ''): ?>
          <img class="hcard__img" src="<?= e($img) ?>" alt=""<?= ($hv = news_hero_vars($a, $large ? 'home' : 'card')) !== '' ? ' style="' . e($hv) . '"' : '' ?><?= $large ? ' fetchpriority="high" decoding="async"' : ' loading="lazy" decoding="async"' ?>>
        <?php endif; ?>
        <span class="hcard__scrim"></span>
      </span>
      <span class="hcard__inner">
        <span class="hcard__cat"><?= e($a['category'] ?? 'News') ?></span>
        <span class="hcard__title"><?= e($a['title'] ?? '') ?></span>
        <?php if (($d = format_date($a['published_at'] ?? '', 'D j M Y')) !== ''): ?>
          <span class="hcard__date"><?= e($d) ?></span>
        <?php endif; ?>
      </span>
    </a>
    <?php
};
?>
<section class="hfeature">
  <div class="hfeature__grid">
    <?php $card($lead, true); ?>
    <?php if ($rest): ?>
      <div class="hfeature__rail">
        <?php foreach ($rest as $a) { $card($a, false); } ?>
      </div>
    <?php endif; ?>
  </div>
</section>
