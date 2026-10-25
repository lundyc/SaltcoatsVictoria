<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/navigation_settings.php';

/** Help Centre data + rendering. Articles are Markdown, edited by Administrators. */

/** Capability slugs an article can be restricted to (empty list = everyone). */
function help_parse_caps(string $csv): array
{
    return array_values(array_filter(array_map('trim', explode(',', $csv)), static fn ($c) => $c !== ''));
}

function help_user_can_see(array $article): bool
{
    $caps = help_parse_caps((string) ($article['capabilities'] ?? ''));
    return $caps === [] || hub_auth_has_any_capability($caps);
}

/**
 * Menu items (keys like "secretary.discipline_register") with the admin page each one opens.
 * Used so the Help Centre follows the same on/off settings as the menu.
 * @return list<array{key:string,script:string,href:string}>
 */
function help_nav_items(): array
{
    static $items = null;
    if ($items === null) {
        $items = [];
        foreach (hub_navigation_definitions() as $group => $def) {
            foreach ($def['items'] as $item => $meta) {
                $path = (string) parse_url((string) ($meta['href'] ?? ''), PHP_URL_PATH);
                $items[] = ['key' => $group . '.' . $item, 'script' => basename($path), 'href' => $path];
            }
        }
    }
    return $items;
}

/** Is a menu item ("group.item") or a whole section ("group") switched on in the menu settings? */
function help_nav_on(string $key): bool
{
    if (str_contains($key, '.')) {
        return hub_navigation_item_visible($key);
    }
    foreach (help_nav_items() as $i) {
        if (str_starts_with($i['key'], $key . '.') && hub_navigation_item_visible($i['key'])) {
            return true;
        }
    }
    return false;
}

/** True when every menu item a guide is about has been switched off (so the guide describes a hidden feature). */
function help_article_feature_off(array $article): bool
{
    $pages = (string) ($article['pages'] ?? '');
    if (trim($pages) === '') {
        return false;
    }
    $matched = false;
    foreach (help_nav_items() as $i) {
        if ($i['script'] !== '' && help_pages_match($pages, $i['script'])) {
            $matched = true;
            if (hub_navigation_item_visible($i['key'])) {
                return false;
            }
        }
    }
    return $matched;
}

/**
 * Lines ending [[needs:secretary]] (a section) or [[needs:sponsorship.sponsor_followups]] (one item)
 * are only shown while that feature is on; [[needs:!secretary]] shows the line only while it is off.
 */
function help_apply_feature_tags(string $markdown): string
{
    if (!str_contains($markdown, '[[needs:')) {
        return $markdown;
    }
    $out = [];
    foreach (explode("\n", $markdown) as $line) {
        if (preg_match('/\s*\[\[needs:(!?)([a-z_.]+)\]\]\s*$/', $line, $m)) {
            $on = help_nav_on($m[2]);
            if ($m[1] === '!' ? $on : !$on) {
                continue;
            }
            $line = preg_replace('/\s*\[\[needs:!?[a-z_.]+\]\]\s*$/', '', $line);
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

/** Articles this user may read, in sort order. Admins also see drafts. Guides for switched-off features are left out. */
function help_visible_articles(PDO $pdo): array
{
    return array_values(array_filter(help_readable_articles($pdo), static fn ($r) => !help_article_feature_off($r)));
}

/** Guides about features switched off in the menu settings (shown to admins only, for maintenance). */
function help_feature_off_articles(PDO $pdo): array
{
    return hub_auth_is_admin() ? array_values(array_filter(help_readable_articles($pdo), 'help_article_feature_off')) : [];
}

function help_readable_articles(PDO $pdo): array
{
    $isAdmin = hub_auth_is_admin();
    $rows = $pdo->query('SELECT * FROM help_articles ORDER BY sort_order, title')->fetchAll(PDO::FETCH_ASSOC);
    return array_values(array_filter($rows, static fn ($r) => ($isAdmin || (int) $r['is_published'] === 1) && ($isAdmin || help_user_can_see($r))));
}

function help_find(PDO $pdo, string $slug): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM help_articles WHERE slug = :s');
    $stmt->execute([':s' => $slug]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function help_render_markdown(string $markdown): string
{
    static $converter = null;
    if ($converter === null) {
        $converter = new League\CommonMark\GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
    return help_linkify_images((string) $converter->convert(help_apply_feature_tags($markdown)));
}

function help_slugify(string $value): string
{
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($value)), '-');
    return substr($slug, 0, 120);
}

/** True if $script (e.g. "shop_orders.php") matches a comma-separated list that may use * wildcards. */
function help_pages_match(string $list, string $script): bool
{
    foreach (help_parse_caps($list) as $pattern) {
        if (fnmatch($pattern, $script)) {
            return true;
        }
    }
    return false;
}

/** Published articles linked to this page that the current user may read. */
function help_articles_for_page(PDO $pdo, string $script): array
{
    $rows = $pdo->query("SELECT * FROM help_articles WHERE is_published = 1 AND pages <> '' ORDER BY sort_order, title")->fetchAll(PDO::FETCH_ASSOC);
    $matches = array_values(array_filter($rows, static fn ($r) => help_pages_match((string) $r['pages'], $script) && help_user_can_see($r) && !help_article_feature_off($r)));
    // A guide that names this exact page outranks one that only matches a wildcard (e.g. an area overview).
    $exact = static fn (array $r): int => in_array($script, help_parse_caps((string) $r['pages']), true) ? 0 : 1;
    // Then narrower guides (fewer linked pages) before broad overviews.
    $breadth = static fn (array $r): int => count(help_parse_caps((string) $r['pages']));
    usort($matches, static fn ($a, $b) => [$exact($a), $breadth($a), (int) $a['sort_order']] <=> [$exact($b), $breadth($b), (int) $b['sort_order']]);
    return $matches;
}

/**
 * Small "i" hint for a form label or column heading. Tap or click to open
 * (works on phones, unlike hover tooltips). Plain text only.
 */
function hub_help_hint(string $text, string $label = 'More information'): string
{
    return ' <button type="button" class="hub-help-hint" data-hub-help="' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
        . '" aria-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button>';
}

/* ---------------------------- Layout + search helpers --------------------------- */

/** Font Awesome icon for each Help Centre area. */
function help_area_icon(string $area): string
{
    return match ($area) {
        'Getting started' => 'fa-circle-play',
        'Match day' => 'fa-futbol',
        'Sponsorship' => 'fa-handshake',
        'Shop & ticketing' => 'fa-bag-shopping',
        'Secretary' => 'fa-user-tie',
        'Website' => 'fa-newspaper',
        'Finance & reports' => 'fa-chart-column',
        'Administration' => 'fa-user-shield',
        default => 'fa-book-open',
    };
}

/** Lower-case plain text of a Markdown body, for searching and read-time. */
function help_plain_text(string $markdown): string
{
    $markdown = help_apply_feature_tags($markdown);
    $t = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $markdown);
    $t = preg_replace('/[#*_`>|]+/', ' ', (string) $t);
    return trim((string) preg_replace('/\s+/', ' ', (string) $t));
}

function help_read_minutes(string $markdown): int
{
    return max(1, (int) ceil(str_word_count(help_plain_text($markdown)) / 200));
}

/**
 * Render a Markdown article and give every h2 an id.
 * @return array{html:string, toc:list<array{id:string,text:string}>}
 */
function help_render_article(string $markdown): array
{
    $html = help_render_markdown($markdown);
    $toc = [];
    $used = [];
    $html = (string) preg_replace_callback('/<h2>(.*?)<\/h2>/s', static function (array $m) use (&$toc, &$used): string {
        $text = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));
        $id = help_slugify($text) ?: 'section';
        $base = $id;
        for ($i = 2; isset($used[$id]); $i++) {
            $id = $base . '-' . $i;
        }
        $used[$id] = true;
        $toc[] = ['id' => $id, 'text' => $text];
        return '<h2 id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">' . $m[1] . '</h2>';
    }, $html);
    // The guide title is the page's second-level heading, so section headings sit one level lower.
    $html = (string) preg_replace(['/<(\/?)h3(\s|>)/', '/<(\/?)h2(\s|>)/'], ['<$1h4$2', '<$1h3$2'], $html);
    return ['html' => $html, 'toc' => $toc];
}

/** Other spellings people type for the same thing. @return list<string> */
function help_term_variants(string $term): array
{
    static $pairs = [['lineup', 'line-up'], ['lineups', 'line-ups'], ['signup', 'sign-up'], ['matchday', 'match day'],
        ['login', 'log in'], ['logon', 'login'], ['email', 'e-mail'], ['preorder', 'pre-order'], ['gameday', 'match day']];
    $out = [$term];
    foreach ($pairs as [$a, $b]) {
        if ($term === $a) { $out[] = $b; }
        if ($term === $b) { $out[] = $a; }
    }
    return array_values(array_unique($out));
}

/**
 * Ranked search over the articles this user may read. Every word must match
 * somewhere. Title hits count most, then summary, area, then body.
 * @return list<array{slug:string,title:string,area:string,summary:string,snippet:string,draft:bool}>
 */
function help_search(PDO $pdo, string $query, string $area = ''): array
{
    $terms = array_values(array_unique(array_filter(
        preg_split('/\s+/', mb_strtolower(trim($query))) ?: [],
        static fn ($t) => mb_strlen($t) >= 2
    )));
    $terms = array_slice($terms, 0, 6);
    $results = [];
    foreach (help_visible_articles($pdo) as $a) {
        if ($area !== '' && $a['area'] !== $area) {
            continue;
        }
        $title = mb_strtolower((string) $a['title']);
        $summary = mb_strtolower((string) $a['summary']);
        $areaLc = mb_strtolower((string) $a['area']);
        $body = help_plain_text((string) $a['body']);
        $bodyLc = mb_strtolower($body);
        $score = 0;
        $firstBodyHit = null;
        foreach ($terms as $term) {
            $hit = 0;
            foreach (help_term_variants($term) as $v) {
                if (str_contains($title, $v)) {
                    $hit += 10 + (str_starts_with($title, $v) ? 5 : 0);
                }
                if (str_contains($summary, $v)) {
                    $hit += 4;
                }
                if (str_contains($areaLc, $v)) {
                    $hit += 3;
                }
                $count = mb_substr_count($bodyLc, $v);
                if ($count > 0) {
                    $hit += min(5, $count);
                    $pos = mb_strpos($bodyLc, $v);
                    $firstBodyHit = $firstBodyHit === null ? $pos : min($firstBodyHit, $pos);
                }
            }
            if ($hit === 0) {
                continue 2;
            }
            $score += $hit;
        }
        $start = $firstBodyHit === null ? 0 : max(0, $firstBodyHit - 60);
        $snippetText = mb_substr($body, $start, 170);
        if ($start > 0) {
            $snippetText = '…' . $snippetText;
        }
        if ($start + 170 < mb_strlen($body)) {
            $snippetText .= '…';
        }
        $snippet = htmlspecialchars($snippetText, ENT_QUOTES, 'UTF-8');
        foreach ($terms as $term) {
            foreach (help_term_variants($term) as $v) {
                $snippet = (string) preg_replace('/(' . preg_quote(htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), '/') . ')/iu', '<mark>$1</mark>', $snippet);
            }
        }
        $results[] = [
            'score' => $score,
            'slug' => (string) $a['slug'], 'title' => (string) $a['title'], 'area' => (string) $a['area'],
            'summary' => (string) $a['summary'], 'snippet' => $snippet, 'draft' => (int) $a['is_published'] !== 1,
        ];
    }
    usort($results, static fn ($x, $y) => $y['score'] <=> $x['score']);
    return array_map(static function (array $r): array {
        unset($r['score']);
        return $r;
    }, $results);
}

/* ------------------------------- Help images ------------------------------- */

function help_image_url(string $file): string
{
    return '/admin/help_image.php?f=' . rawurlencode($file);
}

/** Copy an image into the protected help image folder; the name is its content hash, so duplicates collapse. */
function help_store_image(string $source, string $ext, string $dir): string
{
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $name = substr(sha1_file($source), 0, 20) . '.' . $ext;
    $target = rtrim($dir, '/') . '/' . $name;
    if (!is_file($target)) {
        copy($source, $target);
    }
    return $name;
}

/** Make every image in rendered help HTML click through to the full-size picture. */
function help_linkify_images(string $html): string
{
    return (string) preg_replace('/<img ([^>]*?)src="([^"]+)"([^>]*)>/', '<a class="help-figure" href="$2" target="_blank" rel="noopener"><img loading="lazy" $1src="$2"$3></a>', $html);
}
