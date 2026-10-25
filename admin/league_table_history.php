<?php

declare(strict_types=1);

/**
 * Historical league tables.
 *
 * Primary flow ("Quick import"): paste {"season": "...", "league": "...",
 * "table": [...]} — the season and competition are created automatically if
 * they don't exist, and the table is saved. No season needs to be picked
 * first, so this is a straight paste-and-go for the exported-JSON shape
 * these come in as.
 *
 * Secondary flow (below): pick a season, paste HTML / a bare JSON array /
 * plain text into it — for fixing up one season, or pastes that don't carry
 * season info. Also where promotion/relegation spots and the competition
 * title can be edited after the fact, and where a season's table is deleted.
 *
 * The *current* season keeps using the existing scraper
 * (cache/wosfl_table.json, league_table_manual_update.php) exactly as
 * before — this page refuses to save over it either way.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/wosfl_table.php';
require_once __DIR__ . '/lib/league_table_history.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}

$badgeDir = __DIR__ . '/badges';
$badgeOverridesFile = __DIR__ . '/data/wosfl_badge_overrides.json';
$badgeOverrides = wosfl_load_badge_overrides($badgeOverridesFile);
if (!is_dir($badgeDir)) {
    mkdir($badgeDir, 0777, true);
}

$currentSeason = getCurrentSeason($pdo);
$currentSeasonId = $currentSeason ? (int) $currentSeason['id'] : 0;

/* --------------------------------------------------------------------- */
/* Quick import — POST action=import                                     */
/* --------------------------------------------------------------------- */

$importErrors = [];
$importResult = null;
$importInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    $importInput = (string) ($_POST['import_json'] ?? '');
    if (!csrf_check()) {
        $importErrors[] = 'Your session expired. Please try again.';
    } elseif (trim($importInput) === '') {
        $importErrors[] = 'Paste the season JSON first.';
    } else {
        $result = leagueTableHistoryImportFull($pdo, $importInput, $badgeDir, $badgeOverrides);
        if (!$result['ok']) {
            $importErrors = $result['errors'];
        } else {
            $importResult = $result;
            $importInput = '';
            auditLog(
                $pdo,
                'league_table_history_import',
                ($result['season']['created'] ? 'Created' : 'Updated') . ' season "' . $result['season']['name'] . '"'
                . ($result['competition'] ? ' (' . ($result['competition']['created'] ? 'new' : 'existing') . ' competition "' . $result['competition']['name'] . '")' : '')
                . ' and saved its league table (' . $result['rows'] . ' clubs)'
                . ($result['errors'] ? '; ' . count($result['errors']) . ' row(s) skipped' : '')
            );
        }
    }
}

/* --------------------------------------------------------------------- */
/* Import from a WOSFL URL — POST action=import_url                      */
/* --------------------------------------------------------------------- */

$urlErrors = [];
$urlResult = null;
$urlInput = ['url' => '', 'season_id' => 0, 'new_season' => '', 'title' => '', 'promo' => '', 'releg' => '', 'replace' => false];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_url') {
    $urlInput = [
        'url' => trim((string) ($_POST['wosfl_url'] ?? '')),
        'season_id' => (int) ($_POST['target_season_id'] ?? 0),
        'new_season' => trim((string) ($_POST['new_season'] ?? '')),
        'title' => trim((string) ($_POST['competition_title'] ?? '')),
        'promo' => trim((string) ($_POST['promotion_spots'] ?? '')),
        'releg' => trim((string) ($_POST['relegation_spots'] ?? '')),
        'replace' => !empty($_POST['replace_existing']),
    ];

    if (!csrf_check()) {
        $urlErrors[] = 'Your session expired. Please try again.';
    } elseif ($urlInput['url'] === '') {
        $urlErrors[] = 'Paste the WOSFL standings page URL.';
    } elseif ($urlInput['season_id'] <= 0 && $urlInput['new_season'] === '') {
        $urlErrors[] = 'Choose the season this table belongs to, or type a new season (e.g. 2024/25).';
    } else {
        $seasonSpec = null;
        if ($urlInput['new_season'] !== '') {
            $seasonSpec = leagueTableHistoryParseSeasonLabel($urlInput['new_season']);
            if ($seasonSpec === null) {
                $urlErrors[] = 'Could not work out a season from "' . $urlInput['new_season'] . '". Try something like 2024/25.';
            }
        } else {
            foreach (getSeasons($pdo) as $candidate) {
                if ((int) $candidate['id'] === $urlInput['season_id']) {
                    $seasonSpec = ['id' => (int) $candidate['id'], 'name' => (string) $candidate['name']];
                    break;
                }
            }
            if ($seasonSpec === null) {
                $urlErrors[] = 'That season no longer exists.';
            }
        }

        if (!$urlErrors) {
            // Resolve the target season without creating it until the fetch has worked.
            $targetId = isset($seasonSpec['id']) ? (int) $seasonSpec['id'] : 0;
            if ($targetId === 0) {
                $stmt = $pdo->prepare('SELECT id FROM seasons WHERE start_date = ? LIMIT 1');
                $stmt->execute([$seasonSpec['start']]);
                $targetId = (int) $stmt->fetchColumn();
            }
            if ($targetId > 0 && $targetId === $currentSeasonId) {
                $urlErrors[] = $seasonSpec['name'] . ' is the current season. Use "Update from WOSFL" on the League Table page, then archive it from its page here once it has finished.';
            } elseif ($targetId > 0 && !$urlInput['replace'] && leagueTableHistoryGet($pdo, $targetId) !== null) {
                $urlErrors[] = $seasonSpec['name'] . ' already has a saved table. Tick "Replace the saved table" to overwrite it.';
            }
        }

        if (!$urlErrors) {
            $fetched = leagueTableHistoryFetchWosfl($urlInput['url'], $badgeDir, $badgeOverrides);
            if (!$fetched['ok']) {
                $urlErrors[] = $fetched['error'];
            } else {
                $created = false;
                if (!isset($seasonSpec['id'])) {
                    $ensured = leagueTableHistoryEnsureSeason($pdo, $seasonSpec);
                    $targetId = $ensured['id'];
                    $created = $ensured['created'];
                    $seasonSpec['name'] = $ensured['name'];
                }
                $title = $urlInput['title'] !== '' ? $urlInput['title'] : $fetched['title'];
                $competition = leagueTableHistoryEnsureCompetition($pdo, $title);
                $promo = $urlInput['promo'] !== '' ? max(0, (int) $urlInput['promo']) : null;
                $releg = $urlInput['releg'] !== '' ? max(0, (int) $urlInput['releg']) : null;
                leagueTableHistorySave($pdo, $targetId, $fetched['rows'], $title, $promo, $releg, 'wosfl-url');
                auditLog(
                    $pdo,
                    'league_table_history_import_url',
                    ($created ? 'Created' : 'Updated') . ' season "' . $seasonSpec['name'] . '" from a WOSFL page and saved its league table (' . count($fetched['rows']) . ' clubs)'
                );
                $urlResult = [
                    'season' => ['id' => $targetId, 'name' => $seasonSpec['name'], 'created' => $created],
                    'competition' => $competition,
                    'rows' => count($fetched['rows']),
                    'title' => $title,
                ];
                $urlInput = ['url' => '', 'season_id' => 0, 'new_season' => '', 'title' => '', 'promo' => '', 'releg' => '', 'replace' => false];
            }
        }
    }
}

/* --------------------------------------------------------------------- */
/* Archive the current season's live table — POST action=archive_current */
/* --------------------------------------------------------------------- */

$archiveError = '';
$archived = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive_current') {
    if (!csrf_check()) {
        $archiveError = 'Your session expired. Please try again.';
    } elseif ($currentSeasonId <= 0) {
        $archiveError = 'There is no current season to archive.';
    } else {
        $snapshot = leagueTableHistoryLiveSnapshot($pdo, $currentSeason);
        if (count($snapshot['rows']) < 2) {
            $archiveError = 'There is no live table to archive yet. Run "Update from WOSFL" on the League Table page first.';
        } else {
            leagueTableHistorySave(
                $pdo,
                $currentSeasonId,
                $snapshot['rows'],
                $snapshot['title'],
                $snapshot['promotion'],
                $snapshot['relegation'],
                'archive:live'
            );
            auditLog($pdo, 'league_table_history_archive', 'Archived the current season (' . ($currentSeason['name'] ?? '') . ') live table to history (' . count($snapshot['rows']) . ' clubs)');
            $archived = ['rows' => count($snapshot['rows']), 'updated' => $snapshot['updated']];
        }
    }
}

/* --------------------------------------------------------------------- */
/* Browse / fine-tune one season                                         */
/* --------------------------------------------------------------------- */

$seasons = array_reverse(getSeasons($pdo)); // newest first (re-read here so a season created above is included)
$savedList = leagueTableHistoryList($pdo);
$savedBySeason = [];
foreach ($savedList as $savedRow) {
    $savedBySeason[(int) $savedRow['season_id']] = $savedRow;
}

$seasonId = (int) ($_GET['season_id'] ?? $_POST['season_id'] ?? 0);
if ($importResult !== null) {
    $seasonId = (int) $importResult['season']['id']; // jump straight to what was just imported
}
if ($urlResult !== null) {
    $seasonId = (int) $urlResult['season']['id'];
}
$validSeasonIds = array_map(static fn (array $s): int => (int) $s['id'], $seasons);
if ($seasonId <= 0 || !in_array($seasonId, $validSeasonIds, true)) {
    $nonCurrent = array_values(array_filter($seasons, static fn (array $s): bool => (int) $s['id'] !== $currentSeasonId));
    $seasonId = $nonCurrent !== [] ? (int) $nonCurrent[0]['id'] : ($seasons !== [] ? (int) $seasons[0]['id'] : 0);
}
$season = null;
foreach ($seasons as $s) {
    if ((int) $s['id'] === $seasonId) {
        $season = $s;
        break;
    }
}

$errors = [];
$saved = null;
$existing = $seasonId > 0 ? leagueTableHistoryGet($pdo, $seasonId) : null;

$tableInput = '';
$titleInput = (string) ($existing['competition_title'] ?? '');
$promoInput = $existing['promotion_spots'] ?? '';
$relegInput = $existing['relegation_spots'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($seasonId <= 0) {
        $errors[] = 'Choose a season first.';
    } else {
        leagueTableHistoryDelete($pdo, $seasonId);
        auditLog($pdo, 'league_table_history_delete', 'Deleted the saved league table for season #' . $seasonId . ' (' . ($season['name'] ?? '') . ')');
        header('Location: /admin/league_table_history.php?season_id=' . $seasonId . '&deleted=1');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_season') {
    $tableInput = (string) ($_POST['table_input'] ?? '');
    $titleInput = (string) ($_POST['competition_title'] ?? '');
    $promoInput = (string) ($_POST['promotion_spots'] ?? '');
    $relegInput = (string) ($_POST['relegation_spots'] ?? '');

    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($seasonId <= 0) {
        $errors[] = 'Choose a season first.';
    } elseif ($seasonId === $currentSeasonId) {
        $errors[] = 'That\'s the current season — use "Update from WOSFL" on the League Table page for it instead. This tool is for finished seasons.';
    } else {
        if (trim($tableInput) !== '') {
            $parsed = leagueTableHistoryParseInput($tableInput, $badgeDir, $badgeOverrides);
        } else {
            // Rows edited in the grid.
            $gridRows = [];
            $previousLogos = [];
            foreach (($existing['rows'] ?? []) as $prevRow) {
                $previousLogos[mb_strtolower(trim((string) ($prevRow['club'] ?? '')))] = (string) ($prevRow['logo'] ?? '');
            }
            foreach ((array) ($_POST['rows'] ?? []) as $postedRow) {
                if (!is_array($postedRow)) {
                    continue;
                }
                $club = trim((string) ($postedRow['club'] ?? ''));
                if ($club === '') {
                    continue;
                }
                $num = static fn (string $key): int => (int) trim((string) ($postedRow[$key] ?? '0'));
                $gridRows[] = [
                    'pos' => max(0, $num('pos')),
                    'club' => $club,
                    'p' => max(0, $num('p')),
                    'w' => max(0, $num('w')),
                    'd' => max(0, $num('d')),
                    'l' => max(0, $num('l')),
                    'f' => max(0, $num('f')),
                    'a' => max(0, $num('a')),
                    'gd' => $num('gd'),
                    'pts' => $num('pts'),
                    // Keep the badge only while the club name is unchanged; otherwise re-resolve it.
                    'logo' => $previousLogos[mb_strtolower($club)] ?? '',
                ];
            }
            $parsed = [
                'rows' => leagueTableHistoryAttachLogos($gridRows, $badgeDir, $badgeOverrides),
                'errors' => [],
                'format' => 'table editor',
            ];
        }

        if ($parsed['rows'] === []) {
            $errors[] = 'There are no standings rows to save. Add at least one club, or paste a table.';
            $errors = array_merge($errors, $parsed['errors']);
        } else {
            $promo = trim($promoInput) !== '' ? max(0, (int) $promoInput) : null;
            $releg = trim($relegInput) !== '' ? max(0, (int) $relegInput) : null;
            leagueTableHistorySave($pdo, $seasonId, $parsed['rows'], $titleInput, $promo, $releg, 'manual:' . $parsed['format']);
            auditLog(
                $pdo,
                'league_table_history_save',
                'Saved the ' . ($season['name'] ?? '') . ' league table (' . count($parsed['rows']) . ' clubs, ' . $parsed['format'] . ')'
                . ($parsed['errors'] ? '; ' . count($parsed['errors']) . ' line(s) skipped' : '')
            );
            $saved = [
                'rows' => $parsed['rows'],
                'count' => count($parsed['rows']),
                'skipped' => $parsed['errors'],
                'format' => $parsed['format'],
            ];
            $existing = leagueTableHistoryGet($pdo, $seasonId);
            $tableInput = '';
        }
    }
}

$pageHero = [
    'eyebrow' => 'Publishing',
    'title' => 'Historical League Tables',
    'subtitle' => 'Finished seasons\' standings, shown on the public table page\'s season picker.',
    'actions' => [],
];

/* --------------------------------------------------------------------- */
/* View state: sidebar filter + pagination, main-pane tab                */
/* --------------------------------------------------------------------- */

$view = (string) ($_GET['view'] ?? '');
if ($importResult !== null || $importErrors || $urlResult !== null || $urlErrors) {
    $view = 'import';
}
$source = (string) ($_GET['source'] ?? 'url');
if ($importResult !== null || $importErrors) {
    $source = 'json';
} elseif ($urlResult !== null || $urlErrors) {
    $source = 'url';
}
$source = $source === 'json' ? 'json' : 'url';
$isImportView = $view === 'import';

$tab = (string) ($_GET['tab'] ?? 'table');
if ($saved !== null) {
    $tab = 'table';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_season' && $errors) {
    $tab = 'edit';
}
$tab = in_array($tab, ['table', 'edit'], true) ? $tab : 'table';

$filter = ($_GET['show'] ?? '') === 'saved' ? 'saved' : 'all';
$search = trim((string) ($_GET['q'] ?? ''));
$sidebarSeasons = array_values(array_filter($seasons, static function (array $s) use ($filter, $search, $savedBySeason): bool {
    if ($filter === 'saved' && !isset($savedBySeason[(int) $s['id']])) {
        return false;
    }
    return $search === '' || stripos((string) $s['name'], $search) !== false;
}));
$perPage = 10;
$totalPages = max(1, (int) ceil(count($sidebarSeasons) / $perPage));
if (isset($_GET['page'])) {
    $page = (int) $_GET['page'];
} else {
    $page = 1;
    foreach ($sidebarSeasons as $idx => $s) {
        if ((int) $s['id'] === $seasonId) {
            $page = intdiv($idx, $perPage) + 1;
            break;
        }
    }
}
$page = min(max(1, $page), $totalPages);
$pageSeasons = array_slice($sidebarSeasons, ($page - 1) * $perPage, $perPage);

$url = static function (array $params = []) use ($seasonId, $filter, $search, $page): string {
    $base = ['season_id' => $seasonId, 'show' => $filter === 'saved' ? 'saved' : null, 'q' => $search !== '' ? $search : null, 'page' => $page > 1 ? $page : null];
    $merged = array_filter(array_merge($base, $params), static fn ($v) => $v !== null && $v !== '');
    return '/admin/league_table_history.php' . ($merged ? '?' . http_build_query($merged) : '');
};

$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

if (($_GET['ajax'] ?? '') === 'seasons') {
    ob_start();
    require __DIR__ . '/partials/league_history_sidebar.php';
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['html' => ob_get_clean(), 'page' => $page, 'pages' => $totalPages]);
    exit;
}

require_once __DIR__ . '/header.php';
$existingRows = $existing['rows'] ?? [];
$updatedLabel = '';
if ($existing !== null && (!empty($existing['updated_at']) || !empty($existing['created_at']))) {
    $updatedLabel = date('j M Y', strtotime((string) ($existing['updated_at'] ?: $existing['created_at'])));
}
?>

<style>
.lth-layout { display: grid; grid-template-columns: 320px minmax(0, 1fr); gap: 1.5rem; align-items: start; }
.lth-side { position: sticky; top: 1rem; }
.lth-side__head { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: .85rem; }
.lth-side__head h2 { font-size: 1rem; margin: 0; }
.lth-filter { display: flex; gap: .35rem; margin-bottom: .75rem; }
.lth-filter a { flex: 1; text-align: center; padding: .3rem .5rem; border: 1px solid rgba(75, 8, 24, .2); border-radius: 999px; font-size: .8rem; text-decoration: none; color: #4b0818; }
.lth-filter a.is-active { background: #4b0818; border-color: #4b0818; color: #fff; }
.lth-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .3rem; }
.lth-item { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .55rem .7rem; border-radius: .6rem; text-decoration: none; color: inherit; border: 1px solid transparent; }
.lth-item:hover { background: rgba(75, 8, 24, .05); }
.lth-item.is-active { background: rgba(75, 8, 24, .08); border-color: rgba(75, 8, 24, .25); }
.lth-item__name { font-weight: 600; }
.lth-item__sub { display: block; font-size: .75rem; color: #6c757d; font-weight: 400; }
.lth-dot { width: .55rem; height: .55rem; border-radius: 50%; background: #c9c2b8; flex: none; }
.lth-dot.is-saved { background: #238636; }
.lth-dot.is-current { background: #e9bf27; }
.lth-head { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.lth-head h2 { font-size: 1.35rem; margin: 0 0 .25rem; }
.lth-meta { color: #6c757d; font-size: .9rem; display: flex; flex-wrap: wrap; gap: .25rem .9rem; }
.lth-pill { display: inline-block; padding: .1rem .55rem; border-radius: 999px; font-size: .72rem; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; vertical-align: middle; margin-left: .4rem; }
.lth-pill--saved { background: #e3f4e7; color: #1c6b2a; }
.lth-pill--current { background: #fbf0c6; color: #7a5a00; }
.lth-pill--empty { background: #eee; color: #666; }
.lth-empty { text-align: center; padding: 3rem 1rem; color: #6c757d; }
.lth-empty i { font-size: 2rem; margin-bottom: .75rem; color: #c9c2b8; }
.lth-pager { display: flex; align-items: center; justify-content: center; gap: .3rem; margin-top: .85rem; }
.lth-pager__btn { min-width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center; padding: 0 .5rem; border: 1px solid rgba(75, 8, 24, .2); border-radius: .5rem; text-decoration: none; color: #4b0818; font-size: .85rem; background: #fff; }
a.lth-pager__btn:hover { background: rgba(75, 8, 24, .08); }
.lth-pager__btn.is-active { background: #4b0818; border-color: #4b0818; color: #fff; }
.lth-pager__btn.is-disabled { opacity: .35; }
.lth-pager__count { text-align: center; margin-top: .5rem; font-size: .78rem; color: #6c757d; }
#lthSideBody.is-loading .lth-list { opacity: .45; transition: opacity .15s; }
.lth-grid-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; margin-bottom: .6rem; }
.lth-grid-wrap { border: 1px solid rgba(75, 8, 24, .12); border-radius: .6rem; }
.lth-grid thead th { background: #f6ecde; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: #4b0818; padding: .5rem .35rem; text-align: center; white-space: nowrap; }
.lth-grid thead th:nth-child(2) { text-align: left; }
.lth-grid > tbody > tr > td, .lth-grid > thead > tr > th { padding: .2rem .25rem !important; height: auto !important; }
.lth-grid input.form-control { height: 2rem; min-height: 0; }
.lth-grid .lth-num { width: 4.2rem; text-align: center; padding: .25rem .3rem; }
.lth-grid .lth-club { min-width: 13rem; }
.lth-grid input.form-control { border-color: transparent; background: transparent; }
.lth-grid input.form-control:hover { border-color: rgba(75, 8, 24, .2); background: #fff; }
.lth-grid input.form-control:focus { border-color: #4b0818; background: #fff; box-shadow: 0 0 0 .15rem rgba(75, 8, 24, .15); }
.lth-grid tbody tr:nth-child(even) { background: rgba(246, 236, 222, .45); }
.lth-grid tr.is-new { animation: lthNew .6s ease; }
.lth-paste summary { cursor: pointer; font-weight: 600; color: #4b0818; }
@keyframes lthNew { from { background: #fbf0c6; } to { background: transparent; } }
.lth-archive { display: flex; gap: 1.1rem; padding: 1.25rem; border: 1px solid rgba(75, 8, 24, .15); border-radius: .8rem; background: linear-gradient(180deg, #fff, #fbf7f0); }
.lth-archive__icon { flex: none; width: 3rem; height: 3rem; display: flex; align-items: center; justify-content: center; border-radius: .8rem; background: #4b0818; color: #f6ecde; font-size: 1.25rem; }
.lth-archive h3 { font-size: 1.05rem; margin: 0 0 .35rem; }
.lth-archive__facts { list-style: none; padding: 0; margin: 0 0 1rem; display: grid; gap: .25rem; font-size: .9rem; }
@media (max-width: 991.98px) { .lth-layout { grid-template-columns: 1fr; } .lth-side { position: static; } }
</style>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/league_table.php">League table</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Historical tables</span></nav>

<?php if (isset($_GET['deleted'])): ?>
  <div class="alert alert-success">The saved table for <?= $esc($season['name'] ?? '') ?> was deleted.</div>
<?php endif; ?>

<div class="lth-layout">
  <aside class="lth-side">
    <div class="card dashboard-card hub-section">
      <div class="card-body" id="lthSideBody" data-endpoint="<?= $esc($url(['ajax' => 'seasons'])) ?>">
        <?php require __DIR__ . '/partials/league_history_sidebar.php'; ?>
      </div>
    </div>
  </aside>

  <section>
  <?php if ($isImportView): ?>
    <div class="card dashboard-card hub-section">
      <div class="card-body">
        <div class="lth-head">
          <div>
            <h2>Import a season</h2>
            <div class="lth-meta"><span>Bring a finished season's table in from WOSFL, or paste it.</span></div>
          </div>
        </div>

        <ul class="nav nav-tabs reports-tabs mb-3">
          <li class="nav-item"><a class="nav-link <?= $source === 'url' ? 'active' : '' ?>" href="<?= $esc($url(['view' => 'import', 'source' => null, 'tab' => null])) ?>"><i class="fa-solid fa-link me-1" aria-hidden="true"></i>From a WOSFL link</a></li>
          <li class="nav-item"><a class="nav-link <?= $source === 'json' ? 'active' : '' ?>" href="<?= $esc($url(['view' => 'import', 'source' => 'json', 'tab' => null])) ?>"><i class="fa-solid fa-paste me-1" aria-hidden="true"></i>Paste JSON</a></li>
        </ul>

        <?php if ($source === 'url'): ?>
          <?php if ($urlErrors): ?>
            <div class="alert alert-danger">
              <?php foreach ($urlErrors as $error): ?><div><?= $esc($error) ?></div><?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($urlResult !== null): ?>
            <div class="alert alert-success">
              <div class="fw-bold mb-1">
                <?= $esc($urlResult['season']['name']) ?><?= $urlResult['season']['created'] ? ' (new season)' : '' ?>
                &mdash; <?= (int) $urlResult['rows'] ?> clubs saved from WOSFL.
              </div>
              <?php if ($urlResult['competition']): ?>
                <div>Competition: <?= $esc($urlResult['competition']['name']) ?><?= $urlResult['competition']['created'] ? ' (newly created)' : '' ?></div>
              <?php endif; ?>
              <div class="mt-2">
                <a class="btn btn-sm btn-outline-success" href="<?= $esc($url(['season_id' => (int) $urlResult['season']['id'], 'view' => null, 'source' => null])) ?>">Open this season</a>
                <a class="btn btn-sm btn-outline-success" href="<?= $esc($url(['season_id' => (int) $urlResult['season']['id'], 'view' => null, 'source' => null, 'tab' => 'edit'])) ?>">Set promotion / relegation</a>
                <a class="btn btn-sm btn-outline-success" href="/table?season=<?= (int) $urlResult['season']['id'] ?>" target="_blank" rel="noopener">View on public site</a>
              </div>
            </div>
          <?php endif; ?>

          <form method="post" action="<?= $esc($url(['view' => 'import'])) ?>" id="lthUrlForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import_url">
            <div class="mb-3">
              <label class="form-label" for="wosfl_url">WOSFL standings page</label>
              <input class="form-control" type="url" id="wosfl_url" name="wosfl_url" value="<?= $esc($urlInput['url']) ?>" placeholder="https://www.wosfl.co.uk/fg/1_184170169.html" required>
              <div class="form-text">The page on wosfl.co.uk that shows the league table. It's fetched once and saved; nothing is scheduled.</div>
            </div>
            <div class="row g-3 mb-3">
              <div class="col-md-6">
                <label class="form-label" for="target_season_id">Season</label>
                <select class="form-select" id="target_season_id" name="target_season_id">
                  <option value="0">Choose an existing season&hellip;</option>
                  <?php foreach ($seasons as $s): ?>
                    <?php if ((int) $s['id'] === $currentSeasonId) { continue; } ?>
                    <option value="<?= (int) $s['id'] ?>" <?= $urlInput['season_id'] === (int) $s['id'] ? 'selected' : '' ?>><?= $esc($s['name']) ?><?= isset($savedBySeason[(int) $s['id']]) ? ' (has a table)' : '' ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="new_season">&hellip;or add a new season</label>
                <input class="form-control" type="text" id="new_season" name="new_season" value="<?= $esc($urlInput['new_season']) ?>" placeholder="e.g. 2024/25">
                <div class="form-text">If filled in, this is used instead of the list.</div>
              </div>
            </div>
            <div class="row g-3 mb-3">
              <div class="col-md-8">
                <label class="form-label" for="competition_title_url">Competition name <span class="text-muted fw-normal">(optional)</span></label>
                <input class="form-control" type="text" id="competition_title_url" name="competition_title" value="<?= $esc($urlInput['title']) ?>" placeholder="Taken from the WOSFL page if left blank">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label" for="promotion_spots_url">Promotion</label>
                <input class="form-control" type="number" min="0" id="promotion_spots_url" name="promotion_spots" value="<?= $esc($urlInput['promo']) ?>">
              </div>
              <div class="col-6 col-md-2">
                <label class="form-label" for="relegation_spots_url">Relegation</label>
                <input class="form-control" type="number" min="0" id="relegation_spots_url" name="relegation_spots" value="<?= $esc($urlInput['releg']) ?>">
              </div>
            </div>
            <div class="form-check mb-3">
              <input class="form-check-input" type="checkbox" id="replace_existing" name="replace_existing" value="1" <?= $urlInput['replace'] ? 'checked' : '' ?>>
              <label class="form-check-label" for="replace_existing">Replace the saved table if this season already has one</label>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-brand" type="submit" id="lthUrlSubmit"><i class="fa-solid fa-cloud-arrow-down me-1" aria-hidden="true"></i>Fetch &amp; save</button>
              <a class="btn btn-outline-secondary" href="<?= $esc($url(['view' => null, 'source' => null])) ?>">Cancel</a>
            </div>
          </form>

        <?php else: ?>
          <?php if ($importErrors): ?>
            <div class="alert alert-danger">
              <?php foreach ($importErrors as $error): ?><div><?= $esc($error) ?></div><?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($importResult !== null): ?>
            <div class="alert alert-success">
              <div class="fw-bold mb-1">
                <?= $esc($importResult['season']['name']) ?><?= $importResult['season']['created'] ? ' (new season)' : ' (existing season, table updated)' ?>
                &mdash; <?= (int) $importResult['rows'] ?> clubs saved.
              </div>
              <?php if ($importResult['competition']): ?>
                <div>Competition: <?= $esc($importResult['competition']['name']) ?><?= $importResult['competition']['created'] ? ' (newly created)' : ' (already existed, reused)' ?></div>
              <?php endif; ?>
              <?php if ($importResult['errors']): ?>
                <div class="mt-1">&#9888; <?= count($importResult['errors']) ?> row(s) skipped:</div>
                <ul class="mb-0 small"><?php foreach ($importResult['errors'] as $skip): ?><li><?= $esc($skip) ?></li><?php endforeach; ?></ul>
              <?php endif; ?>
              <div class="mt-2">
                <a class="btn btn-sm btn-outline-success" href="<?= $esc($url(['season_id' => (int) $importResult['season']['id'], 'view' => null, 'source' => null])) ?>">Open this season</a>
                <a class="btn btn-sm btn-outline-success" href="/table?season=<?= (int) $importResult['season']['id'] ?>" target="_blank" rel="noopener">View on public site</a>
              </div>
            </div>
          <?php endif; ?>

          <form method="post" action="<?= $esc($url(['view' => 'import', 'source' => 'json'])) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import">
            <textarea class="form-control" name="import_json" rows="12" style="font-family:monospace;font-size:.85rem" aria-label="Season JSON"
              placeholder='{&#10;  "season": "1988-89",&#10;  "league": "Western Scottish League Second Division",&#10;  "table": [&#10;    {"position": 1, "team": "Kilwinning Rangers", "played": 22, "won": 15, "drawn": 4, "lost": 3, "goals_for": 50, "goals_against": 20, "points": 49}&#10;  ]&#10;}'><?= $esc($importInput) ?></textarea>
            <details class="mt-2 small text-muted">
              <summary>Accepted formats</summary>
              <p class="mt-2 mb-0">Season accepts "1988-89", "1988/1989" or just "1988". Field names are flexible: <code>team</code>/<code>club</code>, <code>played</code>/<code>p</code>, <code>points</code>/<code>pts</code>.</p>
            </details>
            <div class="mt-3 d-flex gap-2">
              <button class="btn btn-brand" type="submit">Import season</button>
              <a class="btn btn-outline-secondary" href="<?= $esc($url(['view' => null, 'source' => null])) ?>">Cancel</a>
            </div>
          </form>
        <?php endif; ?>
      </div>
    </div>

  <?php elseif ($seasonId <= 0 || $season === null): ?>
    <div class="card dashboard-card hub-section"><div class="card-body lth-empty">
      <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
      <p class="mb-0">Choose a season from the list.</p>
    </div></div>

  <?php else: ?>
    <div class="card dashboard-card hub-section">
      <div class="card-body">
        <div class="lth-head">
          <div>
            <h2>
              <?= $esc($season['name']) ?>
              <?php if ($seasonId === $currentSeasonId): ?><span class="lth-pill lth-pill--current">Current</span>
              <?php elseif ($existing !== null): ?><span class="lth-pill lth-pill--saved">Saved</span>
              <?php else: ?><span class="lth-pill lth-pill--empty">No table</span><?php endif; ?>
            </h2>
            <?php if ($existing !== null): ?>
              <div class="lth-meta">
                <?php if (!empty($existing['competition_title'])): ?><span><?= $esc($existing['competition_title']) ?></span><?php endif; ?>
                <span><?= count($existingRows) ?> clubs</span>
                <?php if ($updatedLabel !== ''): ?><span>Updated <?= $esc($updatedLabel) ?></span><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <?php if ($existing !== null): ?>
            <div class="d-flex flex-wrap gap-2">
              <a class="btn btn-outline-secondary btn-sm" href="/table?season=<?= $seasonId ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square me-1" aria-hidden="true"></i>View public</a>
              <?php if ($seasonId !== $currentSeasonId): ?>
                <form method="post" action="<?= $esc($url()) ?>" data-confirm="Delete the saved table for <?= $esc($season['name']) ?>?" data-confirm-action="Delete">
                  <?= csrf_field() ?>
                  <input type="hidden" name="season_id" value="<?= $seasonId ?>">
                  <input type="hidden" name="action" value="delete">
                  <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-regular fa-trash-can me-1" aria-hidden="true"></i>Delete</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <?php foreach ($errors as $error): ?><div><?= $esc($error) ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($saved !== null): ?>
          <div class="alert alert-success">
            <div class="fw-bold mb-1">Saved &mdash; <?= (int) $saved['count'] ?> clubs parsed as <?= $esc($saved['format']) ?>.</div>
            <?php if ($saved['skipped']): ?>
              <div>&#9888; <?= count($saved['skipped']) ?> line(s) skipped:</div>
              <ul class="mb-0 small"><?php foreach ($saved['skipped'] as $skip): ?><li><?= $esc($skip) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($seasonId === $currentSeasonId): ?>
          <?php $snapshot = leagueTableHistoryLiveSnapshot($pdo, $currentSeason); ?>
          <?php if ($archiveError !== ''): ?><div class="alert alert-danger"><?= $esc($archiveError) ?></div><?php endif; ?>
          <?php if ($archived !== null): ?>
            <div class="alert alert-success"><strong>Archived.</strong> The live table (<?= (int) $archived['rows'] ?> clubs) has been saved to this season's history.</div>
          <?php endif; ?>
          <div class="lth-archive">
            <div class="lth-archive__icon"><i class="fa-solid fa-box-archive" aria-hidden="true"></i></div>
            <div class="lth-archive__body">
              <h3>Archive this season's final table</h3>
              <p class="mb-2">Once the season has finished, save the live table here so it stays on the public season picker after you move to the next season.</p>
              <ul class="lth-archive__facts">
                <li><strong><?= count($snapshot['rows']) ?></strong> clubs in the live table<?= $snapshot['updated'] ? ', last updated ' . $esc(date('j M Y, H:i', $snapshot['updated'])) : '' ?></li>
                <li>Competition: <strong><?= $esc($snapshot['title'] !== '' ? $snapshot['title'] : 'not set') ?></strong></li>
                <li>Promotion / relegation spots: <strong><?= $snapshot['promotion'] !== null ? (int) $snapshot['promotion'] : '&ndash;' ?> / <?= $snapshot['relegation'] !== null ? (int) $snapshot['relegation'] : '&ndash;' ?></strong></li>
                <?php if ($existing !== null): ?>
                  <li class="text-success"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Already archived<?= $updatedLabel !== '' ? ' (' . $esc($updatedLabel) . ')' : '' ?>. Archiving again replaces it.</li>
                <?php endif; ?>
              </ul>
              <?php if (count($snapshot['rows']) < 2): ?>
                <div class="alert alert-warning py-2 mb-0">No live table yet. Run <strong>Update from WOSFL</strong> on the <a href="/admin/league_table.php">League table</a> page first.</div>
              <?php else: ?>
                <form method="post" action="<?= $esc($url()) ?>" data-confirm="Save the live table to this season's history?<?= $existing !== null ? ' This replaces the table already archived.' : '' ?>" data-confirm-action="Archive">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="archive_current">
                  <button class="btn btn-brand" type="submit"><i class="fa-solid fa-box-archive me-1" aria-hidden="true"></i><?= $existing !== null ? 'Archive again' : 'Archive final table' ?></button>
                </form>
              <?php endif; ?>
              <p class="text-muted small mt-3 mb-0">Do this before you change the WOSFL link to the next season. For an earlier season, use <a href="<?= $esc($url(['view' => 'import', 'source' => null, 'tab' => null])) ?>">Import</a>.</p>
            </div>
          </div>
        <?php else: ?>
          <ul class="nav nav-tabs reports-tabs mb-3">
            <li class="nav-item"><a class="nav-link <?= $tab === 'table' ? 'active' : '' ?>" href="<?= $esc($url(['tab' => null])) ?>">Table</a></li>
            <li class="nav-item"><a class="nav-link <?= $tab === 'edit' ? 'active' : '' ?>" href="<?= $esc($url(['tab' => 'edit'])) ?>"><?= $existing !== null ? 'Edit' : 'Add table' ?></a></li>
          </ul>

          <?php if ($tab === 'table'): ?>
            <?php if ($existing === null): ?>
              <div class="lth-empty">
                <i class="fa-solid fa-table" aria-hidden="true"></i>
                <p>No table has been saved for this season yet.</p>
                <a class="btn btn-brand btn-sm" href="<?= $esc($url(['tab' => 'edit'])) ?>">Add table</a>
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 hub-data-table">
                  <thead><tr><th class="text-center">#</th><th>Club</th><th class="text-center">P</th><th class="text-center">W</th><th class="text-center">D</th><th class="text-center">L</th><th class="text-center">F</th><th class="text-center">A</th><th class="text-center">GD</th><th class="text-center">Pts</th></tr></thead>
                  <tbody>
                    <?php foreach ($existingRows as $i => $r): ?>
                      <tr class="<?= stripos((string) ($r['club'] ?? ''), 'saltcoats') !== false ? 'table-warning' : '' ?>">
                        <td class="text-center"><?= $esc($r['pos'] ?? ($i + 1)) ?></td>
                        <td><?= $esc($r['club'] ?? '') ?></td>
                        <td class="text-center"><?= $esc($r['p'] ?? '') ?></td>
                        <td class="text-center"><?= $esc($r['w'] ?? '') ?></td>
                        <td class="text-center"><?= $esc($r['d'] ?? '') ?></td>
                        <td class="text-center"><?= $esc($r['l'] ?? '') ?></td>
                        <td class="text-center"><?= $esc($r['f'] ?? '') ?></td>
                        <td class="text-center"><?= $esc($r['a'] ?? '') ?></td>
                        <td class="text-center"><?= $esc($r['gd'] ?? '') ?></td>
                        <td class="text-center"><b><?= $esc($r['pts'] ?? '') ?></b></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

          <?php else: ?>
            <form method="post" action="<?= $esc($url(['tab' => 'edit'])) ?>" id="lthEditForm">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save_season">
              <input type="hidden" name="season_id" value="<?= $seasonId ?>">
              <div class="row g-3 mb-4">
                <div class="col-md-8">
                  <label class="form-label" for="competition_title">Competition name</label>
                  <input class="form-control" type="text" id="competition_title" name="competition_title" value="<?= $esc($titleInput) ?>" placeholder="e.g. West of Scotland Football League Conference A">
                </div>
                <div class="col-6 col-md-2">
                  <label class="form-label" for="promotion_spots">Promotion</label>
                  <input class="form-control" type="number" min="0" id="promotion_spots" name="promotion_spots" value="<?= $esc($promoInput) ?>">
                </div>
                <div class="col-6 col-md-2">
                  <label class="form-label" for="relegation_spots">Relegation</label>
                  <input class="form-control" type="number" min="0" id="relegation_spots" name="relegation_spots" value="<?= $esc($relegInput) ?>">
                </div>
              </div>

              <div class="lth-grid-bar">
                <strong>Standings</strong>
                <div class="d-flex flex-wrap gap-2">
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="lthRecalc" title="Set GD from F&minus;A and Pts from 3&times;W + D"><i class="fa-solid fa-calculator me-1" aria-hidden="true"></i>Recalculate GD &amp; Pts</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm" id="lthSort" title="Order by points, then goal difference, then goals for"><i class="fa-solid fa-arrow-down-wide-short me-1" aria-hidden="true"></i>Sort by points</button>
                  <button type="button" class="btn btn-outline-primary btn-sm" id="lthAddRow"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add club</button>
                </div>
              </div>
              <div class="table-responsive lth-grid-wrap">
                <table class="table table-sm align-middle mb-0 lth-grid" id="lthGrid">
                  <thead><tr><th>#</th><th>Club</th><th>P</th><th>W</th><th>D</th><th>L</th><th>F</th><th>A</th><th>GD</th><th>Pts</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($existingRows as $i => $r): ?>
                      <tr>
                        <td><input class="form-control form-control-sm lth-num" type="number" name="rows[<?= $i ?>][pos]" value="<?= $esc($r['pos'] ?? ($i + 1)) ?>" aria-label="Position"></td>
                        <td><input class="form-control form-control-sm lth-club" type="text" name="rows[<?= $i ?>][club]" value="<?= $esc($r['club'] ?? '') ?>" aria-label="Club"></td>
                        <?php foreach (['p', 'w', 'd', 'l', 'f', 'a', 'gd', 'pts'] as $col): ?>
                          <td><input class="form-control form-control-sm lth-num" type="number" name="rows[<?= $i ?>][<?= $col ?>]" value="<?= $esc($r[$col] ?? '') ?>" data-col="<?= $col ?>" aria-label="<?= strtoupper($col) ?>"></td>
                        <?php endforeach; ?>
                        <td><button type="button" class="btn btn-sm btn-link text-danger lth-del" title="Remove club" aria-label="Remove club"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <p class="text-muted small mt-2 mb-3" id="lthGridHint"><?= $existingRows ? 'Edit any cell. Badges are matched by club name.' : 'No clubs yet. Add them one at a time, or paste a table below.' ?></p>

              <details class="mb-3 lth-paste" <?= $tableInput !== '' ? 'open' : '' ?>>
                <summary>Replace the whole table by pasting</summary>
                <div class="mt-2">
                  <textarea class="form-control" id="table_input" name="table_input" rows="8" aria-label="Paste standings" placeholder="Paste HTML, JSON or plain text. If this box has content it replaces the grid above."><?= $esc($tableInput) ?></textarea>
                </div>
              </details>

              <div class="d-flex gap-2">
                <button class="btn btn-brand" type="submit">Save changes</button>
                <a class="btn btn-outline-secondary" href="<?= $esc($url(['tab' => null])) ?>">Cancel</a>
              </div>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
  </section>
</div>


<script>
(function () {
    // ---- Dynamic season list: pagination, filter and search without a page reload ----
    var side = document.getElementById('lthSideBody');
    if (side) {
        var searchTimer = null;
        var abortCtl = null;

        function load(href) {
            var u = new URL(href, location.href);
            u.searchParams.set('ajax', 'seasons');
            if (abortCtl) { abortCtl.abort(); }
            abortCtl = new AbortController();
            side.classList.add('is-loading');
            return fetch(u.toString(), { credentials: 'same-origin', signal: abortCtl.signal })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    side.innerHTML = data.html;
                    u.searchParams.delete('ajax');
                    history.replaceState(null, '', u.pathname + (u.search || ''));
                    bind();
                })
                .catch(function (e) { if (e.name !== 'AbortError') { location.href = href; } })
                .then(function () { side.classList.remove('is-loading'); });
        }

        function bind() {
            side.querySelectorAll('.lth-pager a[data-page], .lth-filter a').forEach(function (a) {
                a.addEventListener('click', function (e) { e.preventDefault(); load(a.href); });
            });
            var form = side.querySelector('#lthSearchForm');
            if (form) {
                var input = form.querySelector('input[type=search]');
                form.addEventListener('submit', function (e) { e.preventDefault(); load(buildSearchUrl(form)); });
                input.addEventListener('input', function () {
                    clearTimeout(searchTimer);
                    searchTimer = setTimeout(function () { load(buildSearchUrl(form)); }, 250);
                });
            }
        }

        function buildSearchUrl(form) {
            var u = new URL(location.href);
            u.searchParams.delete('page');
            var q = form.querySelector('input[type=search]').value.trim();
            if (q) { u.searchParams.set('q', q); } else { u.searchParams.delete('q'); }
            return u.toString();
        }

        bind();
    }

    // ---- Table editor ----
    var grid = document.getElementById('lthGrid');
    if (!grid) { return; }
    var tbody = grid.querySelector('tbody');
    var cols = ['pos', 'club', 'p', 'w', 'd', 'l', 'f', 'a', 'gd', 'pts'];

    function num(row, col) { return parseInt((row.querySelector('[data-col=' + col + ']') || {}).value, 10) || 0; }
    function setNum(row, col, v) { var el = row.querySelector('[data-col=' + col + ']'); if (el) { el.value = v; } }

    function reindex() {
        Array.prototype.forEach.call(tbody.rows, function (row, i) {
            row.querySelectorAll('input').forEach(function (input) {
                input.name = input.name.replace(/rows\[\d+\]/, 'rows[' + i + ']');
            });
        });
    }

    function newRow() {
        var i = tbody.rows.length;
        var tr = document.createElement('tr');
        tr.className = 'is-new';
        var html = '<td><input class="form-control form-control-sm lth-num" type="number" name="rows[' + i + '][pos]" value="' + (i + 1) + '" aria-label="Position"></td>' +
            '<td><input class="form-control form-control-sm lth-club" type="text" name="rows[' + i + '][club]" aria-label="Club"></td>';
        ['p', 'w', 'd', 'l', 'f', 'a', 'gd', 'pts'].forEach(function (c) {
            html += '<td><input class="form-control form-control-sm lth-num" type="number" name="rows[' + i + '][' + c + ']" value="0" data-col="' + c + '" aria-label="' + c.toUpperCase() + '"></td>';
        });
        html += '<td><button type="button" class="btn btn-sm btn-link text-danger lth-del" title="Remove club" aria-label="Remove club"><i class="fa-regular fa-trash-can" aria-hidden="true"></i></button></td>';
        tr.innerHTML = html;
        return tr;
    }

    document.getElementById('lthAddRow').addEventListener('click', function () {
        var tr = newRow();
        tbody.appendChild(tr);
        tr.querySelector('.lth-club').focus();
    });

    grid.addEventListener('click', function (e) {
        var del = e.target.closest('.lth-del');
        if (!del) { return; }
        del.closest('tr').remove();
        reindex();
    });

    // GD follows F and A as you type; Pts is left alone (points deductions are real).
    grid.addEventListener('input', function (e) {
        var col = e.target.getAttribute('data-col');
        if (col === 'f' || col === 'a') {
            var row = e.target.closest('tr');
            setNum(row, 'gd', num(row, 'f') - num(row, 'a'));
        }
    });

    document.getElementById('lthRecalc').addEventListener('click', function () {
        Array.prototype.forEach.call(tbody.rows, function (row) {
            setNum(row, 'gd', num(row, 'f') - num(row, 'a'));
            setNum(row, 'pts', num(row, 'w') * 3 + num(row, 'd'));
        });
    });

    document.getElementById('lthSort').addEventListener('click', function () {
        var rows = Array.prototype.slice.call(tbody.rows);
        rows.sort(function (a, b) {
            return (num(b, 'pts') - num(a, 'pts')) || (num(b, 'gd') - num(a, 'gd')) || (num(b, 'f') - num(a, 'f'));
        });
        rows.forEach(function (row, i) { tbody.appendChild(row); setNum(row, 'pos', i + 1); });
        reindex();
    });

    // Warn before leaving with unsaved edits.
    var dirty = false;
    var editForm = document.getElementById('lthEditForm');
    editForm.addEventListener('input', function () { dirty = true; });
    editForm.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
