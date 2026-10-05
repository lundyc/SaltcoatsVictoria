<?php
// developer_db.php — Developer Database Inspector
require_once __DIR__ . '/header.php';

// Restrict access
if (!hub_auth_is_developer()) {
          echo '<div class="alert alert-danger m-3">Access denied.</div>';
          require_once __DIR__ . '/footer.php';
          exit;
}

// Fetch all tables with row counts
$tables = [];
$stmt = $pdo->query("SHOW TABLES");
while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
          $tableName = $row[0];
          $count = $pdo->query("SELECT COUNT(*) FROM `$tableName`")->fetchColumn();
          $tables[] = [
                    'name' => $tableName,
                    'count' => $count
          ];
}

// Check if a table was requested
$selectedTable = $_GET['table'] ?? null;
$tableData = [];
$validTableNames = array_column($tables, 'name');
if ($selectedTable !== null && !in_array($selectedTable, $validTableNames, true)) {
          echo '<div class="alert alert-danger m-3">Unknown table.</div>';
          require_once __DIR__ . '/footer.php';
          exit;
}
if ($selectedTable) {
          $quotedTable = '`' . str_replace('`', '``', $selectedTable) . '`';
          $stmt = $pdo->query("SELECT * FROM $quotedTable ORDER BY 1 DESC LIMIT 20");
          $tableData = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div>
          <h1 class="mb-4"><i class="fa-solid fa-database"></i> Database Inspector</h1>

          <div class="row">
                    <div class="col-md-4">
                              <div class="list-group">
                                        <?php foreach ($tables as $tbl): ?>
                                                  <a href="developer_db.php?table=<?= urlencode($tbl['name']) ?>"
                                                            class="list-group-item list-group-item-action <?= ($selectedTable === $tbl['name']) ? 'active' : '' ?>">
                                                            <?= htmlspecialchars($tbl['name']) ?>
                                                            <span class="badge bg-secondary float-end"><?= $tbl['count'] ?></span>
                                                  </a>
                                        <?php endforeach; ?>
                              </div>
                    </div>

                    <div class="col-md-8">
                              <?php if ($selectedTable && $tableData): ?>
                                        <h4 class="mb-3">Last 20 rows from <code><?= htmlspecialchars($selectedTable) ?></code></h4>
                                        <div class="table-responsive">
                                                  <table class="table table-sm table-bordered table-striped mb-0 hub-data-table">
                                                            <thead class="table-dark">
                                                                      <tr>
                                                                                <?php foreach (array_keys($tableData[0]) as $col): ?>
                                                                                          <th><?= htmlspecialchars($col) ?></th>
                                                                                <?php endforeach; ?>
                                                                      </tr>
                                                            </thead>
                                                            <tbody>
                                                                      <?php foreach ($tableData as $row): ?>
                                                                                <tr>
                                                                                          <?php foreach ($row as $cell): ?>
                                                                                                    <td><?= htmlspecialchars((string)$cell) ?></td>
                                                                                          <?php endforeach; ?>
                                                                                </tr>
                                                                      <?php endforeach; ?>
                                                            </tbody>
                                                  </table>
                                        </div>
                              <?php elseif ($selectedTable): ?>
                                        <div class="alert alert-info">No data in <code><?= htmlspecialchars($selectedTable) ?></code>.</div>
                              <?php else: ?>
                                        <div class="alert alert-secondary">Select a table from the left to view its contents.</div>
                              <?php endif; ?>
                    </div>
          </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>