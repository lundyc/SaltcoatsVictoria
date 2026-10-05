<?php
/** Partner logo band: principal partners in colour, then club partners in uniform tiles.
 *  $sponsors: rows from pub_sponsors(). */
declare(strict_types=1);

/** @var list<array<string,mixed>> $sponsors */
$sponsors = $sponsors ?? [];
if ($sponsors === []) {
    return;
}

$main = array_values(array_filter($sponsors, static fn ($s) => (int) $s['is_main_sponsor'] === 1));
$rest = array_values(array_filter($sponsors, static fn ($s) => (int) $s['is_main_sponsor'] !== 1));

$tile = static function (array $s, bool $isMain): void {
    $logo = uploads('sponsors/' . $s['logo_path']);
    $website = trim((string) ($s['website_url'] ?? ''));
    $img = '<img src="' . e($logo) . '" alt="' . e($s['name']) . '" loading="lazy">';
    $cls = $isMain ? ' class="is-main"' : '';
    if ($website !== '') {
        echo '<a' . $cls . ' href="' . e($website) . '" rel="noopener" target="_blank" title="' . e($s['name']) . '">' . $img . '</a>';
    } else {
        echo '<span' . $cls . ' title="' . e($s['name']) . '">' . $img . '</span>';
    }
};
?>
<section class="partners" aria-label="Club partners">
  <div class="container">
    <div class="partners__head">
      <span class="eyebrow">Proudly supported by</span>
      <h2 class="h2">Our partners</h2>
    </div>
    <?php if ($main !== []): ?>
      <div class="partners__group">
        <p class="partners__label">Principal partners</p>
        <div class="partners__grid partners__grid--main">
          <?php foreach ($main as $s) { $tile($s, true); } ?>
        </div>
      </div>
    <?php endif; ?>
    <?php if ($rest !== []): ?>
      <div class="partners__group">
        <p class="partners__label">Club partners</p>
        <div class="partners__grid">
          <?php foreach ($rest as $s) { $tile($s, false); } ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
