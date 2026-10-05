<?php
declare(strict_types=1);

function player_availability_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS player_fixture_availability (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        fixture_id INT UNSIGNED NOT NULL,
        player_id INT UNSIGNED NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'unknown',
        reason VARCHAR(80) NULL,
        notes VARCHAR(255) NULL,
        updated_by_account_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_player_fixture_availability (fixture_id, player_id),
        KEY idx_player_availability_fixture_status (fixture_id, status),
        KEY idx_player_availability_player (player_id),
        CONSTRAINT fk_player_availability_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE,
        CONSTRAINT fk_player_availability_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
        CONSTRAINT fk_player_availability_account FOREIGN KEY (updated_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function player_availability_statuses(): array
{
    return [
        'unknown' => 'Unknown',
        'available' => 'Available',
        'doubtful' => 'Doubtful',
        'unavailable' => 'Unavailable',
        'injured' => 'Injured',
        'suspended' => 'Suspended',
    ];
}

function player_availability_status_label(string $status): string
{
    $statuses = player_availability_statuses();
    return $statuses[$status] ?? 'Unknown';
}

function player_availability_badge_class(string $status): string
{
    return match ($status) {
        'available' => 'text-bg-success',
        'doubtful' => 'text-bg-warning',
        'unavailable', 'injured', 'suspended' => 'text-bg-danger',
        default => 'text-bg-light',
    };
}

function player_availability_selectable_statuses(): array
{
    $statuses = player_availability_statuses();
    unset($statuses['unknown']);
    return $statuses;
}

function player_availability_blocks_selection(string $status): bool
{
    return in_array($status, ['unavailable', 'injured', 'suspended'], true);
}

/**
 * @return array<int,array<string,mixed>>
 */
function player_availability_by_fixture(PDO $pdo, int $fixtureId): array
{
    player_availability_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT a.*, p.name AS player_name, p.status AS player_status
        FROM player_fixture_availability a
        JOIN players p ON p.id = a.player_id
        WHERE a.fixture_id = :fixture_id
        ORDER BY p.name ASC");
    $stmt->execute([':fixture_id' => $fixtureId]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[(int) $row['player_id']] = $row;
    }
    return $rows;
}

/**
 * @param list<array<string,mixed>> $players
 * @return list<array<string,mixed>>
 */
function player_availability_merge_players(array $players, array $availabilityByPlayer): array
{
    $merged = [];
    foreach ($players as $player) {
        $playerId = (int) ($player['id'] ?? 0);
        $availability = $availabilityByPlayer[$playerId] ?? [];
        $status = (string) ($availability['status'] ?? 'unknown');
        $merged[] = $player + [
            'availability_status' => $status,
            'availability_label' => player_availability_status_label($status),
            'availability_badge_class' => player_availability_badge_class($status),
            'availability_reason' => (string) ($availability['reason'] ?? ''),
            'availability_notes' => (string) ($availability['notes'] ?? ''),
            'availability_blocks_selection' => player_availability_blocks_selection($status),
        ];
    }
    return $merged;
}

/**
 * @param list<array<string,mixed>> $players
 * @return array{available:int,doubtful:int,blocked:int,unknown:int,total:int}
 */
function player_availability_summary(array $players): array
{
    $summary = ['available' => 0, 'doubtful' => 0, 'blocked' => 0, 'unknown' => 0, 'total' => count($players)];
    foreach ($players as $player) {
        $status = (string) ($player['availability_status'] ?? 'unknown');
        if ($status === 'available') {
            $summary['available']++;
        } elseif ($status === 'doubtful') {
            $summary['doubtful']++;
        } elseif (player_availability_blocks_selection($status)) {
            $summary['blocked']++;
        } else {
            $summary['unknown']++;
        }
    }
    return $summary;
}

function player_availability_save(PDO $pdo, int $fixtureId, int $playerId, string $status, string $reason = '', string $notes = '', ?int $accountId = null): void
{
    player_availability_ensure_schema($pdo);
    if ($fixtureId <= 0 || $playerId <= 0) {
        throw new InvalidArgumentException('Fixture and player are required.');
    }
    if (!array_key_exists($status, player_availability_statuses())) {
        throw new InvalidArgumentException('Choose a valid availability status.');
    }

    $reason = trim($reason);
    $notes = trim($notes);
    $stmt = $pdo->prepare("INSERT INTO player_fixture_availability
        (fixture_id, player_id, status, reason, notes, updated_by_account_id)
        VALUES (:fixture_id, :player_id, :status, :reason, :notes, :account_id)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            reason = VALUES(reason),
            notes = VALUES(notes),
            updated_by_account_id = VALUES(updated_by_account_id)");
    $stmt->execute([
        ':fixture_id' => $fixtureId,
        ':player_id' => $playerId,
        ':status' => $status,
        ':reason' => $reason !== '' ? mb_substr($reason, 0, 80) : null,
        ':notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null,
        ':account_id' => $accountId,
    ]);
}
