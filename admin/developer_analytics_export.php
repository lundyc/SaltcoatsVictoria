<?php
declare(strict_types=1);

// developer_analytics_export.php — CSV download of a Site Analytics / SEO table
// for the selected date range. Developer-only, like developer_analytics.php.

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/analytics.php';
require_once __DIR__ . '/lib/google_reporting.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
if (!hub_auth_is_developer() || !google_reporting_enabled()) {
    http_response_code(403);
    exit('Access denied.');
}

$days = hub_analytics_interval_days($_GET['days'] ?? 30);
$custom = google_reporting_custom_range($_GET);
$range = $custom !== null ? ['from' => $custom['from'], 'to' => $custom['to']] : $days;
$base = google_reporting_site_base();

$gscPath = static function (string $url): string {
    $parts = parse_url($url);
    return ($parts !== false && isset($parts['host'])) ? ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '') : $url;
};

$table = (string) ($_GET['table'] ?? '');
switch ($table) {
    case 'ga_pages':
        $report = ga4_top_pages($range);
        $header = ['Title', 'Path', 'URL', 'Views', 'Visitors', 'Avg time (s)'];
        $rows = array_map(static fn(array $r): array => [$r['pageTitle'], $r['pagePath'], $base . $r['pagePath'], $r['screenPageViews'], $r['activeUsers'], round((float) $r['averageSessionDuration'])], $report['rows']);
        break;
    case 'ga_sources':
        $report = ga4_traffic_sources($range);
        $header = ['Source', 'Sessions', 'Visitors'];
        $rows = array_map(static fn(array $r): array => [$r['sessionDefaultChannelGroup'], $r['sessions'], $r['activeUsers']], $report['rows']);
        break;
    case 'ga_devices':
        $report = ga4_devices($range);
        $header = ['Device', 'Sessions', 'Visitors'];
        $rows = array_map(static fn(array $r): array => [$r['deviceCategory'], $r['sessions'], $r['activeUsers']], $report['rows']);
        break;
    case 'ga_daily':
        $report = ga4_daily_trend($range);
        $header = ['Date', 'Views', 'Visitors'];
        $rows = array_map(static fn(array $r): array => [date('Y-m-d', strtotime((string) $r['date'])), $r['screenPageViews'], $r['activeUsers']], $report['rows']);
        break;
    case 'sc_queries':
        $report = gsc_top_queries($range);
        $header = ['Query', 'Clicks', 'Impressions', 'CTR (%)', 'Position'];
        $rows = array_map(static fn(array $r): array => [$r['keys'][0] ?? '', $r['clicks'], $r['impressions'], round((float) $r['ctr'] * 100, 2), round((float) $r['position'], 1)], $report['rows']);
        break;
    case 'sc_pages':
        $report = gsc_top_pages($range);
        $header = ['Path', 'URL', 'Clicks', 'Impressions', 'CTR (%)', 'Position'];
        $rows = array_map(static fn(array $r): array => [$gscPath((string) ($r['keys'][0] ?? '')), $r['keys'][0] ?? '', $r['clicks'], $r['impressions'], round((float) $r['ctr'] * 100, 2), round((float) $r['position'], 1)], $report['rows']);
        break;
    case 'sc_daily':
        $report = gsc_daily_trend($range);
        $header = ['Date', 'Clicks', 'Impressions', 'CTR (%)', 'Position'];
        $rows = array_map(static fn(array $r): array => [$r['keys'][0] ?? '', $r['clicks'], $r['impressions'], round((float) $r['ctr'] * 100, 2), round((float) $r['position'], 1)], $report['rows']);
        break;
    default:
        http_response_code(400);
        exit('Unknown table.');
}

if (isset($report['error'])) {
    http_response_code(502);
    exit('Report error: ' . $report['error']);
}

// Cells starting with = + - @ are formula-injection vectors in spreadsheets
// (search queries and page titles are attacker-controlled text).
$safe = static function (mixed $v): string {
    $v = (string) $v;
    return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v) ? "'" . $v : $v;
};

$resolved = $custom ?? ['from' => date('Y-m-d', strtotime("-{$days} days")), 'to' => date('Y-m-d')];
$filename = sprintf('%s_%s_to_%s.csv', $table, $resolved['from'], $resolved['to']);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
fputcsv($out, $header, ',', '"', '');
foreach ($rows as $row) {
    fputcsv($out, array_map($safe, $row), ',', '"', '');
}
fclose($out);
