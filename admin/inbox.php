<?php
declare(strict_types=1);

// Inbox — one list of recent orders and website enquiries across the shop, sponsor shop, season tickets,
// match tickets and hospitality. Each row opens the order on its own page, and the "new"
// markers use the same per-admin "last seen" times as the menu badges (lib/order_alerts.php).

$pageHero = [
    'eyebrow' => 'Home',
    'title' => 'Inbox',
    'subtitle' => 'Orders and website enquiries in one place. Open a row to deal with it.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/enquiries.php';
enquiry_mail_pull_throttled($pdo);

$sources = [
    'shop' => ['label' => 'Shop', 'icon' => 'fa-bag-shopping', 'color' => '#0d6efd', 'caps' => ['shop'], 'page' => '/admin/shop_orders.php'],
    'sponsors' => ['label' => 'Sponsors', 'icon' => 'fa-handshake', 'color' => '#198754', 'caps' => ['sponsorship', 'finance_view'], 'page' => '/admin/sponsor_orders.php'],
    'season_tickets' => ['label' => 'Season tickets', 'icon' => 'fa-id-card', 'color' => '#6f42c1', 'caps' => ['tickets_ops'], 'page' => '/admin/season_ticket_orders.php'],
    'match_tickets' => ['label' => 'Match tickets', 'icon' => 'fa-ticket', 'color' => '#fd7e14', 'caps' => ['tickets_ops'], 'page' => '/admin/ticket_orders.php'],
    'hospitality' => ['label' => 'Hospitality', 'icon' => 'fa-champagne-glasses', 'color' => '#d63384', 'caps' => ['tickets_ops'], 'page' => '/admin/hospitality.php'],
    'enquiries' => ['label' => 'Enquiries', 'icon' => 'fa-envelope', 'color' => '#0aa2c0', 'caps' => ['shop', 'tickets_ops', 'sponsorship', 'finance_view', 'content_social'], 'page' => '/admin/enquiry.php'],
];
$allowed = array_keys(array_filter($sources, static fn(array $s): bool => hub_auth_has_any_capability($s['caps'])));
if ($allowed === []) {
    http_response_code(403);
    echo '<div class="alert alert-warning">Your login does not include any inbox areas.</div>';
    require __DIR__ . '/footer.php';
    exit;
}

$accountId = (int) (hub_auth_current_user()['account_id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'mark_all_read') {
    if (hub_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        foreach ($allowed as $section) {
            order_alerts_mark_seen($pdo, $accountId, $section);
        }
        try {
            $pdo->prepare('UPDATE inbox_item_state SET is_read = NULL WHERE account_id = :a')->execute([':a' => $accountId]);
        } catch (Throwable $exception) {
            // table not created yet: nothing to reset
        }
    }
    header('Location: /admin/inbox.php?read=1');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'row') {
    $back = (string) ($_POST['back'] ?? '');
    $back = '/admin/inbox.php' . (str_starts_with($back, '?') ? $back : '');
    $key = (string) ($_POST['item_key'] ?? '');
    $do = (string) ($_POST['do'] ?? '');
    $msg = '';
    if (hub_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? '')) && preg_match('/^([a-z_]+)(?::[a-z])?:(\d+)$/', $key, $km) && in_array($km[1], $allowed, true)) {
        $itemId = (int) $km[2];
        $now = date('Y-m-d H:i:s');
        $setState = static function (string $col, int $val) use ($pdo, $accountId, $key, $now): void {
            $pdo->prepare("INSERT INTO inbox_item_state (account_id, item_key, $col, updated_at) VALUES (:a, :k, :v, :n)
                ON DUPLICATE KEY UPDATE $col = VALUES($col), updated_at = VALUES(updated_at)")
                ->execute([':a' => $accountId, ':k' => $key, ':v' => $val, ':n' => $now]);
        };
        try {
            if ($do === 'read' || $do === 'unread') {
                $setState('is_read', $do === 'read' ? 1 : 0);
            } elseif ($do === 'archive' || $do === 'restore') {
                $setState('archived', $do === 'archive' ? 1 : 0);
            } elseif ($km[1] === 'shop' && ($do === 'paid' || $do === 'collected') && hub_auth_has_capability('shop')) {
                require_once __DIR__ . '/lib/shop.php';
                require_once __DIR__ . '/lib/audit.php';
                shop_ensure_schema($pdo);
                if ($do === 'paid') {
                    shop_mark_order_paid($pdo, $itemId, null);
                    auditLog($pdo, 'shop_order_marked_paid', 'Shop order #' . $itemId . ' marked paid manually (Inbox)');
                    $msg = 'Order marked as paid.';
                } else {
                    shop_mark_collected($pdo, $itemId);
                    auditLog($pdo, 'shop_order_collected', 'Shop order #' . $itemId . ' marked collected (Inbox)');
                    $msg = 'Order marked as collected.';
                }
            } elseif ($km[1] === 'enquiries' && $do === 'done') {
                $user = hub_auth_current_user();
                $pdo->prepare("UPDATE enquiries SET status = 'done', handled_by = :h WHERE id = :i")
                    ->execute([':h' => (string) (($user['display_name'] ?? '') !== '' ? $user['display_name'] : ($user['username'] ?? $user['email'] ?? '')), ':i' => $itemId]);
                $msg = 'Enquiry marked as done.';
            }
        } catch (Throwable $exception) {
            error_log('[inbox] row action failed: ' . $exception->getMessage());
            $msg = 'That did not work. Open the item to do it there.';
        }
    }
    if ($msg !== '') {
        $_SESSION['inbox_flash'] = $msg;
    }
    header('Location: ' . $back);
    exit;
}

$type = (string) ($_GET['type'] ?? 'all');
if ($type !== 'all' && !in_array($type, $allowed, true)) {
    $type = 'all';
}
$days = (int) ($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90], true)) {
    $days = 30;
}
$showUnpaid = !empty($_GET['unpaid']);
$since = (new DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d H:i:s');
$seen = order_alerts_seen_map($pdo, $accountId);

/** Run a query for one source; a missing table must never take the page down. */
$fetch = static function (string $sql, array $params) use ($pdo): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        return [];
    }
};

$rows = [];
$pdo->exec("CREATE TABLE IF NOT EXISTS inbox_item_state (
    account_id INT NOT NULL,
    item_key VARCHAR(60) NOT NULL,
    is_read TINYINT NULL,
    archived TINYINT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (account_id, item_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$states = [];
foreach ($fetch('SELECT item_key, is_read, archived FROM inbox_item_state WHERE account_id = :a', [':a' => $accountId]) as $st) {
    $states[(string) $st['item_key']] = $st;
}
$showArchived = !empty($_GET['archived']);
$add = static function (string $section, array $row) use (&$rows, $seen, $states, $showArchived): void {
    $row['section'] = $section;
    $st = $states[$row['key']] ?? null;
    $row['archived'] = $st !== null && (int) $st['archived'] === 1;
    if ($row['archived'] !== $showArchived) {
        return;
    }
    $row['is_new'] = $st !== null && $st['is_read'] !== null
        ? (int) $st['is_read'] === 0
        : (string) $row['placed'] > ($seen[$section] ?? '9999-12-31');
    $rows[] = $row;
};
$wanted = static fn(string $section): bool => in_array($section, $allowed, true) && ($type === 'all' || $type === $section);

if ($wanted('shop')) {
    foreach ($fetch("SELECT id, order_ref, customer_name, total, status, is_preorder, COALESCE(paid_at, created_at) AS placed
        FROM shop_orders WHERE created_at >= :s" . ($showUnpaid ? '' : ' AND paid_at IS NOT NULL') . ' ORDER BY placed DESC LIMIT 60', [':s' => $since]) as $r) {
        $add('shop', ['key' => 'shop:' . (int) $r['id'], 'raw_status' => (string) $r['status'], 'who' => (string) $r['customer_name'], 'what' => 'Order ' . $r['order_ref'] . ((int) $r['is_preorder'] === 1 ? ' (pre-order)' : ''),
            'amount' => (float) $r['total'], 'status' => (string) $r['status'], 'placed' => (string) $r['placed'], 'href' => '/admin/shop_order.php?id=' . (int) $r['id']]);
    }
}
if ($wanted('season_tickets')) {
    foreach ($fetch("SELECT o.id, h.name AS holder, t.name AS ticket, o.price, o.status, o.created_at AS placed
        FROM season_ticket_orders o JOIN season_ticket_holders h ON h.id = o.holder_id LEFT JOIN season_ticket_types t ON t.id = o.ticket_type_id
        WHERE o.created_at >= :s" . ($showUnpaid ? '' : ' AND o.paid = 1') . ' ORDER BY o.created_at DESC LIMIT 60', [':s' => $since]) as $r) {
        $add('season_tickets', ['key' => 'season_tickets:' . (int) $r['id'], 'who' => (string) $r['holder'], 'what' => (string) ($r['ticket'] ?? 'Season ticket'),
            'amount' => (float) $r['price'], 'status' => (string) $r['status'], 'placed' => (string) $r['placed'], 'href' => '/admin/season_ticket_order.php?id=' . (int) $r['id']]);
    }
}
if ($wanted('match_tickets')) {
    foreach ($fetch("SELECT id, buyer_name, total_amount, status, created_at AS placed
        FROM match_ticket_orders WHERE created_at >= :s" . ($showUnpaid ? '' : ' AND paid = 1') . ' ORDER BY created_at DESC LIMIT 60', [':s' => $since]) as $r) {
        $add('match_tickets', ['key' => 'match_tickets:' . (int) $r['id'], 'who' => (string) $r['buyer_name'], 'what' => 'Match ticket order #' . (int) $r['id'],
            'amount' => (float) $r['total_amount'], 'status' => (string) $r['status'], 'placed' => (string) $r['placed'], 'href' => '/admin/ticket_orders.php?id=' . (int) $r['id']]);
    }
}
if ($wanted('hospitality')) {
    foreach ($fetch("SELECT id, contact_name, company, guests, total_amount, status, COALESCE(paid_at, created_at) AS placed
        FROM hospitality_bookings WHERE created_at >= :s" . ($showUnpaid ? '' : ' AND paid_at IS NOT NULL') . ' ORDER BY placed DESC LIMIT 60', [':s' => $since]) as $r) {
        $add('hospitality', ['key' => 'hospitality:' . (int) $r['id'], 'who' => (string) $r['contact_name'] . ((string) ($r['company'] ?? '') !== '' ? ' (' . $r['company'] . ')' : ''),
            'what' => (int) $r['guests'] . ' guest' . ((int) $r['guests'] === 1 ? '' : 's'),
            'amount' => (float) $r['total_amount'], 'status' => (string) $r['status'], 'placed' => (string) $r['placed'], 'href' => '/admin/hospitality_booking.php?id=' . (int) $r['id']]);
    }
}
if ($wanted('enquiries')) {
    $depLabels = array_map(static fn(array $d): string => $d['label'], enquiry_departments());
    foreach ($fetch("SELECT id, ref, department, name, subject, status, created_at AS placed
        FROM enquiries WHERE created_at >= :s" . ($showUnpaid ? '' : " AND status <> 'done'") . ' ORDER BY created_at DESC LIMIT 60', [':s' => $since]) as $r) {
        $add('enquiries', ['key' => 'enquiries:' . (int) $r['id'], 'raw_status' => (string) $r['status'], 'who' => (string) $r['name'], 'what' => ($depLabels[$r['department']] ?? 'Enquiry') . ((string) $r['subject'] !== '' ? ': ' . $r['subject'] : ''),
            'amount' => null, 'status' => (string) $r['status'], 'placed' => (string) $r['placed'], 'href' => '/admin/enquiry.php?id=' . (int) $r['id']]);
    }
}
if ($wanted('sponsors')) {
    $sponsorHref = '/admin/sponsor_orders.php';
    foreach ($fetch("SELECT id, buyer_name, total_amount, status, COALESCE(paid_at, created_at) AS placed
        FROM player_sponsorship_orders WHERE status NOT IN ('pending_payment','enquiry','declined')" . ($showUnpaid ? '' : " AND status <> 'expired'") . " AND COALESCE(paid_at, created_at) >= :s ORDER BY placed DESC LIMIT 60", [':s' => $since]) as $r) {
        $add('sponsors', ['key' => 'sponsors:p:' . (int) $r['id'], 'who' => (string) $r['buyer_name'], 'what' => 'Sponsor a Player order #' . (int) $r['id'],
            'amount' => (float) $r['total_amount'], 'status' => (string) $r['status'], 'placed' => (string) $r['placed'], 'href' => $sponsorHref]);
    }
    foreach ($fetch("SELECT id, buyer_name, total_amount, status, COALESCE(paid_at, created_at) AS placed
        FROM match_sponsorship_orders WHERE status NOT IN ('pending_payment','enquiry','declined')" . ($showUnpaid ? '' : " AND status <> 'expired'") . " AND COALESCE(paid_at, created_at) >= :s ORDER BY placed DESC LIMIT 60", [':s' => $since]) as $r) {
        $add('sponsors', ['key' => 'sponsors:m:' . (int) $r['id'], 'who' => (string) $r['buyer_name'], 'what' => 'Match sponsorship order #' . (int) $r['id'],
            'amount' => (float) $r['total_amount'], 'status' => (string) $r['status'], 'placed' => (string) $r['placed'], 'href' => $sponsorHref]);
    }
    foreach ($fetch("SELECT MIN(a.id) AS id, s.name AS sponsor, SUM(a.agreed_amount) AS amount, MIN(a.created_at) AS placed, COUNT(*) AS items
        FROM sponsorship_agreements a JOIN sponsors s ON s.id = a.sponsor_id
        WHERE a.notes LIKE 'self_serve:%' AND a.status = 'active' AND a.created_at >= :s
        GROUP BY a.sponsor_id, a.notes ORDER BY placed DESC LIMIT 60", [':s' => $since]) as $r) {
        $add('sponsors', ['key' => 'sponsors:a:' . (int) $r['id'], 'who' => (string) $r['sponsor'], 'what' => 'Members area purchase (' . (int) $r['items'] . ' item' . ((int) $r['items'] === 1 ? '' : 's') . ')',
            'amount' => (float) $r['amount'], 'status' => 'confirmed', 'placed' => (string) $r['placed'], 'href' => '/admin/sponsorship_agreement.php?id=' . (int) $r['id']]);
    }
}
usort($rows, static fn(array $a, array $b): int => strcmp((string) $b['placed'], (string) $a['placed']));
$rows = array_slice($rows, 0, 150);

/** Status -> colour family, so each state reads at a glance. */
$statusTone = static function (string $status): string {
    $s = strtolower($status);
    return match (true) {
        in_array($s, ['paid', 'confirmed', 'active', 'completed', 'complete', 'collected', 'fulfilled', 'shipped', 'done', 'approved', 'delivered'], true) => 'success',
        in_array($s, ['pending_payment', 'pending', 'replied', 'unpaid', 'awaiting_payment', 'awaiting', 'draft', 'on_hold'], true) => 'warning',
        in_array($s, ['new', 'open', 'received', 'processing', 'in_progress', 'ready', 'reserved'], true) => 'info',
        in_array($s, ['cancelled', 'canceled', 'failed', 'declined', 'rejected'], true) => 'danger',
        in_array($s, ['refunded', 'partially_refunded'], true) => 'purple',
        in_array($s, ['expired', 'archived', 'closed'], true) => 'muted',
        default => 'muted',
    };
};
$query = static fn(array $over): string => '?' . http_build_query(array_filter(array_merge(['type' => $type === 'all' ? '' : $type, 'days' => $days === 30 ? '' : $days, 'unpaid' => $showUnpaid ? '1' : '', 'archived' => $showArchived ? '1' : ''], $over), static fn($v): bool => $v !== '' && $v !== null));
$totalNew = hub_navigation_badge_total($hubOrderAlerts, $allowed);
?>
<div class="container-fluid px-0">
    <?php if (!empty($_SESSION['inbox_flash'])): ?>
        <div class="alert alert-success py-2"><?= h((string) $_SESSION['inbox_flash']) ?></div>
        <?php unset($_SESSION['inbox_flash']); ?>
    <?php endif; ?>
    <?php if (!empty($_GET['read'])): ?>
        <div class="alert alert-success py-2">Everything marked as read.</div>
    <?php endif; ?>

    <nav class="hub-section-tabs" aria-label="Inbox type">
        <ul class="nav">
            <li class="nav-item"><a class="nav-link<?= $type === 'all' ? ' active' : '' ?>"<?= $type === 'all' ? ' aria-current="page"' : '' ?> href="<?= h($query(['type' => ''])) ?>">All<?= $totalNew > 0 ? ' <span class="hub-tab-dot" title="' . $totalNew . ' new" aria-label="' . $totalNew . ' new"></span>' : '' ?></a></li>
            <?php foreach ($allowed as $section): $n = (int) ($hubOrderAlerts[$section] ?? 0); ?>
                <li class="nav-item"><a class="nav-link<?= $type === $section ? ' active' : '' ?>"<?= $type === $section ? ' aria-current="page"' : '' ?> href="<?= h($query(['type' => $section])) ?>"><i class="fa-solid <?= h($sources[$section]['icon']) ?> me-2" style="color: <?= h($sources[$section]['color']) ?>" aria-hidden="true"></i><?= h($sources[$section]['label']) ?><?= $n > 0 ? ' <span class="hub-tab-dot" title="' . $n . ' new" aria-label="' . $n . ' new"></span>' : '' ?></a></li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="d-flex flex-wrap align-items-center justify-content-end gap-2 mb-3 inbox-filters">
        <div class="d-flex flex-wrap align-items-center gap-2 inbox-filters__inner">
            <div class="btn-group btn-group-sm" role="group" aria-label="Time range">
                <?php foreach ([7, 30, 90] as $range): ?>
                    <a class="btn <?= $days === $range ? 'btn-brand' : 'btn-outline-secondary' ?>" href="<?= h($query(['days' => $range === 30 ? '' : $range])) ?>"><?= $range ?> days</a>
                <?php endforeach; ?>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="<?= h($query(['unpaid' => $showUnpaid ? '' : '1'])) ?>"><?= $showUnpaid ? 'Hide unpaid / completed' : 'Show unpaid / completed' ?></a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= h($query(['archived' => $showArchived ? '' : '1'])) ?>"><i class="fa-solid fa-box-archive me-1" aria-hidden="true"></i><?= $showArchived ? 'Back to inbox' : 'Show archived' ?></a>
            <?php if ($totalNew > 0): ?>
                <form method="post" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                    <input type="hidden" name="action" value="mark_all_read">
                    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="fa-solid fa-check-double me-1" aria-hidden="true"></i>Mark all as read</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <section class="card hub-panel">
        <table class="table align-middle mb-0 hub-data-table hub-data-table--responsive inbox-table" id="inboxTable">
            <thead><tr>
                <th data-sort="text" aria-sort="none"><button type="button" class="inbox-sort">Placed</button></th>
                <th data-sort="text" aria-sort="none"><button type="button" class="inbox-sort">Type</button></th>
                <th data-sort="text" aria-sort="none"><button type="button" class="inbox-sort">Customer</button></th>
                <th data-sort="text" aria-sort="none"><button type="button" class="inbox-sort">What</button></th>
                <th data-sort="num" class="text-end" aria-sort="none"><button type="button" class="inbox-sort">Total</button></th>
                <th data-sort="text" aria-sort="none"><button type="button" class="inbox-sort">Status</button></th>
                <th><span class="visually-hidden">Actions</span></th>
            </tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="text-muted text-center py-4">Nothing in the last <?= $days ?> days.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): $src = $sources[$row['section']]; $tone = $statusTone((string) $row['status']); ?>
                <tr class="inbox-row" tabindex="0" data-href="<?= h((string) $row['href']) ?>">
                    <td data-label="Placed" data-type="<?= h($src['label']) ?>" data-value="<?= h((string) $row['placed']) ?>" class="small text-muted text-nowrap"><?= h((new DateTimeImmutable((string) $row['placed']))->format('d/m/Y H:i')) ?><?php if ($row['is_new']): ?> <span class="badge text-bg-danger ms-1">New</span><?php endif; ?></td>
                    <td data-label="Type" data-value="<?= h($src['label']) ?>"><i class="fa-solid <?= h($src['icon']) ?> me-1" style="color: <?= h($src['color']) ?>" aria-hidden="true"></i><?= h($src['label']) ?></td>
                    <td data-label="Customer" class="fw-semibold"><?= h((string) $row['who']) ?></td>
                    <td data-label="What"><?= h((string) $row['what']) ?></td>
                    <td data-label="Total" data-value="<?= $row['amount'] === null ? '' : (float) $row['amount'] ?>" class="text-end fw-bold"><?= $row['amount'] === null ? '' : gbp((float) $row['amount']) ?></td>
                    <td data-label="Status"><span class="inbox-status inbox-status--<?= $tone ?>"><?= h(ucfirst(str_replace('_', ' ', (string) $row['status']))) ?></span></td>
                    <td data-label="Actions" class="text-end text-nowrap inbox-actions">
                        <?php
                        $rowBtn = static function (string $do, string $icon, string $title, string $class = 'btn-outline-secondary', string $confirm = '') use ($row, $query): void {
                            ?><form method="post" class="d-inline"<?= $confirm !== '' ? ' data-confirm="' . h($confirm) . '" data-confirm-action="' . h($title) . '"' : '' ?>>
                                <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                <input type="hidden" name="action" value="row">
                                <input type="hidden" name="do" value="<?= h($do) ?>">
                                <input type="hidden" name="item_key" value="<?= h((string) $row['key']) ?>">
                                <input type="hidden" name="back" value="<?= h($query([])) ?>">
                                <button class="btn btn-sm <?= h($class) ?>" type="submit" title="<?= h($title) ?>" aria-label="<?= h($title) ?>"><i class="fa-solid <?= h($icon) ?>" aria-hidden="true"></i></button>
                            </form><?php
                        };
                        $rs = (string) ($row['raw_status'] ?? '');
                        if (!$row['archived']) {
                            if ($row['section'] === 'shop' && $rs === 'pending_payment' && hub_auth_has_capability('shop')) {
                                $rowBtn('paid', 'fa-sterling-sign', 'Mark paid', 'btn-outline-success', 'Mark this order as paid without taking a Stripe payment?');
                            } elseif ($row['section'] === 'shop' && $rs === 'paid' && hub_auth_has_capability('shop')) {
                                $rowBtn('collected', 'fa-check', 'Mark collected', 'btn-outline-success', 'Mark this order as collected? The customer is emailed.');
                            } elseif ($row['section'] === 'enquiries' && $rs !== 'done') {
                                $rowBtn('done', 'fa-circle-check', 'Mark done', 'btn-outline-success');
                            }
                            $rowBtn($row['is_new'] ? 'read' : 'unread', $row['is_new'] ? 'fa-envelope-open' : 'fa-envelope', $row['is_new'] ? 'Mark as read' : 'Mark as unread');
                            $rowBtn('archive', 'fa-box-archive', 'Archive');
                        } else {
                            $rowBtn('restore', 'fa-rotate-left', 'Restore to inbox');
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <style>
            .hub-tab-dot { display: inline-block; width: 0.55rem; height: 0.55rem; margin-left: 0.4rem; border-radius: 50%; background: #dc3545; vertical-align: middle; box-shadow: 0 0 0 2px rgba(220, 53, 69, 0.25); }
            .inbox-row { cursor: pointer; }
            .inbox-row:hover > td, .inbox-row:focus-visible > td { background: rgba(75, 8, 24, 0.045); }
            .inbox-row:focus-visible { outline: 2px solid var(--brand-primary, #6a2036); outline-offset: -2px; }
            .inbox-sort { all: unset; cursor: pointer; font: inherit; color: inherit; display: inline-flex; align-items: center; gap: 0.35rem; }
            .inbox-sort::after { content: "\f0dc"; font-family: "Font Awesome 7 Free", "Font Awesome 6 Free", "Font Awesome 5 Free"; font-weight: 900; font-size: 0.7em; opacity: 0.35; }
            th[aria-sort="ascending"] .inbox-sort::after { content: "\f0de"; opacity: 1; }
            th[aria-sort="descending"] .inbox-sort::after { content: "\f0dd"; opacity: 1; }
            .inbox-sort:focus-visible { outline: 2px solid var(--brand-primary, #6a2036); outline-offset: 2px; }
            .inbox-status { display: inline-block; padding: 0.2rem 0.6rem; border-radius: 999px; font-size: 0.78rem; font-weight: 700; border: 1px solid transparent; }
            .inbox-status--success { background: #d1f0df; color: #0b5c33; border-color: #a6dfc0; }
            .inbox-status--warning { background: #fff0c2; color: #7a5600; border-color: #f2d98a; }
            .inbox-status--info { background: #d6e8ff; color: #0a4a9e; border-color: #aacbf7; }
            .inbox-status--danger { background: #fadadd; color: #8f1f2f; border-color: #f0b0b8; }
            .inbox-status--purple { background: #e8dcfa; color: #4b2a8a; border-color: #cdb7f0; }
            .inbox-status--muted { background: #e9e6e7; color: #4d4548; border-color: #d3cfd1; }

            /* Phones: each item becomes one compact card instead of seven label/value rows. */
            @media (max-width: 767.98px) {
                .inbox-filters, .inbox-filters__inner { justify-content: stretch; width: 100%; }
                .inbox-filters__inner > * { flex: 1 1 auto; }
                .inbox-filters .btn-group { width: 100%; }
                .inbox-filters .btn-group .btn { flex: 1 1 0; }
                .inbox-filters .btn, .inbox-filters .btn-sm { min-height: 44px; display: inline-flex; align-items: center; justify-content: center; }
                .inbox-filters form { flex: 1 1 100%; }
                .inbox-filters form .btn { width: 100%; }

                .inbox-table tbody { display: flex; flex-direction: column; gap: 0.75rem; padding: 0.75rem; }
                .inbox-table tbody tr.inbox-row {
                    display: grid;
                    grid-template-columns: minmax(0, 1fr) auto;
                    grid-template-areas: "who total" "what what" "meta status" "actions actions";
                    column-gap: 0.75rem;
                    row-gap: 0.35rem;
                    padding: 0.85rem 0.9rem 0.7rem;
                }
                .inbox-table tbody tr.inbox-row > td {
                    display: block;
                    width: auto;
                    padding: 0;
                    border: 0;
                    text-align: left !important;
                    min-width: 0;
                }
                .inbox-table tbody tr.inbox-row > td::before { content: none; }
                .inbox-table td[data-label="Customer"] { grid-area: who; font-size: 1.02rem; overflow-wrap: anywhere; }
                .inbox-table td[data-label="Total"] { grid-area: total; text-align: right !important; font-size: 1.02rem; }
                .inbox-table td[data-label="What"] { grid-area: what; color: #4d4548; font-size: 0.92rem; overflow-wrap: anywhere; }
                .inbox-table td[data-label="Placed"] { grid-area: meta; align-self: center; font-size: 0.78rem; white-space: normal !important; }
                .inbox-table td[data-label="Type"] { display: none !important; }
                .inbox-table td[data-label="Status"] { grid-area: status; text-align: right !important; }
                .inbox-table td[data-label="Actions"] {
                    grid-area: actions;
                    display: flex !important;
                    flex-wrap: wrap;
                    gap: 0.5rem;
                    margin-top: 0.4rem;
                    padding-top: 0.6rem;
                    border-top: 1px solid rgba(75, 8, 24, 0.075);
                }
                .inbox-table .inbox-actions form { display: block !important; flex: 1 1 0; }
                .inbox-table .inbox-actions .btn { width: 100%; min-height: 44px; }
                /* Show the type (Shop, Sponsors...) beside the date, since its own cell is hidden. */
                .inbox-table td[data-label="Placed"]::after { content: attr(data-type); margin-left: 0.5rem; padding-left: 0.5rem; border-left: 1px solid rgba(75, 8, 24, 0.2); font-weight: 700; color: #4d4548; }
                .inbox-table .inbox-row:active > td { background: rgba(75, 8, 24, 0.06); }
            }
        </style>
        <script>
            (function () {
                var table = document.getElementById('inboxTable');
                if (!table || !table.tBodies[0]) return;
                var body = table.tBodies[0];
                function go(row) { if (row.dataset.href) window.location.href = row.dataset.href; }
                body.addEventListener('click', function (e) {
                    var row = e.target.closest('tr.inbox-row');
                    if (!row || e.target.closest('a, button, input, select, label')) return;
                    if (window.getSelection && String(window.getSelection())) return;
                    go(row);
                });
                body.addEventListener('keydown', function (e) {
                    var row = e.target.closest('tr.inbox-row');
                    if (row && e.target === row && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); go(row); }
                });
                var heads = table.tHead.rows[0].cells;
                Array.prototype.forEach.call(heads, function (th, col) {
                    if (!th.dataset.sort) return;
                    th.querySelector('.inbox-sort').addEventListener('click', function () {
                        var dir = th.getAttribute('aria-sort') === 'ascending' ? 'descending' : 'ascending';
                        Array.prototype.forEach.call(heads, function (h) { h.setAttribute('aria-sort', 'none'); });
                        th.setAttribute('aria-sort', dir);
                        var rows = Array.prototype.slice.call(body.querySelectorAll('tr.inbox-row'));
                        function val(r) {
                            var c = r.cells[col];
                            var v = c.dataset.value !== undefined ? c.dataset.value : c.textContent.trim();
                            return th.dataset.sort === 'num' ? (v === '' ? -Infinity : parseFloat(v)) : v.toLowerCase();
                        }
                        rows.sort(function (a, b) {
                            var x = val(a), y = val(b);
                            var r = x < y ? -1 : (x > y ? 1 : 0);
                            return dir === 'ascending' ? r : -r;
                        });
                        rows.forEach(function (r) { body.appendChild(r); });
                    });
                });
            })();
        </script>
    </section>
    <p class="small text-muted mt-2">Showing up to 150 of the most recent items. “New” means placed since you last opened that area (or pressed Mark all as read).</p>
</div>
<?php require __DIR__ . '/footer.php'; ?>
