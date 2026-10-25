<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Tickets',
    'title' => 'Match Ticket Orders',
    'subtitle' => 'Public match ticket purchases, game by game.',
    'actions' => [
        ['label' => 'Fixture setup', 'href' => '/admin/fixture_tickets.php', 'class' => 'btn btn-light btn-sm'],
        ['label' => 'Ticket packages', 'href' => '/admin/ticket_packages.php', 'class' => 'btn btn-outline-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_tickets.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/invoices.php';
hub_auth_require_capability('tickets_ops');
ensureMatchTicketSchema($pdo);
invoices_ensure_schema($pdo);

$seasonId = (int) ($_GET['season_id'] ?? ($seasonContext['season_id'] ?? 0));
$orderId = max(0, (int) ($_GET['id'] ?? ($_POST['order_id'] ?? 0)));
$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please reload and try again.';
    } elseif ($orderId <= 0) {
        $errors[] = 'Order not found.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $userId = isset($currentUser['id']) ? (int) $currentUser['id'] : null;
        try {
            if ($action === 'cancel_order') {
                cancelMatchTicketOrder($pdo, $orderId, (string) ($_POST['reason'] ?? ''), $userId);
                $cancelledOrder = getMatchTicketOrder($pdo, $orderId);
                auditLog($pdo, 'ticket_order_cancelled', "Cancelled match ticket order #{$orderId} for '" . (string) ($cancelledOrder['buyer_name'] ?? '') . "'");
                header('Location: /admin/ticket_orders.php?id=' . $orderId . '&cancelled=1');
                exit;
            }
            if ($action === 'refund_order') {
                if (!hub_auth_has_any_capability(['tickets_refund_comp', 'finance_manage'])) {
                    throw new RuntimeException('You do not have permission to refund ticket orders.');
                }
                refundMatchTicketOrder($pdo, $orderId, (string) ($_POST['reason'] ?? ''), $userId);
                $refundedOrder = getMatchTicketOrder($pdo, $orderId);
                auditLog($pdo, 'ticket_order_refunded', "Refunded match ticket order #{$orderId} for '" . (string) ($refundedOrder['buyer_name'] ?? '') . "'");
                header('Location: /admin/ticket_orders.php?id=' . $orderId . '&refunded=1');
                exit;
            }
            if ($action === 'send_email') {
                if (!sendMatchTicketConfirmationEmail($pdo, $orderId, true)) {
                    throw new RuntimeException('The ticket email could not be sent. Check the buyer email address and server mail settings.');
                }
                auditLog($pdo, 'ticket_order_email_resent', "Resent ticket confirmation email for match ticket order #{$orderId}");
                header('Location: /admin/ticket_orders.php?id=' . $orderId . '&email_sent=1');
                exit;
            }
            if ($action === 'generate_invoice') {
                $result = invoices_generate_for_match_ticket_order($pdo, $orderId, (string) ($currentUser['display_name'] ?? $currentUser['username'] ?? $currentUser['email'] ?? ''));
                if ($result['errors']) {
                    throw new RuntimeException(implode(' ', $result['errors']));
                }
                auditLog($pdo, 'invoice_generated', "Generated invoice for match ticket order #{$orderId}");
                header('Location: /admin/ticket_orders.php?id=' . $orderId . '&invoice_generated=1');
                exit;
            }
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

function match_ticket_order_status_badge(array $order): string
{
    $status = (string) ($order['status'] ?? 'pending_payment');
    if ($status === 'complete' && (int) ($order['paid'] ?? 0) === 1) {
        return '<span class="badge text-bg-success">Paid</span>';
    }
    if ($status === 'refunded') {
        return '<span class="badge text-bg-secondary">Refunded</span>';
    }
    if ($status === 'cancelled') {
        return '<span class="badge text-bg-danger">Cancelled</span>';
    }
    return '<span class="badge text-bg-warning">Pending</span>';
}

if ($orderId > 0) {
    $order = getMatchTicketOrder($pdo, $orderId);
    if (!$order) {
        echo '<div class="alert alert-danger">Ticket order not found.</div>';
        require_once __DIR__ . '/footer.php';
        exit;
    }
    $items = getMatchTicketOrderItems($pdo, $orderId);
    $tickets = getMatchTicketsForOrder($pdo, $orderId);
    $ticketUrl = match_ticket_public_order_url($order);
    $history = [
        ['time' => (string) $order['created_at'], 'label' => 'Order created', 'meta' => 'Checkout as ' . (string) $order['checkout_mode']],
    ];
    if (!empty($order['paid_at'])) {
        $history[] = ['time' => (string) $order['paid_at'], 'label' => 'Payment completed', 'meta' => 'Method: ' . (string) ($order['payment_method'] ?: 'stripe')];
    }
    if (!empty($order['confirmation_email_sent_at'])) {
        $history[] = ['time' => (string) $order['confirmation_email_sent_at'], 'label' => 'Ticket email sent', 'meta' => (string) $order['buyer_email']];
    }
    foreach ($tickets as $ticket) {
        if (!empty($ticket['checked_in_at'])) {
            $history[] = ['time' => (string) $ticket['checked_in_at'], 'label' => 'Ticket checked in', 'meta' => (string) $ticket['ticket_label'] . ' - ' . (string) $ticket['manual_code']];
        }
    }
    if (!empty($order['cancelled_at'])) {
        $history[] = ['time' => (string) $order['cancelled_at'], 'label' => 'Order cancelled', 'meta' => (string) $order['cancel_reason']];
    }
    if (!empty($order['refunded_at'])) {
        $history[] = ['time' => (string) $order['refunded_at'], 'label' => 'Order refunded', 'meta' => gbp((float) $order['refund_amount']) . (!empty($order['stripe_refund_id']) ? ' - Stripe refund ' . (string) $order['stripe_refund_id'] : '') . ' - ' . (string) $order['refund_reason']];
    }
    usort($history, static fn(array $a, array $b): int => strtotime((string) $b['time']) <=> strtotime((string) $a['time']));
    ?>

    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <a class="btn btn-outline-secondary" href="/admin/ticket_orders.php?season_id=<?= (int) $seasonId ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Orders</a>
        <a class="btn btn-brand" href="<?= h($ticketUrl) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-ticket" aria-hidden="true"></i> Open Digital Ticket</a>
    </div>

    <?php if (isset($_GET['cancelled'])): ?><div class="alert alert-success">Order cancelled.</div><?php endif; ?>
    <?php if (isset($_GET['refunded'])): ?><div class="alert alert-success">Order refunded.</div><?php endif; ?>
    <?php if (isset($_GET['email_sent'])): ?><div class="alert alert-success">Ticket email sent.</div><?php endif; ?>
    <?php if (isset($_GET['invoice_generated'])): ?><div class="alert alert-success">Invoice generated.</div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><?= h(implode(' ', $errors)) ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card hub-panel border-0 mb-3">
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-start">
                        <div class="hosp-game__title">
                            <div class="small text-uppercase text-muted fw-bold">Order #<?= (int) $order['id'] ?> &middot; placed <?= h(date('j M Y, H:i', strtotime((string) $order['created_at']))) ?></div>
                            <h2 class="h3 mb-1">v <?= h((string) $order['opponent']) ?></h2>
                            <p class="mb-0"><i class="fa-solid fa-calendar-day me-1" aria-hidden="true"></i><?= h(date('D j M Y', strtotime((string) $order['match_date']))) ?><?= !empty($order['kickoff_time']) ? ' &middot; ' . h(date('H:i', strtotime((string) $order['kickoff_time']))) . ' KO' : '' ?></p>
                        </div>
                        <div class="fs-5"><?= match_ticket_order_status_badge($order) ?></div>
                    </div>

                    <hr>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="text-muted small">Buyer</div>
                            <div class="fw-semibold"><?= h((string) $order['buyer_name']) ?></div>
                            <div><?= h((string) $order['buyer_email']) ?></div>
                            <?php if (!empty($order['buyer_phone'])): ?><div><?= h((string) $order['buyer_phone']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <div class="text-muted small">Payment</div>
                            <div class="fw-semibold"><?= gbp((float) $order['total_amount']) ?></div>
                            <div><?= h((string) ($order['payment_method'] ?: 'Stripe')) ?><?= !empty($order['paid_at']) ? ' - ' . h(date('d/m/Y H:i', strtotime((string) $order['paid_at']))) : '' ?></div>
                            <?php if (!empty($order['stripe_checkout_session_id'])): ?><div class="small text-muted">Session: <?= h((string) $order['stripe_checkout_session_id']) ?></div><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card hub-panel border-0 mb-3">
                <div class="card-body">
                    <h3 class="h5 mb-3">Tickets</h3>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 hub-data-table">
                            <thead><tr><th>Ticket</th><th>Manual Code</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($tickets as $ticket): ?>
                                    <?php $singleTicketUrl = $ticketUrl . '&ticket=' . rawurlencode((string) $ticket['ticket_token']); ?>
                                    <tr>
                                        <td><?= h((string) $ticket['ticket_label']) ?></td>
                                        <td class="font-monospace fw-bold"><?= h((string) $ticket['manual_code']) ?></td>
                                        <td><?= !empty($ticket['checked_in_at']) ? '<span class="badge text-bg-success">Used</span>' : '<span class="badge text-bg-light text-dark">Unused</span>' ?></td>
                                        <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= h($singleTicketUrl) ?>" target="_blank" rel="noopener">View</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card hub-panel border-0">
                <div class="card-body">
                    <h3 class="h5 mb-3">Transaction History</h3>
                    <div class="list-group list-group-flush">
                        <?php foreach ($history as $event): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex justify-content-between gap-3">
                                    <strong><?= h((string) $event['label']) ?></strong>
                                    <span class="text-muted small"><?= h(date('d/m/Y H:i', strtotime((string) $event['time']))) ?></span>
                                </div>
                                <?php if ((string) $event['meta'] !== ''): ?><div class="text-muted small"><?= h((string) $event['meta']) ?></div><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card hub-panel border-0 mb-3">
                <div class="card-body">
                    <h3 class="h5 mb-3">Order Summary</h3>
                    <?php foreach ($items as $item): ?>
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <span><?= h((string) $item['package_name']) ?> x <?= (int) $item['quantity'] ?></span>
                            <span><?= gbp((float) $item['unit_price'] * (int) $item['quantity']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <div class="d-flex justify-content-between fw-bold pt-2">
                        <span>Total</span>
                        <span><?= gbp((float) $order['total_amount']) ?></span>
                    </div>
                </div>
            </div>

            <div class="card hub-panel border-0 mb-3">
                <div class="card-body">
                    <?= invoices_render_admin_section(invoices_list_for_source($pdo, 'match_ticket_order', (int) $order['id']), '<form method="post">' . csrf_field() . '<input type="hidden" name="order_id" value="' . (int) $order['id'] . '"><input type="hidden" name="action" value="generate_invoice"><button type="submit" class="btn btn-outline-primary btn-sm">Generate invoice</button></form>') ?>
                </div>
            </div>

            <div class="card hub-panel border-0">
                <div class="card-body">
                    <h3 class="h5 mb-3">Actions</h3>
                    <form method="post" class="mb-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                        <input type="hidden" name="action" value="send_email">
                        <button class="btn btn-outline-secondary w-100" type="submit"><i class="fa-solid fa-envelope" aria-hidden="true"></i> Send Ticket Email</button>
                    </form>

                    <?php if (!in_array((string) $order['status'], ['cancelled', 'refunded'], true)): ?>
                        <form method="post" class="mb-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <input type="hidden" name="action" value="cancel_order">
                            <label class="form-label fw-semibold" for="cancel_reason">Cancel reason</label>
                            <textarea class="form-control mb-2" id="cancel_reason" name="reason" rows="3" required></textarea>
                            <button class="btn btn-outline-danger w-100" type="submit">Cancel Ticket</button>
                        </form>
                    <?php endif; ?>

                    <?php if ((int) $order['paid'] === 1 && empty($order['refunded_at'])): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                            <input type="hidden" name="action" value="refund_order">
                            <label class="form-label fw-semibold" for="refund_reason">Refund reason</label>
                            <textarea class="form-control mb-2" id="refund_reason" name="reason" rows="3" required></textarea>
                            <button class="btn btn-danger w-100" type="submit">Refund Ticket</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/footer.php';
    exit;
}

$orders = getMatchTicketOrderList($pdo, $seasonId);
$since = (string) ($hubOrderPrevSeen['match_tickets'] ?? '1970-01-01 00:00:00');
$statusFilter = (string) ($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'paid', 'pending', 'closed'], true)) {
    $statusFilter = 'all';
}
$isPaidOrder = static fn(array $o): bool => (int) $o['paid'] === 1 && (string) $o['status'] !== 'refunded';
$isClosedOrder = static fn(array $o): bool => in_array((string) $o['status'], ['cancelled', 'refunded'], true);
$counts = ['all' => count($orders), 'paid' => 0, 'pending' => 0, 'closed' => 0];
$revenue = 0.0;
$ticketsSold = 0;
$ticketsUsed = 0;
$newCount = 0;
foreach ($orders as $o) {
    if ($isClosedOrder($o)) {
        $counts['closed']++;
    } elseif ($isPaidOrder($o)) {
        $counts['paid']++;
        $revenue += (float) $o['total_amount'];
        $ticketsSold += (int) $o['ticket_count'];
        $ticketsUsed += (int) $o['used_count'];
        if ((string) ($o['paid_at'] ?: $o['created_at']) > $since) {
            $newCount++;
        }
    } else {
        $counts['pending']++;
    }
}

$games = [];
foreach ($orders as $o) {
    $match = match ($statusFilter) {
        'paid' => !$isClosedOrder($o) && $isPaidOrder($o),
        'pending' => !$isClosedOrder($o) && !$isPaidOrder($o),
        'closed' => $isClosedOrder($o),
        default => true,
    };
    if (!$match) {
        continue;
    }
    $key = (string) $o['match_date'] . '|' . (string) $o['opponent'];
    $games[$key] ??= ['opponent' => (string) $o['opponent'], 'match_date' => (string) $o['match_date'], 'kickoff' => (string) ($o['kickoff_time'] ?? ''), 'rows' => []];
    $games[$key]['rows'][] = $o;
}
uasort($games, static fn(array $a, array $b): int => strcmp($a['match_date'], $b['match_date']));
$today = date('Y-m-d');
$upcoming = array_filter($games, static fn(array $g): bool => $g['match_date'] >= $today);
$past = array_reverse(array_filter($games, static fn(array $g): bool => $g['match_date'] < $today), true);
$filterUrl = static fn(string $status): string => '?' . http_build_query(array_filter(['season_id' => $seasonId ?: '', 'status' => $status === 'all' ? '' : $status]));

$renderGame = static function (array $game) use ($isPaidOrder, $isClosedOrder, $since): void {
    $rows = $game['rows'];
    $issued = 0;
    $used = 0;
    $income = 0.0;
    foreach ($rows as $r) {
        if ($isPaidOrder($r) && !$isClosedOrder($r)) {
            $issued += (int) $r['ticket_count'];
            $used += (int) $r['used_count'];
            $income += (float) $r['total_amount'];
        }
    }
    $pct = $issued > 0 ? min(100, (int) round($used / $issued * 100)) : 0;
    ?>
    <article class="hub-panel hosp-game" aria-label="v <?= h($game['opponent']) ?>">
        <header class="hosp-game__head">
            <div class="hosp-game__title">
                <h3>v <?= h($game['opponent']) ?></h3>
                <p><i class="fa-solid fa-calendar-day" aria-hidden="true"></i> <?= h(date('D j M Y', strtotime($game['match_date']))) ?><?= $game['kickoff'] !== '' ? ' · ' . h(date('H:i', strtotime($game['kickoff']))) . ' KO' : '' ?></p>
            </div>
            <div class="hosp-game__meter" role="group" aria-label="Tickets used">
                <div class="hosp-game__meter-text"><strong><?= $issued ?></strong> tickets sold · <?= $used ?> used</div>
                <div class="progress" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-success" style="width: <?= $pct ?>%"></div></div>
            </div>
            <div class="hosp-game__actions text-end">
                <div class="fw-bold fs-5"><?= h(gbp($income)) ?></div>
                <div class="small text-muted"><?= count($rows) ?> order<?= count($rows) === 1 ? '' : 's' ?></div>
            </div>
        </header>
        <div class="table-responsive">
            <table class="table align-middle mb-0 hub-data-table hub-data-table--responsive">
                <thead><tr><th>Order</th><th>Buyer</th><th class="text-end">Tickets</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $o):
                    $isNew = $isPaidOrder($o) && (string) ($o['paid_at'] ?: $o['created_at']) > $since; ?>
                    <tr class="<?= $isNew ? 'table-warning' : '' ?>">
                        <td data-label="Order" class="text-nowrap"><strong>#<?= (int) $o['id'] ?></strong><?= $isNew ? ' <span class="badge text-bg-danger ms-1">New</span>' : '' ?><div class="small text-muted"><?= h(date('j M, H:i', strtotime((string) $o['created_at']))) ?></div></td>
                        <td data-label="Buyer"><strong><?= h((string) $o['buyer_name']) ?></strong><div class="small"><a href="mailto:<?= h((string) $o['buyer_email']) ?>"><?= h((string) $o['buyer_email']) ?></a></div></td>
                        <td data-label="Tickets" class="text-end"><?= (int) $o['ticket_count'] ?><div class="small text-muted"><?= (int) $o['used_count'] ?> used</div></td>
                        <td data-label="Total" class="text-end fw-bold"><?= h(gbp((float) $o['total_amount'])) ?></td>
                        <td data-label="Status"><?= match_ticket_order_status_badge($o) ?></td>
                        <td data-label="Actions" class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/admin/ticket_orders.php?id=<?= (int) $o['id'] ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
    <?php
};
?>
<div class="hosp-admin">
    <?php hub_render_metric_grid([
        ['label' => 'New orders', 'value' => $newCount, 'meta' => 'Since you last looked', 'icon' => 'fa-bell', 'tone' => $newCount > 0 ? 'danger' : 'neutral'],
        ['label' => 'Paid orders', 'value' => $counts['paid'], 'meta' => 'This season', 'icon' => 'fa-receipt', 'tone' => 'primary'],
        ['label' => 'Tickets sold', 'value' => $ticketsSold, 'meta' => $ticketsUsed . ' scanned in', 'icon' => 'fa-ticket', 'tone' => 'warning'],
        ['label' => 'Revenue', 'value' => gbp($revenue), 'meta' => 'Paid, not refunded', 'icon' => 'fa-sterling-sign', 'tone' => 'success'],
    ], 'Match ticket order summary'); ?>

    <div class="hosp-status" role="group" aria-label="Filter orders">
        <?php foreach (['all' => 'All orders', 'paid' => 'Paid', 'pending' => 'Awaiting payment', 'closed' => 'Cancelled / refunded'] as $key => $label): ?>
            <a class="btn btn-sm <?= $statusFilter === $key ? 'btn-brand' : 'btn-outline-secondary' ?>" href="<?= h($filterUrl($key)) ?>"><?= h($label) ?> (<?= (int) $counts[$key] ?>)</a>
        <?php endforeach; ?>
        <a class="hosp-status__link" href="/admin/fixture_tickets.php"><i class="fa-solid fa-gear me-1" aria-hidden="true"></i>Choose which games sell tickets</a>
    </div>

    <h2 class="h5 mb-2">Upcoming games</h2>
    <?php if ($upcoming === []): ?>
        <div class="hub-panel hosp-game"><p class="hosp-game__empty"><?= $orders === [] ? 'No match ticket orders yet.' : 'No upcoming games match this filter.' ?></p></div>
    <?php endif; ?>
    <?php foreach ($upcoming as $game): $renderGame($game); endforeach; ?>

    <?php if ($past !== []): ?>
        <details class="hosp-past mt-4">
            <summary><h2 class="h5 d-inline mb-0">Past games</h2> <span class="text-muted small">(<?= count($past) ?>)</span></summary>
            <div class="mt-3">
                <?php foreach ($past as $game): $renderGame($game); endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
