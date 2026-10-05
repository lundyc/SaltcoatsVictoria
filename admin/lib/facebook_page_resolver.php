<?php

declare(strict_types=1);

/**
 * Normalise a user-entered Facebook Page URL and reject non-Facebook hosts.
 */
function sponsor_normalise_facebook_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }

    $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
    if ($host === '' || ($host !== 'facebook.com' && !str_ends_with($host, '.facebook.com'))) {
        return null;
    }

    $path = (string)($parts['path'] ?? '/');
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';

    return 'https://www.facebook.com' . ($path !== '' ? $path : '/') . $query;
}

/**
 * Read a numeric ID already embedded in a Facebook URL.
 */
function sponsor_facebook_id_from_url(string $url): ?string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }

    parse_str((string)($parts['query'] ?? ''), $query);
    $queryId = trim((string)($query['id'] ?? ''));
    if (preg_match('/^[0-9]{5,30}$/', $queryId) === 1) {
        return $queryId;
    }

    $segments = array_values(array_filter(explode('/', trim((string)($parts['path'] ?? ''), '/')), 'strlen'));
    if (count($segments) >= 2) {
        $lastSegment = (string)end($segments);
        if (preg_match('/^[0-9]{5,30}$/', $lastSegment) === 1) {
            return $lastSegment;
        }
    }

    return null;
}

/**
 * Resolve a public Facebook vanity URL using the profile metadata Facebook
 * exposes to link-preview crawlers.
 *
 * @return array{id: string, url: string, name: string}|null
 */
function sponsor_resolve_facebook_page(string $inputUrl): ?array
{
    $url = sponsor_normalise_facebook_url($inputUrl);
    if ($url === null) {
        return null;
    }

    $directId = sponsor_facebook_id_from_url($url);
    $directResult = $directId !== null ? ['id' => $directId, 'url' => $url, 'name' => ''] : null;

    if (!function_exists('curl_init')) {
        return $directResult;
    }

    $body = '';
    $maximumBytes = 2 * 1024 * 1024;
    $ch = curl_init($url);
    if ($ch === false) {
        return $directResult;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'facebookexternalhit/1.1 (+https://www.facebook.com/externalhit_uatext.php)',
        CURLOPT_HTTPHEADER => ['Accept-Language: en-GB,en;q=0.9'],
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $maximumBytes): int {
            $remaining = $maximumBytes - strlen($body);
            if ($remaining > 0) {
                $body .= substr($chunk, 0, $remaining);
            }
            return strlen($chunk);
        },
    ]);

    $success = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    $effectiveHost = strtolower((string)parse_url($effectiveUrl, PHP_URL_HOST));
    if (
        $success === false
        || $status < 200
        || $status >= 400
        || ($effectiveHost !== 'facebook.com' && !str_ends_with($effectiveHost, '.facebook.com'))
        || $body === ''
    ) {
        return $directResult;
    }

    $pageName = '';
    $titlePatterns = [
        '~<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)~i',
        '~<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']~i',
    ];
    foreach ($titlePatterns as $titlePattern) {
        if (preg_match($titlePattern, $body, $titleMatch) === 1) {
            $pageName = trim(html_entity_decode($titleMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $pageName = trim((string)preg_replace('/\s+\|\s+[^|]+$/u', '', $pageName));
            break;
        }
    }

    $patterns = [
        '~<meta[^>]+property=["\']al:android:url["\'][^>]+content=["\']fb://profile/([0-9]{5,30})~i',
        '~<meta[^>]+content=["\']fb://profile/([0-9]{5,30})["\'][^>]+property=["\']al:android:url["\']~i',
        '~fb://profile/([0-9]{5,30})~i',
        '~["\'](?:pageID|page_id|profile_id|entity_id|userID)["\']\s*:\s*["\']([0-9]{5,30})["\']~i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $body, $matches) === 1) {
            return ['id' => $matches[1], 'url' => $url, 'name' => $pageName];
        }
    }

    if ($directResult !== null) {
        $directResult['name'] = $pageName;
    }
    return $directResult;
}
