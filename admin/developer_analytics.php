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

if (!hub_auth_is_developer()) {
    echo '<div class="alert alert-danger m-3">Access denied.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$view = $_GET['view'] ?? 'site';
$view = in_array($view, ['site', 'seo', 'hub'], true) ? $view : 'site';

$days = hub_analytics_interval_days($_GET['days'] ?? 30);

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
} // end $view === 'hub' data prep
?>

<nav class="nav nav-tabs mb-4" aria-label="Analytics sections">
    <?php foreach (['site' => 'Site Analytics', 'seo' => 'SEO', 'hub' => 'Hub Usage'] as $tabKey => $tabLabel): ?>
        <a class="nav-link <?= $view === $tabKey ? 'active' : '' ?>" <?= $view === $tabKey ? 'aria-current="page"' : '' ?> href="?view=<?= $tabKey ?>&amp;days=<?= $days ?>"><?= h($tabLabel) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!google_reporting_enabled() && $view !== 'hub'): ?>
    <div class="alert alert-warning m-3">Google Analytics / Search Console credentials aren't configured yet.</div>
<?php elseif ($view === 'site'): ?>
    <?php
    $gaSummary = ga4_summary($days);
    $gaTopPages = ga4_top_pages($days);
    $gaSources = ga4_traffic_sources($days);
    $gaDevices = ga4_devices($days);
    $gaDaily = ga4_daily_trend($days);
    $gaError = $gaSummary['error'] ?? $gaTopPages['error'] ?? null;
    $s = $gaSummary['rows'][0] ?? [];
    $maxGaDaily = max(1, ...array_map(static fn(array $r): int => (int) ($r['screenPageViews'] ?? 0), $gaDaily['rows'] ?: [['screenPageViews' => 1]]));
    ?>
    <div class="analytics-dashboard">
        <div class="analytics-toolbar">
            <div>
                <strong>Site Analytics</strong>
                <div class="text-muted small">Public site visitors, via Google Analytics.</div>
            </div>
            <div class="analytics-range" aria-label="Date range">
                <?php foreach ([7, 30, 90, 365] as $range): ?>
                    <a class="btn <?= $days === $range ? 'btn-brand' : 'btn-outline-secondary' ?>" href="?view=site&amp;days=<?= $range ?>"><?= $range === 365 ? 'Year' : $range . ' days' ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($gaError): ?>
            <div class="alert alert-danger m-3">Google Analytics error: <?= h($gaError) ?></div>
        <?php else: ?>
            <?php
            hub_render_metric_grid([
                ['label' => 'Page views', 'value' => analytics_number((int) ($s['screenPageViews'] ?? 0)), 'meta' => $days . ' day window', 'icon' => 'fa-eye', 'tone' => 'primary'],
                ['label' => 'Visitors', 'value' => analytics_number((int) ($s['activeUsers'] ?? 0)), 'meta' => analytics_number((int) ($s['newUsers'] ?? 0)) . ' new', 'icon' => 'fa-users', 'tone' => 'success'],
                ['label' => 'Sessions', 'value' => analytics_number((int) ($s['sessions'] ?? 0)), 'meta' => ga_seconds((float) ($s['averageSessionDuration'] ?? 0)) . ' avg', 'icon' => 'fa-arrows-turn-right', 'tone' => 'info'],
                ['label' => 'Bounce rate', 'value' => ga_percent((float) ($s['bounceRate'] ?? 0)), 'meta' => 'left after one page', 'icon' => 'fa-door-open', 'tone' => 'warning'],
            ], 'Site analytics summary');
            ?>

            <div class="analytics-grid">
                <section class="analytics-panel analytics-panel--wide">
                    <div class="analytics-panel__header">
                        <div>
                            <h2 class="analytics-panel__title">Top Pages</h2>
                            <p class="analytics-panel__meta">Most visited public pages.</p>
                        </div>
                    </div>
                    <?php if ($gaTopPages['rows'] === []): ?>
                        <p class="analytics-empty">No data for this date range yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table analytics-table align-middle hub-data-table">
                                <thead><tr><th>Page</th><th>Views</th><th>Visitors</th><th>Avg time</th></tr></thead>
                                <tbody>
                                <?php foreach ($gaTopPages['rows'] as $row): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold"><?= h((string) $row['pageTitle']) ?></div>
                                            <div class="small text-muted"><?= h((string) $row['pagePath']) ?></div>
                                        </td>
                                        <td><?= analytics_number((int) $row['screenPageViews']) ?></td>
                                        <td><?= analytics_number((int) $row['activeUsers']) ?></td>
                                        <td><?= ga_seconds((float) $row['averageSessionDuration']) ?></td>
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
                            <h2 class="analytics-panel__title">Traffic Sources</h2>
                            <p class="analytics-panel__meta">Where visitors come from.</p>
                        </div>
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
                            <p class="analytics-panel__meta">Page views by day.</p>
                        </div>
                    </div>
                    <?php if ($gaDaily['rows'] === []): ?>
                        <p class="analytics-empty">No trend data yet.</p>
                    <?php else: ?>
                        <table class="table analytics-table align-middle hub-data-table">
                            <thead><tr><th>Day</th><th>Views</th><th>Visitors</th></tr></thead>
                            <tbody>
                            <?php foreach (array_reverse($gaDaily['rows']) as $row): ?>
                                <tr>
                                    <td><?= h(date('d M', strtotime((string) $row['date']))) ?></td>
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
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
<?php elseif ($view === 'seo'): ?>
    <?php
    $scSummary = gsc_summary($days);
    $scQueries = gsc_top_queries($days);
    $scPages = gsc_top_pages($days);
    $scDaily = gsc_daily_trend($days);
    $scError = $scSummary['error'] ?? $scQueries['error'] ?? null;
    $sRow = $scSummary['rows'][0] ?? [];
    $maxScDaily = max(1, ...array_map(static fn(array $r): float => (float) ($r['clicks'] ?? 0), $scDaily['rows'] ?: [['clicks' => 1]]));
    ?>
    <div class="analytics-dashboard">
        <div class="analytics-toolbar">
            <div>
                <strong>SEO</strong>
                <div class="text-muted small">Search performance, via Google Search Console. Data lags by about 2 days.</div>
            </div>
            <div class="analytics-range" aria-label="Date range">
                <?php foreach ([7, 30, 90, 365] as $range): ?>
                    <a class="btn <?= $days === $range ? 'btn-brand' : 'btn-outline-secondary' ?>" href="?view=seo&amp;days=<?= $range ?>"><?= $range === 365 ? 'Year' : $range . ' days' ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($scError): ?>
            <div class="alert alert-danger m-3">Search Console error: <?= h($scError) ?></div>
        <?php else: ?>
            <?php
            hub_render_metric_grid([
                ['label' => 'Search clicks', 'value' => analytics_number((float) ($sRow['clicks'] ?? 0)), 'meta' => $days . ' day window', 'icon' => 'fa-arrow-pointer', 'tone' => 'primary'],
                ['label' => 'Impressions', 'value' => analytics_number((float) ($sRow['impressions'] ?? 0)), 'meta' => 'times shown in search', 'icon' => 'fa-eye', 'tone' => 'success'],
                ['label' => 'Click-through rate', 'value' => ga_percent((float) ($sRow['ctr'] ?? 0)), 'meta' => 'of impressions clicked', 'icon' => 'fa-percent', 'tone' => 'info'],
                ['label' => 'Avg position', 'value' => number_format((float) ($sRow['position'] ?? 0), 1), 'meta' => 'lower is better', 'icon' => 'fa-ranking-star', 'tone' => 'warning'],
            ], 'SEO summary');
            ?>

            <div class="analytics-grid">
                <section class="analytics-panel analytics-panel--wide">
                    <div class="analytics-panel__header">
                        <div>
                            <h2 class="analytics-panel__title">Top Search Queries</h2>
                            <p class="analytics-panel__meta">What people search to find this site.</p>
                        </div>
                    </div>
                    <?php if ($scQueries['rows'] === []): ?>
                        <p class="analytics-empty">No search query data yet — this can take a few days after verifying with Google.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table analytics-table align-middle hub-data-table">
                                <thead><tr><th>Query</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead>
                                <tbody>
                                <?php foreach ($scQueries['rows'] as $row): ?>
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
                    <?php endif; ?>
                </section>

                <section class="analytics-panel analytics-panel--side">
                    <div class="analytics-panel__header">
                        <div>
                            <h2 class="analytics-panel__title">Top Pages in Search</h2>
                            <p class="analytics-panel__meta">Pages earning the most clicks.</p>
                        </div>
                    </div>
                    <?php if ($scPages['rows'] === []): ?>
                        <p class="analytics-empty">No data yet.</p>
                    <?php else: ?>
                        <table class="table analytics-table align-middle hub-data-table">
                            <thead><tr><th>Page</th><th>Clicks</th></tr></thead>
                            <tbody>
                            <?php foreach ($scPages['rows'] as $row): ?>
                                <tr><td class="small"><?= h((string) str_replace('https://myclubhub.co.uk', '', (string) ($row['keys'][0] ?? ''))) ?></td><td><?= analytics_number((float) $row['clicks']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>

                <section class="analytics-panel analytics-panel--wide">
                    <div class="analytics-panel__header">
                        <div>
                            <h2 class="analytics-panel__title">Daily Trend</h2>
                            <p class="analytics-panel__meta">Search clicks by day.</p>
                        </div>
                    </div>
                    <?php if ($scDaily['rows'] === []): ?>
                        <p class="analytics-empty">No trend data yet.</p>
                    <?php else: ?>
                        <table class="table analytics-table align-middle hub-data-table">
                            <thead><tr><th>Day</th><th>Clicks</th><th>Impressions</th></tr></thead>
                            <tbody>
                            <?php foreach ($scDaily['rows'] as $row): ?>
                                <tr>
                                    <td><?= h(date('d M', strtotime((string) ($row['keys'][0] ?? '')))) ?></td>
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
                    <?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
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
    hub_render_metric_grid([
        ['label' => 'Page views', 'value' => analytics_number((int) ($summary['page_views'] ?? 0)), 'meta' => $days . ' day window', 'icon' => 'fa-eye', 'tone' => 'primary'],
        ['label' => 'Active users', 'value' => analytics_number((int) ($summary['users'] ?? 0)), 'meta' => analytics_number((int) ($summary['sessions'] ?? 0)) . ' sessions', 'icon' => 'fa-users', 'tone' => 'success'],
        ['label' => 'Feature clicks', 'value' => analytics_number((int) ($summary['clicks'] ?? 0)), 'meta' => analytics_number((int) ($summary['form_submits'] ?? 0)) . ' form submits', 'icon' => 'fa-computer-mouse', 'tone' => 'info'],
        ['label' => 'Avg engagement', 'value' => analytics_seconds((float) ($summary['avg_duration_ms'] ?? 0)), 'meta' => analytics_percent((float) ($summary['avg_scroll_depth'] ?? 0)) . ' avg scroll', 'icon' => 'fa-stopwatch', 'tone' => 'warning'],
    ], 'Analytics summary');
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
                <div style="position:relative;height:260px"><canvas id="hubDailyTrendChart"></canvas></div>
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
</div>

<?php if ($daily !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script>
(() => {
    const canvas = document.getElementById('hubDailyTrendChart');
    if (!canvas) return;
    const labels = <?= json_encode(array_map(static fn(array $r): string => date('d M', strtotime((string) $r['event_day'])), $dailyChart), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const views = <?= json_encode(array_map(static fn(array $r): int => (int) $r['page_views'], $dailyChart), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const users = <?= json_encode(array_map(static fn(array $r): int => (int) $r['users'], $dailyChart), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const brandFont = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.family = brandFont;
    Chart.defaults.plugins.legend.labels.usePointStyle = true;

    new Chart(canvas, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Page views',
                    data: views,
                    borderColor: '#17734f',
                    backgroundColor: 'rgba(23,115,79,0.08)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0,
                    borderWidth: 2,
                },
                {
                    label: 'Active users',
                    data: users,
                    borderColor: '#5b7fdb',
                    backgroundColor: 'rgba(91,127,219,0.08)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0,
                    borderWidth: 2,
                },
            ],
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
})();
</script>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
