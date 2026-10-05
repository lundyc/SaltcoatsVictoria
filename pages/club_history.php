<?php
/** Route: /club/history — "About the club". A single scrolling page split into
 *  distinct, alternating-background sections: the club story, the club at a
 *  glance, what the club stands for (mission / vision / values), the committee,
 *  and links out to the rest of the site. Merges the former History,
 *  Mission-Vision-Values and Club officials pages. */
declare(strict_types=1);

$page = club_page_by_slug(db(), 'history');
$body = $page ? club_page_render($page) : '';

$mvvPage = club_page_by_slug(db(), 'mission-vision-values');
$mvvHtml = $mvvPage ? pub_club_page_html($mvvPage) : '';

$officials      = pub_staff_officials();
$officialsPage  = club_page_by_slug(db(), 'officials');
$officialsIntro = '';
if ($officialsPage) {
    $rendered = pub_club_page_html($officialsPage);
    if (stripos(strip_tags($rendered), 'has not been written') === false) {
        $officialsIntro = $rendered;
    }
}

$founded  = club('club_founded');
$nickname = club('club_nickname');
$ground   = club('ground_name', 'Campbell Park');
$league   = club('league_name', 'West of Scotland Football League');
$record   = club('ground_record_attendance');
$primary  = club('brand_primary', '#6d2231');
$accent   = club('brand_accent', '#e0b42a');
$years    = ($founded !== '' && ctype_digit($founded)) ? (int) date('Y') - (int) $founded : null;

/**
 * Split the Mission/Vision/Values page HTML into structured parts:
 *   ['intro' => string[], 'mission' => string, 'vision' => item[], 'values' => item[]]
 * where item = ['title' => string, 'body' => string]. Empty parts on failure.
 */
$mvv = (static function (string $html): array {
    $out = ['intro' => [], 'mission' => '', 'vision' => [], 'values' => []];
    if (trim($html) === '' || !class_exists('DOMDocument')) {
        return $out;
    }
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="r">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    $root = $doc->getElementById('r');
    if (!$root) {
        return $out;
    }

    $section = '';
    foreach (iterator_to_array($root->childNodes) as $node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }
        $tag  = strtolower($node->nodeName);
        $text = trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');
        if ($text === '') {
            continue;
        }
        if ($tag === 'h3') {
            $l = strtolower($text);
            $section = str_contains($l, 'mission') ? 'mission'
                : (str_contains($l, 'vision') ? 'vision'
                : (str_contains($l, 'value') ? 'values' : ''));
            continue;
        }
        if ($tag === 'h4' && ($section === 'vision' || $section === 'values')) {
            $out[$section][] = ['title' => $text, 'body' => ''];
            continue;
        }
        if ($tag === 'p' || str_starts_with($tag, 'h')) {
            if ($section === 'mission') {
                $out['mission'] = trim($out['mission'] . ' ' . $text);
            } elseif (($section === 'vision' || $section === 'values') && $out[$section] !== []) {
                $k = array_key_last($out[$section]);
                $out[$section][$k]['body'] = trim($out[$section][$k]['body'] . ' ' . $text);
            } elseif ($section === '' && $tag === 'p' && stripos($text, 'view our mission') === false) {
                $out['intro'][] = $text;
            }
        }
    }
    return $out;
})($mvvHtml);

$mvvOk = $mvv['mission'] !== '' || $mvv['vision'] !== [] || $mvv['values'] !== [];

set_meta([
    'title' => club('club_name') . ' History' . ($founded !== '' ? ' | Founded ' . $founded : ''),
    'title_full' => '1',
    'description' => club('club_name') . ($founded !== '' ? ' — formed ' . $founded . '.' : '')
        . ' Our story, what the club stands for, and the committee that runs it.',
]);
seo_breadcrumbs([['Club', url('club/history')], ['History', url('club/history')]]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'The club',
    'title'   => 'About the club',
    'sub'     => ($founded !== '' ? 'Formed ' . $founded : club('club_name'))
        . ($nickname !== '' ? ' · ' . $nickname : ''),
]); ?>

<section class="band about-story">
  <div class="container clay">
    <div>
      <span class="eyebrow about-story__eyebrow">Our story</span>
      <div class="prose prose--lead about-story__body">
        <?= $body !== '' ? $body : '<p>The club story is being written and will appear here soon.</p>' ?>
      </div>
    </div>

    <aside>
      <div class="factbox">
        <img class="factbox__crest" src="<?= e(club_crest()) ?>" alt="<?= e(club('club_name')) ?> crest">
        <h2>Club facts</h2>
        <dl>
          <?php if ($founded !== ''): ?><div><dt>Formed</dt><dd><?= e($founded) ?></dd></div><?php endif; ?>
          <?php if ($nickname !== ''): ?><div><dt>Nickname</dt><dd><?= e($nickname) ?></dd></div><?php endif; ?>
          <div><dt>Home ground</dt><dd><?= e($ground) ?></dd></div>
          <div><dt>League</dt><dd><?= e($league) ?></dd></div>
          <div>
            <dt>Colours</dt>
            <dd class="swatches">
              <span class="swatch"><i style="background:<?= e($primary) ?>"></i> Maroon</span>
              <span class="swatch"><i style="background:<?= e($accent) ?>"></i> Gold</span>
            </dd>
          </div>
        </dl>
      </div>
    </aside>
  </div>
</section>

<section class="band band--ink">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">At a glance</span>
      <h2>The club in numbers</h2>
    </div></div>
    <div class="factstrip">
      <?php if ($founded !== ''): ?>
        <div><span class="factstrip__num"><?= e($founded) ?></span><span class="factstrip__lbl">Year founded</span></div>
      <?php endif; ?>
      <?php if ($years !== null): ?>
        <div><span class="factstrip__num"><?= $years ?></span><span class="factstrip__lbl">Years of history</span></div>
      <?php endif; ?>
      <?php if ($nickname !== ''): ?>
        <div><span class="factstrip__num factstrip__num--text"><?= e($nickname) ?></span><span class="factstrip__lbl">Known as</span></div>
      <?php endif; ?>
      <div><span class="factstrip__num factstrip__num--text"><?= e($ground) ?></span><span class="factstrip__lbl">Home ground</span></div>
      <?php if ($record !== ''): ?>
        <div><span class="factstrip__num"><?= e($record) ?></span><span class="factstrip__lbl">Record attendance</span></div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="band band--surface">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">What we stand for</span>
      <h2>Mission, vision &amp; values</h2>
    </div></div>

    <?php if (!$mvvOk): ?>
      <div class="prose"><?= $mvvHtml !== '' ? $mvvHtml : '<p>Coming soon.</p>' /* sanitised */ ?></div>
    <?php else: ?>
      <?php foreach ($mvv['intro'] as $p): ?>
        <p class="mvv-intro"><?= e($p) ?></p>
      <?php endforeach; ?>

      <?php if ($mvv['mission'] !== ''): ?>
        <figure class="mvv-mission">
          <span class="mvv-mission__k">Our mission</span>
          <blockquote><?= e($mvv['mission']) ?></blockquote>
        </figure>
      <?php endif; ?>

      <?php if ($mvv['vision'] !== []): ?>
        <h3 class="mvv-subhead">Our vision</h3>
        <div class="mvv-grid">
          <?php foreach ($mvv['vision'] as $i => $v): ?>
            <div class="mvv-card">
              <span class="mvv-card__step"><?= sprintf('%02d', $i + 1) ?></span>
              <h4><?= e($v['title']) ?></h4>
              <p><?= e($v['body']) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($mvv['values'] !== []): ?>
        <h3 class="mvv-subhead">Our values</h3>
        <div class="mvv-values">
          <?php foreach ($mvv['values'] as $v): ?>
            <div class="mvv-value">
              <h4><?= e($v['title']) ?></h4>
              <p><?= e($v['body']) ?></p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<section class="band band--tint" id="committee">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">Behind the scenes</span>
      <h2>The committee</h2>
    </div></div>

    <?php if ($officialsIntro !== ''): ?>
      <div class="prose"><?= $officialsIntro /* sanitised */ ?></div>
    <?php endif; ?>

    <?php if ($officials === []): ?>
      <div class="emptystate"><p>Committee details will be published here soon.</p></div>
    <?php else: ?>
      <div class="staff-grid staff-grid--four"<?= $officialsIntro !== '' ? ' style="margin-top:1.6rem"' : '' ?>>
        <?php foreach ($officials as $person): ?>
          <div class="staffcard">
            <div class="staffcard__photo"><?= pub_staff_photo_html($person) ?></div>
            <span class="staffcard__role"><?= e($person['label'] ?? $person['position']) ?></span>
            <span class="staffcard__name"><?= e($person['name']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <p class="fxnote"><a class="linkarrow" href="<?= e(url('staff')) ?>">First-team management &amp; coaching staff</a></p>
  </div>
</section>

<section class="band">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">Go deeper</span>
      <h2>Explore the club</h2>
    </div></div>
    <div class="explore-grid">
      <a class="explore-card" href="<?= e(url('club/honours')) ?>">
        <span class="explore-card__t">Honours &amp; awards</span>
        <span class="explore-card__d">Every trophy and player-of-the-year the club has on record.</span>
        <span class="explore-card__go">View honours</span>
      </a>
      <a class="explore-card" href="<?= e(url('team')) ?>">
        <span class="explore-card__t">First-team squad</span>
        <span class="explore-card__d">The current playing squad and the management team.</span>
        <span class="explore-card__go">Meet the squad</span>
      </a>
      <a class="explore-card" href="<?= e(url('contact')) ?>">
        <span class="explore-card__t">Campbell Park</span>
        <span class="explore-card__d">Directions, facilities and everything for matchday.</span>
        <span class="explore-card__go">Find the ground</span>
      </a>
      <a class="explore-card" href="<?= e(url('club/policies')) ?>">
        <span class="explore-card__t">Policies &amp; governance</span>
        <span class="explore-card__d">The standards the club holds itself to, on and off the pitch.</span>
        <span class="explore-card__go">Read policies</span>
      </a>
    </div>
  </div>
</section>
