<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/member_matches.php';
require_once __DIR__ . '/../admin/lib/match_tickets.php';
require_once __DIR__ . '/../admin/lib/news.php';
require_once __DIR__ . '/../admin/lib/squad_public.php';
ensureMatchTicketSchema($pdo);
require_once __DIR__ . '/../admin/lib/events_orders.php';
$myEventOrders = events_orders_for_member($pdo, $currentHolder);

$myOrders = getSeasonTicketOrders($pdo, ['holder_id' => (int) $currentHolder['id']]);
$matchTicketOrders = getMatchTicketOrdersForMember($pdo, $currentHolder);
$activeOrders = array_values(array_filter($myOrders, static fn(array $order): bool => (string) $order['status'] !== 'cancelled'));
$currentSeason = getCurrentSeason($pdo);
$ticketSponsors = $currentSeason ? getSeasonTicketSponsors($pdo, (int) $currentSeason['id']) : [];
$fixtures = $currentSeason ? member_matches_for_season($pdo, (int) $currentSeason['id']) : [];
$today = date('Y-m-d');
$upcomingFixtures = array_values(array_filter($fixtures, static fn(array $fixture): bool => (string) ($fixture['match_date'] ?? '') >= $today));
usort($upcomingFixtures, static fn(array $a, array $b): int => strcmp((string) $a['match_date'], (string) $b['match_date']));
$nextFixture = $upcomingFixtures[0] ?? null;
$latestNews = news_published($pdo, ['limit' => 3]);
$leadStory = $latestNews[0] ?? null;
$insideStories = array_slice($latestNews, 1, 2);
$squadPlayers = squad_public_players($pdo);
usort($squadPlayers, static fn(array $a, array $b): int => ((string) $b['avatar'] !== '') <=> ((string) $a['avatar'] !== ''));
$squadSpotlight = array_slice($squadPlayers, 0, 3);
$activeSeasonTickets = array_values(array_filter($activeOrders, static fn(array $order): bool => (int) ($order['paid'] ?? 0) === 1 && (string) ($order['status'] ?? '') === 'complete'));
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
            <p>The latest from the team, inside the dressing room and around Campbell Park.</p>
            <?php if ($activeSeasonTickets): ?>
                <div class="member-hero__actions"><a class="btn btn-light" href="ticket.php">Open my ticket</a></div>
            <?php endif; ?>
        </div>
    </section>

    <div class="member-grid member-grid--aside member-home-layout">
        <main class="member-home-feed">
            <?php if ($leadStory): ?>
                <article class="member-news-lead">
                    <a class="member-news-lead__media" href="/news/<?= h(rawurlencode((string) $leadStory['slug'])) ?>">
                        <?php if ((string) $leadStory['hero_image_path'] !== ''): ?><img src="<?= h(news_image_url((string) $leadStory['hero_image_path'])) ?>" alt=""<?= ($vars = news_hero_vars($leadStory)) !== '' ? ' style="' . h($vars) . '"' : '' ?>><?php endif; ?>
                        <span><?= h((string) $leadStory['category']) ?></span>
                    </a>
                    <div class="member-news-lead__copy">
                        <div class="member-home-kicker">Latest</div>
                        <h2><a href="/news/<?= h(rawurlencode((string) $leadStory['slug'])) ?>"><?= h((string) $leadStory['title']) ?></a></h2>
                        <?php if ((string) $leadStory['excerpt'] !== ''): ?><p><?= h((string) $leadStory['excerpt']) ?></p><?php endif; ?>
                        <a class="member-home-link" href="/news/<?= h(rawurlencode((string) $leadStory['slug'])) ?>">Read the story <span aria-hidden="true">→</span></a>
                    </div>
                </article>
            <?php endif; ?>

            <?php if ($insideStories): ?>
                <section class="member-card member-home-section">
                    <div class="member-card__header"><h2>Inside the team</h2><a href="/news">All club news</a></div>
                    <div class="member-story-grid">
                        <?php foreach ($insideStories as $story): ?>
                            <article class="member-story-card">
                                <a class="member-story-card__media" href="/news/<?= h(rawurlencode((string) $story['slug'])) ?>">
                                    <?php if ((string) $story['hero_image_path'] !== ''): ?><img src="<?= h(news_image_url((string) $story['hero_image_path'])) ?>" alt="" loading="lazy"<?= ($vars = news_hero_vars($story)) !== '' ? ' style="' . h($vars) . '"' : '' ?>><?php endif; ?>
                                </a>
                                <div class="member-story-card__copy"><div class="member-home-kicker"><?= h((string) $story['category']) ?></div><h3><a href="/news/<?= h(rawurlencode((string) $story['slug'])) ?>"><?= h((string) $story['title']) ?></a></h3><?php if ((string) $story['excerpt'] !== ''): ?><p><?= h((string) $story['excerpt']) ?></p><?php endif; ?></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($squadSpotlight): ?>
                <section class="member-card member-home-section">
                    <div class="member-card__header"><h2>From the squad</h2><a href="/players">Meet every player</a></div>
                    <div class="member-squad-strip">
                        <?php foreach ($squadSpotlight as $player): ?>
                            <a class="member-squad-player" href="/players/<?= h(rawurlencode((string) $player['slug'])) ?>">
                                <span class="member-squad-player__photo"><?php if ((string) $player['avatar'] !== ''): ?><img src="/uploads/players/<?= h(rawurlencode((string) $player['avatar'])) ?>" alt="" loading="lazy"><?php else: ?><?= h(member_initials((string) $player['name'])) ?><?php endif; ?></span>
                                <span><strong><?= h((string) $player['name']) ?></strong><small><?= h((string) $player['position_label']) ?></small></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </main>

        <aside class="member-grid member-home-sidebar">
            <?php if ($nextFixture): ?>
                <section class="member-card member-next-card">
                    <div class="member-home-kicker">Next match</div>
                    <h2><?= (int) $nextFixture['is_home'] === 1 ? 'Saltcoats Victoria vs ' . h((string) $nextFixture['opponent']) : h((string) $nextFixture['opponent']) . ' vs Saltcoats Victoria' ?></h2>
                    <p><?= h(member_format_date((string) $nextFixture['match_date'])) ?><?= !empty($nextFixture['kickoff_time']) ? ' · ' . h(member_format_time((string) $nextFixture['kickoff_time'])) : '' ?><?= !empty($nextFixture['competition']) ? '<br>' . h((string) $nextFixture['competition']) : '' ?></p>
                    <a href="match.php?id=<?= (int) $nextFixture['id'] ?>">Match centre <span aria-hidden="true">→</span></a>
                </section>
            <?php endif; ?>

            <section class="member-card member-essentials">
                <div class="member-card__header"><h2>Your essentials</h2></div>
                <nav aria-label="Your account shortcuts">
                    <a href="ticket.php"><span>Season ticket</span><strong><?= $activeSeasonTickets ? 'View' : 'Details' ?> →</strong></a>
                    <a href="orders.php"><span>Match tickets<?= $matchTicketOrders ? ' (' . count($matchTicketOrders) . ')' : '' ?></span><strong>Orders →</strong></a>
                    <a href="events.php"><span>Club events<?= $myEventOrders ? ' (' . count($myEventOrders) . ')' : '' ?></span><strong>What's on →</strong></a>
                    <a href="profile.php"><span>My profile</span><strong>Manage →</strong></a>
                </nav>
            </section>

            <?php if ($ticketSponsors): ?>
                <section class="member-card"><div class="member-card__body text-center"><div class="small text-muted mb-2">Season tickets proudly sponsored by</div><?php foreach ($ticketSponsors as $sponsor): ?><img class="member-ticket-sponsor-logo" src="/uploads/sponsors/<?= rawurlencode((string) $sponsor['logo_path']) ?>" alt="<?= h((string) $sponsor['name']) ?>"><?php endforeach; ?></div></section>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
