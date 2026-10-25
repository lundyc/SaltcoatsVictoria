<?php

declare(strict_types=1);

/*
 * Short-lived whole-page cache for anonymous visitors.
 *
 * Only plain read-only routes are cached (see page_cache_path_ok()). A request
 * is served from the cache only when it is a GET with no query string and no
 * PHPSESSID / sv_mbr cookie, i.e. the same visitors who already receive the
 * public, cookie-free response. Entries expire after PAGE_CACHE_TTL seconds,
 * which is also how edits made in the Hub reach the public site.
 *
 * Delete the cache/pages directory to switch it off instantly.
 */

const PAGE_CACHE_TTL = 60;

function page_cache_request_path(): string
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    return $path === '' ? '/' : $path;
}

function page_cache_path_ok(string $path): bool
{
    if ($path === '/') {
        return true;
    }
    return (bool) preg_match(
        '#^/(?:news(?:/[a-z0-9-]+|/category/[a-z0-9-]+)?|fixtures|results|table|match/\d+|team(?:/[a-z0-9-]+)?|staff|gallery(?:/[a-z0-9-]+)?|partners|privacy|club/[a-z0-9/-]+)$#i',
        $path
    );
}

function page_cache_eligible_request(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
        && ($_SERVER['QUERY_STRING'] ?? '') === ''
        && !isset($_COOKIE['PHPSESSID']) && !isset($_COOKIE['sv_mbr'])
        && page_cache_path_ok(page_cache_request_path());
}

function page_cache_file(): string
{
    $key = sha1(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) . '|' . page_cache_request_path());
    return __DIR__ . '/../cache/pages/' . $key . '.html';
}

function page_cache_serve(): void
{
    if (!page_cache_eligible_request()) {
        return;
    }
    $file = page_cache_file();
    $mtime = @filemtime($file);
    if ($mtime === false || time() - $mtime >= PAGE_CACHE_TTL) {
        return;
    }
    $body = @file_get_contents($file);
    if ($body === false || $body === '') {
        return;
    }
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    header('X-Page-Cache: HIT');
    echo $body;
    exit;
}

/** Store a rendered page. Call only for responses already judged shareable. */
function page_cache_store(string $html): void
{
    if (!page_cache_eligible_request() || $html === '') {
        return;
    }
    $file = page_cache_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $html) !== false) {
        @rename($tmp, $file);
    } else {
        @unlink($tmp);
    }
}
