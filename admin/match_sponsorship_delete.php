<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$id = (int)($_GET['id'] ?? 0);
$matchId = (int)($_GET['match_id'] ?? 0);
$row = $id > 0 ? getMatchSponsorshipById($pdo, $id) : null;

if (!$row) {
          echo '<div><div class="alert alert-danger">Match sponsorship not found.</div></div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

if ($matchId <= 0) {
          $matchId = (int)$row['fixture_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          if (!csrf_check()) {
                    $error = 'Invalid CSRF token.';
          } else {
                    try {
                              endMatchSponsorship($pdo, $id, 'deleted');
                              auditLog($pdo, 'match_sponsorship_ended', "Ended {$row['sponsorship_role']} sponsorship by '{$row['sponsor_name']}' (vs {$row['opponent']})");
                              header('Location: match.php?id=' . $matchId . '&season_id=' . (int)$row['season_id'] . '&deleted=1');
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
                              <h1 class="h4 mb-3">End Match Sponsorship</h1>
                              <?php if (!empty($error)): ?>
                                        <div class="alert alert-danger"><?= h($error) ?></div>
                              <?php endif; ?>
                              <p>End the <?= h(ucfirst(str_replace('_', ' ', (string)$row['sponsorship_role']))) ?> sponsorship for <strong><?= h($row['sponsor_name']) ?></strong>?</p>
                              <p class="text-muted mb-4">Fixture: <?= h(($row['is_home'] ? 'Home' : 'Away') . ' v ' . $row['opponent'] . ' on ' . date('d/m/Y', strtotime((string)$row['match_date']))) ?></p>
                              <form method="post" class="d-flex gap-2">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-danger">End Sponsorship</button>
                                        <a href="match.php?id=<?= (int)$matchId ?>&season_id=<?= (int)$row['season_id'] ?>" class="btn btn-outline-secondary">Cancel</a>
                              </form>
                    </div>
          </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
