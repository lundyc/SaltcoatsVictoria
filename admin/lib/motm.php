<?php

declare(strict_types=1);

function ensureMotmSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS motm_votes (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id INT UNSIGNED NULL,
        holder_id INT UNSIGNED NULL,
        fixture_id INT UNSIGNED NOT NULL,
        player_name VARCHAR(150) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_motm_votes_holder_fixture (holder_id, fixture_id),
        KEY idx_motm_votes_person (person_id, fixture_id),
        KEY idx_motm_votes_fixture (fixture_id, player_name),
        CONSTRAINT fk_motm_votes_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
        CONSTRAINT fk_motm_votes_holder FOREIGN KEY (holder_id) REFERENCES season_ticket_holders(id) ON DELETE CASCADE,
        CONSTRAINT fk_motm_votes_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function saveMotmVote(PDO $pdo, int $personId, int $fixtureId, string $playerName, ?int $legacyHolderId = null): void
{
    ensureMotmSchema($pdo);
    if ($personId <= 0) {
        throw new InvalidArgumentException('Person is required.');
    }
    $existing = $pdo->prepare('SELECT id FROM motm_votes WHERE person_id = :person_id AND fixture_id = :fixture_id LIMIT 1');
    $existing->execute([':person_id' => $personId, ':fixture_id' => $fixtureId]);
    $voteId = $existing->fetchColumn();
    if ($voteId !== false) {
        $pdo->prepare('UPDATE motm_votes SET player_name = :player_name WHERE id = :id')
            ->execute([':player_name' => $playerName, ':id' => (int) $voteId]);
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO motm_votes (person_id, fixture_id, player_name) VALUES (:person_id, :fixture_id, :player_name)
        ON DUPLICATE KEY UPDATE person_id = VALUES(person_id), player_name = VALUES(player_name)');
    $stmt->execute([':person_id' => $personId, ':fixture_id' => $fixtureId, ':player_name' => $playerName]);
}

function getMyMotmVote(PDO $pdo, int $personId, int $fixtureId, ?int $legacyHolderId = null): ?string
{
    ensureMotmSchema($pdo);
    $stmt = $pdo->prepare('SELECT player_name FROM motm_votes WHERE person_id = :person_id AND fixture_id = :fixture_id LIMIT 1');
    $stmt->execute([':person_id' => $personId, ':fixture_id' => $fixtureId]);
    $value = $stmt->fetchColumn();
    if ($value === false && $legacyHolderId !== null && $legacyHolderId > 0) {
        $stmt = $pdo->prepare('SELECT player_name FROM motm_votes WHERE holder_id = :holder_id AND fixture_id = :fixture_id LIMIT 1');
        $stmt->execute([':holder_id' => $legacyHolderId, ':fixture_id' => $fixtureId]);
        $value = $stmt->fetchColumn();
    }
    return $value !== false ? (string) $value : null;
}

/**
 * @return list<array{player_name: string, votes: int}>
 */
function getMotmResults(PDO $pdo, int $fixtureId): array
{
    ensureMotmSchema($pdo);
    $stmt = $pdo->prepare('SELECT player_name, COUNT(*) AS votes FROM motm_votes WHERE fixture_id = :fixture_id GROUP BY player_name ORDER BY votes DESC, player_name ASC');
    $stmt->execute([':fixture_id' => $fixtureId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every fixture that has at least one vote, with the total vote count and
 * (for a quick admin glance) who's currently leading — the admin overview's
 * list of matches to drill into.
 *
 * @return list<array<string, mixed>>
 */
function getFixturesWithMotmVotes(PDO $pdo, ?int $seasonId = null): array
{
    ensureMotmSchema($pdo);
    $sql = "SELECT f.id AS fixture_id, f.opponent, f.match_date, f.is_home, f.full_time_home_score, f.full_time_away_score,
            COUNT(v.id) AS vote_count
        FROM motm_votes v
        JOIN match_fixtures f ON f.id = v.fixture_id";
    $params = [];
    if ($seasonId !== null) {
        $sql .= ' WHERE f.season_id = :season_id';
        $params[':season_id'] = $seasonId;
    }
    $sql .= ' GROUP BY f.id ORDER BY f.match_date DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['vote_count'] = (int) $row['vote_count'];
        $row['results'] = getMotmResults($pdo, (int) $row['fixture_id']);
    }
    unset($row);
    return $rows;
}

/**
 * Who voted for whom in one fixture — the admin drill-down.
 *
 * @return list<array{holder_name: string, player_name: string, created_at: string}>
 */
function getMotmVotesForFixture(PDO $pdo, int $fixtureId): array
{
    ensureMotmSchema($pdo);
    $stmt = $pdo->prepare('SELECT COALESCE(p.display_name, h.name, "Member") AS holder_name, v.player_name, v.created_at
        FROM motm_votes v
        LEFT JOIN people p ON p.id = v.person_id
        LEFT JOIN season_ticket_holders h ON h.id = v.holder_id
        WHERE v.fixture_id = :fixture_id
        ORDER BY v.created_at DESC');
    $stmt->execute([':fixture_id' => $fixtureId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
