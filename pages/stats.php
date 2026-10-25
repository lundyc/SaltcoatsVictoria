<?php
/**
 * Route: /stats — club leaderboards, player v player and club v club.
 * Player figures come from the all-time records (pub_history_records); club
 * figures come from the league table and our head-to-head history.
 */
declare(strict_types=1);

$records = pub_history_records();
usort($records, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
$playerByKey = [];
foreach ($records as $r) {
    $playerByKey[pub_records_key((string) $r['name'])] = $r;
}
$p1 = $playerByKey[pub_records_key((string) ($_GET['p1'] ?? ''))] ?? null;
$p2 = $playerByKey[pub_records_key((string) ($_GET['p2'] ?? ''))] ?? null;

$table = pub_league_table();
$clubRows = array_values($table['rows'] ?? []);
$clubByName = [];
foreach ($clubRows as $row) {
    $clubByName[(string) $row['club']] = $row;
}
$ourRow = pub_league_our_row();
$defaultA = $ourRow['club'] ?? (string) ($clubRows[0]['club'] ?? '');
$defaultB = '';
foreach ($clubRows as $row) {
    if ($row['club'] !== $defaultA) {
        $defaultB = (string) $row['club'];
        break;
    }
}
$clubA = (string) ($_GET['c1'] ?? $defaultA);
$clubB = (string) ($_GET['c2'] ?? $defaultB);
$rowA = $clubByName[$clubA] ?? null;
$rowB = $clubByName[$clubB] ?? null;

// Head-to-head is only stored for our own matches, so it applies when one side is us.
$h2h = null;
$h2hOpponent = null;
if ($rowA && $rowB && (pub_league_is_us($rowA) xor pub_league_is_us($rowB))) {
    $h2hOpponent = pub_league_is_us($rowA) ? $rowB['club'] : $rowA['club'];
    $h2h = pub_head_to_head(['opponent_name' => (string) $h2hOpponent], 10);
}

$topScorers = pub_history_top_scorers(10);
$topAppearances = pub_history_appearances(10);

$playerRow = static function (?array $p): array {
    if (!$p) {
        return ['apps' => '–', 'starts' => '–', 'subs' => '–', 'goals' => '–', 'pens' => '–', 'seasons' => '–'];
    }
    return [
        'apps' => (int) $p['appearances'],
        'starts' => (int) $p['starts'],
        'subs' => (int) $p['subs'],
        'goals' => (int) $p['goals'],
        'pens' => (int) $p['penalties'],
        'seasons' => count((array) $p['seasons']),
    ];
};
$pa = $playerRow($p1);
$pb = $playerRow($p2);
$playerLines = [
    'Appearances' => ['apps', 'Apps'],
    'Starts' => ['starts', 'Starts'],
    'Sub appearances' => ['subs', 'Sub apps'],
    'Goals' => ['goals', 'Goals'],
    'Penalties scored' => ['pens', 'Penalties'],
    'Seasons played' => ['seasons', 'Seasons'],
];

set_meta([
    'title' => 'Club Stats | ' . club('club_name'),
    'description' => 'Leaderboards, player v player and club v club stats for ' . club('club_name') . ': top scorers, appearances, head-to-head and league comparisons.',
]);
seo_breadcrumbs([['Stats', url('stats')]]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Matches',
    'title' => 'Club stats',
    'sub' => 'Leaderboards, player v player and club v club',
]); ?>

<div class="page">
  <div class="container">

    <section class="stats-block" id="leaderboards">
      <h2>All-time leaderboards</h2>
      <div class="stats-grid">
        <div>
          <h3>Top scorers</h3>
          <?php if ($topScorers): ?>
            <ol class="stats-board">
              <?php foreach ($topScorers as $r): ?>
                <li><span><?= e((string) $r['name']) ?></span><strong><?= (int) $r['goals'] ?></strong></li>
              <?php endforeach; ?>
            </ol>
          <?php else: ?>
            <p class="emptystate">No goals recorded yet.</p>
          <?php endif; ?>
        </div>
        <div>
          <h3>Most appearances</h3>
          <?php if ($topAppearances): ?>
            <ol class="stats-board">
              <?php foreach ($topAppearances as $r): ?>
                <li><span><?= e((string) $r['name']) ?></span><strong><?= (int) $r['appearances'] ?></strong></li>
              <?php endforeach; ?>
            </ol>
          <?php else: ?>
            <p class="emptystate">No appearances recorded yet.</p>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="stats-block" id="player-v-player">
      <h2>Player v player</h2>
      <form class="stats-form" method="get" action="<?= e(url('stats')) ?>#player-v-player">
        <label>Player
          <select name="p1">
            <option value="">Choose a player</option>
            <?php foreach ($records as $r): $k = pub_records_key((string) $r['name']); ?>
              <option value="<?= e((string) $r['name']) ?>"<?= $p1 && pub_records_key((string) $p1['name']) === $k ? ' selected' : '' ?>><?= e((string) $r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Against
          <select name="p2">
            <option value="">Choose a player</option>
            <?php foreach ($records as $r): $k = pub_records_key((string) $r['name']); ?>
              <option value="<?= e((string) $r['name']) ?>"<?= $p2 && pub_records_key((string) $p2['name']) === $k ? ' selected' : '' ?>><?= e((string) $r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn--sm" type="submit">Compare</button>
      </form>
      <?php if ($p1 || $p2): ?>
        <div class="tablewrap">
          <table class="leaguetable stats-compare">
            <thead><tr><th>Stat</th><th class="c"><?= e((string) ($p1['name'] ?? 'Player 1')) ?></th><th class="c"><?= e((string) ($p2['name'] ?? 'Player 2')) ?></th></tr></thead>
            <tbody>
              <?php foreach ($playerLines as $label => [$key]): ?>
                <tr>
                  <td><?= e($label) ?></td>
                  <td class="c"><strong><?= e((string) $pa[$key]) ?></strong></td>
                  <td class="c"><strong><?= e((string) $pb[$key]) ?></strong></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="muted">Pick two players to compare their all-time record.</p>
      <?php endif; ?>
    </section>

    <section class="stats-block" id="club-v-club">
      <h2>Club v club</h2>
      <form class="stats-form" method="get" action="<?= e(url('stats')) ?>#club-v-club">
        <label>Club
          <select name="c1">
            <?php foreach ($clubRows as $row): ?>
              <option value="<?= e((string) $row['club']) ?>"<?= $row['club'] === $clubA ? ' selected' : '' ?>><?= e((string) $row['club']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Against
          <select name="c2">
            <?php foreach ($clubRows as $row): ?>
              <option value="<?= e((string) $row['club']) ?>"<?= $row['club'] === $clubB ? ' selected' : '' ?>><?= e((string) $row['club']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn btn--sm" type="submit">Compare</button>
      </form>

      <?php if ($rowA && $rowB): ?>
        <h3>This season in the league</h3>
        <div class="tablewrap">
          <table class="leaguetable stats-compare">
            <thead><tr><th>Stat</th><th class="c"><?= e((string) $rowA['club']) ?></th><th class="c"><?= e((string) $rowB['club']) ?></th></tr></thead>
            <tbody>
              <?php foreach ([['Position', 'pos'], ['Played', 'p'], ['Won', 'w'], ['Drawn', 'd'], ['Lost', 'l'], ['Goals for', 'f'], ['Goals against', 'a'], ['Goal difference', 'gd'], ['Points', 'pts']] as [$label, $key]): ?>
                <tr>
                  <td><?= e($label) ?></td>
                  <td class="c"><strong><?= e((string) ($rowA[$key] ?? '')) ?></strong></td>
                  <td class="c"><strong><?= e((string) ($rowB[$key] ?? '')) ?></strong></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <?php if ($h2h): ?>
        <h3>All-time v <?= e((string) $h2hOpponent) ?></h3>
        <p class="stats-summary">
          <strong><?= (int) $h2h['played'] ?></strong> meeting<?= $h2h['played'] === 1 ? '' : 's' ?> &middot;
          <?= (int) $h2h['w'] ?> won, <?= (int) $h2h['d'] ?> drawn, <?= (int) $h2h['l'] ?> lost &middot;
          goals <?= (int) $h2h['gf'] ?>&ndash;<?= (int) $h2h['ga'] ?>
        </p>
        <ul class="stats-meetings">
          <?php foreach ($h2h['meetings'] as $m): ?>
            <li><a href="<?= e((string) $m['url']) ?>"><span><?= e(format_date($m['date'], 'j M Y')) ?></span> <?= e((string) ($m['competition'] ?: 'Match')) ?> &middot; <?= (int) $m['us'] ?>&ndash;<?= (int) $m['them'] ?></a></li>
          <?php endforeach; ?>
        </ul>
      <?php elseif ($rowA && $rowB): ?>
        <p class="muted">Head-to-head is recorded for our matches, so choose us and one opponent to see it.</p>
      <?php endif; ?>
    </section>

  </div>
</div>
