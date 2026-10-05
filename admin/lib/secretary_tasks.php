<?php

declare(strict_types=1);

/**
 * Correspondence log + task tracking (Secretary Handbook sections 4-5):
 * the "Inbox workflow" labels (ACTION-URGENT/ACTION/WAITING/FIXTURE/
 * REGISTRATION/DISCIPLINE/COMMITTEE/FILED) and the "EMAIL RULE" — if an
 * email contains a deadline, the deadline goes in the task system before
 * the email is closed. Two related tables, matching the shapes the
 * handbook's own dashboard spec (section 25) names: correspondence_log and
 * secretary_tasks. A task's due date doubles as the season planner /
 * compliance calendar (sections 20/22) — there is no separate table for
 * that, it is just tasks filtered by category/due date.
 */

const SECRETARY_TASK_LABELS = [
    'action_urgent' => 'ACTION - URGENT',
    'action' => 'ACTION',
    'waiting' => 'WAITING',
    'fixture' => 'FIXTURE',
    'registration' => 'REGISTRATION',
    'discipline' => 'DISCIPLINE',
    'committee' => 'COMMITTEE',
    'filed' => 'FILED',
];

const SECRETARY_TASK_CATEGORIES = [
    'correspondence' => 'Correspondence',
    'registration' => 'Registration',
    'discipline' => 'Discipline',
    'fixture' => 'Fixture',
    'committee' => 'Committee/Governance',
    'compliance' => 'Compliance',
    'other' => 'Other',
];

function secretary_tasks_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS correspondence_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            received_at DATETIME NULL,
            organisation VARCHAR(190) NULL,
            subject VARCHAR(255) NOT NULL,
            label ENUM('action_urgent','action','waiting','fixture','registration','discipline','committee','filed') NOT NULL DEFAULT 'action',
            deadline DATE NULL,
            action_owner VARCHAR(190) NULL,
            status ENUM('open','waiting','filed') NOT NULL DEFAULT 'open',
            reference VARCHAR(255) NULL,
            notes TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_correspondence_log_status (status),
            KEY idx_correspondence_log_deadline (deadline)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS secretary_tasks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            category ENUM('correspondence','registration','discipline','fixture','committee','compliance','other') NOT NULL DEFAULT 'other',
            title VARCHAR(255) NOT NULL,
            owner VARCHAR(190) NULL,
            due_at DATE NULL,
            priority ENUM('low','normal','urgent') NOT NULL DEFAULT 'normal',
            status ENUM('open','waiting','done') NOT NULL DEFAULT 'open',
            source VARCHAR(190) NULL,
            notes TEXT NULL,
            correspondence_id BIGINT UNSIGNED NULL,
            meeting_id BIGINT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            KEY idx_secretary_tasks_status (status),
            KEY idx_secretary_tasks_due (due_at),
            KEY idx_secretary_tasks_meeting (meeting_id),
            CONSTRAINT fk_secretary_tasks_correspondence FOREIGN KEY (correspondence_id) REFERENCES correspondence_log(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM secretary_tasks') as $row) {
        $columns[(string) $row['Field']] = true;
    }
    if (!isset($columns['meeting_id'])) {
        $pdo->exec('ALTER TABLE secretary_tasks ADD COLUMN meeting_id BIGINT UNSIGNED NULL AFTER correspondence_id, ADD KEY idx_secretary_tasks_meeting (meeting_id)');
    }

    $ensured = true;
}

/* ---------------------------------------------------------------------- */
/* Correspondence                                                         */
/* ---------------------------------------------------------------------- */

/**
 * @return list<array<string, mixed>>
 */
function correspondence_list(PDO $pdo, ?string $statusFilter = null): array
{
    secretary_tasks_ensure_schema($pdo);
    $sql = 'SELECT * FROM correspondence_log';
    $params = [];
    if ($statusFilter !== null) {
        $sql .= ' WHERE status = :status';
        $params[':status'] = $statusFilter;
    }
    $sql .= ' ORDER BY (deadline IS NULL), deadline ASC, received_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function correspondence_get(PDO $pdo, int $id): ?array
{
    secretary_tasks_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM correspondence_log WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param array<string, mixed> $data
 */
function correspondence_save(PDO $pdo, ?int $id, array $data, ?int $userId): int
{
    secretary_tasks_ensure_schema($pdo);

    $subject = trim((string) ($data['subject'] ?? ''));
    if ($subject === '') {
        throw new InvalidArgumentException('A subject is required.');
    }

    $label = (string) ($data['label'] ?? 'action');
    if (!array_key_exists($label, SECRETARY_TASK_LABELS)) {
        throw new InvalidArgumentException('Select a valid label.');
    }

    $status = (string) ($data['status'] ?? 'open');
    if (!in_array($status, ['open', 'waiting', 'filed'], true)) {
        throw new InvalidArgumentException('Select a valid status.');
    }

    $receivedAt = trim((string) ($data['received_at'] ?? ''));
    $receivedAt = $receivedAt !== '' ? $receivedAt : null;
    $organisation = trim((string) ($data['organisation'] ?? ''));
    $organisation = $organisation !== '' ? mb_substr($organisation, 0, 190) : null;
    $deadline = trim((string) ($data['deadline'] ?? ''));
    $deadline = $deadline !== '' ? $deadline : null;
    $actionOwner = trim((string) ($data['action_owner'] ?? ''));
    $actionOwner = $actionOwner !== '' ? mb_substr($actionOwner, 0, 190) : null;
    $reference = trim((string) ($data['reference'] ?? ''));
    $reference = $reference !== '' ? mb_substr($reference, 0, 255) : null;
    $notes = trim((string) ($data['notes'] ?? ''));
    $notes = $notes !== '' ? $notes : null;

    $params = [
        ':received_at' => $receivedAt,
        ':organisation' => $organisation,
        ':subject' => mb_substr($subject, 0, 255),
        ':label' => $label,
        ':deadline' => $deadline,
        ':action_owner' => $actionOwner,
        ':status' => $status,
        ':reference' => $reference,
        ':notes' => $notes,
    ];

    if ($id === null) {
        $params[':created_by'] = $userId;
        $stmt = $pdo->prepare(
            'INSERT INTO correspondence_log
                (received_at, organisation, subject, label, deadline, action_owner, status, reference, notes, created_by)
             VALUES
                (:received_at, :organisation, :subject, :label, :deadline, :action_owner, :status, :reference, :notes, :created_by)'
        );
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    }

    $params[':id'] = $id;
    $stmt = $pdo->prepare(
        'UPDATE correspondence_log SET
            received_at = :received_at, organisation = :organisation, subject = :subject, label = :label,
            deadline = :deadline, action_owner = :action_owner, status = :status, reference = :reference, notes = :notes
         WHERE id = :id'
    );
    $stmt->execute($params);
    return $id;
}

function correspondence_delete(PDO $pdo, int $id): bool
{
    secretary_tasks_ensure_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM correspondence_log WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

/* ---------------------------------------------------------------------- */
/* Tasks                                                                  */
/* ---------------------------------------------------------------------- */

/**
 * @return list<array<string, mixed>>
 */
function secretary_task_list(PDO $pdo, ?string $statusFilter = null): array
{
    secretary_tasks_ensure_schema($pdo);
    $sql = 'SELECT * FROM secretary_tasks';
    $params = [];
    if ($statusFilter !== null) {
        $sql .= ' WHERE status = :status';
        $params[':status'] = $statusFilter;
    }
    $sql .= ' ORDER BY (due_at IS NULL), due_at ASC, FIELD(priority,\'urgent\',\'normal\',\'low\')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function secretary_task_get(PDO $pdo, int $id): ?array
{
    secretary_tasks_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM secretary_tasks WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param array<string, mixed> $data
 */
function secretary_task_save(PDO $pdo, ?int $id, array $data, ?int $userId): int
{
    secretary_tasks_ensure_schema($pdo);

    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('A task title is required.');
    }

    $category = (string) ($data['category'] ?? 'other');
    if (!array_key_exists($category, SECRETARY_TASK_CATEGORIES)) {
        throw new InvalidArgumentException('Select a valid category.');
    }

    $priority = (string) ($data['priority'] ?? 'normal');
    if (!in_array($priority, ['low', 'normal', 'urgent'], true)) {
        throw new InvalidArgumentException('Select a valid priority.');
    }

    $status = (string) ($data['status'] ?? 'open');
    if (!in_array($status, ['open', 'waiting', 'done'], true)) {
        throw new InvalidArgumentException('Select a valid status.');
    }

    $owner = trim((string) ($data['owner'] ?? ''));
    $owner = $owner !== '' ? mb_substr($owner, 0, 190) : null;
    $dueAt = trim((string) ($data['due_at'] ?? ''));
    $dueAt = $dueAt !== '' ? $dueAt : null;
    $source = trim((string) ($data['source'] ?? ''));
    $source = $source !== '' ? mb_substr($source, 0, 190) : null;
    $notes = trim((string) ($data['notes'] ?? ''));
    $notes = $notes !== '' ? $notes : null;
    $correspondenceId = isset($data['correspondence_id']) && (int) $data['correspondence_id'] > 0 ? (int) $data['correspondence_id'] : null;
    $meetingId = isset($data['meeting_id']) && (int) $data['meeting_id'] > 0 ? (int) $data['meeting_id'] : null;
    $completedAt = $status === 'done' ? date('Y-m-d H:i:s') : null;

    $params = [
        ':category' => $category,
        ':title' => mb_substr($title, 0, 255),
        ':owner' => $owner,
        ':due_at' => $dueAt,
        ':priority' => $priority,
        ':status' => $status,
        ':source' => $source,
        ':notes' => $notes,
        ':correspondence_id' => $correspondenceId,
        ':meeting_id' => $meetingId,
        ':completed_at' => $completedAt,
    ];

    if ($id === null) {
        $params[':created_by'] = $userId;
        $stmt = $pdo->prepare(
            'INSERT INTO secretary_tasks
                (category, title, owner, due_at, priority, status, source, notes, correspondence_id, meeting_id, created_by, completed_at)
             VALUES
                (:category, :title, :owner, :due_at, :priority, :status, :source, :notes, :correspondence_id, :meeting_id, :created_by, :completed_at)'
        );
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    }

    $params[':id'] = $id;
    $stmt = $pdo->prepare(
        'UPDATE secretary_tasks SET
            category = :category, title = :title, owner = :owner, due_at = :due_at, priority = :priority,
            status = :status, source = :source, notes = :notes, correspondence_id = :correspondence_id,
            meeting_id = :meeting_id, completed_at = :completed_at
         WHERE id = :id'
    );
    $stmt->execute($params);
    return $id;
}

function secretary_task_delete(PDO $pdo, int $id): bool
{
    secretary_tasks_ensure_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM secretary_tasks WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

/**
 * @return list<array<string, mixed>>
 */
function secretary_tasks_for_meeting(PDO $pdo, int $meetingId): array
{
    secretary_tasks_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM secretary_tasks WHERE meeting_id = :meeting_id ORDER BY (due_at IS NULL), due_at ASC');
    $stmt->execute([':meeting_id' => $meetingId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Overdue or due-within-7-days open/waiting tasks — the dashboard's
 * "Urgent Tasks" card and the season-planner view both read from this.
 *
 * @return list<array<string, mixed>>
 */
function secretary_task_upcoming(PDO $pdo, int $withinDays = 7): array
{
    secretary_tasks_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT * FROM secretary_tasks
         WHERE status IN ('open','waiting')
           AND due_at IS NOT NULL
           AND due_at <= DATE_ADD(CURDATE(), INTERVAL :days DAY)
         ORDER BY due_at ASC, FIELD(priority,'urgent','normal','low')"
    );
    $stmt->execute([':days' => $withinDays]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
