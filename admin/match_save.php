<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/google_calendar.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/member_matches.php';
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

$seasonId = (int)($_POST['season_id'] ?? 0);
$fixtureId = (int)($_POST['fixture_id'] ?? 0);
$matchDate = trim($_POST['match_date'] ?? '');
$kickoffTime = trim($_POST['kickoff_time'] ?? '');
$competition = trim($_POST['competition'] ?? '');
$competitionSeasonId = (int)($_POST['competition_season_id'] ?? 0);
$competitionStage = trim($_POST['competition_stage'] ?? '');
$venue = trim($_POST['venue'] ?? '');
$opponentId = (int)($_POST['opponent_id'] ?? 0);
$opponent = trim($_POST['opponent'] ?? '');
$status = trim($_POST['status'] ?? 'scheduled');
$notes = trim($_POST['notes'] ?? '');
$isHome = ((string)($_POST['is_home'] ?? '1') === '1') ? 1 : 0;
$veoUrl = trim((string)($_POST['veo_url'] ?? ''));

// Half-time / full-time scores are now part of this form (no separate Save Score
// button). Same parsing rules as the old match_score_save.php endpoint.
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
} catch (InvalidArgumentException $e) {
          http_response_code(400);
          exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

if ($veoUrl !== '' && !filter_var($veoUrl, FILTER_VALIDATE_URL)) {
          http_response_code(400);
          exit('Enter a valid VEO link, or leave it blank.');
}

if ($seasonId <= 0) {
          exit('Missing season.');
}

$season = getSeasonById($pdo, $seasonId);
if (!$season) {
          exit('Invalid season.');
}
if ((int)$season['is_locked'] === 1) {
          exit('This season is locked.');
}

$competitionSeason = null;
if ($competitionSeasonId > 0) {
          $competitionSeason = getMatchCompetitionSeasonById($pdo, $competitionSeasonId, $seasonId);
          if (!$competitionSeason) {
                    exit('Select a competition assigned to this season.');
          }
          $competition = trim((string)$competitionSeason['display_title']);
}

if (!in_array($status, ['scheduled', 'played', 'postponed', 'cancelled'], true)) {
          $status = 'scheduled';
}

// A full-time score means the match has been played.
if ($fullTimeHome !== null && $status === 'scheduled') {
          $status = 'played';
}

$opponentRow = null;
if ($opponentId > 0) {
          $opponentRow = getMatchOpponentById($pdo, $opponentId);
}
if (!$opponentRow && $opponent !== '') {
          $opponentRow = getMatchOpponentByClubname($pdo, $opponent);
}
if ($opponentRow) {
          $opponentId = (int)$opponentRow['id'];
          $opponent = (string)$opponentRow['clubname'];
}

if ($matchDate === '' || $opponentId <= 0 || $opponent === '') {
          exit('Match date and opponent are required.');
}

if ($opponent === '' || $opponentId <= 0) {
          exit('Opponent is required.');
}

ensureMemberMatchSchema($pdo); // guarantees the veo_url column exists

$pdo->beginTransaction();
try {
          $existingFixture = null;
          if ($fixtureId > 0) {
                    $existingFixture = getMatchFixtureById($pdo, $fixtureId);
          }

          if ($fixtureId > 0) {
                    $stmt = $pdo->prepare("
                              UPDATE match_fixtures
                              SET match_date = :match_date,
                                  kickoff_time = :kickoff_time,
                                  opponent_id = :opponent_id,
                                  opponent = :opponent,
                                  competition = :competition,
                                  competition_season_id = :competition_season_id,
                                  competition_stage = :competition_stage,
                                  venue = :venue,
                                  is_home = :is_home,
                                  status = :status,
                                  notes = :notes,
                                  half_time_home_score = :half_time_home,
                                  half_time_away_score = :half_time_away,
                                  full_time_home_score = :full_time_home,
                                  full_time_away_score = :full_time_away,
                                  veo_url = :veo_url
                              WHERE id = :id
                                AND season_id = :season_id
                              LIMIT 1
                    ");
                    $stmt->execute([
                              ':match_date' => $matchDate,
                              ':kickoff_time' => $kickoffTime !== '' ? $kickoffTime : null,
                              ':opponent_id' => $opponentId,
                              ':opponent' => $opponent,
                              ':competition' => $competition !== '' ? $competition : null,
                              ':competition_season_id' => $competitionSeasonId > 0 ? $competitionSeasonId : null,
                              ':competition_stage' => $competitionStage !== '' ? $competitionStage : null,
                              ':venue' => $venue !== '' ? $venue : null,
                              ':is_home' => $isHome,
                              ':status' => $status,
                              ':notes' => $notes !== '' ? $notes : null,
                              ':half_time_home' => $halfTimeHome,
                              ':half_time_away' => $halfTimeAway,
                              ':full_time_home' => $fullTimeHome,
                              ':full_time_away' => $fullTimeAway,
                              ':veo_url' => $veoUrl !== '' ? $veoUrl : null,
                              ':id' => $fixtureId,
                              ':season_id' => $seasonId,
                    ]);
          } else {
                    $stmt = $pdo->prepare("
                              INSERT INTO match_fixtures
                                        (season_id, opponent_id, match_date, kickoff_time, opponent, competition, competition_season_id, competition_stage, venue, is_home, status, notes, half_time_home_score, half_time_away_score, full_time_home_score, full_time_away_score, veo_url)
                              VALUES
                                        (:season_id, :opponent_id, :match_date, :kickoff_time, :opponent, :competition, :competition_season_id, :competition_stage, :venue, :is_home, :status, :notes, :half_time_home, :half_time_away, :full_time_home, :full_time_away, :veo_url)
                    ");
                    $stmt->execute([
                              ':season_id' => $seasonId,
                              ':opponent_id' => $opponentId,
                              ':match_date' => $matchDate,
                              ':kickoff_time' => $kickoffTime !== '' ? $kickoffTime : null,
                              ':opponent' => $opponent,
                              ':competition' => $competition !== '' ? $competition : null,
                              ':competition_season_id' => $competitionSeasonId > 0 ? $competitionSeasonId : null,
                              ':competition_stage' => $competitionStage !== '' ? $competitionStage : null,
                              ':venue' => $venue !== '' ? $venue : null,
                              ':is_home' => $isHome,
                              ':status' => $status,
                              ':notes' => $notes !== '' ? $notes : null,
                              ':half_time_home' => $halfTimeHome,
                              ':half_time_away' => $halfTimeAway,
                              ':full_time_home' => $fullTimeHome,
                              ':full_time_away' => $fullTimeAway,
                              ':veo_url' => $veoUrl !== '' ? $veoUrl : null,
                    ]);
                    $fixtureId = (int)$pdo->lastInsertId();
          }

          if ($fixtureId > 0 && $existingFixture && (int)$existingFixture['is_home'] !== $isHome) {
                    recalculateMatchFixtureSponsorshipAmounts($pdo, $fixtureId);
          }

          $pdo->commit();

          auditLog($pdo, $existingFixture ? 'match_updated' : 'match_created', ($existingFixture ? 'Updated' : 'Created') . ' fixture vs ' . $opponent . ' on ' . $matchDate);

          $savedFixture = getMatchFixtureById($pdo, $fixtureId);
          if ($savedFixture) {
                    match_google_calendar_sync_fixture($pdo, $savedFixture, 'upsert');
          }

          header('Location: match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&saved=1');
          exit;
} catch (Throwable $e) {
          if ($pdo->inTransaction()) {
                    $pdo->rollBack();
          }
          http_response_code(400);
          echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
