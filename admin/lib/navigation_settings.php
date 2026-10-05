<?php
declare(strict_types=1);

require_once __DIR__ . '/site_settings.php';

/** Stable menu keys keep duplicate links independently configurable. */
function hub_navigation_definitions(): array
{
    return [
        'overview' => [
            'label' => 'Overview',
            'default' => true,
            'items' => [
                'developer_analytics' => [
                    'label' => 'Product analytics',
                    'href' => '/admin/developer_analytics.php',
                    'default' => true,
                    'caps' => [],
                    'developer_only' => true,
                ],
                'index' => [
                    'label' => 'Club overview',
                    'href' => '/admin/index.php',
                    'default' => true,
                    'caps' => [],
                ],
                'club_reminders' => [
                    'label' => 'Reminders',
                    'href' => '/admin/club_reminders.php',
                    'default' => false,
                    'caps' => ['finance_view', 'matchday', 'club_setup', 'tickets_ops', 'secretary_ops'],
                ],
            ],
        ],
        'matchday' => [
            'label' => 'Match Day',
            'default' => true,
            'items' => [
                'matches' => [
                    'label' => 'Fixtures & results',
                    'href' => '/admin/matches.php',
                    'default' => true,
                    'caps' => ['matchday'],
                ],
                'stats' => [
                    'label' => 'Stats',
                    'href' => '/admin/stats.php',
                    'default' => true,
                    'caps' => ['matchday'],
                ],
                'scan_overview' => [
                    'label' => 'Scan',
                    'href' => '/admin/scan_overview.php',
                    'default' => false,
                    'caps' => ['tickets_ops'],
                ],
                'pos_overview' => [
                    'label' => 'POS',
                    'href' => '/admin/pos_overview.php',
                    'default' => false,
                    'caps' => ['tickets_ops'],
                ],
                'matchday_finance' => [
                    'label' => 'Matchday income',
                    'href' => '/admin/matchday_finance.php',
                    'default' => true,
                    'caps' => ['finance_view', 'matchday'],
                ],
                'players' => [
                    'label' => 'Players',
                    'href' => '/admin/players.php',
                    'default' => true,
                    'caps' => ['matchday'],
                ],
            ],
        ],
        'sponsorship' => [
            'label' => 'Sponsorship',
            'default' => true,
            'items' => [
                'sponsors' => [
                    'label' => 'Sponsors',
                    'href' => '/admin/sponsors.php',
                    'default' => true,
                    'caps' => ['finance_view'],
                ],
                'sponsor_followups' => [
                    'label' => 'Follow-ups',
                    'href' => '/admin/sponsor_followups.php',
                    'default' => false,
                    'caps' => ['finance_view'],
                ],
                'sponsorship_agreements' => [
                    'label' => 'Agreements',
                    'href' => '/admin/sponsorship_agreements.php',
                    'default' => true,
                    'caps' => ['finance_view'],
                ],
                'sponsorship_bundles' => [
                    'label' => 'Bundles',
                    'href' => '/admin/sponsorship_bundles.php',
                    'default' => true,
                    'caps' => ['finance_view'],
                ],
                'sponsorship_packages' => [
                    'label' => 'Packages',
                    'href' => '/admin/sponsorship_packages.php',
                    'default' => true,
                    'caps' => ['finance_view'],
                ],
            ],
        ],
        'shop' => [
            'label' => 'Shop',
            'default' => true,
            'items' => [
                'shop_overview' => [
                    'label' => 'Overview',
                    'href' => '/admin/shop_overview.php',
                    'default' => true,
                    'caps' => ['shop'],
                ],
                'shop_orders' => [
                    'label' => 'Orders',
                    'href' => '/admin/shop_orders.php',
                    'default' => true,
                    'caps' => ['shop'],
                ],
                'shop_products' => [
                    'label' => 'Products',
                    'href' => '/admin/shop_products.php',
                    'default' => true,
                    'caps' => ['shop'],
                ],
                'shop_categories' => [
                    'label' => 'Categories',
                    'href' => '/admin/shop_categories.php',
                    'default' => true,
                    'caps' => ['shop'],
                ],
                'shop_modifiers' => [
                    'label' => 'Modifiers',
                    'href' => '/admin/shop_modifiers.php',
                    'default' => true,
                    'caps' => ['shop'],
                ],
                'shop_discount_codes' => [
                    'label' => 'Discount codes',
                    'href' => '/admin/shop_discount_codes.php',
                    'default' => true,
                    'caps' => ['shop'],
                ],
                'shop_settings' => [
                    'label' => 'Shop settings',
                    'href' => '/admin/shop_settings.php',
                    'default' => true,
                    'caps' => ['shop'],
                ],
                'storefront' => [
                    'label' => 'View storefront',
                    'href' => '/shop/',
                    'default' => false,
                    'caps' => ['shop'],
                ],
            ],
        ],
        'ticketing' => [
            'label' => 'Ticketing',
            'default' => true,
            'items' => [
                'season_ticket_orders' => [
                    'label' => 'Season Ticket Orders',
                    'href' => '/admin/season_ticket_orders.php',
                    'default' => true,
                    'caps' => ['tickets_ops'],
                ],
                'fixture_tickets' => [
                    'label' => 'Match Tickets',
                    'href' => '/admin/fixture_tickets.php',
                    'default' => true,
                    'caps' => ['tickets_ops'],
                ],
                'ticket_orders' => [
                    'label' => 'Ticket Orders',
                    'href' => '/admin/ticket_orders.php',
                    'default' => true,
                    'caps' => ['tickets_ops'],
                ],
                'ticket_packages' => [
                    'label' => 'Ticket Packages',
                    'href' => '/admin/ticket_packages.php',
                    'default' => true,
                    'caps' => ['tickets_ops'],
                ],
                'season_ticket_renewals' => [
                    'label' => 'Renewals',
                    'href' => '/admin/season_ticket_renewals.php',
                    'default' => true,
                    'caps' => ['tickets_ops'],
                ],
                'season_ticket_free_codes' => [
                    'label' => 'Free Signup Codes',
                    'href' => '/admin/season_ticket_free_codes.php',
                    'default' => true,
                    'caps' => ['tickets_ops'],
                ],
                'season_ticket_types' => [
                    'label' => 'Types & Pricing',
                    'href' => '/admin/season_ticket_types.php',
                    'default' => true,
                    'caps' => ['tickets_ops'],
                ],
            ],
        ],
        'supporter' => [
            'label' => 'Supporter content',
            'default' => false,
            'items' => [
                'announcements' => [
                    'label' => 'Announcements',
                    'href' => '/admin/announcements.php',
                    'default' => true,
                    'caps' => [],
                    'admin_only' => true,
                ],
                'feedback' => [
                    'label' => 'Feedback',
                    'href' => '/admin/feedback.php',
                    'default' => true,
                    'caps' => [],
                    'admin_only' => true,
                ],
                'venue_reviews' => [
                    'label' => 'Venue Reviews',
                    'href' => '/admin/venue_reviews.php',
                    'default' => true,
                    'caps' => [],
                    'admin_only' => true,
                ],
                'motm' => [
                    'label' => 'Player of the Match',
                    'href' => '/admin/motm.php',
                    'default' => true,
                    'caps' => [],
                    'admin_only' => true,
                ],
            ],
        ],
        'website' => [
            'label' => 'Public website',
            'default' => true,
            'items' => [
                'news' => [
                    'label' => 'News',
                    'href' => '/admin/news.php',
                    'default' => true,
                    'caps' => ['website'],
                ],
                'club_pages' => [
                    'label' => 'Club pages',
                    'href' => '/admin/club_pages.php',
                    'default' => true,
                    'caps' => ['website'],
                ],
                'settings_public' => [
                    'label' => 'Website settings',
                    'href' => '/admin/settings_public.php',
                    'default' => true,
                    'caps' => ['website'],
                ],
                'website' => [
                    'label' => 'View website',
                    'href' => '/',
                    'default' => true,
                    'caps' => ['website'],
                ],
            ],
        ],
        'finance' => [
            'label' => 'Finance',
            'default' => true,
            'items' => [
                'reports' => [
                    'label' => 'Reports',
                    'href' => '/admin/reports.php',
                    'default' => true,
                    'caps' => ['finance_view', 'matchday'],
                ],
                'matchday_finance' => [
                    'label' => 'Matchday income',
                    'href' => '/admin/matchday_finance.php',
                    'default' => false,
                    'caps' => ['finance_view', 'matchday'],
                ],
                'stripe_dashboard' => [
                    'label' => 'Stripe Dashboard',
                    'href' => '/admin/stripe_dashboard.php',
                    'default' => true,
                    'caps' => ['finance_view'],
                ],
            ],
        ],
        'secretary' => [
            'label' => 'Secretary',
            'default' => false,
            'items' => [
                'secretary_dashboard' => [
                    'label' => 'Dashboard',
                    'href' => '/admin/secretary_dashboard.php',
                    'default' => true,
                    'caps' => ['secretary_ops'],
                ],
                'discipline_register' => [
                    'label' => 'Discipline register',
                    'href' => '/admin/discipline_register.php',
                    'default' => true,
                    'caps' => ['secretary_ops'],
                ],
                'player_registrations' => [
                    'label' => 'Player registrations',
                    'href' => '/admin/player_registrations.php',
                    'default' => true,
                    'caps' => ['secretary_ops'],
                ],
                'secretary_tasks' => [
                    'label' => 'Tasks & planner',
                    'href' => '/admin/secretary_tasks.php',
                    'default' => true,
                    'caps' => ['secretary_ops'],
                ],
                'secretary_correspondence' => [
                    'label' => 'Correspondence',
                    'href' => '/admin/secretary_correspondence.php',
                    'default' => true,
                    'caps' => ['secretary_ops'],
                ],
                'fixture_change_requests' => [
                    'label' => 'Fixture changes',
                    'href' => '/admin/fixture_change_requests.php',
                    'default' => true,
                    'caps' => ['secretary_ops'],
                ],
                'committee_meetings' => [
                    'label' => 'Committee & AGM',
                    'href' => '/admin/committee_meetings.php',
                    'default' => true,
                    'caps' => ['secretary_ops'],
                ],
                'secretary_documents' => [
                    'label' => 'Documents',
                    'href' => '/admin/secretary_documents.php',
                    'default' => true,
                    'caps' => ['secretary_ops'],
                ],
                'secretary_guide' => [
                    'label' => 'Emergency guide',
                    'href' => '/admin/secretary_guide.php',
                    'default' => true,
                    'caps' => ['secretary_ops'],
                ],
            ],
        ],
        'clubsetup' => [
            'label' => 'Club setup',
            'default' => true,
            'items' => [
                'seasons' => [
                    'label' => 'Seasons',
                    'href' => '/admin/seasons.php',
                    'default' => true,
                    'caps' => ['club_setup'],
                ],
                'opponents' => [
                    'label' => 'Opponents',
                    'href' => '/admin/opponents.php',
                    'default' => true,
                    'caps' => ['club_setup'],
                ],
                'competitions' => [
                    'label' => 'Competitions',
                    'href' => '/admin/competitions.php',
                    'default' => true,
                    'caps' => ['club_setup'],
                ],
                'venues' => [
                    'label' => 'Venues',
                    'href' => '/admin/venues.php',
                    'default' => true,
                    'caps' => ['club_setup'],
                ],
                'facilities' => [
                    'label' => 'Facilities',
                    'href' => '/admin/facilities.php',
                    'default' => false,
                    'caps' => ['club_setup'],
                ],
            ],
        ],
        'publishing' => [
            'label' => 'Publishing',
            'default' => true,
            'items' => [
                'league_table' => [
                    'label' => 'League table',
                    'href' => '/admin/league_table.php',
                    'default' => true,
                    'caps' => ['content_social'],
                ],
                'template_packs' => [
                    'label' => 'Template packs',
                    'href' => '/admin/template_packs.php',
                    'default' => false,
                    'caps' => ['content_social'],
                ],
            ],
        ],
        'admin' => [
            'label' => 'Admin',
            'default' => true,
            'items' => [
                'match_photos' => [
                    'label' => 'Media Library',
                    'href' => '/admin/match_photos.php',
                    'default' => true,
                    'caps' => ['admin_settings'],
                ],
                'people' => [
                    'label' => 'Photo tags',
                    'href' => '/admin/people.php',
                    'default' => true,
                    'caps' => ['admin_settings'],
                ],
                'photo_albums' => [
                    'label' => 'Photo albums',
                    'href' => '/admin/photo_albums.php',
                    'default' => true,
                    'caps' => ['admin_settings'],
                ],
                'settings' => [
                    'label' => 'Settings',
                    'href' => '/admin/settings.php',
                    'default' => true,
                    'caps' => ['admin_settings'],
                ],
                'club_people' => [
                    'label' => 'People & Users',
                    'href' => '/admin/club_people.php',
                    'default' => true,
                    'caps' => ['admin_settings'],
                ],
                'positions' => [
                    'label' => 'Club roles',
                    'href' => '/admin/positions.php',
                    'default' => true,
                    'caps' => ['admin_settings'],
                ],
                'access_roles' => [
                    'label' => 'Access templates',
                    'href' => '/admin/access_roles.php',
                    'default' => true,
                    'caps' => ['admin_settings'],
                ],
                'access_review' => [
                    'label' => 'Access review',
                    'href' => '/admin/access_review.php',
                    'default' => true,
                    'caps' => ['admin_settings'],
                ],
            ],
        ],
    ];
}

/** Values are loaded once per request; a save is visible on the next request. */
function hub_navigation_preferences(): array
{
    global $pdo;
    static $preferences = null;
    if ($preferences === null) {
        $decoded = json_decode(site_setting($pdo, 'hub_navigation_visibility', '{}'), true);
        $preferences = is_array($decoded) ? $decoded : [];
    }
    return $preferences;
}

function hub_navigation_group_enabled(string $key, ?array $preferences = null): bool
{
    $group = hub_navigation_definitions()[$key] ?? null;
    if (!$group) return false;
    $preferences ??= hub_navigation_preferences();
    return (bool)($preferences['groups'][$key] ?? $group['default']);
}

function hub_navigation_item_enabled(string $key, ?array $preferences = null): bool
{
    [$groupKey, $itemKey] = array_pad(explode('.', $key, 2), 2, '');
    $item = hub_navigation_definitions()[$groupKey]['items'][$itemKey] ?? null;
    if (!$item) return false;
    $preferences ??= hub_navigation_preferences();
    return (bool)($preferences['items'][$key] ?? $item['default']);
}

function hub_navigation_item_visible(string $key, ?array $preferences = null): bool
{
    $groupKey = explode('.', $key, 2)[0];
    return hub_navigation_group_enabled($groupKey, $preferences) && hub_navigation_item_enabled($key, $preferences);
}

function hub_navigation_item_available(string $key): bool
{
    if (!hub_navigation_item_visible($key)) return false;
    [$groupKey, $itemKey] = explode('.', $key, 2);
    $item = hub_navigation_definitions()[$groupKey]['items'][$itemKey];
    if (!empty($item['developer_only']) && !hub_auth_is_developer()) return false;
    if (!empty($item['admin_only']) && (hub_auth_current_user()['role'] ?? '') !== 'admin') return false;
    return $item['caps'] === [] || hub_auth_has_any_capability($item['caps']);
}

/** Avoid empty section headings when all accessible items have been hidden. */
function hub_navigation_group_available(string $key): bool
{
    foreach (hub_navigation_definitions()[$key]['items'] ?? [] as $itemKey => $item) {
        if (hub_navigation_item_available($key . '.' . $itemKey)) return true;
    }
    return false;
}

function hub_navigation_save(PDO $pdo, array $groups, array $items): void
{
    $preferences = ['groups' => [], 'items' => []];
    foreach (hub_navigation_definitions() as $groupKey => $group) {
        $preferences['groups'][$groupKey] = ($groups[$groupKey] ?? '') === '1';
        foreach ($group['items'] as $itemKey => $item) {
            $key = $groupKey . '.' . $itemKey;
            $preferences['items'][$key] = ($items[$key] ?? '') === '1';
        }
    }
    // Keep this admin-only setting outside the public website's editable key list.
    site_settings_ensure_schema($pdo);
    $stmt = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $stmt->execute([':key' => 'hub_navigation_visibility', ':value' => json_encode($preferences, JSON_THROW_ON_ERROR)]);
}
