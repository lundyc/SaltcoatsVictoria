<?php
declare(strict_types=1);

/**
 * Normalized competition structure.
 *
 * `match_competitions` remains the canonical competition record. A
 * `competition_seasons` row describes that competition for one season, while
 * `competition_aliases` records historical/import names without changing the
 * canonical record.
 */

/** @return array<string, bool> */
function competitionStructureColumns(PDO $pdo, string $table): array
{
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `' . $table . '`') as $row) {
        $columns[(string) $row['Field']] = true;
    }
    return $columns;
}

function competitionStructureTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensureCompetitionStructureSchema(PDO $pdo): void
{
    static $done = [];
    $connectionKey = spl_object_id($pdo);
    if (isset($done[$connectionKey])) {
        return;
    }

    if (!competitionStructureTableExists($pdo, 'seasons') && function_exists('ensureSeasonSchema')) {
        ensureSeasonSchema($pdo);
    }
    if (!competitionStructureTableExists($pdo, 'match_competitions') && function_exists('ensureMatchSchema')) {
        ensureMatchSchema($pdo);
    }
    if (!competitionStructureTableExists($pdo, 'match_competitions') || !competitionStructureTableExists($pdo, 'seasons')) {
        throw new RuntimeException('The competition structure requires the match_competitions and seasons tables.');
    }

    $competitionColumns = competitionStructureColumns($pdo, 'match_competitions');
    if (!isset($competitionColumns['organiser'])) {
        $pdo->exec("ALTER TABLE match_competitions ADD COLUMN organiser VARCHAR(190) DEFAULT NULL AFTER name");
    }
    if (!isset($competitionColumns['competition_type'])) {
        $pdo->exec("ALTER TABLE match_competitions ADD COLUMN competition_type VARCHAR(32) NOT NULL DEFAULT 'other' AFTER organiser");
        $pdo->exec("UPDATE match_competitions SET competition_type = 'league' WHERE is_league = 1 AND competition_type = 'other'");
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS competition_seasons (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            competition_id INT UNSIGNED NOT NULL,
            season_id INT UNSIGNED NOT NULL,
            display_name VARCHAR(190) DEFAULT NULL,
            sponsor_title VARCHAR(190) DEFAULT NULL,
            competition_url VARCHAR(255) DEFAULT NULL,
            promotion_spots INT UNSIGNED DEFAULT NULL,
            relegation_spots INT UNSIGNED DEFAULT NULL,
            show_table_lines TINYINT(1) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notes VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_competition_seasons_competition_season (competition_id, season_id),
            KEY idx_competition_seasons_season (season_id),
            KEY idx_competition_seasons_active (season_id, is_active),
            CONSTRAINT fk_competition_seasons_competition
                FOREIGN KEY (competition_id) REFERENCES match_competitions (id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_competition_seasons_season
                FOREIGN KEY (season_id) REFERENCES seasons (id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS competition_aliases (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            competition_id INT UNSIGNED NOT NULL,
            alias_name VARCHAR(190) NOT NULL,
            season_id INT UNSIGNED DEFAULT NULL,
            organiser VARCHAR(190) DEFAULT NULL,
            source_name VARCHAR(100) DEFAULT NULL,
            source_reference VARCHAR(190) DEFAULT NULL,
            review_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            notes VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_competition_aliases_name (alias_name),
            KEY idx_competition_aliases_competition (competition_id),
            KEY idx_competition_aliases_season (season_id),
            KEY idx_competition_aliases_review (review_status),
            CONSTRAINT fk_competition_aliases_competition
                FOREIGN KEY (competition_id) REFERENCES match_competitions (id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_competition_aliases_season
                FOREIGN KEY (season_id) REFERENCES seasons (id)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

    // Preserve the legacy primary-league assignment as a normalized edition.
    $seasonColumns = competitionStructureColumns($pdo, 'seasons');
    if (isset($seasonColumns['competition_id'])) {
        $pdo->exec(<<<'SQL'
            INSERT IGNORE INTO competition_seasons
                (competition_id, season_id, display_name, competition_url, promotion_spots, relegation_spots, show_table_lines)
            SELECT mc.id,
                   s.id,
                   mc.name,
                   mc.league_url,
                   mc.promotion_spots,
                   mc.relegation_spots,
                   mc.show_table_lines
            FROM seasons s
            INNER JOIN match_competitions mc ON mc.id = s.competition_id
            WHERE s.competition_id IS NOT NULL
            SQL);
    }

    // Exact name matches are safe to normalize; all other fixture text remains
    // unchanged and available for deliberate alias review.
    if (competitionStructureTableExists($pdo, 'match_fixtures')) {
        $fixtureColumns = competitionStructureColumns($pdo, 'match_fixtures');
        if (isset($fixtureColumns['season_id'], $fixtureColumns['competition'])) {
            $pdo->exec(<<<'SQL'
                INSERT IGNORE INTO competition_seasons (competition_id, season_id, display_name)
                SELECT DISTINCT mc.id, mf.season_id, TRIM(mf.competition)
                FROM match_fixtures mf
                INNER JOIN match_competitions mc
                    ON LOWER(TRIM(mc.name)) = LOWER(TRIM(mf.competition))
                INNER JOIN seasons s ON s.id = mf.season_id
                WHERE mf.competition IS NOT NULL
                  AND TRIM(mf.competition) <> ''
                SQL);
        }
    }

    $done[$connectionKey] = true;
}

function competitionStructureNullableString(mixed $value): ?string
{
    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function competitionStructureCompetitionType(string $competitionType): string
{
    $competitionType = strtolower(trim($competitionType));
    $allowed = ['league', 'cup', 'friendly', 'tournament', 'other'];
    if (!in_array($competitionType, $allowed, true)) {
        throw new InvalidArgumentException('Invalid competition type.');
    }
    return $competitionType;
}

function competitionStructureSaveCanonicalMetadata(PDO $pdo, int $competitionId, ?string $organiser, string $competitionType): void
{
    ensureCompetitionStructureSchema($pdo);
    if ($competitionId < 1) {
        throw new InvalidArgumentException('A valid competition is required.');
    }

    $stmt = $pdo->prepare(<<<'SQL'
        UPDATE match_competitions
        SET organiser = :organiser,
            competition_type = :competition_type,
            is_league = :is_league
        WHERE id = :id
        LIMIT 1
        SQL);
    $competitionType = competitionStructureCompetitionType($competitionType);
    $stmt->execute([
        ':organiser' => competitionStructureNullableString($organiser),
        ':competition_type' => $competitionType,
        ':is_league' => $competitionType === 'league' ? 1 : 0,
        ':id' => $competitionId,
    ]);
}

/** @return list<array<string, mixed>> */
function competitionStructureListEditions(
    PDO $pdo,
    ?int $competitionId = null,
    ?int $seasonId = null,
    bool $activeOnly = false
): array {
    ensureCompetitionStructureSchema($pdo);
    $where = [];
    $params = [];
    if ($competitionId !== null) {
        $where[] = 'cs.competition_id = :competition_id';
        $params[':competition_id'] = $competitionId;
    }
    if ($seasonId !== null) {
        $where[] = 'cs.season_id = :season_id';
        $params[':season_id'] = $seasonId;
    }
    if ($activeOnly) {
        $where[] = 'cs.is_active = 1';
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare(<<<SQL
        SELECT cs.*,
               mc.name AS canonical_name,
               mc.organiser AS canonical_organiser,
               mc.competition_type,
               s.name AS season_name,
               s.start_date AS season_start_date,
               s.end_date AS season_end_date,
               s.is_locked AS season_is_locked,
               COALESCE(NULLIF(cs.display_name, ''), NULLIF(cs.sponsor_title, ''), mc.name) AS effective_name
        FROM competition_seasons cs
        INNER JOIN match_competitions mc ON mc.id = cs.competition_id
        INNER JOIN seasons s ON s.id = cs.season_id
        {$whereSql}
        ORDER BY s.start_date DESC, s.id DESC, mc.name ASC
        SQL);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function competitionStructureGetEdition(PDO $pdo, int $editionId): ?array
{
    ensureCompetitionStructureSchema($pdo);
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT cs.*,
               mc.name AS canonical_name,
               mc.organiser AS canonical_organiser,
               mc.competition_type,
               s.name AS season_name,
               s.is_locked AS season_is_locked,
               COALESCE(NULLIF(cs.display_name, ''), NULLIF(cs.sponsor_title, ''), mc.name) AS effective_name
        FROM competition_seasons cs
        INNER JOIN match_competitions mc ON mc.id = cs.competition_id
        INNER JOIN seasons s ON s.id = cs.season_id
        WHERE cs.id = :id
        LIMIT 1
        SQL);
    $stmt->execute([':id' => $editionId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function competitionStructureFindEdition(PDO $pdo, int $competitionId, int $seasonId): ?array
{
    ensureCompetitionStructureSchema($pdo);
    $stmt = $pdo->prepare('SELECT id FROM competition_seasons WHERE competition_id = :competition_id AND season_id = :season_id LIMIT 1');
    $stmt->execute([':competition_id' => $competitionId, ':season_id' => $seasonId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : competitionStructureGetEdition($pdo, (int) $id);
}

function competitionStructureSaveEdition(
    PDO $pdo,
    ?int $editionId,
    int $competitionId,
    int $seasonId,
    array $data = []
): int {
    ensureCompetitionStructureSchema($pdo);
    if ($competitionId < 1 || $seasonId < 1) {
        throw new InvalidArgumentException('A valid competition and season are required.');
    }

    $values = [
        ':competition_id' => $competitionId,
        ':season_id' => $seasonId,
        ':display_name' => competitionStructureNullableString($data['display_name'] ?? null),
        ':sponsor_title' => competitionStructureNullableString($data['sponsor_title'] ?? null),
        ':competition_url' => competitionStructureNullableString($data['competition_url'] ?? null),
        ':promotion_spots' => isset($data['promotion_spots']) && $data['promotion_spots'] !== '' ? max(0, (int) $data['promotion_spots']) : null,
        ':relegation_spots' => isset($data['relegation_spots']) && $data['relegation_spots'] !== '' ? max(0, (int) $data['relegation_spots']) : null,
        ':show_table_lines' => array_key_exists('show_table_lines', $data) && $data['show_table_lines'] !== null && $data['show_table_lines'] !== '' ? ((bool) $data['show_table_lines'] ? 1 : 0) : null,
        ':is_active' => !array_key_exists('is_active', $data) || (bool) $data['is_active'] ? 1 : 0,
        ':notes' => competitionStructureNullableString($data['notes'] ?? null),
    ];

    if ($editionId === null) {
        $existing = competitionStructureFindEdition($pdo, $competitionId, $seasonId);
        if ($existing !== null) {
            $editionId = (int) $existing['id'];
        }
    }

    if ($editionId !== null && $editionId > 0) {
        $values[':id'] = $editionId;
        $stmt = $pdo->prepare(<<<'SQL'
            UPDATE competition_seasons
            SET competition_id = :competition_id,
                season_id = :season_id,
                display_name = :display_name,
                sponsor_title = :sponsor_title,
                competition_url = :competition_url,
                promotion_spots = :promotion_spots,
                relegation_spots = :relegation_spots,
                show_table_lines = :show_table_lines,
                is_active = :is_active,
                notes = :notes
            WHERE id = :id
            LIMIT 1
            SQL);
        $stmt->execute($values);
        return $editionId;
    }

    $stmt = $pdo->prepare(<<<'SQL'
        INSERT INTO competition_seasons
            (competition_id, season_id, display_name, sponsor_title, competition_url,
             promotion_spots, relegation_spots, show_table_lines, is_active, notes)
        VALUES
            (:competition_id, :season_id, :display_name, :sponsor_title, :competition_url,
             :promotion_spots, :relegation_spots, :show_table_lines, :is_active, :notes)
        SQL);
    $stmt->execute($values);
    return (int) $pdo->lastInsertId();
}

function competitionStructureSetEditionActive(PDO $pdo, int $editionId, bool $isActive): void
{
    ensureCompetitionStructureSchema($pdo);
    $stmt = $pdo->prepare('UPDATE competition_seasons SET is_active = :is_active WHERE id = :id LIMIT 1');
    $stmt->execute([':is_active' => $isActive ? 1 : 0, ':id' => $editionId]);
}

/** @return list<array<string, mixed>> */
function competitionStructureListAliases(
    PDO $pdo,
    ?int $competitionId = null,
    ?int $seasonId = null,
    ?string $reviewStatus = null
): array {
    ensureCompetitionStructureSchema($pdo);
    $where = [];
    $params = [];
    if ($competitionId !== null) {
        $where[] = 'ca.competition_id = :competition_id';
        $params[':competition_id'] = $competitionId;
    }
    if ($seasonId !== null) {
        $where[] = 'ca.season_id = :season_id';
        $params[':season_id'] = $seasonId;
    }
    if ($reviewStatus !== null) {
        $where[] = 'ca.review_status = :review_status';
        $params[':review_status'] = competitionStructureReviewStatus($reviewStatus);
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $pdo->prepare(<<<SQL
        SELECT ca.*, mc.name AS canonical_name, s.name AS season_name
        FROM competition_aliases ca
        INNER JOIN match_competitions mc ON mc.id = ca.competition_id
        LEFT JOIN seasons s ON s.id = ca.season_id
        {$whereSql}
        ORDER BY ca.alias_name ASC, s.start_date DESC, ca.id ASC
        SQL);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function competitionStructureReviewStatus(string $reviewStatus): string
{
    $reviewStatus = strtolower(trim($reviewStatus));
    if (!in_array($reviewStatus, ['pending', 'confirmed', 'rejected'], true)) {
        throw new InvalidArgumentException('Invalid alias review status.');
    }
    return $reviewStatus;
}

function competitionStructureSaveAlias(
    PDO $pdo,
    ?int $aliasId,
    int $competitionId,
    string $aliasName,
    array $data = []
): int {
    ensureCompetitionStructureSchema($pdo);
    $aliasName = trim($aliasName);
    if ($competitionId < 1 || $aliasName === '') {
        throw new InvalidArgumentException('A valid competition and alias name are required.');
    }

    $seasonId = isset($data['season_id']) && (int) $data['season_id'] > 0 ? (int) $data['season_id'] : null;
    $organiser = competitionStructureNullableString($data['organiser'] ?? null);
    $sourceName = competitionStructureNullableString($data['source_name'] ?? null);
    $sourceReference = competitionStructureNullableString($data['source_reference'] ?? null);

    if ($aliasId === null) {
        $find = $pdo->prepare(<<<'SQL'
            SELECT id
            FROM competition_aliases
            WHERE competition_id = :competition_id
              AND LOWER(alias_name) = LOWER(:alias_name)
              AND season_id <=> :season_id
              AND organiser <=> :organiser
              AND source_name <=> :source_name
              AND source_reference <=> :source_reference
            LIMIT 1
            SQL);
        $find->execute([
            ':competition_id' => $competitionId,
            ':alias_name' => $aliasName,
            ':season_id' => $seasonId,
            ':organiser' => $organiser,
            ':source_name' => $sourceName,
            ':source_reference' => $sourceReference,
        ]);
        $existingId = $find->fetchColumn();
        if ($existingId !== false) {
            $aliasId = (int) $existingId;
        }
    }

    $values = [
        ':competition_id' => $competitionId,
        ':alias_name' => $aliasName,
        ':season_id' => $seasonId,
        ':organiser' => $organiser,
        ':source_name' => $sourceName,
        ':source_reference' => $sourceReference,
        ':review_status' => competitionStructureReviewStatus((string) ($data['review_status'] ?? 'pending')),
        ':notes' => competitionStructureNullableString($data['notes'] ?? null),
    ];

    if ($aliasId !== null && $aliasId > 0) {
        $values[':id'] = $aliasId;
        $stmt = $pdo->prepare(<<<'SQL'
            UPDATE competition_aliases
            SET competition_id = :competition_id,
                alias_name = :alias_name,
                season_id = :season_id,
                organiser = :organiser,
                source_name = :source_name,
                source_reference = :source_reference,
                review_status = :review_status,
                notes = :notes
            WHERE id = :id
            LIMIT 1
            SQL);
        $stmt->execute($values);
        return $aliasId;
    }

    $stmt = $pdo->prepare(<<<'SQL'
        INSERT INTO competition_aliases
            (competition_id, alias_name, season_id, organiser, source_name, source_reference, review_status, notes)
        VALUES
            (:competition_id, :alias_name, :season_id, :organiser, :source_name, :source_reference, :review_status, :notes)
        SQL);
    $stmt->execute($values);
    return (int) $pdo->lastInsertId();
}

/**
 * Resolve only confirmed aliases. If equally specific aliases point at more
 * than one competition the result is intentionally null, forcing review.
 */
function competitionStructureResolveAlias(
    PDO $pdo,
    string $aliasName,
    ?int $seasonId = null,
    ?string $organiser = null,
    ?string $sourceName = null
): ?array {
    ensureCompetitionStructureSchema($pdo);
    $aliasName = trim($aliasName);
    if ($aliasName === '') {
        return null;
    }

    $stmt = $pdo->prepare(<<<'SQL'
        SELECT ca.*,
               mc.name AS canonical_name,
               mc.competition_type,
               ((ca.season_id IS NOT NULL) * 4
                + (ca.organiser IS NOT NULL) * 2
                + (ca.source_name IS NOT NULL)) AS specificity
        FROM competition_aliases ca
        INNER JOIN match_competitions mc ON mc.id = ca.competition_id
        WHERE LOWER(ca.alias_name) = LOWER(:alias_name)
          AND ca.review_status = 'confirmed'
          AND (ca.season_id IS NULL OR ca.season_id = :season_id)
          AND (ca.organiser IS NULL OR LOWER(ca.organiser) = LOWER(:organiser))
          AND (ca.source_name IS NULL OR LOWER(ca.source_name) = LOWER(:source_name))
        ORDER BY specificity DESC, ca.id ASC
        SQL);
    $stmt->execute([
        ':alias_name' => $aliasName,
        ':season_id' => $seasonId,
        ':organiser' => competitionStructureNullableString($organiser),
        ':source_name' => competitionStructureNullableString($sourceName),
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return null;
    }

    $bestSpecificity = (int) $rows[0]['specificity'];
    $bestCompetitionIds = [];
    foreach ($rows as $row) {
        if ((int) $row['specificity'] !== $bestSpecificity) {
            break;
        }
        $bestCompetitionIds[(int) $row['competition_id']] = true;
    }
    return count($bestCompetitionIds) === 1 ? $rows[0] : null;
}
