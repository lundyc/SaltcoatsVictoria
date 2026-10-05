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
    ['route' => 'team',  'label' => 'Team',    'href' => url('team'), 'sections' => ['team'], 'children' => [
        'First team'  => url('team'),
        'Management'  => url('staff'),
    ]],
    ['route' => null,    'label' => 'Matches', 'href' => url('fixtures'), 'sections' => ['fixtures', 'results', 'table', 'match', 'club/results', 'club/records'], 'children' => [
        'Fixtures'        => url('fixtures'),
        'Results'         => url('results'),
        'League table'    => url('table'),
        'Club records'    => url('club/records'),
    ]],
    ['route' => null,    'label' => 'Club',    'href' => url('club/history'), 'sections' => ['club', 'contact', 'privacy', 'gallery'], 'except' => ['club/results', 'club/records'], 'children' => [
        'About the club'    => url('club/history'),
        'Honours & awards'  => url('club/honours'),
        'Photo gallery'     => url('gallery'),
        'Policies'          => url('club/policies'),
        'Contact & find us' => url('contact'),
    ]],
    ['route' => 'partners', 'label' => 'Partners', 'href' => url('partners')],
    ['route' => 'tickets', 'label' => 'Tickets', 'href' => url('tickets')],
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
?>
<button class="nav-scrim" type="button" tabindex="-1" aria-hidden="true" hidden></button>

<nav class="primary-nav" id="primary-nav" aria-label="Primary">
  <div class="primary-nav__bar">
    <span class="primary-nav__title">Menu</span>
    <button class="primary-nav__close" type="button" aria-label="Close menu"><?= pub_icon('close') ?></button>
  </div>

  <ul class="primary-nav__list">
    <?php foreach ($nav as $i => $item): ?>
      <?php
        $active = $isSection($item);
        $hasChildren = !empty($item['children']);
        $panelId = 'subnav-' . $i;
      ?>
      <li class="nav-item<?= $active ? ' is-section' : '' ?><?= $hasChildren ? ' has-sub' : '' ?>">
        <a class="nav-item__link" href="<?= e($item['href']) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
          <span><?= e($item['label']) ?></span>
        </a>
        <?php if ($hasChildren): ?>
          <button class="nav-item__toggle" type="button" aria-expanded="false" aria-controls="<?= $panelId ?>" aria-label="<?= e($item['label']) ?> menu">
            <?= pub_icon('chevron') ?>
          </button>
          <div class="subnav" id="<?= $panelId ?>">
            <?php foreach ($item['children'] as $label => $href): ?>
              <a class="subnav__link" href="<?= e($href) ?>"<?= $childActive($href) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php $navSocial = pub_social_links(); ?>
  <?php if ($navSocial): ?>
    <div class="primary-nav__social">
      <?php foreach ($navSocial as $network => $href): ?>
        <a href="<?= e($href) ?>" aria-label="<?= e(ucfirst($network)) ?>" rel="noopener" target="_blank"><?= pub_icon($network) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</nav>
