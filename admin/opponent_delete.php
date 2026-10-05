<?php
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
          require_once __DIR__ . '/config.php';
          require_once __DIR__ . '/auth.php';
          require_once __DIR__ . '/db.php';
          require_once __DIR__ . '/lib/functions.php';
          require_once __DIR__ . '/lib/match_sponsorship.php';

          if (!hub_auth_is_authenticated()) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(401);
                    echo json_encode([
                              'ok' => false,
                              'error' => 'Authentication required.',
                    ]);
                    exit;
          }
} else {
          require_once __DIR__ . '/header.php';
          require_once __DIR__ . '/lib/match_sponsorship.php';
}
require_once __DIR__ . '/lib/audit.php';

$id = (int)($_GET['id'] ?? 0);
$opponent = $id > 0 ? getMatchOpponentById($pdo, $id) : null;

if (!$opponent) {
          if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(404);
                    echo json_encode([
                              'ok' => false,
                              'error' => 'Opponent not found.',
                    ]);
                    exit;
          }
          echo '<div><div class="alert alert-danger">Opponent not found.</div></div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

$fixtureCountStmt = $pdo->prepare("SELECT COUNT(*) FROM match_fixtures WHERE opponent_id = :id");
$fixtureCountStmt->execute([':id' => $id]);
$fixtureCount = (int)$fixtureCountStmt->fetchColumn();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
          if (!csrf_check()) {
                    $error = 'Invalid CSRF token.';
          } else {
                    try {
                              deleteMatchOpponent($pdo, $id);
                              auditLog($pdo, 'opponent_deleted', "Deleted opponent '" . (string) $opponent['clubname'] . "'");
                              if (!empty($opponent['logo_path'])) {
                                        $logoPath = matchOpponentLogoFilePath((string) $opponent['logo_path']);
                                        if ($logoPath !== '' && is_file($logoPath)) {
                                                  @unlink($logoPath);
                                        }
                              }
                              if ($isAjax) {
                                        header('Content-Type: application/json; charset=utf-8');
                                        echo json_encode([
                                                  'ok' => true,
                                                  'message' => 'Opponent deleted.',
                                        ]);
                                        exit;
                              }
                              header('Location: opponents.php?deleted=1');
                              exit;
                    } catch (Throwable $e) {
                              $error = $e->getMessage();
                    }
          }
}

if ($isAjax) {
          header('Content-Type: application/json; charset=utf-8');
          http_response_code(400);
          echo json_encode([
                    'ok' => false,
                    'error' => $error ?? 'Unable to delete opponent.',
          ]);
          exit;
}
?>

<div>
          <div class="card shadow-sm">
                    <div class="card-body">
                              <h1 class="h4 mb-3">Delete Opponent</h1>
                              <?php if (!empty($error)): ?>
                                        <div class="alert alert-danger"><?= h($error) ?></div>
                              <?php endif; ?>
                              <p>Delete <strong><?= h($opponent['clubname']) ?></strong>?</p>
                                      <p class="text-muted mb-4">
                                        <?php if ($fixtureCount > 0): ?>
                                                  This opponent is linked to <?= (int)$fixtureCount ?> fixture<?= $fixtureCount === 1 ? '' : 's' ?>. Those fixtures will be updated to show <strong>Team Deleted</strong>.
                                        <?php else: ?>
                                                  No fixtures are currently linked to this opponent.
                                        <?php endif; ?>
                              </p>
                              <form method="post" class="d-flex gap-2">
                                        <?= csrf_field() ?>
                                        <button type="submit" class="btn btn-danger">Delete Opponent</button>
                                        <a href="opponents.php" class="btn btn-outline-secondary">Cancel</a>
                              </form>
                    </div>
          </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
