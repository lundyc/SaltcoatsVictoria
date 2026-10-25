<?php
/** Route: /club/vacancies — editable intro from club_pages, plus every club role nobody holds right now. */
declare(strict_types=1);

$page = club_page_by_slug(db(), 'vacancies');
$intro = $page ? pub_club_page_html($page) : '';
// The seeded placeholder paragraph is replaced by the live list below.
$intro = preg_replace('~<p>\s*<strong>\s*\[TO CONFIRM[^<]*</strong>\s*</p>~i', '', $intro) ?? $intro;

// Split the editable intro: the welcome text leads the page, anything from the first heading on (safeguarding) follows the roles.
$introParts = preg_split('~(?=<h2\b)~i', $intro, 2) ?: [$intro];
$introTop = $introParts[0];
$introRest = $introParts[1] ?? '';


// Longer write-ups shown in the role panel. Keyed by role name; roles not listed fall back to the short blurb.
$roleDetails = [
    'Physio / First Aider' => [
        'commitment' => 'Home matchdays and training',
        'about' => "The Vics are looking for a qualified physio or first-aider to look after our players and give the club the medical cover it needs on matchdays and at training.\n\nPlayers pick up knocks, strains and the occasional bigger injury, and having someone trusted on the touchline makes a real difference, to the players and to the team. You would be the first point of call when someone goes down, deciding whether a player can carry on and making sure they get the right treatment afterwards.\n\nYou would work closely with the management team, helping players recover and return to fitness safely. Whether you are a fully qualified physiotherapist, a sports therapist or a first-aider with experience in sport, we would love to hear from you, and we are happy to talk about the time you can give.",
        'do' => ['Provide first-aid and injury cover for our players on matchdays.', 'Support players at training sessions where possible.', 'Assess and treat knocks and strains, and advise on when a player should come off.', 'Keep basic injury records and work with the management team on player fitness.'],
        'need' => ['A recognised first-aid or physiotherapy qualification.', 'A calm, reassuring manner under pressure.', 'Reliability on matchdays.'],
        'offer' => ['Being part of a welcoming football club.', 'Support from the management team and committee.', 'We are happy to talk about training and keeping your qualification up to date.'],
    ],
    'Programme Editor' => [
        'commitment' => 'Before each home match',
        'about' => "The matchday programme is a big part of the Campbell Park experience, and for many supporters it is as much a keepsake as something to read on the day. We are looking for someone to bring it together for every home game.\n\nYou would gather the team news, player profiles, match reports and features, collect adverts from our sponsors and partners, and put it all together into a programme that is ready for print. It is a great chance to tell the story of the club, from the first team to the history of the Vics and the people who make the club what it is.\n\nYou do not need to be a professional designer. If you enjoy writing, layout or photography and want to be part of every matchday, this role could be perfect for you. The club will help with content, contacts and printing.",
        'do' => ['Collect team news, player profiles, features and match reports.', 'Gather adverts from our sponsors and partners.', 'Lay out the programme and get it ready for print.', 'Keep to deadlines so it is ready for every home game.'],
        'need' => ['Good written English and an eye for detail.', 'Comfortable using design or layout software, or willing to learn.', 'A love of the club and its stories.'],
        'offer' => ['A creative role at the heart of matchday.', 'Plenty of help from the club with content and contacts.', 'Your work in the hands of supporters at every home game.'],
    ],
    'Fundraiser / Sponsorship Officer' => [
        'commitment' => 'Flexible, a few hours a week',
        'about' => "Every club depends on the money it can raise, and for Saltcoats Victoria that money pays for the pitch, the facilities and the day-to-day running of the club. We are looking for someone to help bring more of it in.\n\nYou would look after our existing sponsors, find new local businesses who want to support the club, and organise fundraising events that bring supporters and the community together. You would also help promote the club lottery and look for new ways to raise money.\n\nThis is a role for someone who is confident talking to people and enjoys making things happen. You will have the support of the committee behind you, and the hours are flexible, so it can fit around work and family.",
        'do' => ['Find new local sponsors and look after existing ones.', 'Plan and run fundraising events.', 'Help promote the club lottery.', 'Work with the committee on fundraising targets.'],
        'need' => ['Confidence speaking with local businesses and supporters.', 'Good organisation and follow-through.', 'Enthusiasm for the club and its community.'],
        'offer' => ['A chance to make a real difference to the club.', 'The backing of the committee and an established sponsor network.', 'Flexible hours that fit around you.'],
    ],
    'Bar Staff' => [
        'commitment' => 'Matchdays and club events',
        'about' => "Matchday is not complete without a warm welcome and a good pint. Our bar is a meeting place for supporters, visiting fans and the whole community, and the people behind it set the tone for the day.\n\nWe are looking for friendly volunteers to serve behind the bar on matchdays and at club events. You would serve drinks, take payments, keep the bar tidy and stocked, and be part of the matchday team, while getting to enjoy the atmosphere around the ground.\n\nYou do not need previous bar experience. Full guidance is given, and you will be working alongside people who know the routine. Whether you can give every home game or just the odd one, any time you can offer is appreciated.",
        'do' => ['Serve drinks to supporters and visitors on matchdays and at events.', 'Handle cash and card payments.', 'Keep the bar clean, tidy and stocked.', 'Work as part of the matchday team.'],
        'need' => ['A friendly, welcoming personality.', 'Over 18 and happy to work in a busy environment.', 'Experience helps but is not essential, as full guidance is given.'],
        'offer' => ['Be part of the matchday atmosphere.', 'Training on the job and a supportive team.', 'A great way to meet fellow supporters.'],
    ],
    'Kitchen Staff' => [
        'commitment' => 'Matchdays and club events',
        'about' => "A hot pie, a bite to eat or a cup of tea is a matchday tradition, and the kitchen is a busy place when the crowd comes in. We need volunteers to prepare and serve food on matchdays and at club events.\n\nYou would prepare food and refreshments, serve supporters at the hatch, keep the kitchen clean and safe, and help with stock and setting up. It is a fast, friendly job, and a good way to meet people and be part of the matchday team without missing the game.\n\nA food hygiene certificate is a bonus but not essential. All you need is a friendly attitude and a willingness to muck in, and training and guidance are provided.",
        'do' => ['Prepare and serve food and refreshments.', 'Keep the kitchen and serving area clean and safe.', 'Follow food safety and hygiene guidelines.', 'Help with stock and set-up.'],
        'need' => ['A friendly attitude and a willingness to work in a team.', 'A food hygiene certificate is a bonus, but not essential.'],
        'offer' => ['A key part of every home game.', 'Training and guidance from the team.', 'A friendly group of volunteers.'],
    ],
    'Social Media Volunteer' => [
        'commitment' => 'Matchdays and a little time each week',
        'about' => "Help us tell the Vics story online. Our social channels are how many supporters follow the club, and we are looking for someone to keep them lively, up to date and full of personality.\n\nYou would share live match updates, results and fixtures, take and post photos and short videos, reply to messages and comments, and promote events, tickets and club news. You will have the chance to show the club at its best, from the build-up to the full-time whistle and everything in between.\n\nWe are looking for someone who is comfortable with Facebook, X and Instagram and has a good eye for a photo. If you are creative, reliable and love the Vics, you will have plenty of freedom to shape how the club sounds online.",
        'do' => ['Post live match updates, results and fixtures.', 'Take and share photos and short videos.', 'Reply to supporter messages and comments.', 'Promote events, tickets and club news.'],
        'need' => ['Confident using Facebook, X and Instagram.', 'A good eye for photos and video, and clear writing.', 'Enthusiasm for the Vics.'],
        'offer' => ['Creative freedom to shape the club voice online.', 'Access to matchday and behind-the-scenes content.', 'A role that grows with you.'],
    ],
];

$vacancies = pub_staff_vacancies();
$deptLabels = ['committee' => 'Committee', 'football' => 'Football', 'other' => 'Volunteer role'];

set_meta([
    'title' => 'Vacancies',
    'description' => 'Roles at ' . club('club_name') . ' that need filling, and how to get involved.',
]);
seo_breadcrumbs([['Club', url('club/history')], ['Vacancies', url('club/vacancies')]]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'The club',
    'title'   => 'Vacancies',
    'sub'     => $vacancies ? 'Roles we are looking to fill.' : 'Want to help the Vics? We would love to hear from you.',
]); ?>

<?php if (trim(strip_tags($introTop)) !== ''): ?>
<section class="vac-intro">
  <div class="container">
    <div class="vac-intro__box">
      <div class="prose vac-intro__text"><?= $introTop /* sanitised */ ?></div>
      <a class="btn btn--lg btn--gold" href="<?= e(url('enquire/volunteers')) ?>">Offer to volunteer</a>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($vacancies): ?>
<section class="band band--surface" id="roles">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">Get involved</span>
      <h2>Roles we need filling</h2>
    </div></div>
    <div class="jobs" data-jobs>
      <nav class="jobs__list" aria-label="Open roles">
        <p class="jobs__count"><?= count($vacancies) ?> open role<?= count($vacancies) === 1 ? '' : 's' ?></p>
        <?php foreach ($vacancies as $i => $v): $slug = 'role-' . $v['id']; ?>
          <button type="button" class="jobs__item<?= $i === 0 ? ' is-active' : '' ?>" data-job="<?= e($slug) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>">
            <span class="jobs__dept"><?= e($deptLabels[$v['department']] ?? 'Club role') ?></span>
            <strong><?= e($v['name']) ?></strong>
            <span class="jobs__meta"><?= e(($roleDetails[$v['name']]['commitment'] ?? 'Flexible')) ?> &middot; Unpaid</span>
          </button>
        <?php endforeach; ?>
      </nav>
      <div class="jobs__detail">
        <?php foreach ($vacancies as $i => $v):
            $slug = 'role-' . $v['id'];
            $d = $roleDetails[$v['name']] ?? null;
            $apply = url('enquire/volunteers') . '?message=' . rawurlencode('I am interested in the ' . $v['name'] . ' role.');
        ?>
          <article class="job" id="<?= e($slug) ?>"<?= $i === 0 ? '' : ' hidden' ?>>
            <button type="button" class="job__back" data-job-back>&larr; All roles</button>
            <span class="jobs__dept"><?= e($deptLabels[$v['department']] ?? 'Club role') ?></span>
            <h3><?= e($v['name']) ?></h3>
            <p class="job__club"><?= e(club('club_name', 'Saltcoats Victoria FC')) ?> &middot; Campbell Park, Saltcoats</p>
            <ul class="job__chips">
              <li>Volunteer</li>
              <li>Unpaid</li>
              <li><?= e($d['commitment'] ?? 'Flexible') ?></li>
            </ul>
            <a class="btn btn--lg btn--gold" href="<?= e($apply) ?>">I&rsquo;m interested</a>
            <hr>
            <h4>About the role</h4>
            <?php foreach (preg_split('~\\n\\n~', (string) ($d['about'] ?? $v['blurb'])) as $para): ?><p><?= e($para) ?></p><?php endforeach; ?>
            <?php if ($d): ?>
              <h4>What you will do</h4>
              <ul><?php foreach ($d['do'] as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul>
              <h4>What we are looking for</h4>
              <ul><?php foreach ($d['need'] as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul>
              <h4>What you get</h4>
              <ul><?php foreach ($d['offer'] as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul>
            <?php elseif ($v['blurb'] !== ''): ?>
              <p><?= nl2br(e($v['blurb'])) ?></p>
            <?php endif; ?>
            <h4>How to apply</h4>
            <p>Press &ldquo;I&rsquo;m interested&rdquo; and send us a short message about yourself. All roles are subject to our <a href="/club/policies#safe-recruitment-volunteers">safe recruitment policy</a>.</p>
            <a class="btn btn--lg btn--gold" href="<?= e($apply) ?>">I&rsquo;m interested</a>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if (trim(strip_tags($introRest)) !== ''): ?>
    <aside class="safe">
      <svg class="safe__icon" viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 4.5-3 8.2-7 10-4-1.8-7-5.5-7-10V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg>
      <div class="safe__body prose"><?= $introRest /* sanitised */ ?>
        <p>We are committed to keeping everyone at the club safe. Volunteers who work with young people or vulnerable adults are asked to complete recruitment checks before they start.</p>
      </div>
      <div class="safe__links">
        <a class="btn" href="/club/policies#safe-recruitment-volunteers">Safe recruitment policy</a>
        <a class="btn btn--ghost" href="/club/policies#child-protection-policy">Child protection policy</a>
      </div>
    </aside>
    <?php endif; ?>
  </div>
</section>
<script>
(function () {
  var root = document.querySelector('[data-jobs]');
  if (!root) return;
  var items = root.querySelectorAll('[data-job]');
  var jobs = root.querySelectorAll('.job');
  function show(id, scroll) {
    var found = false;
    jobs.forEach(function (j) { var on = j.id === id; j.hidden = !on; found = found || on; });
    items.forEach(function (b) { var on = b.dataset.job === id; b.classList.toggle('is-active', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    root.classList.add('is-open');
    if (scroll && window.matchMedia('(max-width: 800px)').matches) root.scrollIntoView({ block: 'start' });
    return found;
  }
  items.forEach(function (b) {
    b.addEventListener('click', function () {
      show(b.dataset.job, true);
      try { history.replaceState(null, '', '#' + b.dataset.job); } catch (e) {}
    });
  });
  root.querySelectorAll('[data-job-back]').forEach(function (b) {
    b.addEventListener('click', function () { root.classList.remove('is-open'); root.scrollIntoView({ block: 'start' }); });
  });
  var h = location.hash.replace('#', '');
  if (h && document.getElementById(h) && /^role-/.test(h)) show(h, false);
  root.classList.remove('is-open');
  if (h && /^role-/.test(h)) root.classList.add('is-open');
})();
</script>
<?php endif; ?>
