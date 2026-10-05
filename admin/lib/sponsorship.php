<?php
// Shared helpers for player leave / sponsorship transfer flows.

function getPlayerSponsorshipRows(PDO $pdo, int $playerId): array
{
          $stmt = $pdo->prepare("
                    SELECT s.id, s.sponsor_id, s.player_id, s.slot, s.amount, s.notes, s.assigned_at,
                           sp.name AS sponsor_name
                    FROM sponsorships s
                    JOIN sponsors sp ON sp.id = s.sponsor_id
                    WHERE s.player_id = :player_id
                    ORDER BY FIELD(UPPER(s.slot), 'HOME', 'AWAY', 'THIRD'), sp.name ASC, s.id ASC
          ");
          $stmt->execute([':player_id' => $playerId]);
          return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getReplacementCandidates(PDO $pdo, int $playerId): array
{
          $stmt = $pdo->prepare("
                    SELECT id, name
                    FROM players
                    WHERE id <> :player_id
                      AND active = 1
                      AND status IN ('current', 'loan', 'injured')
                    ORDER BY name ASC
          ");
          $stmt->execute([':player_id' => $playerId]);
          return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function logSponsorshipHistory(PDO $pdo, array $row, string $action = 'transfer', string $changeType = 'update'): void
{
          $stmt = $pdo->prepare("
                    INSERT INTO sponsorships_history
                              (sponsorship_id, sponsor_id, player_id, slot, amount, notes, action, changed_at, change_type)
                    VALUES
                              (:sponsorship_id, :sponsor_id, :player_id, :slot, :amount, :notes, :action, NOW(), :change_type)
          ");
          $stmt->execute([
                    ':sponsorship_id' => $row['id'] ?? null,
                    ':sponsor_id'     => $row['sponsor_id'] ?? null,
                    ':player_id'      => $row['player_id'] ?? null,
                    ':slot'           => $row['slot'] ?? null,
                    ':amount'         => $row['amount'] ?? null,
                    ':notes'          => $row['notes'] ?? null,
                    ':action'         => $action,
                    ':change_type'    => $changeType,
          ]);
}

function markPlayerLeftAndTransferSponsorships(PDO $pdo, int $playerId, ?int $replacementPlayerId, ?string $leftAt = null): array
{
          $leftAt = $leftAt ?: date('Y-m-d');

          $pdo->beginTransaction();
          try {
                    $stmt = $pdo->prepare("SELECT id, name, left_at, status, active FROM players WHERE id = :id FOR UPDATE");
                    $stmt->execute([':id' => $playerId]);
                    $player = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$player) {
                              throw new RuntimeException('Player not found.');
                    }

                    $replacement = null;
                    if ($replacementPlayerId !== null) {
                              if ($replacementPlayerId === $playerId) {
                                        throw new RuntimeException('Replacement player must be different to the leaving player.');
                              }

                              $stmt = $pdo->prepare("SELECT id, name, active, status FROM players WHERE id = :id FOR UPDATE");
                              $stmt->execute([':id' => $replacementPlayerId]);
                              $replacement = $stmt->fetch(PDO::FETCH_ASSOC);

                              if (!$replacement) {
                                        throw new RuntimeException('Replacement player not found.');
                              }

                              if ((int)$replacement['active'] !== 1) {
                                        throw new RuntimeException('Replacement player must be active.');
                              }
                    }

                    $sponsorships = getPlayerSponsorshipRows($pdo, $playerId);

                    foreach ($sponsorships as $row) {
                              if ($replacementPlayerId !== null) {
                                        $conflict = $pdo->prepare("
                                                  SELECT COUNT(*)
                                                  FROM sponsorships
                                                  WHERE player_id = :player_id
                                                    AND slot = :slot
                                                    AND id <> :sponsorship_id
                                        ");
                                        $conflict->execute([
                                                  ':player_id'      => $replacementPlayerId,
                                                  ':slot'           => $row['slot'],
                                                  ':sponsorship_id' => $row['id'],
                                        ]);

                                        if ((int)$conflict->fetchColumn() > 0) {
                                                  throw new RuntimeException(
                                                            sprintf(
                                                                      'Replacement player already has an active %s sponsorship.',
                                                                      strtoupper((string)$row['slot'])
                                                            )
                                                  );
                                        }
                              }

                              logSponsorshipHistory($pdo, $row, 'transfer', 'update');

                              if ($replacementPlayerId !== null) {
                                        $upd = $pdo->prepare("
                                                  UPDATE sponsorships
                                                  SET player_id = :replacement_player_id,
                                                      assigned_at = NOW()
                                                  WHERE id = :id
                                        ");
                                        $upd->execute([
                                                  ':replacement_player_id' => $replacementPlayerId,
                                                  ':id'                    => $row['id'],
                                        ]);
                              }
                    }

                    $updatePlayer = $pdo->prepare("
                              UPDATE players
                              SET left_at = :left_at,
                                  status = 'left',
                                  active = 0
                              WHERE id = :id
                    ");
                    $updatePlayer->execute([
                              ':left_at' => $leftAt,
                              ':id'      => $playerId,
                    ]);

                    $pdo->commit();

                    return [
                              'player' => $player,
                              'replacement' => $replacement,
                              'sponsorship_count' => count($sponsorships),
                              'transferred' => $replacementPlayerId !== null && !empty($sponsorships),
                    ];
          } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                              $pdo->rollBack();
                    }
                    throw $e;
          }
}

function handlePlayerLeaving(PDO $pdo, int $playerId, ?int $replacementPlayerId = null, ?string $leftAt = null): array
{
          return markPlayerLeftAndTransferSponsorships($pdo, $playerId, $replacementPlayerId, $leftAt);
}
