<?php
/** One fixture/result row, Motherwell-style (skewed date tag, crest, competition, home - score - away). $fixture: row from pub_fixtures(). */
declare(strict_types=1);

/** @var array<string,mixed> $fixture */
$teams = pub_fixture_teams($fixture);
$played = pub_fixture_is_played($fixture);
$postponed = ($fixture['status'] ?? '') === 'postponed';
$oppName = (string) ($fixture['opponent_name'] ?? $fixture['opponent'] ?? '');
$oppCrest = pub_opponent_crest($fixture['opponent_logo'] ?? '', $oppName);
$ko = format_time($fixture['kickoff_time'] ?? '');
$href = url('match/' . (int) $fixture['id']);
$showTickets = !$played && $fixture['is_home'] && pub_fixture_has_tickets((int) $fixture['id']);
$comp = pub_competition_short((string) ($fixture['competition'] ?? ''));
?>
<li class="mfx-row">
  <span class="mfx-date"><span><?= e(format_date($fixture['match_date'], 'D j')) ?></span></span>
  <span class="mfx-logo"><?php if ($oppCrest !== ''): ?><img src="<?= e($oppCrest) ?>" alt="<?= e($oppName) ?> badge" loading="lazy"><?php endif; ?></span>
  <span class="mfx-comp"><?= e($comp) ?></span>
  <span class="mfx-match">
    <span class="mfx-team mfx-team--home"><?= e($teams['home']) ?></span>
    <span class="mfx-score"<?= $postponed && trim((string) ($fixture['notes'] ?? '')) !== '' ? ' title="' . e((string) $fixture['notes']) . '"' : '' ?>>
      <?php if ($played): ?>
        <?= (int) $fixture['full_time_home_score'] ?> - <?= (int) $fixture['full_time_away_score'] ?>
      <?php elseif ($postponed): ?>
        <span class="mfx-score__label">Postponed</span>
      <?php else: ?>
        <?= e($ko !== '' ? date('H:i', strtotime((string) $fixture['kickoff_time'])) : 'TBC') ?>
      <?php endif; ?>
    </span>
    <span class="mfx-team mfx-team--away"><?= e($teams['away']) ?></span>
  </span>
  <span class="mfx-cta">
    <?php if ($played): ?>
      <a class="mfx-link" href="<?= e($href) ?>">Match centre</a>
    <?php else: ?>
      <?php if ($showTickets): ?><a class="mfx-link mfx-link--tix" href="<?= e(url('tickets') . '?fixture_id=' . (int) $fixture['id']) ?>">Tickets</a><?php endif; ?>
      <a class="mfx-link" href="<?= e($href) ?>">Match info</a>
    <?php endif; ?>
  </span>
</li>
