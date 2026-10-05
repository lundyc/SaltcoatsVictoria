<?php
declare(strict_types=1);

require_once __DIR__ . '/season_tickets.php';

function ensureSeasonPassRuleSchema(PDO $pdo): void
{
    ensureSeasonTicketSchema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS season_pass_rules (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        season_ticket_type_id INT UNSIGNED NOT NULL,
        competition_id INT UNSIGNED NULL,
        competition_type VARCHAR(40) NULL,
        is_home_required TINYINT(1) NOT NULL DEFAULT 1,
        included TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_season_pass_rules_type_comp (season_ticket_type_id, competition_id, competition_type, is_home_required),
        KEY idx_season_pass_rules_type (season_ticket_type_id),
        CONSTRAINT fk_season_pass_rules_type FOREIGN KEY (season_ticket_type_id) REFERENCES season_ticket_types(id) ON DELETE CASCADE,
        CONSTRAINT fk_season_pass_rules_competition FOREIGN KEY (competition_id) REFERENCES match_competitions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS season_pass_fixture_overrides (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        season_ticket_type_id INT UNSIGNED NOT NULL,
        fixture_id INT UNSIGNED NOT NULL,
        included TINYINT(1) NOT NULL DEFAULT 1,
        reason VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_season_pass_fixture_override (season_ticket_type_id, fixture_id),
        KEY idx_season_pass_fixture_overrides_fixture (fixture_id),
        CONSTRAINT fk_season_pass_overrides_type FOREIGN KEY (season_ticket_type_id) REFERENCES season_ticket_types(id) ON DELETE CASCADE,
        CONSTRAINT fk_season_pass_overrides_fixture FOREIGN KEY (fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function seasonPassRuleDecision(PDO $pdo, int $seasonTicketTypeId, int $fixtureId, int $passSeasonId): array
{
    ensureSeasonPassRuleSchema($pdo);
    $stmt = $pdo->prepare('SELECT f.id, f.season_id, f.is_home, f.status, mc.id AS competition_id, mc.competition_type
        FROM match_fixtures f
        LEFT JOIN competition_seasons cs ON cs.id = f.competition_season_id
        LEFT JOIN match_competitions mc ON mc.id = cs.competition_id
        WHERE f.id = :fixture
        LIMIT 1');
    $stmt->execute([':fixture' => $fixtureId]);
    $fixture = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fixture) {
        return ['allowed' => false, 'status' => 'fixture_not_found', 'reason' => 'Fixture not found.'];
    }
    if ((int) $fixture['season_id'] !== $passSeasonId) {
        return ['allowed' => false, 'status' => 'wrong_season', 'reason' => 'This pass is for another season.'];
    }
    if (in_array((string) ($fixture['status'] ?? ''), ['postponed', 'cancelled'], true)) {
        return ['allowed' => false, 'status' => 'fixture_not_admitting', 'reason' => 'Fixture is postponed or cancelled.'];
    }

    $override = $pdo->prepare('SELECT included, reason FROM season_pass_fixture_overrides WHERE season_ticket_type_id = :type AND fixture_id = :fixture LIMIT 1');
    $override->execute([':type' => $seasonTicketTypeId, ':fixture' => $fixtureId]);
    $overrideRow = $override->fetch(PDO::FETCH_ASSOC);
    if ($overrideRow) {
        return ['allowed' => (int) $overrideRow['included'] === 1, 'status' => (int) $overrideRow['included'] === 1 ? 'included_override' : 'season_pass_not_valid', 'reason' => (string) ($overrideRow['reason'] ?: 'Fixture override.')];
    }

    $rules = $pdo->prepare("SELECT * FROM season_pass_rules
        WHERE season_ticket_type_id = :type
          AND (:is_home = 1 OR is_home_required = 0)
          AND (competition_id = :competition_id OR competition_type = :competition_type OR (competition_id IS NULL AND competition_type IS NULL))
        ORDER BY
          (competition_id = :competition_id) DESC,
          (competition_type = :competition_type) DESC,
          is_home_required DESC
        LIMIT 1");
    $competitionId = (int) ($fixture['competition_id'] ?? 0);
    $competitionType = trim((string) ($fixture['competition_type'] ?? ''));
    $rules->execute([
        ':type' => $seasonTicketTypeId,
        ':is_home' => (int) ($fixture['is_home'] ?? 0),
        ':competition_id' => $competitionId > 0 ? $competitionId : null,
        ':competition_type' => $competitionType !== '' ? $competitionType : null,
    ]);
    $rule = $rules->fetch(PDO::FETCH_ASSOC);
    if ($rule) {
        return ['allowed' => (int) $rule['included'] === 1, 'status' => (int) $rule['included'] === 1 ? 'included_rule' : 'season_pass_not_valid', 'reason' => 'Season-pass rule.'];
    }

    $allowed = (int) ($fixture['is_home'] ?? 0) === 1;
    return ['allowed' => $allowed, 'status' => $allowed ? 'legacy_default_home' : 'season_pass_not_valid', 'reason' => $allowed ? 'Default home fixture rule.' : 'Season tickets are not valid for away fixtures.'];
}

function seasonPassRulesForType(PDO $pdo, int $seasonTicketTypeId): array
{
    ensureSeasonPassRuleSchema($pdo);
    $stmt = $pdo->prepare('SELECT r.*, c.name AS competition_name
        FROM season_pass_rules r
        LEFT JOIN match_competitions c ON c.id = r.competition_id
        WHERE r.season_ticket_type_id = :type
        ORDER BY r.competition_type, c.name, r.id');
    $stmt->execute([':type' => $seasonTicketTypeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
