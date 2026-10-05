<?php
/**
 * Shared first-team match tab strip: Fixtures | Results | Records.
 *
 * $active : 'fixtures' | 'results' | 'records'  (which tab is current)
 * $suffix : query string (e.g. "?season=3") appended to the Fixtures/Results
 *           links only, so switching between those two keeps the active filter.
 *
 * The old "Results archive" tab is gone — the archive was merged into /results,
 * reachable through its season filter.
 */
declare(strict_types=1);

$active = $active ?? 'fixtures';
$suffix = $suffix ?? '';

$tabs = [
    'fixtures' => ['Fixtures', url('fixtures') . $suffix],
    'results'  => ['Results',  url('results') . $suffix],
    'records'  => ['Records',  url('club/records')],
];
?>
<div class="fxtabs">
  <?php foreach ($tabs as $key => [$label, $href]): ?>
    <a class="fxtabs__tab <?= $key === $active ? 'is-active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
