<?php
/** Partner strip + site footer + closing tags. */
declare(strict_types=1);

partial('sponsor_strip', ['sponsors' => pub_sponsors_with_logo()]);

$social = pub_social_links();
$quickLinks = [
    'Latest news'     => url('news'),
    'Fixtures'        => url('fixtures'),
    'Results'         => url('results'),
    'League table'    => url('table'),
    'First team'      => url('team'),
    'Club records'    => url('club/records'),
    'Photo gallery'   => url('gallery'),
];
$clubLinks = [
    'About the club'    => url('club/history'),
    'Committee & volunteers' => url('club/officials'),
    'Honours & awards'  => url('club/honours'),
    'Policies'          => url('club/policies'),
    'Contact & find us' => url('contact'),
];

// Policy / governance pages — driven off club_pages so unpublishing one drops it.
$policyLinks = club_menu_pages([
    'privacy',
    'child-protection-policy',
    'code-of-conduct',
    'equality-diversity-inclusion',
    'ground-regulations',
]);
?>
</main>

<?php $footerClubName = 'Saltcoats Victoria Community Football Club'; ?>
<footer class="site-footer">
  <div class="container">
    <div class="site-footer__cols">
      <div class="site-footer__brand">
        <img src="<?= e(club_crest_reverse()) ?>" alt="<?= e($footerClubName) ?>">
        <p><?= e($footerClubName) ?><br><?= e(club('ground_address', club('ground_name'))) ?><br>Scottish Charity No. SCO 55198</p>
        <?php if ($social): ?>
          <div class="site-footer__social">
            <?php foreach ($social as $network => $href): ?>
              <a href="<?= e($href) ?>" aria-label="<?= e(ucfirst($network)) ?>" rel="noopener" target="_blank"><?= pub_icon($network) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <nav aria-label="Football">
        <h3>Football</h3>
        <ul>
          <?php foreach ($quickLinks as $label => $href): ?>
            <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </nav>

      <nav aria-label="Club">
        <h3>Club</h3>
        <ul>
          <?php foreach ($clubLinks as $label => $href): ?>
            <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </nav>

      <nav aria-label="Support">
        <h3>Support</h3>
        <ul>
          <li><a href="<?= e(url('tickets')) ?>">Match tickets</a></li>
          <?php require_once HUB_ROOT . '/lib/events.php'; if ((int) cache_remember('events_public_count', 60, static fn (): string => (string) events_public_upcoming_count(db())) > 0): ?><li><a href="<?= e(url('events')) ?>">Events</a></li><?php endif; ?>
          <li><a href="/season-tickets">Season tickets</a></li>
          <li><a href="<?= e(url('shop')) ?>">Club shop</a></li>
          <li><a href="<?= e(url('commercial')) ?>">Commercial &amp; sponsorship</a></li>
        </ul>
      </nav>
    </div>
  </div>

  <div class="site-footer__bar">
    <div class="container">
      <?php if ($policyLinks): ?>
        <nav class="site-footer__legal" aria-label="Policies &amp; governance">
          <?php foreach ($policyLinks as $slug => $label): ?>
            <a href="<?= e($slug === 'privacy' ? url('privacy') : url('club/policies') . '#' . $slug) ?>"><?= e($label) ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <div class="site-footer__meta">
        <span>&copy; <?= date('Y') ?> <?= e($footerClubName) ?>. All rights reserved.</span>
      </div>
    </div>
  </div>
</footer>

<?php if (club('ga_measurement_id') !== ''): ?>
<div id="cookie-banner" class="cookie-banner" role="region" aria-label="Cookie preferences" hidden>
  <div class="cookie-banner__inner">
    <p>We use cookies to understand how visitors use this site. You can accept or reject analytics cookies.</p>
    <div class="cookie-banner__actions">
      <button type="button" id="cookie-banner-reject" class="btn btn--ghost">Reject</button>
      <button type="button" id="cookie-banner-accept" class="btn">Accept</button>
    </div>
  </div>
</div>
<script>
(function () {
  var KEY = 'cookie_consent';
  var stored = null;
  try { stored = localStorage.getItem(KEY); } catch (e) {}

  if (stored === 'granted') {
    gtag('consent', 'update', { analytics_storage: 'granted' });
    return;
  }
  if (stored === 'denied') {
    return;
  }

  var banner = document.getElementById('cookie-banner');
  if (!banner) return;
  banner.hidden = false;

  function choose(value) {
    try { localStorage.setItem(KEY, value); } catch (e) {}
    if (value === 'granted') {
      gtag('consent', 'update', { analytics_storage: 'granted' });
    }
    banner.hidden = true;
  }

  document.getElementById('cookie-banner-accept').addEventListener('click', function () { choose('granted'); });
  document.getElementById('cookie-banner-reject').addEventListener('click', function () { choose('denied'); });
})();
</script>
<?php endif; ?>
</body>
</html>
