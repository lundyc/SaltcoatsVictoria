<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/sync_social_directory.php';
require_once __DIR__ . '/lib/audit.php';

// Explicit guard: the writes below run before header.php, and the only check
// so far was sync_social_directory.php's unrelated content_social gate.
if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
hub_auth_require_capability('matchday');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
          echo '<div><div class="alert alert-danger">Invalid player ID.</div></div>';
          require __DIR__ . '/footer.php';
          exit;
}

$stmt = $pdo->prepare("SELECT * FROM players WHERE id = :id");
$stmt->execute([':id' => $id]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$player) {
          echo '<div><div class="alert alert-danger">Player not found.</div></div>';
          require __DIR__ . '/footer.php';
          exit;
}

$seasonId = getSelectedSeasonId($pdo);
$season = getSeasonById($pdo, $seasonId);

$errors = [];
$success = false;
$formLeftAt = $player['left_at'] ?: date('Y-m-d');
$formReplacementPlayerId = '';

$currentSponsorshipsStmt = $pdo->prepare("
          SELECT s.id, s.sponsor_id, s.player_id, s.slot, s.amount, s.notes, s.assigned_at, sp.name AS sponsor_name
          FROM sponsorships s
          JOIN sponsors sp ON sp.id = s.sponsor_id
          WHERE s.player_id = :player_id
            AND s.season_id = :season_id
            AND s.ended_at IS NULL
          ORDER BY FIELD(UPPER(s.slot), 'HOME', 'AWAY', 'THIRD'), sp.name ASC, s.id ASC
");
$currentSponsorshipsStmt->execute([
          ':player_id' => $id,
          ':season_id' => $seasonId,
]);
$sponsorships = $currentSponsorshipsStmt->fetchAll(PDO::FETCH_ASSOC);

$replacementPlayersStmt = $pdo->prepare("
          SELECT id, name
          FROM players
          WHERE id <> :player_id
            AND active = 1
          ORDER BY name ASC
");
$replacementPlayersStmt->execute([':player_id' => $id]);
$replacementPlayers = $replacementPlayersStmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          if (!csrf_check()) {
                    $errors[] = 'Invalid CSRF token.';
          }

          $leftAt = trim($_POST['left_at'] ?? '');
          $formLeftAt = $leftAt !== '' ? $leftAt : date('Y-m-d');

          $replacementPlayerId = (int)($_POST['replacement_player_id'] ?? 0);
          $formReplacementPlayerId = $replacementPlayerId > 0 ? (string)$replacementPlayerId : '';
          if ($replacementPlayerId <= 0) {
                    $replacementPlayerId = null;
          }

          if ($leftAt === '') {
                    $leftAt = date('Y-m-d');
          }

          if (!$errors) {
                    try {
                              $season = getSeasonById($pdo, $seasonId);
                              if (!$season) {
                                        throw new RuntimeException('Season not found.');
                              }
                              if ((int)$season['is_locked'] === 1) {
                                        throw new RuntimeException('This season is locked.');
                              }

                              $playerStmt = $pdo->prepare("SELECT id, name, status, active FROM players WHERE id = :id FOR UPDATE");
                              $replacementStmt = $pdo->prepare("SELECT id, name, status, active FROM players WHERE id = :id FOR UPDATE");
                              $pdo->beginTransaction();

                              $playerStmt->execute([':id' => $id]);
                              $lockedPlayer = $playerStmt->fetch(PDO::FETCH_ASSOC);
                              if (!$lockedPlayer) {
                                        throw new RuntimeException('Player not found.');
                              }

                              $lockedReplacement = null;
                              if ($replacementPlayerId !== null) {
                                        if ($replacementPlayerId === $id) {
                                                  throw new RuntimeException('Replacement player must be different to the leaving player.');
                                        }

                                        $replacementStmt->execute([':id' => $replacementPlayerId]);
                                        $lockedReplacement = $replacementStmt->fetch(PDO::FETCH_ASSOC);
                                        if (!$lockedReplacement) {
                                                  throw new RuntimeException('Replacement player not found.');
                                        }
                                        if ((int)$lockedReplacement['active'] !== 1) {
                                                  throw new RuntimeException('Replacement player must be active.');
                                        }
                              }

                              $rows = [];
                              if ($replacementPlayerId !== null) {
                                        $rowsStmt = $pdo->prepare("
                                                  SELECT s.id, s.sponsor_id, s.slot, s.amount, s.notes
                                                  FROM sponsorships s
                                                  WHERE s.player_id = :player_id
                                                    AND s.season_id = :season_id
                                                    AND s.ended_at IS NULL
                                                  ORDER BY FIELD(UPPER(s.slot), 'HOME', 'AWAY', 'THIRD'), s.id ASC
                                        ");
                                        $rowsStmt->execute([
                                                  ':player_id' => $id,
                                                  ':season_id' => $seasonId,
                                        ]);
                                        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);
                              }

                              if ($replacementPlayerId !== null && $rows) {
                                        $conflictStmt = $pdo->prepare("
                                                  SELECT slot
                                                  FROM sponsorships
                                                  WHERE player_id = :player_id
                                                    AND season_id = :season_id
                                                    AND ended_at IS NULL
                                        ");
                                        $conflictStmt->execute([
                                                  ':player_id' => $replacementPlayerId,
                                                  ':season_id' => $seasonId,
                                        ]);
                                        $occupiedSlots = array_map('strtolower', $conflictStmt->fetchAll(PDO::FETCH_COLUMN));

                                        foreach ($rows as $row) {
                                                  if (in_array(strtolower($row['slot']), $occupiedSlots, true)) {
                                                            throw new RuntimeException('Replacement player already has the ' . strtoupper($row['slot']) . ' slot.');
                                                  }
                                        }
                              }

                              if ($replacementPlayerId !== null) {
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

                                        foreach ($rows as $row) {
                                                  $historyStmt->execute([
                                                            ':season_id' => $seasonId,
                                                            ':sponsorship_id' => $row['id'],
                                                            ':sponsor_id' => $row['sponsor_id'],
                                                            ':player_id' => $id,
                                                            ':slot' => $row['slot'],
                                                            ':amount' => $row['amount'],
                                                            ':notes' => $row['notes'],
                                                  ]);

                                                  $moveStmt->execute([
                                                            ':replacement_player_id' => $replacementPlayerId,
                                                            ':id' => $row['id'],
                                                  ]);
                                                  syncPlayerSponsorshipAgreement($pdo, (int) $row['id']);
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
                                        ':id' => $id,
                              ]);

                              $pdo->commit();
                              $success = true;
                              player_sponsors_sync_social_directory();

                              auditLog($pdo, 'player_left', "Marked player '" . (string) $player['name'] . "' as left as of {$leftAt}" . ($lockedReplacement ? ", sponsorships transferred to '" . (string) $lockedReplacement['name'] . "'" : ''));

                              if ($replacementPlayerId !== null) {
                                        header('Location: player_edit.php?id=' . (int)$replacementPlayerId . '&transfer=1&season_id=' . $seasonId);
                              } else {
                                        header('Location: player_edit.php?id=' . $id . '&left=1&season_id=' . $seasonId);
                              }
                              exit;
                    } catch (Throwable $e) {
                              if ($pdo->inTransaction()) {
                                        $pdo->rollBack();
                              }
                              $errors[] = $e->getMessage();
                    }
          }
}

require_once __DIR__ . '/header.php';
?>

<div>
          <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                              <h1 class="h3 mb-1">Mark Player as Left</h1>
                              <div class="text-muted"><?= htmlspecialchars($player['name']) ?></div>
                              <?php if ($season): ?>
                                        <div class="small text-muted">Season: <?= htmlspecialchars($season['name']) ?></div>
                              <?php endif; ?>
                    </div>
                    <a href="player_edit.php?id=<?= $id ?>&season_id=<?= $seasonId ?>" class="btn btn-outline-secondary">Back to Player</a>
          </div>

          <?php if ($errors): ?>
                    <div class="alert alert-danger">
                              <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
                    </div>
          <?php endif; ?>

          <div class="row g-3">
                    <div class="col-lg-5">
                              <div class="card shadow-sm">
                                        <div class="card-body">
                                                  <h5 class="card-title">Leaving Details</h5>
                                                  <form method="post">
                                                            <?= csrf_field() ?>
                                                            <div class="mb-3">
                                                                      <label class="form-label">Leave date</label>
                                                                      <input type="date" name="left_at" class="form-control" value="<?= htmlspecialchars($formLeftAt) ?>">
                                                            </div>

                                                            <div class="mb-3">
                                                                      <label class="form-label">Transfer sponsorships to</label>
                                                                      <select name="replacement_player_id" class="form-select">
                                                                                <option value="">Do not transfer yet</option>
                                                                                <?php foreach ($replacementPlayers as $candidate): ?>
                                                                                          <option value="<?= (int)$candidate['id'] ?>" <?= $formReplacementPlayerId === (string)$candidate['id'] ? 'selected' : '' ?>>
                                                                                                    <?= htmlspecialchars($candidate['name']) ?>
                                                                                          </option>
                                                                                <?php endforeach; ?>
                                                                      </select>
                                                                      <div class="form-text">
                                                                                If selected, the current season sponsorships are ended and recreated for the replacement player.
                                                                      </div>
                                                            </div>

                                                            <button type="submit" class="btn btn-danger">
                                                                      Save Leave Status
                                                            </button>
                                                  </form>
                                        </div>
                              </div>
                    </div>

                    <div class="col-lg-7">
                              <div class="card shadow-sm">
                                        <div class="card-body">
                                                  <h5 class="card-title">Current Sponsorships</h5>
                                                  <?php if ($sponsorships): ?>
                                                            <div class="table-responsive">
                                                                      <table class="table table-sm align-middle mb-0 hub-data-table">
                                                                                <thead>
                                                                                          <tr>
                                                                                                    <th>Sponsor</th>
                                                                                                    <th>Slot</th>
                                                                                                    <th>Amount</th>
                                                                                                    <th>Notes</th>
                                                                                          </tr>
                                                                                </thead>
                                                                                <tbody>
                                                                                          <?php foreach ($sponsorships as $row): ?>
                                                                                                    <tr>
                                                                                                              <td><?= htmlspecialchars($row['sponsor_name']) ?></td>
                                                                                                              <td><?= htmlspecialchars(strtoupper($row['slot'])) ?></td>
                                                                                                              <td>£<?= number_format((float)$row['amount'], 2) ?></td>
                                                                                                              <td><?= htmlspecialchars((string)$row['notes']) ?></td>
                                                                                                    </tr>
                                                                                          <?php endforeach; ?>
                                                                                </tbody>
                                                                      </table>
                                                            </div>
                                                  <?php else: ?>
                                                            <p class="text-muted mb-0">This player has no sponsorship rows in the selected season.</p>
                                                  <?php endif; ?>
                                        </div>
                              </div>
                    </div>
          </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
