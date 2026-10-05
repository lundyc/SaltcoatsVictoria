<?php

declare(strict_types=1);

function ensureAnnouncementsSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(190) NOT NULL,
        body TEXT NOT NULL,
        is_published TINYINT(1) NOT NULL DEFAULT 0,
        published_at DATETIME NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_announcements_published (is_published, published_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

/**
 * @return list<array<string, mixed>>
 */
function getAnnouncements(PDO $pdo, bool $publishedOnly = false): array
{
    ensureAnnouncementsSchema($pdo);
    $sql = 'SELECT * FROM announcements';
    if ($publishedOnly) {
        $sql .= " WHERE is_published = 1 AND published_at <= NOW()";
    }
    $sql .= ' ORDER BY COALESCE(published_at, created_at) DESC, id DESC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getAnnouncement(PDO $pdo, int $id): ?array
{
    ensureAnnouncementsSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM announcements WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * @param array<string, mixed> $data
 */
function saveAnnouncement(PDO $pdo, ?int $id, array $data, ?int $createdByUserId = null): int
{
    ensureAnnouncementsSchema($pdo);
    $isPublished = !empty($data['is_published']) ? 1 : 0;
    $params = [
        ':title' => trim((string) $data['title']),
        ':body' => trim((string) $data['body']),
        ':is_published' => $isPublished,
        ':published_at' => $isPublished ? (trim((string) ($data['published_at'] ?? '')) ?: date('Y-m-d H:i:s')) : null,
    ];

    if ($id) {
        $params[':id'] = $id;
        $pdo->prepare('UPDATE announcements SET title=:title, body=:body, is_published=:is_published, published_at=:published_at WHERE id=:id')->execute($params);
        return $id;
    }

    $params[':created_by'] = $createdByUserId;
    $pdo->prepare('INSERT INTO announcements (title, body, is_published, published_at, created_by) VALUES (:title, :body, :is_published, :published_at, :created_by)')->execute($params);
    return (int) $pdo->lastInsertId();
}

function deleteAnnouncement(PDO $pdo, int $id): void
{
    ensureAnnouncementsSchema($pdo);
    $pdo->prepare('DELETE FROM announcements WHERE id = :id')->execute([':id' => $id]);
}
