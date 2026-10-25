<?php
declare(strict_types=1);

/**
 * Optional guided tours. Steps point at the menu (present on every page), so a
 * tour can start from anywhere. The browser drops any step whose element is
 * not visible, so a tour never breaks when someone lacks access to a link.
 * `caps` empty = everyone; otherwise one capability is enough.
 * `expand` = a menu section to open before highlighting its links.
 */

require_once __DIR__ . '/help.php';

const HELP_PROMPT_SNOOZE_DAYS = 7;
const HELP_STATE_STATUSES = ['completed', 'skipped', 'later', 'off'];

function help_nav_step(string $href, string $title, string $text, string $expand): array
{
    return ['element' => '#navbarMain a[href="' . $href . '"]', 'title' => $title, 'text' => $text, 'expand' => $expand, 'nav' => true, 'href' => $href];
}

function help_tour_definitions(): array
{
    return [
        'welcome' => [
            'title' => 'Welcome to the Hub',
            'summary' => 'A 2-minute look at the menu, the season selector and where to get help.',
            'caps' => [],
            'steps' => [
                ['title' => 'Welcome to the Hub', 'text' => 'This short tour shows you around. You can stop at any time, and restart it later from the Help Centre.'],
                ['element' => '#hubSideNavigation', 'title' => 'The menu', 'text' => 'Everything is reached from here. It only shows the areas your login has access to.', 'nav' => true],
                ['element' => '#hubSeasonSelect', 'title' => 'Working season', 'text' => 'Switch season here. Most lists and figures follow the season you pick.', 'nav' => true],
                ['element' => '#navbarMain .hub-wp-menu .wp-top', 'title' => 'Menu items', 'text' => 'Click an item to open it. The page you are on opens its own sub-pages underneath; hover over any other item to see its sub-pages.', 'nav' => true],
                ['element' => '.hub-mobile-nav', 'title' => 'Bottom bar (phones)', 'text' => 'On a phone, your most-used areas are here. Tap More for the full menu.'],
                ['element' => '.hub-help-fab', 'title' => 'Help for this page', 'text' => 'Tap this on any page for help about that page.'],
                ['element' => '#navbarMain .nav-account a[href="/admin/help.php"]', 'title' => 'Help Centre', 'text' => 'All the guides live here, plus the tours for each area.', 'nav' => true],
                ['element' => '#navbarMain .nav-account a[href="/admin/profile.php"]', 'title' => 'Your profile', 'text' => 'Update your details and password here.', 'nav' => true],
            ],
        ],
        'matchday' => [
            'title' => 'Match day',
            'summary' => 'Fixtures, results, stats, the squad and gate tools.',
            'caps' => ['matchday'],
            'steps' => [
                help_nav_step('/admin/matches.php', 'Fixtures & results', 'Every match for the selected season. Open one to manage its line-ups, events and result.', '#hubMatchdayNavigation'),
                help_nav_step('/admin/stats.php', 'Stats & tables', 'Season statistics. The tabs along the top of the page switch to the league table and league history.', '#hubMatchdayNavigation'),
                help_nav_step('/admin/players.php', 'Players', 'The squad list. Open a player to see or edit their details.', '#hubMatchdayNavigation'),
                help_nav_step('/admin/scan_overview.php', 'Scan & POS', 'Ticket scanning and the till, with tabs to switch between them.', '#hubMatchdayNavigation'),
            ],
        ],
        'sponsorship' => [
            'title' => 'Sponsorship',
            'summary' => 'Sponsors, agreements, orders and packages.',
            'caps' => ['sponsorship', 'finance_view'],
            'steps' => [
                help_nav_step('/admin/sponsors.php', 'Sponsors', 'Each sponsor and their details. The Follow-ups tab shows who needs a call or a reply.', '#hubSponsorshipNavigation'),
                help_nav_step('/admin/sponsorship_agreements.php', 'Agreements', 'What each sponsor has agreed to. Tabs cover season deals, bundles and board renewals.', '#hubSponsorshipNavigation'),
                help_nav_step('/admin/sponsor_orders.php', 'Sponsor orders', 'Everything bought through the members area and the public sponsor shop.', '#hubSponsorshipNavigation'),
                help_nav_step('/admin/sponsorship_packages.php', 'Packages', 'What sponsors can buy.', '#hubSponsorshipNavigation'),
            ],
        ],
        'shop' => [
            'title' => 'Shop',
            'summary' => 'Orders, products and shop setup.',
            'caps' => ['shop'],
            'steps' => [
                help_nav_step('/admin/shop_orders.php', 'Orders', 'What customers have bought. Update an order\'s status here.', '#hubShopNavigation'),
                help_nav_step('/admin/shop_products.php', 'Products', 'What is on sale. Tabs cover categories and option groups.', '#hubShopNavigation'),
                help_nav_step('/admin/shop_discount_codes.php', 'Shop setup', 'Discount codes, shipping zones, settings and emails.', '#hubShopNavigation'),
            ],
        ],
        'ticketing' => [
            'title' => 'Ticketing',
            'summary' => 'Season tickets, match tickets and hospitality.',
            'caps' => ['tickets_ops'],
            'steps' => [
                help_nav_step('/admin/season_ticket_orders.php', 'Season tickets', 'Season pass orders. Tabs cover renewals, free signup codes and types & pricing.', '#hubTicketingNavigation'),
                help_nav_step('/admin/ticket_orders.php', 'Match tickets', 'Single-match ticket orders. Tabs cover fixture setup and ticket packages.', '#hubTicketingNavigation'),
                help_nav_step('/admin/hospitality.php', 'Hospitality', 'Hospitality bookings, prices and capacity.', '#hubTicketingNavigation'),
            ],
        ],
        'secretary' => [
            'title' => 'Secretary',
            'summary' => 'Dashboard, tasks, letters, meetings, registrations and discipline.',
            'caps' => ['secretary_ops'],
            'steps' => [
                help_nav_step('/admin/secretary_dashboard.php', 'Dashboard', 'Deadlines and open items. The Emergency guide tab answers "What do I do if...?" for common match-day problems.', '#hubSecretaryNavigation'),
                help_nav_step('/admin/secretary_tasks.php', 'Tasks & planner', 'What needs doing, and when.', '#hubSecretaryNavigation'),
                help_nav_step('/admin/secretary_correspondence.php', 'Letters & documents', 'Correspondence and the document store.', '#hubSecretaryNavigation'),
                help_nav_step('/admin/committee_meetings.php', 'Committee & AGM', 'Meeting records.', '#hubSecretaryNavigation'),
                help_nav_step('/admin/player_registrations.php', 'Registrations & discipline', 'Official registration and discipline records, plus fixture change requests.', '#hubSecretaryNavigation'),
            ],
        ],
        'website' => [
            'title' => 'Website',
            'summary' => 'News and club pages.',
            'caps' => ['website', 'content_social'],
            'steps' => [
                help_nav_step('/admin/news.php', 'News', 'Write and publish articles for the public website.', '#hubWebsiteNavigation'),
                help_nav_step('/admin/club_pages.php', 'Club pages', 'The fixed pages on the website.', '#hubWebsiteNavigation'),
            ],
        ],
    ];
}

function help_tours_for_user(): array
{
    $tours = [];
    foreach (help_tour_definitions() as $key => $tour) {
        if ($tour['caps'] !== [] && !hub_auth_has_any_capability($tour['caps'])) {
            continue;
        }
        // Drop steps for menu items that are switched off; hide a tour whose menu steps are all gone.
        $hadMenuSteps = false;
        $steps = [];
        foreach ($tour['steps'] as $step) {
            if (isset($step['href'])) {
                $hadMenuSteps = true;
                $on = false;
                foreach (help_nav_items() as $i) {
                    if ($i['href'] === $step['href'] && hub_navigation_item_visible($i['key'])) {
                        $on = true;
                    }
                }
                if (!$on) {
                    // Keep the card summary honest: drop the hidden item from "Sponsors, follow-ups, agreements...".
                    $tour['summary'] = preg_replace('/\b' . preg_quote(strtolower((string) $step['title']), '/') . ',?\s*/i', '', (string) $tour['summary']);
                    continue;
                }
            }
            $steps[] = $step;
        }
        if ($hadMenuSteps && !array_filter($steps, static fn ($s) => isset($s['href']))) {
            continue;
        }
        $tour['steps'] = $steps;
        $tours[$key] = $tour;
    }
    return $tours;
}

function help_account_id(): int
{
    return (int) ($_SESSION['hub_account_id'] ?? 0);
}

/** @return array<string, array{status:string,updated_at:string}> keyed by state_key */
function help_state_all(PDO $pdo): array
{
    $id = help_account_id();
    if ($id <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT state_key, status, updated_at FROM user_help_state WHERE account_id = :a');
    $stmt->execute([':a' => $id]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['state_key']] = ['status' => $r['status'], 'updated_at' => $r['updated_at']];
    }
    return $out;
}

/** Show the first-login prompt? Not if switched off, finished, or postponed recently. */
function help_should_prompt(array $state): bool
{
    if (($state['welcome']['status'] ?? '') === 'completed') {
        return false;
    }
    $prompt = $state['prompt'] ?? null;
    if ($prompt === null) {
        return true;
    }
    if ($prompt['status'] === 'later') {
        return strtotime($prompt['updated_at']) < time() - HELP_PROMPT_SNOOZE_DAYS * 86400;
    }
    return false;
}
