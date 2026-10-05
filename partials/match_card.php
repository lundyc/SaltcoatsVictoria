<?php
/** Compact fixture / result card. $fixture: row from the pub_* fixture helpers. */
declare(strict_types=1);

/** @var array<string,mixed> $fixture */
$teams = pub_fixture_teams($fixture);
$played = pub_fixture_is_played($fixture);
$outcome = pub_fixture_outcome($fixture);
$oppCrest = pub_opponent_crest($fixture['opponent_logo'] ?? '', (string) ($fixture['opponent_name'] ?? $fixture['opponent'] ?? ''));

$homeName = $teams['home'];
$awayName = $teams['away'];
$homeCrest = $fixture['is_home'] ? club_crest() : $oppCrest; // light card
$awayCrest = $fixture['is_home'] ? $oppCrest : club_crest();
$homeScore = $played ? (int) $fixture['full_time_home_score'] : null;
$awayScore = $played ? (int) $fixture['full_time_away_score'] : null;
$href = url('match/' . (int) $fixture['id']);
?>
<a class="matchcard" href="<?= e($href) ?>">
  <span class="matchcard__comp" title="<?= e($fixture['competition'] ?: 'Fixture') ?>"><?= e($fixture['competition'] ?: 'Fixture') ?></span>

  <div class="matchcard__row">
    <?php if ($homeCrest !== ''): ?><img src="<?= e($homeCrest) ?>" alt=""><?php endif; ?>
    <span><?= e($homeName) ?></span>
    <?php if ($played): ?><b><?= $homeScore ?></b><?php endif; ?>
  </div>
  <div class="matchcard__row">
    <?php if ($awayCrest !== ''): ?><img src="<?= e($awayCrest) ?>" alt=""><?php endif; ?>
    <span><?= e($awayName) ?></span>
    <?php if ($played): ?><b><?= $awayScore ?></b><?php endif; ?>
  </div>
  <?php if ($outcome['decided_by_penalties']): ?>
    <div class="matchcard__pens">(Pens <?= (int) $outcome['penalties_us'] ?>&ndash;<?= (int) $outcome['penalties_them'] ?>)</div>
  <?php endif; ?>

  <div class="matchcard__foot">
    <span><?= e(format_date($fixture['match_date'], 'D j M Y')) ?><?php
      if (!$played && format_time($fixture['kickoff_time'] ?? '') !== '') {
          echo ' &middot; ' . e(format_time($fixture['kickoff_time']));
      }
    ?></span>
    <?php if ($played && $outcome['outcome'] !== null): ?>
      <span class="chip chip--<?= e($outcome['outcome']) ?>" title="<?= e($outcome['label']) ?>"><?= e($outcome['outcome']) ?></span>
    <?php else: ?>
      <span><?= $fixture['is_home'] ? 'Home' : 'Away' ?></span>
    <?php endif; ?>
  </div>
</a>
