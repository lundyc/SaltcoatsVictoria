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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
          if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(405);
                    echo json_encode([
                              'ok' => false,
                              'error' => 'Invalid request method.',
                    ]);
                    exit;
          }

          echo '<div><div class="alert alert-danger">Invalid request method.</div></div>';
          if (!$isAjax) {
                    require_once __DIR__ . '/footer.php';
          }
          exit;
}

if (!csrf_check()) {
          if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(400);
                    echo json_encode([
                              'ok' => false,
                              'error' => 'Invalid CSRF token.',
                    ]);
                    exit;
          }

          echo '<div><div class="alert alert-danger">Invalid CSRF token.</div></div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) {
          $ids = [];
}

$ids = array_values(array_unique(array_filter(array_map(static function ($value): int {
          return (int) $value;
}, $ids), static function (int $value): bool {
          return $value > 0;
})));

if ($ids === []) {
          if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(400);
                    echo json_encode([
                              'ok' => false,
                              'error' => 'No opponents selected.',
                    ]);
                    exit;
          }

          echo '<div><div class="alert alert-danger">No opponents selected.</div></div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

$deletedCount = 0;
$deletedClubnames = [];

try {
          foreach ($ids as $opponentId) {
                    $opponent = getMatchOpponentById($pdo, $opponentId);
                    if (!$opponent) {
                              continue;
                    }

                    deleteMatchOpponent($pdo, $opponentId);
                    if (!empty($opponent['logo_path'])) {
                              $logoPath = matchOpponentLogoFilePath((string) $opponent['logo_path']);
                              if ($logoPath !== '' && is_file($logoPath)) {
                                        @unlink($logoPath);
                              }
                    }
                    $deletedClubnames[] = (string) $opponent['clubname'];
                    $deletedCount++;
          }

          if ($deletedCount > 0) {
                    auditLog($pdo, 'opponent_bulk_deleted', "Bulk-deleted {$deletedCount} opponent" . ($deletedCount === 1 ? '' : 's') . ': ' . implode(', ', $deletedClubnames));
          }

          if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                              'ok' => true,
                              'message' => $deletedCount === 1 ? 'Opponent deleted.' : $deletedCount . ' opponents deleted.',
                              'deleted_count' => $deletedCount,
                    ]);
                    exit;
          }

          header('Location: opponents.php?deleted=1');
          exit;
} catch (Throwable $e) {
          if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    http_response_code(400);
                    echo json_encode([
                              'ok' => false,
                              'error' => $e->getMessage(),
                    ]);
                    exit;
          }

          echo '<div><div class="alert alert-danger">' . h($e->getMessage()) . '</div></div>';
          require_once __DIR__ . '/footer.php';
          exit;
}
