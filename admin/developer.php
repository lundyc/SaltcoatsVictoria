<?php
// developer.php — Developer Dashboard
require_once __DIR__ . '/header.php';

// Restrict access
if (!hub_auth_is_developer()) {
          echo '<div class="alert alert-danger m-3">Access denied.</div>';
          require_once __DIR__ . '/footer.php';
          exit;
}
?>

<div>
          <h1 class="mb-4"><i class="fa-solid fa-tools"></i> Developer Dashboard</h1>

          <div class="row row-cols-1 row-cols-md-2 g-4">

                    <!-- Database Inspector -->
                    <div class="col">
                              <div class="card h-100 shadow-sm">
                                        <div class="card-body">
                                                  <h5 class="card-title"><i class="fa-solid fa-database me-2"></i> Database Inspector</h5>
                                                  <p class="card-text">View all tables, row counts, and inspect the last 20 rows of any table (read-only).</p>
                                                  <a href="developer_db.php" class="btn btn-brand">Open</a>
                                        </div>
                              </div>
                    </div>

                    <!-- Audit Log Viewer -->
                    <div class="col">
                              <div class="card h-100 shadow-sm">
                                        <div class="card-body">
                                                  <h5 class="card-title"><i class="fa-solid fa-clipboard-list me-2"></i> Audit Log Viewer</h5>
                                                  <p class="card-text">Browse entries from the system audit log with filters for action, entity, and user.</p>
                                                  <a href="developer_audit.php" class="btn btn-brand">Open</a>
                                        </div>
                              </div>
                    </div>

                    <!-- Sponsorships History -->
                    <div class="col">
                              <div class="card h-100 shadow-sm">
                                        <div class="card-body">
                                                  <h5 class="card-title"><i class="fa-solid fa-history me-2"></i> Sponsorships History</h5>
                                                  <p class="card-text">Inspect all changes to sponsorships, including updates and deletes, via triggers.</p>
                                                  <a href="developer_sponsorships.php" class="btn btn-brand">Open</a>
                                        </div>
                              </div>
                    </div>

                    <!-- Role Switcher -->
                    <div class="col">
                              <div class="card h-100 shadow-sm">
                                        <div class="card-body">
                                                  <h5 class="card-title"><i class="fa-solid fa-user-shield me-2"></i> Role Switcher</h5>
                                                  <p class="card-text">Temporarily switch your session role (committee, treasurer, viewer) to test permissions.</p>
                                                  <a href="developer_roles.php" class="btn btn-brand">Open</a>
                                        </div>
                              </div>
                    </div>

                    <!-- Error Log Viewer -->
                    <div class="col">
                              <div class="card h-100 shadow-sm">
                                        <div class="card-body">
                                                  <h5 class="card-title"><i class="fa-solid fa-bug me-2"></i> Error Logs</h5>
                                                  <p class="card-text">View the last 200 lines of the PHP/Apache error logs. Auto-detects local vs server paths.</p>
                                                  <a href="developer_errors.php" class="btn btn-brand">Open</a>
                                        </div>
                              </div>
                    </div>

                    <!-- Product Analytics -->
                    <div class="col">
                              <div class="card h-100 shadow-sm">
                                        <div class="card-body">
                                                  <h5 class="card-title"><i class="fa-solid fa-chart-line me-2"></i> Product Analytics</h5>
                                                  <p class="card-text">See page usage, feature clicks, journeys, devices, engagement, and click maps across the Hub.</p>
                                                  <a href="developer_analytics.php" class="btn btn-brand">Open</a>
                                        </div>
                              </div>
                    </div>

          </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
