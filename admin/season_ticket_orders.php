<?php
declare(strict_types=1);

// A modal on the list view loads a single order through this same script
// with ?partial=order&id=N. That request must not render the full Hub
// shell, so it boots only the pieces header.php would have pulled in and
// echoes just the order-detail fragment.
$stoPartial = (($_GET['partial'] ?? '') === 'order');

if ($stoPartial) {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/lib/functions.php';
    require_once __DIR__ . '/lib/season.php';
    if (!hub_auth_is_authenticated()) {
        http_response_code(401);
        echo '<div class="alert alert-danger m-0">Your session has expired. Please reload the page and try again.</div>';
        exit;
    }
    $currentUser = hub_auth_current_user();
} else {
    $pageHero = [
        'eyebrow' => 'Season tickets',
        'title' => 'Season Tickets',
        'subtitle' => 'Season-ticket packages attached to people, with shared order, payment, ticket and credential records.',
        'actions' => [],
    ];
    require_once __DIR__ . '/header.php';
}

require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/season_passes.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/invoices.php';
hub_auth_require_capability('tickets_ops');
ensureSeasonPassSchema($pdo);
invoices_ensure_schema($pdo);

$seasons = $pdo->query('SELECT id, name FROM seasons ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
$currentSeason = getCurrentSeason($pdo);
$seasonId = isset($_GET['season_id']) ? (int) $_GET['season_id'] : (int) ($currentSeason['id'] ?? 0);
$status = trim((string) ($_GET['status'] ?? ''));
$q = trim((string) ($_GET['q'] ?? ''));
$ticketTypeId = max(0, (int) ($_GET['ticket_type_id'] ?? 0));
$paymentStatus = trim((string) ($_GET['payment_status'] ?? ''));
$paymentMethod = trim((string) ($_GET['payment_method'] ?? ''));
$ticketStatus = trim((string) ($_GET['ticket_status'] ?? ''));
$credentialActive = trim((string) ($_GET['credential_active'] ?? ''));
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));
$orderId = max(0, (int) ($_GET['id'] ?? ($_POST['order_id'] ?? 0)));
$paymentOptions = seasonTicketPaymentMethodOptions();
$ticketTypes = getSeasonTicketTypes($pdo, null, false);
$errors = [];

if ($stoPartial && $orderId <= 0) {
    http_response_code(400);
    echo '<div class="alert alert-danger m-0">No order was specified.</div>';
    exit;
}

function season_ticket_order_badge(array $order): string
{
    $status = (string) ($order['status'] ?? '');
    $class = in_array($status, ['paid', 'complete'], true) ? 'text-bg-success' : ($status === 'cancelled' ? 'text-bg-secondary' : 'text-bg-warning');
    return '<span class="badge hub-status ' . $class . '">' . h($status) . '</span>';
}

function season_ticket_order_date(?string $value, string $format = 'd/m/Y'): string
{
    $timestamp = strtotime((string) $value);
    return $timestamp ? date($format, $timestamp) : 'Not set';
}

/**
 * Single colour-coded payment glyph for the list. The status drives the
 * icon and its colour; the payment method, when supplied, is folded into
 * the tooltip so a hover or screen reader still gets "Paid - Cash".
 */
function season_ticket_payment_icon(?string $status, string $methodLabel = ''): string
{
    $map = [
        'paid' => ['fa-circle-check', 'text-success', 'Paid'],
        'pending' => ['fa-clock', 'text-warning', 'Payment pending'],
        'failed' => ['fa-circle-xmark', 'text-danger', 'Payment failed'],
        'refunded' => ['fa-rotate-left', 'text-secondary', 'Refunded'],
        'partially_refunded' => ['fa-rotate-left', 'text-warning', 'Partially refunded'],
        'cancelled' => ['fa-ban', 'text-secondary', 'Cancelled'],
    ];
    $key = (string) $status;
    [$icon, $class, $label] = $map[$key] ?? ['fa-circle-question', 'text-muted', $key !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'Unknown'];
    if ($methodLabel !== '') {
        $label .= ' - ' . $methodLabel;
    }
    return '<i class="fa-solid ' . $icon . ' ' . $class . ' fa-lg" role="img" title="' . h($label) . '" aria-label="' . h($label) . '"></i>';
}

/**
 * Ticket (entitlement) status glyph for the list.
 */
function season_ticket_status_icon(?string $status): string
{
    $map = [
        'active' => ['fa-ticket', 'text-success', 'Active'],
        'pending' => ['fa-hourglass-half', 'text-warning', 'Pending'],
        'cancelled' => ['fa-ban', 'text-secondary', 'Cancelled'],
        'refunded' => ['fa-rotate-left', 'text-secondary', 'Refunded'],
    ];
    $key = (string) $status;
    [$icon, $class, $label] = $map[$key] ?? ['fa-ticket', 'text-muted', $key !== '' ? ucfirst(str_replace('_', ' ', $key)) : 'Unknown'];
    return '<i class="fa-solid ' . $icon . ' ' . $class . ' fa-lg" role="img" title="' . h($label) . '" aria-label="' . h($label) . '"></i>';
}

/**
 * Page numbers to show in the pager, with null marking an elided gap.
 *
 * @return list<int|null>
 */
function sto_page_window(int $current, int $count): array
{
    if ($count <= 7) {
        return range(1, $count);
    }
    $pages = [1];
    $low = max(2, $current - 1);
    $high = min($count - 1, $current + 1);
    if ($low > 2) {
        $pages[] = null;
    }
    for ($i = $low; $i <= $high; $i++) {
        $pages[] = $i;
    }
    if ($high < $count - 1) {
        $pages[] = null;
    }
    $pages[] = $count;
    return $pages;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please reload and try again.';
    } elseif ($orderId <= 0) {
        $errors[] = 'Order not found.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'cancel_order') {
                cancelSeasonPassOrder($pdo, $orderId, (string) ($_POST['reason'] ?? ''), isset($currentUser['account_id']) ? (int) $currentUser['account_id'] : null);
                $cancelledOrder = getSeasonPassOrderBundle($pdo, $orderId);
                auditLog($pdo, 'season_ticket_order_cancelled', "Cancelled season ticket order #{$orderId} for '" . (string) ($cancelledOrder['holder_name'] ?? '') . "'");
                header('Location: /admin/season_ticket_orders.php?id=' . $orderId . '&cancelled=1');
                exit;
            }
            if ($action === 'refund_order') {
                if (!hub_auth_has_any_capability(['tickets_refund_comp', 'finance_manage'])) {
                    throw new RuntimeException('You do not have permission to refund ticket orders.');
                }
                refundSeasonPassOrder($pdo, $orderId, (float) ($_POST['amount'] ?? 0), (string) ($_POST['reason'] ?? ''), isset($currentUser['account_id']) ? (int) $currentUser['account_id'] : null);
                $refundedOrder = getSeasonPassOrderBundle($pdo, $orderId);
                auditLog($pdo, 'season_ticket_order_refunded', "Refunded season ticket order #{$orderId} for '" . (string) ($refundedOrder['holder_name'] ?? '') . "'");
                header('Location: /admin/season_ticket_orders.php?id=' . $orderId . '&refunded=1');
                exit;
            }
            if ($action === 'generate_invoice') {
                $result = invoices_generate_for_season_pass_order($pdo, $orderId, (string) ($currentUser['display_name'] ?? $currentUser['username'] ?? $currentUser['email'] ?? ''));
                if ($result['errors']) {
                    throw new RuntimeException(implode(' ', $result['errors']));
                }
                auditLog($pdo, 'invoice_generated', "Generated invoice for season ticket order #{$orderId}");
                header('Location: /admin/season_ticket_orders.php?id=' . $orderId . '&invoice_generated=1');
                exit;
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

if ($orderId > 0) {
    $order = getSeasonPassOrderBundle($pdo, $orderId);
    if (!$order) {
        if ($stoPartial) {
            http_response_code(404);
            echo '<div class="alert alert-danger m-0">Season ticket order not found.</div>';
            exit;
        }
        echo '<div class="alert alert-danger">Season ticket order not found.</div>';
        require_once __DIR__ . '/footer.php';
        exit;
    }
    $holderUrl = '/club_person.php?id=' . (int) $order['owner_person_id'];
    $verifyUrl = !empty($order['token']) ? '/verify_ticket.php?token=' . rawurlencode((string) $order['token']) : '';
    ?>

    <?php if (!$stoPartial): ?>
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <a class="btn btn-outline-secondary" href="/admin/season_ticket_orders.php?season_id=<?= (int) $seasonId ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Orders</a>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary" href="<?= h($holderUrl) ?>"><i class="fa-solid fa-user" aria-hidden="true"></i> Person</a>
            <a class="btn btn-brand" href="/admin/season_ticket_order.php?action=new"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add ticket</a>
        </div>
    </div>

    <?php if (isset($_GET['cancelled'])): ?><div class="alert alert-success">Season ticket cancelled and credential revoked.</div><?php endif; ?>
    <?php if (isset($_GET['refunded'])): ?><div class="alert alert-success">Refund recorded. Full refunds revoke the ticket credential.</div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><?= h(implode(' ', $errors)) ?></div><?php endif; ?>
    <?php endif; ?>

    <?php if ($stoPartial): ?>
    <div class="d-flex flex-wrap gap-2 justify-content-end mb-3">
        <a class="btn btn-outline-secondary btn-sm" href="<?= h($holderUrl) ?>"><i class="fa-solid fa-user" aria-hidden="true"></i> Person</a>
    </div>
    <?php if ($errors): ?><div class="alert alert-danger"><?= h(implode(' ', $errors)) ?></div><?php endif; ?>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <section class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
                        <div>
                            <div class="small text-uppercase text-muted fw-bold">Order #<?= (int) $order['id'] ?><?php if (!empty($order['legacy_season_ticket_order_id'])): ?> · legacy #<?= (int) $order['legacy_season_ticket_order_id'] ?><?php endif; ?></div>
                            <h2 class="h3 mb-1"><?= h((string) $order['type_name']) ?> Season Ticket</h2>
                            <div class="text-muted"><?= h((string) $order['season_name']) ?> · <?= h(season_ticket_order_date((string) $order['created_at'], 'd/m/Y H:i')) ?></div>
                        </div>
                        <div class="fs-5"><?= season_ticket_order_badge($order) ?></div>
                    </div>
                    <hr>
                    <div class="row g-3">
                        <div class="col-md-6"><div class="text-muted small">Person / ticket holder</div><div class="fw-semibold"><?= h((string) $order['holder_name']) ?></div><div><?= h((string) ($order['holder_email'] ?? '')) ?></div></div>
                        <div class="col-md-6"><div class="text-muted small">Payment</div><div class="fw-semibold"><?= gbp((float) $order['total_amount']) ?></div><div><?= h((string) $order['payment_status']) ?><?= !empty($order['paid_at']) ? ' - ' . h(season_ticket_order_date((string) $order['paid_at'])) : '' ?></div><div class="small text-muted"><?= h((string) ($paymentOptions[$order['payment_method']] ?? $order['payment_method'] ?? 'No method')) ?></div></div>
                        <div class="col-md-6"><div class="text-muted small">Ticket</div><div class="fw-semibold"><?= h((string) $order['pass_status']) ?></div><div class="small text-muted">Entitlement #<?= (int) $order['entitlement_id'] ?> · Ticket #<?= (int) $order['season_pass_id'] ?></div></div>
                        <div class="col-md-6"><div class="text-muted small">Credential</div><div class="fw-semibold font-monospace"><?= h((string) $order['manual_code']) ?></div><div class="small text-muted"><?= (int) $order['credential_is_active'] === 1 ? 'Active' : 'Inactive' ?></div><?php if ($verifyUrl !== ''): ?><a class="small" href="<?= h($verifyUrl) ?>" target="_blank" rel="noopener">Open verification</a><?php endif; ?></div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-4">
            <section class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <h3 class="h5 mb-3">Order Summary</h3>
                    <div class="d-flex justify-content-between border-bottom py-2"><span>Ticket</span><strong><?= h((string) $order['type_name']) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-2"><span>Season</span><strong><?= h((string) $order['season_name']) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-2"><span>Order</span><strong><?= h((string) $order['status']) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-2"><span>Payment</span><strong><?= h((string) $order['payment_status']) ?></strong></div>
                    <div class="d-flex justify-content-between border-bottom py-2"><span>Ticket</span><strong><?= h((string) $order['pass_status']) ?></strong></div>
                    <div class="d-flex justify-content-between fw-bold pt-2"><span>Total</span><span><?= gbp((float) $order['total_amount']) ?></span></div>
                </div>
            </section>

            <section class="card shadow-sm border-0 mb-3">
                <div class="card-body">
                    <?= invoices_render_admin_section(invoices_list_for_source($pdo, 'season_pass_order', (int) $order['id']), '<form method="post" action="/admin/season_ticket_orders.php">' . csrf_field() . '<input type="hidden" name="order_id" value="' . (int) $order['id'] . '"><input type="hidden" name="action" value="generate_invoice"><button type="submit" class="btn btn-outline-primary btn-sm">Generate invoice</button></form>') ?>
                </div>
            </section>

            <section class="card shadow-sm border-0">
                <div class="card-body">
                    <h3 class="h5 mb-3">Actions</h3>
                    <?php if (!in_array((string) $order['status'], ['cancelled', 'refunded'], true)): ?>
                        <form method="post" class="mb-3" action="/admin/season_ticket_orders.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <input type="hidden" name="action" value="cancel_order">
                            <label class="form-label fw-semibold" for="cancel_reason">Cancel reason</label>
                            <textarea class="form-control mb-2" id="cancel_reason" name="reason" rows="2" required></textarea>
                            <button class="btn btn-outline-danger w-100" type="submit">Cancel Ticket</button>
                        </form>
                        <?php if ((string) $order['payment_status'] === 'paid'): ?>
                            <form method="post" action="/admin/season_ticket_orders.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <input type="hidden" name="action" value="refund_order">
                                <label class="form-label fw-semibold" for="refund_amount">Refund amount</label>
                                <input class="form-control mb-2" id="refund_amount" name="amount" type="number" min="0" step="0.01" value="<?= h((string) $order['total_amount']) ?>" required>
                                <label class="form-label fw-semibold" for="refund_reason">Refund reason</label>
                                <textarea class="form-control mb-2" id="refund_reason" name="reason" rows="2"></textarea>
                                <button class="btn btn-outline-warning w-100" type="submit">Record Refund</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-secondary mb-0">This ticket is not active.</div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
    <?php
    if ($stoPartial) {
        exit;
    }
    require_once __DIR__ . '/footer.php';
    exit;
}

$filters = [];
if ($seasonId > 0) {
    $filters['season_id'] = $seasonId;
}
if ($status !== '') {
    $filters['status'] = $status;
}
if ($q !== '') {
    $filters['q'] = $q;
}
if ($ticketTypeId > 0) {
    $filters['ticket_type_id'] = $ticketTypeId;
}
if ($paymentStatus !== '') {
    $filters['payment_status'] = $paymentStatus;
}
if ($paymentMethod !== '') {
    $filters['payment_method'] = $paymentMethod;
}
if ($ticketStatus !== '') {
    $filters['ticket_status'] = $ticketStatus;
}
if ($credentialActive !== '') {
    $filters['credential_active'] = $credentialActive === '1' ? 1 : 0;
}
if ($dateFrom !== '') {
    $filters['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $filters['date_to'] = $dateTo;
}
$orders = getSeasonPassOrderList($pdo, $filters);

$totalPaid = 0.0;
$totalOutstanding = 0.0;
foreach ($orders as $order) {
    if ((string) $order['payment_status'] === 'paid') {
        $totalPaid += (float) $order['total_amount'];
    } else {
        $totalOutstanding += (float) $order['total_amount'];
    }
}

// The filter panel is compact by default; it opens automatically when any
// of the collapsed filters is already in play so the result set makes sense.
$advancedFiltersActive = ($status !== '' || $ticketTypeId > 0 || $paymentStatus !== '' || $paymentMethod !== ''
    || $ticketStatus !== '' || $credentialActive !== '' || $dateFrom !== '' || $dateTo !== '');

// Pagination. getSeasonPassOrderList() returns every match, so the running
// totals above stay accurate; only the rendered slice is limited.
$totalOrders = count($orders);
$perPage = 25;
$pageCount = max(1, (int) ceil($totalOrders / $perPage));
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($page > $pageCount) {
    $page = $pageCount;
}
$pageOffset = ($page - 1) * $perPage;
$pagedOrders = array_slice($orders, $pageOffset, $perPage);
$pageStart = $totalOrders > 0 ? $pageOffset + 1 : 0;
$pageEnd = min($pageOffset + $perPage, $totalOrders);

$stoQueryBase = $_GET;
unset($stoQueryBase['page'], $stoQueryBase['partial'], $stoQueryBase['id']);
$stoPageUrl = static function (int $target) use ($stoQueryBase): string {
    return 'season_ticket_orders.php?' . http_build_query(['page' => $target] + $stoQueryBase);
};
?>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Order saved.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?= h(implode(' ', $errors)) ?></div><?php endif; ?>

<div class="hub-section-commandbar">
  <div><h2>Season Ticket Orders</h2><p><?= count($orders) ?> ticket order<?= count($orders) === 1 ? '' : 's' ?> · <?= gbp($totalPaid) ?> paid · <?= gbp($totalOutstanding) ?> outstanding</p></div>
  <div class="hub-local-actions">
    <a class="btn btn-outline-secondary btn-sm" href="club_people.php">People &amp; Users</a>
    <a class="btn btn-outline-secondary btn-sm" href="season_ticket_free_codes.php">Free links</a>
    <a class="btn btn-brand btn-sm" href="season_ticket_order.php?action=new"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add ticket</a>
  </div>
</div>

<form method="get" class="card shadow-sm border-0 mb-3" id="stFilters">
  <div class="card-body">
    <div class="row g-2 align-items-end">
      <div class="col-12 col-sm-6 col-lg-4">
        <label class="form-label small text-muted" for="stSearch">Search</label>
        <input class="form-control form-control-sm" id="stSearch" name="q" value="<?= h($q) ?>" placeholder="Name, email, code, order #">
      </div>
      <div class="col-8 col-sm-4 col-lg-3">
        <label class="form-label small text-muted" for="stSeason">Season</label>
        <select class="form-select form-select-sm" id="stSeason" name="season_id" onchange="this.form.submit()">
            <option value="0">All seasons</option>
            <?php foreach ($seasons as $season): ?>
                <option value="<?= (int) $season['id'] ?>" <?= $seasonId === (int) $season['id'] ? 'selected' : '' ?>><?= h((string) $season['name']) ?></option>
            <?php endforeach; ?>
        </select>
      </div>
      <div class="col-4 col-sm-2 col-lg-2 d-grid">
        <button class="btn btn-outline-secondary btn-sm stFilterToggle <?= $advancedFiltersActive ? '' : 'collapsed' ?>" type="button"
                data-bs-toggle="collapse" data-bs-target="#stAdvancedFilters"
                aria-expanded="<?= $advancedFiltersActive ? 'true' : 'false' ?>" aria-controls="stAdvancedFilters">
          <span class="stFilterToggle-more"><i class="fa-solid fa-sliders me-1" aria-hidden="true"></i>More</span>
          <span class="stFilterToggle-less"><i class="fa-solid fa-chevron-up me-1" aria-hidden="true"></i>Less</span>
        </button>
      </div>
      <div class="col-12 col-lg-3 d-flex gap-2">
        <button class="btn btn-brand btn-sm flex-fill" type="submit"><i class="fa-solid fa-filter me-1" aria-hidden="true"></i>Apply</button>
        <a class="btn btn-outline-secondary btn-sm flex-fill" href="season_ticket_orders.php">Reset</a>
      </div>
    </div>

    <div class="collapse <?= $advancedFiltersActive ? 'show' : '' ?> mt-1" id="stAdvancedFilters">
      <hr class="my-2">
      <div class="row g-2 align-items-end">
        <div class="col-6 col-md-4 col-lg-3">
            <label class="form-label small text-muted" for="stType">Ticket type</label>
            <select class="form-select form-select-sm" id="stType" name="ticket_type_id">
                <option value="0">All types</option>
                <?php foreach ($ticketTypes as $type): ?>
                    <option value="<?= (int) $type['id'] ?>" <?= $ticketTypeId === (int) $type['id'] ? 'selected' : '' ?>><?= h((string) $type['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <label class="form-label small text-muted" for="stStatus">Order status</label>
            <select class="form-select form-select-sm" id="stStatus" name="status" onchange="this.form.submit()">
                <option value="">Any status</option>
                <?php foreach (['draft', 'pending_payment', 'paid', 'cancelled', 'partially_refunded', 'refunded'] as $value): ?>
                    <option value="<?= h($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= h($value) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <label class="form-label small text-muted" for="stPaymentStatus">Payment status</label>
            <select class="form-select form-select-sm" id="stPaymentStatus" name="payment_status">
                <option value="">Any payment</option>
                <?php foreach (['pending', 'paid', 'failed', 'refunded', 'partially_refunded'] as $value): ?>
                    <option value="<?= h($value) ?>" <?= $paymentStatus === $value ? 'selected' : '' ?>><?= h($value) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <label class="form-label small text-muted" for="stPaymentMethod">Method</label>
            <select class="form-select form-select-sm" id="stPaymentMethod" name="payment_method">
                <option value="">Any method</option>
                <?php foreach ($paymentOptions as $value => $label): ?>
                    <option value="<?= h((string) $value) ?>" <?= $paymentMethod === (string) $value ? 'selected' : '' ?>><?= h((string) $label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <label class="form-label small text-muted" for="stTicketStatus">Ticket status</label>
            <select class="form-select form-select-sm" id="stTicketStatus" name="ticket_status">
                <option value="">Any ticket</option>
                <?php foreach (['pending', 'active', 'cancelled', 'refunded'] as $value): ?>
                    <option value="<?= h($value) ?>" <?= $ticketStatus === $value ? 'selected' : '' ?>><?= h($value) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <label class="form-label small text-muted" for="stCredential">Credential</label>
            <select class="form-select form-select-sm" id="stCredential" name="credential_active">
                <option value="">Any credential</option>
                <option value="1" <?= $credentialActive === '1' ? 'selected' : '' ?>>Active</option>
                <option value="0" <?= $credentialActive === '0' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <label class="form-label small text-muted" for="stFrom">From</label>
            <input class="form-control form-control-sm" id="stFrom" type="date" name="from" value="<?= h($dateFrom) ?>">
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <label class="form-label small text-muted" for="stTo">To</label>
            <input class="form-control form-control-sm" id="stTo" type="date" name="to" value="<?= h($dateTo) ?>">
        </div>
      </div>
    </div>
  </div>
</form>

<style>
  .sto-row { cursor: pointer; }
  .sto-row:hover { background: rgba(106, 32, 54, 0.05); }
  .sto-row:focus-visible { outline: 2px solid var(--brand-primary, #6a2036); outline-offset: -2px; }
  .sto-price { font-style: italic; color: var(--brand-primary, #6a2036); font-size: 0.9em; }
  .sto-icons i + i { margin-left: 0.6rem; }
  .sto-cred { display: inline-flex; align-items: center; gap: 0.5rem; }
  .stFilterToggle .stFilterToggle-less { display: none; }
  .stFilterToggle:not(.collapsed) .stFilterToggle-more { display: none; }
  .stFilterToggle:not(.collapsed) .stFilterToggle-less { display: inline; }
  #stOrdersPager .pagination { --bs-pagination-color: var(--brand-primary, #6a2036); }
  #stOrdersPager .page-item.active .page-link { background-color: var(--brand-primary, #6a2036); border-color: var(--brand-primary, #6a2036); }
</style>

<div class="card hub-list-card hub-section hub-table-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle hub-data-table hub-data-table--responsive" id="stOrdersTable">
        <thead><tr><th>Person</th><th>Type</th><th>Payment</th><th>Ticket</th><th>Credential</th><th>Order date</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($pagedOrders as $order): ?>
            <?php $createdTs = strtotime((string) $order['created_at']); ?>
            <tr class="sto-row" data-order-id="<?= (int) $order['id'] ?>" tabindex="0" role="button" aria-label="Open season ticket order for <?= h((string) $order['holder_name']) ?>">
              <td data-label="Person"><a href="club_person.php?id=<?= (int) $order['person_id'] ?>"><?= h((string) $order['holder_name']) ?></a></td>
              <td data-label="Type">
                <div class="fw-semibold"><?= h((string) $order['type_name']) ?></div>
                <div class="sto-price"><?= gbp((float) $order['total_amount']) ?></div>
              </td>
              <td data-label="Payment" class="sto-icons"><?= season_ticket_payment_icon((string) $order['payment_status'], (string) ($paymentOptions[$order['payment_method']] ?? $order['payment_method'] ?? '')) ?></td>
              <td data-label="Ticket" class="sto-icons"><?= season_ticket_status_icon((string) $order['entitlement_status']) ?></td>
              <td data-label="Credential">
                <?php $manualCode = (string) $order['manual_code']; ?>
                <?php if ($manualCode !== ''): ?>
                  <span class="js-cred sto-cred">
                    <button type="button" class="btn btn-sm btn-outline-secondary js-cred-toggle">Show</button>
                    <span class="font-monospace js-cred-code" hidden><?= h($manualCode) ?></span>
                  </span>
                <?php else: ?>
                  <span class="text-muted small">&mdash;</span>
                <?php endif; ?>
              </td>
              <td data-label="Order date">
                <div><?= $createdTs ? h(date('d/m/Y', $createdTs)) : '&mdash;' ?></div>
                <?php if ($createdTs): ?><div class="small text-muted"><?= h(date('H:i', $createdTs)) ?></div><?php endif; ?>
              </td>
              <td data-label="Actions" class="text-end"><button type="button" class="btn btn-sm btn-brand js-open-order" data-order-id="<?= (int) $order['id'] ?>">Open</button></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$orders): ?><tr><td colspan="7" class="text-center text-muted py-4">No orders found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($totalOrders > 0): ?>
  <div class="card-footer bg-white d-flex flex-wrap gap-2 justify-content-between align-items-center" id="stOrdersPager">
    <div class="small text-muted">Showing <?= (int) $pageStart ?>&ndash;<?= (int) $pageEnd ?> of <?= (int) $totalOrders ?></div>
    <?php if ($pageCount > 1): ?>
    <nav aria-label="Order pages">
      <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= h($stoPageUrl(max(1, $page - 1))) ?>" aria-label="Previous"<?= $page <= 1 ? ' tabindex="-1" aria-disabled="true"' : '' ?>>&laquo;</a>
        </li>
        <?php foreach (sto_page_window($page, $pageCount) as $windowPage): ?>
          <?php if ($windowPage === null): ?>
            <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
          <?php else: ?>
            <li class="page-item <?= $windowPage === $page ? 'active' : '' ?>">
              <a class="page-link" href="<?= h($stoPageUrl($windowPage)) ?>"><?= (int) $windowPage ?></a>
            </li>
          <?php endif; ?>
        <?php endforeach; ?>
        <li class="page-item <?= $page >= $pageCount ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= h($stoPageUrl(min($pageCount, $page + 1))) ?>" aria-label="Next"<?= $page >= $pageCount ? ' tabindex="-1" aria-disabled="true"' : '' ?>>&raquo;</a>
        </li>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="modal fade" id="stOrderModal" tabindex="-1" aria-hidden="true" aria-labelledby="stOrderModalLabel">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5 mb-0" id="stOrderModalLabel">Season ticket order</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="stOrderModalBody"></div>
      <div class="modal-footer">
        <a class="btn btn-outline-secondary btn-sm" id="stOrderModalFull" href="#"><i class="fa-solid fa-up-right-from-square me-1" aria-hidden="true"></i>Full page</a>
        <button type="button" class="btn btn-brand btn-sm" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  // Remember whether the "More" filter panel was left open between visits.
  var advPanel = document.getElementById('stAdvancedFilters');
  if (advPanel && window.bootstrap) {
    var ADV_KEY = 'stFiltersExpanded';
    try {
      if (!advPanel.classList.contains('show') && localStorage.getItem(ADV_KEY) === '1') {
        bootstrap.Collapse.getOrCreateInstance(advPanel, { toggle: false }).show();
      }
    } catch (e) {}
    advPanel.addEventListener('shown.bs.collapse', function () { try { localStorage.setItem(ADV_KEY, '1'); } catch (e) {} });
    advPanel.addEventListener('hidden.bs.collapse', function () { try { localStorage.setItem(ADV_KEY, '0'); } catch (e) {} });
  }

  var table = document.getElementById('stOrdersTable');
  if (!table) { return; }

  var modalEl = document.getElementById('stOrderModal');
  var modalBody = document.getElementById('stOrderModalBody');
  var modalFullLink = document.getElementById('stOrderModalFull');
  var bsModal = (window.bootstrap && modalEl) ? new bootstrap.Modal(modalEl) : null;

  // Credential: click to reveal the code, auto-hide again after 30s.
  function toggleCredential(wrap) {
    if (!wrap) { return; }
    var codeEl = wrap.querySelector('.js-cred-code');
    var btnEl = wrap.querySelector('.js-cred-toggle');
    if (!codeEl || !btnEl) { return; }
    if (codeEl.hasAttribute('hidden')) {
      codeEl.removeAttribute('hidden');
      btnEl.textContent = 'Hide';
      window.clearTimeout(wrap._credTimer);
      wrap._credTimer = window.setTimeout(function () {
        codeEl.setAttribute('hidden', '');
        btnEl.textContent = 'Show';
      }, 30000);
    } else {
      codeEl.setAttribute('hidden', '');
      btnEl.textContent = 'Show';
      window.clearTimeout(wrap._credTimer);
    }
  }

  function openOrder(id) {
    if (!id) { return; }
    var fullUrl = 'season_ticket_orders.php?id=' + encodeURIComponent(id);
    if (!bsModal) { window.location.href = fullUrl; return; }
    if (modalFullLink) { modalFullLink.href = fullUrl; }
    modalBody.innerHTML = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Loading order&hellip;</div>';
    bsModal.show();
    fetch('season_ticket_orders.php?partial=order&id=' + encodeURIComponent(id), {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (response) {
        if (!response.ok) { throw new Error('HTTP ' + response.status); }
        return response.text();
      })
      .then(function (html) { modalBody.innerHTML = html; })
      .catch(function () {
        modalBody.innerHTML = '<div class="alert alert-danger m-0">Could not load this order here. <a href="' + fullUrl + '">Open the full page</a> instead.</div>';
      });
  }

  table.addEventListener('click', function (event) {
    var credToggle = event.target.closest('.js-cred-toggle');
    if (credToggle) {
      event.stopPropagation();
      toggleCredential(credToggle.closest('.js-cred'));
      return;
    }
    var openBtn = event.target.closest('.js-open-order');
    if (openBtn) {
      event.stopPropagation();
      openOrder(openBtn.getAttribute('data-order-id'));
      return;
    }
    if (event.target.closest('a, button, input, .js-cred')) { return; }
    var row = event.target.closest('tr[data-order-id]');
    if (row) { openOrder(row.getAttribute('data-order-id')); }
  });

  table.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') { return; }
    var row = event.target.closest('tr[data-order-id]');
    if (!row || event.target !== row) { return; }
    event.preventDefault();
    openOrder(row.getAttribute('data-order-id'));
  });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
