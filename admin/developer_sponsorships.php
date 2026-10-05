<?php
// developer_sponsorships.php — Sponsorships History Viewer
require_once __DIR__ . '/header.php';

// Restrict access
if (!hub_auth_is_developer()) {
          echo '<div class="alert alert-danger m-3">Access denied.</div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

// --- Filters ---
$sponsorId = $_GET['sponsor_id'] ?? '';
$playerId  = $_GET['player_id'] ?? '';
$slot      = $_GET['slot'] ?? '';
$action    = $_GET['action'] ?? '';

// --- Pagination ---
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

// --- Build WHERE ---
$where = [];
$params = [];
if ($sponsorId !== '') {
          $where[] = "sponsor_id = :sponsor_id";
          $params[':sponsor_id'] = $sponsorId;
}
if ($playerId !== '') {
          $where[] = "player_id = :player_id";
          $params[':player_id'] = $playerId;
}
if ($slot !== '') {
          $where[] = "slot = :slot";
          $params[':slot'] = $slot;
}
if ($action !== '') {
          $where[] = "action = :action";
          $params[':action'] = $action;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// --- Count total ---
$stmt = $pdo->prepare("SELECT COUNT(*) FROM sponsorships_history $whereSql");
$stmt->execute($params);
$totalRows = (int)$stmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

// --- Fetch records ---
$sql = "SELECT * FROM sponsorships_history $whereSql ORDER BY changed_at DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
          $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div>
          <h1 class="mb-4"><i class="fa-solid fa-history"></i> Sponsorships History</h1>

          <!-- Filters -->
          <form method="get" class="row g-2 mb-3">
                    <div class="col-md-2">
                              <input type="text" name="sponsor_id" class="form-control" placeholder="Sponsor ID" value="<?= htmlspecialchars($sponsorId) ?>">
                    </div>
                    <div class="col-md-2">
                              <input type="text" name="player_id" class="form-control" placeholder="Player ID" value="<?= htmlspecialchars($playerId) ?>">
                    </div>
                    <div class="col-md-2">
                              <input type="text" name="slot" class="form-control" placeholder="Slot" value="<?= htmlspecialchars($slot) ?>">
                    </div>
                    <div class="col-md-2">
                              <input type="text" name="action" class="form-control" placeholder="Action" value="<?= htmlspecialchars($action) ?>">
                    </div>
                    <div class="col-md-2">
                              <button type="submit" class="btn btn-brand w-100">Filter</button>
                    </div>
          </form>

          <!-- Table -->
          <div class="table-responsive">
                    <table class="table table-bordered table-striped table-sm mb-0 hub-data-table">
                              <thead class="table-dark">
                                        <tr>
                                                  <th>ID</th>
                                                  <th>Sponsorship ID</th>
                                                  <th>Sponsor ID</th>
                                                  <th>Player ID</th>
                                                  <th>Slot</th>
                                                  <th>Amount</th>
                                                  <th>Paid</th>
                                                  <th>Notes</th>
                                                  <th>Action</th>
                                                  <th>Change Type</th>
                                                  <th>Changed At</th>
                                        </tr>
                              </thead>
                              <tbody>
                                        <?php if ($history): ?>
                                                  <?php foreach ($history as $row): ?>
                                                            <tr>
                                                                      <td><?= (int)$row['id'] ?></td>
                                                                      <td><?= htmlspecialchars((string)($row['sponsorship_id'] ?? '')) ?></td>
                                                                      <td><?= htmlspecialchars((string)($row['sponsor_id'] ?? '')) ?></td>
                                                                      <td><?= htmlspecialchars((string)($row['player_id'] ?? '')) ?></td>
                                                                      <td><?= htmlspecialchars((string)($row['slot'] ?? '')) ?></td>
                                                                      <td><?= htmlspecialchars((string)($row['amount'] ?? '')) ?></td>
                                                                      <td><?= htmlspecialchars((string)($row['paid'] ?? '')) ?></td>
                                                                      <td><small><?= htmlspecialchars($row['notes'] ?? '') ?></small></td>
                                                                      <td><?= htmlspecialchars((string)($row['action'] ?? '')) ?></td>
                                                                      <td><?= htmlspecialchars((string)($row['change_type'] ?? '')) ?></td>
                                                                      <td><?= htmlspecialchars((string)($row['changed_at'] ?? '')) ?></td>
                                                            </tr>
                                                  <?php endforeach; ?>
                                        <?php else: ?>
                                                  <tr>
                                                            <td colspan="11" class="text-center">No history found.</td>
                                                  </tr>
                                        <?php endif; ?>
                              </tbody>
                    </table>
          </div>

          <!-- Pagination -->
          <?php
          hub_render_pagination(
              $page,
              (int) $totalPages,
              static fn (int $targetPage): string => '?page=' . $targetPage . '&sponsor_id=' . urlencode($sponsorId) . '&player_id=' . urlencode($playerId) . '&slot=' . urlencode($slot) . '&action=' . urlencode($action),
              'Sponsorship history pages'
          );
          ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>