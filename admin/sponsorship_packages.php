<?php
$pageHero = [
    'eyebrow' => 'Sponsorship management',
    'title' => 'Sponsorship packages',
    'subtitle' => 'Define what sponsors can support across the club, teams, matches, players and digital channels.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
ensureSponsorshipCatalogSchema($pdo);
$packages = getSponsorshipPackages($pdo);
$grouped = [];
foreach ($packages as $package) $grouped[(string)$package['category']][] = $package;
$scopeLabels = ['club'=>'Club-wide','match'=>'Match','player'=>'Player','team'=>'Team','digital'=>'Digital'];
?>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Sponsorship package saved.</div><?php endif; ?>

<div class="hub-section-commandbar">
  <div><h2>Package catalogue</h2><p>Products, pricing, duration and graphic placement.</p></div>
  <div class="hub-local-actions"><a class="btn btn-outline-secondary btn-sm" href="sponsorship_agreements.php">Agreements</a><a class="btn btn-brand btn-sm" href="sponsorship_package.php?action=new"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add package</a></div>
</div>

<div class="row g-4">
  <?php foreach ($grouped as $category => $rows): ?>
    <div class="col-12">
      <section class="card hub-list-card hub-section hub-table-card">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center gap-2">
          <div><h2 class="h5 mb-0"><?= h($category) ?></h2><div class="small text-muted"><?= count($rows) ?> package<?= count($rows)===1?'':'s' ?></div></div>
          <span class="badge text-bg-light"><?= h($scopeLabels[(string)$rows[0]['scope']] ?? ucfirst((string)$rows[0]['scope'])) ?></span>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table align-middle hub-data-table hub-data-table--responsive">
              <caption class="visually-hidden"><?= h($category) ?> sponsorship packages</caption>
              <thead><tr><th>Order</th><th>Package</th><th>Default</th><th>Duration</th><th>Graphics</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($rows as $package): ?>
                  <tr>
                    <td data-label="Order"><?= (int)$package['sort_order'] ?></td>
                    <td data-label="Package"><div class="fw-semibold"><?= h((string)$package['name']) ?></div><div class="small text-muted"><?= h((string)$package['description']) ?></div><code><?= h((string)$package['code']) ?></code></td>
                    <td data-label="Default"><?= gbp((float)$package['amount']) ?></td>
                    <td data-label="Duration"><?= h(ucfirst((string)$package['duration_type'])) ?></td>
                    <td data-label="Graphics"><?= (int)$package['graphic_enabled']===1 ? h((string)($package['graphic_placement'] ?: 'Enabled')) : '<span class="text-muted">Not included</span>' ?></td>
                    <td data-label="Status"><span class="badge hub-status <?= (int)$package['is_active']===1?'text-bg-success':'text-bg-secondary' ?>"><?= (int)$package['is_active']===1?'Active':'Archived' ?></span></td>
                    <td data-label="Actions" class="text-end"><a class="btn btn-sm btn-outline-primary" href="sponsorship_package.php?id=<?= (int)$package['id'] ?>">Edit</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
