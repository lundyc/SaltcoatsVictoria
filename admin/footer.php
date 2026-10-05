<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/navigation_settings.php';
?>
    </div>
</main>

<?php
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$showMobileNav = !in_array($currentScript, ['login.php', 'forgot_password.php', 'reset_password.php'], true);

/*
 * The 3 shortcut slots (Overview is a fixed 4th) are filled from whichever of
 * these the signed-in user actually has capabilities for, in priority order —
 * previously these were hard-coded to Match day/Players/Sponsors, so anyone
 * working shop, ticketing, finance or secretary duties on a phone had no
 * shortcut at all and had to open "More" to reach the full desktop menu every
 * time. Order here mirrors how often each area is used day-to-day.
 */
$mobileNavCandidates = [
    ['caps' => ['matchday', 'tickets_ops', 'finance_view'], 'pages' => ['matches.php', 'match.php', 'match_starting_11.php', 'match_events.php'], 'feature' => 'matchday.matches', 'href' => '/admin/matches.php', 'icon' => 'fa-futbol', 'label' => 'Match day'],
    ['caps' => ['matchday'], 'pages' => ['players.php', 'player_view.php', 'player_edit.php', 'player_add.php'], 'feature' => 'matchday.players', 'href' => '/admin/players.php', 'icon' => 'fa-users', 'label' => 'Players'],
    ['caps' => ['finance_view'], 'pages' => ['sponsors.php', 'sponsor.php', 'sponsorship_agreements.php', 'sponsorship_agreement.php'], 'feature' => 'sponsorship.sponsors', 'href' => '/admin/sponsors.php', 'icon' => 'fa-handshake', 'label' => 'Sponsors'],
    ['caps' => ['shop'], 'pages' => ['shop_overview.php', 'shop_orders.php', 'shop_order.php', 'shop_products.php'], 'feature' => 'shop.shop_overview', 'href' => '/admin/shop_overview.php', 'icon' => 'fa-bag-shopping', 'label' => 'Shop'],
    ['caps' => ['tickets_ops'], 'pages' => ['fixture_tickets.php', 'ticket_orders.php', 'season_ticket_orders.php'], 'feature' => 'ticketing.fixture_tickets', 'href' => '/admin/fixture_tickets.php', 'icon' => 'fa-cash-register', 'label' => 'Tickets'],
    ['caps' => ['finance_view', 'matchday'], 'pages' => ['reports.php', 'matchday_finance.php', 'matchday_finance_edit.php'], 'feature' => 'finance.reports', 'href' => '/admin/reports.php', 'icon' => 'fa-sterling-sign', 'label' => 'Finance'],
    ['caps' => ['secretary_ops'], 'pages' => ['secretary_dashboard.php', 'discipline_register.php', 'secretary_tasks.php'], 'feature' => 'secretary.secretary_dashboard', 'href' => '/admin/secretary_dashboard.php', 'icon' => 'fa-user-tie', 'label' => 'Secretary'],
    ['caps' => ['website'], 'pages' => ['news.php', 'news_edit.php', 'club_pages.php'], 'feature' => 'website.news', 'href' => '/admin/news.php', 'icon' => 'fa-newspaper', 'label' => 'Website'],
];
$mobileNavShortcuts = [];
$mobileNavPages = ['index.php'];
foreach ($mobileNavCandidates as $candidate) {
    if (count($mobileNavShortcuts) >= 3) {
        break;
    }
    if (!hub_navigation_item_available($candidate['feature']) || !hub_auth_has_any_capability($candidate['caps'])) {
        continue;
    }
    $mobileNavShortcuts[] = $candidate;
    $mobileNavPages = array_merge($mobileNavPages, $candidate['pages']);
}
$mobileMoreActive = !in_array($currentScript, $mobileNavPages, true);
if ($showMobileNav):
?>
<nav class="hub-mobile-nav d-lg-none" aria-label="Mobile hub navigation">
    <?php if (hub_navigation_item_visible('overview.index')): ?>
    <a class="hub-mobile-nav__item <?= $currentScript === 'index.php' ? 'active' : '' ?>" href="/admin/index.php" <?= $currentScript === 'index.php' ? 'aria-current="page"' : '' ?>>
        <i class="fa-solid fa-house" aria-hidden="true"></i>
        <span>Overview</span>
    </a>
    <?php endif; ?>
    <?php foreach ($mobileNavShortcuts as $shortcut): ?>
    <a class="hub-mobile-nav__item <?= in_array($currentScript, $shortcut['pages'], true) ? 'active' : '' ?>" href="<?= htmlspecialchars($shortcut['href'], ENT_QUOTES, 'UTF-8') ?>" <?= in_array($currentScript, $shortcut['pages'], true) ? 'aria-current="page"' : '' ?>>
        <i class="fa-solid <?= htmlspecialchars($shortcut['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i>
        <span><?= htmlspecialchars($shortcut['label'], ENT_QUOTES, 'UTF-8') ?></span>
    </a>
    <?php endforeach; ?>
    <button class="hub-mobile-nav__item <?= $mobileMoreActive ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain" aria-controls="navbarMain" aria-expanded="false" aria-label="Open all Hub navigation">
        <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
        <span>More</span>
    </button>
</nav>

<div class="modal fade" id="hubConfirmDialog" tabindex="-1" aria-labelledby="hubConfirmDialogTitle" aria-describedby="hubConfirmDialogMessage" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content hub-confirm-dialog">
            <div class="modal-header">
                <div class="hub-confirm-dialog__heading">
                    <span class="hub-confirm-dialog__icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>
                    <div><div class="small text-uppercase fw-bold text-muted">Please confirm</div><h2 class="modal-title h5 mb-0" id="hubConfirmDialogTitle">Confirm this action</h2></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cancel and close"></button>
            </div>
            <div class="modal-body"><p class="mb-0" id="hubConfirmDialogMessage">This action cannot be undone.</p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="hubConfirmDialogAction">Continue</button>
            </div>
        </div>
    </div>
</div>

<div class="hub-toast-region" aria-live="polite" aria-atomic="true">
    <span class="visually-hidden" id="hubLiveStatus"></span>
</div>
<?php endif; ?>

<?php
$hubAnalyticsScript = __DIR__ . '/assets/js/hub_analytics.js';
$hubAnalyticsDisabledPages = ['login.php', 'forgot_password.php', 'reset_password.php', 'analytics_track.php'];
if (
    $showMobileNav
    && function_exists('hub_auth_is_authenticated')
    && hub_auth_is_authenticated()
    && !in_array($currentScript, $hubAnalyticsDisabledPages, true)
    && is_file($hubAnalyticsScript)
):
?>
<script>
    window.hubAnalyticsConfig = {
        endpoint: "/admin/analytics_track.php"
    };
</script>
<script src="/admin/assets/js/hub_analytics.js?v=<?= (int)(@filemtime($hubAnalyticsScript) ?: time()) ?>" defer></script>
<?php endif; ?>

<?php ob_end_flush(); ?>
</body>

</html>
