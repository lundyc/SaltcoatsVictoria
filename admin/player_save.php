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
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/audit.php';

$seasonId = getSelectedSeasonId($pdo);
$allowedSlots = getAllowedSponsorshipSlots($pdo, $seasonId);

header('Content-Type: application/json');

if (!csrf_check()) {
          echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
          exit;
}

$player_id   = (int)($_POST['player'] ?? 0);
$slot        = $_POST['slot'] ?? '';
$sponsor     = (int)($_POST['sponsor_id'] ?? 0);
$amount      = (float)($_POST['amount'] ?? 0);
$is_free     = isset($_POST['is_free']) ? 1 : 0;
$add_payment = $canRecordPayments ? (float)($_POST['add_payment'] ?? 0) : 0.0;
$notes       = trim($_POST['notes'] ?? '');

if (!$player_id || !in_array($slot, $allowedSlots, true)) {
          echo json_encode(['success' => false, 'error' => 'Invalid input']);
          exit;
}

try {
          $pdo->beginTransaction();

          if (!$canRecordPayments) {
                    assertSponsorshipEditable($pdo, $seasonId);
          }

          // check existing sponsorship
          $stmt = $pdo->prepare("SELECT id FROM sponsorships WHERE player_id=:pid AND slot=:slot AND season_id = :season_id AND ended_at IS NULL");
          $stmt->execute([':pid' => $player_id, ':slot' => $slot, ':season_id' => $seasonId]);
          $id = $stmt->fetchColumn();

          if ($sponsor === 0) {
                    if ($id) {
                              $pdo->prepare("UPDATE sponsorships SET ended_at = NOW(), ended_reason = 'deleted' WHERE id=:id")->execute([':id' => $id]);
                              auditLog($pdo, 'sponsorship_ended', "Ended {$slot} sponsorship for player #{$player_id}");
                              syncPlayerSponsorshipAgreement($pdo, (int) $id);
                    }
                    $pdo->commit();
                    echo json_encode(['success' => true, 'paid_total' => 0, 'due' => 0]);
                    exit;
          }

          $sponsorshipCreated = false;
          if ($id) {
                    $pdo->prepare("UPDATE sponsorships 
                    SET sponsor_id=:sid, amount=:amt, notes=:notes, paid=:paid 
                   WHERE id=:id")
                              ->execute([
                                        ':sid' => $sponsor,
                                        ':amt' => $is_free ? 0.00 : $amount,
                                        ':notes' => $notes ?: null,
                                        ':paid' => $is_free ? 1 : 0,
                                        ':id' => $id
                              ]);
          } else {
                    $existingStmt = $pdo->prepare("
                              SELECT id, ended_at
                              FROM sponsorships
                              WHERE player_id = :pid
                                AND slot = :slot
                                AND season_id = :season_id
                              LIMIT 1
                    ");
                    $existingStmt->execute([
                              ':pid' => $player_id,
                              ':slot' => $slot,
                              ':season_id' => $seasonId
                    ]);
                    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing && empty($existing['ended_at'])) {
                              $pdo->prepare("UPDATE sponsorships
                                             SET sponsor_id = :sid,
                                                 amount = :amt,
                                                 notes = :notes,
                                                 paid = :paid
                                             WHERE id = :id")
                                        ->execute([
                                                  ':sid' => $sponsor,
                                                  ':amt' => $is_free ? 0.00 : $amount,
                                                  ':notes' => $notes ?: null,
                                                  ':paid' => $is_free ? 1 : 0,
                                                  ':id' => $existing['id']
                                        ]);
                              $id = $existing['id'];
                    } elseif ($existing) {
                              $pdo->prepare("UPDATE sponsorships
                                             SET sponsor_id = :sid,
                                                 amount = :amt,
                                                 notes = :notes,
                                                 paid = :paid,
                                                 ended_at = NULL,
                                                 ended_reason = NULL,
                                                 assigned_at = NOW(),
                                                 started_at = NOW()
                                             WHERE id = :id")
                                        ->execute([
                                                  ':sid' => $sponsor,
                                                  ':amt' => $is_free ? 0.00 : $amount,
                                                  ':notes' => $notes ?: null,
                                                  ':paid' => $is_free ? 1 : 0,
                                                  ':id' => $existing['id']
                                        ]);
                              $id = $existing['id'];
                    } else {
                              $pdo->prepare("INSERT INTO sponsorships (season_id, sponsor_id, player_id, slot, amount, notes, paid, assigned_at, started_at)
                                             VALUES (:season_id,:sid,:pid,:slot,:amt,:notes,:paid,NOW(),NOW())")
                                        ->execute([
                                                  ':season_id' => $seasonId,
                                                  ':sid' => $sponsor,
                                                  ':pid' => $player_id,
                                                  ':slot' => $slot,
                                                  ':amt' => $is_free ? 0.00 : $amount,
                                                  ':notes' => $notes ?: null,
                                                  ':paid' => $is_free ? 1 : 0
                                        ]);
                              $id = $pdo->lastInsertId();
                              $sponsorshipCreated = true;
                    }
          }

          auditLog($pdo, $sponsorshipCreated ? 'sponsorship_added' : 'sponsorship_updated', ($sponsorshipCreated ? 'Added' : 'Updated') . " {$slot} sponsorship for player #{$player_id}");

          // Add payment if entered
          if ($add_payment > 0 && !$is_free) {
                    $pdo->prepare("INSERT INTO sponsorship_payments (sponsorship_id, amount, note, season_id)
                   VALUES (:sid,:amt,'Manual update from player.php', :season_id)")
                              ->execute([':sid' => $id, ':amt' => $add_payment, ':season_id' => $seasonId]);
                    auditLog($pdo, 'sponsorship_payment_recorded', "Recorded payment of {$add_payment} for {$slot} sponsorship (player #{$player_id})");
          }

          // Recompute paid flag
          recomputePaidFlag($pdo, $id);
          syncPlayerSponsorshipAgreement($pdo, (int) $id);

          // fetch updated totals
          $stmt = $pdo->prepare("SELECT amount, paid FROM sponsorships WHERE id=:id");
          $stmt->execute([':id' => $id]);
          $sponsorship = $stmt->fetch();

          $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sponsorship_payments WHERE sponsorship_id=:id");
          $stmt->execute([':id' => $id]);
          $paid_total = (float)$stmt->fetchColumn();

          $due = max(0, (float)$sponsorship['amount'] - $paid_total);

          $pdo->commit();
          echo json_encode(['success' => true, 'paid_total' => $paid_total, 'due' => $due]);
} catch (Throwable $e) {
          $pdo->rollBack();
          echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
