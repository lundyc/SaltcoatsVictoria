<?php
/** Route: /club/honours — "Honours & awards". Two vertical timelines: the
 *  club's roll of honour (chronological, grouped by decade) and the
 *  end-of-season player award winners. Split into alternating-background
 *  sections. Both are restructured from their club_pages bodies, each with a
 *  raw-page fallback if the source content no longer matches the expected
 *  shape. Merges the former Honours and Player awards pages. */
declare(strict_types=1);

$page = club_page_by_slug(db(), 'honours');
if ($page === null) {
    http_response_code(404);
    set_meta(['title' => 'Page not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Page not found</h1><p><a class="linkarrow" href="' . e(url()) . '">Home</a></p></div></div>';
    return;
}

$awardsPage = club_page_by_slug(db(), 'player-awards');

/**
 * Parse repeated `<h2>Competition</h2><p>1924 / 1925, …</p>` blocks into
 * [['name'=>…, 'years'=>[…], 'count'=>int], …]. Returns [] if nothing matched.
 */
$parseHonours = static function (string $html): array {
    // Only the real entries are a bare `<div><h2>Name</h2><p>years</p>`; the
    // old-CMS import also carries a `<h2 class="titleinpanel">Honours</h2>View`
    // panel header which a looser pattern would fold into the first entry.
    if (!preg_match_all('#<h2>\s*(.*?)\s*</h2>\s*<p>\s*(.*?)\s*</p>#is', $html, $m, PREG_SET_ORDER)) {
        return [];
    }
    $out = [];
    foreach ($m as $row) {
        $name = trim(html_entity_decode(strip_tags($row[1]), ENT_QUOTES | ENT_HTML5));
        $raw  = trim(html_entity_decode(strip_tags($row[2]), ENT_QUOTES | ENT_HTML5));
        if ($name === '' || $raw === '') {
            continue;
        }
        $years = array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', $raw) ?: [])));
        if ($years === []) {
            continue;
        }
        $out[] = ['name' => $name, 'years' => $years, 'count' => count($years)];
    }
    return $out;
};

/**
 * Parse `<h5>2012-2013</h5>` season headings each followed by a table of
 * "Award: Winner" rows into [['season'=>…, 'awards'=>[['award'=>…,'winner'=>…]]]].
 */
$parseAwards = static function (string $html): array {
    $parts = preg_split('#<h5[^>]*>\s*(\d{4}\s*-\s*\d{4})\s*</h5>#i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!$parts || count($parts) < 3) {
        return [];
    }

    $tidy = static function (string $s): string {
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5);
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    };

    $seasons = [];
    for ($i = 1; $i < count($parts); $i += 2) {
        $season = $tidy($parts[$i]);
        $chunk  = $parts[$i + 1] ?? '';
        $awards = [];

        preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $chunk, $rows);
        foreach ($rows[1] as $rowHtml) {
            preg_match_all('#<td[^>]*>(.*?)</td>#is', $rowHtml, $cells);
            $text = '';
            foreach (array_reverse($cells[1]) as $c) {
                $c = $tidy($c);
                if (str_contains($c, ':')) {
                    $text = $c;
                    break;
                }
            }
            if ($text === '') {
                continue;
            }
            [$award, $winner] = array_map('trim', explode(':', $text, 2) + ['', '']);
            if ($winner === '') {
                continue;
            }
            $awards[] = [
                'award'  => ucwords(strtolower($award)),
                'winner' => $winner,
            ];
        }

        if ($awards !== []) {
            $seasons[] = ['season' => $season, 'awards' => $awards];
        }
    }

    usort($seasons, static fn ($a, $b) => strcmp($b['season'], $a['season']));
    return $seasons;
};

$honours = $parseHonours((string) $page['body']);

// Ranked by win count, for the "most decorated" bars.
$ranked = $honours;
usort($ranked, static fn (array $a, array $b): int => ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']));

// Invert competition -> years into a chronological year -> trophies timeline.
$byYear = [];
foreach ($honours as $h) {
    foreach ($h['years'] as $y) {
        if (!preg_match('/(\d{4})/', $y, $m)) {
            continue;
        }
        $start = (int) $m[1];
        $label = preg_match('/(\d{4})\D+(\d{2,4})/', $y, $mm)
            ? $mm[1] . '/' . substr($mm[2], -2)
            : (string) $start;
        $byYear[$start] ??= ['year' => $start, 'label' => $label, 'trophies' => []];
        $byYear[$start]['trophies'][] = $h['name'];
    }
}
ksort($byYear);
$timeline = array_values($byYear);

$totalTrophies = array_sum(array_column($honours, 'count'));
$firstLabel = $timeline[0]['label'] ?? '';
$firstYear  = $timeline ? $timeline[0]['year'] : null;
$lastYear   = $timeline ? end($timeline)['year'] : null;
$goldenLabel = '';
$goldenCount = 0;
foreach ($timeline as $t) {
    if (count($t['trophies']) > $goldenCount) {
        $goldenCount = count($t['trophies']);
        $goldenLabel = $t['label'];
    }
}

$seasons     = $awardsPage ? $parseAwards((string) $awardsPage['body']) : [];
$totalAwards = array_sum(array_map(static fn ($s) => count($s['awards']), $seasons));

$tidyAward = static function (string $s): string {
    $s = preg_replace_callback('/\b(Of|The|And)\b/', static fn ($m) => strtolower($m[1]), $s) ?? $s;
    return str_replace('Players ', "Players' ", $s);
};
$seasonLabel = static fn (string $s): string => preg_replace('/^(\d{4})\D+\d{2}(\d{2})$/', '$1/$2', $s) ?: $s;

set_meta([
    'title' => club('club_name') . ' Honours & Trophies',
    'description' => trim(club('club_name') . ' honours — ' . $totalTrophies . ' trophies'
        . ($firstYear && $lastYear ? ' won between ' . $firstYear . ' and ' . $lastYear : ''))
        . '. End-of-season player award winners.',
]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'The club',
    'title'   => 'Honours & awards',
    'sub'     => $timeline
        ? $totalTrophies . ' trophies across ' . count($honours) . ' competitions'
            . ($firstYear && $lastYear ? ', from ' . $firstYear . ' to ' . $lastYear : '') . '.'
        : '',
]); ?>

<?php if ($timeline): ?>
<section class="band band--ink">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">At a glance</span>
      <h2>A century of silverware</h2>
    </div></div>
    <div class="factstrip">
      <div><span class="factstrip__num"><?= $totalTrophies ?></span><span class="factstrip__lbl">Trophies won</span></div>
      <div><span class="factstrip__num"><?= count($honours) ?></span><span class="factstrip__lbl">Competitions</span></div>
      <?php if ($firstLabel !== ''): ?>
        <div><span class="factstrip__num factstrip__num--text"><?= e($firstLabel) ?></span><span class="factstrip__lbl">First silverware</span></div>
      <?php endif; ?>
      <?php if ($goldenLabel !== ''): ?>
        <div><span class="factstrip__num factstrip__num--text"><?= e($goldenLabel) ?></span><span class="factstrip__lbl">Best season · <?= $goldenCount ?> trophies</span></div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="band">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">Roll of honour</span>
      <h2>Every trophy, decade by decade</h2>
    </div></div>

    <?php if (!$timeline): ?>
      <div class="prose"><?= club_page_render($page) /* sanitised fallback */ ?></div>
    <?php else: ?>
      <ol class="tl">
        <?php $decade = null; ?>
        <?php foreach ($timeline as $t): ?>
          <?php
          $d = intdiv($t['year'], 10) * 10;
          $multi = count($t['trophies']) > 1;
          ?>
          <?php if ($d !== $decade): $decade = $d; ?>
            <li class="tl__era"><span>The <?= $d ?>s</span></li>
          <?php endif; ?>

          <?php if ($multi): ?>
            <li class="tl__item tl__item--gold">
              <span class="tl__marker" aria-hidden="true"></span>
              <div class="tl__meta">
                <span class="tl__year"><?= e($t['label']) ?></span>
                <span class="tl__tag"><?= count($t['trophies']) ?> trophies</span>
              </div>
              <div class="tl__card">
                <ul class="tl__wins">
                  <?php foreach ($t['trophies'] as $name): ?>
                    <li><?= e($name) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            </li>
          <?php else: ?>
            <li class="tl__item tl__item--flat">
              <span class="tl__marker" aria-hidden="true"></span>
              <div class="tl__row">
                <span class="tl__year"><?= e($t['label']) ?></span>
                <span class="tl__single"><?= e($t['trophies'][0]) ?></span>
              </div>
            </li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</section>

<?php if (count($ranked) > 1): ?>
<section class="band band--surface">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">By competition</span>
      <h2>Most decorated</h2>
    </div></div>
    <?php $top = array_slice($ranked, 0, 6); $max = $top[0]['count'] ?: 1; ?>
    <ul class="rankbars">
      <?php foreach ($top as $h): ?>
        <li class="rankbar">
          <span class="rankbar__name"><?= e($h['name']) ?></span>
          <span class="rankbar__row">
            <span class="rankbar__track"><span class="rankbar__fill" style="width:<?= max(6, (int) round($h['count'] / $max * 100)) ?>%"></span></span>
            <span class="rankbar__val"><?= $h['count'] ?></span>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<?php if ($seasons || $awardsPage): ?>
<section class="band band--tint">
  <div class="container">
    <div class="band__head"><div>
      <span class="eyebrow">End of season</span>
      <h2>Player awards</h2>
    </div></div>

    <?php if (!$seasons): ?>
      <div class="prose"><?= $awardsPage ? club_page_render($awardsPage) : '' /* sanitised fallback */ ?></div>
    <?php else: ?>
      <?php if ($totalAwards): ?>
        <p class="tl-intro"><?= $totalAwards ?> awards across <?= count($seasons) ?> seasons on record.</p>
      <?php endif; ?>
      <ol class="tl tl--awards">
        <?php foreach ($seasons as $s): ?>
          <li class="tl__item">
            <span class="tl__marker" aria-hidden="true"></span>
            <div class="tl__meta"><span class="tl__year"><?= e($seasonLabel($s['season'])) ?></span></div>
            <div class="tl__card">
              <dl class="tl__awards">
                <?php foreach ($s['awards'] as $a): ?>
                  <div>
                    <dt><?= e($tidyAward($a['award'])) ?></dt>
                    <dd><?= e($a['winner']) ?></dd>
                  </div>
                <?php endforeach; ?>
              </dl>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<section class="band">
  <div class="container">
    <p class="honours-note">
      Honours and award winners are compiled from the club's archives and may not be complete.
      <a class="linkarrow" href="<?= e(url('club/records')) ?>">All-time club records</a>
    </p>
  </div>
</section>
