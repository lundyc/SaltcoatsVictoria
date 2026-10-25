<?php

declare(strict_types=1);

require_once __DIR__ . '/../env.php';

const MATCH_GOOGLE_CALENDAR_DEFAULT_TIMEZONE = 'Europe/London';
const MATCH_GOOGLE_CALENDAR_DEFAULT_DURATION_MINUTES = 120;

/**
 * @return array<string, string>
 */
function match_google_calendar_env(): array
{
          $fileEnv = app_parse_env_file(__DIR__ . '/../.env');
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

function match_google_calendar_is_enabled(): bool
{
          $env = match_google_calendar_env();
          $flag = strtolower(trim((string) ($env['GOOGLE_CALENDAR_SYNC_ENABLED'] ?? 'true')));

          return !in_array($flag, ['0', 'false', 'no', 'off'], true);
}

function match_google_calendar_id(): string
{
          $env = match_google_calendar_env();

          return trim((string) ($env['GOOGLE_CALENDAR_ID'] ?? ''));
}

function match_google_calendar_timezone(): string
{
          $env = match_google_calendar_env();
          $timezone = trim((string) ($env['GOOGLE_CALENDAR_TIMEZONE'] ?? ''));

          return $timezone !== '' ? $timezone : MATCH_GOOGLE_CALENDAR_DEFAULT_TIMEZONE;
}

function match_google_calendar_event_duration_minutes(): int
{
          $env = match_google_calendar_env();
          $duration = (int) ($env['GOOGLE_CALENDAR_EVENT_DURATION_MINUTES'] ?? MATCH_GOOGLE_CALENDAR_DEFAULT_DURATION_MINUTES);

          return $duration > 0 ? $duration : MATCH_GOOGLE_CALENDAR_DEFAULT_DURATION_MINUTES;
}

function match_google_calendar_bearer_token(): string
{
          static $cached = null;
          if ($cached !== null && $cached !== '') {
                    return $cached;
          }

          $env = match_google_calendar_env();
          // A stored access token only lasts an hour, so prefer the refresh token when there is one.
          $accessToken = trim((string) ($env['GOOGLE_CALENDAR_ACCESS_TOKEN'] ?? ''));
          if ($accessToken !== '' && trim((string) ($env['GOOGLE_CALENDAR_REFRESH_TOKEN'] ?? '')) === '') {
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

          curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => http_build_query([
                              'client_id' => $clientId,
                              'client_secret' => $clientSecret,
                              'refresh_token' => $refreshToken,
                              'grant_type' => 'refresh_token',
                    ], '', '&', PHP_QUERY_RFC3986),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => [
                              'Content-Type: application/x-www-form-urlencoded',
                    ],
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_USERAGENT => 'SaltcoatsVictoriaPlayerSponsors/1.0',
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

          return $cached = trim((string) ($decoded['access_token'] ?? ''));
}

function match_google_calendar_request(string $method, string $path, ?array $payload = null, array $query = [], ?string $bearerToken = null): array
{
          $calendarId = match_google_calendar_id();
          if ($calendarId === '') {
                    return [
                              'ok' => false,
                              'message' => 'Google Calendar id is not configured.',
                    ];
          }

          $bearerToken = $bearerToken !== null ? trim($bearerToken) : match_google_calendar_bearer_token();
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
          $options = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_USERAGENT => 'SaltcoatsVictoriaPlayerSponsors/1.0',
          ];

          $method = strtoupper($method);
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

function match_google_calendar_fixture_label(array $fixture): string
{
          $opponent = trim((string) ($fixture['opponent'] ?? ''));
          $isHome = (int) ($fixture['is_home'] ?? 1) === 1;

          return ($isHome ? 'vs ' : 'at ') . ($opponent !== '' ? $opponent : 'TBC');
}

/**
 * Full ground address for Google Maps, e.g. "Campbell Park, Blakely Road, Saltcoats, KA21 5JQ".
 * Falls back to the plain venue name when the ground is not in match_venues.
 */
function match_google_calendar_fixture_location(?PDO $pdo, array $fixture): string
{
          $venue = trim((string) ($fixture['venue'] ?? ''));
          if ($venue === '' || $pdo === null) {
                    return $venue;
          }

          try {
                    $stmt = $pdo->prepare("SELECT name, club_name, address_line1, town, postcode FROM match_venues WHERE name = :n OR name LIKE :p OR address_line1 LIKE :p OR name LIKE :w ORDER BY (name = :n) DESC, (postcode IS NOT NULL AND postcode <> '') DESC");
                    $stmt->execute([':n' => $venue, ':p' => $venue . '%', ':w' => (preg_split('/\s+/', $venue)[0] ?: $venue) . ' %']);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
          } catch (Throwable) {
                    return $venue;
          }

          $rows = array_values(array_filter($rows, fn($r) => trim((string) ($r['postcode'] ?? '')) !== ''));
          if ($rows === []) {
                    return $venue;
          }

          $row = $rows[0];
          if (count($rows) > 1) {
                    // Same ground name at several clubs (e.g. Victoria Park): match on who is playing.
                    $who = strtolower(((int) ($fixture['is_home'] ?? 1) === 1 ? 'saltcoats victoria' : (string) ($fixture['opponent'] ?? '')));
                    foreach ($rows as $r) {
                              $club = strtolower(trim((string) ($r['club_name'] ?? '')));
                              $club = trim(preg_replace('/\s+(fc|afc|f\.c\.)$/', '', $club));
                              if ($club !== '' && $who !== '' && (str_contains($who, $club) || str_contains($club, $who))) {
                                        $row = $r;
                                        break;
                              }
                    }
          }

          $parts = [];
          foreach (['name', 'address_line1', 'town', 'postcode'] as $k) {
                    $v = trim((string) ($row[$k] ?? ''));
                    if ($v !== '' && !in_array(strtolower($v), array_map('strtolower', $parts), true)) {
                              $parts[] = $v;
                    }
          }

          return implode(', ', $parts);
}

function match_google_calendar_fixture_description(array $fixture, string $location = ''): string
{
          $opponent = trim((string) ($fixture['opponent'] ?? '')) ?: 'TBC';
          $isHome = (int) ($fixture['is_home'] ?? 1) === 1;
          $competition = trim((string) ($fixture['competition'] ?? ''));
          $where = $location !== '' ? $location : trim((string) ($fixture['venue'] ?? ''));

          $when = '';
          $matchDate = trim((string) ($fixture['match_date'] ?? ''));
          $kickoff = trim((string) ($fixture['kickoff_time'] ?? ''));
          if ($matchDate !== '') {
                    try {
                              $d = new DateTimeImmutable($matchDate . ' ' . ($kickoff !== '' ? substr($kickoff, 0, 5) : '00:00'));
                              $when = $d->format('l j F Y');
                              if ($kickoff !== '') {
                                        $when .= ' at ' . strtolower($d->format('g:ia'));
                              }
                    } catch (Throwable) {
                    }
          }

          $lines = [];
          $lines[] = $isHome
                    ? "Home game: Saltcoats Victoria host {$opponent}" . ($where !== '' ? " at {$where}." : '.')
                    : "Away day: Saltcoats Victoria travel to face {$opponent}" . ($where !== '' ? " at {$where}." : '.');
          if ($when !== '') {
                    $lines[] = ($kickoff !== '' ? 'Kick-off is ' : 'The match is on ') . $when . '.';
          }
          if ($competition !== '') {
                    $lines[] = "This one counts towards the {$competition}.";
          }
          $lines[] = $isHome ? 'Come along to Campbell Park and get behind the Vics!' : 'Plenty of support travelling would be great. Come on the Vics!';

          $fixtureId = trim((string) ($fixture['id'] ?? ''));
          $lines[] = 'Added automatically by the Saltcoats Victoria Hub' . ($fixtureId !== '' ? " (fixture {$fixtureId})" : '') . '. Changes made in the Hub update this event.';

          return implode("\n\n", $lines);
}

function match_google_calendar_fixture_payload(array $fixture, ?PDO $pdo = null): array
{
          $timezone = match_google_calendar_timezone();
          $kickoffTime = trim((string) ($fixture['kickoff_time'] ?? ''));
          $matchDate = trim((string) ($fixture['match_date'] ?? ''));
          $durationMinutes = match_google_calendar_event_duration_minutes();
          $location = match_google_calendar_fixture_location($pdo, $fixture);

          $payload = [
                    'summary' => match_google_calendar_fixture_label($fixture),
                    'description' => match_google_calendar_fixture_description($fixture, $location),
                    'location' => $location,
                    // Home = Tomato (red), away = Blueberry (blue).
                    'colorId' => (int) ($fixture['is_home'] ?? 1) === 1 ? '11' : '9',
                    'reminders' => [
                              'useDefault' => false,
                              'overrides' => [['method' => 'popup', 'minutes' => 1440]],
                    ],
                    'extendedProperties' => [
                              'private' => [
                                        'hubFixtureId' => trim((string) ($fixture['id'] ?? '')),
                              ],
                    ],
          ];

          if ($matchDate === '') {
                    return $payload;
          }

          try {
                    $date = new DateTimeImmutable($matchDate . ' ' . ($kickoffTime !== '' ? substr($kickoffTime, 0, 5) : '00:00'), new DateTimeZone($timezone));
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

function match_google_calendar_find_event_id(PDO $pdo, int $fixtureId): string
{
          $fixtureId = (int) $fixtureId;
          if ($fixtureId <= 0) {
                    return '';
          }

          $stmt = $pdo->prepare('SELECT google_calendar_event_id FROM match_fixtures WHERE id = :id LIMIT 1');
          $stmt->execute([':id' => $fixtureId]);
          $storedEventId = trim((string) $stmt->fetchColumn());
          if ($storedEventId !== '') {
                    return $storedEventId;
          }

          $bearerToken = match_google_calendar_bearer_token();
          $result = match_google_calendar_request('GET', 'events', null, [
                    'privateExtendedProperty' => ['hubFixtureId=' . $fixtureId],
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
function match_google_calendar_is_current_season(PDO $pdo, int $seasonId): bool
{
          if ($seasonId <= 0) {
                    return false;
          }
          $stmt = $pdo->prepare('SELECT is_current FROM seasons WHERE id = ? LIMIT 1');
          $stmt->execute([$seasonId]);
          return (int) $stmt->fetchColumn() === 1;
}

function match_google_calendar_sync_fixture(PDO $pdo, array $fixture, string $operation = 'upsert'): array
{
          if (!match_google_calendar_is_enabled()) {
                    return [
                              'ok' => true,
                              'message' => 'Google Calendar sync is disabled.',
                              'skipped' => true,
                    ];
          }

          $calendarId = match_google_calendar_id();
          $bearerToken = match_google_calendar_bearer_token();
          if ($calendarId === '' || $bearerToken === '') {
                    return [
                              'ok' => true,
                              'message' => 'Google Calendar sync is not configured.',
                              'skipped' => true,
                    ];
          }

          $fixtureId = (int) ($fixture['id'] ?? 0);
          if ($fixtureId <= 0) {
                    return [
                              'ok' => false,
                              'message' => 'Fixture id is required for Google Calendar sync.',
                    ];
          }

          // Only the current season goes on the club calendar; older seasons are left alone.
          if ($operation !== 'delete' && !match_google_calendar_is_current_season($pdo, (int) ($fixture['season_id'] ?? 0))) {
                    return [
                              'ok' => true,
                              'message' => 'Not in the current season, so not added to Google Calendar.',
                              'skipped' => true,
                    ];
          }

          $existingEventId = trim((string) ($fixture['google_calendar_event_id'] ?? ''));
          if ($existingEventId === '') {
                    $existingEventId = match_google_calendar_find_event_id($pdo, $fixtureId);
          }

          if ($operation === 'delete') {
                    if ($existingEventId === '') {
                              return [
                                        'ok' => true,
                                        'message' => 'No Google Calendar event needed removal.',
                                        'skipped' => true,
                              ];
                    }

                    $result = match_google_calendar_request('DELETE', 'events/' . rawurlencode($existingEventId), null, [], $bearerToken);
                    if (!$result['ok']) {
                              return $result;
                    }

                    return [
                              'ok' => true,
                              'message' => 'Google Calendar event removed.',
                              'event_id' => $existingEventId,
                    ];
          }

          $payload = match_google_calendar_fixture_payload($fixture, $pdo);
          if ($existingEventId !== '') {
                    $result = match_google_calendar_request('PATCH', 'events/' . rawurlencode($existingEventId), $payload, [], $bearerToken);
                    if (!$result['ok']) {
                              return $result;
                    }
          } else {
                    $result = match_google_calendar_request('POST', 'events', $payload, [], $bearerToken);
                    if (!$result['ok']) {
                              return $result;
                    }

                    $body = $result['body'] ?? [];
                    $existingEventId = is_array($body) ? trim((string) ($body['id'] ?? '')) : '';
          }

          if ($existingEventId !== '') {
                    $stmt = $pdo->prepare("
                              UPDATE match_fixtures
                              SET google_calendar_event_id = :google_calendar_event_id,
                                  google_calendar_synced_at = NOW()
                              WHERE id = :id
                              LIMIT 1
                    ");
                    $stmt->execute([
                              ':google_calendar_event_id' => $existingEventId,
                              ':id' => $fixtureId,
                    ]);
          }

          return [
                    'ok' => true,
                    'message' => $existingEventId !== '' ? 'Google Calendar event synced.' : 'Google Calendar event synced.',
                    'event_id' => $existingEventId,
          ];
}
