<?php
/** Route: /commercial — hub for everything commercial: partners, boards, player and match sponsorship. */
declare(strict_types=1);

$clubName = club('club_name', 'Saltcoats Victoria FC');
$ground = club('ground_name', 'Campbell Park');

set_meta([
    'title' => 'Commercial & sponsorship',
    'description' => 'Partner with ' . $clubName . ': pitchside boards, player and matchday sponsorship and club partnerships at ' . $ground . '.',
]);
seo_breadcrumbs([['Commercial', url('commercial')]]);

$enquire = static fn (string $subject, string $message): string =>
    url('enquire/commercial') . '?' . http_build_query(['subject' => $subject, 'message' => $message]);

$cards = [
    ['title' => 'Our partners', 'text' => 'Meet the businesses who back the club.', 'href' => url('partners'),
     'img' => '/uploads/site/partners-board.jpg', 'pos' => 'center 35%'],
    ['title' => 'Pitchside boards', 'text' => 'Season-long advertising around the pitch.', 'href' => url('pitchside-boards'),
     'img' => '/uploads/site/pitchside-cta.jpg', 'pos' => '30% 55%'],
    ['title' => 'Sponsor a player', 'text' => 'Put your name on a home or away kit.', 'href' => url('playersponsors'),
     'img' => '/uploads/site/sponsor-cta.jpg', 'pos' => 'center 35%'],
    ['title' => 'Matchday &amp; match ball sponsorship', 'text' => 'Sponsor a game and be part of the day.',
     'href' => url('matchday-sponsorship'),
     'img' => '/uploads/site/match-balls.jpg', 'pos' => 'center 70%'],
    ['title' => 'Hospitality', 'text' => 'Matchday in our Big Js suite, for individuals and groups.', 'href' => url('hospitality'),
     'img' => '/uploads/site/big-jays.jpg', 'pos' => 'center 40%'],
    ['title' => 'Club 1889 lottery', 'text' => 'Enter the monthly draw and support the club.', 'href' => url('lottery'),
     'img' => '/uploads/site/our-club-lotto-logo.svg', 'pos' => 'center', 'logo' => true],
];
?>
<?php partial('page_hero', [
    'eyebrow' => 'Partner with the Vics',
    'title'   => 'Commercial',
    'sub'     => 'Partnerships, sponsorship and advertising with ' . $clubName . '.',
]); ?>

<div class="page">
  <div class="container">
    <figure class="comm-feature">
      <img src="/uploads/site/pitchside-cta.jpg" alt="The main stand and pitchside boards at <?= e($ground) ?>" width="2000" height="1501" loading="eager">
      <figcaption>
        <h2>Back your local club</h2>
        <p>Whether you want to see your name around the pitch, on a player&rsquo;s shirt or alongside a matchday, there&rsquo;s a way to get involved with <?= e($clubName) ?>. Every partnership helps the club go further.</p>
        <a class="btn btn--gold btn--lg" href="<?= e($enquire('Commercial enquiry', "Hello, I'd like to talk about a commercial partnership with the club.\n\nBusiness name:\nBest contact number:")) ?>">Talk to us</a>
      </figcaption>
    </figure>

    <div class="comm-grid">
      <?php foreach ($cards as $c): ?>
        <a class="comm-card" href="<?= e($c['href']) ?>">
          <span class="comm-card__img<?= !empty($c['logo']) ? ' comm-card__img--logo' : '' ?>" style="<?php if (empty($c['logo'])): $w = preg_replace('/\.jpg$/', '.webp', $c['img']); $m = preg_replace('/\.webp$/', '-m.webp', $w); ?>--bg:url('<?= e($w) ?>');--bg-m:url('<?= e($m) ?>');<?php else: ?>background-image:url('<?= e($c['img']) ?>');<?php endif; ?>background-position:<?= e($c['pos']) ?>"></span>
          <span class="comm-card__body">
            <strong><?= $c['title'] ?></strong>
            <small><?= e($c['text']) ?></small>
            <span class="comm-card__arrow" aria-hidden="true">&rarr;</span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
