<?php
declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
          http_response_code(405);
          exit('Invalid request method.');
}

if (!csrf_check()) {
          http_response_code(400);
          exit('Invalid CSRF token.');
}

$fixtureId = (int)($_POST['fixture_id'] ?? 0);
$seasonId = (int)($_POST['season_id'] ?? 0);
$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;
if (!$fixture || (int)$fixture['season_id'] !== $seasonId) {
          http_response_code(404);
          exit('Fixture not found.');
}

$parseScorePair = static function (string $homeKey, string $awayKey): array {
          $homeRaw = trim((string)($_POST[$homeKey] ?? ''));
          $awayRaw = trim((string)($_POST[$awayKey] ?? ''));
          if ($homeRaw === '' && $awayRaw === '') {
                    return [null, null];
          }
          if ($homeRaw === '' || $awayRaw === '') {
                    throw new InvalidArgumentException('Enter both teams’ scores, or leave both boxes blank.');
          }
          if (!ctype_digit($homeRaw) || !ctype_digit($awayRaw)) {
                    throw new InvalidArgumentException('Scores must be whole numbers.');
          }
          $home = (int)$homeRaw;
          $away = (int)$awayRaw;
          if ($home > 99 || $away > 99) {
                    throw new InvalidArgumentException('Scores must be between 0 and 99.');
          }
          return [$home, $away];
};

try {
          [$halfTimeHome, $halfTimeAway] = $parseScorePair('half_time_home_score', 'half_time_away_score');
          [$fullTimeHome, $fullTimeAway] = $parseScorePair('full_time_home_score', 'full_time_away_score');

          if (
                    $halfTimeHome !== null
                    && $fullTimeHome !== null
                    && ($fullTimeHome < $halfTimeHome || $fullTimeAway < $halfTimeAway)
          ) {
                    throw new InvalidArgumentException('A full-time score cannot be lower than the half-time score.');
          }

          $stmt = $pdo->prepare("
                    UPDATE match_fixtures
                    SET half_time_home_score = :half_time_home,
                        half_time_away_score = :half_time_away,
                        full_time_home_score = :full_time_home,
                        full_time_away_score = :full_time_away,
                        status = CASE
                              WHEN :has_full_time = 1 THEN 'played'
                              ELSE status
                        END
                    WHERE id = :fixture_id
                      AND season_id = :season_id
                    LIMIT 1
          ");
          $stmt->execute([
                    ':half_time_home' => $halfTimeHome,
                    ':half_time_away' => $halfTimeAway,
                    ':full_time_home' => $fullTimeHome,
                    ':full_time_away' => $fullTimeAway,
                    ':has_full_time' => $fullTimeHome !== null ? 1 : 0,
                    ':fixture_id' => $fixtureId,
                    ':season_id' => $seasonId,
          ]);

          $scoreSummary = $fullTimeHome !== null ? ($fullTimeHome . '-' . $fullTimeAway) : 'cleared';
          auditLog($pdo, 'match_score_saved', 'Set score for fixture vs ' . (string) ($fixture['opponent'] ?? '') . ' on ' . (string) ($fixture['match_date'] ?? '') . ': ' . $scoreSummary);

          header('Location: match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&score_saved=1');
          exit;
} catch (InvalidArgumentException $e) {
          http_response_code(400);
          echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
