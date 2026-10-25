<?php
declare(strict_types=1);

require_once __DIR__ . '/social_post_settings.php';
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
// The scrape below writes a cache file and shells out, so gate before it runs
// rather than relying on header.php further down.
require_once __DIR__ . '/auth.php';
if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
hub_auth_require_capability('publishing');

require_once __DIR__ . '/wosfl-table.php';

if (!isset($teams) || !is_array($teams)) {
    $teams = [];
}

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

if (is_file($configFile)) {
    $json = file_get_contents($configFile);
    $decoded = is_string($json) ? decode_comment_tolerant_json($json) : null;
    if (is_array($decoded)) {
        $leagueConfig = array_merge($leagueConfig, $decoded);
    }
}

$leagueConfig['total_games_per_team'] = max(1, (int) ($leagueConfig['total_games_per_team'] ?? 30));
$leagueConfig['promotion_spots'] = max(1, (int) ($leagueConfig['promotion_spots'] ?? 9));
$leagueConfig['relegation_spots'] = max(0, (int) ($leagueConfig['relegation_spots'] ?? 0));
$leagueConfig['show_table_lines'] = !empty($leagueConfig['show_table_lines']);
$leagueConfig['league_title'] = trim((string) ($leagueConfig['league_title'] ?? 'WOSFL Fourth Division')) ?: 'WOSFL Fourth Division';
$leagueConfig['league_table_link'] = trim((string) ($leagueConfig['league_table_link'] ?? 'league_table.php')) ?: 'league_table.php';
$leagueConfig['league_banner_image'] = trim((string) ($leagueConfig['league_banner_image'] ?? 'assets/images/header.png')) ?: 'assets/images/header.png';
$leagueConfig['white_badge_image'] = trim((string) ($leagueConfig['white_badge_image'] ?? ''));

$leagueBadgeDir = __DIR__ . '/badges';
$leagueBadgeOverrides = league_table_load_badge_overrides(__DIR__ . '/data/wosfl_badge_overrides.json');

foreach ($teams as &$team) {
    $clubName = trim((string) ($team['club'] ?? ''));
    $logoUrl = trim((string) ($team['logo'] ?? ''));
    $opponentBadge = matchOpponentBadgeAssetUrl($pdo, $clubName);
    if ($opponentBadge !== '') {
        $team['logo'] = $opponentBadge;
        continue;
    }
    [$localLogo, $localPath] = league_table_resolve_badge($clubName, $logoUrl, $leagueBadgeDir, $leagueBadgeOverrides);
    if ($localLogo !== '') {
        $team['logo'] = $localLogo;
    }
}
unset($team);

if (is_array($leagueCompetition) && !empty($leagueCompetition['is_league'])) {
    $competitionName = trim((string) ($leagueCompetition['name'] ?? ''));
    if ($competitionName !== '') {
        $leagueConfig['league_title'] = $competitionName;
    }
    $leagueConfig['league_table_link'] = trim((string) ($leagueCompetition['league_url'] ?? '')) ?: $leagueConfig['league_table_link'];
    $leagueConfig['league_banner_image'] = trim((string) ($leagueCompetition['league_banner_image'] ?? '')) ?: $leagueConfig['league_banner_image'];
    $leagueConfig['white_badge_image'] = trim((string) ($leagueCompetition['white_badge_image'] ?? '')) ?: $leagueConfig['white_badge_image'];
    $leagueConfig['promotion_spots'] = isset($leagueCompetition['promotion_spots']) && $leagueCompetition['promotion_spots'] !== null
        ? max(1, (int) $leagueCompetition['promotion_spots'])
        : $leagueConfig['promotion_spots'];
    $leagueConfig['relegation_spots'] = isset($leagueCompetition['relegation_spots']) && $leagueCompetition['relegation_spots'] !== null
        ? max(0, (int) $leagueCompetition['relegation_spots'])
        : $leagueConfig['relegation_spots'];
    $leagueConfig['show_table_lines'] = array_key_exists('show_table_lines', $leagueCompetition)
        ? !empty($leagueCompetition['show_table_lines'])
        : $leagueConfig['show_table_lines'];
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

function normalize_team_name(string $name): string
{
    return strtolower(preg_replace('/\s+/', ' ', trim($name)));
}

function league_table_normalize_club_name(string $clubName): string
{
    $clubName = strtolower(trim($clubName));
    $clubName = preg_replace('/\s+/', ' ', $clubName) ?? $clubName;

    return trim($clubName);
}

/**
 * @return array<string, string>
 */
function league_table_load_badge_overrides(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $overrides = [];
    foreach ($decoded as $clubName => $badgePath) {
        if (!is_string($clubName) || !is_string($badgePath)) {
            continue;
        }

        $normalizedClub = league_table_normalize_club_name($clubName);
        if ($normalizedClub === '') {
            continue;
        }

        $overrides[$normalizedClub] = trim($badgePath);
    }

    return $overrides;
}

/**
 * @return array{0: string, 1: string}
 */
function league_table_resolve_badge(string $clubName, string $logoUrl, string $badgeDir, array $badgeOverrides): array
{
    $slugSource = strtolower($clubName);
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slugSource), '-');
    $defaultRelative = 'badges/' . $slug . '.png';
    $defaultPath = $badgeDir . '/' . basename($defaultRelative);

    $overrideKey = league_table_normalize_club_name($clubName);
    $overrideRelative = null;

    if ($overrideKey !== '' && isset($badgeOverrides[$overrideKey])) {
        $overrideCandidate = trim((string) $badgeOverrides[$overrideKey]);
        if ($overrideCandidate !== '') {
            $overrideCandidate = str_replace('\\', '/', $overrideCandidate);
            $overrideCandidate = ltrim($overrideCandidate, '/');
            $overrideCandidate = preg_replace('#^\.\/#', '', $overrideCandidate) ?? $overrideCandidate;

            if (str_starts_with($overrideCandidate, 'badges/')) {
                $overrideRelative = $overrideCandidate;
            } else {
                $overrideRelative = 'badges/' . basename($overrideCandidate);
            }
        }
    }

    $candidates = [];
    if ($overrideRelative !== null) {
        $candidates[] = $overrideRelative;
    }
    $candidates[] = $defaultRelative;

    foreach ($candidates as $relativePath) {
        $absolutePath = __DIR__ . '/' . ltrim($relativePath, '/');
        if (is_file($absolutePath)) {
            return [$relativePath, $absolutePath];
        }
    }

    if ($logoUrl !== '' && !is_file($defaultPath)) {
        $img = @file_get_contents($logoUrl);
        if ($img) {
            @file_put_contents($defaultPath, $img);
        }
    }

    if (is_file($defaultPath)) {
        return [$defaultRelative, $defaultPath];
    }

    if ($overrideRelative !== null) {
        $fallbackPath = __DIR__ . '/' . ltrim($overrideRelative, '/');
        if (is_file($fallbackPath)) {
            return [$overrideRelative, $fallbackPath];
        }
    }

    return [$defaultRelative, $defaultPath];
}

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
        if (($other['club'] ?? '') === ($team['club'] ?? '')) {
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

$tableDataSource = $tableDataSource ?? 'live';
$tableStatusLabel = $tableStatusLabel ?? 'Live data';
$tableStatusMessage = $tableStatusMessage ?? '';
$lastUpdatedTimestamp = @filemtime(__DIR__ . '/cache/wosfl_table.json') ?: time();
$lastUpdatedString = date('j M Y, H:i', $lastUpdatedTimestamp);
$dateShort = date('j M Y', $lastUpdatedTimestamp);
$tableMatchWeek = 0;
foreach ($teams as $team) {
    $tableMatchWeek = max($tableMatchWeek, (int) ($team['p'] ?? 0));
}

function league_table_asset_url(string $path): string
{
    $path = trim($path);
    if ($path === '' || preg_match('#^(?:https?:)?//#i', $path) === 1 || str_contains($path, '?')) {
        return $path;
    }

    $candidatePaths = [
        __DIR__ . '/' . ltrim($path, '/'),
        __DIR__ . '/uploads/competitions/' . basename($path),
    ];

    foreach ($candidatePaths as $absolutePath) {
        if (!is_file($absolutePath)) {
            continue;
        }

        $relativePath = str_replace(__DIR__ . '/', '', $absolutePath);
        return $relativePath . '?v=' . rawurlencode((string) filemtime($absolutePath));
    }

    return $path;
}

$leagueTableTitle = (string) $leagueConfig['league_title'];
$leagueBannerImage = league_table_asset_url((string) $leagueConfig['league_banner_image']);
$compactLeagueBannerImage = $leagueConfig['white_badge_image'] !== ''
    ? league_table_asset_url((string) $leagueConfig['white_badge_image'])
    : $leagueBannerImage;
$leagueBannerAlt = trim($leagueTableTitle) !== '' ? $leagueTableTitle . ' banner' : 'League table banner';
$leagueTableHref = (string) $leagueConfig['league_table_link'];
$postComposerPresets = [
    'facebook' => social_post_get_preset_groups('facebook', 'league_table'),
    'instagram' => social_post_get_preset_groups('instagram', 'league_table'),
    'x' => social_post_get_preset_groups('x', 'league_table'),
];

$pageHero = [
    'eyebrow' => 'Publishing',
    'title' => 'League Table',
    'subtitle' => $leagueConfig['league_title'] . ' standings and publish tools.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';

function league_table_render_row(array $team): string
{
    $isHighlight = stripos((string) ($team['club'] ?? ''), 'Saltcoats Victoria') !== false;
    $rowClass = [];
    if ($isHighlight) {
        $rowClass[] = 'table-warning';
    }
    if (!empty($team['promoted'])) {
        $rowClass[] = 'table-success';
    }

    $cellClass = trim(
        (!empty($team['promotion_line']) ? 'promotion-line ' : '') .
        (!empty($team['relegation_line']) ? 'relegation-line' : '')
    );

    $logoHtml = '';
    if (!empty($team['logo'])) {
        $logoHtml = '<img src="' . htmlspecialchars((string) $team['logo'], ENT_QUOTES, 'UTF-8') . '?t=' . time() . '" alt="' . htmlspecialchars((string) ($team['club'] ?? ''), ENT_QUOTES, 'UTF-8') . '" loading="lazy" width="24" height="24">';
    }

    $promotedBadge = !empty($team['promoted'])
        ? '<span class="promoted-badge" aria-label="Promoted" title="Promoted">P</span>'
        : '';

    return '<tr class="' . htmlspecialchars(trim(implode(' ', $rowClass)), ENT_QUOTES, 'UTF-8') . '">' .
        '<td class="col-pos ' . htmlspecialchars($cellClass, ENT_QUOTES, 'UTF-8') . '"><span class="position-chip' . ((int) ($team['pos'] ?? 0) > 0 && (int) ($team['pos'] ?? 0) <= 4 ? ' position-chip--top' : '') . '">' . htmlspecialchars((string) ($team['pos'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</span></td>' .
        '<th scope="row" class="col-club club-cell ' . htmlspecialchars($cellClass, ENT_QUOTES, 'UTF-8') . '"><div class="club-cell__content">' . $logoHtml . '<span class="club-name">' . htmlspecialchars((string) ($team['club'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</span></div></th>' .
        '<td class="col-promoted ' . htmlspecialchars($cellClass, ENT_QUOTES, 'UTF-8') . '">' . $promotedBadge . '</td>' .
        '<td class="col-stat col-played ' . htmlspecialchars($cellClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($team['p'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>' .
        '<td class="col-stat col-won ' . htmlspecialchars($cellClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($team['w'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>' .
        '<td class="col-stat col-drawn ' . htmlspecialchars($cellClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($team['d'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>' .
        '<td class="col-stat col-lost ' . htmlspecialchars($cellClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($team['l'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>' .
        '<td class="col-stat col-goal-difference ' . htmlspecialchars($cellClass, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) ($team['gd'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</td>' .
        '<td class="col-points ' . htmlspecialchars($cellClass, ENT_QUOTES, 'UTF-8') . '"><span class="points-chip">' . htmlspecialchars((string) ($team['pts'] ?? '-'), ENT_QUOTES, 'UTF-8') . '</span></td>' .
        '<td class="col-form ' . htmlspecialchars($cellClass, ENT_QUOTES, 'UTF-8') . '">' . league_table_form_html(is_array($team['form'] ?? null) ? $team['form'] : []) . '</td>' .
        '</tr>';
}
?>

<div>
    <?php if ($tableStatusMessage !== ''): ?>
        <div class="alert alert-info border-0 shadow-sm mb-4"><?= htmlspecialchars($tableStatusMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="card dashboard-card hub-section mb-4">
        <div class="card-body py-3">
            <div class="league-toolbar">
                <div class="btn-group league-table-style-toggle" role="group" aria-label="League table design">
                    <button type="button" class="btn btn-outline-primary" data-table-style="standard" aria-pressed="false" title="Standard table" aria-label="Standard table">
                        <i class="fa-solid fa-table" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-brand active" data-table-style="compact" aria-pressed="true" title="Compact table" aria-label="Compact table">
                        <i class="fa-solid fa-list-ol" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-primary" data-table-style="expanded" aria-pressed="false" title="Expanded table with recent form" aria-label="Expanded table with recent form">
                        <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
                    </button>
                </div>
                <div class="league-toolbar__center">
                    <button type="button" class="btn btn-outline-secondary league-update-btn" id="updateFromWosflBtn" data-state="idle" data-fallback-url="/admin/league_table_manual_update.php">
                        <span class="league-update-btn__icon" aria-hidden="true">
                            <i class="fa-solid fa-arrows-rotate league-update-btn__idle"></i>
                            <span class="league-update-btn__spinner"></span>
                            <i class="fa-solid fa-check league-update-btn__done"></i>
                            <i class="fa-solid fa-triangle-exclamation league-update-btn__fail"></i>
                        </span>
                        <span class="league-update-btn__label" id="updateFromWosflLabel" aria-live="polite">Update from WOSFL</span>
                        <span class="league-update-btn__bar" aria-hidden="true"></span>
                    </button>
                    <a class="btn btn-outline-secondary" href="/admin/league_table_history.php"><i class="fa-solid fa-clock-rotate-left me-1" aria-hidden="true"></i>Historical tables</a>
                </div>
                <div class="btn-group league-table-actions" role="group" aria-label="League table actions">
                    <button id="saveAsImageBtn" class="btn btn-brand" type="button" title="Download PNG" aria-label="Download PNG">
                        <i class="fa-solid fa-download" aria-hidden="true"></i>
                    </button>
                    <button id="postToFacebookBtn" class="btn league-action--facebook" type="button" title="Post to Facebook" aria-label="Post to Facebook">
                        <i class="fa-brands fa-facebook" aria-hidden="true"></i>
                    </button>
                    <button id="postToInstagramBtn" class="btn league-action--instagram" type="button" title="Post to Instagram" aria-label="Post to Instagram">
                        <i class="fa-brands fa-instagram" aria-hidden="true"></i>
                    </button>
                    <button id="postToTwitterBtn" class="btn league-action--twitter" type="button" title="Prepare Twitter/X share" aria-label="Prepare Twitter/X share">
                        <i class="fa-brands fa-x-twitter" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4 table-card table-card--compact hub-table-card" id="leagueTableCard" data-table-style-current="compact">
        <div class="card-body table-card__body" id="tableCardBody">
            <div class="table-card__hero text-center mb-3">
                <img
                    src="<?= htmlspecialchars($compactLeagueBannerImage, ENT_QUOTES, 'UTF-8') ?>"
                    data-standard-src="<?= htmlspecialchars($leagueBannerImage, ENT_QUOTES, 'UTF-8') ?>"
                    data-compact-src="<?= htmlspecialchars($compactLeagueBannerImage, ENT_QUOTES, 'UTF-8') ?>"
                    alt="<?= htmlspecialchars($leagueBannerAlt, ENT_QUOTES, 'UTF-8') ?>"
                    loading="lazy"
                    class="img-fluid banner-img banner-img--small"
                >
                <div class="compact-matchweek" aria-hidden="true"><span>Match Week</span><strong><?= $tableMatchWeek ?></strong></div>
            </div>

            <div class="table-scroll" id="tableWrapper">
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
                        <?php foreach ($teams as $i => $team): ?>
                            <?php
                                $team['promotion_line'] = $showTableLines && (($i + 1) === $promotionSpots);
                                $team['relegation_line'] = $showTableLines && $relegationLineIndex > 0 && (($i + 1) === $relegationLineIndex);
                            ?>
                            <?= league_table_render_row($team) ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="text-center small text-muted mt-3">Last Updated: <span id="leagueLastUpdated"><?= htmlspecialchars($lastUpdatedString, ENT_QUOTES, 'UTF-8') ?></span></div>
        </div>
    </div>
</div>

<div id="postModal" class="modal fade" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <p class="page-kicker mb-1">Social post</p>
                    <h5 class="modal-title mb-0" id="postModalTitle">Prepare post</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="postModalNotice" class="alert d-none" role="status" aria-live="polite"></div>
                <div class="mb-3">
                    <label for="postModalTextarea" class="form-label">Caption</label>
                    <textarea id="postModalTextarea" class="form-control" rows="5"></textarea>
                </div>
                <div class="mb-2">
                    <label class="form-label">Preset captions</label>
                    <div id="postModalPills" class="d-flex flex-wrap gap-2"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button id="postModalSubmitBtn" type="button" class="btn btn-brand">Send</button>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        (function () {
            var btn = document.getElementById('updateFromWosflBtn');
            var card = document.getElementById('leagueTableCard');
            var label = document.getElementById('updateFromWosflLabel');
            if (!btn || !card || !label) { return; }
            var tbody = card.querySelector('#tableWrapper tbody');
            var steps = ['Contacting WOSFL\u2026', 'Reading standings\u2026', 'Checking recent form\u2026', 'Updating the table\u2026'];
            var stepTimer = null;
            var running = false;

            function setState(state, text) {
                btn.dataset.state = state;
                label.textContent = text;
            }

            function startSteps() {
                var i = 0;
                setState('loading', steps[0]);
                stepTimer = window.setInterval(function () {
                    i = Math.min(i + 1, steps.length - 1);
                    label.textContent = steps[i];
                }, 2200);
            }

            function stopSteps() {
                if (stepTimer) { window.clearInterval(stepTimer); stepTimer = null; }
            }

            function wait(ms) { return new Promise(function (resolve) { window.setTimeout(resolve, ms); }); }

            function showError(message) {
                var existing = document.getElementById('leagueUpdateError');
                if (existing) { existing.remove(); }
                var box = document.createElement('div');
                box.id = 'leagueUpdateError';
                box.className = 'alert alert-danger border-0 shadow-sm mb-4';
                box.textContent = message + ' ';
                var link = document.createElement('a');
                link.href = btn.dataset.fallbackUrl;
                link.className = 'alert-link';
                link.textContent = 'Paste the table in manually instead';
                box.appendChild(link);
                card.parentNode.insertBefore(box, card);
            }

            btn.addEventListener('click', function () {
                if (running) { return; }
                running = true;
                btn.disabled = true;
                var oldError = document.getElementById('leagueUpdateError');
                if (oldError) { oldError.remove(); }
                card.classList.add('is-refreshing');
                startSteps();
                var started = Date.now();

                fetch('refresh.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'csrf_token=' + encodeURIComponent(postComposerCsrfToken),
                    credentials: 'same-origin'
                }).then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (json) {
                        return { ok: response.ok, json: json };
                    });
                }).then(function (result) {
                    // The table itself is refreshed once the standings step succeeds; a failure
                    // in the later export-image step must not block showing the new rows.
                    var standingsRefreshed = result.json && typeof result.json.summary === 'string'
                        && result.json.summary.indexOf('Standings refreshed') === 0;
                    if (!standingsRefreshed) {
                        var detail = (result.json && result.json.details && result.json.details[0]) || (result.json && result.json.summary) || 'WOSFL could not be reached.';
                        throw new Error(detail);
                    }
                    label.textContent = 'Updating the table\u2026';
                    return fetch('league_table.php', { credentials: 'same-origin', cache: 'no-store' });
                }).then(function (response) {
                    if (!response.ok) { throw new Error('The refreshed table could not be loaded.'); }
                    return response.text();
                }).then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var fresh = doc.querySelector('#tableWrapper tbody');
                    if (!fresh || !fresh.children.length) { throw new Error('The refreshed table was empty.'); }
                    var week = doc.querySelector('#leagueTableCard .compact-matchweek strong');
                    var updated = doc.getElementById('leagueLastUpdated');
                    // Keep the shimmer on screen long enough to read as a deliberate refresh.
                    return wait(Math.max(0, 1600 - (Date.now() - started))).then(function () {
                        tbody.innerHTML = fresh.innerHTML;
                        var weekEl = card.querySelector('.compact-matchweek strong');
                        if (weekEl && week) { weekEl.textContent = week.textContent; }
                        var updatedEl = document.getElementById('leagueLastUpdated');
                        if (updatedEl && updated) { updatedEl.textContent = updated.textContent; }
                        stopSteps();
                        card.classList.remove('is-refreshing');
                        card.classList.add('is-fresh');
                        setState('done', 'Table updated');
                        window.setTimeout(function () { card.classList.remove('is-fresh'); }, 1500);
                    });
                }).catch(function (error) {
                    stopSteps();
                    card.classList.remove('is-refreshing');
                    setState('error', 'Update failed');
                    showError((error && error.message) ? error.message : 'The update failed.');
                }).then(function () {
                    return wait(2600);
                }).then(function () {
                    setState('idle', 'Update from WOSFL');
                    btn.disabled = false;
                    running = false;
                });
            });
        })();

        var selectedTableStyle = 'compact';
        var leagueTableCard = document.getElementById('leagueTableCard');
        var leagueBanner = leagueTableCard ? leagueTableCard.querySelector('.banner-img') : null;
        var tableStyleButtons = Array.prototype.slice.call(document.querySelectorAll('[data-table-style]'));

        function setTableStyle(style) {
            selectedTableStyle = ['standard', 'compact', 'expanded'].indexOf(style) !== -1 ? style : 'standard';
            // Mirrors download/league_table_graphic.php: every style uses the compact
            // sidebar layout. Standard and expanded add the full columns; expanded adds form.
            var expanded = selectedTableStyle === 'expanded';
            leagueTableCard.classList.add('table-card--compact');
            leagueTableCard.classList.toggle('table-card--standard', selectedTableStyle !== 'compact');
            leagueTableCard.classList.toggle('table-card--expanded', expanded);
            leagueTableCard.dataset.tableStyleCurrent = selectedTableStyle;
            if (leagueBanner) {
                leagueBanner.src = leagueBanner.dataset.compactSrc;
                leagueBanner.classList.add('banner-img--small');
            }
            tableStyleButtons.forEach(function(button) {
                var active = button.dataset.tableStyle === selectedTableStyle;
                button.classList.toggle('active', active);
                button.classList.toggle('btn-brand', active);
                button.classList.toggle('btn-outline-primary', !active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }

        tableStyleButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                setTableStyle(button.dataset.tableStyle || 'standard');
            });
        });

        var postComposerPresets = <?= json_encode($postComposerPresets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        var postComposerCsrfToken = <?= json_encode((string) ($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
        var postComposerConfigs = {
            facebook: {
                buttonId: 'postToFacebookBtn',
                endpoint: 'generate_and_post.php',
                requestTarget: 'facebook',
                modalTitle: 'Post to Facebook',
                submitLabel: 'Post to Facebook',
                progressLabel: 'Posting...',
                progressMessage: 'Posting to Facebook...',
                successSummary: 'Facebook post completed.',
                failureSummary: 'Facebook post failed.',
                presets: postComposerPresets.facebook || []
            },
            instagram: {
                buttonId: 'postToInstagramBtn',
                endpoint: 'generate_and_post.php',
                requestTarget: 'instagram',
                modalTitle: 'Post to Instagram',
                submitLabel: 'Post to Instagram',
                progressLabel: 'Posting...',
                progressMessage: 'Posting to Instagram...',
                successSummary: 'Instagram post completed.',
                failureSummary: 'Instagram post failed.',
                presets: postComposerPresets.instagram || []
            },
            x: {
                buttonId: 'postToTwitterBtn',
                endpoint: 'prepare_twitter_share.php',
                requestTarget: 'x',
                modalTitle: 'Prepare Twitter Share',
                submitLabel: 'Prepare Share',
                progressLabel: 'Preparing...',
                progressMessage: 'Preparing Twitter share...',
                successSummary: 'Twitter share prepared.',
                failureSummary: 'Twitter share prep failed.',
                presets: postComposerPresets.x || []
            }
        };

        var postModalEl = document.getElementById('postModal');
        if (!postModalEl) {
            return;
        }

        var postModal = new bootstrap.Modal(postModalEl);
        var postModalTitle = document.getElementById('postModalTitle');
        var postModalTextarea = document.getElementById('postModalTextarea');
        var postModalPills = document.getElementById('postModalPills');
        var postModalNotice = document.getElementById('postModalNotice');
        var postModalSubmitBtn = document.getElementById('postModalSubmitBtn');
        var activePostTarget = null;

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

        function setNotice(type, message) {
            if (!message) {
                postModalNotice.className = 'alert d-none';
                postModalNotice.textContent = '';
                return;
            }
            postModalNotice.className = 'alert';
            postModalNotice.classList.add(type === 'success' ? 'alert-success' : 'alert-danger');
            postModalNotice.textContent = message;
        }

        function renderPresetButtons(config, selectedValue) {
            var presets = getPresetList(config);
            var selected = normalizeCaption(selectedValue);
            postModalPills.innerHTML = '';
            presets.forEach(function(preset, index) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-sm btn-outline-primary';
                button.textContent = preset.label || ('Preset ' + (index + 1));
                button.dataset.caption = preset.caption;
                if (normalizeCaption(preset.caption) === selected) {
                    button.classList.add('active');
                }
                button.addEventListener('click', function() {
                    postModalTextarea.value = preset.caption;
                    updatePresetSelection();
                });
                postModalPills.appendChild(button);
            });
        }

        function updatePresetSelection() {
            var currentValue = normalizeCaption(postModalTextarea.value);
            var buttons = postModalPills.querySelectorAll('button');
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

            var presetList = getPresetList(config);
            var defaultCaption = presetList.length > 0 ? presetList[0].caption : '';
            postModalTextarea.value = defaultCaption;
            renderPresetButtons(config, defaultCaption);
            setNotice('', '');
            postModal.show();
        }

        function buildRequestBody(config, caption, confirmBurst) {
            var params = new URLSearchParams();
            params.set('graphic', 'league_table');
            params.set('csrf_token', postComposerCsrfToken);
            params.set('table_style', selectedTableStyle);
            if (config.requestTarget) {
                params.set('target', config.requestTarget);
            }
            if (caption !== '') {
                params.set('caption', caption);
            }
            if (confirmBurst) {
                params.set('confirm_burst', '1');
            }
            return params.toString();
        }

        function submitComposerRequest(config, caption, confirmBurst) {
            return fetch(config.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: buildRequestBody(config, caption, confirmBurst)
            }).then(function(response) {
                return response.text().then(function(body) {
                    var json;
                    try {
                        json = JSON.parse(body);
                    } catch (error) {
                        throw new Error('The publishing service returned an unexpected response (HTTP ' + response.status + ').');
                    }
                    return { ok: response.ok, json: json };
                });
            }).then(function(result) {
                // Posting-frequency safeguard: not a hard block, just a
                // confirmation prompt before an accidental burst of posts
                // goes any further.
                if (result.json && result.json.requires_confirmation) {
                    var message = result.json.summary || result.json.message || 'Publish anyway?';
                    return window.hubConfirm(message, { actionLabel: 'Publish anyway', actionClass: 'btn-primary' }).then(function (confirmed) {
                        if (confirmed) {
                            return submitComposerRequest(config, caption, true);
                        }
                        throw new Error('Post cancelled.');
                    });
                }
                return result;
            });
        }

        function handleComposerSubmit() {
            if (!activePostTarget) {
                return;
            }

            var config = postComposerConfigs[activePostTarget];
            var caption = normalizeCaption(postModalTextarea.value);
            var presetList = getPresetList(config);
            if (caption === '' && presetList.length > 0) {
                caption = normalizeCaption(presetList[0].caption);
            }

            var originalText = postModalSubmitBtn.textContent;
            postModalSubmitBtn.disabled = true;
            postModalSubmitBtn.textContent = config.progressLabel;
            setNotice('success', config.progressMessage);

            submitComposerRequest(config, caption, false).then(function(result) {
                if (!result.ok || !result.json || !result.json.ok) {
                    throw new Error((result.json && result.json.error) ? result.json.error : 'Request failed.');
                }

                setNotice('success', result.json.summary || config.successSummary);

                if (activePostTarget === 'x') {
                    if (result.json.compose_url) {
                        window.open(result.json.compose_url, '_blank');
                    }
                    if (result.json.download_url) {
                        var downloadLink = document.createElement('a');
                        downloadLink.href = result.json.download_url;
                        document.body.appendChild(downloadLink);
                        downloadLink.click();
                        document.body.removeChild(downloadLink);
                    }
                }
            }).catch(function(error) {
                var errorMessage = error && typeof error.message === 'string' && error.message.trim() !== ''
                    ? error.message.trim()
                    : 'Request failed.';
                setNotice('danger', errorMessage);
            }).finally(function() {
                postModalSubmitBtn.disabled = false;
                postModalSubmitBtn.textContent = originalText;
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
        postModalTextarea.addEventListener('input', updatePresetSelection);
        postModalSubmitBtn.addEventListener('click', handleComposerSubmit);

        document.getElementById('saveAsImageBtn').addEventListener('click', function() {
            var downloadButton = this;
            downloadButton.disabled = true;
            var link = document.createElement('a');
            link.href = 'download_league_table.php?table_style='
                + encodeURIComponent(selectedTableStyle)
                + '&v=' + Date.now();
            link.download = '';
            document.body.appendChild(link);
            link.click();
            link.remove();

            window.setTimeout(function() {
                downloadButton.disabled = false;
            }, 1500);
        });
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
