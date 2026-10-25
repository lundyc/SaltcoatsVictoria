<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/events_orders.php';

$eventOrders = events_orders_for_member($pdo, $currentHolder);
$upcoming = array_values(array_filter(events_list($pdo, 'upcoming'), static fn (array $e): bool => $e['visibility'] === 'public' && in_array($e['status'], ['on_sale', 'sold_out', 'postponed'], true)));
?>

<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">Events</div>
            <h1>My Events</h1>
            <p>Your tickets for club events, and what's coming up.</p>
        </div>
    </section>

    <section class="member-card">
        <div class="member-card__header">
            <h2>My event tickets</h2>
            <a href="/events" class="btn btn-sm btn-brand"><i class="fa-solid fa-plus" aria-hidden="true"></i> Book an event</a>
        </div>
        <div class="member-card__body">
            <?php if (!$eventOrders): ?>
                <div class="text-center text-muted py-4">
                    <div class="h5 text-dark mb-2">No event tickets yet</div>
                    <p class="mb-3">Event tickets bought with this account's email address will appear here.</p>
                    <a class="btn btn-brand" href="/events">See what's on</a>
                </div>
            <?php else: ?>
                <div class="member-list">
                    <?php foreach ($eventOrders as $eo): ?>
                        <?php $past = strtotime((string) $eo['starts_at']) < time() - 86400; ?>
                        <article class="member-row member-row--ticket-order">
                            <div>
                                <div class="member-row__title"><?= h((string) $eo['title']) ?></div>
                                <div class="member-row__meta"><?= h(member_format_date((string) $eo['starts_at'])) ?> · <?= h((string) $eo['venue_name']) ?> · <?= (int) $eo['ticket_count'] ?> ticket<?= (int) $eo['ticket_count'] === 1 ? '' : 's' ?></div>
                            </div>
                            <div class="member-row__actions">
                                <span class="badge <?= $eo['paid'] ? ($past ? 'text-bg-secondary' : 'text-bg-success') : 'text-bg-warning' ?>"><?= $eo['paid'] ? ($past ? 'Past' : 'Ready') : 'Pending' ?></span>
                                <?php if ($eo['paid'] && !empty($eo['access_token'])): ?><a class="btn btn-sm btn-brand" href="/events/order/<?= h(rawurlencode((string) $eo['access_token'])) ?>" target="_blank" rel="noopener">Open</a><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="member-card">
        <div class="member-card__header"><h2>What's on</h2><a href="/events" class="btn btn-sm btn-outline-secondary">All events</a></div>
        <div class="member-card__body">
            <?php if (!$upcoming): ?>
                <p class="text-muted mb-0">No events are on sale right now. Check back soon.</p>
            <?php else: ?>
                <div class="member-list">
                    <?php foreach ($upcoming as $ev): ?>
                        <a class="member-row" href="/events/<?= h(rawurlencode((string) $ev['slug'])) ?>">
                            <div>
                                <div class="member-row__title"><?= h((string) $ev['title']) ?></div>
                                <div class="member-row__meta"><?= h(member_format_date((string) $ev['starts_at'])) ?> · <?= h((string) $ev['venue_name']) ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
