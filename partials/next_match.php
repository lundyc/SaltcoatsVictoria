<?php
/** Next-match card. $fixture: row from pub_next_fixture() or null. */
declare(strict_types=1);

/** @var array<string,mixed>|null $fixture */
$fixture = $fixture ?? null;

if ($fixture === null) {
    ?>
    <a class="matchbar__match" href="<?= e(url('fixtures')) ?>">
      <span class="matchbar__eyebrow">Next match</span>
      <span class="matchbar__empty">The next fixture will be confirmed soon.</span>
    </a>
    <?php
    return;
}

$teams = pub_fixture_teams($fixture);
$clubCrest = club_crest_reverse(); // dark card
$oppCrest = pub_opponent_crest($fixture['opponent_logo'] ?? '', (string) ($fixture['opponent_name'] ?? $fixture['opponent'] ?? ''));
$homeCrest = $fixture['is_home'] ? $clubCrest : $oppCrest;
$awayCrest = $fixture['is_home'] ? $oppCrest : $clubCrest;
$dateStr = format_date($fixture['match_date'], 'D j M Y');
$timeStr = format_time($fixture['kickoff_time'] ?? '');
$matchUrl = url('match/' . (int) $fixture['id']);

// Show "Match tickets" only when this is a home game that really has tickets on sale.
$ticketsUrl = pub_fixture_tickets_url($fixture);
?>
<div class="matchbar__match">
  <span class="matchbar__eyebrow">Next <br>match</span>
  <span class="matchbar__crests">
    <?php if ($homeCrest !== ''): ?><img src="<?= e($homeCrest) ?>" alt="" width="30" height="30"><?php endif; ?>
    <?php if ($awayCrest !== ''): ?><img src="<?= e($awayCrest) ?>" alt="" width="30" height="30"><?php endif; ?>
  </span>
  <span class="matchbar__info">
    <strong class="matchbar__title"><?= e(mb_strtoupper($teams['home'] . ' v ' . $teams['away'])) ?></strong>
    <span class="matchbar__subtitle"><?= e(pub_competition_short((string) $fixture['competition'])) ?> &middot; <?= e($dateStr) ?><?php if ($timeStr !== ''): ?> &middot; <?= e($timeStr) ?><?php endif; ?></span>
  </span>
  <span class="matchbar__actions">
    <?php if ($ticketsUrl !== ''): ?><a class="matchbar__btn matchbar__btn--primary" href="<?= e($ticketsUrl) ?>">Match tickets</a><?php endif; ?>
    <a class="matchbar__btn" href="<?= e($matchUrl) ?>">Match info</a>
  </span>
</div>
