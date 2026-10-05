<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/match_tickets.php';
ensureMatchTicketSchema($pdo);

$matchOrders = getMatchTicketOrdersForMember($pdo, $currentHolder);
?>

<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">Match Tickets</div>
            <h1>My Tickets & Orders</h1>
            <p>View your match ticket orders and open the digital QR codes for each game.</p>
        </div>
    </section>

    <section class="member-card">
        <div class="member-card__header">
            <h2>Match Ticket Orders</h2>
            <a href="/tickets" class="btn btn-sm btn-brand"><i class="fa-solid fa-plus" aria-hidden="true"></i> Buy Tickets</a>
        </div>
        <div class="member-card__body">
            <?php if (!$matchOrders): ?>
                <div class="text-center text-muted py-4">
                    <div class="h5 text-dark mb-2">No match tickets yet</div>
                    <p class="mb-3">Any match tickets bought with this account will appear here.</p>
                    <a class="btn btn-brand" href="/tickets">Buy Tickets</a>
                </div>
            <?php else: ?>
                <div class="member-list">
                    <?php foreach ($matchOrders as $order): ?>
                        <?php
                        $isComplete = (int) $order['paid'] === 1 && (string) $order['status'] === 'complete';
                        $isRefunded = (string) $order['status'] === 'refunded';
                        $isCancelled = (string) $order['status'] === 'cancelled';
                        $statusClass = $isComplete ? 'text-bg-success' : ($isRefunded || $isCancelled ? 'text-bg-secondary' : 'text-bg-warning');
                        $statusLabel = $isComplete ? 'Ready' : ($isRefunded ? 'Refunded' : ($isCancelled ? 'Cancelled' : 'Pending'));
                        ?>
                        <article class="member-row member-row--ticket-order">
                            <div>
                                <div class="member-row__title">vs <?= h((string) $order['opponent']) ?></div>
                                <div class="member-row__meta">
                                    <?= h(member_format_date((string) $order['match_date'])) ?><?= !empty($order['kickoff_time']) ? ' · ' . h(member_format_time((string) $order['kickoff_time'])) : '' ?>
                                    · Order #<?= (int) $order['id'] ?>
                                </div>
                                <div class="member-row__meta"><?= (int) $order['ticket_count'] ?> ticket<?= (int) $order['ticket_count'] === 1 ? '' : 's' ?> · <?= (int) $order['used_count'] ?> used · <?= gbp((float) $order['total_amount']) ?></div>
                            </div>
                            <div class="member-row__actions">
                                <span class="badge <?= $statusClass ?>"><?= h($statusLabel) ?></span>
                                <?php if ($isComplete): ?>
                                    <a class="btn btn-sm btn-brand" href="<?= h(match_ticket_public_order_url($order)) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-qrcode" aria-hidden="true"></i> Open</a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
