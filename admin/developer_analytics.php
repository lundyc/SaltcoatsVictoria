<?php
declare(strict_types=1);

$pageStyles = ['developer_analytics.css'];
$pageHero = [
    'eyebrow' => 'Developer',
    'title' => 'Analytics & SEO',
    'subtitle' => 'Site visitors, search performance, and hub usage — without leaving the admin.',
    'actions' => [
        ['label' => 'Developer dashboard', 'href' => '/developer.php', 'icon' => 'fa-arrow-left'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/analytics.php';
require_once __DIR__ . '/lib/google_reporting.php';
require_once __DIR__ . '/lib/analytics_dashboard.php';
require_once __DIR__ . '/lib/analytics_extra.php';

if (!hub_auth_is_developer()) {
    echo '<div class="alert alert-danger m-3">Access denied.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$view = $_GET['view'] ?? 'site';
$view = in_array($view, ['site', 'seo', 'hub', 'business'], true) ? $view : 'site';

$days = hub_analytics_interval_days($_GET['days'] ?? ($view === 'business' ? 90 : 30));

// Custom ?from=&to= range applies to the Google-backed tabs only; the Hub tab
// queries its own table by preset interval.
$customRange = !in_array($view, ['hub', 'business'], true) ? google_reporting_custom_range($_GET) : null;
if ($customRange !== null) {
    $days = $customRange['days'];
}
$range = $customRange !== null ? ['from' => $customRange['from'], 'to' => $customRange['to']] : $days;

function ga_percent(int|float|null $fraction): string
{
    return number_format(((float) ($fraction ?? 0)) * 100, 1) . '%';
}

function ga_seconds(int|float|null $seconds): string
{
    $seconds = (int) round((float) ($seconds ?? 0));
    if ($seconds < 60) {
        return $seconds . 's';
    }
    return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
}

function analytics_number(int|float|null $value): string
{
    return number_format((float) ($value ?? 0));
}

function analytics_seconds(int|float|null $milliseconds): string
{
    $seconds = (int) round(((float) ($milliseconds ?? 0)) / 1000);
    if ($seconds < 60) {
        return $seconds . 's';
    }
    return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
}

function analytics_percent(int|float|null $value): string
{
    return number_format((float) ($value ?? 0), 0) . '%';
}

function analytics_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function analytics_fetch_one(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function analytics_page_param(string $key): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $value === false || $value === null ? 1 : (int) $value;
}

function analytics_paginate_rows(array $rows, int $page, int $perPage = 10): array
{
    $total = count($rows);
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));

    return [
        'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
        'page' => $page,
        'total' => $total,
        'total_pages' => $totalPages,
    ];
}

/**
 * Windowed pager: first, last, current +/- 2 neighbours, with "…" gaps and
 * prev/next arrows — plain 1..N lists got unusably long once a table had
 * more than a page or two of rows.
 */
function analytics_render_pagination(string $pageKey, array $pagination, int $days): void
{
    $totalPages = (int) ($pagination['total_pages'] ?? 1);
    if ($totalPages <= 1) {
        return;
    }

    $currentPage = (int) ($pagination['page'] ?? 1);
    $baseParams = $_GET;
    $baseParams['days'] = $days;

    $link = static function (int $page) use ($pageKey, $baseParams): string {
        $params = $baseParams;
        $params[$pageKey] = $page;
        return '?' . http_build_query($params);
    };

    $window = 2;
    $pages = array_unique(array_merge(
        [1, $totalPages],
        range(max(1, $currentPage - $window), min($totalPages, $currentPage + $window))
    ));
    sort($pages);

    echo '<nav class="mt-3" aria-label="' . h(str_replace('_', ' ', $pageKey)) . ' pages"><ul class="pagination pagination-sm mb-0">';

    echo '<li class="page-item' . ($currentPage <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . h($link(max(1, $currentPage - 1))) . '" aria-label="Previous">&laquo;</a></li>';

    $previous = 0;
    foreach ($pages as $page) {
        if ($previous !== 0 && $page - $previous > 1) {
            echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        echo '<li class="page-item' . ($page === $currentPage ? ' active' : '') . '"><a class="page-link" href="' . h($link($page)) . '">' . $page . '</a></li>';
        $previous = $page;
    }

    echo '<li class="page-item' . ($currentPage >= $totalPages ? ' disabled' : '') . '"><a class="page-link" href="' . h($link(min($totalPages, $currentPage + 1))) . '" aria-label="Next">&raquo;</a></li>';

    echo '</ul></nav>';
}

/** Query params that identify the active date range, for building links. */
function analytics_range_params(): array
{
    global $customRange, $days;
    return $customRange !== null
        ? ['from' => $customRange['from'], 'to' => $customRange['to']]
        : ['days' => $days];
}

/** "▲ 12.3% vs previous 30 days" style comparison text for a metric tile. */
function analytics_delta_text(float $current, ?float $previous, string $mode = 'pct'): string
{
    global $days;
    $suffix = ' vs previous ' . $days . ' day' . ($days === 1 ? '' : 's');
    if ($previous === null) {
        return 'No prior data';
    }
    if ($mode === 'pts') {
        $diff = ($current - $previous) * 100;
        $text = number_format(abs($diff), 1) . ' pts';
    } elseif ($mode === 'pos') {
        // Average position: lower is better, so a falling number is the "up" move.
        $diff = $previous - $current;
        $text = number_format(abs($diff), 1) . ' places';
    } else {
        if ($previous == 0.0) {
            return $current == 0.0 ? 'No change' . $suffix : 'New' . $suffix;
        }
        $diff = ($current - $previous) / $previous * 100;
        $text = number_format(abs($diff), 1) . '%';
    }
    if (abs($diff) < 0.05) {
        return 'No change' . $suffix;
    }
    return ($diff > 0 ? '▲ ' : '▼ ') . $text . $suffix;
}

/** Read ?{prefix}_sort / ?{prefix}_dir, falling back to the defaults. */
function analytics_sort_state(string $prefix, array $columns, string $defaultSort, string $defaultDir = 'desc'): array
{
    $sort = (string) ($_GET[$prefix . '_sort'] ?? '');
    $dir = (string) ($_GET[$prefix . '_dir'] ?? '');
    if (!isset($columns[$sort])) {
        $sort = $defaultSort;
        $dir = $defaultDir;
    }
    return ['sort' => $sort, 'dir' => $dir === 'asc' ? 'asc' : 'desc'];
}

/** @param array<string, callable(array): (int|float|string)> $columns */
function analytics_sort_rows(array $rows, array $state, array $columns): array
{
    $extract = $columns[$state['sort']];
    $factor = $state['dir'] === 'asc' ? 1 : -1;
    usort($rows, static function (array $a, array $b) use ($extract, $factor): int {
        $x = $extract($a);
        $y = $extract($b);
        return $factor * (is_string($x) || is_string($y) ? strcasecmp((string) $x, (string) $y) : $x <=> $y);
    });
    return $rows;
}

/** A sortable <th>; clicking toggles direction and returns to page 1. */
function analytics_sort_th(string $prefix, string $label, string $key, array $state, string $firstDir = 'desc'): string
{
    $active = $state['sort'] === $key;
    $nextDir = $active ? ($state['dir'] === 'asc' ? 'desc' : 'asc') : $firstDir;
    $params = $_GET;
    unset($params[$prefix . '_page']);
    $params[$prefix . '_sort'] = $key;
    $params[$prefix . '_dir'] = $nextDir;
    $arrow = $active ? ($state['dir'] === 'asc' ? ' ▲' : ' ▼') : '';
    $aria = $active ? ' aria-sort="' . ($state['dir'] === 'asc' ? 'ascending' : 'descending') . '"' : '';
    return '<th' . $aria . '><a class="text-reset text-decoration-none" href="?' . h(http_build_query($params)) . '">' . h($label) . $arrow . '</a></th>';
}

/** Small "CSV" download link for a table, honouring the active range. */
function analytics_export_link(string $table): string
{
    $params = ['table' => $table] + analytics_range_params();
    return '<a class="btn btn-sm btn-outline-secondary" href="developer_analytics_export.php?' . h(http_build_query($params)) . '"><i class="fa-solid fa-download" aria-hidden="true"></i> CSV</a>';
}

/** Strip scheme + host from a full URL, leaving path (and query). */
function analytics_url_path(string $url): string
{
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['host'])) {
        return $url;
    }
    return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

/** Pretty "d M" for the daily-trend date strings from GA (Ymd) or GSC (Y-m-d). */
function analytics_day_label(string $date): string
{
    return date('d M', strtotime($date));
}

/**
 * Queue a Chart.js line chart; emitted (with Chart.js) at the foot of the page.
 *
 * @param list<string> $labels
 * @param list<array{label: string, data: list<int|float>, color: string}> $datasets
 */
function analytics_line_chart(string $id, array $labels, array $datasets): void
{
    $GLOBALS['analytics_charts'][$id] = ['labels' => $labels, 'datasets' => $datasets];
    echo '<div class="mb-3" style="position:relative;height:240px"><canvas id="' . h($id) . '" role="img" aria-label="Daily trend chart"></canvas></div>';
}

if ($view === 'hub') {
    hub_analytics_ensure_schema($pdo);
    $dateSql = 'created_at >= DATE_SUB(NOW(), INTERVAL ' . $days . ' DAY)';

$summary = analytics_fetch_one($pdo, "
    SELECT
        COUNT(*) AS total_events,
        SUM(event_type = 'page_view') AS page_views,
        SUM(event_type = 'click') AS clicks,
        SUM(event_type = 'form_submit') AS form_submits,
        COUNT(DISTINCT NULLIF(session_key, '')) AS sessions,
        COUNT(DISTINCT user_id) AS users,
        AVG(CASE WHEN event_type = 'page_summary' THEN duration_ms END) AS avg_duration_ms,
        AVG(CASE WHEN event_type = 'page_summary' THEN scroll_depth END) AS avg_scroll_depth
    FROM hub_analytics_events
    WHERE {$dateSql}
");

$topPages = analytics_fetch_all($pdo, "
    SELECT
        page_path,
        MAX(page_title) AS page_title,
        SUM(event_type = 'page_view') AS page_views,
        SUM(event_type = 'click') AS clicks,
        SUM(event_type = 'form_submit') AS form_submits,
        COUNT(DISTINCT user_id) AS users,
        COUNT(DISTINCT session_key) AS sessions,
        AVG(CASE WHEN event_type = 'page_summary' THEN duration_ms END) AS avg_duration_ms,
        AVG(CASE WHEN event_type = 'page_summary' THEN scroll_depth END) AS avg_scroll_depth,
        MAX(created_at) AS last_seen
    FROM hub_analytics_events
    WHERE {$dateSql}
    GROUP BY page_path
    ORDER BY page_views DESC, clicks DESC, last_seen DESC
");

$topFeatures = analytics_fetch_all($pdo, "
    SELECT
        COALESCE(NULLIF(feature_label, ''), NULLIF(element_text, ''), element_selector, 'Unnamed action') AS label,
        page_path,
        event_type,
        COUNT(*) AS events,
        COUNT(DISTINCT user_id) AS users,
        MAX(created_at) AS last_used
    FROM hub_analytics_events
    WHERE {$dateSql}
      AND event_type IN ('click', 'form_submit')
    GROUP BY label, page_path, event_type
    ORDER BY events DESC, users DESC, last_used DESC
");

$users = analytics_fetch_all($pdo, "
    SELECT
        COALESCE(NULLIF(user_display_name, ''), CONCAT('User #', user_id), 'Unknown user') AS name,
        user_role,
        COUNT(DISTINCT session_key) AS sessions,
        SUM(event_type = 'page_view') AS page_views,
        SUM(event_type = 'click') AS clicks,
        SUM(event_type = 'form_submit') AS form_submits,
        AVG(CASE WHEN event_type = 'page_summary' THEN duration_ms END) AS avg_duration_ms,
        MAX(created_at) AS last_seen
    FROM hub_analytics_events
    WHERE {$dateSql}
    GROUP BY user_id, name, user_role
    ORDER BY last_seen DESC
");

$journeys = analytics_fetch_all($pdo, "
    SELECT
        session_key,
        COALESCE(NULLIF(MAX(user_display_name), ''), 'Unknown user') AS name,
        COUNT(*) AS events,
        SUM(event_type = 'page_view') AS page_views,
        MIN(created_at) AS started_at,
        MAX(created_at) AS ended_at,
        GROUP_CONCAT(DISTINCT page_path ORDER BY created_at SEPARATOR ' > ') AS pages
    FROM hub_analytics_events
    WHERE {$dateSql}
    GROUP BY session_key
    ORDER BY ended_at DESC
");

$deviceCase = hub_analytics_device_case_sql();
$devices = analytics_fetch_all($pdo, "
    SELECT {$deviceCase} AS device, COUNT(*) AS events, COUNT(DISTINCT session_key) AS sessions
    FROM hub_analytics_events
    WHERE {$dateSql}
    GROUP BY device
    ORDER BY sessions DESC, events DESC
");

$daily = analytics_fetch_all($pdo, "
    SELECT
        DATE(created_at) AS event_day,
        SUM(event_type = 'page_view') AS page_views,
        SUM(event_type = 'click') AS clicks,
        COUNT(DISTINCT user_id) AS users
    FROM hub_analytics_events
    WHERE {$dateSql}
    GROUP BY DATE(created_at)
    ORDER BY event_day DESC
");

$topFeaturesPagination = analytics_paginate_rows($topFeatures, analytics_page_param('features_page'));
$usersPagination = analytics_paginate_rows($users, analytics_page_param('users_page'), 6);
$journeysPagination = analytics_paginate_rows($journeys, analytics_page_param('journeys_page'));

$topFeaturesVisible = $topFeaturesPagination['rows'];
$usersVisible = $usersPagination['rows'];
$journeysVisible = $journeysPagination['rows'];

$maxFeatureEvents = max(1, ...array_map(static fn(array $row): int => (int) $row['events'], $topFeaturesVisible ?: [['events' => 1]]));

// $topPages is already sorted by page_views DESC — top 5 is the head, worst
// 5 is the least-visited among whatever's left (so the two lists never
// overlap unless fewer than 6 pages have any tracked activity at all).
$topFivePages = array_slice($topPages, 0, 5);
$worstFivePages = array_slice(array_reverse(array_slice($topPages, 5)), 0, 5);
$maxPageViews = max(1, ...array_map(static fn(array $row): int => (int) $row['page_views'], $topPages ?: [['page_views' => 1]]));

$dailyChart = array_reverse($daily);

// Days with no recorded activity are missing from the query; add them as zeros so
// the chart's time axis is continuous and the line does not skip over quiet days.
if ($dailyChart !== []) {
    $dailyByDay = [];
    foreach ($dailyChart as $dailyRow) {
        $dailyByDay[(string) $dailyRow['event_day']] = $dailyRow;
    }
    $dailyFilled = [];
    $cursor = strtotime((string) $dailyChart[0]['event_day']);
    $lastDay = strtotime(date('Y-m-d'));
    for ($t = $cursor; $t !== false && $t <= $lastDay; $t = strtotime('+1 day', $t)) {
        $key = date('Y-m-d', $t);
        $dailyFilled[] = $dailyByDay[$key] ?? ['event_day' => $key, 'page_views' => 0, 'clicks' => 0, 'users' => 0];
    }
    $dailyChart = $dailyFilled;
}

// Previous window of the same length, for the comparison chips on the cards.
$prevSummary = analytics_fetch_one($pdo, "
    SELECT
        SUM(event_type = 'page_view') AS page_views,
        SUM(event_type = 'click') AS clicks,
        COUNT(DISTINCT user_id) AS users,
        AVG(CASE WHEN event_type = 'page_summary' THEN duration_ms END) AS avg_duration_ms
    FROM hub_analytics_events
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL " . ($days * 2) . " DAY)
      AND created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)
");

// Busiest times: events by weekday (Mon = 0) and hour of day.
$heatRows = analytics_fetch_all($pdo, "
    SELECT WEEKDAY(created_at) AS wd, HOUR(created_at) AS hr, COUNT(*) AS n
    FROM hub_analytics_events
    WHERE {$dateSql}
    GROUP BY wd, hr
");
$heatValues = array_fill(0, 7, array_fill(0, 24, 0));
foreach ($heatRows as $heatRow) {
    $heatValues[(int) $heatRow['wd']][(int) $heatRow['hr']] = (int) $heatRow['n'];
}
} // end $view === 'hub' data prep
?>

<nav class="nav nav-tabs mb-4" aria-label="Analytics sections">
    <?php foreach (['site' => 'Site Analytics', 'seo' => 'SEO', 'hub' => 'Hub Usage', 'business' => 'Club Business'] as $tabKey => $tabLabel): ?>
        <?php $tabParams = ['view' => $tabKey] + (in_array($tabKey, ['hub', 'business'], true) ? ['days' => $days] : analytics_range_params()); ?>
        <a class="nav-link <?= $view === $tabKey ? 'active' : '' ?>" <?= $view === $tabKey ? 'aria-current="page"' : '' ?> href="?<?= h(http_build_query($tabParams)) ?>"><?= $tabKey === 'business' ? '<span class="d-none d-sm-inline">Club </span>Business' : h($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>

<?php
/** Preset range buttons plus a custom from/to picker, shared by the Google tabs. */
function analytics_render_range_controls(string $view): void
{
    global $days, $customRange;
    $today = date('Y-m-d');
    ?>
    <div class="analytics-range" aria-label="Date range">
        <?php foreach ([7, 30, 90, 365] as $preset): ?>
            <a class="btn <?= $customRange === null && $days === $preset ? 'btn-brand' : 'btn-outline-secondary' ?>" href="?view=<?= h($view) ?>&amp;days=<?= $preset ?>"><?= $preset === 365 ? 'Year' : $preset . ' days' ?></a>
        <?php endforeach; ?>
        <form method="get" class="d-inline-flex align-items-center gap-1 ms-2">
            <input type="hidden" name="view" value="<?= h($view) ?>">
            <input type="date" name="from" class="form-control form-control-sm" aria-label="From date" max="<?= $today ?>" value="<?= h($customRange['from'] ?? '') ?>" required>
            <span class="text-muted">–</span>
            <input type="date" name="to" class="form-control form-control-sm" aria-label="To date" max="<?= $today ?>" value="<?= h($customRange['to'] ?? '') ?>" required>
            <button type="submit" class="btn btn-sm <?= $customRange !== null ? 'btn-brand' : 'btn-outline-secondary' ?>">Apply</button>
        </form>
    </div>
    <?php
}
?>

<?php if (!google_reporting_enabled() && !in_array($view, ['hub', 'business'], true)): ?>
    <div class="alert alert-warning m-3">Google Analytics / Search Console credentials aren't configured yet.</div>
<?php elseif ($view === 'site'): ?>
    <?php
    $gaSummary = ga4_summary($range);
    $gaPrevSummary = ga4_summary(google_reporting_previous_range($range));
    $gaTopPages = ga4_top_pages($range);
    $gaSources = ga4_traffic_sources($range);
    $gaDevices = ga4_devices($range);
    $gaDaily = ga4_daily_trend($range);

    $gaPagesColumns = [
        'page' => static fn(array $r): string => (string) $r['pagePath'],
        'views' => static fn(array $r): float => (float) $r['screenPageViews'],
        'visitors' => static fn(array $r): float => (float) $r['activeUsers'],
        'time' => static fn(array $r): float => (float) $r['averageSessionDuration'],
    ];
    $gaPagesSort = analytics_sort_state('ga_pages', $gaPagesColumns, 'views');
    $gaTopPagesPagination = analytics_paginate_rows(analytics_sort_rows($gaTopPages['rows'], $gaPagesSort, $gaPagesColumns), analytics_page_param('ga_pages_page'));
    $gaDailyPagination = analytics_paginate_rows(array_reverse($gaDaily['rows']), analytics_page_param('ga_daily_page'));

    $gaError = $gaSummary['error'] ?? $gaTopPages['error'] ?? null;
    $s = $gaSummary['rows'][0] ?? [];
    $sp = isset($gaPrevSummary['error']) ? null : ($gaPrevSummary['rows'][0] ?? null);
    $maxGaDaily = max(1, ...array_map(static fn(array $r): int => (int) ($r['screenPageViews'] ?? 0), $gaDaily['rows'] ?: [['screenPageViews' => 1]]));
    $siteBase = google_reporting_site_base();
    ?>
    <div class="analytics-dashboard">
        <div class="analytics-toolbar">
            <div>
                <strong>Site Analytics</strong>
                <div class="text-muted small">Public site visitors, via Google Analytics.<?php if ($customRange !== null): ?> Showing <?= h(date('d M Y', strtotime($customRange['from']))) ?> – <?= h(date('d M Y', strtotime($customRange['to']))) ?>.<?php endif; ?></div>
            </div>
            <?php analytics_render_range_controls('site'); ?>
        </div>

        <?php if ($gaError): ?>
            <div class="alert alert-danger m-3">Google Analytics error: <?= h($gaError) ?></div>
        <?php else: ?>
            <?php
            $prev = static fn(string $metric): ?float => $sp === null ? null : (float) ($sp[$metric] ?? 0);
            $cmpText = ' vs previous ' . $days . ' day' . ($days === 1 ? '' : 's');
$gaLabels = array_map(static fn(array $r): string => ad_day_label((string) $r['date']), $gaDaily['rows']);
$gaViewsSeries = array_map(static fn(array $r): int => (int) $r['screenPageViews'], $gaDaily['rows']);
$gaUsersSeries = array_map(static fn(array $r): int => (int) $r['activeUsers'], $gaDaily['rows']);
$gaViews = (float) ($s['screenPageViews'] ?? 0);
$gaUsers = (float) ($s['activeUsers'] ?? 0);
$gaNew = (float) ($s['newUsers'] ?? 0);
$gaBounce = (float) ($s['bounceRate'] ?? 0);

ad_kpi_row([
    ['label' => 'Page views', 'value' => $gaViews, 'icon' => 'fa-eye', 'accent' => AD_WINE, 'delta' => ad_delta($gaViews, $prev('screenPageViews')), 'compare' => $cmpText, 'spark' => $gaViewsSeries],
    ['label' => 'Visitors', 'value' => $gaUsers, 'icon' => 'fa-users', 'accent' => AD_BLUE, 'delta' => ad_delta($gaUsers, $prev('activeUsers')), 'compare' => $cmpText, 'meta' => analytics_number((int) $gaNew) . ' new', 'spark' => $gaUsersSeries],
    ['label' => 'Sessions', 'value' => (float) ($s['sessions'] ?? 0), 'icon' => 'fa-arrows-turn-right', 'accent' => AD_TEAL, 'delta' => ad_delta((float) ($s['sessions'] ?? 0), $prev('sessions')), 'compare' => $cmpText, 'meta' => ga_seconds((float) ($s['averageSessionDuration'] ?? 0)) . ' avg visit'],
    ['label' => 'Bounce rate', 'value' => $gaBounce * 100, 'format' => 'pct', 'decimals' => 1, 'icon' => 'fa-door-open', 'accent' => AD_GOLD, 'delta' => ad_delta($gaBounce, $prev('bounceRate'), 'pts', false), 'compare' => $cmpText],
]);

$gaEngage = ax_ga($range, [], ['engagementRate', 'sessionsPerUser', 'screenPageViewsPerSession', 'userEngagementDuration', 'engagedSessions']);
$ge = $gaEngage['rows'][0] ?? [];
$gaUsersForTime = max(1.0, $gaUsers);
if ($ge !== []) {
    ad_kpi_row([
        ['label' => 'Engagement rate', 'value' => (float) $ge['engagementRate'] * 100, 'format' => 'pct', 'decimals' => 1, 'icon' => 'fa-hand-pointer', 'accent' => AD_TEAL, 'meta' => analytics_number((int) $ge['engagedSessions']) . ' engaged sessions'],
        ['label' => 'Pages per visit', 'value' => (float) $ge['screenPageViewsPerSession'], 'format' => 'dec', 'decimals' => 1, 'icon' => 'fa-copy', 'accent' => AD_WINE, 'meta' => 'Page views per session'],
        ['label' => 'Visits per visitor', 'value' => (float) $ge['sessionsPerUser'], 'format' => 'dec', 'decimals' => 2, 'icon' => 'fa-rotate', 'accent' => AD_BLUE, 'meta' => 'Sessions per person'],
        ['label' => 'Time engaged', 'value' => (float) $ge['userEngagementDuration'] / $gaUsersForTime, 'format' => 'sec', 'icon' => 'fa-hourglass-half', 'accent' => AD_GOLD, 'meta' => 'Average per visitor'],
    ]);
}

echo '<div class="analytics-grid">';

ad_panel_open('Traffic over time', 'Page views and visitors for each day. Click a key to show or hide it.', 12);
if ($gaDaily['rows'] === []) {
    ad_empty('No trend data yet.');
} else {
    ad_widget('trend', [
        'title' => 'Site traffic by day',
        'labels' => $gaLabels,
        'active' => ['views', 'visitors'],
        'metrics' => [
            ['key' => 'views', 'label' => 'Page views', 'color' => AD_WINE, 'data' => $gaViewsSeries, 'fmt' => 'int', 'agg' => 'sum'],
            ['key' => 'visitors', 'label' => 'Visitors', 'color' => AD_BLUE, 'data' => $gaUsersSeries, 'fmt' => 'int', 'agg' => 'sum'],
        ],
    ]);
}
ad_panel_close();

ad_panel_open('Top pages', 'Most viewed public pages. Hover a row for visitors and time on page.', 7);
if ($gaTopPages['rows'] === []) {
    ad_empty('No page data yet.');
} else {
    ad_widget('rank', [
        'valueLabel' => 'views', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 8, 'total' => $gaViews,
        'items' => array_map(static fn(array $r): array => [
            'label' => (string) $r['pagePath'],
            'value' => (float) $r['screenPageViews'],
            'tip' => [['visitors', analytics_number((int) $r['activeUsers'])], ['avg time', ga_seconds((float) $r['averageSessionDuration'])]],
        ], array_slice(ad_sort_desc(array_values(array_reduce($gaTopPages['rows'], static function (array $carry, array $r): array {
            $path = (string) $r['pagePath'];
            if (!isset($carry[$path])) {
                $carry[$path] = $r;
            } else {
                $carry[$path]['screenPageViews'] = (float) $carry[$path]['screenPageViews'] + (float) $r['screenPageViews'];
                $carry[$path]['activeUsers'] = (float) $carry[$path]['activeUsers'] + (float) $r['activeUsers'];
            }
            return $carry;
        }, [])), 'screenPageViews'), 0, 20)),
    ]);
}
ad_panel_close();

ad_panel_open('Audience', 'How engaged and how new your visitors are.', 5);
ad_widget('ring', ['value' => round((1 - $gaBounce) * 100, 1), 'decimals' => 1, 'label' => 'Engaged sessions', 'sub' => 'The rest bounced after one page (' . ga_percent($gaBounce) . ').', 'color' => AD_TEAL], 'ad-ring');
echo '<div style="height:1.1rem"></div>';
ad_widget('ring', ['value' => $gaUsers > 0 ? round($gaNew / $gaUsers * 100, 1) : 0, 'decimals' => 1, 'label' => 'New visitors', 'sub' => analytics_number((int) $gaNew) . ' new, ' . analytics_number((int) max(0, $gaUsers - $gaNew)) . ' returning.', 'color' => AD_BLUE], 'ad-ring');
ad_callout(ga_seconds((float) ($s['averageSessionDuration'] ?? 0)), 'average visit length, across ' . analytics_number((int) ($s['sessions'] ?? 0)) . ' sessions');
ad_panel_close();

ad_panel_open('Where visitors come from', 'Sessions by channel.', 6);
if ($gaSources['rows'] === []) {
    ad_empty('No source data yet.');
} else {
    ad_widget('rank', [
        'valueLabel' => 'sessions', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 7,
        'items' => array_map(static fn(array $r): array => [
            'label' => (string) $r['sessionDefaultChannelGroup'],
            'value' => (float) $r['sessions'],
            'tip' => [['visitors', analytics_number((int) $r['activeUsers'])]],
        ], $gaSources['rows']),
    ]);
}
ad_panel_close();

ad_panel_open('Devices', 'Share of sessions by device type.', 6);
if ($gaDevices['rows'] === []) {
    ad_empty('No device data yet.');
} else {
    $deviceColors = ['mobile' => AD_WINE, 'desktop' => AD_BLUE, 'tablet' => AD_GOLD];
    ad_widget('split', [
        'valueLabel' => 'sessions', 'fmt' => 'int',
        'parts' => array_map(static fn(array $r): array => [
            'label' => ucfirst((string) $r['deviceCategory']),
            'value' => (float) $r['sessions'],
            'color' => $deviceColors[strtolower((string) $r['deviceCategory'])] ?? AD_TEAL,
            'tip' => [['visitors', analytics_number((int) $r['activeUsers'])]],
        ], $gaDevices['rows']),
    ]);
    $topDevice = $gaDevices['rows'][0];
    $deviceTotal = array_sum(array_map(static fn(array $r): float => (float) $r['sessions'], $gaDevices['rows']));
    if ($deviceTotal > 0) {
        ad_callout(number_format((float) $topDevice['sessions'] / $deviceTotal * 100, 0) . '%', 'of sessions are on ' . strtolower((string) $topDevice['deviceCategory']));
    }
}
ad_panel_close();

// ---------------------------------------------------------------------
// More Google Analytics breakdowns
// ---------------------------------------------------------------------
$gaHeat = ax_weekday_hour($range);
$gaLanding = ax_ga($range, ['landingPagePlusQueryString'], ['sessions', 'bounceRate', 'engagedSessions'], 20);
$gaNewRet = ax_ga($range, ['newVsReturning'], ['activeUsers', 'sessions'], 5);
$gaCountries = ax_ga($range, ['country'], ['activeUsers', 'sessions'], 12);
$gaCities = ax_ga($range, ['city'], ['activeUsers', 'sessions'], 14);
$gaSourceMedium = ax_ga($range, ['sessionSourceMedium'], ['sessions', 'engagedSessions'], 20);
$gaBrowsers = ax_ga($range, ['browser'], ['sessions'], 8);
$gaSystems = ax_ga($range, ['operatingSystem'], ['sessions'], 8);
$gaEvents = ax_ga($range, ['eventName'], ['eventCount', 'totalUsers'], 25);

ad_panel_open('Match-day pattern', 'Sessions by day of the week and hour. Darker means busier.', 12);
if (!empty($gaHeat['error'])) {
    ad_empty('Could not load this: ' . $gaHeat['error']);
} else {
    ad_widget('heat', ['title' => 'Site sessions by weekday and hour', 'valueLabel' => 'sessions', 'rows' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], 'values' => $gaHeat['values']]);
    if ($gaHeat['peakDay'] !== '') {
        ad_callout($gaHeat['peakDay'], 'is the busiest day, peaking around ' . str_pad((string) $gaHeat['peakHour'], 2, '0', STR_PAD_LEFT) . ':00');
    }
}
ad_panel_close();

ad_panel_open('Landing pages', 'Where visits begin. Hover a row for bounce rate and engaged sessions.', 7);
if (!empty($gaLanding['error']) || $gaLanding['rows'] === []) {
    ad_empty(!empty($gaLanding['error']) ? 'Could not load this: ' . $gaLanding['error'] : 'No landing page data yet.');
} else {
    ad_widget('rank', [
        'valueLabel' => 'sessions', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 8,
        'items' => array_map(static fn(array $r): array => [
            'label' => (string) ($r['landingPagePlusQueryString'] ?: '/'),
            'sub' => 'Bounce rate ' . ga_percent((float) $r['bounceRate']),
            'value' => (float) $r['sessions'],
            'tip' => [['bounce rate', ga_percent((float) $r['bounceRate'])], ['engaged sessions', analytics_number((int) $r['engagedSessions'])]],
        ], $gaLanding['rows']),
    ]);
}
ad_panel_close();

ad_panel_open('New and returning', 'Visitors who had not been before, against those who had.', 5);
$nrItems = [];
foreach ($gaNewRet['rows'] as $r) {
    $label = (string) $r['newVsReturning'];
    if (in_array($label, ['new', 'returning'], true) && (float) $r['activeUsers'] > 0) {
        $nrItems[] = ['label' => ucfirst($label), 'value' => (float) $r['activeUsers'], 'tip' => [['sessions', analytics_number((int) $r['sessions'])]]];
    }
}
if ($nrItems === []) {
    ad_empty('No visitor data yet.');
} else {
    ad_widget('split', ['valueLabel' => 'visitors', 'fmt' => 'int', 'parts' => array_map(static function (array $it, int $i): array {
        $it['color'] = $i === 0 ? AD_BLUE : AD_WINE;
        return $it;
    }, $nrItems, array_keys($nrItems))]);
    $nrTotal = array_sum(array_column($nrItems, 'value'));
    foreach ($nrItems as $it) {
        if ($it['label'] === 'Returning' && $nrTotal > 0) {
            ad_callout(number_format($it['value'] / $nrTotal * 100, 0) . '%', 'of visitors came back after an earlier visit');
        }
    }
}
ad_panel_close();

ad_panel_open('Countries', 'Visitors by country.', 5);
$countryRows = array_values(array_filter($gaCountries['rows'], static fn(array $r): bool => (string) $r['country'] !== '(not set)'));
if ($countryRows === []) {
    ad_empty('No location data yet.');
} else {
    ad_widget('rank', [
        'valueLabel' => 'visitors', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 6,
        'items' => array_map(static fn(array $r): array => ['label' => (string) $r['country'], 'value' => (float) $r['activeUsers'], 'tip' => [['sessions', analytics_number((int) $r['sessions'])]]], $countryRows),
    ]);
}
ad_panel_close();

ad_panel_open('Cities', 'Where in the world your visitors are.', 7);
$cityRows = array_values(array_filter($gaCities['rows'], static fn(array $r): bool => (string) $r['city'] !== '(not set)'));
if ($cityRows === []) {
    ad_empty('No location data yet.');
} else {
    ad_widget('rank', [
        'valueLabel' => 'visitors', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 8,
        'items' => array_map(static fn(array $r): array => ['label' => (string) $r['city'], 'value' => (float) $r['activeUsers'], 'tip' => [['sessions', analytics_number((int) $r['sessions'])]]], $cityRows),
    ]);
}
ad_panel_close();

ad_panel_open('Referrals and social', 'The exact websites and apps sending visitors, including Facebook.', 7);
if ($gaSourceMedium['rows'] === []) {
    ad_empty('No source data yet.');
} else {
    $smTotal = array_sum(array_map(static fn(array $r): float => (float) $r['sessions'], $gaSourceMedium['rows']));
    $fbSessions = array_sum(array_map(static fn(array $r): float => stripos((string) $r['sessionSourceMedium'], 'facebook') !== false ? (float) $r['sessions'] : 0.0, $gaSourceMedium['rows']));
    ad_widget('rank', [
        'valueLabel' => 'sessions', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 8, 'total' => $smTotal,
        'items' => array_map(static fn(array $r): array => [
            'label' => str_replace(' / ', ' · ', (string) $r['sessionSourceMedium']),
            'value' => (float) $r['sessions'],
            'tip' => [['engaged sessions', analytics_number((int) $r['engagedSessions'])]],
        ], $gaSourceMedium['rows']),
    ]);
    if ($smTotal > 0 && $fbSessions > 0) {
        ad_callout(number_format($fbSessions / $smTotal * 100, 0) . '%', 'of sessions came from Facebook (including its in-app browser)');
    }
}
ad_panel_close();

ad_panel_open('Browsers and phones', 'What people use to open the site.', 5);
foreach ([['Browser', $gaBrowsers, 'browser'], ['System', $gaSystems, 'operatingSystem']] as [$title, $res, $dimKey]) {
    if ($res['rows'] === []) {
        continue;
    }
    echo '<p class="ad-mini-title">' . ad_e($title) . '</p>';
    ad_widget('split', ['valueLabel' => 'sessions', 'fmt' => 'int', 'parts' => ax_split_parts(array_map(static fn(array $r): array => ['label' => (string) $r[$dimKey], 'value' => (float) $r['sessions']], $res['rows']), 4)]);
}
ad_panel_close();

ad_panel_open('What visitors do', 'Actions recorded by Google Analytics, other than simply viewing a page.', 7);
$eventItems = [];
foreach ($gaEvents['rows'] as $r) {
    $label = ax_event_label((string) $r['eventName']);
    if ($label !== null) {
        $eventItems[] = ['label' => $label, 'value' => (float) $r['eventCount'], 'tip' => [['people', analytics_number((int) $r['totalUsers'])], ['event', (string) $r['eventName']]]];
    }
}
if ($eventItems === []) {
    ad_empty('No visitor actions recorded yet.');
} else {
    ad_widget('rank', ['valueLabel' => 'times', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 6, 'showShare' => false, 'items' => $eventItems]);
}
ad_panel_close();

// ---------------------------------------------------------------------
// Conversions and page speed
// ---------------------------------------------------------------------
$convRange = google_reporting_date_range($range);
$convStmt = $pdo->prepare("SELECT COUNT(*) FROM shop_orders WHERE status IN ('paid','collected','refunded') AND paid_at >= :from AND paid_at < DATE_ADD(:to, INTERVAL 1 DAY)");
$convStmt->execute([':from' => $convRange['startDate'], ':to' => $convRange['endDate']]);
$shopPaidOrders = (int) $convStmt->fetchColumn();
$shopSetting = $pdo->query("SELECT setting_value FROM shop_settings WHERE setting_key = 'shop_enabled'")->fetchColumn();
$shopOpen = $shopSetting === false || (string) $shopSetting === '1'; // no row means the default, which is open
$gaSessions = (float) ($s['sessions'] ?? 0);
$formStart = 0;
$formDone = 0;
foreach ($gaEvents['rows'] as $evRow) {
    if ($evRow['eventName'] === 'form_start') {
        $formStart = (int) $evRow['totalUsers'];
    } elseif ($evRow['eventName'] === 'form_submit') {
        $formDone = (int) $evRow['totalUsers'];
    }
}

ad_panel_open('Conversions', 'How many visits turn into something: an order, or a finished form.', 5);
ad_widget('ring', [
    'value' => $gaSessions > 0 ? round($shopPaidOrders / $gaSessions * 100, 2) : 0, 'decimals' => 1, 'label' => 'Shop conversion',
    'sub' => $shopPaidOrders . ' paid order' . ($shopPaidOrders === 1 ? '' : 's') . ' from ' . analytics_number((int) $gaSessions) . ' visits' . ($shopOpen ? '.' : ' (the shop is currently closed).'),
    'color' => AD_WINE,
], 'ad-ring');
echo '<div style="height:1.1rem"></div>';
ad_widget('ring', [
    'value' => $formStart > 0 ? min(100, round($formDone / $formStart * 100, 1)) : 0, 'decimals' => 0, 'label' => 'Form completion',
    'sub' => $formStart > 0 ? $formDone . ' of the ' . $formStart . ' people who started a form sent one.' : 'No forms were started in this period.',
    'color' => AD_TEAL,
], 'ad-ring');
ad_panel_close();

$speed = ax_speed_view();
ad_panel_open('Page speed', 'Measured by loading each page in a real browser every day. Phones are tested on a slow 4G connection with a slower processor, like a typical visitor.', 12);
if ($speed['latest'] === null) {
    ad_empty('No speed checks have run yet. The first one runs overnight.');
} else {
    ad_widget('speed', $speed['cfg']);
    $homeMobile = $speed['cfg']['strategies']['mobile'][0] ?? null;
    if ($homeMobile !== null && isset($homeMobile['metrics']['lcp'])) {
        ad_callout(number_format($homeMobile['metrics']['lcp'] / 1000, 1) . 's', 'for the home page\'s main content to appear on a phone (good is under 2.5s)');
    }
    if ($homeMobile !== null && !empty($homeMobile['metrics']['bytes'])) {
        ad_callout(number_format($homeMobile['metrics']['bytes'] / 1048576, 1) . ' MB', 'downloaded to show the home page. Large images are the usual cause.');
    }
    if (count($speed['trend']['labels']) >= 2) {
        echo '<p class="ad-mini-title" style="margin-top:1.4rem">Home page on a phone, over time</p>';
        ad_widget('trend', [
            'title' => 'Home page load times on a phone',
            'labels' => $speed['trend']['labels'],
            'active' => ['lcp', 'fcp'],
            'metrics' => [
                ['key' => 'lcp', 'label' => 'Main content', 'color' => AD_WINE, 'data' => $speed['trend']['series']['lcp'], 'fmt' => 'secd', 'decimals' => 1, 'agg' => 'avg'],
                ['key' => 'fcp', 'label' => 'First paint', 'color' => AD_BLUE, 'data' => $speed['trend']['series']['fcp'], 'fmt' => 'secd', 'decimals' => 1, 'agg' => 'avg'],
                ['key' => 'ttfb', 'label' => 'Server reply', 'color' => AD_TEAL, 'data' => $speed['trend']['series']['ttfb'], 'fmt' => 'secd', 'decimals' => 2, 'agg' => 'avg'],
            ],
        ]);
    } else {
        echo '<p class="analytics-empty" style="margin-top:1rem">A trend chart appears here after a few daily checks.</p>';
    }
}
ad_panel_close();

echo '</div>';
ad_tables_open_tag('Sortable lists, pagination and CSV export');
            ?>

            <div class="analytics-grid">
                <section class="analytics-panel analytics-panel--wide">
                    <div class="analytics-panel__header">
                        <div>
                            <h2 class="analytics-panel__title">Top Pages</h2>
                            <p class="analytics-panel__meta">Most visited public pages. Click a heading to sort.</p>
                        </div>
                        <?php if ($gaTopPages['rows'] !== []): ?><?= analytics_export_link('ga_pages') ?><?php endif; ?>
                    </div>
                    <?php if ($gaTopPages['rows'] === []): ?>
                        <p class="analytics-empty">No data for this date range yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table analytics-table align-middle hub-data-table">
                                <thead><tr>
                                    <?= analytics_sort_th('ga_pages', 'Page', 'page', $gaPagesSort, 'asc') ?>
                                    <?= analytics_sort_th('ga_pages', 'Views', 'views', $gaPagesSort) ?>
                                    <?= analytics_sort_th('ga_pages', 'Visitors', 'visitors', $gaPagesSort) ?>
                                    <?= analytics_sort_th('ga_pages', 'Avg time', 'time', $gaPagesSort) ?>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($gaTopPagesPagination['rows'] as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= h((string) $row['pageTitle']) ?></div>
                                            <div class="small"><a class="text-muted" href="<?= h($siteBase . (string) $row['pagePath']) ?>" target="_blank" rel="noopener" title="<?= h($siteBase . (string) $row['pagePath']) ?>"><?= h((string) $row['pagePath']) ?></a></div>
                                        </td>
                                        <td><?= analytics_number((int) $row['screenPageViews']) ?></td>
                                        <td><?= analytics_number((int) $row['activeUsers']) ?></td>
                                        <td><?= ga_seconds((float) $row['averageSessionDuration']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php analytics_render_pagination('ga_pages_page', $gaTopPagesPagination, $days); ?>
                    <?php endif; ?>
                </section>

                <section class="analytics-panel analytics-panel--side">
                    <div class="analytics-panel__header">
                        <div>
                            <h2 class="analytics-panel__title">Traffic Sources</h2>
                            <p class="analytics-panel__meta">Where visitors come from.</p>
                        </div>
                        <?php if ($gaSources['rows'] !== []): ?><?= analytics_export_link('ga_sources') ?><?php endif; ?>
                    </div>
                    <?php if ($gaSources['rows'] === []): ?>
                        <p class="analytics-empty">No data yet.</p>
                    <?php else: ?>
                        <table class="table analytics-table align-middle hub-data-table">
                            <thead><tr><th>Source</th><th>Sessions</th></tr></thead>
                            <tbody>
                            <?php foreach ($gaSources['rows'] as $row): ?>
                                <tr><td><?= h((string) $row['sessionDefaultChannelGroup']) ?></td><td><?= analytics_number((int) $row['sessions']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

                <section class="analytics-panel analytics-panel--side">
                    <div class="analytics-panel__header">
                        <div>
                            <h2 class="analytics-panel__title">Devices</h2>
                            <p class="analytics-panel__meta">Mobile vs desktop split.</p>
                        </div>
                        <?php if ($gaDevices['rows'] !== []): ?><?= analytics_export_link('ga_devices') ?><?php endif; ?>
                    </div>
                    <?php if ($gaDevices['rows'] === []): ?>
                        <p class="analytics-empty">No data yet.</p>
                    <?php else: ?>
                        <table class="table analytics-table align-middle hub-data-table">
                            <thead><tr><th>Device</th><th>Sessions</th></tr></thead>
                            <tbody>
                            <?php foreach ($gaDevices['rows'] as $row): ?>
                                <tr><td><?= h(ucfirst((string) $row['deviceCategory'])) ?></td><td><?= analytics_number((int) $row['sessions']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

                <section class="analytics-panel analytics-panel--wide">
                    <div class="analytics-panel__header">
                        <div>
                            <h2 class="analytics-panel__title">Daily Trend</h2>
                            <p class="analytics-panel__meta">Page views and visitors by day.</p>
                        </div>
                        <?php if ($gaDaily['rows'] !== []): ?><?= analytics_export_link('ga_daily') ?><?php endif; ?>
                    </div>
                    <?php if ($gaDaily['rows'] === []): ?>
                        <p class="analytics-empty">No trend data yet.</p>
                    <?php else: ?>
                        <table class="table analytics-table align-middle hub-data-table">
                            <thead><tr><th>Day</th><th>Views</th><th>Visitors</th></tr></thead>
                            <tbody>
                            <?php foreach ($gaDailyPagination['rows'] as $row): ?>
                                <tr>
                                    <td><?= h(analytics_day_label((string) $row['date'])) ?></td>
                                    <td>
                                        <div class="analytics-bar">
                                            <span><?= analytics_number((int) $row['screenPageViews']) ?></span>
                                            <span class="analytics-bar__track"><span class="analytics-bar__fill" style="width: <?= min(100, ((int) $row['screenPageViews'] / $maxGaDaily) * 100) ?>%"></span></span>
                                        </div>
                                    </td>
                                    <td><?= analytics_number((int) $row['activeUsers']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php analytics_render_pagination('ga_daily_page', $gaDailyPagination, $days); ?>
                    <?php endif; ?>
                </section>
            </div>
            <?php ad_tables_close_tag(); ?>
        <?php endif; ?>
    </div>
<?php elseif ($view === 'seo'): ?>
    <?php
    $scSummary = gsc_summary($range);
    $scPrevSummary = gsc_summary(google_reporting_previous_range($range, GSC_LAG_DAYS));
    $scQueries = gsc_top_queries($range);
    $scPages = gsc_top_pages($range);
    $scDaily = gsc_daily_trend($range);

    $scQueryColumns = [
        'query' => static fn(array $r): string => (string) ($r['keys'][0] ?? ''),
        'clicks' => static fn(array $r): float => (float) $r['clicks'],
        'impressions' => static fn(array $r): float => (float) $r['impressions'],
        'ctr' => static fn(array $r): float => (float) $r['ctr'],
        'position' => static fn(array $r): float => (float) $r['position'],
    ];
    $scQuerySort = analytics_sort_state('sc_queries', $scQueryColumns, 'clicks');
    $scQueriesPagination = analytics_paginate_rows(analytics_sort_rows($scQueries['rows'], $scQuerySort, $scQueryColumns), analytics_page_param('sc_queries_page'));

    $scPageColumns = [
        'page' => static fn(array $r): string => analytics_url_path((string) ($r['keys'][0] ?? '')),
        'clicks' => static fn(array $r): float => (float) $r['clicks'],
    ];
    $scPageSort = analytics_sort_state('sc_pages', $scPageColumns, 'clicks');
    $scPagesPagination = analytics_paginate_rows(analytics_sort_rows($scPages['rows'], $scPageSort, $scPageColumns), analytics_page_param('sc_pages_page'));
    $scDailyPagination = analytics_paginate_rows(array_reverse($scDaily['rows']), analytics_page_param('sc_daily_page'));

    $scError = $scSummary['error'] ?? $scQueries['error'] ?? null;
    $sRow = $scSummary['rows'][0] ?? [];
    $sPrev = isset($scPrevSummary['error']) ? null : ($scPrevSummary['rows'][0] ?? null);
    $maxScDaily = max(1, ...array_map(static fn(array $r): float => (float) ($r['clicks'] ?? 0), $scDaily['rows'] ?: [['clicks' => 1]]));
    ?>
    <div class="analytics-dashboard">
        <div class="analytics-toolbar">
            <div>
                <strong>SEO</strong>
                <div class="text-muted small">Search performance, via Google Search Console. Data lags by about 2 days.<?php if ($customRange !== null): ?> Showing <?= h(date('d M Y', strtotime($customRange['from']))) ?> – <?= h(date('d M Y', strtotime($customRange['to']))) ?>.<?php endif; ?></div>
            </div>
            <?php analytics_render_range_controls('seo'); ?>
        </div>

        <?php if ($scError): ?>
            <div class="alert alert-danger m-3">Search Console error: <?= h($scError) ?></div>
        <?php else: ?>
            <?php
            $sc = static fn(string $metric): ?float => $sPrev === null ? null : (float) ($sPrev[$metric] ?? 0);
            $cmpText = ' vs previous ' . $days . ' day' . ($days === 1 ? '' : 's');
$scRows = $scDaily['rows'];
$scLabels = array_map(static fn(array $r): string => ad_day_label((string) ($r['keys'][0] ?? '')), $scRows);
$scClicksSeries = array_map(static fn(array $r): int => (int) $r['clicks'], $scRows);
$scImprSeries = array_map(static fn(array $r): int => (int) $r['impressions'], $scRows);
$scCtrSeries = array_map(static fn(array $r): float => round((float) $r['ctr'] * 100, 2), $scRows);
$scPosSeries = array_map(static fn(array $r): float => round((float) $r['position'], 2), $scRows);
$scClicks = (float) ($sRow['clicks'] ?? 0);
$scImpr = (float) ($sRow['impressions'] ?? 0);
$scCtr = (float) ($sRow['ctr'] ?? 0);
$scPos = (float) ($sRow['position'] ?? 0);

ad_kpi_row([
    ['label' => 'Search clicks', 'value' => $scClicks, 'icon' => 'fa-arrow-pointer', 'accent' => AD_WINE, 'delta' => ad_delta($scClicks, $sc('clicks')), 'compare' => $cmpText, 'spark' => $scClicksSeries],
    ['label' => 'Impressions', 'value' => $scImpr, 'icon' => 'fa-eye', 'accent' => AD_BLUE, 'delta' => ad_delta($scImpr, $sc('impressions')), 'compare' => $cmpText, 'spark' => $scImprSeries],
    ['label' => 'Click-through rate', 'value' => $scCtr * 100, 'format' => 'pct', 'decimals' => 1, 'icon' => 'fa-percent', 'accent' => AD_TEAL, 'delta' => ad_delta($scCtr, $sc('ctr'), 'pts'), 'compare' => $cmpText, 'spark' => $scCtrSeries],
    ['label' => 'Average position', 'value' => $scPos, 'format' => 'dec', 'decimals' => 1, 'icon' => 'fa-ranking-star', 'accent' => AD_GOLD, 'delta' => ad_delta($scPos, $sc('position'), 'pos'), 'compare' => $cmpText, 'meta' => 'Lower is better', 'spark' => $scPosSeries],
]);

// Queries grouped by where they rank, for the position-band split.
$scQueryRows = $scQueries['rows'];
$bandDefs = [['1–3', 0, 3.5, '#4b0818'], ['4–10', 3.5, 10.5, '#8a2d49'], ['11–20', 10.5, 20.5, '#bf6a85'], ['21+', 20.5, 9999, '#d79fb2']];
$bandParts = [];
foreach ($bandDefs as [$bandLabel, $lo, $hi, $bandColor]) {
    $inBand = array_filter($scQueryRows, static fn(array $r): bool => (float) $r['position'] >= $lo && (float) $r['position'] < $hi);
    $bandParts[] = [
        'label' => 'Position ' . $bandLabel,
        'value' => (float) array_sum(array_map(static fn(array $r): float => (float) $r['clicks'], $inBand)),
        'color' => $bandColor,
        'tip' => [['queries', (string) count($inBand)], ['impressions', analytics_number((int) array_sum(array_map(static fn(array $r): float => (float) $r['impressions'], $inBand)))]],
    ];
}
// High-impression queries that rank just outside the top three.
$nearMisses = array_values(array_filter($scQueryRows, static fn(array $r): bool => (float) $r['position'] > 3.5 && (float) $r['position'] <= 20 && (float) $r['impressions'] >= 5));
usort($nearMisses, static fn(array $a, array $b): int => (float) $b['impressions'] <=> (float) $a['impressions']);
$nearMisses = array_slice($nearMisses, 0, 6);

echo '<div class="analytics-grid">';

ad_panel_open('Search performance over time', 'Pick one measure at a time so each keeps its own scale.', 12);
if ($scRows === []) {
    ad_empty('No trend data yet.');
} else {
    ad_widget('trend', [
        'title' => 'Search performance by day',
        'exclusive' => true,
        'labels' => $scLabels,
        'active' => ['clicks'],
        'metrics' => [
            ['key' => 'clicks', 'label' => 'Clicks', 'color' => AD_WINE, 'data' => $scClicksSeries, 'fmt' => 'int', 'agg' => 'sum'],
            ['key' => 'impressions', 'label' => 'Impressions', 'color' => AD_BLUE, 'data' => $scImprSeries, 'fmt' => 'int', 'agg' => 'sum'],
            ['key' => 'ctr', 'label' => 'Click-through rate', 'color' => AD_TEAL, 'data' => $scCtrSeries, 'fmt' => 'pct', 'decimals' => 1, 'agg' => 'avg'],
            ['key' => 'position', 'label' => 'Average position', 'color' => AD_GOLD, 'data' => $scPosSeries, 'fmt' => 'dec', 'decimals' => 1, 'agg' => 'avg', 'reverse' => true],
        ],
    ]);
}
ad_panel_close();

ad_panel_open('Top search queries', 'What people typed to find you, by clicks. Hover for impressions, rate and position.', 7);
if ($scQueryRows === []) {
    ad_empty('No query data yet.');
} else {
    ad_widget('rank', [
        'valueLabel' => 'clicks', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 8, 'total' => $scClicks,
        'items' => array_map(static fn(array $r): array => [
            'label' => (string) ($r['keys'][0] ?? ''),
            'value' => (float) $r['clicks'],
            'tip' => [['impressions', analytics_number((int) $r['impressions'])], ['click-through', ga_percent((float) $r['ctr'])], ['avg position', number_format((float) $r['position'], 1)]],
        ], array_slice($scQueryRows, 0, 20)),
    ]);
}
ad_panel_close();

ad_panel_open('Where clicks come from', 'Clicks grouped by where the query ranks in Google.', 5);
ad_widget('split', ['valueLabel' => 'clicks', 'fmt' => 'int', 'parts' => $bandParts]);
$bandTotal = array_sum(array_column($bandParts, 'value'));
if ($bandTotal > 0) {
    ad_callout(number_format((float) $bandParts[0]['value'] / $bandTotal * 100, 0) . '%', 'of search clicks come from queries ranking in the top three');
}
ad_panel_close();

ad_panel_open('Top pages in search', 'Pages that earn the most search clicks.', 6);
if ($scPages['rows'] === []) {
    ad_empty('No page data yet.');
} else {
    ad_widget('rank', [
        'valueLabel' => 'clicks', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 7, 'total' => $scClicks,
        'items' => array_map(static fn(array $r): array => [
            'label' => analytics_url_path((string) ($r['keys'][0] ?? '')) ?: '/',
            'value' => (float) $r['clicks'],
            'tip' => [['impressions', analytics_number((int) $r['impressions'])], ['avg position', number_format((float) $r['position'], 1)]],
        ], array_slice($scPages['rows'], 0, 20)),
    ]);
}
ad_panel_close();

ad_panel_open('Near misses', 'Queries with plenty of impressions that rank just outside the top three.', 6);
if ($nearMisses === []) {
    ad_empty('Nothing to flag right now.');
} else {
    echo '<ul class="ad-opps">';
    foreach ($nearMisses as $r) {
        echo '<li class="ad-opp"><span class="ad-opp__q" title="' . ad_e((string) ($r['keys'][0] ?? '')) . '">' . ad_e((string) ($r['keys'][0] ?? '')) . '</span>';
        echo '<span class="ad-opp__n">' . analytics_number((int) $r['impressions']) . ' impressions</span>';
        echo '<span class="ad-opp__meta">Position ' . number_format((float) $r['position'], 1) . ' · ' . ga_percent((float) $r['ctr']) . ' click-through · ' . analytics_number((int) $r['clicks']) . ((int) $r['clicks'] === 1 ? ' click' : ' clicks') . '</span></li>';
    }
    echo '</ul>';
}
ad_panel_close();

$scDevices = ax_gsc($range, ['device'], 5);
$scCountries = ax_gsc($range, ['country'], 10);

ad_panel_open('Search devices', 'Which devices people use when they find you in Google.', 5);
if (!empty($scDevices['error']) || $scDevices['rows'] === []) {
    ad_empty(!empty($scDevices['error']) ? 'Could not load this: ' . $scDevices['error'] : 'No device data yet.');
} else {
    $devColors = ['MOBILE' => AD_WINE, 'DESKTOP' => AD_BLUE, 'TABLET' => AD_GOLD];
    ad_widget('split', [
        'valueLabel' => 'clicks', 'fmt' => 'int',
        'parts' => array_map(static fn(array $r): array => [
            'label' => ucfirst(strtolower((string) ($r['keys'][0] ?? ''))),
            'value' => (float) $r['clicks'],
            'color' => $devColors[(string) ($r['keys'][0] ?? '')] ?? AD_TEAL,
            'tip' => [['impressions', analytics_number((int) $r['impressions'])], ['click-through', ga_percent((float) $r['ctr'])], ['avg position', number_format((float) $r['position'], 1)]],
        ], $scDevices['rows']),
    ]);
}
ad_panel_close();

ad_panel_open('Search countries', 'Where searchers are, by clicks.', 7);
if (!empty($scCountries['error']) || $scCountries['rows'] === []) {
    ad_empty(!empty($scCountries['error']) ? 'Could not load this: ' . $scCountries['error'] : 'No country data yet.');
} else {
    ad_widget('rank', [
        'valueLabel' => 'clicks', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 6,
        'items' => array_map(static fn(array $r): array => [
            'label' => ax_country_name((string) ($r['keys'][0] ?? '')),
            'value' => (float) $r['clicks'],
            'tip' => [['impressions', analytics_number((int) $r['impressions'])], ['avg position', number_format((float) $r['position'], 1)]],
        ], $scCountries['rows']),
    ]);
}
ad_panel_close();

echo '</div>';
ad_tables_open_tag('Sortable lists, pagination and CSV export');
            ?>

            <div class="analytics-grid">
                <section class="analytics-panel analytics-panel--wide">
                    <div class="analytics-panel__header">
                        <div>
                            <h2 class="analytics-panel__title">Top Search Queries</h2>
                            <p class="analytics-panel__meta">What people search to find this site. Click a heading to sort.</p>
                        </div>
                        <?php if ($scQueries['rows'] !== []): ?><?= analytics_export_link('sc_queries') ?><?php endif; ?>
                    </div>
                    <?php if ($scQueries['rows'] === []): ?>
                        <p class="analytics-empty">No search query data yet — this can take a few days after verifying with Google.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table analytics-table align-middle hub-data-table">
                                <thead><tr>
                                    <?= analytics_sort_th('sc_queries', 'Query', 'query', $scQuerySort, 'asc') ?>
                                    <?= analytics_sort_th('sc_queries', 'Clicks', 'clicks', $scQuerySort) ?>
                                    <?= analytics_sort_th('sc_queries', 'Impressions', 'impressions', $scQuerySort) ?>
                                    <?= analytics_sort_th('sc_queries', 'CTR', 'ctr', $scQuerySort) ?>
                                    <?= analytics_sort_th('sc_queries', 'Position', 'position', $scQuerySort, 'asc') ?>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($scQueriesPagination['rows'] as $row): ?>
                                    <tr>
                                        <td><?= h((string) ($row['keys'][0] ?? '')) ?></td>
                                        <td><?= analytics_number((float) $row['clicks']) ?></td>
                                        <td><?= analytics_number((float) $row['impressions']) ?></td>
                                        <td><?= ga_percent((float) $row['ctr']) ?></td>
                                        <td><?= number_format((float) $row['position'], 1) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php analytics_render_pagination('sc_queries_page', $scQueriesPagination, $days); ?>
                    <?php endif; ?>
                </section>

                <section class="analytics-panel analytics-panel--side">
                    <div class="analytics-panel__header">
                        <div>
                            <h2 class="analytics-panel__title">Top Pages in Search</h2>
                            <p class="analytics-panel__meta">Pages earning the most clicks.</p>
                        </div>
                        <?php if ($scPages['rows'] !== []): ?><?= analytics_export_link('sc_pages') ?><?php endif; ?>
                    </div>
                    <?php if ($scPages['rows'] === []): ?>
                        <p class="analytics-empty">No data yet.</p>
                    <?php else: ?>
                        <table class="table analytics-table align-middle hub-data-table">
                            <thead><tr>
                                <?= analytics_sort_th('sc_pages', 'Page', 'page', $scPageSort, 'asc') ?>
                                <?= analytics_sort_th('sc_pages', 'Clicks', 'clicks', $scPageSort) ?>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($scPagesPagination['rows'] as $row): ?>
                                <?php $fullUrl = (string) ($row['keys'][0] ?? ''); ?>
                                <tr><td class="small"><a class="text-reset" href="<?= h($fullUrl) ?>" target="_blank" rel="noopener" title="<?= h($fullUrl) ?>"><?= h(analytics_url_path($fullUrl)) ?></a></td><td><?= analytics_number((float) $row['clicks']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php analytics_render_pagination('sc_pages_page', $scPagesPagination, $days); ?>
                    <?php endif; ?>
                </section>

                <section class="analytics-panel analytics-panel--wide">
                    <div class="analytics-panel__header">
                        <div>
                            <h2 class="analytics-panel__title">Daily Trend</h2>
                            <p class="analytics-panel__meta">Search clicks and impressions by day.</p>
                        </div>
                        <?php if ($scDaily['rows'] !== []): ?><?= analytics_export_link('sc_daily') ?><?php endif; ?>
                    </div>
                    <?php if ($scDaily['rows'] === []): ?>
                        <p class="analytics-empty">No trend data yet.</p>
                    <?php else: ?>
                        <table class="table analytics-table align-middle hub-data-table">
                            <thead><tr><th>Day</th><th>Clicks</th><th>Impressions</th></tr></thead>
                            <tbody>
                            <?php foreach ($scDailyPagination['rows'] as $row): ?>
                                <tr>
                                    <td><?= h(analytics_day_label((string) ($row['keys'][0] ?? ''))) ?></td>
                                    <td>
                                        <div class="analytics-bar">
                                            <span><?= analytics_number((float) $row['clicks']) ?></span>
                                            <span class="analytics-bar__track"><span class="analytics-bar__fill" style="width: <?= min(100, ((float) $row['clicks'] / $maxScDaily) * 100) ?>%"></span></span>
                                        </div>
                                    </td>
                                    <td><?= analytics_number((float) $row['impressions']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php analytics_render_pagination('sc_daily_page', $scDailyPagination, $days); ?>
                    <?php endif; ?>
                </section>
            </div>
            <?php ad_tables_close_tag(); ?>
        <?php endif; ?>
    </div>
<?php elseif ($view === 'business'): ?>
<?php
$biz = ax_business_data($pdo, $days);
$bizStreams = [
    'shop' => ['Shop', 'fa-bag-shopping', AD_WINE],
    'tickets' => ['Tickets', 'fa-ticket', AD_BLUE],
    'tills' => ['Tills', 'fa-cash-register', AD_GOLD],
    'sponsors' => ['Sponsors', 'fa-handshake', AD_TEAL],
];
$bizSums = [];
$bizPrev = [];
foreach (array_keys($bizStreams) as $k) {
    $bizSums[$k] = ax_sum_rows($biz['income'][$k]);
    $bizPrev[$k] = ax_sum_rows($biz['previous'][$k]);
}
$bizTotal = array_sum($bizSums);
$bizPrevTotal = array_sum($bizPrev);
$bizCmp = ' vs previous ' . $days . ' day' . ($days === 1 ? '' : 's');
$bizTotalSeries = [];
foreach ($biz['buckets']['labels'] as $i => $_) {
    $bizTotalSeries[] = round(array_sum(array_map(static fn(array $s): float => (float) ($s[$i] ?? 0), $biz['buckets']['series'])), 2);
}
?>
<div class="analytics-dashboard">
    <div class="analytics-toolbar">
        <div>
            <strong>Club Business</strong>
            <div class="text-muted small">Money in from the shop, tickets, tills and sponsors, plus social posts and news. Taken from your own records, net of refunds.</div>
        </div>
        <div class="analytics-range" aria-label="Date range">
            <?php foreach ([30, 90, 180, 365] as $preset): ?>
                <a class="btn <?= $days === $preset ? 'btn-brand' : 'btn-outline-secondary' ?>" href="?view=business&amp;days=<?= $preset ?>"><?= $preset === 365 ? 'Year' : $preset . ' days' ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    ad_kpi_row([
        ['label' => 'Total income', 'value' => $bizTotal, 'format' => 'gbp', 'icon' => 'fa-sterling-sign', 'accent' => AD_BRAND, 'delta' => ad_delta($bizTotal, $bizPrevTotal), 'compare' => $bizCmp, 'spark' => $bizTotalSeries],
        ['label' => 'Shop', 'value' => $bizSums['shop'], 'format' => 'gbp', 'decimals' => 2, 'icon' => 'fa-bag-shopping', 'accent' => AD_WINE, 'delta' => ad_delta($bizSums['shop'], $bizPrev['shop']), 'compare' => $bizCmp, 'meta' => $biz['shopOrderCount'] . ' paid order' . ($biz['shopOrderCount'] === 1 ? '' : 's'), 'spark' => $biz['buckets']['series']['shop']],
        ['label' => 'Tickets', 'value' => $bizSums['tickets'], 'format' => 'gbp', 'icon' => 'fa-ticket', 'accent' => AD_BLUE, 'delta' => ad_delta($bizSums['tickets'], $bizPrev['tickets']), 'compare' => $bizCmp, 'meta' => 'Match and season tickets', 'spark' => $biz['buckets']['series']['tickets']],
        ['label' => 'Tills', 'value' => $bizSums['tills'], 'format' => 'gbp', 'icon' => 'fa-cash-register', 'accent' => AD_GOLD, 'delta' => ad_delta($bizSums['tills'], $bizPrev['tills']), 'compare' => $bizCmp, 'meta' => 'Matchday sales', 'spark' => $biz['buckets']['series']['tills']],
        ['label' => 'Sponsors', 'value' => $bizSums['sponsors'], 'format' => 'gbp', 'icon' => 'fa-handshake', 'accent' => AD_TEAL, 'delta' => ad_delta($bizSums['sponsors'], $bizPrev['sponsors']), 'compare' => $bizCmp, 'meta' => 'Payments received', 'spark' => $biz['buckets']['series']['sponsors']],
    ]);
    ?>

    <div class="analytics-grid">
        <?php
        ad_panel_open('Income over time', 'Money received, grouped by ' . ($days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month')) . '. Click a key to show or hide a source.', 12);
        if ($bizTotal <= 0) {
            ad_empty('No income recorded in this period. Try a longer range.');
        } else {
            ad_widget('trend', [
                'title' => 'Income by source',
                'labels' => $biz['buckets']['labels'],
                'active' => array_keys($bizStreams),
                'metrics' => array_map(static fn(string $k, array $def): array => ['key' => $k, 'label' => $def[0], 'color' => $def[2], 'data' => $biz['buckets']['series'][$k], 'fmt' => 'gbp', 'agg' => 'sum'], array_keys($bizStreams), $bizStreams),
            ]);
        }
        ad_panel_close();

        ad_panel_open('Where the money comes from', 'Share of income in this period.', 5);
        if ($bizTotal <= 0) {
            ad_empty('No income recorded in this period.');
        } else {
            $streamParts = [];
            foreach ($bizStreams as $k => $def) {
                $streamParts[] = ['label' => $def[0], 'value' => round($bizSums[$k], 2), 'color' => $def[2], 'tip' => [['payments', (string) count($biz['income'][$k])]]];
            }
            ad_widget('split', ['valueLabel' => 'income', 'fmt' => 'gbp', 'parts' => $streamParts]);
            $topStream = array_keys($bizSums, max($bizSums), true)[0];
            ad_callout(number_format($bizSums[$topStream] / $bizTotal * 100, 0) . '%', 'of income came from ' . strtolower($bizStreams[$topStream][0]));
        }
        ad_panel_close();

        ad_panel_open('Shop', 'Orders created in this period, and what sold.', 7);
        $statusNames = ['paid' => 'Paid', 'collected' => 'Collected', 'refunded' => 'Refunded', 'pending_payment' => 'Awaiting payment', 'cancelled' => 'Cancelled', 'ready' => 'Ready'];
        $statusColors = ['paid' => AD_BLUE, 'collected' => AD_TEAL, 'refunded' => AD_GOLD, 'pending_payment' => AX_OTHER, 'cancelled' => AD_VIOLET, 'ready' => AD_WINE];
        if ($biz['shopStatus'] !== []) {
            echo '<p class="ad-mini-title">Orders by status</p>';
            ad_widget('split', ['valueLabel' => 'orders', 'fmt' => 'int', 'parts' => array_map(static fn(array $r): array => [
                'label' => $statusNames[(string) $r['status']] ?? ucfirst(str_replace('_', ' ', (string) $r['status'])),
                'value' => (float) $r['n'],
                'color' => $statusColors[(string) $r['status']] ?? AD_TEAL,
            ], $biz['shopStatus'])]);
        } else {
            ad_empty('No shop orders were created in this period.');
        }
        if ($biz['shopProducts'] !== []) {
            echo '<p class="ad-mini-title" style="margin-top:1.1rem">Best sellers</p>';
            ad_widget('rank', ['valueLabel' => 'sold', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 5, 'showShare' => false, 'items' => array_map(static fn(array $r): array => [
                'label' => (string) $r['product_name'],
                'value' => (float) $r['qty'],
                'tip' => [['revenue', '£' . number_format((float) $r['revenue'], 2)]],
            ], $biz['shopProducts'])]);
        }
        $pend = $biz['shopPending'];
        if ((int) $pend['n'] > 0) {
            ad_callout('£' . number_format((float) $pend['t'], 0), (int) $pend['n'] . ' order' . ((int) $pend['n'] === 1 ? ' is' : 's are') . ' still waiting for payment');
        }
        ad_panel_close();

        ad_panel_open('Tickets', 'Season ticket sales and match-day check-in.', 5);
        $mt = $biz['matchTickets'];
        $issued = (int) $mt['issued'];
        ad_widget('ring', ['value' => $issued > 0 ? round((int) $mt['checked'] / $issued * 100, 1) : 0, 'decimals' => 0, 'label' => 'Match tickets checked in', 'sub' => (int) $mt['checked'] . ' of ' . $issued . ' scanned at the gate.', 'color' => AD_TEAL], 'ad-ring');
        if ($biz['seasonTypes'] !== []) {
            echo '<p class="ad-mini-title" style="margin-top:1.1rem">Season tickets sold</p>';
            ad_widget('rank', ['valueLabel' => 'tickets', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 6, 'items' => array_map(static fn(array $r): array => [
                'label' => (string) $r['name'],
                'value' => (float) $r['n'],
                'tip' => [['revenue', '£' . number_format((float) $r['revenue'], 2)]],
            ], $biz['seasonTypes'])]);
        }
        ad_callout((string) $biz['seasonTotal'], 'season passes issued in total');
        ad_panel_close();

        ad_panel_open('Tills', 'Matchday takings by how people paid.', 7);
        if ($biz['tillMethods'] === []) {
            ad_empty('No till sales in this period.');
        } else {
            $payNames = ['card' => 'Card', 'card_stripe_app' => 'Card (Stripe app)', 'cash' => 'Cash'];
            $payColors = ['card' => AD_BLUE, 'card_stripe_app' => AD_TEAL, 'cash' => AD_GOLD];
            ad_widget('split', ['valueLabel' => 'takings', 'fmt' => 'gbp', 'decimals' => 2, 'parts' => array_map(static fn(array $r): array => [
                'label' => $payNames[(string) $r['payment_method']] ?? ucfirst(str_replace('_', ' ', (string) $r['payment_method'])),
                'value' => (float) $r['t'],
                'color' => $payColors[(string) $r['payment_method']] ?? AD_VIOLET,
                'tip' => [['sales', (string) $r['n']]],
            ], $biz['tillMethods'])]);
            $tillCount = array_sum(array_map(static fn(array $r): int => (int) $r['n'], $biz['tillMethods']));
            if ($tillCount > 0) {
                ad_callout('£' . number_format($bizSums['tills'] / $tillCount, 2), 'average sale across ' . $tillCount . ' sales');
            }
            if ($biz['tillProducts'] !== []) {
                echo '<p class="ad-mini-title" style="margin-top:1.1rem">Top sellers</p>';
                ad_widget('rank', ['valueLabel' => 'sold', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 5, 'showShare' => false, 'items' => array_map(static fn(array $r): array => [
                    'label' => (string) $r['product_name'],
                    'value' => (float) $r['qty'],
                    'tip' => [['takings', '£' . number_format((float) $r['revenue'], 2)]],
                ], $biz['tillProducts'])]);
            }
        }
        ad_panel_close();

        ad_panel_open('Sponsors', 'Payments received, by how they were paid.', 6);
        if ($biz['sponsorMethods'] === []) {
            ad_empty('No sponsor payments in this period.');
        } else {
            $methodParts = array_map(static fn(array $r): array => ['label' => ucfirst(str_replace('_', ' ', (string) $r['method'])), 'value' => (float) $r['t'], 'tip' => [['payments', (string) $r['n']]]], $biz['sponsorMethods']);
            ad_widget('split', ['valueLabel' => 'received', 'fmt' => 'gbp', 'parts' => ax_split_parts($methodParts, 4)]);
        }
        ad_callout('£' . number_format((float) $biz['agreements']['t'], 0), 'agreed across ' . (int) $biz['agreements']['n'] . ' active sponsorships');
        ad_panel_close();

        ad_panel_open('Social posts', 'Posts published in this period, by platform.', 6);
        $socialPublished = [];
        foreach ($biz['social'] as $r) {
            if (in_array((string) $r['status'], ['published', 'prepared'], true)) {
                $name = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'x' => 'X (prepared)'][(string) $r['platform']] ?? ucfirst((string) $r['platform']);
                $socialPublished[$name] = ($socialPublished[$name] ?? 0) + (int) $r['n'];
            }
        }
        if ($socialPublished === []) {
            ad_empty('No posts were published in this period.');
        } else {
            arsort($socialPublished);
            ad_widget('rank', ['valueLabel' => 'posts', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 6, 'showShare' => false, 'items' => array_map(static fn(string $k, int $v): array => ['label' => $k, 'value' => (float) $v], array_keys($socialPublished), $socialPublished)]);
        }
        ad_callout((string) $biz['socialDrafts'], 'drafts waiting to be published');
        ad_panel_close();

        ad_panel_open('News', 'Most read articles in this period, from Google Analytics.', 12);
        $newsRows = [];
        if (google_reporting_enabled()) {
            $paths = ga4_top_pages($days, 100);
            foreach ($paths['rows'] ?? [] as $r) {
                if (preg_match('#^/news/([^/?]+)#', (string) $r['pagePath'], $m) && isset($biz['newsTitles'][$m[1]])) {
                    $newsRows[$m[1]] = ['label' => $biz['newsTitles'][$m[1]], 'value' => ($newsRows[$m[1]]['value'] ?? 0) + (float) $r['screenPageViews'], 'sub' => (string) $r['pagePath']];
                }
            }
        }
        if ($newsRows === []) {
            ad_empty('No article views recorded in this period.');
        } else {
            $newsRows = ad_sort_desc(array_values($newsRows), 'value');
            ad_widget('rank', ['valueLabel' => 'views', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 5, 'items' => array_slice($newsRows, 0, 12)]);
        }
        ad_callout((string) $biz['newsPublished'], 'article' . ($biz['newsPublished'] === 1 ? '' : 's') . ' published in this period');
        ad_panel_close();
        ?>
    </div>
</div>
<?php elseif ($view === 'hub'): ?>

<div class="analytics-dashboard">
    <div class="analytics-toolbar">
        <div>
            <strong>Analytics window</strong>
            <div class="text-muted small">First-party tracking from authenticated hub pages. Form values are not recorded.</div>
        </div>
        <div class="analytics-range" aria-label="Date range">
            <?php foreach ([7, 30, 90, 365] as $range): ?>
                <a class="btn <?= $days === $range ? 'btn-brand' : 'btn-outline-secondary' ?>" href="?view=hub&amp;days=<?= $range ?>"><?= $range === 365 ? 'Year' : $range . ' days' ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    $hubLabels = array_map(static fn(array $r): string => ad_day_label((string) $r['event_day']), $dailyChart);
$hubViewsSeries = array_map(static fn(array $r): int => (int) $r['page_views'], $dailyChart);
$hubClicksSeries = array_map(static fn(array $r): int => (int) $r['clicks'], $dailyChart);
$hubUsersSeries = array_map(static fn(array $r): int => (int) $r['users'], $dailyChart);
$hubCmp = ' vs previous ' . $days . ' day' . ($days === 1 ? '' : 's');
$hubPrev = static fn(string $key): ?float => $prevSummary === [] || ($prevSummary[$key] ?? null) === null ? null : (float) $prevSummary[$key];
$hubViews = (float) ($summary['page_views'] ?? 0);
$hubUsers = (float) ($summary['users'] ?? 0);
$hubClicks = (float) ($summary['clicks'] ?? 0);
$hubDuration = (float) ($summary['avg_duration_ms'] ?? 0) / 1000;
$hubPrevDuration = ($p = $hubPrev('avg_duration_ms')) === null ? null : $p / 1000;

ad_kpi_row([
    ['label' => 'Page views', 'value' => $hubViews, 'icon' => 'fa-eye', 'accent' => AD_WINE, 'delta' => ad_delta($hubViews, $hubPrev('page_views')), 'compare' => $hubCmp, 'spark' => $hubViewsSeries],
    ['label' => 'Active users', 'value' => $hubUsers, 'icon' => 'fa-users', 'accent' => AD_BLUE, 'delta' => ad_delta($hubUsers, $hubPrev('users')), 'compare' => $hubCmp, 'meta' => analytics_number((int) ($summary['sessions'] ?? 0)) . ' sessions', 'spark' => $hubUsersSeries],
    ['label' => 'Feature clicks', 'value' => $hubClicks, 'icon' => 'fa-computer-mouse', 'accent' => AD_TEAL, 'delta' => ad_delta($hubClicks, $hubPrev('clicks')), 'compare' => $hubCmp, 'meta' => analytics_number((int) ($summary['form_submits'] ?? 0)) . ' form submits', 'spark' => $hubClicksSeries],
    ['label' => 'Time on page', 'value' => $hubDuration, 'format' => 'sec', 'icon' => 'fa-stopwatch', 'accent' => AD_GOLD, 'delta' => ad_delta($hubDuration, $hubPrevDuration), 'compare' => $hubCmp, 'meta' => 'Average per page'],
]);

echo '<div class="analytics-grid">';

ad_panel_open('Activity over time', 'Daily page views, feature clicks and active users. Click a key to show or hide it.', 12);
if ($dailyChart === []) {
    ad_empty('No analytics have been recorded for this date range yet.');
} else {
    ad_widget('trend', [
        'title' => 'Hub activity by day',
        'labels' => $hubLabels,
        'active' => ['views', 'clicks'],
        'metrics' => [
            ['key' => 'views', 'label' => 'Page views', 'color' => AD_WINE, 'data' => $hubViewsSeries, 'fmt' => 'int', 'agg' => 'sum'],
            ['key' => 'clicks', 'label' => 'Feature clicks', 'color' => AD_TEAL, 'data' => $hubClicksSeries, 'fmt' => 'int', 'agg' => 'sum'],
            ['key' => 'users', 'label' => 'Active users', 'color' => AD_BLUE, 'data' => $hubUsersSeries, 'fmt' => 'int', 'agg' => 'sum'],
        ],
    ]);
}
ad_panel_close();

ad_panel_open('Most visited pages', 'Page views per page. Hover a row for users, clicks and time on page.', 7);
if ($topPages === []) {
    ad_empty('No analytics have been recorded for this date range yet.');
} else {
    ad_widget('rank', [
        'valueLabel' => 'page views', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 8, 'total' => $hubViews,
        'items' => array_map(static fn(array $r): array => [
            'label' => (string) ($r['page_title'] ?: $r['page_path']),
            'sub' => (string) $r['page_path'],
            'value' => (float) $r['page_views'],
            'tip' => [['users', analytics_number((int) $r['users'])], ['clicks', analytics_number((int) $r['clicks'])], ['avg time', analytics_seconds((float) ($r['avg_duration_ms'] ?? 0))]],
        ], array_slice($topPages, 0, 20)),
    ]);
}
ad_panel_close();

ad_panel_open('Engagement', 'How deeply people read, and how much they interact.', 5);
ad_widget('ring', ['value' => round((float) ($summary['avg_scroll_depth'] ?? 0), 1), 'decimals' => 0, 'label' => 'Average scroll depth', 'sub' => 'How far down a page people get.', 'color' => AD_WINE], 'ad-ring');
echo '<div style="height:1.1rem"></div>';
$clicksPerView = $hubViews > 0 ? $hubClicks / $hubViews : 0;
ad_widget('ring', ['value' => min(100, round($clicksPerView * 100, 1)), 'decimals' => 0, 'label' => 'Interaction rate', 'sub' => number_format($clicksPerView, 2) . ' clicks per page view.', 'color' => AD_TEAL], 'ad-ring');
ad_panel_close();

ad_panel_open('Busiest times', 'Events by day of the week and hour. Darker means busier.', 12);
ad_widget('heat', ['title' => 'Hub activity by weekday and hour', 'valueLabel' => 'events', 'rows' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], 'values' => $heatValues]);
ad_panel_close();

ad_panel_open('Most used features', 'Buttons and forms people use most.', 7);
if ($topFeatures === []) {
    ad_empty('No feature activity recorded yet.');
} else {
    ad_widget('rank', [
        'valueLabel' => 'uses', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 8,
        'items' => array_map(static fn(array $r): array => [
            'label' => (string) $r['label'],
            'sub' => (string) $r['page_path'],
            'value' => (float) $r['events'],
            'tip' => [['type', $r['event_type'] === 'form_submit' ? 'Form submit' : 'Click'], ['users', analytics_number((int) $r['users'])]],
        ], array_slice($topFeatures, 0, 20)),
    ]);
}
ad_panel_close();

ad_panel_open('Devices', 'Share of sessions by device.', 5);
if ($devices === []) {
    ad_empty('No device data yet.');
} else {
    $hubDeviceColors = ['mobile' => AD_WINE, 'desktop' => AD_BLUE, 'tablet' => AD_GOLD];
    ad_widget('split', [
        'valueLabel' => 'sessions', 'fmt' => 'int',
        'parts' => array_map(static fn(array $r): array => [
            'label' => ucfirst((string) $r['device']),
            'value' => (float) $r['sessions'],
            'color' => $hubDeviceColors[strtolower((string) $r['device'])] ?? AD_TEAL,
            'tip' => [['events', analytics_number((int) $r['events'])]],
        ], $devices),
    ]);
    $hubDeviceTotal = array_sum(array_map(static fn(array $r): float => (float) $r['sessions'], $devices));
    if ($hubDeviceTotal > 0) {
        ad_callout(number_format((float) $devices[0]['sessions'] / $hubDeviceTotal * 100, 0) . '%', 'of sessions are on ' . strtolower((string) $devices[0]['device']));
    }
}
ad_panel_close();

$hubLongest = array_values(array_filter($topPages, static fn(array $r): bool => (int) $r['page_views'] >= 3 && (float) ($r['avg_duration_ms'] ?? 0) > 0));
usort($hubLongest, static fn(array $a, array $b): int => (float) $b['avg_duration_ms'] <=> (float) $a['avg_duration_ms']);
$hubLongest = array_slice($hubLongest, 0, 12);

ad_panel_open('Where people spend the most time', 'Average time on a page, for pages with 3 or more views.', 6);
if ($hubLongest === []) {
    ad_empty('Not enough page views recorded yet.');
} else {
    ad_widget('rank', [
        'valueLabel' => 'average time', 'fmt' => 'sec', 'color' => AD_BRAND, 'limit' => 6, 'showShare' => false,
        'items' => array_map(static fn(array $r): array => [
            'label' => (string) ($r['page_title'] ?: $r['page_path']),
            'sub' => (string) $r['page_path'],
            'value' => round((float) $r['avg_duration_ms'] / 1000, 1),
            'tip' => [['page views', analytics_number((int) $r['page_views'])], ['avg scroll', analytics_percent((float) ($r['avg_scroll_depth'] ?? 0))]],
        ], $hubLongest),
    ]);
}
ad_panel_close();

ad_panel_open('Who uses the hub', 'People by page views in this period.', 6);
if ($users === []) {
    ad_empty('No activity recorded yet.');
} else {
    $hubUserRows = ad_sort_desc($users, 'page_views');
    ad_widget('rank', [
        'valueLabel' => 'page views', 'fmt' => 'int', 'color' => AD_BRAND, 'limit' => 6,
        'items' => array_map(static fn(array $r): array => [
            'label' => (string) $r['name'],
            'sub' => ucfirst((string) ($r['user_role'] ?: 'user')) . ' · last seen ' . date('j M, H:i', (int) strtotime((string) $r['last_seen'])),
            'value' => (float) $r['page_views'],
            'tip' => [['sessions', analytics_number((int) $r['sessions'])], ['clicks', analytics_number((int) $r['clicks'])], ['form submits', analytics_number((int) $r['form_submits'])]],
        ], array_slice($hubUserRows, 0, 20)),
    ]);
}
ad_panel_close();

echo '</div>';
ad_tables_open_tag('Pages, features, users and journeys');
    ?>

    <div class="analytics-grid">
        <section class="analytics-panel analytics-panel--wide">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">Top 5 Pages</h2>
                    <p class="analytics-panel__meta">Most visited pages this window.</p>
                </div>
            </div>
            <?php if ($topFivePages === []): ?>
                <p class="analytics-empty">No analytics have been recorded for this date range yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table analytics-table align-middle hub-data-table">
                        <thead><tr><th>Page</th><th>Views</th><th>Users</th><th>Avg time</th></tr></thead>
                        <tbody>
                        <?php foreach ($topFivePages as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= h((string) $row['page_path']) ?></div>
                                    <div class="analytics-bar mt-1"><span class="analytics-bar__track"><span class="analytics-bar__fill" style="width: <?= min(100, ((int) $row['page_views'] / $maxPageViews) * 100) ?>%"></span></span></div>
                                </td>
                                <td><?= analytics_number((int) $row['page_views']) ?></td>
                                <td><?= analytics_number((int) $row['users']) ?></td>
                                <td><?= analytics_seconds((float) ($row['avg_duration_ms'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="analytics-panel analytics-panel--side">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">Worst 5 Pages</h2>
                    <p class="analytics-panel__meta">Least visited, with tracked activity.</p>
                </div>
            </div>
            <?php if ($worstFivePages === []): ?>
                <p class="analytics-empty">Not enough tracked pages yet.</p>
            <?php else: ?>
                <table class="table analytics-table align-middle hub-data-table">
                    <thead><tr><th>Page</th><th>Views</th></tr></thead>
                    <tbody>
                    <?php foreach ($worstFivePages as $row): ?>
                        <tr><td><?= h((string) $row['page_path']) ?></td><td><?= analytics_number((int) $row['page_views']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="analytics-panel analytics-panel--side">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">Devices</h2>
                    <p class="analytics-panel__meta">Viewport-based usage split.</p>
                </div>
            </div>
            <?php if ($devices === []): ?>
                <p class="analytics-empty">No device data yet.</p>
            <?php else: ?>
                <table class="table analytics-table align-middle hub-data-table">
                    <thead><tr><th>Device</th><th>Sessions</th><th>Events</th></tr></thead>
                    <tbody>
                    <?php foreach ($devices as $row): ?>
                        <tr><td><?= h((string) $row['device']) ?></td><td><?= analytics_number((int) $row['sessions']) ?></td><td><?= analytics_number((int) $row['events']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="analytics-panel analytics-panel--side">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">Daily Trend</h2>
                    <p class="analytics-panel__meta">Page views and active users by day.</p>
                </div>
            </div>
            <?php if ($daily === []): ?>
                <p class="analytics-empty">No trend data yet.</p>
            <?php else: ?>
            <?php endif; ?>
        </section>

        <section class="analytics-panel analytics-panel--side">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">User Activity</h2>
                    <p class="analytics-panel__meta">Who is using the Hub.</p>
                </div>
            </div>
            <?php if ($users === []): ?>
                <p class="analytics-empty">No user activity has been recorded yet.</p>
            <?php else: ?>
                <table class="table analytics-table align-middle hub-data-table">
                    <thead><tr><th>User</th><th>Sessions</th><th>Views</th></tr></thead>
                    <tbody>
                    <?php foreach ($usersVisible as $row): ?>
                        <tr>
                            <td>
                                <div class="fw-bold"><?= h((string) $row['name']) ?></div>
                                <div class="small text-muted"><?= h((string) $row['user_role']) ?> &middot; <?= h(date('d M H:i', strtotime((string) $row['last_seen']))) ?></div>
                            </td>
                            <td><?= analytics_number((int) $row['sessions']) ?></td>
                            <td><?= analytics_number((int) $row['page_views']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php analytics_render_pagination('users_page', $usersPagination, $days); ?>
            <?php endif; ?>
        </section>

        <section class="analytics-panel analytics-panel--full">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">Feature Usage</h2>
                    <p class="analytics-panel__meta">Buttons, links, and form submissions people use most.</p>
                </div>
            </div>
            <?php if ($topFeatures === []): ?>
                <p class="analytics-empty">No feature clicks have been recorded yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table analytics-table align-middle hub-data-table">
                        <thead><tr><th>Feature</th><th>Page</th><th>Type</th><th>Events</th><th>Users</th></tr></thead>
                        <tbody>
                        <?php foreach ($topFeaturesVisible as $row): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold"><?= h((string) $row['label']) ?></div>
                                    <div class="analytics-bar mt-1"><span class="analytics-bar__track"><span class="analytics-bar__fill" style="width: <?= min(100, ((int) $row['events'] / $maxFeatureEvents) * 100) ?>%"></span></span></div>
                                </td>
                                <td><?= h((string) $row['page_path']) ?></td>
                                <td><?= h(str_replace('_', ' ', (string) $row['event_type'])) ?></td>
                                <td><?= analytics_number((int) $row['events']) ?></td>
                                <td><?= analytics_number((int) $row['users']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php analytics_render_pagination('features_page', $topFeaturesPagination, $days); ?>
            <?php endif; ?>
        </section>

        <section class="analytics-panel analytics-panel--full">
            <div class="analytics-panel__header">
                <div>
                    <h2 class="analytics-panel__title">Recent Journeys</h2>
                    <p class="analytics-panel__meta">Latest sessions and their page sequence.</p>
                </div>
            </div>
            <?php if ($journeys === []): ?>
                <p class="analytics-empty">No journeys have been recorded yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table analytics-table align-middle hub-data-table">
                        <thead><tr><th>User</th><th>Started</th><th>Duration</th><th>Steps</th><th>Path</th></tr></thead>
                        <tbody>
                        <?php foreach ($journeysVisible as $row): ?>
                            <?php
                            $steps = array_values(array_filter(explode(' > ', (string) $row['pages']), static fn(string $p): bool => $p !== ''));
                            $stepCount = count($steps);
                            $shownSteps = array_slice($steps, 0, 4);
                            $pathText = implode(' › ', $shownSteps) . ($stepCount > 4 ? ' … +' . ($stepCount - 4) . ' more' : '');
                            $duration = max(0, strtotime((string) $row['ended_at']) - strtotime((string) $row['started_at']));
                            ?>
                            <tr>
                                <td class="fw-bold"><?= h((string) $row['name']) ?></td>
                                <td class="small text-muted"><?= h(date('d M H:i', strtotime((string) $row['started_at']))) ?></td>
                                <td><?= ga_seconds($duration) ?></td>
                                <td><?= analytics_number($stepCount) ?></td>
                                <td class="small text-muted" title="<?= h(str_replace(' > ', ' › ', (string) $row['pages'])) ?>"><?= h($pathText) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php analytics_render_pagination('journeys_page', $journeysPagination, $days); ?>
            <?php endif; ?>
        </section>
    </div>
    <?php ad_tables_close_tag(); ?>
</div>


<?php endif; ?>

<?php if (!empty($GLOBALS['analytics_charts'])): ?>
<script src="/admin/assets/vendor/chartjs/chart.umd.min.js"></script>
<script>
(() => {
    const charts = <?= json_encode($GLOBALS['analytics_charts'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Object.entries(charts).forEach(([id, cfg]) => {
        const canvas = document.getElementById(id);
        if (!canvas) return;
        new Chart(canvas, {
            type: 'line',
            data: {
                labels: cfg.labels,
                datasets: cfg.datasets.map(d => ({
                    label: d.label,
                    data: d.data,
                    borderColor: d.color,
                    backgroundColor: d.color + '14',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0,
                    borderWidth: 2,
                })),
            },
            options: {
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.06)' } },
                },
                plugins: { legend: { position: 'bottom' } },
            },
        });
    });
})();
</script>
<?php endif; ?>

<script src="/admin/assets/vendor/chartjs/chart.umd.min.js"></script>
<script src="/admin/assets/js/developer_analytics_dashboard.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/developer_analytics_dashboard.js') ?>"></script>

<?php require_once __DIR__ . '/footer.php'; ?>
