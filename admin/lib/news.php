<?php

declare(strict_types=1);

/**
 * News system for the public website. Separate from `announcements` (which is a
 * members-only notification feed and untouched by this).
 *
 * Storage: news_articles (+ news_images for the per-article gallery). Bodies are
 * authored as Markdown, rendered with Parsedown (safe mode) and then run through
 * HTMLPurifier before display. Schema lives here so callers self-heal; the
 * 2026_09_06 migration is the formal record.
 */

require_once __DIR__ . '/../vendor/autoload.php';

function news_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS news_articles (
            id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug             VARCHAR(190) NOT NULL,
            title            VARCHAR(190) NOT NULL,
            excerpt          VARCHAR(400) NOT NULL DEFAULT '',
            body             MEDIUMTEXT NOT NULL,
            body_format      ENUM('markdown','html') NOT NULL DEFAULT 'markdown',
            hero_image_path  VARCHAR(255) NOT NULL DEFAULT '',
            hero_caption     VARCHAR(255) NOT NULL DEFAULT '',
            hero_focus_x     DECIMAL(5,2) NOT NULL DEFAULT 50,
            hero_focus_y     DECIMAL(5,2) NOT NULL DEFAULT 50,
            hero_zoom        DECIMAL(4,2) NOT NULL DEFAULT 1,
            hero_framing     TEXT NULL,
            category         VARCHAR(60) NOT NULL DEFAULT 'Club news',
            author_name      VARCHAR(120) NOT NULL DEFAULT '',
            status           ENUM('draft','scheduled','published','archived') NOT NULL DEFAULT 'draft',
            published_at     DATETIME NULL,
            is_featured      TINYINT(1) NOT NULL DEFAULT 0,
            fixture_id       INT UNSIGNED NULL,
            meta_title       VARCHAR(190) NOT NULL DEFAULT '',
            meta_description VARCHAR(300) NOT NULL DEFAULT '',
            created_by       BIGINT UNSIGNED NULL,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_news_slug (slug),
            KEY idx_news_public (status, published_at),
            KEY idx_news_featured (is_featured, status, published_at),
            KEY idx_news_fixture (fixture_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Existing installs: hero framing columns (idempotent).
    if (!$pdo->query("SHOW COLUMNS FROM news_articles LIKE 'hero_zoom'")->fetch()) {
        $pdo->exec("ALTER TABLE news_articles
            ADD COLUMN hero_focus_x DECIMAL(5,2) NOT NULL DEFAULT 50 AFTER hero_caption,
            ADD COLUMN hero_focus_y DECIMAL(5,2) NOT NULL DEFAULT 50 AFTER hero_focus_x,
            ADD COLUMN hero_zoom DECIMAL(4,2) NOT NULL DEFAULT 1 AFTER hero_focus_y");
    }

    if (!$pdo->query("SHOW COLUMNS FROM news_articles LIKE 'hero_framing'")->fetch()) {
        $pdo->exec("ALTER TABLE news_articles ADD COLUMN hero_framing TEXT NULL AFTER hero_zoom");
    }

    // Existing installs: add the 'scheduled' status (idempotent).
    $col = $pdo->query("SHOW COLUMNS FROM news_articles LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if ($col && !str_contains((string) $col['Type'], "'scheduled'")) {
        $pdo->exec("ALTER TABLE news_articles MODIFY status ENUM('draft','scheduled','published','archived') NOT NULL DEFAULT 'draft'");
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS news_images (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            article_id INT UNSIGNED NULL,
            file_path  VARCHAR(255) NOT NULL,
            caption    VARCHAR(255) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_news_images_article (article_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $done = true;
}

/** @return list<string> */
function news_categories(): array
{
    return ['Match report', 'Club news', 'Team news', 'Tickets', 'Community', 'Partner news'];
}

function news_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('~[^a-z0-9]+~', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value === '' ? 'article' : substr($value, 0, 180);
}

function news_unique_slug(PDO $pdo, string $base, ?int $ignoreId = null): string
{
    news_ensure_schema($pdo);
    $slug = news_slugify($base);
    $candidate = $slug;
    $n = 2;
    $sql = 'SELECT COUNT(*) FROM news_articles WHERE slug = :s' . ($ignoreId ? ' AND id <> :id' : '');
    while (true) {
        $stmt = $pdo->prepare($sql);
        $params = [':s' => $candidate];
        if ($ignoreId) {
            $params[':id'] = $ignoreId;
        }
        $stmt->execute($params);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
        $candidate = $slug . '-' . $n++;
    }
}

/* -------------------------------------------------------------------------
 * Admin-side queries
 * ---------------------------------------------------------------------- */

/**
 * @param array{status?:string,category?:string,q?:string,limit?:int,offset?:int} $filters
 * @return list<array<string,mixed>>
 */
function news_all(PDO $pdo, array $filters = []): array
{
    news_ensure_schema($pdo);
    [$where, $params] = news_filter_sql($filters);
    $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));
    $sql = "SELECT * FROM news_articles $where
            ORDER BY COALESCE(published_at, created_at) DESC, id DESC
            LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function news_count(PDO $pdo, array $filters = []): int
{
    news_ensure_schema($pdo);
    [$where, $params] = news_filter_sql($filters);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM news_articles $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** @return array{0:string,1:array<string,mixed>} */
function news_filter_sql(array $filters): array
{
    $clauses = [];
    $params = [];
    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $clauses[] = 'status = :status';
        $params[':status'] = (string) $filters['status'];
    }
    if (!empty($filters['category'])) {
        $clauses[] = 'category = :category';
        $params[':category'] = (string) $filters['category'];
    }
    if (!empty($filters['q'])) {
        $clauses[] = '(title LIKE :q OR excerpt LIKE :q)';
        $params[':q'] = '%' . $filters['q'] . '%';
    }
    return [$clauses ? 'WHERE ' . implode(' AND ', $clauses) : '', $params];
}

function news_find(PDO $pdo, int $id): ?array
{
    news_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM news_articles WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* -------------------------------------------------------------------------
 * Public-side queries (published + published_at in the past only)
 * ---------------------------------------------------------------------- */

function news_public_where(): string
{
    return "status = 'published' AND published_at IS NOT NULL AND published_at <= NOW()";
}

function news_find_by_slug(PDO $pdo, string $slug, bool $publishedOnly = true): ?array
{
    news_ensure_schema($pdo);
    $sql = 'SELECT * FROM news_articles WHERE slug = :s';
    if ($publishedOnly) {
        $sql .= ' AND ' . news_public_where();
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute([':s' => $slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * @param array{category?:?string,limit?:int,offset?:int,exclude_id?:int} $opts
 * @return list<array<string,mixed>>
 */
function news_published(PDO $pdo, array $opts = []): array
{
    news_ensure_schema($pdo);
    $limit = max(1, min(60, (int) ($opts['limit'] ?? 12)));
    $offset = max(0, (int) ($opts['offset'] ?? 0));
    $sql = 'SELECT id, slug, title, excerpt, hero_image_path, hero_focus_x, hero_focus_y, hero_zoom, hero_framing, category, author_name,
                   published_at, is_featured, fixture_id
            FROM news_articles WHERE ' . news_public_where();
    $params = [];
    if (!empty($opts['category'])) {
        $sql .= ' AND category = :c';
        $params[':c'] = (string) $opts['category'];
    }
    if (!empty($opts['exclude_id'])) {
        $sql .= ' AND id <> :x';
        $params[':x'] = (int) $opts['exclude_id'];
    }
    $sql .= " ORDER BY published_at DESC, id DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function news_published_count(PDO $pdo, ?string $category = null): int
{
    news_ensure_schema($pdo);
    $sql = 'SELECT COUNT(*) FROM news_articles WHERE ' . news_public_where();
    $params = [];
    if ($category !== null && $category !== '') {
        $sql .= ' AND category = :c';
        $params[':c'] = $category;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** @return list<array<string,mixed>> */
function news_featured(PDO $pdo, int $limit = 5): array
{
    news_ensure_schema($pdo);
    $limit = max(1, min(10, $limit));
    $sql = 'SELECT id, slug, title, excerpt, hero_image_path, hero_focus_x, hero_focus_y, hero_zoom, hero_framing, category, published_at
            FROM news_articles
            WHERE ' . news_public_where() . ' AND is_featured = 1
            ORDER BY published_at DESC, id DESC LIMIT ' . $limit;
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function news_for_fixture(PDO $pdo, int $fixtureId): array
{
    news_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT id, slug, title, excerpt, hero_image_path, hero_focus_x, hero_focus_y, hero_zoom, hero_framing, category, published_at
         FROM news_articles
         WHERE fixture_id = :f AND ' . news_public_where() . '
         ORDER BY published_at DESC'
    );
    $stmt->execute([':f' => $fixtureId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function news_gallery(PDO $pdo, int $articleId): array
{
    news_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM news_images WHERE article_id = :a ORDER BY sort_order ASC, id ASC'
    );
    $stmt->execute([':a' => $articleId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* -------------------------------------------------------------------------
 * Writes
 * ---------------------------------------------------------------------- */

/**
 * @param array<string,mixed> $data
 */
function news_save(PDO $pdo, ?int $id, array $data, ?int $userId = null): int
{
    news_ensure_schema($pdo);

    $title = trim((string) ($data['title'] ?? ''));
    $slugInput = trim((string) ($data['slug'] ?? ''));
    $slug = news_unique_slug($pdo, $slugInput !== '' ? $slugInput : $title, $id);

    $status = in_array($data['status'] ?? '', ['draft', 'scheduled', 'published', 'archived'], true)
        ? (string) $data['status'] : 'draft';

    $publishedAt = trim((string) ($data['published_at'] ?? ''));
    if ($status === 'published' && $publishedAt === '') {
        $publishedAt = date('Y-m-d H:i:s');
    }
    $publishedAt = $publishedAt !== '' ? date('Y-m-d H:i:s', strtotime($publishedAt)) : null;

    // A publish date decides the state: future = scheduled, now/past = published.
    // (Archived is left alone; a date-less draft stays a draft.)
    if ($publishedAt !== null && $status !== 'archived') {
        $status = strtotime($publishedAt) > time() ? 'scheduled' : 'published';
    } elseif ($publishedAt === null && $status === 'scheduled') {
        $status = 'draft';
    }

    $fields = [
        'slug'            => $slug,
        'title'           => $title,
        'excerpt'         => trim((string) ($data['excerpt'] ?? '')),
        'body'            => (string) ($data['body'] ?? ''),
        'body_format'     => ($data['body_format'] ?? 'markdown') === 'html' ? 'html' : 'markdown',
        'hero_image_path' => trim((string) ($data['hero_image_path'] ?? '')),
        'hero_caption'    => trim((string) ($data['hero_caption'] ?? '')),
        'hero_focus_x'    => max(0, min(100, (float) ($data['hero_focus_x'] ?? 50))),
        'hero_focus_y'    => max(0, min(100, (float) ($data['hero_focus_y'] ?? 50))),
        'hero_zoom'       => max(1, min(3, (float) ($data['hero_zoom'] ?? 1))),
        'hero_framing'    => news_normalise_framing($data['hero_framing'] ?? ''),
        'category'        => in_array($data['category'] ?? '', news_categories(), true)
            ? (string) $data['category'] : 'Club news',
        'author_name'     => trim((string) ($data['author_name'] ?? '')),
        'status'          => $status,
        'published_at'    => $publishedAt,
        'is_featured'     => !empty($data['is_featured']) ? 1 : 0,
        'fixture_id'      => !empty($data['fixture_id']) ? (int) $data['fixture_id'] : null,
        'meta_title'      => trim((string) ($data['meta_title'] ?? '')),
        'meta_description' => trim((string) ($data['meta_description'] ?? '')),
    ];

    if ($id) {
        $set = implode(', ', array_map(static fn ($k) => "$k = :$k", array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE news_articles SET $set WHERE id = :id");
        $stmt->execute($fields + [':id' => $id]);
        if ($fields['is_featured'] === 1) {
            news_enforce_featured_limit($pdo);
        }
        return $id;
    }

    $fields['created_by'] = $userId;
    $cols = implode(', ', array_keys($fields));
    $vals = implode(', ', array_map(static fn ($k) => ":$k", array_keys($fields)));
    $pdo->prepare("INSERT INTO news_articles ($cols) VALUES ($vals)")->execute($fields);
    $newId = (int) $pdo->lastInsertId();
    if ($fields['is_featured'] === 1) {
        news_enforce_featured_limit($pdo);
    }
    return $newId;
}

/**
 * Keeps at most $limit featured articles. When saving pushes the count over
 * the limit, the oldest-featured ones (by published_at, falling back to
 * created_at) are unfeatured first — the just-saved article is exempt only
 * by virtue of being the newest, same as any other.
 */
function news_enforce_featured_limit(PDO $pdo, int $limit = 3): void
{
    news_ensure_schema($pdo);
    $pdo->exec(
        'UPDATE news_articles SET is_featured = 0 WHERE is_featured = 1 AND id NOT IN (
            SELECT id FROM (
                SELECT id FROM news_articles
                WHERE is_featured = 1
                ORDER BY COALESCE(published_at, created_at) DESC, id DESC
                LIMIT ' . max(1, $limit) . '
            ) keep
        )'
    );
}

function news_delete(PDO $pdo, int $id): void
{
    news_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM news_images WHERE article_id = :id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM news_articles WHERE id = :id')->execute([':id' => $id]);
}

/* -------------------------------------------------------------------------
 * Rendering
 * ---------------------------------------------------------------------- */

function news_render_markdown(string $markdown): string
{
    static $converter = null;
    if ($converter === null) {
        // GitHub-flavoured: tables, strikethrough, autolinks, task lists.
        // Raw HTML in the source is stripped here and the result is still run
        // through HTMLPurifier by news_purify().
        $converter = new League\CommonMark\GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
    return (string) $converter->convert($markdown);
}

function news_purify(string $html): string
{
    static $purifier = null;
    if ($purifier === null) {
        $config = HTMLPurifier_Config::createDefault();
        $cacheDir = __DIR__ . '/../cache/htmlpurifier';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        $config->set('Cache.SerializerPath', is_dir($cacheDir) && is_writable($cacheDir) ? $cacheDir : null);
        // A controlled rich-text set — what the TinyMCE editor can emit. Inline
        // CSS is limited to a short, safe property list (align + colour). No
        // <figure>/loading — not in HTMLPurifier's default (XHTML 1.0) doctype.
        $config->set('HTML.Allowed',
            'p[style|class],br,strong,em,u,s,sub,sup,mark[class|style],span[style|class],'
            . 'blockquote[style|class|cite],cite,pre[class|style],code,kbd,hr[class|style],'
            . 'h1[style|class],h2[style|class],h3[style|class],h4[style|class],h5[style|class],h6[style|class],'
            . 'ul[class|style],ol[class|style|start],li[style|class],'
            . 'div[class|style],figure[class|style],figcaption[class],details[open|class|style],summary,'
            . 'a[href|title|rel|target|class],'
            . 'img[src|alt|title|width|height|style|class],'
            . 'table[class|style],thead,tbody,tfoot,caption,tr[class|style],th[class|style|colspan|rowspan|scope],td[class|style|colspan|rowspan]');
        // Inline CSS the block editor emits (alignment, colour, spacing, sizes).
        $config->set('CSS.AllowedProperties', [
            'text-align', 'color', 'background-color', 'float',
            'margin', 'margin-top', 'margin-bottom', 'margin-left', 'margin-right',
            'padding', 'padding-top', 'padding-bottom', 'padding-left', 'padding-right',
            'width', 'height',
            'font-size', 'font-weight', 'font-style', 'line-height', 'letter-spacing', 'text-decoration', 'text-transform',
            'border', 'border-top', 'border-bottom', 'border-left', 'border-right',
            'border-color', 'border-style', 'border-width',
        ]);
        $config->set('Attr.EnableID', false);
        $config->set('HTML.DefinitionID', 'news-blocks');
        $config->set('HTML.DefinitionRev', 2);
        $config->set('HTML.TargetBlank', true);
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);
        $config->set('AutoFormat.RemoveEmpty', true);
        // Keep empty classed <div>s (e.g. the Spacer block), but not empty paragraphs etc.
        $config->set('AutoFormat.RemoveEmpty.Predicate', ['colgroup' => [], 'th' => [], 'td' => [], 'div' => ['class']]);
        // Editor blocks: <details>/<summary> aren't in HTMLPurifier's XHTML 1.0 doctype.
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addElement('details', 'Block', 'Flow', 'Common', ['open' => 'Bool#open']);
            $def->addElement('summary', 'Block', 'Inline', 'Common');
            $def->addElement('figure', 'Block', 'Flow', 'Common');
            $def->addElement('figcaption', 'Inline', 'Flow', 'Common');
            $def->addElement('mark', 'Inline', 'Inline', 'Common');
        }
        if ($css = $config->getCSSDefinition()) {
            $len = new HTMLPurifier_AttrDef_CSS_Composite([new HTMLPurifier_AttrDef_CSS_Length(), new HTMLPurifier_AttrDef_CSS_Percentage()]);
            $css->info['flex-basis'] = $len;
            $css->info['gap'] = new HTMLPurifier_AttrDef_CSS_Length();
            $css->info['aspect-ratio'] = new HTMLPurifier_AttrDef_Enum(['1', '1/1', '16/9', '4/3', '3/2', '9/16', '3/4', '2/3', '21/9', 'auto', '1 / 1', '16 / 9', '4 / 3', '3 / 2', '9 / 16', '3 / 4', '2 / 3', '21 / 9']);
            $css->info['object-fit'] = new HTMLPurifier_AttrDef_Enum(['cover', 'contain', 'fill', 'none', 'scale-down']);
            $css->info['min-height'] = $len;
            $css->info['max-width'] = $len;
            $css->info['border-radius'] = new HTMLPurifier_AttrDef_CSS_Multiple($len);
        }
        $purifier = new HTMLPurifier($config);
    }
    return $purifier->purify($html);
}

/** Full rendered, sanitised HTML for an article body. */
function news_render(array $article): string
{
    $body = (string) ($article['body'] ?? '');
    $html = ($article['body_format'] ?? 'markdown') === 'html'
        ? $body
        : news_render_markdown($body);
    return news_purify($html);
}

/* -------------------------------------------------------------------------
 * Image uploads (hero, inline editor images, gallery)
 * ---------------------------------------------------------------------- */

function news_uploads_dir(): string
{
    return __DIR__ . '/../uploads/news';
}

/**
 * Validate + store one uploaded image under uploads/news/YYYY/MM/.
 *
 * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
 * @return array{ok:bool,path?:string,url?:string,error?:string}
 */
function news_store_upload(array $file): array
{
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'No file was received.'];
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Invalid upload.'];
    }
    if ((int) ($file['size'] ?? 0) > 24 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Image must be 24MB or smaller.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmp) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    if (!isset($allowed[$mime])) {
        return ['ok' => false, 'error' => 'Use a JPG, PNG, WebP or GIF image.'];
    }

    $rel = date('Y/m');
    $dir = news_uploads_dir() . '/' . $rel;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'error' => 'Could not prepare the upload directory.'];
    }

    try {
        $name = bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    } catch (Exception) {
        return ['ok' => false, 'error' => 'Could not generate a filename.'];
    }

    if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
        return ['ok' => false, 'error' => 'Could not store the file.'];
    }

    $path = $rel . '/' . $name; // relative to uploads/news/
    return ['ok' => true, 'path' => $path, 'url' => '/uploads/news/' . $path];
}

/** Attach an uploaded image to an article's gallery. */
function news_gallery_add(PDO $pdo, int $articleId, string $path, string $caption = ''): void
{
    news_ensure_schema($pdo);
    $order = (int) $pdo->query(
        'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM news_images WHERE article_id = ' . $articleId
    )->fetchColumn();
    $pdo->prepare(
        'INSERT INTO news_images (article_id, file_path, caption, sort_order) VALUES (:a, :p, :c, :o)'
    )->execute([':a' => $articleId, ':p' => $path, ':c' => $caption, ':o' => $order]);
}

/**
 * Public URL for a stored news image path. Two shapes are supported:
 *  - "YYYY/MM/xxxx.jpg"            — uploaded via the editor/dropzone, lives
 *                                    under /uploads/news/
 *  - "uploads/… | badges/… | assets/…" — referenced straight from the Media
 *                                    Library, served from the web root as-is
 *                                    (no copy).
 */
function news_image_url(string $path): string
{
    $path = ltrim(trim($path), '/');
    if ($path === '') {
        return '';
    }
    if (!preg_match('#^(uploads|badges|assets)/#', $path)) {
        $path = 'uploads/news/' . $path;
    }
    return '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * Validate a Media Library path (relative to the Hub root, e.g.
 * "uploads/history/gallery/2016/x.jpg"). No copy — the path is referenced
 * as-is; news_image_url() resolves it at render time.
 *
 * @return array{ok:bool,path?:string,url?:string,error?:string}
 */
function news_library_image_ref(string $sourceRelPath): array
{
    require_once __DIR__ . '/media_library.php';

    $src = ltrim(trim($sourceRelPath), '/');
    if ($src === '' || hub_media_resolve($src) === null) {
        return ['ok' => false, 'error' => 'That media item could not be found.'];
    }
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return ['ok' => false, 'error' => 'Only JPG, PNG, WebP or GIF images can be used.'];
    }
    return ['ok' => true, 'path' => $src, 'url' => news_image_url($src)];
}

/**
 * Attach a Media Library image to an article's gallery by reference (no copy).
 *
 * @return array{ok:bool,path?:string,error?:string}
 */
function news_gallery_add_from_library(PDO $pdo, int $articleId, string $sourceRelPath, string $caption = ''): array
{
    $res = news_library_image_ref($sourceRelPath);
    if ($res['ok']) {
        news_gallery_add($pdo, $articleId, $res['path'], $caption);
    }
    return $res;
}

function news_gallery_delete(PDO $pdo, int $imageId, ?int $articleId = null): void
{
    news_ensure_schema($pdo);
    $sql = 'DELETE FROM news_images WHERE id = :id';
    $params = [':id' => $imageId];
    if ($articleId !== null) {
        $sql .= ' AND article_id = :a';
        $params[':a'] = $articleId;
    }
    $pdo->prepare($sql)->execute($params);
}


/**
 * Publish every scheduled article whose time has arrived. Returns the count.
 * Used by cron_publish_scheduled_news.php.
 */
function news_publish_due(PDO $pdo): int
{
    news_ensure_schema($pdo);
    $stmt = $pdo->prepare("UPDATE news_articles SET status = 'published'
        WHERE status = 'scheduled' AND published_at IS NOT NULL AND published_at <= :now");
    $stmt->execute([':now' => date('Y-m-d H:i:s')]);
    return $stmt->rowCount();
}


/** Placements that can be framed independently. */
const NEWS_HERO_PLACEMENTS = ['home', 'card', 'banner'];

/** Validate/clamp a hero_framing JSON string; returns JSON or null. */
function news_normalise_framing($raw): ?string
{
    $arr = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($arr)) {
        return null;
    }
    $out = [];
    foreach (NEWS_HERO_PLACEMENTS as $k) {
        if (isset($arr[$k]) && is_array($arr[$k])) {
            $out[$k] = [
                'x' => round(max(0, min(100, (float) ($arr[$k]['x'] ?? 50))), 1),
                'y' => round(max(0, min(100, (float) ($arr[$k]['y'] ?? 50))), 1),
                'z' => round(max(1, min(3, (float) ($arr[$k]['z'] ?? 1))), 2),
            ];
        }
    }
    return $out === [] ? null : json_encode($out);
}

/**
 * Inline CSS custom properties carrying an article's hero framing (focal
 * point + zoom) for one placement (home | card | banner). Falls back to the
 * legacy single framing. Empty string when default.
 *
 * @param array<string,mixed> $a
 */
function news_hero_vars(array $a, string $placement = 'card'): string
{
    $x = (float) ($a['hero_focus_x'] ?? 50);
    $y = (float) ($a['hero_focus_y'] ?? 50);
    $z = (float) ($a['hero_zoom'] ?? 1);
    $f = json_decode((string) ($a['hero_framing'] ?? ''), true);
    if (is_array($f) && isset($f[$placement])) {
        $x = (float) ($f[$placement]['x'] ?? 50);
        $y = (float) ($f[$placement]['y'] ?? 50);
        $z = (float) ($f[$placement]['z'] ?? 1);
    }
    if ($x === 50.0 && $y === 50.0 && $z === 1.0) {
        return '';
    }
    $n = static fn(float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    return '--hx:' . $n($x) . '%;--hy:' . $n($y) . '%;--hz:' . $n($z) . ';';
}
