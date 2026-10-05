<?php
declare(strict_types=1);

function hub_player_stats_normalize_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    return mb_strtolower($name, 'UTF-8');
}

/** Own-goal events identify the benefiting team, not the scorer's team. */
function hub_player_stats_is_club_player_event(array $event): bool
{
    $team = (string)($event['team'] ?? '');
    return !empty($event['own_goal']) ? $team === 'opponent' : $team === 'svfc';
}

function hub_player_stats_event_minute(mixed $value): int
{
    $minute = trim((string)$value);
    if (preg_match('/^(\d{1,3})(?:\+(\d{1,2}))?$/', $minute, $parts) !== 1) {
        return 0;
    }

    return min(90, (int)$parts[1] + (int)($parts[2] ?? 0));
}

/**
 * Calculate season totals from the fixture lineups and Match Graphics timeline.
 * Optional preloaded fixtures/events allow filtered stats without repeated queries.
 *
 * @return array{
 *   appearances: int,
 *   starts: int,
 *   substitute_appearances: int,
 *   goals: int,
 *   yellow_cards: int,
 *   red_cards: int,
 *   clean_sheets: int,
 *   minutes_played: int,
 *   is_goalkeeper: bool
 * }
 */
function hub_player_match_stats(
    PDO $pdo,
    int $seasonId,
    string $playerName,
    string $matchesDataFile,
    ?array $fixtures = null,
    ?array $matchEvents = null
): array {
    $stats = [
        'appearances' => 0,
        'starts' => 0,
        'substitute_appearances' => 0,
        'goals' => 0,
        'yellow_cards' => 0,
        'red_cards' => 0,
        'clean_sheets' => 0,
        'minutes_played' => 0,
        'is_goalkeeper' => false,
    ];
    $normalizedPlayerName = hub_player_stats_normalize_name($playerName);
    if ($seasonId <= 0 || $normalizedPlayerName === '') {
        return $stats;
    }

    // $matchesDataFile is accepted for backward compatibility but no longer
    // read — events always come straight from matchday_events now (see
    // hub_matchday_events_by_fixture() in lib/stats.php).
    $eventsByFixture = $matchEvents ?? [];
    if ($matchEvents === null) {
        require_once __DIR__ . '/stats.php';
        $eventsByFixture = hub_matchday_events_by_fixture($pdo);
    }

    if ($fixtures === null) {
        $stmt = $pdo->prepare("
            SELECT id, is_home, status,
                   starting11_starters_json, starting11_substitutes_json,
                   full_time_home_score, full_time_away_score
            FROM match_fixtures
            WHERE season_id = :season_id
              AND status = 'played'
            ORDER BY match_date ASC, kickoff_time ASC, id ASC
        ");
        $stmt->execute([':season_id' => $seasonId]);

        $fixtures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($fixtures as $fixture) {
        if (($fixture['status'] ?? '') !== 'played') {
            continue;
        }
        $starters = json_decode((string)($fixture['starting11_starters_json'] ?? '[]'), true);
        $substitutes = json_decode((string)($fixture['starting11_substitutes_json'] ?? '[]'), true);
        $starters = is_array($starters) ? array_values($starters) : [];
        $substitutes = is_array($substitutes) ? array_values($substitutes) : [];

        $starterIndex = null;
        foreach ($starters as $index => $starter) {
            if (hub_player_stats_normalize_name((string)$starter) === $normalizedPlayerName) {
                $starterIndex = (int)$index;
                break;
            }
        }
        $isStarter = $starterIndex !== null;
        $isNamedSubstitute = false;
        foreach ($substitutes as $substitute) {
            if (hub_player_stats_normalize_name((string)$substitute) === $normalizedPlayerName) {
                $isNamedSubstitute = true;
                break;
            }
        }

        $events = array_values(array_filter($eventsByFixture[(int)$fixture['id']] ?? [], 'is_array'));
        usort($events, static fn(array $left, array $right): int =>
            (int)($left['sequence'] ?? 0) <=> (int)($right['sequence'] ?? 0)
        );

        $onMinute = null;
        $offMinute = null;
        $opponentGoalsFromEvents = 0;
        $hasFullTimeEvent = false;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $type = (string)($event['type'] ?? '');
            $team = (string)($event['team'] ?? '');
            $eventPlayer = hub_player_stats_normalize_name((string)($event['player'] ?? ''));
            $isScoringEvent = $type === 'goal' || $type === 'penalty_scored'
                || ($type === 'penalty' && (string)($event['outcome'] ?? '') === 'scored');

            if ($isScoringEvent && $team === 'opponent') {
                $opponentGoalsFromEvents++;
            }
            if ($type === 'full_time') {
                $hasFullTimeEvent = true;
            }
            if ($team === 'svfc' && $eventPlayer === $normalizedPlayerName) {
                if ($isScoringEvent && empty($event['own_goal'])) {
                    $stats['goals']++;
                }
                if ($type === 'yellow_card' || ($type === 'card' && (string)($event['card_type'] ?? '') === 'yellow')) {
                    $stats['yellow_cards']++;
                }
                if ($type === 'red_card' || ($type === 'card' && (string)($event['card_type'] ?? '') === 'red')) {
                    $stats['red_cards']++;
                }
            }

            if ($type !== 'substitution' || $team !== 'svfc') {
                continue;
            }
            $minute = hub_player_stats_event_minute($event['minute'] ?? '');
            $changes = is_array($event['substitutions'] ?? null) ? $event['substitutions'] : [];
            if ($changes === []) {
                $changes[] = [
                    'off' => (string)($event['player'] ?? ''),
                    'on' => (string)($event['secondary_player'] ?? ''),
                ];
            }
            foreach ($changes as $change) {
                if (!is_array($change)) {
                    continue;
                }
                if (
                    $offMinute === null
                    && hub_player_stats_normalize_name((string)($change['off'] ?? '')) === $normalizedPlayerName
                ) {
                    $offMinute = $minute;
                }
                if (
                    $onMinute === null
                    && hub_player_stats_normalize_name((string)($change['on'] ?? '')) === $normalizedPlayerName
                ) {
                    $onMinute = $minute;
                }
            }
        }

        if ($isStarter) {
            $stats['appearances']++;
            $stats['starts']++;
            $stats['minutes_played'] += $offMinute ?? 90;
        } elseif ($isNamedSubstitute && $onMinute !== null) {
            $stats['appearances']++;
            $stats['substitute_appearances']++;
            $stats['minutes_played'] += max(0, 90 - $onMinute);
        }

        // Hub lineup slot one is the goalkeeper/shirt number one position.
        if ($isStarter && $starterIndex === 0) {
            $stats['is_goalkeeper'] = true;
            $opponentScore = null;
            if ($fixture['full_time_home_score'] !== null && $fixture['full_time_away_score'] !== null) {
                $opponentScore = (int)$fixture[(int)$fixture['is_home'] === 1
                    ? 'full_time_away_score'
                    : 'full_time_home_score'];
            } elseif ($hasFullTimeEvent) {
                $opponentScore = $opponentGoalsFromEvents;
            }
            if ($opponentScore === 0) {
                $stats['clean_sheets']++;
            }
        }
    }

    return $stats;
}
