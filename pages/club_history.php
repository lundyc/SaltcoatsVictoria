<?php
/** Route: /club/history — "About the club". A single scrolling page split into
 *  distinct, alternating-background sections: the club story, the club at a
 *  glance, and links out to the rest of the site. The committee, volunteers and
 *  mission / vision / values live on /club/officials. */
declare(strict_types=1);

$page = club_page_by_slug(db(), 'history');
$body = $page ? club_page_render($page) : '';

$founded  = club('club_founded');
$nickname = club('club_nickname');
$ground   = club('ground_name', 'Campbell Park');
$league   = club('league_name', 'West of Scotland Football League');
$record   = club('ground_record_attendance');
$primary  = club('brand_primary', '#6d2231');
$accent   = club('brand_accent', '#e0b42a');
$years    = ($founded !== '' && ctype_digit($founded)) ? (int) date('Y') - (int) $founded : null;


set_meta([
    'title' => club('club_name') . ' History' . ($founded !== '' ? ' | Founded ' . $founded : ''),
    'title_full' => '1',
    'description' => club('club_name') . ($founded !== '' ? ' — formed ' . $founded . '.' : '')
        . ' Our story, the club at a glance and how to find out more.',
]);
seo_breadcrumbs([['Club', url('club/history')], ['History', url('club/history')]]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'The club',
    'title'   => 'About the club',
    'sub'     => ($founded !== '' ? 'Formed ' . $founded : club('club_name'))
        . ($nickname !== '' ? ' · ' . $nickname : ''),
]); ?>

<section class="band about-story">
  <div class="container clay">
    <div>
      <span class="eyebrow about-story__eyebrow">Our story</span>
      <div class="prose prose--lead about-story__body">
        <?= $body !== '' ? $body : '<p>The club story is being written and will appear here soon.</p>' ?>
      </div>
    </div>

    <aside>
      <div class="factbox">
        <img class="factbox__crest" src="<?= e(club_crest()) ?>" alt="<?= e(club('club_name')) ?> crest">
        <h2>Club facts</h2>
        <dl>
          <?php if ($founded !== ''): ?><div><dt>Formed</dt><dd><?= e($founded) ?></dd></div><?php endif; ?>
          <?php if ($nickname !== ''): ?><div><dt>Nickname</dt><dd><?= e($nickname) ?></dd></div><?php endif; ?>
          <div><dt>Home ground</dt><dd><?= e($ground) ?></dd></div>
          <div><dt>League</dt><dd><?= e($league) ?></dd></div>
          <div>
            <dt>Home colours</dt>
            <dd class="swatches">
              <span class="swatch"><i style="background:<?= e($primary) ?>"></i> Maroon</span>
              <span class="swatch"><i style="background:<?= e($accent) ?>"></i> Yellow</span>
            </dd>
          </div>
          <div>
            <dt>Away colours</dt>
            <dd class="swatches">
              <span class="swatch"><i style="background:#1928bb"></i> Blue</span>
            </dd>
          </div>
        </dl>
      </div>
    </aside>
  </div>
</section>

<section class="band band--ink">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">At a glance</span>
      <h2>The club in numbers</h2>
    </div></div>
    <div class="factstrip">
      <?php if ($founded !== ''): ?>
        <div><span class="factstrip__num"><?= e($founded) ?></span><span class="factstrip__lbl">Year founded</span></div>
      <?php endif; ?>
      <?php if ($years !== null): ?>
        <div><span class="factstrip__num"><?= $years ?></span><span class="factstrip__lbl">Years of history</span></div>
      <?php endif; ?>
      <?php if ($nickname !== ''): ?>
        <div><span class="factstrip__num factstrip__num--text"><?= e($nickname) ?></span><span class="factstrip__lbl">Known as</span></div>
      <?php endif; ?>
      <div><span class="factstrip__num factstrip__num--text"><?= e($ground) ?></span><span class="factstrip__lbl">Home ground</span></div>
      <?php if ($record !== ''): ?>
        <div><span class="factstrip__num"><?= e($record) ?></span><span class="factstrip__lbl">Record attendance</span></div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="band">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">Go deeper</span>
      <h2>Explore the club</h2>
    </div></div>
    <div class="explore-grid">
      <a class="explore-card" href="<?= e(url('club/officials')) ?>">
        <span class="explore-card__t">Committee &amp; volunteers</span>
        <span class="explore-card__d">The people who run the club, and what we stand for.</span>
        <span class="explore-card__go">Meet the people</span>
      </a>
      <a class="explore-card" href="<?= e(url('club/honours')) ?>">
        <span class="explore-card__t">Honours &amp; awards</span>
        <span class="explore-card__d">Every trophy and player-of-the-year the club has on record.</span>
        <span class="explore-card__go">View honours</span>
      </a>
      <a class="explore-card" href="<?= e(url('team')) ?>">
        <span class="explore-card__t">First-team squad</span>
        <span class="explore-card__d">The current playing squad and the management team.</span>
        <span class="explore-card__go">Meet the squad</span>
      </a>
      <a class="explore-card" href="<?= e(url('contact')) ?>">
        <span class="explore-card__t">Campbell Park</span>
        <span class="explore-card__d">Directions, facilities and everything for matchday.</span>
        <span class="explore-card__go">Find the ground</span>
      </a>
      <a class="explore-card" href="<?= e(url('club/policies')) ?>">
        <span class="explore-card__t">Policies &amp; governance</span>
        <span class="explore-card__d">The standards the club holds itself to, on and off the pitch.</span>
        <span class="explore-card__go">Read policies</span>
      </a>
    </div>
  </div>
</section>
