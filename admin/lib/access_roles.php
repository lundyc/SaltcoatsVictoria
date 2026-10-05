<?php

declare(strict_types=1);

require_once __DIR__ . '/people.php'; // for identityAuditLog()

/**
 * WordPress-style roles & capabilities — CRUD helpers for the
 * access_templates / capabilities / access_template_capabilities /
 * person_access_templates tables added by
 * database/migrations/2026_09_14_001_access_roles_capabilities.php.
 *
 * @return list<array<string, mixed>>
 */
function getCapabilitiesCatalog(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM capabilities ORDER BY sort_order, label')->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return list<array<string, mixed>>
 */
function getAccessRoles(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM access_templates ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
}

function getAccessRole(PDO $pdo, int $roleId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM access_templates WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $roleId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Capability slugs granted to $roleId (ignores bypass_all — callers that
 * need "does this role effectively have X" should check bypass_all
 * themselves first, same as hub_auth_has_capability() does).
 *
 * @return list<string>
 */
function getAccessRoleCapabilitySlugs(PDO $pdo, int $roleId): array
{
    $stmt = $pdo->prepare('SELECT c.slug
        FROM access_template_capabilities arc
        JOIN capabilities c ON c.id = arc.capability_id
        WHERE arc.template_id = :role_id');
    $stmt->execute([':role_id' => $roleId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Replaces the full set of capabilities granted to a role — the save
 * handler for one column of the Roles & Capabilities grid.
 *
 * @param list<int> $capabilityIds
 */
function setAccessRoleCapabilities(PDO $pdo, int $roleId, array $capabilityIds): void
{
    $pdo->prepare('DELETE FROM access_template_capabilities WHERE template_id = :role_id')->execute([':role_id' => $roleId]);
    if ($capabilityIds === []) {
        return;
    }
    $insert = $pdo->prepare('INSERT IGNORE INTO access_template_capabilities (template_id, capability_id) VALUES (:role_id, :capability_id)');
    foreach ($capabilityIds as $capabilityId) {
        $insert->execute([':role_id' => $roleId, ':capability_id' => (int) $capabilityId]);
    }
    identityAuditLog($pdo, 'access_template_capabilities_changed', 'Updated capability grants for access role #' . $roleId);
}

function createAccessRole(PDO $pdo, string $name): int
{
    $slug = access_role_slugify($pdo, $name);
    $maxSort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM access_templates')->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO access_templates (slug, name, bypass_all, is_system, sort_order) VALUES (:slug, :name, 0, 0, :sort_order)');
    $stmt->execute([':slug' => $slug, ':name' => $name, ':sort_order' => $maxSort + 10]);
    $roleId = (int) $pdo->lastInsertId();
    identityAuditLog($pdo, 'access_role_created', 'Created access role "' . $name . '" (#' . $roleId . ')');
    return $roleId;
}

/**
 * System roles (Administrator, Staff, Volunteer) keep their fixed name —
 * only a custom role's name can be changed. Returns false without renaming
 * if $roleId is a system role, doesn't exist, or $name is blank.
 */
function renameAccessRole(PDO $pdo, int $roleId, string $name): bool
{
    $name = trim($name);
    $role = getAccessRole($pdo, $roleId);
    if ($role === null || (int) $role['is_system'] === 1 || $name === '') {
        return false;
    }
    $pdo->prepare('UPDATE access_templates SET name = :name WHERE id = :id')->execute([':name' => $name, ':id' => $roleId]);
    identityAuditLog($pdo, 'access_role_renamed', 'Renamed access role #' . $roleId . ' to "' . $name . '"');
    return true;
}

/**
 * How many people directly hold $roleId (person_access_templates), plus how many
 * committee positions link to it (club_roles.access_template_id) — shown on
 * the Roles & Capabilities list so an admin can see what's using a role
 * before deleting it.
 *
 * @return array{people: int, positions: int}
 */
function getAccessRoleUsageCounts(PDO $pdo, int $roleId): array
{
    $peopleStmt = $pdo->prepare('SELECT COUNT(*) FROM person_access_templates WHERE template_id = :role_id');
    $peopleStmt->execute([':role_id' => $roleId]);

    $positionsStmt = $pdo->prepare('SELECT COUNT(*) FROM club_roles WHERE access_template_id = :role_id');
    $positionsStmt->execute([':role_id' => $roleId]);

    return [
        'people' => (int) $peopleStmt->fetchColumn(),
        'positions' => (int) $positionsStmt->fetchColumn(),
    ];
}

/**
 * System roles (Administrator, Staff, Volunteer) can't be deleted — they
 * back the base account role used for login itself, not just capability
 * delegation. A role still assigned to a person or linked from a committee
 * position can't be deleted either (reassign them first, same rule
 * deleteHubPosition() already applies to positions).
 *
 * @return array{ok: bool, error?: string}
 */
function deleteAccessRole(PDO $pdo, int $roleId): array
{
    $role = getAccessRole($pdo, $roleId);
    if ($role === null) {
        return ['ok' => false, 'error' => 'That role no longer exists.'];
    }
    if ((int) $role['is_system'] === 1) {
        return ['ok' => false, 'error' => 'System roles (Administrator, Staff, Volunteer) can\'t be deleted.'];
    }
    $usage = getAccessRoleUsageCounts($pdo, $roleId);
    if ($usage['people'] > 0 || $usage['positions'] > 0) {
        return ['ok' => false, 'error' => 'This role is still assigned to ' . $usage['people'] . ' people or linked from ' . $usage['positions'] . ' position(s). Reassign them first.'];
    }
    $pdo->prepare('DELETE FROM access_templates WHERE id = :id')->execute([':id' => $roleId]);
    identityAuditLog($pdo, 'access_role_deleted', 'Deleted access role "' . $role['name'] . '" (#' . $roleId . ')');
    return ['ok' => true];
}

function access_role_slugify(PDO $pdo, string $name): string
{
    $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $name), '_'));
    if ($base === '') {
        $base = 'role';
    }
    $slug = $base;
    $suffix = 2;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM access_templates WHERE slug = :slug');
    while (true) {
        $stmt->execute([':slug' => $slug]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '_' . $suffix;
        $suffix++;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function getPersonAccessRoles(PDO $pdo, int $personId): array
{
    $stmt = $pdo->prepare('SELECT ar.*
        FROM person_access_templates par
        JOIN access_templates ar ON ar.id = par.template_id
        WHERE par.person_id = :person_id
        ORDER BY ar.sort_order, ar.name');
    $stmt->execute([':person_id' => $personId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Replaces the full set of access roles assigned to a person — the save
 * handler for club_person.php's "Access roles" panel.
 *
 * @param list<int> $roleIds
 */
function setPersonAccessRoles(PDO $pdo, int $personId, array $roleIds): void
{
    $pdo->prepare('DELETE FROM person_access_templates WHERE person_id = :person_id')->execute([':person_id' => $personId]);
    $insert = $pdo->prepare('INSERT IGNORE INTO person_access_templates (person_id, template_id) VALUES (:person_id, :role_id)');
    foreach ($roleIds as $roleId) {
        $insert->execute([':person_id' => $personId, ':role_id' => (int) $roleId]);
    }
    identityAuditLog($pdo, 'person_access_templates_changed', 'Updated access roles for person #' . $personId);
}


/**
 * A person's individual overrides — an extra grant or an explicit removal of one
 * capability on top of their access templates, with an optional expiry.
 *
 * @return list<array<string, mixed>>
 */
function getPersonAccessOverrides(PDO $pdo, int $personId): array
{
    $stmt = $pdo->prepare('SELECT o.id, o.effect, o.expires_at, o.note, o.created_at,
            c.id AS capability_id, c.slug, c.label,
            (o.expires_at IS NOT NULL AND o.expires_at < CURDATE()) AS is_expired
        FROM person_access_overrides o
        JOIN capabilities c ON c.id = o.capability_id
        WHERE o.person_id = :person_id
        ORDER BY c.sort_order, c.label');
    $stmt->execute([':person_id' => $personId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Add or replace the override for one capability (one per person + capability). */
function setPersonAccessOverride(PDO $pdo, int $personId, int $capabilityId, string $effect, ?string $expiresAt, string $note, ?int $byPersonId = null): void
{
    if (!in_array($effect, ['grant', 'deny'], true)) {
        throw new InvalidArgumentException('Invalid override type.');
    }
    $expiresAt = $expiresAt !== null && trim($expiresAt) !== '' ? trim($expiresAt) : null;
    if ($expiresAt !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt)) {
        throw new InvalidArgumentException('Expiry must be a date.');
    }
    $stmt = $pdo->prepare('SELECT slug FROM capabilities WHERE id = :id');
    $stmt->execute([':id' => $capabilityId]);
    $slug = $stmt->fetchColumn();
    if ($slug === false) {
        throw new InvalidArgumentException('Unknown permission.');
    }
    $pdo->prepare('INSERT INTO person_access_overrides (person_id, capability_id, effect, expires_at, note, created_by_person_id)
        VALUES (:person, :cap, :effect, :expires, :note, :by)
        ON DUPLICATE KEY UPDATE effect = VALUES(effect), expires_at = VALUES(expires_at), note = VALUES(note), created_by_person_id = VALUES(created_by_person_id)')
        ->execute([
            ':person' => $personId, ':cap' => $capabilityId, ':effect' => $effect, ':expires' => $expiresAt,
            ':note' => mb_substr(trim($note), 0, 255) ?: null, ':by' => $byPersonId,
        ]);
    identityAuditLog($pdo, 'person_access_override_set', ($effect === 'grant' ? 'Granted extra' : 'Removed') . " '{$slug}' for person #{$personId}" . ($expiresAt ? " until {$expiresAt}" : ''));
}

function removePersonAccessOverride(PDO $pdo, int $personId, int $overrideId): void
{
    $pdo->prepare('DELETE FROM person_access_overrides WHERE id = :id AND person_id = :person')
        ->execute([':id' => $overrideId, ':person' => $personId]);
    identityAuditLog($pdo, 'person_access_override_removed', "Removed access override #{$overrideId} for person #{$personId}");
}
