<?php

declare(strict_types=1);

/*
 * View + request helpers for the public site. All output-facing strings run
 * through e(); URLs run through url()/asset()/uploads() so the PUBLIC_BASE
 * move is a one-line config change.
 */

/** The shared PDO handle from bootstrap.php. */
function db(): PDO
{
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'];
    return $pdo;
}

/** A club configuration value (site_settings, with shipped defaults). */
function club(string $key, string $default = ''): string
{
    $value = $GLOBALS['pub_settings'][$key] ?? null;
    return $value !== null && $value !== '' ? (string) $value : $default;
}

/** The club crest. The full-colour crest is used on every surface. */
function club_crest(): string
{
    return club('crest_url', '/uploads/club/crest-colour.png');
}

/** Kept so existing call sites work; the club uses one crest everywhere. */
function club_crest_reverse(): string
{
    return club_crest();
}

/** HTML-escape for text/attribute context. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an internal URL, mount-point aware. url() === site root. */
function url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = PUBLIC_BASE === '' ? '' : PUBLIC_BASE;
    if ($path === '') {
        return $base . '/';
    }
    return $base . '/' . $path;
}

/** URL for a bundled asset, with an mtime cache-buster. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = PUBLIC_ROOT . '/assets/' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return url('assets/' . $path) . '?v=' . $version;
}

/** URL for user-uploaded media. Path segments are individually encoded because
 *  legacy filenames contain spaces ("Bobbys Bar.png"). */
function uploads(string $path): string
{
    $segments = array_map('rawurlencode', explode('/', trim($path, '/')));
    return UPLOADS_BASE . '/' . implode('/', $segments);
}

/** Scheme + host for the current request, e.g. https://example.com */
function current_url_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Absolute URL for the current request (for canonical / og:url). */
function current_url(): string
{
    $uri = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    return current_url_origin() . $uri;
}

/**
 * Per-request metadata store, read by partials/head.php.
 * @param array<string,string> $values
 */
function set_meta(array $values): void
{
    $GLOBALS['pub_meta'] = array_merge($GLOBALS['pub_meta'] ?? [], $values);
}

function meta(string $key, string $default = ''): string
{
    return (string) ($GLOBALS['pub_meta'][$key] ?? $default);
}

/** Mark the current response as raw (no site chrome): sitemaps, feeds. */
function pub_raw(bool $on = true): void
{
    $GLOBALS['pub_raw'] = $on;
}

/** Attach a JSON-LD document to the page head (call repeatedly for several). @param array<string,mixed> $data */
function pub_jsonld(array $data): void
{
    $GLOBALS['pub_jsonld_docs'][] = ['@context' => 'https://schema.org'] + $data;
}

/** Path of the matched route (no base, no slashes), set by the router. */
function current_route(): string
{
    return (string) ($GLOBALS['pub_route'] ?? '');
}

/** Is the current route within this section? nav_active('club') etc. */
function nav_active(string $prefix): bool
{
    $route = current_route();
    return $route === $prefix || str_starts_with($route, $prefix . '/');
}

/**
 * Published club_pages as [slug => label], for building menus without
 * hard-coding pages that may be unpublished. Pass $only to restrict to (and
 * order by) an explicit slug list; the "privacy" slug keeps its own /privacy
 * route, every other slug lives at /club/<slug>.
 *
 * @param list<string>|null $only
 * @return array<string,string>
 */
function club_menu_pages(?array $only = null): array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        try {
            foreach (club_pages_menu(db()) as $row) {
                $all[(string) $row['slug']] = (string) $row['label'];
            }
        } catch (Throwable) {
            $all = [];
        }
    }
    if ($only === null) {
        return $all;
    }
    $out = [];
    foreach ($only as $slug) {
        if (isset($all[$slug])) {
            $out[$slug] = $all[$slug];
        }
    }
    return $out;
}

/** Public URL for a club_pages slug ("privacy" has its own route). */
function club_page_url(string $slug): string
{
    return $slug === 'privacy' ? url('privacy') : url('club/' . $slug);
}

/** Normalise a heading for loose comparison: "Mission, Vision & Values" -> "missionvisionandvalues". */
function pub_normalise_heading(string $s): string
{
    $s = strtolower(html_entity_decode($s, ENT_QUOTES | ENT_HTML5));
    $s = str_replace('&', 'and', $s);
    return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
}

/**
 * Rendered club-page HTML with the leading heading(s) that merely repeat the
 * page title stripped — old-CMS imports carry a panel heading AND a content
 * heading, so the title otherwise prints two or three times under the <h1>.
 */
function pub_club_page_html(array $page): string
{
    $html  = club_page_render($page); // sanitised (HTMLPurifier already drops the panel <div>s)
    $title = pub_normalise_heading((string) ($page['title'] ?? ''));
    if ($title === '' || trim($html) === '' || !class_exists('DOMDocument')) {
        return $html;
    }

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML(
        '<?xml encoding="utf-8"?><div id="pcp-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    $root = $doc->getElementById('pcp-root');
    if (!$root) {
        return $html;
    }

    while ($root->firstChild) {
        $node = $root->firstChild;
        if ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) === '') {
            $root->removeChild($node);
            continue;
        }
        if ($node->nodeType === XML_ELEMENT_NODE
            && in_array(strtolower($node->nodeName), ['h1', 'h2', 'h3', 'h4'], true)
            && pub_normalise_heading($node->textContent) === $title
        ) {
            $root->removeChild($node);
            continue;
        }
        break;
    }

    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out) !== '' ? trim($out) : $html;
}

/** Plain-text summary from a possibly-HTML string. */
function excerpt(string $text, int $length = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    $clip = mb_substr($text, 0, $length);
    $lastSpace = mb_strrpos($clip, ' ');
    if ($lastSpace !== false && $lastSpace > 0) {
        $clip = mb_substr($clip, 0, $lastSpace);
    }
    return rtrim($clip, " ,.;:-") . '…';
}

function format_date(?string $value, string $fmt = 'j M Y'): string
{
    $value = trim((string) $value);
    if ($value === '' || $value === '0000-00-00') {
        return '';
    }
    $ts = strtotime($value);
    return $ts === false ? $value : date($fmt, $ts);
}

function format_time(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    $ts = strtotime($value);
    return $ts === false ? $value : date('g:i a', $ts);
}

/**
 * File-backed fragment cache. Returns the cached string if fresh, otherwise
 * runs $producer, stores its return value and hands it back.
 */
function cache_remember(string $key, int $ttlSeconds, callable $producer): string
{
    $file = PUBLIC_CACHE_DIR . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $key) . '.cache';
    if (is_file($file) && (time() - (int) filemtime($file)) < $ttlSeconds) {
        $cached = file_get_contents($file);
        if ($cached !== false) {
            return $cached;
        }
    }
    $value = (string) $producer();
    if (is_dir(PUBLIC_CACHE_DIR) && is_writable(PUBLIC_CACHE_DIR)) {
        @file_put_contents($file, $value, LOCK_EX);
    }
    return $value;
}

/**
 * Render a partial from public/partials/. Data keys become local variables.
 * @param array<string,mixed> $data
 */
function partial(string $name, array $data = []): void
{
    $file = PUBLIC_ROOT . '/partials/' . $name . '.php';
    if (!is_file($file)) {
        return;
    }
    (static function () use ($file, $data): void {
        extract($data, EXTR_SKIP);
        require $file;
    })();
}

function redirect(string $to, int $status = 302): never
{
    header('Location: ' . $to, true, $status);
    exit;
}

/** Inline SVG icon (brand + UI). Returns '' for an unknown name. */
function pub_icon(string $name): string
{
    $paths = [
        'facebook'  => '<path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.2c-1.2 0-1.6.75-1.6 1.5V12h2.7l-.43 2.9h-2.3v7A10 10 0 0 0 22 12Z"/>',
        'twitter'   => '<path d="M18.9 2H22l-7 8 8.3 12H17l-5-7.3L6.2 22H3l7.5-8.6L2.5 2H9l4.6 6.8L18.9 2Zm-1.1 18h1.8L7.3 4H5.4l12.4 16Z"/>',
        'instagram' => '<path d="M12 4.5c2.5 0 2.8 0 3.8.06 2.5.1 3.6 1.3 3.7 3.7.05 1 .06 1.3.06 3.7s0 2.8-.06 3.7c-.1 2.4-1.2 3.6-3.7 3.7-1 .06-1.3.06-3.8.06s-2.8 0-3.8-.06c-2.5-.1-3.6-1.3-3.7-3.7C4.5 14.8 4.5 14.5 4.5 12s0-2.8.06-3.7C4.66 5.9 5.8 4.7 8.2 4.6c1-.06 1.3-.06 3.8-.06ZM12 2.7c-2.5 0-2.9 0-3.9.07C4.7 2.9 2.9 4.7 2.8 8.1c-.05 1-.06 1.4-.06 3.9s0 2.9.06 3.9C2.9 19.3 4.7 21.1 8.1 21.2c1 .06 1.4.07 3.9.07s2.9 0 3.9-.07c3.4-.15 5.2-1.95 5.3-5.3.06-1 .07-1.4.07-3.9s0-2.9-.07-3.9c-.15-3.4-1.95-5.2-5.3-5.3-1-.06-1.4-.07-3.9-.07Zm0 4.5A4.8 4.8 0 1 0 12 16.8 4.8 4.8 0 0 0 12 7.2Zm0 7.9A3.1 3.1 0 1 1 12 8.9a3.1 3.1 0 0 1 0 6.2Zm4.9-8.2a1.1 1.1 0 1 0 0 2.3 1.1 1.1 0 0 0 0-2.3Z"/>',
        'youtube'   => '<path d="M23 12s0-3.2-.4-4.7c-.24-.85-.9-1.5-1.75-1.74C19.35 5.2 12 5.2 12 5.2s-7.35 0-8.85.36c-.85.24-1.5.9-1.75 1.74C1 8.8 1 12 1 12s0 3.2.4 4.7c.24.85.9 1.5 1.75 1.74 1.5.36 8.85.36 8.85.36s7.35 0 8.85-.36c.85-.24 1.5-.9 1.75-1.74C23 15.2 23 12 23 12Zm-13 3.1V8.9l5.2 3.1-5.2 3.1Z"/>',
        'menu'      => '<path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>',
        'close'     => '<path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>',
        'chevron'   => '<path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>',
        'search'    => '<circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16 16l4.5 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>',
        'basket'    => '<path d="M5.5 8h13l-1.2 10.2a2 2 0 0 1-2 1.8H8.7a2 2 0 0 1-2-1.8L5.5 8Zm3-.5 3-4 3 4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/>',
    ];
    if (!isset($paths[$name])) {
        return '';
    }
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}

/** @return array<string,string> non-empty social links (network => url). */
function pub_social_links(): array
{
    $links = [];
    foreach (['facebook', 'twitter', 'instagram', 'youtube'] as $network) {
        $url = club('social_' . $network);
        if ($url !== '') {
            $links[$network] = $url;
        }
    }
    return $links;
}

/**
 * Render a matched route: run the page (buffered, so it can set_meta()), then
 * wrap it in the head + footer chrome.
 *
 * @param array{page:string,status?:int} $handler
 * @param array<string,mixed>            $params
 */
function public_render(array $handler, array $params): void
{
    $page = $handler['page'];
    $status = $handler['status'] ?? 200;
    http_response_code($status);

    $file = PUBLIC_ROOT . '/pages/' . $page . '.php';
    if (!is_file($file)) {
        $file = PUBLIC_ROOT . '/pages/error.php';
        $params = ['title' => 'Page not found'];
        http_response_code(404);
    }

    $content = (static function () use ($file, $params): string {
        extract($params, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    })();

    // Raw responses (sitemap, feeds) send their own headers and body.
    if (!empty($GLOBALS['pub_raw'])) {
        echo $content;
        return;
    }

    pub_send_cache_headers($status);

    ob_start();
    require PUBLIC_ROOT . '/partials/head.php';
    echo $content;
    require PUBLIC_ROOT . '/partials/site_footer.php';
    echo imgopt_filter_html((string) ob_get_clean());
}


/**
 * Anonymous, read-only pages may be cached briefly by browsers/CDNs. Anything
 * with a form/CSRF token, basket, order or a logged-in visitor stays no-store.
 */
function pub_send_cache_headers(int $status): void
{
    if ($status !== 200 || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || headers_sent()) {
        return;
    }
    $route = current_route();
    if ($route === 'contact' || preg_match('#^(shop|tickets)/(basket|checkout|order)#', $route)
        || $route === 'tickets' || $route === 'shop' || str_starts_with($route, 'shop/p/')
        || !empty($_SESSION['hub_user_id']) || !empty($_SESSION['user_id']) || !empty($_SESSION['shop_basket'])) {
        return;
    }
    header_remove('Set-Cookie');
    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    header_remove('Pragma');
    header_remove('Expires');
}

/** Generated square icon (uploads/club/icon-{n}.png) if present, else the crest. */
function pub_icon_url(int $size): string
{
    $rel = '/uploads/club/icon-' . $size . '.png';
    return is_file(PUBLIC_ROOT . $rel) ? $rel : club_crest();
}
