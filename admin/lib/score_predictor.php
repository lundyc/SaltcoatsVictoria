<?php

declare(strict_types=1);

/**
 * Score Predictor (members game): storage, scoring and leaderboards.
 *
 * Points copy the Man City rules: 5 for submitting, 15 for the right result, 20 each for
 * the City score, opposition score, exact scoreline and a correct goalscorer (100 max).
 * Cup fixtures add a flat bonus on top. Predictions lock at kick-off.
 *
 * Settling a fixture reads the final score and goal events from the matchday record, so
 * it only runs once a fixture is marked as played.
 */

require_once __DIR__ . '/matchday_record.php';

const SP_DEFAULT_POINTS = [
    'submit' => 5,
    'result' => 15,
    'city_score' => 20,
    'opposition_score' => 20,
    'scoreline' => 20,
    'goalscorer' => 20,
    'cup_bonus' => 5,
];

function sp_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS sp_settings (
        setting_key VARCHAR(40) NOT NULL PRIMARY KEY,
        setting_value INT UNSIGNED NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sp_predictions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        fixture_id INT UNSIGNED NOT NULL,
        holder_id INT UNSIGNED NOT NULL,
        city_score TINYINT UNSIGNED NOT NULL,
        opposition_score TINYINT UNSIGNED NOT NULL,
        goalscorer VARCHAR(160) NOT NULL DEFAULT '',
        submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_sp_fixture_holder (fixture_id, holder_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // One row per settled prediction. match_date and season_id are copied here so the
    // leaderboards are simple GROUP BY queries with no joins.
    $pdo->exec("CREATE TABLE IF NOT EXISTS sp_scores (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        prediction_id INT UNSIGNED NOT NULL,
        fixture_id INT UNSIGNED NOT NULL,
        holder_id INT UNSIGNED NOT NULL,
        season_id INT UNSIGNED NOT NULL,
        match_date DATE NOT NULL,
        submitted_at DATETIME NOT NULL,
        is_cup TINYINT(1) NOT NULL DEFAULT 0,
        submit_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        result_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        city_score_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        opposition_score_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        scoreline_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        goalscorer_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        cup_bonus_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        total_points SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        settled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_sp_score_prediction (prediction_id),
        KEY idx_sp_score_fixture (fixture_id),
        KEY idx_sp_score_season (season_id, match_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Current point values: the defaults, overridden by anything saved in sp_settings.
 *
 * @return array<string, int>
 */
function sp_points(PDO $pdo): array
{
    sp_ensure_schema($pdo);
    $points = SP_DEFAULT_POINTS;
    foreach ($pdo->query('SELECT setting_key, setting_value FROM sp_settings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string) $row['setting_key'];
        if (array_key_exists($key, $points)) {
            $points[$key] = (int) $row['setting_value'];
        }
    }
    return $points;
}

/**
 * A fixture is cup if its competition is not a league. Fixtures with no competition
 * link are treated as league.
 *
 * @param array<string, mixed> $fixture a match_fixtures row
 */
function sp_fixture_is_cup(PDO $pdo, array $fixture): bool
{
    $competitionSeasonId = (int) ($fixture['competition_season_id'] ?? 0);
    if ($competitionSeasonId <= 0) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT mc.competition_type
        FROM competition_seasons cs
        JOIN match_competitions mc ON mc.id = cs.competition_id
        WHERE cs.id = :id');
    $stmt->execute([':id' => $competitionSeasonId]);
    $type = $stmt->fetchColumn();
    return $type !== false && (string) $type !== 'league';
}

/**
 * Predictions close at kick-off. A fixture with no kick-off time closes at the start of
 * its match date.
 *
 * @param array<string, mixed> $fixture
 */
function sp_fixture_is_locked(array $fixture, ?DateTimeImmutable $now = null): bool
{
    $now ??= new DateTimeImmutable('now');
    $kickoff = trim((string) $fixture['match_date']) . ' ' . (trim((string) ($fixture['kickoff_time'] ?? '')) ?: '00:00:00');
    return new DateTimeImmutable($kickoff) <= $now;
}

/**
 * Win / draw / loss from one side's point of view.
 */
function sp_outcome(int $for, int $against): string
{
    if ($for > $against) {
        return 'W';
    }
    return $for < $against ? 'L' : 'D';
}

/**
 * Case- and spacing-insensitive name match, so "Haaland" and "haaland " agree.
 */
function sp_names_match(string $a, string $b): bool
{
    $normalise = static fn(string $name): string => mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    return $normalise($a) !== '' && $normalise($a) === $normalise($b);
}

/**
 * Points for one prediction against the real result. Pure: no database access.
 *
 * @param array{city_score:int, opposition_score:int, goalscorer:string} $prediction
 * @param array{city_score:int, opposition_score:int, scorers:list<string>} $result
 * @param array<string, int> $points
 * @return array{submit:int, result:int, city_score:int, opposition_score:int, scoreline:int, goalscorer:int, cup_bonus:int, total:int}
 */
function sp_score_prediction(array $prediction, array $result, bool $isCup, array $points): array
{
    $resultRight = sp_outcome((int) $prediction['city_score'], (int) $prediction['opposition_score'])
        === sp_outcome((int) $result['city_score'], (int) $result['opposition_score']);
    $cityRight = (int) $prediction['city_score'] === (int) $result['city_score'];
    $oppositionRight = (int) $prediction['opposition_score'] === (int) $result['opposition_score'];

    $goalscorerRight = false;
    foreach ($result['scorers'] as $scorer) {
        if (sp_names_match((string) $prediction['goalscorer'], (string) $scorer)) {
            $goalscorerRight = true;
            break;
        }
    }

    $parts = [
        'submit' => $points['submit'],
        'result' => $resultRight ? $points['result'] : 0,
        'city_score' => $cityRight ? $points['city_score'] : 0,
        'opposition_score' => $oppositionRight ? $points['opposition_score'] : 0,
        'scoreline' => ($cityRight && $oppositionRight) ? $points['scoreline'] : 0,
        'goalscorer' => $goalscorerRight ? $points['goalscorer'] : 0,
        'cup_bonus' => $isCup ? $points['cup_bonus'] : 0,
    ];
    $parts['total'] = array_sum($parts);
    return $parts;
}

/**
 * The real result of a played fixture, from its full-time score and the matchday record.
 * Returns null if the fixture is not played or has no full-time score yet.
 *
 * @param array<string, mixed> $fixture
 * @return array{city_score:int, opposition_score:int, scorers:list<string>}|null
 */
function sp_actual_result(PDO $pdo, array $fixture): ?array
{
    if ((string) $fixture['status'] !== 'played'
        || $fixture['full_time_home_score'] === null
        || $fixture['full_time_away_score'] === null) {
        return null;
    }

    $isHome = (int) $fixture['is_home'] === 1;
    $home = (int) $fixture['full_time_home_score'];
    $away = (int) $fixture['full_time_away_score'];

    // Goals scored by City players. An own goal by one of our players counts for the
    // opposition, so it is left out of the scorers list.
    $types = matchday_record_event_types($pdo);
    $scorers = [];
    foreach (matchday_events_get($pdo, (int) $fixture['id']) as $event) {
        $projectsTo = $types[(string) $event['type']]['projects_to'] ?? '';
        if ($projectsTo === 'goal'
            && (string) $event['side'] === 'svfc'
            && (int) $event['own_goal'] === 0
            && trim((string) $event['player_name']) !== '') {
            $scorers[] = trim((string) $event['player_name']);
        }
    }

    return [
        'city_score' => $isHome ? $home : $away,
        'opposition_score' => $isHome ? $away : $home,
        'scorers' => $scorers,
    ];
}

/**
 * Save or change a member's prediction. Rejects fixtures that are locked, already
 * played, or postponed, so a late save can't slip through.
 *
 * @return string '' on success, otherwise a message to show the member
 */
function sp_save_prediction(PDO $pdo, int $fixtureId, int $holderId, int $cityScore, int $oppositionScore, string $goalscorer): string
{
    sp_ensure_schema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE id = :id');
    $stmt->execute([':id' => $fixtureId]);
    $fixture = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fixture) {
        return 'That fixture could not be found.';
    }
    if ((string) $fixture['status'] !== 'scheduled') {
        return 'Predictions are closed for this fixture.';
    }
    if (sp_fixture_is_locked($fixture)) {
        return 'Predictions closed at kick-off.';
    }
    if ($cityScore < 0 || $cityScore > 20 || $oppositionScore < 0 || $oppositionScore > 20) {
        return 'Scores must be between 0 and 20.';
    }
    if (trim($goalscorer) === '') {
        return 'Pick a goalscorer.';
    }

    $pdo->prepare('INSERT INTO sp_predictions (fixture_id, holder_id, city_score, opposition_score, goalscorer)
        VALUES (:fixture_id, :holder_id, :city_score, :opposition_score, :goalscorer)
        ON DUPLICATE KEY UPDATE city_score = VALUES(city_score),
            opposition_score = VALUES(opposition_score),
            goalscorer = VALUES(goalscorer)')
        ->execute([
            ':fixture_id' => $fixtureId,
            ':holder_id' => $holderId,
            ':city_score' => $cityScore,
            ':opposition_score' => $oppositionScore,
            ':goalscorer' => trim($goalscorer),
        ]);

    return '';
}

/**
 * Score every prediction for a played fixture and store the results. Safe to re-run,
 * for example after a result is corrected: existing scores for the fixture are replaced.
 *
 * @return int number of predictions scored
 */
function sp_settle_fixture(PDO $pdo, int $fixtureId): int
{
    sp_ensure_schema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE id = :id');
    $stmt->execute([':id' => $fixtureId]);
    $fixture = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fixture) {
        throw new RuntimeException('Fixture not found.');
    }

    $result = sp_actual_result($pdo, $fixture);
    if ($result === null) {
        throw new RuntimeException('Fixture is not played yet, or has no full-time score.');
    }

    $points = sp_points($pdo);
    $isCup = sp_fixture_is_cup($pdo, $fixture);

    $predictions = $pdo->prepare('SELECT * FROM sp_predictions WHERE fixture_id = :fixture_id');
    $predictions->execute([':fixture_id' => $fixtureId]);
    $rows = $predictions->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sp_scores WHERE fixture_id = :fixture_id')->execute([':fixture_id' => $fixtureId]);

        $insert = $pdo->prepare('INSERT INTO sp_scores
            (prediction_id, fixture_id, holder_id, season_id, match_date, submitted_at, is_cup,
             submit_points, result_points, city_score_points, opposition_score_points, scoreline_points,
             goalscorer_points, cup_bonus_points, total_points)
            VALUES (:prediction_id, :fixture_id, :holder_id, :season_id, :match_date, :submitted_at, :is_cup,
             :submit_points, :result_points, :city_score_points, :opposition_score_points, :scoreline_points,
             :goalscorer_points, :cup_bonus_points, :total_points)');

        foreach ($rows as $row) {
            $score = sp_score_prediction($row, $result, $isCup, $points);
            $insert->execute([
                ':prediction_id' => (int) $row['id'],
                ':fixture_id' => $fixtureId,
                ':holder_id' => (int) $row['holder_id'],
                ':season_id' => (int) $fixture['season_id'],
                ':match_date' => (string) $fixture['match_date'],
                ':submitted_at' => (string) $row['submitted_at'],
                ':is_cup' => $isCup ? 1 : 0,
                ':submit_points' => $score['submit'],
                ':result_points' => $score['result'],
                ':city_score_points' => $score['city_score'],
                ':opposition_score_points' => $score['opposition_score'],
                ':scoreline_points' => $score['scoreline'],
                ':goalscorer_points' => $score['goalscorer'],
                ':cup_bonus_points' => $score['cup_bonus'],
                ':total_points' => $score['total'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return count($rows);
}

/**
 * Leaderboard rows for one month (YYYY-MM) or the whole season, ranked by points.
 * Ties go to whoever submitted their first counted prediction earliest.
 *
 * @return list<array{holder_id:int, name:string, points:int, predictions:int, first_submitted:string}>
 */
function sp_leaderboard(PDO $pdo, int $seasonId, ?string $month = null): array
{
    sp_ensure_schema($pdo);

    $where = 's.season_id = :season_id';
    $params = [':season_id' => $seasonId];
    if ($month !== null) {
        $where .= " AND DATE_FORMAT(s.match_date, '%Y-%m') = :month";
        $params[':month'] = $month;
    }

    $stmt = $pdo->prepare("SELECT s.holder_id,
            h.name,
            SUM(s.total_points) AS points,
            COUNT(*) AS predictions,
            MIN(s.submitted_at) AS first_submitted
        FROM sp_scores s
        LEFT JOIN season_ticket_holders h ON h.id = s.holder_id
        WHERE {$where}
        GROUP BY s.holder_id, h.name
        ORDER BY points DESC, first_submitted ASC");
    $stmt->execute($params);

    return array_map(static fn(array $row): array => [
        'holder_id' => (int) $row['holder_id'],
        'name' => (string) ($row['name'] ?? ''),
        'points' => (int) $row['points'],
        'predictions' => (int) $row['predictions'],
        'first_submitted' => (string) $row['first_submitted'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * "Charlie Lundy" becomes "C. Lundy". Single names are returned unchanged.
 */
function sp_display_name(string $fullName): string
{
    $parts = preg_split('/\s+/', trim($fullName)) ?: [];
    if (count($parts) < 2) {
        return trim($fullName);
    }
    $first = mb_substr($parts[0], 0, 1);
    $last = $parts[count($parts) - 1];
    return mb_strtoupper($first) . '. ' . $last;
}
