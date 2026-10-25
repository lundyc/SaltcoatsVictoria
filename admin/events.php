<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Events',
    'title' => 'Events',
    'subtitle' => 'Ticketed occasions that are not matches, such as speaker nights and tribute nights.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/events.php';
ensureEventsSchema($pdo);

$canManage = hub_auth_has_capability('events_manage');
$when = in_array($_GET['when'] ?? '', ['past', 'all'], true) ? (string) $_GET['when'] : 'upcoming';
$events = events_list($pdo, $when);
$upcoming = $when === 'upcoming' ? $events : events_list($pdo, 'upcoming');

$statusTone = ['draft' => 'secondary', 'on_sale' => 'success', 'sold_out' => 'warning', 'postponed' => 'warning', 'cancelled' => 'danger', 'finished' => 'dark'];
$onSale = count(array_filter($upcoming, static fn (array $e): bool => $e['status'] === 'on_sale'));
$drafts = count(array_filter($upcoming, static fn (array $e): bool => $e['status'] === 'draft'));
$next = null;
foreach ($upcoming as $candidate) {
    if (!in_array($candidate['status'], ['cancelled', 'finished'], true) && strtotime((string) $candidate['starts_at']) >= time()) {
        $next = $candidate;
        break;
    }
}
$filters = ['upcoming' => 'Upcoming', 'past' => 'Past', 'all' => 'All'];
?>

<?php hub_render_metric_grid([
    ['label' => 'Upcoming events', 'value' => (string) count($upcoming), 'meta' => $drafts > 0 ? $drafts . ' still in draft' : 'None in draft', 'icon' => 'fa-calendar-days', 'tone' => 'primary'],
    ['label' => 'On sale now', 'value' => (string) $onSale, 'meta' => $onSale === 1 ? 'event selling tickets' : 'events selling tickets', 'icon' => 'fa-ticket', 'tone' => 'success'],
    ['label' => 'Next event', 'value' => $next ? date('j M', strtotime((string) $next['starts_at'])) : 'None', 'meta' => $next ? (string) $next['title'] : 'Nothing scheduled', 'icon' => 'fa-clock', 'tone' => 'info'],
], 'Events summary'); ?>

<div class="hub-section-commandbar">
    <div><h2>Club events</h2><p>Each event has its own venue, capacity and ticket types. Events never appear on the Fixtures or Results pages.</p></div>
    <div class="hub-local-actions">
        <?php if ($canManage): ?><a href="event_edit.php" class="btn btn-brand"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add event</a><?php endif; ?>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Show events">
    <?php foreach ($filters as $key => $label): ?>
        <a class="btn btn-sm <?= $when === $key ? 'btn-brand' : 'btn-outline-secondary' ?>" href="events.php?when=<?= h($key) ?>"<?= $when === $key ? ' aria-current="true"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="card hub-list-card hub-table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle hub-data-table hub-data-table--responsive">
                <caption class="visually-hidden">Events, dates, venues, capacity, status and actions</caption>
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Date</th>
                        <th>Venue</th>
                        <th>Seats allocated</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <?php
                        $capacity = events_capacity($event);
                        $allocated = (int) $event['allocated_seats'];
                        $pct = $capacity > 0 ? min(100, (int) round($allocated / $capacity * 100)) : 0;
                        $over = $allocated > $capacity;
                        ?>
                        <tr>
                            <td data-label="Event">
                                <a class="fw-semibold text-decoration-none" href="event_edit.php?id=<?= (int) $event['id'] ?>"><?= h((string) $event['title']) ?></a>
                                <?php if ($event['visibility'] === 'hidden'): ?><span class="badge bg-secondary ms-1">Hidden</span><?php endif; ?>
                                <?php if ((int) $event['min_age'] > 0): ?><span class="badge bg-dark ms-1"><?= h(EVENT_MIN_AGES[(int) $event['min_age']]) ?></span><?php endif; ?>
                                <?php if (!empty($event['short_description'])): ?><div class="text-muted small"><?= h((string) $event['short_description']) ?></div><?php endif; ?>
                            </td>
                            <td data-label="Date">
                                <strong><?= h(date('D j M Y', strtotime((string) $event['starts_at']))) ?></strong>
                                <div class="text-muted small"><?= h(date('H:i', strtotime((string) $event['starts_at']))) ?><?= !empty($event['doors_at']) ? ' · doors ' . h(date('H:i', strtotime((string) $event['doors_at']))) : '' ?></div>
                            </td>
                            <td data-label="Venue"><?= h((string) $event['venue_name']) ?></td>
                            <td data-label="Seats allocated" style="min-width:9rem">
                                <?php if ((int) $event['type_count'] > 0): ?>
                                    <div class="d-flex justify-content-between small"><span><?= $allocated ?> of <?= $capacity ?></span><span class="text-muted"><?= (int) $event['type_count'] ?> ticket type<?= (int) $event['type_count'] === 1 ? '' : 's' ?></span></div>
                                    <div class="progress mt-1" style="height:6px" role="progressbar" aria-label="Seats allocated" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?= $over ? 'bg-danger' : ($pct >= 90 ? 'bg-warning' : 'bg-success') ?>" style="width:<?= $pct ?>%"></div></div>
                                <?php else: ?>
                                    <span class="text-muted small">No tickets yet · holds <?= $capacity ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Status"><?php hub_render_badge(EVENT_STATUSES[$event['status']] ?? (string) $event['status'], $statusTone[$event['status']] ?? 'secondary'); ?></td>
                            <td data-label="Actions" class="text-nowrap">
                                <div class="hub-row-actions hub-actions">
                                    <a href="event_dashboard.php?event_id=<?= (int) $event['id'] ?>" class="btn btn-sm btn-outline-primary">Sales</a>
                                    <a href="event_tickets.php?event_id=<?= (int) $event['id'] ?>" class="btn btn-sm btn-outline-primary">Tickets</a>
                                    <a href="event_edit.php?id=<?= (int) $event['id'] ?>" class="btn btn-sm btn-outline-secondary"><?= $canManage ? 'Edit' : 'View' ?></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$events): ?>
                        <tr><td colspan="6" class="hub-record-empty hub-empty-state"><?= $when === 'past' ? 'No past events.' : 'No events yet. Add an event to start selling tickets.' ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
