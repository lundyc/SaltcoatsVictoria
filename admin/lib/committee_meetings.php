<?php

declare(strict_types=1);

/**
 * Committee meetings & minutes (Secretary Handbook section 18), and the
 * AGM checklist (section 19) as a set of fields on the same record when
 * meeting_type = 'agm' — an AGM is a committee meeting with extra
 * governance steps to track, not a separate subsystem. Actions arising
 * from a meeting are ordinary secretary_tasks (category = 'committee')
 * linked back via meeting_id, so there is one task list in the app, not two.
 */

function committee_meetings_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS committee_meetings (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            meeting_type ENUM('committee','agm','egm') NOT NULL DEFAULT 'committee',
            meeting_date DATE NOT NULL,
            location VARCHAR(190) NULL,
            attendees TEXT NULL,
            apologies TEXT NULL,
            minutes TEXT NULL,
            next_meeting_date DATE NULL,
            status ENUM('draft','final') NOT NULL DEFAULT 'draft',
            agm_constitution_version VARCHAR(50) NULL,
            agm_notice_issued TINYINT(1) NOT NULL DEFAULT 0,
            agm_reports_prepared TINYINT(1) NOT NULL DEFAULT 0,
            agm_nominations_handled TINYINT(1) NOT NULL DEFAULT 0,
            agm_quorum_recorded TINYINT(1) NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_committee_meetings_date (meeting_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

/**
 * @return list<array<string, mixed>>
 */
function committee_meetings_list(PDO $pdo): array
{
    committee_meetings_ensure_schema($pdo);
    $stmt = $pdo->query('SELECT * FROM committee_meetings ORDER BY meeting_date DESC, id DESC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function committee_meeting_get(PDO $pdo, int $id): ?array
{
    committee_meetings_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM committee_meetings WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function committee_meeting_next(PDO $pdo): ?array
{
    committee_meetings_ensure_schema($pdo);
    $stmt = $pdo->query(
        "SELECT * FROM committee_meetings WHERE next_meeting_date IS NOT NULL AND next_meeting_date >= CURDATE()
         ORDER BY next_meeting_date ASC LIMIT 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param array<string, mixed> $data
 */
function committee_meeting_save(PDO $pdo, ?int $id, array $data, ?int $userId): int
{
    committee_meetings_ensure_schema($pdo);

    $meetingDate = trim((string) ($data['meeting_date'] ?? ''));
    if ($meetingDate === '') {
        throw new InvalidArgumentException('A meeting date is required.');
    }

    $meetingType = (string) ($data['meeting_type'] ?? 'committee');
    if (!in_array($meetingType, ['committee', 'agm', 'egm'], true)) {
        throw new InvalidArgumentException('Select a valid meeting type.');
    }

    $status = (string) ($data['status'] ?? 'draft');
    if (!in_array($status, ['draft', 'final'], true)) {
        throw new InvalidArgumentException('Select a valid status.');
    }

    $location = trim((string) ($data['location'] ?? ''));
    $location = $location !== '' ? mb_substr($location, 0, 190) : null;
    $attendees = trim((string) ($data['attendees'] ?? ''));
    $attendees = $attendees !== '' ? $attendees : null;
    $apologies = trim((string) ($data['apologies'] ?? ''));
    $apologies = $apologies !== '' ? $apologies : null;
    $minutes = trim((string) ($data['minutes'] ?? ''));
    $minutes = $minutes !== '' ? $minutes : null;
    $nextMeetingDate = trim((string) ($data['next_meeting_date'] ?? ''));
    $nextMeetingDate = $nextMeetingDate !== '' ? $nextMeetingDate : null;
    $agmConstitutionVersion = trim((string) ($data['agm_constitution_version'] ?? ''));
    $agmConstitutionVersion = $agmConstitutionVersion !== '' ? mb_substr($agmConstitutionVersion, 0, 50) : null;

    $params = [
        ':meeting_type' => $meetingType,
        ':meeting_date' => $meetingDate,
        ':location' => $location,
        ':attendees' => $attendees,
        ':apologies' => $apologies,
        ':minutes' => $minutes,
        ':next_meeting_date' => $nextMeetingDate,
        ':status' => $status,
        ':agm_constitution_version' => $agmConstitutionVersion,
        ':agm_notice_issued' => !empty($data['agm_notice_issued']) ? 1 : 0,
        ':agm_reports_prepared' => !empty($data['agm_reports_prepared']) ? 1 : 0,
        ':agm_nominations_handled' => !empty($data['agm_nominations_handled']) ? 1 : 0,
        ':agm_quorum_recorded' => !empty($data['agm_quorum_recorded']) ? 1 : 0,
    ];

    if ($id === null) {
        $params[':created_by'] = $userId;
        $stmt = $pdo->prepare(
            'INSERT INTO committee_meetings
                (meeting_type, meeting_date, location, attendees, apologies, minutes, next_meeting_date, status,
                 agm_constitution_version, agm_notice_issued, agm_reports_prepared, agm_nominations_handled, agm_quorum_recorded, created_by)
             VALUES
                (:meeting_type, :meeting_date, :location, :attendees, :apologies, :minutes, :next_meeting_date, :status,
                 :agm_constitution_version, :agm_notice_issued, :agm_reports_prepared, :agm_nominations_handled, :agm_quorum_recorded, :created_by)'
        );
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    }

    $params[':id'] = $id;
    $stmt = $pdo->prepare(
        'UPDATE committee_meetings SET
            meeting_type = :meeting_type, meeting_date = :meeting_date, location = :location,
            attendees = :attendees, apologies = :apologies, minutes = :minutes, next_meeting_date = :next_meeting_date,
            status = :status, agm_constitution_version = :agm_constitution_version, agm_notice_issued = :agm_notice_issued,
            agm_reports_prepared = :agm_reports_prepared, agm_nominations_handled = :agm_nominations_handled,
            agm_quorum_recorded = :agm_quorum_recorded
         WHERE id = :id'
    );
    $stmt->execute($params);
    return $id;
}

function committee_meeting_delete(PDO $pdo, int $id): bool
{
    committee_meetings_ensure_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM committee_meetings WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}
