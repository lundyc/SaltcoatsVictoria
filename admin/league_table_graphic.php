<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/render_access_token.php';
$isTokenRender = RENDER_ACCESS_TOKEN !== ''
    && ($_GET['render'] ?? '') === '1'
    && hash_equals(RENDER_ACCESS_TOKEN, (string) ($_GET['_token'] ?? ''));
if (!$isTokenRender && !hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$currentSeason = getCurrentSeason($pdo);
$leagueCompetition = null;
$leagueEdition = null;
if (is_array($currentSeason) && !empty($currentSeason['competition_id'])) {
    $leagueCompetition = getMatchCompetitionById($pdo, (int) $currentSeason['competition_id']);
    if (function_exists('competitionStructureFindEdition')) {
        $leagueEdition = competitionStructureFindEdition($pdo, (int) $currentSeason['competition_id'], (int) $currentSeason['id']);
    }
}
$wosflTableUrlOverride = is_array($leagueEdition)
    ? trim((string) ($leagueEdition['competition_url'] ?? ''))
    : '';
if ($wosflTableUrlOverride === '' && is_array($leagueCompetition) && !empty($leagueCompetition['is_league'])) {
    $wosflTableUrlOverride = trim((string) ($leagueCompetition['league_url'] ?? ''));
}
include __DIR__ . '/wosfl-table.php';
require_once __DIR__ . '/social_post_settings.php';

// Load league config
$configFile = __DIR__ . '/league-config.json';
$leagueConfig = [
    'total_games_per_team' => 30,
    'promotion_spots' => 9,
    'relegation_spots' => 0,
    'show_table_lines' => true,
];

function decode_comment_tolerant_json(string $json): ?array
{
    $json = preg_replace('~/\*.*?\*/~s', '', $json) ?? $json;
    $json = preg_replace('/^\s*\/\/.*$/m', '', $json) ?? $json;
    $json = preg_replace('/^\s*#.*$/m', '', $json) ?? $json;

    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

if (file_exists($configFile)) {
    $json = file_get_contents($configFile);
    $data = is_string($json) ? decode_comment_tolerant_json($json) : null;
    if (is_array($data)) {
        $leagueConfig = array_merge($leagueConfig, $data);
    }
}
// Validate config values
$leagueConfig['total_games_per_team'] = max(1, (int)($leagueConfig['total_games_per_team'] ?? 30));
$leagueConfig['promotion_spots'] = max(1, (int)($leagueConfig['promotion_spots'] ?? 9));
$leagueConfig['relegation_spots'] = max(0, (int)($leagueConfig['relegation_spots'] ?? 0));
$leagueConfig['show_table_lines'] = !empty($leagueConfig['show_table_lines']);
$leagueConfig['league_title'] = trim((string) ($leagueConfig['league_title'] ?? 'WOSFL Fourth Division')) ?: 'WOSFL Fourth Division';
$leagueConfig['league_table_link'] = trim((string) ($leagueConfig['league_table_link'] ?? 'league_table.php')) ?: 'league_table.php';
$leagueConfig['league_banner_image'] = trim((string) ($leagueConfig['league_banner_image'] ?? 'assets/images/header.png')) ?: 'assets/images/header.png';

if (is_array($leagueCompetition) && !empty($leagueCompetition['is_league'])) {
    $competitionName = trim((string) ($leagueCompetition['name'] ?? ''));
    if ($competitionName !== '') {
        $leagueConfig['league_title'] = $competitionName;
    }
    $competitionUrl = trim((string) ($leagueCompetition['league_url'] ?? ''));
    if ($competitionUrl !== '') {
        $leagueConfig['league_table_link'] = $competitionUrl;
    }
    $competitionBanner = trim((string) ($leagueCompetition['league_banner_image'] ?? ''));
    if ($competitionBanner !== '') {
        $leagueConfig['league_banner_image'] = '/' . ltrim($competitionBanner, '/');
    }
    if (isset($leagueCompetition['promotion_spots']) && $leagueCompetition['promotion_spots'] !== null) {
        $leagueConfig['promotion_spots'] = max(1, (int) $leagueCompetition['promotion_spots']);
    }
    if (isset($leagueCompetition['relegation_spots']) && $leagueCompetition['relegation_spots'] !== null) {
        $leagueConfig['relegation_spots'] = max(0, (int) $leagueCompetition['relegation_spots']);
    }
    if (array_key_exists('show_table_lines', $leagueCompetition)) {
        $leagueConfig['show_table_lines'] = !empty($leagueCompetition['show_table_lines']);
    }
}
if (is_array($leagueEdition) && !empty($leagueEdition['is_active'])) {
    $editionTitle = trim((string)($leagueEdition['effective_name'] ?? ''));
    if ($editionTitle !== '') {
        $leagueConfig['league_title'] = $editionTitle;
    }
    $editionUrl = trim((string)($leagueEdition['competition_url'] ?? ''));
    if ($editionUrl !== '') {
        $leagueConfig['league_table_link'] = $editionUrl;
    }
    if ($leagueEdition['promotion_spots'] !== null) {
        $leagueConfig['promotion_spots'] = max(0, (int)$leagueEdition['promotion_spots']);
    }
    if ($leagueEdition['relegation_spots'] !== null) {
        $leagueConfig['relegation_spots'] = max(0, (int)$leagueEdition['relegation_spots']);
    }
    if ($leagueEdition['show_table_lines'] !== null) {
        $leagueConfig['show_table_lines'] = !empty($leagueEdition['show_table_lines']);
    }
}

foreach ($teams as &$team) {
    $opponentBadge = matchOpponentBadgeAssetUrl($pdo, trim((string)($team['club'] ?? '')));
    if ($opponentBadge !== '') {
        $team['logo'] = $opponentBadge;
    }
}
unset($team);

// Placeholder: UI config panel can be integrated here in the future
// Example: if ($showConfigPanel) { renderConfigPanel($leagueConfig); }

function normalize_team_name(string $name): string
{
    return strtolower(preg_replace('/\s+/', ' ', trim($name)));
}

// Calculate games left, max points, and promotion status using the same
// logic as promotion_check.php.
$activeTeams = array_values(array_filter($teams, static function (array $team): bool {
    return (int) ($team['p'] ?? 0) > 0;
}));
$foldedTeams = array_values(array_filter($teams, static function (array $team): bool {
    return (int) ($team['p'] ?? 0) === 0;
}));
$activeTeamCount = count($activeTeams);
$foldedTeamCount = count($foldedTeams);
$totalGames = ($activeTeamCount > 1) ? 2 * ($activeTeamCount - 1) : 0;

foreach ($activeTeams as &$team) {
    $played = (int) ($team['p'] ?? 0);
    $remaining = $totalGames - $played;
    if ($remaining < 0) {
        $remaining = 0;
    }
    $team['max_points'] = (int) ($team['pts'] ?? 0) + ($remaining * 3);
}
unset($team);

// Determine mathematically promoted teams using the same "safe club" logic
// as promotion_check.php.
$promotionSpots = (int) $leagueConfig['promotion_spots'];
$relegationSpots = (int) $leagueConfig['relegation_spots'];
$showTableLines = !empty($leagueConfig['show_table_lines']);
$tableTeamCount = count($teams);
$relegationLineIndex = $relegationSpots > 0 ? max(1, $tableTeamCount - $relegationSpots + 1) : 0;
$safeTeams = [];
foreach ($activeTeams as $team) {
    if ((int) ($team['pos'] ?? 0) > $promotionSpots) {
        continue;
    }

    $currentPoints = (int) ($team['pts'] ?? 0);
    $possibleAhead = 0;
    foreach ($activeTeams as $other) {
        if ($other['club'] === $team['club']) {
            continue;
        }

        $otherPoints = (int) ($other['pts'] ?? 0);
        $otherMaxPoints = (int) ($other['max_points'] ?? 0);

        if ($otherPoints >= $currentPoints || ($otherPoints < $currentPoints && $otherMaxPoints >= $currentPoints)) {
            $possibleAhead++;
        }
    }

    if ($possibleAhead < $promotionSpots) {
        $safeTeams[] = $team;
    }
}

$safeTeamNames = array_fill_keys(array_map(static function (array $team): string {
    return normalize_team_name((string) ($team['club'] ?? ''));
}, $safeTeams), true);

foreach ($teams as &$team) {
    $team['promoted'] = isset($safeTeamNames[normalize_team_name((string) ($team['club'] ?? ''))]);
}
unset($team);

$isRender = isset($_GET['render']);
$app = app_bootstrap_state();
$isAuthenticated = $isRender ? true : $app['isAuthenticated'];
$styleVersion = $app['styleVersion'];
$lastUpdatedTimestamp = @filemtime(__DIR__ . '/cache/wosfl_table.json') ?: time();
$lastUpdatedString = date('j M Y, H:i', $lastUpdatedTimestamp);
$dateShort = date('j M Y', $lastUpdatedTimestamp);
$requestedTableStyle = isset($_GET['table_style']) ? strtolower((string) $_GET['table_style']) : 'compact';
$tableStyle = in_array($requestedTableStyle, ['standard', 'compact', 'expanded'], true)
    ? $requestedTableStyle
    : 'compact';
// Standard must look like the admin preview (league_table.php setTableStyle),
// which keeps the compact base layout and layers the standard columns on top.
// Expanded keeps its own full-width layout: its form column is clipped at 1080px
// when combined with the compact sidebar.
$tableStyleClass = $tableStyle === 'compact'
    ? ' table-card--compact'
    : ($tableStyle === 'expanded'
        ? ' table-card--expanded'
        : ' table-card--compact table-card--standard');
$tableMatchWeek = 0;
foreach ($teams as $team) {
    $tableMatchWeek = max($tableMatchWeek, (int) ($team['p'] ?? 0));
}
$bodyClass = 'bg-cream' . ($isRender ? ' render-mode' : '');
$loadingModalClass = 'loading-modal' . ($isRender || !$isAuthenticated ? ' is-hidden' : '');
$tableCardBodyClass = 'card-body' . ($isRender ? '' : ' is-loading');
$tableWrapperClass = $isRender ? '' : 'is-loading';
$tableWrapperBusy = $isRender ? 'false' : 'true';
$postComposerPresets = [
    'facebook' => social_post_get_preset_groups('facebook', 'league_table'),
    'instagram' => social_post_get_preset_groups('instagram', 'league_table'),
    'x' => social_post_get_preset_groups('x', 'league_table'),
];

/**
 * @param array<string, mixed> $team
 */
function renderTableRow(array $team): string
{
    $isHighlight = stripos((string) ($team['club'] ?? ''), 'Saltcoats Victoria') !== false;
    $hasPromotionLine = !empty($team['promotion_line']);
    $hasRelegationLine = !empty($team['relegation_line']);
    $rowClass = $isHighlight ? 'highlight' : '';
    $cellClass = '';
    if ($hasPromotionLine) {
        $cellClass = 'promotion-line';
    }
    if ($hasRelegationLine) {
        $cellClass = trim($cellClass . ' relegation-line');
    }
    $position = (int) ($team['pos'] ?? 0);
    $positionChipClass = 'position-chip' . ($position > 0 && $position <= 4 ? ' position-chip--top' : '');
    $logoHtml = '';

    if (!empty($team['logo'])) {
        // Server-side screenshot capture (render.js) takes the shot right after the DOM
        // settles; native loading="lazy" can leave these badges unrequested at that point,
        // so skip it when generating the export graphic.
        global $isRender;
        $lazyAttr = empty($isRender) ? ' loading="lazy"' : '';
        $logoHtml = '<img src="' . safe($team['logo']) . '?t=' . time() . '" alt="' . safe($team['club']) . '"' . $lazyAttr . ' width="24" height="24">';
    }

    $promotedBadge = '';
    if (!empty($team['promoted'])) {
        $promotedBadge = '<span class="promoted-badge" aria-label="Promoted" title="Promoted">P</span>';
    }

    return '<tr class="' . trim($rowClass) . '">' .
        '<td class="col-pos ' . $cellClass . '"><span class="' . $positionChipClass . '">' . safe($team['pos'] ?? '-') . '</span></td>' .
        '<th scope="row" class="col-club club-cell ' . $cellClass . '"><div class="club-cell__content">' . $logoHtml . '<span class="club-name">' . safe($team['club'] ?? '-') . '</span></div></th>' .
        '<td class="col-promoted ' . $cellClass . '">' . $promotedBadge . '</td>' .
        '<td class="col-stat col-played ' . $cellClass . '">' . safe($team['p'] ?? '-') . '</td>' .
        '<td class="col-stat col-won ' . $cellClass . '">' . safe($team['w'] ?? '-') . '</td>' .
        '<td class="col-stat col-drawn ' . $cellClass . '">' . safe($team['d'] ?? '-') . '</td>' .
        '<td class="col-stat col-lost ' . $cellClass . '">' . safe($team['l'] ?? '-') . '</td>' .
        '<td class="col-stat col-goal-difference ' . $cellClass . '">' . safe($team['gd'] ?? '-') . '</td>' .
        '<td class="col-points ' . $cellClass . '"><span class="points-chip">' . safe($team['pts'] ?? '-') . '</span></td>' .
        '<td class="col-form ' . $cellClass . '">' . league_table_form_html(is_array($team['form'] ?? null) ? $team['form'] : []) . '</td>' .
        '</tr>';
}

function league_table_asset_url(string $path): string
{
    $path = trim($path);
    if ($path === '' || preg_match('#^(?:https?:)?//#i', $path) === 1 || str_contains($path, '?')) {
        return $path;
    }

    $absolutePath = __DIR__ . '/' . ltrim($path, '/');
    if (!is_file($absolutePath)) {
        return $path;
    }

    return $path . '?v=' . rawurlencode((string) filemtime($absolutePath));
}

$leagueTableBannerAlt = static function (string $leagueTitle): string {
    $leagueTitle = trim($leagueTitle);
    return $leagueTitle !== '' ? $leagueTitle . ' banner' : 'League table banner';
};

$leagueBannerImage = league_table_asset_url((string) $leagueConfig['league_banner_image']);
$leagueBannerAlt = $leagueTableBannerAlt((string) $leagueConfig['league_title']);
$leagueTableHref = (string) $leagueConfig['league_table_link'];
$leagueTableTitle = (string) $leagueConfig['league_title'];

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= safe($leagueTableTitle) ?> League Table</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="assets/css/social-publishing.css?v=<?= $styleVersion ?>">

    <?php if ($isRender): ?>
        <link rel="stylesheet" href="/admin/assets/css/league_table.css">
    <?php endif; ?>

</head>

<body class="<?= safe($bodyClass) ?>">
    <div id="loadingModal" class="<?= safe($loadingModalClass) ?>" role="dialog" aria-modal="true" aria-live="polite" aria-labelledby="loadingTitle">
        <div class="loading-modal__content">
            <div class="loading-spinner" aria-hidden="true"></div>
            <h2 id="loadingTitle" class="loading-title">Loading...</h2>
            <ul id="loadingSteps" class="loading-steps" aria-label="Loading progress">
                <li class="loading-step" data-step="0">Deleting old cache</li>
                <li class="loading-step" data-step="1">Loading new table</li>
                <li class="loading-step" data-step="2">Finishing up...</li>
                <li class="loading-step" data-step="3">Done</li>
            </ul>
        </div>
    </div>

    <div id="postModal" class="post-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="postModalTitle" aria-describedby="postModalHelp">
        <div class="post-modal__card">
            <div class="post-modal__header">
                <div>
                    <p class="post-modal__eyebrow">Social post</p>
                    <h2 id="postModalTitle" class="post-modal__title">Prepare post</h2>
                    <p id="postModalHelp" class="post-modal__copy">Choose a preset caption or type your own before posting.</p>
                </div>
                <button type="button" class="post-modal__close" data-post-modal-close aria-label="Close post composer">&times;</button>
            </div>

            <div id="postModalNotice" class="post-modal__notice d-none" role="status" aria-live="polite"></div>

            <form id="postModalForm" class="post-modal__form">
                <div class="post-modal__section">
                    <div class="post-modal__section-header">
                        <h3 class="post-modal__section-title">Preset captions</h3>
                        <span id="postModalTarget" class="post-modal__target-pill">Facebook</span>
                    </div>
                    <div id="postModalPills" class="post-modal__pills" aria-label="Caption presets"></div>
                </div>

                <div class="post-modal__section">
                    <label for="postModalTextarea" class="form-label">Caption</label>
                    <textarea id="postModalTextarea" class="form-control post-modal__textarea" rows="5" spellcheck="true"></textarea>
                    <div class="post-modal__hint">You can edit the text directly if you want something different.</div>
                </div>

                <div class="post-modal__footer">
                    <button type="button" class="btn btn-neutral" data-post-modal-close>Cancel</button>
                    <button id="postModalSubmitBtn" type="submit" class="btn btn-brand">Send</button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$isRender && !$isAuthenticated): ?>
        <?php app_render_login_modal('Login Required', 'Enter your username or email and password to access the Saltcoats Victoria league table tools.'); ?>
    <?php endif; ?>

    <?php if (!$isRender && $isAuthenticated): ?>
        <header class="site-header py-3">
            <div class="container-fluid">
                <?php app_render_primary_nav('league_table'); ?>
                <div class="nav-status" aria-live="polite">
                    <div id="refreshStatus" class="d-none" role="status" aria-live="polite"></div>
                    <div id="facebookPostStatus" class="d-none" role="status" aria-live="polite"></div>
                    <div id="instagramPostStatus" class="d-none" role="status" aria-live="polite"></div>
                    <div id="twitterPostStatus" class="d-none" role="status" aria-live="polite"></div>
                </div>
            </div>
        </header>
    <?php endif; ?>

    <?php if ($isRender || $isAuthenticated): ?>
        <main class="pb-4">
            <?php if (!$isRender): ?>
                <div class="container-fluid">
                    <section class="utility-grid mb-4">
                        <article class="utility-panel">
                            <p class="utility-panel__eyebrow">League Table</p>
                            <h1 class="utility-panel__title"><?= safe($leagueTableTitle) ?></h1>
                            <p class="utility-panel__copy">
                                Refresh the latest standings, export the image, and prepare or publish the table for match day channels.
                            </p>
                            <div class="dashboard-actions">
                                <button id="refreshTableBtn" class="btn btn-brand" type="button" data-csrf-token="<?= htmlspecialchars((string) ($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">Refresh Table</button>
                                <button id="saveAsImageBtn" class="btn btn-neutral" type="button">Save as Image</button>
                            </div>
                        </article>

                        <aside class="utility-panel utility-panel--admin">
                            <p class="utility-panel__eyebrow">Publishing</p>
                            <h2 class="utility-panel__title">Social shortcuts</h2>
                            <p class="utility-panel__copy">
                                Generate a fresh export and send it straight into your posting workflow.
                            </p>
                            <div class="dashboard-actions">
                                <button id="postToFacebookBtn" class="btn btn-brand" type="button">Post to Facebook</button>
                                <button id="postToInstagramBtn" class="btn btn-outline-maroon" type="button">Post to Instagram</button>
                                <button id="postToTwitterBtn" class="btn btn-outline-maroon" type="button">Prepare Twitter Share</button>
                            </div>
                        </aside>
                    </section>
                </div>
            <?php endif; ?>

            <?php if ($isRender): ?>
                <section class="card table-card table-card--render<?= safe($tableStyleClass) ?>" aria-label="<?= safe($leagueTableTitle) ?> Table">
                        <div id="tableCardBody" class="table-card__body <?= safe($tableCardBodyClass) ?>">
                            <div class="table-card__hero text-center mb-3">
                                <a href="<?= safe($leagueTableHref) ?>" class="table-card__hero-link" aria-label="<?= safe($leagueTableTitle) ?> table link">
                                    <img src="<?= safe($leagueBannerImage) ?>" alt="<?= safe($leagueBannerAlt) ?>" width="360" height="auto" class="img-fluid banner-img">
                                </a>
                                <div class="compact-matchweek" aria-hidden="true"><span>Match Week</span><strong><?= $tableMatchWeek ?></strong></div>
                            </div>
                        <div id="tableWrapper" class="table-scroll <?= safe($tableWrapperClass) ?>" aria-busy="<?= safe($tableWrapperBusy) ?>">
                            <table class="standings-table">
                                <thead>
                                    <tr>
                                        <th scope="col" class="col-pos">Pos</th>
                                        <th scope="col" class="col-club club-cell">Club</th>
                                        <th scope="col" class="col-promoted"></th>
                                        <th scope="col" class="col-stat col-played">P</th>
                                        <th scope="col" class="col-stat col-won">W</th>
                                        <th scope="col" class="col-stat col-drawn">D</th>
                                        <th scope="col" class="col-stat col-lost">L</th>
                                        <th scope="col" class="col-stat col-goal-difference">GD</th>
                                        <th scope="col" class="col-points">PTS</th>
                                        <th scope="col" class="col-form">Form</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $promotionSpots = (int)$leagueConfig['promotion_spots'];
                                    foreach ($teams as $i => $team):
                                        // Add promotion-line class to the team row at the promotion spot
                                        $team['promotion_line'] = $showTableLines && (($i + 1) === $promotionSpots);
                                        $team['relegation_line'] = $showTableLines && $relegationLineIndex > 0 && (($i + 1) === $relegationLineIndex);
                                        echo renderTableRow($team);
                                    endforeach;
                                    ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="meta text-center small">Last Updated: <?= safe($lastUpdatedString) ?></div>
                    </div>
                </section>
            <?php else: ?>
                <div class="container-fluid">
                    <section id="standings" class="card table-card<?= safe($tableStyleClass) ?>" aria-label="<?= safe($leagueTableTitle) ?> Table">
                            <div id="tableCardBody" class="table-card__body <?= safe($tableCardBodyClass) ?>">
                                <div class="table-card__hero text-center mb-3">
                                    <a href="<?= safe($leagueTableHref) ?>" class="table-card__hero-link" aria-label="<?= safe($leagueTableTitle) ?> table link">
                                        <img src="<?= safe($leagueBannerImage) ?>" alt="<?= safe($leagueBannerAlt) ?>" width="360" height="auto" class="img-fluid banner-img">
                                    </a>
                                    <div class="compact-matchweek" aria-hidden="true"><span>Match Week</span><strong><?= $tableMatchWeek ?></strong></div>
                                </div>
                            <div id="tableWrapper" class="table-scroll <?= safe($tableWrapperClass) ?>" aria-busy="<?= safe($tableWrapperBusy) ?>">
                                <table class="standings-table">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="col-pos">Pos</th>
                                            <th scope="col" class="col-club club-cell">Club</th>
                                            <th scope="col" class="col-promoted" aria-label="Promotion status"></th>
                                            <th scope="col" class="col-stat col-played">P</th>
                                            <th scope="col" class="col-stat col-won">W</th>
                                            <th scope="col" class="col-stat col-drawn">D</th>
                                            <th scope="col" class="col-stat col-lost">L</th>
                                            <th scope="col" class="col-stat col-goal-difference">GD</th>
                                            <th scope="col" class="col-points">PTS</th>
                                            <th scope="col" class="col-form">Form</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $promotionSpots = (int)$leagueConfig['promotion_spots'];
                                        foreach ($teams as $i => $team):
                                            // Add promotion-line class to the team row at the promotion spot
                                            $team['promotion_line'] = $showTableLines && (($i + 1) === $promotionSpots);
                                            $team['relegation_line'] = $showTableLines && $relegationLineIndex > 0 && (($i + 1) === $relegationLineIndex);
                                            echo renderTableRow($team);
                                        endforeach;
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="meta text-center small">Last Updated: <?= safe($lastUpdatedString) ?></div>
                        </div>
                    </section>
                </div>
            <?php endif; ?>
        </main>
    <?php endif; ?>

    <?php app_render_auth_scripts($isAuthenticated); ?>

    <script>
        <?php if (!$isRender && $isAuthenticated): ?>
        (function() {
            var modal = document.getElementById('loadingModal');
            var steps = Array.prototype.slice.call(document.querySelectorAll('.loading-step'));
            var tableWrapper = document.getElementById('tableWrapper');
            var tableCardBody = document.getElementById('tableCardBody');
            var current = 0;

            function markStep(index) {
                steps.forEach(function(step, i) {
                    step.classList.toggle('is-active', i === index);
                    step.classList.toggle('is-done', i < index);
                });
            }

            function finishLoading() {
                markStep(steps.length - 1);
                setTimeout(function() {
                    modal.classList.add('is-hidden');
                    tableWrapper.classList.remove('is-loading');
                    tableCardBody.classList.remove('is-loading');
                    tableWrapper.setAttribute('aria-busy', 'false');
                }, 500);
            }

            function runSequence() {
                markStep(0);
                var delays = [700, 900, 900, 400];

                function next() {
                    current += 1;
                    if (current < steps.length - 1) {
                        markStep(current);
                        setTimeout(next, delays[current]);
                    } else {
                        finishLoading();
                    }
                }

                setTimeout(next, delays[0]);
            }

            runSequence();
        })();

        document.getElementById('refreshTableBtn').addEventListener('click', function() {
            var button = this;
            var statusEl = document.getElementById('refreshStatus');

            function setRefreshStatus(type, message) {
                statusEl.className = 'alert mt-2';
                statusEl.classList.add(type === 'success' ? 'alert-success' : 'alert-danger');
                statusEl.classList.remove('d-none');
                statusEl.style.whiteSpace = 'pre-line';
                statusEl.textContent = message;
            }

            button.disabled = true;
            button.textContent = 'Refreshing...';
            setRefreshStatus('success', 'Refreshing standings data...');

            fetch('refresh.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: 'csrf_token=' + encodeURIComponent(button.getAttribute('data-csrf-token') || '')
            }).then(function(response) {
                return response.json().then(function(json) {
                    return {
                        ok: response.ok,
                        json: json
                    };
                });
            }).then(function(result) {
                if (!result.ok || !result.json || !result.json.ok) {
                    throw new Error((result.json && result.json.error) ? result.json.error : 'Refresh failed.');
                }

                setRefreshStatus('success', result.json.message || 'Standings refreshed successfully.');
                window.setTimeout(function() {
                    window.location.reload();
                }, 1000);
            }).catch(function(error) {
                setRefreshStatus('error', error.message);
            }).finally(function() {
                button.disabled = false;
                button.textContent = 'Refresh Table';
            });
        });

        document.getElementById('saveAsImageBtn').addEventListener('click', function() {
            var link = document.createElement('a');
            link.href = 'download_league_table.php?table_style=<?= rawurlencode($tableStyle) ?>&v=' + Date.now();
            link.download = '';
            document.body.appendChild(link);
            link.click();
            link.remove();
        });

        var postComposerPresets = <?= json_encode($postComposerPresets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        var postComposerCsrfToken = <?= json_encode((string) ($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
        var postComposerConfigs = {
            facebook: {
                buttonId: 'postToFacebookBtn',
                statusId: 'facebookPostStatus',
                endpoint: 'generate_and_post.php',
                requestTarget: 'facebook',
                modalTitle: 'Post to Facebook',
                modalTarget: 'Facebook',
                submitLabel: 'Post to Facebook',
                progressLabel: 'Posting...',
                progressMessage: 'Posting to Facebook...',
                successSummary: 'Facebook post completed.',
                failureSummary: 'Facebook post failed.',
                presets: postComposerPresets.facebook || []
            },
            instagram: {
                buttonId: 'postToInstagramBtn',
                statusId: 'instagramPostStatus',
                endpoint: 'generate_and_post.php',
                requestTarget: 'instagram',
                modalTitle: 'Post to Instagram',
                modalTarget: 'Instagram',
                submitLabel: 'Post to Instagram',
                progressLabel: 'Posting...',
                progressMessage: 'Posting to Instagram...',
                successSummary: 'Instagram post completed.',
                failureSummary: 'Instagram post failed.',
                presets: postComposerPresets.instagram || []
            },
            x: {
                buttonId: 'postToTwitterBtn',
                statusId: 'twitterPostStatus',
                endpoint: 'prepare_twitter_share.php',
                requestTarget: 'x',
                modalTitle: 'Prepare Twitter Share',
                modalTarget: 'X',
                submitLabel: 'Prepare Share',
                progressLabel: 'Preparing...',
                progressMessage: 'Preparing Twitter share...',
                successSummary: 'Twitter share prepared.',
                failureSummary: 'Twitter share prep failed.',
                presets: postComposerPresets.x || []
            }
        };

        var postModal = document.getElementById('postModal');
        var postModalForm = document.getElementById('postModalForm');
        var postModalTitle = document.getElementById('postModalTitle');
        var postModalHelp = document.getElementById('postModalHelp');
        var postModalTarget = document.getElementById('postModalTarget');
        var postModalPills = document.getElementById('postModalPills');
        var postModalTextarea = document.getElementById('postModalTextarea');
        var postModalNotice = document.getElementById('postModalNotice');
        var postModalSubmitBtn = document.getElementById('postModalSubmitBtn');
        var activePostTarget = null;

        function setPostStatus(statusId, type, message) {
            var statusEl = document.getElementById(statusId);
            if (!statusEl) {
                return;
            }

            statusEl.className = 'alert mt-2';
            statusEl.classList.add(type === 'success' ? 'alert-success' : 'alert-danger');
            statusEl.classList.remove('d-none');
            statusEl.style.whiteSpace = 'pre-line';
            statusEl.textContent = message;
        }

        function setPostModalNotice(type, message) {
            if (!postModalNotice) {
                return;
            }

            if (!message) {
                postModalNotice.className = 'post-modal__notice d-none';
                postModalNotice.textContent = '';
                return;
            }

            postModalNotice.className = 'post-modal__notice';
            postModalNotice.classList.add(type === 'success' ? 'post-modal__notice--success' : 'post-modal__notice--error');
            postModalNotice.classList.remove('d-none');
            postModalNotice.style.whiteSpace = 'pre-line';
            postModalNotice.textContent = message;
        }

        function normalizeCaption(value) {
            return String(value || '').replace(/\r\n?/g, '\n').trim();
        }

        function getPresetList(config) {
            var presets = config && config.presets && typeof config.presets === 'object' ? config.presets : {};
            return Object.keys(presets).map(function(key) {
                var preset = presets[key] || {};
                return {
                    key: key,
                    label: preset.label || key,
                    caption: preset.caption || ''
                };
            });
        }

        function renderPresetButtons(config, selectedValue) {
            var presets = getPresetList(config);
            var selected = normalizeCaption(selectedValue);

            postModalPills.innerHTML = '';

            presets.forEach(function(preset, index) {
                if (!preset || typeof preset.caption !== 'string') {
                    return;
                }

                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-sm btn-outline-maroon post-modal__pill';
                button.textContent = preset.label || 'Preset ' + (index + 1);
                button.dataset.caption = preset.caption;

                if (normalizeCaption(preset.caption) === selected) {
                    button.classList.add('active');
                }

                button.addEventListener('click', function() {
                    postModalTextarea.value = preset.caption;
                    updatePresetSelection();
                    postModalTextarea.focus();
                });

                postModalPills.appendChild(button);
            });
        }

        function updatePresetSelection() {
            var currentValue = normalizeCaption(postModalTextarea.value);
            var buttons = postModalPills.querySelectorAll('.post-modal__pill');

            buttons.forEach(function(button) {
                var caption = normalizeCaption(button.dataset.caption || '');
                button.classList.toggle('active', caption !== '' && caption === currentValue);
            });
        }

        function openPostComposer(targetKey) {
            var config = postComposerConfigs[targetKey];
            if (!config) {
                return;
            }

            activePostTarget = targetKey;
            postModalTitle.textContent = config.modalTitle;
            postModalHelp.textContent = targetKey === 'x'
                ? 'Choose a caption for the X share, then we will open the composer for you.'
                : 'Choose a caption or type your own before posting.';
            postModalTarget.textContent = config.modalTarget;
            postModalSubmitBtn.textContent = config.submitLabel;

            var defaultCaption = '';
            var presetList = getPresetList(config);
            if (presetList.length > 0 && typeof presetList[0].caption === 'string') {
                defaultCaption = presetList[0].caption;
            }

            postModalTextarea.value = defaultCaption;
            renderPresetButtons(config, defaultCaption);
            setPostModalNotice('', '');
            postModal.classList.remove('is-hidden');
            document.body.classList.add('modal-open');

            window.setTimeout(function() {
                postModalTextarea.focus();
                postModalTextarea.setSelectionRange(postModalTextarea.value.length, postModalTextarea.value.length);
            }, 0);
        }

        function closePostComposer() {
            activePostTarget = null;
            postModal.classList.add('is-hidden');
            document.body.classList.remove('modal-open');
            setPostModalNotice('', '');
        }

        function buildRequestBody(config, caption) {
            var params = new URLSearchParams();
            params.set('graphic', 'league_table');
            params.set('csrf_token', postComposerCsrfToken);
            params.set('table_style', <?= json_encode($tableStyle) ?>);
            if (config.requestTarget) {
                params.set('target', config.requestTarget);
            }
            if (caption !== '') {
                params.set('caption', caption);
            }
            return params.toString();
        }

        function handleComposerSubmit(event) {
            event.preventDefault();

            if (!activePostTarget) {
                return;
            }

            var config = postComposerConfigs[activePostTarget];
            if (!config) {
                return;
            }

            var caption = normalizeCaption(postModalTextarea.value);
            var presetList = getPresetList(config);
            if (caption === '' && presetList.length > 0) {
                caption = normalizeCaption(presetList[0].caption);
            }

            var submitButton = postModalSubmitBtn;
            var originalText = submitButton.textContent;
            submitButton.disabled = true;
            submitButton.textContent = config.progressLabel;
            setPostModalNotice('success', config.progressMessage);
            setPostStatus(config.statusId, 'success', config.progressMessage);

            fetch(config.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: buildRequestBody(config, caption)
            }).then(function(response) {
                return response.text().then(function(body) {
                    var json;
                    try {
                        json = JSON.parse(body);
                    } catch (error) {
                        throw new Error('The publishing service returned an unexpected response (HTTP ' + response.status + ').');
                    }
                    return {
                        ok: response.ok,
                        json: json
                    };
                }).catch(function() {
                    return {
                        ok: response.ok,
                        json: null
                    };
                });
            }).then(function(result) {
                if (!result.ok || !result.json || !result.json.ok) {
                    var failureDetails = result.json && Array.isArray(result.json.details)
                        ? result.json.details.filter(function(detail) {
                            return typeof detail === 'string' && detail.trim() !== '';
                        })
                        : [];
                    var failureMessage = (result.json && result.json.summary) ? result.json.summary : 'Request failed.';

                    if (failureDetails.length) {
                        failureMessage += '\n\n' + failureDetails.join('\n');
                    }

                    throw new Error(failureMessage);
                }

                var details = Array.isArray(result.json.details) ? result.json.details.filter(function(detail) {
                    return typeof detail === 'string' && detail.trim() !== '';
                }) : [];
                var message = result.json.summary || config.successSummary;

                if (details.length) {
                    message += '\n\n' + details.join('\n');
                }

                setPostStatus(config.statusId, 'success', message);

                if (activePostTarget === 'x') {
                    if (result.json.compose_url) {
                        window.open(result.json.compose_url, '_blank');
                    }

                    if (result.json.download_url) {
                        var downloadLink = document.createElement('a');
                        downloadLink.href = result.json.download_url;
                        downloadLink.download = '';
                        document.body.appendChild(downloadLink);
                        downloadLink.click();
                        document.body.removeChild(downloadLink);
                    }
                }

                closePostComposer();
            }).catch(function(error) {
                var errorMessage = error && typeof error.message === 'string' && error.message.trim() !== ''
                    ? error.message.trim()
                    : 'Request failed.';

                setPostModalNotice('error', errorMessage);
                setPostStatus(config.statusId, 'error', config.failureSummary + '\n\n' + errorMessage);
            }).finally(function() {
                submitButton.disabled = false;
                submitButton.textContent = originalText;
            });
        }

        document.getElementById('postToFacebookBtn').addEventListener('click', function() {
            openPostComposer('facebook');
        });

        document.getElementById('postToInstagramBtn').addEventListener('click', function() {
            openPostComposer('instagram');
        });

        document.getElementById('postToTwitterBtn').addEventListener('click', function() {
            openPostComposer('x');
        });

        postModalForm.addEventListener('submit', handleComposerSubmit);

        postModalTextarea.addEventListener('input', updatePresetSelection);

        Array.prototype.forEach.call(document.querySelectorAll('[data-post-modal-close]'), function(button) {
            button.addEventListener('click', closePostComposer);
        });

        postModal.addEventListener('click', function(event) {
            if (event.target === postModal) {
                closePostComposer();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && !postModal.classList.contains('is-hidden')) {
                closePostComposer();
            }
        });
        <?php endif; ?>
    </script>
</body>

</html>
