<?php
// sponsorship_edit.php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';

$seasonId = getSelectedSeasonId($pdo);
$allowedSlots = getAllowedSponsorshipSlots($pdo, $seasonId);


$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
          echo '<div class="alert alert-danger">Invalid sponsorship ID.</div>';
          require __DIR__ . '/footer.php';
          exit;
}

// Fetch sponsorship + player
$stmt = $pdo->prepare("
    SELECT s.*, p.name AS player_name, sp.name AS sponsor_name
    FROM sponsorships s
    JOIN players p ON p.id = s.player_id
    JOIN sponsors sp ON sp.id = s.sponsor_id
    WHERE s.id = :id
      AND s.season_id = :season_id
      AND s.ended_at IS NULL
");
$stmt->execute([':id' => $id, ':season_id' => $seasonId]);
$sponsorship = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sponsorship) {
          echo '<div class="alert alert-danger">Sponsorship not found.</div>';
          require __DIR__ . '/footer.php';
          exit;
}

// Fetch all sponsors active in this season for dropdown
$sponsors = getActiveSponsorsForSeason($pdo, $seasonId);

// Fetch slots already taken (same player, excluding this one)
$stmt = $pdo->prepare("SELECT slot FROM sponsorships WHERE player_id=:pid AND season_id = :season_id AND id!=:id AND ended_at IS NULL");
$stmt->execute([':pid' => $sponsorship['player_id'], ':id' => $id, ':season_id' => $seasonId]);
$takenSlots = array_map('strtolower', $stmt->fetchAll(PDO::FETCH_COLUMN));

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          $sponsorId = (int)$_POST['sponsor_id'];
          $slot = strtolower(trim($_POST['slot']));
          $notes = trim($_POST['notes']);

          if (!$sponsorId) $errors[] = "Sponsor is required.";
          if (!in_array($slot, $allowedSlots, true)) $errors[] = "Invalid slot for this season.";
          if ($slot === '' || in_array($slot, $takenSlots)) $errors[] = "Invalid or duplicate slot.";

          if (!$errors) {
                    $stmt = $pdo->prepare("
            UPDATE sponsorships
            SET sponsor_id=:sid, slot=:slot, notes=:notes
            WHERE id=:id
        ");
                    $stmt->execute([
                              ':sid' => $sponsorId,
                              ':slot' => $slot,
                              ':notes' => $notes,
                              ':id' => $id
                    ]);

                    // Recalculate amounts — this can touch every slot this sponsor holds for
                    // this player (home/away/third share one pot), not just the one edited
                    // here, so re-sync all of them rather than just $id.
                    recalculateSponsorAmounts($pdo, (int)$sponsorship['player_id'], $sponsorId, $seasonId);
                    $affectedStmt = $pdo->prepare("SELECT id FROM sponsorships WHERE player_id = :pid AND sponsor_id = :sid AND season_id = :season_id");
                    $affectedStmt->execute([':pid' => (int)$sponsorship['player_id'], ':sid' => $sponsorId, ':season_id' => $seasonId]);
                    foreach ($affectedStmt->fetchAll(PDO::FETCH_COLUMN) as $affectedId) {
                        syncPlayerSponsorshipAgreement($pdo, (int) $affectedId);
                    }
                    $editedSponsorName = (array_column($sponsors, 'name', 'id'))[$sponsorId] ?? ('#' . $sponsorId);
                    auditLog($pdo, 'player_sponsorship_updated', "Updated {$slot} sponsorship for player '{$sponsorship['player_name']}' to sponsor '{$editedSponsorName}'");

                    header("Location: player_edit.php?id=" . $sponsorship['player_id'] . "&season_id=" . $seasonId);
                    exit;
          }
}
?>

<div>
          <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="player_edit.php?id=<?= (int) $sponsorship['player_id'] ?>"><?= htmlspecialchars((string) $sponsorship['player_name']) ?></a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Edit sponsorship</span></nav>
          <h1 class="h3">Edit Sponsorship (<?= htmlspecialchars($sponsorship['player_name']) ?>)</h1>

          <?php if ($errors): ?>
                    <div class="alert alert-danger"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
          <?php endif; ?>

          <form method="post">
                    <div class="mb-3">
                              <label class="form-label">Sponsor</label>
                              <select name="sponsor_id" class="form-select" required>
                                        <?php foreach ($sponsors as $sp): ?>
                                                  <option value="<?= $sp['id'] ?>" <?= $sponsorship['sponsor_id'] == $sp['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars($sp['name']) ?>
                                                  </option>
                                        <?php endforeach; ?>
                              </select>
                    </div>

          <div class="mb-3">
                              <label class="form-label">Slot</label>
                              <select name="slot" class="form-select" required>
                                        <?php foreach ($allowedSlots as $slot): ?>
                                                  <?php if (!in_array($slot, $takenSlots) || $slot === strtolower($sponsorship['slot'])): ?>
                                                            <option value="<?= $slot ?>" <?= strtolower($sponsorship['slot']) === $slot ? 'selected' : '' ?>>
                                                                      <?= ucfirst($slot) ?>
                                                            </option>
                                                  <?php endif; ?>
                                        <?php endforeach; ?>
                              </select>
                    </div>

                    <div class="mb-3">
                              <label class="form-label">Notes</label>
                              <input type="text" name="notes" class="form-control" value="<?= htmlspecialchars($sponsorship['notes']) ?>">
                    </div>

                    <button type="submit" class="btn btn-success">Update Sponsorship</button>
                    <a href="player_edit.php?id=<?= $sponsorship['player_id'] ?>&season_id=<?= $seasonId ?>" class="btn btn-secondary">Cancel</a>
          </form>
</div>

<?php require __DIR__ . '/footer.php'; ?>
