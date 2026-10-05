<?php

declare(strict_types=1);

function ensureFeedbackSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id INT UNSIGNED NULL,
        holder_id INT UNSIGNED NULL,
        rating TINYINT UNSIGNED NOT NULL,
        comment TEXT NULL,
        page VARCHAR(255) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_feedback_person (person_id, created_at),
        KEY idx_feedback_holder (holder_id),
        KEY idx_feedback_status (status),
        CONSTRAINT fk_feedback_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
        CONSTRAINT fk_feedback_holder FOREIGN KEY (holder_id) REFERENCES season_ticket_holders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_comments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        feedback_id INT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        comment TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_feedback_comments_feedback (feedback_id),
        CONSTRAINT fk_feedback_comments_feedback FOREIGN KEY (feedback_id) REFERENCES feedback(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function feedbackStatusOptions(): array
{
    return [
        'new' => 'New',
        'in_progress' => 'In progress',
        'done' => 'Done',
    ];
}

function personHasSubmittedFeedback(PDO $pdo, int $personId, ?int $legacyHolderId = null): bool
{
    ensureFeedbackSchema($pdo);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM feedback WHERE person_id = :person_id' . ($legacyHolderId !== null && $legacyHolderId > 0 ? ' OR holder_id = :holder_id' : ''));
    $params = [':person_id' => $personId];
    if ($legacyHolderId !== null && $legacyHolderId > 0) {
        $params[':holder_id'] = $legacyHolderId;
    }
    $stmt->execute($params);
    return (int) $stmt->fetchColumn() > 0;
}

function holderHasSubmittedFeedback(PDO $pdo, int $holderId): bool
{
    $personId = function_exists('personIdFromLegacyHolderId') ? (personIdFromLegacyHolderId($pdo, $holderId) ?? 0) : 0;
    return $personId > 0 ? personHasSubmittedFeedback($pdo, $personId, $holderId) : false;
}

function saveFeedback(PDO $pdo, int $personId, int $rating, string $comment, string $page, ?int $legacyHolderId = null): int
{
    ensureFeedbackSchema($pdo);
    if ($personId <= 0) {
        throw new InvalidArgumentException('Person is required.');
    }
    $rating = max(1, min(5, $rating));
    $stmt = $pdo->prepare('INSERT INTO feedback (person_id, rating, comment, page) VALUES (:person_id, :rating, :comment, :page)');
    $stmt->execute([
        ':person_id' => $personId,
        ':rating' => $rating,
        ':comment' => trim($comment) !== '' ? trim($comment) : null,
        ':page' => $page !== '' ? substr($page, 0, 255) : null,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * @return list<array<string, mixed>>
 */
function getFeedbackList(PDO $pdo, array $filters = []): array
{
    ensureFeedbackSchema($pdo);
    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'f.status = :status';
        $params[':status'] = (string) $filters['status'];
    }
    $sql = 'SELECT f.*, COALESCE(p.display_name, h.name, "Member") AS holder_name, COALESCE(p.email, h.email) AS holder_email,
            (SELECT COUNT(*) FROM feedback_comments c WHERE c.feedback_id = f.id) AS comment_count
        FROM feedback f
        LEFT JOIN people p ON p.id = f.person_id
        LEFT JOIN season_ticket_holders h ON h.id = f.holder_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY FIELD(f.status,\'new\',\'in_progress\',\'done\'), f.created_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getFeedbackItem(PDO $pdo, int $id): ?array
{
    ensureFeedbackSchema($pdo);
    $stmt = $pdo->prepare('SELECT f.*, COALESCE(p.display_name, h.name, "Member") AS holder_name, COALESCE(p.email, h.email) AS holder_email
        FROM feedback f
        LEFT JOIN people p ON p.id = f.person_id
        LEFT JOIN season_ticket_holders h ON h.id = f.holder_id
        WHERE f.id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function updateFeedbackStatus(PDO $pdo, int $id, string $status): void
{
    ensureFeedbackSchema($pdo);
    if (!array_key_exists($status, feedbackStatusOptions())) {
        throw new InvalidArgumentException('Invalid status.');
    }
    $pdo->prepare('UPDATE feedback SET status = :status WHERE id = :id')->execute([':status' => $status, ':id' => $id]);
}

function addFeedbackComment(PDO $pdo, int $feedbackId, ?int $userId, string $comment): void
{
    ensureFeedbackSchema($pdo);
    $comment = trim($comment);
    if ($comment === '') {
        return;
    }
    $pdo->prepare('INSERT INTO feedback_comments (feedback_id, user_id, comment) VALUES (:feedback_id, :user_id, :comment)')
        ->execute([':feedback_id' => $feedbackId, ':user_id' => $userId, ':comment' => $comment]);
}

/**
 * @return list<array<string, mixed>>
 */
function getFeedbackComments(PDO $pdo, int $feedbackId): array
{
    ensureFeedbackSchema($pdo);
    $stmt = $pdo->prepare('SELECT c.*, u.display_name, u.username
        FROM feedback_comments c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE c.feedback_id = :feedback_id ORDER BY c.created_at ASC');
    $stmt->execute([':feedback_id' => $feedbackId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function feedbackAverageRating(PDO $pdo): float
{
    ensureFeedbackSchema($pdo);
    return round((float) $pdo->query('SELECT AVG(rating) FROM feedback')->fetchColumn(), 1);
}
