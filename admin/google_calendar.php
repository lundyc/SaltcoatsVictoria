<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

const GOOGLE_CALENDAR_DEFAULT_TIMEZONE = 'Europe/London';
const GOOGLE_CALENDAR_DEFAULT_DURATION_MINUTES = 120;

/**
 * @return array<string, string>
 */
function matches_google_calendar_env(): array
{
    $fileEnv = array_merge(
        app_parse_env_file(__DIR__ . '/.env'),
        app_parse_env_file(__DIR__ . '/../.env')
    );
    $runtimeEnv = [
        'GOOGLE_CALENDAR_ID' => getenv('GOOGLE_CALENDAR_ID') !== false ? (string) getenv('GOOGLE_CALENDAR_ID') : '',
        'GOOGLE_CALENDAR_CLIENT_ID' => getenv('GOOGLE_CALENDAR_CLIENT_ID') !== false ? (string) getenv('GOOGLE_CALENDAR_CLIENT_ID') : '',
        'GOOGLE_CALENDAR_CLIENT_SECRET' => getenv('GOOGLE_CALENDAR_CLIENT_SECRET') !== false ? (string) getenv('GOOGLE_CALENDAR_CLIENT_SECRET') : '',
        'GOOGLE_CALENDAR_REFRESH_TOKEN' => getenv('GOOGLE_CALENDAR_REFRESH_TOKEN') !== false ? (string) getenv('GOOGLE_CALENDAR_REFRESH_TOKEN') : '',
        'GOOGLE_CALENDAR_ACCESS_TOKEN' => getenv('GOOGLE_CALENDAR_ACCESS_TOKEN') !== false ? (string) getenv('GOOGLE_CALENDAR_ACCESS_TOKEN') : '',
        'GOOGLE_CALENDAR_TIMEZONE' => getenv('GOOGLE_CALENDAR_TIMEZONE') !== false ? (string) getenv('GOOGLE_CALENDAR_TIMEZONE') : '',
        'GOOGLE_CALENDAR_SYNC_ENABLED' => getenv('GOOGLE_CALENDAR_SYNC_ENABLED') !== false ? (string) getenv('GOOGLE_CALENDAR_SYNC_ENABLED') : '',
        'GOOGLE_CALENDAR_EVENT_DURATION_MINUTES' => getenv('GOOGLE_CALENDAR_EVENT_DURATION_MINUTES') !== false ? (string) getenv('GOOGLE_CALENDAR_EVENT_DURATION_MINUTES') : '',
    ];

    return array_merge($fileEnv, array_filter($runtimeEnv, static fn(string $value): bool => $value !== ''));
}

function matches_google_calendar_is_enabled(): bool
{
    $env = matches_google_calendar_env();
    $flag = strtolower(trim((string) ($env['GOOGLE_CALENDAR_SYNC_ENABLED'] ?? 'true')));

    return !in_array($flag, ['0', 'false', 'no', 'off'], true);
}

function matches_google_calendar_id(): string
{
    $env = matches_google_calendar_env();

    return trim((string) ($env['GOOGLE_CALENDAR_ID'] ?? ''));
}

function matches_google_calendar_timezone(): string
{
    $env = matches_google_calendar_env();
    $timezone = trim((string) ($env['GOOGLE_CALENDAR_TIMEZONE'] ?? ''));

    return $timezone !== '' ? $timezone : GOOGLE_CALENDAR_DEFAULT_TIMEZONE;
}

function matches_google_calendar_event_duration_minutes(): int
{
    $env = matches_google_calendar_env();
    $duration = (int) ($env['GOOGLE_CALENDAR_EVENT_DURATION_MINUTES'] ?? GOOGLE_CALENDAR_DEFAULT_DURATION_MINUTES);

    return $duration > 0 ? $duration : GOOGLE_CALENDAR_DEFAULT_DURATION_MINUTES;
}

function matches_google_calendar_bearer_token(): string
{
    $env = matches_google_calendar_env();
    $accessToken = trim((string) ($env['GOOGLE_CALENDAR_ACCESS_TOKEN'] ?? ''));
    if ($accessToken !== '') {
        return $accessToken;
    }

    $clientId = trim((string) ($env['GOOGLE_CALENDAR_CLIENT_ID'] ?? ''));
    $clientSecret = trim((string) ($env['GOOGLE_CALENDAR_CLIENT_SECRET'] ?? ''));
    $refreshToken = trim((string) ($env['GOOGLE_CALENDAR_REFRESH_TOKEN'] ?? ''));
    if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
        return '';
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    if ($ch === false) {
        return '';
    }

    $postFields = http_build_query([
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ], '', '&', PHP_QUERY_RFC3986);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'SaltcoatsVictoriaHub/1.0',
    ]);

    $response = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpStatus < 200 || $httpStatus >= 300) {
        return '';
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return '';
    }

    return trim((string) ($decoded['access_token'] ?? ''));
}

function matches_google_calendar_request(string $method, string $path, ?array $payload = null, array $query = [], ?string $bearerToken = null): array
{
    $calendarId = matches_google_calendar_id();
    if ($calendarId === '') {
        return [
            'ok' => false,
            'message' => 'Google Calendar id is not configured.',
        ];
    }

    $bearerToken = $bearerToken !== null ? trim($bearerToken) : matches_google_calendar_bearer_token();
    if ($bearerToken === '') {
        return [
            'ok' => false,
            'message' => 'Google Calendar authentication is not configured.',
        ];
    }

    $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/' . ltrim($path, '/');
    if ($query !== []) {
        $pairs = [];
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $item);
                }
                continue;
            }

            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
        }
        $url .= '?' . implode('&', $pairs);
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'ok' => false,
            'message' => 'Unable to initialise the Google Calendar request.',
        ];
    }

    $headers = [
        'Authorization: Bearer ' . $bearerToken,
    ];
    $method = strtoupper($method);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'SaltcoatsVictoriaHub/1.0',
    ];

    if ($method === 'GET') {
        $options[CURLOPT_HTTPGET] = true;
    } else {
        $options[CURLOPT_CUSTOMREQUEST] = $method;
        $headers[] = 'Content-Type: application/json; charset=UTF-8';
        $options[CURLOPT_HTTPHEADER] = $headers;
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }
    }

    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $error !== '' || $httpStatus < 200 || $httpStatus >= 300) {
        $message = $error !== '' ? $error : ('Google Calendar request failed with HTTP ' . $httpStatus);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
            $message = $decoded['error']['message'];
        }

        return [
            'ok' => false,
            'message' => $message,
            'status' => $httpStatus,
            'body' => is_string($response) ? $response : '',
        ];
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        $decoded = [];
    }

    return [
        'ok' => true,
        'message' => 'Google Calendar request succeeded.',
        'status' => $httpStatus,
        'body' => $decoded,
    ];
}

function matches_google_calendar_match_summary(array $match): string
{
    $opponent = trim((string) ($match['opponent'] ?? ''));
    $isHome = matches_normalize_venue((string) ($match['venue'] ?? 'H')) === 'H';
    $prefix = $isHome ? 'vs' : 'at';

    return 'Saltcoats Victoria ' . $prefix . ' ' . ($opponent !== '' ? $opponent : 'TBC');
}

function matches_google_calendar_match_description(array $match): string
{
    $parts = [
        'Fixture synced from the Hub.',
    ];

    $competition = trim((string) ($match['competition'] ?? ''));
    if ($competition !== '') {
        $parts[] = 'Competition: ' . $competition;
    }

    $venueName = trim((string) ($match['venue_name'] ?? ''));
    if ($venueName !== '') {
        $parts[] = 'Venue: ' . $venueName;
    }

    $matchId = trim((string) ($match['id'] ?? ''));
    if ($matchId !== '') {
        $parts[] = 'Hub match id: ' . $matchId;
    }

    return implode("\n", $parts);
}

function matches_google_calendar_match_payload(array $match): array
{
    $timezone = matches_google_calendar_timezone();
    $kickoffTime = trim((string) ($match['kickoff_time'] ?? ''));
    $matchDate = trim((string) ($match['match_date'] ?? ''));
    $durationMinutes = matches_google_calendar_event_duration_minutes();

    $payload = [
        'summary' => matches_google_calendar_match_summary($match),
        'description' => matches_google_calendar_match_description($match),
        'location' => trim((string) ($match['venue_name'] ?? '')),
        'extendedProperties' => [
            'private' => [
                'hubMatchId' => trim((string) ($match['id'] ?? '')),
            ],
        ],
    ];

    if ($matchDate === '') {
        return $payload;
    }

    try {
        $date = new DateTimeImmutable($matchDate . ' ' . ($kickoffTime !== '' ? $kickoffTime : '00:00'), new DateTimeZone($timezone));
    } catch (Throwable) {
        return $payload;
    }

    if ($kickoffTime === '') {
        $payload['start'] = ['date' => $date->format('Y-m-d')];
        $payload['end'] = ['date' => $date->modify('+1 day')->format('Y-m-d')];

        return $payload;
    }

    $payload['start'] = [
        'dateTime' => $date->format(DateTimeInterface::RFC3339),
        'timeZone' => $timezone,
    ];
    $payload['end'] = [
        'dateTime' => $date->modify('+' . $durationMinutes . ' minutes')->format(DateTimeInterface::RFC3339),
        'timeZone' => $timezone,
    ];

    return $payload;
}

function matches_google_calendar_find_event_id(string $matchId): string
{
    $matchId = trim($matchId);
    if ($matchId === '') {
        return '';
    }

    $bearerToken = matches_google_calendar_bearer_token();
    $result = matches_google_calendar_request('GET', 'events', null, [
        'privateExtendedProperty' => ['hubMatchId=' . $matchId],
        'maxResults' => 1,
        'singleEvents' => 'false',
        'showDeleted' => 'false',
        'orderBy' => 'updated',
    ], $bearerToken);

    if (!$result['ok']) {
        return '';
    }

    $items = is_array($result['body'] ?? null) ? ($result['body']['items'] ?? []) : [];
    if (!is_array($items) || $items === []) {
        return '';
    }

    $event = $items[0];
    if (!is_array($event)) {
        return '';
    }

    return trim((string) ($event['id'] ?? ''));
}

/**
 * @return array{ok: bool, message: string, event_id?: string, skipped?: bool}
 */
function matches_google_calendar_sync_fixture(array $match, string $operation = 'upsert'): array
{
    if (!matches_google_calendar_is_enabled()) {
        return [
            'ok' => true,
            'message' => 'Google Calendar sync is disabled.',
            'skipped' => true,
        ];
    }

    if (matches_google_calendar_id() === '' || matches_google_calendar_bearer_token() === '') {
        return [
            'ok' => true,
            'message' => 'Google Calendar sync is not configured.',
            'skipped' => true,
        ];
    }

    $bearerToken = matches_google_calendar_bearer_token();

    $payload = matches_google_calendar_match_payload($match);
    $matchId = trim((string) ($match['id'] ?? ''));
    if ($matchId === '') {
        return [
            'ok' => false,
            'message' => 'Match id is required for Google Calendar sync.',
        ];
    }

    $existingEventId = trim((string) ($match['google_calendar_event_id'] ?? ''));
    if ($existingEventId === '') {
        $existingEventId = matches_google_calendar_find_event_id($matchId);
    }

    if ($operation === 'delete') {
        if ($existingEventId === '') {
            return [
                'ok' => true,
                'message' => 'No Google Calendar event needed removal.',
                'skipped' => true,
            ];
        }

        $result = matches_google_calendar_request('DELETE', 'events/' . rawurlencode($existingEventId), null, [], $bearerToken);
        if (!$result['ok']) {
            return $result;
        }

        return [
            'ok' => true,
            'message' => 'Google Calendar event removed.',
            'event_id' => $existingEventId,
        ];
    }

    if ($existingEventId !== '') {
        $result = matches_google_calendar_request('PATCH', 'events/' . rawurlencode($existingEventId), $payload, [], $bearerToken);
        if (!$result['ok']) {
            return $result;
        }

        return [
            'ok' => true,
            'message' => 'Google Calendar event updated.',
            'event_id' => $existingEventId,
        ];
    }

    $result = matches_google_calendar_request('POST', 'events', $payload, [], $bearerToken);
    if (!$result['ok']) {
        return $result;
    }

    $body = $result['body'] ?? [];
    $eventId = is_array($body) ? trim((string) ($body['id'] ?? '')) : '';

    return [
        'ok' => true,
        'message' => 'Google Calendar event created.',
        'event_id' => $eventId,
    ];
}
