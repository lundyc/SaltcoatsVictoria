<?php
declare(strict_types=1);

function matchday_staffing_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS matchday_staff_assignments (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        fixture_id INT UNSIGNED NOT NULL,
        role_key VARCHAR(60) NOT NULL,
        role_label VARCHAR(120) NOT NULL,
        person_id INT UNSIGNED NULL,
        report_time TIME NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'planned',
        notes VARCHAR(255) NULL,
        created_by_account_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_matchday_staff_fixture (fixture_id, status),
        KEY idx_matchday_staff_person (person_id),
        CONSTRAINT fk_matchday_staff_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE,
        CONSTRAINT fk_matchday_staff_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL,
        CONSTRAINT fk_matchday_staff_account FOREIGN KEY (created_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function matchday_staffing_roles(): array
{
    return [
        'gate' => 'Gate / Admissions',
        'turnstile' => 'Turnstile Scanner',
        'pos' => 'POS Operator',
        'bar' => 'Bar',
        'kitchen' => 'Kitchen',
        'steward' => 'Stewarding',
        'media' => 'Media / Social',
        'secretary' => 'Match Secretary',
        'kit' => 'Kit / Dressing Room',
        'first_aid' => 'First Aid',
        'other' => 'Other',
    ];
}

function matchday_staffing_statuses(): array
{
    return [
        'planned' => 'Planned',
        'confirmed' => 'Confirmed',
        'checked_in' => 'Checked in',
        'checked_out' => 'Checked out',
        'cancelled' => 'Cancelled',
    ];
}

function matchday_staffing_status_label(string $status): string
{
    $statuses = matchday_staffing_statuses();
    return $statuses[$status] ?? 'Planned';
}

function matchday_staffing_badge_class(string $status): string
{
    return match ($status) {
        'confirmed' => 'text-bg-primary',
        'checked_in' => 'text-bg-success',
        'checked_out' => 'text-bg-secondary',
        'cancelled' => 'text-bg-dark',
        default => 'text-bg-light',
    };
}

function matchday_staffing_role_label(string $roleKey, string $fallback = ''): string
{
    $roles = matchday_staffing_roles();
    if (isset($roles[$roleKey])) {
        return $roles[$roleKey];
    }
    $fallback = trim($fallback);
    return $fallback !== '' ? mb_substr($fallback, 0, 120) : 'Other';
}

/**
 * @return list<array<string,mixed>>
 */
function matchday_staffing_people_options(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, display_name, email
        FROM people
        WHERE is_active = 1
        ORDER BY display_name ASC, id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string,mixed>>
 */
function matchday_staffing_assignments(PDO $pdo, int $fixtureId): array
{
    matchday_staffing_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT a.*, p.display_name AS person_name, p.email AS person_email
        FROM matchday_staff_assignments a
        LEFT JOIN people p ON p.id = a.person_id
        WHERE a.fixture_id = :fixture_id
        ORDER BY
            FIELD(a.status, 'checked_in', 'confirmed', 'planned', 'checked_out', 'cancelled'),
            (a.report_time IS NULL), a.report_time ASC, a.role_label ASC, a.id ASC");
    $stmt->execute([':fixture_id' => $fixtureId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param list<array<string,mixed>> $assignments
 * @return array{total:int, active:int, confirmed:int, checked_in:int}
 */
function matchday_staffing_summary(array $assignments): array
{
    $summary = ['total' => count($assignments), 'active' => 0, 'confirmed' => 0, 'checked_in' => 0];
    foreach ($assignments as $assignment) {
        $status = (string) ($assignment['status'] ?? 'planned');
        if ($status !== 'cancelled') {
            $summary['active']++;
        }
        if (in_array($status, ['confirmed', 'checked_in', 'checked_out'], true)) {
            $summary['confirmed']++;
        }
        if ($status === 'checked_in') {
            $summary['checked_in']++;
        }
    }
    return $summary;
}

function matchday_staffing_save_assignment(PDO $pdo, int $fixtureId, ?int $assignmentId, array $data, ?int $accountId): int
{
    matchday_staffing_ensure_schema($pdo);
    if ($fixtureId <= 0) {
        throw new InvalidArgumentException('Fixture is required.');
    }

    $roleKey = trim((string) ($data['role_key'] ?? ''));
    if ($roleKey === '') {
        throw new InvalidArgumentException('Choose a matchday role.');
    }
    $roleLabel = matchday_staffing_role_label($roleKey, (string) ($data['role_label'] ?? ''));
    $personId = (int) ($data['person_id'] ?? 0);
    $reportTime = trim((string) ($data['report_time'] ?? ''));
    if ($reportTime !== '' && preg_match('/^\d{2}:\d{2}$/', $reportTime) !== 1) {
        throw new InvalidArgumentException('Report time must use HH:MM format.');
    }
    $status = trim((string) ($data['status'] ?? 'planned'));
    if (!array_key_exists($status, matchday_staffing_statuses())) {
        throw new InvalidArgumentException('Choose a valid status.');
    }
    $notes = trim((string) ($data['notes'] ?? ''));

    $params = [
        ':fixture_id' => $fixtureId,
        ':role_key' => mb_substr($roleKey, 0, 60),
        ':role_label' => $roleLabel,
        ':person_id' => $personId > 0 ? $personId : null,
        ':report_time' => $reportTime !== '' ? $reportTime : null,
        ':status' => $status,
        ':notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null,
        ':account_id' => $accountId,
    ];

    if ($assignmentId !== null && $assignmentId > 0) {
        $params[':id'] = $assignmentId;
        $stmt = $pdo->prepare("UPDATE matchday_staff_assignments
            SET role_key = :role_key, role_label = :role_label, person_id = :person_id,
                report_time = :report_time, status = :status, notes = :notes
            WHERE id = :id AND fixture_id = :fixture_id");
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Staff assignment not found.');
        }
        return $assignmentId;
    }

    $stmt = $pdo->prepare("INSERT INTO matchday_staff_assignments
        (fixture_id, role_key, role_label, person_id, report_time, status, notes, created_by_account_id)
        VALUES (:fixture_id, :role_key, :role_label, :person_id, :report_time, :status, :notes, :account_id)");
    $stmt->execute($params);
    return (int) $pdo->lastInsertId();
}

function matchday_staffing_update_status(PDO $pdo, int $fixtureId, int $assignmentId, string $status): void
{
    matchday_staffing_ensure_schema($pdo);
    if (!array_key_exists($status, matchday_staffing_statuses())) {
        throw new InvalidArgumentException('Choose a valid status.');
    }

    $stmt = $pdo->prepare('UPDATE matchday_staff_assignments SET status = :status WHERE id = :id AND fixture_id = :fixture_id');
    $stmt->execute([':status' => $status, ':id' => $assignmentId, ':fixture_id' => $fixtureId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Staff assignment not found.');
    }
}

function matchday_staffing_delete_assignment(PDO $pdo, int $fixtureId, int $assignmentId): void
{
    matchday_staffing_ensure_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM matchday_staff_assignments WHERE id = :id AND fixture_id = :fixture_id');
    $stmt->execute([':id' => $assignmentId, ':fixture_id' => $fixtureId]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Staff assignment not found.');
    }
}
