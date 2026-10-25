<?php

declare(strict_types=1);

require_once __DIR__ . '/google_calendar.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/player_birthdays.php';

/*
 * Keeps the "Club Birthdays" Google Calendar in step with the dates of birth recorded on the Hub.
 *
 * Everyone with a date of birth is included except people whose role is Member. Each birthday is a
 * yearly event at 07:00 (London time) with reminders 24 hours before and at 07:00 on the day.
 * Events use fixed ids, so a changed date of birth or role updates the same event.
 */

const BIRTHDAY_CALENDAR_DEFAULT_ID = '808a1d4a57343a4c441a423cad1b02f824fcac7929706e7229dd698f38b808e8@group.calendar.google.com';
const BIRTHDAY_CALENDAR_EXCLUDED_ROLE = 'Member';
const BIRTHDAY_CALENDAR_TIMEZONE = 'Europe/London';

function birthday_calendar_id(): string
{
    $env = match_google_calendar_env();
    $calendarId = trim((string) ($env['GOOGLE_BIRTHDAY_CALENDAR_ID'] ?? ''));

    return $calendarId !== '' ? $calendarId : BIRTHDAY_CALENDAR_DEFAULT_ID;
}

/**
 * Stable Google event id for one record. Google accepts only lowercase a-v and 0-9 in event ids.
 */
function birthday_calendar_event_id(string $source, int $recordId): string
{
    return ($source === 'person' ? 'bdpe' : 'bdpl') . $recordId;
}

/**
 * @param list<array<string, mixed>> $birthdays rows from players_all_birthdays()
 * @return array<string, array{source: string, id: int, name: string, role: string, month: int, day: int}>
 */
function birthday_calendar_desired_from_rows(array $birthdays): array
{
    $desired = [];
    foreach ($birthdays as $row) {
        $role = (string) ($row['role_label'] ?? '');
        if (strcasecmp($role, BIRTHDAY_CALENDAR_EXCLUDED_ROLE) === 0) {
            continue;
        }
        if (!preg_match('/^\d{4}-(\d{2})-(\d{2})$/', (string) ($row['date_of_birth'] ?? ''), $parts)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $name = trim((string) ($row['name'] ?? ''));
        if ($id <= 0 || $name === '') {
            continue;
        }

        $source = str_starts_with((string) ($row['href'] ?? ''), '/club_person.php') ? 'person' : 'player';
        $desired[$source . ':' . $id] = [
            'source' => $source,
            'id' => $id,
            'name' => $name,
            'role' => $role,
            'month' => (int) $parts[1],
            'day' => (int) $parts[2],
        ];
    }

    return $desired;
}

/**
 * @return array<string, array{source: string, id: int, name: string, role: string, month: int, day: int}>
 */
function birthday_calendar_desired(PDO $pdo): array
{
    return birthday_calendar_desired_from_rows(players_all_birthdays($pdo));
}

function birthday_calendar_next_occurrence(int $month, int $day, DateTimeImmutable $today): DateTimeImmutable
{
    $year = (int) $today->format('Y');
    for ($candidateYear = $year; $candidateYear <= $year + 8; $candidateYear++) {
        if (!checkdate($month, $day, $candidateYear)) {
            continue; // 29 February only exists in leap years
        }
        $candidate = new DateTimeImmutable(sprintf('%04d-%02d-%02d', $candidateYear, $month, $day), $today->getTimezone());
        if ($candidate >= $today) {
            return $candidate;
        }
    }

    return $today;
}

/**
 * @param array{source: string, id: int, name: string, role: string, month: int, day: int} $item
 * @return array<string, mixed>
 */
function birthday_calendar_event_payload(array $item, DateTimeImmutable $today): array
{
    $date = birthday_calendar_next_occurrence($item['month'], $item['day'], $today)->format('Y-m-d');

    return [
        'summary' => $item['name'] . "'s birthday",
        'description' => 'Club role: ' . $item['role'],
        'start' => ['dateTime' => $date . 'T07:00:00', 'timeZone' => BIRTHDAY_CALENDAR_TIMEZONE],
        'end' => ['dateTime' => $date . 'T07:15:00', 'timeZone' => BIRTHDAY_CALENDAR_TIMEZONE],
        'recurrence' => ['RRULE:FREQ=YEARLY'],
        'transparency' => 'transparent',
        'reminders' => [
            'useDefault' => false,
            'overrides' => [
                ['method' => 'popup', 'minutes' => 1440],
                ['method' => 'popup', 'minutes' => 0],
            ],
        ],
    ];
}

/**
 * @param array<string, mixed>|null $payload
 * @return array{ok: bool, status: int, message: string}
 */
function birthday_calendar_request(string $bearerToken, string $method, string $eventId, ?array $payload = null): array
{
    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode(birthday_calendar_id()) . '/events';
    if ($eventId !== '') {
        $url .= '/' . rawurlencode($eventId);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'message' => 'Unable to initialise the Google Calendar request.'];
    }

    $headers = ['Authorization: Bearer ' . $bearerToken];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json; charset=UTF-8';
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'SaltcoatsVictoriaPlayerSponsors/1.0',
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    $response = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $error === '' && $status >= 200 && $status < 300) {
        return ['ok' => true, 'status' => $status, 'message' => 'Google Calendar request succeeded.'];
    }

    $message = $error !== '' ? $error : 'Google Calendar request failed with HTTP ' . $status;
    $decoded = is_string($response) ? json_decode($response, true) : null;
    if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
        $message = $decoded['error']['message'];
    }

    return ['ok' => false, 'status' => $status, 'message' => $message];
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok: bool, status: int, message: string}
 */
function birthday_calendar_upsert_event(string $bearerToken, string $eventId, array $payload): array
{
    $result = birthday_calendar_request($bearerToken, 'PUT', $eventId, $payload);
    if ($result['status'] === 404) {
        // Not in the calendar yet, so create it under the same fixed id.
        $result = birthday_calendar_request($bearerToken, 'POST', '', $payload + ['id' => $eventId]);
    }

    return $result;
}

/**
 * @return array{ok: bool, status: int, message: string}
 */
function birthday_calendar_delete_event(string $bearerToken, string $eventId): array
{
    $result = birthday_calendar_request($bearerToken, 'DELETE', $eventId);
    // 404 or 410 means the event is already gone, which is the state we want.
    if (in_array($result['status'], [404, 410], true)) {
        return ['ok' => true, 'status' => $result['status'], 'message' => 'Already removed.'];
    }

    return $result;
}

function birthday_calendar_ensure_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS birthday_calendar_sync (
        source VARCHAR(10) NOT NULL,
        record_id INT NOT NULL,
        synced_hash CHAR(40) NOT NULL,
        synced_at DATETIME NOT NULL,
        PRIMARY KEY (source, record_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/**
 * Brings the calendar into line with the Hub. Only events whose details have changed are sent to Google.
 *
 * @return array{ok: bool, message: string, changed: int, errors: list<string>}
 */
function birthday_calendar_sync(PDO $pdo): array
{
    if (!match_google_calendar_is_enabled()) {
        return ['ok' => true, 'message' => 'Google Calendar sync is disabled.', 'changed' => 0, 'errors' => []];
    }

    $bearerToken = match_google_calendar_bearer_token();
    if ($bearerToken === '') {
        return ['ok' => false, 'message' => 'Google Calendar authentication is not configured.', 'changed' => 0, 'errors' => []];
    }

    birthday_calendar_ensure_table($pdo);
    $today = new DateTimeImmutable('today', new DateTimeZone(BIRTHDAY_CALENDAR_TIMEZONE));
    $desired = birthday_calendar_desired($pdo);

    $synced = [];
    foreach ($pdo->query('SELECT source, record_id, synced_hash FROM birthday_calendar_sync')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $synced[$row['source'] . ':' . $row['record_id']] = $row['synced_hash'];
    }

    $remember = $pdo->prepare('INSERT INTO birthday_calendar_sync (source, record_id, synced_hash, synced_at) VALUES (:source, :id, :hash, NOW()) ON DUPLICATE KEY UPDATE synced_hash = VALUES(synced_hash), synced_at = NOW()');
    $forget = $pdo->prepare('DELETE FROM birthday_calendar_sync WHERE source = :source AND record_id = :id');

    $changed = 0;
    $errors = [];
    foreach ($desired as $key => $item) {
        $payload = birthday_calendar_event_payload($item, $today);
        $hash = sha1((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        if (($synced[$key] ?? null) === $hash) {
            continue;
        }

        $result = birthday_calendar_upsert_event($bearerToken, birthday_calendar_event_id($item['source'], $item['id']), $payload);
        if (!$result['ok']) {
            $errors[] = $item['name'] . ': ' . $result['message'];
            continue;
        }
        $remember->execute([':source' => $item['source'], ':id' => $item['id'], ':hash' => $hash]);
        $changed++;
    }

    // Anyone no longer listed (Member, archived, left, or no date of birth) loses their event.
    foreach (array_keys($synced) as $key) {
        if (isset($desired[$key])) {
            continue;
        }
        [$source, $id] = explode(':', (string) $key);
        $result = birthday_calendar_delete_event($bearerToken, birthday_calendar_event_id($source, (int) $id));
        if (!$result['ok']) {
            $errors[] = $key . ': ' . $result['message'];
            continue;
        }
        $forget->execute([':source' => $source, ':id' => (int) $id]);
        $changed++;
    }

    return [
        'ok' => $errors === [],
        'message' => $errors === [] ? 'Birthday calendar is up to date.' : 'Some birthdays could not be synced.',
        'changed' => $changed,
        'errors' => $errors,
    ];
}

/**
 * Runs the sync after the response has been sent, so a save never waits on Google.
 * Several saves in one request share a single sync.
 */
function birthday_calendar_queue_sync(PDO $pdo): void
{
    static $queued = false;
    if ($queued) {
        return;
    }
    $queued = true;

    register_shutdown_function(static function () use ($pdo): void {
        if ($pdo->inTransaction()) {
            // The write has not committed. The next save will bring the calendar into line.
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            foreach (birthday_calendar_sync($pdo)['errors'] as $error) {
                error_log('Birthday calendar sync: ' . $error);
            }
        } catch (Throwable $e) {
            error_log('Birthday calendar sync failed: ' . $e->getMessage());
        }
    });
}
