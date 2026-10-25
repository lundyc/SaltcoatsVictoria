<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Sponsorship management',
    'title' => 'Sponsor Orders',
    'subtitle' => 'Everything bought through the members area and the public Sponsor a Player shop, newest first.',
    'actions' => [
        ['label' => 'Player shop orders', 'href' => '/playersponsors_orders.php', 'class' => 'btn btn-outline-light btn-sm'],
        ['label' => 'All agreements', 'href' => '/sponsorship_agreements.php', 'class' => 'btn btn-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/player_sponsorship_shop.php';
require_once __DIR__ . '/lib/match_sponsorship_shop.php';
ensureSponsorshipCatalogSchema($pdo);
match_sponsorship_ensure_schema($pdo);
ensurePlayerSponsorshipShopSchema($pdo);

// header.php has just marked this section as read; $hubOrderPrevSeen holds the time it was last read before today's visit.
$since = (string) ($hubOrderPrevSeen['sponsors'] ?? '1970-01-01 00:00:00');
$orders = [];

// 1) Members area purchases: one order per basket (agreements share their Stripe session in the notes).
$stmt = $pdo->query("SELECT a.id, a.sponsor_id, a.agreed_amount, a.status, a.notes, a.created_at, a.player_id, a.fixture_id,
        s.name AS sponsor_name, s.contact_email AS sponsor_email, p.name AS package_name,
        pl.name AS player_name, f.opponent, f.match_date, f.is_home
    FROM sponsorship_agreements a
    JOIN sponsors s ON s.id = a.sponsor_id
    JOIN packages p ON p.id = a.package_id
    LEFT JOIN players pl ON pl.id = a.player_id
    LEFT JOIN match_fixtures f ON f.id = a.fixture_id
    WHERE a.notes LIKE 'self_serve:%'
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT 400");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = 'm|' . $row['sponsor_id'] . '|' . $row['notes'];
    if (!isset($orders[$key])) {
        $method = str_contains((string) $row['notes'], 'stripe') ? 'Card (Stripe)' : ucfirst(str_replace(['self_serve:', '_'], ['', ' '], explode(' ', (string) $row['notes'])[0]));
        $orders[$key] = ['source' => 'Members area', 'when' => (string) $row['created_at'], 'who' => (string) $row['sponsor_name'], 'email' => (string) ($row['sponsor_email'] ?? ''),
            'method' => $method, 'total' => 0.0, 'items' => [], 'status' => 'Confirmed', 'link' => '/admin/sponsorship_agreement.php?id=' . (int) $row['id'], 'flag' => false];
    }
    $detail = (string) $row['package_name'];
    if (!empty($row['player_name'])) {
        $detail .= ' — ' . $row['player_name'];
    } elseif (!empty($row['opponent'])) {
        $detail .= ' — ' . ((int) $row['is_home'] === 1 ? 'Home v ' : 'Away at ') . $row['opponent'] . ' (' . date('D, j M Y', strtotime((string) $row['match_date'])) . ')';
    }
    $orders[$key]['items'][] = $detail;
    $orders[$key]['total'] += (float) $row['agreed_amount'];
    if ((string) $row['status'] === 'cancelled') {
        $orders[$key]['status'] = 'Cancelled';
    }
}

// 2) Public Sponsor a Player shop orders.
$shopStmt = $pdo->query("SELECT * FROM player_sponsorship_orders WHERE status NOT IN ('pending_payment','enquiry','declined') ORDER BY COALESCE(paid_at, created_at) DESC LIMIT 100");
foreach ($shopStmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
    $items = player_sponsorship_shop_get_order_items($pdo, (int) $order['id']);
    $conflict = false;
    $lines = [];
    foreach ($items as $it) {
        $label = (string) $it['player_name_snapshot'] . ' — ' . str_replace(' Sponsorship', '', (string) player_sponsorship_shop_package_label((string) $it['package_code']));
        if ((string) $it['status'] === 'conflict_refund_due') {
            $conflict = true;
            $label .= ' (double-booked, refund due)';
        }
        $lines[] = $label;
    }
    $okVia = array_filter([!empty($order['consent_email']) ? 'Email' : '', !empty($order['consent_sms']) ? 'SMS' : '', !empty($order['consent_whatsapp']) ? 'WhatsApp' : '']);
    $orders['s|' . $order['id']] = ['contact_ok' => $okVia ? implode(', ', $okVia) : 'No contact consent given', 'source' => 'Sponsor a Player shop', 'when' => (string) ($order['paid_at'] ?: $order['created_at']), 'who' => (string) $order['buyer_name'], 'email' => (string) $order['buyer_email'],
        'method' => 'Card (Stripe)', 'total' => (float) $order['total_amount'], 'items' => $lines,
        'status' => $conflict ? 'Needs attention' : 'Confirmed', 'link' => '/admin/playersponsors_orders.php', 'flag' => $conflict];
}

// 3) Public Matchday / Match Ball shop orders.
foreach ($pdo->query("SELECT * FROM match_sponsorship_orders WHERE status IN ('paid','partially_conflicted') ORDER BY COALESCE(paid_at, created_at) DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) as $order) {
    $lines = [];
    $conflict = false;
    foreach (match_sponsorship_get_items($pdo, (int) $order['id']) as $it) {
        $label = (string) $it['label'];
        if ((string) $it['status'] === 'conflict_refund_due') {
            $conflict = true;
            $label .= ' (double-booked, refund due)';
        }
        $lines[] = $label;
    }
    $okVia = array_filter([!empty($order['consent_email']) ? 'Email' : '', !empty($order['consent_sms']) ? 'SMS' : '', !empty($order['consent_whatsapp']) ? 'WhatsApp' : '']);
    $orders['g|' . $order['id']] = ['contact_ok' => $okVia ? implode(', ', $okVia) : 'No contact consent given', 'source' => 'Matchday shop', 'when' => (string) ($order['paid_at'] ?: $order['created_at']),
        'who' => (string) $order['buyer_name'], 'email' => (string) $order['buyer_email'], 'method' => 'Card (Stripe)', 'total' => (float) $order['total_amount'], 'items' => $lines,
        'status' => $conflict ? 'Needs attention' : 'Confirmed', 'link' => !empty($order['sponsor_id']) ? '/admin/sponsor.php?id=' . (int) $order['sponsor_id'] : '/admin/sponsorship_agreements.php', 'flag' => $conflict];
}

usort($orders, static fn(array $a, array $b): int => strcmp($b['when'], $a['when']));
$newCount = count(array_filter($orders, static fn(array $o): bool => $o['when'] > $since || $o['flag']));
?>
<div class="alert alert-light border small mb-3"><i class="fa-solid fa-bell me-1" aria-hidden="true"></i>
    <?= $newCount > 0 ? '<strong>' . $newCount . ' new</strong> since you last looked (highlighted below).' : 'Nothing new since you last looked.' ?>
    This page is marked as read for you when you open it; items that need attention (such as a double-booked slot) stay highlighted for everyone until they are resolved.
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>When</th><th>From</th><th>Sponsor</th><th>What was bought</th><th class="text-end">Total</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$orders): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">No sponsor orders yet. Purchases made on the members sponsorship page and the Sponsor a Player shop will appear here.</td></tr>
            <?php endif; ?>
            <?php foreach ($orders as $o): $isNew = $o['when'] > $since || $o['flag']; ?>
                <tr class="<?= $isNew ? 'table-warning' : '' ?>">
                    <td class="text-nowrap small"><?= h(date('j M Y', strtotime($o['when']))) ?><br><span class="text-muted"><?= h(date('H:i', strtotime($o['when']))) ?></span><?= $isNew ? ' <span class="badge text-bg-danger">New</span>' : '' ?></td>
                    <td class="small"><?= h($o['source']) ?><br><span class="text-muted"><?= h($o['method']) ?></span></td>
                    <td><?= h($o['who']) ?><?= $o['email'] !== '' ? '<br><span class="small text-muted">' . h($o['email']) . '</span>' : '' ?><?= isset($o['contact_ok']) ? '<br><span class="small ' . ($o['contact_ok'] === 'No contact consent given' ? 'text-muted' : 'text-success') . '"><i class="fa-regular fa-comment-dots me-1"></i>' . h($o['contact_ok']) . '</span>' : '' ?></td>
                    <td class="small"><ul class="mb-0 ps-3"><?php foreach ($o['items'] as $line): ?><li><?= h($line) ?></li><?php endforeach; ?></ul></td>
                    <td class="text-end fw-bold"><?= h(gbp((float) $o['total'])) ?></td>
                    <td><span class="badge <?= $o['status'] === 'Confirmed' ? 'text-bg-success' : ($o['status'] === 'Cancelled' ? 'text-bg-secondary' : 'text-bg-warning') ?>"><?= h($o['status']) ?></span></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= h($o['link']) ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
