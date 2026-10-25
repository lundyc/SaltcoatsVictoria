<?php

declare(strict_types=1);

/**
 * Read-only integration with Google Analytics Data API (GA4) and Search
 * Console API, authenticated via a service account. Powers the Site
 * Analytics / SEO tabs on developer_analytics.php so nobody needs to visit
 * analytics.google.com or search.google.com/search-console directly.
 *
 * Credentials and config live outside the web root — see
 * /var/www/vhosts/saltcoatsvictoria.co.uk/secrets/.
 */

function google_reporting_config(): array
{
    static $config = null;
    if ($config === null) {
        // __DIR__ is httpdocs/admin/lib — the secrets dir sits three levels
        // up, outside the web root, alongside the vhost's httpdocs folder.
        $path = __DIR__ . '/../../../secrets/google_config.php';
        $config = is_file($path) ? require $path : [];
    }
    return $config;
}

function google_reporting_enabled(): bool
{
    $config = google_reporting_config();
    return !empty($config['service_account_key_file'])
        && is_file((string) $config['service_account_key_file'])
        && !empty($config['ga4_property_id'])
        && !empty($config['search_console_site_url']);
}

function google_reporting_b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Cached-to-disk OAuth2 access token for a service account, per scope. */
function google_reporting_access_token(string $scope): string
{
    $config = google_reporting_config();
    $cacheDir = (string) ($config['cache_dir'] ?? '');
    $cacheFile = $cacheDir . '/token_' . md5($scope) . '.json';

    if (is_file($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60) {
            return (string) $cached['access_token'];
        }
    }

    $key = json_decode((string) file_get_contents((string) $config['service_account_key_file']), true);
    if (!is_array($key)) {
        throw new RuntimeException('Google service account key is unreadable.');
    }

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claim = [
        'iss' => $key['client_email'],
        'scope' => $scope,
        'aud' => $key['token_uri'],
        'iat' => $now,
        'exp' => $now + 3600,
    ];
    $segments = [
        google_reporting_b64url((string) json_encode($header)),
        google_reporting_b64url((string) json_encode($claim)),
    ];
    $signInput = implode('.', $segments);
    openssl_sign($signInput, $signature, (string) $key['private_key'], 'sha256WithRSAEncryption');
    $segments[] = google_reporting_b64url($signature);
    $jwt = implode('.', $segments);

    $response = google_reporting_http_post((string) $key['token_uri'], [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ], form: true);

    $token = (string) ($response['access_token'] ?? '');
    if ($token === '') {
        throw new RuntimeException('Google token request failed: ' . json_encode($response));
    }

    if ($cacheDir !== '' && is_dir($cacheDir)) {
        file_put_contents($cacheFile, json_encode([
            'access_token' => $token,
            'expires_at' => $now + (int) ($response['expires_in'] ?? 3600),
        ]));
        chmod($cacheFile, 0600);
    }

    return $token;
}

function google_reporting_http_post(string $url, array $body, bool $form = false, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $form ? http_build_query($body) : (string) json_encode($body),
        CURLOPT_HTTPHEADER => $form ? $headers : array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        throw new RuntimeException('Google API request failed: ' . $err);
    }
    return json_decode($res, true) ?? [];
}

/** Response cache so repeated tab/date-range clicks don't re-hit Google every time. */
function google_reporting_cached(string $cacheKey, callable $fetch, int $ttlSeconds = 900): array
{
    $config = google_reporting_config();
    $cacheDir = (string) ($config['cache_dir'] ?? '');
    $cacheFile = $cacheDir . '/report_' . md5($cacheKey) . '.json';

    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttlSeconds) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $result = $fetch();

    if ($cacheDir !== '' && is_dir($cacheDir)) {
        file_put_contents($cacheFile, json_encode($result));
        chmod($cacheFile, 0600);
    }

    return $result;
}

/** @return array{rows: array<int, array<string, string>>, error?: string} */
function ga4_run_report(array $body): array
{
    $config = google_reporting_config();
    $cacheKey = 'ga4:' . $config['ga4_property_id'] . ':' . json_encode($body);

    return google_reporting_cached($cacheKey, function () use ($config, $body): array {
        try {
            $token = google_reporting_access_token('https://www.googleapis.com/auth/analytics.readonly');
            $response = google_reporting_http_post(
                "https://analyticsdata.googleapis.com/v1beta/properties/{$config['ga4_property_id']}:runReport",
                $body,
                headers: ['Authorization: Bearer ' . $token]
            );
            if (isset($response['error'])) {
                return ['rows' => [], 'error' => (string) ($response['error']['message'] ?? 'Unknown GA4 error')];
            }
            return google_reporting_shape_ga4_response($response);
        } catch (Throwable $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }
    });
}

function google_reporting_shape_ga4_response(array $response): array
{
    $dimNames = array_map(static fn(array $h): string => (string) $h['name'], $response['dimensionHeaders'] ?? []);
    $metNames = array_map(static fn(array $h): string => (string) $h['name'], $response['metricHeaders'] ?? []);

    $rows = [];
    foreach ($response['rows'] ?? [] as $row) {
        $shaped = [];
        foreach ($dimNames as $i => $name) {
            $shaped[$name] = (string) ($row['dimensionValues'][$i]['value'] ?? '');
        }
        foreach ($metNames as $i => $name) {
            $shaped[$name] = (string) ($row['metricValues'][$i]['value'] ?? '0');
        }
        $rows[] = $shaped;
    }

    $totals = [];
    foreach ($response['totals'][0]['metricValues'] ?? [] as $i => $val) {
        $totals[$metNames[$i]] = (string) ($val['value'] ?? '0');
    }

    return ['rows' => $rows, 'totals' => $totals];
}

/** @return array{rows: array<int, array<string, string>>, error?: string} */
function gsc_query(array $body): array
{
    $config = google_reporting_config();
    $siteUrl = (string) $config['search_console_site_url'];
    $cacheKey = 'gsc:' . $siteUrl . ':' . json_encode($body);

    return google_reporting_cached($cacheKey, function () use ($siteUrl, $body): array {
        try {
            $token = google_reporting_access_token('https://www.googleapis.com/auth/webmasters.readonly');
            $response = google_reporting_http_post(
                'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query',
                $body,
                headers: ['Authorization: Bearer ' . $token]
            );
            if (isset($response['error'])) {
                return ['rows' => [], 'error' => (string) ($response['error']['message'] ?? 'Unknown Search Console error')];
            }
            return ['rows' => $response['rows'] ?? []];
        } catch (Throwable $e) {
            return ['rows' => [], 'error' => $e->getMessage()];
        }
    });
}

/**
 * Public base URL of the reported site, derived from the Search Console
 * property ("sc-domain:example.com" or a URL-prefix property).
 */
function google_reporting_site_base(): string
{
    $site = (string) (google_reporting_config()['search_console_site_url'] ?? '');
    if (str_starts_with($site, 'sc-domain:')) {
        return 'https://' . substr($site, strlen('sc-domain:'));
    }
    return rtrim($site, '/');
}

/**
 * Validate a custom ?from=YYYY-MM-DD&to=YYYY-MM-DD range (max 365 days,
 * not in the future). Returns null when absent or invalid so callers fall
 * back to the preset ?days= range.
 *
 * @return array{from: string, to: string, days: int}|null
 */
function google_reporting_custom_range(array $get): ?array
{
    $from = (string) ($get['from'] ?? '');
    $to = (string) ($get['to'] ?? '');
    foreach ([$from, $to] as $value) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            return null;
        }
    }
    $today = date('Y-m-d');
    if ($to > $today) {
        $to = $today;
    }
    if ($from > $to) {
        return null;
    }
    $span = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
    if ($span > 365) {
        return null;
    }
    return ['from' => $from, 'to' => $to, 'days' => $span];
}

/**
 * Normalise a range (day count, or ['from' => ..., 'to' => ...]) to explicit
 * dates. $lagDays pulls the end date back for sources that lag (Search
 * Console is ~2 days behind).
 *
 * @return array{from: string, to: string}
 */
function google_reporting_resolve_range(int|array $range, int $lagDays = 0): array
{
    $latest = date('Y-m-d', strtotime("-{$lagDays} days"));
    if (is_int($range)) {
        return ['from' => date('Y-m-d', strtotime("-{$range} days")), 'to' => $latest];
    }
    $to = min((string) $range['to'], $latest);
    $from = min((string) $range['from'], $to);
    return ['from' => $from, 'to' => $to];
}

/** The equal-length window immediately before $range. */
function google_reporting_previous_range(int|array $range, int $lagDays = 0): array
{
    $r = google_reporting_resolve_range($range, $lagDays);
    $length = (int) round((strtotime($r['to']) - strtotime($r['from'])) / 86400) + 1;
    $prevTo = date('Y-m-d', strtotime($r['from'] . ' -1 day'));
    $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($length - 1) . ' days'));
    return ['from' => $prevFrom, 'to' => $prevTo];
}

/** GA4 date range structure the API expects. */
function google_reporting_date_range(int|array $range): array
{
    $r = google_reporting_resolve_range($range);
    return ['startDate' => $r['from'], 'endDate' => $r['to']];
}

// ---------------------------------------------------------------------
// GA4 report builders
// ---------------------------------------------------------------------

function ga4_summary(int|array $range): array
{
    return ga4_run_report([
        'dateRanges' => [google_reporting_date_range($range)],
        'metrics' => [
            ['name' => 'screenPageViews'],
            ['name' => 'activeUsers'],
            ['name' => 'sessions'],
            ['name' => 'averageSessionDuration'],
            ['name' => 'bounceRate'],
            ['name' => 'newUsers'],
        ],
    ]);
}

function ga4_top_pages(int|array $range, int $limit = 50): array
{
    return ga4_run_report([
        'dateRanges' => [google_reporting_date_range($range)],
        'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
        'metrics' => [['name' => 'screenPageViews'], ['name' => 'activeUsers'], ['name' => 'averageSessionDuration']],
        'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
        'limit' => $limit,
    ]);
}

function ga4_traffic_sources(int|array $range): array
{
    return ga4_run_report([
        'dateRanges' => [google_reporting_date_range($range)],
        'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
        'metrics' => [['name' => 'sessions'], ['name' => 'activeUsers']],
        'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
    ]);
}

function ga4_devices(int|array $range): array
{
    return ga4_run_report([
        'dateRanges' => [google_reporting_date_range($range)],
        'dimensions' => [['name' => 'deviceCategory']],
        'metrics' => [['name' => 'sessions'], ['name' => 'activeUsers']],
        'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
    ]);
}

function ga4_daily_trend(int|array $range): array
{
    return ga4_run_report([
        'dateRanges' => [google_reporting_date_range($range)],
        'dimensions' => [['name' => 'date']],
        'metrics' => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
        'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
    ]);
}

// ---------------------------------------------------------------------
// Search Console report builders
// ---------------------------------------------------------------------

const GSC_LAG_DAYS = 2; // GSC data lags ~2 days

function gsc_date_range(int|array $range): array
{
    $r = google_reporting_resolve_range($range, GSC_LAG_DAYS);
    return ['startDate' => $r['from'], 'endDate' => $r['to']];
}

function gsc_summary(int|array $range): array
{
    return gsc_query(gsc_date_range($range));
}

function gsc_top_queries(int|array $range, int $limit = 50): array
{
    return gsc_query(gsc_date_range($range) + [
        'dimensions' => ['query'],
        'rowLimit' => $limit,
    ]);
}

function gsc_top_pages(int|array $range, int $limit = 50): array
{
    return gsc_query(gsc_date_range($range) + [
        'dimensions' => ['page'],
        'rowLimit' => $limit,
    ]);
}

function gsc_daily_trend(int|array $range): array
{
    return gsc_query(gsc_date_range($range) + [
        'dimensions' => ['date'],
    ]);
}
