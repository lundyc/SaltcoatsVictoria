<?php
/**
 * Shared fixtures/results listing UI.
 * $mode: 'upcoming' | 'results'
 */
declare(strict_types=1);

$mode = ($mode ?? 'upcoming') === 'results' ? 'results' : 'upcoming';

$seasons = pub_seasons();
$seasonParam = trim((string) ($_GET['season'] ?? ''));
$seasonAll = strtolower($seasonParam) === 'all';
$seasonId = $seasonAll ? 0 : ((int) $seasonParam ?: pub_current_season_id());
if (!$seasonAll) {
    $validSeason = false;
    foreach ($seasons as $s) {
        if ((int) $s['id'] === $seasonId) {
            $validSeason = true;
            break;
        }
    }
    if (!$validSeason) {
        $seasonId = pub_current_season_id();
    }
}

$competitions = pub_competitions_in_season($seasonId);
$competition = (string) ($_GET['competition'] ?? '');
if ($competition !== '' && !in_array($competition, $competitions, true)) {
    $competition = '';
}

$rows = pub_fixtures([
    'season_id' => $seasonId,
    'competition' => $competition ?: null,
    'type' => $mode,
]);
$byMonth = pub_fixtures_by_month($rows);

$base = url($mode === 'results' ? 'results' : 'fixtures');
$qs = static function (array $overrides) use ($seasonId, $seasonAll, $competition): string {
    $params = array_filter([
        'season' => $seasonAll ? 'all' : $seasonId,
        'competition' => $competition,
    ] + $overrides, static fn ($v) => $v !== '' && $v !== null);
    return $params ? '?' . http_build_query($params) : '';
};
// Upcoming list: the very next game (not postponed) gets the big banner and is left out of the month lists.
$nextFx = null;
if ($mode === 'upcoming') {
    foreach ($rows as $i => $row) {
        if ($row['status'] !== 'postponed') {
            $nextFx = $row;
            unset($rows[$i]);
            break;
        }
    }
    $rows = array_values($rows);
    $byMonth = pub_fixtures_by_month($rows);
}
$otherMode = $mode === 'results' ? 'fixtures' : 'results';
?>
<?php partial('page_hero', ['eyebrow' => 'Matches', 'title' => 'First team']); ?>

<div class="page mfx">
  <div class="mfx-wrap">
    <div class="mfx-top">
      <h2><?= $mode === 'results' ? 'Results' : 'Fixtures' ?></h2>
      <div class="mfx-top__right">
        <form class="mfx-filters" method="get" action="<?= e($base) ?>">
          <label><span class="sr-only">Season</span>
            <select name="season" onchange="this.form.submit()" aria-label="Season">
              <option value="all" <?= $seasonAll ? 'selected' : '' ?>>All seasons</option>
              <?php foreach ($seasons as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= !$seasonAll && (int) $s['id'] === $seasonId ? 'selected' : '' ?>><?= e($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php if ($competitions): ?>
          <label><span class="sr-only">Competition</span>
            <select name="competition" onchange="this.form.submit()" aria-label="Competition">
              <option value="">All competitions</option>
              <?php foreach ($competitions as $c): ?>
                <option value="<?= e($c) ?>" <?= $c === $competition ? 'selected' : '' ?>><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php endif; ?>
          <noscript><button type="submit">Go</button></noscript>
        </form>
        <a class="mfx-viewlink" href="<?= e(url($otherMode) . $qs([])) ?>">View <?= e($otherMode) ?></a>
        <a class="mfx-viewlink" href="<?= e(url('table')) ?>">League table</a>
        <a class="mfx-viewlink" href="<?= e(url('club/records')) ?>">Records</a>
      </div>
    </div>

    <?php if ($nextFx !== null):
      $nTeams = pub_fixture_teams($nextFx);
      $nOpp = pub_opponent_crest($nextFx['opponent_logo'] ?? '', (string) ($nextFx['opponent_name'] ?? $nextFx['opponent'] ?? ''));
      $nHomeCrest = $nextFx['is_home'] ? club_crest_reverse() : $nOpp;
      $nAwayCrest = $nextFx['is_home'] ? $nOpp : club_crest_reverse();
      $nTime = !empty($nextFx['kickoff_time']) ? date('g:i A', strtotime((string) $nextFx['kickoff_time'])) : 'TBC';
    ?>
      <a class="mfx-next" href="<?= e(url('match/' . (int) $nextFx['id'])) ?>">
        <span class="mfx-next__label">Next fixture</span>
        <span class="mfx-next__date"><?= e(format_date($nextFx['match_date'], 'l j F Y')) ?></span>
        <span class="mfx-next__teams">
          <span class="mfx-next__name mfx-next__name--home"><?= e($nTeams['home']) ?></span>
          <?php if ($nHomeCrest !== ''): ?><img src="<?= e($nHomeCrest) ?>" alt=""><?php endif; ?>
          <span class="mfx-next__time"><?= e($nTime) ?></span>
          <?php if ($nAwayCrest !== ''): ?><img src="<?= e($nAwayCrest) ?>" alt=""><?php endif; ?>
          <span class="mfx-next__name mfx-next__name--away"><?= e($nTeams['away']) ?></span>
        </span>
        <span class="mfx-next__meta"><b><?= e(pub_competition_short((string) $nextFx['competition'])) ?></b><?php if (trim((string) ($nextFx['venue'] ?? '')) !== ''): ?><i></i><span><?= e((string) $nextFx['venue']) ?></span><?php endif; ?></span>
      </a>
    <?php endif; ?>

    <?php if ($rows === [] && $nextFx === null): ?>
      <div class="emptystate"><p>No <?= $mode === 'results' ? 'results' : 'fixtures' ?> to show for this selection.</p></div>
    <?php else: ?>
      <?php foreach ($byMonth as $month => $monthRows): ?>
        <h3 class="mfx-month"><?= e($month) ?></h3>
        <ul class="mfx-list">
          <?php foreach ($monthRows as $fixture): ?>
            <?php partial('fixture_row_mfc', ['fixture' => $fixture]); ?>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
