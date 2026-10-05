<?php

declare(strict_types=1);

require_once __DIR__ . '/players_lib.php';
require_once __DIR__ . '/google_calendar.php';

const MATCHES_DATA_FILE = __DIR__ . '/data/matches.json';
const MATCHES_META_FILE = __DIR__ . '/data/match_meta.json';
const MATCHES_MASTER_TEMPLATES_FILE = __DIR__ . '/data/match_graphic_templates.json';
const MATCHES_UPLOAD_DIR = __DIR__ . '/uploads/matches';
const MATCHES_EXPORT_DIR = __DIR__ . '/export/matches';

function matches_master_pdo(): ?PDO
{
    static $pdo = null;
    static $initialized = false;

    if ($initialized) {
        return $pdo;
    }

    $initialized = true;

    // Same primary-then-fallback database resolution as db.php's hub_connect_pdo() —
    // this MySQL user only has grants on DB_FALLBACK_NAME, not DB_NAME, so without
    // the fallback this always failed silently and matches_load_all() fell back to
    // reading data/matches.json as-is, with no fixture ever merged in from the DB.
    $databases = array_values(array_unique(array_filter([
        DB_NAME,
        defined('DB_FALLBACK_NAME') ? DB_FALLBACK_NAME : null,
    ])));

    foreach ($databases as $database) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . $database . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            return $pdo;
        } catch (Throwable) {
            $pdo = null;
        }
    }

    return $pdo;
}

function matches_master_format_datetime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format(DATE_ATOM);
    } catch (Throwable) {
        return $value;
    }
}

function matches_master_format_time(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^\d{2}:\d{2}/', $value) === 1) {
        return substr($value, 0, 5);
    }

    return $value;
}

function matches_master_normalize_season_name(string $season): string
{
    return trim(preg_replace('/\s+/', ' ', $season) ?? $season);
}

function matches_master_normalize_opponent_name(string $opponent): string
{
    return trim(preg_replace('/\s+/', ' ', $opponent) ?? $opponent);
}

function matches_master_auto_abbreviation(string $clubname): string
{
    $clubname = trim(preg_replace('/\s+/', ' ', $clubname) ?? '');
    if ($clubname === '') {
        return 'TBC';
    }

    $words = preg_split('/\s+/', strtoupper($clubname)) ?: [];
    $words = array_values(array_filter(array_map(
        static function (string $word): string {
            return preg_replace('/[^A-Z0-9]/', '', $word) ?? '';
        },
        $words
    ), static function (string $word): bool {
        return $word !== '' && !in_array($word, ['FC', 'AFC', 'SC', 'THE'], true);
    }));

    if ($words === []) {
        return strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($clubname)) ?? 'TBC', 0, 3));
    }

    if (count($words) >= 3) {
        return substr(implode('', array_map(static fn(string $word): string => substr($word, 0, 1), $words)), 0, 3);
    }

    return substr($words[0], 0, 3);
}

function matches_master_lookup_season_id(PDO $pdo, string $season): int
{
    $season = matches_master_normalize_season_name($season);
    if ($season === '') {
        return 0;
    }

    $stmt = $pdo->prepare('SELECT id FROM seasons WHERE name = :name LIMIT 1');
    $stmt->execute([':name' => $season]);
    $seasonId = (int) $stmt->fetchColumn();
    if ($seasonId > 0) {
        return $seasonId;
    }

    $stmt = $pdo->prepare('INSERT INTO seasons (name) VALUES (:name)');
    $stmt->execute([':name' => $season]);

    return (int) $pdo->lastInsertId();
}

function matches_master_lookup_opponent(PDO $pdo, string $opponent): array
{
    $opponent = matches_master_normalize_opponent_name($opponent);
    if ($opponent === '') {
        return [];
    }

    $stmt = $pdo->prepare('SELECT id, clubname FROM match_opponents WHERE clubname = :clubname LIMIT 1');
    $stmt->execute([':clubname' => $opponent]);
    $existing = $stmt->fetch();
    if ($existing !== false && $existing !== null) {
        return $existing;
    }

    $stmt = $pdo->prepare('INSERT INTO match_opponents (clubname, abbreviation) VALUES (:clubname, :abbreviation)');
    $stmt->execute([
        ':clubname' => $opponent,
        ':abbreviation' => matches_master_auto_abbreviation($opponent),
    ]);

    $opponentId = (int) $pdo->lastInsertId();
    if ($opponentId <= 0) {
        return [];
    }

    return [
        'id' => $opponentId,
        'clubname' => $opponent,
    ];
}

function matches_master_fixture_row_by_id(PDO $pdo, string $fixtureId): ?array
{
    if (!ctype_digit($fixtureId)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            f.id,
            f.season_id,
            COALESCE(s.name, '') AS season_name,
            f.opponent_id,
            COALESCE(o.clubname, f.opponent) AS opponent,
            f.match_date,
            f.kickoff_time,
            f.competition,
            f.is_home,
            f.venue,
            f.status,
            f.notes,
            f.starting11_template_key,
            f.starting11_background_image,
            f.starting11_starters_json,
            f.starting11_substitutes_json,
            f.starting11_captain,
            f.created_at,
            f.updated_at
        FROM match_fixtures f
        LEFT JOIN seasons s ON s.id = f.season_id
        LEFT JOIN match_opponents o ON o.id = f.opponent_id
        WHERE f.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => (int) $fixtureId]);
    $row = $stmt->fetch();

    return $row !== false && $row !== null ? $row : null;
}

function matches_master_fixture_row_by_composite(PDO $pdo, array $match): ?array
{
    $season = matches_master_normalize_season_name((string) ($match['season'] ?? ''));
    $opponent = matches_master_normalize_opponent_name((string) ($match['opponent'] ?? ''));
    $matchDate = trim((string) ($match['match_date'] ?? ''));
    $kickoffTime = matches_master_format_time((string) ($match['kickoff_time'] ?? ''));
    $isHome = matches_normalize_venue((string) ($match['venue'] ?? 'H')) === 'H' ? 1 : 0;

    if ($season === '' || $opponent === '' || $matchDate === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM seasons WHERE name = :name LIMIT 1');
    $stmt->execute([':name' => $season]);
    $seasonId = (int) $stmt->fetchColumn();
    if ($seasonId <= 0) {
        return null;
    }

    $sql = "
        SELECT
            f.id,
            f.season_id,
            COALESCE(s.name, '') AS season_name,
            f.opponent_id,
            COALESCE(o.clubname, f.opponent) AS opponent,
            f.match_date,
            f.kickoff_time,
            f.competition,
            f.is_home,
            f.venue,
            f.status,
            f.notes,
            f.starting11_template_key,
            f.starting11_background_image,
            f.starting11_starters_json,
            f.starting11_substitutes_json,
            f.starting11_captain,
            f.created_at,
            f.updated_at
        FROM match_fixtures f
        LEFT JOIN seasons s ON s.id = f.season_id
        LEFT JOIN match_opponents o ON o.id = f.opponent_id
        WHERE f.season_id = :season_id
          AND f.match_date = :match_date
          AND f.is_home = :is_home
          AND LEFT(COALESCE(f.kickoff_time, ''), 5) = :kickoff_time
          AND (COALESCE(o.clubname, f.opponent) = :opponent OR f.opponent = :opponent)
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':season_id' => $seasonId,
        ':match_date' => $matchDate,
        ':kickoff_time' => $kickoffTime,
        ':is_home' => $isHome,
        ':opponent' => $opponent,
    ]);
    $row = $stmt->fetch();

    return $row !== false && $row !== null ? $row : null;
}

/**
 * @return list<array<string, mixed>>
 */
function matches_master_fixtures_load(): array
{
    $pdo = matches_master_pdo();
    if ($pdo === null) {
        return [];
    }

    $rows = $pdo->query("
        SELECT
            f.id,
            f.season_id,
            COALESCE(s.name, '') AS season_name,
            f.opponent_id,
            COALESCE(o.clubname, f.opponent) AS opponent,
            f.match_date,
            f.kickoff_time,
            f.competition,
            f.is_home,
            f.venue,
            f.status,
            f.notes,
            f.starting11_template_key,
            f.starting11_background_image,
            f.starting11_starters_json,
            f.starting11_substitutes_json,
            f.starting11_captain,
            f.created_at,
            f.updated_at
        FROM match_fixtures f
        LEFT JOIN seasons s ON s.id = f.season_id
        LEFT JOIN match_opponents o ON o.id = f.opponent_id
        ORDER BY f.match_date DESC, f.kickoff_time DESC, f.id DESC
    ")->fetchAll();

    $fixtures = [];
    foreach ($rows as $row) {
        $fixtures[] = matches_normalize([
            'id' => (string) ($row['id'] ?? ''),
            'opponent' => (string) ($row['opponent'] ?? ''),
            'competition' => (string) ($row['competition'] ?? ''),
            'season' => (string) ($row['season_name'] ?? ''),
            'venue' => ((int) ($row['is_home'] ?? 1) === 1) ? 'H' : 'A',
            'venue_name' => (string) ($row['venue'] ?? ''),
            'match_date' => (string) ($row['match_date'] ?? ''),
            'kickoff_time' => matches_master_format_time((string) ($row['kickoff_time'] ?? '')),
            'status' => (string) ($row['status'] ?? 'scheduled'),
            'notes' => (string) ($row['notes'] ?? ''),
            'template_key' => (string) ($row['starting11_template_key'] ?? 'starting_xi_classic'),
            'background_image' => (string) ($row['starting11_background_image'] ?? ''),
            'starters' => json_decode((string) ($row['starting11_starters_json'] ?? '[]'), true) ?: [],
            'substitutes' => json_decode((string) ($row['starting11_substitutes_json'] ?? '[]'), true) ?: [],
            'captain' => (string) ($row['starting11_captain'] ?? ''),
            'season_id' => (int) ($row['season_id'] ?? 0),
            'opponent_id' => (int) ($row['opponent_id'] ?? 0),
            'created_at' => matches_master_format_datetime((string) ($row['created_at'] ?? '')),
            'updated_at' => matches_master_format_datetime((string) ($row['updated_at'] ?? $row['created_at'] ?? '')),
        ]);
    }

    return $fixtures;
}

function matches_fixture_composite_key(array $fixture): string
{
    return strtolower(matches_master_normalize_season_name((string) ($fixture['season'] ?? ''))) . '|'
        . trim((string) ($fixture['match_date'] ?? '')) . '|'
        . strtolower(matches_master_normalize_opponent_name((string) ($fixture['opponent'] ?? ''))) . '|'
        . matches_normalize_venue((string) ($fixture['venue'] ?? 'H')) . '|'
        . matches_master_format_time((string) ($fixture['kickoff_time'] ?? ''));
}

/**
 * @param list<array<string, mixed>> $remoteFixtures
 * @param list<array<string, mixed>> $localFixtures
 * @return list<array<string, mixed>>
 */
function matches_merge_master_fixtures(array $remoteFixtures, array $localFixtures): array
{
    $localById = [];
    $localByComposite = [];

    foreach ($localFixtures as $fixture) {
        $id = trim((string) ($fixture['id'] ?? ''));
        if ($id !== '' && !isset($localById[$id])) {
            $localById[$id] = $fixture;
        }

        $composite = matches_fixture_composite_key($fixture);
        if ($composite !== '' && !isset($localByComposite[$composite])) {
            $localByComposite[$composite] = $fixture;
        }
    }

    $merged = [];
    $matchedLocalIds = [];
    $matchedLocalComposite = [];

    foreach ($remoteFixtures as $remoteFixture) {
        $remoteId = trim((string) ($remoteFixture['id'] ?? ''));
        $composite = matches_fixture_composite_key($remoteFixture);
        $localFixture = null;

        if ($remoteId !== '' && isset($localById[$remoteId])) {
            $localFixture = $localById[$remoteId];
            $matchedLocalIds[$remoteId] = true;
        } elseif ($composite !== '' && isset($localByComposite[$composite])) {
            $localFixture = $localByComposite[$composite];
            $matchedLocalComposite[$composite] = true;
            $localId = trim((string) ($localFixture['id'] ?? ''));
            if ($localId !== '') {
                $matchedLocalIds[$localId] = true;
            }
        }

        $merged[] = matches_merge_fixture_overlay($remoteFixture, $localFixture);
    }

    foreach ($localFixtures as $localFixture) {
        $localId = trim((string) ($localFixture['id'] ?? ''));
        $composite = matches_fixture_composite_key($localFixture);
        if (($localId !== '' && isset($matchedLocalIds[$localId])) || ($composite !== '' && isset($matchedLocalComposite[$composite]))) {
            continue;
        }

        $merged[] = matches_merge_fixture_overlay($localFixture, null);
    }

    usort($merged, static function (array $a, array $b): int {
        $aDate = (string) ($a['match_date'] ?? '');
        $bDate = (string) ($b['match_date'] ?? '');

        if ($aDate !== $bDate) {
            return strcmp($bDate, $aDate);
        }

        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return array_values($merged);
}

function matches_merge_fixture_overlay(array $base, ?array $overlay): array
{
    $fixture = matches_normalize($base);
    if (!is_array($overlay)) {
        return $fixture;
    }

    $sharedFields = [
        'season',
        'venue',
        'venue_name',
        'match_date',
        'kickoff_time',
        'status',
        'notes',
        'opponent',
        'competition',
        'background_image',
        'created_at',
        'updated_at',
    ];
    $localFields = [
        'template_key',
        'starters',
        'substitutes',
        'captain',
        'events',
        'graphics_templates',
        'google_calendar_event_id',
        'google_calendar_synced_at',
    ];

    foreach ($sharedFields as $field) {
        if (!array_key_exists($field, $overlay)) {
            continue;
        }

        $value = $overlay[$field];
        if (is_array($value)) {
            if ($value !== [] && ($fixture[$field] ?? []) === []) {
                $fixture[$field] = $value;
            }
            continue;
        }

        $stringValue = trim((string) $value);
        if ($stringValue !== '' && trim((string) ($fixture[$field] ?? '')) === '') {
            $fixture[$field] = $stringValue;
        }
    }

    foreach ($localFields as $field) {
        if (!array_key_exists($field, $overlay)) {
            continue;
        }

        $value = $overlay[$field];
        if ($field === 'graphics_templates' && is_array($value)) {
            $fixture[$field] = matches_normalize_graphic_templates($value);
            continue;
        }

        if (is_array($value)) {
            if ($value !== []) {
                $fixture[$field] = $value;
            }
            continue;
        }

        $stringValue = trim((string) $value);
        if ($stringValue !== '') {
            $fixture[$field] = $stringValue;
        }
    }

    return matches_normalize($fixture);
}

/**
 * @param array<string, mixed> $match
 * @return array{ok: bool, message: string}
 */
function matches_sync_master_fixture_details(array $match): array
{
    $pdo = matches_master_pdo();
    if ($pdo === null) {
        return [
            'ok' => false,
            'message' => 'The master match database is unavailable.',
        ];
    }

    $matchId = trim((string) ($match['id'] ?? ''));
    if ($matchId === '') {
        return [
            'ok' => false,
            'message' => 'Match id is required for master sync.',
        ];
    }

    $numericMatchId = ctype_digit($matchId) ? (int) $matchId : 0;

    $seasonId = matches_master_lookup_season_id($pdo, (string) ($match['season'] ?? ''));
    if ($seasonId <= 0) {
        return [
            'ok' => false,
            'message' => 'The season could not be resolved in the master match database.',
        ];
    }

    $opponent = matches_master_lookup_opponent($pdo, (string) ($match['opponent'] ?? ''));
    if ($opponent === []) {
        return [
            'ok' => false,
            'message' => 'The opponent could not be resolved in the master match database.',
        ];
    }

    $existing = matches_master_fixture_row_by_id($pdo, $matchId);
    $templateKey = (string) ($match['template_key'] ?? 'starting_xi_classic');
    $backgroundImage = trim((string) ($match['background_image'] ?? ''));
    $starters = matches_prepare_lineup($match['starters'] ?? []);
    $substitutes = matches_prepare_lineup($match['substitutes'] ?? []);
    $captain = trim((string) ($match['captain'] ?? ''));
    $status = trim((string) ($match['status'] ?? ($existing['status'] ?? 'scheduled')));
    if ($status === '') {
        $status = 'scheduled';
    }
    $notes = trim((string) ($match['notes'] ?? ($existing['notes'] ?? '')));
    $existingMasterId = $existing !== null ? (int) ($existing['id'] ?? 0) : 0;

    $params = [
        ':season_id' => $seasonId,
        ':opponent_id' => (int) $opponent['id'],
        ':match_date' => trim((string) ($match['match_date'] ?? '')),
        ':kickoff_time' => trim((string) ($match['kickoff_time'] ?? '')),
        ':opponent' => (string) $opponent['clubname'],
        ':competition' => trim((string) ($match['competition'] ?? '')),
        ':is_home' => matches_normalize_venue((string) ($match['venue'] ?? 'H')) === 'H' ? 1 : 0,
        ':venue' => trim((string) ($match['venue_name'] ?? '')),
        ':status' => $status,
        ':notes' => $notes !== '' ? $notes : null,
        ':starting11_template_key' => $templateKey !== '' ? $templateKey : 'starting_xi_classic',
        ':starting11_background_image' => $backgroundImage !== '' ? $backgroundImage : null,
        ':starting11_starters_json' => $starters !== [] ? json_encode(array_values($starters), JSON_UNESCAPED_SLASHES) : null,
        ':starting11_substitutes_json' => $substitutes !== [] ? json_encode(array_values($substitutes), JSON_UNESCAPED_SLASHES) : null,
        ':starting11_captain' => $captain !== '' ? $captain : null,
    ];

    $fixtureExists = $existingMasterId > 0;

    if ($fixtureExists || $numericMatchId > 0) {
        $sql = "
            INSERT INTO match_fixtures (
                id,
                season_id,
                opponent_id,
                match_date,
                kickoff_time,
                opponent,
                competition,
                is_home,
                venue,
                status,
                notes,
                starting11_template_key,
                starting11_background_image,
                starting11_starters_json,
                starting11_substitutes_json,
                starting11_captain
            ) VALUES (
                :id,
                :season_id,
                :opponent_id,
                :match_date,
                :kickoff_time,
                :opponent,
                :competition,
                :is_home,
                :venue,
                :status,
                :notes,
                :starting11_template_key,
                :starting11_background_image,
                :starting11_starters_json,
                :starting11_substitutes_json,
                :starting11_captain
            ) ON DUPLICATE KEY UPDATE
                season_id = VALUES(season_id),
                opponent_id = VALUES(opponent_id),
                match_date = VALUES(match_date),
                kickoff_time = VALUES(kickoff_time),
                opponent = VALUES(opponent),
                competition = VALUES(competition),
                is_home = VALUES(is_home),
                venue = VALUES(venue),
                status = VALUES(status),
                notes = VALUES(notes),
                starting11_template_key = VALUES(starting11_template_key),
                starting11_background_image = VALUES(starting11_background_image),
                starting11_starters_json = VALUES(starting11_starters_json),
                starting11_substitutes_json = VALUES(starting11_substitutes_json),
                starting11_captain = VALUES(starting11_captain)
        ";
        $params[':id'] = $fixtureExists ? $existingMasterId : $numericMatchId;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $masterId = $fixtureExists ? $existingMasterId : $numericMatchId;
    } else {
        $sql = "
            INSERT INTO match_fixtures (
                season_id,
                opponent_id,
                match_date,
                kickoff_time,
                opponent,
                competition,
                is_home,
                venue,
                status,
                notes,
                starting11_template_key,
                starting11_background_image,
                starting11_starters_json,
                starting11_substitutes_json,
                starting11_captain
            ) VALUES (
                :season_id,
                :opponent_id,
                :match_date,
                :kickoff_time,
                :opponent,
                :competition,
                :is_home,
                :venue,
                :status,
                :notes,
                :starting11_template_key,
                :starting11_background_image,
                :starting11_starters_json,
                :starting11_substitutes_json,
                :starting11_captain
            )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $masterId = (int) $pdo->lastInsertId();
    }

    return [
        'ok' => true,
        'message' => 'Match synced to the master database.',
        'master_id' => $masterId > 0 ? (string) $masterId : '',
    ];
}

/**
 * @param array<string, mixed> $match
 * @return array{ok: bool, message: string}
 */
function matches_sync_master_fixture_delete(array $match): array
{
    $pdo = matches_master_pdo();
    if ($pdo === null) {
        return [
            'ok' => false,
            'message' => 'The master match database is unavailable.',
        ];
    }

    $matchId = trim((string) ($match['id'] ?? ''));
    if ($matchId === '') {
        return [
            'ok' => false,
            'message' => 'Match id is required for master sync.',
        ];
    }

    $masterRow = matches_master_fixture_row_by_id($pdo, $matchId);
    if ($masterRow === null) {
        $masterRow = matches_master_fixture_row_by_composite($pdo, $match);
    }

    if ($masterRow === null) {
        return [
            'ok' => true,
            'message' => 'No master fixture row matched this record.',
        ];
    }

    $stmt = $pdo->prepare('DELETE FROM match_fixtures WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => (int) ($masterRow['id'] ?? 0)]);

    return [
        'ok' => true,
        'message' => 'Match removed from the master database.',
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function matches_load_all(): array
{
    $localMatches = [];
    if (is_file(MATCHES_DATA_FILE)) {
        $json = file_get_contents(MATCHES_DATA_FILE);
        if ($json !== false && trim($json) !== '') {
            $data = json_decode($json, true);
            if (is_array($data)) {
                foreach ($data as $item) {
                    if (is_array($item)) {
                        $localMatches[] = matches_normalize($item);
                    }
                }
            }
        }
    }

    $remoteMatches = matches_master_fixtures_load();
    if ($remoteMatches === []) {
        usort($localMatches, static function (array $a, array $b): int {
            $aDate = (string) ($a['match_date'] ?? '');
            $bDate = (string) ($b['match_date'] ?? '');

            if ($aDate !== $bDate) {
                return strcmp($bDate, $aDate);
            }

            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        return $localMatches;
    }

    return matches_merge_master_fixtures($remoteMatches, $localMatches);
}

/**
 * @param list<array<string, mixed>> $matches
 */
function matches_save_all(array $matches): bool
{
    if (!is_dir(dirname(MATCHES_DATA_FILE))) {
        @mkdir(dirname(MATCHES_DATA_FILE), 0775, true);
    }

    $json = json_encode(array_values($matches), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    // The historical importer updates one entry under this same lock. Atomic
    // replacement also prevents public readers seeing a partially written file.
    $lock = @fopen(MATCHES_DATA_FILE . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) fclose($lock);
        return false;
    }
    $temporary = null;
    try {
        $temporary = tempnam(dirname(MATCHES_DATA_FILE), '.matches-');
        if ($temporary === false) return false;
        $content = $json . PHP_EOL;
        if (file_put_contents($temporary, $content) !== strlen($content)) return false;
        chmod($temporary, is_file(MATCHES_DATA_FILE) ? (fileperms(MATCHES_DATA_FILE) & 0777) : 0664);
        return rename($temporary, MATCHES_DATA_FILE);
    } finally {
        if ($temporary && is_file($temporary)) @unlink($temporary);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * @param array<string, mixed> $match
 * @return array<string, mixed>
 */
function matches_normalize(array $match): array
{
    $template = matches_template_config(isset($match['template_key']) ? (string) $match['template_key'] : null);

    return [
        'id' => (string) ($match['id'] ?? ''),
        'opponent' => trim((string) ($match['opponent'] ?? '')),
        'competition' => trim((string) ($match['competition'] ?? '')),
        'season' => trim((string) ($match['season'] ?? '')),
        'venue' => matches_normalize_venue(isset($match['venue']) ? (string) $match['venue'] : null),
        'venue_name' => trim((string) ($match['venue_name'] ?? '')),
        'match_date' => trim((string) ($match['match_date'] ?? '')),
        'kickoff_time' => trim((string) ($match['kickoff_time'] ?? '')),
        'template_key' => $template['key'],
        'title_primary' => trim((string) ($match['title_primary'] ?? $template['title_primary'])),
        'title_accent' => trim((string) ($match['title_accent'] ?? $template['title_accent'])),
        'opponent_prefix' => trim((string) ($match['opponent_prefix'] ?? $template['opponent_prefix'])),
        'background_image' => trim((string) ($match['background_image'] ?? '')),
        'graphics_templates' => array_key_exists('graphics_templates', $match)
            ? matches_normalize_graphic_templates($match['graphics_templates'] ?? [])
            : matches_match_graphic_templates_defaults(),
        'starters' => matches_prepare_lineup($match['starters'] ?? []),
        'substitutes' => matches_prepare_lineup($match['substitutes'] ?? []),
        'captain' => trim((string) ($match['captain'] ?? '')),
        'events' => matches_normalize_events($match['events'] ?? []),
        'status' => trim((string) ($match['status'] ?? 'scheduled')) ?: 'scheduled',
        'notes' => trim((string) ($match['notes'] ?? '')),
        'season_id' => (int) ($match['season_id'] ?? 0),
        'opponent_id' => (int) ($match['opponent_id'] ?? 0),
        'google_calendar_event_id' => trim((string) ($match['google_calendar_event_id'] ?? '')),
        'google_calendar_synced_at' => trim((string) ($match['google_calendar_synced_at'] ?? '')),
        'created_at' => trim((string) ($match['created_at'] ?? '')),
        'updated_at' => trim((string) ($match['updated_at'] ?? '')),
    ];
}

function matches_generate_id(): string
{
    return bin2hex(random_bytes(8));
}

function matches_normalize_venue(?string $venue): string
{
    $venue = strtoupper(trim((string) $venue));
    return in_array($venue, ['H', 'A'], true) ? $venue : 'H';
}

function matches_format_venue(string $venue): string
{
    return matches_normalize_venue($venue) === 'A' ? 'Away' : 'Home';
}

/**
 * @return array<string, array<string, string>>
 */
function matches_template_options(): array
{
    return [
        'starting_xi_classic' => [
            'label' => 'Starting XI Classic',
            'title_primary' => 'STARTING',
            'title_accent' => 'XI',
            'opponent_prefix' => 'VS',
        ],
        'starting_xi_master' => [
            'label' => 'Starting XI master template',
            'title_primary' => 'STARTING',
            'title_accent' => 'XI',
            'opponent_prefix' => 'VS',
        ],
    ];
}

/**
 * @return array{key: string, label: string, title_primary: string, title_accent: string, opponent_prefix: string}
 */
function matches_template_config(?string $key): array
{
    $options = matches_template_options();
    $key = trim((string) $key);
    if ($key === '' || !isset($options[$key])) {
        $key = 'starting_xi_classic';
    }

    return [
        'key' => $key,
        'label' => $options[$key]['label'],
        'title_primary' => $options[$key]['title_primary'],
        'title_accent' => $options[$key]['title_accent'],
        'opponent_prefix' => $options[$key]['opponent_prefix'],
    ];
}

/**
 * @return array<string, array{label: string}>
 */
function matches_graphic_template_definitions(): array
{
    return [
        'starting_xi' => ['label' => 'Starting XI'],
        'kick_off' => ['label' => 'Kick Off'],
        'half_time' => ['label' => 'Half Time'],
        'full_time' => ['label' => 'Full Time'],
    ];
}

/**
 * @return array<string, string>
 */
function matches_graphic_template_channels(): array
{
    return [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'x' => 'X',
    ];
}

/**
 * @return array{key: string, label: string}
 */
function matches_graphic_template_config(?string $key): array
{
    $definitions = matches_graphic_template_definitions();
    $key = trim((string) $key);
    if ($key === '' || !isset($definitions[$key])) {
        return ['key' => '', 'label' => 'Graphic template'];
    }

    return [
        'key' => $key,
        'label' => $definitions[$key]['label'],
    ];
}

/**
 * @return array{image: string, captions: array<string, string>}
 */
function matches_graphic_template_empty(): array
{
    $captions = [];
    foreach (matches_graphic_template_channels() as $channelKey => $label) {
        $captions[$channelKey] = '';
    }

    return [
        'image' => '',
        'captions' => $captions,
    ];
}

/**
 * @param mixed $submitted
 * @return array<string, array{image: string, captions: array<string, string>}>
 */
function matches_normalize_graphic_templates($submitted): array
{
    $definitions = matches_graphic_template_definitions();
    $channels = matches_graphic_template_channels();
    $templates = [];
    $source = is_array($submitted) ? $submitted : [];

    foreach ($definitions as $templateKey => $definition) {
        $item = isset($source[$templateKey]) && is_array($source[$templateKey]) ? $source[$templateKey] : [];
        $normalized = matches_graphic_template_empty();
        $normalized['image'] = trim((string) ($item['image'] ?? ''));

        $submittedCaptions = isset($item['captions']) && is_array($item['captions']) ? $item['captions'] : [];
        foreach ($channels as $channelKey => $label) {
            $normalized['captions'][$channelKey] = trim((string) ($submittedCaptions[$channelKey] ?? ''));
        }

        $templates[$templateKey] = $normalized;
    }

    return $templates;
}

/**
 * @return array<string, array{image: string, captions: array<string, string>}>
 */
function matches_master_graphic_templates_defaults(): array
{
    return matches_normalize_graphic_templates([]);
}

/**
 * @return array<string, array{image: string, captions: array<string, string>}>
 */
function matches_master_graphic_templates_load(): array
{
    $defaults = matches_master_graphic_templates_defaults();
    if (!is_file(MATCHES_MASTER_TEMPLATES_FILE)) {
        return $defaults;
    }

    $json = file_get_contents(MATCHES_MASTER_TEMPLATES_FILE);
    if ($json === false || trim($json) === '') {
        return $defaults;
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return matches_normalize_graphic_templates($decoded);
}

/**
 * @param array<string, array{image: string, captions: array<string, string>}> $templates
 */
function matches_master_graphic_templates_save(array $templates): bool
{
    if (!is_dir(dirname(MATCHES_MASTER_TEMPLATES_FILE))) {
        @mkdir(dirname(MATCHES_MASTER_TEMPLATES_FILE), 0775, true);
    }

    $payload = matches_normalize_graphic_templates($templates);
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents(MATCHES_MASTER_TEMPLATES_FILE, $json . PHP_EOL, LOCK_EX) !== false;
}

/**
 * @return array<string, array{image: string, captions: array<string, string>}>
 */
function matches_graphic_templates_seed_from_master(): array
{
    return matches_master_graphic_templates_load();
}

/**
 * @return array<string, array{image: string, captions: array<string, string>}>
 */
function matches_match_graphic_templates_defaults(): array
{
    return matches_normalize_graphic_templates([]);
}

/**
 * @return array<string, array{image: string, captions: array<string, string>}>
 */
function matches_match_graphic_templates(?array $match): array
{
    $templates = matches_graphic_templates_seed_from_master();
    if (!is_array($match)) {
        return $templates;
    }

    $stored = matches_normalize_graphic_templates($match['graphics_templates'] ?? []);
    foreach ($templates as $templateKey => $template) {
        $storedTemplate = $stored[$templateKey] ?? matches_graphic_template_empty();
        if (trim((string) ($storedTemplate['image'] ?? '')) !== '') {
            $templates[$templateKey]['image'] = trim((string) $storedTemplate['image']);
        }

        foreach (matches_graphic_template_channels() as $channelKey => $label) {
            $storedCaption = trim((string) ($storedTemplate['captions'][$channelKey] ?? ''));
            if ($storedCaption !== '') {
                $templates[$templateKey]['captions'][$channelKey] = $storedCaption;
            }
        }
    }

    return $templates;
}

/**
 * @return array{image: string, captions: array<string, string>}
 */
function matches_match_graphic_template(?array $match, string $templateKey): array
{
    $templateKey = trim($templateKey);
    $templates = matches_match_graphic_templates($match);

    return $templates[$templateKey] ?? matches_graphic_template_empty();
}

function matches_match_lineup_background(?array $match): string
{
    $startingXiTemplate = matches_match_graphic_template($match, 'starting_xi');
    $startingXiImage = trim((string) ($startingXiTemplate['image'] ?? ''));
    if ($startingXiImage !== '') {
        return $startingXiImage;
    }

    // Keep older fixtures working until they receive a dedicated Starting XI template.
    $kickOffTemplate = matches_match_graphic_template($match, 'kick_off');
    $kickOffImage = trim((string) ($kickOffTemplate['image'] ?? ''));
    if ($kickOffImage !== '') {
        return $kickOffImage;
    }

    return trim((string) ($match['background_image'] ?? ''));
}

/**
 * @param mixed $submitted
 * @return list<string>
 */
function matches_prepare_lineup($submitted): array
{
    if (!is_array($submitted)) {
        return [];
    }

    $values = [];
    foreach ($submitted as $value) {
        $name = trim((string) $value);
        if ($name === '' || in_array($name, $values, true)) {
            continue;
        }
        $values[] = $name;
    }

    return $values;
}

/**
 * @param mixed $submitted
 * @return list<array<string, mixed>>
 */
function matches_normalize_events($submitted): array
{
    if (!is_array($submitted)) {
        return [];
    }

    $events = [];
    foreach ($submitted as $index => $event) {
        if (!is_array($event)) {
            continue;
        }

        $type = trim((string) ($event['type'] ?? ''));
        $allowedTypes = [
            'kickoff', 'half_time', 'second_half', 'full_time', 'goal', 'shot', 'chance',
            'corner', 'free_kick', 'penalty', 'off_side', 'yellow_card', 'red_card',
            'substitution', 'mistake', 'good_play', 'highlight', 'player_of_match', 'note'
        ];
        if (!in_array($type, $allowedTypes, true)) {
            continue;
        }

        $team = trim((string) ($event['team'] ?? ''));
        $allowedTeams = ['svfc', 'opponent', 'match'];
        if (!in_array($team, $allowedTeams, true)) {
            $team = in_array($type, ['kickoff', 'half_time', 'second_half', 'full_time'], true) ? 'match' : 'svfc';
        }

        $minuteRaw = trim((string) ($event['minute'] ?? ''));
        $minute = '';
        if ($minuteRaw !== '' && preg_match('/^\d{1,3}(?:\+\d{1,2})?$/', $minuteRaw)) {
            $minute = $minuteRaw;
        }

        $sequence = isset($event['sequence']) ? (int) $event['sequence'] : ($index + 1);
        if ($sequence <= 0) {
            $sequence = $index + 1;
        }

        $player = trim((string) ($event['player'] ?? ''));
        $secondaryPlayer = trim((string) ($event['secondary_player'] ?? ''));
        $substitutions = [];
        if ($type === 'substitution') {
            $submittedSubstitutions = isset($event['substitutions']) && is_array($event['substitutions'])
                ? $event['substitutions']
                : [];
            foreach ($submittedSubstitutions as $substitution) {
                if (!is_array($substitution)) {
                    continue;
                }
                $off = trim((string) ($substitution['off'] ?? ''));
                $on = trim((string) ($substitution['on'] ?? ''));
                if ($off === '' && $on === '') {
                    continue;
                }
                $substitutions[] = ['off' => $off, 'on' => $on];
            }
            if ($substitutions === [] && ($player !== '' || $secondaryPlayer !== '')) {
                $substitutions[] = ['off' => $player, 'on' => $secondaryPlayer];
            }
            if ($substitutions !== []) {
                $player = (string) ($substitutions[0]['off'] ?? '');
                $secondaryPlayer = (string) ($substitutions[0]['on'] ?? '');
            }
        }

        $events[] = [
            'id' => trim((string) ($event['id'] ?? matches_generate_id())),
            'type' => $type,
            'minute' => $minute,
            'team' => $team,
            'player' => $player,
            'own_goal' => !empty($event['own_goal']),
            'secondary_player' => $secondaryPlayer,
            'substitutions' => $substitutions,
            'card_type' => trim((string) ($event['card_type'] ?? '')),
            'outcome' => trim((string) ($event['outcome'] ?? '')),
            'origin' => trim((string) ($event['origin'] ?? '')),
            'target' => trim((string) ($event['target'] ?? '')),
            'note' => trim((string) ($event['note'] ?? '')),
            'created_at' => trim((string) ($event['created_at'] ?? '')),
            'updated_at' => trim((string) ($event['updated_at'] ?? '')),
            'sequence' => $sequence,
        ];
    }

    usort($events, static function (array $a, array $b): int {
        $aMinute = matches_event_minute_sort_value((string) ($a['minute'] ?? ''));
        $bMinute = matches_event_minute_sort_value((string) ($b['minute'] ?? ''));

        if ($aMinute !== $bMinute) {
            return $aMinute <=> $bMinute;
        }

        return ((int) ($a['sequence'] ?? 0)) <=> ((int) ($b['sequence'] ?? 0));
    });

    return array_values($events);
}

function matches_event_minute_sort_value(string $minute): int
{
    $minute = trim($minute);
    if ($minute === '') {
        return 10000;
    }

    if (preg_match('/^(\d{1,3})(?:\+(\d{1,2}))?$/', $minute, $matches) !== 1) {
        return 10000;
    }

    $base = (int) ($matches[1] ?? 0);
    $extra = (int) ($matches[2] ?? 0);

    return ($base * 100) + $extra;
}

function matches_next_event_sequence(array $events): int
{
    $max = 0;
    foreach ($events as $event) {
        $max = max($max, (int) ($event['sequence'] ?? 0));
    }

    return $max + 1;
}

/**
 * @return array{state: string, goals: int, cards: int, substitutions: int}
 */
function matches_event_summary(array $match): array
{
    $events = isset($match['events']) && is_array($match['events']) ? $match['events'] : [];
    $state = 'Not started';
    $goals = 0;
    $cards = 0;
    $substitutions = 0;

    foreach ($events as $event) {
        $type = (string) ($event['type'] ?? '');
        if ($type === 'kickoff') {
            $state = 'First half';
        } elseif ($type === 'half_time') {
            $state = 'Half time';
        } elseif ($type === 'second_half') {
            $state = 'Second half';
        } elseif ($type === 'full_time') {
            $state = 'Full time';
        } elseif ($type === 'goal') {
            $goals++;
        } elseif (in_array($type, ['card', 'yellow_card', 'red_card'], true)) {
            $cards++;
        } elseif ($type === 'substitution') {
            $substitutions++;
        }
    }

    return [
        'state' => $state,
        'goals' => $goals,
        'cards' => $cards,
        'substitutions' => $substitutions,
    ];
}

/**
 * @return array<string, string>
 */
function matches_event_type_labels(): array
{
    return [
        'kickoff' => 'Kick Off',
        'half_time' => 'Half Time',
        'second_half' => 'Second Half',
        'full_time' => 'Full Time',
        'goal' => 'Goal',
        'shot' => 'Shot',
        'chance' => 'Chance',
        'corner' => 'Corner',
        'free_kick' => 'Free Kick',
        'penalty' => 'Penalty',
        'off_side' => 'Offside',
        'yellow_card' => 'Yellow Card',
        'red_card' => 'Red Card',
        'card' => 'Card',
        'substitution' => 'Substitution',
        'mistake' => 'Mistake',
        'good_play' => 'Good Play',
        'highlight' => 'Highlight',
        'player_of_match' => 'Player of the Match',
        'note' => 'Note',
    ];
}

/**
 * @return array<string, string>
 */
function matches_event_form_type_labels(): array
{
    return [
        'goal' => 'Goal',
        'shot' => 'Shot',
        'chance' => 'Chance',
        'corner' => 'Corner',
        'free_kick' => 'Free Kick',
        'penalty' => 'Penalty',
        'off_side' => 'Offside',
        'yellow_card' => 'Yellow Card',
        'red_card' => 'Red Card',
        'card' => 'Card',
        'substitution' => 'Substitution',
        'mistake' => 'Mistake',
        'good_play' => 'Good Play',
        'highlight' => 'Highlight',
        'player_of_match' => 'Player of the Match',
    ];
}

/**
 * @return array<string, string>
 */
function matches_event_team_labels(): array
{
    return [
        'svfc' => 'Saltcoats Victoria',
        'opponent' => 'Opponent',
        'match' => 'Match',
    ];
}

/**
 * @return array{ok: bool, message: string, errors?: list<string>, event?: array<string, mixed>}
 */
function matches_build_event_payload(array $post): array
{
    $type = isset($post['event_type']) && is_string($post['event_type']) ? trim($post['event_type']) : '';
    $team = isset($post['event_team']) && is_string($post['event_team']) ? trim($post['event_team']) : '';
    $minute = isset($post['event_minute']) && is_string($post['event_minute']) ? trim($post['event_minute']) : '';
    $player = isset($post['event_player']) && is_string($post['event_player']) ? trim($post['event_player']) : '';
    $ownGoal = !empty($post['event_own_goal']);
    $secondaryPlayer = isset($post['event_secondary_player']) && is_string($post['event_secondary_player']) ? trim($post['event_secondary_player']) : '';
    $cardType = isset($post['event_card_type']) && is_string($post['event_card_type']) ? trim($post['event_card_type']) : '';
    $note = isset($post['event_note']) && is_string($post['event_note']) ? trim($post['event_note']) : '';
    $outcome = isset($post['event_outcome']) && is_string($post['event_outcome']) ? trim($post['event_outcome']) : '';
    $origin = isset($post['event_origin']) && is_string($post['event_origin']) ? trim($post['event_origin']) : '';
    $target = isset($post['event_target']) && is_string($post['event_target']) ? trim($post['event_target']) : '';
    $substitutionOff = isset($post['substitution_off']) && is_array($post['substitution_off']) ? $post['substitution_off'] : [];
    $substitutionOn = isset($post['substitution_on']) && is_array($post['substitution_on']) ? $post['substitution_on'] : [];
    $substitutions = [];

    $errors = [];
    if (!isset(matches_event_form_type_labels()[$type]) && !in_array($type, ['kickoff', 'half_time', 'second_half', 'full_time', 'note'], true)) {
        $errors[] = 'Event type is not valid.';
    }

    if (in_array($type, ['kickoff', 'half_time', 'second_half', 'full_time'], true)) {
        $team = 'match';
    } elseif (!isset(matches_event_team_labels()[$team])) {
        $errors[] = 'Team is required.';
    }

    $timedTypes = ['goal', 'shot', 'chance', 'corner', 'free_kick', 'penalty', 'off_side', 'yellow_card', 'red_card', 'card', 'substitution', 'mistake', 'good_play', 'highlight'];
    if (in_array($type, $timedTypes, true) && $minute === '') {
        $errors[] = 'Minute is required for this event.';
    } elseif ($minute !== '' && preg_match('/^\d{1,3}(?:\+\d{1,2})?$/', $minute) !== 1) {
        $errors[] = 'Minute must look like 23 or 45+2.';
    }

    if ($type === 'goal' && $team === 'svfc' && $player === '' && !$ownGoal) {
        $errors[] = 'Select the Saltcoats Victoria goalscorer.';
    }
    if ($type === 'player_of_match' && $player === '') {
        $errors[] = 'Select the Player of the Match.';
    }
    if ($type !== 'goal') {
        $ownGoal = false;
    }

    if ($type === 'card') {
        if (!in_array($cardType, ['yellow', 'red'], true)) {
            $errors[] = 'Card type is required.';
        }
        if ($team === 'svfc' && $player === '') {
            $errors[] = 'Select the booked or sent-off Saltcoats Victoria player.';
        }
    }

    if (in_array($type, ['yellow_card', 'red_card'], true)) {
        $cardType = $type === 'yellow_card' ? 'yellow' : 'red';
        if ($team === 'svfc' && $player === '') {
            $errors[] = 'Select the booked or sent-off Saltcoats Victoria player.';
        }
    }

    if (in_array($type, ['shot', 'free_kick', 'penalty'], true) && $outcome === '') {
        $errors[] = 'Select an outcome for this event.';
    }

    if ($type === 'substitution') {
        $rowCount = max(count($substitutionOff), count($substitutionOn));
        for ($index = 0; $index < $rowCount; $index++) {
            $off = isset($substitutionOff[$index]) && is_string($substitutionOff[$index]) ? trim($substitutionOff[$index]) : '';
            $on = isset($substitutionOn[$index]) && is_string($substitutionOn[$index]) ? trim($substitutionOn[$index]) : '';
            if ($off === '' && $on === '') {
                continue;
            }
            if ($off === '' || $on === '') {
                $errors[] = 'Select both players for substitution ' . ($index + 1) . '.';
                continue;
            }
            if ($off === $on && strcasecmp($off, 'Unknown Player') !== 0) {
                $errors[] = 'The players in substitution ' . ($index + 1) . ' must be different.';
                continue;
            }
            $substitutions[] = ['off' => $off, 'on' => $on];
        }

        // Continue accepting the original single-substitution fields from older clients.
        if ($substitutions === [] && $player !== '' && $secondaryPlayer !== '') {
            $substitutions[] = ['off' => $player, 'on' => $secondaryPlayer];
        }
        if ($substitutions === []) {
            $errors[] = 'Add at least one complete substitution.';
        } else {
            $player = $substitutions[0]['off'];
            $secondaryPlayer = $substitutions[0]['on'];
        }
    }

    if ($type === 'note' && $note === '') {
        $errors[] = 'Event details are required.';
    }

    if ($errors !== []) {
        return [
            'ok' => false,
            'message' => 'Please fix the event form and try again.',
            'errors' => $errors,
        ];
    }

    return [
        'ok' => true,
        'message' => 'Event is valid.',
        'event' => [
            'type' => $type,
            'team' => $team,
            'minute' => $minute,
            'player' => $player,
            'own_goal' => $ownGoal,
            'secondary_player' => $secondaryPlayer,
            'substitutions' => $substitutions,
            'card_type' => $cardType,
            'outcome' => $outcome,
            'origin' => $origin,
            'target' => $target,
            'note' => $note,
        ],
    ];
}

/**
 * @return list<string>
 */
function matches_current_squad_names(): array
{
    $players = players_load_all();
    $names = [];
    $allowedStatuses = ['current', 'signed', 'trial', 'trialist'];

    foreach ($players as $player) {
        $status = (string) ($player['status'] ?? '');
        if (!in_array($status, $allowedStatuses, true)) {
            continue;
        }

        $name = trim((string) ($player['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $names[] = $name;
    }

    $names = array_values(array_unique($names));
    natcasesort($names);

    return array_values($names);
}

/**
 * @param list<array<string, mixed>> $matches
 */
function matches_find_by_id(array $matches, string $id): ?array
{
    foreach ($matches as $match) {
        if ((string) ($match['id'] ?? '') === $id) {
            return $match;
        }
    }

    return null;
}

/**
 * @return array{path: string, error: string}
 */
function matches_handle_background_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['path' => '', 'error' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['path' => '', 'error' => 'Background image upload failed.'];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['path' => '', 'error' => 'Uploaded background image could not be validated.'];
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        return ['path' => '', 'error' => 'Please upload a valid background image.'];
    }

    $mime = (string) ($imageInfo['mime'] ?? '');
    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensionMap[$mime])) {
        return ['path' => '', 'error' => 'Only JPG, PNG, or WebP images are allowed for match backgrounds.'];
    }

    if (!is_dir(MATCHES_UPLOAD_DIR) && !@mkdir(MATCHES_UPLOAD_DIR, 0775, true) && !is_dir(MATCHES_UPLOAD_DIR)) {
        return ['path' => '', 'error' => 'The match background upload directory could not be created.'];
    }

    $filename = 'match-bg-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensionMap[$mime];
    $destination = MATCHES_UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        return ['path' => '', 'error' => 'The match background image could not be saved.'];
    }

    return ['path' => 'uploads/matches/' . $filename, 'error' => ''];
}

/**
 * @return array{path: string, error: string}
 */
function matches_handle_graphic_template_upload(array $file, string $templateKey): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['path' => '', 'error' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['path' => '', 'error' => 'Graphic upload failed.'];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['path' => '', 'error' => 'Uploaded graphic could not be validated.'];
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        return ['path' => '', 'error' => 'Please upload a valid graphic image.'];
    }

    $mime = (string) ($imageInfo['mime'] ?? '');
    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensionMap[$mime])) {
        return ['path' => '', 'error' => 'Only JPG, PNG, or WebP images are allowed for match graphics.'];
    }

    if (!is_dir(MATCHES_UPLOAD_DIR) && !@mkdir(MATCHES_UPLOAD_DIR, 0775, true) && !is_dir(MATCHES_UPLOAD_DIR)) {
        return ['path' => '', 'error' => 'The match graphics upload directory could not be created.'];
    }

    $filename = 'match-template-' . matches_slugify($templateKey) . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensionMap[$mime];
    $destination = MATCHES_UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        return ['path' => '', 'error' => 'The match graphic image could not be saved.'];
    }

    return ['path' => 'uploads/matches/' . $filename, 'error' => ''];
}

function matches_delete_background(?string $path): void
{
    $path = trim((string) $path);
    if ($path === '' || !str_starts_with($path, 'uploads/matches/')) {
        return;
    }

    $absolutePath = __DIR__ . '/' . $path;
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function matches_fixture_label(array $match): string
{
    $opponent = trim((string) ($match['opponent'] ?? ''));
    $venue = matches_normalize_venue((string) ($match['venue'] ?? 'H'));

    if ($opponent === '') {
        return 'Unnamed Fixture';
    }

    return $opponent . ' (' . $venue . ')';
}

function matches_has_fixture_details(array $match): bool
{
    return trim((string) ($match['opponent'] ?? '')) !== ''
        && trim((string) ($match['competition'] ?? '')) !== ''
        && trim((string) ($match['season'] ?? '')) !== ''
        && trim((string) ($match['match_date'] ?? '')) !== '';
}

function matches_is_upcoming_fixture(array $match): bool
{
    $matchDate = trim((string) ($match['match_date'] ?? ''));
    if ($matchDate === '') {
        return false;
    }

    $parsedMatchDate = DateTimeImmutable::createFromFormat('Y-m-d', $matchDate);
    if ($parsedMatchDate === false) {
        return false;
    }

    return $parsedMatchDate > new DateTimeImmutable('today');
}

function matches_has_ready_lineup(array $match): bool
{
    $starters = matches_prepare_lineup($match['starters'] ?? []);
    $captain = trim((string) ($match['captain'] ?? ''));

    return count($starters) === 11
        && ($captain === '' || in_array($captain, $starters, true));
}

/**
 * @return array{configured: int, total: int, has_background: bool, ready: bool}
 */
function matches_template_progress(array $match): array
{
    $templates = matches_normalize_graphic_templates($match['graphics_templates'] ?? []);
    $configured = 0;

    foreach (matches_graphic_template_definitions() as $templateKey => $definition) {
        if (trim((string) ($templates[$templateKey]['image'] ?? '')) !== '') {
            $configured++;
        }
    }

    $total = count(matches_graphic_template_definitions());
    $hasBackground = trim((string) ($match['background_image'] ?? '')) !== '';

    return [
        'configured' => $configured,
        'total' => $total,
        'has_background' => $hasBackground,
        'ready' => $hasBackground && $configured === $total,
    ];
}

/**
 * @return array{count: int, summary: array{state: string, goals: int, cards: int, substitutions: int}, ready: bool}
 */
function matches_event_progress(array $match): array
{
    $events = isset($match['events']) && is_array($match['events']) ? $match['events'] : [];

    return [
        'count' => count($events),
        'summary' => matches_event_summary($match),
        'ready' => $events !== [],
    ];
}

/**
 * @return list<array{key: string, label: string, done: bool, summary: string, href: string, cta: string}>
 */
function matches_workflow_steps(array $match): array
{
    $templateProgress = matches_template_progress($match);
    $eventProgress = matches_event_progress($match);
    $matchId = trim((string) ($match['id'] ?? ''));

    return [
        [
            'key' => 'fixture',
            'label' => 'Fixture',
            'done' => matches_has_fixture_details($match),
            'summary' => trim((string) ($match['competition'] ?? '')) !== ''
                ? app_format_uk_date((string) ($match['match_date'] ?? ''), 'Set match date')
                    . ' • '
                    . trim((string) ($match['competition'] ?? ''))
                : 'Opponent, competition, season, and date',
            'href' => 'match.php?id=' . rawurlencode($matchId) . '&tool=edit_match',
            'cta' => 'Edit fixture',
        ],
        [
            'key' => 'lineup',
            'label' => 'Starting 11',
            'done' => matches_has_ready_lineup($match),
            'summary' => count(matches_prepare_lineup($match['starters'] ?? [])) . '/11 starters selected',
            'href' => 'match_starting_11.php?id=' . rawurlencode($matchId),
            'cta' => 'Set lineup',
        ],
        [
            'key' => 'templates',
            'label' => 'Templates',
            'done' => $templateProgress['ready'],
            'summary' => $templateProgress['configured'] . '/' . $templateProgress['total'] . ' event graphics'
                . ($templateProgress['has_background'] ? ' + XI background' : ' + XI background missing'),
            'href' => 'match_templates.php?id=' . rawurlencode($matchId),
            'cta' => 'Manage assets',
        ],
        [
            'key' => 'events',
            'label' => 'Match Events',
            'done' => $eventProgress['ready'],
            'summary' => $eventProgress['count'] > 0
                ? $eventProgress['count'] . ' logged • ' . $eventProgress['summary']['state']
                : 'No match events recorded yet',
            'href' => 'match_events.php?id=' . rawurlencode($matchId),
            'cta' => 'Add events',
        ],
    ];
}

/**
 * @return array{label: string, description: string, href: string}
 */
function matches_primary_action(array $match): array
{
    foreach (matches_workflow_steps($match) as $step) {
        if (!$step['done']) {
            return [
                'label' => $step['cta'],
                'description' => $step['summary'],
                'href' => $step['href'],
            ];
        }
    }

    return [
        'label' => 'Preview and post',
        'description' => 'Your match setup is in place. Open the graphic preview and send it out.',
        'href' => 'match_graphic.php?id=' . rawurlencode((string) ($match['id'] ?? '')),
    ];
}

function matches_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'match';
}

/**
 * @return array{opponents: list<string>, competitions: list<string>, seasons: list<string>}
 */
function matches_meta_load(): array
{
    $defaults = [
        'opponents' => [],
        'competitions' => [],
        'seasons' => [],
    ];

    if (!is_file(MATCHES_META_FILE)) {
        return $defaults;
    }

    $json = file_get_contents(MATCHES_META_FILE);
    if ($json === false || trim($json) === '') {
        return $defaults;
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return $defaults;
    }

    $dbCompetitions = matches_meta_db_competitions();
    $competitions = $dbCompetitions !== [] ? $dbCompetitions : matches_meta_normalize_values($data['competitions'] ?? []);

    return [
        'opponents' => matches_meta_normalize_values($data['opponents'] ?? []),
        'competitions' => $competitions,
        'seasons' => matches_meta_normalize_values($data['seasons'] ?? []),
    ];
}

/**
 * @param array{opponents: list<string>, competitions: list<string>, seasons: list<string>} $meta
 */
function matches_meta_save(array $meta): bool
{
    if (!is_dir(dirname(MATCHES_META_FILE))) {
        @mkdir(dirname(MATCHES_META_FILE), 0775, true);
    }

    $payload = [
        'opponents' => matches_meta_normalize_values($meta['opponents'] ?? []),
        'competitions' => matches_meta_normalize_values($meta['competitions'] ?? []),
        'seasons' => matches_meta_normalize_values($meta['seasons'] ?? []),
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    $saved = file_put_contents(MATCHES_META_FILE, $json . PHP_EOL, LOCK_EX) !== false;
    if ($saved) {
        matches_meta_sync_competitions_to_db($payload['competitions']);
    }

    return $saved;
}

/**
 * @param mixed $values
 * @return list<string>
 */
function matches_meta_normalize_values($values): array
{
    if (!is_array($values)) {
        return [];
    }

    $normalized = [];
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }

        $exists = false;
        foreach ($normalized as $existing) {
            if (strcasecmp($existing, $value) === 0) {
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $normalized[] = $value;
        }
    }

    natcasesort($normalized);

    return array_values($normalized);
}

/**
 * @param list<string> $primary
 * @param list<string> $secondary
 * @return list<string>
 */
function matches_meta_merge_values(array $primary, array $secondary): array
{
    $merged = matches_meta_normalize_values($primary);
    foreach (matches_meta_normalize_values($secondary) as $value) {
        $exists = false;
        foreach ($merged as $existing) {
            if (strcasecmp($existing, $value) === 0) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $merged[] = $value;
        }
    }

    natcasesort($merged);

    return array_values($merged);
}

/**
 * @return list<string>
 */
function matches_meta_db_competitions(): array
{
    $pdo = matches_master_pdo();
    if (!$pdo) {
        return [];
    }

    try {
        $stmt = $pdo->query("
            SELECT name
            FROM match_competitions
            ORDER BY COALESCE(sort_order, 2147483647) ASC, name ASC
        ");
        $values = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        return matches_meta_normalize_values($values ?: []);
    } catch (Throwable) {
        return [];
    }
}

/**
 * @param list<string> $competitions
 */
function matches_meta_sync_competitions_to_db(array $competitions): void
{
    $pdo = matches_master_pdo();
    if (!$pdo) {
        return;
    }

    $competitions = matches_meta_normalize_values($competitions);
    if ($competitions === []) {
        return;
    }

    try {
        $index = 0;
        foreach ($competitions as $competition) {
            $stmt = $pdo->prepare("
                INSERT INTO match_competitions (name, sort_order)
                VALUES (:name, :sort_order)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    sort_order = VALUES(sort_order)
            ");
            $stmt->execute([
                ':name' => $competition,
                ':sort_order' => $index,
            ]);
            $index++;
        }
    } catch (Throwable) {
        // Keep socials working even if the shared competition table is temporarily unavailable.
    }
}

/**
 * @return array{key: string, label: string}
 */
function matches_meta_field_config(string $type): array
{
    $map = [
        'opponent' => ['key' => 'opponents', 'label' => 'Opponent'],
        'competition' => ['key' => 'competitions', 'label' => 'Competition'],
        'season' => ['key' => 'seasons', 'label' => 'Season'],
    ];

    return $map[$type] ?? ['key' => '', 'label' => ''];
}

/**
 * @return array{ok: bool, message: string, option_type?: string, option_value?: string, errors?: list<string>}
 */
function matches_meta_add_option(string $type, string $value): array
{
    $field = matches_meta_field_config($type);
    if ($field['key'] === '') {
        return [
            'ok' => false,
            'message' => 'Option type is not valid.',
        ];
    }

    $value = trim($value);
    if ($value === '') {
        return [
            'ok' => false,
            'message' => 'Please enter a value before saving.',
            'errors' => [$field['label'] . ' name is required.'],
        ];
    }

    $meta = matches_meta_load();
    $values = $meta[$field['key']];
    foreach ($values as $existing) {
        if (strcasecmp($existing, $value) === 0) {
            return [
                'ok' => false,
                'message' => $field['label'] . ' already exists.',
                'errors' => [$field['label'] . ' already exists.'],
            ];
        }
    }

    $values[] = $value;
    $meta[$field['key']] = $values;

    if (!matches_meta_save($meta)) {
        return [
            'ok' => false,
            'message' => $field['label'] . ' could not be saved.',
        ];
    }

    return [
        'ok' => true,
        'message' => $field['label'] . ' added successfully.',
        'option_type' => $type,
        'option_value' => $value,
    ];
}

/**
 * @return array<string, mixed>
 */
function matches_empty_form_state(): array
{
    return [
        'id' => '',
        'opponent' => '',
        'competition' => '',
        'season' => '',
        'venue' => 'H',
        'venue_name' => '',
        'match_date' => date('Y-m-d'),
        'kickoff_time' => '',
        'template_key' => 'starting_xi_classic',
        'title_primary' => 'STARTING',
        'title_accent' => 'XI',
        'opponent_prefix' => 'VS',
        'background_image' => '',
        'graphics_templates' => matches_match_graphic_templates_defaults(),
        'starters' => array_fill(0, 11, ''),
        'substitutes' => array_fill(0, 9, ''),
        'captain' => '',
        'events' => [],
    ];
}

/**
 * @return array{ok: bool, message: string, match_id?: string, errors?: list<string>}
 */
function matches_handle_create(array $post): array
{
    $matches = matches_load_all();
    $opponent = isset($post['opponent']) && is_string($post['opponent']) ? trim($post['opponent']) : '';
    $competition = isset($post['competition']) && is_string($post['competition']) ? trim($post['competition']) : '';
    $season = isset($post['season']) && is_string($post['season']) ? trim($post['season']) : '';
    $venue = matches_normalize_venue(isset($post['venue']) && is_string($post['venue']) ? $post['venue'] : null);
    $venueName = isset($post['venue_name']) && is_string($post['venue_name']) ? trim($post['venue_name']) : '';
    $matchDate = isset($post['match_date']) && is_string($post['match_date']) ? trim($post['match_date']) : '';
    $kickoffTime = isset($post['kickoff_time']) && is_string($post['kickoff_time']) ? trim($post['kickoff_time']) : '';
    $template = matches_template_config(isset($post['template_key']) && is_string($post['template_key']) ? $post['template_key'] : null);

    $errors = [];
    if ($opponent === '') {
        $errors[] = 'Opponent is required.';
    }
    if ($competition === '') {
        $errors[] = 'Competition is required.';
    }
    if ($season === '') {
        $errors[] = 'Season is required.';
    }
    if ($matchDate === '') {
        $errors[] = 'Match date is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $matchDate)) {
        $errors[] = 'Match date must use the YYYY-MM-DD format.';
    }
    if ($kickoffTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $kickoffTime)) {
        $errors[] = 'Kickoff time must use the HH:MM format.';
    }

    if ($errors !== []) {
        return [
            'ok' => false,
            'message' => 'Please fix the match form and try again.',
            'errors' => $errors,
        ];
    }

    $timestamp = date(DATE_ATOM);
    $matchId = matches_generate_id();
    $matchRecord = matches_normalize([
        'id' => $matchId,
        'opponent' => $opponent,
        'competition' => $competition,
        'season' => $season,
        'venue' => $venue,
        'venue_name' => $venueName,
        'match_date' => $matchDate,
        'kickoff_time' => $kickoffTime,
        'template_key' => $template['key'],
        'title_primary' => $template['title_primary'],
        'title_accent' => $template['title_accent'],
        'opponent_prefix' => $template['opponent_prefix'],
        'background_image' => '',
        'graphics_templates' => matches_match_graphic_templates_defaults(),
        'starters' => [],
        'substitutes' => [],
        'captain' => '',
        'events' => [],
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    $syncResult = matches_sync_master_fixture_details($matchRecord);
    if (!$syncResult['ok']) {
        return $syncResult + ['match_id' => $matchId];
    }

    if (!empty($syncResult['master_id'])) {
        $matchId = (string) $syncResult['master_id'];
        $matchRecord['id'] = $matchId;
    }

    $matches[] = $matchRecord;

    if (!matches_save_all($matches)) {
        return [
            'ok' => false,
            'message' => 'Match record could not be created.',
        ];
    }

    matches_google_calendar_sync_fixture($matchRecord, 'upsert');

    return [
        'ok' => true,
        'message' => 'Match created successfully.',
        'match_id' => $matchId,
    ];
}

/**
 * @return array{ok: bool, message: string, errors?: list<string>}
 */
function matches_validate_fixture_fields(string $opponent, string $competition, string $season, string $matchDate, string $kickoffTime): array
{
    $errors = [];
    if ($opponent === '') {
        $errors[] = 'Opponent is required.';
    }
    if ($competition === '') {
        $errors[] = 'Competition is required.';
    }
    if ($season === '') {
        $errors[] = 'Season is required.';
    }
    if ($matchDate === '') {
        $errors[] = 'Match date is required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $matchDate)) {
        $errors[] = 'Match date must use the YYYY-MM-DD format.';
    }
    if ($kickoffTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $kickoffTime)) {
        $errors[] = 'Kickoff time must use the HH:MM format.';
    }

    return $errors;
}

function matches_find_index(array $matches, string $matchId): int
{
    foreach ($matches as $index => $match) {
        if ((string) ($match['id'] ?? '') === $matchId && $matchId !== '') {
            return $index;
        }
    }

    return -1;
}

/**
 * @return array{ok: bool, message: string, errors?: list<string>}
 */
function matches_handle_update_details(array $post): array
{
    $matches = matches_load_all();
    $matchId = isset($post['match_id']) && is_string($post['match_id']) ? trim($post['match_id']) : '';
    $opponent = isset($post['opponent']) && is_string($post['opponent']) ? trim($post['opponent']) : '';
    $competition = isset($post['competition']) && is_string($post['competition']) ? trim($post['competition']) : '';
    $season = isset($post['season']) && is_string($post['season']) ? trim($post['season']) : '';
    $venue = matches_normalize_venue(isset($post['venue']) && is_string($post['venue']) ? $post['venue'] : null);
    $venueName = isset($post['venue_name']) && is_string($post['venue_name']) ? trim($post['venue_name']) : '';
    $matchDate = isset($post['match_date']) && is_string($post['match_date']) ? trim($post['match_date']) : '';
    $kickoffTime = isset($post['kickoff_time']) && is_string($post['kickoff_time']) ? trim($post['kickoff_time']) : '';
    $errors = matches_validate_fixture_fields($opponent, $competition, $season, $matchDate, $kickoffTime);

    if ($errors !== []) {
        return [
            'ok' => false,
            'message' => 'Please fix the match form and try again.',
            'errors' => $errors,
        ];
    }

    $index = matches_find_index($matches, $matchId);
    if ($index < 0) {
        return [
            'ok' => false,
            'message' => 'Match could not be found.',
        ];
    }

    $existing = $matches[$index];
    $updatedMatch = matches_normalize([
        'id' => $matchId,
        'opponent' => $opponent,
        'competition' => $competition,
        'season' => $season,
        'venue' => $venue,
        'venue_name' => $venueName,
        'match_date' => $matchDate,
        'kickoff_time' => $kickoffTime,
        'template_key' => (string) ($existing['template_key'] ?? 'starting_xi_classic'),
        'title_primary' => (string) ($existing['title_primary'] ?? 'STARTING'),
        'title_accent' => (string) ($existing['title_accent'] ?? 'XI'),
        'opponent_prefix' => (string) ($existing['opponent_prefix'] ?? 'VS'),
        'background_image' => (string) ($existing['background_image'] ?? ''),
        'graphics_templates' => $existing['graphics_templates'] ?? [],
        'starters' => $existing['starters'] ?? [],
        'substitutes' => $existing['substitutes'] ?? [],
        'captain' => (string) ($existing['captain'] ?? ''),
        'events' => $existing['events'] ?? [],
        'created_at' => $existing['created_at'] ?? date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
    ]);

    $syncResult = matches_sync_master_fixture_details($updatedMatch);
    if (!$syncResult['ok']) {
        return $syncResult;
    }

    if (!empty($syncResult['master_id'])) {
        $matchId = (string) $syncResult['master_id'];
        $updatedMatch['id'] = $matchId;
    }

    $matches[$index] = $updatedMatch;

    if (!matches_save_all($matches)) {
        return [
            'ok' => false,
            'message' => 'Match record could not be saved.',
        ];
    }

    matches_google_calendar_sync_fixture($updatedMatch, 'upsert');

    return [
        'ok' => true,
        'message' => 'Match updated successfully.',
        'match_id' => $matchId,
    ];
}

/**
 * @return array{ok: bool, message: string, errors?: list<string>}
 */
function matches_handle_update_lineup(array $post, array $files): array
{
    $matches = matches_load_all();
    $matchId = isset($post['match_id']) && is_string($post['match_id']) ? trim($post['match_id']) : '';
    $isAutosave = !empty($post['autosave']);
    $template = matches_template_config(isset($post['template_key']) && is_string($post['template_key']) ? $post['template_key'] : null);
    $titlePrimary = isset($post['title_primary']) && is_string($post['title_primary']) ? trim($post['title_primary']) : $template['title_primary'];
    $titleAccent = isset($post['title_accent']) && is_string($post['title_accent']) ? trim($post['title_accent']) : $template['title_accent'];
    $opponentPrefix = isset($post['opponent_prefix']) && is_string($post['opponent_prefix']) ? trim($post['opponent_prefix']) : $template['opponent_prefix'];
    $postedBackground = isset($post['existing_background']) && is_string($post['existing_background']) ? trim($post['existing_background']) : '';
    $starters = matches_prepare_lineup($post['starters'] ?? []);
    $substitutes = matches_prepare_lineup($post['substitutes'] ?? []);
    $captain = isset($post['captain']) && is_string($post['captain']) ? trim($post['captain']) : '';

    $errors = [];
    if (!$isAutosave && count($starters) !== 11) {
        $errors[] = 'Select exactly 11 starters.';
    }
    if (count(array_intersect($starters, $substitutes)) > 0) {
        $errors[] = 'A player cannot be listed in both starters and substitutes.';
    }
    if ($captain !== '' && !in_array($captain, $starters, true)) {
        $errors[] = 'Captain must be one of the selected starters.';
    }
    if ($titlePrimary === '') {
        $errors[] = 'Primary title is required.';
    }
    if ($titleAccent === '') {
        $errors[] = 'Accent title is required.';
    }
    if ($opponentPrefix === '') {
        $errors[] = 'Opponent prefix is required.';
    }

    $upload = matches_handle_background_upload($files['background_image'] ?? []);
    if ($upload['error'] !== '') {
        $errors[] = $upload['error'];
    }

    if ($errors !== []) {
        return [
            'ok' => false,
            'message' => 'Please fix the match form and try again.',
            'errors' => $errors,
        ];
    }

    $index = matches_find_index($matches, $matchId);
    if ($index < 0) {
        return [
            'ok' => false,
            'message' => 'Match could not be found.',
        ];
    }

    $existing = $matches[$index];
    $storedBackground = trim((string) ($existing['background_image'] ?? ''));
    $existingBackground = $postedBackground !== '' ? $postedBackground : $storedBackground;
    $backgroundImage = $upload['path'] !== '' ? $upload['path'] : $existingBackground;
    $graphicsTemplates = matches_normalize_graphic_templates($existing['graphics_templates'] ?? []);
    $previousKickOffImage = trim((string) ($graphicsTemplates['kick_off']['image'] ?? ''));

    if ($upload['path'] !== '') {
        $graphicsTemplates['kick_off']['image'] = $upload['path'];
    }

    if ($upload['path'] !== '' && $storedBackground !== '' && $storedBackground !== $upload['path']) {
        matches_delete_background($storedBackground);
    }
    if ($upload['path'] !== '' && $previousKickOffImage !== '' && $previousKickOffImage !== $upload['path'] && $previousKickOffImage !== $storedBackground) {
        matches_delete_background($previousKickOffImage);
    }

    $updatedMatch = matches_normalize([
        'id' => $matchId,
        'opponent' => (string) ($existing['opponent'] ?? ''),
        'competition' => (string) ($existing['competition'] ?? ''),
        'season' => (string) ($existing['season'] ?? ''),
        'venue' => matches_normalize_venue((string) ($existing['venue'] ?? 'H')),
        'match_date' => (string) ($existing['match_date'] ?? ''),
        'kickoff_time' => (string) ($existing['kickoff_time'] ?? ''),
        'template_key' => $template['key'],
        'title_primary' => $titlePrimary,
        'title_accent' => $titleAccent,
        'opponent_prefix' => $opponentPrefix,
        'background_image' => $backgroundImage,
        'graphics_templates' => $graphicsTemplates,
        'starters' => $starters,
        'substitutes' => $substitutes,
        'captain' => $captain,
        'events' => $existing['events'] ?? [],
        'created_at' => $existing['created_at'] ?? date(DATE_ATOM),
        'updated_at' => date(DATE_ATOM),
    ]);

    $syncResult = matches_sync_master_fixture_details($updatedMatch);
    if (!$syncResult['ok']) {
        return $syncResult;
    }

    if (!empty($syncResult['master_id'])) {
        $matchId = (string) $syncResult['master_id'];
        $updatedMatch['id'] = $matchId;
    }

    $matches[$index] = $updatedMatch;

    if (!matches_save_all($matches)) {
        return [
            'ok' => false,
            'message' => 'Match record could not be saved.',
        ];
    }

    return [
        'ok' => true,
        'message' => 'Starting 11 updated successfully.',
        'match_id' => $matchId,
        'background_image' => $backgroundImage,
        'lineup_background' => matches_match_lineup_background($matches[$index]),
    ];
}

/**
 * @return array{ok: bool, message: string, errors?: list<string>}
 */
function matches_handle_save_background_asset(array $post, array $files): array
{
    $matches = matches_load_all();
    $matchId = isset($post['match_id']) && is_string($post['match_id']) ? trim($post['match_id']) : '';
    $index = matches_find_index($matches, $matchId);

    if ($index < 0) {
        return [
            'ok' => false,
            'message' => 'Match could not be found.',
        ];
    }

    $upload = matches_handle_background_upload($files['background_image'] ?? []);
    if ($upload['error'] !== '') {
        return [
            'ok' => false,
            'message' => 'Please fix the background upload and try again.',
            'errors' => [$upload['error']],
        ];
    }

    if ($upload['path'] === '') {
        return [
            'ok' => false,
            'message' => 'Please choose a background image to upload.',
            'errors' => ['Background image is required.'],
        ];
    }

    $existing = $matches[$index];
    $existingBackground = trim((string) ($existing['background_image'] ?? ''));
    if ($existingBackground !== '' && $existingBackground !== $upload['path']) {
        matches_delete_background($existingBackground);
    }

    $updatedMatch = matches_normalize(array_merge($existing, [
        'background_image' => $upload['path'],
        'updated_at' => date(DATE_ATOM),
    ]));

    $syncResult = matches_sync_master_fixture_details($updatedMatch);
    if (!$syncResult['ok']) {
        return $syncResult;
    }

    if (!empty($syncResult['master_id'])) {
        $matchId = (string) $syncResult['master_id'];
        $updatedMatch['id'] = $matchId;
    }

    $matches[$index] = $updatedMatch;

    if (!matches_save_all($matches)) {
        return [
            'ok' => false,
            'message' => 'Background image could not be saved.',
        ];
    }

    return [
        'ok' => true,
        'message' => 'Starting XI background updated successfully.',
        'match_id' => $matchId,
    ];
}

/**
 * @return array{ok: bool, message: string}
 */
function matches_handle_delete_background_asset(array $post): array
{
    $matches = matches_load_all();
    $matchId = isset($post['match_id']) && is_string($post['match_id']) ? trim($post['match_id']) : '';
    $index = matches_find_index($matches, $matchId);

    if ($index < 0) {
        return [
            'ok' => false,
            'message' => 'Match could not be found.',
        ];
    }

    $existing = $matches[$index];
    $backgroundImage = trim((string) ($existing['background_image'] ?? ''));
    if ($backgroundImage !== '') {
        matches_delete_background($backgroundImage);
    }

    $updatedMatch = matches_normalize(array_merge($existing, [
        'background_image' => '',
        'updated_at' => date(DATE_ATOM),
    ]));

    $syncResult = matches_sync_master_fixture_details($updatedMatch);
    if (!$syncResult['ok']) {
        return $syncResult;
    }

    if (!empty($syncResult['master_id'])) {
        $updatedMatch['id'] = (string) $syncResult['master_id'];
    }

    $matches[$index] = $updatedMatch;

    if (!matches_save_all($matches)) {
        return [
            'ok' => false,
            'message' => 'Background image could not be removed.',
        ];
    }

    return [
        'ok' => true,
        'message' => 'Starting XI background removed.',
        'match_id' => $matchId,
    ];
}

/**
 * @return array{ok: bool, message: string, errors?: list<string>}
 */
function matches_handle_save(array $post, array $files): array
{
    return matches_handle_update_lineup($post, $files);
}

/**
 * @return array{ok: bool, message: string, errors?: list<string>}
 */
function matches_handle_save_graphic_template(array $post, array $files): array
{
    $matches = matches_load_all();
    $matchId = isset($post['match_id']) && is_string($post['match_id']) ? trim($post['match_id']) : '';
    $templateConfig = matches_graphic_template_config(isset($post['template_type']) && is_string($post['template_type']) ? $post['template_type'] : null);
    if ($templateConfig['key'] === '') {
        return [
            'ok' => false,
            'message' => 'Graphic template type is not valid.',
        ];
    }

    $index = matches_find_index($matches, $matchId);
    if ($index < 0) {
        return [
            'ok' => false,
            'message' => 'Match could not be found.',
        ];
    }

    $captions = [];
    foreach (matches_graphic_template_channels() as $channelKey => $label) {
        $value = isset($post['captions'][$channelKey]) && is_string($post['captions'][$channelKey])
            ? trim($post['captions'][$channelKey])
            : '';
        $captions[$channelKey] = $value;
    }

    $upload = matches_handle_graphic_template_upload($files['graphic_image'] ?? [], $templateConfig['key']);
    if ($upload['error'] !== '') {
        return [
            'ok' => false,
            'message' => 'Please fix the template form and try again.',
            'errors' => [$upload['error']],
        ];
    }

    $existing = $matches[$index];
    $templates = matches_normalize_graphic_templates($existing['graphics_templates'] ?? []);
    $current = $templates[$templateConfig['key']] ?? matches_graphic_template_empty();
    $imagePath = $upload['path'] !== '' ? $upload['path'] : (string) ($current['image'] ?? '');

    if ($upload['path'] !== '' && $current['image'] !== '' && $current['image'] !== $upload['path']) {
        matches_delete_background((string) $current['image']);
    }

    $templates[$templateConfig['key']] = [
        'image' => $imagePath,
        'captions' => $captions,
    ];

    $matches[$index] = matches_normalize(array_merge($existing, [
        'graphics_templates' => $templates,
        'updated_at' => date(DATE_ATOM),
    ]));

    if (!matches_save_all($matches)) {
        return [
            'ok' => false,
            'message' => 'Graphic template could not be saved.',
        ];
    }

    return [
        'ok' => true,
        'message' => $templateConfig['label'] . ' template saved successfully.',
    ];
}

/**
 * @return array{ok: bool, message: string}
 */
function matches_handle_delete_graphic_template(array $post): array
{
    $matches = matches_load_all();
    $matchId = isset($post['match_id']) && is_string($post['match_id']) ? trim($post['match_id']) : '';
    $templateConfig = matches_graphic_template_config(isset($post['template_type']) && is_string($post['template_type']) ? $post['template_type'] : null);
    if ($templateConfig['key'] === '') {
        return [
            'ok' => false,
            'message' => 'Graphic template type is not valid.',
        ];
    }

    $index = matches_find_index($matches, $matchId);
    if ($index < 0) {
        return [
            'ok' => false,
            'message' => 'Match could not be found.',
        ];
    }

    $existing = $matches[$index];
    $templates = matches_normalize_graphic_templates($existing['graphics_templates'] ?? []);
    $current = $templates[$templateConfig['key']] ?? matches_graphic_template_empty();
    if ((string) ($current['image'] ?? '') !== '') {
        matches_delete_background((string) $current['image']);
    }

    $templates[$templateConfig['key']] = matches_graphic_template_empty();
    $matches[$index] = matches_normalize(array_merge($existing, [
        'graphics_templates' => $templates,
        'updated_at' => date(DATE_ATOM),
    ]));

    if (!matches_save_all($matches)) {
        return [
            'ok' => false,
            'message' => 'Graphic template could not be deleted.',
        ];
    }

    return [
        'ok' => true,
        'message' => $templateConfig['label'] . ' template deleted successfully.',
    ];
}

/**
 * @return array{ok: bool, message: string, errors?: list<string>}
 */
function matches_handle_save_master_graphic_template(array $post, array $files): array
{
    $templateConfig = matches_graphic_template_config(isset($post['template_type']) && is_string($post['template_type']) ? $post['template_type'] : null);
    if ($templateConfig['key'] === '') {
        return [
            'ok' => false,
            'message' => 'Graphic template type is not valid.',
        ];
    }

    $templates = matches_master_graphic_templates_load();
    $current = $templates[$templateConfig['key']] ?? matches_graphic_template_empty();
    $captions = [];
    foreach (matches_graphic_template_channels() as $channelKey => $label) {
        $captions[$channelKey] = isset($post['captions'][$channelKey]) && is_string($post['captions'][$channelKey])
            ? trim($post['captions'][$channelKey])
            : (string) ($current['captions'][$channelKey] ?? '');
    }

    $upload = matches_handle_graphic_template_upload($files['graphic_image'] ?? [], $templateConfig['key']);
    if ($upload['error'] !== '') {
        return [
            'ok' => false,
            'message' => 'Please fix the template form and try again.',
            'errors' => [$upload['error']],
        ];
    }

    $imagePath = $upload['path'] !== '' ? $upload['path'] : (string) ($current['image'] ?? '');
    if ($upload['path'] !== '' && $current['image'] !== '' && $current['image'] !== $upload['path']) {
        matches_delete_background((string) $current['image']);
    }

    $templates[$templateConfig['key']] = [
        'image' => $imagePath,
        'captions' => $captions,
    ];

    if (!matches_master_graphic_templates_save($templates)) {
        return [
            'ok' => false,
            'message' => 'Master graphic template could not be saved.',
        ];
    }

    return [
        'ok' => true,
        'message' => $templateConfig['label'] . ' master template saved successfully.',
    ];
}

/**
 * @return array{ok: bool, message: string}
 */
function matches_handle_delete_master_graphic_template(array $post): array
{
    $templateConfig = matches_graphic_template_config(isset($post['template_type']) && is_string($post['template_type']) ? $post['template_type'] : null);
    if ($templateConfig['key'] === '') {
        return [
            'ok' => false,
            'message' => 'Graphic template type is not valid.',
        ];
    }

    $templates = matches_master_graphic_templates_load();
    $current = $templates[$templateConfig['key']] ?? matches_graphic_template_empty();
    if ((string) ($current['image'] ?? '') !== '') {
        matches_delete_background((string) $current['image']);
    }

    $templates[$templateConfig['key']] = matches_graphic_template_empty();
    if (!matches_master_graphic_templates_save($templates)) {
        return [
            'ok' => false,
            'message' => 'Master graphic template could not be deleted.',
        ];
    }

    return [
        'ok' => true,
        'message' => $templateConfig['label'] . ' master template deleted successfully.',
    ];
}

/**
 * Persist half-time/full-time totals when Match Graphics records those match states.
 * Manual fixture scores remain untouched until the matching state exists in the timeline.
 */
function matches_sync_fixture_summary_scores(array $match): void
{
    $fixtureId = ctype_digit((string)($match['id'] ?? '')) ? (int)$match['id'] : 0;
    $pdo = null;
    $scoreDatabases = array_values(array_unique([
        DB_NAME,
        defined('DB_FALLBACK_NAME') ? DB_FALLBACK_NAME : DB_NAME,
    ]));
    foreach ($scoreDatabases as $scoreDatabase) {
        try {
            $candidate = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . $scoreDatabase . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
            if ((bool)$candidate->query("SHOW TABLES LIKE 'match_fixtures'")->fetchColumn()) {
                $pdo = $candidate;
                break;
            }
        } catch (Throwable) {
            // Try the configured fallback database.
        }
    }
    if ($fixtureId <= 0 || $pdo === null) {
        return;
    }

    $events = isset($match['events']) && is_array($match['events']) ? $match['events'] : [];
    usort($events, static fn(array $left, array $right): int =>
        (int)($left['sequence'] ?? 0) <=> (int)($right['sequence'] ?? 0)
    );

    $homeScore = 0;
    $awayScore = 0;
    $halfTimeScore = null;
    $fullTimeScore = null;
    $isHomeFixture = matches_normalize_venue((string)($match['venue'] ?? 'H')) === 'H';

    foreach ($events as $event) {
        $type = (string)($event['type'] ?? '');
        $isGoal = $type === 'goal'
            || ($type === 'penalty' && (string)($event['outcome'] ?? '') === 'scored');
        if ($isGoal) {
            $isSaltcoats = (string)($event['team'] ?? '') === 'svfc';
            $isHomeGoal = $isHomeFixture ? $isSaltcoats : !$isSaltcoats;
            if ($isHomeGoal) {
                $homeScore++;
            } else {
                $awayScore++;
            }
        }

        if ($type === 'half_time') {
            $halfTimeScore = [$homeScore, $awayScore];
        } elseif ($type === 'full_time') {
            $fullTimeScore = [$homeScore, $awayScore];
        }
    }

    if ($halfTimeScore === null && $fullTimeScore === null) {
        return;
    }

    $sets = [];
    $params = [':fixture_id' => $fixtureId];
    if ($halfTimeScore !== null) {
        $sets[] = 'half_time_home_score = :half_time_home';
        $sets[] = 'half_time_away_score = :half_time_away';
        $params[':half_time_home'] = $halfTimeScore[0];
        $params[':half_time_away'] = $halfTimeScore[1];
    }
    if ($fullTimeScore !== null) {
        $sets[] = 'full_time_home_score = :full_time_home';
        $sets[] = 'full_time_away_score = :full_time_away';
        $sets[] = "status = 'played'";
        $params[':full_time_home'] = $fullTimeScore[0];
        $params[':full_time_away'] = $fullTimeScore[1];
    }

    try {
        $stmt = $pdo->prepare(
            'UPDATE match_fixtures SET ' . implode(', ', $sets) . ' WHERE id = :fixture_id LIMIT 1'
        );
        $stmt->execute($params);
    } catch (Throwable) {
        // Match-event recording must continue even if the summary cannot be synced.
    }
}

/**
 * @return array{ok: bool, message: string, errors?: list<string>, event?: array<string, mixed>}
 */
function matches_handle_add_event(array $post): array
{
    $matches = matches_load_all();
    $matchId = isset($post['match_id']) && is_string($post['match_id']) ? trim($post['match_id']) : '';
    $index = matches_find_index($matches, $matchId);
    if ($index < 0) {
        return [
            'ok' => false,
            'message' => 'Match could not be found.',
        ];
    }

    $validation = matches_build_event_payload($post);
    if (!$validation['ok']) {
        return $validation;
    }

    $existing = $matches[$index];
    $events = isset($existing['events']) && is_array($existing['events']) ? $existing['events'] : [];
    $timestamp = date(DATE_ATOM);
    $event = $validation['event'];
    $event['id'] = matches_generate_id();
    $event['sequence'] = matches_next_event_sequence($events);
    $event['created_at'] = $timestamp;
    $event['updated_at'] = $timestamp;
    $events[] = $event;

    $matches[$index] = matches_normalize(array_merge($existing, [
        'events' => $events,
        'updated_at' => $timestamp,
    ]));

    if (!matches_save_all($matches)) {
        return [
            'ok' => false,
            'message' => 'Match event could not be saved.',
        ];
    }
    matches_sync_fixture_summary_scores($matches[$index]);

    return [
        'ok' => true,
        'message' => 'Match event added successfully.',
        'event' => $event,
    ];
}

/**
 * @return array{ok: bool, message: string, errors?: list<string>}
 */
function matches_handle_update_event(array $post): array
{
    $matches = matches_load_all();
    $matchId = isset($post['match_id']) && is_string($post['match_id']) ? trim($post['match_id']) : '';
    $eventId = isset($post['event_id']) && is_string($post['event_id']) ? trim($post['event_id']) : '';
    $index = matches_find_index($matches, $matchId);
    if ($index < 0) {
        return [
            'ok' => false,
            'message' => 'Match could not be found.',
        ];
    }

    $validation = matches_build_event_payload($post);
    if (!$validation['ok']) {
        return $validation;
    }

    $existing = $matches[$index];
    $events = isset($existing['events']) && is_array($existing['events']) ? $existing['events'] : [];
    $updated = false;
    $timestamp = date(DATE_ATOM);

    foreach ($events as $eventIndex => $event) {
        if ((string) ($event['id'] ?? '') !== $eventId) {
            continue;
        }

        $updatedEvent = $validation['event'];
        $updatedEvent['id'] = $eventId;
        $updatedEvent['sequence'] = (int) ($event['sequence'] ?? ($eventIndex + 1));
        $updatedEvent['created_at'] = (string) ($event['created_at'] ?? $timestamp);
        $updatedEvent['updated_at'] = $timestamp;
        $events[$eventIndex] = $updatedEvent;
        $updated = true;
        break;
    }

    if (!$updated) {
        return [
            'ok' => false,
            'message' => 'Event could not be found.',
        ];
    }

    $matches[$index] = matches_normalize(array_merge($existing, [
        'events' => $events,
        'updated_at' => $timestamp,
    ]));

    if (!matches_save_all($matches)) {
        return [
            'ok' => false,
            'message' => 'Match event could not be saved.',
        ];
    }
    matches_sync_fixture_summary_scores($matches[$index]);

    return [
        'ok' => true,
        'message' => 'Match event updated successfully.',
    ];
}

/**
 * @return array{ok: bool, message: string}
 */
function matches_handle_delete_event(array $post): array
{
    $matches = matches_load_all();
    $matchId = isset($post['match_id']) && is_string($post['match_id']) ? trim($post['match_id']) : '';
    $eventId = isset($post['event_id']) && is_string($post['event_id']) ? trim($post['event_id']) : '';
    $index = matches_find_index($matches, $matchId);
    if ($index < 0) {
        return [
            'ok' => false,
            'message' => 'Match could not be found.',
        ];
    }

    $existing = $matches[$index];
    $events = isset($existing['events']) && is_array($existing['events']) ? $existing['events'] : [];
    $remaining = [];
    $deleted = false;
    foreach ($events as $event) {
        if ((string) ($event['id'] ?? '') === $eventId) {
            $deleted = true;
            continue;
        }
        $remaining[] = $event;
    }

    if (!$deleted) {
        return [
            'ok' => false,
            'message' => 'Event could not be found.',
        ];
    }

    $matches[$index] = matches_normalize(array_merge($existing, [
        'events' => $remaining,
        'updated_at' => date(DATE_ATOM),
    ]));

    if (!matches_save_all($matches)) {
        return [
            'ok' => false,
            'message' => 'Match event could not be deleted.',
        ];
    }
    matches_sync_fixture_summary_scores($matches[$index]);

    return [
        'ok' => true,
        'message' => 'Match event deleted successfully.',
    ];
}

/**
 * @return array{ok: bool, message: string}
 */
function matches_handle_delete(array $post): array
{
    $matches = matches_load_all();
    $matchId = isset($post['match_id']) && is_string($post['match_id']) ? trim($post['match_id']) : '';
    $remaining = [];
    $deleted = false;

    foreach ($matches as $match) {
        if ((string) ($match['id'] ?? '') === $matchId) {
            $syncResult = matches_sync_master_fixture_delete($match);
            if (!$syncResult['ok']) {
                return $syncResult;
            }
            matches_google_calendar_sync_fixture($match, 'delete');
            matches_delete_background((string) ($match['background_image'] ?? ''));
            $templates = matches_normalize_graphic_templates($match['graphics_templates'] ?? []);
            foreach ($templates as $template) {
                matches_delete_background((string) ($template['image'] ?? ''));
            }
            $deleted = true;
            continue;
        }
        $remaining[] = $match;
    }

    if ($deleted && matches_save_all($remaining)) {
        return [
            'ok' => true,
            'message' => 'Match deleted successfully.',
        ];
    }

    return [
        'ok' => false,
        'message' => 'Match could not be deleted.',
    ];
}
