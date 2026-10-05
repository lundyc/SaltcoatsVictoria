<?php
/** Route: /club/player-awards — end-of-season award winners, restructured from
 *  the club_pages "player-awards" body into a modern season timeline. Falls
 *  back to the raw page if the content no longer matches the expected shape. */
declare(strict_types=1);

$page = club_page_by_slug(db(), 'player-awards');
if ($page === null) {
    http_response_code(404);
    set_meta(['title' => 'Page not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Page not found</h1><p><a class="linkarrow" href="' . e(url()) . '">Home</a></p></div></div>';
    return;
}

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
            // The meaningful text is the last cell that looks like "Label: Name".
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

$seasons = $parseAwards((string) $page['body']);
$totalAwards = array_sum(array_map(static fn ($s) => count($s['awards']), $seasons));

set_meta([
    'title' => 'Player awards',
    'description' => 'End-of-season player award winners for ' . club('club_name') . '.',
]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'The club',
    'title'   => 'Player awards',
    'sub'     => $seasons ? $totalAwards . ' awards across ' . count($seasons) . ' seasons on record.' : '',
]); ?>

<div class="page">
  <div class="container">
    <?php if (!$seasons): ?>
      <div class="prose"><?= club_page_render($page) /* sanitised fallback */ ?></div>
    <?php else: ?>
      <ol class="tl tl--awards">
        <?php foreach ($seasons as $s): ?>
          <li class="tl__item">
            <span class="tl__marker" aria-hidden="true"></span>
            <div class="tl__meta"><span class="tl__year"><?= e(preg_replace('/^(\d{4})\D+\d{2}(\d{2})$/', '$1/$2', $s['season']) ?: $s['season']) ?></span></div>
            <div class="tl__card">
              <dl class="tl__awards">
                <?php foreach ($s['awards'] as $a): ?>
                  <div>
                    <dt><?= e($a['award']) ?></dt>
                    <dd><?= e($a['winner']) ?></dd>
                  </div>
                <?php endforeach; ?>
              </dl>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
      <p class="honours-note">
        Winners as recorded in the club's archives.
        <a class="linkarrow" href="<?= e(url('club/records')) ?>">All-time club records</a>
      </p>
    <?php endif; ?>
  </div>
</div>
