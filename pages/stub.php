<?php
/** Placeholder for routes whose real page arrives in a later build step. */
declare(strict_types=1);

$labels = [
    'news'           => 'News',
    'fixtures'       => 'Fixtures',
    'results'        => 'Results',
    'table'          => 'League table',
    'team'           => 'First team',
    'staff'          => 'Management & staff',
    'partners'       => 'Partners',
    'contact'        => 'Contact the club',
    'privacy'        => 'Privacy policy',
    'club/history'   => 'Club history',
    'club/stadium'   => 'Ground & directions',
    'club/officials' => 'Club officials',
];
$route = current_route();
$label = $labels[$route] ?? $labels[explode('/', $route)[0]] ?? 'Coming soon';
set_meta(['title' => $label]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Coming soon',
    'title'   => $label,
    'sub'     => 'This section of the new ' . club('club_name') . ' website is still being built. Check back soon.',
]); ?>

<div class="page">
  <div class="container">
    <p><a class="linkarrow" href="<?= e(url()) ?>">Back to home</a></p>
  </div>
</div>
