<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/match_sponsorship.php';

$currentSeason = getCurrentSeason($pdo);
$leagueCompetition = null;
$leagueEdition = null;
if (is_array($currentSeason) && !empty($currentSeason['competition_id'])) {
    $leagueCompetition = getMatchCompetitionById($pdo, (int) $currentSeason['competition_id']);
    if (function_exists('competitionStructureFindEdition')) {
        $leagueEdition = competitionStructureFindEdition($pdo, (int) $currentSeason['competition_id'], (int) $currentSeason['id']);
    }
}
$wosflTableUrlOverride = is_array($leagueEdition) ? trim((string) ($leagueEdition['competition_url'] ?? '')) : '';
if ($wosflTableUrlOverride === '' && is_array($leagueCompetition) && !empty($leagueCompetition['is_league'])) {
    $wosflTableUrlOverride = trim((string) ($leagueCompetition['league_url'] ?? ''));
}
require_once __DIR__ . '/../admin/wosfl-table.php';
if (!isset($teams) || !is_array($teams)) {
    $teams = [];
}
?>

<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">League Table</div>
            <h1>League Standings</h1>
            <p>Follow the Vics through the current league campaign.</p>
        </div>
    </section>

    <?php if (!$teams): ?>
        <div class="card shadow-sm border-0"><div class="card-body text-center text-muted py-4">The league table isn't available right now.</div></div>
    <?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>#</th><th>Club</th><th>P</th><th>W</th><th>D</th><th>L</th><th>F</th><th>A</th><th>GD</th><th>Pts</th></tr></thead>
                    <tbody>
                        <?php foreach ($teams as $team): ?>
                            <tr class="<?= stripos((string) $team['club'], 'saltcoats') !== false ? 'table-warning' : '' ?>">
                                <td><?= (int) $team['pos'] ?></td>
                                <td class="fw-semibold"><?= h((string) $team['club']) ?></td>
                                <td><?= (int) $team['p'] ?></td>
                                <td><?= (int) $team['w'] ?></td>
                                <td><?= (int) $team['d'] ?></td>
                                <td><?= (int) $team['l'] ?></td>
                                <td><?= (int) $team['f'] ?></td>
                                <td><?= (int) $team['a'] ?></td>
                                <td><?= (int) $team['gd'] ?></td>
                                <td class="fw-bold"><?= (int) $team['pts'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
