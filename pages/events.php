<?php
/** Route: /events — upcoming club events (speaker nights, tribute nights and so on). Events live in their own
 *  tables and never appear on the Fixtures or Results pages. Reuses admin/lib/events*.php. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/events_orders.php';

ensureEventOrdersSchema(db());
if (random_int(1, 25) === 1) {
    events_release_expired_holds(db());
    events_purge_access_needs(db());
}

// Public list: events that are open to the public and not hidden. Drafts are never shown.
$stmt = db()->query("SELECT e.*, v.name AS venue_name, v.capacity AS venue_capacity
    FROM events e JOIN event_venues v ON v.id = e.venue_id
    WHERE e.visibility = 'public' AND e.status IN ('on_sale', 'sold_out', 'postponed')
      AND e.starts_at >= (NOW() - INTERVAL 1 DAY)
    ORDER BY e.starts_at ASC");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusChip = ['sold_out' => 'Sold out', 'postponed' => 'Postponed'];

set_meta(['title' => 'Events', 'description' => 'Events at ' . club('club_name') . ' — speaker nights, tribute nights and more. Book tickets online.']);
seo_breadcrumbs([['Events', url('events')]]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'What\'s on',
    'title'   => 'Events',
    'sub'     => 'Nights out at the club. Book online and show your QR ticket at the door.',
]); ?>

<div class="page">
  <div class="container">
    <?php if (!$events): ?>
      <div class="emptystate">
        <p><strong>No events are on sale right now.</strong></p>
        <p>Check back soon, or follow the club on social media for announcements.</p>
      </div>
    <?php else: ?>
      <?php
      $byMonth = [];
      foreach ($events as $ev) {
          $byMonth[format_date($ev['starts_at'], 'F Y')][] = $ev;
      }
      ?>
      <?php foreach ($byMonth as $month => $rows): ?>
        <div class="tkt-month"><?= e($month) ?></div>
        <ul class="tkt-list">
          <?php foreach ($rows as $ev): ?>
            <li>
              <a class="tkt-card" href="<?= e(url('events/' . $ev['slug'])) ?>">
                <span class="tkt-card__date">
                  <b><?= e(format_date($ev['starts_at'], 'D')) ?></b>
                  <em><?= e(format_date($ev['starts_at'], 'j')) ?></em>
                  <span><?= e(format_date($ev['starts_at'], 'M')) ?></span>
                </span>
                <span class="tkt-card__body">
                  <strong><?= e((string) $ev['title']) ?><?php if (isset($statusChip[$ev['status']])): ?> <span class="tkt-chip"><?= e($statusChip[$ev['status']]) ?></span><?php endif; ?></strong>
                  <span><?= e(format_time($ev['starts_at'])) ?> · <?= e((string) $ev['venue_name']) ?><?= (int) $ev['min_age'] > 0 ? ' · ' . e(EVENT_MIN_AGES[(int) $ev['min_age']]) : '' ?></span>
                  <?php if (!empty($ev['short_description'])): ?><span><?= e((string) $ev['short_description']) ?></span><?php endif; ?>
                </span>
                <span class="tkt-card__cta"><?= $ev['status'] === 'on_sale' ? 'Get tickets' : 'Details' ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
