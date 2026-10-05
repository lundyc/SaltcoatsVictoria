<?php
/**
 * Route: /table — league standings, with a season picker.
 * The current season reads the Hub's live WOSFL cache; any other season
 * reads whatever standings an admin pasted into league_table_history.
 */
declare(strict_types=1);

$seasons = pub_league_table_seasons();
$currentSeasonId = pub_current_season_id();
$seasonId = (int) ($_GET['season'] ?? 0);
$validSeasonIds = array_map(static fn (array $s): int => (int) $s['id'], $seasons);
if ($seasonId <= 0 || !in_array($seasonId, $validSeasonIds, true)) {
    $seasonId = $currentSeasonId;
}
$isCurrent = $seasonId === $currentSeasonId;
$recentSeasonIds = array_slice($validSeasonIds, 0, 2); // $seasons is ordered newest-first
$showBadges = in_array($seasonId, $recentSeasonIds, true);

// $validSeasonIds is newest-first, so the next index back is an older season
// and the previous index is a newer one.
$seasonIndex = array_search($seasonId, $validSeasonIds, true);
$olderSeasonId = $seasonIndex !== false ? ($validSeasonIds[$seasonIndex + 1] ?? null) : null;
$newerSeasonId = $seasonIndex !== false && $seasonIndex > 0 ? ($validSeasonIds[$seasonIndex - 1] ?? null) : null;

$table = pub_league_table_for_season($seasonId);
$rowCount = count($table['rows']);

set_meta([
    'title' => club('club_short_name', club('club_name')) . ' League Table | ' . $table['title'] . ' | ' . club('club_name'),
    'title_full' => '1',
    'description' => $table['title'] . ' standings' . ' — played, won, drawn, lost, goal difference and points for ' . club('club_name') . ' and every club in the division.',
]);
seo_breadcrumbs([['League table', url('table')]]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Matches',
    'title'   => $table['title'],
    'sub'     => $table['updated'] ? 'Updated ' . format_date(date('Y-m-d', $table['updated']), 'j M Y') : '',
]); ?>

<div class="page">
  <div class="container">
    <?php if (count($seasons) > 1): ?>
      <div class="fxfilters tablebar no-print">
        <form method="get" action="<?= e(url('table')) ?>" class="tablebar__seasonpick">
          <?php if ($newerSeasonId): ?>
            <a class="tablebar__nav" href="<?= e(url('table') . '?season=' . $newerSeasonId) ?>" aria-label="Newer season">&#8249;</a>
          <?php else: ?>
            <span class="tablebar__nav tablebar__nav--disabled" aria-hidden="true">&#8249;</span>
          <?php endif; ?>
          <label>
            <span>Season</span>
            <select name="season" onchange="this.form.submit()">
              <?php foreach ($seasons as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $seasonId ? 'selected' : '' ?>><?= e($s['name']) ?><?= (int) $s['id'] === $currentSeasonId ? ' (current)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php if ($olderSeasonId): ?>
            <a class="tablebar__nav" href="<?= e(url('table') . '?season=' . $olderSeasonId) ?>" aria-label="Older season">&#8250;</a>
          <?php else: ?>
            <span class="tablebar__nav tablebar__nav--disabled" aria-hidden="true">&#8250;</span>
          <?php endif; ?>
          <noscript><button type="submit">Go</button></noscript>
        </form>
        <div class="tablebar__actions">
          <a class="btn btn--sm btn--ghost" href="<?= e(url('table/pdf') . '?season=' . $seasonId) ?>" target="_blank" rel="noopener" aria-label="Download as PDF" title="Download as PDF">PDF</a>
          <button type="button" class="btn btn--sm btn--ghost" onclick="window.print()" aria-label="Print table" title="Print table">Print</button>
        </div>
      </div>
      <style media="print">
        .site-header, .primary-nav, .site-footer, .no-print { display: none !important; }
      </style>
    <?php endif; ?>

    <?php if (!$table['ok']): ?>
      <div class="emptystate">
        <?php if ($isCurrent): ?>
          <p>The league table isn't available right now.</p>
          <p><a class="btn btn--sm" href="<?= e($table['url']) ?>" target="_blank" rel="noopener">View on WOSFL</a></p>
        <?php else: ?>
          <p>No table has been recorded for this season yet.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="tablewrap">
        <table class="leaguetable">
          <thead>
            <tr>
              <th class="c">#</th>
              <th>Club</th>
              <th class="c">P</th><th class="c">W</th><th class="c">D</th><th class="c">L</th>
              <th class="c hide-sm">F</th><th class="c hide-sm">A</th><th class="c">GD</th><th class="c">Pts</th>
              <th class="c hide-sm">Form</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($table['rows'] as $i => $row): ?>
              <?php
              $pos = (int) ($row['pos'] ?: $i + 1);
              $isUs = pub_league_is_us($row);
              $zone = '';
              if ($table['promotion'] > 0 && $pos <= $table['promotion']) {
                  $zone = 'promo';
              } elseif ($table['relegation'] > 0 && $pos > $rowCount - $table['relegation']) {
                  $zone = 'releg';
              }
              $logo = trim((string) ($row['logo'] ?? ''));
              ?>
              <tr class="<?= $isUs ? 'is-us' : '' ?> <?= $zone ? 'zone-' . $zone : '' ?>">
                <td class="c"><?= $pos ?></td>
                <td class="club">
                  <?php if ($logo !== '' && $showBadges): ?><img src="/<?= e(ltrim($logo, '/')) ?>" alt="" loading="lazy"><?php endif; ?>
                  <span><?= e($row['club'] ?? '') ?></span>
                </td>
                <td class="c"><?= e($row['p'] ?? '') ?></td>
                <td class="c"><?= e($row['w'] ?? '') ?></td>
                <td class="c"><?= e($row['d'] ?? '') ?></td>
                <td class="c"><?= e($row['l'] ?? '') ?></td>
                <td class="c hide-sm"><?= e($row['f'] ?? '') ?></td>
                <td class="c hide-sm"><?= e($row['a'] ?? '') ?></td>
                <td class="c"><?= e($row['gd'] ?? '') ?></td>
                <td class="c"><b><?= e($row['pts'] ?? '') ?></b></td>
                <td class="c hide-sm form">
                  <?php foreach (array_slice((array) ($row['form'] ?? []), -5) as $f): ?>
                    <span class="chip chip--<?= e($f) ?>"><?= e($f) ?></span>
                  <?php endforeach; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="tablekey">
        <?php if ($table['promotion'] > 0): ?><span><i class="sw sw--promo"></i> Promotion</span><?php endif; ?>
        <?php if ($table['relegation'] > 0): ?><span><i class="sw sw--releg"></i> Relegation</span><?php endif; ?>
        <?php if ($isCurrent): ?><a href="<?= e($table['url']) ?>" target="_blank" rel="noopener">Full standings on WOSFL →</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
