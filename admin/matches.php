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

$nextMatchFixtureId = 0;
if (!$allSeasons) {
    try {
        $nextStmt = $pdo->prepare("SELECT id FROM match_fixtures WHERE season_id = :s AND match_date >= CURDATE() AND status IN ('scheduled', 'postponed') ORDER BY match_date, kickoff_time LIMIT 1");
        $nextStmt->execute([':s' => (int) $seasonId]);
        $nextMatchFixtureId = (int) $nextStmt->fetchColumn();
    } catch (Throwable $e) {
        $nextMatchFixtureId = 0;
    }
}

$heroActions = [];
if (!$allSeasons) {
    $heroActions[] = ['label' => 'Add match', 'href' => 'match.php?action=new&season_id=' . (int) $seasonId, 'class' => 'btn btn-light btn-sm'];
}

$pageHero = [
    'eyebrow'  => 'Fixture management',
    'title'    => 'Matches',
    'subtitle' => $allSeasons ? 'All seasons' : ('Season: ' . ($season['name'] ?? 'Unknown')),
    'actions'  => $heroActions,
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
?>
<section class="matches-summary" aria-label="Match summary">
    <?php foreach ($metrics as $m): ?>
        <?php $alert = ($m['label'] === 'Outstanding' && $outstanding > 0.004); ?>
        <article class="matches-summary__item<?= $alert ? ' is-alert' : '' ?>">
            <span class="matches-summary__label"><?= h((string) $m['label']) ?></span>
            <strong class="matches-summary__value"><?= h((string) $m['value']) ?></strong>
            <?php if (trim((string) ($m['meta'] ?? '')) !== ''): ?><span class="matches-summary__meta"><?= h((string) $m['meta']) ?></span><?php endif; ?>
        </article>
    <?php endforeach; ?>
</section>
<?php

$colspan = 5 + ($allSeasons ? 1 : 0) + ($showSponsor ? 3 : 0);
?>

<?php if (isset($_GET['gcal_synced'])): ?>
    <div class="alert alert-<?= (int) ($_GET['gcal_failed_count'] ?? 0) > 0 ? 'warning' : 'success' ?>">
        Google Calendar: <?= (int) $_GET['gcal_synced'] ?> fixture(s) synced for this season.
        <?php if ((int) ($_GET['gcal_failed_count'] ?? 0) > 0): ?>
            <?= (int) $_GET['gcal_failed_count'] ?> could not be synced. Check Settings &rarr; Calendar.
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php if (isset($_GET['gcal_failed'])): ?>
    <div class="alert alert-warning">The change was saved, but Google Calendar could not be updated. Check Settings &rarr; Calendar.</div>
<?php endif; ?>

<?php if (isset($_GET['imported'])): ?>
    <div class="alert alert-success">
        Fixtures imported successfully.
        <?php if (isset($_GET['created']) || isset($_GET['updated'])): ?>
            Created <?= (int) ($_GET['created'] ?? 0) ?>, updated <?= (int) ($_GET['updated'] ?? 0) ?>.
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="matches-filters" aria-label="Filter matches">
    <div class="matches-search">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <label class="visually-hidden" for="matchSearch">Search matches</label>
        <input type="search" id="matchSearch" class="form-control" placeholder="Search matches…" autocomplete="off">
    </div>
    <button type="button" class="btn btn-outline-secondary matches-filters__toggle" id="matchFilterToggle" aria-expanded="false" aria-controls="matchFilterPanel">
        <i class="fa-solid fa-sliders" aria-hidden="true"></i> Filters <span class="badge text-bg-dark" id="matchFilterBadge" hidden>0</span>
    </button>
    <div class="matches-filters__panel" id="matchFilterPanel">
        <div class="matches-field">
            <span class="matches-field__label" id="lblView">View</span>
            <div class="matches-seg" role="group" aria-labelledby="lblView" data-target="matchStatusFilter">
                <button type="button" data-value="all" aria-pressed="true">All</button>
                <button type="button" data-value="upcoming" aria-pressed="false">Upcoming</button>
                <button type="button" data-value="played" aria-pressed="false">Finished</button>
            </div>
            <input type="hidden" id="matchStatusFilter" value="all">
        </div>
        <div class="matches-field">
            <span class="matches-field__label" id="lblVenue">Venue</span>
            <div class="matches-seg" role="group" aria-labelledby="lblVenue" data-target="matchVenueFilter">
                <button type="button" data-value="all" aria-pressed="true">All</button>
                <button type="button" data-value="home" aria-pressed="false">Home</button>
                <button type="button" data-value="away" aria-pressed="false">Away</button>
            </div>
            <input type="hidden" id="matchVenueFilter" value="all">
        </div>
        <div class="matches-field">
            <label class="matches-field__label" for="matchOutcomeFilter">Outcome</label>
            <select id="matchOutcomeFilter" class="form-select" aria-label="Match outcome">
                <option value="all">Any outcome</option>
                <option value="W">Wins</option>
                <option value="D">Draws</option>
                <option value="L">Losses</option>
            </select>
        </div>
        <div class="matches-field matches-field--wide">
            <label class="matches-field__label" for="matchCompetitionFilter">Competition</label>
            <select id="matchCompetitionFilter" class="form-select" aria-label="Competition">
                <option value="all">All competitions</option>
                <?php foreach ($competitionOptions as $competition): ?>
                    <option value="<?= h($competition) ?>"><?= h($competition) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <form class="matches-field" method="get" action="matches.php">
            <label class="matches-field__label" for="matchSeason">Season</label>
            <select id="matchSeason" name="season_id" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $allSeasons ? 'selected' : '' ?>>All seasons</option>
                <?php
                $optLine = static function (array $opt) use ($allSeasons, $seasonId): string {
                    return '<option value="' . (int) $opt['id'] . '"' . ((!$allSeasons && (int) $opt['id'] === (int) $seasonId) ? ' selected' : '') . '>'
                        . h((string) $opt['name']) . (!empty($opt['is_current']) ? ' · Current' : '') . (!empty($opt['is_locked']) && empty($opt['is_archived']) ? ' · Locked' : '') . '</option>';
                };
                foreach ($seasonsDesc as $opt) { if (empty($opt['is_archived'])) { echo $optLine($opt); } }
                $archivedOpts = array_filter($seasonsDesc, static fn (array $o): bool => !empty($o['is_archived']));
                if ($archivedOpts): ?>
                    <optgroup label="Archived (history)">
                        <?php foreach ($archivedOpts as $opt) { echo $optLine($opt); } ?>
                    </optgroup>
                <?php endif; ?>
            </select>
            <noscript><button type="submit" class="btn btn-sm btn-outline-secondary mt-1">Go</button></noscript>
        </form>
        <button type="button" class="btn btn-link matches-filters__reset" id="matchFilterReset" hidden>
            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i> Reset
        </button>
    </div>
</section>

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

<div class="card shadow-sm hub-section hub-table-card matches-list">
    <div class="matches-list__head">
        <div>
            <h2 class="matches-list__title"><span id="matchVisibleCount" aria-live="polite"><?= $fixtureCount ?></span> <span id="matchCountLabel"><?= $fixtureCount === 1 ? 'match' : 'matches' ?></span></h2>
            <span class="matches-list__hint">Next 5 upcoming, then results</span>
        </div>
        <div class="matches-list__tools">
            <?php if ($showSponsor && $rowsVm): ?>
                <details class="matches-key">
                    <summary><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Key</summary>
                    <div class="matches-key__panel" aria-label="Sponsorship payment key">
                        <span><span class="fixtures-sponsorship-status is-available is-compact"><i class="fa-solid fa-minus" aria-hidden="true"></i></span>Available</span>
                        <span><span class="fixtures-sponsorship-status is-unpaid is-compact"><i class="fa-solid fa-sterling-sign" aria-hidden="true"></i></span>Unpaid</span>
                        <span><span class="fixtures-sponsorship-status is-partial is-compact"><i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i></span>Part paid</span>
                        <span><span class="fixtures-sponsorship-status is-paid is-compact"><i class="fa-solid fa-check" aria-hidden="true"></i></span>Paid</span>
                        <span><span class="fixtures-sponsorship-status is-complimentary is-compact"><i class="fa-solid fa-gift" aria-hidden="true"></i></span>Complimentary</span>
                    </div>
                </details>
            <?php endif; ?>
            <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">More</button>
                <div class="dropdown-menu dropdown-menu-end">
                    <?php if (!$allSeasons && $nextMatchFixtureId > 0): ?>
                        <a class="dropdown-item" href="match_next_match.php?fixture_id=<?= $nextMatchFixtureId ?>&amp;season_id=<?= (int) $seasonId ?>"><i class="fa-solid fa-image" aria-hidden="true"></i>Next match graphic</a>
                    <?php endif; ?>
                    <?php if (!$allSeasons): ?>
                        <button class="dropdown-item" type="button" data-bs-toggle="modal" data-bs-target="#importFixturesModal"><i class="fa-solid fa-upload" aria-hidden="true"></i>Import fixtures</button>
                        <a class="dropdown-item" href="monthly_fixtures.php?season_id=<?= (int) $seasonId ?>"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i>Monthly fixtures</a>
                        <a class="dropdown-item" href="match_fixtures_poster.php?season_id=<?= (int) $seasonId ?>"><i class="fa-solid fa-image" aria-hidden="true"></i>Fixture poster</a>
                    <?php endif; ?>
                    <?php if (!$allSeasons && $season !== null && (int) ($season['is_current'] ?? 0) === 1): ?>
                        <form method="post" action="matches_calendar_sync.php" onsubmit="return confirm('Add or update every fixture in this season on the club Google Calendar?');">
                            <?= csrf_field() ?>
                            <button class="dropdown-item" type="submit"><i class="fa-brands fa-google" aria-hidden="true"></i>Sync season to Google Calendar</button>
                        </form>
                    <?php endif; ?>
                    <a class="dropdown-item" href="seasons.php"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i>Seasons</a>
                </div>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (!$rowsVm): ?>
            <div class="alert alert-info mb-0 hub-empty-state">No matches recorded for this selection.</div>
        <?php else: ?>
            <?php
            $compCounts = [];
            foreach ($rowsVm as $cv) { $cn = trim((string) ($cv['f']['competition'] ?? '')); if ($cn !== '') { $compCounts[$cn] = ($compCounts[$cn] ?? 0) + 1; } }
            arsort($compCounts);
            $mainComp = (string) (array_key_first($compCounts) ?? '');
            /** One row/card, shared desktop + mobile. */
            $renderMatch = function (array $vm, string $mode) use (
                $seasonId, $allSeasons, $showSponsor, $fixtureStatusClass, $outcomeChipClass,
                $matchSponsorshipStateClass, $matchSponsorshipStateIcon, $matchSponsorshipStateLabel, $mainComp
            ): void {
                $f = $vm['f'];
                $o = $vm['o'];
                $id = (int) $f['id'];
                $isHome = !empty($f['is_home']);
                $deleted = isDeletedOpponentLabel((string) ($f['opponent'] ?? ''));
                $ts = strtotime((string) $f['match_date']);
                $dateStr = date('d/m/Y', $ts);
                $ko = !empty($f['kickoff_time']) ? substr((string) $f['kickoff_time'], 0, 5) : '';
                $comp = trim((string) ($f['competition'] ?? ''));
                $stage = trim((string) ($f['competition_stage'] ?? ''));
                $compLine = $comp . ($comp !== '' && $stage !== '' ? ' · ' : '') . $stage;
                $seasonName = (string) ($f['season_name'] ?? '');
                $statusRaw = (string) ($f['status'] ?? '');
                $statusKey = strtolower(trim($statusRaw));
                $isPlainScheduled = in_array($statusKey, ['', 'scheduled', 'upcoming'], true);
                // Anything other than scheduled or finished (postponed, cancelled, ...) needs action, so it gets flagged.
                $flagClass = ($isPlainScheduled || in_array($statusKey, ['played', 'finished', 'complete', 'completed'], true)) ? '' : ' is-flagged';
                $search = strtolower(implode(' ', array_filter([
                    (string) ($f['opponent'] ?? ''), $comp, $stage, $seasonName, $statusRaw,
                    (string) $f['match_date'], $dateStr, $isHome ? 'home' : 'away',
                ])));
                $href = 'match.php?id=' . $id . '&season_id=' . ($allSeasons ? (int) $f['season_id'] : (int) $seasonId);
                $opp = $deleted ? '<em class="text-muted">Team deleted</em>' : h((string) $f['opponent']);

                $scoreCell = $o['played'] && $o['us'] !== null
                    ? '<span class="match-score">' . ($isHome ? (int) $o['us'] : (int) $o['them']) . '&ndash;' . ($isHome ? (int) $o['them'] : (int) $o['us']) . '</span>'
                      . ' <span class="' . $outcomeChipClass($o['outcome']) . '">' . $o['outcome'] . '</span>'
                    : ($isPlainScheduled
                        ? ''
                        : '<span class="badge ' . h($fixtureStatusClass($statusRaw)) . '">' . h(ucfirst($statusRaw)) . '</span>');

                $data = 'data-match-id="' . $id . '" data-status="' . ($o['played'] ? 'played' : 'upcoming') . '"'
                    . ' data-venue="' . ($isHome ? 'home' : 'away') . '" data-outcome="' . h($o['outcome']) . '"'
                    . ' data-competition="' . h($comp) . '" data-search="' . h($search) . '"';

                if ($mode === 'mobile'):
                    $needsAttention = false;
                    if ($showSponsor && !$o['played']) {
                        foreach ([$vm['dayRow'], $vm['ballRow'], $vm['motmRow']] as $sr) {
                            if ($sr !== null && (int) ($sr['is_complimentary'] ?? 0) !== 1
                                && (float) ($sr['paid_total'] ?? 0) + 0.0001 < (float) ($sr['amount'] ?? 0)) { $needsAttention = true; }
                        }
                    }
                    $showComp = $compLine !== '' && ($allSeasons || $comp !== $mainComp);
                    ?>
                    <a class="matches-card js-match-item<?= $flagClass ?>" href="<?= h($href) ?>" <?= $data ?>>
                        <span class="matches-card__date" aria-label="<?= h(date('l j F Y', $ts)) ?>">
                            <strong><?= h(date('j', $ts)) ?></strong>
                            <span><?= h(date('M', $ts)) ?></span>
                        </span>
                        <span class="matches-card__main">
                            <span class="matches-card__row">
                                <span class="matches-card__opp"><?= $opp ?></span>
                                <?php if ($scoreCell !== ''): ?><span class="matches-card__score"><?= $scoreCell ?></span><?php endif; ?>
                            </span>
                            <span class="matches-card__meta">
                                <?= $isHome ? 'Home' : 'Away' ?><?= $ko ? ' · ' . h(date('D', $ts)) . ' ' . h($ko) : '' ?><?= ($allSeasons && $seasonName !== '') ? ' · ' . h($seasonName) : '' ?>
                            </span>
                            <?php if ($showComp): ?><span class="matches-card__comp"><?= h($compLine) ?></span><?php endif; ?>
                            <?php if ($needsAttention): ?><span class="matches-card__flag"><i class="fa-solid fa-sterling-sign" aria-hidden="true"></i> Sponsor payment due</span><?php endif; ?>
                        </span>
                    </a>
                    <?php
                else:
                    ?>
                    <tr class="match-row js-match-item<?= $flagClass ?>" role="link" tabindex="0" data-href="<?= h($href) ?>" <?= $data ?>>
                        <td class="text-center">
                            <span class="matches-venue-icon <?= $isHome ? 'is-home' : 'is-away' ?>" title="<?= $isHome ? 'Home' : 'Away' ?>"><i class="fa-solid <?= $isHome ? 'fa-house' : 'fa-bus' ?>" aria-hidden="true"></i><span class="visually-hidden"><?= $isHome ? 'Home' : 'Away' ?></span></span>
                        </td>
                        <td class="nowrap">
                            <span class="match-date"><?= h(date('j M Y', $ts)) ?></span>
                            <div class="small text-muted"><?= h(date('D', $ts)) ?><?= $ko ? ' · ' . h($ko) : '' ?></div>
                        </td>
                        <td class="match-opp-cell">
                            <span class="fw-semibold"><?= $opp ?></span>
                            <?php if ($compLine !== ''): ?><div class="small text-muted match-comp-line" title="<?= h($compLine) ?>"><?= h($compLine) ?></div><?php endif; ?>
                        </td>
                        <?php if ($allSeasons): ?><td class="nowrap small text-muted"><?= h($seasonName) ?></td><?php endif; ?>
                        <td class="nowrap"><?= $scoreCell !== '' ? $scoreCell : '<span class="text-muted">—</span>' ?></td>
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
            $groupLabels = ['upcoming' => 'Upcoming', 'played' => 'Results'];
                        ?>

            <?php
            $blocks = ['upcoming' => [], 'played' => []];
            foreach ($rowsVm as $vm) { $blocks[$vm['o']['played'] ? 'played' : 'upcoming'][] = $vm; }
            $blockSize = ['upcoming' => 5, 'played' => 10];
            foreach ($blocks as $grp => $groupRows):
                if (!$groupRows) { continue; }
            ?>
            <section class="matches-block" data-group="<?= $grp ?>" data-size="<?= $blockSize[$grp] ?>" aria-labelledby="matchesHead-<?= $grp ?>">
                <h3 class="matches-group" id="matchesHead-<?= $grp ?>"><?= h($groupLabels[$grp]) ?> <span class="matches-block__count"><?= count($groupRows) ?></span></h3>

                <div class="d-lg-none matches-mobile-list">
                    <?php foreach ($groupRows as $vm) { $renderMatch($vm, 'mobile'); } ?>
                </div>

                <div class="d-none d-lg-block table-responsive">
                    <table class="table hub-data-table align-middle matches-table">
                        <thead>
                            <tr>
                                <th class="text-center c-venue"><span class="visually-hidden">Venue</span></th>
                                <th class="c-date">Date</th>
                                <th>Opponent</th>
                                <?php if ($allSeasons): ?><th class="c-season">Season</th><?php endif; ?>
                                <th class="c-result">Result</th>
                                <?php if ($showSponsor): ?>
                                    <th class="text-center c-spons">Match Day</th>
                                    <th class="text-center c-spons">Match Ball</th>
                                    <th class="text-center c-spons">MOTM</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groupRows as $vm) { $renderMatch($vm, 'desktop'); } ?>
                        </tbody>
                    </table>
                </div>

                <nav class="matches-pagination" aria-label="<?= h($groupLabels[$grp]) ?> pages" hidden>
                    <ul class="pagination pagination-sm justify-content-center mb-0"></ul>
                </nav>
            </section>
            <?php endforeach; ?>

            <div class="hub-empty-state matches-filter-empty" id="matchesFilterEmpty" hidden>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <strong>No matches for these filters</strong>
                <span>Try a different search or filter.</span>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Summary strip */
.matches-summary { display:grid; grid-auto-flow:column; grid-auto-columns:minmax(0,1fr); gap:.6rem; margin:0 0 1rem; }
.matches-summary__item { display:flex; flex-direction:column; gap:.05rem; min-width:0; padding:.6rem .85rem; background:var(--hub-surface,#fffdf9); border:1px solid var(--hub-line,rgba(75,8,24,.12)); border-radius:var(--hub-radius-sm,.7rem); }
.matches-summary__label { font-size:.66rem; font-weight:800; letter-spacing:.07em; text-transform:uppercase; color:var(--hub-muted,#70676b); }
.matches-summary__value { font-size:1.35rem; line-height:1.15; font-weight:800; color:var(--hub-ink,#1f1a1d); font-variant-numeric:tabular-nums; }
.matches-summary__meta { font-size:.72rem; color:var(--hub-muted,#70676b); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.matches-summary__item.is-alert .matches-summary__value { color:#a32a3a; }

/* Filters */
.matches-filters { display:flex; flex-wrap:wrap; align-items:flex-end; gap:.7rem 1rem; padding:.85rem 1rem; margin-bottom:1rem; background:var(--hub-surface,#fffdf9); border:1px solid var(--hub-line,rgba(75,8,24,.12)); border-radius:var(--hub-radius,1rem); box-shadow:var(--hub-shadow-sm,none); }
.matches-search { position:relative; flex:1 1 16rem; min-width:12rem; }
.matches-search i { position:absolute; left:.8rem; top:50%; transform:translateY(-50%); color:var(--hub-muted,#70676b); font-size:.85rem; pointer-events:none; }
.matches-search input { padding-left:2.3rem; }
.matches-filters .matches-filters__toggle { display:none; }
.matches-filters__panel { display:flex; flex-wrap:wrap; align-items:flex-end; gap:.7rem 1rem; }
.matches-field { display:flex; flex-direction:column; gap:.25rem; margin:0; }
.matches-field__label { font-size:.66rem; font-weight:800; letter-spacing:.07em; text-transform:uppercase; color:var(--hub-muted,#70676b); }
.matches-field select { min-width:8.5rem; }
.matches-field--wide select { min-width:12rem; max-width:17rem; }
.matches-seg { display:inline-flex; padding:.15rem; gap:.1rem; background:rgba(75,8,24,.06); border-radius:.65rem; }
.matches-seg button { border:0; background:transparent; padding:.38rem .8rem; border-radius:.5rem; font-size:.85rem; font-weight:600; color:var(--hub-ink,#1f1a1d); line-height:1.2; white-space:nowrap; }
.matches-seg button[aria-pressed="true"] { background:#fff; color:#6a2036; box-shadow:0 1px 3px rgba(31,26,29,.18); }
.matches-seg button:focus-visible { outline:2px solid #6a2036; outline-offset:1px; }
.matches-filters__reset { align-self:flex-end; padding:.4rem .2rem; color:#6a2036; text-decoration:none; font-weight:600; font-size:.85rem; }

/* List card */
.matches-list__head { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.9rem 1.1rem .2rem; }
.matches-list__title { font-size:1.05rem; font-weight:800; margin:0; color:var(--hub-ink,#1f1a1d); }
.matches-list__hint { font-size:.78rem; color:var(--hub-muted,#70676b); }
.matches-list__tools { display:flex; align-items:center; gap:.5rem; }
.matches-key { position:relative; }
.matches-key summary { white-space:nowrap; display:inline-flex; align-items:center; gap:.35rem; list-style:none; cursor:pointer; padding:.28rem .65rem; border:1px solid var(--hub-line-strong,rgba(75,8,24,.2)); border-radius:.5rem; font-size:.8rem; font-weight:600; color:var(--hub-muted,#70676b); background:#fff; }
.matches-key summary::-webkit-details-marker { display:none; }
.matches-key__panel { position:absolute; right:0; top:calc(100% + .4rem); z-index:20; display:flex; flex-direction:column; gap:.45rem; min-width:12rem; padding:.8rem .9rem; background:#fff; border:1px solid var(--hub-line-strong,rgba(75,8,24,.2)); border-radius:.7rem; box-shadow:0 .6rem 1.4rem rgba(31,26,29,.14); font-size:.82rem; }
.matches-key__panel > span { display:flex; align-items:center; gap:.5rem; }

/* Rows */
.matches-venue-icon { display:inline-grid; place-items:center; width:1.9rem; height:1.9rem; border-radius:50%; font-size:.78rem; }
.matches-venue-icon.is-home { background:rgba(106,32,54,.10); color:#6a2036; }
.matches-venue-icon.is-away { background:rgba(0,0,0,.06); color:#555; }
.matches-table { table-layout:fixed; }
.matches-table .c-venue { width:4rem; }
.matches-table .c-date { width:9.5rem; }
.matches-table .c-season { width:8rem; }
.matches-table .c-result { width:8rem; }
.matches-table .c-spons { width:7.5rem; }
.matches-table td { vertical-align:middle; }
.matches-table thead th { font-size:.7rem; letter-spacing:.06em; text-transform:uppercase; color:var(--hub-muted,#70676b); }
.match-row { cursor:pointer; }
.match-row:hover td { background:rgba(106,32,54,.045); }
.match-row:focus-visible { outline:2px solid #6a2036; outline-offset:-2px; }
.match-date { font-weight:600; font-variant-numeric:tabular-nums; }
.match-opp-cell { max-width:34rem; }
.match-comp-line { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:32rem; }
.match-score { font-weight:700; font-variant-numeric:tabular-nums; }
.match-outcome { display:inline-grid; place-items:center; min-width:1.4rem; height:1.4rem; padding:0 .3rem; border-radius:.35rem; font-size:.72rem; font-weight:800; color:#fff; }
.match-outcome--w { background:#2f7d55; }
.match-outcome--d { background:#8a8a8a; }
.match-outcome--l { background:#b23b3b; }
.matches-table tr.matches-group th, .matches-group { font-size:.7rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:#6a2036; background:rgba(106,32,54,.06); padding:.45rem .75rem; }
.matches-block + .matches-block { margin-top:1.4rem; padding-top:1rem; border-top:1px solid var(--hub-line,#eadfdf); }
.matches-block[hidden] { display:none; }
h3.matches-group { display:flex; align-items:center; gap:.5rem; margin:0 0 .5rem; border-radius:.5rem; font-size:.78rem; }
.matches-block__count { display:inline-grid; place-items:center; min-width:1.5rem; padding:0 .4rem; height:1.25rem; border-radius:999px; background:#6a2036; color:#fff; font-size:.7rem; letter-spacing:0; }
.matches-pagination { margin-top:.8rem; }

/* Mobile cards */
.matches-mobile-list { display:flex; flex-direction:column; gap:.55rem; }
.matches-card { display:flex; align-items:center; gap:.8rem; padding:.65rem .8rem; border:1px solid var(--hub-line,#e6dede); border-radius:.8rem; text-decoration:none; color:inherit; background:#fff; }
.matches-card:hover, .matches-card:focus-visible { border-color:#6a2036; }
.matches-card[hidden] { display:none; }
.matches-card.is-flagged { background:#fff3cd; border-color:#e0b64a; }
.match-row.is-flagged > td { background-color:#fff3cd; }
.matches-card__date { flex:0 0 2.9rem; display:flex; flex-direction:column; align-items:center; line-height:1.05; padding:.4rem 0; border-radius:.6rem; background:rgba(106,32,54,.07); color:#6a2036; }
.matches-card__date strong { font-size:1.25rem; font-weight:800; }
.matches-card__date span { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; }
.matches-card__main { flex:1; min-width:0; display:flex; flex-direction:column; gap:.15rem; }
.matches-card__row { display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
.matches-card__opp { font-weight:700; min-width:0; overflow-wrap:anywhere; }
.matches-card__score { white-space:nowrap; flex:none; }
.matches-card__meta { font-size:.8rem; color:var(--hub-muted,#70676b); }
.matches-card__comp { font-size:.76rem; color:var(--hub-muted,#70676b); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.matches-card__flag { align-self:flex-start; margin-top:.2rem; padding:.1rem .5rem; border-radius:999px; background:rgba(178,59,59,.1); color:#a32a3a; font-size:.72rem; font-weight:700; }
.matches-filter-empty { text-align:center; padding:2rem 1rem; }

@media (max-width: 991.98px) {
    .matches-summary { display:flex; overflow-x:auto; scroll-snap-type:x proximity; padding-bottom:.35rem; margin-inline:-.1rem; scrollbar-width:none; }
    .matches-summary::-webkit-scrollbar { display:none; }
    .matches-summary__item { flex:0 0 8.4rem; scroll-snap-align:start; }
    .matches-filters { padding:.7rem; }
    .matches-search { flex:1 1 0; min-width:0; }
    .matches-filters .matches-filters__toggle { display:inline-flex; align-items:center; gap:.4rem; flex:none; }
    .matches-filters__panel { display:none; flex:1 0 100%; flex-direction:column; align-items:stretch; padding-top:.7rem; border-top:1px solid var(--hub-line,#eadfdf); }
    .matches-filters__panel.is-open { display:flex; }
    .matches-field select, .matches-field--wide select { width:100%; max-width:none; }
    .matches-seg { display:flex; }
    .matches-seg button { flex:1; padding:.5rem .4rem; }
    .matches-filters__reset { align-self:flex-start; }
    .matches-list__head { padding:.8rem .85rem .1rem; }
    .hub-table-card.matches-list > .card-body { padding:.7rem; }
}
</style>

<script>
(function () {
    var blocks = Array.prototype.slice.call(document.querySelectorAll('.matches-block'));
    if (!blocks.length) { return; }

    var search = document.getElementById('matchSearch');
    var venueSel = document.getElementById('matchVenueFilter');
    var statusSel = document.getElementById('matchStatusFilter');
    var outcomeSel = document.getElementById('matchOutcomeFilter');
    var competitionSel = document.getElementById('matchCompetitionFilter');
    var resetBtn = document.getElementById('matchFilterReset');
    var countEl = document.getElementById('matchVisibleCount');
    var countLabel = document.getElementById('matchCountLabel');
    var emptyEl = document.getElementById('matchesFilterEmpty');
    var toggleBtn = document.getElementById('matchFilterToggle');
    var panel = document.getElementById('matchFilterPanel');
    var badge = document.getElementById('matchFilterBadge');

    var pages = {};
    blocks.forEach(function (b) { pages[b.dataset.group] = 1; });

    function norm(v) { return String(v || '').toLowerCase().trim(); }
    function val(el) { return el ? el.value : 'all'; }

    function matches(el) {
        var q = norm(search && search.value);
        var status = val(statusSel), venue = val(venueSel), outcome = val(outcomeSel), competition = val(competitionSel);
        if (status !== 'all' && el.dataset.status !== status) { return false; }
        if (venue !== 'all' && el.dataset.venue !== venue) { return false; }
        if (outcome !== 'all' && el.dataset.outcome !== outcome) { return false; }
        if (competition !== 'all' && el.dataset.competition !== competition) { return false; }
        if (q && el.dataset.search.indexOf(q) === -1) { return false; }
        return true;
    }

    function syncSegments() {
        document.querySelectorAll('.matches-seg').forEach(function (seg) {
            var input = document.getElementById(seg.dataset.target);
            seg.querySelectorAll('button').forEach(function (b) {
                b.setAttribute('aria-pressed', input && b.dataset.value === input.value ? 'true' : 'false');
            });
        });
    }

    function renderPager(block, totalPages) {
        var pager = block.querySelector('.matches-pagination');
        var ul = pager.querySelector('ul');
        var g = block.dataset.group;
        ul.innerHTML = '';
        if (totalPages <= 1) { pager.hidden = true; return; }
        pager.hidden = false;
        function add(label, target, disabled, current) {
            var li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (current ? ' active' : '');
            var b = document.createElement('button');
            b.type = 'button'; b.className = 'page-link'; b.textContent = label;
            if (!disabled && !current) {
                b.addEventListener('click', function () {
                    pages[g] = target; render();
                    block.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            }
            li.appendChild(b); ul.appendChild(li);
        }
        var cur = pages[g];
        add('Prev', cur - 1, cur === 1, false);
        for (var i = 1; i <= totalPages; i++) {
            if (totalPages > 9 && Math.abs(i - cur) > 2 && i !== 1 && i !== totalPages) {
                if (i === 2 || i === totalPages - 1) { add('…', cur, true, false); }
                continue;
            }
            add(String(i), i, false, i === cur);
        }
        add('Next', cur + 1, cur === totalPages, false);
    }

    function render() {
        var grandTotal = 0;
        blocks.forEach(function (block) {
            var g = block.dataset.group;
            var size = parseInt(block.dataset.size, 10) || 10;
            var rows = Array.prototype.slice.call(block.querySelectorAll('tbody .js-match-item'));
            var visibleIds = rows.filter(matches).map(function (el) { return el.dataset.matchId; });
            var total = visibleIds.length;
            grandTotal += total;
            var totalPages = Math.max(1, Math.ceil(total / size));
            if (pages[g] > totalPages) { pages[g] = totalPages; }
            var start = (pages[g] - 1) * size;
            var onPage = {};
            visibleIds.slice(start, start + size).forEach(function (id) { onPage[id] = true; });

            block.querySelectorAll('.js-match-item').forEach(function (el) {
                el.hidden = !(matches(el) && onPage[el.dataset.matchId]);
            });
            var c = block.querySelector('.matches-block__count');
            if (c) { c.textContent = String(total); }
            block.hidden = total === 0;
            renderPager(block, totalPages);
        });

        if (countEl) { countEl.textContent = String(grandTotal); }
        if (countLabel) { countLabel.textContent = grandTotal === 1 ? 'match' : 'matches'; }
        if (emptyEl) { emptyEl.hidden = grandTotal !== 0; }

        var n = 0;
        if (val(statusSel) !== 'all') { n++; }
        if (val(venueSel) !== 'all') { n++; }
        if (val(outcomeSel) !== 'all') { n++; }
        if (val(competitionSel) !== 'all') { n++; }
        var active = n > 0 || !!(search && search.value);
        if (resetBtn) { resetBtn.hidden = !active; }
        if (badge) { badge.textContent = String(n); badge.hidden = n === 0; }
    }

    function onChange() { Object.keys(pages).forEach(function (k) { pages[k] = 1; }); syncSegments(); render(); }

    if (search) { search.addEventListener('input', onChange); }
    if (outcomeSel) { outcomeSel.addEventListener('change', onChange); }
    if (competitionSel) { competitionSel.addEventListener('change', onChange); }
    document.querySelectorAll('.matches-seg button').forEach(function (b) {
        b.addEventListener('click', function () {
            var input = document.getElementById(b.closest('.matches-seg').dataset.target);
            if (input) { input.value = b.dataset.value; }
            onChange();
        });
    });
    if (toggleBtn && panel) {
        toggleBtn.addEventListener('click', function () {
            var open = panel.classList.toggle('is-open');
            toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (search) { search.value = ''; }
            if (venueSel) { venueSel.value = 'all'; }
            if (statusSel) { statusSel.value = 'all'; }
            if (outcomeSel) { outcomeSel.value = 'all'; }
            if (competitionSel) { competitionSel.value = 'all'; }
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
