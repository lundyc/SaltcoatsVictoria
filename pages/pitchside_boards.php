<?php
/** Route: /pitchside-boards — public page selling pitchside advertising boards at the ground. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/sponsorship_catalog.php';

$boards = [];
foreach (['pitchside_board_small' => 'Small', 'pitchside_board_medium' => 'Medium', 'pitchside_board_large' => 'Large'] as $code => $label) {
    $pkg = getSponsorshipPackageByCode(db(), $code);
    if ($pkg && (int) $pkg['is_active'] === 1) {
        $boards[] = [
            'label' => $label,
            'where' => [
                'pitchside_board_small' => 'Located on our recently renovated stand, seen by both home and visiting fans.',
                'pitchside_board_medium' => 'Located within view of the pitch, around the ground.',
                'pitchside_board_large' => 'Inside the ground, in view of fans, and outside the ground, seen by anyone passing, especially visitors to Park Dean\'s caravan park, which welcomes hundreds of thousands of visitors each year.',
            ][$code] ?? '',
            'photo' => ['pitchside_board_small' => 'board-small', 'pitchside_board_medium' => 'board-medium', 'pitchside_board_large' => 'board-large'][$code] ?? '',
            'name' => (string) $pkg['name'],
            'description' => (string) ($pkg['description'] ?? ''),
            'first' => (float) $pkg['amount'],
        ];
    }
}
$ground = club('ground_name', 'Campbell Park');
$clubName = club('club_name', 'Saltcoats Victoria FC');

set_meta([
    'title' => 'Pitchside advertising boards at ' . $ground,
    'description' => 'Advertise your business on a pitchside board at ' . $ground . ', home of ' . $clubName . '. Season-long visibility in front of our supporters.',
]);
seo_breadcrumbs([['Commercial', url('commercial')], ['Pitchside boards', url('pitchside-boards')]]);

$enquiryUrl = static function (string $board = '') use ($ground): string {
    $subject = 'Pitchside board enquiry' . ($board !== '' ? ' - ' . $board : '');
    $message = "Hello, I'd like to find out more about advertising on a pitchside board at " . $ground
        . ($board !== '' ? ' (' . $board . ')' : '') . ".\n\nBusiness name:\nBest contact number:";
    return url('enquire/commercial') . '?' . http_build_query(['subject' => $subject, 'message' => $message]);
};
?>
<?php partial('page_hero', [
    'eyebrow' => 'Advertise with the Vics',
    'title'   => 'Pitchside advertising boards',
    'sub'     => 'A season-long advertising board at ' . $ground . ', seen by our supporters at our home games.',
]); ?>

<div class="page">
  <div class="container">

    <section class="pb-intro">
      <div class="pb-intro__text">
        <h2>Be seen at the heart of the club</h2>
        <p>Pitchside boards put your business right where the action is. Your board stays up for the whole season, working for you at every home game, and your support helps keep Saltcoats Victoria going.</p>
        <p>Choose the size that suits you and get in touch. We&rsquo;ll look after everything else.</p>
        <a class="btn btn--lg" href="<?= e($enquiryUrl()) ?>">Enquire about a board</a>
      </div>
      <ol class="pb-steps">
        <li><span>1</span><div><strong>Choose a board</strong><small>Pick the size that suits your business.</small></div></li>
        <li><span>2</span><div><strong>We make and install it</strong><small>Your design goes up at the ground for you.</small></div></li>
        <li><span>3</span><div><strong>Seen all season</strong><small>Your board is up for the whole season.</small></div></li>
      </ol>
    </section>

    <?php if ($boards): ?>
    <h2 class="pb-heading">Choose your board</h2>
    <div class="pb-grid">
      <?php foreach ($boards as $b): ?>
        <article class="pb-card">
          <div class="pb-card__media<?= $b['photo'] !== '' ? ' pb-card__media--photo' : ' pb-card__media--icon' ?>">
            <?php if ($b['photo'] !== ''): ?>
              <picture>
                <source srcset="/uploads/site/<?= e($b['photo']) ?>.webp" type="image/webp">
                <img src="/uploads/site/<?= e($b['photo']) ?>.jpg" alt="" width="900" height="600" loading="lazy">
              </picture>
            <?php else: ?>
              <div class="pb-card__sign" aria-hidden="true">
                <svg viewBox="0 0 80 56" focusable="false"><rect x="2" y="4" width="76" height="34" rx="4" fill="currentColor"/><rect x="9" y="11" width="62" height="20" rx="2" fill="none" stroke="#e0b42a" stroke-width="2"/><rect x="12" y="38" width="5" height="14" fill="currentColor"/><rect x="63" y="38" width="5" height="14" fill="currentColor"/></svg>
              </div>
            <?php endif; ?>
            <div class="pb-card__overlay">
              <h3><?= e($b['label']) ?> board</h3>
              <p class="pb-card__desc"><?= e($b['description']) ?></p>
            </div>
          </div>
          <ul class="pb-benefits">
            <?php if ($b['where'] !== ''): ?><li><?= e($b['where']) ?></li><?php endif; ?>
            <li>Digital coverage on our website and regular shout-outs on our social media channels, which are reaching over 250,000 visitors each month.</li>
          </ul>
          <div class="pb-price pb-price--single">
            <strong><?= e(gbp($b['first'])) ?></strong>
          </div>
          <a class="btn btn--lg" href="<?= e($enquiryUrl($b['name'])) ?>">Enquire about this board</a>
        </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <section class="pb-faq">
      <h2>What you get</h2>
      <p>Every board is on show at <?= e($ground) ?> at our home games, and every sponsor also gets digital coverage on our website and regular shout-outs on our social media channels, which are reaching over 250,000 visitors each month.</p>
      <p>Not sure which size or position is right for your business, or want to talk it through? <a href="<?= e($enquiryUrl()) ?>">Send us a message</a> and we&rsquo;ll get back to you.</p>
    </section>
  </div>
</div>
