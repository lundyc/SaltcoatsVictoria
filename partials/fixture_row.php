<?php
/** One fixture/result row for the list pages. $fixture: row from pub_fixtures(). */
declare(strict_types=1);

/** @var array<string,mixed> $fixture */
$teams = pub_fixture_teams($fixture);
$played = pub_fixture_is_played($fixture);
$outcome = pub_fixture_outcome($fixture);
$oppCrest = pub_opponent_crest($fixture['opponent_logo'] ?? '', (string) ($fixture['opponent_name'] ?? $fixture['opponent'] ?? ''));
$homeCrest = $fixture['is_home'] ? club_crest() : $oppCrest; // light rows
$awayCrest = $fixture['is_home'] ? $oppCrest : club_crest();
$ko = format_time($fixture['kickoff_time'] ?? '');
$href = url('match/' . (int) $fixture['id']);
$showTickets = !$played && $fixture['is_home'] && pub_fixture_has_tickets((int) $fixture['id']);
?>
<div class="fxrow<?= $played && $outcome['outcome'] ? ' fxrow--' . strtolower($outcome['outcome']) : '' ?>">
  <a class="fxrow__link" href="<?= e($href) ?>" aria-label="<?= e($teams['home'] . ' v ' . $teams['away']) ?> — match centre"></a>

  <span class="fxrow__date">
    <b><?= e(format_date($fixture['match_date'], 'D j M')) ?></b>
    <span><?= $played ? e($fixture['competition'] ?: '') : e($ko ?: 'TBC') ?></span>
  </span>

  <span class="fxrow__team fxrow__team--home">
    <span><?= e($teams['home']) ?></span>
    <?php if ($homeCrest !== ''): ?><img src="<?= e($homeCrest) ?>" alt=""><?php endif; ?>
  </span>

  <span class="fxrow__score">
    <?php if ($played): ?>
      <b><?= (int) $fixture['full_time_home_score'] ?><span>–</span><?= (int) $fixture['full_time_away_score'] ?></b>
      <?php if ($outcome['decided_by_penalties']): ?>
        <span class="fxrow__pens">(Pens <?= (int) $fixture['home_penalties'] ?>&ndash;<?= (int) $fixture['away_penalties'] ?>)</span>
      <?php endif; ?>
    <?php else: ?>
      <span class="fxrow__v">v</span>
    <?php endif; ?>
  </span>

  <span class="fxrow__team fxrow__team--away">
    <?php if ($awayCrest !== ''): ?><img src="<?= e($awayCrest) ?>" alt=""><?php endif; ?>
    <span><?= e($teams['away']) ?></span>
  </span>

  <span class="fxrow__meta">
    <?php if ($played && $outcome['outcome']): ?>
      <span class="chip chip--<?= e($outcome['outcome']) ?>"><?= e($outcome['outcome']) ?></span>
    <?php elseif ($showTickets): ?>
      <a class="fxrow__tickets" href="<?= e(url('tickets') . '?fixture_id=' . (int) $fixture['id']) ?>">Buy tickets</a>
    <?php else: ?>
      <span class="fxrow__ha"><?= $fixture['is_home'] ? 'H' : 'A' ?></span>
    <?php endif; ?>
  </span>
</div>
