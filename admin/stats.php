<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/player_match_stats.php';
require_once __DIR__ . '/lib/opposition_report.php';
$pageStyles = ['player-sponsors-match-player-of-match.css', 'reports.css'];
$pageHero = ['eyebrow' => 'Matchday', 'title' => 'Stats', 'subtitle' => 'Team performance, player contributions and match breakdowns.', 'actions' => []];
require __DIR__ . '/header.php';
$seasonId = (int)$seasonContext['season_id'];
$tab = is_string($_GET['tab'] ?? null) && in_array($_GET['tab'], ['team', 'players', 'matches', 'potm', 'compare', 'opposition', 'league', 'fixtures', 'finder', 'competitions', 'seasons', 'career', 'records', 'teamstats', 'referees', 'player'], true) ? $_GET['tab'] : 'team';
// Tabs rendered by stats_extra.php; they bring their own filters instead of the shared Season/Competition/Venue card.
$extraTabs = ['league', 'fixtures', 'finder', 'competitions', 'seasons', 'career', 'records', 'teamstats'];
$competition = is_string($_GET['competition'] ?? null) ? $_GET['competition'] : '';
$venue = is_string($_GET['venue'] ?? null) && in_array($_GET['venue'], ['home', 'away'], true) ? $_GET['venue'] : '';
// Team / Player / Match / POTM stats only cover the current season, and default to League fixtures only.
$currentOnlyTabs = ['team', 'players', 'player', 'matches', 'potm'];
$currentOnly = in_array($tab, $currentOnlyTabs, true);
if ($currentOnly) {
    foreach ($seasonContext['seasons'] as $s) {
        if (!empty($s['is_current'])) { $seasonId = (int)$s['id']; break; }
    }
}
$leagueCompetitionNames = array_map('strval', $pdo->query('SELECT name FROM match_competitions WHERE is_league = 1')->fetchAll(PDO::FETCH_COLUMN));
$stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE season_id = ? AND status = ? ORDER BY match_date ASC, kickoff_time ASC, id ASC');
$stmt->execute([$seasonId, 'played']);
$allFixtures = $stmt->fetchAll(PDO::FETCH_ASSOC);
$competitions = array_values(array_unique(array_filter(array_column($allFixtures, 'competition'))));
sort($competitions);
// Competition scope buttons: League / Cups / Both (league + cups; friendlies are left out).
$competition = match ($competition) { 'cups' => 'cups', 'both', 'all' => 'both', default => 'league' };
$friendlyNames = array_map('strval', $pdo->query("SELECT name FROM match_competitions WHERE competition_type = 'friendly'")->fetchAll(PDO::FETCH_COLUMN));
$inCompetitionScope = static function (string $name) use ($competition, $leagueCompetitionNames, $friendlyNames): bool {
    if (in_array($name, $friendlyNames, true) || stripos($name, 'friendly') !== false) return false;
    $isLeague = in_array($name, $leagueCompetitionNames, true);
    return $competition === 'both' || ($competition === 'league' ? $isLeague : !$isLeague);
};
$fixtures = array_values(array_filter($allFixtures, static fn(array $f): bool => $inCompetitionScope((string)($f['competition'] ?? '')) && ($venue === '' || (int)$f['is_home'] === ($venue === 'home' ? 1 : 0))));
$eventsByFixture = [];
$dataFile = __DIR__ . '/data/matches.json';
$eventsAvailable = false;
if (is_readable($dataFile)) {
    $records = json_decode((string)file_get_contents($dataFile), true);
    $eventsAvailable = is_array($records);
    foreach (is_array($records) ? $records : [] as $record) {
        if (is_array($record) && isset($record['id'])) $eventsByFixture[(int)$record['id']] = array_values(array_filter(is_array($record['events'] ?? null) ? $record['events'] : [], 'is_array'));
    }
}
$summary = hub_stats_summary($fixtures);
// --- Year-on-year comparison (tab=compare) ------------------------------------
// The heavy lifting lives in lib/stats.php (hub_stats_compare_build) so the
// A4 export (stats_compare_pdf.php) reuses exactly the same model.
$pmetricLabels = hub_stats_compare_metric_labels();
$seasonById = [];
foreach ($seasonContext['seasons'] as $s) {
    $seasonById[(int)$s['id']] = $s;
}
$cmp = null;
$compareSeasonIds = [];
$selectedCompetitions = [];
$compareMetrics = ['goals'];
// Every season's competitions, keyed by season_id, so the "Competitions" picker can
// filter itself in JS as seasons are ticked/unticked, without a page round-trip.
// Shared by the Compare tab and the Players tab's own multi-season picker below.
$competitionsBySeasonId = [];
foreach ($pdo->query(
    "SELECT DISTINCT season_id, competition FROM match_fixtures
     WHERE status = 'played' AND competition IS NOT NULL AND competition <> ''" . hub_stats_not_friendly_sql($pdo)
) as $row) {
    $competitionsBySeasonId[(int)$row['season_id']][] = (string)$row['competition'];
}
$allCompareCompetitions = [];
foreach ($competitionsBySeasonId as $seasonComps) {
    foreach ($seasonComps as $c) {
        if (!in_array($c, $allCompareCompetitions, true)) $allCompareCompetitions[] = $c;
    }
}
sort($allCompareCompetitions);
if ($tab === 'compare') {
    $reqMetrics = $_GET['pmetric'] ?? null;
    $cmp = hub_stats_compare_build($pdo, $seasonContext['seasons'], [
        'season_ids'        => is_array($_GET['seasons'] ?? null) ? $_GET['seasons'] : [],
        'competitions'      => is_array($_GET['competitions'] ?? null) ? $_GET['competitions'] : [],
        'venue'             => $venue,
        'metrics'           => is_array($reqMetrics) ? $reqMetrics : (is_string($reqMetrics) ? [$reqMetrics] : []),
        'events_by_fixture' => $eventsByFixture,
        'data_file'         => $dataFile,
    ]);
    $compareSeasonIds     = $cmp['season_ids'];
    $selectedCompetitions = $cmp['competitions'];
    $compareMetrics       = $cmp['metrics'];
}

// --- Players tab: independent multi-season/multi-competition filter -----------
// Deliberately separate from $seasonId/$competition (which stay single-select and
// keep driving Team stats/Match stats) — same seasons[]/competitions[] pattern the
// Compare tab already uses, just scoped to whichever seasons/competitions are ticked
// here rather than the whole-site season switcher.
$playersSeasonIds = [];
foreach (is_array($_GET['season_ids'] ?? null) ? $_GET['season_ids'] : [] as $rid) {
    $rid = (int)$rid;
    if ($rid > 0 && !in_array($rid, $playersSeasonIds, true)) $playersSeasonIds[] = $rid;
}
if (!$playersSeasonIds) $playersSeasonIds = [$seasonId];
$playersCompetitions = array_values(array_filter($competitions, $inCompetitionScope));
if (in_array($tab, ['players', 'player'], true)) $playersSeasonIds = [$seasonId];
$playersFixtures = [];
if (in_array($tab, ['players', 'player'], true)) {
    $playersFixtures = hub_stats_player_fixtures($pdo, $playersSeasonIds[0], $playersCompetitions, $venue);
}

$url = static function (array $changes) use ($seasonId, $tab, $competition, $venue, $compareSeasonIds, $selectedCompetitions, $compareMetrics, $playersSeasonIds, $playersCompetitions): string {
    $targetTab = $changes['tab'] ?? $tab;
    $params = ['season_id' => $seasonId, 'tab' => $tab, 'competition' => $competition, 'venue' => $venue];
    if ($targetTab === 'compare') {
        unset($params['competition']);
        $params['seasons'] = $compareSeasonIds ?: [$seasonId];
        $params['competitions'] = $selectedCompetitions;
        $params['pmetric'] = $compareMetrics;
    }
    return '/admin/stats.php?' . http_build_query(array_merge($params, $changes));
};

/** URL to the A4 PDF export, carrying the current compare selection. */
$comparePdfUrl = static function () use ($compareSeasonIds, $selectedCompetitions, $venue, $compareMetrics): string {
    return '/admin/stats_compare_pdf.php?' . http_build_query(array_filter([
        'seasons' => $compareSeasonIds,
        'competitions' => $selectedCompetitions,
        'venue' => $venue,
        'pmetric' => $compareMetrics,
    ]));
};
$esc = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$badge = static fn(string $result): string => ['W' => 'success', 'D' => 'secondary', 'L' => 'danger'][$result] ?? 'secondary';
?>
<div class="container-fluid py-3 rpt-page">
<?php
$statsNav = [
    'Club' => [
        'team' => ['Team stats', 'fa-chart-column', 'Results, goals and form'],
        'players' => ['Player stats', 'fa-person-running', 'Apps, goals and cards'],
        'matches' => ['Match stats', 'fa-futbol', 'Every match, event by event'],
        'potm' => ['POTM', 'fa-award', 'Player of the match awards'],
    ],
    'League' => [
        'league' => ['League & form', 'fa-ranking-star', 'Table, last 3/6/10 form, points graph'],
        'teamstats' => ['Team stat explorer', 'fa-table-cells', '26 team stats: runs, clean sheets, goals, points'],
        'competitions' => ['Competitions', 'fa-trophy', 'League and cup records by competition'],
    ],
    'Fixtures' => [
        'fixtures' => ['Next & latest', 'fa-calendar-day', 'Upcoming fixtures and recent results'],
        'finder' => ['Fixture finder', 'fa-magnifying-glass', 'Search every season by opponent or date'],
    ],
    'History' => [
        'seasons' => ['Season history', 'fa-clock-rotate-left', 'Past season tables and summaries'],
        'compare' => ['Compare seasons', 'fa-code-compare', 'Year-on-year team and player stats'],
        'career' => ['Player careers', 'fa-id-card', 'Season-by-season player record'],
        'records' => ['Records', 'fa-medal', 'Biggest wins, streaks and top scorers'],
    ],
    'Scouting' => [
        'opposition' => ['Opposition reports', 'fa-binoculars', 'Uploaded opponent reports'],
        'referees' => ['Referees', 'fa-user-tie', 'Card rates, record and fairness for each referee'],
    ],
];
// PDF export: the tab's title, and the filters it states (only the tabs that use the shared filter card state them).
$statsTabKey = $tab === 'player' ? 'players' : $tab;
$statsTabLabel = '';
foreach ($statsNav as $statsGroup) {
    if (isset($statsGroup[$statsTabKey])) $statsTabLabel = $statsGroup[$statsTabKey][0];
}
$statsUsesFilters = in_array($tab, $currentOnlyTabs, true);
$statsPdfSummary = implode('  ·  ', array_filter([
    $statsTabLabel,
    $statsUsesFilters ? (string)($seasonById[$seasonId]['name'] ?? '') : '',
    $statsUsesFilters ? (['league' => 'League', 'cups' => 'Cups', 'both' => 'League and cups'][$competition] ?? '') : '',
    $statsUsesFilters ? (['' => 'Home and away', 'home' => 'Home games', 'away' => 'Away games'][$venue] ?? '') : '',
]));
// Players and compare have their own PDF documents; every other tab prints the page as it shows on screen.
$exportPdfUrl = static function () use ($tab, $playersSeasonIds, $playersCompetitions, $venue): string {
    if ($tab === 'players') {
        return '/admin/stats_players_pdf.php?' . http_build_query(['season_id' => $playersSeasonIds[0], 'competitions' => $playersCompetitions, 'venue' => $venue, 'sort' => $_GET['sort'] ?? null, 'direction' => $_GET['direction'] ?? null]);
    }
    return '/admin/stats_pdf.php?' . http_build_query($_GET);
};
?>
<div class="rpt-layout">
    <nav class="rpt-picker" aria-label="Statistics sections">
        <?php foreach ($statsNav as $groupName => $items): ?>
        <div class="rpt-picker__group">
            <div class="rpt-picker__group-label"><?= $esc($groupName) ?></div>
            <?php foreach ($items as $key => [$label, $icon, $desc]): ?>
            <a class="rpt-picker__item <?= ($tab === $key || ($tab === 'player' && $key === 'players')) ? 'is-active' : '' ?>" <?= ($tab === $key || ($tab === 'player' && $key === 'players')) ? 'aria-current="page"' : '' ?> href="<?= $esc($url(['tab' => $key])) ?>">
                <span class="rpt-picker__icon"><i class="fa-solid <?= $esc($icon) ?>" aria-hidden="true"></i></span>
                <span class="rpt-picker__text"><strong><?= $esc($label) ?></strong><small><?= $esc($desc) ?></small></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </nav>
    <div class="rpt-main">
    <?php if (!empty($statsPdfExport)): ?><div class="stats-pdf-summary"><?= $esc($statsPdfSummary) ?></div><?php endif; ?>
    <?php if ($tab !== 'compare'): ?><div class="stats-export-row d-flex justify-content-end mb-3"><a class="btn btn-outline-secondary btn-sm" href="<?= $esc($exportPdfUrl()) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>Export as PDF</a></div><?php endif; ?>
    <?php if ($tab !== 'opposition' && $tab !== 'referees' && !in_array($tab, $extraTabs, true)): ?>
    <form method="get" class="card card-body shadow-sm mb-4<?= $tab === 'compare' ? ' cmp-filter-card' : '' ?>">
        <input type="hidden" name="tab" value="<?= $esc($tab) ?>">
        <?php if ($tab === 'player' && is_string($_GET['player'] ?? null)): ?><input type="hidden" name="player" value="<?= $esc($_GET['player']) ?>"><?php endif; ?>
        <?php if ($tab === 'compare'): ?>
        <?php
        $ddSummary = static function (array $items, string $all, string $word): string {
            $n = count($items);
            if ($n === 0) return $all;
            if ($n <= 2) return implode(', ', $items);
            return $n . ' ' . $word;
        };
        $seasonSummary = $compareSeasonIds
            ? $ddSummary(array_map(static fn($id) => (string)($seasonById[$id]['name'] ?? $id), $compareSeasonIds), 'Choose seasons', 'seasons selected')
            : 'Choose seasons';
        $compSummary = $ddSummary($selectedCompetitions, 'All competitions', 'competitions');
        ?>
        <style>.cmp-filter-card,.cmp-metric-bar{overflow:visible}</style>
        <?php foreach ($compareMetrics as $m): ?><input type="hidden" name="pmetric[]" value="<?= $esc($m) ?>"><?php endforeach; ?>
        <div class="row g-3 align-items-end">
            <div class="col-md-3 hub-checkbox-dropdown-field">
                <label class="form-label" id="cmpSeasonsLabel">Seasons to compare</label>
                <div class="dropdown hub-checkbox-dropdown">
                    <button type="button" class="form-select hub-checkbox-dropdown__toggle" aria-haspopup="true" aria-expanded="false" aria-labelledby="cmpSeasonsLabel">
                        <span class="hub-checkbox-dropdown__summary" data-dd-summary data-dd-all="Choose seasons" data-dd-word="seasons selected"><?= $esc($seasonSummary) ?></span>
                    </button>
                    <div class="dropdown-menu hub-checkbox-dropdown__menu" aria-label="Seasons to compare">
                        <?php foreach (array_reverse($seasonContext['seasons']) as $s): ?>
                            <label class="hub-checkbox-dropdown__item">
                                <input type="checkbox" name="seasons[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $compareSeasonIds, true) ? 'checked' : '' ?>>
                                <span><?= $esc($s['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-3 hub-checkbox-dropdown-field">
                <label class="form-label" id="cmpCompsLabel">Competitions</label>
                <div class="dropdown hub-checkbox-dropdown" id="cmpCompsDropdown">
                    <button type="button" class="form-select hub-checkbox-dropdown__toggle" aria-haspopup="true" aria-expanded="false" aria-labelledby="cmpCompsLabel">
                        <span class="hub-checkbox-dropdown__summary" data-dd-summary data-dd-all="All competitions" data-dd-word="competitions"><?= $esc($compSummary) ?></span>
                    </button>
                    <div class="dropdown-menu hub-checkbox-dropdown__menu" aria-label="Competitions">
                        <span class="hub-checkbox-dropdown__empty" data-cmp-empty-noseasons>Pick seasons first.</span>
                        <span class="hub-checkbox-dropdown__empty" data-cmp-empty-nodata hidden>No competitions recorded for the selected season(s).</span>
                        <?php foreach ($allCompareCompetitions as $c): ?>
                            <?php
                            $ownerSeasonIds = [];
                            foreach ($competitionsBySeasonId as $sid => $seasonComps) {
                                if (in_array($c, $seasonComps, true)) $ownerSeasonIds[] = $sid;
                            }
                            ?>
                            <label class="hub-checkbox-dropdown__item" data-cmp-seasons="<?= $esc(implode(',', $ownerSeasonIds)) ?>">
                                <input type="checkbox" name="competitions[]" value="<?= $esc($c) ?>" <?= in_array($c, $selectedCompetitions, true) ? 'checked' : '' ?>>
                                <span><?= $esc($c) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-2"><label for="stats-venue" class="form-label">Venue</label><select class="form-select" id="stats-venue" name="venue"><option value="">Home &amp; away</option><option value="home" <?= $venue === 'home' ? 'selected' : '' ?>>Home</option><option value="away" <?= $venue === 'away' ? 'selected' : '' ?>>Away</option></select></div>
            <div class="col-md-2 d-grid"><label class="form-label d-none d-md-block">&nbsp;</label><button class="btn btn-primary" type="submit">Compare</button></div>
            <div class="col-md-2 d-grid"><label class="form-label d-none d-md-block">&nbsp;</label><a class="btn btn-outline-secondary" href="<?= $esc($comparePdfUrl()) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>Export as PDF</a></div>
        </div>
        <?php else: ?>
        <style>
.stats-scope .btn{min-width:5.5rem;color:var(--brand-primary,#6a2036);border-color:var(--brand-primary,#6a2036);background:#fff}
.stats-scope .btn:hover{background:var(--brand-light,#f6ecde);color:var(--brand-dark,#4b0818);border-color:var(--brand-primary,#6a2036)}
.stats-scope--events .btn,.stats-scope--venue .btn,.stats-scope--result .btn{min-width:0;padding-left:.55rem;padding-right:.55rem}
.stats-scope .btn-check:checked+.btn i{filter:drop-shadow(0 0 1px #fff)}
.stats-scope .btn-check:checked+.btn,.stats-scope .btn-check:active+.btn{background:var(--brand-primary,#6a2036);border-color:var(--brand-primary,#6a2036);color:#fff}
.stats-scope .btn-check:focus-visible+.btn{box-shadow:0 0 0 .2rem rgba(224,180,42,.45)}
</style>
        <div class="row g-3 align-items-end">
            <div class="col-md-3"><label for="stats-season" class="form-label">Season</label><input type="hidden" name="season_id" value="<?= $seasonId ?>"><input type="text" class="form-control" id="stats-season" value="<?= $esc($seasonById[$seasonId]['name'] ?? '') ?> · Current" readonly></div>
            <div class="col-md-4">
                <span class="form-label d-block" id="stats-scope-label">Competition</span>
                <div class="btn-group stats-scope w-100" role="group" aria-labelledby="stats-scope-label">
                    <?php foreach (['league' => 'League', 'cups' => 'Cups', 'both' => 'Both'] as $k => $label): ?>
                        <input type="radio" class="btn-check" name="competition" id="stats-scope-<?= $k ?>" value="<?= $k ?>" autocomplete="off" <?= $competition === $k ? 'checked' : '' ?> onchange="this.form.submit()">
                        <label class="btn btn-outline-primary" for="stats-scope-<?= $k ?>"><?= $label ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-3">
                <span class="form-label d-block" id="stats-venue-label">Venue</span>
                <div class="btn-group stats-scope w-100" role="group" aria-labelledby="stats-venue-label">
                    <?php foreach (['' => ['Home &amp; away', 'fa-arrows-left-right'], 'home' => ['Home', 'fa-house'], 'away' => ['Away', 'fa-bus']] as $k => [$label, $icon]): ?>
                        <input type="radio" class="btn-check" name="venue" id="stats-venue-<?= $k === '' ? 'both' : $k ?>" value="<?= $k ?>" autocomplete="off" <?= $venue === $k ? 'checked' : '' ?> onchange="this.form.submit()">
                        <label class="btn btn-outline-primary" for="stats-venue-<?= $k === '' ? 'both' : $k ?>" title="<?= $label ?>"><i class="fa-solid <?= $icon ?> me-1" aria-hidden="true"></i><span class="<?= $k === '' ? 'visually-hidden' : '' ?>"><?= $label ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Apply</button></div>
        </div>
        <?php endif; ?>
    </form>
    <?php endif; ?>
    <?php if (!$eventsAvailable && $tab !== 'opposition' && $tab !== 'referees' && !in_array($tab, $extraTabs, true)): ?><div class="alert alert-warning">Match events are currently unavailable. Player goals and cards may be incomplete.</div><?php endif; ?>
    <?php if (in_array($tab, $extraTabs, true)): ?>
        <?php require __DIR__ . '/stats_extra.php'; ?>
    <?php elseif ($tab === 'referees'): ?>
        <?php require __DIR__ . '/stats_referees.php'; ?>
    <?php elseif ($tab === 'opposition'): ?>
        <?php
        $oppUploaded = isset($_GET['uploaded']);
        $oppDeleted = isset($_GET['deleted']);
        $oppError = is_string($_GET['error'] ?? null) ? $_GET['error'] : '';
        $oppErrorMessages = hub_opposition_report_error_messages();
        $oppReports = hub_opposition_report_list();
        ?>
        <?php if ($oppUploaded): ?><div class="alert alert-success">Report uploaded. Use the PDF or PowerPoint icons below to generate it.</div><?php endif; ?>
        <?php if ($oppDeleted): ?><div class="alert alert-success">Report deleted.</div><?php endif; ?>
        <?php if ($oppError !== ''): ?><div class="alert alert-danger"><?= $esc($oppErrorMessages[$oppError] ?? 'Something went wrong.') ?></div><?php endif; ?>

        <div class="card card-body shadow-sm mb-4" id="cometFetchCard">
            <h2 class="h5">Fetch<?php if (($_GET['test'] ?? '') === '1'): ?> <span class="badge text-bg-warning align-middle ms-1">Test mode &mdash; nothing is sent to COMET</span><?php endif; ?></h2>
            <p class="text-muted small">Pulls the team&rsquo;s previous matches, line-ups and events straight from COMET and adds the report below &mdash; no export or upload needed. It takes a few minutes.</p>
            <form id="cometFetchForm" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small" for="cometTeam">Opposition team</label>
                    <select id="cometTeam" name="team" class="form-select" required>
                        <option value="">-- Select team --</option>
                        <?php $cometTeams = ['East Kilbride Thistle', 'Glenvale', 'East Kilbride YM', 'Royal Albert', 'Carluke Rovers', 'West Park United', 'Kello Rovers', 'Glasgow Perthshire', 'Eglinton', 'Vale of Leven', 'Lugar Boswell Thistle', 'Giffnock', 'Newmains United', 'Irvine Victoria', "St. Anthony's"]; sort($cometTeams, SORT_NATURAL | SORT_FLAG_CASE); foreach ($cometTeams as $cometTeam): ?>
                            <option value="<?= $esc($cometTeam) ?>"><?= $esc($cometTeam) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (($_GET['test'] ?? '') === '1'): ?>
                <div class="col-sm-6 col-md-4">
                    <label class="form-label small" for="cometTestScenario">Simulate outcome (test only)</label>
                    <select id="cometTestScenario" class="form-select">
                        <option value="auto">Automatic (updated if already listed, otherwise new)</option>
                        <option value="new">New report added</option>
                        <option value="updated">Updated &mdash; 1 new match</option>
                        <option value="same">Checked &mdash; no new matches</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-auto"><button type="submit" id="cometFetchBtn" class="btn btn-primary"><i class="fa-solid fa-cloud-arrow-down me-1" aria-hidden="true"></i><span>Fetch</span></button></div>
            </form>
            <div id="cometFetchMsg" class="alert d-none align-items-start gap-3 mt-3 mb-0" role="status" aria-live="polite">
                <i id="cometFetchIcon" class="fa-solid fa-circle-notch fa-spin fs-4 mt-1" aria-hidden="true"></i>
                <div class="flex-grow-1">
                    <div id="cometFetchTitle" class="fw-semibold"></div>
                    <div id="cometFetchDetail" class="small"></div>
                    <div class="progress mt-2" style="height:8px" id="cometFetchBarWrap"><div id="cometFetchBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div></div>
                </div>
            </div>
            <details class="mt-3" id="oppUploadDetails"<?= $oppError !== '' ? ' open' : '' ?>>
                <summary class="small text-muted" style="cursor:pointer">Or upload a COMET export manually (optional)</summary>
            <p class="text-muted small mt-2">Drag and drop a COMET matches export (JSON) for an opponent, or click to browse. Once uploaded, download it as a formatted PDF or PowerPoint scouting report below.</p>
            <form id="oppUploadForm" method="post" action="/admin/opposition_report_upload.php" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="upload">
                <input type="file" id="opp-file" name="json_file" accept=".json,application/json" class="visually-hidden" required>
                <div id="oppDropzone" class="opp-dropzone" tabindex="0" role="button" aria-label="Upload a JSON opponent report">
                    <div class="opp-dropzone__icon"><i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i></div>
                    <div class="opp-dropzone__text"><strong>Drag &amp; drop</strong> a JSON file here, or <span class="opp-dropzone__browse">click to browse</span></div>
                </div>
                <div id="oppProgress" class="opp-progress-card" hidden>
                    <div class="opp-progress-card__row">
                        <i id="oppProgressIcon" class="fa-solid fa-file-code opp-progress-card__icon" aria-hidden="true"></i>
                        <div class="opp-progress-card__body">
                            <div id="oppProgressName" class="opp-progress-card__name"></div>
                            <div class="opp-progress-bar"><div id="oppProgressBar" class="opp-progress-bar__fill"></div></div>
                        </div>
                        <span id="oppProgressPct" class="opp-progress-card__pct">0%</span>
                    </div>
                    <div id="oppProgressMsg" class="opp-progress-card__msg"></div>
                </div>
            </form>
            </details>
        </div>
        <script>
        (function () {
            var form = document.getElementById('cometFetchForm'), btn = document.getElementById('cometFetchBtn'), box = document.getElementById('cometFetchMsg');
            var icon = document.getElementById('cometFetchIcon'), title = document.getElementById('cometFetchTitle'), detail = document.getElementById('cometFetchDetail');
            var bar = document.getElementById('cometFetchBar'), barWrap = document.getElementById('cometFetchBarWrap'), btnLabel = btn.querySelector('span'), btnIcon = btn.querySelector('i');
            var timer = null, sawActive = false;
            var KINDS = { working: ['alert-primary', 'fa-circle-notch fa-spin'], success: ['alert-success', 'fa-circle-check'], error: ['alert-danger', 'fa-circle-exclamation'] };
            function show(kind, head, sub, pct) {
                var k = KINDS[kind];
                box.className = 'alert d-flex align-items-start gap-3 mt-3 mb-0 ' + k[0];
                icon.className = 'fa-solid ' + k[1] + ' fs-4 mt-1';
                title.textContent = head; detail.textContent = sub || '';
                barWrap.style.display = kind === 'working' ? '' : 'none';
                bar.style.width = (pct == null ? 100 : pct) + '%';
                bar.className = 'progress-bar progress-bar-striped' + (pct == null ? ' progress-bar-animated' : '');
                box.animate([{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'none' }], { duration: 220, easing: 'ease-out' });
            }
            function busy(on) {
                btn.disabled = on;
                btnLabel.textContent = on ? 'Fetching\u2026' : 'Fetch';
                btnIcon.className = on ? 'fa-solid fa-circle-notch fa-spin me-1' : 'fa-solid fa-cloud-arrow-down me-1';
            }
            function poll() {
                fetch('/admin/opposition_collect.php?action=status', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        var s = d.status; if (!s) { return; }
                        if (s.phase === 'queued') { sawActive = true; busy(true); show('working', 'Request received for ' + s.team, 'Waiting for the server to pick it up \u2014 this takes up to a minute.', 8); }
                        else if (s.phase === 'login' || s.phase === 'matches') { sawActive = true; busy(true); show('working', 'Connecting to COMET\u2026', 'Signing in and finding ' + s.team + '\u2019s previous matches.', 15); }
                        else if (s.phase === 'collecting') { sawActive = true; busy(true); show('working', 'Collecting ' + s.team + ' \u2014 match ' + (s.done + 1) + ' of ' + s.total, s.current, 20 + Math.round(75 * s.done / Math.max(1, s.total))); }
                        else if (s.phase === 'done') { clearInterval(timer); busy(false); if (sawActive) { var dsc = window.oppDescribe(s.team, s.matches != null ? s : null); show('success', dsc.head, dsc.sub); if (window.oppRefreshReports) { window.oppRefreshReports(s.team, dsc.badge); } } }
                        else if (s.phase === 'error') { clearInterval(timer); busy(false); if (sawActive) { show('error', 'Fetch failed', s.message || 'Unknown error. Please try again.'); } }
                    }).catch(function () {});
            }
            function startPolling() { clearInterval(timer); timer = setInterval(poll, 4000); poll(); }
            var TEST = new URLSearchParams(window.location.search).get('test') === '1';
            function addFakeRow(team, scenario) {
                var now = new Date(), mon = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                var date = now.getDate() + ' ' + mon[now.getMonth()] + ' ' + now.getFullYear(), time = ('0' + now.getHours()).slice(-2) + ':' + ('0' + now.getMinutes()).slice(-2);
                var body = document.getElementById('oppReportsBody');
                if (!body) {
                    var empty = document.getElementById('oppEmptyState');
                    if (!empty) { return; }
                    var wrap = document.createElement('div'); wrap.className = 'table-responsive';
                    wrap.innerHTML = '<table class="table align-middle mb-0 hub-data-table"><thead><tr><th scope="col">Opponent</th><th scope="col">Competition</th><th scope="col">Matches</th><th scope="col">Uploaded</th><th scope="col" class="text-end">Actions</th></tr></thead><tbody id="oppReportsBody"></tbody></table>';
                    empty.replaceWith(wrap); body = document.getElementById('oppReportsBody');
                }
                var existing = Array.prototype.find.call(body.rows, function (r) { return r.cells[0].firstChild.textContent.trim().toLowerCase() === team.toLowerCase(); });
                var comp = body.rows.length ? body.rows[0].cells[1].textContent : 'West of Scotland Third Division 26/27';
                var row = existing || body.insertRow(0);
                var dis = function (icon, label) { return '<span class="btn btn-sm btn-outline-secondary disabled" title="' + label + ' (not available in test mode)" aria-label="' + label + '"><i class="fa-solid ' + icon + '" aria-hidden="true"></i></span> '; };
                row.setAttribute('data-test-row', '1');
                row.innerHTML = '<td></td><td></td><td>8</td><td><div>' + date + '</div><div class="text-muted small">' + time + '</div></td><td class="text-end">' + dis('fa-eye', 'Preview report') + dis('fa-file-powerpoint', 'Download PowerPoint') + dis('fa-film', 'Create video') + '<span class="btn btn-sm btn-outline-danger disabled" aria-label="Delete"><i class="fa-solid fa-trash" aria-hidden="true"></i></span></td>';
                row.cells[0].textContent = team + ' '; var b = document.createElement('span'); b.className = 'badge text-bg-warning'; b.textContent = 'Test'; row.cells[0].appendChild(b); var b2 = document.createElement('span'); b2.className = 'badge ms-1 text-bg-success'; var sc = (scenario && scenario !== 'auto') ? scenario : (existing ? 'updated' : 'new'); b2.className = 'badge ms-1 ' + (sc === 'same' ? 'text-bg-secondary' : 'text-bg-success'); b2.textContent = sc === 'new' ? 'New' : (sc === 'same' ? 'Updated \u00b7 no new matches' : 'Updated \u00b7 +1'); row.cells[0].appendChild(b2);
                row.cells[1].textContent = comp;
                row.animate([{ backgroundColor: 'rgba(25,135,84,.35)' }, { backgroundColor: 'rgba(25,135,84,0)' }], { duration: 2600, easing: 'ease-out' });
                row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                return sc;
            }
            function simulate(team) {
                var fixtures = ['Home v ' + team, team + ' v Away', 'Home v ' + team, team + ' v Away', 'Home v ' + team, team + ' v Away', 'Home v ' + team, team + ' v Away'];
                var steps = [[0, 'working', 'Request received for ' + team, 'Waiting for the server to pick it up \u2014 this takes up to a minute.', 8], [2200, 'working', 'Connecting to COMET\u2026', 'Signing in and finding ' + team + '\u2019s previous matches.', 15]];
                fixtures.forEach(function (f, i) { steps.push([4200 + i * 1100, 'working', 'Collecting ' + team + ' \u2014 match ' + (i + 1) + ' of ' + fixtures.length, f + ' (simulated)', 20 + Math.round(75 * i / fixtures.length)]); });
                steps.push([4200 + fixtures.length * 1100, 'success', null, null]);
                steps.forEach(function (st) { setTimeout(function () { show(st[1], st[2], st[3], st[4]); if (st[1] === 'success') { busy(false); var scen = document.getElementById('cometTestScenario'), sc2 = addFakeRow(team, scen ? scen.value : 'auto'); var dd = window.oppDescribe(team, sc2 === 'new' ? { matches: 8, previous: null, added: 8 } : { matches: 8, previous: sc2 === 'same' ? 8 : 7, added: sc2 === 'same' ? 0 : 1 }); show('success', dd.head + ' (test)', dd.sub + ' Simulated \u2014 nothing was requested from COMET or saved.'); return; } }, st[0]); });
            }
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (TEST) { if (!form.team.value) { return; } busy(true); simulate(form.team.value); return; }
                busy(true); show('working', 'Sending request\u2026', '', 4);
                fetch('/admin/opposition_collect.php', { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) { if (d.ok) { sawActive = true; show('working', 'Request received for ' + form.team.value, 'Waiting for the server to pick it up \u2014 this takes up to a minute.', 8); startPolling(); } else { busy(false); show('error', 'Could not start', d.message || 'Please try again.'); } })
                    .catch(function () { busy(false); show('error', 'Could not reach the server', 'Check your connection and try again.'); });
            });
            if (!TEST) fetch('/admin/opposition_collect.php?action=status').then(function (r) { return r.json(); }).then(function (d) {
                var p = d.status && d.status.phase;
                if (p === 'queued' || p === 'login' || p === 'matches' || p === 'collecting') { busy(true); sawActive = true; startPolling(); }
            }).catch(function () {});
        })();
        </script>


        <section class="card shadow-sm" id="oppReportsSection">
            <div class="card-body">
                <h2 class="h5">Uploaded reports</h2>
                <?php if (!$oppReports): ?>
                    <p class="text-muted text-center py-4 mb-0" id="oppEmptyState">No opponent reports uploaded yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 hub-data-table">
                        <thead><tr><th scope="col">Opponent</th><th scope="col">Competition</th><th scope="col">Matches</th><th scope="col">Uploaded</th><th scope="col" class="text-end">Actions</th></tr></thead>
                        <tbody id="oppReportsBody">
                        <?php foreach ($oppReports as $rep): ?>
                            <tr>
                                <td><?= $esc($rep['team'] !== '' ? $rep['team'] : $rep['file']) ?><?php if (!$rep['valid']): ?> <span class="badge text-bg-warning">Unreadable</span><?php endif; ?></td>
                                <td><?= $esc($rep['competition']) ?></td>
                                <td><?= (int)$rep['matches'] ?></td>
                                <td><div><?= $esc(date('j M Y', $rep['modified'])) ?></div><div class="text-muted small"><?= $esc(date('H:i', $rep['modified'])) ?></div></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-primary opp-preview-btn <?= $rep['valid'] ? '' : 'disabled' ?>" data-file="<?= $esc($rep['file']) ?>" title="Preview report" aria-label="Preview report"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i></button>
                                    <a class="btn btn-sm btn-outline-secondary <?= $rep['valid'] ? '' : 'disabled' ?>" href="/admin/opposition_report_pptx.php?file=<?= $esc(rawurlencode($rep['file'])) ?>" title="Download PowerPoint" aria-label="Download PowerPoint"><i class="fa-solid fa-file-powerpoint" aria-hidden="true"></i></a>
                                    <button type="button" class="btn btn-sm btn-outline-secondary opp-video-btn <?= $rep['valid'] ? '' : 'disabled' ?>" data-file="<?= $esc($rep['file']) ?>" title="Create video (MP4)" aria-label="Create video (MP4)"><i class="fa-solid fa-film" aria-hidden="true"></i></button>
                                    <form method="post" action="/admin/opposition_report_upload.php" class="d-inline" data-confirm="Delete this report?" data-confirm-action="Delete">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="file" value="<?= $esc($rep['file']) ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete" aria-label="Delete"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <div id="oppVideoStatus" class="alert alert-info align-items-center gap-2 mt-4 mb-0" role="status" hidden></div>
        <div id="oppPreview" class="card shadow-sm mt-4" hidden>
            <div class="card-body" id="oppPreviewBody"></div>
        </div>
        <script>
        (function () {
            var box = document.getElementById('oppVideoStatus');
            var token = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_UNESCAPED_SLASHES) ?>;
            var timer = null;
            function show(cls, html) {
                box.className = 'alert alert-' + cls + ' d-flex align-items-center gap-2 mt-4 mb-0';
                box.innerHTML = html;
            }
            function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
            function poll(file) {
                fetch('/admin/opposition_report_video.php?action=status&file=' + encodeURIComponent(file), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (st) {
                        if (st.state === 'running') {
                            show('info', '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Creating video&hellip; ' + esc(st.step || '') + ' (about a minute — you can leave this page open)</span>');
                            timer = setTimeout(function () { poll(file); }, 3000);
                        } else if (st.state === 'done') {
                            show('success', '<i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>Your video is ready.</span><a class="btn btn-sm btn-success ms-auto" href="/admin/opposition_report_video.php?action=download&file=' + encodeURIComponent(file) + '"><i class="fa-solid fa-download me-1" aria-hidden="true"></i>Download MP4</a>');
                        } else {
                            show('danger', '<i class="fa-solid fa-circle-xmark" aria-hidden="true"></i><span>' + esc(st.message || 'The video could not be created.') + '</span>');
                        }
                    })
                    .catch(function () { timer = setTimeout(function () { poll(file); }, 5000); });
            }
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.opp-video-btn');
                if (!btn || btn.classList.contains('disabled')) return;
                var file = btn.getAttribute('data-file');
                clearTimeout(timer);
                show('info', '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>Starting&hellip;</span>');
                box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                var fd = new FormData();
                fd.append('action', 'start');
                fd.append('file', file);
                fd.append('csrf_token', token);
                fetch('/admin/opposition_report_video.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.ok) { show('danger', '<i class="fa-solid fa-circle-xmark" aria-hidden="true"></i><span>' + esc(res.message || 'Could not start the video.') + '</span>'); return; }
                        poll(file);
                    })
                    .catch(function () { show('danger', '<span>Could not start the video. Please try again.</span>'); });
            });
        })();
        </script>
        <style>
        .opp-dropzone { border: 2px dashed #ced4da; border-radius: .5rem; padding: 2rem 1rem; text-align: center; cursor: pointer; transition: border-color .15s ease, background-color .15s ease; }
        .opp-dropzone:hover, .opp-dropzone:focus-visible { border-color: #86b7fe; background: #f8f9fa; outline: none; }
        .opp-dropzone.is-dragover { border-color: #0d6efd; background: #eaf2ff; }
        .opp-dropzone__icon { font-size: 1.75rem; color: #6c757d; margin-bottom: .5rem; }
        .opp-dropzone__text { color: #495057; font-size: .9rem; }
        .opp-dropzone__browse { color: #0d6efd; text-decoration: underline; }
        .opp-progress-card { border: 1px solid #e3e3e6; border-radius: .5rem; padding: .75rem .9rem; margin-top: .75rem; animation: opp-fade-in .15s ease; }
        .opp-progress-card__row { display: flex; align-items: center; gap: .6rem; }
        .opp-progress-card__icon { font-size: 1.1rem; color: #6c757d; flex-shrink: 0; transition: color .2s ease; }
        .opp-progress-card__icon.is-success { color: #198754; }
        .opp-progress-card__icon.is-fail { color: #dc3545; }
        .opp-progress-card__body { flex: 1 1 auto; min-width: 0; }
        .opp-progress-card__name { font-size: .85rem; color: #212529; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: .3rem; }
        .opp-progress-card__pct { font-size: .8rem; color: #6c757d; min-width: 2.5em; text-align: right; }
        .opp-progress-bar { height: 6px; border-radius: 999px; background: #e9ecef; overflow: hidden; }
        .opp-progress-bar__fill { height: 100%; width: 0%; background: #0d6efd; border-radius: 999px; transition: width .2s ease, background-color .2s ease; }
        .opp-progress-bar__fill.is-success { background: #198754; }
        .opp-progress-bar__fill.is-fail { background: #dc3545; }
        .opp-progress-card__msg { font-size: .8rem; margin-top: .4rem; }
        .opp-progress-card__msg.is-success { color: #198754; }
        .opp-progress-card__msg.is-fail { color: #dc3545; }
        @keyframes opp-fade-in { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
        </style>
        <script>
        (function () {
            var dropzone = document.getElementById('oppDropzone');
            var input = document.getElementById('opp-file');
            var form = document.getElementById('oppUploadForm');
            var progress = document.getElementById('oppProgress');
            var progressIcon = document.getElementById('oppProgressIcon');
            var progressName = document.getElementById('oppProgressName');
            var progressBar = document.getElementById('oppProgressBar');
            var progressPct = document.getElementById('oppProgressPct');
            var progressMsg = document.getElementById('oppProgressMsg');
            if (!dropzone || !input || !form) return;

            function resetProgress() {
                progressIcon.className = 'fa-solid fa-file-code opp-progress-card__icon';
                progressBar.className = 'opp-progress-bar__fill';
                progressBar.style.width = '0%';
                progressPct.textContent = '0%';
                progressMsg.textContent = '';
                progressMsg.className = 'opp-progress-card__msg';
            }

            function upload(file) {
                if (!file) return;
                dropzone.hidden = true;
                progress.hidden = false;
                resetProgress();
                progressName.textContent = file.name;

                var xhr = new XMLHttpRequest();
                xhr.open('POST', form.getAttribute('action'), true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.upload.addEventListener('progress', function (e) {
                    if (!e.lengthComputable) return;
                    var pct = Math.round((e.loaded / e.total) * 100);
                    progressBar.style.width = pct + '%';
                    progressPct.textContent = pct + '%';
                });
                xhr.addEventListener('load', function () {
                    var result = null;
                    try { result = JSON.parse(xhr.responseText); } catch (e) { /* fall through to generic failure below */ }
                    var ok = xhr.status >= 200 && xhr.status < 300 && result && result.ok;
                    progressBar.style.width = '100%';
                    progressPct.textContent = '100%';
                    if (ok) {
                        progressIcon.className = 'fa-solid fa-circle-check opp-progress-card__icon is-success';
                        progressBar.classList.add('is-success');
                        progressMsg.textContent = (window.oppDescribe ? (function () { var d = window.oppDescribe(result.team || 'Report', result.matches != null ? result : null); return d.head + (result.matches != null ? ' \u2014 ' + d.sub : ''); })() : 'Uploaded successfully.');
                        progressMsg.classList.add('is-success');
                        setTimeout(function () { var dsu = window.oppDescribe(result.team, result.matches != null ? result : null); if (window.oppRefreshReports) { window.oppRefreshReports(result.team, dsu.badge); } progress.hidden = true; }, 700);
                    } else {
                        progressIcon.className = 'fa-solid fa-circle-xmark opp-progress-card__icon is-fail';
                        progressBar.classList.add('is-fail');
                        progressMsg.textContent = (result && result.message) ? result.message : 'Upload failed. Please try again.';
                        progressMsg.classList.add('is-fail');
                        setTimeout(function () { progress.hidden = true; dropzone.hidden = false; input.value = ''; }, 2200);
                    }
                });
                xhr.addEventListener('error', function () {
                    progressBar.style.width = '100%';
                    progressBar.classList.add('is-fail');
                    progressIcon.className = 'fa-solid fa-circle-xmark opp-progress-card__icon is-fail';
                    progressMsg.textContent = 'Upload failed — check your connection and try again.';
                    progressMsg.classList.add('is-fail');
                    setTimeout(function () { progress.hidden = true; dropzone.hidden = false; input.value = ''; }, 2200);
                });

                var data = new FormData(form);
                data.set('json_file', file);
                xhr.send(data);
            }

            dropzone.addEventListener('click', function () { input.click(); });
            dropzone.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
            });
            input.addEventListener('change', function () {
                if (input.files && input.files[0]) upload(input.files[0]);
            });
            ['dragenter', 'dragover'].forEach(function (evt) {
                dropzone.addEventListener(evt, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.add('is-dragover');
                });
            });
            ['dragleave', 'drop'].forEach(function (evt) {
                dropzone.addEventListener(evt, function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    dropzone.classList.remove('is-dragover');
                });
            });
            dropzone.addEventListener('drop', function (e) {
                var files = e.dataTransfer && e.dataTransfer.files;
                if (files && files[0]) upload(files[0]);
            });
            form.addEventListener('submit', function (e) { e.preventDefault(); });
        })();
        (function () {
            var panel = document.getElementById('oppPreview');
            var body = document.getElementById('oppPreviewBody');
            if (!panel || !body) return;

            function esc(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }
            function numTag(n) { return n ? '#' + esc(n) : '&mdash;'; }
            function outcomeBadge(o) {
                var cls = o === 'W' ? 'success' : (o === 'L' ? 'danger' : 'secondary');
                return '<span class="badge text-bg-' + cls + '">' + esc(o) + '</span>';
            }
            // cols: [{label, align}] — 'start' for the player-name column, 'center' for everything else.
            function renderTable(cols, rows, emptyText) {
                var html = '<div class="table-responsive"><table class="table table-sm align-middle mb-0 hub-data-table"><thead><tr>';
                cols.forEach(function (c) { html += '<th scope="col" class="text-' + c.align + '">' + c.label + '</th>'; });
                html += '</tr></thead><tbody>';
                if (!rows.length) {
                    html += '<tr><td colspan="' + cols.length + '" class="text-muted text-center py-3">' + esc(emptyText) + '</td></tr>';
                }
                rows.forEach(function (r) { html += '<tr>' + r + '</tr>'; });
                html += '</tbody></table></div>';
                return html;
            }
            // Every table with a shirt-number column puts it first, centered; the
            // player name stays left-aligned; everything else is centered.
            var NO_COL = { label: 'No.', align: 'center' };
            var PLAYER_COL = { label: 'Player', align: 'start' };
            var td = function (align, content) { return '<td class="text-' + align + '">' + content + '</td>'; };

            function render(report, file) {
                var s = report.summary;
                var gd = s.gf - s.ga;
                var html = '';

                html += '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">';
                html += '<div><h2 class="h5 mb-1">' + esc(report.team || 'Opposition') + '</h2><div class="text-muted small">' + esc(report.competition) + (report.competition ? ' &middot; ' : '') + report.total_matches + ' match' + (report.total_matches === 1 ? '' : 'es') + ' analysed</div></div>';
                html += '<a class="btn btn-primary" href="/admin/opposition_report_pdf.php?file=' + encodeURIComponent(file) + '" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>Download PDF</a> <a class="btn btn-outline-secondary" href="/admin/opposition_report_pptx.php?file=' + encodeURIComponent(file) + '"><i class="fa-solid fa-file-powerpoint me-1" aria-hidden="true"></i>Download PowerPoint</a> <button type="button" class="btn btn-outline-secondary opp-video-btn" data-file="' + esc(file) + '"><i class="fa-solid fa-film me-1" aria-hidden="true"></i>Create video (MP4)</button>';
                html += '</div>';

                html += '<div class="table-responsive mb-3"><table class="table table-sm table-bordered text-center mb-0 hub-data-table"><thead class="table-dark"><tr><th>P</th><th>W</th><th>D</th><th>L</th><th>GF</th><th>GA</th><th>GD</th><th>CS</th></tr></thead><tbody><tr>'
                    + '<td>' + s.p + '</td><td>' + s.w + '</td><td>' + s.d + '</td><td>' + s.l + '</td><td>' + s.gf + '</td><td>' + s.ga + '</td><td>' + (gd > 0 ? '+' : '') + gd + '</td><td>' + s.cs + '</td></tr></tbody></table></div>';

                if (report.captain || report.goalkeeper) {
                    html += '<h3 class="h6">Key personnel</h3><div class="table-responsive mb-3"><table class="table table-sm table-bordered mb-0 hub-data-table"><tbody>';
                    if (report.captain) {
                        html += '<tr><th scope="row" class="bg-light" style="width:110px">Captain</th><td>' + esc(report.captain.player) + ' (' + numTag(report.captain.number) + ') &mdash; armband in ' + report.captain.matches + '/' + report.total_matches + ' matches</td></tr>';
                    }
                    if (report.goalkeeper) {
                        html += '<tr><th scope="row" class="bg-light">Goalkeeper</th><td>' + esc(report.goalkeeper.player) + ' (' + numTag(report.goalkeeper.number) + ') &mdash; started in goal ' + report.goalkeeper.matches + '/' + report.total_matches + ' matches</td></tr>';
                    }
                    html += '</tbody></table></div>';
                }

                html += '<div class="row g-3 mb-1">';
                html += '<div class="col-md-6"><h3 class="h6">Results</h3>' + renderTable(
                    [{ label: 'Opponent', align: 'start' }, { label: 'Result', align: 'center' }],
                    report.results.map(function (r) {
                        return td('start', esc(r.opponent)) + td('center', r.for + '-' + r.against + ' ' + outcomeBadge(r.outcome));
                    }), 'No played matches recorded.') + '</div>';
                html += '<div class="col-md-6"><h3 class="h6">Goal threats</h3>' + renderTable(
                    [NO_COL, PLAYER_COL, { label: 'Goals', align: 'center' }],
                    report.goal_threats.map(function (g) {
                        return td('center', numTag(g.number)) + td('start', esc(g.player)) + td('center', g.goals);
                    }), 'No goals recorded.') + '</div>';
                html += '</div>';

                html += '<div class="row g-3 mb-1 mt-4">';
                html += '<div class="col-md-6"><h3 class="h6">Player usage</h3>' + renderTable(
                    [NO_COL, PLAYER_COL, { label: 'Off', align: 'center' }, { label: 'On', align: 'center' }],
                    report.usage.map(function (u) {
                        return td('center', numTag(u.number)) + td('start', esc(u.player)) + td('center', u.off > 0 ? u.off : '-') + td('center', u.on > 0 ? u.on : '-');
                    }), 'No substitutions recorded.') + '</div>';
                html += '<div class="col-md-6"><h3 class="h6">Discipline</h3>' + renderTable(
                    [NO_COL, PLAYER_COL, { label: 'YC', align: 'center' }, { label: 'RC', align: 'center' }],
                    report.discipline.map(function (d) {
                        return td('center', numTag(d.number)) + td('start', esc(d.player)) + td('center', d.yc) + td('center', d.rc);
                    }), 'No notable discipline record.') + '</div>';
                html += '</div>';

                if (report.regulars && report.regulars.length) {
                    html += '<h3 class="h6 mt-4">Regular starters</h3>' + renderTable(
                        [NO_COL, PLAYER_COL, { label: 'Starts', align: 'center' }, { label: 'Goals', align: 'center' }, { label: 'YC', align: 'center' }, { label: 'RC', align: 'center' }],
                        report.regulars.slice(0, 11).map(function (r) {
                            return td('center', numTag(r.number)) + td('start', esc(r.player)) + td('center', r.starts + '/' + report.total_matches) + td('center', r.goals) + td('center', r.yc) + td('center', r.rc);
                        }), '');
                }

                if (report.key_facts && report.key_facts.length) {
                    html += '<h3 class="h6 mt-4">Key facts</h3><ul class="list-group list-group-flush small mb-0">';
                    report.key_facts.forEach(function (fact) {
                        var idx = fact.indexOf(':');
                        html += '<li class="list-group-item px-0">';
                        if (idx === -1) {
                            html += esc(fact);
                        } else {
                            html += '<strong>' + esc(fact.slice(0, idx)) + ':</strong>' + esc(fact.slice(idx + 1));
                        }
                        html += '</li>';
                    });
                    html += '</ul>';
                }

                if (report.cup_matches && report.cup_matches.length) {
                    html += '<h3 class="h6 mt-4">Cup matches</h3>' + renderTable(
                        [{ label: 'Date', align: 'start' }, { label: 'Competition', align: 'start' }, { label: 'Opponent', align: 'start' }, { label: 'Result', align: 'center' }, { label: 'Scorers', align: 'start' }],
                        report.cup_matches.map(function (c) {
                            var d = c.date ? new Date(c.date).toLocaleDateString('en-GB') : '';
                            var comp = c.competition + (c.round ? ' (Round ' + c.round + ')' : '');
                            return td('start', esc(d)) + td('start', esc(comp)) + td('start', esc(c.opponent) + ' (' + (c.home ? 'H' : 'A') + ')') + td('center', esc(c.outcome + ' ' + c.for + '-' + c.against)) + td('start', esc(c.scorers || '-'));
                        }), '') + '<div class="small text-muted mt-1">Cup matches are shown separately and are not included in the league figures above.</div>';
                }

                body.innerHTML = html;
            }

            function bindPreview() { document.querySelectorAll('.opp-preview-btn').forEach(function (btn) {
                if (btn.dataset.bound) return; btn.dataset.bound = '1';
                btn.addEventListener('click', function () {
                    if (btn.classList.contains('disabled')) return;
                    var file = btn.getAttribute('data-file');
                    body.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading report&hellip;</div>';
                    panel.hidden = false;
                    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    fetch('/admin/opposition_report_preview.php?file=' + encodeURIComponent(file), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json(); })
                        .then(function (result) {
                            if (!result.ok) {
                                body.innerHTML = '<div class="alert alert-danger mb-0">' + esc(result.message || 'Could not load that report.') + '</div>';
                                return;
                            }
                            render(result.report, result.file);
                        })
                        .catch(function () {
                            body.innerHTML = '<div class="alert alert-danger mb-0">Could not load that report — check your connection and try again.</div>';
                        });
                });
            }); }
            bindPreview(); window.oppBindPreview = bindPreview;

            // Live table updates: swap in the freshly rendered reports section, and delete rows in place.
            window.oppDescribe = function (team, info, verb) {
                if (!info) { return { head: team + ' report is ready', sub: 'It has been added to the list below.', badge: null }; }
                var n = info.added || 0, plus = n === 1 ? '1 new match' : n + ' new matches';
                if (info.previous === null || info.previous === undefined) { return { head: team + ' added', sub: info.matches + ' matches collected.', badge: ['New', 'text-bg-success'] }; }
                if (n === 0) { return { head: team + ' checked \u2014 no new matches', sub: 'Still ' + info.matches + ' matches (was ' + info.previous + '). Data refreshed.', badge: ['Updated \u00b7 no new matches', 'text-bg-secondary'] }; }
                return { head: team + ' updated \u2014 ' + plus, sub: info.matches + ' matches (was ' + info.previous + ').', badge: ['Updated \u00b7 +' + n, 'text-bg-success'] };
            };
            window.oppRefreshReports = function (highlightTeam, badge) {
                return fetch('/admin/stats.php?tab=opposition', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        var doc = new DOMParser().parseFromString(html, 'text/html'), fresh = doc.getElementById('oppReportsSection'), cur = document.getElementById('oppReportsSection');
                        if (!fresh || !cur) { return; }
                        cur.innerHTML = fresh.innerHTML; bindPreview();
                        if (highlightTeam) {
                            Array.prototype.forEach.call(cur.querySelectorAll('tbody tr'), function (tr) {
                                if (tr.cells[0].textContent.trim().toLowerCase() === highlightTeam.toLowerCase()) {
                                    if (badge) { var bd = document.createElement('span'); bd.className = 'badge ms-2 ' + badge[1]; bd.textContent = badge[0]; tr.cells[0].appendChild(bd); }
                                    tr.animate([{ backgroundColor: 'rgba(25,135,84,.35)' }, { backgroundColor: 'rgba(25,135,84,0)' }], { duration: 2600, easing: 'ease-out' });
                                }
                            });
                        }
                    }).catch(function () {});
            };
            document.addEventListener('submit', function (e) {
                var f = e.target;
                if (!f.matches || !f.matches('#oppReportsSection form') || f.dataset.confirmed !== 'true') { return; }
                e.preventDefault();
                var tr = f.closest('tr'); if (tr) { tr.style.opacity = '.45'; }
                fetch(f.getAttribute('action'), { method: 'POST', body: new FormData(f), credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.ok && tr) { tr.style.transition = 'opacity .3s'; tr.style.opacity = '0'; setTimeout(function () { window.oppRefreshReports(); }, 320); }
                        else { if (tr) { tr.style.opacity = ''; } f.dataset.confirmed = ''; if (window.hubConfirm) { window.hubConfirm(d.message || 'Could not delete that report.', { title: 'Delete failed', actionLabel: 'OK', actionClass: 'btn-secondary' }); } }
                    })
                    .catch(function () { if (tr) { tr.style.opacity = ''; } f.dataset.confirmed = ''; });
            });
        })();
        </script>
    <?php elseif ($tab === 'compare'): ?>
        <?php
        $sids = $cmp['season_ids'];
        $seasonName = static fn(int $sid): string => (string)($cmp['season_by_id'][$sid]['name'] ?? $sid);
        $teamRows = hub_stats_compare_team_rows();
        $deltaCell = static function (?float $cur, ?float $prev, ?string $better) use ($esc): string {
            if ($cur === null || $prev === null || $better === null) {
                return '';
            }
            $d = $cur - $prev;
            if (abs($d) < 0.005) {
                return ' <span class="text-muted small">&pm;0</span>';
            }
            $good = $better === 'high' ? $d > 0 : $d < 0;
            $num = fmod($d, 1.0) === 0.0 ? (string)(int)round($d) : number_format($d, 2);
            return ' <span class="small ' . ($good ? 'text-success' : 'text-danger') . '">' . ($d > 0 ? "\u{25B2} +" : "\u{25BC} ") . $esc($num) . '</span>';
        };
        $playerSeasons = $cmp['player_seasons'];
        $filterBits = array_values(array_filter([
            $venue !== '' ? ucfirst($venue) . ' matches only' : '',
            $selectedCompetitions ? implode(', ', $selectedCompetitions) : '',
        ]));
        ?>
        <?php if (!$sids): ?>
            <div class="card card-body text-center py-5"><h2 class="h5">Nothing to compare yet</h2><p class="text-muted mb-0">Choose seasons above.</p></div>
        <?php else: ?>
        <?php if (count($sids) < 2): ?>
            <div class="alert alert-info">Tick at least two seasons to see year-on-year changes.</div>
        <?php endif; ?>

        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-1">Team stats year on year</h2>
                <p class="text-muted small mb-0"><?= count($sids) ?> season<?= count($sids) === 1 ? '' : 's' ?><?= $filterBits ? ' &middot; ' . $esc(implode(' &middot; ', $filterBits)) : '' ?>. Rate metrics use matches with a recorded full-time score. Points are 3 for a win, 1 for a draw. The arrow compares each season with the one to its left.</p>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 hub-data-table">
                    <thead><tr><th scope="col">Metric</th><?php foreach ($sids as $sid): ?><th scope="col" class="text-end text-nowrap"><?= $esc($seasonName($sid)) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php foreach ($teamRows as [$rowLabel, $getStr, $getRaw, $better]): ?>
                        <tr>
                            <th scope="row" class="text-nowrap"><?= $esc($rowLabel) ?></th>
                            <?php $prevRaw = null; foreach ($sids as $i => $sid): $c = $cmp['team'][$sid]; $raw = $getRaw ? $getRaw($c) : null; ?>
                                <td class="text-end text-nowrap"><?= $esc($getStr($c)) ?><?= $i > 0 ? $deltaCell($raw, $prevRaw, $better) : '' ?></td>
                            <?php $prevRaw = $raw; endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <?php $pmSummary = count($compareMetrics) <= 2
            ? implode(', ', array_map(static fn($m) => $pmetricLabels[$m], $compareMetrics))
            : count($compareMetrics) . ' metrics'; ?>
        <form method="get" class="cmp-metric-bar d-flex flex-wrap align-items-end gap-2 mb-3">
            <input type="hidden" name="tab" value="compare">
            <?php foreach ($sids as $sid): ?><input type="hidden" name="seasons[]" value="<?= (int)$sid ?>"><?php endforeach; ?>
            <?php foreach ($selectedCompetitions as $c): ?><input type="hidden" name="competitions[]" value="<?= $esc($c) ?>"><?php endforeach; ?>
            <?php if ($venue !== ''): ?><input type="hidden" name="venue" value="<?= $esc($venue) ?>"><?php endif; ?>
            <div class="hub-checkbox-dropdown-field" style="max-width:22rem;flex:1 1 16rem">
                <label class="form-label mb-1" id="cmpMetricLabel">Player metrics to show</label>
                <div class="dropdown hub-checkbox-dropdown">
                    <button type="button" class="form-select hub-checkbox-dropdown__toggle" aria-haspopup="true" aria-expanded="false" aria-labelledby="cmpMetricLabel">
                        <span class="hub-checkbox-dropdown__summary" data-dd-summary data-dd-all="Goals" data-dd-word="metrics"><?= $esc($pmSummary) ?></span>
                    </button>
                    <div class="dropdown-menu hub-checkbox-dropdown__menu" aria-label="Player metrics">
                        <?php foreach ($pmetricLabels as $k => $lbl): ?>
                            <label class="hub-checkbox-dropdown__item">
                                <input type="checkbox" name="pmetric[]" value="<?= $esc($k) ?>" <?= in_array($k, $compareMetrics, true) ? 'checked' : '' ?>>
                                <span><?= $esc($lbl) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <button class="btn btn-outline-primary" type="submit">Update player tables</button>
        </form>

        <?php if ($cmp['no_player_seasons']): ?>
            <div class="alert alert-info">No player lineup data recorded for <?= $esc(implode(', ', array_map($seasonName, $cmp['no_player_seasons']))) ?>. Those seasons are left out of the player tables.</div>
        <?php endif; ?>

        <?php if (!$playerSeasons): ?>
            <section class="card shadow-sm"><div class="card-body"><h2 class="h5 mb-1">Player stats year on year</h2><p class="text-muted mb-0">No player lineups or events recorded for the selected seasons.</p></div></section>
        <?php else: ?>
        <?php foreach ($compareMetrics as $metric): $pm = $cmp['players'][$metric]; $prows = $pm['rows']; $mlabel = mb_strtolower($pmetricLabels[$metric]); ?>
        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5 mb-1">Player <?= $esc($mlabel) ?> year on year</h2>
                <p class="text-muted small mb-0">Players who registered any <?= $esc($mlabel) ?> across the chosen seasons, from recorded lineups and match events.<?= $metric === 'clean_sheets' ? ' Clean sheets count only for the starting goalkeeper.' : '' ?><?= $pm['any_left'] ? ' Players who have left the club are listed last and marked.' : '' ?><?= $pm['hidden_zero'] > 0 ? ' (' . (int)$pm['hidden_zero'] . ' with none are hidden.)' : '' ?></p>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 hub-data-table">
                    <thead><tr><th scope="col">Player</th><?php foreach ($playerSeasons as $sid): ?><th scope="col" class="text-end text-nowrap"><?= $esc($seasonName($sid)) ?></th><?php endforeach; ?><?php if (count($playerSeasons) > 1): ?><th scope="col" class="text-end">Total</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($prows as $row): ?>
                        <tr<?= $row['left'] ? ' class="text-muted"' : '' ?>>
                            <th scope="row"><button type="button" class="btn btn-link p-0 text-start text-decoration-none stats-player-link" data-player="<?= $esc($row['name']) ?>" data-metric="<?= $esc($metric) ?>" data-metric-label="<?= $esc($pmetricLabels[$metric]) ?>"><?= $esc($row['name']) ?></button><?php if ($row['left']): ?> <span class="badge rounded-pill text-bg-light border align-middle"><i class="fa-solid fa-right-from-bracket me-1" aria-hidden="true"></i><?= $row['status'] === 'retired' ? 'Retired' : 'Left club' ?></span><?php endif; ?></th>
                            <?php $prev = null; foreach ($playerSeasons as $i => $sid): $v = (int)($row['vals'][$sid] ?? 0); ?>
                                <td class="text-end"><?= $v ?><?php
                                    if ($i > 0 && $prev !== null && $v !== $prev) {
                                        $d = $v - $prev;
                                        $good = $pm['better'] === 'high' ? $d > 0 : $d < 0;
                                        echo ' <span class="small ' . ($good ? 'text-success' : 'text-danger') . '">' . ($d > 0 ? "\u{25B2} +" : "\u{25BC} ") . $d . '</span>';
                                    }
                                    $prev = $v;
                                ?></td>
                            <?php endforeach; ?>
                            <?php if (count($playerSeasons) > 1): ?><td class="text-end fw-semibold"><?= (int)$row['total'] ?></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$prows): ?><tr><td colspan="<?= count($playerSeasons) + 2 ?>" class="text-muted text-center py-4">No players registered any <?= $esc($mlabel) ?> in these seasons.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php endif; ?>
    <?php elseif (in_array($tab, ['players', 'player'], true) ? !$playersFixtures : !$fixtures): ?>
        <div class="card card-body text-center py-5"><h2 class="h5">No played matches found</h2><p class="text-muted mb-0">Choose another season or filter, or record a result in Match day to start building your stats.</p></div>
    <?php elseif ($tab === 'team'): ?>
        <div class="row g-3 mb-4">
        <?php
        $metricCards = [
            ['Matches played' => $summary['played']],
            ['Wins' => $summary['wins'], 'Draws' => $summary['draws'], 'Losses' => $summary['losses']],
            ['Win rate' => $summary['scored'] ? round(100 * $summary['wins'] / $summary['scored']) . '%' : '—'],
            ['Goals for' => $summary['goals_for'], 'Goals against' => $summary['goals_against'], 'Goal difference' => $summary['goals_for'] - $summary['goals_against']],
            ['Clean sheets' => $summary['clean_sheets']],
            ['Goals per game' => $summary['scored'] ? number_format($summary['goals_for'] / $summary['scored'], 2) : '—'],
        ];
        ?>
        <?php foreach ($metricCards as $metrics): ?>
            <div class="<?= count($metrics) > 1 ? 'col-12 col-lg-6' : 'col-6 col-lg-3' ?>">
                <div class="card card-body h-100 shadow-sm">
                    <div class="row g-0">
                        <?php foreach ($metrics as $label => $value): ?>
                            <div class="<?= count($metrics) > 1 ? 'col-4 text-center px-2' : 'col-12 text-center' ?>">
                                <span class="text-muted small d-block"><?= $esc($label) ?></span>
                                <strong class="fs-2"><?= $esc($value) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php if ($summary['played'] > $summary['scored']): ?><div class="alert alert-info"><?= $summary['played'] - $summary['scored'] ?> played match(es) have no full-time score. Result metrics exclude them.</div><?php endif; ?>
        <section class="card card-body shadow-sm mb-4"><h2 class="h5">Recent form</h2><p class="text-muted small">Last five played matches, oldest to newest.</p><div class="hub-form-row"><?php foreach (array_slice($fixtures, -5) as $f): $r = hub_stats_result($f); ?><div class="hub-form-chip hub-form-chip--<?= $badge($r['result'] ?? '') ?>" title="<?= $esc($f['opponent']) ?>"><span class="hub-form-chip__result"><?= $esc($r['result'] ?? '—') ?></span><span class="hub-form-chip__opp"><?= $esc($f['opponent']) ?></span></div><?php endforeach; ?></div></section>
        <section class="card shadow-sm"><div class="card-body"><h2 class="h5">Home &amp; away performance</h2></div><div class="table-responsive"><table class="table mb-0 hub-data-table"><thead><tr><th scope="col">Venue</th><?php foreach (['Played', 'Won', 'Drawn', 'Lost', 'GF', 'GA', 'Clean sheets'] as $label): ?><th scope="col" class="text-center"><?= $label ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ([1 => 'Home', 0 => 'Away'] as $home => $label): $s = hub_stats_summary(array_filter($fixtures, static fn(array $f): bool => (int)$f['is_home'] === $home)); ?><tr><th scope="row"><?= $label ?></th><?php foreach (['played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against', 'clean_sheets'] as $k): ?><td class="text-center"><?= $s[$k] ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div></section>
    <?php elseif ($tab === 'players'): ?>
        <?php
        // Rows, sort order and the Current / Left split come from the shared model in lib/stats.php (the PDF export uses it too).
        $playerTable = hub_stats_player_contributions($pdo, $playersSeasonIds[0], $playersFixtures, $eventsByFixture, $dataFile, $_GET['sort'] ?? null, $_GET['direction'] ?? null);
        $sorts = $playerTable['sorts'];
        $sort = $playerTable['sort'];
        $direction = $playerTable['direction'];
        $hasPlayers = $playerTable['current'] || $playerTable['left'];
        $playerGroups = ['Current' => $playerTable['current']];
        if ($playerTable['left']) $playerGroups['Left'] = $playerTable['left'];
        ?>
        <section class="card shadow-sm"><div class="card-body"><h2 class="h5">Player contributions</h2><p class="text-muted small">From recorded lineups and match events. Minutes are estimated over 90 minutes; unused substitutes do not count as appearances. Clean sheets apply to the starting goalkeeper.</p><p class="text-muted small mb-0">Click a column heading to sort; click again to reverse the order.</p></div>
        <?php foreach ($playerGroups as $groupLabel => $groupPlayers): ?>
        <div class="card-body pb-2"><h3 class="h6 mb-0"><?= $esc($groupLabel) ?></h3></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0 hub-data-table"><thead><tr><?php foreach ($sorts as $key => $label):
            $activeSort = $sort === $key;
            $nextDirection = $activeSort ? ($direction === 'asc' ? 'desc' : 'asc') : ($key === 'name' ? 'asc' : 'desc');
        ?><th scope="col" class="text-nowrap<?= $key === 'name' ? '' : ' text-center' ?>"<?= $activeSort ? ' aria-sort="' . ($direction === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>><a class="d-block text-reset text-decoration-none" href="<?= $esc($url(['sort' => $key, 'direction' => $nextDirection])) ?>" aria-label="<?= $esc('Sort by ' . $label . ', ' . ($nextDirection === 'asc' ? 'ascending' : 'descending')) ?>"><?= $esc($label) ?> <span aria-hidden="true" class="<?= $activeSort ? '' : 'text-muted' ?>"><?= $activeSort ? ($direction === 'asc' ? '↑' : '↓') : '↕' ?></span></a></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($groupPlayers as $player): ?><tr><th scope="row"><a class="fw-semibold text-decoration-none" href="<?= $esc($url(['tab' => 'player', 'player' => $player['name'], 'sort' => null, 'direction' => null])) ?>"><?= $esc($player['name']) ?></a></th><?php foreach (['appearances', 'starts', 'substitute_appearances', 'minutes_played', 'goals', 'yellow_cards', 'red_cards', 'clean_sheets'] as $key): ?><td class="text-center"><?= $key === 'clean_sheets' && !$player['is_goalkeeper'] ? '—' : (int)$player[$key] ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        <?php if (!$groupPlayers): ?><tr><td colspan="9" class="text-muted text-center py-4"><?= $hasPlayers ? 'No current players in these matches.' : 'No player lineups or events recorded for these matches yet.' ?></td></tr><?php endif; ?>
        </tbody></table></div>
        <?php endforeach; ?>
        </section>
    <?php elseif ($tab === 'player'): ?>
        <?php
        // ---- Single player page -------------------------------------------------
        $names = [];
        foreach ($playersFixtures as $f) {
            foreach (['starting11_starters_json', 'starting11_substitutes_json'] as $field) {
                $lineup = json_decode((string)($f[$field] ?? '[]'), true);
                foreach (is_array($lineup) ? $lineup : [] as $name) if (is_string($name) && trim($name) !== '') $names[hub_player_stats_normalize_name($name)] = $name;
            }
            foreach ($eventsByFixture[(int)$f['id']] ?? [] as $event) {
                if (hub_player_stats_is_club_player_event($event) && trim((string)($event['player'] ?? '')) !== '') $names[hub_player_stats_normalize_name((string)$event['player'])] = (string)$event['player'];
            }
        }
        $playerList = array_values($names);
        usort($playerList, static fn(string $a, string $b): int => strcasecmp($a, $b));
        $wanted = is_string($_GET['player'] ?? null) ? hub_player_stats_normalize_name($_GET['player']) : '';
        $playerName = '';
        foreach ($playerList as $pn) if (hub_player_stats_normalize_name($pn) === $wanted) $playerName = $pn;
        if ($playerName === '' && $playerList) $playerName = $playerList[0];
        $playerIdx = array_search($playerName, $playerList, true);
        $prevPlayer = ($playerIdx !== false && $playerIdx > 0) ? $playerList[$playerIdx - 1] : null;
        $nextPlayer = ($playerIdx !== false && $playerIdx < count($playerList) - 1) ? $playerList[$playerIdx + 1] : null;
        $playerUrl = static fn(string $n): string => $url(['tab' => 'player', 'player' => $n, 'sort' => null, 'direction' => null]);
        ?>
        <?php if (!$playerList): ?>
            <div class="card card-body text-center py-5"><h2 class="h5">No players found</h2><p class="text-muted mb-0">No player line-ups or events are recorded for these matches yet.</p></div>
        <?php else:
            $pstats = hub_player_match_stats($pdo, $playersSeasonIds[0], $playerName, $dataFile, $playersFixtures, $eventsByFixture);
            $detail = hub_player_all_events_match_rows($pdo, $playersSeasonIds, $seasonById, $playerName, $playersCompetitions, $venue, $dataFile);
            $avatar = '';
            $av = $pdo->prepare('SELECT avatar FROM players WHERE name = ? LIMIT 1');
            $av->execute([$playerName]);
            $avFile = trim((string)$av->fetchColumn());
            if ($avFile !== '') $avatar = '/uploads/players/' . rawurlencode($avFile);
            $initials = '';
            foreach (preg_split('/\s+/', trim($playerName)) as $part) { if ($part !== '') $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
            $initials = mb_substr($initials, 0, 2);
            $pdfParams = ['player' => $playerName, 'metric' => 'all', 'venue' => $venue, 'download' => 1, 'seasons' => $playersSeasonIds, 'competitions' => $playersCompetitions];
            $tiles = [
                ['Apps', $pstats['appearances']], ['Starts', $pstats['starts']], ['Sub apps', $pstats['substitute_appearances']],
                ['Minutes', $pstats['minutes_played']], ['Goals', $pstats['goals']], ['Yellow', $pstats['yellow_cards']],
                ['Red', $pstats['red_cards']], ['Clean sheets', $pstats['is_goalkeeper'] ? $pstats['clean_sheets'] : '—'],
            ];
        ?>
        <style>
        .pp-switch{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin-bottom:1rem}
        .pp-switch select{flex:1 1 14rem;min-width:0;max-width:24rem}
        .pp-switch .btn{white-space:nowrap}
        .pp-head{display:flex;flex-wrap:wrap;align-items:center;gap:1rem;padding:1.1rem 1.25rem;border-radius:var(--hub-radius,1rem);background:linear-gradient(135deg,#4c1521,#6d2231);color:#fff;margin-bottom:1rem}
        .pp-avatar{flex:none;width:4rem;height:4rem;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.16);font-weight:800;font-size:1.3rem;overflow:hidden}
        .pp-avatar img{width:100%;height:100%;object-fit:cover}
        .pp-head h2{margin:0;font-size:1.5rem;font-weight:800;color:#fff!important}.pp-head p{margin:.15rem 0 0;color:rgba(255,255,255,.8)!important;font-size:.85rem}
        .pp-head .btn{margin-left:auto}
        .pp-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.6rem;margin-bottom:1rem}
        .pp-tile{padding:.65rem .85rem;background:var(--hub-surface,#fffdf9);border:1px solid var(--hub-line,rgba(75,8,24,.12));border-radius:var(--hub-radius-sm,.7rem)}
        .pp-tile span{display:block;font-size:.66rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--hub-muted,#70676b)}
        .pp-tile strong{font-size:1.4rem;font-variant-numeric:tabular-nums}
        .pp-filters{display:flex;flex-wrap:wrap;align-items:center;gap:.6rem 1rem;margin-bottom:.9rem}
        .pp-seg{display:inline-flex;padding:.15rem;gap:.1rem;background:rgba(75,8,24,.06);border-radius:.65rem}
        .pp-seg button{border:0;background:transparent;padding:.35rem .8rem;border-radius:.5rem;font-size:.82rem;font-weight:600;color:var(--hub-ink,#1f1a1d);white-space:nowrap}
        .pp-seg button[aria-pressed=true]{background:#fff;color:#6a2036;box-shadow:0 1px 3px rgba(31,26,29,.18)}
        .pp-search{flex:1 1 12rem;max-width:20rem}
        .pp-evi--g{color:#23653f}.pp-evi--y{color:#e0b42a}.pp-evi--r{color:#b23b3b}.pp-evi--s{color:#276884}
        .pp-seg--multi{flex-wrap:wrap}
        .pp-ev{display:inline-flex;align-items:center;gap:.25rem;padding:.1rem .5rem;margin:.1rem .25rem .1rem 0;border-radius:999px;background:rgba(75,8,24,.06);font-size:.74rem;font-weight:700;white-space:nowrap}
        .pp-ev i.g{color:#23653f}.pp-ev i.y{color:#e0b42a}.pp-ev i.r{color:#b23b3b}.pp-ev i.s{color:#276884}
        .pp-row{cursor:pointer}
        .pp-comp{max-width:16rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        @media (max-width:767.98px){.pp-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}.pp-head .btn{margin-left:0}.pp-comp{display:none}}
        </style>

        <div class="pp-switch">
            <a class="btn btn-outline-secondary btn-sm" href="<?= $esc($url(['tab' => 'players', 'player' => null])) ?>"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>All players</a>
            <?php if ($prevPlayer !== null): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $esc($playerUrl($prevPlayer)) ?>" title="<?= $esc($prevPlayer) ?>" aria-label="Previous player: <?= $esc($prevPlayer) ?>"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></a><?php endif; ?>
            <label class="visually-hidden" for="ppPlayerSelect">Choose a player</label>
            <select id="ppPlayerSelect" class="form-select form-select-sm" onchange="window.location.assign(this.value)">
                <?php foreach ($playerList as $pn): ?><option value="<?= $esc($playerUrl($pn)) ?>"<?= $pn === $playerName ? ' selected' : '' ?>><?= $esc($pn) ?></option><?php endforeach; ?>
            </select>
            <?php if ($nextPlayer !== null): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $esc($playerUrl($nextPlayer)) ?>" title="<?= $esc($nextPlayer) ?>" aria-label="Next player: <?= $esc($nextPlayer) ?>"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a><?php endif; ?>
        </div>

        <header class="pp-head">
            <span class="pp-avatar"><?php if ($avatar !== ''): ?><img src="<?= $esc($avatar) ?>" alt=""><?php else: ?><?= $esc($initials) ?><?php endif; ?></span>
            <div><h2><?= $esc($playerName) ?></h2><p><?= $esc($seasonById[$seasonId]['name'] ?? '') ?> · <?= ['league' => 'League', 'cups' => 'Cups', 'both' => 'League and cups'][$competition] ?? 'League' ?><?= $venue !== '' ? ' · ' . ($venue === 'home' ? 'Home games' : 'Away games') : '' ?></p></div>
            <a class="btn btn-light btn-sm" href="/admin/stats_player_detail_pdf.php?<?= $esc(http_build_query($pdfParams)) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>Download PDF</a>
        </header>

        <section class="pp-tiles" aria-label="Player summary">
            <?php foreach ($tiles as [$tl, $tv]): ?><div class="pp-tile"><span><?= $esc($tl) ?></span><strong><?= $esc($tv) ?></strong></div><?php endforeach; ?>
        </section>

        <section class="card shadow-sm"><div class="card-body">
            <h3 class="h5 mb-3">Match by match</h3>
            <?php if (!$detail['rows']): ?><p class="text-muted mb-0">No matches found for the current filters.</p><?php else: ?>
            <?php
            $evDefs = ['goal' => ['Goals', 'fa-futbol', 'g'], 'pen' => ['Penalties', 'fa-bullseye', 'g'], 'yellow' => ['Yellow cards', 'fa-square', 'y'], 'red' => ['Red cards', 'fa-square', 'r'], 'clean' => ['Clean sheets', 'fa-shield-halved', 'g'], 'start' => ['Started', 'fa-shirt', 's'], 'sub' => ['Came on', 'fa-arrow-up', 's']];
            $presentEv = [];
            foreach ($detail['rows'] as $r0) {
                $d0 = (string)($r0['detail'] ?? '');
                foreach (['goal' => '/Scored/', 'pen' => '/\(pen\)/', 'yellow' => '/Yellow card/', 'red' => '/Red card/', 'clean' => '/Clean sheet/', 'start' => '/Started/', 'sub' => '/Came on/'] as $k0 => $re0) { if (preg_match($re0, $d0)) $presentEv[$k0] = true; }
            }
            ?>
            <div class="pp-filters">
                <?php if ($presentEv): ?>
                <div class="pp-seg pp-seg--multi" role="group" aria-label="Events" data-filter="ev">
                    <?php foreach ($evDefs as $ek => [$el, $ei, $ec]): if (!isset($presentEv[$ek])) continue; ?>
                        <button type="button" data-value="<?= $ek ?>" aria-pressed="false" title="<?= $esc($el) ?>"><i class="fa-solid <?= $ei ?> pp-evi pp-evi--<?= $ec ?>" aria-hidden="true"></i> <?= $esc($el) ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <div class="pp-seg" role="group" aria-label="Venue" data-filter="venue">
                    <button type="button" data-value="" aria-pressed="true">All</button><button type="button" data-value="home" aria-pressed="false">Home</button><button type="button" data-value="away" aria-pressed="false">Away</button>
                </div>
                <div class="pp-seg" role="group" aria-label="Result" data-filter="result">
                    <button type="button" data-value="" aria-pressed="true">Any</button><button type="button" data-value="W" aria-pressed="false">Won</button><button type="button" data-value="D" aria-pressed="false">Drawn</button><button type="button" data-value="L" aria-pressed="false">Lost</button>
                </div>
                <input type="search" id="ppSearch" class="form-control form-control-sm pp-search" placeholder="Search opponent…" autocomplete="off">
                <span class="text-muted small" id="ppCount"></span>
            </div>
            <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 hub-data-table" id="ppTable"><thead><tr><th>Date</th><th>Opponent</th><th>Venue</th><th class="pp-comp">Competition</th><th>Score</th><th>Contribution</th></tr></thead><tbody>
            <?php foreach (array_reverse($detail["rows"]) as $row):
                $det = (string)($row['detail'] ?? '');
                $badges = [];
                if (preg_match_all('/Scored[^\n]*/', $det, $gm)) { foreach ($gm[0] as $g) $badges[] = ['g', 'fa-futbol', trim($g)]; }
                if (preg_match('/Yellow card/', $det)) $badges[] = ['y', 'fa-square', 'Yellow card'];
                if (preg_match('/Red card/', $det)) $badges[] = ['r', 'fa-square', 'Red card'];
                if (preg_match('/Clean sheet/', $det)) $badges[] = ['g', 'fa-shield-halved', 'Clean sheet'];
                if (preg_match('/Came on/', $det)) $badges[] = ['s', 'fa-arrow-up', 'Came on'];
                elseif (preg_match('/Started/', $det)) $badges[] = ['s', 'fa-shirt', 'Started'];
                if (preg_match('/Subbed off|Substituted off|Taken off/i', $det)) $badges[] = ['r', 'fa-arrow-down', 'Subbed off'];
                $evKeys = [];
                if (preg_match('/Scored/', $det)) $evKeys[] = 'goal';
                if (preg_match('/\(pen\)/', $det)) $evKeys[] = 'pen';
                if (preg_match('/Yellow card/', $det)) $evKeys[] = 'yellow';
                if (preg_match('/Red card/', $det)) $evKeys[] = 'red';
                if (preg_match('/Clean sheet/', $det)) $evKeys[] = 'clean';
                if (preg_match('/Started/', $det)) $evKeys[] = 'start';
                if (preg_match('/Came on/', $det)) $evKeys[] = 'sub';
                $allEv = array_merge($allEv ?? [], $evKeys);
                $matchHref = '';
                foreach ($playersFixtures as $pf) { if ((string)$pf['match_date'] === $row['date'] && (string)$pf['opponent'] === $row['opponent']) { $matchHref = $url(['tab' => 'matches', 'match_id' => $pf['id'], 'player' => null]) . '#match-breakdown'; break; } }
            ?>
                <tr class="pp-row" data-ev="<?= $esc(implode(' ', $evKeys)) ?>" data-venue="<?= $esc(strtolower((string)$row['venue'])) ?>" data-result="<?= $esc((string)($row['result'] ?? '')) ?>" data-search="<?= $esc(strtolower($row['opponent'] . ' ' . $row['competition'])) ?>"<?= $matchHref !== '' ? ' role="link" tabindex="0" data-href="' . $esc($matchHref) . '"' : '' ?>>
                    <td class="text-nowrap"><?= $esc(date('j M Y', strtotime($row['date']))) ?></td>
                    <th scope="row"><?= $esc($row['opponent']) ?></th>
                    <td><?= $esc($row['venue']) ?></td>
                    <td class="pp-comp" title="<?= $esc($row['competition']) ?>"><?= $esc($row['competition']) ?></td>
                    <td class="text-nowrap"><?= $row['score'] !== null ? $esc($row['score']) . ' <span class="badge text-bg-' . $badge((string)$row['result']) . '">' . $esc($row['result']) . '</span>' : '—' ?></td>
                    <td><?php foreach ($badges as [$bc, $bi, $bl]): ?><span class="pp-ev" title="<?= $esc($bl) ?>"><i class="fa-solid <?= $bi ?> <?= $bc ?>" aria-hidden="true"></i><?= $esc(mb_strlen($bl) > 22 ? mb_substr($bl, 0, 21) . '…' : $bl) ?></span><?php endforeach; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <script>
            (function () {
                var rows = Array.prototype.slice.call(document.querySelectorAll('#ppTable tbody .pp-row'));
                var state = { venue: '', result: '', ev: [] };
                var search = document.getElementById('ppSearch'), count = document.getElementById('ppCount');
                function apply() {
                    var q = (search.value || '').toLowerCase().trim(), shown = 0;
                    rows.forEach(function (r) {
                        var have = ' ' + (r.dataset.ev || '') + ' ';
                        var ok = (!state.ev.length || state.ev.some(function (e) { return have.indexOf(' ' + e + ' ') !== -1; })) && (!state.venue || r.dataset.venue === state.venue) && (!state.result || r.dataset.result === state.result) && (!q || r.dataset.search.indexOf(q) !== -1);
                        r.hidden = !ok; if (ok) shown++;
                    });
                    count.textContent = shown === rows.length ? rows.length + ' matches' : 'Showing ' + shown + ' of ' + rows.length;
                }
                document.querySelectorAll('.pp-seg').forEach(function (seg) {
                    seg.addEventListener('click', function (e) {
                        var b = e.target.closest('button'); if (!b) return;
                        if (seg.dataset.filter === 'ev') {
                            var on = b.getAttribute('aria-pressed') !== 'true';
                            b.setAttribute('aria-pressed', on ? 'true' : 'false');
                            state.ev = Array.prototype.map.call(seg.querySelectorAll('button[aria-pressed=true]'), function (x) { return x.dataset.value; });
                            apply();
                            return;
                        }
                        state[seg.dataset.filter] = b.dataset.value;
                        seg.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
                        apply();
                    });
                });
                search.addEventListener('input', apply);
                rows.forEach(function (r) {
                    if (!r.dataset.href) return;
                    r.addEventListener('click', function () { window.location.assign(r.dataset.href); });
                    r.addEventListener('keydown', function (e) { if (e.key === 'Enter') window.location.assign(r.dataset.href); });
                });
                apply();
            })();
            </script>
            <?php endif; ?>
        </div></section>
        <?php endif; ?>
    <?php elseif ($tab === 'potm'): ?>
        <?php
        csrf_field(); // ensures $_SESSION['csrf_token'] exists for the award-modal fetch below
        $potmTypes = ['player_of_match', 'motm', 'man_of_the_match'];
        $potmCompAbbr = static function (string $c): string {
            return $c === '' ? '' : str_ireplace('West of Scotland Football League', 'WOSFL', $c);
        };
        // Everyone who has ever had an avatar on file, so historic awards (a player
        // who has since left) still get a photo instead of a blank initials circle.
        $potmAvatarByName = [];
        foreach ($pdo->query('SELECT name, avatar FROM players') as $p) {
            $name = trim((string) $p['name']);
            $avatar = basename(trim((string) $p['avatar']));
            if ($name === '' || $avatar === '' || !is_file(__DIR__ . '/uploads/players/' . $avatar)) {
                continue;
            }
            $potmAvatarByName[$name] = '/uploads/players/' . rawurlencode($avatar);
        }
        $potmInitials = static function (string $name): string {
            $initials = '';
            foreach (array_slice(preg_split('/\s+/', $name) ?: [], 0, 2) as $part) {
                $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            }
            return $initials;
        };

        $potmRows = [];
        $potmCandidatesByFixture = [];
        foreach (array_reverse($fixtures) as $f) {
            $fid = (int) $f['id'];
            $player = '';
            foreach ($eventsByFixture[$fid] ?? [] as $event) {
                if (in_array((string) ($event['type'] ?? ''), $potmTypes, true)) {
                    $player = trim((string) ($event['player'] ?? ''));
                    break;
                }
            }
            $potmRows[] = ['fixture' => $f, 'player' => $player];

            $starters = json_decode((string) ($f['starting11_starters_json'] ?? '[]'), true);
            $subs = json_decode((string) ($f['starting11_substitutes_json'] ?? '[]'), true);
            $names = array_values(array_unique(array_filter(array_map(
                static fn ($v): string => trim((string) $v),
                array_merge(is_array($starters) ? $starters : [], is_array($subs) ? $subs : [])
            ), static fn (string $v): bool => $v !== '')));
            $potmCandidatesByFixture[$fid] = array_map(static fn (string $n): array => [
                'name' => $n,
                'avatar' => $potmAvatarByName[$n] ?? '',
                'initials' => $potmInitials($n),
            ], $names);
        }
        $potmAwarded = count(array_filter($potmRows, static fn (array $r): bool => $r['player'] !== ''));
        ?>
        <section class="card shadow-sm">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h2 class="h5 mb-1">Player of the Match awards</h2>
                    <p class="text-muted small mb-0"><?= $potmAwarded ?> of <?= count($potmRows) ?> match<?= count($potmRows) === 1 ? '' : 'es' ?> in this selection <?= count($potmRows) === 1 ? 'has' : 'have' ?> a Player of the Match recorded.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 hub-data-table potm-table">
                    <thead><tr><th scope="col">Date</th><th scope="col">Fixture</th><th scope="col" class="text-center">Venue</th><th scope="col">Competition</th><th scope="col">Player of the Match</th></tr></thead>
                    <tbody>
                    <?php foreach ($potmRows as $row): $f = $row['fixture']; $fid = (int) $f['id']; $isHome = (int) $f['is_home'] === 1; $hasCandidates = $potmCandidatesByFixture[$fid] !== []; ?>
                        <tr>
                            <td class="text-nowrap"><?= $esc(date('j M Y', strtotime($f['match_date']))) ?></td>
                            <th scope="row"><?= $esc($f['opponent']) ?></th>
                            <td class="text-center">
                                <span class="matches-venue-icon <?= $isHome ? 'is-home' : 'is-away' ?>" title="<?= $isHome ? 'Home' : 'Away' ?>">
                                    <i class="fa-solid <?= $isHome ? 'fa-house' : 'fa-bus' ?>" aria-hidden="true"></i>
                                </span>
                            </td>
                            <td><span title="<?= $esc((string) $f['competition']) ?>"><?= $esc($potmCompAbbr((string) ($f['competition'] ?? '')) ?: '—') ?></span></td>
                            <td class="potm-cell" data-fixture-id="<?= $fid ?>" data-opponent="<?= $esc($f['opponent']) ?>">
                                <?php if ($row['player'] !== ''): ?>
                                    <span class="potm-winner"><i class="fa-solid fa-star" aria-hidden="true"></i><?= $esc($row['player']) ?></span>
                                <?php elseif ($hasCandidates): ?>
                                    <button type="button" class="potm-award-btn" data-fixture-id="<?= $fid ?>">Not yet awarded</button>
                                <?php else: ?>
                                    <span class="text-muted">Not yet awarded</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$potmRows): ?><tr><td colspan="5" class="text-muted text-center py-4">No played matches found for this selection.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="modal fade" id="potmAwardModal" tabindex="-1" aria-labelledby="potmAwardModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="potmAwardModalLabel">Player of the Match</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small" id="potmAwardModalSubtitle"></p>
                        <div id="potmAwardModalError" class="alert alert-danger" hidden></div>
                        <div class="potm-grid" id="potmAwardModalGrid" role="radiogroup" aria-label="Select Player of the Match"></div>
                    </div>
                </div>
            </div>
        </div>

        <style>
        .matches-venue-icon { display:inline-grid; place-items:center; width:1.9rem; height:1.9rem; border-radius:50%; font-size:.78rem; }
        .matches-venue-icon.is-home { background:rgba(106,32,54,.10); color:#6a2036; }
        .matches-venue-icon.is-away { background:rgba(0,0,0,.06); color:#555; }
        .potm-winner { display:inline-flex; align-items:center; gap:.4rem; font-weight:600; color:#29151b; }
        .potm-winner i { color:#f0bf1d; }
        .potm-award-btn { padding:0; border:0; background:none; color:#6a2036; font-size:inherit; text-decoration:underline; text-underline-offset:2px; cursor:pointer; }
        .potm-award-btn:hover { color:#40101f; }
        #potmAwardModalGrid .potm-player { min-height:190px; }
        #potmAwardModalGrid .potm-player__card { min-height:190px; }
        #potmAwardModalGrid .potm-player__image { height:135px; }
        </style>
        <script>
        (function () {
            var candidates = <?= json_encode($potmCandidatesByFixture, JSON_UNESCAPED_SLASHES) ?>;
            var csrfToken = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_UNESCAPED_SLASHES) ?>;
            var modalEl = document.getElementById('potmAwardModal');
            var gridEl = document.getElementById('potmAwardModalGrid');
            var subtitleEl = document.getElementById('potmAwardModalSubtitle');
            var errorEl = document.getElementById('potmAwardModalError');
            var titleEl = document.getElementById('potmAwardModalLabel');
            if (!modalEl || !gridEl) return;
            var modal = null;
            var activeFixtureId = null;

            function esc(s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            function renderGrid(fixtureId) {
                var players = candidates[fixtureId] || [];
                gridEl.innerHTML = players.map(function (p) {
                    var image = p.avatar
                        ? '<img src="' + esc(p.avatar) + '" alt="" loading="lazy">'
                        : '<span class="potm-player__initials" aria-hidden="true">' + esc(p.initials) + '</span>';
                    return '<button type="button" class="potm-player" data-player="' + esc(p.name) + '">'
                        + '<span class="potm-player__card">'
                        + '<span class="potm-player__image">' + image + '</span>'
                        + '<span class="potm-player__name">' + esc(p.name) + '</span>'
                        + '</span></button>';
                }).join('');
            }

            document.querySelectorAll('.potm-award-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (!window.bootstrap) return;
                    activeFixtureId = btn.getAttribute('data-fixture-id');
                    var cell = document.querySelector('.potm-cell[data-fixture-id="' + activeFixtureId + '"]');
                    titleEl.textContent = 'Player of the Match';
                    subtitleEl.textContent = cell ? ('Choose the standout player vs ' + cell.getAttribute('data-opponent')) : 'Choose the standout player.';
                    errorEl.hidden = true;
                    renderGrid(activeFixtureId);
                    if (!modal) modal = new bootstrap.Modal(modalEl);
                    modal.show();
                });
            });

            gridEl.addEventListener('click', function (e) {
                var card = e.target.closest('.potm-player');
                if (!card || !activeFixtureId) return;
                var player = card.getAttribute('data-player');
                gridEl.querySelectorAll('.potm-player').forEach(function (c) { c.disabled = true; c.style.opacity = '.6'; });
                errorEl.hidden = true;

                var params = new URLSearchParams();
                params.set('fixture_id', activeFixtureId);
                params.set('player', player);
                params.set('csrf_token', csrfToken);

                fetch('/admin/ajax_potm_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString(),
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.success) {
                            errorEl.textContent = data.error || 'Could not save this award.';
                            errorEl.hidden = false;
                            gridEl.querySelectorAll('.potm-player').forEach(function (c) { c.disabled = false; c.style.opacity = ''; });
                            return;
                        }
                        var cell = document.querySelector('.potm-cell[data-fixture-id="' + activeFixtureId + '"]');
                        if (cell) {
                            cell.innerHTML = '<span class="potm-winner"><i class="fa-solid fa-star" aria-hidden="true"></i>' + esc(data.player) + '</span>';
                        }
                        modal.hide();
                    })
                    .catch(function () {
                        errorEl.textContent = 'Something went wrong saving this award.';
                        errorEl.hidden = false;
                        gridEl.querySelectorAll('.potm-player').forEach(function (c) { c.disabled = false; c.style.opacity = ''; });
                    });
            });
        })();
        </script>
    <?php else: ?>
        <section class="card shadow-sm mb-4"><div class="card-body"><h2 class="h5">Match results</h2><p class="text-muted small mb-0">Scores are shown as home – away. Results are from your club's perspective. Choose a match to see its breakdown.</p></div><div class="table-responsive d-none d-lg-block"><table class="table table-hover align-middle mb-0 hub-data-table"><thead><tr><?php foreach (['Date', 'Opponent', 'Venue', 'Competition', 'Half time', 'Full time', 'Result', ''] as $label): ?><th scope="col" class="text-nowrap"><?php if ($label === ''): ?><span class="visually-hidden">Open breakdown</span><?php else: ?><?= $label ?><?php endif; ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach (array_reverse($fixtures) as $f): $r = hub_stats_result($f); $rowHref = $url(['match_id' => $f['id']]) . '#match-breakdown'; $rowSel = (int)$f['id'] === (int)($_GET['match_id'] ?? 0); ?><tr class="mr-row<?= $rowSel ? ' is-selected' : '' ?>" role="link" tabindex="0" data-href="<?= $esc($rowHref) ?>" aria-label="Open breakdown: <?= $esc($f['opponent']) ?>"><td class="text-nowrap"><?= $esc(date('j M Y', strtotime($f['match_date']))) ?></td><th scope="row"><?= $esc($f['opponent']) ?></th><td><?= (int)$f['is_home'] === 1 ? 'Home' : 'Away' ?></td><td><?= $esc($f['competition'] ?: '—') ?></td><?php foreach (['half_time', 'full_time'] as $period): ?><td class="text-nowrap"><?= isset($f[$period . '_home_score'], $f[$period . '_away_score']) ? (int)$f[$period . '_home_score'] . ' – ' . (int)$f[$period . '_away_score'] : '—' ?></td><?php endforeach; ?><td><span class="badge text-bg-<?= $badge($r['result'] ?? '') ?>"><?= $esc($r['result'] ?? 'No score') ?></span></td><td class="text-end text-muted"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="d-lg-none mr-cards">
        <?php foreach (array_reverse($fixtures) as $f): $r = hub_stats_result($f); $isSel = (int)$f['id'] === (int)($_GET['match_id'] ?? 0); ?>
            <a class="mr-card<?= $isSel ? ' is-selected' : '' ?>" href="<?= $esc($url(['match_id' => $f['id']])) ?>#match-breakdown">
                <span class="mr-card__date"><strong><?= $esc(date('j', strtotime($f['match_date']))) ?></strong><span><?= $esc(date('M', strtotime($f['match_date']))) ?></span></span>
                <span class="mr-card__main">
                    <span class="mr-card__opp"><?= $esc($f['opponent']) ?></span>
                    <span class="mr-card__meta"><?= (int)$f['is_home'] === 1 ? 'Home' : 'Away' ?><?= !empty($f['competition']) ? ' · ' . $esc($f['competition']) : '' ?></span>
                </span>
                <span class="mr-card__score">
                    <?php if (isset($f['full_time_home_score'], $f['full_time_away_score'])): ?><strong><?= (int)$f['full_time_home_score'] ?> – <?= (int)$f['full_time_away_score'] ?></strong><?php endif; ?>
                    <span class="badge text-bg-<?= $badge($r['result'] ?? '') ?>"><?= $esc($r['result'] ?? '—') ?></span>
                </span>
            </a>
        <?php endforeach; ?>
        </div>
        <style>
        .mr-row{cursor:pointer}.mr-row:hover td,.mr-row:hover th{background:rgba(106,32,54,.05)}
        .mr-row.is-selected td,.mr-row.is-selected th{background:rgba(106,32,54,.08)}
        .mr-row:focus-visible{outline:2px solid #6a2036;outline-offset:-2px}
        .mr-cards{display:flex;flex-direction:column;gap:.5rem;padding:0 1rem 1rem}
        .mr-card{display:flex;align-items:center;gap:.8rem;padding:.6rem .75rem;border:1px solid var(--hub-line,#eadfdf);border-radius:.8rem;background:#fff;text-decoration:none;color:inherit}
        .mr-card.is-selected{border-color:#6a2036;box-shadow:0 0 0 2px rgba(106,32,54,.15)}
        .mr-card__date{flex:0 0 2.7rem;display:flex;flex-direction:column;align-items:center;line-height:1.05;padding:.35rem 0;border-radius:.6rem;background:rgba(106,32,54,.07);color:#6a2036}
        .mr-card__date strong{font-size:1.15rem}.mr-card__date span{font-size:.65rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
        .mr-card__main{flex:1;min-width:0;display:flex;flex-direction:column}
        .mr-card__opp{font-weight:700;overflow-wrap:anywhere}.mr-card__meta{font-size:.76rem;color:var(--hub-muted,#70676b);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .mr-card__score{display:flex;flex-direction:column;align-items:flex-end;gap:.15rem;font-variant-numeric:tabular-nums;white-space:nowrap}
        </style>
        </section>
        <script>
        document.querySelectorAll('.mr-row[data-href]').forEach(function (row) {
            function go() { window.location.assign(row.dataset.href); }
            row.addEventListener('click', function (e) { if (!e.target.closest('a,button')) { go(); } });
            row.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
        });
        </script>
        <?php
        $selected = null;
        $matchId = (int)($_GET['match_id'] ?? 0);
        foreach ($fixtures as $f) if ((int)$f['id'] === $matchId) $selected = $f;
        if (!$selected) $selected = $fixtures[count($fixtures) - 1];
        $events = $eventsByFixture[(int)$selected['id']] ?? [];
        usort($events, static fn(array $a, array $b): int => (int)($a['sequence'] ?? 0) <=> (int)($b['sequence'] ?? 0));
        ?>
        <?php
        require_once __DIR__ . '/lib/site_settings.php';
        try { $mbClub = trim(site_setting($pdo, 'club_name', 'Saltcoats Victoria')) ?: 'Saltcoats Victoria'; } catch (Throwable $e) { $mbClub = 'Saltcoats Victoria'; }
        $mbHomeIsUs = (int)$selected['is_home'] === 1;
        $mbHomeName = $mbHomeIsUs ? $mbClub : (string)$selected['opponent'];
        $mbAwayName = $mbHomeIsUs ? (string)$selected['opponent'] : $mbClub;
        $mbHasFt = isset($selected['full_time_home_score'], $selected['full_time_away_score']);
        $mbHasHt = isset($selected['half_time_home_score'], $selected['half_time_away_score']);
        $mbResult = $mbHasFt ? hub_stats_result($selected) : null;
        $mbTypes = [
            'goal' => ['Goal', 'fa-futbol', 'goal'], 'own_goal' => ['Own goal', 'fa-futbol', 'owngoal'], 'penalty_goal' => ['Penalty goal', 'fa-futbol', 'goal'],
            'yellow_card' => ['Yellow card', 'fa-square', 'yellow'], 'red_card' => ['Red card', 'fa-square', 'red'], 'second_yellow' => ['Second yellow', 'fa-square', 'red'],
            'substitution' => ['Substitution', 'fa-right-left', 'sub'],
        ];
        $mbHalves = ['First half' => [], 'Second half' => []];
        foreach ($events as $event) {
            $mbHalves[((int)($event['minute'] ?? 0)) > 45 ? 'Second half' : 'First half'][] = $event;
        }
        ?>
        <style>
        .mb-card{background:var(--hub-surface,#fffdf9);border:1px solid var(--hub-line,rgba(75,8,24,.12));border-radius:var(--hub-radius,1rem);box-shadow:var(--hub-shadow-sm,none);overflow:hidden}
        .mb-head{padding:1.1rem 1.25rem 1.25rem;background:linear-gradient(135deg,#4c1521,#6d2231);color:#fff}
        .mb-meta{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;font-size:.78rem;letter-spacing:.04em;text-transform:uppercase;opacity:.9}
        .mb-meta .btn{--bs-btn-padding-y:.2rem;--bs-btn-font-size:.78rem;text-transform:none;letter-spacing:0}
        .mb-board{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:1rem;margin-top:.9rem}
        .mb-team{font-size:1.15rem;font-weight:800;line-height:1.2}
        .mb-team--home{text-align:right}.mb-team--away{text-align:left}
        .mb-team small{display:block;font-size:.68rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.7}
        .mb-score{text-align:center}
        .mb-ft{display:inline-block;padding:.2rem .9rem;border-radius:.7rem;background:rgba(255,255,255,.14);font-size:2rem;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap}
        .mb-ht{display:block;margin-top:.3rem;font-size:.75rem;opacity:.8}
        .mb-chip{display:inline-block;margin-top:.35rem;padding:.1rem .65rem;border-radius:999px;font-size:.72rem;font-weight:800;letter-spacing:.06em}
        .mb-chip--W{background:#2f7d55}.mb-chip--D{background:#8a8a8a}.mb-chip--L{background:#b23b3b}
        .mb-body{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(0,1fr);gap:1.5rem;padding:1.25rem}
        .mb-section-title{margin:0 0 .75rem;font-size:.72rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase;color:var(--hub-muted,#70676b)}
        .mb-half{margin-bottom:1.25rem}.mb-half:last-child{margin-bottom:0}
        .mb-half__label{display:inline-block;margin:0 0 .6rem;padding:.2rem .7rem;border-radius:999px;background:rgba(106,32,54,.08);color:#6a2036;font-size:.72rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
        .mb-events{list-style:none;margin:0;padding:0;position:relative}
        .mb-events::before{content:"";position:absolute;top:.3rem;bottom:.3rem;left:50%;width:2px;margin-left:-1px;background:var(--hub-line,#eadfdf)}
        .mb-ev{display:grid;grid-template-columns:minmax(0,1fr) 3.6rem minmax(0,1fr);align-items:center;margin-bottom:.5rem;position:relative}
        .mb-min{grid-column:2;grid-row:1;justify-self:center;position:relative;z-index:1;min-width:2.6rem;padding:.12rem .4rem;text-align:center;border-radius:999px;background:#fff;border:2px solid var(--hub-line-strong,#d9c9cc);font-size:.76rem;font-weight:800;font-variant-numeric:tabular-nums}
        .mb-ev__card{grid-row:1;display:flex;align-items:flex-start;gap:.55rem;padding:.5rem .7rem;border:1px solid var(--hub-line,#eadfdf);border-radius:.7rem;background:#fff;min-width:0}
        .mb-ev--home .mb-ev__card{grid-column:1;justify-self:end;flex-direction:row-reverse;text-align:right}
        .mb-ev--away .mb-ev__card{grid-column:3;justify-self:start}
        .mb-ev--neutral .mb-ev__card{grid-column:1 / -1;justify-self:center}
        .mb-ev--ours .mb-ev__card{border-color:rgba(106,32,54,.28);background:rgba(106,32,54,.035)}
        .mb-ico{flex:none;display:grid;place-items:center;width:1.7rem;height:1.7rem;border-radius:50%;font-size:.8rem;background:#f1ebe6;color:#555}
        .mb-ico--goal{background:rgba(47,125,85,.15);color:#23653f}.mb-ico--owngoal{background:rgba(178,59,59,.13);color:#a32a3a}
        .mb-ico--yellow{background:rgba(240,185,11,.2);color:#b88400}.mb-ico--red{background:rgba(178,59,59,.15);color:#b23b3b}
        .mb-ico--sub{background:rgba(39,104,132,.13);color:#276884}
        .mb-ev__text{min-width:0;line-height:1.25}.mb-ev__type{display:block;font-size:.68rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--hub-muted,#70676b)}
        .mb-ev__player{font-weight:700;overflow-wrap:anywhere}.mb-ev__note{display:block;font-size:.78rem;color:var(--hub-muted,#70676b)}
        .mb-ev__team{display:none;font-size:.7rem;font-weight:700;color:var(--hub-muted,#70676b)}
        .mb-sub-line{display:block;font-size:.82rem}.mb-sub-line b{font-weight:700}.mb-on{color:#23653f}.mb-off{color:#a32a3a}
        .mb-lineups{display:flex;flex-direction:column;gap:1.25rem}
        .mb-lineup{margin:0;padding:0;list-style:none;display:grid;gap:.3rem}
        .mb-lineup li{display:flex;align-items:center;gap:.6rem;padding:.4rem .6rem;border-radius:.6rem;background:rgba(75,8,24,.04);font-weight:600}
        .mb-lineup li span{flex:none;display:grid;place-items:center;width:1.5rem;height:1.5rem;border-radius:50%;background:#6a2036;color:#fff;font-size:.7rem;font-weight:800}
        .mb-lineup--subs li span{background:#8d7f83}
        .mb-lineup li em{font-style:normal;flex:1;min-width:0;overflow-wrap:anywhere}
        .mb-tag{flex:none;display:inline-flex;align-items:center;gap:.25rem;padding:.1rem .5rem;border-radius:999px;font-size:.68rem;font-weight:800;letter-spacing:.03em;white-space:nowrap}
        .mb-tag--on{background:rgba(47,125,85,.14);color:#23653f}.mb-tag--off{background:rgba(178,59,59,.13);color:#a32a3a}
        .mb-tag--unused,.mb-tag--full{background:rgba(75,8,24,.06);color:var(--hub-muted,#70676b);font-weight:700}
        .mb-empty{margin:0;color:var(--hub-muted,#70676b)}
        .mb-stats{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.35fr);gap:1.5rem;padding:1.25rem;border-top:1px solid var(--hub-line,#eadfdf);background:rgba(75,8,24,.02)}
        .mb-vs__head{display:flex;justify-content:space-between;gap:1rem;margin-bottom:.4rem;font-size:.78rem;font-weight:800;color:var(--hub-ink,#1f1a1d)}
        .mb-vs__row{display:grid;grid-template-columns:1.6rem 1fr 1.6rem;align-items:center;gap:.6rem;margin-bottom:.7rem}
        .mb-vs__v{font-size:1.05rem;text-align:center;font-variant-numeric:tabular-nums}
        .mb-vs__label{display:block;text-align:center;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--hub-muted,#70676b);margin-bottom:.2rem}
        .mb-vs__bar{display:flex;gap:3px;height:.55rem}
        .mb-vs__bar i{display:block;border-radius:999px;background:#cfc6c8;min-width:0}
        .mb-vs__bar i.is-us{background:#6a2036}
        .mb-bands{display:grid;grid-template-columns:repeat(6,1fr);gap:.4rem;align-items:end;height:8.5rem;padding-top:.5rem}
        .mb-band{display:flex;flex-direction:column;align-items:center;height:100%}
        .mb-band__bars{flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center;gap:3px}
        .mb-band__bar{display:flex;align-items:flex-start;justify-content:center;width:26%;max-width:1.2rem;min-height:2px;border-radius:.3rem .3rem 0 0;font-style:normal;font-size:.65rem;font-weight:800;color:#fff;line-height:1.3}
        .mb-band__bar--us{background:#6a2036}.mb-band__bar--them{background:#9b9094}.mb-band__bar--card{background:#e0b42a;color:#5c4400}
        .mb-band__label{margin-top:.35rem;font-size:.66rem;font-weight:700;color:var(--hub-muted,#70676b)}
        .mb-legend,.mb-gantt__foot{display:flex;flex-wrap:wrap;gap:.35rem 1rem;margin:.7rem 0 0;font-size:.74rem;color:var(--hub-muted,#70676b)}
        .mb-legend span,.mb-gantt__foot span{display:inline-flex;align-items:center;gap:.35rem}
        .lg{display:inline-block;width:.7rem;height:.7rem;border-radius:.2rem}.lg--us{background:#6a2036}.lg--them{background:#9b9094}.lg--card{background:#e0b42a}.lg--start{background:#6a2036}.lg--sub{background:#2f7d55}
        .mk-goal{color:#23653f}.mk-assist{color:#276884}.mk-yellow{color:#e0b42a}.mk-red{color:#b23b3b}
        .mb-gantt__axis{position:relative;height:1.1rem;margin-left:0;font-size:.66rem;font-weight:700;color:var(--hub-muted,#70676b)}
        .mb-gantt__axis span{position:absolute;transform:translateX(-50%)}.mb-gantt__axis span:first-child{transform:none}.mb-gantt__axis span:last-child{transform:translateX(-100%)}
        .mb-gantt__row{padding:.45rem 0;border-top:1px solid var(--hub-line,#eadfdf)}
        .mb-gantt__who{display:flex;align-items:baseline;gap:.5rem;margin-bottom:.3rem}
        .mb-gantt__who strong{font-size:.88rem;min-width:0;overflow-wrap:anywhere}.mb-gantt__who small{font-size:.66rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--hub-muted,#70676b)}
        .mb-gantt__mins{margin-left:auto;font-size:.88rem;font-variant-numeric:tabular-nums;color:#6a2036}
        .mb-gantt__track{position:relative;height:1.35rem;border-radius:.4rem;background:
            linear-gradient(to right,transparent calc(50% - 1px),rgba(75,8,24,.28) calc(50% - 1px),rgba(75,8,24,.28) calc(50% + 1px),transparent calc(50% + 1px)),
            repeating-linear-gradient(to right,rgba(75,8,24,.07) 0,rgba(75,8,24,.07) 1px,transparent 1px,transparent 16.666%),rgba(75,8,24,.05)}
        .mb-gantt__bar{position:absolute;top:.2rem;bottom:.2rem;border-radius:999px;background:#6a2036}.mb-gantt__bar--sub{background:#2f7d55}
        .mb-mk{position:absolute;top:50%;transform:translate(-50%,-50%);display:grid;place-items:center;width:1.15rem;height:1.15rem;border-radius:50%;background:#fff;font-size:.62rem;box-shadow:0 0 0 1.5px rgba(31,26,29,.25)}
        .mb-mk--goal{color:#23653f}.mb-mk--assist{color:#276884}.mb-mk--yellow{color:#e0b42a}.mb-mk--red{color:#b23b3b}
        .mb-gantt__unused{margin:.6rem 0 0;font-size:.8rem;color:var(--hub-muted,#70676b)}
        .mb-mini,.mb-mins{width:100%;border-collapse:collapse;font-size:.88rem}
        .mb-mini th,.mb-mini td,.mb-mins th,.mb-mins td{padding:.45rem .5rem;border-bottom:1px solid var(--hub-line,#eadfdf);text-align:left;vertical-align:middle}
        .mb-mini thead th,.mb-mins thead th{font-size:.66rem;font-weight:800;letter-spacing:.07em;text-transform:uppercase;color:var(--hub-muted,#70676b);border-bottom-width:2px}
        .mb-mini thead th small{text-transform:none;letter-spacing:0;font-weight:600}
        .mb-mini--center th,.mb-mini--center td{text-align:center!important}.mb-mini tbody th{font-weight:700;white-space:nowrap}.mb-mini td{font-variant-numeric:tabular-nums}
        .mb-mins tbody th{font-weight:600}.mb-mins tbody tr:last-child th,.mb-mins tbody tr:last-child td,.mb-mini tbody tr:last-child th,.mb-mini tbody tr:last-child td{border-bottom:0}
        .mb-subtag{margin-left:.35rem;padding:.05rem .4rem;border-radius:999px;background:rgba(47,125,85,.14);color:#23653f;font-size:.62rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase}
        .mb-mins td strong{font-variant-numeric:tabular-nums}
        .mb-mins__notes{display:flex;flex-wrap:wrap;align-items:center;gap:.25rem .5rem}
        .mb-mins__span{font-size:.76rem;color:var(--hub-muted,#70676b)}.mb-mins__span.is-part{color:#a32a3a;font-weight:700}
        .mb-chipmk{display:inline-flex;align-items:center;gap:.25rem;padding:.05rem .4rem;border-radius:999px;background:rgba(75,8,24,.06);font-size:.7rem;font-weight:700}
        @media (max-width:991.98px){.mb-stats{grid-template-columns:1fr}}
        @media (max-width:767.98px){.mb-stats{padding:1rem}}
        @media (max-width:991.98px){.mb-body{grid-template-columns:1fr}}
        @media (max-width:767.98px){
            .mb-head{padding:1rem}.mb-board{gap:.5rem}.mb-team{font-size:.95rem}.mb-ft{font-size:1.5rem;padding:.15rem .6rem}
            .mb-body{padding:1rem}
            .mb-events::before{left:1.3rem}
            .mb-ev{grid-template-columns:2.6rem minmax(0,1fr);gap:.6rem;align-items:flex-start}
            .mb-min{grid-column:1;min-width:2.6rem;margin-top:.25rem}
            .mb-ev--home .mb-ev__card,.mb-ev--away .mb-ev__card,.mb-ev--neutral .mb-ev__card{grid-column:2;grid-row:1;justify-self:stretch;flex-direction:row;text-align:left}
            .mb-ev__team{display:block}
        }
        </style>
        <section class="mb-card" id="match-breakdown" aria-label="Match breakdown">
            <header class="mb-head">
                <div class="mb-meta">
                    <span><?= $esc(date('D j M Y', strtotime($selected['match_date']))) ?><?= !empty($selected['competition']) ? ' · ' . $esc($selected['competition']) : '' ?> · <?= $mbHomeIsUs ? 'Home' : 'Away' ?></span>
                    <a class="btn btn-light btn-sm" href="/admin/match.php?id=<?= (int)$selected['id'] ?>&amp;season_id=<?= $seasonId ?>">Open match</a>
                </div>
                <div class="mb-board">
                    <div class="mb-team mb-team--home"><?= $esc($mbHomeName) ?><small>Home</small></div>
                    <div class="mb-score">
                        <?php if ($mbHasFt): ?>
                            <span class="mb-ft"><?= (int)$selected['full_time_home_score'] ?> – <?= (int)$selected['full_time_away_score'] ?></span>
                            <?php if ($mbHasHt): ?><span class="mb-ht">Half time <?= (int)$selected['half_time_home_score'] ?> – <?= (int)$selected['half_time_away_score'] ?></span><?php endif; ?>
                            <?php if ($mbResult): ?><span class="mb-chip mb-chip--<?= $esc($mbResult['result']) ?>"><?= ['W' => 'WIN', 'D' => 'DRAW', 'L' => 'LOSS'][$mbResult['result']] ?? '' ?></span><?php endif; ?>
                        <?php else: ?>
                            <span class="mb-ft">v</span>
                        <?php endif; ?>
                    </div>
                    <div class="mb-team mb-team--away"><?= $esc($mbAwayName) ?><small>Away</small></div>
                </div>
            </header>

            <div class="mb-body">
                <div>
                    <h3 class="mb-section-title">Match timeline</h3>
                    <?php if (!$events): ?><p class="mb-empty">No match events recorded yet.</p><?php else: ?>
                        <?php foreach ($mbHalves as $halfLabel => $halfEvents): if (!$halfEvents) continue; ?>
                        <div class="mb-half">
                            <span class="mb-half__label"><?= $esc($halfLabel) ?></span>
                            <ol class="mb-events">
                            <?php foreach ($halfEvents as $event):
                                $type = (string)($event['type'] ?? 'event');
                                [$tLabel, $tIcon, $tTone] = $mbTypes[$type] ?? [ucwords(str_replace('_', ' ', $type)), 'fa-circle', 'other'];
                                $team = (string)($event['team'] ?? '');
                                $ours = $team === 'svfc';
                                $isOpp = $team === 'opponent';
                                $side = $team === '' ? 'neutral' : (($ours === $mbHomeIsUs) ? 'home' : 'away');
                                $teamName = $ours ? $mbClub : ($isOpp ? (string)$selected['opponent'] : '');
                            ?>
                                <li class="mb-ev mb-ev--<?= $side ?><?= $ours ? ' mb-ev--ours' : '' ?>">
                                    <span class="mb-min"><?= $esc(($event['minute'] ?? '') !== '' ? $event['minute'] . '′' : '–') ?></span>
                                    <div class="mb-ev__card">
                                        <span class="mb-ico mb-ico--<?= $tTone ?>"><i class="fa-solid <?= $tIcon ?>" aria-hidden="true"></i></span>
                                        <div class="mb-ev__text">
                                            <span class="mb-ev__type"><?= $esc($tLabel) ?></span>
                                            <?php if ($type !== 'substitution' && !empty($event['player'])): ?><span class="mb-ev__player"><?= $esc($event['player']) ?></span><?php endif; ?>
                                            <?php if ($type !== 'substitution' && !empty($event['secondary_player'])): ?><span class="mb-ev__note">Assist: <?= $esc($event['secondary_player']) ?></span><?php endif; ?>
                                            <?php if ($type === 'substitution'):
                                                $changes = is_array($event['substitutions'] ?? null) ? $event['substitutions'] : [];
                                                if (!$changes && (!empty($event['player']) || !empty($event['secondary_player']))) { $changes = [['off' => $event['player'] ?? '', 'on' => $event['secondary_player'] ?? '']]; }
                                                foreach ($changes as $change): if (!is_array($change)) continue; ?>
                                                    <span class="mb-sub-line"><b class="mb-on">▲</b> <?= $esc($change['on'] ?? '') ?> <b class="mb-off">▼</b> <?= $esc($change['off'] ?? '') ?></span>
                                                <?php endforeach; endif; ?>
                                            <?php if (!empty($event['outcome'])): ?><span class="mb-ev__note"><?= $esc(ucfirst((string)$event['outcome'])) ?></span><?php endif; ?>
                                            <?php if (!empty($event['note'])): ?><span class="mb-ev__note"><?= $esc((string)$event['note']) ?></span><?php endif; ?>
                                            <?php if ($teamName !== ''): ?><span class="mb-ev__team"><?= $esc($teamName) ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                            </ol>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php
                // Who came on / went off for our team, from the substitution events.
                $mbNorm = static fn($n): string => mb_strtolower(trim((string)$n));
                $mbOff = $mbOn = [];
                foreach ($events as $event) {
                    if (($event['type'] ?? '') !== 'substitution' || ($event['team'] ?? '') !== 'svfc') continue;
                    $min = ($event['minute'] ?? '') !== '' ? $event['minute'] . '′' : '';
                    $changes = is_array($event['substitutions'] ?? null) ? $event['substitutions'] : [];
                    if (!$changes && (!empty($event['player']) || !empty($event['secondary_player']))) { $changes = [['off' => $event['player'] ?? '', 'on' => $event['secondary_player'] ?? '']]; }
                    foreach ($changes as $change) {
                        if (!is_array($change)) continue;
                        if (!empty($change['off'])) $mbOff[$mbNorm($change['off'])] = $min;
                        if (!empty($change['on'])) $mbOn[$mbNorm($change['on'])] = $min;
                    }
                }
                ?>
                <aside class="mb-lineups" aria-label="Line-up">
                    <?php foreach (['starting11_starters_json' => ['Starting XI', ''], 'starting11_substitutes_json' => ['Substitutes', 'mb-lineup--subs']] as $field => [$label, $extra]):
                        $names = json_decode((string)($selected[$field] ?? '[]'), true);
                        $names = is_array($names) ? array_values(array_filter($names, static fn($n) => is_string($n) && trim($n) !== '')) : []; ?>
                        <div>
                            <h3 class="mb-section-title"><?= $label ?> · <?= $esc($mbClub) ?></h3>
                            <?php if (!$names): ?><p class="mb-empty">No lineup recorded.</p><?php else: ?>
                                <ul class="mb-lineup <?= $extra ?>"><?php foreach ($names as $i => $name): $key = $mbNorm($name);
                                    $isSubList = $field === 'starting11_substitutes_json';
                                    $wentOff = !$isSubList && isset($mbOff[$key]);
                                    $cameOn = $isSubList && isset($mbOn[$key]);
                                    $alsoOff = $isSubList && $cameOn && isset($mbOff[$key]); ?>
                                    <li><span><?= $i + 1 ?></span><em><?= $esc($name) ?></em>
                                        <?php if ($wentOff): ?><b class="mb-tag mb-tag--off" title="Substituted off"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i> Off<?= $mbOff[$key] !== '' ? ' ' . $esc($mbOff[$key]) : '' ?></b>
                                        <?php elseif ($cameOn): ?><b class="mb-tag mb-tag--on" title="Came on as a substitute"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i> On <?= $esc($mbOn[$key]) ?></b><?php if ($alsoOff): ?><b class="mb-tag mb-tag--off"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i> Off <?= $esc($mbOff[$key]) ?></b><?php endif; ?>
                                        <?php elseif ($isSubList): ?><b class="mb-tag mb-tag--unused">Not used</b>
                                        <?php else: ?><b class="mb-tag mb-tag--full">Full match</b><?php endif; ?>
                                    </li><?php endforeach; ?></ul>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </aside>
            </div>

            <?php
            // ---- Match statistics, derived from the recorded events and line-up ----
            $mbTotal = 90;
            foreach ($events as $ev) { if ((int)($ev['minute'] ?? 0) > $mbTotal) $mbTotal = (int)$ev['minute']; }
            $mbPct = static fn(int $m): float => round(min(100, max(0, $m / $mbTotal * 100)), 2);
            $mbMin = static fn($m): ?int => ($m === null || $m === '') ? null : (int)$m;

            // Team comparison counters: [ours, theirs]
            $mbCount = ['goals' => [0, 0], 'g1' => [0, 0], 'g2' => [0, 0], 'yellow' => [0, 0], 'red' => [0, 0], 'subs' => [0, 0]];
            $mbBands = [[0, 15], [16, 30], [31, 45], [46, 60], [61, 75], [76, 999]];
            $mbBandLabels = ['0–15', '16–30', '31–45', '46–60', '61–75', '76+'];
            $mbBandData = array_fill(0, 6, ['goals_us' => 0, 'goals_them' => 0, 'cards' => 0]);
            $mbMarks = [];
            foreach ($events as $ev) {
                $team = (string)($ev['team'] ?? '');
                if ($team !== 'svfc' && $team !== 'opponent') continue;
                $idx = $team === 'svfc' ? 0 : 1;
                $type = (string)($ev['type'] ?? '');
                $m = (int)($ev['minute'] ?? 0);
                $band = 5; foreach ($mbBands as $bi => [$lo, $hi]) { if ($m >= $lo && $m <= $hi) { $band = $bi; break; } }
                if (in_array($type, ['goal', 'own_goal', 'penalty_goal'], true)) {
                    $mbCount['goals'][$idx]++; $mbCount[$m > 45 ? 'g2' : 'g1'][$idx]++;
                    $mbBandData[$band][$idx === 0 ? 'goals_us' : 'goals_them']++;
                    if ($idx === 0) {
                        if (!empty($ev['player']) && $type !== 'own_goal') $mbMarks[$mbNorm($ev['player'])][] = ['goal', $m];
                        if (!empty($ev['secondary_player'])) $mbMarks[$mbNorm($ev['secondary_player'])][] = ['assist', $m];
                    }
                } elseif ($type === 'yellow_card' || $type === 'second_yellow') {
                    $mbCount['yellow'][$idx]++; $mbBandData[$band]['cards']++;
                    if ($idx === 0 && !empty($ev['player'])) $mbMarks[$mbNorm($ev['player'])][] = ['yellow', $m];
                } elseif ($type === 'red_card') {
                    $mbCount['red'][$idx]++; $mbBandData[$band]['cards']++;
                    if ($idx === 0 && !empty($ev['player'])) $mbMarks[$mbNorm($ev['player'])][] = ['red', $m];
                } elseif ($type === 'substitution') {
                    $changes = is_array($ev['substitutions'] ?? null) ? count($ev['substitutions']) : 0;
                    $mbCount['subs'][$idx] += max(1, $changes);
                }
            }
            // Player minutes (our team)
            $mbStarters = json_decode((string)($selected['starting11_starters_json'] ?? '[]'), true);
            $mbSubsList = json_decode((string)($selected['starting11_substitutes_json'] ?? '[]'), true);
            $mbStarters = is_array($mbStarters) ? array_values(array_filter($mbStarters, static fn($n) => is_string($n) && trim($n) !== '')) : [];
            $mbSubsList = is_array($mbSubsList) ? array_values(array_filter($mbSubsList, static fn($n) => is_string($n) && trim($n) !== '')) : [];
            $mbRedAt = [];
            foreach ($mbMarks as $k => $list) { foreach ($list as [$kind, $mm]) { if ($kind === 'red') $mbRedAt[$k] = $mm; } }
            $mbPlayers = [];
            foreach ($mbStarters as $n) {
                $k = $mbNorm($n);
                $end = $mbTotal;
                if (isset($mbOff[$k]) && $mbMin(rtrim($mbOff[$k], '′')) !== null) $end = (int)rtrim($mbOff[$k], '′');
                if (isset($mbRedAt[$k])) $end = min($end, $mbRedAt[$k]);
                $mbPlayers[] = ['name' => $n, 'role' => 'Starter', 'start' => 0, 'end' => $end, 'key' => $k];
            }
            $mbUnused = [];
            foreach ($mbSubsList as $n) {
                $k = $mbNorm($n);
                if (!isset($mbOn[$k])) { $mbUnused[] = $n; continue; }
                $start = $mbMin(rtrim($mbOn[$k], '′'));
                if ($start === null) { continue; }
                $end = $mbTotal;
                if (isset($mbOff[$k]) && $mbMin(rtrim($mbOff[$k], '′')) !== null) $end = (int)rtrim($mbOff[$k], '′');
                if (isset($mbRedAt[$k])) $end = min($end, $mbRedAt[$k]);
                $mbPlayers[] = ['name' => $n, 'role' => 'Sub', 'start' => $start, 'end' => max($start, $end), 'key' => $k];
            }
            $mbTotalMinutes = array_sum(array_map(static fn($pl) => $pl['end'] - $pl['start'], $mbPlayers));
            $mbRows = [
                ['Goals', 'goals'], ['First-half goals', 'g1'], ['Second-half goals', 'g2'],
                ['Yellow cards', 'yellow'], ['Red cards', 'red'], ['Substitutions', 'subs'],
            ];
            $mbMaxBand = max(1, ...array_map(static fn($b) => max($b['goals_us'], $b['goals_them'], $b['cards']), $mbBandData));
            ?>
            <div class="mb-stats">
                <div class="mb-stats__col">
                    <h3 class="mb-section-title">Head to head</h3>
                    <div class="mb-vs">
                        <div class="mb-vs__head"><span><?= $esc($mbHomeName) ?></span><span><?= $esc($mbAwayName) ?></span></div>
                        <?php foreach ($mbRows as [$vsLabel, $vsKey]):
                            $ours = $mbCount[$vsKey][0]; $theirs = $mbCount[$vsKey][1];
                            $homeV = $mbHomeIsUs ? $ours : $theirs; $awayV = $mbHomeIsUs ? $theirs : $ours;
                            $sum = max(1, $homeV + $awayV);
                            $ourSideHome = $mbHomeIsUs; ?>
                            <div class="mb-vs__row">
                                <strong class="mb-vs__v"><?= $homeV ?></strong>
                                <div class="mb-vs__mid">
                                    <span class="mb-vs__label"><?= $esc($vsLabel) ?></span>
                                    <div class="mb-vs__bar">
                                        <i class="mb-vs__home <?= $mbHomeIsUs ? 'is-us' : '' ?>" style="width:<?= round($homeV / $sum * 100, 1) ?>%"></i>
                                        <i class="mb-vs__away <?= $mbHomeIsUs ? '' : 'is-us' ?>" style="width:<?= round($awayV / $sum * 100, 1) ?>%"></i>
                                    </div>
                                </div>
                                <strong class="mb-vs__v"><?= $awayV ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <h3 class="mb-section-title mt-4">When it happened</h3>
                    <?php $mbActive = array_filter($mbBandData, static fn($bd) => $bd['goals_us'] || $bd['goals_them'] || $bd['cards']); ?>
                    <?php if (!$mbActive): ?><p class="mb-empty">No goals or cards recorded.</p><?php else: ?>
                    <table class="mb-mini mb-mini--center">
                        <thead><tr><th>Minutes</th><th>Goals <small>(us – them)</small></th><th>Cards</th></tr></thead>
                        <tbody>
                        <?php foreach ($mbActive as $bi => $bd): ?>
                            <tr><th scope="row"><?= $mbBandLabels[$bi] ?><?= $bi < 5 ? '′' : '' ?></th>
                                <td><?= ($bd['goals_us'] || $bd['goals_them']) ? '<strong>' . $bd['goals_us'] . ' – ' . $bd['goals_them'] . '</strong>' : '<span class="text-muted">–</span>' ?></td>
                                <td><?= $bd['cards'] ? str_repeat('<i class="fa-solid fa-square mk-yellow" aria-hidden="true"></i> ', 0) . '<strong>' . $bd['cards'] . '</strong>' : '<span class="text-muted">–</span>' ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <div class="mb-stats__col">
                    <h3 class="mb-section-title">Minutes played · <?= $esc($mbClub) ?></h3>
                    <?php if (!$mbPlayers): ?><p class="mb-empty">No line-up recorded, so minutes can't be worked out.</p><?php else: ?>
                    <table class="mb-mins">
                        <thead><tr><th>Player</th><th class="text-end">Minutes</th><th>Notes</th></tr></thead>
                        <tbody>
                        <?php foreach ($mbPlayers as $pl): $mins = $pl['end'] - $pl['start']; $marks = $mbMarks[$pl['key']] ?? [];
                            $span = $mins >= $mbTotal ? 'Full match' : ($pl['start'] > 0 && $pl['end'] >= $mbTotal ? 'On ' . $pl['start'] . '′' : ($pl['start'] > 0 ? $pl['start'] . '′–' . $pl['end'] . '′' : 'Off ' . $pl['end'] . '′')); ?>
                            <tr>
                                <th scope="row"><?= $esc($pl['name']) ?><?= $pl['role'] === 'Sub' ? ' <small class="mb-subtag">Sub</small>' : '' ?></th>
                                <td class="text-end"><strong><?= $mins ?>′</strong></td>
                                <td><div class="mb-mins__notes">
                                    <span class="mb-mins__span<?= str_starts_with($span, 'Off') ? ' is-part' : '' ?>"><?= $esc($span) ?></span>
                                    <?php foreach ($marks as [$kind, $mm]): $icon = ['goal' => 'fa-futbol', 'assist' => 'fa-shoe-prints', 'yellow' => 'fa-square', 'red' => 'fa-square'][$kind]; $lab = ['goal' => 'Goal', 'assist' => 'Assist', 'yellow' => 'Yellow card', 'red' => 'Red card'][$kind]; ?>
                                        <span class="mb-chipmk" title="<?= $lab ?> <?= $mm ?>′"><i class="fa-solid <?= $icon ?> mk-<?= $kind ?>" aria-hidden="true"></i><?= $mm ?>′</span>
                                    <?php endforeach; ?>
                                </div></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if ($mbUnused): ?><p class="mb-gantt__unused"><strong>Not used:</strong> <?= $esc(implode(', ', $mbUnused)) ?></p><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
    </div>
</div>
</div>
<div class="modal fade" id="playerStatDetailModal" tabindex="-1" aria-labelledby="playerStatDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="playerStatDetailModalLabel">Player detail</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="d-none"><a class="btn btn-outline-secondary btn-sm text-nowrap" id="playerStatDetailPdfLink" href="#" target="_blank" rel="noopener" hidden><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>Download as PDF</a></div>
            <div class="modal-body" id="playerStatDetailBody">
                <p class="text-muted mb-0">Loading&hellip;</p>
            </div>
        </div>
    </div>
</div>
<style>
.hub-checkbox-dropdown__menu{display:none}
.hub-checkbox-dropdown__menu.show{display:block;position:fixed;transform:none;z-index:2000}
</style>
<script>
window.STATS_PLAYER_FILTERS = <?= json_encode(
    $tab === 'compare'
        ? ['seasons' => $compareSeasonIds, 'competitions' => $selectedCompetitions, 'venue' => $venue]
        : ['seasons' => $playersSeasonIds, 'competitions' => $playersCompetitions ?: ['__none__'], 'venue' => $venue],
    JSON_UNESCAPED_SLASHES
) ?>;
(function () {
    var open = null, openBtn = null;
    function place(btn, menu) {
        var r = btn.getBoundingClientRect();
        menu.style.left = Math.round(r.left) + 'px';
        menu.style.top = Math.round(r.bottom + 2) + 'px';
        menu.style.minWidth = Math.max(Math.round(r.width), 220) + 'px';
        menu.style.maxWidth = Math.max(Math.round(r.width), Math.min(380, window.innerWidth - r.left - 12)) + 'px';
        var mh = menu.getBoundingClientRect().height;
        if (r.bottom + 2 + mh > window.innerHeight - 8) {
            menu.style.top = Math.max(8, Math.round(r.top - 2 - mh)) + 'px';
        }
    }
    function close() {
        if (!open) return;
        open.classList.remove('show');
        open.style.left = open.style.top = open.style.minWidth = open.style.maxWidth = '';
        if (openBtn) openBtn.setAttribute('aria-expanded', 'false');
        open = openBtn = null;
    }
    document.querySelectorAll('.hub-checkbox-dropdown').forEach(function (dd) {
        var btn = dd.querySelector('.hub-checkbox-dropdown__toggle');
        var menu = dd.querySelector('.hub-checkbox-dropdown__menu');
        var summary = dd.querySelector('[data-dd-summary]');
        if (!btn || !menu) return;
        var boxes = Array.prototype.slice.call(menu.querySelectorAll('input[type="checkbox"]'));
        var all = summary && summary.getAttribute('data-dd-all') || 'All';
        var word = summary && summary.getAttribute('data-dd-word') || 'selected';
        function sync() {
            if (!summary) return;
            var c = boxes.filter(function (b) { return b.checked; });
            if (c.length === 0) summary.textContent = all;
            else if (c.length <= 2) summary.textContent = c.map(function (b) {
                var s = b.closest('label').querySelector('span');
                return s ? s.textContent.trim() : b.value;
            }).join(', ');
            else summary.textContent = c.length + ' ' + word;
        }
        boxes.forEach(function (b) { b.addEventListener('change', sync); });
        sync();
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var wasOpen = open === menu;
            close();
            if (wasOpen) return;
            open = menu;
            openBtn = btn;
            menu.classList.add('show');
            place(btn, menu);
            btn.setAttribute('aria-expanded', 'true');
        });
        menu.addEventListener('click', function (e) { e.stopPropagation(); });
    });
    document.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    window.addEventListener('resize', close);
    window.addEventListener('scroll', function () { if (open && openBtn) place(openBtn, open); }, true);
})();
(function () {
    // Filter the "Competitions" picker to whatever the ticked seasons actually
    // have on record, live, as seasons are ticked/unticked — no page reload.
    // Self-guards: harmlessly no-ops on tabs without a seasons[] picker.
    var seasonBoxes = Array.prototype.slice.call(document.querySelectorAll('input[name="seasons[]"]'));
    var compItems = Array.prototype.slice.call(document.querySelectorAll('#cmpCompsDropdown [data-cmp-seasons]'));
    var emptyNoSeasons = document.querySelector('[data-cmp-empty-noseasons]');
    var emptyNoData = document.querySelector('[data-cmp-empty-nodata]');
    if (!seasonBoxes.length) return;
    function refresh() {
        var ticked = seasonBoxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
        var anyVisible = false;
        compItems.forEach(function (item) {
            var owners = (item.getAttribute('data-cmp-seasons') || '').split(',').filter(Boolean);
            var available = ticked.length > 0 && owners.some(function (id) { return ticked.indexOf(id) !== -1; });
            item.hidden = !available;
            if (available) {
                anyVisible = true;
            } else {
                var box = item.querySelector('input[type="checkbox"]');
                if (box.checked) {
                    box.checked = false;
                    box.dispatchEvent(new Event('change'));
                }
            }
        });
        if (emptyNoSeasons) emptyNoSeasons.hidden = ticked.length > 0;
        if (emptyNoData) emptyNoData.hidden = ticked.length === 0 || anyVisible;
    }
    seasonBoxes.forEach(function (b) { b.addEventListener('change', refresh); });
    refresh();
})();
(function () {
    // Click a player's name in any of the player tables to see the match-by-match
    // detail behind that number — on the Compare tab, one metric's games; on the
    // Players tab, every event grouped by game (metric "all").
    var modalEl = document.getElementById('playerStatDetailModal');
    var bodyEl = document.getElementById('playerStatDetailBody');
    var titleEl = document.getElementById('playerStatDetailModalLabel');
    var pdfLinkEl = document.getElementById('playerStatDetailPdfLink');
    var links = Array.prototype.slice.call(document.querySelectorAll('.stats-player-link'));
    if (!modalEl || !bodyEl || !titleEl || !links.length) return;
    // Bootstrap's JS loads with `defer` in <head>, so it may not have run yet when
    // this inline script executes during parsing — resolve bootstrap.Modal lazily,
    // on first click, instead of at setup time.
    var modal = null;
    var filters = window.STATS_PLAYER_FILTERS || { seasons: [], competitions: [], venue: '' };

    function esc(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var shortComp = function (c) {
        var t = c.replace(/\s*\(.*?\)/g, '').replace(/\b\d{2}\/\d{2}\b/g, '').replace(/West of Scotland Football League\s*/gi, 'WOSFL - ').replace(/West of Scotland/gi, 'WoS').replace(/Football League/gi, 'FL').replace(/Division/gi, 'Div').replace(/Challenge/gi, 'Chall.').replace(/Scottish/gi, 'Scot.').replace(/\s+/g, ' ').trim();
        return t.length > 20 ? t.slice(0, 19).trim() + '…' : t;
    };

    links.forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!modal) {
                if (!window.bootstrap) return;
                modal = new bootstrap.Modal(modalEl);
            }
            var player = btn.getAttribute('data-player') || '';
            var metric = btn.getAttribute('data-metric') || '';
            var metricLabel = btn.getAttribute('data-metric-label') || '';
            titleEl.textContent = metricLabel + ' — ' + player;
            bodyEl.innerHTML = '<p class="text-muted mb-0">Loading…</p>';
            modal.show();

            var params = new URLSearchParams();
            params.set('player', player);
            params.set('metric', metric);
            params.set('venue', filters.venue || '');
            (filters.seasons || []).forEach(function (s) { params.append('seasons[]', s); });
            (filters.competitions || []).forEach(function (c) { params.append('competitions[]', c); });

            if (pdfLinkEl) {
                var pdfParams = new URLSearchParams(params.toString());
                pdfParams.set('download', '1');
                pdfLinkEl.href = '/admin/stats_player_detail_pdf.php?' + pdfParams.toString();
                pdfLinkEl.hidden = false;
            }

            fetch('/admin/stats_player_detail.php?' + params.toString())
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        bodyEl.innerHTML = '<p class="text-danger mb-0">' + esc(data.error || 'Could not load this detail.') + '</p>';
                        return;
                    }
                    if (!data.rows.length) {
                        bodyEl.innerHTML = '<p class="text-muted mb-0">No matches found for the current filters.</p>';
                        return;
                    }
                    var summaryLine;
                    if (data.metric === 'all' && data.summary) {
                        var s = data.summary;
                        var parts = [s.appearances + ' appearance' + (s.appearances === 1 ? '' : 's')];
                        if (s.goals) parts.push(s.goals + ' goal' + (s.goals === 1 ? '' : 's'));
                        if (s.yellow_cards) parts.push(s.yellow_cards + ' yellow card' + (s.yellow_cards === 1 ? '' : 's'));
                        if (s.red_cards) parts.push(s.red_cards + ' red card' + (s.red_cards === 1 ? '' : 's'));
                        if (s.clean_sheets) parts.push(s.clean_sheets + ' clean sheet' + (s.clean_sheets === 1 ? '' : 's'));
                        summaryLine = parts.join(', ') + '.';
                    } else {
                        summaryLine = data.total + ' total across ' + data.rows.length + ' match' + (data.rows.length === 1 ? '' : 'es') + '.';
                    }
                    var html = '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2"><p class="text-muted small mb-0">' + esc(summaryLine) + '</p><span id="playerStatDetailPdfSlot"></span></div>'
                        + '<div id="playerStatDetailToolbar"></div>';
                    html += '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 hub-data-table"><thead><tr>'
                        + '<th scope="col">Date</th><th scope="col">Opponent</th><th scope="col">Venue</th><th scope="col">Competition</th><th scope="col">Score</th><th scope="col">Detail</th>'
                        + '</tr></thead><tbody>';
                    data.rows.forEach(function (row) {
                        var d = new Date(row.date);
                        var dateStr = isNaN(d.getTime()) ? row.date : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
                        var detailHtml = String(row.detail || '').split('\n').map(esc).join('<br>');
                        var det = String(row.detail || '');
                        var ev = [/Scored/.test(det) ? 'goal' : '', /\(pen\)/.test(det) ? 'pen' : '', /Yellow card/.test(det) ? 'yellow' : '', /Red card/.test(det) ? 'red' : '', /Clean sheet/.test(det) ? 'clean' : '', /Started/.test(det) ? 'start' : '', /Came on/.test(det) ? 'sub' : ''].filter(Boolean).join(' ');
                        html += '<tr data-ev="' + ev + '" data-venue="' + esc(String(row.venue || '').toLowerCase()) + '" data-result="' + esc(row.result || '') + '" data-comp="' + esc(row.competition || '') + '"><td class="text-nowrap">' + esc(dateStr) + '</td><th scope="row">' + esc(row.opponent) + '</th><td>' + esc(row.venue) + '</td><td title="' + esc(row.competition) + '">' + esc(shortComp(String(row.competition || ''))) + '</td><td class="text-nowrap fw-semibold" style="color:' + ({ W: '#1e8e3e', D: '#e8710a', L: '#d12a2a' }[row.result] || 'inherit') + '">' + esc(row.score || '—') + '</td><td>' + detailHtml + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                    bodyEl.innerHTML = html;
                    var slot = document.getElementById('playerStatDetailPdfSlot');
                    if (slot && pdfLinkEl) slot.appendChild(pdfLinkEl);
                    var trs = Array.prototype.slice.call(bodyEl.querySelectorAll('tbody tr'));
                    var comps = [];
                    trs.forEach(function (tr) { var c = tr.getAttribute('data-comp'); if (c && comps.indexOf(c) === -1) comps.push(c); });
                    var hasEv = function (k) { return trs.some(function (tr) { return (' ' + tr.getAttribute('data-ev') + ' ').indexOf(' ' + k + ' ') !== -1; }); };
                    var chip = function (type, name, value, icon, label, color, iconOnly, shown) {
                        var id = 'psd-' + name + '-' + value.replace(/[^a-z0-9]/gi, '_');
                        return '<input type="' + type + '" class="btn-check" name="psd-' + name + '" id="' + id + '" value="' + esc(value) + '" autocomplete="off"' + (type === 'radio' && value === '' ? ' checked' : '') + '>'
                            + '<label class="btn btn-outline-primary btn-sm" for="' + id + '" title="' + esc(label) + '">' + (icon ? '<i class="fa-solid ' + icon + (iconOnly ? '' : ' me-1') + '"' + (color ? ' style="color:' + color + '"' : '') + ' aria-hidden="true"></i>' : '') + (iconOnly ? '<span class="visually-hidden">' + esc(label) + '</span>' : esc(shown || label)) + '</label>';
                    };
                    var group = function (title, inner) {
                        return '<div class="col-md-4"><span class="form-label small d-block mb-1">' + title + '</span><div class="btn-group stats-scope stats-scope--' + title.toLowerCase() + ' flex-wrap" role="group">' + inner + '</div></div>';
                    };
                    var evDefs = [['goal', 'fa-futbol', 'Goals'], ['pen', 'fa-bullseye', 'Penalties'], ['yellow', 'fa-square', 'Yellow cards', '#f2c200'], ['red', 'fa-square', 'Red cards', '#d12a2a'], ['clean', 'fa-shield-halved', 'Clean sheets'], ['start', 'fa-shirt', 'Started'], ['sub', 'fa-arrow-right-arrow-left', 'Came on']].filter(function (d) { return hasEv(d[0]); });
                    var bar = '<div class="row g-3 mb-2">';
                    if (evDefs.length) bar += group('Events', evDefs.map(function (d) { return chip('checkbox', 'ev', d[0], d[1], d[2], d[3], true); }).join(''));
                    bar += group('Venue', chip('radio', 'venue', '', 'fa-arrows-left-right', 'Home & away', '', true) + chip('radio', 'venue', 'home', 'fa-house', 'Home', '', true) + chip('radio', 'venue', 'away', 'fa-bus', 'Away', '', true));
                    bar += group('Result', [['W', 'fa-circle-check', 'Won', '#1e8e3e'], ['D', 'fa-circle-minus', 'Drawn', '#e8710a'], ['L', 'fa-circle-xmark', 'Lost', '#d12a2a']].map(function (d) { return chip('checkbox', 'res', d[0], d[1], d[2], d[3], true); }).join(''));
                    bar += '</div><div class="input-group mb-1"><span class="input-group-text"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span><input type="search" class="form-control" id="playerStatDetailSearch" placeholder="Search opponent, competition…" autocomplete="off"></div>'
                        + '<div class="form-text mb-3" id="playerStatDetailCount" hidden></div>';
                    var toolbar = document.getElementById('playerStatDetailToolbar');
                    toolbar.innerHTML = bar;
                    var countEl = document.getElementById('playerStatDetailCount');
                    var checked = function (name) { return Array.prototype.map.call(toolbar.querySelectorAll('input[name="psd-' + name + '"]:checked'), function (i) { return i.value; }); };
                    var apply = function () {
                        var evs = checked('ev'), res = checked('res'), cmp = checked('comp'), ven = checked('venue')[0] || '';
                        var terms = document.getElementById('playerStatDetailSearch').value.toLowerCase().split(/\s+/).filter(Boolean);
                        var shown = 0;
                        trs.forEach(function (tr) {
                            var have = ' ' + tr.getAttribute('data-ev') + ' ';
                            var ok = (!evs.length || evs.some(function (e) { return have.indexOf(' ' + e + ' ') !== -1; }))
                                && (!ven || tr.getAttribute('data-venue') === ven)
                                && (!res.length || res.indexOf(tr.getAttribute('data-result')) !== -1)
                                && (!cmp.length || cmp.indexOf(tr.getAttribute('data-comp')) !== -1);
                            if (ok && terms.length) { var t = tr.textContent.toLowerCase(); ok = terms.every(function (w) { return t.indexOf(w) !== -1; }); }
                            tr.hidden = !ok;
                            if (ok) shown++;
                        });
                        countEl.hidden = shown === trs.length;
                        countEl.textContent = 'Showing ' + shown + ' of ' + trs.length + ' matches.';
                    };
                    toolbar.addEventListener('change', apply);
                    toolbar.addEventListener('input', apply);
                })
                .catch(function () {
                    bodyEl.innerHTML = '<p class="text-danger mb-0">Something went wrong loading this detail.</p>';
                });
        });
    });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
