<?php
declare(strict_types=1);

/**
 * @param array<int, array<string, mixed>> $events
 * @return array<int, array<string, mixed>>
 */
function matchOverviewSortedEvents(array $events): array
{
          usort($events, static function (array $left, array $right): int {
                    $sequenceComparison = (int)($left['sequence'] ?? 0) <=> (int)($right['sequence'] ?? 0);
                    if ($sequenceComparison !== 0) {
                              return $sequenceComparison;
                    }
                    return (int)preg_replace('/\D.*$/', '', (string)($left['minute'] ?? '0'))
                              <=> (int)preg_replace('/\D.*$/', '', (string)($right['minute'] ?? '0'));
          });
          return $events;
}

function matchOverviewIsFinished(array $fixture, array $events): bool
{
          if (in_array(strtolower(trim((string)($fixture['status'] ?? ''))), ['played', 'finished', 'complete', 'completed'], true)) {
                    return true;
          }
          if ($fixture['full_time_home_score'] !== null && $fixture['full_time_away_score'] !== null) {
                    return true;
          }
          foreach ($events as $event) {
                    if ((string)($event['type'] ?? '') === 'full_time') {
                              return true;
                    }
          }
          return false;
}

function matchOverviewEventTitle(array $event, string $opponent): string
{
          $type = (string)($event['type'] ?? '');
          $team = (string)($event['team'] ?? '');
          $player = trim((string)($event['player'] ?? ''));
          $secondary = trim((string)($event['secondary_player'] ?? ''));
          $note = trim((string)($event['note'] ?? ''));
          $teamName = $team === 'opponent' ? $opponent : 'Saltcoats Victoria';
          $knownPlayer = $player !== '' && strcasecmp($player, 'Unknown Player') !== 0;

          if ($type === 'goal') {
                    if (!empty($event['own_goal'])) {
                              return 'Own goal for ' . $teamName . ($knownPlayer ? ' — ' . $player : '');
                    }
                    return 'Goal for ' . $teamName . ($knownPlayer ? ' — ' . $player : '');
          }
          if ($type === 'substitution') {
                    $changes = isset($event['substitutions']) && is_array($event['substitutions'])
                              ? $event['substitutions']
                              : [];
                    if (count($changes) > 1) {
                              return count($changes) . ' Saltcoats Victoria substitutions';
                    }
                    return 'Substitution' . ($player !== '' ? ' — ' . $player . ' off' : '')
                              . ($secondary !== '' ? ', ' . $secondary . ' on' : '');
          }
          if (in_array($type, ['yellow_card', 'red_card'], true)) {
                    return ($type === 'yellow_card' ? 'Yellow card' : 'Red card')
                              . ($knownPlayer ? ' — ' . $player : '')
                              . ($team !== 'match' ? ' (' . $teamName . ')' : '');
          }
          if ($type === 'card') {
                    return ucfirst((string)($event['card_type'] ?? '')) . ' card'
                              . ($knownPlayer ? ' — ' . $player : '');
          }
          if ($note !== '') {
                    return $note;
          }

          $labels = [
                    'kickoff' => 'Kick-off',
                    'half_time' => 'Half-time',
                    'second_half' => 'Second half',
                    'full_time' => 'Full-time',
                    'shot' => 'Shot',
                    'chance' => 'Chance',
                    'corner' => 'Corner',
                    'free_kick' => 'Free kick',
                    'penalty' => 'Penalty',
                    'off_side' => 'Offside',
                    'mistake' => 'Mistake',
                    'good_play' => 'Good play',
                    'highlight' => 'Highlight',
                    'note' => 'Match note',
          ];
          return ($labels[$type] ?? ucwords(str_replace('_', ' ', $type ?: 'Match event')))
                    . ($knownPlayer ? ' — ' . $player : '');
}

/**
 * @param array<int, array<string, mixed>> $events
 */
function matchOverviewNarrative(array $fixture, array $events): string
{
          $opponent = trim((string)($fixture['opponent'] ?? 'the opposition')) ?: 'the opposition';
          $isHome = (int)($fixture['is_home'] ?? 1) === 1;
          $homeScore = $fixture['full_time_home_score'] ?? null;
          $awayScore = $fixture['full_time_away_score'] ?? null;
          $venue = trim((string)($fixture['venue'] ?? ''));

          if (!matchOverviewIsFinished($fixture, $events)) {
                    $date = trim((string)($fixture['match_date'] ?? ''));
                    $kickoff = trim((string)($fixture['kickoff_time'] ?? ''));
                    $when = $date !== '' ? date('l j F Y', strtotime($date)) : 'the scheduled date';
                    if ($kickoff !== '') {
                              $when .= ' at ' . date('H:i', strtotime($kickoff));
                    }
                    return 'Saltcoats Victoria ' . ($isHome ? 'host ' : 'travel to face ') . $opponent
                              . ' on ' . $when . ($venue !== '' ? ' at ' . $venue : '') . '.';
          }

          if ($homeScore !== null && $awayScore !== null) {
                    $svfcScore = (int)($isHome ? $homeScore : $awayScore);
                    $opponentScore = (int)($isHome ? $awayScore : $homeScore);
                    if ($svfcScore > $opponentScore) {
                              $opening = 'Saltcoats Victoria beat ' . $opponent . ' ' . $svfcScore . '–' . $opponentScore;
                    } elseif ($svfcScore < $opponentScore) {
                              $opening = 'Saltcoats Victoria were beaten ' . $opponentScore . '–' . $svfcScore . ' by ' . $opponent;
                    } else {
                              $opening = 'Saltcoats Victoria drew ' . $svfcScore . '–' . $opponentScore . ' with ' . $opponent;
                    }
                    $opening .= $venue !== '' ? ' at ' . $venue . '.' : '.';
          } else {
                    $opening = 'The match against ' . $opponent . ' has finished.';
          }

          $goalDetails = [];
          foreach ($events as $event) {
                    if ((string)($event['type'] ?? '') !== 'goal') {
                              continue;
                    }
                    $minute = trim((string)($event['minute'] ?? ''));
                    $player = trim((string)($event['player'] ?? ''));
                    $side = (string)($event['team'] ?? '') === 'opponent' ? $opponent : 'Saltcoats Victoria';
                    $detail = $side;
                    if ($player !== '' && strcasecmp($player, 'Unknown Player') !== 0) {
                              $detail .= ' — ' . $player;
                    }
                    if ($minute !== '') {
                              $detail .= ' (' . $minute . '\')';
                    }
                    $goalDetails[] = $detail;
          }
          if ($goalDetails !== []) {
                    $opening .= ' Logged goals: ' . implode(', ', $goalDetails) . '.';
          } elseif ($events === []) {
                    $opening .= ' No match events have been logged yet.';
          }
          return $opening;
}

/**
 * @param array<int, array<string, mixed>> $events
 * @return array<string, int>
 */
function matchOverviewEventStats(array $events): array
{
          $stats = ['goals' => 0, 'shots' => 0, 'chances' => 0, 'cards' => 0, 'substitutions' => 0];
          foreach ($events as $event) {
                    $type = (string)($event['type'] ?? '');
                    if ($type === 'goal') {
                              $stats['goals']++;
                    } elseif ($type === 'shot') {
                              $stats['shots']++;
                    } elseif ($type === 'chance') {
                              $stats['chances']++;
                    } elseif (in_array($type, ['card', 'yellow_card', 'red_card'], true)) {
                              $stats['cards']++;
                    } elseif ($type === 'substitution') {
                              $changes = isset($event['substitutions']) && is_array($event['substitutions'])
                                        ? count($event['substitutions'])
                                        : 0;
                              $stats['substitutions'] += max(1, $changes);
                    }
          }
          return $stats;
}

function matchOverviewWeatherDescription(int $code): string
{
          if ($code === 0) return 'Clear';
          if (in_array($code, [1, 2], true)) return 'Partly cloudy';
          if ($code === 3) return 'Overcast';
          if (in_array($code, [45, 48], true)) return 'Foggy';
          if (in_array($code, [51, 53, 55, 56, 57], true)) return 'Drizzle';
          if (in_array($code, [61, 63, 65, 66, 67, 80, 81, 82], true)) return 'Rain';
          if (in_array($code, [71, 73, 75, 77, 85, 86], true)) return 'Snow';
          if (in_array($code, [95, 96, 99], true)) return 'Thunderstorms';
          return 'Mixed conditions';
}

function matchOverviewFetchJson(string $url): ?array
{
          $cacheFile = sys_get_temp_dir() . '/lundy-match-weather-' . hash('sha256', $url) . '.json';
          if (is_file($cacheFile) && (time() - (int)filemtime($cacheFile)) < 21600) {
                    $cached = json_decode((string)file_get_contents($cacheFile), true);
                    if (is_array($cached)) {
                              return $cached;
                    }
          }

          $ch = curl_init($url);
          if ($ch === false) {
                    return null;
          }
          curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: LundyMatchHub/1.0'],
          ]);
          $body = curl_exec($ch);
          $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
          curl_close($ch);
          if (!is_string($body) || $status < 200 || $status >= 300) {
                    return null;
          }
          $decoded = json_decode($body, true);
          if (!is_array($decoded)) {
                    return null;
          }
          @file_put_contents($cacheFile, $body, LOCK_EX);
          return $decoded;
}

function matchOverviewWeather(PDO $pdo, array $fixture): array
{
          $venueName = trim((string)($fixture['venue'] ?? ''));
          $venue = null;
          if ((int)($fixture['is_home'] ?? 1) === 0 && (int)($fixture['opponent_id'] ?? 0) > 0) {
                    $stmt = $pdo->prepare("
                              SELECT v.*
                              FROM match_opponents o
                              INNER JOIN match_venues v ON v.id = o.venue_id
                              WHERE o.id = :opponent_id
                              LIMIT 1
                    ");
                    $stmt->execute([':opponent_id' => (int)$fixture['opponent_id']]);
                    $venue = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
          }
          if ($venueName !== '') {
                    if (!$venue) {
                              $stmt = $pdo->prepare("
                                        SELECT *
                                        FROM match_venues
                                        WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))
                                        ORDER BY
                                                  CASE WHEN postcode IS NOT NULL AND TRIM(postcode) <> '' THEN 0 ELSE 1 END,
                                                  CASE WHEN town IS NOT NULL AND TRIM(town) <> '' THEN 0 ELSE 1 END,
                                                  id ASC
                                        LIMIT 1
                              ");
                              $stmt->execute([':name' => $venueName]);
                              $venue = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    }
          }

          $locationParts = [];
          foreach (['postcode', 'town', 'address_line1', 'name'] as $field) {
                    $value = trim((string)($venue[$field] ?? ''));
                    if ($value !== '') {
                              $locationParts[] = $value;
                    }
          }
          if ($locationParts === []) {
                    $fallback = $venueName !== '' ? $venueName : trim((string)($fixture['opponent_ground_location'] ?? ''));
                    if ($fallback !== '') {
                              $locationParts[] = $fallback;
                    }
          }
          if ($locationParts === []) {
                    return ['ok' => false, 'message' => 'Add a venue name, town or postcode to show match-day weather.'];
          }

          $location = implode(', ', array_values(array_unique($locationParts)));
          $postcode = strtoupper(trim((string)($venue['postcode'] ?? '')));
          $town = trim((string)($venue['town'] ?? ''));
          $place = null;

          // Open-Meteo does not consistently resolve full UK postcodes. Resolve
          // the postcode to WGS84 coordinates first, then use those coordinates
          // for the weather request.
          if ($postcode !== '') {
                    $postcodeLookup = matchOverviewFetchJson(
                              'https://api.postcodes.io/postcodes/' . rawurlencode(preg_replace('/\s+/', '', $postcode) ?? $postcode)
                    );
                    $postcodeResult = $postcodeLookup['result'] ?? null;
                    if (
                              is_array($postcodeResult)
                              && isset($postcodeResult['latitude'], $postcodeResult['longitude'])
                              && is_numeric($postcodeResult['latitude'])
                              && is_numeric($postcodeResult['longitude'])
                    ) {
                              $place = [
                                        'name' => trim(implode(', ', array_filter([$venueName, $town]))),
                                        'latitude' => (float)$postcodeResult['latitude'],
                                        'longitude' => (float)$postcodeResult['longitude'],
                              ];
                    }
          }

          if (!$place) {
                    $searchTerms = array_values(array_unique(array_filter([
                              $town,
                              $venueName,
                              trim((string)($fixture['opponent_ground_location'] ?? '')),
                    ])));
                    foreach ($searchTerms as $searchTerm) {
                              $geocodeUrl = 'https://geocoding-api.open-meteo.com/v1/search?' . http_build_query([
                                        'name' => $searchTerm,
                                        'count' => 1,
                                        'language' => 'en',
                                        'format' => 'json',
                                        'countryCode' => 'GB',
                              ]);
                              $geocode = matchOverviewFetchJson($geocodeUrl);
                              if (!empty($geocode['results'][0]) && is_array($geocode['results'][0])) {
                                        $place = $geocode['results'][0];
                                        if ($venueName !== '') {
                                                  $place['name'] = trim(implode(', ', array_filter([$venueName, $town])));
                                        }
                                        break;
                              }
                    }
          }

          if (!$place) {
                    // A final full-address attempt helps locations outside the UK
                    // postcode dataset without weakening postcode accuracy.
                    $geocodeUrl = 'https://geocoding-api.open-meteo.com/v1/search?' . http_build_query([
                              'name' => $location,
                              'count' => 1,
                              'language' => 'en',
                              'format' => 'json',
                              'countryCode' => 'GB',
                    ]);
                    $geocode = matchOverviewFetchJson($geocodeUrl);
                    $place = $geocode['results'][0] ?? null;
          }
          if (!is_array($place) || !isset($place['latitude'], $place['longitude'])) {
                    return ['ok' => false, 'message' => 'The venue location could not be found. Add its postcode in Venues for a more accurate weather lookup.'];
          }

          $matchDate = trim((string)($fixture['match_date'] ?? ''));
          if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $matchDate)) {
                    return ['ok' => false, 'message' => 'Set the fixture date to show match-day weather.'];
          }
          $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
          $date = DateTimeImmutable::createFromFormat('!Y-m-d', $matchDate, new DateTimeZone('UTC'));
          $daysAhead = $date ? (int)$today->diff($date)->format('%r%a') : 0;
          if ($daysAhead > 16) {
                    return [
                              'ok' => false,
                              'message' => 'A detailed forecast will appear when the match is within 16 days.',
                              'location' => $place['name'] ?? $location,
                    ];
          }

          $weatherHost = $daysAhead < 0
                    ? 'https://historical-forecast-api.open-meteo.com/v1/forecast'
                    : 'https://api.open-meteo.com/v1/forecast';
          $weatherUrl = $weatherHost . '?' . http_build_query([
                    'latitude' => $place['latitude'],
                    'longitude' => $place['longitude'],
                    'start_date' => $matchDate,
                    'end_date' => $matchDate,
                    'hourly' => 'temperature_2m,apparent_temperature,precipitation_probability,precipitation,weather_code,wind_speed_10m,wind_gusts_10m',
                    'timezone' => 'Europe/London',
                    'wind_speed_unit' => 'mph',
          ]);
          $weather = matchOverviewFetchJson($weatherUrl);
          $times = $weather['hourly']['time'] ?? [];
          if (!is_array($times) || $times === []) {
                    return ['ok' => false, 'message' => 'Weather data is temporarily unavailable for this match date.'];
          }

          $kickoff = trim((string)($fixture['kickoff_time'] ?? '15:00'));
          $kickoffHour = substr($kickoff !== '' ? $kickoff : '15:00', 0, 2);
          $target = $matchDate . 'T' . str_pad($kickoffHour, 2, '0', STR_PAD_LEFT) . ':00';
          $index = array_search($target, $times, true);
          if ($index === false) {
                    $index = 0;
          }
          $hourly = $weather['hourly'];
          $value = static fn(string $key): mixed => $hourly[$key][$index] ?? null;
          $code = (int)($value('weather_code') ?? -1);

          return [
                    'ok' => true,
                    'observed' => $daysAhead < 0,
                    'location' => (string)($place['name'] ?? $location),
                    'time' => date('H:i', strtotime((string)$times[$index])),
                    'description' => matchOverviewWeatherDescription($code),
                    'temperature' => $value('temperature_2m'),
                    'feels_like' => $value('apparent_temperature'),
                    'precipitation_probability' => $value('precipitation_probability'),
                    'precipitation' => $value('precipitation'),
                    'wind_speed' => $value('wind_speed_10m'),
                    'wind_gusts' => $value('wind_gusts_10m'),
          ];
}
