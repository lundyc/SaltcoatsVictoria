<?php

declare(strict_types=1);

require_once __DIR__ . '/player_match_stats.php';

/**
 * Discipline & suspension register.
 *
 * Cards are already logged as free-text JSON events by match_events.php
 * (data/matches.json) — this register turns them into things a Secretary
 * must actively verify and record against COMET/the SFA. It deliberately
 * never computes a ban length or clear date itself: the Secretary Handbook's
 * "System Safety" rule is that the app should record and remind, not
 * independently declare a suspension, since ban length/scope depends on
 * competition rules that change and must be checked live.
 */

const DISCIPLINE_MATCHES_DATA_FILE = __DIR__ . '/../data/matches.json';

function discipline_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS discipline_register (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            player_id INT UNSIGNED NOT NULL,
            fixture_id INT UNSIGNED NULL,
            match_event_id VARCHAR(64) NULL,
            card_type ENUM('yellow','red') NOT NULL,
            incident_date DATE NULL,
            competition VARCHAR(150) NULL,
            status ENUM('unverified','suspension_confirmed','cleared') NOT NULL DEFAULT 'unverified',
            suspension_summary VARCHAR(255) NULL,
            suspension_clear_date DATE NULL,
            next_check_at DATE NULL,
            notes TEXT NULL,
            evidence_reference VARCHAR(255) NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_discipline_register_event (match_event_id),
            KEY idx_discipline_register_player (player_id),
            KEY idx_discipline_register_status (status),
            CONSTRAINT fk_discipline_register_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
            CONSTRAINT fk_discipline_register_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $ensured = true;
}

/**
 * Same JSON shape reports.php's reportLoadMatchEvents() reads — kept as a
 * small standalone copy here rather than a shared include so this feature
 * has no dependency on reports.php internals.
 *
 * @return array<int, list<array<string, mixed>>>
 */
function discipline_load_events_by_fixture(string $matchesDataFile): array
{
    $eventsByFixture = [];
    if (is_file($matchesDataFile)) {
        $decoded = json_decode((string) file_get_contents($matchesDataFile), true);
        if (is_array($decoded)) {
            foreach ($decoded as $match) {
                if (!is_array($match)) {
                    continue;
                }
                $fixtureId = (int) ($match['id'] ?? 0);
                if ($fixtureId > 0) {
                    $eventsByFixture[$fixtureId] = is_array($match['events'] ?? null) ? $match['events'] : [];
                }
            }
        }
    }
    return $eventsByFixture;
}

/**
 * @return array<string, int> normalized player name => players.id
 */
function discipline_players_by_normalized_name(PDO $pdo): array
{
    $map = [];
    $stmt = $pdo->query('SELECT id, name FROM players');
    foreach ($stmt as $row) {
        $name = hub_player_stats_normalize_name((string) ($row['name'] ?? ''));
        if ($name !== '') {
            $map[$name] = (int) $row['id'];
        }
    }
    return $map;
}

/**
 * Card events logged against Saltcoats Victoria that have no discipline
 * register row yet — the Secretary's "needs logging" queue. Red cards sort
 * first (an automatic suspension applies immediately on a red card, so
 * these are the highest-risk unverified items).
 *
 * @param list<array<string, mixed>> $fixtures match_fixtures rows (id, match_date, opponent, competition) to scan
 * @return list<array{match_event_id: string, player_id: ?int, player_name: string, fixture_id: int, card_type: string, incident_date: string, competition: string, opponent: string, minute: string}>
 */
function discipline_pending_incidents(PDO $pdo, array $fixtures): array
{
    discipline_ensure_schema($pdo);

    $loggedEventIds = [];
    $loggedFallbackKeys = [];
    $stmt = $pdo->query('SELECT match_event_id, player_id, fixture_id, card_type FROM discipline_register');
    foreach ($stmt as $row) {
        $eventId = trim((string) ($row['match_event_id'] ?? ''));
        if ($eventId !== '') {
            $loggedEventIds[$eventId] = true;
        } else {
            $loggedFallbackKeys[$row['player_id'] . ':' . $row['fixture_id'] . ':' . $row['card_type']] = true;
        }
    }

    $playerIdsByName = discipline_players_by_normalized_name($pdo);
    $eventsByFixture = discipline_load_events_by_fixture(DISCIPLINE_MATCHES_DATA_FILE);

    $pending = [];
    foreach ($fixtures as $fixture) {
        $fixtureId = (int) ($fixture['id'] ?? 0);
        $events = $eventsByFixture[$fixtureId] ?? [];

        foreach ($events as $event) {
            if (!is_array($event) || (string) ($event['team'] ?? '') !== 'svfc') {
                continue;
            }

            $type = (string) ($event['type'] ?? '');
            $isYellow = $type === 'yellow_card' || ($type === 'card' && (string) ($event['card_type'] ?? '') === 'yellow');
            $isRed = $type === 'red_card' || ($type === 'card' && (string) ($event['card_type'] ?? '') === 'red');
            if (!$isYellow && !$isRed) {
                continue;
            }

            $cardType = $isRed ? 'red' : 'yellow';
            $eventId = trim((string) ($event['id'] ?? ''));
            $playerName = trim((string) ($event['player'] ?? ''));
            $playerId = $playerIdsByName[hub_player_stats_normalize_name($playerName)] ?? null;

            if ($eventId !== '' && isset($loggedEventIds[$eventId])) {
                continue;
            }
            if ($eventId === '' && $playerId !== null && isset($loggedFallbackKeys[$playerId . ':' . $fixtureId . ':' . $cardType])) {
                continue;
            }

            $pending[] = [
                'match_event_id' => $eventId,
                'player_id' => $playerId,
                'player_name' => $playerName !== '' ? $playerName : 'Unknown player',
                'fixture_id' => $fixtureId,
                'card_type' => $cardType,
                'incident_date' => (string) ($fixture['match_date'] ?? ''),
                'competition' => (string) ($fixture['competition'] ?? ''),
                'opponent' => (string) ($fixture['opponent'] ?? ''),
                'minute' => trim((string) ($event['minute'] ?? '')),
            ];
        }
    }

    usort($pending, static function (array $a, array $b): int {
        if ($a['card_type'] !== $b['card_type']) {
            return $a['card_type'] === 'red' ? -1 : 1;
        }
        return strcmp((string) $b['incident_date'], (string) $a['incident_date']);
    });

    return $pending;
}

/**
 * @return list<array<string, mixed>>
 */
function discipline_list_register(PDO $pdo): array
{
    discipline_ensure_schema($pdo);
    $stmt = $pdo->query(
        "SELECT dr.*, p.name AS player_name, mf.match_date, mf.opponent, mf.venue
         FROM discipline_register dr
         INNER JOIN players p ON p.id = dr.player_id
         LEFT JOIN match_fixtures mf ON mf.id = dr.fixture_id
         ORDER BY dr.incident_date DESC, dr.id DESC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function discipline_get_incident(PDO $pdo, int $id): ?array
{
    discipline_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT dr.*, p.name AS player_name
         FROM discipline_register dr
         INNER JOIN players p ON p.id = dr.player_id
         WHERE dr.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param array<string, mixed> $data
 */
function discipline_save_incident(PDO $pdo, ?int $id, array $data, ?int $userId): int
{
    discipline_ensure_schema($pdo);

    $playerId = (int) ($data['player_id'] ?? 0);
    if ($playerId <= 0) {
        throw new InvalidArgumentException('Select a player.');
    }

    $cardType = (string) ($data['card_type'] ?? '');
    if (!in_array($cardType, ['yellow', 'red'], true)) {
        throw new InvalidArgumentException('Select a card type.');
    }

    $status = (string) ($data['status'] ?? 'unverified');
    if (!in_array($status, ['unverified', 'suspension_confirmed', 'cleared'], true)) {
        throw new InvalidArgumentException('Select a valid status.');
    }

    $fixtureId = isset($data['fixture_id']) && (int) $data['fixture_id'] > 0 ? (int) $data['fixture_id'] : null;
    $matchEventId = trim((string) ($data['match_event_id'] ?? ''));
    $matchEventId = $matchEventId !== '' ? mb_substr($matchEventId, 0, 64) : null;
    $incidentDate = trim((string) ($data['incident_date'] ?? ''));
    $incidentDate = $incidentDate !== '' ? $incidentDate : null;
    $competition = trim((string) ($data['competition'] ?? ''));
    $competition = $competition !== '' ? mb_substr($competition, 0, 150) : null;
    $suspensionSummary = trim((string) ($data['suspension_summary'] ?? ''));
    $suspensionSummary = $suspensionSummary !== '' ? mb_substr($suspensionSummary, 0, 255) : null;
    $suspensionClearDate = trim((string) ($data['suspension_clear_date'] ?? ''));
    $suspensionClearDate = $suspensionClearDate !== '' ? $suspensionClearDate : null;
    $nextCheckAt = trim((string) ($data['next_check_at'] ?? ''));
    $nextCheckAt = $nextCheckAt !== '' ? $nextCheckAt : null;
    $notes = trim((string) ($data['notes'] ?? ''));
    $notes = $notes !== '' ? $notes : null;
    $evidenceReference = trim((string) ($data['evidence_reference'] ?? ''));
    $evidenceReference = $evidenceReference !== '' ? mb_substr($evidenceReference, 0, 255) : null;

    $params = [
        ':player_id' => $playerId,
        ':fixture_id' => $fixtureId,
        ':match_event_id' => $matchEventId,
        ':card_type' => $cardType,
        ':incident_date' => $incidentDate,
        ':competition' => $competition,
        ':status' => $status,
        ':suspension_summary' => $suspensionSummary,
        ':suspension_clear_date' => $suspensionClearDate,
        ':next_check_at' => $nextCheckAt,
        ':notes' => $notes,
        ':evidence_reference' => $evidenceReference,
    ];

    if ($id === null) {
        $params[':created_by'] = $userId;
        $stmt = $pdo->prepare(
            'INSERT INTO discipline_register
                (player_id, fixture_id, match_event_id, card_type, incident_date, competition, status,
                 suspension_summary, suspension_clear_date, next_check_at, notes, evidence_reference, created_by)
             VALUES
                (:player_id, :fixture_id, :match_event_id, :card_type, :incident_date, :competition, :status,
                 :suspension_summary, :suspension_clear_date, :next_check_at, :notes, :evidence_reference, :created_by)'
        );
        $stmt->execute($params);
        return (int) $pdo->lastInsertId();
    }

    $params[':id'] = $id;
    $stmt = $pdo->prepare(
        'UPDATE discipline_register SET
            player_id = :player_id,
            fixture_id = :fixture_id,
            match_event_id = :match_event_id,
            card_type = :card_type,
            incident_date = :incident_date,
            competition = :competition,
            status = :status,
            suspension_summary = :suspension_summary,
            suspension_clear_date = :suspension_clear_date,
            next_check_at = :next_check_at,
            notes = :notes,
            evidence_reference = :evidence_reference
         WHERE id = :id'
    );
    $stmt->execute($params);
    return $id;
}

function discipline_delete_incident(PDO $pdo, int $id): bool
{
    discipline_ensure_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM discipline_register WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

/**
 * GREEN/AMBER/RED eligibility read for one player.
 *
 * @return array{status: 'green'|'amber'|'red', reason: string}
 */
function discipline_player_status(PDO $pdo, int $playerId): array
{
    discipline_ensure_schema($pdo);
    $today = date('Y-m-d');

    $stmt = $pdo->prepare(
        "SELECT suspension_summary FROM discipline_register
         WHERE player_id = :player_id AND status = 'suspension_confirmed'
           AND (suspension_clear_date IS NULL OR suspension_clear_date >= :today)
         ORDER BY incident_date DESC LIMIT 1"
    );
    $stmt->execute([':player_id' => $playerId, ':today' => $today]);
    $activeSuspension = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($activeSuspension) {
        $summary = trim((string) ($activeSuspension['suspension_summary'] ?? ''));
        return [
            'status' => 'red',
            'reason' => $summary !== '' ? $summary : 'Suspension confirmed — check COMET before selecting.',
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM discipline_register WHERE player_id = :player_id AND status = 'unverified'"
    );
    $stmt->execute([':player_id' => $playerId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return ['status' => 'amber', 'reason' => 'A logged card for this player has not been verified yet.'];
    }

    return ['status' => 'green', 'reason' => ''];
}

/**
 * Same read as discipline_player_status(), for every player with a
 * non-green discipline state in one pass — used by list screens (e.g.
 * players.php) that would otherwise run the per-player query once per row.
 *
 * @return array<int, array{status: 'amber'|'red', reason: string}>
 */
function discipline_status_by_player(PDO $pdo): array
{
    discipline_ensure_schema($pdo);
    $today = date('Y-m-d');
    $statuses = [];

    $stmt = $pdo->prepare(
        "SELECT player_id, suspension_summary FROM discipline_register
         WHERE status = 'suspension_confirmed'
           AND (suspension_clear_date IS NULL OR suspension_clear_date >= :today)
         ORDER BY incident_date ASC"
    );
    $stmt->execute([':today' => $today]);
    foreach ($stmt as $row) {
        $summary = trim((string) ($row['suspension_summary'] ?? ''));
        $statuses[(int) $row['player_id']] = [
            'status' => 'red',
            'reason' => $summary !== '' ? $summary : 'Suspension confirmed — check COMET before selecting.',
        ];
    }

    $stmt = $pdo->query("SELECT DISTINCT player_id FROM discipline_register WHERE status = 'unverified'");
    foreach ($stmt as $row) {
        $playerId = (int) $row['player_id'];
        if (!isset($statuses[$playerId])) {
            $statuses[$playerId] = ['status' => 'amber', 'reason' => 'A logged card for this player has not been verified yet.'];
        }
    }

    return $statuses;
}
