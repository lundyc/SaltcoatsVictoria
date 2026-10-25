<?php
/** Route: /lottery — Club 1889 monthly draw (entries are made on the external Club 1889 site). */
declare(strict_types=1);

$clubName = club('club_name', 'Saltcoats Victoria FC');
$joinUrl = 'https://www.club1889.co.uk';

set_meta([
    'title' => 'Club 1889 lottery',
    'description' => 'Join Club 1889, the ' . $clubName . ' monthly prize draw. £10 per entry, 50% paid out in cash prizes and the rest reinvested in the club.',
]);
seo_breadcrumbs([['Commercial', url('commercial')], ['Club 1889', url('lottery')]]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Commercial',
    'title'   => 'Club 1889',
    'sub'     => 'Support The Vics and be in with a chance to win big every month.',
    'back'    => ['href' => url('commercial'), 'label' => 'Commercial'],
]); ?>

<div class="page">
  <div class="container">
    <section class="pb-intro">
      <div class="pb-intro__text">
        <h2>Enter the draw</h2>
        <p>By joining Club 1889 you&rsquo;re not just entering a prize draw &mdash; you&rsquo;re helping to develop Saltcoats Victoria Football Club, both now and into the future.</p>
        <p>For just <strong>£10 per entry</strong>, you&rsquo;ll be in the running to win a share of a growing prize pot, with <strong>50% of all income paid out in cash prizes</strong> and the remaining profits reinvested directly into the club.</p>
        <p>You can enter as many times as you like &mdash; the more entries, the bigger the prize fund and the greater the support for your local club.</p>
        <a class="btn btn--lg btn--gold" href="<?= e($joinUrl) ?>" target="_blank" rel="noopener">Join Club 1889 today</a>
      </div>
      <ul class="pb-steps">
        <li><span>1</span><div><strong>Each entry costs £10</strong><small>The more entries you have, the more chances you have to win.</small></div></li>
        <li><span>2</span><div><strong>Monthly draw</strong><small>The draw takes place at 7pm on the 14th of each month.</small></div></li>
        <li><span>3</span><div><strong>50% paid out in prizes</strong><small>The rest is reinvested directly into the club.</small></div></li>
      </ul>
    </section>

    <section class="oclotto">
      <img class="oclotto__logo" src="<?= e(uploads('club/our-club-lotto.png')) ?>" width="360" height="360" alt="Our Club Lotto &ndash; supporting communities" loading="lazy">
      <div class="oclotto__text">
        <h2>About Our Club Lotto</h2>
        <p>Established in 2018 with a goal to help small clubs and organisations raise funds, Our Club Lotto is an online platform that allows supporters to play a lottery &ndash; helping their cause, with a chance of winning a prize.</p>
        <p>They take care of the day-to-day operation of the lottery, allowing us to continue running and building the club.</p>
        <p><a class="linkarrow" href="https://www.ourclublotto.co.uk/" target="_blank" rel="noopener">Visit ourclublotto.co.uk</a></p>
      </div>
      <div class="oclotto__contact">
        <h3>Contact Our Club Lotto</h3>
        <p>They&rsquo;re on hand to answer any questions you may have, or to sign up.</p>
        <ul>
          <li><a href="tel:01413099174">0141 309 9174</a></li>
          <li><a href="mailto:info@ourclublotto.co.uk">info@ourclublotto.co.uk</a></li>
        </ul>
      </div>
    </section>
  </div>
</div>
