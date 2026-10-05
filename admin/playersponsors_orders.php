<?php
// playersponsors_orders.php — Staff view of the public player sponsorship
// shop's orders. This is the only place in the Hub a conflict_refund_due
// item is ever visible: it can never gain a stripe_transactions row (no
// sponsorship_agreements row exists for it), so it never shows up in
// stripe_refund.php / stripe_dashboard.php — see lib/player_sponsorship_shop.php.
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/player_sponsorship_shop.php';
require_once __DIR__ . '/lib/stripe.php';
require_once __DIR__ . '/lib/invoices.php';
ensurePlayerSponsorshipShopSchema($pdo);
invoices_ensure_schema($pdo);

$pageHero = [
  'eyebrow' => 'Sponsor management',
  'title' => 'Player Sponsorship Shop Orders',
  'subtitle' => 'Orders placed through the public /playersponsors storefront.',
  'actions' => [],
];
require_once __DIR__ . '/header.php';

$feedback = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resolve_conflict') {
  if (!csrf_check()) {
    $feedback = 'Invalid CSRF token.';
  } else {
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $stmt = $pdo->prepare("UPDATE player_sponsorship_order_items SET status = 'conflict_resolved' WHERE id = :id AND status = 'conflict_refund_due'");
    $stmt->execute([':id' => $itemId]);
    if ($stmt->rowCount() > 0) {
        auditLog($pdo, 'player_sponsorship_order_conflict_resolved', "Marked order item #{$itemId} conflict as resolved");
    }
    header('Location: playersponsors_orders.php?resolved=1');
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_invoice') {
  if (!csrf_check()) {
    $feedback = 'Invalid CSRF token.';
  } else {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $currentAdmin = hub_auth_current_user();
    $currentAdminLabel = (string) ($currentAdmin['display_name'] ?? $currentAdmin['username'] ?? $currentAdmin['email'] ?? '');
    $result = invoices_generate_for_player_sponsorship_order($pdo, $orderId, $currentAdminLabel);
    if ($result['errors']) {
      $feedback = implode(' ', $result['errors']);
    } else {
      auditLog($pdo, 'invoice_generated', "Generated invoice for player sponsorship order #{$orderId}");
      header('Location: playersponsors_orders.php?invoice_generated=1');
      exit;
    }
  }
}

$statusFilter = $_GET['status'] ?? 'all';
if (!in_array($statusFilter, ['all', 'pending_payment', 'paid', 'partially_conflicted', 'expired', 'cancelled'], true)) {
  $statusFilter = 'all';
}

$where = $statusFilter !== 'all' ? 'WHERE o.status = :status' : '';
$sql = "SELECT o.* FROM player_sponsorship_orders o $where ORDER BY o.created_at DESC, o.id DESC";
$stmt = $pdo->prepare($sql);
if ($statusFilter !== 'all') {
  $stmt->execute([':status' => $statusFilter]);
} else {
  $stmt->execute();
}
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$itemsByOrder = [];
if ($orders) {
  $orderIds = array_map(static fn(array $o): int => (int) $o['id'], $orders);
  $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
  $itemsStmt = $pdo->prepare("SELECT * FROM player_sponsorship_order_items WHERE order_id IN ($placeholders) ORDER BY id");
  $itemsStmt->execute($orderIds);
  foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
    $itemsByOrder[(int) $item['order_id']][] = $item;
  }
}

$counts = [
  'total' => count($orders),
  'paid' => 0,
  'pending' => 0,
  'conflicts' => 0,
];
foreach ($orders as $order) {
  if ((string) $order['status'] === 'paid') {
    $counts['paid']++;
  } elseif ((string) $order['status'] === 'pending_payment') {
    $counts['pending']++;
  }
}
foreach ($itemsByOrder as $items) {
  foreach ($items as $item) {
    if ((string) $item['status'] === 'conflict_refund_due') {
      $counts['conflicts']++;
    }
  }
}

function playersponsors_order_status_badge(string $status): string
{
  $map = [
    'pending_payment' => ['bg-secondary', 'Awaiting payment'],
    'paid' => ['bg-brand-paid', 'Paid'],
    'partially_conflicted' => ['bg-brand-partial', 'Partially conflicted'],
    'expired' => ['text-bg-light', 'Expired'],
    'cancelled' => ['text-bg-light', 'Cancelled'],
  ];
  [$class, $label] = $map[$status] ?? ['text-bg-secondary', ucfirst($status)];
  return '<span class="badge hub-status ' . h($class) . '">' . h($label) . '</span>';
}

function playersponsors_item_status_badge(string $status): string
{
  $map = [
    'pending' => ['text-bg-secondary', 'Pending'],
    'confirmed' => ['bg-brand-paid', 'Confirmed'],
    'conflict_refund_due' => ['bg-brand-unpaid', 'Conflict — refund due'],
    'conflict_resolved' => ['text-bg-light', 'Conflict resolved'],
  ];
  [$class, $label] = $map[$status] ?? ['text-bg-secondary', ucfirst($status)];
  return '<span class="badge hub-status ' . h($class) . '">' . h($label) . '</span>';
}
?>

<div class="sponsors-page">
  <?php hub_render_metric_grid([
    ['label' => 'Orders', 'value' => (int) $counts['total'], 'meta' => 'In current filter', 'icon' => 'fa-filter', 'tone' => 'primary'],
    ['label' => 'Paid', 'value' => (int) $counts['paid'], 'meta' => 'Fully confirmed', 'icon' => 'fa-circle-check', 'tone' => 'success'],
    ['label' => 'Awaiting payment', 'value' => (int) $counts['pending'], 'meta' => 'Not yet paid', 'icon' => 'fa-clock', 'tone' => 'warning'],
    ['label' => 'Conflicts needing attention', 'value' => (int) $counts['conflicts'], 'meta' => 'Refund due via Stripe dashboard', 'icon' => 'fa-triangle-exclamation', 'tone' => 'danger'],
  ], 'Order summary'); ?>

  <?php if (isset($_GET['resolved'])): ?>
    <div class="alert alert-success">Marked as resolved.</div>
  <?php endif; ?>
  <?php if (isset($_GET['invoice_generated'])): ?>
    <div class="alert alert-success">Invoice generated.</div>
  <?php endif; ?>
  <?php if ($feedback !== ''): ?>
    <div class="alert alert-danger"><?= h($feedback) ?></div>
  <?php endif; ?>

  <div class="card sponsors-section-card border-0 shadow-sm mb-3 hub-form-card">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-12 col-sm-4">
          <label for="statusFilter" class="form-label small text-muted mb-1">Status</label>
          <select id="statusFilter" name="status" class="form-select">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All statuses</option>
            <option value="pending_payment" <?= $statusFilter === 'pending_payment' ? 'selected' : '' ?>>Awaiting payment</option>
            <option value="paid" <?= $statusFilter === 'paid' ? 'selected' : '' ?>>Paid</option>
            <option value="partially_conflicted" <?= $statusFilter === 'partially_conflicted' ? 'selected' : '' ?>>Partially conflicted</option>
            <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
          </select>
        </div>
        <div class="col-12 col-sm-3">
          <button class="btn btn-brand w-100" type="submit">Apply</button>
        </div>
      </form>
    </div>
  </div>

  <?php if (!$orders): ?>
    <div class="alert alert-info mb-0 hub-empty-state">No orders match the current filter.</div>
  <?php else: ?>
    <?php foreach ($orders as $order): ?>
      <?php $items = $itemsByOrder[(int) $order['id']] ?? []; ?>
      <details class="card sponsors-section-card border-0 shadow-sm mb-2 hub-table-card">
        <summary style="cursor:pointer;padding:.9rem 1.1rem;list-style:none;display:flex;flex-wrap:wrap;align-items:center;gap:.75rem;justify-content:space-between;">
          <span>
            <strong>Order #<?= (int) $order['id'] ?></strong>
            &middot; <?= h((string) $order['buyer_name']) ?>
            <span class="text-muted small">(<?= h((string) $order['buyer_email']) ?>)</span>
          </span>
          <span class="d-flex align-items-center gap-2">
            <span class="fw-semibold"><?= gbp((float) $order['total_amount']) ?></span>
            <?= playersponsors_order_status_badge((string) $order['status']) ?>
            <span class="text-muted small"><?= h(date('d M Y H:i', strtotime((string) $order['created_at']))) ?></span>
          </span>
        </summary>
        <div class="card-body pt-0">
          <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 hub-data-table">
            <thead><tr><th>Player</th><th>Package</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><?= h((string) $item['player_name_snapshot']) ?></td>
                  <td><?= h(player_sponsorship_shop_package_label((string) $item['package_code'])) ?></td>
                  <td class="text-end"><?= gbp((float) $item['unit_amount']) ?></td>
                  <td><?= playersponsors_item_status_badge((string) $item['status']) ?></td>
                  <td class="text-end">
                    <?php if ((string) $item['status'] === 'confirmed' && !empty($item['sponsorship_agreement_id'])): ?>
                      <a href="/admin/sponsorship_agreement.php?id=<?= (int) $item['sponsorship_agreement_id'] ?>" class="btn btn-sm btn-outline-secondary">View agreement</a>
                    <?php elseif ((string) $item['status'] === 'conflict_refund_due'): ?>
                      <?php if (!empty($order['stripe_checkout_session_id'])): ?>
                        <a href="https://dashboard.stripe.com/<?= stripe_mode() === 'test' ? 'test/' : '' ?>checkout/sessions/<?= rawurlencode((string) $order['stripe_checkout_session_id']) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-danger">Open payment in Stripe</a>
                      <?php endif; ?>
                      <form method="post" class="d-inline" data-confirm="Mark this conflict as resolved?" data-confirm-action="Mark resolved" data-confirm-class="btn-primary">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="resolve_conflict">
                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Mark resolved</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <?php if (!empty($order['buyer_is_business'])): ?>
            <div class="text-muted small mt-2">Business sponsor<?= !empty($order['buyer_address']) ? ' · ' . h((string) $order['buyer_address']) : '' ?><?= !empty($order['buyer_website_url']) ? ' · ' . h((string) $order['buyer_website_url']) : '' ?></div>
          <?php endif; ?>
          <hr>
          <?= invoices_render_admin_section(invoices_list_for_source($pdo, 'player_sponsorship_order', (int) $order['id']), '<form method="post">' . csrf_field() . '<input type="hidden" name="order_id" value="' . (int) $order['id'] . '"><input type="hidden" name="action" value="generate_invoice"><button type="submit" class="btn btn-outline-primary btn-sm">Generate invoice</button></form>') ?>
        </div>
      </details>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php
require_once __DIR__ . '/footer.php';
?>
