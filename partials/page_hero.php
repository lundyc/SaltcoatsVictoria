<?php
/**
 * Unified page hero — the standard title block for every interior page.
 * Full-width maroon band: optional back-link, optional eyebrow, <h1>, optional sub-line.
 *
 * @var string      $eyebrow  small uppercase kicker (optional)
 * @var string      $title    the <h1> text (required)
 * @var string      $sub      one plain-text line under the title (optional)
 * @var array{href:string,label?:string} $back  a "‹ back" link above the title (optional)
 */
declare(strict_types=1);

$eyebrow = trim((string) ($eyebrow ?? ''));
$title   = (string) ($title ?? '');
$sub     = trim((string) ($sub ?? ''));
$back    = (isset($back) && is_array($back) && !empty($back['href'])) ? $back : null;
?>
<section class="pagehero">
  <div class="container">
    <?php if ($back !== null): ?>
      <a class="pagehero__back" href="<?= e($back['href']) ?>"><?= e($back['label'] ?? 'Back') ?></a>
    <?php endif; ?>
    <?php if ($eyebrow !== ''): ?><span class="eyebrow"><?= e($eyebrow) ?></span><?php endif; ?>
    <h1><?= e($title) ?></h1>
    <?php if ($sub !== ''): ?><p class="pagehero__sub"><?= e($sub) ?></p><?php endif; ?>
  </div>
</section>
