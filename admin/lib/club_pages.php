<?php

declare(strict_types=1);

/**
 * club_pages — editable static content for the public website (history, ground
 * & directions, club officials, and any others added later). Markdown bodies,
 * rendered through the same Parsedown + HTMLPurifier pipeline as news.
 *
 * Schema lives here so callers self-heal; the 2026_09_06 migration is the
 * formal record and seeds the starter pages.
 */

require_once __DIR__ . '/news.php'; // news_render_markdown() / news_purify()

/** slug => default title, used for seeding and the admin "add known page" list. */
function club_pages_known(): array
{
    return [
        'history'   => 'Club history',
        'stadium'   => 'Ground & directions',
        'officials' => 'Club officials',
        'privacy'   => 'Privacy policy',
    ];
}

function club_pages_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS club_pages (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug         VARCHAR(120) NOT NULL,
            title        VARCHAR(190) NOT NULL,
            body         MEDIUMTEXT NOT NULL,
            body_format  ENUM('markdown','html') NOT NULL DEFAULT 'markdown',
            is_published TINYINT(1) NOT NULL DEFAULT 1,
            menu_label   VARCHAR(120) NOT NULL DEFAULT '',
            sort_order   INT NOT NULL DEFAULT 0,
            updated_by   BIGINT UNSIGNED NULL,
            updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_club_pages_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

function club_pages_seed(PDO $pdo): void
{
    club_pages_ensure_schema($pdo);
    $sort = 0;
    foreach (club_pages_known() as $slug => $title) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM club_pages WHERE slug = :s');
        $stmt->execute([':s' => $slug]);
        if ((int) $stmt->fetchColumn() > 0) {
            continue;
        }
        $pdo->prepare(
            'INSERT INTO club_pages (slug, title, body, is_published, menu_label, sort_order)
             VALUES (:s, :t, :b, 0, :m, :o)'
        )->execute([
            ':s' => $slug,
            ':t' => $title,
            ':b' => "_This page has not been written yet._",
            ':m' => $title,
            ':o' => $sort += 10,
        ]);
    }
}

/** @return list<array<string,mixed>> */
function club_pages_all(PDO $pdo): array
{
    club_pages_ensure_schema($pdo);
    return $pdo->query(
        'SELECT * FROM club_pages ORDER BY sort_order ASC, title ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

function club_page_find(PDO $pdo, int $id): ?array
{
    club_pages_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM club_pages WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function club_page_by_slug(PDO $pdo, string $slug, bool $publishedOnly = true): ?array
{
    club_pages_ensure_schema($pdo);
    $sql = 'SELECT * FROM club_pages WHERE slug = :s';
    if ($publishedOnly) {
        $sql .= ' AND is_published = 1';
    }
    $stmt = $pdo->prepare($sql . ' LIMIT 1');
    $stmt->execute([':s' => $slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Published pages that opted into a menu label, for the public nav/footer. */
function club_pages_menu(PDO $pdo): array
{
    club_pages_ensure_schema($pdo);
    return $pdo->query(
        "SELECT slug, COALESCE(NULLIF(menu_label, ''), title) AS label
         FROM club_pages WHERE is_published = 1
         ORDER BY sort_order ASC, title ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

function club_page_save(PDO $pdo, ?int $id, array $data, ?int $userId = null): int
{
    club_pages_ensure_schema($pdo);

    $slug = news_slugify(trim((string) ($data['slug'] ?? ($data['title'] ?? ''))));
    $fields = [
        'slug'         => $slug,
        'title'        => trim((string) ($data['title'] ?? '')),
        'body'         => (string) ($data['body'] ?? ''),
        'body_format'  => ($data['body_format'] ?? 'markdown') === 'html' ? 'html' : 'markdown',
        'is_published' => !empty($data['is_published']) ? 1 : 0,
        'menu_label'   => trim((string) ($data['menu_label'] ?? '')),
        'sort_order'   => (int) ($data['sort_order'] ?? 0),
        'updated_by'   => $userId,
    ];

    if ($id) {
        $set = implode(', ', array_map(static fn ($k) => "$k = :$k", array_keys($fields)));
        $pdo->prepare("UPDATE club_pages SET $set WHERE id = :id")
            ->execute($fields + [':id' => $id]);
        return $id;
    }

    $cols = implode(', ', array_keys($fields));
    $vals = implode(', ', array_map(static fn ($k) => ":$k", array_keys($fields)));
    $pdo->prepare("INSERT INTO club_pages ($cols) VALUES ($vals)")->execute($fields);
    return (int) $pdo->lastInsertId();
}

function club_page_delete(PDO $pdo, int $id): void
{
    club_pages_ensure_schema($pdo);
    $pdo->prepare('DELETE FROM club_pages WHERE id = :id')->execute([':id' => $id]);
}

function club_page_render(array $page): string
{
    $body = (string) ($page['body'] ?? '');
    $html = ($page['body_format'] ?? 'markdown') === 'html'
        ? $body
        : news_render_markdown($body);
    return news_purify($html);
}
