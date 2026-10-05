<?php

// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}
// player_edit_ajax.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php'; // for recalc + helpers
require_once __DIR__ . '/lib/player_birthdays.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/sync_social_directory.php';
require_once __DIR__ . '/players_lib.php';
require_once __DIR__ . '/lib/audit.php';

header('Content-Type: application/json');

$action   = $_POST['action'] ?? '';
$response = ['success' => false];
$seasonId = getSelectedSeasonId($pdo);
$allowedSlots = getAllowedSponsorshipSlots($pdo, $seasonId);
$playerEditSlots = array_values(array_intersect($allowedSlots, ['home', 'away']));

try {
          if ($action === 'update_player') {
                    $id    = (int)($_POST['id'] ?? 0);
                    $field = $_POST['field'] ?? '';
                    $value = $_POST['value'] ?? '';

                    $allowed = ['name', 'date_of_birth', 'status', 'joined_at', 'left_at', 'active', 'position'];
                    if ($id > 0 && in_array($field, $allowed, true)) {
                              if ($field === 'status' && !in_array($value, ['current', 'trialist', 'left', 'retired', 'loan', 'injured'], true)) {
                                        throw new InvalidArgumentException('Choose a valid player status.');
                              }
                              if ($field === 'position') {
                                        $value = trim((string) $value);
                                        if ($value !== '' && !array_key_exists($value, players_position_options())) {
                                                  throw new InvalidArgumentException('Choose a valid position.');
                                        }
                                        $value = $value !== '' ? $value : null;
                              }
                              if (in_array($field, ['date_of_birth', 'joined_at', 'left_at'], true)) {
                                        $value = trim((string) $value);
                                        if ($value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                                                  throw new InvalidArgumentException('Date fields must use the YYYY-MM-DD format.');
                                        }
                                        $value = $value !== '' ? $value : null;
                              }
                              if ($field === 'date_of_birth') {
                                        players_ensure_date_of_birth_column($pdo);
                              }
                              $stmt = $pdo->prepare("UPDATE players SET $field = :val WHERE id = :id");
                              $stmt->execute([':val' => $value, ':id' => $id]);
                              auditLog($pdo, 'player_field_updated', "Updated {$field} for player #{$id}");
                              player_sponsors_sync_social_directory();
                              $response['success'] = true;
                    }
          } elseif ($action === 'mark_paid') {
                    $sponsorshipId = (int)($_POST['id'] ?? 0);
                    $amount        = (float)($_POST['amount'] ?? 0);

                    if ($sponsorshipId > 0 && $amount > 0) {
                              // Validate sponsorship exists
                              $stmt = $pdo->prepare("SELECT player_id, sponsor_id FROM sponsorships WHERE id=:id");
                              $stmt->execute([':id' => $sponsorshipId]);
                              $sponsorship = $stmt->fetch(PDO::FETCH_ASSOC);

                              if ($sponsorship) {
                                        $stmt = $pdo->prepare("
                    INSERT INTO sponsorship_payments (sponsorship_id, amount, paid_at, method, note, season_id)
                    VALUES (:sid, :amt, NOW(), 'manual', 'Marked paid via admin', :season_id)
                ");
                                        $stmt->execute([
                                                  ':sid' => $sponsorshipId,
                                                  ':amt' => $amount,
                                                  ':season_id' => $seasonId
                                        ]);
                                        auditLog($pdo, 'sponsorship_payment_recorded', "Recorded payment of {$amount} for sponsorship #{$sponsorshipId} (player #{$sponsorship['player_id']})");

                                        // Optionally recalc sponsor amounts (keeps DB tidy)
                                        recalculateSponsorAmounts($pdo, (int)$sponsorship['player_id'], (int)$sponsorship['sponsor_id'], $seasonId);
                                        recomputePaidFlag($pdo, $sponsorshipId);
                                        syncPlayerSponsorshipAgreement($pdo, $sponsorshipId);

                                        player_sponsors_sync_social_directory();
                                        $response['success'] = true;
                              }
                    }
          } elseif ($action === 'update_sponsorship') {
                    $id    = (int)($_POST['id'] ?? 0);
                    $field = $_POST['field'] ?? '';
                    $value = $_POST['value'] ?? '';

                    $allowed = ['notes']; // amount now readonly
                    if ($id > 0 && in_array($field, $allowed, true)) {
                              $rowSeasonStmt = $pdo->prepare("SELECT season_id FROM sponsorships WHERE id = :id LIMIT 1");
                              $rowSeasonStmt->execute([':id' => $id]);
                              assertSponsorshipEditable($pdo, (int) $rowSeasonStmt->fetchColumn() ?: $seasonId);

                              $stmt = $pdo->prepare("UPDATE sponsorships SET $field = :val WHERE id = :id");
                              $stmt->execute([':val' => $value, ':id' => $id]);
                              auditLog($pdo, 'sponsorship_updated', "Updated {$field} for sponsorship #{$id}");
                              syncPlayerSponsorshipAgreement($pdo, $id);
                              $response['success'] = true;
                    }
          } elseif ($action === 'delete_sponsorship') {
                    $id = (int)($_POST['id'] ?? 0);
                    if ($id > 0) {
                              $rowSeasonStmt = $pdo->prepare("SELECT season_id FROM sponsorships WHERE id = :id LIMIT 1");
                              $rowSeasonStmt->execute([':id' => $id]);
                              assertSponsorshipEditable($pdo, (int) $rowSeasonStmt->fetchColumn() ?: $seasonId);

                              $stmt = $pdo->prepare("UPDATE sponsorships SET ended_at = NOW(), ended_reason = 'deleted' WHERE id = :id");
                              $stmt->execute([':id' => $id]);
                              auditLog($pdo, 'sponsorship_ended', "Ended sponsorship #{$id}");
                              syncPlayerSponsorshipAgreement($pdo, $id);
                              $response['success'] = true;
                    }
          } elseif ($action === 'add_sponsorship') {
                    $playerId  = (int)($_POST['player_id'] ?? 0);
                    $sponsorId = (int)($_POST['sponsor_id'] ?? 0);
                    $slot      = strtolower(trim($_POST['slot'] ?? ''));
                    $notes     = trim($_POST['notes'] ?? '');
                    $complimentary = !empty($_POST['complimentary']) ? 1 : 0;
                    $postSeasonId = (int)($_POST['season_id'] ?? 0);
                    if ($postSeasonId > 0) {
                              $seasonId = $postSeasonId;
                              $allowedSlots = getAllowedSponsorshipSlots($pdo, $seasonId);
                              $playerEditSlots = array_values(array_intersect($allowedSlots, ['home', 'away']));
                    }

                    if ($playerId > 0 && $sponsorId > 0 && in_array($slot, $playerEditSlots, true)) {
                              assertSponsorshipEditable($pdo, $seasonId);

                              $existingStmt = $pdo->prepare("
                                        SELECT id, ended_at
                                        FROM sponsorships
                                        WHERE player_id = :pid
                                          AND slot = :slot
                                          AND season_id = :season_id
                                        LIMIT 1
                              ");
                              $existingStmt->execute([
                                        ':pid' => $playerId,
                                        ':slot' => $slot,
                                        ':season_id' => $seasonId,
                              ]);
                              $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);

                              $sponsorshipCreated = false;
                              if ($existing && empty($existing['ended_at'])) {
                                        throw new Exception(strtoupper($slot) . " sponsorship already exists for this player");
                              } elseif ($existing) {
                                        $stmt = $pdo->prepare("
                                                  UPDATE sponsorships
                                                  SET sponsor_id = :sid,
                                                      amount = 0,
                                                      complimentary = :complimentary,
                                                      notes = :notes,
                                                      paid = :paid,
                                                      ended_at = NULL,
                                                      ended_reason = NULL,
                                                      assigned_at = NOW(),
                                                      started_at = NOW()
                                                  WHERE id = :id
                                        ");
                                        $stmt->execute([
                                                  ':sid' => $sponsorId,
                                                  ':complimentary' => $complimentary,
                                                  ':notes' => $notes ?: null,
                                                  ':paid' => $complimentary ? 1 : 0,
                                                  ':id' => (int)$existing['id'],
                                        ]);
                                        $id = (int)$existing['id'];
                              } else {
                                        $stmt = $pdo->prepare("
                                                  INSERT INTO sponsorships (season_id, player_id, sponsor_id, slot, amount, complimentary, paid, notes, assigned_at, started_at)
                                                  VALUES (:season_id, :pid, :sid, :slot, 0, :complimentary, :paid, :notes, NOW(), NOW())
                                        ");
                                        $stmt->execute([
                                                  ':season_id' => $seasonId,
                                                  ':pid'   => $playerId,
                                                  ':sid'   => $sponsorId,
                                                  ':slot'  => $slot,
                                                  ':complimentary' => $complimentary,
                                                  ':paid' => $complimentary ? 1 : 0,
                                                  ':notes' => $notes ?: null
                                        ]);
                                        $id = (int)$pdo->lastInsertId();
                                        $sponsorshipCreated = true;
                              }

                              auditLog($pdo, $sponsorshipCreated ? 'sponsorship_added' : 'sponsorship_updated', ($sponsorshipCreated ? 'Added' : 'Updated') . " {$slot} sponsorship for player #{$playerId}" . ($complimentary ? ' (complimentary)' : ''));

                              if (!$complimentary) {
                                        recalculateSponsorAmounts($pdo, $playerId, $sponsorId, $seasonId);
                              }
                              $affectedStmt = $pdo->prepare("SELECT id FROM sponsorships WHERE player_id = :pid AND sponsor_id = :sid AND season_id = :season_id");
                              $affectedStmt->execute([':pid' => $playerId, ':sid' => $sponsorId, ':season_id' => $seasonId]);
                              foreach ($affectedStmt->fetchAll(PDO::FETCH_COLUMN) as $affectedId) {
                                        syncPlayerSponsorshipAgreement($pdo, (int) $affectedId);
                              }
                              $response['success'] = true;
                    } else {
                              throw new Exception("Select an available home or away sponsorship slot");
                    }
          } elseif ($action === 'update_player_status_with_sponsorships') {
                    $playerId = (int)($_POST['id'] ?? 0);
                    $status = $_POST['status'] ?? '';
                    $joinedAt = $_POST['joined_at'] ?? null;
                    $leftAt = $_POST['left_at'] ?? null;
                    $active = (int)($_POST['active'] ?? 0);
                    $sponsorshipAction = $_POST['sponsorship_action'] ?? 'keep';
                    $replacementPlayerId = (int)($_POST['replacement_player_id'] ?? 0);
                    $replacementHomePlayerId = (int)($_POST['replacement_home_player_id'] ?? 0);
                    $replacementAwayPlayerId = (int)($_POST['replacement_away_player_id'] ?? 0);
                    $postSeasonId = (int)($_POST['season_id'] ?? 0);
                    if ($postSeasonId > 0) {
                              $seasonId = $postSeasonId;
                              $allowedSlots = getAllowedSponsorshipSlots($pdo, $seasonId);
                              $playerEditSlots = array_values(array_intersect($allowedSlots, ['home', 'away']));
                    }

                    if ($playerId <= 0) {
                              throw new Exception('Invalid player selected.');
                    }
                    if (!in_array($status, ['current', 'trialist', 'left', 'retired', 'loan', 'injured'], true)) {
                              throw new Exception('Choose a valid player status.');
                    }
                    if (in_array($status, ['left', 'retired'], true) && $sponsorshipAction === 'transfer' && $replacementHomePlayerId <= 0 && $replacementAwayPlayerId <= 0 && $replacementPlayerId <= 0) {
                              throw new Exception('Select a replacement player to transfer sponsorships.');
                    }

                    $playerStmt = $pdo->prepare('SELECT id FROM players WHERE id = :id LIMIT 1');
                    $playerStmt->execute([':id' => $playerId]);
                    if (!$playerStmt->fetch(PDO::FETCH_ASSOC)) {
                              throw new Exception('Player not found.');
                    }

                    $pdo->beginTransaction();
                    try {
                              $stmt = $pdo->prepare('UPDATE players SET status = :status, joined_at = :joined_at, left_at = :left_at, active = :active WHERE id = :id');
                              $stmt->execute([
                                        ':status' => $status,
                                        ':joined_at' => $joinedAt ?: null,
                                        ':left_at' => $leftAt ?: null,
                                        ':active' => $active,
                                        ':id' => $playerId,
                              ]);

                              if (in_array($status, ['left', 'retired'], true)) {
                                        $assignmentsStmt = $pdo->prepare(
                                                  'SELECT id, slot FROM sponsorships WHERE player_id = :player_id AND season_id = :season_id AND ended_at IS NULL'
                                        );
                                        $assignmentsStmt->execute([
                                                  ':player_id' => $playerId,
                                                  ':season_id' => $seasonId,
                                        ]);
                                        $assignments = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);

                                        if ($assignments && in_array($sponsorshipAction, ['transfer', 'unassign'], true)) {
                                                  assertSponsorshipEditable($pdo, $seasonId);
                                        }

                                        if ($sponsorshipAction === 'transfer' && $assignments) {
                                                  $transferTargets = [];

                                                  foreach ($assignments as $assignment) {
                                                            $slot = strtolower($assignment['slot']);
                                                            $targetPlayerId = 0;

                                                            if ($slot === 'home' && $replacementHomePlayerId > 0) {
                                                                      $targetPlayerId = $replacementHomePlayerId;
                                                            } elseif ($slot === 'away' && $replacementAwayPlayerId > 0) {
                                                                      $targetPlayerId = $replacementAwayPlayerId;
                                                            } elseif ($replacementPlayerId > 0) {
                                                                      $targetPlayerId = $replacementPlayerId;
                                                            }

                                                            if ($targetPlayerId > 0) {
                                                                      $transferTargets[$assignment['id']] = [
                                                                                'slot' => $slot,
                                                                                'target' => $targetPlayerId,
                                                                      ];
                                                            }
                                                  }

                                                  if (empty($transferTargets)) {
                                                            throw new Exception('Select a replacement player to transfer sponsorships.');
                                                  }

                                                  $targetPlayerIds = array_unique(array_column($transferTargets, 'target'));
                                                  $activeStmt = $pdo->prepare('SELECT id FROM players WHERE id IN (' . implode(', ', array_fill(0, count($targetPlayerIds), '?')) . ') AND active = 1');
                                                  $activeStmt->execute($targetPlayerIds);
                                                  $activeIds = $activeStmt->fetchAll(PDO::FETCH_COLUMN);

                                                  foreach ($targetPlayerIds as $targetPlayerId) {
                                                            if (!in_array($targetPlayerId, $activeIds, true)) {
                                                                      throw new Exception('Replacement player must be active.');
                                                            }
                                                  }

                                                  $conflictStmt = $pdo->prepare(
                                                            'SELECT slot FROM sponsorships WHERE player_id = :replacement_id AND season_id = :season_id AND ended_at IS NULL AND slot = :slot'
                                                  );

                                                  foreach ($transferTargets as $assignmentId => $transferInfo) {
                                                            if ($transferInfo['target'] === $playerId) {
                                                                      throw new Exception('Replacement player must be different from the original player.');
                                                            }

                                                            $conflictStmt->execute([
                                                                      ':replacement_id' => $transferInfo['target'],
                                                                      ':season_id' => $seasonId,
                                                                      ':slot' => $transferInfo['slot'],
                                                            ]);
                                                            if ($conflictStmt->fetchColumn()) {
                                                                      throw new Exception('Replacement player already has the ' . strtoupper($transferInfo['slot']) . ' slot.');
                                                            }
                                                  }

                                                  $moveStmt = $pdo->prepare(
                                                            'UPDATE sponsorships SET player_id = :replacement_player_id, assigned_at = NOW() WHERE id = :id'
                                                  );
                                                  foreach ($transferTargets as $assignmentId => $transferInfo) {
                                                            $moveStmt->execute([
                                                                      ':replacement_player_id' => $transferInfo['target'],
                                                                      ':id' => $assignmentId,
                                                            ]);
                                                            syncPlayerSponsorshipAgreement($pdo, (int) $assignmentId);
                                                  }
                                        } elseif ($sponsorshipAction === 'unassign' && $assignments) {
                                                  $unassignStmt = $pdo->prepare(
                                                            "UPDATE sponsorships SET ended_at = NOW(), ended_reason = 'unassigned' WHERE id = :id"
                                                  );
                                                  foreach ($assignments as $assignment) {
                                                            $unassignStmt->execute([':id' => $assignment['id']]);
                                                            syncPlayerSponsorshipAgreement($pdo, (int) $assignment['id']);
                                                  }
                                        }
                              }

                              $pdo->commit();
                              auditLog($pdo, 'player_status_updated', "Updated player #{$playerId} status to '{$status}'");
                              player_sponsors_sync_social_directory();
                              $response['success'] = true;
                    } catch (Throwable $statusError) {
                              $pdo->rollBack();
                              throw $statusError;
                    }
          } elseif ($action === 'transfer_player_sponsorships') {
                    $playerId = (int)($_POST['player_id'] ?? 0);
                    $replacementPlayerId = (int)($_POST['replacement_player_id'] ?? 0);
                    $postSeasonId = (int)($_POST['season_id'] ?? 0);
                    if ($postSeasonId > 0) {
                              $seasonId = $postSeasonId;
                              $allowedSlots = getAllowedSponsorshipSlots($pdo, $seasonId);
                              $playerEditSlots = array_values(array_intersect($allowedSlots, ['home', 'away']));
                    }

                    if ($playerId <= 0 || $replacementPlayerId <= 0 || $playerId === $replacementPlayerId) {
                              throw new Exception("Select a valid replacement player");
                    }

                    assertSponsorshipEditable($pdo, $seasonId);

                    $playerStmt = $pdo->prepare("SELECT id, name, status, active, left_at FROM players WHERE id = :id LIMIT 1");
                    $playerStmt->execute([':id' => $playerId]);
                    $player = $playerStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$player) {
                              throw new Exception("Player not found");
                    }

                    $replacementStmt = $pdo->prepare("SELECT id, name, status, active FROM players WHERE id = :id LIMIT 1");
                    $replacementStmt->execute([':id' => $replacementPlayerId]);
                    $replacement = $replacementStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$replacement || (int)$replacement['active'] !== 1) {
                              throw new Exception("Replacement player must be active");
                    }

                    $assignmentsStmt = $pdo->prepare("
                              SELECT s.id, s.sponsor_id, s.slot, s.amount, s.notes, sp.name AS sponsor_name
                              FROM sponsorships s
                              JOIN sponsors sp ON sp.id = s.sponsor_id
                              WHERE s.player_id = :player_id
                                AND s.season_id = :season_id
                                AND s.ended_at IS NULL
                                AND LOWER(s.slot) IN ('home', 'away')
                              ORDER BY FIELD(UPPER(s.slot),'HOME','AWAY'), s.id ASC
                    ");
                    $assignmentsStmt->execute([
                              ':player_id' => $playerId,
                              ':season_id' => $seasonId,
                    ]);
                    $assignments = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!$assignments) {
                              throw new Exception("No active sponsorships to transfer");
                    }

                    $conflictStmt = $pdo->prepare("
                              SELECT slot
                              FROM sponsorships
                              WHERE player_id = :replacement_id
                                AND season_id = :season_id
                                AND ended_at IS NULL
                    ");
                    $conflictStmt->execute([
                              ':replacement_id' => $replacementPlayerId,
                              ':season_id' => $seasonId,
                    ]);
                    $occupiedSlots = array_map('strtolower', $conflictStmt->fetchAll(PDO::FETCH_COLUMN));

                    foreach ($assignments as $assignment) {
                              if (in_array(strtolower($assignment['slot']), $occupiedSlots, true)) {
                                        throw new Exception("Replacement player already has the " . strtoupper($assignment['slot']) . " slot");
                              }
                    }

                              $pdo->beginTransaction();
                    try {
                              $historyStmt = $pdo->prepare("
                                        INSERT INTO sponsorships_history
                                                  (season_id, sponsorship_id, sponsor_id, player_id, slot, amount, notes, action, changed_at, change_type)
                                        VALUES
                                                  (:season_id, :sponsorship_id, :sponsor_id, :player_id, :slot, :amount, :notes, 'transfer', NOW(), 'update')
                              ");
                              $moveStmt = $pdo->prepare("
                                        UPDATE sponsorships
                                        SET player_id = :replacement_player_id,
                                            assigned_at = NOW()
                                        WHERE id = :id
                              ");

                              foreach ($assignments as $assignment) {
                                        $historyStmt->execute([
                                                  ':season_id' => $seasonId,
                                                  ':sponsorship_id' => $assignment['id'],
                                                  ':sponsor_id' => $assignment['sponsor_id'],
                                                  ':player_id' => $playerId,
                                                  ':slot' => $assignment['slot'],
                                                  ':amount' => $assignment['amount'],
                                                  ':notes' => $assignment['notes'],
                                        ]);

                                        $moveStmt->execute([
                                                  ':replacement_player_id' => $replacementPlayerId,
                                                  ':id' => $assignment['id'],
                                        ]);
                                        syncPlayerSponsorshipAgreement($pdo, (int) $assignment['id']);
                              }

                              $pdo->commit();
                              auditLog($pdo, 'player_sponsorships_transferred', 'Transferred ' . count($assignments) . " sponsorship(s) from '{$player['name']}' to '{$replacement['name']}'");
                              player_sponsors_sync_social_directory();
                              $response['success'] = true;
                    } catch (Throwable $transferError) {
                              $pdo->rollBack();
                              throw $transferError;
                    }
          }
} catch (Exception $e) {
          $response['success'] = false;
          $response['error']   = $e->getMessage();
}

echo json_encode($response);
