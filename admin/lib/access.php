<?php

declare(strict_types=1);

/**
 * The access core: one place that answers "may this person do X in the Hub?".
 *
 * Two separate ideas, deliberately kept apart:
 *   - Club roles (Treasurer, Volunteer, Manager …) are labels. They grant
 *     nothing on their own.
 *   - Access is granted per person from exactly two sources:
 *       1. Access templates (person_access_templates) — a live link to a named
 *          bundle of capabilities. Edit the template and everyone on it changes.
 *       2. Individual overrides (person_access_overrides) — an extra grant or an
 *          explicit denial of one capability, with an optional expiry date.
 *
 *   effective = union(template capabilities) + granted overrides − denied overrides
 *   A template with bypass_all (Administrator) gets every capability and cannot
 *   be narrowed by a denial; nor can an account whose role is admin.
 *
 * Club roles grant nothing. ACCESS_LEGACY_POSITION_GRANTS (now false) is the old
 * behaviour where holding e.g. Treasurer also pulled in a template; it stays
 * only until the club_roles.access_template_id column is dropped.
 * "Admin" is not special either: it is the Administrator template (bypass_all).
 */

const ACCESS_LEGACY_POSITION_GRANTS = false;

/**
 * Capabilities that include a weaker one, so nobody can hold the powerful
 * right without the basic one (like WordPress: edit implies read). Applied
 * after grants and before denials, so an explicit denial still wins.
 */
const ACCESS_IMPLIES = [
    'finance_manage' => ['finance_view'],
    'sponsorship_payments' => ['sponsorship'],
    'tickets_refund_comp' => ['tickets_ops'],
    'tickets_ops' => ['tickets_scan'],
];

/**
 * Full explanation of one person's access: the capability list plus where each
 * capability comes from. $isAdminAccount is the account-level admin flag
 * (accounts role), passed in because it lives outside the tables read here.
 *
 * @return array{
 *   capabilities: list<string>,
 *   bypass: bool,
 *   sources: array<string, list<string>>,
 *   denied: list<string>
 * }
 */
function access_explain(PDO $pdo, int $personId, bool $isAdminAccount = false): array
{
    $all = $pdo->query('SELECT slug FROM capabilities ORDER BY slug')->fetchAll(PDO::FETCH_COLUMN);
    $sources = [];
    $bypass = $isAdminAccount;
    if ($isAdminAccount) {
        foreach ($all as $slug) {
            $sources[$slug][] = 'admin account';
        }
    }

    // 1. Templates the person is on (live link).
    $stmt = $pdo->prepare(
        'SELECT t.id, t.slug, t.bypass_all
         FROM person_access_templates pat
         JOIN access_templates t ON t.id = pat.template_id
         WHERE pat.person_id = :person'
    );
    $stmt->execute([':person' => $personId]);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $templateCaps = $pdo->prepare(
        'SELECT c.slug FROM access_template_capabilities atc
         JOIN capabilities c ON c.id = atc.capability_id
         WHERE atc.template_id = :template'
    );
    foreach ($templates as $template) {
        if ((int) $template['bypass_all'] === 1) {
            $bypass = true;
            foreach ($all as $slug) {
                $sources[$slug][] = 'template:' . $template['slug'];
            }
            continue;
        }
        $templateCaps->execute([':template' => (int) $template['id']]);
        foreach ($templateCaps->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            $sources[$slug][] = 'template:' . $template['slug'];
        }
    }

    // 2. TRANSITIONAL: a current club role that still points at a template.
    if (ACCESS_LEGACY_POSITION_GRANTS) {
        $stmt = $pdo->prepare(
            'SELECT cr.name, cr.access_template_id
             FROM person_club_roles pcr
             JOIN club_roles cr ON cr.id = pcr.club_role_id
             LEFT JOIN seasons s ON s.id = pcr.season_id
             WHERE pcr.person_id = :person
               AND cr.access_template_id IS NOT NULL
               AND COALESCE(pcr.start_date, s.start_date, DATE(pcr.assigned_at)) <= CURDATE()
               AND (COALESCE(pcr.end_date, s.end_date) IS NULL OR COALESCE(pcr.end_date, s.end_date) >= CURDATE())'
        );
        $stmt->execute([':person' => $personId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $templateCaps->execute([':template' => (int) $row['access_template_id']]);
            foreach ($templateCaps->fetchAll(PDO::FETCH_COLUMN) as $slug) {
                $sources[$slug][] = 'club role (legacy):' . $row['name'];
            }
        }
    }

    // Implied capabilities (manage => view, refund/comp => ticket ops).
    foreach (ACCESS_IMPLIES as $strong => $weakList) {
        if (isset($sources[$strong])) {
            foreach ($weakList as $weak) {
                $sources[$weak][] = 'implied by ' . $strong;
            }
        }
    }

    // 3. Individual overrides (unexpired only). Deny beats everything except bypass.
    $denied = [];
    $stmt = $pdo->prepare(
        'SELECT c.slug, o.effect
         FROM person_access_overrides o
         JOIN capabilities c ON c.id = o.capability_id
         WHERE o.person_id = :person
           AND (o.expires_at IS NULL OR o.expires_at >= CURDATE())'
    );
    $stmt->execute([':person' => $personId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['effect'] === 'grant') {
            $sources[$row['slug']][] = 'extra';
        } elseif (!$bypass) {
            $denied[] = $row['slug'];
        }
    }
    foreach ($denied as $slug) {
        unset($sources[$slug]);
    }

    foreach ($sources as &$list) {
        $list = array_values(array_unique($list));
        sort($list);
    }
    unset($list);
    ksort($sources);
    sort($denied);

    return [
        'capabilities' => array_keys($sources),
        'bypass' => $bypass,
        'sources' => $sources,
        'denied' => $denied,
    ];
}

/**
 * Per-request memo of access_explain() so a page that asks a dozen
 * can()-questions costs one set of queries, not a dozen.
 *
 * @return array{capabilities: list<string>, bypass: bool, sources: array<string, list<string>>, denied: list<string>}
 */
function access_effective(PDO $pdo, int $personId, bool $isAdminAccount = false): array
{
    static $memo = [];
    $key = $personId . ':' . ($isAdminAccount ? '1' : '0');
    return $memo[$key] ??= access_explain($pdo, $personId, $isAdminAccount);
}

/**
 * Effective access of the logged-in Hub user, or null when nobody is logged in.
 *
 * @return array{capabilities: list<string>, bypass: bool, sources: array<string, list<string>>, denied: list<string>}|null
 */
function access_current_effective(): ?array
{
    global $pdo;
    $user = hub_auth_current_user();
    if ($user === null) {
        return null;
    }
    $personId = (int) ($user['person_id'] ?? 0);
    if ($personId <= 0) {
        $personId = (int) (personIdFromLegacyHolderId($pdo, (int) ($user['id'] ?? 0)) ?? 0);
    }
    if ($personId <= 0) {
        return null;
    }
    return access_effective($pdo, $personId, false);
}

/** The one question every gated page asks. */
function access_can(string $capability): bool
{
    $effective = access_current_effective();
    return $effective !== null
        && ($effective['bypass'] || in_array($capability, $effective['capabilities'], true));
}

/** True for anyone on a bypass template (Administrator). */
function access_is_admin(): bool
{
    $effective = access_current_effective();
    return $effective !== null && $effective['bypass'];
}
