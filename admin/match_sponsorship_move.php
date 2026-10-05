<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$id = (int)($_GET['id'] ?? 0);
$row = $id > 0 ? getMatchSponsorshipById($pdo, $id) : null;

if (!$row) {
          echo '<div><div class="alert alert-danger">Match sponsorship not found.</div></div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

$seasonId = (int)$row['season_id'];
$fixtures = $pdo->prepare("
          SELECT id, match_date, opponent, is_home, status
          FROM match_fixtures
          WHERE season_id = :season_id
            AND id <> :id
          ORDER BY match_date ASC, id ASC
");
$fixtures->execute([
          ':season_id' => $seasonId,
          ':id' => (int)$row['fixture_id'],
]);
$fixtureOptions = $fixtures->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          if (!csrf_check()) {
                    $error = 'Invalid CSRF token.';
          } else {
                    $toFixtureId = (int)($_POST['to_fixture_id'] ?? 0);
                    try {
                              moveMatchSponsorship($pdo, $id, $toFixtureId);
                              $targetLabel = '#' . $toFixtureId;
                              foreach ($fixtureOptions as $fixtureOption) {
                                        if ((int)$fixtureOption['id'] === $toFixtureId) {
                                                  $targetLabel = 'vs ' . $fixtureOption['opponent'] . ' on ' . date('d/m/Y', strtotime((string)$fixtureOption['match_date']));
                                                  break;
                                        }
                              }
                              auditLog($pdo, 'match_sponsorship_moved', "Moved {$row['sponsorship_role']} sponsorship by '{$row['sponsor_name']}' from vs {$row['opponent']} to {$targetLabel}");
                              header('Location: match.php?id=' . $toFixtureId . '&season_id=' . $seasonId . '&moved=1');
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
                              <h1 class="h4 mb-3">Move Match Sponsorship</h1>
                              <?php if (!empty($error)): ?>
                                        <div class="alert alert-danger"><?= h($error) ?></div>
                              <?php endif; ?>
                              <p class="mb-3">Move the <strong><?= h(ucfirst(str_replace('_', ' ', (string)$row['sponsorship_role']))) ?></strong> sponsorship for <strong><?= h($row['sponsor_name']) ?></strong> to another fixture in the same season.</p>
                              <form method="post">
                                        <?= csrf_field() ?>
                                        <div class="mb-3">
                                                  <label class="form-label">Target fixture</label>
                                                  <select name="to_fixture_id" class="form-select" required>
                                                            <option value="">Select fixture</option>
                                                            <?php foreach ($fixtureOptions as $fixture): ?>
                                                                      <option value="<?= (int)$fixture['id'] ?>">
                                                                                <?= h(($fixture['is_home'] ? 'Home' : 'Away') . ' v ' . $fixture['opponent'] . ' on ' . date('d/m/Y', strtotime((string)$fixture['match_date']))) ?>
                                                                      </option>
                                                            <?php endforeach; ?>
                                                  </select>
                                        </div>
                                        <div class="d-flex gap-2">
                                                  <button type="submit" class="btn btn-primary">Move Sponsorship</button>
                                                  <a href="match.php?id=<?= (int)$row['fixture_id'] ?>&season_id=<?= $seasonId ?>" class="btn btn-outline-secondary">Cancel</a>
                                        </div>
                              </form>
                    </div>
          </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
