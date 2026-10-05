<?php

declare(strict_types=1);

/**
 * Read-only integration with Google Analytics Data API (GA4) and Search
 * Console API, authenticated via a service account. Powers the Site
 * Analytics / SEO tabs on developer_analytics.php so nobody needs to visit
 * analytics.google.com or search.google.com/search-console directly.
 *
 * Credentials and config live outside the web root — see
 * /var/www/vhosts/myclubhub.co.uk/secrets/.
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

/** GA4 date range string GA's API expects, from a day count. */
function google_reporting_date_range(int $days): array
{
    return ['startDate' => $days . 'daysAgo', 'endDate' => 'today'];
}

// ---------------------------------------------------------------------
// GA4 report builders
// ---------------------------------------------------------------------

function ga4_summary(int $days): array
{
    return ga4_run_report([
        'dateRanges' => [google_reporting_date_range($days)],
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

function ga4_top_pages(int $days, int $limit = 15): array
{
    return ga4_run_report([
        'dateRanges' => [google_reporting_date_range($days)],
        'dimensions' => [['name' => 'pagePath'], ['name' => 'pageTitle']],
        'metrics' => [['name' => 'screenPageViews'], ['name' => 'activeUsers'], ['name' => 'averageSessionDuration']],
        'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
        'limit' => $limit,
    ]);
}

function ga4_traffic_sources(int $days): array
{
    return ga4_run_report([
        'dateRanges' => [google_reporting_date_range($days)],
        'dimensions' => [['name' => 'sessionDefaultChannelGroup']],
        'metrics' => [['name' => 'sessions'], ['name' => 'activeUsers']],
        'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
    ]);
}

function ga4_devices(int $days): array
{
    return ga4_run_report([
        'dateRanges' => [google_reporting_date_range($days)],
        'dimensions' => [['name' => 'deviceCategory']],
        'metrics' => [['name' => 'sessions'], ['name' => 'activeUsers']],
        'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]],
    ]);
}

function ga4_daily_trend(int $days): array
{
    return ga4_run_report([
        'dateRanges' => [google_reporting_date_range($days)],
        'dimensions' => [['name' => 'date']],
        'metrics' => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
        'orderBys' => [['dimension' => ['dimensionName' => 'date']]],
    ]);
}

// ---------------------------------------------------------------------
// Search Console report builders
// ---------------------------------------------------------------------

function gsc_date_range(int $days): array
{
    return [
        'startDate' => date('Y-m-d', strtotime("-{$days} days")),
        'endDate' => date('Y-m-d', strtotime('-2 days')), // GSC data lags ~2 days
    ];
}

function gsc_summary(int $days): array
{
    $range = gsc_date_range($days);
    return gsc_query(['startDate' => $range['startDate'], 'endDate' => $range['endDate']]);
}

function gsc_top_queries(int $days, int $limit = 15): array
{
    $range = gsc_date_range($days);
    return gsc_query([
        'startDate' => $range['startDate'],
        'endDate' => $range['endDate'],
        'dimensions' => ['query'],
        'rowLimit' => $limit,
    ]);
}

function gsc_top_pages(int $days, int $limit = 15): array
{
    $range = gsc_date_range($days);
    return gsc_query([
        'startDate' => $range['startDate'],
        'endDate' => $range['endDate'],
        'dimensions' => ['page'],
        'rowLimit' => $limit,
    ]);
}

function gsc_daily_trend(int $days): array
{
    $range = gsc_date_range($days);
    return gsc_query([
        'startDate' => $range['startDate'],
        'endDate' => $range['endDate'],
        'dimensions' => ['date'],
    ]);
}
