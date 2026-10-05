<?php
$pageHero = [
    'eyebrow' => 'Season tickets',
    'title' => 'Season ticket types',
    'subtitle' => 'Manage the ticket types and prices on sale for the season.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/season_tickets.php';
ensureSeasonTicketSchema($pdo);

$seasons = $pdo->query('SELECT id, name FROM seasons ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
$types = getSeasonTicketTypes($pdo);
$bySeason = [];
foreach ($types as $type) {
    $bySeason[(int) $type['season_id']][] = $type;
}
$seasonNames = [];
foreach ($seasons as $season) {
    $seasonNames[(int) $season['id']] = (string) $season['name'];
}
?>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Season ticket type saved.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">Season ticket type deleted.</div><?php endif; ?>
<?php if (isset($_GET['error'])): ?><div class="alert alert-danger"><?= h((string) $_GET['error']) ?></div><?php endif; ?>

<div class="hub-section-commandbar">
  <div><h2>Ticket catalogue</h2><p>Prices shown to admins entering orders and on the public sign-up page.</p></div>
  <div class="hub-local-actions">
    <a class="btn btn-outline-secondary btn-sm" href="season_ticket_orders.php">Season tickets</a>
    <a class="btn btn-brand btn-sm" href="season_ticket_type.php?action=new"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add ticket type</a>
  </div>
</div>

<?php if (!$types): ?>
  <div class="card shadow-sm"><div class="card-body text-center text-muted py-4">No season ticket types created yet.</div></div>
<?php endif; ?>

<?php foreach ($bySeason as $seasonId => $rows): ?>
  <section class="card hub-list-card hub-section hub-table-card mb-4">
    <div class="card-header bg-transparent"><h2 class="h5 mb-0"><?= h($seasonNames[$seasonId] ?? 'Unknown season') ?></h2></div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table align-middle hub-data-table hub-data-table--responsive">
          <thead><tr><th>Order</th><th>Name</th><th>Code</th><th>Price</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($rows as $type): ?>
              <tr>
                <td data-label="Order"><?= (int) $type['sort_order'] ?></td>
                <td data-label="Name" class="fw-semibold"><?= h((string) $type['name']) ?></td>
                <td data-label="Code"><code><?= h((string) $type['code']) ?></code></td>
                <td data-label="Price"><?= gbp((float) $type['price']) ?></td>
                <td data-label="Status"><span class="badge hub-status <?= (int) $type['is_active'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $type['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                <td data-label="Actions" class="text-end"><a class="btn btn-sm btn-outline-primary" href="season_ticket_type.php?id=<?= (int) $type['id'] ?>">Edit</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
<?php endforeach; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
