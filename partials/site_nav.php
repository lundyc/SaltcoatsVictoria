<?php
/** Primary navigation.
 *  Desktop: dropdowns open on hover / focus-within, or via the caret toggle.
 *  Mobile:  slide-in drawer (public.js) with per-item caret toggles.
 */
declare(strict_types=1);

/**
 * route/href => active-section check + top-level link
 * label      => visible text
 * children   => optional [label => href]
 * sections   => route prefixes that light this item up (defaults to [route])
 * except     => route prefixes that must NOT light this item up
 */
$nav = [
    ['route' => 'news',  'label' => 'News',    'href' => url('news')],
    ['route' => 'team',  'panel' => 'team', 'label' => 'Team',    'href' => url('team'), 'sections' => ['team'], 'children' => [
        'First team'  => url('team'),
        'Management'  => url('staff'),
    ]],
    ['route' => null,    'panel' => 'matches', 'label' => 'Matches', 'href' => url('fixtures'), 'sections' => ['fixtures', 'results', 'table', 'stats', 'match', 'club/results', 'club/records'], 'children' => [
        'Fixtures'        => url('fixtures'),
        'Results'         => url('results'),
        'League table'    => url('table'),
        'Stats'           => url('stats'),
        'Club records'    => url('club/records'),
    ]],
    ['route' => null,    'label' => 'Club',    'href' => url('club/history'), 'sections' => ['club', 'contact', 'privacy', 'gallery'], 'except' => ['club/results', 'club/records'], 'children' => [
        'Advertising & sponsorship' => ['href' => url('commercial'), 'children' => [
            'Match sponsorship'         => url('matchday-sponsorship'),
            'Dugout sponsorship'        => url('club/advertising-sponsorship/dugout-sponsorship'),
            'Match ball sponsorship'    => url('matchday-sponsorship'),
            'Captain sponsorship'       => url('club/advertising-sponsorship/captain-sponsorship'),
            'Stadium advertising'       => url('pitchside-boards'),
            'Digital media sponsorship' => url('club/advertising-sponsorship/digital-media-sponsorship'),
            'Programme advertising'     => url('club/advertising-sponsorship/programme-advertising'),
            'Player sponsorship'        => url('playersponsors'),
        ]],
        'Hire the ground' => ['href' => url('club/hire-the-ground'), 'children' => [
            'Birthdays'              => url('club/hire-the-ground/birthdays'),
            'Conferences & events'   => url('club/hire-the-ground/conferences-events'),
            'Weddings'               => url('club/hire-the-ground/weddings'),
            'Funerals & remembrance' => url('club/hire-the-ground/funerals-remembrance'),
        ]],
        // 'Events' => url('club/events'), // hidden for now
        'Community'         => url('club/community'),
        // 'Academy' => url('club/academy'), // hidden for now
        'Media relations' => ['href' => url('club/media-relations'), 'children' => [
            'Matchday accreditation' => url('club/media-relations/matchday-accreditation'),
        ]],
        'Committee & volunteers' => url('club/officials'),
        'Club partners'     => url('partners'),
        'History' => ['href' => url('club/history'), 'children' => [
            'Honours'                  => url('club/honours'),
            'Records'                  => url('club/records'),
            'Through the years'        => url('club/history/through-the-years'),
        ]],
        'Photo gallery'     => url('gallery'),
        'Policies' => ['href' => url('club/policies'), 'children' => [
            'Child protection'                 => url('club/policies') . '#child-protection-policy',
            'Safeguarding adults at risk'      => url('club/policies') . '#safeguarding-adults-at-risk',
            'Code of conduct'                  => url('club/policies') . '#code-of-conduct',
            'Equality, diversity & inclusion'  => url('club/policies') . '#equality-diversity-inclusion',
            'Anti-discrimination'              => url('club/policies') . '#anti-discrimination-anti-racism-anti-sectarianism',
            'Complaints & discipline'          => url('club/policies') . '#complaints-disciplinary-procedure',
            'Health & safety'                  => url('club/policies') . '#health-safety-emergency-procedures',
            'Ground regulations'               => url('club/policies') . '#ground-regulations',
            'Safe recruitment & volunteers'    => url('club/policies') . '#safe-recruitment-volunteers',
            'Photography & social media'       => url('club/policies') . '#photography-filming-social-media',
            'Data protection (GDPR)'           => url('club/policies') . '#data-protection-gdpr',
        ]],
        'Supporter services' => ['href' => url('club/supporter-services'), 'children' => [
            'Supporter liaison officer' => url('club/supporter-services/slo'),
            'Club 1889 lottery'         => url('lottery'),
            'Disabled supporters'       => url('club/supporter-services/disabled-supporters'),
            'Supporters clubs'          => url('club/supporter-services/supporters-clubs'),
            'Club accounts'             => url('club/supporter-services/club-accounts'),
        ]],
        'Vacancies'         => url('club/vacancies'),
        'Contact us'        => url('contact'),
    ], 'cards' => [
        ['title' => 'Sponsor a match',  'text' => 'Put your business in front of our supporters on matchday.', 'href' => url('matchday-sponsorship'), 'img' => '/uploads/site/sponsor-cta.webp'],
        ['title' => 'Pitchside boards', 'text' => 'Advertise around the pitch all season long.',               'href' => url('pitchside-boards'),     'img' => '/uploads/site/pitchside-cta.webp'],
    ]],
    ['route' => 'commercial', 'label' => 'Commercial', 'href' => url('commercial'), 'sections' => ['commercial', 'partners', 'pitchside-boards', 'playersponsors', 'matchday-sponsorship', 'hospitality', 'lottery']],
    ['route' => 'tickets', 'label' => 'Tickets', 'href' => url('tickets')],
    ['route' => 'events',  'label' => 'Events',  'href' => url('events')],
    ['route' => 'shop',    'label' => 'Shop',    'href' => url('shop')],
];

$isSection = static function (array $item): bool {
    foreach ($item['except'] ?? [] as $prefix) {
        if (nav_active($prefix)) {
            return false;
        }
    }
    foreach ($item['sections'] ?? ($item['route'] !== null ? [$item['route']] : []) as $prefix) {
        if (nav_active($prefix)) {
            return true;
        }
    }
    return false;
};

$curPath = trim((string) ($GLOBALS['pub_route'] ?? ''), '/');
$childActive = static function (string $href) use ($curPath): bool {
    $p = trim((string) (parse_url($href, PHP_URL_PATH) ?? ''), '/');
    return $p !== '' && ($curPath === $p || str_starts_with($curPath, $p . '/'));
};

/* Extra content for the desktop mega panels (hidden on mobile). */
$mnNext = pub_next_fixture();
$mnLast = (pub_recent_results(1)[0] ?? null);
$mnTable = pub_league_table();
$mnRows = [];
if (!empty($mnTable['ok'])) {
    $all = $mnTable['rows'];
    $ourIdx = 0;
    foreach ($all as $k => $r) { if (pub_league_is_us($r)) { $ourIdx = $k; break; } }
    $start = max(0, min(count($all) - 5, $ourIdx - 2));
    $mnRows = array_slice($all, $start, 5);
}
$mnSquad = squad_public_grouped(db());
$mnSquadLabels = ['GK' => 'Goalkeepers', 'DEF' => 'Defenders', 'MID' => 'Midfielders', 'FWD' => 'Forwards'];
$mnCrests = static function (array $f): string {
    $club = club_crest_reverse();
    $opp = pub_opponent_crest($f['opponent_logo'] ?? '', (string) ($f['opponent_name'] ?? $f['opponent'] ?? ''));
    $home = !empty($f['is_home']) ? $club : $opp;
    $away = !empty($f['is_home']) ? $opp : $club;
    $h = '';
    foreach ([$home, $away] as $src) { if ($src !== '') { $h .= '<img src="' . e($src) . '" alt="" width="36" height="36" loading="lazy">'; } }
    return $h;
};
?>
<button class="nav-scrim" type="button" tabindex="-1" aria-hidden="true" hidden></button>

<nav class="primary-nav" id="primary-nav" aria-label="Primary">
  <div class="primary-nav__bar">
    <a class="primary-nav__crest" href="<?= e(url()) ?>" aria-label="Home"><img src="<?= e(club_crest_reverse()) ?>" alt="" width="56" height="56"></a>
    <button class="primary-nav__close" type="button" aria-label="Close menu"><?= pub_icon('close') ?></button>
  </div>

  <ul class="primary-nav__list">
    <?php foreach ($nav as $i => $item): ?>
      <?php
        $active = $isSection($item);
        $hasChildren = !empty($item['children']);
        $panelId = 'subnav-' . $i;
      ?>
      <?php if ($i === 4): ?><li class="nav-gap" aria-hidden="true"></li><?php endif; ?>
      <li class="nav-item<?= $active ? ' is-section' : '' ?><?= $hasChildren ? ' has-sub' : '' ?>">
        <a class="nav-item__link" href="<?= e($item['href']) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
          <span><?= e($item['label']) ?></span>
        </a>
        <?php if ($hasChildren): ?>
          <button class="nav-item__toggle" type="button" aria-expanded="false" aria-controls="<?= $panelId ?>" aria-label="<?= e($item['label']) ?> menu">
            <?= pub_icon('chevron') ?>
          </button>
          <div class="subnav" id="<?= $panelId ?>">
            <?php $navCards = $item['cards'] ?? []; ?>
            <div class="subnav__inner<?= $navCards ? ' subnav__inner--cards' : '' ?>">
              <div class="subnav__links"<?= $navCards ? ' style="--rows:' . (int) ceil(count($item['children']) / 2) . '"' : '' ?>>
                <?php foreach ($item['children'] as $label => $child): ?>
                  <?php if (!is_array($child)): ?>
                    <a class="subnav__link" href="<?= e($child) ?>"<?= $childActive($child) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
                  <?php else: ?>
                    <?php
                      $grpOpen = $childActive($child['href']);
                      $grpId = $panelId . '-' . substr(md5($label), 0, 6);
                    ?>
                    <div class="subnav__group<?= $grpOpen ? ' is-open' : '' ?>">
                      <a class="subnav__link" href="<?= e($child['href']) ?>"<?= $curPath === trim((string) parse_url($child['href'], PHP_URL_PATH), '/') ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
                      <button class="subnav__grouptoggle" type="button" aria-expanded="<?= $grpOpen ? 'true' : 'false' ?>" aria-controls="<?= $grpId ?>" aria-label="<?= e($label) ?> pages"><?= pub_icon('chevron') ?></button>
                      <div class="subnav__sub" id="<?= $grpId ?>">
                        <?php foreach ($child['children'] as $cl => $ch): ?>
                          <a class="subnav__sublink" href="<?= e($ch) ?>"<?= (!str_contains($ch, '#') && $childActive($ch)) ? ' aria-current="page"' : '' ?>><?= e($cl) ?></a>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>

              <?php foreach ($navCards as $card): ?>
                <a class="subnav__card" href="<?= e($card['href']) ?>">
                  <span class="subnav__cardimg" style="background-image:url('<?= e($card['img']) ?>')"></span>
                  <strong><?= e($card['title']) ?></strong>
                  <small><?= e($card['text']) ?></small>
                </a>
              <?php endforeach; ?>

              <?php if (($item['panel'] ?? '') === 'matches'): ?>
                <div class="subnav__extra subnav__fixtures">
                  <?php if ($mnNext): $t = pub_fixture_teams($mnNext); ?>
                    <p class="subnav__label">Next fixture</p>
                    <a class="subnav__match" href="<?= e(url('fixtures')) ?>">
                      <span class="subnav__crests"><?= $mnCrests($mnNext) ?></span>
                      <span class="subnav__matchtext"><strong><?= e(mb_strtoupper($t['home'] . ' v ' . $t['away'])) ?></strong>
                        <small><?= e(($mnNext['competition'] ?: 'Fixture') . ', ' . format_date($mnNext['match_date'], 'd/m/y') . (format_time($mnNext['kickoff_time'] ?? '') !== '' ? ' ' . format_time($mnNext['kickoff_time']) : '')) ?></small></span>
                    </a>
                  <?php endif; ?>
                  <?php if ($mnLast): $t = pub_fixture_teams($mnLast); $o = pub_fixture_outcome($mnLast); ?>
                    <p class="subnav__label">Latest result</p>
                    <a class="subnav__match" href="<?= e(url('match/' . (int) $mnLast['id'])) ?>">
                      <span class="subnav__crests"><?= $mnCrests($mnLast) ?></span>
                      <span class="subnav__matchtext"><strong><?= e(mb_strtoupper($t['home'])) ?> <?= (int) $mnLast['full_time_home_score'] ?> - <?= (int) $mnLast['full_time_away_score'] ?> <?= e(mb_strtoupper($t['away'])) ?></strong>
                        <small><?= e(($mnLast['competition'] ?: 'Fixture') . ', ' . format_date($mnLast['match_date'], 'd/m/y')) ?></small></span>
                    </a>
                    <a class="subnav__more" href="<?= e(url('match/' . (int) $mnLast['id'])) ?>">Match centre <span aria-hidden="true">&rsaquo;</span></a>
                  <?php endif; ?>
                </div>
                <?php if ($mnRows): ?>
                  <div class="subnav__extra subnav__table">
                    <p class="subnav__label">League table</p>
                    <table>
                      <tbody>
                      <?php foreach ($mnRows as $r): ?>
                        <tr<?= pub_league_is_us($r) ? ' class="is-us"' : '' ?>>
                          <td><?= e($r['pos'] ?? '') ?></td><td class="club"><?= e(mb_strtoupper((string) ($r['club'] ?? ''))) ?></td>
                          <td><?= e($r['p'] ?? '') ?></td><td><?= e($r['w'] ?? '') ?></td><td><?= e($r['d'] ?? '') ?></td><td><?= e($r['l'] ?? '') ?></td><td><?= e($r['gd'] ?? '') ?></td><td><?= e($r['pts'] ?? '') ?></td>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table>
                    <a class="subnav__more" href="<?= e(url('table')) ?>">View full league table <span aria-hidden="true">&rsaquo;</span></a>
                  </div>
                <?php endif; ?>
              <?php elseif (($item['panel'] ?? '') === 'team'): ?>
                <div class="subnav__extra subnav__squad">
                  <?php foreach ($mnSquadLabels as $key => $label): if (empty($mnSquad[$key])) { continue; } ?>
                    <div class="subnav__squadgroup">
                      <p class="subnav__label"><?= e($label) ?></p>
                      <ul>
                        <?php foreach ($mnSquad[$key] as $pl): ?>
                          <li><a href="<?= e(url('team/' . $pl['slug'])) ?>"><?php if ($pl['squad_number'] !== null): ?><span><?= (int) $pl['squad_number'] ?>.</span> <?php endif; ?><?= e($pl['name']) ?></a></li>
                        <?php endforeach; ?>
                      </ul>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php
  // The gold utility bar is hidden on phones and tablets; its account link lives here (shop, tickets and contact are in the menu above).
  require_once HUB_ROOT . '/lib/member_hint.php';
  $navMember = member_hint_read();
  ?>
  <div class="primary-nav__utility">
    <div class="primary-nav__account">
      <?php if ($navMember): ?>
        <a class="primary-nav__login" href="/members/index.php">My Vics</a>
        <a class="primary-nav__logout" href="/members/logout.php?all=1">Log out</a>
      <?php else: ?>
        <a class="primary-nav__login" href="/members/login.php">Log in</a>
      <?php endif; ?>
    </div>
  </div>

  <?php $navSocial = pub_social_links(); ?>
  <?php if ($navSocial): ?>
    <div class="primary-nav__social">
      <span class="primary-nav__follow">Follow us</span>
      <?php foreach ($navSocial as $network => $href): ?>
        <a href="<?= e($href) ?>" aria-label="<?= e(ucfirst($network)) ?>" rel="noopener" target="_blank"><?= pub_icon($network) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</nav>
