<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('club_setup')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/template_packs.php';
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
$name = trim($_POST['name'] ?? '');
$startDate = trim($_POST['start_date'] ?? '');
$endDate = trim($_POST['end_date'] ?? '');
$isCurrent = isset($_POST['is_current']) ? 1 : 0;
$isLocked = isset($_POST['is_locked']) ? 1 : 0;
$sponsorshipDeadline = trim((string) ($_POST['sponsorship_deadline'] ?? ''));
$competitionId = (int)($_POST['competition_id'] ?? 0);
$seasonTicketTerms = trim((string) ($_POST['season_ticket_terms'] ?? ''));
$playerHomeAmount = (float)($_POST['player_home_amount'] ?? 50.00);
$playerAwayAmount = (float)($_POST['player_away_amount'] ?? 30.00);
$playerThirdAmount = (float)($_POST['player_third_amount'] ?? 20.00);
$matchHomeAmount = (float)($_POST['match_home_amount'] ?? 50.00);
$matchAwayAmount = (float)($_POST['match_away_amount'] ?? 20.00);
$matchBallAmount = (float)($_POST['match_ball_amount'] ?? 25.00);
$templatePackId = max(0, (int) ($_POST['template_pack_id'] ?? 0));

if ($name === '') {
          exit('Season name is required.');
}

if ($startDate === '') {
          $startDate = null;
}
if ($endDate === '') {
          $endDate = null;
}
if ($sponsorshipDeadline === '') {
          $sponsorshipDeadline = null;
}
if ($seasonId <= 0 && !hub_auth_is_sponsorship_editor()) {
          $sponsorshipDeadline = null;
}

template_packs_ensure_schema($pdo);
ensureCompetitionStructureSchema($pdo);

$isNewSeason = $seasonId <= 0;
$pdo->beginTransaction();
try {
          if ($seasonId > 0) {
                    $existing = getSeasonById($pdo, $seasonId);
                    if (!$existing) {
                              throw new RuntimeException('Season not found.');
                    }
                    if ((int)$existing['is_locked'] === 1) {
                              throw new RuntimeException('This season is locked.');
                    }
                    // The sponsorship deadline gates who can touch player sponsorships
                    // (see assertSponsorshipEditable()); this form is otherwise open to
                    // anyone with the club_setup capability, so only the configured
                    // sponsorship editor may move it. Anyone else's submission keeps
                    // whatever value was already stored.
                    if (!hub_auth_is_sponsorship_editor()) {
                              $sponsorshipDeadline = $existing['sponsorship_deadline'];
                    }

                    $stmt = $pdo->prepare("
                              UPDATE seasons
                              SET name = :name,
                                  start_date = :start_date,
                                  end_date = :end_date,
                                  is_current = :is_current,
                                  is_locked = :is_locked,
                                  sponsorship_deadline = :sponsorship_deadline,
                                  competition_id = :competition_id,
                                  season_ticket_terms = :season_ticket_terms
                              WHERE id = :id
                              LIMIT 1
                    ");
                    $stmt->execute([
                              ':name' => $name,
                              ':start_date' => $startDate,
                              ':end_date' => $endDate,
                              ':is_current' => $isCurrent,
                              ':is_locked' => $isLocked,
                              ':sponsorship_deadline' => $sponsorshipDeadline,
                              ':competition_id' => $competitionId > 0 ? $competitionId : null,
                              ':season_ticket_terms' => $seasonTicketTerms !== '' ? $seasonTicketTerms : null,
                              ':id' => $seasonId,
                    ]);
          } else {
                    $stmt = $pdo->prepare("
                              INSERT INTO seasons
                                        (name, start_date, end_date, is_current, is_locked, sponsorship_deadline, competition_id, season_ticket_terms)
                              VALUES
                                        (:name, :start_date, :end_date, :is_current, :is_locked, :sponsorship_deadline, :competition_id, :season_ticket_terms)
                    ");
                    $stmt->execute([
                              ':name' => $name,
                              ':start_date' => $startDate,
                              ':end_date' => $endDate,
                              ':is_current' => $isCurrent,
                              ':is_locked' => $isLocked,
                              ':sponsorship_deadline' => $sponsorshipDeadline,
                              ':competition_id' => $competitionId > 0 ? $competitionId : null,
                              ':season_ticket_terms' => $seasonTicketTerms !== '' ? $seasonTicketTerms : null,
                    ]);
                    $seasonId = (int)$pdo->lastInsertId();
          }

          if ($isCurrent === 1) {
                    $other = $pdo->prepare("UPDATE seasons SET is_current = 0 WHERE id <> :id");
                    $other->execute([':id' => $seasonId]);
          }

          if ($competitionId > 0) {
                    $primaryLeagueEdition = competitionStructureFindEdition($pdo, $competitionId, $seasonId);
                    if ($primaryLeagueEdition) {
                              competitionStructureSetEditionActive($pdo, (int)$primaryLeagueEdition['id'], true);
                    } else {
                              $primaryLeague = getMatchCompetitionById($pdo, $competitionId);
                              competitionStructureSaveEdition($pdo, null, $competitionId, $seasonId, [
                                        'display_name' => (string)($primaryLeague['name'] ?? ''),
                                        'competition_url' => $primaryLeague['league_url'] ?? null,
                                        'promotion_spots' => $primaryLeague['promotion_spots'] ?? null,
                                        'relegation_spots' => $primaryLeague['relegation_spots'] ?? null,
                                        'show_table_lines' => $primaryLeague['show_table_lines'] ?? null,
                                        'is_active' => true,
                              ]);
                    }
          }

          saveSeasonPlayerPricing($pdo, $seasonId, $playerHomeAmount, $playerAwayAmount, $playerThirdAmount);
          saveMatchSeasonPricing($pdo, $seasonId, $matchHomeAmount, $matchAwayAmount, $matchBallAmount);
          if ($templatePackId > 0) {
                    template_packs_set_season_default(
                              $pdo,
                              $seasonId,
                              $templatePackId,
                              null,
                              isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null
                    );
          } else {
                    template_packs_clear_season_default($pdo, $seasonId);
          }

          $sponsorshipsStmt = $pdo->prepare("
                    SELECT DISTINCT player_id, sponsor_id
                    FROM sponsorships
                    WHERE season_id = :season_id
                      AND ended_at IS NULL
          ");
          $sponsorshipsStmt->execute([':season_id' => $seasonId]);
          foreach ($sponsorshipsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    recalculateSponsorAmounts($pdo, (int)$row['player_id'], (int)$row['sponsor_id'], $seasonId);
          }

          if ($isCurrent === 1) {
                    $_SESSION['season_id'] = $seasonId;
          }

          $pdo->commit();
          auditLog($pdo, $isNewSeason ? 'season_created' : 'season_updated', ($isNewSeason ? 'Created' : 'Updated') . " season '{$name}'");
          header('Location: season.php?id=' . $seasonId . '&saved=1');
          exit;
} catch (Throwable $e) {
          if ($pdo->inTransaction()) {
                    $pdo->rollBack();
          }
          http_response_code(400);
          echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
