<?php
/** Route: /club/officials — the committee / club officials. */
declare(strict_types=1);

$people = pub_staff_officials();

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
    'title' => club('club_name') . ' Committee & Club Officials',
    'description' => club('club_name') . ' committee and club officials.',
]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'The club',
    'title'   => 'Club officials',
    'sub'     => 'The committee and volunteers who run ' . club('club_name') . '.',
]); ?>

<div class="page">
  <div class="container">
    <?php if ($intro !== ''): ?>
      <div class="prose"><?= $intro /* sanitised */ ?></div>
    <?php endif; ?>

    <?php if ($people === []): ?>
      <div class="emptystate"><p>Committee details will be published here soon.</p></div>
    <?php else: ?>
      <div class="staff-grid" style="margin-top:<?= $intro !== '' ? '1.8rem' : '0' ?>">
        <?php foreach ($people as $person): ?>
          <div class="staffcard">
            <div class="staffcard__photo"><?= pub_staff_photo_html($person) ?></div>
            <span class="staffcard__role"><?= e(($person['label'] ?? $person['position'])) ?></span>
            <span class="staffcard__name"><?= e($person['name']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p class="fxnote"><a class="linkarrow" href="<?= e(url('staff')) ?>">First-team management</a></p>
  </div>
</div>
