<?php
// sponsorship_delete.php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';

$seasonId = getSelectedSeasonId($pdo);

$id = (int)($_GET['id'] ?? 0);
$playerId = (int)($_GET['player_id'] ?? 0);

if ($id <= 0 || $playerId <= 0) {
          echo '<div class="alert alert-danger">Invalid request.</div>';
          require __DIR__ . '/footer.php';
          exit;
}

try {
          assertSponsorshipEditable($pdo, $seasonId);
} catch (Throwable $e) {
          echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
          require __DIR__ . '/footer.php';
          exit;
}

// Fetch sponsorship
$stmt = $pdo->prepare("
    SELECT s.id, p.name AS player_name, sp.name AS sponsor_name, s.slot
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          $stmt = $pdo->prepare("UPDATE sponsorships SET ended_at = NOW(), ended_reason = 'deleted' WHERE id = :id");
          $stmt->execute([':id' => $id]);
          syncPlayerSponsorshipAgreement($pdo, $id);
          auditLog($pdo, 'player_sponsorship_removed', "Deleted {$sponsorship['slot']} sponsorship by '{$sponsorship['sponsor_name']}' for player '{$sponsorship['player_name']}'");

          header("Location: player_edit.php?id=" . $playerId . "&season_id=" . $seasonId);
          exit;
}
?>

<div>
          <h1 class="h3">Delete Sponsorship</h1>
          <div class="alert alert-warning">
                    Are you sure you want to delete the sponsorship
                    <strong><?= htmlspecialchars($sponsorship['sponsor_name']) ?> (<?= strtoupper($sponsorship['slot']) ?>)</strong>
                    for player <strong><?= htmlspecialchars($sponsorship['player_name']) ?></strong>?
          </div>

          <form method="post">
                    <button type="submit" class="btn btn-danger">Yes, Delete</button>
                    <a href="player_edit.php?id=<?= $playerId ?>&season_id=<?= $seasonId ?>" class="btn btn-secondary">Cancel</a>
          </form>
</div>

<?php require __DIR__ . '/footer.php'; ?>
