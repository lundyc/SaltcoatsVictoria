<?php
/**
 * Route: /table — league standings, with a season picker.
 * The current season reads the Hub's live WOSFL cache; any other season
 * reads whatever standings an admin pasted into league_table_history.
 */
declare(strict_types=1);

$seasonId = pub_current_season_id();
$isCurrent = true;

$table = pub_league_table_for_season($seasonId);
$rowCount = count($table['rows']);
$showForm = $isCurrent;

set_meta([
    'title' => club('club_short_name', club('club_name')) . ' League Table | ' . $table['title'],
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
              <?php if ($showForm): ?><th class="c hide-sm">Form</th><?php endif; ?>
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
                  <?php if ($logo !== '' && is_file(HUB_ROOT . '/' . ltrim($logo, '/'))): ?><img src="/<?= e(implode('/', array_map('rawurlencode', explode('/', ltrim($logo, '/'))))) ?>" alt="" sizes="22px" loading="lazy"><?php else: ?><i class="clubbadge-gap" aria-hidden="true"></i><?php endif; ?>
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
                <?php if ($showForm): ?>
                  <td class="c hide-sm form">
                    <?php foreach (array_slice((array) ($row['form'] ?? []), -5) as $f): ?>
                      <span class="chip chip--<?= e($f) ?>"><?= e($f) ?></span>
                    <?php endforeach; ?>
                  </td>
                <?php endif; ?>
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
