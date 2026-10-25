<?php
/** Route: /club/records — all-time appearances & goals (history_player_stats). */
declare(strict_types=1);

$scorers = pub_history_top_scorers(10);
$apps    = pub_history_appearances(10);
$extra   = pub_records_extra();
$hasData = $scorers || $apps;

set_meta([
    'title' => club('club_name') . ' Club Records',
    'description' => 'All-time appearances and goalscorers for ' . club('club_name') . '.',
]);
?>
<?php seo_breadcrumbs([['Club', url('club/history')], ['Records', url('club/records')]]); ?>
<?php partial('page_hero', [
    'eyebrow' => 'Matches',
    'title'   => 'Club records',
    'sub'     => 'Player milestones and memorable matches from the club’s recorded history.',
]); ?>

<div class="page records-page">
  <div class="container">
    <?php partial('fixtures_tabs', ['active' => 'records']); ?>

    <?php if (!$hasData): ?>
      <div class="emptystate"><p>All-time statistics are being prepared.</p></div>
    <?php else: ?>
  </div>
  <section class="band records-band records-band--career" aria-labelledby="records-career">
    <div class="container">
      <header class="records-band__intro">
        <p class="eyebrow">Club milestones</p>
        <h2 id="records-career">Career leaderboards</h2>
        <p>The leading scorers and most regular faces across every season on record. Competitive appearances and goals, with each player’s spells at the club.</p>
      </header>
      <div class="records-grid">
        <section class="records-table">
          <h3 class="squad-heading">All-time goalscorers</h3>
          <div class="tablewrap">
            <table class="leaguetable">
              <thead><tr><th>#</th><th>Player</th><th class="hide-sm">Years</th><th class="c">Goals</th><th class="c hide-sm">Pens</th><th class="c">Apps</th></tr></thead>
              <tbody>
                <?php foreach ($scorers as $i => $p): ?>
                  <tr>
                    <td class="c"><?= $i + 1 ?></td>
                    <td class="club"><?= e($p['name']) ?></td>
                    <td class="hide-sm"><?= e(pub_records_years($p['seasons'] ?? [])) ?></td>
                    <td class="c"><b><?= (int) $p['goals'] ?></b></td>
                    <td class="c hide-sm"><?= (int) $p['penalties'] ?></td>
                    <td class="c"><?= (int) $p['appearances'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="records-table">
          <h3 class="squad-heading">All-time appearances</h3>
          <div class="tablewrap">
            <table class="leaguetable">
              <thead><tr><th>#</th><th>Player</th><th class="hide-sm">Years</th><th class="c">Apps</th><th class="c hide-sm">Start</th><th class="c hide-sm">Sub</th><th class="c">Goals</th></tr></thead>
              <tbody>
                <?php foreach ($apps as $i => $p): ?>
                  <tr>
                    <td class="c"><?= $i + 1 ?></td>
                    <td class="club"><?= e($p['name']) ?></td>
                    <td class="hide-sm"><?= e(pub_records_years($p['seasons'] ?? [])) ?></td>
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
    </div>
  </section>
        <?php
        $date = static fn (string $d): string => date('j M Y', strtotime($d));
        $matchRows = static function (array $matches) use ($date): array {
            return array_map(static fn ($m) => [$m['opponent'], $m['us'].'–'.$m['them'], $date($m['match_date'])], array_slice($matches, 0, 5));
        };
        $blocks = [
            ['Biggest wins', 'From recorded competitive matches. Ranked by winning margin; scores show Vics first.', ['Opponent', 'Score', 'Date'], $matchRows($extra['wins'])],
            ['Highest-scoring matches', 'From recorded competitive matches. Ranked by combined goals; scores show Vics first.', ['Opponent', 'Score', 'Date'], $matchRows($extra['high'])],
            ['Longest runs', 'Since 2024–25, across recorded competitive matches. Draws end winning runs. Shootouts are excluded from the result.', ['Run', 'Matches', 'Dates'], array_map(static fn ($r) => [$r['label'], $r['count'], $date($r['from']).' – '.$date($r['to'])], $extra['runs'])],
            ['Most goals in a season', 'Since 2024–25. Competitive goals recorded in the match record.', ['Player', 'Season', 'Goals'], array_map(static fn ($r) => [$r['name'], $r['season'], $r['goals']], array_slice($extra['seasonGoals'], 0, 5))],
            ['Most goals in one match', 'Since 2024–25. Three or more goals is a hat-trick; penalty shootouts are excluded.', ['Player / opponent', 'Date', 'Goals'], array_map(static fn ($r) => [$r['name'].' v '.$r['opponent'], $date($r['match_date']), $r['goals']], array_slice($extra['goals'], 0, 5))],
            ['Most clean sheets in a season', 'Team clean sheets from recorded competitive results. Older seasons may be incomplete.', ['Season', 'Clean sheets'], array_map(static fn ($season, $count) => [$season, $count], array_slice(array_keys($extra['clean']), 0, 5), array_slice(array_values($extra['clean']), 0, 5))],
            ['Most penalties scored', 'Career totals from the historical statistics and current match record. Excludes shootouts and friendlies.', ['Player', 'Penalties'], array_map(static fn ($r) => [$r['name'], $r['penalties']], array_slice($extra['penalties'], 0, 5))],
            ['Best goals per appearance', 'Career totals from the available records. Minimum 20 appearances; friendlies excluded.', ['Player', 'Goals / apps', 'Ratio'], array_map(static fn ($r) => [$r['name'], $r['goals'].' / '.$r['appearances'], number_format($r['goals']/$r['appearances'], 2)], array_slice($extra['ratios'], 0, 5))],
        ];
        $groups = [
            ['matches', 'Memorable results', 'Standout scorelines', 'The biggest victories and the matches with the most goals in the available archive. Every score below shows Saltcoats Victoria first.', [0, 1]],
            ['season', 'Form and finishing', 'Season & match records', 'Scoring achievements, unbeaten spells and defensive records. Each table explains the period covered by its data.', [3, 4, 2, 5]],
            ['scoring', 'A closer look', 'Scoring specialists', 'Penalty scorers and the players with the strongest goals-per-appearance record. The scoring ratio requires at least 20 appearances.', [6, 7]],
        ];
        foreach ($groups as [$id, $eyebrow, $title, $intro, $indices]): ?>
  <section class="band records-band records-band--<?= e($id) ?>" aria-labelledby="records-<?= e($id) ?>">
    <div class="container">
      <header class="records-band__intro">
        <p class="eyebrow"><?= e($eyebrow) ?></p>
        <h2 id="records-<?= e($id) ?>"><?= e($title) ?></h2>
        <p><?= e($intro) ?></p>
      </header>
      <div class="records-grid">
        <?php foreach ($indices as $index):
          [$heading, $note, $columns, $rows] = $blocks[$index];
          $centred = $index === 5 ? [0, 1] : array_keys(array_filter($columns, static fn ($column) => in_array($column, ['Score', 'Date', 'Matches'], true)));
          ?>
          <section class="records-table">
            <h3 class="squad-heading"><?= e($heading) ?></h3>
            <p class="records-table__note"><?= e($note) ?></p>
            <?php if ($rows): ?>
              <div class="tablewrap">
                <table class="leaguetable">
                  <thead><tr><?php foreach ($columns as $j => $column): ?><th scope="col"<?= in_array($j, $centred, true) ? ' class="c"' : '' ?>><?= e($column) ?></th><?php endforeach; ?></tr></thead>
                  <tbody><?php foreach ($rows as $row): ?><tr><?php foreach ($row as $j => $value): ?><td<?= in_array($j, $centred, true) ? ' class="c"' : ($j === 0 ? ' class="club"' : '') ?>><?= e((string)$value) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
                </table>
              </div>
            <?php else: ?>
              <p class="records-table__note">No qualifying records available yet.</p>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
        <?php endforeach; ?>
  <div class="container records-methodology">
      <h2 class="squad-heading">About these records</h2>
      <p class="records-note">Player career leaderboards show the top 10; other ranked blocks show the top 5. Records reflect the available archive, which has gaps, and are not a complete history of the club. Seasons up to 2023-24 are from the club's historical statistics; 2024-25 onward is counted live from the match record. Friendlies are not counted. Years show each spell at the club (season start years), so gaps show where a player left and returned.</p>
  </div>
    <?php endif; ?>
</div>
