<?php

declare(strict_types=1);

/**
 * Fixture change requests (Secretary Handbook section 12): "an agreement
 * between two clubs does not necessarily make a fixture change official."
 * This is a request/approval audit trail alongside the fixture, not a
 * replacement for actually editing match_fixtures via match.php — the
 * Secretary still updates the real fixture once the competition has
 * approved it. The checklist columns mirror checklist D in the handbook
 * almost verbatim.
 */

function fixture_change_requests_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fixture_change_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fixture_id INT UNSIGNED NOT NULL,
            requested_by VARCHAR(190) NULL,
            reason TEXT NULL,
            requested_date DATE NULL,
            requested_kickoff_time TIME NULL,
            requested_venue VARCHAR(190) NULL,
            club_availability_checked TINYINT(1) NOT NULL DEFAULT 0,
            opposition_agreement_recorded TINYINT(1) NOT NULL DEFAULT 0,
            competition_approval_requested TINYINT(1) NOT NULL DEFAULT 0,
            competition_approval_received TINYINT(1) NOT NULL DEFAULT 0,
            officials_informed TINYINT(1) NOT NULL DEFAULT 0,
            public_updated TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('requested','pending_approval','approved','confirmed','rejected') NOT NULL DEFAULT 'requested',
            notes TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_fixture_change_requests_fixture (fixture_id),
            KEY idx_fixture_change_requests_status (status),
            CONSTRAINT fk_fixture_change_requests_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

/**
 * @return list<array<string, mixed>>
 */
function fixture_change_requests_list(PDO $pdo): array
{
    fixture_change_requests_ensure_schema($pdo);
    $stmt = $pdo->query(
        "SELECT fcr.*, mf.match_date, mf.opponent, mf.competition, mf.venue AS current_venue, mf.kickoff_time AS current_kickoff_time
         FROM fixture_change_requests fcr
         INNER JOIN match_fixtures mf ON mf.id = fcr.fixture_id
         ORDER BY fcr.status = 'confirmed', fcr.status = 'rejected', fcr.created_at DESC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function fixture_change_request_get(PDO $pdo, int $id): ?array
{
    fixture_change_requests_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT fcr.*, mf.match_date, mf.opponent, mf.competition, mf.venue AS current_venue, mf.kickoff_time AS current_kickoff_time
         FROM fixture_change_requests fcr
         INNER JOIN match_fixtures mf ON mf.id = fcr.fixture_id
         WHERE fcr.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param array<string, mixed> $data
 */
function fixture_change_request_save(PDO $pdo, ?int $id, array $data, ?int $userId): int
{
    fixture_change_requests_ensure_schema($pdo);

    $fixtureId = (int) ($data['fixture_id'] ?? 0);
    if ($fixtureId <= 0) {
        throw new InvalidArgumentException('Select a fixture.');
    }

    $status = (string) ($data['status'] ?? 'requested');
    if (!in_array($status, ['requested', 'pending_approval', 'approved', 'confirmed', 'rejected'], true)) {
        throw new InvalidArgumentException('Select a valid status.');
    }

    $requestedBy = trim((string) ($data['requested_by'] ?? ''));
    $requestedBy = $requestedBy !== '' ? mb_substr($requestedBy, 0, 190) : null;
    $reason = trim((string) ($data['reason'] ?? ''));
    $reason = $reason !== '' ? $reason : null;
    $requestedDate = trim((string) ($data['requested_date'] ?? ''));
    $requestedDate = $requestedDate !== '' ? $requestedDate : null;
    $requestedKickoffTime = trim((string) ($data['requested_kickoff_time'] ?? ''));
    $requestedKickoffTime = $requestedKickoffTime !== '' ? $requestedKickoffTime : null;
    $requestedVenue = trim((string) ($data['requested_venue'] ?? ''));
    $requestedVenue = $requestedVenue !== '' ? mb_substr($requestedVenue, 0, 190) : null;
    $notes = trim((string) ($data['notes'] ?? ''));
    $notes = $notes !== '' ? $notes : null;

    $params = [
        ':fixture_id' => $fixtureId,
        ':requested_by' => $requestedBy,
        ':reason' => $reason,
        ':requested_date' => $requestedDate,
        ':requested_kickoff_time' => $requestedKickoffTime,
        ':requested_venue' => $requestedVenue,
        ':club_availability_checked' => !empty($data['club_availability_checked']) ? 1 : 0,
        ':opposition_agreement_recorded' => !empty($data['opposition_agreement_recorded']) ? 1 : 0,
        ':competition_approval_requested' => !empty($data['competition_approval_requested']) ? 1 : 0,
        ':competition_approval_received' => !empty($data['competition_approval_received']) ? 1 : 0,
        ':officials_informed' => !empty($data['officials_informed']) ? 1 : 0,
        ':public_updated' => !empty($data['public_updated']) ? 1 : 0,
        ':status' => $status,
        ':notes' => $notes,
    ];

    if ($id === null) {
        $params[':created_by'] = $userId;
        $stmt = $pdo->prepare(
            'INSERT INTO fixture_change_requests
                (fixture_id, requested_by, reason, requested_date, requested_kickoff_time, requested_venue,
                 club_availability_checked, opposition_agreement_recorded, competition_approval_requested,
                 competition_approval_received, officials_informed, public_updated, status, notes, created_by)
             VALUES
                (:fixture_id, :requested_by, :reason, :requested_date, :requested_kickoff_time, :requested_venue,
                 :club_availability_checked, :opposition_agreement_recorded, :competition_approval_requested,
                 :competition_approval_received, :officials_informed, :public_updated, :status, :notes, :created_by)'
        );
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    }

    $params[':id'] = $id;
    $stmt = $pdo->prepare(
        'UPDATE fixture_change_requests SET
            fixture_id = :fixture_id, requested_by = :requested_by, reason = :reason,
            requested_date = :requested_date, requested_kickoff_time = :requested_kickoff_time, requested_venue = :requested_venue,
            club_availability_checked = :club_availability_checked, opposition_agreement_recorded = :opposition_agreement_recorded,
            competition_approval_requested = :competition_approval_requested, competition_approval_received = :competition_approval_received,
            officials_informed = :officials_informed, public_updated = :public_updated, status = :status, notes = :notes
         WHERE id = :id'
    );
    $stmt->execute($params);
    return $id;
}

function fixture_change_request_delete(PDO $pdo, int $id): bool
{
    fixture_change_requests_ensure_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM fixture_change_requests WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}
