<?php
declare(strict_types=1);

const FACILITY_MAINTENANCE_CATEGORIES = [
    'pitch' => 'Pitch',
    'ground' => 'Ground',
    'clubhouse' => 'Clubhouse',
    'equipment' => 'Equipment',
    'safety' => 'Safety',
    'other' => 'Other',
];

const FACILITY_MAINTENANCE_PRIORITIES = [
    'low' => 'Low',
    'normal' => 'Normal',
    'urgent' => 'Urgent',
];

const FACILITY_MAINTENANCE_STATUSES = [
    'open' => 'Open',
    'planned' => 'Planned',
    'in_progress' => 'In progress',
    'done' => 'Done',
    'cancelled' => 'Cancelled',
];

function facility_maintenance_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS facility_maintenance_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        area VARCHAR(120) NOT NULL DEFAULT '',
        category ENUM('pitch','ground','clubhouse','equipment','safety','other') NOT NULL DEFAULT 'other',
        title VARCHAR(255) NOT NULL,
        owner VARCHAR(190) NULL,
        due_at DATE NULL,
        priority ENUM('low','normal','urgent') NOT NULL DEFAULT 'normal',
        status ENUM('open','planned','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
        reported_by VARCHAR(190) NULL,
        notes TEXT NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        completed_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_facility_jobs_status_due (status, due_at),
        KEY idx_facility_jobs_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ensured = true;
}

function facility_maintenance_priority_tone(string $priority): string
{
    return match ($priority) {
        'urgent' => 'danger',
        'low' => 'secondary',
        default => 'info',
    };
}

function facility_maintenance_status_tone(string $status): string
{
    return match ($status) {
        'done' => 'success',
        'cancelled' => 'secondary',
        'in_progress' => 'info',
        'planned' => 'primary',
        default => 'warning',
    };
}

function facility_maintenance_is_open_status(string $status): bool
{
    return !in_array($status, ['done', 'cancelled'], true);
}

function facility_maintenance_is_overdue(array $job, ?string $today = null): bool
{
    $dueAt = trim((string) ($job['due_at'] ?? ''));
    if ($dueAt === '' || !facility_maintenance_is_open_status((string) ($job['status'] ?? 'open'))) {
        return false;
    }

    return $dueAt < ($today ?: date('Y-m-d'));
}

/**
 * @param list<array<string, mixed>> $jobs
 * @return array{total: int, open: int, overdue: int, urgent: int, done: int}
 */
function facility_maintenance_summary(array $jobs, ?string $today = null): array
{
    $summary = ['total' => count($jobs), 'open' => 0, 'overdue' => 0, 'urgent' => 0, 'done' => 0];
    foreach ($jobs as $job) {
        $status = (string) ($job['status'] ?? 'open');
        if (facility_maintenance_is_open_status($status)) {
            $summary['open']++;
        }
        if ($status === 'done') {
            $summary['done']++;
        }
        if ((string) ($job['priority'] ?? 'normal') === 'urgent' && facility_maintenance_is_open_status($status)) {
            $summary['urgent']++;
        }
        if (facility_maintenance_is_overdue($job, $today)) {
            $summary['overdue']++;
        }
    }

    return $summary;
}

/**
 * @return list<array<string, mixed>>
 */
function facility_maintenance_list(PDO $pdo, string $statusFilter = 'open'): array
{
    facility_maintenance_ensure_schema($pdo);
    $where = '';
    if ($statusFilter === 'open') {
        $where = "WHERE status NOT IN ('done','cancelled')";
    } elseif ($statusFilter === 'history') {
        $where = "WHERE status IN ('done','cancelled')";
    }

    $stmt = $pdo->query("SELECT * FROM facility_maintenance_jobs {$where}
        ORDER BY FIELD(priority, 'urgent', 'normal', 'low'), (due_at IS NULL), due_at ASC, created_at DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<string, mixed> $data
 */
function facility_maintenance_save(PDO $pdo, ?int $id, array $data, ?int $userId = null): int
{
    facility_maintenance_ensure_schema($pdo);
    $title = trim((string) ($data['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('A job title is required.');
    }

    $category = (string) ($data['category'] ?? 'other');
    if (!array_key_exists($category, FACILITY_MAINTENANCE_CATEGORIES)) {
        throw new InvalidArgumentException('Select a valid category.');
    }

    $priority = (string) ($data['priority'] ?? 'normal');
    if (!array_key_exists($priority, FACILITY_MAINTENANCE_PRIORITIES)) {
        throw new InvalidArgumentException('Select a valid priority.');
    }

    $status = (string) ($data['status'] ?? 'open');
    if (!array_key_exists($status, FACILITY_MAINTENANCE_STATUSES)) {
        throw new InvalidArgumentException('Select a valid status.');
    }

    $dueAt = trim((string) ($data['due_at'] ?? ''));
    $params = [
        ':area' => mb_substr(trim((string) ($data['area'] ?? '')), 0, 120),
        ':category' => $category,
        ':title' => mb_substr($title, 0, 255),
        ':owner' => facility_maintenance_nullable_text((string) ($data['owner'] ?? ''), 190),
        ':due_at' => $dueAt !== '' ? $dueAt : null,
        ':priority' => $priority,
        ':status' => $status,
        ':reported_by' => facility_maintenance_nullable_text((string) ($data['reported_by'] ?? ''), 190),
        ':notes' => facility_maintenance_nullable_text((string) ($data['notes'] ?? ''), 5000),
        ':completed_at' => $status === 'done' ? date('Y-m-d H:i:s') : null,
    ];

    if ($id === null) {
        $params[':created_by'] = $userId;
        $stmt = $pdo->prepare('INSERT INTO facility_maintenance_jobs
            (area, category, title, owner, due_at, priority, status, reported_by, notes, created_by, completed_at)
            VALUES (:area, :category, :title, :owner, :due_at, :priority, :status, :reported_by, :notes, :created_by, :completed_at)');
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    }

    $params[':id'] = $id;
    $stmt = $pdo->prepare('UPDATE facility_maintenance_jobs SET
        area = :area, category = :category, title = :title, owner = :owner, due_at = :due_at,
        priority = :priority, status = :status, reported_by = :reported_by, notes = :notes,
        completed_at = :completed_at
        WHERE id = :id');
    $stmt->execute($params);
    return $id;
}

function facility_maintenance_update_status(PDO $pdo, int $id, string $status): void
{
    facility_maintenance_ensure_schema($pdo);
    if (!array_key_exists($status, FACILITY_MAINTENANCE_STATUSES)) {
        throw new InvalidArgumentException('Select a valid status.');
    }

    $stmt = $pdo->prepare('UPDATE facility_maintenance_jobs
        SET status = :status, completed_at = :completed_at
        WHERE id = :id');
    $stmt->execute([
        ':id' => $id,
        ':status' => $status,
        ':completed_at' => $status === 'done' ? date('Y-m-d H:i:s') : null,
    ]);
}

function facility_maintenance_nullable_text(string $value, int $limit): ?string
{
    $value = trim($value);
    return $value !== '' ? mb_substr($value, 0, $limit) : null;
}
