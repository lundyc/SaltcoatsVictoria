<?php
/** Route: /gallery/{slug} — one photo album with a lightbox. */
declare(strict_types=1);

$data = pub_gallery((string) ($slug ?? ''));
if ($data === null) {
    http_response_code(404);
    set_meta(['title' => 'Album not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Album not found</h1><p><a class="linkarrow" href="' . e(url('gallery')) . '">All albums</a></p></div></div>';
    return;
}

$album  = $data['album'];
$photos = $data['photos'];

$fixtureId = (int) ($album['fixture_id'] ?? 0);
$fixtureLine = '';
if ($fixtureId > 0 && !empty($album['fixture_opponent'])) {
    $fixtureLine = ((int) ($album['fixture_is_home'] ?? 1) === 1 ? 'v ' : 'away to ') . (string) $album['fixture_opponent']
        . (!empty($album['fixture_date']) ? ' · ' . format_date($album['fixture_date'], 'j F Y') : '');
}

set_meta([
    'title' => $album['title'],
    'description' => trim((string) ($album['description'] ?? '')) !== ''
        ? (string) $album['description']
        : 'Photo album — ' . $album['title'] .
            (!empty($album['album_date']) ? ' (' . format_date($album['album_date'], 'F Y') . ')' : ''),
    'image' => $album['cover_path'] ? current_url_origin() . uploads($album['cover_path']) : '',
]);
seo_breadcrumbs([['Gallery', url('gallery')], [(string) $album['title'], url('gallery/' . $album['slug'])]]);
?>
<?php partial('page_hero', [
    'back'  => ['href' => url('gallery'), 'label' => 'All albums'],
    'title' => $album['title'],
    'sub'   => count($photos) . ' photo' . (count($photos) === 1 ? '' : 's')
        . (!empty($album['album_date']) ? ' · ' . format_date($album['album_date'], 'j F Y') : ''),
]); ?>

<div class="page">
  <div class="container">
    <?php if (trim((string) ($album['description'] ?? '')) !== '' || $fixtureLine !== ''): ?>
      <p class="prose" style="margin-top:-0.4rem;margin-bottom:1.6rem;">
        <?php if (trim((string) ($album['description'] ?? '')) !== ''): ?><?= e($album['description']) ?><?php endif; ?>
        <?php if ($fixtureLine !== ''): ?>
          <?php if (trim((string) ($album['description'] ?? '')) !== ''): ?><br><?php endif; ?>
          <a class="linkarrow" href="<?= e(url('match/' . $fixtureId)) ?>"><?= e($fixtureLine) ?></a>
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if (!$photos): ?>
      <div class="emptystate"><p>This album has no photos.</p></div>
    <?php else: ?>
      <div class="album-grid" data-lightbox>
        <?php foreach ($photos as $i => $p): ?>
          <a class="album-grid__item" href="<?= e(uploads($p['file_path'])) ?>"
             data-full="<?= e(uploads($p['file_path'])) ?>" data-caption="" data-index="<?= $i ?>">
            <img src="<?= e(uploads($p['thumb_path'] ?: $p['file_path'])) ?>" alt="<?= e(trim((string) ($album['title'] ?? 'Album')) . ' – photo ' . ($i + 1) . ' of ' . count($photos)) ?>" loading="lazy">
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php partial('lightbox'); ?>
