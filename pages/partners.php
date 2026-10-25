<?php
/** Route: /partners — club sponsors & partners. */
declare(strict_types=1);

$main = pub_main_sponsors();
$others = pub_other_sponsors();
$contactEmail = club('contact_email');

// Opening hours, keyed by sponsor id.
$partnerHours = [
    207 => '9am – 12am, every day',
];

// Short write-ups for the principal partners, keyed by sponsor id.
$partnerBlurbs = [
    207 => "A friendly, lively pub with a great atmosphere, just a short walk from Saltcoats train station. There's a licensed beer garden for the warmer days, and every bit of sport that can legally be shown goes up on the big screens. With entertainment from Thursday to Sunday, it's no surprise that Saturday nights happen here.",
    182 => "Great coffee, homemade treats and soups, all in one welcoming spot. Café Del Greco's is the perfect place to relax, catch up with friends and enjoy really good food.",
    176 => "Sports, events and commercial photographer, capturing the moments that matter. Many of the photos you'll see around Campbell Park and on our channels are the work of Jazza McG.",
    225 => "Licensed insolvency practitioners with 30 years of experience, offering tailored solutions that help you breathe easy again. If money worries are getting on top of you, McLenan Corporate are on hand with friendly, practical advice.",
];

set_meta([
    'title' => club('club_name') . ' Sponsors & Partners',
    'description' => 'The businesses and supporters backing ' . club('club_name') . '.',
]);
seo_breadcrumbs([['Commercial', url('commercial')], ['Our partners', url('partners')]]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Commercial',
    'title'   => 'Our partners',
    'sub'     => 'The local businesses and supporters who back ' . club('club_name') . '.',
]); ?>

<?php if ($main): ?>
<div class="pl-intro">
  <div class="container">
    <span class="eyebrow">Principal partners</span>
    <h2>Leading the way</h2>
    <p>Our principal partners are the businesses and supporters who stand shoulder to shoulder with the Vics. Their backing helps keep football at Campbell Park going, and we are proud to have them with us.</p>
  </div>
</div>
<?php foreach (array_values($main) as $i => $s): ?>
  <?php
  $logo = trim((string) $s['logo_path']);
  $addr = trim((string) ($s['address'] ?? ''));
  $phone = trim((string) ($s['contact_phone'] ?? ''));
  $hours = $partnerHours[(int) $s['id']] ?? '';
  $links = array_filter([
      'Website'   => trim((string) ($s['website_url'] ?? '')),
      'Facebook'  => trim((string) ($s['facebook_page_url'] ?? '')),
      'Instagram' => trim((string) ($s['instagram_url'] ?? '')),
      'X'         => trim((string) ($s['twitter_url'] ?? '')),
  ]);
  ?>
  <section class="pl-feature<?= $i % 2 === 0 ? ' pl-feature--right' : '' ?>">
    <div class="container">
      <div class="pl-feature__inner">
        <div class="pl-feature__logo">
          <?php if ($logo !== ''): ?>
            <img src="<?= e(uploads('sponsors/' . $logo)) ?>" alt="<?= e($s['name']) ?>" <?= $i < 2 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
          <?php else: ?>
            <span><?= e($s['name']) ?></span>
          <?php endif; ?>
        </div>
        <div class="pl-feature__body">
          <span class="pl-feature__tag">Principal partner</span>
          <h3><?= e($s['name']) ?></h3>
          <p class="pl-feature__lead"><?= e($partnerBlurbs[(int) $s['id']] ?? ('Proud principal partner of ' . club('club_name') . '.')) ?></p>
          <?php if ($addr !== '' || $phone !== '' || $hours !== ''): ?>
            <ul class="pl-feature__info">
              <?php if ($addr !== ''): ?><li><b>Find them</b><?= e($addr) ?></li><?php endif; ?>
              <?php if ($hours !== ''): ?><li><b>Open</b><?= e($hours) ?></li><?php endif; ?>
              <?php if ($phone !== ''): ?><li><b>Call</b><a href="tel:<?= e(preg_replace('~\s+~', '', $phone)) ?>"><?= e($phone) ?></a></li><?php endif; ?>
            </ul>
          <?php endif; ?>
          <?php if ($links): ?>
            <div class="pl-feature__links">
              <?php $first = true; foreach ($links as $label => $href): ?>
                <a class="btn <?= $first ? 'btn--gold' : 'btn--ghost' ?>" href="<?= e($href) ?>" target="_blank" rel="noopener"><?= $first ? 'Visit ' . e($label) : e($label) ?></a>
              <?php $first = false; endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
<?php endforeach; ?>
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
            ? '<img src="' . e(uploads('sponsors/' . $logo)) . '" alt="' . e($s['name']) . '" loading="lazy" data-sz="150">'
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
