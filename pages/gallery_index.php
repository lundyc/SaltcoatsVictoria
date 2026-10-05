<?php
/** Route: /gallery — photo album index (history_galleries). */
declare(strict_types=1);

$albums = pub_galleries();

set_meta([
    'title' => 'Photo gallery',
    'description' => 'Matchday and club photo albums from ' . club('club_name') . '.',
]);

// Group by year (undated last).
$byYear = [];
foreach ($albums as $a) {
    $year = !empty($a['album_date']) ? substr((string) $a['album_date'], 0, 4) : 'Earlier';
    $byYear[$year][] = $a;
}
?>
<?php partial('page_hero', [
    'eyebrow' => 'Media',
    'title'   => 'Photo gallery',
    'sub'     => count($albums) . ' albums from the archive — matchdays, presentations and club occasions.',
]); ?>

<div class="page">
  <div class="container">
    <?php if (!$albums): ?>
      <div class="emptystate"><p>Photo albums are being prepared.</p></div>
    <?php else: ?>
      <?php foreach ($byYear as $year => $list): ?>
        <h2 class="squad-heading"><?= e($year) ?></h2>
        <div class="gallery-grid">
          <?php foreach ($list as $a): ?>
            <?php $count = (int) ($a['real_count'] ?: $a['photo_count']); ?>
            <a class="gcard" href="<?= e(url('gallery/' . $a['slug'])) ?>">
              <span class="gcard__media">
                <?php if ($a['cover_path']): ?>
                  <img src="<?= e(uploads($a['cover_path'])) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <span class="gcard__count"><?= $count ?> photo<?= $count === 1 ? '' : 's' ?></span>
              </span>
              <span class="gcard__body">
                <span class="gcard__title"><?= e($a['title']) ?></span>
                <?php if (!empty($a['album_date'])): ?>
                  <span class="gcard__date"><?= e(format_date($a['album_date'], 'D j M Y')) ?></span>
                <?php endif; ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
