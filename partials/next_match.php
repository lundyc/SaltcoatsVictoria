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
$dateStr = format_date($fixture['match_date'], 'd/m/y');
$timeStr = format_time($fixture['kickoff_time'] ?? '');
?>
<a class="matchbar__match" href="<?= e(url('fixtures')) ?>">
  <span class="matchbar__eyebrow">Next<br>match</span>
  <span class="matchbar__crests">
    <?php if ($homeCrest !== ''): ?><img src="<?= e($homeCrest) ?>" alt=""><?php endif; ?>
    <?php if ($awayCrest !== ''): ?><img src="<?= e($awayCrest) ?>" alt=""><?php endif; ?>
  </span>
  <span class="matchbar__info">
    <strong class="matchbar__title"><?= e(mb_strtoupper($teams['home'] . ' v ' . $teams['away'])) ?></strong>
    <span class="matchbar__subtitle"><?= e($fixture['competition'] ?: 'Fixture') ?>, <?= e($dateStr) ?><?php if ($timeStr !== ''): ?> &middot; <?= e($timeStr) ?><?php endif; ?></span>
  </span>
</a>
