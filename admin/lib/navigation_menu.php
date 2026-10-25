<?php
declare(strict_types=1);

/**
 * The Hub menu as people see it: task-based sections, one link per job, and a
 * tab strip across the top of related pages.
 *
 * lib/navigation_settings.php stays the registry of individual features (the
 * on/off switches in Settings > Navigation, help-centre links, mobile bar).
 * This file only decides how those features are grouped and presented, so
 * every saved switch keeps working and no URL or permission changes.
 *
 * Link:  id, label, icon, tabs[], optional also[] / uri[] (extra pages that
 *        highlight the link), badge[] (order-alert sections), external, strip.
 * Tab:   label, href, pages[] (list pages: the tab strip shows on these),
 *        also[] (detail/edit pages: highlight only), feature ("group.item"
 *        registry key) or caps[] / group, developer, badge (alert section).
 */

require_once __DIR__ . '/navigation_settings.php';
require_once __DIR__ . '/order_alerts.php';

function hub_navigation_menu(): array
{
    return [
        'home' => [
            'label' => '',
            'icon' => 'fa-house',
            'links' => [
                ['id' => 'overview', 'label' => 'Club overview', 'icon' => 'fa-house', 'tabs' => [
                    ['label' => 'Overview', 'href' => '/admin/index.php', 'pages' => ['index.php'], 'feature' => 'overview.index'],
                    ['label' => 'Reminders', 'href' => '/admin/club_reminders.php', 'pages' => ['club_reminders.php'], 'feature' => 'overview.club_reminders'],
                ]],
                ['id' => 'orders', 'label' => 'Inbox', 'icon' => 'fa-inbox', 'badge' => ORDER_ALERT_SECTIONS, 'tabs' => [
                    ['label' => 'Inbox', 'href' => '/admin/inbox.php', 'pages' => ['inbox.php', 'orders_inbox.php'], 'feature' => 'overview.orders_inbox'],
                ]],
            ],
        ],
        'matchday' => [
            'label' => 'Match Day',
            'icon' => 'fa-futbol',
            'links' => [
                ['id' => 'fixtures', 'label' => 'Fixtures & results', 'icon' => 'fa-futbol', 'tabs' => [
                    ['label' => 'Fixtures', 'href' => '/admin/matches.php', 'pages' => ['matches.php'], 'feature' => 'matchday.matches',
                        'also' => ['match.php', 'match_lineups.php', 'match_record_events.php', 'match_starting_11.php', 'match_starting_11_graphic.php', 'fixture_starting_11.php', 'match_next_match.php', 'match_graphics.php', 'match_poster.php', 'match_events.php', 'monthly_fixtures.php', 'match_fixtures_poster.php', 'match_media.php', 'match_templates.php']],
                    ['label' => 'Match report import', 'href' => '/admin/match_report_import.php', 'pages' => ['match_report_import.php'], 'feature' => 'matchday.match_reports'],
                ]],
                ['id' => 'stats', 'label' => 'Stats & tables', 'icon' => 'fa-chart-simple', 'tabs' => [
                    ['label' => 'Stats', 'href' => '/admin/stats.php', 'pages' => ['stats.php'], 'feature' => 'matchday.stats',
                        'also' => ['matchday_stats.php', 'stats_extra.php', 'stats_player_detail.php', 'stats_referees.php']],
                    ['label' => 'League table', 'href' => '/admin/league_table.php', 'pages' => ['league_table.php'], 'feature' => 'overview.league_table',
                        'also' => ['league_table_manual_update.php', 'league_table_graphic.php']],
                    ['label' => 'League history', 'href' => '/admin/league_table_history.php', 'pages' => ['league_table_history.php'], 'feature' => 'overview.league_table'],
                    ['label' => 'Score predictor', 'href' => '/admin/score_predictor.php', 'pages' => ['score_predictor.php'], 'feature' => 'matchday.score_predictor'],
                ]],
                ['id' => 'players', 'label' => 'Players', 'icon' => 'fa-users', 'tabs' => [
                    ['label' => 'Players', 'href' => '/admin/players.php', 'pages' => ['players.php'], 'feature' => 'matchday.players',
                        'also' => ['player_add.php', 'player_edit.php', 'player_view.php', 'player.php', 'player_graphics.php', 'player_photos.php', 'player_photos_bulk.php', 'player_photo_review.php', 'player_reference.php', 'player_website.php', 'players_active.php', 'players_leave.php']],
                ]],
                ['id' => 'gate', 'label' => 'Scan & POS', 'icon' => 'fa-qrcode', 'tabs' => [
                    ['label' => 'Scan', 'href' => '/admin/scan_overview.php', 'pages' => ['scan_overview.php'], 'feature' => 'matchday.scan_overview', 'uri' => ['/scan']],
                    ['label' => 'POS', 'href' => '/admin/pos_overview.php', 'pages' => ['pos_overview.php'], 'feature' => 'matchday.pos_overview', 'uri' => ['/pos']],
                ]],
            ],
        ],
        'sponsorship' => [
            'label' => 'Sponsorship',
            'icon' => 'fa-handshake',
            'badge' => ['sponsors'],
            'links' => [
                ['id' => 'sponsors', 'label' => 'Sponsors', 'icon' => 'fa-handshake', 'tabs' => [
                    ['label' => 'Sponsors', 'href' => '/admin/sponsors.php', 'pages' => ['sponsors.php'], 'feature' => 'sponsorship.sponsors',
                        'also' => ['sponsor.php', 'sponsor_wall.php', 'sponsor_graphics.php', 'assign_sponsors.php']],
                    ['label' => 'Follow-ups', 'href' => '/admin/sponsor_followups.php', 'pages' => ['sponsor_followups.php'], 'feature' => 'sponsorship.sponsor_followups'],
                ]],
                ['id' => 'agreements', 'label' => 'Agreements', 'icon' => 'fa-file-signature', 'tabs' => [
                    ['label' => 'Agreements', 'href' => '/admin/sponsorship_agreements.php', 'pages' => ['sponsorship_agreements.php'], 'feature' => 'sponsorship.sponsorship_agreements', 'also' => ['sponsorship_agreement.php', 'sponsorship_edit.php']],
                    ['label' => 'Season deals', 'href' => '/admin/match_deals.php', 'pages' => ['match_deals.php'], 'feature' => 'sponsorship.match_deals'],
                    ['label' => 'Bundles', 'href' => '/admin/sponsorship_bundles.php', 'pages' => ['sponsorship_bundles.php'], 'feature' => 'sponsorship.sponsorship_bundles', 'also' => ['sponsorship_bundle.php']],
                    ['label' => 'Board renewals', 'href' => '/admin/board_renewals.php', 'pages' => ['board_renewals.php'], 'feature' => 'sponsorship.board_renewals'],
                ]],
                ['id' => 'sponsor_orders', 'label' => 'Sponsor orders', 'icon' => 'fa-cart-shopping', 'badge' => ['sponsors'], 'tabs' => [
                    ['label' => 'Sponsor orders', 'href' => '/admin/sponsor_orders.php', 'pages' => ['sponsor_orders.php'], 'feature' => 'sponsorship.sponsor_orders', 'also' => ['playersponsors_orders.php']],
                ]],
                ['id' => 'packages', 'label' => 'Packages', 'icon' => 'fa-box-open', 'tabs' => [
                    ['label' => 'Packages', 'href' => '/admin/sponsorship_packages.php', 'pages' => ['sponsorship_packages.php'], 'feature' => 'sponsorship.sponsorship_packages', 'also' => ['sponsorship_package.php', 'sponsorship_types.php', 'sponsorship_type.php']],
                ]],
            ],
        ],
        'shop' => [
            'label' => 'Shop',
            'icon' => 'fa-bag-shopping',
            'badge' => ['shop'],
            'links' => [
                ['id' => 'shop_overview', 'label' => 'Shop overview', 'icon' => 'fa-bag-shopping', 'tabs' => [
                    ['label' => 'Overview', 'href' => '/admin/shop_overview.php', 'pages' => ['shop_overview.php'], 'feature' => 'shop.shop_overview'],
                ]],
                ['id' => 'shop_orders', 'label' => 'Shop orders', 'icon' => 'fa-receipt', 'badge' => ['shop'], 'tabs' => [
                    ['label' => 'Orders', 'href' => '/admin/shop_orders.php', 'pages' => ['shop_orders.php'], 'feature' => 'shop.shop_orders', 'also' => ['shop_order.php', 'shop_order_new.php']],
                ]],
                ['id' => 'shop_products', 'label' => 'Products', 'icon' => 'fa-shirt', 'tabs' => [
                    ['label' => 'Products', 'href' => '/admin/shop_products.php', 'pages' => ['shop_products.php'], 'feature' => 'shop.shop_products', 'also' => ['shop_product.php', 'shop_media_catalogue.php']],
                    ['label' => 'Categories', 'href' => '/admin/shop_categories.php', 'pages' => ['shop_categories.php'], 'feature' => 'shop.shop_categories'],
                    ['label' => 'Modifiers', 'href' => '/admin/shop_modifiers.php', 'pages' => ['shop_modifiers.php'], 'feature' => 'shop.shop_modifiers'],
                ]],
                ['id' => 'shop_setup', 'label' => 'Shop setup', 'icon' => 'fa-sliders', 'tabs' => [
                    ['label' => 'Discount codes', 'href' => '/admin/shop_discount_codes.php', 'pages' => ['shop_discount_codes.php'], 'feature' => 'shop.shop_discount_codes'],
                    ['label' => 'Shipping zones', 'href' => '/admin/shop_shipping.php', 'pages' => ['shop_shipping.php'], 'feature' => 'shop.shop_shipping', 'also' => ['shop_shipping_classes.php']],
                    ['label' => 'Settings & emails', 'href' => '/admin/shop_settings.php', 'pages' => ['shop_settings.php'], 'caps' => ['shop'], 'group' => 'shop', 'also' => ['shop_email.php', 'shop_email_preview.php']],
                ]],
                ['id' => 'storefront', 'label' => 'View storefront', 'icon' => 'fa-arrow-up-right-from-square', 'external' => true, 'tabs' => [
                    ['label' => 'View storefront', 'href' => '/shop/', 'pages' => [], 'feature' => 'shop.storefront'],
                ]],
            ],
        ],
        'ticketing' => [
            'label' => 'Tickets',
            'icon' => 'fa-ticket',
            'badge' => ['season_tickets', 'match_tickets', 'hospitality'],
            'links' => [
                ['id' => 'season_tickets', 'label' => 'Season tickets', 'icon' => 'fa-id-card', 'badge' => ['season_tickets'], 'tabs' => [
                    ['label' => 'Orders', 'href' => '/admin/season_ticket_orders.php', 'pages' => ['season_ticket_orders.php'], 'feature' => 'ticketing.season_ticket_orders', 'badge' => ['season_tickets'], 'also' => ['season_ticket_order.php', 'season_ticket_holders.php', 'season_ticket_holder.php']],
                    ['label' => 'Renewals', 'href' => '/admin/season_ticket_renewals.php', 'pages' => ['season_ticket_renewals.php'], 'feature' => 'ticketing.season_ticket_renewals'],
                    ['label' => 'Free signup codes', 'href' => '/admin/season_ticket_free_codes.php', 'pages' => ['season_ticket_free_codes.php'], 'feature' => 'ticketing.season_ticket_free_codes'],
                    ['label' => 'Types & pricing', 'href' => '/admin/season_ticket_types.php', 'pages' => ['season_ticket_types.php'], 'feature' => 'ticketing.season_ticket_types', 'also' => ['season_ticket_type.php', 'season_pass_rules.php']],
                ]],
                ['id' => 'match_tickets', 'label' => 'Match tickets', 'icon' => 'fa-ticket', 'badge' => ['match_tickets'], 'tabs' => [
                    ['label' => 'Orders', 'href' => '/admin/ticket_orders.php', 'pages' => ['ticket_orders.php'], 'feature' => 'ticketing.ticket_orders', 'badge' => ['match_tickets'], 'also' => ['ticket_order.php', 'complimentary_admission.php']],
                    ['label' => 'Fixture setup', 'href' => '/admin/fixture_tickets.php', 'pages' => ['fixture_tickets.php'], 'feature' => 'ticketing.fixture_tickets'],
                    ['label' => 'Ticket packages', 'href' => '/admin/ticket_packages.php', 'pages' => ['ticket_packages.php'], 'feature' => 'ticketing.ticket_packages'],
                ]],
                ['id' => 'door', 'label' => 'Door dashboard', 'icon' => 'fa-door-open', 'tabs' => [
                    ['label' => 'Live gates', 'href' => '/admin/door_dashboard.php', 'pages' => ['door_dashboard.php'], 'feature' => 'ticketing.door_dashboard'],
                ]],
                ['id' => 'events', 'label' => 'Events', 'icon' => 'fa-calendar-days', 'tabs' => [
                    ['label' => 'Events', 'href' => '/admin/events.php', 'pages' => ['events.php'], 'feature' => 'ticketing.events', 'also' => ['event_edit.php', 'event_tickets.php', 'event_dashboard.php', 'event_attendees.php', 'event_message.php', 'event_promos.php']],
                    ['label' => 'Venues', 'href' => '/admin/event_venues.php', 'pages' => ['event_venues.php'], 'feature' => 'ticketing.event_venues'],
                    ['label' => 'Settings', 'href' => '/admin/event_settings.php', 'pages' => ['event_settings.php'], 'feature' => 'ticketing.event_settings'],
                ]],
                ['id' => 'hospitality', 'label' => 'Hospitality', 'icon' => 'fa-champagne-glasses', 'badge' => ['hospitality'], 'tabs' => [
                    ['label' => 'Bookings', 'href' => '/admin/hospitality.php', 'pages' => ['hospitality.php'], 'also' => ['hospitality_booking.php'], 'feature' => 'ticketing.hospitality', 'badge' => ['hospitality']],
                    ['label' => 'Prices & settings', 'href' => '/admin/hospitality_settings.php', 'pages' => ['hospitality_settings.php'], 'feature' => 'ticketing.hospitality'],
                ]],
            ],
        ],
        'money' => [
            'label' => 'Money',
            'icon' => 'fa-sterling-sign',
            'links' => [
                ['id' => 'reports', 'label' => 'Reports', 'icon' => 'fa-chart-column', 'tabs' => [
                    ['label' => 'Reports', 'href' => '/admin/reports.php', 'pages' => ['reports.php'], 'feature' => 'finance.reports'],
                ]],
                ['id' => 'matchday_income', 'label' => 'Matchday income', 'icon' => 'fa-sterling-sign', 'tabs' => [
                    ['label' => 'Matchday income', 'href' => '/admin/matchday_finance.php', 'pages' => ['matchday_finance.php'], 'feature' => 'finance.matchday_finance', 'legacy' => 'matchday.matchday_finance', 'also' => ['matchday_finance_edit.php']],
                ]],
                ['id' => 'stripe', 'label' => 'Stripe payments', 'icon' => 'fa-credit-card', 'tabs' => [
                    ['label' => 'Stripe payments', 'href' => '/admin/stripe_dashboard.php', 'pages' => ['stripe_dashboard.php'], 'feature' => 'finance.stripe_dashboard'],
                    ['label' => 'Payout reconciliation', 'href' => '/admin/payout_reconciliation.php', 'pages' => ['payout_reconciliation.php'], 'feature' => 'finance.payout_reconciliation'],
                ]],
            ],
        ],
        'secretary' => [
            'label' => 'Secretary',
            'icon' => 'fa-user-tie',
            'links' => [
                ['id' => 'sec_dashboard', 'label' => 'Dashboard', 'icon' => 'fa-user-tie', 'tabs' => [
                    ['label' => 'Dashboard', 'href' => '/admin/secretary_dashboard.php', 'pages' => ['secretary_dashboard.php'], 'feature' => 'secretary.secretary_dashboard'],
                    ['label' => 'Emergency guide', 'href' => '/admin/secretary_guide.php', 'pages' => ['secretary_guide.php'], 'feature' => 'secretary.secretary_guide'],
                ]],
                ['id' => 'sec_tasks', 'label' => 'Tasks & planner', 'icon' => 'fa-list-check', 'tabs' => [
                    ['label' => 'Tasks & planner', 'href' => '/admin/secretary_tasks.php', 'pages' => ['secretary_tasks.php'], 'feature' => 'secretary.secretary_tasks', 'also' => ['secretary_task_edit.php']],
                ]],
                ['id' => 'sec_papers', 'label' => 'Letters & documents', 'icon' => 'fa-envelope', 'tabs' => [
                    ['label' => 'Correspondence', 'href' => '/admin/secretary_correspondence.php', 'pages' => ['secretary_correspondence.php'], 'feature' => 'secretary.secretary_correspondence', 'also' => ['secretary_correspondence_edit.php']],
                    ['label' => 'Documents', 'href' => '/admin/secretary_documents.php', 'pages' => ['secretary_documents.php'], 'feature' => 'secretary.secretary_documents', 'also' => ['secretary_document_delete.php', 'secretary_document_download.php']],
                ]],
                ['id' => 'sec_committee', 'label' => 'Committee & AGM', 'icon' => 'fa-people-group', 'tabs' => [
                    ['label' => 'Committee & AGM', 'href' => '/admin/committee_meetings.php', 'pages' => ['committee_meetings.php'], 'feature' => 'secretary.committee_meetings', 'also' => ['committee_meeting_edit.php', 'committee_meeting_delete.php']],
                ]],
                ['id' => 'sec_registers', 'label' => 'Registrations & discipline', 'icon' => 'fa-id-card-clip', 'tabs' => [
                    ['label' => 'Registrations', 'href' => '/admin/player_registrations.php', 'pages' => ['player_registrations.php'], 'feature' => 'secretary.player_registrations', 'also' => ['player_registration_edit.php']],
                    ['label' => 'Discipline', 'href' => '/admin/discipline_register.php', 'pages' => ['discipline_register.php'], 'feature' => 'secretary.discipline_register', 'also' => ['discipline_incident.php', 'discipline_incident_delete.php']],
                    ['label' => 'Fixture changes', 'href' => '/admin/fixture_change_requests.php', 'pages' => ['fixture_change_requests.php'], 'feature' => 'secretary.fixture_change_requests', 'also' => ['fixture_change_request_edit.php']],
                ]],
            ],
        ],
        'website' => [
            'label' => 'Website & social',
            'icon' => 'fa-globe',
            'links' => [
                ['id' => 'news', 'label' => 'News', 'icon' => 'fa-newspaper', 'tabs' => [
                    ['label' => 'News', 'href' => '/admin/news.php', 'pages' => ['news.php'], 'feature' => 'website.news', 'also' => ['news_edit.php']],
                ]],
                ['id' => 'club_pages', 'label' => 'Club pages', 'icon' => 'fa-file-lines', 'tabs' => [
                    ['label' => 'Club pages', 'href' => '/admin/club_pages.php', 'pages' => ['club_pages.php'], 'feature' => 'website.club_pages'],
                ]],
                ['id' => 'media', 'label' => 'Media', 'icon' => 'fa-photo-film', 'tabs' => [
                    ['label' => 'Library', 'href' => '/admin/match_photos.php', 'pages' => ['match_photos.php', 'media.php'], 'feature' => 'admin.match_photos', 'also' => ['match_photo_view.php']],
                    ['label' => 'Albums', 'href' => '/admin/photo_albums.php', 'pages' => ['photo_albums.php'], 'feature' => 'admin.photo_albums', 'also' => ['photo_album.php']],
                    ['label' => 'Photo tags', 'href' => '/admin/people.php', 'pages' => ['people.php'], 'feature' => 'admin.people'],
                    ['label' => 'Facebook import', 'href' => '/admin/facebook_photo_import.php', 'pages' => ['facebook_photo_import.php'], 'feature' => 'admin.match_photos', 'caps' => ['content_social']],
                ]],
                ['id' => 'graphics', 'label' => 'Graphics & posting', 'icon' => 'fa-layer-group', 'tabs' => [
                    ['label' => 'Template packs', 'href' => '/admin/template_packs.php', 'pages' => ['template_packs.php'], 'feature' => 'publishing.template_packs', 'also' => ['template_pack.php', 'templates.php']],
                    ['label' => 'Facebook diagnostics', 'href' => '/admin/facebook_diagnostics.php', 'pages' => ['facebook_diagnostics.php'], 'feature' => 'publishing.template_packs', 'caps' => ['content_social']],
                ]],
                ['id' => 'supporters', 'label' => 'Supporter area', 'icon' => 'fa-bullhorn', 'tabs' => [
                    ['label' => 'Announcements', 'href' => '/admin/announcements.php', 'pages' => ['announcements.php'], 'feature' => 'supporter.announcements', 'also' => ['announcement.php']],
                    ['label' => 'Feedback', 'href' => '/admin/feedback.php', 'pages' => ['feedback.php'], 'feature' => 'supporter.feedback', 'also' => ['feedback_item.php']],
                    ['label' => 'Venue reviews', 'href' => '/admin/venue_reviews.php', 'pages' => ['venue_reviews.php'], 'feature' => 'supporter.venue_reviews'],
                    ['label' => 'Player of the Match', 'href' => '/admin/motm.php', 'pages' => ['motm.php'], 'feature' => 'supporter.motm'],
                ]],
                ['id' => 'website', 'label' => 'View website', 'icon' => 'fa-arrow-up-right-from-square', 'external' => true, 'tabs' => [
                    ['label' => 'View website', 'href' => '/', 'pages' => [], 'feature' => 'website.website'],
                ]],
            ],
        ],
        'setup' => [
            'label' => 'Setup',
            'icon' => 'fa-gear',
            'links' => [
                ['id' => 'seasons', 'label' => 'Seasons', 'icon' => 'fa-calendar-days', 'tabs' => [
                    ['label' => 'Seasons', 'href' => '/admin/seasons.php', 'pages' => ['seasons.php'], 'feature' => 'clubsetup.seasons', 'also' => ['season.php', 'season_archive.php', 'season_delete.php']],
                ]],
                ['id' => 'teams_venues', 'label' => 'Opponents & venues', 'icon' => 'fa-people-arrows', 'tabs' => [
                    ['label' => 'Opponents', 'href' => '/admin/opponents.php', 'pages' => ['opponents.php'], 'feature' => 'clubsetup.opponents', 'also' => ['opponent.php']],
                    ['label' => 'Competitions', 'href' => '/admin/competitions.php', 'pages' => ['competitions.php'], 'feature' => 'clubsetup.competitions', 'also' => ['competition.php']],
                    ['label' => 'Venues', 'href' => '/admin/venues.php', 'pages' => ['venues.php'], 'feature' => 'clubsetup.venues', 'also' => ['venue.php']],
                ]],
                ['id' => 'facilities', 'label' => 'Facilities', 'icon' => 'fa-screwdriver-wrench', 'tabs' => [
                    ['label' => 'Facilities', 'href' => '/admin/facilities.php', 'pages' => ['facilities.php'], 'feature' => 'clubsetup.facilities'],
                ]],
                ['id' => 'access', 'label' => 'Users & access', 'icon' => 'fa-address-book', 'tabs' => [
                    ['label' => 'Users', 'href' => '/admin/club_people.php', 'pages' => ['club_people.php'], 'feature' => 'admin.club_people', 'also' => ['club_person.php', 'admin_users.php', 'user.php']],
                    ['label' => 'Club positions', 'href' => '/admin/positions.php', 'pages' => ['positions.php'], 'feature' => 'admin.positions'],
                    ['label' => 'Roles', 'href' => '/admin/access_roles.php', 'pages' => ['access_roles.php'], 'feature' => 'admin.access_roles', 'also' => ['access_role_edit.php']],
                    ['label' => 'Access review', 'href' => '/admin/access_review.php', 'pages' => ['access_review.php'], 'feature' => 'admin.access_review'],
                ]],
                ['id' => 'settings', 'label' => 'Settings', 'icon' => 'fa-gear', 'strip' => false, 'tabs' => [
                    ['label' => 'Settings', 'href' => '/admin/settings.php', 'pages' => ['settings.php'], 'feature' => 'admin.settings', 'also' => ['social_post_settings.php', 'settings_public.php']],
                ]],
                ['id' => 'mailboxes', 'label' => 'Mailboxes', 'icon' => 'fa-envelope', 'strip' => false, 'tabs' => [
                    ['label' => 'Mailboxes', 'href' => '/admin/mailboxes.php', 'pages' => ['mailboxes.php'], 'feature' => 'admin.mailboxes'],
                ]],
            ],
        ],
    ];
}

/** Extra pages that have no menu entry but should be findable from the Ctrl+K search. */
function hub_navigation_search_extras(): array
{
    return [
        ['label' => 'Hidden team games', 'href' => '/admin/hidden_team_games.php', 'caps' => ['fundraising'], 'where' => 'Fundraising'],
        ['label' => 'Complimentary admissions', 'href' => '/admin/complimentary_admission.php', 'caps' => ['tickets_ops'], 'where' => 'Tickets'],
        ['label' => 'Season pass rules', 'href' => '/admin/season_pass_rules.php', 'caps' => ['tickets_ops'], 'where' => 'Tickets'],
        ['label' => 'Club birthdays', 'href' => '/admin/club_birthdays.php', 'caps' => ['admin_settings'], 'where' => 'People'],
        ['label' => 'Sponsor wall', 'href' => '/admin/sponsor_wall.php', 'caps' => ['sponsorship', 'finance_view'], 'where' => 'Sponsorship'],
        ['label' => 'Sponsor graphics', 'href' => '/admin/sponsor_graphics.php', 'caps' => ['sponsorship', 'finance_view'], 'where' => 'Sponsorship'],
        ['label' => 'Player graphics', 'href' => '/admin/player_graphics.php', 'caps' => ['matchday'], 'where' => 'Players'],
        ['label' => 'Player photos', 'href' => '/admin/player_photos.php', 'caps' => ['matchday'], 'where' => 'Players'],
        ['label' => 'Monthly fixtures graphic', 'href' => '/admin/monthly_fixtures.php', 'caps' => ['matchday'], 'where' => 'Fixtures'],
        ['label' => 'Match graphics', 'href' => '/admin/match_graphics.php', 'caps' => ['matchday'], 'where' => 'Fixtures'],
        ['label' => 'Opponent report video', 'href' => '/admin/stats.php?tab=opposition', 'caps' => ['matchday'], 'where' => 'Stats'],
        ['label' => 'Year-on-year comparison', 'href' => '/admin/stats.php?tab=compare', 'caps' => ['matchday'], 'where' => 'Stats'],
        ['label' => 'Publishing settings', 'href' => '/admin/settings.php?tab=publishing', 'caps' => ['admin_settings'], 'where' => 'Settings'],
        ['label' => 'Email notifications', 'href' => '/admin/settings.php?tab=notifications', 'caps' => ['admin_settings'], 'where' => 'Settings'],
        ['label' => 'Menu visibility (Navigation)', 'href' => '/admin/settings.php?tab=navigation', 'caps' => ['admin_settings'], 'where' => 'Settings'],
        ['label' => 'Payments settings', 'href' => '/admin/settings.php?tab=payments', 'caps' => ['admin_settings'], 'where' => 'Settings'],
        ['label' => 'Social post settings', 'href' => '/admin/social_post_settings.php', 'caps' => ['admin_settings'], 'where' => 'Settings'],
        ['label' => 'Export everything', 'href' => '/admin/export_all.php', 'caps' => ['admin_settings'], 'where' => 'Settings'],
        ['label' => 'Help Centre', 'href' => '/admin/help.php', 'caps' => [], 'where' => 'Help'],
        ['label' => 'Edit my profile', 'href' => '/admin/profile.php', 'caps' => [], 'where' => 'Account'],
    ];
}

function hub_navigation_tab_available(array $tab): bool
{
    if (!empty($tab['developer'])) {
        return hub_auth_is_developer();
    }
    $caps = $tab['caps'] ?? [];
    if ($caps !== [] && !hub_auth_has_any_capability($caps)) {
        return false;
    }
    if (isset($tab['feature'])) {
        if (hub_navigation_item_available((string) $tab['feature'])) {
            return true;
        }
        // A switch that used to live under another section (e.g. the old Match Day
        // "Matchday income" link) still counts, so nobody's saved choice is lost.
        if (isset($tab['legacy'])) {
            [$legacyGroup] = explode('.', (string) $tab['legacy'], 2);
            $items = hub_navigation_preferences()['items'] ?? [];
            return hub_navigation_group_enabled($legacyGroup)
                && (bool) ($items[$tab['legacy']] ?? true)
                && hub_auth_has_any_capability(['finance_view', 'matchday']);
        }
        return false;
    }
    return !isset($tab['group']) || hub_navigation_group_enabled((string) $tab['group']);
}

function hub_navigation_script(): string
{
    return basename((string) ($_SERVER['PHP_SELF'] ?? ''));
}

function hub_navigation_tab_current(array $tab, string $script, string $uri): bool
{
    if ($script !== '' && (in_array($script, $tab['pages'] ?? [], true) || in_array($script, $tab['also'] ?? [], true))) {
        return true;
    }
    foreach ($tab['uri'] ?? [] as $prefix) {
        if (str_starts_with($uri, (string) $prefix)) {
            return true;
        }
    }
    return false;
}

/**
 * The menu for this user and page: sections of links, each with its available
 * tabs. Empty links and sections are dropped.
 *
 * @return list<array{key:string,label:string,open:bool,active:bool,badge_keys:list<string>,links:list<array>}>
 */
function hub_navigation_resolve(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $script = hub_navigation_script();
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $resolved = [];
    foreach (hub_navigation_menu() as $sectionKey => $section) {
        $links = [];
        foreach ($section['links'] as $link) {
            $tabs = [];
            foreach ($link['tabs'] as $tab) {
                if (!hub_navigation_tab_available($tab)) {
                    continue;
                }
                $tab['current'] = hub_navigation_tab_current($tab, $script, $uri);
                $tabs[] = $tab;
            }
            if ($tabs === []) {
                continue;
            }
            $active = false;
            foreach ($link['tabs'] as $tab) {
                if (hub_navigation_tab_current($tab, $script, $uri)) {
                    $active = true;
                    break;
                }
            }
            $link['tabs'] = $tabs;
            $link['href'] = $tabs[0]['href'];
            $link['active'] = $active;
            $links[] = $link;
        }
        if ($links === []) {
            continue;
        }
        $sectionActive = false;
        foreach ($links as $link) {
            $sectionActive = $sectionActive || $link['active'];
        }
        $resolved[] = [
            'key' => (string) $sectionKey,
            'label' => (string) $section['label'],
            'icon' => (string) ($section['icon'] ?? 'fa-circle'),
            'active' => $sectionActive,
            'badge_keys' => $section['badge'] ?? [],
            'links' => $links,
        ];
    }
    return $cache = $resolved;
}

/** The tab strip for the page being viewed, or null when it isn't a list page of a multi-tab link. */
function hub_navigation_strip(): ?array
{
    $script = hub_navigation_script();
    foreach (hub_navigation_resolve() as $section) {
        foreach ($section['links'] as $link) {
            if (($link['strip'] ?? true) === false || count($link['tabs']) < 2) {
                continue;
            }
            foreach ($link['tabs'] as $tab) {
                if (in_array($script, $tab['pages'] ?? [], true)) {
                    return ['label' => $link['label'], 'tabs' => $link['tabs']];
                }
            }
        }
    }
    return null;
}

function hub_navigation_badge_total(array $alerts, array $keys): int
{
    $total = 0;
    foreach ($keys as $key) {
        $total += (int) ($alerts[$key] ?? 0);
    }
    return $total;
}

/** Everything the Ctrl+K search can jump to for this user. */
function hub_navigation_search_index(): array
{
    $items = [];
    $seen = [];
    foreach (hub_navigation_resolve() as $section) {
        $where = $section['label'] !== '' ? $section['label'] : 'Home';
        foreach ($section['links'] as $link) {
            foreach ($link['tabs'] as $tab) {
                if (isset($seen[$tab['href']])) {
                    continue;
                }
                $seen[$tab['href']] = true;
                $label = count($link['tabs']) > 1 && $tab['label'] !== $link['label'] ? $link['label'] . ' › ' . $tab['label'] : $tab['label'];
                $items[] = ['label' => $label, 'where' => $where, 'href' => $tab['href'], 'external' => !empty($link['external'])];
            }
        }
    }
    foreach (hub_navigation_search_extras() as $extra) {
        if (isset($seen[$extra['href']])) {
            continue;
        }
        if ($extra['caps'] !== [] && !hub_auth_has_any_capability($extra['caps'])) {
            continue;
        }
        $seen[$extra['href']] = true;
        $items[] = ['label' => $extra['label'], 'where' => $extra['where'], 'href' => $extra['href'], 'external' => false];
    }
    if (hub_auth_is_developer()) {
        $items[] = ['label' => 'Developer tools', 'where' => 'Developer', 'href' => '/admin/developer.php', 'external' => false];
        $items[] = ['label' => 'Product analytics', 'where' => 'Developer', 'href' => '/admin/developer_analytics.php', 'external' => false];
    }
    return $items;
}

/**
 * The few menu links this person opens most (last 30 days of Hub analytics),
 * so their daily pages are one click from anywhere. Cached in the session.
 *
 * @return list<array{label:string,href:string,icon:string}>
 */
function hub_navigation_most_used(PDO $pdo, int $limit = 4): array
{
    $userId = (int) (hub_auth_current_user()['id'] ?? 0);
    if ($userId <= 0) {
        return [];
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }
    $cached = $_SESSION['hub_nav_most_used'] ?? null;
    if (is_array($cached) && (int) ($cached['at'] ?? 0) > time() - 900 && (int) ($cached['uid'] ?? 0) === $userId) {
        $paths = (array) ($cached['paths'] ?? []);
    } else {
        $paths = [];
        try {
            $stmt = $pdo->prepare(
                "SELECT page_path, COUNT(*) AS views FROM hub_analytics_events
                 WHERE user_id = :u AND event_type = 'page_view' AND created_at >= (NOW() - INTERVAL 30 DAY)
                 GROUP BY page_path ORDER BY views DESC LIMIT 40"
            );
            $stmt->execute([':u' => $userId]);
            $paths = array_map(static fn(array $r): string => (string) $r['page_path'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (Throwable $exception) {
            $paths = []; // analytics table missing or unreachable: the menu simply shows no shortcuts
        }
        $_SESSION['hub_nav_most_used'] = ['at' => time(), 'uid' => $userId, 'paths' => $paths];
    }

    $byScript = [];
    foreach (hub_navigation_resolve() as $section) {
        foreach ($section['links'] as $link) {
            if (!empty($link['external']) || $section['key'] === 'home') {
                continue;
            }
            foreach ($link['tabs'] as $tab) {
                foreach (array_merge($tab['pages'] ?? [], $tab['also'] ?? []) as $script) {
                    $byScript[(string) $script] ??= $link;
                }
            }
        }
    }
    $out = [];
    $used = [];
    foreach ($paths as $path) {
        $script = basename((string) parse_url($path, PHP_URL_PATH));
        $link = $byScript[$script] ?? null;
        if ($link === null || isset($used[$link['id']])) {
            continue;
        }
        $used[$link['id']] = true;
        $out[] = ['label' => (string) $link['label'], 'href' => (string) $link['href'], 'icon' => (string) $link['icon']];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}
