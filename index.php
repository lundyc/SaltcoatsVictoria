<?php

declare(strict_types=1);

/*
 * Front controller for the public site. Every request that is not a real file
 * under public/ is rewritten here by .htaccess.
 */

/*
 * Page-cache fast path. Anonymous GET requests for plain read-only pages are
 * answered from a 60-second file copy written by public_render() (see
 * pub_page_cache_*() in helpers.php), skipping PHP bootstrap and the database.
 * Any visitor with a session or member cookie, or a query string, bypasses it.
 */
require __DIR__ . '/lib/page_cache.php';
page_cache_serve();

require __DIR__ . '/bootstrap.php';

// Optimised image variants: /img/{width}/uploads/... (see lib/imgopt.php).
if (preg_match('#^img(j?)/(\d+)/((?:uploads|badges)/.+)$#', public_request_path(), $im)) {
    header_remove('Set-Cookie');
    imgopt_serve((int) $im[2], $im[3], $im[1] === 'j' ? 'jpg' : 'webp');
}

[$handler, $params] = public_router_match(public_request_path());

/*
 * Back-compat: the Hub used to live at the web root, so old bookmarks and
 * external links point at flat /whatever.php paths. Bounce those to
 * /admin/whatever.php. The root .htaccess already does this, but a stale
 * nginx `try_files … /index.php` directive can shadow the Apache rule, so
 * the front controller owns the fallback too.
 */
if (($handler['status'] ?? 200) === 404) {
    $requested = public_request_path();
    if (preg_match('#^[A-Za-z0-9_-]+\.php$#', $requested) && !is_file(__DIR__ . '/' . $requested)) {
        header('Location: /admin/' . $requested, true, 301);
        exit;
    }
}

// Old saltcoatsvictoria.co.uk URLs (previous site) -> their new home.
if (($handler['status'] ?? 200) === 404) {
    $legacy = legacy_redirect_target(public_request_path(), $GLOBALS['pdo']);
    if ($legacy !== null) {
        header_remove('Set-Cookie');
        header('Location: ' . $legacy, true, 301);
        exit;
    }
}

// /RESULTS -> /results: only when the lower-cased path is a real route.
$reqPath = public_request_path();
if (($handler['status'] ?? 200) === 404 && $reqPath !== strtolower($reqPath)
    && (public_router_match(strtolower($reqPath))[0]['status'] ?? 404) === 200) {
    header('Location: /' . strtolower($reqPath), true, 301);
    exit;
}

/*
 * One URL per page: /team/ -> /team (301, query string preserved). Only for
 * matched public routes; the root and real files/directories never reach here.
 */
$rawPath = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
if ($rawPath !== '/' && str_ends_with($rawPath, '/') && ($handler['status'] ?? 200) === 200) {
    $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
    header('Location: ' . rtrim($rawPath, '/') . ($qs !== '' ? '?' . $qs : ''), true, 301);
    exit;
}

public_render($handler, $params);
