<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/competition_structure.php';
require_once __DIR__ . '/lib/audit.php';

ensureCompetitionStructureSchema($pdo);

$id = (int)($_GET['id'] ?? 0);
$competition = $id > 0 ? getMatchCompetitionById($pdo, $id) : null;

if (!$competition) {
          echo '<div><div class="alert alert-danger">Competition not found.</div></div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

$fixtureCountStmt = $pdo->prepare("
          SELECT COUNT(DISTINCT f.id)
          FROM match_fixtures f
          LEFT JOIN competition_seasons cs ON cs.id = f.competition_season_id
          LEFT JOIN competition_aliases ca
            ON ca.competition_id = :alias_competition_id
           AND ca.review_status = 'confirmed'
           AND LOWER(TRIM(ca.alias_name)) = LOWER(TRIM(f.competition))
          WHERE cs.competition_id = :linked_competition_id
             OR (f.competition_season_id IS NULL AND LOWER(TRIM(f.competition)) = LOWER(TRIM(:name)))
             OR ca.id IS NOT NULL
");
$fixtureCountStmt->execute([
          ':alias_competition_id' => $id,
          ':linked_competition_id' => $id,
          ':name' => (string)$competition['name'],
]);
$fixtureCount = (int)$fixtureCountStmt->fetchColumn();
$relationshipStmt = $pdo->prepare("
          SELECT
              (SELECT COUNT(*) FROM seasons WHERE competition_id = :season_competition_id)
            + (SELECT COUNT(*) FROM competition_seasons WHERE competition_id = :edition_competition_id)
");
$relationshipStmt->execute([':season_competition_id' => $id, ':edition_competition_id' => $id]);
$relationshipCount = (int)$relationshipStmt->fetchColumn();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          if (!csrf_check()) {
                    $error = 'Invalid CSRF token.';
          } else {
                    try {
                              deleteMatchCompetition($pdo, $id);
                              auditLog($pdo, 'competition_deleted', "Deleted competition '" . (string)$competition['name'] . "'");
                              header('Location: competitions.php?deleted=1');
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
                              <h1 class="h4 mb-3">Delete Competition</h1>
                              <?php if (!empty($error)): ?>
                                        <div class="alert alert-danger"><?= h($error) ?></div>
                              <?php endif; ?>
                              <p>Delete <strong><?= h((string)$competition['name']) ?></strong>?</p>
                              <p class="text-muted mb-4">
                                        <?php if ($relationshipCount > 0): ?>
                                                  This competition is assigned to a season and cannot be deleted until those relationships are removed.
                                        <?php elseif ($fixtureCount > 0): ?>
                                                  This competition is recognised by <?= (int)$fixtureCount ?> legacy fixture<?= $fixtureCount === 1 ? '' : 's' ?>. Review those fixtures before deleting it.
                                        <?php else: ?>
                                                  No fixtures are currently using this competition.
                                        <?php endif; ?>
                              </p>
                              <form method="post" class="d-flex gap-2">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-danger" <?= ($relationshipCount > 0 || $fixtureCount > 0) ? 'disabled' : '' ?>>Delete Competition</button>
                                        <a href="competitions.php" class="btn btn-outline-secondary">Cancel</a>
                              </form>
                    </div>
          </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
