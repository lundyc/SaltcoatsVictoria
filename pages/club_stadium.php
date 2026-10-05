<?php
/** Route: /club/stadium — the ground, with a fact panel + map. */
declare(strict_types=1);

$page = club_page_by_slug(db(), 'stadium');
$body = $page ? club_page_render($page) : '';

$name = club('ground_name', 'Campbell Park');
$address = club('ground_address');
$station = club('ground_station');
$record = club('ground_record_attendance');
$mapsUrl = club('ground_maps_url');
$mapQuery = $address !== '' ? $address : $name . ', Saltcoats';
$mapEmbed = 'https://www.google.com/maps?q=' . rawurlencode($mapQuery) . '&output=embed';
if ($mapsUrl === '') {
    $mapsUrl = 'https://www.google.com/maps?q=' . rawurlencode($mapQuery);
}

set_meta([
    'title' => $name . ' | ' . club('club_name') . ' Home Ground',
    'title_full' => '1',
    'description' => $name . ' — ' . club('club_name') . '. ' . ($address !== '' ? $address . '. ' : '') . 'Facilities, directions and matchday information.',
]);
seo_breadcrumbs([['Club', url('club/history')], [$name, url('club/stadium')]]);

pub_jsonld(['@type' => 'StadiumOrArena', 'name' => $name, 'address' => $address,
    'url' => current_url_origin() . url('club/stadium'), 'tenant' => ['@id' => current_url_origin() . '/#club'],
    'hasMap' => $mapsUrl]);
?>
<?php partial('page_hero', ['eyebrow' => 'The club', 'title' => $name, 'sub' => $address]); ?>

<div class="page">
  <div class="container clay">
    <div class="prose prose--lead">
      <?= $body !== '' ? $body : '<p>Information about ' . e($name) . ' is coming soon.</p>' ?>
    </div>

    <aside>
      <div class="factbox">
        <h2>Ground</h2>
        <dl>
          <?php if ($address !== ''): ?><div><dt>Address</dt><dd><?= e($address) ?></dd></div><?php endif; ?>
          <?php if ($station !== ''): ?><div><dt>Nearest station</dt><dd><?= e($station) ?></dd></div><?php endif; ?>
          <?php if ($record !== ''): ?><div><dt>Record attendance</dt><dd><?= e($record) ?></dd></div><?php endif; ?>
        </dl>
        <a class="btn btn--sm" style="margin-top:1.1rem;width:100%;justify-content:center" href="<?= e($mapsUrl) ?>" target="_blank" rel="noopener">Get directions</a>
      </div>
    </aside>
  </div>

  <div class="container">
    <div class="mapembed">
      <iframe src="<?= e($mapEmbed) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Map of <?= e($name) ?>"></iframe>
    </div>
  </div>
</div>
