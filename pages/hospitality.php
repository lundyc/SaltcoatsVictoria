<?php
/** Route: /hospitality — the Big Js hospitality suite: what it is, prices and the games you can book. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/hospitality.php';

$pdo = db();
$settings = hospitality_settings($pdo);
$fixtures = hospitality_bookable_fixtures($pdo, pub_current_season_id());
$fixtures = array_slice($fixtures, 0, 3); // only the next three games
$ground = club('ground_name', 'Campbell Park');
$arrive = (int) $settings['arrival_minutes_before'];
$groupMin = (int) $settings['group_min'];
$indMax = (int) $settings['individual_max'];
$corpGuests = (int) $settings['corporate_guests'];
$enquire = static fn (string $subject): string => url('contact') . '?subject=' . rawurlencode($subject) . '#enquiry';
$pr = static fn (string $k): string => gbp((float) $settings[$k]);

set_meta([
    'title' => 'Big Js Hospitality Suite | Matchday Hospitality',
    'description' => 'Matchday hospitality in the Big Js Hospitality Suite at ' . $ground . ': four drinks, a football pie, pitch views and a post-match visit from the team. Book individually, as a group or for your business.',
]);
seo_breadcrumbs([['Commercial', url('commercial')], ['Hospitality', url('hospitality')]]);
?>
<section class="hosp-hero">
  <div class="container hosp-hero__inner">
    <div class="hosp-hero__text">
      <span class="eyebrow">Commercial</span>
      <h1>Matchday Hospitality at <?= e($ground) ?></h1>
      <h2>Big Js Hospitality Suite</h2>
      <p class="hosp-hero__tag">Great football. Great company. The perfect matchday experience.</p>
      <h3 class="hosp-hero__lead">Enjoy a matchday with a difference</h3>
      <p>Whether you&rsquo;re treating yourself, catching up with friends, celebrating a special occasion or entertaining clients, <strong>Big Js Hospitality Suite</strong> offers a fantastic way to enjoy the football at <?= e($ground) ?>.</p>
      <p>With comfortable indoor seating overlooking the pitch, an outdoor viewing deck, a licensed bar and a warm welcome, you can enjoy the build-up, the match and the post-match atmosphere in style.</p>
      <div class="hosp-hero__cta">
        <a class="btn btn--lg btn--gold" href="#book">Book Your Hospitality</a>
        <a class="btn btn--lg btn--ghost-light" href="#packages">Explore Our Packages</a>
      </div>
    </div>
    <ul class="hosp-hero__features" data-hosp-rotator aria-label="About the suite">
      <li><strong>Seating and a view of the pitch</strong><span>Watch the game from inside the suite.</span></li>
      <li><strong>Bar</strong><span>Available in the suite on matchdays.</span></li>
      <li><strong>Outside decking with seating</strong><span>Step outside for the atmosphere.</span></li>
    </ul>
  </div>
</section>

<div class="page hosp-page">
  <div class="container">

    <section class="hsec" id="packages" aria-labelledby="pk-h">
      <h2 class="pb-heading" id="pk-h">Hospitality packages</h2>
      <div class="hosp-pk">
        <article class="hosp-pkg">
          <h3>The Matchday Experience</h3>
          <p class="hosp-pkg__price"><b><?= e($pr('price_individual_league')) ?></b> per person<small>League matches</small><b><?= e($pr('price_individual_cup')) ?></b> per person<small>Cup matches</small></p>
          <p>The perfect way to enjoy a matchday at <?= e($ground) ?>. Whether you&rsquo;re on your own, with a partner or alongside friends, enjoy football, refreshments and great company in Big Js.</p>
          <ul>
            <li>Match admission</li><li>Four complimentary drinks</li><li>Traditional half-time pie</li><li>Tea, coffee or Bovril at half-time</li>
            <li>Access to Big Js Hospitality Suite</li><li>Indoor pitch views and outdoor decking</li><li>Official team sheet</li><li>Post-match manager or player visit</li>
          </ul>
          <p class="hosp-pkg__note">For 1 to <?= $indMax ?> guests.</p>
          <a class="btn btn--lg" href="#book">Book Matchday Hospitality</a>
        </article>
        <article class="hosp-pkg hosp-pkg--feat">
          <h3>The Group Experience</h3>
          <p class="hosp-pkg__price"><b><?= e($pr('price_group_league')) ?></b> per person<small>League matches</small><b><?= e($pr('price_group_cup')) ?></b> per person<small>Cup matches</small></p>
          <p>Make it a matchday to remember with friends, family or colleagues. Everything in the standard package at a discounted rate for groups of <?= $groupMin ?> or more.</p>
          <ul>
            <li>All Matchday Experience benefits</li><li>Discounted hospitality for groups of <?= $groupMin ?> or more</li><li>Reserved group seating, subject to availability</li>
            <li>Group photograph opportunity</li><li>Personalised welcome for special occasions, arranged in advance</li>
          </ul>
          <p class="hosp-pkg__note">Minimum booking: <?= $groupMin ?> guests.</p>
          <a class="btn btn--lg" href="#book">Book Group Hospitality</a>
        </article>
        <article class="hosp-pkg">
          <h3>Corporate Matchday Experience</h3>
          <p class="hosp-pkg__price"><b><?= e($pr('price_corporate_league')) ?></b> for <?= $corpGuests ?> guests<small>League matches</small><b><?= e($pr('price_corporate_cup')) ?></b> for <?= $corpGuests ?> guests<small>Cup matches</small></p>
          <p>Entertain clients, reward your staff or bring your business together for a memorable afternoon of football, while showcasing your business to the local football community.</p>
          <ul>
            <li>Match admission for <?= $corpGuests ?> guests</li><li>Four complimentary drinks per guest</li><li>Traditional half-time pie</li><li>Half-time hot refreshments</li>
            <li>Reserved group seating</li><li>Official team sheets</li><li>Access to Big Js Hospitality Suite</li><li>Post-match manager or player visit</li>
            <li>Business welcome on the club&rsquo;s social media channels</li><li>PA acknowledgement during the match, where available</li><li>Group photograph opportunity</li>
          </ul>
          <p class="hosp-pkg__note">Booked by enquiry.</p>
          <a class="btn btn--lg" href="<?= e($enquire('Corporate hospitality enquiry')) ?>">Enquire About Corporate Hospitality</a>
        </article>
      </div>
    </section>

    <section class="hsec" aria-labelledby="exp-h">
      <h2 class="pb-heading" id="exp-h">Your matchday experience</h2>
      <ol class="hosp-time">
        <li><b><?= $arrive ?> minutes before kick-off</b><strong>The welcome</strong><span>Arrive at <?= e($ground) ?>, settle into Big Js and enjoy your complimentary drinks while soaking up the pre-match atmosphere.</span></li>
        <li><b>Kick-off</b><strong>Enjoy the action</strong><span>Take your place inside the suite or on the outdoor decking and get behind the Vics.</span></li>
        <li><b>Half-time</b><strong>Traditional football refreshments</strong><span>Enjoy your half-time pie and a hot drink before the second half gets underway.</span></li>
        <li><b>Full-time</b><strong>Meet the team</strong><span>Return to the suite, discuss the match with fellow supporters and enjoy a visit from the manager or members of the squad.</span></li>
      </ol>
    </section>

    <section class="hsec" id="book" aria-labelledby="book-h">
      <h2 class="pb-heading" id="book-h">Book hospitality &ndash; upcoming home games</h2>
      <?php if ($settings['enabled'] !== '1'): ?>
        <div class="emptystate"><p>Online booking isn&rsquo;t open right now. Please <a href="<?= e($enquire('Hospitality enquiry')) ?>">contact the club</a>.</p></div>
      <?php elseif (!$fixtures): ?>
        <div class="emptystate"><p>There are no home games available to book at the moment. Check back soon.</p></div>
      <?php else: ?>
        <div class="hosp-games">
          <?php foreach ($fixtures as $f):
              $ts = strtotime((string) $f['match_date']);
              $isCup = hospitality_is_cup($f);
              $left = hospitality_places_left($pdo, $settings, (int) $f['id']);
              $oppCrest = pub_opponent_crest($f['opponent_logo'] ?? '', (string) $f['opponent']);
              $ko = !empty($f['kickoff_time']) ? 'KO ' . format_time((string) $f['kickoff_time']) : 'KO TBC';
          ?>
            <article class="hosp-game">
              <div class="hosp-game__when">
                <b><?= e(date('D, j M Y', (int) $ts)) ?></b>
                <small><?= e((string) ($f['competition'] ?? '')) ?> &middot; <?= $isCup ? 'Cup' : 'League' ?> &middot; <?= e($ko) ?></small>
              </div>
              <div class="hosp-game__opp">
                <?php if ($oppCrest !== ''): ?><img src="<?= e($oppCrest) ?>" alt="" loading="lazy" width="40" height="40"><?php endif; ?>
                <strong><?= e((string) $f['opponent']) ?></strong>
              </div>
              <div class="hosp-game__act">
                <?php if ($left <= 0): ?><span class="hosp-left hosp-left--low">Fully booked</span>
                <?php else: ?>
                  <?php if ($left <= 8): ?><span class="hosp-left hosp-left--low">Limited places</span><?php endif; ?>
                  <a class="btn" href="<?= e(url('hospitality/book') . '?fixture_id=' . (int) $f['id']) ?>">Book now</a>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <p class="hosp-book__hint">Choose your game, then pick a standard or group booking and the number of guests. You&rsquo;ll see the total before you pay by card. For corporate hospitality, <a href="<?= e($enquire('Corporate hospitality enquiry')) ?>">get in touch</a>.</p>
      <?php endif; ?>
    </section>

    <section class="hsec" aria-labelledby="faq-h">
      <h2 class="pb-heading" id="faq-h">Frequently asked questions</h2>
      <div class="hosp-faq">
        <?php
        $faqs = [
            ['What time should I arrive?', 'Hospitality guests are welcome from ' . $arrive . ' minutes before kick-off.'],
            ['Are drinks included?', 'Yes. Each hospitality guest receives four complimentary drinks from our selected hospitality range. Additional drinks can be bought from the bar.'],
            ['Is food included?', 'Yes. A traditional football pie and a hot drink are included at half-time.'],
            ['Can I book for a group?', 'Absolutely. Groups of ' . $groupMin . ' or more qualify for discounted hospitality rates.'],
            ['Can businesses book hospitality?', 'Yes. Our Corporate Matchday Experience is ideal for entertaining clients, rewarding staff or enjoying a business outing. Get in touch and we will take it from there.'],
            ['Can children attend?', 'Please contact the club to discuss children attending hospitality. Complimentary alcoholic drinks are only available to guests aged 18 and over.'],
            ['Can you accommodate dietary requirements?', 'Dietary requirements must be declared when you book, and you are welcome to contact the club before booking to discuss them.'],
            ['Will we meet the manager or players?', 'We aim to arrange a post-match visit from the manager or members of the squad, subject to their matchday commitments.'],
            ['What happens if a fixture is postponed?', 'Bookings are non-refundable. If a fixture is postponed, please contact the club and we will let you know what can be done.'],
        ];
        foreach ($faqs as $q): ?>
          <details class="hosp-faq__item"><summary><?= e($q[0]) ?></summary><p><?= e($q[1]) ?></p></details>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="hosp-terms" aria-labelledby="terms-h">
      <h2 id="terms-h">Booking information</h2>
      <ul>
        <li>Complimentary drinks are limited to the selected hospitality range, and additional drinks are charged separately.</li>
        <li>Alcohol is available only to guests aged 18 and over. Four complimentary drinks are included per eligible guest.</li>
        <li>Manager and player appearances are subject to availability.</li>
        <li>Group bookings require a minimum of <?= $groupMin ?> guests. Corporate packages cover <?= $corpGuests ?> guests.</li>
        <li>Bookings are non-refundable. Hospitality is subject to availability and fixture changes.</li>
      </ul>
    </section>

    <section class="hosp-final">
      <h2>Make your next matchday special</h2>
      <p>From birthdays and family celebrations to staff outings and corporate entertaining, Big Js offers a welcoming and relaxed hospitality experience at <?= e($ground) ?>.</p>
      <p>Book your place, enjoy the atmosphere and support the Vics in style.</p>
      <a class="btn btn--lg btn--gold" href="#book">Book Your Hospitality</a>
    </section>

  </div>
</div>

<script>
(function () {
  var list = document.querySelector('[data-hosp-rotator]');
  if (!list) return;
  var items = list.querySelectorAll('li');
  if (items.length < 2) return;
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return; // show them all, still
  list.classList.add('is-rotating');
  var n = items.length, pos = [];
  for (var k = 0; k < n; k++) { pos[k] = (k + 1) % n; items[k].dataset.pos = pos[k]; } // item 0 starts in the middle
  var paused = false;
  list.addEventListener('mouseenter', function () { paused = true; });
  list.addEventListener('mouseleave', function () { paused = false; });
  setInterval(function () {
    if (paused || document.hidden) return;
    for (var k = 0; k < n; k++) {
      var next = (pos[k] + n - 1) % n; // everything moves up one; the top one drops to the bottom
      var wrap = pos[k] === 0;
      if (wrap) items[k].classList.add('no-anim');
      items[k].dataset.pos = next;
      if (wrap) { void items[k].offsetHeight; items[k].classList.remove('no-anim'); }
      pos[k] = next;
    }
  }, 4500);
})();
</script>
