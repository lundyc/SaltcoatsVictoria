<?php
/** Document head + open <body> + site header. Rendered after the page body so
 *  pages can set_meta() first. */
declare(strict_types=1);

$clubName = club('club_name', 'Saltcoats Victoria FC');
$clubShort = club('club_short_name', $clubName);
seo_send_headers();

/* Brand tokens: only emit overrides that differ from the CSS defaults so the
   stylesheet stays the single source of the base palette. */
$tokenMap = [
    '--club-primary'     => club('brand_primary'),
    '--club-primary-ink' => club('brand_primary_ink'),
    '--club-secondary'   => club('brand_secondary'),
    '--club-on-primary'  => club('brand_on_primary'),
    '--club-accent'      => club('brand_accent'),
    '--club-accent-ink'  => club('brand_accent_ink'),
];
$tokenCss = '';
foreach ($tokenMap as $name => $value) {
    if ($value !== '') {
        $tokenCss .= $name . ':' . $value . ';';
    }
}
?>
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(seo_title()) ?></title>
<?= seo_head_tags() /* every value escaped inside; JSON-LD hex-encoded */ ?><link rel="icon" type="image/png" sizes="32x32" href="<?= e(pub_icon_url(32)) ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= e(pub_icon_url(192)) ?>">
<link rel="apple-touch-icon" href="<?= e(pub_icon_url(180)) ?>">
<?php if (is_file(PUBLIC_ROOT . '/uploads/club/site.webmanifest')): ?><link rel="manifest" href="/uploads/club/site.webmanifest"><?php endif; ?>
<link rel="preload" href="/assets/fonts/Montserrat.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/Roboto.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= e(asset('css/public.css')) ?>">
<link rel="alternate" type="application/rss+xml" title="<?= e($clubName) ?> news" href="<?= e(url('news/feed.xml')) ?>">
<?php if ($tokenCss !== ''): ?><style>:root{<?= $tokenCss /* colour literals, validated on save */ ?>}</style><?php endif; ?>
<script src="<?= e(asset('js/public.js')) ?>" defer></script>
<?php if (club('ga_measurement_id') !== ''): ?>
<script>
window.dataLayer=window.dataLayer||[];
function gtag(){dataLayer.push(arguments);}
/* Analytics storage stays denied until the visitor accepts the cookie banner (see site_footer.php). */
gtag('consent','default',{'analytics_storage':'denied'});
</script>
<script>
/* Google's analytics script (about 175 KB) loads once the page has finished loading, so it does not
   compete with the page's own pictures. The commands queued above and below (consent default, config)
   are kept in order and run as soon as it arrives. */
(function(){var id=<?= json_encode((string) club('ga_measurement_id'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function load(){var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(id);document.head.appendChild(s);}
if(document.readyState==='complete'){load();}else{window.addEventListener('load',function(){setTimeout(load,0);});}})();
</script>
<script>gtag('js',new Date());gtag('config','<?= e(club('ga_measurement_id')) ?>');</script>
<?php endif; ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header">
<?php
$social = pub_social_links();
$utilityLinks = [
    'Club shop' => url('shop'),
    'Tickets'   => url('tickets'),
    'Contact'   => url('contact'),
];
// Events link appears only while there are public upcoming events, so it never leads to an empty page.
require_once HUB_ROOT . '/lib/events.php';
$hasPublicEvents = (int) cache_remember('events_public_count', 60, static fn (): string => (string) events_public_upcoming_count(db())) > 0;
if ($hasPublicEvents) {
    $utilityLinks = ['Club shop' => url('shop'), 'Tickets' => url('tickets'), 'Events' => url('events'), 'Contact' => url('contact')];
}
require_once HUB_ROOT . '/lib/member_hint.php';
$memberHint = member_hint_read();
?>
  <div class="utility-bar">
    <div class="container">
      <nav class="utility-bar__links" aria-label="Utility">
        <?php foreach ($utilityLinks as $label => $href): ?>
          <a href="<?= e($href) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="utility-bar__account">
        <?php if ($memberHint): ?>
          <a class="utility-bar__me" href="/members/index.php"><?= pub_icon('user') ?: '' ?><span>My Vics<span class="utility-bar__name"> · <?= e($memberHint['name']) ?></span></span></a>
          <a href="/members/logout.php?all=1">Log out</a>
        <?php else: ?>
          <a class="utility-bar__login" href="/members/login.php">Log in</a>
        <?php endif; ?>
      </div>
      <?php if ($social): ?>
        <div class="utility-bar__social">
          <?php foreach ($social as $network => $href): ?>
            <a href="<?= e($href) ?>" aria-label="<?= e(ucfirst($network)) ?>" rel="noopener" target="_blank"><?= pub_icon($network) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="masthead">
    <div class="container">
      <a class="brand" href="<?= e(url()) ?>" aria-label="<?= e($clubShort) ?> — home">
        <img src="<?= e(club_crest_reverse()) ?>" alt="" width="42" height="42">
      </a>

      <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-nav" aria-label="Menu">
        <span class="nav-toggle__open"><?= pub_icon('menu') ?></span>
        <span class="nav-toggle__close"><?= pub_icon('close') ?></span>
      </button>

      <?php partial('site_nav'); ?>
    </div>
  </div>
</header>

<main id="main">
