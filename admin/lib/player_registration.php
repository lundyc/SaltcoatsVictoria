<?php

declare(strict_types=1);

/**
 * Player registration / COMET status tracking (Secretary Handbook section 7:
 * "check the resulting status — do not assume submission equals acceptance").
 *
 * Each row is one registration event a Secretary has logged (new signing,
 * transfer, re-registration, loan, international clearance, termination).
 * A player's *current* status is simply their latest logged event — kept as
 * a log rather than a single mutable record so there's an audit trail of
 * what was submitted, when, and what was told to the manager, matching the
 * handbook's "file the supporting record" step.
 */

function registration_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS player_registrations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            player_id INT UNSIGNED NOT NULL,
            registration_type ENUM('new','transfer','re_registration','loan','international','termination','other') NOT NULL DEFAULT 'new',
            comet_reference VARCHAR(120) NULL,
            submitted_at DATETIME NULL,
            comet_status ENUM('not_started','entered','confirmed','rejected','terminated') NOT NULL DEFAULT 'not_started',
            competition_eligibility_checked TINYINT(1) NOT NULL DEFAULT 0,
            manager_confirmation ENUM('not_sent','confirmed_eligible','not_yet_confirmed','requires_further_check') NOT NULL DEFAULT 'not_sent',
            manager_confirmation_sent_at DATETIME NULL,
            notes TEXT NULL,
            evidence_reference VARCHAR(255) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_player_registrations_player (player_id),
            CONSTRAINT fk_player_registrations_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

/**
 * GREEN/AMBER/RED + the exact wording the handbook's traffic-light table
 * (section 9) says to give the manager, from one registration row.
 *
 * @param array<string, mixed>|null $row
 * @return array{status: 'green'|'amber'|'red', label: string}
 */
function registration_traffic_light(?array $row): array
{
    if ($row === null) {
        return ['status' => 'amber', 'label' => 'NOT YET CONFIRMED'];
    }

    $cometStatus = (string) ($row['comet_status'] ?? '');
    $managerConfirmation = (string) ($row['manager_confirmation'] ?? '');

    if (in_array($cometStatus, ['rejected', 'terminated'], true)) {
        return ['status' => 'red', 'label' => 'NOT ELIGIBLE FOR THIS MATCH'];
    }

    if ($cometStatus === 'confirmed' && $managerConfirmation === 'confirmed_eligible' && (int) ($row['competition_eligibility_checked'] ?? 0) === 1) {
        return ['status' => 'green', 'label' => 'CONFIRMED ELIGIBLE'];
    }

    if ($managerConfirmation === 'requires_further_check') {
        return ['status' => 'amber', 'label' => 'REQUIRES FURTHER CHECK'];
    }

    return ['status' => 'amber', 'label' => 'NOT YET CONFIRMED'];
}

/**
 * @return list<array<string, mixed>> newest first
 */
function registration_list_for_player(PDO $pdo, int $playerId): array
{
    registration_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM player_registrations WHERE player_id = :player_id ORDER BY id DESC');
    $stmt->execute([':player_id' => $playerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function registration_current_for_player(PDO $pdo, int $playerId): ?array
{
    registration_ensure_schema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM player_registrations WHERE player_id = :player_id ORDER BY id DESC LIMIT 1');
    $stmt->execute([':player_id' => $playerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Every current-squad player with their latest registration event (if any),
 * for the Secretary's registration list page — one query rather than one
 * per player.
 *
 * @return list<array<string, mixed>>
 */
function registration_current_all(PDO $pdo): array
{
    registration_ensure_schema($pdo);
    $stmt = $pdo->query(
        "SELECT
            p.id AS player_id, p.name AS player_name, p.status AS player_status,
            r.id AS registration_id, r.registration_type, r.comet_reference, r.submitted_at,
            r.comet_status, r.competition_eligibility_checked, r.manager_confirmation,
            r.manager_confirmation_sent_at, r.notes, r.evidence_reference,
            r.created_by, r.created_at, r.updated_at
         FROM players p
         LEFT JOIN (
            SELECT pr.*, ROW_NUMBER() OVER (PARTITION BY pr.player_id ORDER BY pr.id DESC) AS rn
            FROM player_registrations pr
         ) r ON r.player_id = p.id AND r.rn = 1
         WHERE p.status IN ('current', 'trialist', 'loan')
         ORDER BY p.name"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function registration_get(PDO $pdo, int $id): ?array
{
    registration_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT pr.*, p.name AS player_name FROM player_registrations pr
         INNER JOIN players p ON p.id = pr.player_id
         WHERE pr.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param array<string, mixed> $data
 */
function registration_save(PDO $pdo, ?int $id, array $data, ?int $userId): int
{
    registration_ensure_schema($pdo);

    $playerId = (int) ($data['player_id'] ?? 0);
    if ($playerId <= 0) {
        throw new InvalidArgumentException('Select a player.');
    }

    $registrationType = (string) ($data['registration_type'] ?? 'new');
    if (!in_array($registrationType, ['new', 'transfer', 're_registration', 'loan', 'international', 'termination', 'other'], true)) {
        throw new InvalidArgumentException('Select a valid registration type.');
    }

    $cometStatus = (string) ($data['comet_status'] ?? 'not_started');
    if (!in_array($cometStatus, ['not_started', 'entered', 'confirmed', 'rejected', 'terminated'], true)) {
        throw new InvalidArgumentException('Select a valid COMET status.');
    }

    $managerConfirmation = (string) ($data['manager_confirmation'] ?? 'not_sent');
    if (!in_array($managerConfirmation, ['not_sent', 'confirmed_eligible', 'not_yet_confirmed', 'requires_further_check'], true)) {
        throw new InvalidArgumentException('Select a valid manager confirmation state.');
    }

    $cometReference = trim((string) ($data['comet_reference'] ?? ''));
    $cometReference = $cometReference !== '' ? mb_substr($cometReference, 0, 120) : null;
    $submittedAt = trim((string) ($data['submitted_at'] ?? ''));
    $submittedAt = $submittedAt !== '' ? $submittedAt : null;
    $competitionEligibilityChecked = !empty($data['competition_eligibility_checked']) ? 1 : 0;
    $managerConfirmationSentAt = trim((string) ($data['manager_confirmation_sent_at'] ?? ''));
    $managerConfirmationSentAt = $managerConfirmationSentAt !== '' ? $managerConfirmationSentAt : null;
    $notes = trim((string) ($data['notes'] ?? ''));
    $notes = $notes !== '' ? $notes : null;
    $evidenceReference = trim((string) ($data['evidence_reference'] ?? ''));
    $evidenceReference = $evidenceReference !== '' ? mb_substr($evidenceReference, 0, 255) : null;

    $params = [
        ':player_id' => $playerId,
        ':registration_type' => $registrationType,
        ':comet_reference' => $cometReference,
        ':submitted_at' => $submittedAt,
        ':comet_status' => $cometStatus,
        ':competition_eligibility_checked' => $competitionEligibilityChecked,
        ':manager_confirmation' => $managerConfirmation,
        ':manager_confirmation_sent_at' => $managerConfirmationSentAt,
        ':notes' => $notes,
        ':evidence_reference' => $evidenceReference,
    ];

    if ($id === null) {
        $params[':created_by'] = $userId;
        $stmt = $pdo->prepare(
            'INSERT INTO player_registrations
                (player_id, registration_type, comet_reference, submitted_at, comet_status,
                 competition_eligibility_checked, manager_confirmation, manager_confirmation_sent_at,
                 notes, evidence_reference, created_by)
             VALUES
                (:player_id, :registration_type, :comet_reference, :submitted_at, :comet_status,
                 :competition_eligibility_checked, :manager_confirmation, :manager_confirmation_sent_at,
                 :notes, :evidence_reference, :created_by)'
        );
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    }

    $params[':id'] = $id;
    $stmt = $pdo->prepare(
        'UPDATE player_registrations SET
            player_id = :player_id,
            registration_type = :registration_type,
            comet_reference = :comet_reference,
            submitted_at = :submitted_at,
            comet_status = :comet_status,
            competition_eligibility_checked = :competition_eligibility_checked,
            manager_confirmation = :manager_confirmation,
            manager_confirmation_sent_at = :manager_confirmation_sent_at,
            notes = :notes,
            evidence_reference = :evidence_reference
         WHERE id = :id'
    );
    $stmt->execute($params);
    return $id;
}

function registration_delete(PDO $pdo, int $id): bool
{
    registration_ensure_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM player_registrations WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}
