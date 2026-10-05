<?php
/** Route: /club/policies — the club's governance documents, all on one page
 *  with a jump nav. (Each policy is still individually editable in the Hub as
 *  its own club_pages row; "privacy" also keeps its standalone /privacy route.) */
declare(strict_types=1);

$slugs = [
    'child-protection-policy', 'safeguarding-adults-at-risk', 'code-of-conduct',
    'equality-diversity-inclusion', 'anti-discrimination-anti-racism-anti-sectarianism',
    'complaints-disciplinary-procedure', 'health-safety-emergency-procedures',
    'ground-regulations', 'safe-recruitment-volunteers', 'photography-filming-social-media',
    'data-protection-gdpr', 'privacy',
];
$menu  = club_menu_pages($slugs);

$policies = [];
foreach ($menu as $slug => $label) {
    $page = club_page_by_slug(db(), $slug);
    if ($page === null) {
        continue;
    }
    $policies[] = [
        'slug'  => $slug,
        'label' => $label,
        'html'  => pub_club_page_html($page),
    ];
}

set_meta([
    'title' => club('club_name') . ' Policies & Governance',
    'description' => 'Safeguarding, conduct, equality, complaints, health & safety, data protection and ground regulations for ' . club('club_name') . '.',
]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'The club',
    'title'   => 'Policies & governance',
    'sub'     => 'How ' . club('club_name') . ' is run — the standards we hold ourselves to, on and off the pitch.',
]); ?>

<div class="page">
  <div class="container">
    <?php if (!$policies): ?>
      <div class="emptystate"><p>Policy documents will be published here soon.</p></div>
    <?php else: ?>
      <div class="policytabs" data-policytabs>
        <nav class="policytabs__nav" aria-label="Policies">
          <?php foreach ($policies as $p): ?>
            <a class="policytabs__tab" href="#<?= e($p['slug']) ?>"><?= e($p['label']) ?></a>
          <?php endforeach; ?>
        </nav>

        <div class="policytabs__panels">
          <?php foreach ($policies as $p): ?>
            <section class="policydoc aboutblock" id="<?= e($p['slug']) ?>" aria-labelledby="h-<?= e($p['slug']) ?>">
              <h2 class="squad-heading" id="h-<?= e($p['slug']) ?>"><?= e($p['label']) ?></h2>
              <div class="prose"><?= $p['html'] /* sanitised */ ?></div>
            </section>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
