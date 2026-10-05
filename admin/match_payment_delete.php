<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$id = (int)($_GET['id'] ?? 0);
$payment = null;

if ($id > 0) {
          $stmt = $pdo->prepare("
                    SELECT pay.*, ms.fixture_id, ms.season_id, ms.sponsor_id, s.name AS sponsor_name, f.match_date, COALESCE(o.clubname, f.opponent) AS opponent, f.is_home
                    FROM match_sponsorship_payments pay
                    JOIN match_sponsorships ms ON ms.id = pay.match_sponsorship_id
                    JOIN sponsors s ON s.id = ms.sponsor_id
                    JOIN match_fixtures f ON f.id = ms.fixture_id
                    LEFT JOIN match_opponents o ON o.id = f.opponent_id
                    WHERE pay.id = :id
                    LIMIT 1
          ");
          $stmt->execute([':id' => $id]);
          $payment = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$payment) {
          echo '<div><div class="alert alert-danger">Payment not found.</div></div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          if (!csrf_check()) {
                    $error = 'Invalid CSRF token.';
          } else {
                    try {
                              $season = getSeasonById($pdo, (int)$payment['season_id']);
                              if ($season && (int)$season['is_locked'] === 1) {
                                        throw new RuntimeException('This season is locked.');
                              }
                              $del = $pdo->prepare("DELETE FROM match_sponsorship_payments WHERE id = :id LIMIT 1");
                              $del->execute([':id' => $id]);
                              recomputeMatchPaidFlag($pdo, (int)$payment['match_sponsorship_id']);
                              auditLog($pdo, 'match_sponsorship_payment_removed', "Deleted payment of " . gbp((float)$payment['amount']) . " for {$payment['sponsor_name']} sponsorship (vs {$payment['opponent']})");
                              header('Location: match.php?id=' . (int)$payment['fixture_id'] . '&season_id=' . (int)$payment['season_id'] . '&payment_deleted=1');
                              exit;
                    } catch (Throwable $e) {
                              $error = $e->getMessage();
                    }
          }
}
?>

<div>
          <div class="card shadow-sm">
                    <div class="card-body">
                              <h1 class="h4 mb-3">Delete Match Payment</h1>
                              <?php if (!empty($error)): ?>
                                        <div class="alert alert-danger"><?= h($error) ?></div>
                              <?php endif; ?>
                              <p>Delete payment of <?= gbp((float)$payment['amount']) ?> for <strong><?= h($payment['sponsor_name']) ?></strong>?</p>
                              <p class="text-muted">Fixture: <?= h(($payment['is_home'] ? 'Home' : 'Away') . ' v ' . $payment['opponent'] . ' on ' . date('d/m/Y', strtotime((string)$payment['match_date']))) ?></p>
                              <form method="post" class="d-flex gap-2">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-danger">Delete Payment</button>
                                        <a href="match.php?id=<?= (int)$payment['fixture_id'] ?>&season_id=<?= (int)$payment['season_id'] ?>" class="btn btn-outline-secondary">Cancel</a>
                              </form>
                    </div>
          </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
