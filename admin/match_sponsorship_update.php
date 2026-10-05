<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_any_capability(['sponsorship', 'finance_manage'])) {
    http_response_code(403);
    exit('Access denied.');
}
$canRecordPayments = hub_auth_has_any_capability(['finance_manage', 'sponsorship_payments']);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

header('Content-Type: text/html; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
          http_response_code(405);
          exit('Invalid request method.');
}

if (!csrf_check()) {
          http_response_code(400);
          exit('Invalid CSRF token.');
}

$id = (int)($_POST['id'] ?? 0);
$sponsorId = (int)($_POST['sponsor_id'] ?? 0);
$amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? (float)$_POST['amount'] : null;
$notes = trim((string)($_POST['notes'] ?? ''));
$paid = $canRecordPayments && isset($_POST['paid']) && (string)$_POST['paid'] === '1';
$isComplimentary = isset($_POST['is_complimentary']) && (string)$_POST['is_complimentary'] === '1';

if ($id <= 0) {
          exit('Invalid sponsorship.');
}

if ($sponsorId <= 0) {
          exit('Missing sponsor.');
}

if ($amount === null || $amount < 0) {
          exit('Invalid amount.');
}
if ($isComplimentary && $paid) {
          exit('A complimentary sponsorship cannot also be marked as paid.');
}
if ($isComplimentary) {
          $amount = 0.0;
}

try {
          ensureMatchSchema($pdo);
          ensureSponsorshipCatalogSchema($pdo);
          $pdo->beginTransaction();
          $row = getMatchSponsorshipById($pdo, $id);
          if (!$row) {
                    throw new RuntimeException('Match sponsorship not found.');
          }

          $sponsorCheck = $pdo->prepare("
                    SELECT id
                    FROM sponsors
                    WHERE id = :id
                      AND is_active = 1
                    LIMIT 1
          ");
          $sponsorCheck->execute([':id' => $sponsorId]);
          if (!(int)$sponsorCheck->fetchColumn()) {
                    throw new RuntimeException('Selected sponsor is not active.');
          }
          $paidTotal = getMatchSponsorshipPaidTotal($pdo, $id);
          if ($isComplimentary && $paidTotal > 0.0001) {
                    throw new RuntimeException('A sponsorship with recorded payments cannot be changed to complimentary. Remove or refund the payments first.');
          }

          logMatchSponsorshipHistory(
                    $pdo,
                    $row,
                    'update',
                    'update',
                    $isComplimentary !== ((int)($row['is_complimentary'] ?? 0) === 1) ? 'complimentary_status_changed' : 'details_changed'
          );

          $upd = $pdo->prepare("
                    UPDATE match_sponsorships
                    SET sponsor_id = :sponsor_id,
                        amount = :amount,
                        is_complimentary = :is_complimentary,
                        notes = :notes
                    WHERE id = :id
                    LIMIT 1
          ");
          $upd->execute([
                    ':sponsor_id' => $sponsorId,
                    ':amount' => $amount,
                    ':is_complimentary' => $isComplimentary ? 1 : 0,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':id' => $id,
          ]);

          syncMatchSponsorshipAgreement($pdo, $id);

          recomputeMatchPaidFlag($pdo, $id);

          if (!$canRecordPayments) {
                    // payment state is finance_manage only; leave it untouched
          } elseif ($paid) {
                    markMatchSponsorshipPaid($pdo, $id);
          } elseif ($paidTotal > 0.0001) {
                    unpayMatchSponsorship($pdo, $id);
          }

          $fixture = getMatchFixtureById($pdo, (int)$row['fixture_id']);
          $seasonId = (int)($fixture['season_id'] ?? $row['season_id'] ?? 0);
          $pdo->commit();
          auditLog($pdo, 'match_sponsorship_updated', "Updated {$row['sponsorship_role']} sponsorship (vs {$row['opponent']}) — sponsor #{$sponsorId}, £" . number_format($amount, 2));
          header('Location: match.php?id=' . (int)$row['fixture_id'] . '&season_id=' . $seasonId . '&saved=1');
          exit;
} catch (Throwable $e) {
          if ($pdo->inTransaction()) {
                    $pdo->rollBack();
          }
          http_response_code(400);
          echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
