<?php
require_once __DIR__ . '/header.php';

// Fixtures & results for members. Uses the same data and row design as the public
// /fixtures and /results pages, but each row opens the members match centre.
$view = (string) ($_GET['view'] ?? 'fixtures') === 'results' ? 'results' : 'fixtures';
$seasons = pub_seasons();
$seasonId = (int) ($_GET['season'] ?? 0) ?: pub_current_season_id();
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
$seasonName = '';
foreach ($seasons as $s) {
    if ((int) $s['id'] === $seasonId) {
        $seasonName = (string) $s['name'];
    }
}
$competitions = pub_competitions_in_season($seasonId);
$competition = (string) ($_GET['competition'] ?? '');
if ($competition !== '' && !in_array($competition, $competitions, true)) {
    $competition = '';
}

$mode = $view === 'results' ? 'results' : 'upcoming';
$rows = pub_fixtures(['season_id' => $seasonId, 'competition' => $competition ?: null, 'type' => $mode]);
$byMonth = pub_fixtures_by_month($rows);

$upcomingAll = pub_fixtures(['season_id' => $seasonId, 'type' => 'upcoming']);
$resultsAll = pub_fixtures(['season_id' => $seasonId, 'type' => 'results']);
$nextFixture = $upcomingAll[0] ?? null;

$qs = static function (array $extra) use ($seasonId, $competition): string {
    $params = array_filter(['season' => $seasonId, 'competition' => $competition] + $extra, static fn ($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
};
$memberRowHref = static fn (array $f): string => '/members/match.php?id=' . (int) $f['id'];
?>
<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">Matches</div>
            <h1>Fixtures &amp; Results</h1>
            <p>Every Saltcoats Victoria game<?= $seasonName !== '' ? ' in ' . h($seasonName) : '' ?>. Open a match for line-ups, stats and the Player of the Match vote.</p>
        </div>
    </section>

    <?php if ($nextFixture && $view === 'fixtures'): ?>
        <div class="mmatch-next">
            <span class="mmatch-next__label">Next match</span>
            <?php partial('fixture_row', ['fixture' => $nextFixture, 'rowHref' => $memberRowHref($nextFixture)]); ?>
        </div>
    <?php endif; ?>

    <div class="fxtabs">
        <a class="fxtabs__tab <?= $view === 'fixtures' ? 'is-active' : '' ?>" href="<?= e($qs(['view' => 'fixtures'])) ?>">Fixtures <span class="mmatch-count"><?= count($upcomingAll) ?></span></a>
        <a class="fxtabs__tab <?= $view === 'results' ? 'is-active' : '' ?>" href="<?= e($qs(['view' => 'results'])) ?>">Results <span class="mmatch-count"><?= count($resultsAll) ?></span></a>
    </div>

    <form class="fxfilters" method="get" action="matches.php">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <label>
            <span>Season</span>
            <select name="season" onchange="this.form.submit()">
                <?php foreach ($seasons as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $seasonId ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($competitions): ?>
            <label>
                <span>Competition</span>
                <select name="competition" onchange="this.form.submit()">
                    <option value="">All competitions</option>
                    <?php foreach ($competitions as $c): ?>
                        <option value="<?= e($c) ?>" <?= $c === $competition ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <noscript><button type="submit">Go</button></noscript>
    </form>

    <?php if ($rows === []): ?>
        <div class="emptystate"><p>No <?= $view === 'results' ? 'results' : 'fixtures' ?> to show for this selection.</p></div>
    <?php else: ?>
        <?php foreach ($byMonth as $month => $monthRows): ?>
            <h2 class="fxmonth"><?= e($month) ?></h2>
            <div class="fxlist">
                <?php foreach ($monthRows as $fixture): ?>
                    <?php partial('fixture_row', ['fixture' => $fixture, 'rowHref' => $memberRowHref($fixture)]); ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <p class="fxnote"><a class="linkarrow" href="table.php">League table</a></p>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
