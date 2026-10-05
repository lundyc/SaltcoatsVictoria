<?php
/** Route: /partners — club sponsors & partners. */
declare(strict_types=1);

$main = pub_main_sponsors();
$others = pub_other_sponsors();
$contactEmail = club('contact_email');

set_meta([
    'title' => club('club_name') . ' Sponsors & Partners',
    'description' => 'The businesses and supporters backing ' . club('club_name') . '.',
]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Commercial',
    'title'   => 'Our partners',
    'sub'     => 'The local businesses and supporters who back ' . club('club_name') . '.',
]); ?>

<?php if ($main): ?>
<section class="band">
  <div class="container">
    <div class="band__head"><div><span class="eyebrow">Principal partners</span><h2>Leading the way</h2></div></div>
    <div class="partner-lead">
      <?php foreach ($main as $s): ?>
        <?php
        $logo = trim((string) $s['logo_path']);
        $link = pub_sponsor_link($s);
        $addr = trim((string) ($s['address'] ?? ''));
        $phone = trim((string) ($s['contact_phone'] ?? ''));
        ?>
        <div class="partner-lead__card">
          <div class="partner-lead__logo">
            <?php if ($logo !== ''): ?>
              <img src="<?= e(uploads('sponsors/' . $logo)) ?>" alt="<?= e($s['name']) ?>" loading="lazy">
            <?php else: ?>
              <span><?= e($s['name']) ?></span>
            <?php endif; ?>
          </div>
          <div class="partner-lead__body">
            <h3><?= e($s['name']) ?></h3>
            <?php if ($addr !== ''): ?><p class="partner-lead__meta"><?= e($addr) ?></p><?php endif; ?>
            <?php if ($phone !== ''): ?><p class="partner-lead__meta"><?= e($phone) ?></p><?php endif; ?>
            <?php if ($link !== ''): ?>
              <a class="linkarrow" href="<?= e($link) ?>" target="_blank" rel="noopener">Visit</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($others): ?>
<section class="band band--surface">
  <div class="container">
    <div class="band__head"><div><span class="eyebrow">In partnership with</span><h2>Our partners &amp; supporters</h2></div></div>
    <div class="partners__grid">
      <?php foreach ($others as $s): ?>
        <?php
        $logo = trim((string) $s['logo_path']);
        $link = pub_sponsor_link($s);
        $inner = $logo !== ''
            ? '<img src="' . e(uploads('sponsors/' . $logo)) . '" alt="' . e($s['name']) . '" loading="lazy">'
            : '<span class="partners__name">' . e($s['name']) . '</span>';
        ?>
        <?php if ($link !== ''): ?>
          <a class="<?= $logo === '' ? 'is-text' : '' ?>" href="<?= e($link) ?>" target="_blank" rel="noopener" title="<?= e($s['name']) ?>"><?= $inner ?></a>
        <?php else: ?>
          <span class="<?= $logo === '' ? 'is-text' : '' ?>" title="<?= e($s['name']) ?>"><?= $inner ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="band">
  <div class="container">
    <div class="partner-cta">
      <div class="partner-cta__main">
        <span class="eyebrow">Get involved</span>
        <h2>Put your business in front of the Campbell Park crowd</h2>
        <p>Back your local club and be seen by supporters every matchday. We'll work with you to find a package that suits your business and your budget.</p>
        <div class="partner-cta__actions">
          <a class="btn btn--gold btn--lg" href="<?= e(url('contact')) ?>">Become a partner</a>
          <?php if ($contactEmail !== ''): ?>
            <a class="btn btn--ghost btn--lg" href="mailto:<?= e($contactEmail) ?>?subject=<?= rawurlencode('Sponsorship enquiry') ?>">Email the club</a>
          <?php endif; ?>
        </div>
      </div>
      <ul class="partner-cta__packages" aria-label="Sponsorship packages" data-rotator>
        <li><b>Match sponsorship</b><span>Your name on the matchday programme and in the ground</span></li>
        <li><b>Matchball</b><span>Sponsor the ball and get pitch-side recognition</span></li>
        <li><b>Hospitality</b><span>Host clients and guests at Campbell Park</span></li>
        <li><b>Advertising boards</b><span>Year-round visibility around the pitch</span></li>
        <li><b>Player sponsorship</b><span>Back a first-team player for the season</span></li>
      </ul>
    </div>
  </div>
</section>

<script>
(function () {
  var list = document.querySelector('[data-rotator]');
  if (!list) return;
  var items = list.querySelectorAll('li');
  if (items.length < 2) return;
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return; // keep the full list
  var i = 0, paused = false;
  list.classList.add('is-rotating');
  items[0].classList.add('is-active');
  list.addEventListener('mouseenter', function () { paused = true; });
  list.addEventListener('mouseleave', function () { paused = false; });
  setInterval(function () {
    if (paused || document.hidden) return;
    var old = items[i];
    old.classList.remove('is-active');
    old.classList.add('is-leaving');           // slides down and fades out
    setTimeout(function () { old.classList.remove('is-leaving'); }, 800); // reset above, invisible
    i = (i + 1) % items.length;
    items[i].classList.add('is-active');       // drops in from above
  }, 4000);
})();
</script>
