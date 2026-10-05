<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/audit.php';

$seasonId = (int)($_GET['id'] ?? 0);
$season = $seasonId > 0 ? getSeasonById($pdo, $seasonId) : null;

if (!$season) {
          echo '<div><div class="alert alert-danger">Season not found.</div></div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

function seasonCount(PDO $pdo, string $sql, int $seasonId): int
{
          $stmt = $pdo->prepare($sql);
          $stmt->execute([':season_id' => $seasonId]);
          return (int)$stmt->fetchColumn();
}

$dependencies = [
          'fixtures' => seasonCount($pdo, "SELECT COUNT(*) FROM match_fixtures WHERE season_id = :season_id", $seasonId),
          'match sponsorships' => seasonCount($pdo, "SELECT COUNT(*) FROM match_sponsorships WHERE season_id = :season_id", $seasonId),
          'sponsor seasons' => seasonCount($pdo, "SELECT COUNT(*) FROM sponsor_seasons WHERE season_id = :season_id", $seasonId),
          'season sponsorships' => seasonCount($pdo, "SELECT COUNT(*) FROM sponsorships WHERE season_id = :season_id", $seasonId),
          'match payments' => seasonCount($pdo, "SELECT COUNT(*) FROM match_sponsorship_payments WHERE season_id = :season_id", $seasonId),
          'sponsorship payments' => seasonCount($pdo, "SELECT COUNT(*) FROM sponsorship_payments WHERE season_id = :season_id", $seasonId),
];
$hasDependencies = array_sum($dependencies) > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          if (!csrf_check()) {
                    $error = 'Invalid CSRF token.';
          } elseif ((int)$season['is_current'] === 1) {
                    $error = 'The current season cannot be deleted.';
          } elseif ($hasDependencies) {
                    $error = 'This season has related records and cannot be deleted.';
          } else {
                    try {
                              $pdo->beginTransaction();
                              $delPricing = $pdo->prepare("DELETE FROM match_season_pricing WHERE season_id = :season_id");
                              $delPricing->execute([':season_id' => $seasonId]);
                              $delSeason = $pdo->prepare("DELETE FROM seasons WHERE id = :id LIMIT 1");
                              $delSeason->execute([':id' => $seasonId]);
                              if (isset($_SESSION['season_id']) && (int)$_SESSION['season_id'] === $seasonId) {
                                        unset($_SESSION['season_id']);
                              }
                              $pdo->commit();
                              auditLog($pdo, 'season_deleted', "Deleted season '" . (string)$season['name'] . "'");
                              header('Location: seasons.php?deleted=1');
                              exit;
                    } catch (Throwable $e) {
                              if ($pdo->inTransaction()) {
                                        $pdo->rollBack();
                              }
                              $error = $e->getMessage();
                    }
          }
}
?>

<div>
          <div class="card shadow-sm">
                    <div class="card-body">
                              <h1 class="h4 mb-3">Delete Season</h1>
                              <?php if (!empty($error)): ?>
                                        <div class="alert alert-danger"><?= h($error) ?></div>
                              <?php endif; ?>
                              <p>Delete <strong><?= h($season['name']) ?></strong>?</p>
                              <?php if ($hasDependencies): ?>
                                        <div class="alert alert-warning">
                                                  This season cannot be deleted because it already has related records.
                                        </div>
                              <?php endif; ?>
                              <div class="mb-3 small text-muted">
                                        <?php foreach ($dependencies as $label => $count): ?>
                                                  <div><?= h(ucfirst($label)) ?>: <?= (int)$count ?></div>
                                        <?php endforeach; ?>
                              </div>
                              <form method="post" class="d-flex gap-2">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-danger" <?= $hasDependencies ? 'disabled' : '' ?>>Delete Season</button>
                                        <a href="season.php?id=<?= (int)$seasonId ?>" class="btn btn-outline-secondary">Cancel</a>
                              </form>
                    </div>
          </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
