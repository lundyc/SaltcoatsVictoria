<?php
$pageHero = [
    'eyebrow' => 'Members',
    'title' => 'Player of the Match Voting',
    'subtitle' => 'How season ticket holders voted, match by match.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/motm.php';
require_once __DIR__ . '/lib/season.php';
ensureMotmSchema($pdo);

$seasons = $pdo->query('SELECT id, name FROM seasons ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
$selectedSeasonId = isset($_GET['season_id']) ? (int) $_GET['season_id'] : getSelectedSeasonId($pdo);
$fixtures = getFixturesWithMotmVotes($pdo, $selectedSeasonId ?: null);

$expandedFixtureId = (int) ($_GET['fixture_id'] ?? 0);
$expandedVotes = $expandedFixtureId ? getMotmVotesForFixture($pdo, $expandedFixtureId) : [];
?>

<div class="hub-section-commandbar">
  <div><h2>Matches with votes</h2><p><?= count($fixtures) ?> match<?= count($fixtures) === 1 ? '' : 'es' ?> with at least one vote.</p></div>
</div>

<form method="get" class="mb-3">
    <select class="form-select form-select-sm" style="max-width:260px;" name="season_id" onchange="this.form.submit()">
        <option value="0">All seasons</option>
        <?php foreach ($seasons as $season): ?>
            <option value="<?= (int) $season['id'] ?>" <?= $selectedSeasonId === (int) $season['id'] ? 'selected' : '' ?>><?= h((string) $season['name']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (!$fixtures): ?>
  <div class="card shadow-sm border-0"><div class="card-body text-center text-muted py-4">No Player of the Match votes recorded for this season yet.</div></div>
<?php endif; ?>

<?php foreach ($fixtures as $fixture): ?>
    <?php $topVotes = (int) ($fixture['results'][0]['votes'] ?? 0); ?>
    <section class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-semibold"><?= (int) $fixture['is_home'] === 1 ? 'vs' : '@' ?> <?= h((string) $fixture['opponent']) ?></div>
                <div class="small text-muted">
                    <?= h(date('d/m/Y', strtotime((string) $fixture['match_date']))) ?>
                    <?php if ($fixture['full_time_home_score'] !== null && $fixture['full_time_away_score'] !== null): ?>
                        · <?= (int) $fixture['full_time_home_score'] ?>–<?= (int) $fixture['full_time_away_score'] ?>
                    <?php endif; ?>
                </div>
            </div>
            <span class="badge text-bg-light"><?= (int) $fixture['vote_count'] ?> vote<?= (int) $fixture['vote_count'] === 1 ? '' : 's' ?></span>
        </div>
        <div class="card-body">
            <?php foreach ($fixture['results'] as $result): ?>
                <div class="d-flex justify-content-between align-items-center border-bottom py-1">
                    <span><?= h((string) $result['player_name']) ?><?= (int) $result['votes'] === $topVotes ? ' 🏆' : '' ?></span>
                    <span class="badge text-bg-light"><?= (int) $result['votes'] ?> vote<?= (int) $result['votes'] === 1 ? '' : 's' ?></span>
                </div>
            <?php endforeach; ?>

            <?php if ($expandedFixtureId === (int) $fixture['fixture_id']): ?>
                <h3 class="h6 text-muted text-uppercase small mt-3">Who voted for whom</h3>
                <?php foreach ($expandedVotes as $vote): ?>
                    <div class="d-flex justify-content-between align-items-center border-bottom py-1 small">
                        <span><?= h((string) $vote['holder_name']) ?></span>
                        <span class="text-muted">voted <?= h((string) $vote['player_name']) ?> · <?= h(date('d/m/Y H:i', strtotime((string) $vote['created_at']))) ?></span>
                    </div>
                <?php endforeach; ?>
                <a class="btn btn-sm btn-outline-secondary mt-2" href="motm.php?season_id=<?= $selectedSeasonId ?>">Hide details</a>
            <?php else: ?>
                <a class="btn btn-sm btn-outline-secondary mt-2" href="motm.php?season_id=<?= $selectedSeasonId ?>&fixture_id=<?= (int) $fixture['fixture_id'] ?>">Who voted for whom?</a>
            <?php endif; ?>
        </div>
    </section>
<?php endforeach; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
