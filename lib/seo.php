<?php
/**
 * Central SEO engine for the public site.
 *
 * Pages describe themselves with set_meta() (title, description, image, og_type,
 * robots, canonical, title_full) and seo_breadcrumbs() / pub_jsonld(). This file
 * turns that into the final head data, so no page hand-writes canonical, robots,
 * Open Graph or Twitter markup. Everything club-specific comes from club()
 * settings — nothing here is tied to a particular club.
 */
declare(strict_types=1);

/** Query parameters that legitimately change page content (and so the canonical). */
const SEO_CANONICAL_PARAMS = ['page'];

/** Routes (prefix match) that must never be indexed: transactional / private / utility. */
const SEO_NOINDEX_ROUTES = [
    'shop/basket', 'shop/checkout', 'shop/order',
    'tickets/order', 'table/pdf',
];

/** Absolute URL for a site-relative path (or pass-through for absolute URLs). */
function seo_absolute(string $pathOrUrl): string
{
    if ($pathOrUrl === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $pathOrUrl)) {
        return $pathOrUrl;
    }
    return current_url_origin() . '/' . ltrim($pathOrUrl, '/');
}

/** Label of the current season (e.g. "2026/27"), '' when none is flagged. */
function seo_season_label(): string
{
    static $label = null;
    if ($label === null) {
        $label = '';
        try {
            foreach (pub_seasons() as $s) {
                if (!empty($s['is_current'])) {
                    $label = trim((string) $s['name']);
                    // "2026 / 2027" -> "2026/27" for compact, conventional titles.
                    if (preg_match('#^(\d{4})\s*[/-]\s*(?:\d{2})?(\d{2})$#', $label, $m)) {
                        $label = $m[1] . '/' . $m[2];
                    }
                    break;
                }
            }
        } catch (Throwable) {
            $label = '';
        }
    }
    return $label;
}

/**
 * Canonical URL for the current request: production origin + path without
 * trailing slash + only whitelisted query params (page>1). Tracking/filter
 * parameters never produce a distinct canonical. A page may override via
 * set_meta(['canonical' => ...]).
 */
function seo_canonical(): string
{
    $override = meta('canonical');
    if ($override !== '') {
        return seo_absolute($override);
    }
    $path = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $path = $path === '/' ? '/' : rtrim($path, '/');
    $keep = [];
    foreach (SEO_CANONICAL_PARAMS as $param) {
        $v = (int) ($_GET[$param] ?? 0);
        if ($v > 1) {
            $keep[$param] = $v;
        }
    }
    return current_url_origin() . $path . ($keep ? '?' . http_build_query($keep) : '');
}

/** Robots directive for this response. Explicit set_meta('robots') wins. */
function seo_robots(): string
{
    $explicit = meta('robots');
    if ($explicit !== '') {
        return $explicit;
    }
    if (http_response_code() >= 400) {
        return 'noindex, follow';
    }
    $route = current_route();
    foreach (SEO_NOINDEX_ROUTES as $prefix) {
        if ($route === $prefix || str_starts_with($route, $prefix . '/')) {
            return 'noindex, follow';
        }
    }
    return 'index, follow, max-image-preview:large';
}

/** ALL-CAPS headlines read as shouting in search results: title-case them (search/social only). */
function seo_tame_caps(string $title): string
{
    $letters = preg_replace('/[^\p{L}]/u', '', $title) ?? '';
    $upper = preg_replace('/[^\p{Lu}]/u', '', $title) ?? '';
    if (mb_strlen($letters) < 8 || mb_strlen($upper) / mb_strlen($letters) < 0.85) {
        return $title;
    }
    $t = mb_convert_case(mb_strtolower($title), MB_CASE_TITLE);
    $t = preg_replace('/\b(V|Vs)\b/', 'v', $t) ?? $t;
    return preg_replace_callback('/\b(Fc|Afc|Ym|Wosfl|Agm|Ko)\b/', static fn (array $m): string => strtoupper($m[1]), $t) ?? $t;
}

/** Full <title>: page title plus the club name unless already present. */
function seo_title(): string
{
    $clubName = club('club_name', 'Football Club');
    $title = seo_tame_caps(trim(meta('title')));
    if ($title === '') {
        return $clubName;
    }
    $page = (int) ($_GET['page'] ?? 1);
    if ($page > 1 && meta('canonical') === '') {
        $title .= ' – Page ' . $page;
    }
    if (meta('title_full') === '1'
        || stripos($title, $clubName) !== false
        || stripos($title, club('club_short_name', $clubName)) !== false) {
        return $title;
    }
    return $title . ' | ' . $clubName;
}

/** Trim a description to search-snippet length on a word boundary. */
function seo_description(): string
{
    $desc = trim(preg_replace('/\s+/', ' ', meta('description', club('club_name') . ' — official website. Fixtures, results, news, squad and more.')) ?? '');
    return excerpt($desc, 160);
}

/** 1200x630 branded card for pages with no image of their own (see tools/make_share_assets.php). */
function seo_default_share_image(): string
{
    $rel = club('share_image_url', '/uploads/club/share-default.png');
    return is_file(PUBLIC_ROOT . $rel) ? $rel : club_crest();
}

/** Absolute social-share image: page image, else the branded default card. */
function seo_image(): string
{
    $img = meta('image');
    $img = $img !== '' ? $img : seo_default_share_image();
    // Social crawlers choke on multi-MB originals: hand them the resized variant.
    $file = imgopt_resolve((string) parse_url($img, PHP_URL_PATH));
    if ($file !== null && filesize($file) > 400000) {
        return seo_absolute(imgopt_url($img, 1200, 'jpg') ?? $img);
    }
    return seo_absolute($img);
}

/**
 * Declare the breadcrumb trail (excluding Home, which is added automatically).
 * @param list<array{0:string,1:string}> $trail [label, site-relative url]; the
 *        last item is the current page and is rendered without a link.
 */
function seo_breadcrumbs(array $trail): void
{
    $GLOBALS['pub_breadcrumbs'] = $trail;
}

/** @return list<array{0:string,1:string}> full trail including Home. */
function seo_breadcrumb_trail(): array
{
    $trail = $GLOBALS['pub_breadcrumbs'] ?? [];
    if ($trail === []) {
        return [];
    }
    return array_merge([['Home', url()]], $trail);
}

/** Visible, accessible breadcrumb nav (empty string when none declared). */
function seo_breadcrumb_html(): string
{
    $trail = seo_breadcrumb_trail();
    if ($trail === []) {
        return '';
    }
    $last = count($trail) - 1;
    $html = '<nav class="breadcrumbs" aria-label="Breadcrumb"><div class="container"><ol>';
    foreach ($trail as $i => [$label, $href]) {
        $html .= $i === $last
            ? '<li aria-current="page">' . e($label) . '</li>'
            : '<li><a href="' . e($href) . '">' . e($label) . '</a></li>';
    }
    return $html . '</ol></div></nav>';
}

/** BreadcrumbList JSON-LD document (or null). */
function seo_breadcrumb_jsonld(): ?array
{
    $trail = seo_breadcrumb_trail();
    if ($trail === []) {
        return null;
    }
    $items = [];
    foreach ($trail as $i => [$label, $href]) {
        $items[] = [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'name' => $label,
            'item' => seo_absolute($href === '' ? seo_canonical() : $href),
        ];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/** All JSON-LD documents for the page, encoded safely for a <script> block. @return list<string> */
function seo_jsonld_documents(): array
{
    $docs = $GLOBALS['pub_jsonld_docs'] ?? [];
    $crumbs = seo_breadcrumb_jsonld();
    if ($crumbs !== null) {
        $docs[] = $crumbs;
    }
    $out = [];
    foreach ($docs as $doc) {
        $json = json_encode(
            seo_prune($doc),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        if ($json !== false) {
            $out[] = $json;
        }
    }
    return $out;
}

/** Drop null / empty-string values recursively so unsupported properties are omitted, never faked. */
function seo_prune(mixed $v): mixed
{
    if (!is_array($v)) {
        return $v;
    }
    $out = [];
    foreach ($v as $k => $item) {
        $item = seo_prune($item);
        if ($item === null || $item === '' || $item === []) {
            continue;
        }
        $out[$k] = $item;
    }
    return array_is_list($v) ? array_values($out) : $out;
}

/** Schema.org SportsOrganization node describing the club, from settings only. */
function seo_club_node(): array
{
    $sameAs = array_values(pub_social_links());
    $founded = club('club_founded');
    return [
        '@type' => 'SportsTeam',
        '@id' => current_url_origin() . '/#club',
        'name' => club('club_name'),
        'alternateName' => array_values(array_unique(array_filter([club('club_short_name'), club('club_nickname')], static fn ($n) => $n !== '' && $n !== club('club_name')))),
        'sport' => 'Football',
        'url' => current_url_origin() . '/',
        'logo' => seo_absolute(club_crest()),
        'foundingDate' => preg_match('/^\d{4}$/', $founded) ? $founded : null,
        'email' => club('contact_email'),
        'telephone' => club('contact_phone'),
        'sameAs' => $sameAs,
        'location' => [
            '@type' => 'StadiumOrArena',
            'name' => club('ground_name'),
            'address' => club('ground_address'),
        ],
    ];
}

/**
 * Emit everything head-related: X-Robots-Tag header plus the tag block.
 * Called from partials/head.php.
 */
function seo_send_headers(): void
{
    if (!headers_sent()) {
        header('X-Robots-Tag: ' . (str_starts_with(seo_robots(), 'noindex') ? 'noindex, follow' : 'index, follow'));
    }
}

function seo_head_tags(): string
{
    $clubName = club('club_name');
    $title = seo_title();
    $desc = seo_description();
    $image = seo_image();
    $ogTitle = meta('title') === '' ? $clubName : seo_tame_caps(trim(meta('title')));
    $ogType = meta('og_type', 'website');
    $isError = http_response_code() >= 400;

    $t = '<meta name="robots" content="' . e(seo_robots()) . '">' . "\n";
    $t .= '<meta name="description" content="' . e($desc) . '">' . "\n";
    if (!$isError) {
        $canon = seo_canonical();
        $t .= '<link rel="canonical" href="' . e($canon) . '">' . "\n";
        $t .= '<meta property="og:url" content="' . e($canon) . '">' . "\n";
    }
    $t .= '<meta property="og:site_name" content="' . e($clubName) . '">' . "\n";
    $t .= '<meta property="og:locale" content="en_GB">' . "\n";
    $t .= '<meta property="og:type" content="' . e($ogType) . '">' . "\n";
    $t .= '<meta property="og:title" content="' . e($ogTitle) . '">' . "\n";
    $t .= '<meta property="og:description" content="' . e($desc) . '">' . "\n";
    $t .= '<meta property="og:image" content="' . e($image) . '">' . "\n";
    $t .= '<meta property="og:image:alt" content="' . e($clubName) . '">' . "\n";
    $t .= '<meta name="twitter:card" content="' . (meta('image') !== '' || seo_default_share_image() !== club_crest() ? 'summary_large_image' : 'summary') . '">' . "\n";
    $t .= '<meta name="twitter:title" content="' . e($ogTitle) . '">' . "\n";
    $t .= '<meta name="twitter:description" content="' . e($desc) . '">' . "\n";
    $t .= '<meta name="twitter:image" content="' . e($image) . '">' . "\n";
    if ($ogType === 'article') {
        foreach (['article_published' => 'article:published_time', 'article_modified' => 'article:modified_time'] as $k => $prop) {
            if (meta($k) !== '') {
                $t .= '<meta property="' . $prop . '" content="' . e(meta($k)) . '">' . "\n";
            }
        }
    }
    if (!$isError) {
        $page = (int) ($_GET['page'] ?? 1);
        foreach (['prev' => meta('rel_prev'), 'next' => meta('rel_next')] as $rel => $href) {
            if ($href !== '') {
                $t .= '<link rel="' . $rel . '" href="' . e(seo_absolute($href)) . '">' . "\n";
            }
        }
    }
    foreach (seo_jsonld_documents() as $json) {
        $t .= '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }
    return $t;
}
