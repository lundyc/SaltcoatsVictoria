<?php

declare(strict_types=1);

/**
 * Season stats derived from the matchday record (Stage 6 of the veo port):
 * team P/W/D/L/GF/GA + clean sheets, and a per-player table of
 * appearances / goals / assists / cards / minutes.
 */

$pageStyles = ['match-fixture-tabs.css'];

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/functions.php';

$pageHero = [
    'eyebrow' => 'Match day',
    'title' => 'Season stats',
    'subtitle' => 'From recorded line-ups and match events',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/matchday_record_stats.php';

if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    echo '<div class="container-fluid"><div class="alert alert-danger">Access denied.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$bySeason = matchday_stats_seasons_with_data($pdo);
$seasonIds = array_keys($bySeason);

$seasonId = (int) ($_GET['season_id'] ?? 0);
if ($seasonId <= 0 || !in_array($seasonId, $seasonIds, true)) {
    $seasonId = $seasonIds ? (int) end($seasonIds) : (int) getSelectedSeasonId($pdo);
}

$seasonLabels = [];
foreach ($seasonIds as $sid) {
    $s = getSeasonById($pdo, $sid);
    $seasonLabels[$sid] = is_array($s) ? (string) ($s['name'] ?? ('Season ' . $sid)) : ('Season ' . $sid);
}

$stats = $seasonIds ? matchday_stats_season($pdo, $seasonId) : ['players' => [], 'team' => ['P' => 0, 'W' => 0, 'D' => 0, 'L' => 0, 'GF' => 0, 'GA' => 0, 'clean_sheets' => 0], 'fixtures' => 0];
$team = $stats['team'];
?>
<div class="container-fluid" style="max-width:1100px">
    <?php if (!$seasonIds): ?>
        <div class="alert alert-info">
            No match-day data recorded yet. Fill in the <strong>Line-ups</strong> and <strong>Events</strong> tabs on a
            fixture (or run the backfill migration) and stats will appear here.
        </div>
    <?php else: ?>
        <form method="get" class="row g-2 align-items-end mb-4" style="max-width:22rem">
            <div class="col">
                <label class="form-label small fw-bold mb-1">Season</label>
                <select name="season_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($seasonLabels as $sid => $label): ?>
                        <option value="<?= (int) $sid ?>"<?= $sid === $seasonId ? ' selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <noscript><div class="col-auto"><button class="btn btn-sm btn-secondary">Go</button></div></noscript>
        </form>

        <div class="row g-3 mb-4">
            <?php
            $tiles = [
                ['Played', $team['P']],
                ['Won', $team['W']],
                ['Drawn', $team['D']],
                ['Lost', $team['L']],
                ['Goals for', $team['GF']],
                ['Goals against', $team['GA']],
                ['Clean sheets', $team['clean_sheets']],
            ];
            foreach ($tiles as [$label, $value]):
            ?>
                <div class="col-6 col-md-3 col-lg-auto">
                    <div class="card text-center h-100"><div class="card-body py-2 px-3">
                        <div class="fs-4 fw-bold"><?= (int) $value ?></div>
                        <div class="small text-secondary text-uppercase"><?= h($label) ?></div>
                    </div></div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="small text-secondary">
            Based on <?= (int) $stats['fixtures'] ?> fixture<?= $stats['fixtures'] === 1 ? '' : 's' ?> with recorded data.
            Minutes are estimated from starts, substitutions and period lengths.
        </p>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 hub-data-table">
                <thead class="table-light">
                    <tr>
                        <th>Player</th>
                        <th class="text-end" title="Appearances">Apps</th>
                        <th class="text-end" title="Starts">St</th>
                        <th class="text-end" title="From the bench">Sub</th>
                        <th class="text-end">Goals</th>
                        <th class="text-end">Assists</th>
                        <th class="text-end" title="Own goals">OG</th>
                        <th class="text-end"><span class="badge text-bg-warning">&nbsp;</span></th>
                        <th class="text-end"><span class="badge text-bg-danger">&nbsp;</span></th>
                        <th class="text-end">Mins</th>
                        <th class="text-end" title="Clean sheets while on the pitch">CS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($stats['players'] === []): ?>
                        <tr><td colspan="11" class="text-secondary">No player data for this season yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($stats['players'] as $p): ?>
                        <tr>
                            <td><?= h((string) ($p['name'] ?: 'Unknown')) ?></td>
                            <td class="text-end"><?= (int) $p['apps'] ?></td>
                            <td class="text-end text-secondary"><?= (int) $p['starts'] ?></td>
                            <td class="text-end text-secondary"><?= (int) $p['sub_apps'] ?></td>
                            <td class="text-end fw-bold"><?= (int) $p['goals'] ?: '' ?></td>
                            <td class="text-end"><?= (int) $p['assists'] ?: '' ?></td>
                            <td class="text-end text-secondary"><?= (int) $p['own_goals'] ?: '' ?></td>
                            <td class="text-end"><?= (int) $p['yellow'] ?: '' ?></td>
                            <td class="text-end"><?= (int) $p['red'] ?: '' ?></td>
                            <td class="text-end text-secondary"><?= (int) $p['minutes'] ?></td>
                            <td class="text-end text-secondary"><?= (int) $p['clean_sheets'] ?: '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
