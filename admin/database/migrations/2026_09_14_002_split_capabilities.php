<?php
declare(strict_types=1);

// Splits the original 7 bundled Roles & Capabilities rows into one per nav
// section (12 total), so each area can be granted/denied independently —
// e.g. Sponsorship separately from Fundraising, Match Day separately from
// Club setup, instead of both sharing one capability. See
// admin/lib/positions.php's HUB_CAPABILITIES/HUB_PAGE_CAPABILITIES for the
// new page-to-capability mapping this migration's grants must stay in sync
// with.
//
// Existing role grants are migrated forward so nobody's actual access
// changes: Treasurer (finance) also gets sponsorship + fundraising;
// Football Ops (football_ops) becomes matchday + club_setup; Content &
// Social (content_social) also gets publishing (league table, template
// packs moved out of content_social). The now-unused football_ops
// capability row is then removed (cascades its now-stale grant away).

return static function (PDO $pdo): void {
    $newCapabilities = [
        'matchday' => ['Match Day (fixtures, lineups, live matchday stats, players)', 15],
        'club_setup' => ['Club setup (seasons, opponents, competitions, venues, facilities)', 25],
        'sponsorship' => ['Sponsorship (sponsors, follow-ups, agreements, bundles, packages)', 35],
        'fundraising' => ['Fundraising (Hidden Team)', 45],
        'publishing' => ['Publishing (league table, template packs)', 65],
        'website' => ['Public website (news, club pages, website settings)', 75],
    ];
    $insertCapability = $pdo->prepare('INSERT INTO capabilities (slug, label, sort_order) VALUES (:slug, :label, :sort_order)
        ON DUPLICATE KEY UPDATE label = VALUES(label), sort_order = VALUES(sort_order)');
    foreach ($newCapabilities as $slug => [$label, $sort]) {
        $insertCapability->execute([':slug' => $slug, ':label' => $label, ':sort_order' => $sort]);
    }

    // Re-point the surviving capabilities' sort order so the grid reads in
    // the same order as the nav (finance/tickets_ops/shop/secretary_ops/
    // content_social/admin_settings already exist from the first migration).
    // finance's label narrows now that sponsorship/fundraising have split off.
    $pdo->prepare("UPDATE capabilities SET label = 'Finance (reports, matchday income, Stripe, refunds)' WHERE slug = 'finance'")->execute();

    $resort = [
        'finance' => 55,
        'tickets_ops' => 85,
        'shop' => 95,
        'secretary_ops' => 105,
        'content_social' => 115,
        'admin_settings' => 125,
    ];
    $updateSort = $pdo->prepare('UPDATE capabilities SET sort_order = :sort_order WHERE slug = :slug');
    foreach ($resort as $slug => $sort) {
        $updateSort->execute([':sort_order' => $sort, ':slug' => $slug]);
    }

    $migrateGrants = [
        'treasurer' => ['sponsorship', 'fundraising'],
        'football_ops' => ['matchday', 'club_setup'],
        'content_social' => ['publishing'],
    ];
    $grant = $pdo->prepare('INSERT IGNORE INTO access_role_capabilities (role_id, capability_id)
        SELECT ar.id, c.id FROM access_roles ar, capabilities c
        WHERE ar.slug = :role_slug AND c.slug = :capability_slug');
    foreach ($migrateGrants as $roleSlug => $capabilitySlugs) {
        foreach ($capabilitySlugs as $capabilitySlug) {
            $grant->execute([':role_slug' => $roleSlug, ':capability_slug' => $capabilitySlug]);
        }
    }

    // The Football Ops role's slug stays as-is (it's the role's identity,
    // not tied to the capability of the same old name) — only the now-dead
    // 'football_ops' capability row goes, taking its stale grant with it via
    // the access_role_capabilities FK's ON DELETE CASCADE.
    $pdo->prepare("DELETE FROM capabilities WHERE slug = 'football_ops'")->execute();
};
