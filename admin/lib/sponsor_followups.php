<?php
declare(strict_types=1);

const SPONSOR_FOLLOWUP_STAGES = [
    'lead' => 'Lead',
    'contacted' => 'Contacted',
    'proposal_sent' => 'Proposal sent',
    'negotiating' => 'Negotiating',
    'won' => 'Won',
    'lost' => 'Lost',
    'on_hold' => 'On hold',
];

const SPONSOR_FOLLOWUP_PRIORITIES = [
    'low' => 'Low',
    'normal' => 'Normal',
    'urgent' => 'Urgent',
];

function sponsor_followups_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS sponsor_followups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sponsor_id INT UNSIGNED NOT NULL,
        stage ENUM('lead','contacted','proposal_sent','negotiating','won','lost','on_hold') NOT NULL DEFAULT 'lead',
        title VARCHAR(255) NOT NULL,
        owner VARCHAR(190) NULL,
        next_contact_at DATE NULL,
        expected_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        priority ENUM('low','normal','urgent') NOT NULL DEFAULT 'normal',
        last_contact_at DATE NULL,
        notes TEXT NULL,
        created_by BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        closed_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_sponsor_followups_sponsor (sponsor_id),
        KEY idx_sponsor_followups_stage_contact (stage, next_contact_at),
        CONSTRAINT fk_sponsor_followups_sponsor FOREIGN KEY (sponsor_id) REFERENCES sponsors(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ensured = true;
}

function sponsor_followup_is_open_stage(string $stage): bool
{
    return !in_array($stage, ['won', 'lost'], true);
}

function sponsor_followup_is_overdue(array $followup, ?string $today = null): bool
{
    $nextContact = trim((string) ($followup['next_contact_at'] ?? ''));
    if ($nextContact === '' || !sponsor_followup_is_open_stage((string) ($followup['stage'] ?? 'lead'))) {
        return false;
    }

    return $nextContact < ($today ?: date('Y-m-d'));
}

function sponsor_followup_stage_tone(string $stage): string
{
    return match ($stage) {
        'won' => 'success',
        'lost' => 'secondary',
        'proposal_sent', 'negotiating' => 'primary',
        'on_hold' => 'info',
        default => 'warning',
    };
}

function sponsor_followup_priority_tone(string $priority): string
{
    return match ($priority) {
        'urgent' => 'danger',
        'low' => 'secondary',
        default => 'info',
    };
}

/**
 * @param list<array<string, mixed>> $followups
 * @return array{total: int, open: int, overdue: int, urgent: int, pipeline_value: float, won_value: float}
 */
function sponsor_followup_summary(array $followups, ?string $today = null): array
{
    $summary = ['total' => count($followups), 'open' => 0, 'overdue' => 0, 'urgent' => 0, 'pipeline_value' => 0.0, 'won_value' => 0.0];
    foreach ($followups as $followup) {
        $stage = (string) ($followup['stage'] ?? 'lead');
        $value = (float) ($followup['expected_value'] ?? 0);
        if (sponsor_followup_is_open_stage($stage)) {
            $summary['open']++;
            $summary['pipeline_value'] += $value;
        }
        if ($stage === 'won') {
            $summary['won_value'] += $value;
        }
        if ((string) ($followup['priority'] ?? 'normal') === 'urgent' && sponsor_followup_is_open_stage($stage)) {
            $summary['urgent']++;
        }
        if (sponsor_followup_is_overdue($followup, $today)) {
            $summary['overdue']++;
        }
    }
    $summary['pipeline_value'] = round($summary['pipeline_value'], 2);
    $summary['won_value'] = round($summary['won_value'], 2);

    return $summary;
}

/**
 * @return list<array<string, mixed>>
 */
function sponsor_followup_list(PDO $pdo, string $stageFilter = 'open'): array
{
    sponsor_followups_ensure_schema($pdo);
    $where = '';
    if ($stageFilter === 'open') {
        $where = "WHERE f.stage NOT IN ('won','lost')";
    } elseif ($stageFilter === 'closed') {
        $where = "WHERE f.stage IN ('won','lost')";
    }

    $stmt = $pdo->query("SELECT f.*, s.name AS sponsor_name, s.contact_email, s.contact_phone
        FROM sponsor_followups f
        JOIN sponsors s ON s.id = f.sponsor_id
        {$where}
        ORDER BY FIELD(f.priority, 'urgent', 'normal', 'low'), (f.next_contact_at IS NULL), f.next_contact_at ASC, f.updated_at DESC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<string, mixed> $data
 */
function sponsor_followup_save(PDO $pdo, ?int $id, array $data, ?int $userId = null): int
{
    sponsor_followups_ensure_schema($pdo);
    $sponsorId = max(0, (int) ($data['sponsor_id'] ?? 0));
    $title = trim((string) ($data['title'] ?? ''));
    if ($sponsorId <= 0) {
        throw new InvalidArgumentException('A sponsor is required.');
    }
    if ($title === '') {
        throw new InvalidArgumentException('A follow-up title is required.');
    }

    $stage = (string) ($data['stage'] ?? 'lead');
    if (!array_key_exists($stage, SPONSOR_FOLLOWUP_STAGES)) {
        throw new InvalidArgumentException('Select a valid stage.');
    }
    $priority = (string) ($data['priority'] ?? 'normal');
    if (!array_key_exists($priority, SPONSOR_FOLLOWUP_PRIORITIES)) {
        throw new InvalidArgumentException('Select a valid priority.');
    }

    $params = [
        ':sponsor_id' => $sponsorId,
        ':stage' => $stage,
        ':title' => mb_substr($title, 0, 255),
        ':owner' => sponsor_followup_nullable_text((string) ($data['owner'] ?? ''), 190),
        ':next_contact_at' => sponsor_followup_nullable_date((string) ($data['next_contact_at'] ?? '')),
        ':expected_value' => round(max(0, (float) ($data['expected_value'] ?? 0)), 2),
        ':priority' => $priority,
        ':last_contact_at' => sponsor_followup_nullable_date((string) ($data['last_contact_at'] ?? '')),
        ':notes' => sponsor_followup_nullable_text((string) ($data['notes'] ?? ''), 5000),
        ':closed_at' => sponsor_followup_is_open_stage($stage) ? null : date('Y-m-d H:i:s'),
    ];

    if ($id === null) {
        $params[':created_by'] = $userId;
        $stmt = $pdo->prepare('INSERT INTO sponsor_followups
            (sponsor_id, stage, title, owner, next_contact_at, expected_value, priority, last_contact_at, notes, created_by, closed_at)
            VALUES (:sponsor_id, :stage, :title, :owner, :next_contact_at, :expected_value, :priority, :last_contact_at, :notes, :created_by, :closed_at)');
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    }

    $params[':id'] = $id;
    $stmt = $pdo->prepare('UPDATE sponsor_followups SET
        sponsor_id = :sponsor_id, stage = :stage, title = :title, owner = :owner,
        next_contact_at = :next_contact_at, expected_value = :expected_value, priority = :priority,
        last_contact_at = :last_contact_at, notes = :notes, closed_at = :closed_at
        WHERE id = :id');
    $stmt->execute($params);
    return $id;
}

function sponsor_followup_update_stage(PDO $pdo, int $id, string $stage): void
{
    sponsor_followups_ensure_schema($pdo);
    if (!array_key_exists($stage, SPONSOR_FOLLOWUP_STAGES)) {
        throw new InvalidArgumentException('Select a valid stage.');
    }

    $stmt = $pdo->prepare('UPDATE sponsor_followups
        SET stage = :stage, last_contact_at = CURRENT_DATE(), closed_at = :closed_at
        WHERE id = :id');
    $stmt->execute([
        ':id' => $id,
        ':stage' => $stage,
        ':closed_at' => sponsor_followup_is_open_stage($stage) ? null : date('Y-m-d H:i:s'),
    ]);
}

function sponsor_followup_nullable_text(string $value, int $limit): ?string
{
    $value = trim($value);
    return $value !== '' ? mb_substr($value, 0, $limit) : null;
}

function sponsor_followup_nullable_date(string $value): ?string
{
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}
