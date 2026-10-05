<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/member_sponsorship.php';
require_once __DIR__ . '/../admin/lib/member_matches.php';
require_once __DIR__ . '/../admin/lib/match_tickets.php';
ensureMatchTicketSchema($pdo);

$myOrders = getSeasonTicketOrders($pdo, ['holder_id' => (int) $currentHolder['id']]);
$matchTicketOrders = getMatchTicketOrdersForMember($pdo, $currentHolder);
$activeOrders = array_values(array_filter($myOrders, static fn(array $order): bool => (string) $order['status'] !== 'cancelled'));
$currentPersonId = member_auth_current_person_id() ?? 0;
$dependents = $currentPersonId > 0 ? getPersonDependents($pdo, $currentPersonId) : [];
$statusOptions = seasonTicketOrderStatusOptions();
$sponsorships = member_sponsorship_overview($pdo, $currentHolder);
$hasAnySponsorship = $sponsorships['current'] || $sponsorships['upcoming'] || $sponsorships['previous'];
$currentSeason = getCurrentSeason($pdo);
$ticketSponsors = $currentSeason ? getSeasonTicketSponsors($pdo, (int) $currentSeason['id']) : [];
$fixtures = $currentSeason ? member_matches_for_season($pdo, (int) $currentSeason['id']) : [];
$today = date('Y-m-d');
$upcomingFixtures = array_values(array_filter($fixtures, static fn(array $fixture): bool => (string) ($fixture['match_date'] ?? '') >= $today));
usort($upcomingFixtures, static fn(array $a, array $b): int => strcmp((string) $a['match_date'], (string) $b['match_date']));
$nextFixture = $upcomingFixtures[0] ?? null;

function member_sponsorship_summary_line(array $agreement): string
{
    $label = (string) $agreement['package_name'];
    if ((string) $agreement['package_scope'] === 'player' && !empty($agreement['player_name'])) {
        return $label . ' - ' . (string) $agreement['player_name'];
    }
    if ((string) $agreement['package_scope'] === 'match' && !empty($agreement['fixture_opponent'])) {
        return $label . ' - vs ' . (string) $agreement['fixture_opponent'];
    }
    return $label;
}
?>

<div class="member-page">
    <?php if (isset($_GET['order_placed'])): ?>
        <div class="alert alert-success">Thanks. Your order is placed and your member account is ready.</div>
    <?php endif; ?>
    <?php if (isset($_GET['welcome'])): ?>
        <div class="alert alert-success">Welcome! Your account is ready — buy a season ticket any time to unlock the league table, announcements and more.</div>
    <?php endif; ?>
    <?php if (isset($_GET['hub_login'])): ?>
        <div class="alert alert-info">You're signed in to your supporter account. Club staff sign in to the Hub at <a href="/admin/login.php">the staff login</a>.</div>
    <?php endif; ?>

    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">Members Home</div>
            <h1>Welcome back, <?= h((string) $currentHolder['name']) ?></h1>
            <p>Your season ticket, match information and sponsorship options in one place.</p>
        </div>
    </section>

    <div class="member-grid member-grid--aside">
        <div class="member-grid">
            <section class="member-card">
                <div class="member-card__header">
                    <h2>Your Season Ticket<?= count($activeOrders) === 1 ? '' : 's' ?></h2>
                    <a href="ticket.php" class="btn btn-sm btn-outline-secondary">View ticket</a>
                </div>
                <div class="member-card__body">
                    <?php if (!$myOrders): ?>
                        <p class="text-muted mb-0">You do not have a season ticket order on file yet. <a href="/season-tickets">Buy one here</a>.</p>
                    <?php else: ?>
                        <div class="member-list">
                            <?php foreach ($myOrders as $order): ?>
                                <div class="member-row">
                                    <div>
                                        <div class="member-row__title"><?= h((string) $order['type_name']) ?> Season Ticket</div>
                                        <div class="member-row__meta"><?= h((string) $order['season_name']) ?> · <?= gbp((float) $order['price']) ?></div>
                                    </div>
                                    <span class="badge hub-status <?= $order['status'] === 'complete' ? 'text-bg-success' : ($order['status'] === 'cancelled' ? 'text-bg-secondary' : 'text-bg-warning') ?>"><?= h($statusOptions[$order['status']] ?? (string) $order['status']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="member-card">
                <div class="member-card__header">
                    <h2>Match Tickets</h2>
                    <a href="orders.php" class="btn btn-sm btn-outline-secondary">View orders</a>
                </div>
                <div class="member-card__body">
                    <?php if (!$matchTicketOrders): ?>
                        <p class="text-muted mb-0">Match tickets bought with this account will appear here. <a href="/tickets">Buy tickets</a>.</p>
                    <?php else: ?>
                        <div class="member-list">
                            <?php foreach (array_slice($matchTicketOrders, 0, 3) as $order): ?>
                                <?php $ready = (int) $order['paid'] === 1 && (string) $order['status'] === 'complete'; ?>
                                <div class="member-row">
                                    <div>
                                        <div class="member-row__title">vs <?= h((string) $order['opponent']) ?></div>
                                        <div class="member-row__meta"><?= h(member_format_date((string) $order['match_date'])) ?> · <?= (int) $order['ticket_count'] ?> ticket<?= (int) $order['ticket_count'] === 1 ? '' : 's' ?></div>
                                    </div>
                                    <div class="member-row__actions">
                                        <span class="badge <?= $ready ? 'text-bg-success' : 'text-bg-warning' ?>"><?= $ready ? 'Ready' : 'Pending' ?></span>
                                        <?php if ($ready): ?><a class="btn btn-sm btn-brand" href="<?= h(match_ticket_public_order_url($order)) ?>" target="_blank" rel="noopener">Open</a><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="member-card">
                <div class="member-card__header">
                    <h2>Next Match</h2>
                    <a href="matches.php" class="btn btn-sm btn-outline-secondary">All matches</a>
                </div>
                <div class="member-card__body">
                    <?php if (!$nextFixture): ?>
                        <p class="text-muted mb-0">No upcoming fixture is currently published.</p>
                    <?php else: ?>
                        <a class="member-row" href="match.php?id=<?= (int) $nextFixture['id'] ?>">
                            <div>
                                <div class="member-row__title"><?= (int) $nextFixture['is_home'] === 1 ? 'Saltcoats Victoria vs ' . h((string) $nextFixture['opponent']) : h((string) $nextFixture['opponent']) . ' vs Saltcoats Victoria' ?></div>
                                <div class="member-row__meta"><?= h(member_format_date((string) $nextFixture['match_date'])) ?><?= !empty($nextFixture['kickoff_time']) ? ' · ' . h(member_format_time((string) $nextFixture['kickoff_time'])) : '' ?><?= !empty($nextFixture['competition']) ? ' · ' . h((string) $nextFixture['competition']) : '' ?></div>
                            </div>
                            <span class="member-badge"><?= (int) $nextFixture['is_home'] === 1 ? 'Home' : 'Away' ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <aside class="member-grid">
            <section class="member-card">
                <div class="member-card__body d-flex align-items-center gap-3">
                    <div class="member-avatar">
                        <?php if (!empty($currentHolder['profile_image_path'])): ?><img src="/<?= h((string) $currentHolder['profile_image_path']) ?>" alt=""><?php else: ?><?= h(member_initials((string) $currentHolder['name'])) ?><?php endif; ?>
                    </div>
                    <div>
                        <h2><?= h((string) $currentHolder['name']) ?></h2>
                        <div class="text-muted small"><?= h((string) ($currentHolder['email'] ?? '')) ?></div>
                        <?php if (!empty($currentHolder['date_of_birth'])): ?><div class="text-muted small">DOB: <?= h(member_format_date((string) $currentHolder['date_of_birth'])) ?></div><?php endif; ?>
                    </div>
                </div>
            </section>

            <?php if ($ticketSponsors): ?>
            <section class="member-card">
                <div class="member-card__body text-center">
                    <div class="small text-muted mb-2">Season tickets proudly sponsored by</div>
                    <?php foreach ($ticketSponsors as $sponsor): ?>
                        <img src="/uploads/sponsors/<?= rawurlencode((string) $sponsor['logo_path']) ?>" alt="<?= h((string) $sponsor['name']) ?>" style="height:46px;max-width:180px;object-fit:contain;margin:4px;">
                    <?php endforeach; ?>
                </div>
            </section>
            <?php endif; ?>

            <section class="member-card">
                <div class="member-card__header">
                    <h2>Sponsorship</h2>
                    <a href="sponsor.php" class="btn btn-sm btn-outline-secondary">Browse</a>
                </div>
                <div class="member-card__body">
                    <?php if (!$hasAnySponsorship): ?>
                        <p class="text-muted mb-0">Support the Vics through player, match or pitchside sponsorship.</p>
                    <?php else: ?>
                        <div class="member-list">
                            <?php foreach (array_slice(array_merge($sponsorships['current'], $sponsorships['upcoming']), 0, 3) as $agreement): ?>
                                <div>
                                    <div class="fw-semibold"><?= h(member_sponsorship_summary_line($agreement)) ?></div>
                                    <div class="small text-muted"><?= gbp((float) $agreement['agreed_amount']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
