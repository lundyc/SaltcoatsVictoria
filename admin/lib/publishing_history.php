<?php

declare(strict_types=1);

/** @return list<array<string, mixed>> */
function hub_publishing_history_recent(PDO $pdo, int $limit = 20): array
{
    $limit = max(1, min(100, $limit));
    return $pdo->query(
        'SELECT sp.*, COALESCE(u.display_name, u.username, "System") AS created_by_name
         FROM social_posts sp
         LEFT JOIN users u ON u.id = sp.created_by
         ORDER BY sp.created_at DESC, sp.id DESC
         LIMIT ' . $limit
    )->fetchAll() ?: [];
}

/**
 * Whether a fixture already has a completed (published, prepared, or
 * in-flight) social post of the given type — optionally scoped to one
 * specific match event.
 */
function hub_social_post_exists(PDO $pdo, int $fixtureId, string $postType, ?string $eventId = null): bool
{
    if ($fixtureId <= 0 || $postType === '') {
        return false;
    }
    $sql = 'SELECT COUNT(*) FROM social_posts
            WHERE fixture_id = :fixture_id AND post_type = :post_type
              AND status IN ("published", "prepared", "publishing")';
    $params = [':fixture_id' => $fixtureId, ':post_type' => $postType];
    if ($eventId !== null) {
        $sql .= ' AND event_id = :event_id';
        $params[':event_id'] = $eventId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

/** @return array<string, int> */
function hub_publishing_history_counts(PDO $pdo): array
{
    $counts = ['draft' => 0, 'queued' => 0, 'published' => 0, 'failed' => 0];
    foreach ($pdo->query('SELECT status, COUNT(*) AS total FROM social_posts GROUP BY status') as $row) {
        $counts[(string)$row['status']] = (int)$row['total'];
    }
    return $counts;
}

/**
 * @param array<string, mixed> $data
 * @return array{ok: bool, id: int, duplicate: bool}
 */
function hub_publishing_history_start(PDO $pdo, array $data): array
{
    $fixtureId = (int)($data['fixture_id'] ?? 0);
    $eventId = trim((string)($data['event_id'] ?? ''));
    $postType = trim((string)($data['post_type'] ?? ''));
    $platform = trim((string)($data['platform'] ?? ''));
    $caption = trim((string)($data['caption'] ?? ''));
    $contentHash = trim((string)($data['content_hash'] ?? ''));
    $allowRepost = !empty($data['allow_repost']);
    $dedupeParts = [$fixtureId, $eventId, $postType, $platform, $caption];
    if ($contentHash !== '') {
        $dedupeParts[] = $contentHash;
    }
    if ($allowRepost) {
        // Preserve the original publication record while allowing an explicitly
        // confirmed replacement of a post deleted directly on the platform.
        $dedupeParts[] = 'repost';
        $dedupeParts[] = bin2hex(random_bytes(16));
    }
    $dedupeKey = hash('sha256', implode('|', $dedupeParts));

    $existing = $pdo->prepare('SELECT id, status FROM social_posts WHERE dedupe_key = :dedupe LIMIT 1');
    $existing->execute([':dedupe' => $dedupeKey]);
    $row = $existing->fetch();
    if ($row && in_array((string)$row['status'], ['publishing', 'published'], true)) {
        return ['ok' => false, 'id' => (int)$row['id'], 'duplicate' => true];
    }

    if ($row) {
        $stmt = $pdo->prepare('UPDATE social_posts SET status = "publishing", attempts = attempts + 1, error_message = NULL, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => (int)$row['id']]);
        return ['ok' => true, 'id' => (int)$row['id'], 'duplicate' => false];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO social_posts (fixture_id, event_id, post_type, platform, caption, image_url, status, attempts, dedupe_key, created_by)
         VALUES (:fixture_id, :event_id, :post_type, :platform, :caption, :image_url, "publishing", 1, :dedupe, :created_by)'
    );
    $stmt->execute([
        ':fixture_id' => $fixtureId > 0 ? $fixtureId : null,
        ':event_id' => $eventId,
        ':post_type' => $postType,
        ':platform' => $platform,
        ':caption' => $caption,
        ':image_url' => trim((string)($data['image_url'] ?? '')),
        ':dedupe' => $dedupeKey,
        ':created_by' => (int)($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null,
    ]);
    return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'duplicate' => false];
}

function hub_publishing_history_finish(PDO $pdo, int $id, bool $success, string $externalId = '', string $error = ''): void
{
    $stmt = $pdo->prepare(
        'UPDATE social_posts
         SET status = :status, published_at = IF(:success = 1, NOW(), published_at), external_post_id = :external_id, error_message = :error
         WHERE id = :id'
    );
    $stmt->execute([
        ':status' => $success ? 'published' : 'failed',
        ':success' => $success ? 1 : 0,
        ':external_id' => $externalId,
        ':error' => $error !== '' ? $error : null,
        ':id' => $id,
    ]);
}

/** @param array<string, mixed> $data */
function hub_publishing_history_upsert_draft(PDO $pdo, array $data): bool
{
    $fixtureId = (int)($data['fixture_id'] ?? 0);
    $postType = trim((string)($data['post_type'] ?? ''));
    $platform = trim((string)($data['platform'] ?? ''));
    $status = ($data['status'] ?? 'draft') === 'queued' ? 'queued' : 'draft';
    if ($fixtureId <= 0 || $postType === '' || $platform === '') {
        return false;
    }
    $dedupeKey = hash('sha256', 'draft|' . $fixtureId . '|' . $postType . '|' . $platform);
    $stmt = $pdo->prepare(
        'INSERT INTO social_posts (fixture_id, post_type, platform, caption, image_url, status, scheduled_for, dedupe_key, created_by)
         VALUES (:fixture_id, :post_type, :platform, :caption, :image_url, :status, :scheduled_for, :dedupe, :created_by)
         ON DUPLICATE KEY UPDATE
            caption = IF(status IN ("draft", "queued"), VALUES(caption), caption),
            image_url = IF(status IN ("draft", "queued"), VALUES(image_url), image_url),
            scheduled_for = IF(status IN ("draft", "queued"), VALUES(scheduled_for), scheduled_for),
            status = IF(status IN ("draft", "queued"), VALUES(status), status),
            updated_at = NOW()'
    );
    return $stmt->execute([
        ':fixture_id' => $fixtureId,
        ':post_type' => $postType,
        ':platform' => $platform,
        ':caption' => trim((string)($data['caption'] ?? '')),
        ':image_url' => trim((string)($data['image_url'] ?? '')),
        ':status' => $status,
        ':scheduled_for' => ($data['scheduled_for'] ?? null) ?: null,
        ':dedupe' => $dedupeKey,
        ':created_by' => (int)($_SESSION['hub_user_id'] ?? $_SESSION['user_id'] ?? 0) ?: null,
    ]);
}
