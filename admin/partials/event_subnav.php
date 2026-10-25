<?php
declare(strict_types=1);

/**
 * Event-level navigation shown on every page about ONE event (details, tickets, sales, attendees, messages).
 * Expects: $event (array) and $eventNavActive (string: details|tickets|sales|attendees|messages).
 */
$eventNavActive = $eventNavActive ?? '';
$eventNavItems = [
    'details' => ['Details', 'event_edit.php?id=' . (int) $event['id'], 'fa-pen'],
    'tickets' => ['Tickets', 'event_tickets.php?event_id=' . (int) $event['id'], 'fa-ticket'],
    'sales' => ['Sales', 'event_dashboard.php?event_id=' . (int) $event['id'], 'fa-chart-simple'],
    'attendees' => ['Attendees', 'event_attendees.php?event_id=' . (int) $event['id'], 'fa-users'],
    'promos' => ['Promo codes', 'event_promos.php?event_id=' . (int) $event['id'], 'fa-tags'],
    'messages' => ['Messages', 'event_message.php?event_id=' . (int) $event['id'], 'fa-envelope'],
];
?>
<nav class="d-flex flex-wrap gap-2 mb-3" aria-label="This event">
    <?php foreach ($eventNavItems as $key => [$label, $href, $icon]): ?>
        <a class="btn btn-sm <?= $key === $eventNavActive ? 'btn-brand' : 'btn-outline-secondary' ?>" href="<?= h($href) ?>"<?= $key === $eventNavActive ? ' aria-current="page"' : '' ?>><i class="fa-solid <?= h($icon) ?> me-1" aria-hidden="true"></i><?= h($label) ?></a>
    <?php endforeach; ?>
</nav>
