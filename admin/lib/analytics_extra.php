<?php

declare(strict_types=1);

/**
 * Extra data sources for the developer analytics dashboard:
 *  - more Google Analytics / Search Console breakdowns (location, referrers,
 *    weekday x hour, landing pages, devices...)
 *  - the "Club Business" tab: income from the shop, tickets, tills and sponsors,
 *    plus social posting and news.
 * Everything here is read-only.
 */

require_once __DIR__ . '/google_reporting.php';

/** Colour used for a folded-together "Other" slice (never a categorical hue). */
const AX_OTHER = '#9a9396';

// ---------------------------------------------------------------------
// Google Analytics / Search Console
// ---------------------------------------------------------------------

/**
 * @param list<string> $dims
 * @param list<string> $metrics
 * @return array{rows: list<array<string,string>>, totals?: array<string,string>, error?: string}
 */
function ax_ga(int|array $range, array $dims, array $metrics, int $limit = 10, ?string $orderMetric = null): array
{
    $body = [
        'dateRanges' => [google_reporting_date_range($range)],
        'metrics' => array_map(static fn(string $m): array => ['name' => $m], $metrics),
        'limit' => $limit,
    ];
    if ($dims !== []) {
        $body['dimensions'] = array_map(static fn(string $d): array => ['name' => $d], $dims);
        $body['orderBys'] = [['metric' => ['metricName' => $orderMetric ?? $metrics[0]], 'desc' => true]];
    }
    return ga4_run_report($body);
}

/**
 * @param list<string> $dims
 * @return array{rows: list<array<string,mixed>>, error?: string}
 */
function ax_gsc(int|array $range, array $dims, int $limit = 10): array
{
    $r = gsc_date_range($range);
    return gsc_query([
        'startDate' => $r['startDate'],
        'endDate' => $r['endDate'],
        'dimensions' => $dims,
        'rowLimit' => $limit,
    ]);
}

/** Friendly names for Search Console's three-letter country codes. */
function ax_country_name(string $code): string
{
    static $names = [
        'gbr' => 'United Kingdom', 'usa' => 'United States', 'nga' => 'Nigeria', 'aus' => 'Australia', 'esp' => 'Spain',
        'irl' => 'Ireland', 'can' => 'Canada', 'deu' => 'Germany', 'fra' => 'France', 'ind' => 'India', 'nld' => 'Netherlands',
        'pak' => 'Pakistan', 'zaf' => 'South Africa', 'are' => 'United Arab Emirates', 'pol' => 'Poland', 'ita' => 'Italy',
        'bra' => 'Brazil', 'nzl' => 'New Zealand', 'swe' => 'Sweden', 'prt' => 'Portugal', 'bel' => 'Belgium', 'che' => 'Switzerland',
        'gha' => 'Ghana', 'ken' => 'Kenya', 'sgp' => 'Singapore', 'phl' => 'Philippines', 'mys' => 'Malaysia', 'rou' => 'Romania',
    ];
    return $names[strtolower($code)] ?? strtoupper($code);
}

/** "scroll_depth" -> a readable label; null for events that are just noise. */
function ax_event_label(string $name): ?string
{
    static $skip = ['page_view', 'session_start', 'first_visit', 'user_engagement'];
    if (in_array($name, $skip, true)) {
        return null;
    }
    static $friendly = [
        'scroll_depth' => 'Scrolled down a page',
        'scroll' => 'Scrolled to the bottom',
        'click' => 'Clicked an outside link',
        'file_download' => 'Downloaded a file',
        'form_start' => 'Started a form',
        'form_submit' => 'Submitted a form',
        'video_start' => 'Started a video',
        'view_search_results' => 'Used site search',
    ];
    return $friendly[$name] ?? ucfirst(str_replace('_', ' ', $name));
}

/**
 * Weekday (Mon = 0) x hour grid from GA4 sessions.
 *
 * @return array{values: list<list<int>>, peakDay: string, peakHour: int}
 */
function ax_weekday_hour(int|array $range): array
{
    $r = ax_ga($range, ['dayOfWeek', 'hour'], ['sessions'], 200);
    $grid = array_fill(0, 7, array_fill(0, 24, 0));
    $dayTotals = array_fill(0, 7, 0);
    $hourTotals = array_fill(0, 24, 0);
    foreach ($r['rows'] as $row) {
        // GA4: 0 = Sunday ... 6 = Saturday. Re-base to Monday first.
        $day = ((int) $row['dayOfWeek'] + 6) % 7;
        $hour = max(0, min(23, (int) $row['hour']));
        $n = (int) $row['sessions'];
        $grid[$day][$hour] += $n;
        $dayTotals[$day] += $n;
        $hourTotals[$hour] += $n;
    }
    $names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $peakDay = array_sum($dayTotals) > 0 ? $names[(int) array_search(max($dayTotals), $dayTotals, true)] : '';
    $peakHour = array_sum($hourTotals) > 0 ? (int) array_search(max($hourTotals), $hourTotals, true) : 0;
    return ['values' => $grid, 'peakDay' => $peakDay, 'peakHour' => $peakHour, 'error' => $r['error'] ?? null];
}

/**
 * Turn label/value rows into split-bar parts: the first five get the fixed
 * categorical colours, anything after that folds into one grey "Other".
 *
 * @param list<array{label: string, value: float}> $items
 * @return list<array{label: string, value: float, color: string}>
 */
function ax_split_parts(array $items, int $max = 5): array
{
    $parts = [];
    $other = 0.0;
    foreach ($items as $i => $item) {
        if ($i < $max) {
            $parts[] = ['label' => $item['label'], 'value' => $item['value'], 'color' => AD_SERIES[$i]];
        } else {
            $other += $item['value'];
        }
    }
    if ($other > 0) {
        $parts[] = ['label' => 'Other', 'value' => $other, 'color' => AX_OTHER];
    }
    return $parts;
}

// ---------------------------------------------------------------------
// Club Business (first-party database)
// ---------------------------------------------------------------------

/**
 * Money received in [from, to), per income stream, as [date, amount] pairs.
 *
 * @return array{shop: list<array{0:string,1:float}>, tickets: list<array{0:string,1:float}>, tills: list<array{0:string,1:float}>, sponsors: list<array{0:string,1:float}>}
 */
function ax_income_rows(PDO $pdo, string $from, string $to): array
{
    $fetch = static function (string $sql) use ($pdo, $from, $to): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':from' => $from, ':to' => $to]);
        return array_map(static fn(array $r): array => [(string) $r[0], (float) $r[1]], $stmt->fetchAll(PDO::FETCH_NUM));
    };
    return [
        // Net of refunds; paid, collected and refunded orders all had a payment.
        'shop' => $fetch("SELECT paid_at, GREATEST(0, total - amount_refunded) FROM shop_orders
            WHERE status IN ('paid','collected','refunded') AND paid_at >= :from AND paid_at < :to"),
        // Match tickets and season tickets both land in payments.
        'tickets' => $fetch("SELECT paid_at, amount FROM payments
            WHERE status = 'paid' AND paid_at >= :from AND paid_at < :to"),
        'tills' => $fetch("SELECT COALESCE(completed_at, created_at), total FROM pos_sales
            WHERE status = 'complete' AND COALESCE(completed_at, created_at) >= :from AND COALESCE(completed_at, created_at) < :to"),
        'sponsors' => $fetch("SELECT paid_at, amount FROM sponsorship_payments WHERE paid_at >= :from AND paid_at < :to
            UNION ALL
            SELECT paid_at, amount FROM match_sponsorship_payments WHERE paid_at >= :from AND paid_at < :to"),
    ];
}

/** @param list<array{0:string,1:float}> $rows */
function ax_sum_rows(array $rows): float
{
    return (float) array_sum(array_map(static fn(array $r): float => $r[1], $rows));
}

/**
 * Bucket income rows into days / weeks / months, depending on the window length.
 *
 * @param array<string, list<array{0:string,1:float}>> $income
 * @return array{labels: list<string>, series: array<string, list<float>>}
 */
function ax_bucket_income(array $income, int $days): array
{
    $mode = $days <= 31 ? 'day' : ($days <= 120 ? 'week' : 'month');
    $keyOf = static function (int $ts) use ($mode): string {
        if ($mode === 'day') {
            return date('Y-m-d', $ts);
        }
        if ($mode === 'week') {
            return date('Y-m-d', strtotime('monday this week', $ts));
        }
        return date('Y-m-01', $ts);
    };
    $end = time();
    $start = $end - $days * 86400;
    $keys = [];
    for ($t = strtotime($keyOf($start)); $t !== false && $t <= $end; $t = strtotime($mode === 'day' ? '+1 day' : ($mode === 'week' ? '+1 week' : '+1 month'), $t)) {
        $keys[date('Y-m-d', $t)] = true;
    }
    $series = [];
    foreach ($income as $stream => $rows) {
        $series[$stream] = array_fill_keys(array_keys($keys), 0.0);
        foreach ($rows as [$date, $amount]) {
            $ts = strtotime($date);
            if ($ts === false) {
                continue;
            }
            $k = $keyOf($ts);
            if (isset($series[$stream][$k])) {
                $series[$stream][$k] += $amount;
            }
        }
    }
    $labels = array_map(static function (string $k) use ($mode): string {
        $ts = strtotime($k);
        return $mode === 'month' ? date("M 'y", $ts) : ($mode === 'week' ? 'w/c ' . date('j M', $ts) : date('j M', $ts));
    }, array_keys($keys));
    foreach ($series as $stream => $values) {
        $series[$stream] = array_map(static fn(float $v): float => round($v, 2), array_values($values));
    }
    return ['labels' => $labels, 'series' => $series];
}

/** @return array<string, mixed> */
function ax_business_data(PDO $pdo, int $days): array
{
    $now = time();
    $from = date('Y-m-d H:i:s', $now - $days * 86400);
    $to = date('Y-m-d H:i:s', $now + 60);
    $prevFrom = date('Y-m-d H:i:s', $now - 2 * $days * 86400);

    $current = ax_income_rows($pdo, $from, $to);
    $previous = ax_income_rows($pdo, $prevFrom, $from);
    $data = ['income' => $current, 'previous' => $previous, 'buckets' => ax_bucket_income($current, $days)];

    $one = static function (string $sql, array $params = []) use ($pdo): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $w = [':from' => $from, ':to' => $to];

    // Shop: orders by status (whole window by creation date) and best sellers.
    $data['shopStatus'] = $one("SELECT status, COUNT(*) n FROM shop_orders WHERE created_at >= :from AND created_at < :to GROUP BY status ORDER BY n DESC", $w);
    $data['shopProducts'] = $one("SELECT i.product_name, SUM(i.quantity) qty, SUM(i.line_total) revenue
        FROM shop_order_items i JOIN shop_orders o ON o.id = i.order_id
        WHERE o.status IN ('paid','collected','refunded') AND o.paid_at >= :from AND o.paid_at < :to
        GROUP BY i.product_name ORDER BY qty DESC, revenue DESC LIMIT 12", $w);
    $data['shopOrderCount'] = (int) ($one("SELECT COUNT(*) n FROM shop_orders WHERE status IN ('paid','collected','refunded') AND paid_at >= :from AND paid_at < :to", $w)[0]['n'] ?? 0);
    $data['shopPending'] = $one("SELECT COUNT(*) n, COALESCE(SUM(total),0) t FROM shop_orders WHERE status = 'pending_payment'")[0] ?? ['n' => 0, 't' => 0];

    // Tickets: season tickets by type, match-ticket check-in.
    $data['seasonTypes'] = $one("SELECT t.name, COUNT(*) n, SUM(o.price) revenue
        FROM season_ticket_orders o JOIN season_ticket_types t ON t.id = o.ticket_type_id
        WHERE o.paid = 1 AND COALESCE(o.paid_at, o.order_date) >= :from AND COALESCE(o.paid_at, o.order_date) < :to
        GROUP BY t.name ORDER BY n DESC", $w);
    $data['seasonTotal'] = (int) ($one("SELECT COUNT(*) n FROM season_passes")[0]['n'] ?? 0);
    $data['matchTickets'] = $one("SELECT COUNT(*) issued, COALESCE(SUM(checked_in_at IS NOT NULL),0) checked
        FROM match_tickets WHERE created_at >= :from AND created_at < :to", $w)[0] ?? ['issued' => 0, 'checked' => 0];

    // Tills: takings by payment method and basket size.
    $data['tillMethods'] = $one("SELECT payment_method, COUNT(*) n, SUM(total) t FROM pos_sales
        WHERE status = 'complete' AND COALESCE(completed_at, created_at) >= :from AND COALESCE(completed_at, created_at) < :to
        GROUP BY payment_method ORDER BY t DESC", $w);
    $data['tillProducts'] = $one("SELECT i.product_name, SUM(i.qty) qty, SUM(i.line_total) revenue
        FROM pos_sale_items i JOIN pos_sales s ON s.id = i.sale_id
        WHERE s.status = 'complete' AND COALESCE(s.completed_at, s.created_at) >= :from AND COALESCE(s.completed_at, s.created_at) < :to
        GROUP BY i.product_name ORDER BY qty DESC LIMIT 10", $w);

    // Sponsors: income by payment method, and what is currently agreed.
    $data['sponsorMethods'] = $one("SELECT COALESCE(NULLIF(method,''),'Other') method, COUNT(*) n, SUM(amount) t FROM (
            SELECT method, amount FROM sponsorship_payments WHERE paid_at >= :from AND paid_at < :to
            UNION ALL
            SELECT method, amount FROM match_sponsorship_payments WHERE paid_at >= :from2 AND paid_at < :to2
        ) p GROUP BY method ORDER BY t DESC", $w + [':from2' => $from, ':to2' => $to]);
    $data['agreements'] = $one("SELECT COUNT(*) n, COALESCE(SUM(agreed_amount),0) t FROM sponsorship_agreements WHERE status = 'active'")[0] ?? ['n' => 0, 't' => 0];

    // Social posting.
    $data['social'] = $one("SELECT platform, status, COUNT(*) n FROM social_posts
        WHERE COALESCE(published_at, created_at) >= :from AND COALESCE(published_at, created_at) < :to
        GROUP BY platform, status", $w);
    $data['socialDrafts'] = (int) ($one("SELECT COUNT(*) n FROM social_posts WHERE status = 'draft'")[0]['n'] ?? 0);

    // News: how much was published, and titles by slug so GA page paths can be named.
    $data['newsPublished'] = (int) ($one("SELECT COUNT(*) n FROM news_articles WHERE status = 'published' AND published_at >= :from AND published_at < :to", $w)[0]['n'] ?? 0);
    $data['newsTitles'] = [];
    foreach ($one("SELECT slug, title FROM news_articles WHERE status = 'published'") as $row) {
        $data['newsTitles'][(string) $row['slug']] = (string) $row['title'];
    }
    return $data;
}

// ---------------------------------------------------------------------
// Page speed (written by cron_speed_check.php)
// ---------------------------------------------------------------------

/**
 * Google's published thresholds: [good up to, slow above]. Times in ms, CLS unitless.
 * Between the two numbers a metric "needs work".
 */
const AX_SPEED_THRESHOLDS = [
    'lcp' => [2500, 4000],
    'fcp' => [1800, 3000],
    'cls' => [0.1, 0.25],
    'tbt' => [200, 600],
    'ttfb' => [800, 1800],
];

/** @return array{runs: list<array<string, mixed>>} */
function ax_speed_history(): array
{
    $file = __DIR__ . '/../../../secrets/speed_history.json';
    if (!is_file($file)) {
        return ['runs' => []];
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    return ['runs' => is_array($decoded['runs'] ?? null) ? $decoded['runs'] : []];
}

/** 'good' | 'ni' (needs work) | 'poor' | 'none' */
function ax_speed_rating(string $metric, int|float|null $value): string
{
    if ($value === null || !isset(AX_SPEED_THRESHOLDS[$metric])) {
        return 'none';
    }
    [$good, $poor] = AX_SPEED_THRESHOLDS[$metric];
    return $value <= $good ? 'good' : ($value <= $poor ? 'ni' : 'poor');
}

/** Human text for a threshold, used in the table tooltips. */
function ax_speed_threshold_text(string $metric, int $which): string
{
    $v = AX_SPEED_THRESHOLDS[$metric][$which];
    if ($metric === 'cls') {
        return ($which === 0 ? 'up to ' : 'over ') . $v;
    }
    if ($metric === 'tbt') {
        return ($which === 0 ? 'up to ' : 'over ') . $v . ' ms';
    }
    return ($which === 0 ? 'up to ' : 'over ') . rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . ' s';
}

/** Table columns for the speed widget. */
function ax_speed_columns(): array
{
    $defs = [
        'lcp' => ['Main content', 'Largest contentful paint: when the biggest thing on the page (usually a photo or heading) appears.'],
        'fcp' => ['First paint', 'First contentful paint: when anything at all first appears.'],
        'cls' => ['Layout shift', 'Cumulative layout shift: how much the page jumps around while loading.'],
        'tbt' => ['Blocking time', 'Total blocking time: how long the page is too busy to respond to taps.'],
        'ttfb' => ['Server reply', 'Time to first byte: how quickly the server starts answering.'],
    ];
    $out = [];
    foreach ($defs as $key => [$label, $help]) {
        $out[] = ['key' => $key, 'label' => $label, 'help' => $help, 'good' => ax_speed_threshold_text($key, 0), 'poor' => ax_speed_threshold_text($key, 1)];
    }
    return $out;
}

/**
 * Latest results shaped for the speed widget, plus the home-page timings over time.
 *
 * @return array{cfg: array<string, mixed>, trend: array{labels: list<string>, series: array<string, list<float|null>>}, latest: ?array<string, mixed>}
 */
function ax_speed_view(): array
{
    $runs = ax_speed_history()['runs'];
    $latest = $runs === [] ? null : end($runs);
    $strategies = ['mobile' => [], 'desktop' => []];
    $label = static fn(string $path): string => $path === '/' ? 'Home page' : ucfirst(trim($path, '/'));
    if ($latest !== null) {
        foreach ($latest['pages'] ?? [] as $path => $byStrategy) {
            foreach (['mobile', 'desktop'] as $strategy) {
                $m = $byStrategy[$strategy] ?? null;
                if (!is_array($m)) {
                    continue;
                }
                $ratings = [];
                foreach (array_keys(AX_SPEED_THRESHOLDS) as $metric) {
                    $ratings[$metric] = ax_speed_rating($metric, $m[$metric] ?? null);
                }
                $strategies[$strategy][] = ['path' => (string) $path, 'label' => $label((string) $path), 'metrics' => $m, 'ratings' => $ratings];
            }
        }
    }
    $trend = ['labels' => [], 'series' => ['lcp' => [], 'fcp' => [], 'ttfb' => []]];
    foreach ($runs as $run) {
        $home = $run['pages']['/']['mobile'] ?? null;
        if (!is_array($home)) {
            continue;
        }
        $trend['labels'][] = date('j M', (int) strtotime((string) $run['at']));
        foreach (array_keys($trend['series']) as $k) {
            $trend['series'][$k][] = isset($home[$k]) ? round((float) $home[$k] / 1000, 2) : null;
        }
    }
    return [
        'cfg' => [
            'columns' => ax_speed_columns(),
            'strategies' => $strategies,
            'checkedAt' => $latest !== null ? date('j M, H:i', (int) strtotime((string) $latest['at'])) : '',
        ],
        'trend' => $trend,
        'latest' => $latest,
    ];
}
