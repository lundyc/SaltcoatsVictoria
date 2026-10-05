<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/season.php';

/* --------------------------------------------------------------------------
 * Season scope. The Hub header has its own season switcher (numeric ids); this
 * page adds an "All seasons" mode via ?season_id=all so the whole match history
 * (live + the merged results archive) can be browsed in one place.
 * ---------------------------------------------------------------------- */
$allSeasons = (($_GET['season_id'] ?? null) === 'all');
$seasonId   = $allSeasons ? 0 : getSelectedSeasonId($pdo);
$season     = $allSeasons ? null : getSeasonById($pdo, $seasonId);
$seasons    = getSeasons($pdo);                       // start_date ASC
$seasonsDesc = array_reverse($seasons);

$isOpenSeason = !$allSeasons && $season !== null && (int) ($season['is_locked'] ?? 0) !== 1;
$showSponsor  = $isOpenSeason;                        // sponsorship only tracked for the working season

$pageHero = [
    'eyebrow'  => 'Fixture management',
    'title'    => 'Matches',
    'subtitle' => $allSeasons ? 'All seasons' : ('Season: ' . ($season['name'] ?? 'Unknown')),
    'actions'  => [],
];

require_once __DIR__ . '/header.php';

$fixtures = getMatchFixtures($pdo, $seasonId);

/* --------------------------------------------------------------------------
 * Per-fixture view model (result from the club's perspective, sponsorship
 * rollup only where it is meaningful).
 * ---------------------------------------------------------------------- */
$clubOutcome = static function (array $f): array {
    $h = $f['full_time_home_score'];
    $a = $f['full_time_away_score'];
    $played = strtolower(trim((string) ($f['status'] ?? ''))) === 'played' || ($h !== null && $a !== null);
    if (!$played || $h === null || $a === null) {
        return ['played' => $played, 'outcome' => '', 'us' => null, 'them' => null];
    }
    $us   = $f['is_home'] ? (int) $h : (int) $a;
    $them = $f['is_home'] ? (int) $a : (int) $h;
    return [
        'played'  => true,
        'outcome' => $us > $them ? 'W' : ($us < $them ? 'L' : 'D'),
        'us'      => $us,
        'them'    => $them,
    ];
};

$fixtureStatusClass = static function (string $status): string {
    return match (strtolower(trim($status))) {
        'played', 'finished', 'complete', 'completed' => 'text-bg-success',
        'live', 'in_progress', 'in progress'          => 'text-bg-danger',
        'postponed', 'delayed'                        => 'text-bg-warning',
        'cancelled', 'canceled', 'abandoned'          => 'text-bg-dark',
        'scheduled', 'upcoming'                       => 'text-bg-primary',
        default                                       => 'text-bg-secondary',
    };
};

$outcomeChipClass = static fn (string $o): string => match ($o) {
    'W' => 'match-outcome match-outcome--w',
    'D' => 'match-outcome match-outcome--d',
    'L' => 'match-outcome match-outcome--l',
    default => 'match-outcome',
};

// Sponsorship state helpers (only used when $showSponsor).
$matchSponsorshipStateClass = static function (?array $row): string {
    if ($row === null) {
        return 'is-available';
    }
    if ((int) ($row['is_complimentary'] ?? 0) === 1) {
        return 'is-complimentary';
    }
    $amount = (float) ($row['amount'] ?? 0);
    $paid   = (float) ($row['paid_total'] ?? 0);
    if ($paid <= 0.0001) {
        return 'is-unpaid';
    }
    return ($paid + 0.0001) >= $amount ? 'is-paid' : 'is-partial';
};
$matchSponsorshipStateIcon = static function (?array $row): string {
    if ($row === null) {
        return '<i class="fa-solid fa-minus" aria-hidden="true"></i>';
    }
    if ((int) ($row['is_complimentary'] ?? 0) === 1) {
        return '<i class="fa-solid fa-gift" aria-hidden="true"></i>';
    }
    $amount = (float) ($row['amount'] ?? 0);
    $paid   = (float) ($row['paid_total'] ?? 0);
    if ($paid <= 0.0001) {
        return '<i class="fa-solid fa-sterling-sign" aria-hidden="true"></i>';
    }
    return ($paid + 0.0001) >= $amount
        ? '<i class="fa-solid fa-check" aria-hidden="true"></i>'
        : '<i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i>';
};
$matchSponsorshipStateLabel = static function (?array $row): string {
    if ($row === null) {
        return 'Not assigned';
    }
    if ((int) ($row['is_complimentary'] ?? 0) === 1) {
        return 'Complimentary / free promotion';
    }
    $amount = (float) ($row['amount'] ?? 0);
    $paid   = (float) ($row['paid_total'] ?? 0);
    if ($paid <= 0.0001) {
        return 'Assigned, not paid';
    }
    return ($paid + 0.0001) >= $amount ? 'Paid' : 'Part paid';
};

$rowsVm = [];
$played = $upcoming = $won = $drawn = $lost = $gf = $ga = 0;
$activeMatchDay = $activeMatchBall = $activeMotm = 0;
$totalDue = $totalPaid = 0.0;

foreach ($fixtures as $fixture) {
    $o = $clubOutcome($fixture);
    if ($o['played']) {
        $played++;
        if ($o['outcome'] === 'W') { $won++; }
        elseif ($o['outcome'] === 'D') { $drawn++; }
        elseif ($o['outcome'] === 'L') { $lost++; }
        if ($o['us'] !== null) { $gf += $o['us']; $ga += $o['them']; }
    } else {
        $upcoming++;
    }

    $dayRow = $ballRow = $motmRow = null;
    if ($showSponsor) {
        foreach (getMatchSponsorshipRows($pdo, (int) $fixture['id']) as $sr) {
            if ((int) ($sr['is_complimentary'] ?? 0) !== 1) {
                $totalDue  += (float) $sr['amount'];
                $totalPaid += (float) $sr['paid_total'];
            }
            if ($sr['sponsorship_role'] === 'match_day')  { $activeMatchDay++;  $dayRow  = $sr; }
            if ($sr['sponsorship_role'] === 'match_ball') { $activeMatchBall++; $ballRow = $sr; }
            if ($sr['sponsorship_role'] === 'motm')       { $activeMotm++;      $motmRow = $sr; }
        }
    }

    $rowsVm[] = [
        'f'       => $fixture,
        'o'       => $o,
        'dayRow'  => $dayRow,
        'ballRow' => $ballRow,
        'motmRow' => $motmRow,
    ];
}
// Keep the next fixture at the top, then show completed matches newest first.
usort($rowsVm, static function (array $a, array $b): int {
    $aPlayed = $a['o']['played'];
    $bPlayed = $b['o']['played'];
    if ($aPlayed !== $bPlayed) {
        return $aPlayed ? 1 : -1;
    }
    $aDate = (string) ($a['f']['match_date'] ?? '');
    $bDate = (string) ($b['f']['match_date'] ?? '');
    $dateOrder = $aPlayed ? strcmp($bDate, $aDate) : strcmp($aDate, $bDate);
    return $dateOrder !== 0 ? $dateOrder : ($aPlayed
        ? ((int) $b['f']['id'] <=> (int) $a['f']['id'])
        : ((int) $a['f']['id'] <=> (int) $b['f']['id']));
});
$outstanding = max(0, $totalDue - $totalPaid);
$fixtureCount = count($rowsVm);
$competitionOptions = [];
foreach ($rowsVm as $vm) {
    $competition = trim((string) ($vm['f']['competition'] ?? ''));
    if ($competition !== '') {
        $competitionOptions[$competition] = $competition;
    }
}
natcasesort($competitionOptions);

/* --------------------------------------------------------------------------
 * Metric grid — contextual.
 * ---------------------------------------------------------------------- */
$metrics = [
    ['label' => 'Matches',  'value' => $fixtureCount, 'meta' => $allSeasons ? 'All seasons' : (string) ($season['name'] ?? ''), 'icon' => 'fa-calendar-days', 'tone' => 'primary'],
    ['label' => 'Played',   'value' => $played,   'meta' => $played ? sprintf('%d W · %d D · %d L', $won, $drawn, $lost) : 'None yet', 'icon' => 'fa-flag-checkered', 'tone' => 'success'],
    ['label' => 'Upcoming', 'value' => $upcoming, 'meta' => 'Not yet played', 'icon' => 'fa-clock', 'tone' => 'info'],
];
if ($showSponsor) {
    $metrics[] = ['label' => 'Sponsor slots', 'value' => ($activeMatchDay + $activeMatchBall + $activeMotm), 'meta' => sprintf('%d day · %d ball · %d MOTM', $activeMatchDay, $activeMatchBall, $activeMotm), 'icon' => 'fa-handshake', 'tone' => 'info'];
    $metrics[] = ['label' => 'Total due', 'value' => gbp($totalDue), 'meta' => 'Booked sponsorship value', 'icon' => 'fa-sterling-sign', 'tone' => 'primary'];
    $metrics[] = ['label' => 'Outstanding', 'value' => gbp($outstanding), 'meta' => 'Still to collect', 'icon' => 'fa-hourglass-half', 'tone' => 'danger'];
} else {
    $metrics[] = ['label' => 'Goals', 'value' => $played ? ($gf . '–' . $ga) : '—', 'meta' => $played ? 'For – against' : '', 'icon' => 'fa-futbol', 'tone' => 'neutral'];
}
hub_render_metric_grid($metrics, 'Match summary');

$colspan = 5 + ($allSeasons ? 1 : 0) + ($showSponsor ? 3 : 0);
?>

<?php if (isset($_GET['imported'])): ?>
    <div class="alert alert-success">
        Fixtures imported successfully.
        <?php if (isset($_GET['created']) || isset($_GET['updated'])): ?>
            Created <?= (int) ($_GET['created'] ?? 0) ?>, updated <?= (int) ($_GET['updated'] ?? 0) ?>.
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="hub-toolbar matches-toolbar" aria-label="Filter matches">
    <div class="matches-toolbar__heading">
        <span class="matches-toolbar__eyebrow">Match list</span>
        <strong>Upcoming first</strong>
        <span class="matches-toolbar__hint">Finished matches follow, newest first</span>
    </div>
    <form class="matches-toolbar__season" method="get" action="matches.php">
        <label for="matchSeason">Season scope</label>
        <select id="matchSeason" name="season_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="all" <?= $allSeasons ? 'selected' : '' ?>>All seasons</option>
            <?php foreach ($seasonsDesc as $s): ?>
                <option value="<?= (int) $s['id'] ?>" <?= (!$allSeasons && (int) $s['id'] === (int) $seasonId) ? 'selected' : '' ?>>
                    <?= h((string) $s['name']) ?><?= !empty($s['is_current']) ? ' · Current' : '' ?><?= !empty($s['is_locked']) ? ' · Locked' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn btn-sm btn-outline-secondary">Go</button></noscript>
    </form>

    <div class="matches-toolbar__filters">
        <div class="matches-toolbar__field">
            <label for="matchStatusFilter">View</label>
            <select id="matchStatusFilter" class="form-select form-select-sm" aria-label="Match status">
                <option value="all">All matches</option>
                <option value="upcoming">Upcoming</option>
                <option value="played">Finished</option>
            </select>
        </div>
        <div class="matches-toolbar__field">
            <label for="matchVenueFilter">Venue</label>
            <select id="matchVenueFilter" class="form-select form-select-sm" aria-label="Home or away">
                <option value="all">Home &amp; away</option>
                <option value="home">Home</option>
                <option value="away">Away</option>
            </select>
        </div>
        <div class="matches-toolbar__field">
            <label for="matchOutcomeFilter">Outcome</label>
            <select id="matchOutcomeFilter" class="form-select form-select-sm" aria-label="Match outcome">
                <option value="all">Any outcome</option>
                <option value="W">Wins</option>
                <option value="D">Draws</option>
                <option value="L">Losses</option>
            </select>
        </div>
        <div class="matches-toolbar__field matches-toolbar__field--competition">
            <label for="matchCompetitionFilter">Competition</label>
            <select id="matchCompetitionFilter" class="form-select form-select-sm" aria-label="Competition">
                <option value="all">All competitions</option>
                <?php foreach ($competitionOptions as $competition): ?>
                    <option value="<?= h($competition) ?>"><?= h($competition) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="matches-search">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <label class="visually-hidden" for="matchSearch">Search matches</label>
            <input type="search" id="matchSearch" class="form-control form-control-sm" placeholder="Search opponent, date…" autocomplete="off">
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="matchFilterReset" hidden>
            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reset
        </button>
    </div>

    <div class="hub-toolbar__count" aria-live="polite">
        <strong id="matchVisibleCount"><?= $fixtureCount ?></strong>
        <span id="matchCountLabel"><?= $fixtureCount === 1 ? 'match' : 'matches' ?></span>
    </div>

    <div class="hub-local-actions">
        <?php if (!$allSeasons): ?>
            <a href="match.php?action=new&amp;season_id=<?= (int) $seasonId ?>" class="btn btn-brand btn-sm"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add match</a>
        <?php endif; ?>
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">More</button>
            <div class="dropdown-menu dropdown-menu-end">
                <?php if (!$allSeasons): ?>
                    <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#importFixturesModal"><i class="fa-solid fa-upload" aria-hidden="true"></i>Import fixtures</button>
                    <a class="dropdown-item" href="monthly_fixtures.php?season_id=<?= (int) $seasonId ?>"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i>Monthly fixtures</a>
                    <a class="dropdown-item" href="match_fixtures_poster.php?season_id=<?= (int) $seasonId ?>"><i class="fa-solid fa-image" aria-hidden="true"></i>Fixture poster</a>
                <?php endif; ?>
                <a class="dropdown-item" href="seasons.php"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i>Seasons</a>
            </div>
        </div>
    </div>
</div>

<?php if (!$allSeasons): ?>
<div class="modal fade" id="importFixturesModal" tabindex="-1" aria-labelledby="importFixturesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-1" id="importFixturesModalLabel">Import fixtures</h5>
                    <div class="text-muted small">Upload a CSV or Excel file to create or update fixtures in <?= h((string) ($season['name'] ?? 'this season')) ?>.</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-end mb-3">
                    <a href="templates/match_fixtures_import_template.csv" class="btn btn-outline-secondary btn-sm" download>
                        <i class="fa-solid fa-file-csv me-1"></i>Download template
                    </a>
                </div>
                <form method="post" action="matches_import.php" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-8">
                            <label class="form-label">Import file</label>
                            <input type="file" name="import_file" class="form-control" accept=".csv,.xlsx,.xlsm,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
                            <div class="form-text">Headers must match the template. Existing fixtures are matched by <code>fixture_id</code> or <code>match_date + opponent</code>.</div>
                        </div>
                        <div class="col-lg-4 text-lg-end">
                            <button type="submit" class="btn btn-brand w-100">Import fixtures</button>
                        </div>
                        <?php if ((int) ($season['is_locked'] ?? 0) === 1): ?>
                            <div class="col-12">
                                <div class="alert alert-warning mb-0">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1" name="historical_import" id="historicalImportConfirm" required>
                                        <label class="form-check-label fw-semibold" for="historicalImportConfirm">I understand this import will add or update fixtures in a locked season.</label>
                                    </div>
                                    <div class="small mt-1">The season stays locked for normal editing. Review the spreadsheet carefully first.</div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm hub-section hub-table-card">
    <div class="card-body">
        <?php if (!$rowsVm): ?>
            <div class="alert alert-info mb-0 hub-empty-state">No matches recorded for this selection.</div>
        <?php else: ?>
            <?php if ($showSponsor): ?>
                <div class="matches-legend" aria-label="Sponsorship payment key">
                    <span><span class="fixtures-sponsorship-status is-available is-compact"><i class="fa-solid fa-minus" aria-hidden="true"></i></span>Available</span>
                    <span><span class="fixtures-sponsorship-status is-unpaid is-compact"><i class="fa-solid fa-sterling-sign" aria-hidden="true"></i></span>Unpaid</span>
                    <span><span class="fixtures-sponsorship-status is-partial is-compact"><i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i></span>Part paid</span>
                    <span><span class="fixtures-sponsorship-status is-paid is-compact"><i class="fa-solid fa-check" aria-hidden="true"></i></span>Paid</span>
                    <span><span class="fixtures-sponsorship-status is-complimentary is-compact"><i class="fa-solid fa-gift" aria-hidden="true"></i></span>Complimentary</span>
                </div>
            <?php endif; ?>

            <?php
            /** One row/card, shared desktop + mobile. */
            $renderMatch = function (array $vm, string $mode) use (
                $seasonId, $allSeasons, $showSponsor, $fixtureStatusClass, $outcomeChipClass,
                $matchSponsorshipStateClass, $matchSponsorshipStateIcon, $matchSponsorshipStateLabel
            ): void {
                $f = $vm['f'];
                $o = $vm['o'];
                $id = (int) $f['id'];
                $isHome = !empty($f['is_home']);
                $deleted = isDeletedOpponentLabel((string) ($f['opponent'] ?? ''));
                $dateStr = date('d/m/Y', strtotime((string) $f['match_date']));
                $ko = !empty($f['kickoff_time']) ? substr((string) $f['kickoff_time'], 0, 5) : '';
                $comp = trim((string) ($f['competition'] ?? ''));
                $stage = trim((string) ($f['competition_stage'] ?? ''));
                $seasonName = (string) ($f['season_name'] ?? '');
                $statusRaw = (string) ($f['status'] ?? '');
                $search = strtolower(implode(' ', array_filter([
                    (string) ($f['opponent'] ?? ''), $comp, $stage, $seasonName, $statusRaw,
                    (string) $f['match_date'], $dateStr, $isHome ? 'home' : 'away',
                ])));
                $href = 'match.php?id=' . $id . '&season_id=' . ($allSeasons ? (int) $f['season_id'] : (int) $seasonId);

                $scoreCell = $o['played'] && $o['us'] !== null
                    ? '<span class="match-score">' . ($isHome ? (int) $o['us'] : (int) $o['them']) . '&ndash;' . ($isHome ? (int) $o['them'] : (int) $o['us']) . '</span>'
                      . ' <span class="' . $outcomeChipClass($o['outcome']) . '">' . $o['outcome'] . '</span>'
                    : '<span class="badge ' . h($fixtureStatusClass($statusRaw)) . '">' . h(ucfirst($statusRaw ?: 'scheduled')) . '</span>';

                if ($mode === 'mobile'):
                    ?>
                    <a class="matches-card js-match-item" href="<?= h($href) ?>"
                       data-match-id="<?= $id ?>" data-status="<?= $o['played'] ? 'played' : 'upcoming' ?>"
                       data-venue="<?= $isHome ? 'home' : 'away' ?>" data-outcome="<?= h($o['outcome']) ?>"
                       data-competition="<?= h($comp) ?>" data-search="<?= h($search) ?>">
                        <div class="matches-card__top">
                            <span class="matches-venue-icon <?= $isHome ? 'is-home' : 'is-away' ?>"><i class="fa-solid <?= $isHome ? 'fa-house' : 'fa-bus' ?>" aria-hidden="true"></i></span>
                            <span class="matches-card__opp"><?= $deleted ? '<em class="text-muted">Team deleted</em>' : h((string) $f['opponent']) ?></span>
                            <span class="matches-card__score"><?= $scoreCell ?></span>
                        </div>
                        <div class="matches-card__meta">
                            <span><?= h($dateStr) ?><?= $ko ? ' · ' . h($ko) : '' ?></span>
                            <?php if ($comp !== ''): ?><span><?= h($comp) ?><?= $stage ? ' · ' . h($stage) : '' ?></span><?php endif; ?>
                            <?php if ($allSeasons && $seasonName !== ''): ?><span class="badge bg-light text-dark"><?= h($seasonName) ?></span><?php endif; ?>
                        </div>
                        <?php if ($showSponsor): ?>
                            <div class="matches-card__sponsors">
                                <?php foreach ([['Day', $vm['dayRow']], ['Ball', $vm['ballRow']], ['MOTM', $vm['motmRow']]] as [$lbl, $r]): ?>
                                    <span><?= h($lbl) ?>
                                        <span class="fixtures-sponsorship-status is-compact <?= h($matchSponsorshipStateClass($r)) ?>" title="<?= h($matchSponsorshipStateLabel($r)) ?>"><?= $matchSponsorshipStateIcon($r) ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </a>
                    <?php
                else:
                    ?>
                    <tr class="match-row js-match-item" role="link" tabindex="0"
                        data-href="<?= h($href) ?>" data-match-id="<?= $id ?>"
                        data-status="<?= $o['played'] ? 'played' : 'upcoming' ?>"
                        data-venue="<?= $isHome ? 'home' : 'away' ?>" data-outcome="<?= h($o['outcome']) ?>"
                        data-competition="<?= h($comp) ?>" data-search="<?= h($search) ?>">
                        <td class="text-center">
                            <span class="matches-venue-icon <?= $isHome ? 'is-home' : 'is-away' ?>" title="<?= $isHome ? 'Home' : 'Away' ?>"><i class="fa-solid <?= $isHome ? 'fa-house' : 'fa-bus' ?>" aria-hidden="true"></i></span>
                        </td>
                        <td class="nowrap"><?= h($dateStr) ?><?php if ($ko): ?><div class="small text-muted"><?= h($ko) ?></div><?php endif; ?></td>
                        <td>
                            <?php if ($deleted): ?><em class="text-muted">Team deleted</em>
                            <?php else: ?><span class="fw-semibold"><?= h((string) $f['opponent']) ?></span><?php endif; ?>
                        </td>
                        <td class="match-comp">
                            <?= $comp !== '' ? h($comp) : '<span class="text-muted">—</span>' ?>
                            <?php if ($stage !== ''): ?><div class="small text-muted"><?= h($stage) ?></div><?php endif; ?>
                        </td>
                        <?php if ($allSeasons): ?><td class="nowrap small text-muted"><?= h($seasonName) ?></td><?php endif; ?>
                        <td class="nowrap"><?= $scoreCell ?></td>
                        <?php if ($showSponsor): ?>
                            <?php foreach ([$vm['dayRow'], $vm['ballRow'], $vm['motmRow']] as $r): ?>
                                <td class="text-center">
                                    <span class="fixtures-sponsorship-status is-compact <?= h($matchSponsorshipStateClass($r)) ?>" title="<?= h($matchSponsorshipStateLabel($r)) ?>" aria-label="<?= h($matchSponsorshipStateLabel($r)) ?>"><?= $matchSponsorshipStateIcon($r) ?></span>
                                </td>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                    <?php
                endif;
            };
            ?>

            <div class="d-lg-none d-flex flex-column gap-2" id="matchesMobileList">
                <?php foreach ($rowsVm as $vm) { $renderMatch($vm, 'mobile'); } ?>
            </div>

            <div class="d-none d-lg-block table-responsive">
                <table class="table hub-data-table align-middle matches-table">
                    <thead>
                        <tr>
                            <th class="text-center"><span class="visually-hidden">Venue</span></th>
                            <th>Date</th>
                            <th>Opponent</th>
                            <th>Competition</th>
                            <?php if ($allSeasons): ?><th>Season</th><?php endif; ?>
                            <th>Result</th>
                            <?php if ($showSponsor): ?>
                                <th class="text-center">Match Day</th>
                                <th class="text-center">Match Ball</th>
                                <th class="text-center">MOTM</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="matchesTableBody">
                        <?php foreach ($rowsVm as $vm) { $renderMatch($vm, 'desktop'); } ?>
                    </tbody>
                </table>
            </div>

            <div class="hub-empty-state matches-filter-empty" id="matchesFilterEmpty" hidden>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <strong>No matches for these filters</strong>
                <span>Try a different search or filter.</span>
            </div>

            <nav class="matches-pagination mt-3" id="matchesPagination" aria-label="Match pages" hidden>
                <ul class="pagination pagination-sm justify-content-center mb-0"></ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<style>
.matches-toolbar { display:flex; flex-wrap:wrap; align-items:flex-end; gap:.75rem 1rem; }
.matches-toolbar__heading { display:flex; flex-direction:column; gap:.08rem; margin-right:auto; min-width:13rem; }
.matches-toolbar__eyebrow, .matches-toolbar__field label { font-size:.68rem; font-weight:800; letter-spacing:.07em; text-transform:uppercase; color:var(--bs-secondary-color,#6c757d); }
.matches-toolbar__heading strong { font-size:.96rem; color:#1f1a1d; }
.matches-toolbar__hint { font-size:.75rem; color:var(--bs-secondary-color,#6c757d); }
.matches-toolbar__season { display:flex; flex-direction:column; gap:.2rem; }
.matches-toolbar__season label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--bs-secondary-color,#6c757d); }
.matches-toolbar__season select { min-width:14rem; }
.matches-toolbar__filters { display:flex; flex-wrap:wrap; align-items:center; gap:.5rem; }
.matches-toolbar__field { display:flex; flex-direction:column; gap:.2rem; }
.matches-toolbar__field select { min-width:8.5rem; }
.matches-toolbar__field--competition select { min-width:12rem; max-width:15rem; }
.matches-search { position:relative; }
.matches-search i { position:absolute; left:.6rem; top:50%; transform:translateY(-50%); color:var(--bs-secondary-color,#6c757d); font-size:.8rem; }
.matches-search input { padding-left:1.9rem; min-width:15rem; }
.matches-legend { display:flex; flex-wrap:wrap; gap:1rem; font-size:.8rem; color:var(--bs-secondary-color,#6c757d); margin-bottom:.9rem; }
.matches-legend > span { display:inline-flex; align-items:center; gap:.35rem; }
.matches-venue-icon { display:inline-grid; place-items:center; width:1.9rem; height:1.9rem; border-radius:50%; font-size:.78rem; }
.matches-venue-icon.is-home { background:rgba(106,32,54,.10); color:#6a2036; }
.matches-venue-icon.is-away { background:rgba(0,0,0,.06); color:#555; }
.matches-table td { vertical-align:middle; }
.match-row { cursor:pointer; }
.match-row:hover td { background:rgba(106,32,54,.045); }
.match-row:focus-visible { outline:2px solid #6a2036; outline-offset:-2px; }
.match-comp { max-width:22rem; }
.match-score { font-weight:700; font-variant-numeric:tabular-nums; }
.match-outcome { display:inline-grid; place-items:center; min-width:1.4rem; height:1.4rem; padding:0 .3rem; border-radius:.35rem; font-size:.72rem; font-weight:800; color:#fff; }
.match-outcome--w { background:#2f7d55; }
.match-outcome--d { background:#8a8a8a; }
.match-outcome--l { background:#b23b3b; }
.matches-card { display:block; padding:.8rem .9rem; border:1px solid var(--bs-border-color,#dee2e6); border-radius:.7rem; text-decoration:none; color:inherit; background:#fff; }
.matches-card:hover { border-color:#6a2036; }
.matches-card__top { display:flex; align-items:center; gap:.6rem; }
.matches-card__opp { font-weight:600; flex:1; min-width:0; }
.matches-card__score { white-space:nowrap; }
.matches-card__meta { display:flex; flex-wrap:wrap; gap:.4rem .8rem; margin-top:.4rem; font-size:.82rem; color:var(--bs-secondary-color,#6c757d); }
.matches-card__sponsors { display:flex; gap:1rem; margin-top:.5rem; font-size:.78rem; color:var(--bs-secondary-color,#6c757d); }
.matches-card__sponsors span { display:inline-flex; align-items:center; gap:.3rem; }
.matches-filter-empty { text-align:center; padding:2rem 1rem; }
@media (max-width: 900px) {
    .matches-toolbar__heading { flex-basis:100%; }
    .matches-toolbar__field--competition { flex:1 1 12rem; }
}
@media (max-width: 560px) {
    .matches-toolbar__season, .matches-toolbar__filters, .matches-toolbar__field,
    .matches-toolbar__field select, .matches-search, .matches-search input { width:100%; max-width:none; }
}
</style>

<script>
(function () {
    var PAGE_SIZE = 25;
    var page = 1;

    var body = document.getElementById('matchesTableBody');
    var mobile = document.getElementById('matchesMobileList');
    if (!body && !mobile) { return; }

    var search = document.getElementById('matchSearch');
    var venueSel = document.getElementById('matchVenueFilter');
    var statusSel = document.getElementById('matchStatusFilter');
    var outcomeSel = document.getElementById('matchOutcomeFilter');
    var competitionSel = document.getElementById('matchCompetitionFilter');
    var resetBtn = document.getElementById('matchFilterReset');
    var countEl = document.getElementById('matchVisibleCount');
    var countLabel = document.getElementById('matchCountLabel');
    var emptyEl = document.getElementById('matchesFilterEmpty');
    var pager = document.getElementById('matchesPagination');

    var status = 'all';
    var items = Array.prototype.slice.call(document.querySelectorAll('.js-match-item'));
    // De-dupe by id so desktop + mobile copies count once for totals.
    var ids = [];
    items.forEach(function (el) { if (ids.indexOf(el.dataset.matchId) === -1) { ids.push(el.dataset.matchId); } });

    function norm(v) { return String(v || '').toLowerCase().trim(); }

    function matches(el) {
        var q = norm(search && search.value);
        var venue = venueSel ? venueSel.value : 'all';
        var outcome = outcomeSel ? outcomeSel.value : 'all';
        var competition = competitionSel ? competitionSel.value : 'all';
        if (status !== 'all' && el.dataset.status !== status) { return false; }
        if (venue !== 'all' && el.dataset.venue !== venue) { return false; }
        if (outcome !== 'all' && el.dataset.outcome !== outcome) { return false; }
        if (competition !== 'all' && el.dataset.competition !== competition) { return false; }
        if (q && el.dataset.search.indexOf(q) === -1) { return false; }
        return true;
    }

    function render() {
        // Desktop pagination works on rows; mobile just shows everything that matches.
        var visibleIds = [];
        (body ? Array.prototype.slice.call(body.querySelectorAll('.js-match-item')) : []).forEach(function (el) {
            if (visibleIds.indexOf(el.dataset.matchId) === -1 && matches(el)) { visibleIds.push(el.dataset.matchId); }
        });
        var total = visibleIds.length;
        var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
        if (page > pages) { page = pages; }
        var start = (page - 1) * PAGE_SIZE;
        var onPage = {};
        visibleIds.slice(start, start + PAGE_SIZE).forEach(function (id) { onPage[id] = true; });

        if (body) {
            body.querySelectorAll('.js-match-item').forEach(function (el) {
                el.hidden = !(matches(el) && onPage[el.dataset.matchId]);
            });
        }
        if (mobile) {
            mobile.querySelectorAll('.js-match-item').forEach(function (el) { el.hidden = !matches(el); });
        }

        if (countEl) { countEl.textContent = String(total); }
        if (countLabel) { countLabel.textContent = total === 1 ? 'match' : 'matches'; }
        if (emptyEl) { emptyEl.hidden = total !== 0; }

        var active = (search && search.value) || (venueSel && venueSel.value !== 'all')
            || (outcomeSel && outcomeSel.value !== 'all') || (competitionSel && competitionSel.value !== 'all') || status !== 'all';
        if (resetBtn) { resetBtn.hidden = !active; }

        renderPager(pages);
    }

    function renderPager(pages) {
        if (!pager) { return; }
        var ul = pager.querySelector('ul');
        ul.innerHTML = '';
        if (pages <= 1) { pager.hidden = true; return; }
        pager.hidden = false;
        function add(label, target, disabled, current) {
            var li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (current ? ' active' : '');
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'page-link'; b.textContent = label;
            if (!disabled && !current) { b.addEventListener('click', function () { page = target; render(); window.scrollTo({ top: 0, behavior: 'smooth' }); }); }
            li.appendChild(b); ul.appendChild(li);
        }
        add('Prev', page - 1, page === 1, false);
        for (var i = 1; i <= pages; i++) {
            if (pages > 9 && Math.abs(i - page) > 2 && i !== 1 && i !== pages) {
                if (i === 2 || i === pages - 1) { add('…', page, true, false); }
                continue;
            }
            add(String(i), i, false, i === page);
        }
        add('Next', page + 1, page === pages, false);
    }

    function onChange() { page = 1; render(); }

    if (search) { search.addEventListener('input', onChange); }
    if (venueSel) { venueSel.addEventListener('change', onChange); }
    if (statusSel) { statusSel.addEventListener('change', function () { status = statusSel.value; onChange(); }); }
    if (outcomeSel) { outcomeSel.addEventListener('change', onChange); }
    if (competitionSel) { competitionSel.addEventListener('change', onChange); }
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (search) { search.value = ''; }
            if (venueSel) { venueSel.value = 'all'; }
            if (statusSel) { statusSel.value = 'all'; }
            if (outcomeSel) { outcomeSel.value = 'all'; }
            if (competitionSel) { competitionSel.value = 'all'; }
            status = 'all';
            onChange();
        });
    }

    document.querySelectorAll('.match-row[data-href]').forEach(function (row) {
        row.addEventListener('click', function (e) {
            if (e.target.closest('a,button,input,select,label')) { return; }
            window.location.assign(row.dataset.href);
        });
        row.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') { return; }
            e.preventDefault();
            window.location.assign(row.dataset.href);
        });
    });

    render();
}());
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
