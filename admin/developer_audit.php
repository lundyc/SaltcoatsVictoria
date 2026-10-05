<?php
require_once __DIR__ . '/header.php';

if (!hub_auth_is_developer()) {
          echo '<div class="alert alert-danger m-3">Access denied.</div>';
          require __DIR__ . '/footer.php';
          exit;
}

$stmt = $pdo->query("
  SELECT a.id, a.action, a.details, a.created_at,
         COALESCE(p.display_name, acc.email, 'System') AS username
  FROM audit_logs a
  LEFT JOIN accounts acc ON acc.id = a.user_id
  LEFT JOIN people p ON p.id = acc.person_id
  ORDER BY a.created_at DESC
  LIMIT 200
");
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div>
          <h1 class="h3 mb-3"><i class="fa-regular fa-clipboard"></i> Audit Logs</h1>

          <div class="card border-0 shadow-sm">
                    <div class="card-body p-0">
                              <div class="table-responsive">
                              <table class="table hub-data-table--rounded table-sm mb-0 hub-data-table">
                                        <thead class="table-dark">
                                                  <tr>
                                                            <th>ID</th>
                                                            <th>User</th>
                                                            <th>Action</th>
                                                            <th>Details</th>
                                                            <th>Timestamp</th>
                                                  </tr>
                                        </thead>
                                        <tbody>
                                                  <?php foreach ($logs as $log): ?>
                                                            <tr>
                                                                      <td><?= $log['id'] ?></td>
                                                                      <td><?= htmlspecialchars($log['username']) ?></td>
                                                                      <td><span class="badge bg-secondary"><?= htmlspecialchars($log['action']) ?></span></td>
                                                                      <td><?= htmlspecialchars($log['details']) ?></td>
                                                                      <td><?= date('d M Y H:i:s', strtotime($log['created_at'])) ?></td>
                                                            </tr>
                                                  <?php endforeach; ?>
                                        </tbody>
                              </table>
                              </div>
                    </div>
          </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
