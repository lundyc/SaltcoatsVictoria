<?php

declare(strict_types=1);

require_once __DIR__ . '/../matches_lib.php';
require_once __DIR__ . '/match_overview.php';

function ensureMemberMatchSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $column = $pdo->query("SHOW COLUMNS FROM match_fixtures LIKE 'veo_url'")->fetch(PDO::FETCH_ASSOC);
    if (!$column) {
        $pdo->exec("ALTER TABLE match_fixtures ADD COLUMN veo_url VARCHAR(500) NULL AFTER notes");
    }

    $done = true;
}

/**
 * Fixtures for a season, earliest first, for the member "Matches" list.
 *
 * @return list<array<string, mixed>>
 */
function member_matches_for_season(PDO $pdo, int $seasonId): array
{
    ensureMemberMatchSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE season_id = :season ORDER BY match_date ASC, kickoff_time ASC, id ASC');
    $stmt->execute([':season' => $seasonId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Recent played matches against the same opponent for lightweight H2H context.
 *
 * @return list<array<string, mixed>>
 */
function member_match_head_to_head(PDO $pdo, array $fixture, int $limit = 5): array
{
    ensureMemberMatchSchema($pdo);
    $opponentId = (int) ($fixture['opponent_id'] ?? 0);
    $opponent = trim((string) ($fixture['opponent'] ?? ''));
    if ($opponentId <= 0 && $opponent === '') {
        return [];
    }

    $where = $opponentId > 0 ? 'opponent_id = :opponent_id' : 'opponent = :opponent';
    $stmt = $pdo->prepare("SELECT * FROM match_fixtures
        WHERE id <> :id
          AND {$where}
          AND full_time_home_score IS NOT NULL
          AND full_time_away_score IS NOT NULL
        ORDER BY match_date DESC, kickoff_time DESC, id DESC
        LIMIT " . max(1, min(10, $limit)));
    $params = [':id' => (int) $fixture['id']];
    if ($opponentId > 0) {
        $params[':opponent_id'] = $opponentId;
    } else {
        $params[':opponent'] = $opponent;
    }
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array{played:int,wins:int,draws:int,losses:int}
 */
function member_match_head_to_head_record(array $matches): array
{
    $record = ['played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0];
    foreach ($matches as $match) {
        if ($match['full_time_home_score'] === null || $match['full_time_away_score'] === null) {
            continue;
        }
        $record['played']++;
        $home = (int) $match['full_time_home_score'];
        $away = (int) $match['full_time_away_score'];
        $saltcoats = (int) $match['is_home'] === 1 ? $home : $away;
        $opponent = (int) $match['is_home'] === 1 ? $away : $home;
        if ($saltcoats > $opponent) {
            $record['wins']++;
        } elseif ($saltcoats === $opponent) {
            $record['draws']++;
        } else {
            $record['losses']++;
        }
    }
    return $record;
}

/**
 * Full detail for one fixture: the SQL row (score, lineup, VEO link) plus
 * its match events, which live in the separate JSON-backed match store
 * (matches_lib.php) keyed by the same id once merged with the master
 * match_fixtures row — see matches_merge_master_fixtures().
 *
 * @return array{fixture: array<string, mixed>, events: list<array<string, mixed>>, starters: list<string>, substitutes: list<string>}|null
 */
function member_match_detail(PDO $pdo, int $fixtureId): ?array
{
    ensureMemberMatchSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM match_fixtures WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $fixtureId]);
    $fixture = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fixture) {
        return null;
    }

    $jsonMatch = matches_find_by_id(matches_load_all(), (string) $fixtureId);
    $events = $jsonMatch['events'] ?? [];
    $events = is_array($events) ? matchOverviewSortedEvents($events) : [];

    $starters = json_decode((string) ($fixture['starting11_starters_json'] ?? ''), true);
    $substitutes = json_decode((string) ($fixture['starting11_substitutes_json'] ?? ''), true);

    return [
        'fixture' => $fixture,
        'events' => $events,
        'starters' => is_array($starters) ? array_values(array_filter($starters, 'is_string')) : [],
        'substitutes' => is_array($substitutes) ? array_values(array_filter($substitutes, 'is_string')) : [],
    ];
}
