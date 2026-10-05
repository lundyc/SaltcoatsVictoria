<?php
/** Route: /club/records — all-time appearances & goals (history_player_stats). */
declare(strict_types=1);

$scorers = pub_history_top_scorers(30);
$apps    = pub_history_appearances(50);
$totals  = pub_history_totals();
$hasData = $scorers || $apps;

set_meta([
    'title' => club('club_name') . ' Club Records',
    'description' => 'All-time appearances and goalscorers for ' . club('club_name') . '.',
]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Matches',
    'title'   => 'Club records',
    'sub'     => 'The all-time leaderboard, combining every season, team and competition on record.',
]); ?>

<div class="page">
  <div class="container">
    <?php partial('fixtures_tabs', ['active' => 'records']); ?>

    <?php if (!$hasData): ?>
      <div class="emptystate"><p>All-time statistics are being prepared.</p></div>
    <?php else: ?>
      <div class="records-grid">
        <section>
          <h2 class="squad-heading">All-time goalscorers</h2>
          <div class="tablewrap">
            <table class="leaguetable">
              <thead><tr><th>#</th><th>Player</th><th class="c">Goals</th><th class="c hide-sm">Pens</th><th class="c">Apps</th></tr></thead>
              <tbody>
                <?php foreach ($scorers as $i => $p): ?>
                  <tr>
                    <td class="c"><?= $i + 1 ?></td>
                    <td class="club"><?= e($p['name']) ?></td>
                    <td class="c"><b><?= (int) $p['goals'] ?></b></td>
                    <td class="c hide-sm"><?= (int) $p['penalties'] ?></td>
                    <td class="c"><?= (int) $p['appearances'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section>
          <h2 class="squad-heading">All-time appearances</h2>
          <div class="tablewrap">
            <table class="leaguetable">
              <thead><tr><th>#</th><th>Player</th><th class="c">Apps</th><th class="c hide-sm">Start</th><th class="c hide-sm">Sub</th><th class="c">Goals</th></tr></thead>
              <tbody>
                <?php foreach ($apps as $i => $p): ?>
                  <tr>
                    <td class="c"><?= $i + 1 ?></td>
                    <td class="club"><?= e($p['name']) ?></td>
                    <td class="c"><b><?= (int) $p['appearances'] ?></b></td>
                    <td class="c hide-sm"><?= (int) $p['starts'] ?></td>
                    <td class="c hide-sm"><?= (int) $p['subs'] ?></td>
                    <td class="c"><?= (int) $p['goals'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      </div>
      <p class="records-note">Figures are as recorded on the club's historical statistics pages and cover matches held on record only.</p>
    <?php endif; ?>
  </div>
</div>
