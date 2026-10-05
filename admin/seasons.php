<?php
$pageHero = [
          'eyebrow' => 'Season settings',
          'title' => 'Seasons',
          'subtitle' => 'Manage season dates, status, and match sponsorship pricing.',
          'actions' => [],
];
require_once __DIR__ . '/header.php';

$seasonRows = $pdo->query("
          SELECT s.id,
                 s.name,
                 s.start_date,
                 s.end_date,
                 s.is_current,
                 s.is_locked,
                 COALESCE(msp.home_amount, 50.00) AS home_amount,
                 COALESCE(msp.away_amount, 20.00) AS away_amount
          FROM seasons s
          LEFT JOIN match_season_pricing msp ON msp.season_id = s.id
          ORDER BY s.start_date ASC, s.id ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">Season deleted successfully.</div><?php endif; ?>

<div class="hub-section-commandbar">
          <div><h2>Club seasons</h2><p>Dates, availability and match sponsorship pricing.</p></div>
          <div class="hub-local-actions"><a href="season.php?action=new" class="btn btn-brand"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add season</a></div>
</div>

<div class="card hub-list-card hub-table-card">
          <div class="card-body p-0">
                    <div class="table-responsive">
                              <table class="table align-middle hub-data-table hub-data-table--responsive">
                                        <caption class="visually-hidden">Club seasons, dates, status, pricing, and actions</caption>
                                        <thead>
                                                  <tr>
                                                            <th>Season</th>
                                                            <th>Dates</th>
                                                            <th>Status</th>
                                                            <th>Match Day</th>
                                                            <th>Actions</th>
                                                  </tr>
                                        </thead>
                                        <tbody>
                                                  <?php foreach ($seasonRows as $row): ?>
                                                            <tr>
                                                                      <td data-label="Season">
                                                                                <strong><?= h($row['name']) ?></strong>
                                                                                <?php if ((int)$row['is_current'] === 1): ?>
                                                                                          <span class="badge bg-primary ms-2">Current</span>
                                                                                <?php endif; ?>
                                                                      </td>
                                                                      <td data-label="Dates">
                                                                                <span><?= h($row['start_date'] ? date('d/m/Y', strtotime((string)$row['start_date'])) : '-') ?> <span class="text-muted">to</span> <?= h($row['end_date'] ? date('d/m/Y', strtotime((string)$row['end_date'])) : '-') ?></span>
                                                                      </td>
                                                                      <td data-label="Status">
                                                                                <?php if ((int)$row['is_locked'] === 1): ?>
                                                                                          <span class="badge bg-danger">Locked</span>
                                                                                <?php else: ?>
                                                                                          <span class="badge bg-success">Open</span>
                                                                                <?php endif; ?>
                                                                      </td>
                                                                      <td data-label="Match day pricing"><?= gbp((float)$row['home_amount']) ?> home / <?= gbp((float)$row['away_amount']) ?> away</td>
                                                                      <td data-label="Actions" class="text-nowrap">
                                                                                <div class="hub-row-actions hub-actions"><a href="season.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                                                <a href="season_delete.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-danger" aria-label="Review deletion of <?= h((string)$row['name']) ?>">Delete</a></div>
                                                                      </td>
                                                            </tr>
                                                  <?php endforeach; ?>
                                                  <?php if (!$seasonRows): ?>
                                                            <tr>
                                                                      <td colspan="5" class="hub-record-empty hub-empty-state">No seasons found. Add a season to organise fixtures and sponsorships.</td>
                                                            </tr>
                                                  <?php endif; ?>
                                        </tbody>
                              </table>
                    </div>
          </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
