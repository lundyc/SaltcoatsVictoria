<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/stats.php';
require_once __DIR__ . '/lib/player_match_stats.php';
require_once __DIR__ . '/lib/opposition_report.php';
$pageStyles = ['player-sponsors-match-player-of-match.css'];
$pageHero = ['eyebrow' => 'Matchday', 'title' => 'Stats', 'subtitle' => 'Team performance, player contributions and match breakdowns.', 'actions' => []];
require __DIR__ . '/header.php';
$seasonId = (int)$seasonContext['season_id'];
$tab = is_string($_GET['tab'] ?? null) && in_array($_GET['tab'], ['team', 'players', 'matches', 'potm', 'compare', 'opposition'], true) ? $_GET['tab'] : 'team';
$competition = is_string($_GET['competition'] ?? null) ? $_GET['competition'] : '';
$venue = is_string($_GET['venue'] ?? null) && in_array($_GET['venue'], ['home', 'away'], true) ? $_GET['venue'] : '';
$stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE season_id = ? AND status = ? ORDER BY match_date ASC, kickoff_time ASC, id ASC');
$stmt->execute([$seasonId, 'played']);
$allFixtures = $stmt->fetchAll(PDO::FETCH_ASSOC);
$competitions = array_values(array_unique(array_filter(array_column($allFixtures, 'competition'))));
sort($competitions);
if (!in_array($competition, $competitions, true)) $competition = '';
$fixtures = array_values(array_filter($allFixtures, static fn(array $f): bool => ($competition === '' || $f['competition'] === $competition) && ($venue === '' || (int)$f['is_home'] === ($venue === 'home' ? 1 : 0))));
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
     WHERE status = 'played' AND competition IS NOT NULL AND competition <> ''"
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
$playersCompetitions = array_values(array_filter(array_map('strval', is_array($_GET['competitions'] ?? null) ? $_GET['competitions'] : [])));
$playersFixtures = [];
if ($tab === 'players') {
    $stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE season_id = ? AND status = ? ORDER BY match_date ASC, kickoff_time ASC, id ASC');
    foreach ($playersSeasonIds as $psid) {
        $stmt->execute([$psid, 'played']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if ($venue !== '' && (int)$f['is_home'] !== ($venue === 'home' ? 1 : 0)) continue;
            if ($playersCompetitions !== [] && !in_array((string)($f['competition'] ?? ''), $playersCompetitions, true)) continue;
            $playersFixtures[] = $f;
        }
    }
}

$url = static function (array $changes) use ($seasonId, $tab, $competition, $venue, $compareSeasonIds, $selectedCompetitions, $compareMetrics, $playersSeasonIds, $playersCompetitions): string {
    $targetTab = $changes['tab'] ?? $tab;
    $params = ['season_id' => $seasonId, 'tab' => $tab, 'competition' => $competition, 'venue' => $venue];
    if ($targetTab === 'compare') {
        unset($params['competition']);
        $params['seasons'] = $compareSeasonIds ?: [$seasonId];
        $params['competitions'] = $selectedCompetitions;
        $params['pmetric'] = $compareMetrics;
    } elseif ($targetTab === 'players') {
        unset($params['competition']);
        $params['season_ids'] = $playersSeasonIds;
        $params['competitions'] = $playersCompetitions;
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
<div class="container-fluid py-3">
    <nav class="nav nav-tabs mb-4" aria-label="Statistics sections">
        <?php foreach (['team' => 'Team stats', 'players' => 'Player Stats', 'matches' => 'Match Stats', 'potm' => 'POTM', 'compare' => 'Compare seasons', 'opposition' => 'Opposition Reports'] as $key => $label): ?>
        <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?> href="<?= $esc($url(['tab' => $key])) ?>"><?= $esc($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($tab !== 'opposition'): ?>
    <form method="get" class="card card-body shadow-sm mb-4<?= $tab === 'compare' ? ' cmp-filter-card' : '' ?>">
        <input type="hidden" name="tab" value="<?= $esc($tab) ?>">
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
        <?php elseif ($tab === 'players'): ?>
        <?php
        $playersSeasonSummary = count($playersSeasonIds) > 2
            ? count($playersSeasonIds) . ' seasons selected'
            : implode(', ', array_map(static fn($id) => (string)($seasonById[$id]['name'] ?? $id), $playersSeasonIds));
        $playersCompSummary = $playersCompetitions === []
            ? 'All competitions'
            : (count($playersCompetitions) > 2 ? count($playersCompetitions) . ' competitions' : implode(', ', $playersCompetitions));
        ?>
        <style>.hub-checkbox-dropdown-field{overflow:visible}</style>
        <div class="row g-3 align-items-end">
            <div class="col-md-4 hub-checkbox-dropdown-field">
                <label class="form-label" id="playersSeasonsLabel">Seasons</label>
                <div class="dropdown hub-checkbox-dropdown">
                    <button type="button" class="form-select hub-checkbox-dropdown__toggle" aria-haspopup="true" aria-expanded="false" aria-labelledby="playersSeasonsLabel">
                        <span class="hub-checkbox-dropdown__summary" data-dd-summary data-dd-all="Choose seasons" data-dd-word="seasons selected"><?= $esc($playersSeasonSummary) ?></span>
                    </button>
                    <div class="dropdown-menu hub-checkbox-dropdown__menu" aria-label="Seasons">
                        <?php foreach (array_reverse($seasonContext['seasons']) as $s): ?>
                            <label class="hub-checkbox-dropdown__item">
                                <input type="checkbox" name="season_ids[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $playersSeasonIds, true) ? 'checked' : '' ?>>
                                <span><?= $esc($s['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4 hub-checkbox-dropdown-field">
                <label class="form-label" id="playersCompsLabel">Competitions</label>
                <div class="dropdown hub-checkbox-dropdown">
                    <button type="button" class="form-select hub-checkbox-dropdown__toggle" aria-haspopup="true" aria-expanded="false" aria-labelledby="playersCompsLabel">
                        <span class="hub-checkbox-dropdown__summary" data-dd-summary data-dd-all="All competitions" data-dd-word="competitions"><?= $esc($playersCompSummary) ?></span>
                    </button>
                    <div class="dropdown-menu hub-checkbox-dropdown__menu" aria-label="Competitions">
                        <?php foreach ($allCompareCompetitions as $c): ?>
                            <label class="hub-checkbox-dropdown__item">
                                <input type="checkbox" name="competitions[]" value="<?= $esc($c) ?>" <?= in_array($c, $playersCompetitions, true) ? 'checked' : '' ?>>
                                <span><?= $esc($c) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-2"><label for="stats-venue" class="form-label">Venue</label><select class="form-select" id="stats-venue" name="venue"><option value="">Home &amp; away</option><option value="home" <?= $venue === 'home' ? 'selected' : '' ?>>Home</option><option value="away" <?= $venue === 'away' ? 'selected' : '' ?>>Away</option></select></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Apply filters</button></div>
        </div>
        <?php else: ?>
        <div class="row g-3 align-items-end">
            <div class="col-md-4"><label for="stats-season" class="form-label">Season</label><select class="form-select" id="stats-season" name="season_id"><?php foreach ($seasonContext['seasons'] as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $seasonId ? 'selected' : '' ?>><?= $esc($s['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label for="stats-competition" class="form-label">Competition</label><select class="form-select" id="stats-competition" name="competition"><option value="">All competitions</option><?php foreach ($competitions as $c): ?><option <?= $competition === $c ? 'selected' : '' ?> value="<?= $esc($c) ?>"><?= $esc($c) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label for="stats-venue" class="form-label">Venue</label><select class="form-select" id="stats-venue" name="venue"><option value="">Home &amp; away</option><option value="home" <?= $venue === 'home' ? 'selected' : '' ?>>Home</option><option value="away" <?= $venue === 'away' ? 'selected' : '' ?>>Away</option></select></div>
            <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Apply filters</button></div>
        </div>
        <?php endif; ?>
    </form>
    <?php endif; ?>
    <?php if (!$eventsAvailable && $tab !== 'opposition'): ?><div class="alert alert-warning">Match events are currently unavailable. Player goals and cards may be incomplete.</div><?php endif; ?>
    <?php if ($tab === 'opposition'): ?>
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

        <div class="card card-body shadow-sm mb-4">
            <h2 class="h5">Upload an opponent report</h2>
            <p class="text-muted small">Drag and drop a COMET matches export (JSON) for an upcoming opponent, or click to browse. Once uploaded, download it as a formatted PDF or PowerPoint scouting report below.</p>
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
        </div>

        <section class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Uploaded reports</h2>
                <?php if (!$oppReports): ?>
                    <p class="text-muted text-center py-4 mb-0" id="oppEmptyState">No opponent reports uploaded yet.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 hub-data-table">
                        <thead><tr><th scope="col">Opponent</th><th scope="col">Competition</th><th scope="col">Matches</th><th scope="col">Uploaded</th><th scope="col" class="text-end">Actions</th></tr></thead>
                        <tbody>
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
                box.hidden = false;
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
                        progressMsg.textContent = (result.team ? result.team + ': ' : '') + 'Uploaded successfully.';
                        progressMsg.classList.add('is-success');
                        setTimeout(function () { window.location.href = '/admin/stats.php?tab=opposition&uploaded=1'; }, 700);
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

                body.innerHTML = html;
            }

            document.querySelectorAll('.opp-preview-btn').forEach(function (btn) {
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
    <?php elseif ($tab === 'players' ? !$playersFixtures : !$fixtures): ?>
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
        // Include names in historic lineups/events even when their player record has since left the club.
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
        $players = [];
        foreach ($names as $name) $players[] = ['name' => $name] + hub_player_match_stats($pdo, $playersSeasonIds[0], $name, $dataFile, $playersFixtures, $eventsByFixture);
        $sorts = ['name' => 'Player', 'appearances' => 'Apps', 'starts' => 'Starts', 'substitute_appearances' => 'Sub apps', 'minutes_played' => 'Minutes', 'goals' => 'Goals', 'yellow_cards' => 'Yellow cards', 'red_cards' => 'Red cards', 'clean_sheets' => 'Clean sheets'];
        $sort = is_string($_GET['sort'] ?? null) && isset($sorts[$_GET['sort']]) ? $_GET['sort'] : 'goals';
        $direction = is_string($_GET['direction'] ?? null) && in_array($_GET['direction'], ['asc', 'desc'], true)
            ? $_GET['direction'] : ($sort === 'name' ? 'asc' : 'desc');
        usort($players, static function (array $a, array $b) use ($sort, $direction): int {
            // Keep the displayed em dashes below goalkeeper clean-sheet values.
            if ($sort === 'clean_sheets' && $a['is_goalkeeper'] !== $b['is_goalkeeper']) {
                return $a['is_goalkeeper'] ? -1 : 1;
            }
            $comparison = $sort === 'name' ? strcasecmp($a['name'], $b['name']) : ($a[$sort] <=> $b[$sort]);
            return ($direction === 'asc' ? $comparison : -$comparison) ?: strcasecmp($a['name'], $b['name']);
        });
        ?>
        <section class="card shadow-sm"><div class="card-body"><h2 class="h5">Player contributions</h2><p class="text-muted small">From recorded lineups and match events. Minutes are estimated over 90 minutes; unused substitutes do not count as appearances. Clean sheets apply to the starting goalkeeper.</p><p class="text-muted small mb-0">Click a column heading to sort; click again to reverse the order.</p></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0 hub-data-table"><thead><tr><?php foreach ($sorts as $key => $label):
            $activeSort = $sort === $key;
            $nextDirection = $activeSort ? ($direction === 'asc' ? 'desc' : 'asc') : ($key === 'name' ? 'asc' : 'desc');
        ?><th scope="col" class="text-nowrap<?= $key === 'name' ? '' : ' text-center' ?>"<?= $activeSort ? ' aria-sort="' . ($direction === 'asc' ? 'ascending' : 'descending') . '"' : '' ?>><a class="d-block text-reset text-decoration-none" href="<?= $esc($url(['sort' => $key, 'direction' => $nextDirection])) ?>" aria-label="<?= $esc('Sort by ' . $label . ', ' . ($nextDirection === 'asc' ? 'ascending' : 'descending')) ?>"><?= $esc($label) ?> <span aria-hidden="true" class="<?= $activeSort ? '' : 'text-muted' ?>"><?= $activeSort ? ($direction === 'asc' ? '↑' : '↓') : '↕' ?></span></a></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($players as $player): ?><tr><th scope="row"><button type="button" class="btn btn-link p-0 text-start text-decoration-none stats-player-link" data-player="<?= $esc($player['name']) ?>" data-metric="all" data-metric-label="Match-by-match"><?= $esc($player['name']) ?></button></th><?php foreach (['appearances', 'starts', 'substitute_appearances', 'minutes_played', 'goals', 'yellow_cards', 'red_cards', 'clean_sheets'] as $key): ?><td class="text-center"><?= $key === 'clean_sheets' && !$player['is_goalkeeper'] ? '—' : (int)$player[$key] ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        <?php if (!$players): ?><tr><td colspan="9" class="text-muted text-center py-4">No player lineups or events recorded for these matches yet.</td></tr><?php endif; ?>
        </tbody></table></div></section>
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
        <section class="card shadow-sm mb-4"><div class="card-body"><h2 class="h5">Match results</h2><p class="text-muted small mb-0">Scores are shown as home – away. Results are from your club's perspective.</p></div><div class="table-responsive"><table class="table table-hover align-middle mb-0 hub-data-table"><thead><tr><?php foreach (['Date', 'Opponent', 'Venue', 'Competition', 'Half time', 'Full time', 'Result', 'Details'] as $label): ?><th scope="col" class="text-nowrap"><?= $label ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach (array_reverse($fixtures) as $f): $r = hub_stats_result($f); ?><tr><td class="text-nowrap"><?= $esc(date('j M Y', strtotime($f['match_date']))) ?></td><th scope="row"><?= $esc($f['opponent']) ?></th><td><?= (int)$f['is_home'] === 1 ? 'Home' : 'Away' ?></td><td><?= $esc($f['competition'] ?: '—') ?></td><?php foreach (['half_time', 'full_time'] as $period): ?><td class="text-nowrap"><?= isset($f[$period . '_home_score'], $f[$period . '_away_score']) ? (int)$f[$period . '_home_score'] . ' – ' . (int)$f[$period . '_away_score'] : '—' ?></td><?php endforeach; ?><td><span class="badge text-bg-<?= $badge($r['result'] ?? '') ?>"><?= $esc($r['result'] ?? 'No score') ?></span></td><td><a href="<?= $esc($url(['match_id' => $f['id']])) ?>#match-breakdown" class="btn btn-outline-primary btn-sm">Breakdown</a></td></tr><?php endforeach; ?>
        </tbody></table></div></section>
        <?php
        $selected = null;
        $matchId = (int)($_GET['match_id'] ?? 0);
        foreach ($fixtures as $f) if ((int)$f['id'] === $matchId) $selected = $f;
        if (!$selected) $selected = $fixtures[count($fixtures) - 1];
        $events = $eventsByFixture[(int)$selected['id']] ?? [];
        usort($events, static fn(array $a, array $b): int => (int)($a['sequence'] ?? 0) <=> (int)($b['sequence'] ?? 0));
        ?>
        <section class="card shadow-sm" id="match-breakdown"><div class="card-body"><div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h2 class="h5">Match breakdown: <?= $esc($selected['opponent']) ?></h2><span class="text-muted small"><?= $esc(date('j M Y', strtotime($selected['match_date']))) ?></span></div><a class="btn btn-outline-primary align-self-start" href="/admin/match.php?id=<?= (int)$selected['id'] ?>&amp;season_id=<?= $seasonId ?>">Open match</a></div>
        <h3 class="h6">Match timeline</h3>
        <?php if (!$events): ?><p class="text-muted">No match events recorded yet.</p><?php else: ?><ol class="list-group list-group-numbered mb-4"><?php foreach ($events as $event): ?><li class="list-group-item"><strong><?= $esc(($event['minute'] ?? '') !== '' ? $event['minute'] . "′ · " : '') ?><?= $esc(ucwords(str_replace('_', ' ', (string)($event['type'] ?? 'Event')))) ?></strong> <span class="text-muted"><?= $esc(($event['team'] ?? '') === 'opponent' ? $selected['opponent'] : (($event['team'] ?? '') === 'svfc' ? 'Our team' : '')) ?></span> <?= $esc($event['player'] ?? '') ?><?php if (!empty($event['secondary_player'])): ?> → <?= $esc($event['secondary_player']) ?><?php endif; ?><?php foreach (is_array($event['substitutions'] ?? null) ? $event['substitutions'] : [] as $change): if (!is_array($change)) continue; ?> <span class="d-block small">Off: <?= $esc($change['off'] ?? '') ?> · On: <?= $esc($change['on'] ?? '') ?></span><?php endforeach; ?><?php if (!empty($event['outcome'])): ?> · <?= $esc(ucfirst((string)$event['outcome'])) ?><?php endif; ?><?php if (!empty($event['note'])): ?> · <?= $esc((string)$event['note']) ?><?php endif; ?></li><?php endforeach; ?></ol><?php endif; ?>
        <div class="row g-3"><?php foreach (['starting11_starters_json' => 'Starting XI', 'starting11_substitutes_json' => 'Substitutes'] as $field => $label): $names = json_decode((string)($selected[$field] ?? '[]'), true); $names = is_array($names) ? array_filter($names, 'is_string') : []; ?><div class="col-md-6"><h3 class="h6"><?= $label ?></h3><?php if (!$names): ?><p class="text-muted">No lineup recorded.</p><?php else: ?><ul class="mb-0"><?php foreach ($names as $name): if (trim($name) === '') continue; ?><li><?= $esc($name) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endforeach; ?></div>
        </div></section>
    <?php endif; ?>
</div>
<div class="modal fade" id="playerStatDetailModal" tabindex="-1" aria-labelledby="playerStatDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="playerStatDetailModalLabel">Player detail</h2>
                <a class="btn btn-outline-secondary btn-sm me-2" id="playerStatDetailPdfLink" href="#" target="_blank" rel="noopener" hidden><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>Download as PDF</a>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
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
        : ['seasons' => $playersSeasonIds, 'competitions' => $playersCompetitions, 'venue' => $venue],
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
                    var html = '<p class="text-muted small">' + esc(summaryLine) + '</p>';
                    html += '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 hub-data-table"><thead><tr>'
                        + '<th scope="col">Date</th><th scope="col">Opponent</th><th scope="col">Venue</th><th scope="col">Competition</th><th scope="col">Score</th><th scope="col">Detail</th>'
                        + '</tr></thead><tbody>';
                    data.rows.forEach(function (row) {
                        var d = new Date(row.date);
                        var dateStr = isNaN(d.getTime()) ? row.date : d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
                        var detailHtml = String(row.detail || '').split('\n').map(esc).join('<br>');
                        html += '<tr><td class="text-nowrap">' + esc(dateStr) + '</td><th scope="row">' + esc(row.opponent) + '</th><td>' + esc(row.venue) + '</td><td>' + esc(row.competition) + '</td><td class="text-nowrap">' + esc(row.score || '—') + '</td><td>' + detailHtml + '</td></tr>';
                    });
                    html += '</tbody></table></div>';
                    bodyEl.innerHTML = html;
                })
                .catch(function () {
                    bodyEl.innerHTML = '<p class="text-danger mb-0">Something went wrong loading this detail.</p>';
                });
        });
    });
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
