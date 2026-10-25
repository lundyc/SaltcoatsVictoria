<?php
/** Partner logo band: principal partners in colour, then club partners in uniform tiles.
 *  $sponsors: rows from pub_sponsors(). */
declare(strict_types=1);

/** @var list<array<string,mixed>> $sponsors */
$sponsors = array_values(array_filter($sponsors ?? [], static fn ($s) => trim((string) ($s['logo_path'] ?? '')) !== ''));
if ($sponsors === []) {
    return;
}

$main = array_values(array_filter($sponsors, static fn ($s) => (int) $s['is_main_sponsor'] === 1));
$rest = array_values(array_filter($sponsors, static fn ($s) => (int) $s['is_main_sponsor'] !== 1));

$tile = static function (array $s, bool $isMain): void {
    $website = trim((string) ($s['website_url'] ?? ''));
    // Use the edge-to-edge copy of the logo (white margin trimmed off) when there is one.
    $fit = pub_partner_logo_fit((string) $s['logo_path']);
    $src = $fit['url'] ?? uploads('sponsors/' . $s['logo_path']);
    $dims = $fit ? ' width="' . $fit['w'] . '" height="' . $fit['h'] . '"' : '';
    $img = '<img src="' . e($src) . '" alt="' . e($s['name']) . '" loading="lazy" decoding="async"' . $dims . '>';
    // Tiles are shaped like the typical logo (about 1.9:1, principal partners 2.18:1). A logo close to that
    // shape fills its tile edge to edge; an unusual shape (very wide or square) is shown whole instead.
    $ratio = $fit && $fit['h'] > 0 ? $fit['w'] / $fit['h'] : 1.9;
    $tileRatio = $isMain ? 2.18 : 1.9;
    $classes = [];
    if ($isMain) {
        $classes[] = 'is-main';
    }
    if (abs($ratio / $tileRatio - 1) > 0.15) {
        $classes[] = 'is-contain';
    }
    $cls = $classes ? ' class="' . implode(' ', $classes) . '"' : '';
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
        <div class="partners__marquee" data-marquee>
          <div class="partners__grid" data-marquee-track>
            <?php foreach ($rest as $s) { $tile($s, false); } ?>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
