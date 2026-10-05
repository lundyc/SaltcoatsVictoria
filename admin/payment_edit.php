<?php
// payment_edit.php
require_once __DIR__ . '/header.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
          echo '<div class="alert alert-danger">Invalid payment ID.</div>';
          require __DIR__ . '/footer.php';
          exit;
}

// Load payment
$stmt = $pdo->prepare("
    SELECT pay.*, s.player_id 
    FROM sponsorship_payments pay
    JOIN sponsorships s ON s.id = pay.sponsorship_id
    WHERE pay.id = :id
");
$stmt->execute([':id' => $id]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
          echo '<div class="alert alert-danger">Payment not found.</div>';
          require __DIR__ . '/footer.php';
          exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          $amount = (float)$_POST['amount'];
          $method = trim($_POST['method']);
          $note = trim($_POST['note']);

          if ($amount > 0) {
                    $stmt = $pdo->prepare("
            UPDATE sponsorship_payments
            SET amount = :amount, method = :method, note = :note
            WHERE id = :id
        ");
                    $stmt->execute([
                              ':amount' => $amount,
                              ':method' => $method,
                              ':note' => $note,
                              ':id' => $id
                    ]);
                    auditLog($pdo, 'player_sponsorship_payment_updated', "Updated payment #{$id} to £" . number_format($amount, 2) . " (player #{$payment['player_id']})");

                    header("Location: player_view.php?id=" . $payment['player_id']);
                    exit;
          } else {
                    $errors[] = "Amount must be greater than 0.";
          }
}
?>

<div>
          <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="player_view.php?id=<?= (int) $payment['player_id'] ?>">Player</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Edit payment</span></nav>
          <h1 class="h3">Edit Payment</h1>

          <?php if ($errors): ?>
                    <div class="alert alert-danger">
                              <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
                    </div>
          <?php endif; ?>

          <form method="post">
                    <div class="mb-3">
                              <label class="form-label">Amount</label>
                              <input type="number" name="amount" step="0.01" min="0" class="form-control"
                                        value="<?= htmlspecialchars($payment['amount']) ?>" required>
                    </div>

                    <div class="mb-3">
                              <label class="form-label">Method</label>
                              <input type="text" name="method" class="form-control"
                                        value="<?= htmlspecialchars($payment['method'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                              <label class="form-label">Note</label>
                              <input type="text" name="note" class="form-control"
                                        value="<?= htmlspecialchars($payment['note'] ?? '') ?>">
                    </div>

                    <button type="submit" class="btn btn-success">Update Payment</button>
                    <a href="player_view.php?id=<?= $payment['player_id'] ?>" class="btn btn-secondary">Cancel</a>
          </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
