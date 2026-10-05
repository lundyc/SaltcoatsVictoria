<?php

declare(strict_types=1);

require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/access_roles.php';

/**
 * Committee positions (Treasurer, Secretary, ...) that staff/volunteer
 * accounts can be assigned, each granting a set of capabilities. Positions
 * themselves are fully admin-manageable (add/rename/delete) via
 * hub/positions.php — only the fixed set of capabilities below is
 * developer-defined, since each one corresponds to a real code-level gate
 * somewhere in the app.
 */
const HUB_CAPABILITIES = [
    'matchday' => 'Match Day (fixtures, lineups, live matchday stats, players)',
    'club_setup' => 'Club setup (seasons, opponents, competitions, venues, facilities)',
    'sponsorship' => 'Sponsorship (sponsors, follow-ups, agreements, bundles, packages)',
    'fundraising' => 'Fundraising (Hidden Team)',
    'finance_view' => 'Finance - view (reports, matchday income, Stripe dashboard, exports)',
    'finance_manage' => 'Finance - manage (record/edit payments, payment links, refunds, recalculate)',
    'tickets_ops' => 'Ticketing & gate operations (incl. POS)',
    'shop' => 'Club shop (orders, products, storefront settings)',
    'secretary_ops' => 'Secretary & club administration (discipline, registrations, correspondence, governance)',
    'publishing' => 'Publishing (league table, template packs)',
    'website' => 'Public website (news, club pages, website settings)',
    'content_social' => 'Content & social media tools (media library, photo tags, graphics, Facebook tools)',
    'admin_settings' => 'Site administration (people, accounts, roles, global settings)',
];

/**
 * Which real page (by basename) requires which capability/capabilities, for
 * pages that have been moved off a hard admin-only check. A page passes if
 * the user holds ANY of its listed capabilities (see
 * hub_auth_has_any_capability()) — most pages list exactly one. Used by
 * header.php's volunteer/staff default-deny gate, and by the pages
 * themselves alongside hub_auth_is_admin().
 *
 * @var array<string, list<string>>
 */
const HUB_PAGE_CAPABILITIES = [
    'pdf_importer.php' => ['matchday'], // Temporary historical importer.
    // Cross-club overview
    'club_reminders.php' => ['finance_view', 'matchday', 'club_setup', 'tickets_ops', 'secretary_ops'],

    // Finance / Stripe (already gated)
    'stripe_dashboard.php' => ['finance_view'],
    'stripe_refund.php' => ['finance_manage'],

    // Content & social media tools (already gated)
    'facebook_diagnostics.php' => ['content_social'],
    'facebook_photo_import.php' => ['content_social'],
    'google-callback.php' => ['content_social'],

    // Match Day — daily driver: fixtures, lineups, live stats, squad
    'fixture_starting_11.php' => ['matchday'],
    'match.php' => ['matchday'],
    'match_lineups.php' => ['matchday'],
    'match_lineup_save.php' => ['matchday'],
    'match_sub_save.php' => ['matchday'],
    'match_formation_save.php' => ['matchday'],
    'match_record_events.php' => ['matchday'],
    'match_record_event_save.php' => ['matchday'],
    'match_record_event_delete.php' => ['matchday'],
    'matchday_stats.php' => ['matchday'],
    'match_next_match.php' => ['matchday'],
    'match_player_of_match.php' => ['matchday'],
    // Matchday balance sheet: recorded by the treasurer or matchday staff,
    // so either capability grants access.
    'matchday_finance.php' => ['finance_view', 'matchday'],
    'matchday_finance_edit.php' => ['finance_manage', 'matchday'],
    'matchday_finance_export.php' => ['finance_view', 'matchday'],
    'matches.php' => ['matchday'],
    'stats.php' => ['matchday'],
    'monthly_fixtures.php' => ['matchday'],
    'player.php' => ['matchday'],
    'player_add.php' => ['matchday'],
    'player_edit.php' => ['matchday'],
    'player_reference.php' => ['matchday'],
    'player_view.php' => ['matchday'],
    'players.php' => ['matchday'],
    'players_leave.php' => ['matchday'],
    // Not their own nav section yet (folded under the hidden "Supporter
    // content" group) — matchday is the closest fit until that's revisited.
    'announcement.php' => ['matchday'],
    'announcements.php' => ['matchday'],
    'feedback.php' => ['matchday'],
    'feedback_item.php' => ['matchday'],
    'motm.php' => ['matchday'],
    'venue_reviews.php' => ['matchday'],

    // Club setup — season structure, opponents, competitions, venues
    'competition.php' => ['club_setup'],
    'competition_delete.php' => ['club_setup'],
    'competitions.php' => ['club_setup'],
    'facilities.php' => ['club_setup'],
    'opponent.php' => ['club_setup'],
    'opponent_bulk_delete.php' => ['club_setup'],
    'opponent_delete.php' => ['club_setup'],
    'opponents.php' => ['club_setup'],
    'season.php' => ['club_setup'],
    'season_delete.php' => ['club_setup'],
    'seasons.php' => ['club_setup'],
    'venue.php' => ['club_setup'],
    'venue_delete.php' => ['club_setup'],
    'venues.php' => ['club_setup'],

    // Sponsorship
    'assign_sponsors.php' => ['sponsorship'],
    'match_sponsorship_delete.php' => ['sponsorship'],
    'match_sponsorship_move.php' => ['sponsorship'],
    'playersponsors_orders.php' => ['sponsorship'],
    'sponsor.php' => ['sponsorship'],
    'sponsor_followups.php' => ['sponsorship'],
    'sponsors.php' => ['sponsorship'],
    'sponsorship_agreement.php' => ['sponsorship'],
    'sponsorship_agreement_bulk.php' => ['sponsorship'],
    'sponsorship_agreements.php' => ['sponsorship'],
    'sponsorship_bundle.php' => ['sponsorship'],
    'sponsorship_bundles.php' => ['sponsorship'],
    'sponsorship_delete.php' => ['sponsorship'],
    'sponsorship_edit.php' => ['sponsorship'],
    'sponsorship_package.php' => ['sponsorship'],
    'sponsorship_packages.php' => ['sponsorship'],
    'sponsorship_type.php' => ['sponsorship'],
    'sponsorship_type_delete.php' => ['sponsorship'],
    'sponsorship_types.php' => ['sponsorship'],

    // Fundraising
    'hidden_team_game.php' => ['fundraising'],
    'hidden_team_games.php' => ['fundraising'],

    // Finance — payments not already covered above
    'match_payment_delete.php' => ['finance_manage', 'sponsorship_payments'],
    'payment_add.php' => ['finance_manage', 'sponsorship_payments'],
    'payment_edit.php' => ['finance_manage', 'sponsorship_payments'],

    // Ticketing & gate operations — includes the pages that used to sit on
    // the separate RBAC permission layer (lib/permissions.php); that layer
    // is retired in favour of this single capability, admin-only by default.
    'fixture_tickets.php' => ['tickets_ops'],
    'pos_overview.php' => ['tickets_ops'],
    'season_ticket_free_codes.php' => ['tickets_ops'],
    'season_ticket_order.php' => ['tickets_ops'],
    'season_ticket_renewals.php' => ['tickets_ops'],
    'season_ticket_type.php' => ['tickets_ops'],
    'season_ticket_types.php' => ['tickets_ops'],
    'ticket_packages.php' => ['tickets_ops'],
    'complimentary_admission.php' => ['tickets_refund_comp'],
    'access_review.php' => ['admin_settings'],
    'fixture_ticketing_dashboard.php' => ['tickets_ops'],
    'scan_overview.php' => ['tickets_ops'],
    'season_pass_rules.php' => ['tickets_ops'],
    'season_ticket_orders.php' => ['tickets_ops'],
    'ticket_orders.php' => ['tickets_ops'],
    'match_ticket_scan.php' => ['tickets_ops'],

    // Club shop
    'shop_categories.php' => ['shop'],
    'shop_discount_codes.php' => ['shop'],
    'shop_modifiers.php' => ['shop'],
    'shop_order.php' => ['shop'],
    'shop_order_new.php' => ['shop'],
    'shop_orders.php' => ['shop'],
    'shop_overview.php' => ['shop'],
    'shop_product.php' => ['shop'],
    'shop_products.php' => ['shop'],
    'shop_settings.php' => ['shop'],
    'shop_vsn_order_form_pdf.php' => ['shop'],

    // Site administration — previously admin-only by omission (not in this
    // map at all); now a normal, grantable capability, still admin-only by
    // default (see access_template_capabilities seed data).
    'club_people.php' => ['admin_settings'],
    'club_person.php' => ['admin_settings'],
    'positions.php' => ['admin_settings'],
    'access_roles.php' => ['admin_settings'],
    'access_role_edit.php' => ['admin_settings'],
    'settings.php' => ['admin_settings'],
    'user_edit.php' => ['admin_settings'],
    'user_delete.php' => ['admin_settings'],

    // Public website — was admin-only by omission, now its own capability
    // (still admin-only by default; see access_template_capabilities seed data).
    'news.php' => ['website'],
    'news_edit.php' => ['website'],
    'club_pages.php' => ['website'],
    'settings_public.php' => ['website'],

    // Secretary & club administration
    'secretary_dashboard.php' => ['secretary_ops'],
    'discipline_register.php' => ['secretary_ops'],
    'discipline_incident.php' => ['secretary_ops'],
    'discipline_incident_delete.php' => ['secretary_ops'],
    'player_registrations.php' => ['secretary_ops'],
    'player_registration_edit.php' => ['secretary_ops'],
    'secretary_tasks.php' => ['secretary_ops'],
    'secretary_task_edit.php' => ['secretary_ops'],
    'secretary_correspondence.php' => ['secretary_ops'],
    'secretary_correspondence_edit.php' => ['secretary_ops'],
    'fixture_change_requests.php' => ['secretary_ops'],
    'fixture_change_request_edit.php' => ['secretary_ops'],
    'committee_meetings.php' => ['secretary_ops'],
    'committee_meeting_edit.php' => ['secretary_ops'],
    'committee_meeting_delete.php' => ['secretary_ops'],
    'secretary_documents.php' => ['secretary_ops'],
    'secretary_document_download.php' => ['secretary_ops'],
    'secretary_document_delete.php' => ['secretary_ops'],
    'secretary_guide.php' => ['secretary_ops'],

    // Publishing
    'league_table.php' => ['publishing'],
    'template_packs.php' => ['publishing'],

    // Graphics, posters, media library, social — content & social
    'match_fixtures_poster.php' => ['content_social'],
    'match_graphics.php' => ['content_social'],
    'match_media.php' => ['content_social'],
    'match_photos.php' => ['content_social'],
    'match_poster.php' => ['content_social'],
    'match_starting_11_graphic.php' => ['content_social'],
    'media.php' => ['content_social'],
    'people.php' => ['content_social'],
    'player_photos.php' => ['content_social'],
    'player_photo_review.php' => ['content_social'],
    'player_graphics.php' => ['content_social'],
    'player_photos_bulk.php' => ['content_social'],
    'sponsor_graphics.php' => ['content_social'],
    'sponsor_wall.php' => ['content_social'],

    // reports.php is gated per report-type (see reports.php's own
    // $reportTypeCapability map) rather than one capability for the whole
    // page — deliberately not listed here, and covered separately by
    // HUB_PAGE_GATE_EXEMPT below.
];

/**
 * Pages header.php's volunteer/staff default-deny gate must NOT block on
 * "not in HUB_PAGE_CAPABILITIES", because something else already grants or
 * enforces access correctly:
 *
 * - index.php is the shared dashboard landing page every authenticated
 *   account should reach, capability-gated or not.
 * - reports.php gates itself per report-type internally (see its own
 *   $reportTypeCapability map) rather than as one page-level capability.
 * - developer*.php pages gate themselves with hub_auth_is_developer(), which
 *   is stricter than admin and resolves to the configured Colin/developer
 *   email only. The blanket capability gate must not run first.
 *
 * The ticketing pages that used to be exempted here (complimentary_admission,
 * fixture_ticketing_dashboard, scan_overview, season_pass_rules,
 * season_ticket_orders, ticket_orders) now go through the same tickets_ops
 * capability as everything else in HUB_PAGE_CAPABILITIES above, so they no
 * longer need an exemption.
 *
 * @var list<string>
 */
const HUB_PAGE_GATE_EXEMPT = [
    'profile.php', // Signed-in users edit only the person linked to their own session.
    'index.php',
    'reports.php',
    'developer.php',
    'developer_analytics.php',
    'developer_audit.php',
    'developer_db.php',
    'developer_errors.php',
    'developer_roles.php',
    'developer_sponsorships.php',
];

/**
 * Which section of People & Users' "Club Roles & Committee" a position's
 * holders group under. Distinct from Site Admin, which is the account-level
 * "Site Administrator" flag (independent of any position).
 *
 * @var array<string, string>
 */
const HUB_POSITION_DEPARTMENTS = [
    'committee' => 'Committee',
    'football' => 'Football Department',
    'other' => 'Other Roles',
];

function ensureHubPositionsSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    // club_roles was renamed club_roles (migration 2026_09_25_001); the
    // schema is managed by migrations, so there is nothing to ensure.
    $done = true;
}

/**
 * Capability slugs a position grants — resolved through its linked access
 * role (see access_template_id / access_roles.php's Roles & Capabilities grid),
 * not a capabilities list of the position's own. A position with no linked
 * role (e.g. Committee Member, Volunteer) grants nothing.
 *
 * @return list<string>
 */
function hub_position_capabilities(PDO $pdo, array $position): array
{
    $accessRoleId = (int) ($position['access_template_id'] ?? 0);
    if ($accessRoleId <= 0) {
        return [];
    }
    return getAccessRoleCapabilitySlugs($pdo, $accessRoleId);
}

/**
 * @return list<array<string, mixed>>
 */
function getHubPositions(PDO $pdo): array
{
    ensureHubPositionsSchema($pdo);
    $stmt = $pdo->query('SELECT * FROM club_roles ORDER BY sort_order, name');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getHubPosition(PDO $pdo, int $id): ?array
{
    ensureHubPositionsSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM club_roles WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $position = $stmt->fetch(PDO::FETCH_ASSOC);
    return $position ?: null;
}

/**
 * @param array{name: string, department?: string, access_role_id?: int|null, sort_order?: int} $data
 */
function saveHubPosition(PDO $pdo, ?int $id, array $data): int
{
    ensureHubPositionsSchema($pdo);

    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('A position name is required.');
    }

    $department = (string) ($data['department'] ?? 'other');
    if (!array_key_exists($department, HUB_POSITION_DEPARTMENTS)) {
        $department = 'other';
    }
    $accessRoleId = (int) ($data['access_role_id'] ?? 0);
    $accessRoleId = $accessRoleId > 0 ? $accessRoleId : null;
    $sortOrder = (int) ($data['sort_order'] ?? 0);

    if ($id === null) {
        $stmt = $pdo->prepare('INSERT INTO club_roles (name, department, access_template_id, sort_order) VALUES (:name, :department, :access_role_id, :sort_order)');
        $stmt->execute([':name' => $name, ':department' => $department, ':access_role_id' => $accessRoleId, ':sort_order' => $sortOrder]);
        $newId = (int) $pdo->lastInsertId();
        auditLog($pdo, 'position_created', 'Created position #' . $newId . ' (' . $name . ')');
        return $newId;
    }

    $stmt = $pdo->prepare('UPDATE club_roles SET name = :name, department = :department, access_template_id = :access_role_id, sort_order = :sort_order WHERE id = :id');
    $stmt->execute([':name' => $name, ':department' => $department, ':access_role_id' => $accessRoleId, ':sort_order' => $sortOrder, ':id' => $id]);
    auditLog($pdo, 'position_updated', 'Updated position #' . $id . ' (' . $name . ')');
    return $id;
}

/**
 * @return array{ok: bool, error?: string}
 */
function deleteHubPosition(PDO $pdo, int $id): array
{
    ensureHubPositionsSchema($pdo);

    $inUse = $pdo->prepare('SELECT COUNT(*) FROM person_club_roles WHERE club_role_id = :id');
    $inUse->execute([':id' => $id]);
    if ((int) $inUse->fetchColumn() > 0) {
        return ['ok' => false, 'error' => 'This position is still assigned to one or more accounts. Reassign them first.'];
    }

    $position = getHubPosition($pdo, $id);
    $pdo->prepare('DELETE FROM club_roles WHERE id = :id')->execute([':id' => $id]);
    auditLog($pdo, 'position_deleted', 'Deleted position #' . $id . ' (' . (string) ($position['name'] ?? '') . ')');
    return ['ok' => true];
}
