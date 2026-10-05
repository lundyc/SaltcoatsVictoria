<?php

declare(strict_types=1);

/**
 * Affiliations: how a person relates to the club (fan, season-ticket holder,
 * committee, football staff, volunteer, life member …). These are LABELS. They
 * never grant Hub access — that comes only from access templates and
 * individual overrides (lib/access.php).
 *
 * Derived, never stored twice:
 *   committee / football staff / volunteer  <- current club roles (person_club_roles)
 *   season-ticket holder                    <- a live order in the current season
 * Stored (person_tags), only where nothing else knows the answer:
 *   life member, sponsor contact, player.
 * Fan is the baseline: shown when nothing else applies.
 *
 * Not to be confused with person_relationships, which links a guardian to a
 * dependant.
 */

/** Manual tags a person can carry, tag => label. */
const PERSON_TAG_CATALOG = [
    'player' => 'Player',
    'life_member' => 'Life member',
    'sponsor' => 'Sponsor contact',
];

/**
 * @param list<int> $personIds
 * @return array<int, list<array{key: string, label: string, detail: string}>>
 */
function person_affiliations_bulk(PDO $pdo, array $personIds): array
{
    $personIds = array_values(array_unique(array_map('intval', $personIds)));
    $out = array_fill_keys($personIds, []);
    if ($personIds === []) {
        return $out;
    }
    $in = implode(',', $personIds); // ints only

    // Current club roles -> committee / football / volunteer.
    $rows = $pdo->query(
        "SELECT pcr.person_id, cr.name, cr.department
         FROM person_club_roles pcr
         JOIN club_roles cr ON cr.id = pcr.club_role_id
         LEFT JOIN seasons s ON s.id = pcr.season_id
         WHERE pcr.person_id IN ({$in})
           AND COALESCE(pcr.start_date, s.start_date, DATE(pcr.assigned_at)) <= CURDATE()
           AND (COALESCE(pcr.end_date, s.end_date) IS NULL OR COALESCE(pcr.end_date, s.end_date) >= CURDATE())
         ORDER BY cr.sort_order, cr.name"
    )->fetchAll(PDO::FETCH_ASSOC);
    $byKey = [];
    foreach ($rows as $row) {
        $isVolunteer = strcasecmp((string) $row['name'], 'Volunteer') === 0;
        $key = $isVolunteer ? 'volunteer' : match ((string) $row['department']) {
            'committee' => 'committee',
            'football' => 'football',
            default => 'volunteer',
        };
        $byKey[(int) $row['person_id']][$key][] = (string) $row['name'];
    }
    $labels = ['committee' => 'Committee', 'football' => 'Football staff', 'volunteer' => 'Volunteer'];
    foreach ($byKey as $personId => $keys) {
        foreach ($labels as $key => $label) {
            if (isset($keys[$key])) {
                $out[$personId][] = ['key' => $key, 'label' => $label, 'detail' => implode(', ', $keys[$key])];
            }
        }
    }

    // Season-ticket holder: a live (not cancelled) order in the current season.
    $rows = $pdo->query(
        "SELECT DISTINCT m.person_id, s.name AS season
         FROM identity_migration_map m
         JOIN season_ticket_orders o ON o.holder_id = m.old_holder_id
         JOIN seasons s ON s.id = o.season_id AND s.is_current = 1
         WHERE m.person_id IN ({$in}) AND o.cancelled_at IS NULL AND o.status <> 'cancelled'"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $out[(int) $row['person_id']][] = ['key' => 'season_ticket_holder', 'label' => 'Season ticket holder', 'detail' => (string) $row['season']];
    }

    // Manual tags.
    $rows = $pdo->query("SELECT person_id, tag FROM person_tags WHERE person_id IN ({$in}) ORDER BY tag")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        if (isset(PERSON_TAG_CATALOG[$row['tag']])) {
            $out[(int) $row['person_id']][] = ['key' => (string) $row['tag'], 'label' => PERSON_TAG_CATALOG[$row['tag']], 'detail' => ''];
        }
    }

    // Baseline.
    foreach ($out as $personId => $list) {
        if ($list === []) {
            $out[$personId][] = ['key' => 'fan', 'label' => 'Fan', 'detail' => ''];
        }
    }
    return $out;
}

/** @return list<array{key: string, label: string, detail: string}> */
function person_affiliations(PDO $pdo, int $personId): array
{
    return person_affiliations_bulk($pdo, [$personId])[$personId] ?? [];
}

/** @return list<string> the manual tag keys a person carries */
function person_tag_keys(PDO $pdo, int $personId): array
{
    $stmt = $pdo->prepare('SELECT tag FROM person_tags WHERE person_id = :p ORDER BY tag');
    $stmt->execute([':p' => $personId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Replace a person's manual tags (unknown tags ignored). */
function set_person_tags(PDO $pdo, int $personId, array $tags): void
{
    $tags = array_values(array_intersect(array_map('strval', $tags), array_keys(PERSON_TAG_CATALOG)));
    $pdo->prepare('DELETE FROM person_tags WHERE person_id = :p')->execute([':p' => $personId]);
    $insert = $pdo->prepare('INSERT IGNORE INTO person_tags (person_id, tag) VALUES (:p, :t)');
    foreach ($tags as $tag) {
        $insert->execute([':p' => $personId, ':t' => $tag]);
    }
    if (function_exists('identityAuditLog')) {
        identityAuditLog($pdo, 'person_tags_changed', 'Updated tags for person #' . $personId . ': ' . ($tags === [] ? 'none' : implode(', ', $tags)));
    }
}
