<?php
declare(strict_types=1);

/**
 * "New order" notifications for the Hub menu.
 *
 * Four sections are tracked: shop, sponsors, season_tickets, match_tickets. For each admin a
 * "last seen" time is stored per section; opening that section's orders page moves it to now
 * (so it is read per admin). On top of that, an order stops counting for EVERYONE as soon as
 * it has been dealt with: a shop order that is no longer 'paid', a sponsor-shop item that has
 * been resolved, a cancelled agreement, and so on — see the count queries below.
 *
 * Nothing before the feature was switched on is counted (the baseline is the install time).
 */

const ORDER_ALERT_SECTIONS = ['shop', 'sponsors', 'season_tickets', 'match_tickets', 'hospitality', 'enquiries'];

/** Which admin page marks which section as read. */
const ORDER_ALERT_PAGES = [
    'shop_orders.php' => 'shop',
    'sponsor_orders.php' => 'sponsors',
    'season_ticket_orders.php' => 'season_tickets',
    'ticket_orders.php' => 'match_tickets',
    'hospitality.php' => 'hospitality',
    'enquiry.php' => 'enquiries',
];

function order_alerts_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_order_seen (
        account_id INT UNSIGNED NOT NULL,
        section VARCHAR(30) NOT NULL,
        last_seen_at DATETIME NOT NULL,
        PRIMARY KEY (account_id, section)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_order_alert_meta (
        meta_key VARCHAR(40) NOT NULL PRIMARY KEY,
        meta_value VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO admin_order_alert_meta (meta_key, meta_value) VALUES ('baseline', NOW())");
    $done = true;
}

function order_alerts_baseline(PDO $pdo): string
{
    order_alerts_ensure_schema($pdo);
    return (string) $pdo->query("SELECT meta_value FROM admin_order_alert_meta WHERE meta_key = 'baseline'")->fetchColumn();
}

/** @return array<string,string> section => last seen datetime (baseline when never seen) */
function order_alerts_seen_map(PDO $pdo, int $accountId): array
{
    order_alerts_ensure_schema($pdo);
    $baseline = order_alerts_baseline($pdo);
    $map = array_fill_keys(ORDER_ALERT_SECTIONS, $baseline);
    $stmt = $pdo->prepare('SELECT section, last_seen_at FROM admin_order_seen WHERE account_id = :a');
    $stmt->execute([':a' => $accountId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string) $row['section']] = max((string) $row['last_seen_at'], $baseline);
    }
    return $map;
}

function order_alerts_mark_seen(PDO $pdo, int $accountId, string $section): void
{
    if ($accountId <= 0 || !in_array($section, ORDER_ALERT_SECTIONS, true)) {
        return;
    }
    order_alerts_ensure_schema($pdo);
    $pdo->prepare('INSERT INTO admin_order_seen (account_id, section, last_seen_at) VALUES (:a, :s, NOW())
        ON DUPLICATE KEY UPDATE last_seen_at = NOW()')->execute([':a' => $accountId, ':s' => $section]);
}

function order_alerts_count_query(PDO $pdo, string $sql, array $params = []): int
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0; // a missing table must never break the menu
    }
}

/**
 * @return array<string,int> unread count per section for this admin
 */
function order_alerts_counts(PDO $pdo, int $accountId): array
{
    $seen = order_alerts_seen_map($pdo, $accountId);

    $counts = [];
    // Shop: paid orders still waiting to be dealt with (status changes away from 'paid' once handled).
    $counts['shop'] = order_alerts_count_query($pdo,
        "SELECT COUNT(*) FROM shop_orders WHERE status = 'paid' AND COALESCE(paid_at, created_at) > :t",
        [':t' => $seen['shop']]);
    // Season tickets / match tickets: paid, completed orders since last visit.
    $counts['season_tickets'] = order_alerts_count_query($pdo,
        "SELECT COUNT(*) FROM season_ticket_orders WHERE status = 'complete' AND paid = 1 AND created_at > :t",
        [':t' => $seen['season_tickets']]);
    $counts['match_tickets'] = order_alerts_count_query($pdo,
        "SELECT COUNT(*) FROM match_ticket_orders WHERE status = 'complete' AND paid = 1 AND created_at > :t",
        [':t' => $seen['match_tickets']]);
    // Hospitality: paid bookings since last visit.
    $counts['hospitality'] = order_alerts_count_query($pdo,
        "SELECT COUNT(*) FROM hospitality_bookings WHERE status = 'paid' AND paid_at > :t",
        [':t' => $seen['hospitality']]);
    // Enquiries: website enquiries still marked 'new' (stop counting for everyone once replied/done).
    $counts['enquiries'] = order_alerts_count_query($pdo,
        "SELECT COUNT(*) FROM enquiries WHERE status = 'new' AND created_at > :t",
        [':t' => $seen['enquiries']]);
    // Sponsors: members' purchases (one per basket), public shop orders since last visit,
    // plus any double-booked item that still needs resolving (counted until resolved, for everyone).
    $counts['sponsors'] =
        order_alerts_count_query($pdo,
            "SELECT COUNT(DISTINCT CONCAT(sponsor_id, '|', notes)) FROM sponsorship_agreements
              WHERE notes LIKE 'self_serve:%' AND status = 'active' AND created_at > :t",
            [':t' => $seen['sponsors']])
        + order_alerts_count_query($pdo,
            "SELECT COUNT(*) FROM player_sponsorship_orders
              WHERE status IN ('paid','partially_conflicted') AND COALESCE(paid_at, created_at) > :t",
            [':t' => $seen['sponsors']])
        + order_alerts_count_query($pdo,
            "SELECT COUNT(*) FROM match_sponsorship_orders
              WHERE status IN ('paid','partially_conflicted') AND COALESCE(paid_at, created_at) > :t",
            [':t' => $seen['sponsors']])
        + order_alerts_count_query($pdo,
            "SELECT COUNT(DISTINCT order_id) FROM match_sponsorship_order_items WHERE status = 'conflict_refund_due'")
        + order_alerts_count_query($pdo,
            "SELECT COUNT(DISTINCT order_id) FROM player_sponsorship_order_items WHERE status = 'conflict_refund_due'
                AND order_id NOT IN (SELECT id FROM player_sponsorship_orders WHERE COALESCE(paid_at, created_at) > :t)",
            [':t' => $seen['sponsors']]);

    return $counts;
}

/** Small red count pill for a menu link, or '' when there is nothing new. */
function order_alert_badge(int $count): string
{
    if ($count <= 0) {
        return '';
    }
    return '<span class="badge rounded-pill text-bg-danger hub-order-badge ms-2" title="' . $count . ' new">' . ($count > 99 ? '99+' : (string) $count) . '</span>';
}
