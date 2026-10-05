<?php
// payment_add.php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';

$seasonId = getSelectedSeasonId($pdo);

$playerId = (int)($_GET['player_id'] ?? 0);
$sponsorshipId = (int)($_GET['sponsorship_id'] ?? 0);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          $sponsorshipId = (int)$_POST['sponsorship_id'];
          $amount = (float)$_POST['amount'];
          $method = trim($_POST['method']);
          $note = trim($_POST['note']);

          if ($sponsorshipId > 0 && $amount > 0) {
                    $stmt = $pdo->prepare("
            INSERT INTO sponsorship_payments (sponsorship_id, amount, method, note, season_id) 
            VALUES (:sid, :amount, :method, :note, :season_id)
        ");
                    $stmt->execute([
                              ':sid' => $sponsorshipId,
                              ':amount' => $amount,
                              ':method' => $method,
                              ':note' => $note,
                              ':season_id' => $seasonId
                    ]);
                    recomputePaidFlag($pdo, $sponsorshipId);
                    syncPlayerSponsorshipAgreement($pdo, $sponsorshipId);
                    auditLog($pdo, 'player_sponsorship_payment_added', "Added payment of £" . number_format($amount, 2) . " for sponsorship #{$sponsorshipId} (player #{$playerId})");

                    header("Location: player_view.php?id=" . $playerId . "&season_id=" . $seasonId);
                    exit;
          } else {
                    $errors[] = "Amount and sponsorship are required.";
          }
}
?>

<div>
          <h1 class="h3">Add Payment</h1>

          <?php if ($errors): ?>
                    <div class="alert alert-danger">
                              <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
                    </div>
          <?php endif; ?>

          <form method="post">
                    <input type="hidden" name="sponsorship_id" value="<?= $sponsorshipId ?>">

                    <div class="mb-3">
                              <label class="form-label">Amount</label>
                              <input type="number" name="amount" step="0.01" min="0" class="form-control" required>
                    </div>

                    <div class="mb-3">
                              <label class="form-label">Method</label>
                              <input type="text" name="method" class="form-control" placeholder="Cash, Bank Transfer, etc.">
                    </div>

                    <div class="mb-3">
                              <label class="form-label">Note</label>
                              <input type="text" name="note" class="form-control">
                    </div>

                    <button type="submit" class="btn btn-success">Save Payment</button>
                    <a href="player_view.php?id=<?= $playerId ?>&season_id=<?= $seasonId ?>" class="btn btn-secondary">Cancel</a>
          </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
