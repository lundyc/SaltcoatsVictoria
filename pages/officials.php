<?php
/** Route: /club/officials — committee, volunteers, and what the club stands for
 *  (mission / vision / values). */
declare(strict_types=1);

$mvvPage = club_page_by_slug(db(), 'mission-vision-values');
$mvvHtml = $mvvPage ? pub_club_page_html($mvvPage) : '';
$mvv     = pub_mvv_parse($mvvHtml);
$mvvOk   = $mvv['mission'] !== '' || $mvv['vision'] !== [] || $mvv['values'] !== [];

$people     = pub_staff_officials();
$volunteers = pub_staff_volunteers(array_column($people, 'name'));

// Optional intro text from the editable club_pages "officials" row — but not
// the seed placeholder that ships before anyone has written the page.
$page = club_page_by_slug(db(), 'officials');
$intro = '';
if ($page) {
    $rendered = pub_club_page_html($page);
    if (stripos(strip_tags($rendered), 'has not been written') === false) {
        $intro = $rendered;
    }
}

set_meta([
    'title' => club('club_name') . ' Committee, Volunteers & Values',
    'description' => club('club_name') . ' committee, volunteers, and our mission, vision and values.',
]);
seo_breadcrumbs([['Club', url('club/history')], ['Committee, volunteers & values', url('club/officials')]]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'The club',
    'title'   => 'Committee, volunteers & values',
    'sub'     => 'The people who run ' . club('club_name') . ', and what we stand for.',
]); ?>

<section class="band band--surface" id="values">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">What we stand for</span>
      <h2>Mission, vision &amp; values</h2>
    </div></div>

    <?php if (!$mvvOk): ?>
      <div class="prose"><?= $mvvHtml !== '' ? $mvvHtml : '<p>Coming soon.</p>' /* sanitised */ ?></div>
    <?php else: ?>
      <?php foreach ($mvv['intro'] as $p): ?>
        <p class="mvv-intro"><?= e($p) ?></p>
      <?php endforeach; ?>

      <?php if ($mvv['mission'] !== ''): ?>
        <figure class="mvv-mission">
          <span class="mvv-mission__k">Our mission</span>
          <blockquote><?= e($mvv['mission']) ?></blockquote>
        </figure>
      <?php endif; ?>

      <?php if ($mvv['vision'] !== []): ?>
        <h3 class="mvv-subhead">Our vision</h3>
        <div class="mvv-grid">
          <?php foreach ($mvv['vision'] as $i => $v): ?>
            <div class="mvv-card">
              <span class="mvv-card__step"><?= sprintf('%02d', $i + 1) ?></span>
              <h4><?= e($v['title']) ?></h4>
              <p><?= e($v['body']) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($mvv['values'] !== []): ?>
        <h3 class="mvv-subhead">Our values</h3>
        <div class="mvv-values">
          <?php foreach ($mvv['values'] as $v): ?>
            <div class="mvv-value">
              <h4><?= e($v['title']) ?></h4>
              <p><?= e($v['body']) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<section class="band band--tint" id="committee">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">Behind the scenes</span>
      <h2>The committee</h2>
    </div></div>

    <?php if ($intro !== ''): ?>
      <div class="prose"><?= $intro /* sanitised */ ?></div>
    <?php endif; ?>

    <?php if ($people === []): ?>
      <div class="emptystate"><p>Committee details will be published here soon.</p></div>
    <?php else: ?>
      <div class="staff-grid staff-grid--four"<?= $intro !== '' ? ' style="margin-top:1.6rem"' : '' ?>>
        <?php foreach ($people as $person): ?>
          <div class="staffcard">
            <div class="staffcard__photo"><?= pub_staff_photo_html($person) ?></div>
            <span class="staffcard__role"><?= e($person['label'] ?? $person['position']) ?></span>
            <span class="staffcard__name"><?= e($person['name']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($volunteers !== []): ?>
<section class="band" id="volunteers">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">Giving their time</span>
      <h2>Our volunteers</h2>
    </div></div>
    <p class="mvv-intro">The club could not run without the people who give their time to it. Thank you.</p>
    <div class="staff-grid staff-grid--four" style="margin-top:1.6rem">
      <?php foreach ($volunteers as $person): ?>
        <div class="staffcard">
          <div class="staffcard__photo"><?= pub_staff_photo_html($person) ?></div>
          <span class="staffcard__role"><?= e($person['label']) ?></span>
          <span class="staffcard__name"><?= e($person['name']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="fxnote"><a class="linkarrow" href="<?= e(url('staff')) ?>">First-team management &amp; coaching staff</a></p>
  </div>
</section>
<?php else: ?>
<section class="band"><div class="container">
  <p class="fxnote"><a class="linkarrow" href="<?= e(url('staff')) ?>">First-team management &amp; coaching staff</a></p>
</div></section>
<?php endif; ?>
