<?php

declare(strict_types=1);

require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/render_lib.php';
require_once __DIR__ . '/social_post_settings.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/render_access_token.php';

/**
 * @param array<string, mixed> $match
 * @return list<array<string, mixed>>
 */
function event_share_events(array $match): array
{
    return isset($match['events']) && is_array($match['events']) ? $match['events'] : [];
}

/**
 * @param array<string, mixed> $match
 * @return array<string, mixed>|null
 */
function event_share_find_event(array $match, string $eventId): ?array
{
    $eventId = trim($eventId);
    if ($eventId === '') {
        return null;
    }

    foreach (event_share_events($match) as $event) {
        if ((string) ($event['id'] ?? '') === $eventId) {
            return $event;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $match
 * @return array<string, mixed>|null
 */
function event_share_latest_event(array $match): ?array
{
    $events = event_share_events($match);
    if ($events === []) {
        return null;
    }

    $latest = end($events);
    return is_array($latest) ? $latest : null;
}

/**
 * "Unknown Player" is the internal placeholder used when recording an
 * opponent event without a specific name (we don't track opposition squads).
 * Public-facing captions and graphics should show the opponent's club name
 * instead, never that internal placeholder.
 *
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 */
function event_share_public_player_name(array $match, array $event, string $playerName): string
{
    $playerName = trim($playerName);
    if ($playerName !== '' && strcasecmp($playerName, 'Unknown Player') !== 0) {
        return $playerName;
    }

    if ((string) ($event['team'] ?? '') === 'opponent') {
        $opponent = trim((string) ($match['opponent'] ?? ''));
        if ($opponent !== '') {
            return $opponent;
        }
    }

    return 'Unknown Player';
}

/**
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 */
function event_share_title(array $match, array $event): string
{
    $labels = matches_event_type_labels();
    $type = (string) ($event['type'] ?? '');
    $team = (string) ($event['team'] ?? '');
    $player = event_share_public_player_name($match, $event, (string) ($event['player'] ?? ''));
    $secondary = trim((string) ($event['secondary_player'] ?? ''));
    $note = trim((string) ($event['note'] ?? ''));
    $cardType = trim((string) ($event['card_type'] ?? ''));

    if ($type === 'goal') {
        if (!empty($event['own_goal'])) {
            $ownGoalPlayer = trim((string) ($event['player'] ?? ''));
            return $ownGoalPlayer !== '' && strcasecmp($ownGoalPlayer, 'Unknown Player') !== 0
                ? 'Own Goal: ' . $ownGoalPlayer
                : 'Own Goal';
        }
        if ($team === 'svfc' && $player !== '') {
            return 'Goal: ' . $player;
        }
        if ($note !== '') {
            return 'Goal: ' . $note;
        }
        return 'Goal';
    }

    if ($type === 'card') {
        $cardLabel = $cardType !== '' ? ucfirst($cardType) . ' card' : 'Card';
        if ($player !== '') {
            return $cardLabel . ': ' . $player;
        }
        if ($note !== '') {
            return $cardLabel . ': ' . $note;
        }
        return $cardLabel;
    }

    if (in_array($type, ['yellow_card', 'red_card'], true)) {
        $cardLabel = $type === 'yellow_card' ? 'Yellow card' : 'Red card';
        return $cardLabel . ($player !== '' ? ': ' . $player : '');
    }

    if ($type === 'penalty') {
        $outcome = trim((string) ($event['outcome'] ?? ''));
        $title = 'Penalty' . ($player !== '' ? ': ' . $player : '');
        if ($outcome !== '') {
            $title .= ' · ' . ucwords(str_replace('_', ' ', $outcome));
        }
        return $title;
    }

    if ($type === 'substitution') {
        $substitutions = event_share_substitution_pairs($match, $event);
        if (count($substitutions) > 1) {
            return 'Substitutions: ' . count($substitutions) . ' changes';
        }
        if ($team === 'svfc' && $player !== '' && $secondary !== '') {
            return 'Substitution: ' . $player . ' off, ' . $secondary . ' on';
        }
        if ($note !== '') {
            return 'Substitution: ' . $note;
        }
        return 'Substitution';
    }

    if ($type === 'player_of_match') {
        return 'Player of the Match' . ($player !== '' ? ': ' . $player : '');
    }

    if ($type === 'note' && $note !== '') {
        return $note;
    }

    return $labels[$type] ?? 'Match event';
}

/**
 * Returns every OFF/ON pair while remaining compatible with older single-sub records.
 *
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 * @return list<array{off: string, on: string}>
 */
function event_share_substitution_pairs(array $match, array $event): array
{
    $pairs = [];
    $submitted = isset($event['substitutions']) && is_array($event['substitutions'])
        ? $event['substitutions']
        : [];
    foreach ($submitted as $pair) {
        if (!is_array($pair)) {
            continue;
        }
        $off = trim((string) ($pair['off'] ?? ''));
        $on = trim((string) ($pair['on'] ?? ''));
        if ($off === '' && $on === '') {
            continue;
        }
        $pairs[] = [
            'off' => event_share_public_player_name($match, $event, $off),
            'on' => event_share_public_player_name($match, $event, $on),
        ];
    }
    if ($pairs === []) {
        $off = trim((string) ($event['player'] ?? ''));
        $on = trim((string) ($event['secondary_player'] ?? ''));
        if ($off !== '' || $on !== '') {
            $pairs[] = [
                'off' => event_share_public_player_name($match, $event, $off),
                'on' => event_share_public_player_name($match, $event, $on),
            ];
        }
    }

    return $pairs;
}

/**
 * @param array<string, mixed> $event
 */
function event_share_meta(array $event): string
{
    $teamLabels = matches_event_team_labels();
    $type = (string) ($event['type'] ?? '');
    $team = (string) ($event['team'] ?? '');
    $minute = trim((string) ($event['minute'] ?? ''));
    $parts = [];

    if ($minute !== '') {
        $parts[] = $minute . "'";
    }

    if (!in_array($type, ['kickoff', 'half_time', 'second_half', 'full_time'], true) && isset($teamLabels[$team])) {
        $parts[] = $teamLabels[$team];
    }

    return implode(' | ', $parts);
}

function event_share_team_abbreviation(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') {
        return 'TBC';
    }

    $words = preg_split('/\s+/', strtoupper($name)) ?: [];
    $words = array_values(array_filter($words, static function (string $word): bool {
        return $word !== '' && !in_array($word, ['FC', 'AFC', 'SC', 'THE'], true);
    }));

    if ($words === []) {
        return strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($name)) ?? 'TBC', 0, 3));
    }

    if (count($words) >= 3) {
        return substr(implode('', array_map(static fn(string $word): string => substr($word, 0, 1), $words)), 0, 3);
    }

    return substr($words[0], 0, 3);
}

function event_share_match_venue_name(array $match): string
{
    $explicitVenue = trim((string) ($match['venue_name'] ?? ''));
    if ($explicitVenue !== '') {
        return $explicitVenue;
    }

    $venue = matches_normalize_venue((string) ($match['venue'] ?? 'H'));
    if ($venue === 'A') {
        $opponent = trim((string) ($match['opponent'] ?? 'the opposition'));
        return $opponent !== '' ? $opponent : 'the opposition';
    }

    return 'Barrfields';
}

function event_share_kickoff_line(array $match): string
{
    return "1’ | Kick off at " . event_share_match_venue_name($match);
}

/**
 * Return the score immediately after the selected event.
 *
 * @return array{home: int, away: int}
 */
function event_share_score_at_event(array $match, array $selectedEvent): array
{
    $home = 0;
    $away = 0;
    $selectedId = (string) ($selectedEvent['id'] ?? '');
    $selectedSequence = (int) ($selectedEvent['sequence'] ?? PHP_INT_MAX);
    $venue = matches_normalize_venue((string) ($match['venue'] ?? 'H'));

    foreach (event_share_events($match) as $event) {
        $sequence = (int) ($event['sequence'] ?? 0);
        if ($sequence > $selectedSequence) {
            continue;
        }
        $eventType = (string) ($event['type'] ?? '');
        $isScoringEvent = $eventType === 'goal'
            || ($eventType === 'penalty' && (string) ($event['outcome'] ?? '') === 'scored');
        if ($isScoringEvent) {
            $isSvfc = (string) ($event['team'] ?? '') === 'svfc';
            $isHomeGoal = $venue === 'H' ? $isSvfc : !$isSvfc;
            $isHomeGoal ? $home++ : $away++;
        }
        if ($selectedId !== '' && (string) ($event['id'] ?? '') === $selectedId) {
            break;
        }
    }

    return ['home' => $home, 'away' => $away];
}

function event_share_score_line(array $match, ?array $event = null): string
{
    $homeName = matches_normalize_venue((string) ($match['venue'] ?? 'H')) === 'H'
        ? 'Saltcoats Victoria'
        : trim((string) ($match['opponent'] ?? 'Opponent'));
    $awayName = matches_normalize_venue((string) ($match['venue'] ?? 'H')) === 'H'
        ? trim((string) ($match['opponent'] ?? 'Opponent'))
        : 'Saltcoats Victoria';

    $score = $event === null ? ['home' => 0, 'away' => 0] : event_share_score_at_event($match, $event);

    return event_share_team_abbreviation($homeName)
        . ' ' . $score['home'] . '-' . $score['away'] . ' '
        . event_share_team_abbreviation($awayName);
}

/**
 * Same as event_share_score_line() but with full team names and an en-dash,
 * for the concise Facebook live-event captions, e.g.
 * "West Park United 0–1 Saltcoats Victoria".
 */
function event_share_score_line_full(array $match, ?array $event = null): string
{
    $homeName = matches_normalize_venue((string) ($match['venue'] ?? 'H')) === 'H'
        ? 'Saltcoats Victoria'
        : trim((string) ($match['opponent'] ?? 'Opponent'));
    $awayName = matches_normalize_venue((string) ($match['venue'] ?? 'H')) === 'H'
        ? trim((string) ($match['opponent'] ?? 'Opponent'))
        : 'Saltcoats Victoria';

    $score = $event === null ? ['home' => 0, 'away' => 0] : event_share_score_at_event($match, $event);

    return $homeName . ' ' . $score['home'] . '–' . $score['away'] . ' ' . $awayName;
}

/**
 * Full team name for the side an event belongs to (Saltcoats Victoria or the
 * opponent's full name), for the {Team Full} caption placeholder.
 */
function event_share_event_team_full(array $match, array $event): string
{
    $team = (string) ($event['team'] ?? '');
    if ($team === 'svfc') {
        return 'Saltcoats Victoria';
    }

    $opponent = trim((string) ($match['opponent'] ?? ''));
    return $opponent !== '' ? $opponent : 'the opposition';
}

function event_share_own_goal_player_label(array $match, array $event): string
{
    $playerName = trim((string) ($event['player'] ?? ''));
    if ($playerName !== '' && strcasecmp($playerName, 'Unknown Player') !== 0) {
        return $playerName . ' (OG)';
    }

    $beneficiary = (string) ($event['team'] ?? 'svfc');
    $scoringTeam = $beneficiary === 'svfc'
        ? trim((string) ($match['opponent'] ?? 'Opponent'))
        : 'Saltcoats Victoria';

    return ($scoringTeam !== '' ? $scoringTeam : 'Opponent') . ' Own Goal';
}

/**
 * Return a player's active home and away sponsors in shirt-side order.
 *
 * @return list<string>
 */
function event_share_player_sponsors_by_name(array $match, string $playerName): array
{
    static $databaseConnection = null;

    $playerName = trim($playerName);
    $seasonId = (int) ($match['season_id'] ?? 0);
    if ($playerName === '' || strcasecmp($playerName, 'Unknown Player') === 0 || $seasonId <= 0) {
        return [];
    }

    try {
        $database = isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO ? $GLOBALS['pdo'] : $databaseConnection;
        if (!$database instanceof PDO) {
            require_once __DIR__ . '/db.php';
            $database = isset($pdo) && $pdo instanceof PDO ? $pdo : null;
        }
        if (!$database instanceof PDO) {
            return [];
        }
        $databaseConnection = $database;

        $statement = $database->prepare('
            SELECT sponsorships.slot, sponsors.name
            FROM players
            INNER JOIN sponsorships
                ON sponsorships.player_id = players.id
                AND sponsorships.season_id = :season_id
                AND sponsorships.ended_at IS NULL
                AND sponsorships.slot IN (\'home\', \'away\')
            INNER JOIN sponsors ON sponsors.id = sponsorships.sponsor_id
            WHERE TRIM(players.name) = :player_name
              AND sponsors.name IS NOT NULL
              AND TRIM(sponsors.name) <> \'\'
            ORDER BY FIELD(sponsorships.slot, \'home\', \'away\'), sponsorships.id DESC
        ');
        $statement->execute([
            ':season_id' => $seasonId,
            ':player_name' => $playerName,
        ]);

        $sponsorsBySlot = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $slot = (string) ($row['slot'] ?? '');
            $name = trim((string) ($row['name'] ?? ''));
            if ($name !== '' && !isset($sponsorsBySlot[$slot])) {
                $sponsorsBySlot[$slot] = $name;
            }
        }

        return array_values(array_unique(array_filter([
            $sponsorsBySlot['home'] ?? '',
            $sponsorsBySlot['away'] ?? '',
        ])));
    } catch (Throwable $exception) {
        return [];
    }
}

/**
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 * @return list<string>
 */
function event_share_player_sponsor_names(array $match, array $event): array
{
    if (
        (string) ($event['type'] ?? '') !== 'goal'
        || (string) ($event['team'] ?? '') !== 'svfc'
        || !empty($event['own_goal'])
    ) {
        return [];
    }

    return event_share_player_sponsors_by_name($match, (string) ($event['player'] ?? ''));
}

/**
 * Build the Saltcoats scorer list up to and including the selected event.
 */
function event_share_scorer_credits(array $match, array $selectedEvent): string
{
    $selectedId = (string) ($selectedEvent['id'] ?? '');
    $selectedSequence = (int) ($selectedEvent['sequence'] ?? PHP_INT_MAX);
    $scorers = [];

    foreach (event_share_events($match) as $event) {
        $sequence = (int) ($event['sequence'] ?? 0);
        if ($sequence > $selectedSequence) {
            continue;
        }

        $eventType = (string) ($event['type'] ?? '');
        $isSaltcoatsGoal = (string) ($event['team'] ?? '') === 'svfc'
            && ($eventType === 'goal'
                || ($eventType === 'penalty' && (string) ($event['outcome'] ?? '') === 'scored'));

        if ($isSaltcoatsGoal) {
            $playerName = trim((string) ($event['player'] ?? ''));
            $isOwnGoal = !empty($event['own_goal']);
            if ($isOwnGoal) {
                $playerName = event_share_own_goal_player_label($match, $event);
            } elseif ($playerName === '') {
                $playerName = 'Unknown Player';
            }
            $key = ($isOwnGoal ? 'og:' : 'goal:')
                . (function_exists('mb_strtolower') ? mb_strtolower($playerName) : strtolower($playerName));
            if (!isset($scorers[$key])) {
                $scorers[$key] = ['name' => $playerName, 'goals' => 0, 'own_goal' => $isOwnGoal];
            }
            $scorers[$key]['goals']++;
        }

        if ($selectedId !== '' && (string) ($event['id'] ?? '') === $selectedId) {
            break;
        }
    }

    $lines = [];
    foreach ($scorers as $scorer) {
        $line = str_repeat('⚽', (int) $scorer['goals']) . ' ' . $scorer['name'];
        $sponsors = !empty($scorer['own_goal'])
            ? []
            : event_share_player_sponsors_by_name($match, (string) $scorer['name']);
        if ($sponsors !== []) {
            $line .= ' - Sponsored By: ' . implode(' : ', $sponsors);
        }
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

/**
 * Build active Matchday and Match Ball sponsor credits for an event caption.
 * Facebook receives the sponsor's readable name as plain text. Instagram and X
 * receive an @handle derived from the saved profile URL.
 */
function event_share_fixture_sponsor_credit(array $match, string $channel = 'facebook'): string
{
    static $databaseConnection = null;

    $fixtureId = (int)($match['id'] ?? $match['fixture_id'] ?? 0);
    if ($fixtureId <= 0) {
        return '';
    }

    try {
        $database = isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO ? $GLOBALS['pdo'] : $databaseConnection;
        if (!$database instanceof PDO) {
            require_once __DIR__ . '/db.php';
            $database = isset($pdo) && $pdo instanceof PDO ? $pdo : null;
        }
        if (!$database instanceof PDO) {
            return '';
        }
        $databaseConnection = $database;

        $statement = $database->prepare('
            SELECT
                ms.sponsorship_role,
                sponsors.name,
                sponsors.facebook_page_url,
                sponsors.facebook_page_id,
                sponsors.facebook_page_name,
                sponsors.instagram_url,
                sponsors.twitter_url
            FROM match_sponsorships ms
            INNER JOIN sponsors ON sponsors.id = ms.sponsor_id
            LEFT JOIN (
                SELECT match_sponsorship_id, SUM(amount) AS total_paid
                FROM match_sponsorship_payments
                GROUP BY match_sponsorship_id
            ) sponsor_payments ON sponsor_payments.match_sponsorship_id = ms.id
            WHERE ms.fixture_id = :fixture_id
              AND ms.ended_at IS NULL
              AND ms.sponsorship_role IN (\'match_day\', \'match_ball\')
              AND (
                  ms.is_complimentary = 1
                  OR ms.amount <= 0
                  OR COALESCE(sponsor_payments.total_paid, 0) >= ms.amount
              )
              AND sponsors.name IS NOT NULL
              AND TRIM(sponsors.name) <> \'\'
            ORDER BY
                FIELD(ms.sponsorship_role, \'match_day\', \'match_ball\'),
                sponsors.name ASC,
                ms.id ASC
        ');
        $statement->execute([':fixture_id' => $fixtureId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        return '';
    }

    $lines = [];
    $seen = [];
    foreach ($rows as $row) {
        $role = (string)($row['sponsorship_role'] ?? '');
        $name = $channel === 'facebook'
            ? trim((string)($row['facebook_page_name'] ?? ''))
            : '';
        if ($name === '') {
            $name = trim((string)($row['name'] ?? ''));
        }
        $key = $role . '|' . strtolower($name);
        if ($name === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $reference = '';
        if ($channel === 'facebook') {
            $reference = trim((string)($row['facebook_page_url'] ?? ''));
        } elseif ($channel === 'instagram') {
            $reference = event_share_social_handle((string)($row['instagram_url'] ?? ''), ['instagram.com']);
        } elseif ($channel === 'x') {
            $reference = event_share_social_handle((string)($row['twitter_url'] ?? ''), ['x.com', 'twitter.com']);
        }

        $label = $role === 'match_ball' ? 'Match Ball Sponsor' : 'Matchday Sponsor';
        if ($channel === 'facebook') {
            $lines[] = $label . ': ' . ltrim($name, '@');
            continue;
        }

        $lines[] = $label . ': ' . $name . ($reference !== '' ? ' — ' . $reference : '');
    }

    return implode("\n", $lines);
}

/**
 * Derive a platform @handle from a saved social profile URL.
 *
 * @param list<string> $allowedHosts
 */
function event_share_social_handle(string $url, array $allowedHosts): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }

    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host) ?? $host;
    if (!in_array($host, $allowedHosts, true)) {
        return '';
    }

    $segments = array_values(array_filter(explode('/', trim((string)parse_url($url, PHP_URL_PATH), '/')), 'strlen'));
    $handle = trim((string)($segments[0] ?? ''), '@');
    return preg_match('/^[A-Za-z0-9._]{1,50}$/', $handle) === 1 ? '@' . $handle : '';
}

/**
 * Return active fixture sponsors that are configured for Facebook Page tags.
 *
 * @return list<array{id: string, name: string, url: string}>
 */
function event_share_facebook_fixture_page_targets(array $match): array
{
    $fixtureId = (int)($match['id'] ?? $match['fixture_id'] ?? 0);
    if ($fixtureId <= 0) {
        return [];
    }

    try {
        $database = isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO ? $GLOBALS['pdo'] : null;
        if (!$database instanceof PDO) {
            require_once __DIR__ . '/db.php';
            $database = isset($pdo) && $pdo instanceof PDO ? $pdo : null;
        }
        if (!$database instanceof PDO) {
            return [];
        }

        $statement = $database->prepare('
            SELECT DISTINCT
                sponsors.name,
                sponsors.facebook_page_name,
                sponsors.facebook_page_id,
                sponsors.facebook_page_url
            FROM match_sponsorships ms
            INNER JOIN sponsors ON sponsors.id = ms.sponsor_id
            LEFT JOIN (
                SELECT match_sponsorship_id, SUM(amount) AS total_paid
                FROM match_sponsorship_payments
                GROUP BY match_sponsorship_id
            ) sponsor_payments ON sponsor_payments.match_sponsorship_id = ms.id
            WHERE ms.fixture_id = :fixture_id
              AND ms.ended_at IS NULL
              AND ms.sponsorship_role IN (\'match_day\', \'match_ball\')
              AND (
                  ms.is_complimentary = 1
                  OR ms.amount <= 0
                  OR COALESCE(sponsor_payments.total_paid, 0) >= ms.amount
              )
              AND sponsors.facebook_page_id REGEXP \'^[0-9]{5,30}$\'
            ORDER BY sponsors.name ASC
        ');
        $statement->execute([':fixture_id' => $fixtureId]);

        $targets = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = trim((string)($row['facebook_page_name'] ?? ''));
            if ($name === '') {
                $name = trim((string)($row['name'] ?? ''));
            }
            $pageId = trim((string)($row['facebook_page_id'] ?? ''));
            if ($name !== '' && $pageId !== '') {
                $targets[] = [
                    'id' => $pageId,
                    'name' => $name,
                    'url' => trim((string)($row['facebook_page_url'] ?? '')),
                ];
            }
        }
        return $targets;
    } catch (Throwable $exception) {
        return [];
    }
}

/**
 * Replace readable sponsor @names with Facebook Page mention tokens. This is
 * used only for a Page feed post, where Facebook processes message mentions.
 */
function event_share_encode_facebook_fixture_mentions(array $match, string $message): string
{
    if ($message === '') {
        return $message;
    }

    $targets = event_share_facebook_fixture_page_targets($match);
    usort($targets, static fn(array $left, array $right): int => strlen($right['name']) <=> strlen($left['name']));
    foreach ($targets as $target) {
        $message = str_replace(
            '@' . ltrim($target['name'], '@'),
            '@[' . $target['id'] . ']',
            $message
        );
    }

    return $message;
}

/**
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 */
function event_share_build_text(array $match, array $event, string $channel = 'facebook'): string
{
    $type = (string) ($event['type'] ?? '');
    $scoreLine = event_share_score_line($match, $event);
    $contentType = isset(social_post_content_definitions($channel)[$type]) ? $type : 'match_update';
    $minute = trim((string) ($event['minute'] ?? ''));
    if ($type === 'goal' && !empty($event['own_goal'])) {
        $player = event_share_own_goal_player_label($match, $event);
    } else {
        $player = event_share_public_player_name($match, $event, (string) ($event['player'] ?? ''));
    }
    $substitutions = event_share_substitution_pairs($match, $event);
    $sponsorCredit = '';
    $preferences = social_publishing_preferences_load();
    if (!empty($preferences['global']['include_player_sponsor']) && $player !== '') {
        $sponsors = event_share_player_sponsor_names($match, $event);
        if ($sponsors !== []) {
            $lastSponsor = count($sponsors) > 1 ? array_pop($sponsors) : '';
            $sponsorList = $lastSponsor !== '' ? implode(', ', $sponsors) . ' and ' . $lastSponsor : $sponsors[0];
            $sponsorCredit = $player . ' is sponsored by ' . $sponsorList . '.';
        }
    }
    $context = [
        'score' => $scoreLine,
        'score_full' => event_share_score_line_full($match, $event),
        'team_full' => event_share_event_team_full($match, $event),
        'scorer_credits' => in_array($type, ['half_time', 'full_time'], true)
            ? event_share_scorer_credits($match, $event)
            : '',
        'minute' => $minute !== '' ? $minute . "'" : '',
        'player' => $player,
        'player_off' => (string) ($substitutions[0]['off'] ?? ''),
        'player_on' => (string) ($substitutions[0]['on'] ?? ''),
        'outcome' => strtoupper(str_replace('_', ' ', trim((string) ($event['outcome'] ?? '')))),
        'sponsor_credit' => $sponsorCredit,
        'fixture_sponsor_credit' => event_share_fixture_sponsor_credit($match, $channel),
        'event_headline' => event_share_title($match, $event),
        'event_detail' => event_share_meta($event),
        'ground' => event_share_match_venue_name($match),
    ];

    return social_post_resolve_caption($channel, $contentType, $match, $context);

    if ($type === 'kickoff') {
        return implode("\n\n", [
            'KICK-OFF',
            event_share_kickoff_line($match),
            $scoreLine,
        ]);
    }

    if ($type === 'goal') {
        $minute = trim((string) ($event['minute'] ?? ''));
        $player = trim((string) ($event['player'] ?? ''));
        $team = (string) ($event['team'] ?? '');
        $goalLine = $team === 'svfc'
            ? ($player !== '' ? $player : 'Unknown Player')
            : 'Goal for ' . trim((string) ($match['opponent'] ?? 'the opposition'));
        if ($minute !== '') {
            $goalLine = $minute . "' — " . $goalLine;
        }

        $lines = ['GOAL!', $goalLine, $scoreLine];
        $sponsors = event_share_player_sponsor_names($match, $event);
        if ($sponsors !== []) {
            $sponsorText = $sponsors[0];
            if (count($sponsors) > 1) {
                $lastSponsor = array_pop($sponsors);
                $sponsorText = implode(', ', $sponsors) . ' and ' . $lastSponsor;
            }
            $lines[] = $player . ' is sponsored by ' . $sponsorText . '.';
        }

        return implode("\n\n", $lines);
    }

    if ($type === 'half_time') {
        return "HALF-TIME\n\n" . $scoreLine;
    }

    if ($type === 'full_time') {
        return "FULL-TIME\n\n" . $scoreLine;
    }

    if (in_array($type, ['yellow_card', 'red_card'], true)) {
        $isYellow = $type === 'yellow_card';
        $minute = trim((string) ($event['minute'] ?? ''));
        $player = trim((string) ($event['player'] ?? ''));
        $team = (string) ($event['team'] ?? '');
        if ($player === '' || strcasecmp($player, 'Unknown Player') === 0) {
            $player = $team === 'opponent'
                ? trim((string) ($match['opponent'] ?? 'Opponent'))
                : 'Unknown Player';
        }
        $detail = ($minute !== '' ? $minute . "' — " : '') . $player;

        return implode("\n\n", [
            $isYellow ? 'YELLOW CARD 🟨' : 'RED CARD 🟥',
            $detail,
            $scoreLine,
        ]);
    }

    if ($type === 'penalty') {
        $minute = trim((string) ($event['minute'] ?? ''));
        $player = trim((string) ($event['player'] ?? ''));
        $outcome = trim((string) ($event['outcome'] ?? ''));
        $team = (string) ($event['team'] ?? '');
        if ($player === '' || strcasecmp($player, 'Unknown Player') === 0) {
            $player = $team === 'opponent'
                ? trim((string) ($match['opponent'] ?? 'Opponent'))
                : 'Unknown Player';
        }
        $detail = ($minute !== '' ? $minute . "' — " : '') . $player;
        if ($outcome !== '') {
            $detail .= ' · ' . strtoupper(str_replace('_', ' ', $outcome));
        }

        return implode("\n\n", ['PENALTY', $detail, $scoreLine]);
    }

    if ($type === 'substitution') {
        $minute = trim((string) ($event['minute'] ?? ''));
        $team = (string) ($event['team'] ?? '');
        $substitutions = event_share_substitution_pairs($event);
        $lines = [count($substitutions) > 1 ? 'SUBSTITUTIONS 🔄' : 'SUBSTITUTION 🔄'];

        if ($team === 'svfc') {
            $changes = [];
            foreach ($substitutions as $substitution) {
                $changes[] = 'OFF: ' . $substitution['off'] . "\nON: " . $substitution['on'];
            }
            if ($changes === []) {
                $changes[] = "OFF: Unknown Player\nON: Unknown Player";
            }
            $lines[] = ($minute !== '' ? $minute . "'\n" : '') . implode("\n\n", $changes);
        } else {
            $opponent = trim((string) ($match['opponent'] ?? 'Opponent'));
            $lines[] = ($minute !== '' ? $minute . "' — " : '') . $opponent . ' substitution';
        }
        $lines[] = $scoreLine;

        return implode("\n\n", $lines);
    }

    $opponent = trim((string) ($match['opponent'] ?? 'the opposition'));
    $venue = matches_normalize_venue((string) ($match['venue'] ?? 'H'));
    $competition = trim((string) ($match['competition'] ?? ''));
    $headline = event_share_title($event);
    $meta = event_share_meta($event);

    $fixtureLine = 'Saltcoats Victoria ' . ($venue === 'A' ? 'away at ' : 'vs ') . $opponent;
    $text = $fixtureLine . ' - ' . $headline;

    if ($meta !== '') {
        $text .= ' (' . $meta . ')';
    }

    if ($competition !== '') {
        $text .= ' - ' . $competition;
    }

    return $text;
}

function event_share_export_dir(): string
{
    return __DIR__ . '/export/matches/events';
}

function event_share_export_public_base_url(): string
{
    return APP_ORIGIN . '/admin/export/matches/events';
}

function event_share_background_dir(): string
{
    return __DIR__ . '/uploads/event-backgrounds';
}

function event_share_settings_dir(): string
{
    return __DIR__ . '/uploads/event-settings';
}

/**
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 */
function event_share_background_prefix(array $match, array $event): string
{
    $matchId = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string) ($match['id'] ?? 'match')) ?: 'match';
    $eventId = preg_replace('/[^a-zA-Z0-9_-]+/', '', (string) ($event['id'] ?? 'event')) ?: 'event';

    return strtolower($matchId . '-' . $eventId);
}

/**
 * Return a path relative to the social workspace for use by the graphic renderer.
 *
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 */
function event_share_custom_background(array $match, array $event): string
{
    $prefix = event_share_background_prefix($match, $event);
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
        $path = event_share_background_dir() . '/' . $prefix . '.' . $extension;
        if (is_file($path)) {
            return 'uploads/event-backgrounds/' . basename($path);
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 */
function event_share_uses_white_badges(array $match, array $event): bool
{
    $stylePath = event_share_settings_dir() . '/' . event_share_background_prefix($match, $event) . '.badge-style';
    if (is_file($stylePath)) {
        return trim((string) @file_get_contents($stylePath)) === 'white';
    }

    $flagPath = event_share_settings_dir() . '/' . event_share_background_prefix($match, $event) . '.white-badges';
    if (is_file($flagPath)) {
        return true;
    }

    $preferences = social_publishing_preferences_load();
    return !empty($preferences['visuals']['events']['white_badges']);
}

/**
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 */
function event_share_set_white_badges(array $match, array $event, bool $enabled): bool
{
    $directory = event_share_settings_dir();
    $flagPath = $directory . '/' . event_share_background_prefix($match, $event) . '.white-badges';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $stylePath = $directory . '/' . event_share_background_prefix($match, $event) . '.badge-style';
    $saved = file_put_contents($stylePath, ($enabled ? 'white' : 'colour') . "\n", LOCK_EX) !== false;
    if ($saved && is_file($flagPath)) {
        @unlink($flagPath);
    }

    return $saved;
}

/**
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 * @return array{level: int, color: string}
 */
function event_share_gradient_settings(array $match, array $event): array
{
    $path = event_share_settings_dir() . '/' . event_share_background_prefix($match, $event) . '.gradient.json';
    $preferences = social_publishing_preferences_load();
    $visualDefaults = $preferences['visuals']['events'] ?? [];
    $defaultColor = strtolower(trim((string)($visualDefaults['gradient_color'] ?? '#000000')));
    $settings = [
        'level' => max(0, min(100, (int)($visualDefaults['gradient_strength'] ?? 70))),
        'color' => preg_match('/^#[0-9a-f]{6}$/', $defaultColor) === 1 ? $defaultColor : '#000000',
    ];
    if (!is_file($path)) {
        return $settings;
    }

    $decoded = json_decode((string) @file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $settings;
    }

    $level = isset($decoded['level']) ? (int) $decoded['level'] : $settings['level'];
    $color = isset($decoded['color']) ? strtolower(trim((string) $decoded['color'])) : $settings['color'];
    $settings['level'] = max(0, min(100, $level));
    if (preg_match('/^#[0-9a-f]{6}$/', $color) === 1) {
        $settings['color'] = $color;
    }

    return $settings;
}

/**
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 */
function event_share_set_gradient_settings(array $match, array $event, int $level, string $color): bool
{
    $level = max(0, min(100, $level));
    $color = strtolower(trim($color));
    if (preg_match('/^#[0-9a-f]{6}$/', $color) !== 1) {
        return false;
    }

    $directory = event_share_settings_dir();
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        return false;
    }

    $path = $directory . '/' . event_share_background_prefix($match, $event) . '.gradient.json';
    $json = json_encode(['level' => $level, 'color' => $color], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return $json !== false && file_put_contents($path, $json . PHP_EOL, LOCK_EX) !== false;
}

/**
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 */
function event_share_image_basename(array $match, array $event): string
{
    $fixture = matches_slugify(matches_fixture_label($match));
    $date = trim((string) ($match['match_date'] ?? ''));
    $dateSlug = $date !== '' ? matches_slugify($date) : date('Y-m-d');
    $eventId = trim((string) ($event['id'] ?? 'event'));
    $eventId = preg_replace('/[^a-zA-Z0-9_-]+/', '', $eventId) ?? 'event';
    if ($eventId === '') {
        $eventId = 'event';
    }

    return $fixture . '-' . $dateSlug . '-event-' . strtolower(substr($eventId, 0, 12));
}

function event_share_unique_suffix(): string
{
    return date('YmdHis') . '-' . bin2hex(random_bytes(3));
}

function event_share_delete_previous_exports(string $exportDir, string $basePrefix): void
{
    $pattern = $exportDir . '/' . $basePrefix . '-*.png';
    $matches = glob($pattern);
    if ($matches === false) {
        return;
    }

    foreach ($matches as $match) {
        if (is_file($match)) {
            @unlink($match);
        }
    }
}

/**
 * @param array<string, mixed> $match
 * @param array<string, mixed> $event
 * @return array{ok: bool, path?: string, download_url?: string, error?: string}
 */
function event_share_generate_image(array $match, array $event): array
{
    $exportDir = event_share_export_dir();
    if (!is_dir($exportDir) && !@mkdir($exportDir, 0775, true) && !is_dir($exportDir)) {
        return [
            'ok' => false,
            'error' => 'Event export directory could not be created.',
        ];
    }

    $basePrefix = event_share_image_basename($match, $event);
    event_share_delete_previous_exports($exportDir, $basePrefix);

    $basename = $basePrefix . '-' . event_share_unique_suffix();
    $outputPath = $exportDir . '/' . $basename . '.png';
    $renderUrl = APP_ORIGIN . '/admin/match_event_graphic.php?id='
        . rawurlencode((string) ($match['id'] ?? ''))
        . '&event_id=' . rawurlencode((string) ($event['id'] ?? ''))
        . '&render=1&_token=' . rawurlencode(RENDER_ACCESS_TOKEN)
        . '&v=' . rawurlencode((string) time());

    $renderWidth = 1080;
    $renderHeight = 1080;
    $packActionKey = match ((string) ($event['type'] ?? '')) {
        'kickoff' => 'kick_off',
        'half_time' => 'half_time',
        'full_time' => 'full_time',
        'goal' => 'goal',
        'substitution' => 'substitution',
        'yellow_card' => 'yellow_card',
        'red_card' => 'red_card',
        'player_of_match' => 'player_of_match',
        'postponed' => 'postponed',
        'abandoned' => 'abandoned',
        default => '',
    };
    if ($packActionKey !== '' && ctype_digit((string) ($match['id'] ?? ''))) {
        try {
            require_once __DIR__ . '/db.php';
            require_once __DIR__ . '/lib/template_pack_render.php';
            if (isset($pdo) && $pdo instanceof PDO) {
                $packContext = matchTemplatePackContext($pdo, (int) $match['id'], $packActionKey, false);
                if ((int) ($packContext['pack']['id'] ?? 0) > 0) {
                    $packContext = matchTemplatePackPreferLatest($pdo, $packContext, $packActionKey);
                    $layout = matchTemplatePackLayout($packContext);
                    $renderWidth = max(320, min(4096, (int) ($layout['canvas_width'] ?? 1080)));
                    $renderHeight = max(320, min(4096, (int) ($layout['canvas_height'] ?? 1080)));
                }
            }
        } catch (Throwable $dimensionError) {
            error_log('Template-pack event export dimensions could not be resolved: ' . $dimensionError->getMessage());
        }
    }

    $result = render_capture_image($renderUrl, $outputPath, '.event-share-card', $renderWidth, $renderHeight, '.event-share-card');

    if (!$result['ok']) {
        return [
            'ok' => false,
            'error' => 'Event image generation failed.' . ($result['output'] !== [] ? ' ' . implode(' ', $result['output']) : ''),
        ];
    }

    return [
        'ok' => true,
        'path' => $outputPath,
        'download_url' => event_share_export_public_base_url() . '/' . rawurlencode($basename) . '.png?v=' . rawurlencode((string) time()),
    ];
}
