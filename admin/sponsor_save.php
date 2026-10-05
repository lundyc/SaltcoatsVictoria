<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_any_capability(['sponsorship', 'finance_manage'])) {
    http_response_code(403);
    exit('Access denied.');
}
$canRecordPayments = hub_auth_has_any_capability(['finance_manage', 'sponsorship_payments']);
// sponsor_save.php — handle sponsor actions (AJAX-friendly)
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
          session_start();
}

if (!isset($_SESSION['user_id'])) {
          echo json_encode(['success' => false, 'error' => 'Login required']);
          exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
          echo json_encode(['success' => false, 'error' => 'Invalid request']);
          exit;
}

$action = $_POST['action'] ?? '';
$sponsor_id = (int)($_POST['sponsor_id'] ?? 0);
$season_id = (int)($_POST['season_id'] ?? 0);

if ($sponsor_id <= 0) {
          echo json_encode(['success' => false, 'error' => 'Invalid sponsor ID']);
          exit;
}

try {
          if ($action === 'add_sponsorship') {
                    $player_id = (int)($_POST['player_id'] ?? 0);
                    $slot = strtolower(trim($_POST['slot'] ?? ''));
                    $amount = (float)($_POST['amount'] ?? 0);
                    $notes = trim($_POST['notes'] ?? '');
                    $mark_paid = $canRecordPayments && isset($_POST['mark_paid']) && (int)$_POST['mark_paid'] === 1;

                    if ($season_id <= 0) {
                              throw new Exception("Invalid season selected");
                    }

                    $season = getSeasonById($pdo, $season_id);
                    if (!$season) {
                              throw new Exception("Invalid season selected");
                    }
                    if ((int)($season['is_locked'] ?? 0) === 1) {
                              throw new Exception("This season is locked");
                    }

                    if ($player_id <= 0) {
                              throw new HubFieldValidationException('player_id', "Invalid player selected");
                    }

                    if (!$canRecordPayments) {
                              assertSponsorshipEditable($pdo, $season_id);
                    }

                    $allowedSlots = getAllowedSponsorshipSlots($pdo, $season_id);
                    if (!in_array($slot, $allowedSlots, true)) {
                              throw new HubFieldValidationException('slot', "Invalid sponsorship slot");
                    }

                    $playerStmt = $pdo->prepare("SELECT id, name, active FROM players WHERE id = :id LIMIT 1");
                    $playerStmt->execute([':id' => $player_id]);
                    $player = $playerStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$player) {
                              throw new HubFieldValidationException('player_id', "Player not found");
                    }
                    if ((int)$player['active'] !== 1) {
                              throw new HubFieldValidationException('player_id', "Player must be active");
                    }

                    if ($amount < 0) {
                              throw new HubFieldValidationException('amount', "Invalid sponsorship amount");
                    }

                    $occupiedStmt = $pdo->prepare("
                              SELECT LOWER(slot)
                              FROM sponsorships
                              WHERE season_id = :season_id
                                AND player_id = :player_id
                                AND ended_at IS NULL
                    ");
                    $occupiedStmt->execute([
                              ':season_id' => $season_id,
                              ':player_id' => $player_id,
                    ]);
                    $occupiedSlots = array_map('strtolower', $occupiedStmt->fetchAll(PDO::FETCH_COLUMN));
                    if (in_array($slot, $occupiedSlots, true)) {
                              throw new HubFieldValidationException('slot', "That player already has the selected slot");
                    }

                    $pdo->beginTransaction();
                    $existingStmt = $pdo->prepare("
                              SELECT id
                              FROM sponsorships
                              WHERE season_id = :season_id
                                AND player_id = :player_id
                                AND slot = :slot
                                AND ended_at IS NULL
                              LIMIT 1
                    ");
                    $existingStmt->execute([
                              ':season_id' => $season_id,
                              ':player_id' => $player_id,
                              ':slot' => $slot,
                    ]);
                    $existingId = (int)($existingStmt->fetchColumn() ?: 0);

                    if ($existingId > 0) {
                              $stmt = $pdo->prepare("
                                        UPDATE sponsorships
                                        SET sponsor_id = :sponsor_id,
                                            amount = :amount,
                                            notes = :notes,
                                            assigned_at = NOW(),
                                            started_at = COALESCE(started_at, NOW()),
                                            ended_at = NULL,
                                            ended_reason = NULL
                                        WHERE id = :id
                              ");
                              $stmt->execute([
                                        ':sponsor_id' => $sponsor_id,
                                        ':amount' => $amount,
                                        ':notes' => $notes !== '' ? $notes : null,
                                        ':id' => $existingId,
                              ]);
                              $sponsorship_id = $existingId;
                    } else {
                              $stmt = $pdo->prepare("
                                        INSERT INTO sponsorships
                                          (season_id, sponsor_id, player_id, slot, amount, notes, assigned_at, started_at)
                                        VALUES
                                          (:season_id, :sponsor_id, :player_id, :slot, :amount, :notes, NOW(), NOW())
                              ");
                              $stmt->execute([
                                        ':season_id' => $season_id,
                                        ':sponsor_id' => $sponsor_id,
                                        ':player_id' => $player_id,
                                        ':slot' => $slot,
                                        ':amount' => $amount,
                                        ':notes' => $notes !== '' ? $notes : null,
                              ]);
                              $sponsorship_id = (int)$pdo->lastInsertId();
                    }

                    if ($mark_paid && $amount > 0) {
                              $paidStmt = $pdo->prepare("
                                        INSERT INTO sponsorship_payments
                                          (sponsorship_id, amount, paid_at, method, note, season_id)
                                        VALUES
                                          (:sponsorship_id, :amount, NOW(), :method, :note, :season_id)
                              ");
                              $paidStmt->execute([
                                        ':sponsorship_id' => $sponsorship_id,
                                        ':amount' => $amount,
                                        ':method' => 'manual',
                                        ':note' => 'Marked paid via sponsor profile',
                                        ':season_id' => $season_id,
                              ]);
                    }

                    recomputePaidFlag($pdo, $sponsorship_id);
                    syncPlayerSponsorshipAgreement($pdo, $sponsorship_id);

                    $pdo->commit();

                    $playerName = $player['name'] ?? 'Unknown Player';
                    auditLog($pdo, 'player_sponsorship_assigned', "Assigned {$slot} slot to {$playerName} for sponsor #{$sponsor_id} (£" . number_format($amount, 2) . ')');
                    echo json_encode([
                              'success' => true,
                              'sponsorship' => [
                                        'id' => $sponsorship_id,
                                        'player_id' => $player_id,
                                        'player' => $playerName,
                                        'slot' => ucfirst($slot),
                                        'amount' => number_format($amount, 2),
                                        'notes' => $notes,
                              ],
                    ]);
                    exit;
          }

          if ($action === 'add_payment') {
                    if (!$canRecordPayments) {
                              throw new Exception('You do not have permission to record payments.');
                    }
                    $amount = (float)($_POST['amount'] ?? 0);
                    $method = trim($_POST['method'] ?? '');
                    $note   = trim($_POST['note'] ?? '');
                    $sponsorship_id = (int)($_POST['sponsorship_id'] ?? 0);

                    if ($amount <= 0) {
                              throw new HubFieldValidationException('amount', "Invalid payment amount");
                    }
                    if ($sponsorship_id <= 0) {
                              throw new HubFieldValidationException('sponsorship_id', "You must select a sponsorship slot");
                    }

                    // Validate sponsorship belongs to this sponsor
                    $stmt = $pdo->prepare("SELECT player_id, slot, amount FROM sponsorships WHERE id = :sid AND sponsor_id = :sponsor_id AND season_id = :season_id AND ended_at IS NULL");
                    $stmt->execute([':sid' => $sponsorship_id, ':sponsor_id' => $sponsor_id, ':season_id' => $season_id]);
                    $sponsorship = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$sponsorship) {
                              throw new HubFieldValidationException('sponsorship_id', "Invalid sponsorship selection");
                    }

                    // Insert payment
                    $stmt = $pdo->prepare("
            INSERT INTO sponsorship_payments (sponsorship_id, amount, paid_at, method, note, season_id)
            VALUES (:sponsorship_id, :amount, NOW(), :method, :note, :season_id)
        ");
                    $stmt->execute([
                              ':sponsorship_id' => $sponsorship_id,
                              ':amount' => $amount,
                              ':method' => $method ?: null,
                              ':note'   => $note ?: null,
                              ':season_id' => $season_id,
                    ]);
                    $newPaymentId = (int)$pdo->lastInsertId();
                    recomputePaidFlag($pdo, $sponsorship_id);
                    syncPlayerSponsorshipAgreement($pdo, $sponsorship_id);

                    // Reload sponsor totals
                    $totals = $pdo->prepare("
            SELECT SUM(sp.amount) AS total_due, COALESCE(SUM(pay.amount),0) AS total_paid
            FROM sponsorships sp
            LEFT JOIN sponsorship_payments pay ON pay.sponsorship_id = sp.id
            WHERE sp.sponsor_id = :id
              AND sp.season_id = :season_id
              AND sp.ended_at IS NULL
        ");
                    $totals->execute([':id' => $sponsor_id, ':season_id' => $season_id]);
                    $totals = $totals->fetch(PDO::FETCH_ASSOC);
                    $total_due = $totals['total_due'] ?? 0;
                    $total_paid = $totals['total_paid'] ?? 0;
                    $total_outstanding = $total_due - $total_paid;
                    $paidPlayerName = getPlayerName($pdo, $sponsorship['player_id']);
                    auditLog($pdo, 'player_sponsorship_payment_added', "Added payment of £" . number_format($amount, 2) . " for {$paidPlayerName} (" . ucfirst($sponsorship['slot']) . ' slot)');

                    echo json_encode([
                              'success' => true,
                              'payment' => [
                                        'id'      => $newPaymentId,
                                        'paid_at' => date("d/m/Y H:i"),
                                        'player'  => $paidPlayerName,
                                        'slot'    => ucfirst($sponsorship['slot']),
                                        'amount'  => number_format($amount, 2),
                                        'method'  => $method,
                                        'note'    => $note,
                              ],
                              'totals' => [
                                        'due' => number_format($total_due, 2),
                                        'paid' => number_format($total_paid, 2),
                                        'outstanding' => number_format($total_outstanding, 2),
                              ]
                    ]);
                    exit;
          }

          if ($action === 'add_note') {
                    $note = trim($_POST['note'] ?? '');
                    if ($note === '') {
                              throw new HubFieldValidationException('note', "Note cannot be empty");
                    }

                    $stmt = $pdo->prepare("
        INSERT INTO sponsor_notes (sponsor_id, note, created_at)
        VALUES (:sponsor_id, :note, NOW())
    ");
                    $stmt->execute([
                              ':sponsor_id' => $sponsor_id,
                              ':note' => $note,
                    ]);
                    auditLog($pdo, 'sponsor_note_added', "Added a note to sponsor #{$sponsor_id}");

                    echo json_encode([
                              'success' => true,
                              'note' => [
                                        'note' => nl2br(h($note)),
                                        'created_at' => date("d/m/Y H:i"),
                              ]
                    ]);
                    exit;
          }


          throw new Exception("Unknown action");
} catch (Exception $e) {
          if ($pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
          }
          $response = ['success' => false, 'error' => $e->getMessage()];
          if ($e instanceof HubFieldValidationException) {
                    $response['field'] = $e->field;
          }
          echo json_encode($response);
          exit;
}

// Helper
function getPlayerName($pdo, $id)
{
          $stmt = $pdo->prepare("SELECT name FROM players WHERE id = :id");
          $stmt->execute([':id' => $id]);
          return $stmt->fetchColumn() ?: 'Unknown Player';
}
