<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/member_matches.php';

$currentSeason = getCurrentSeason($pdo);
$fixtures = $currentSeason ? member_matches_for_season($pdo, (int) $currentSeason['id']) : [];
$today = date('Y-m-d');
$upcoming = [];
$results = [];
foreach ($fixtures as $fixture) {
    $played = (string) ($fixture['status'] ?? '') === 'played'
        || ($fixture['full_time_home_score'] !== null && $fixture['full_time_away_score'] !== null);
    if ($played) {
        $results[] = $fixture;
    } else {
        $upcoming[] = $fixture;
    }
}
$nextFixture = null;
foreach ($upcoming as $fixture) {
    if ((string) ($fixture['match_date'] ?? '') >= $today) {
        $nextFixture = $fixture;
        break;
    }
}

function member_match_scoreline(array $fixture): string
{
    if ($fixture['full_time_home_score'] === null || $fixture['full_time_away_score'] === null) {
        return ucfirst((string) ($fixture['status'] ?? 'scheduled'));
    }
    return (int) $fixture['full_time_home_score'] . ' - ' . (int) $fixture['full_time_away_score'];
}
?>

<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">Fixtures & Results</div>
            <h1><?= $currentSeason ? h((string) $currentSeason['name']) : 'Matches' ?></h1>
            <p>Follow upcoming fixtures and catch up on results from the current Saltcoats Victoria season.</p>
        </div>
    </section>

    <?php if ($nextFixture): ?>
        <section class="member-card mb-3">
            <div class="member-card__body d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <div class="text-muted small text-uppercase fw-bold">Next Match</div>
                    <h2 class="mb-1">
                        <?= (int) $nextFixture['is_home'] === 1 ? 'Saltcoats Victoria vs ' . h((string) $nextFixture['opponent']) : h((string) $nextFixture['opponent']) . ' vs Saltcoats Victoria' ?>
                    </h2>
                    <div class="text-muted">
                        <?= h(member_format_date((string) $nextFixture['match_date'])) ?>
                        <?= !empty($nextFixture['kickoff_time']) ? ' at ' . h(member_format_time((string) $nextFixture['kickoff_time'])) : '' ?>
                        <?= !empty($nextFixture['competition']) ? ' · ' . h((string) $nextFixture['competition']) : '' ?>
                    </div>
                </div>
                <a class="btn btn-brand" href="match.php?id=<?= (int) $nextFixture['id'] ?>">View Match</a>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!$fixtures): ?>
        <div class="member-card"><div class="member-card__body text-center text-muted py-4">No fixtures found for this season.</div></div>
    <?php else: ?>
        <div class="member-grid member-grid--2">
            <section class="member-card">
                <div class="member-card__header">
                    <h2>Upcoming Fixtures</h2>
                    <span class="member-badge"><?= count($upcoming) ?> open</span>
                </div>
                <div class="member-card__body">
                    <?php if (!$upcoming): ?>
                        <p class="text-muted mb-0">No upcoming fixtures are listed yet.</p>
                    <?php else: ?>
                        <div class="member-list">
                            <?php foreach ($upcoming as $fixture): ?>
                                <a class="member-row" href="match.php?id=<?= (int) $fixture['id'] ?>">
                                    <div>
                                        <div class="member-row__title"><?= (int) $fixture['is_home'] === 1 ? 'vs' : '@' ?> <?= h((string) $fixture['opponent']) ?></div>
                                        <div class="member-row__meta">
                                            <?= h(member_format_date((string) $fixture['match_date'])) ?>
                                            <?= !empty($fixture['kickoff_time']) ? ' · ' . h(member_format_time((string) $fixture['kickoff_time'])) : '' ?>
                                            <?= !empty($fixture['competition']) ? ' · ' . h((string) $fixture['competition']) : '' ?>
                                        </div>
                                    </div>
                                    <span class="member-badge"><?= (int) $fixture['is_home'] === 1 ? 'Home' : 'Away' ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="member-card">
                <div class="member-card__header">
                    <h2>Results</h2>
                    <span class="member-badge"><?= count($results) ?> played</span>
                </div>
                <div class="member-card__body">
                    <?php if (!$results): ?>
                        <p class="text-muted mb-0">Results will appear here once matches have been played.</p>
                    <?php else: ?>
                        <div class="member-list">
                            <?php foreach ($results as $fixture): ?>
                                <a class="member-row" href="match.php?id=<?= (int) $fixture['id'] ?>">
                                    <div>
                                        <div class="member-row__title"><?= (int) $fixture['is_home'] === 1 ? 'vs' : '@' ?> <?= h((string) $fixture['opponent']) ?></div>
                                        <div class="member-row__meta"><?= h(member_format_date((string) $fixture['match_date'])) ?><?= !empty($fixture['competition']) ? ' · ' . h((string) $fixture['competition']) : '' ?></div>
                                    </div>
                                    <span class="badge text-bg-light fs-6"><?= h(member_match_scoreline($fixture)) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
