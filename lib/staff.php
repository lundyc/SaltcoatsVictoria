<?php

declare(strict_types=1);

/*
 * Public people data from person_club_roles × club_roles × people.
 *   - pub_staff_management()  -> football department  (shown on /staff)
 *   - pub_staff_officials()   -> committee department  (shown on /club/officials)
 * The `other` department (volunteers etc.) is shown by pub_staff_volunteers() on
 * /club/officials only; it never appears in the committee or management lists.
 */

/** Rough seniority ordering within a department. */
function pub_staff_rank(string $position): int
{
    $order = [
        'chairman' => 0, 'vice chairman' => 1, 'secretary' => 2, 'assistant secretary' => 3, 'treasurer' => 4,
        'manager' => 0, 'assistant manager' => 1, 'coach' => 2, 'goalkeeping coach' => 3,
    ];
    return $order[strtolower($position)] ?? 50;
}

/**
 * Current holders of positions in the given department.
 * @return list<array{name:string,position:string,label:string,image:string}>
 */
function pub_staff_in_department(string $department): array
{
    $stmt = db()->prepare(
        "SELECT p.display_name, p.profile_image_path, hp.name AS position, hp.sort_order
         FROM person_club_roles pp
         JOIN club_roles hp ON hp.id = pp.club_role_id
         JOIN people p ON p.id = pp.person_id
         WHERE hp.department = :dept
           AND (pp.end_date IS NULL OR pp.end_date >= CURDATE())
           AND (pp.start_date IS NULL OR pp.start_date <= CURDATE())
           AND p.is_active = 1
         ORDER BY hp.sort_order ASC"
    );
    $stmt->execute([':dept' => $department]);
    $people = [];
    foreach ($stmt->fetchAll() as $r) {
        $position = (string) $r['position'];
        // Public wording: the hub position is "Secretary"; the club presents it as below.
        $label = match (strtolower($position)) {
            'secretary' => 'Club / Match Secretary',
            'assistant secretary' => 'Club / Match Assistant Secretary',
            default => $position,
        };
        $people[] = ['name' => (string) $r['display_name'], 'position' => $position, 'label' => $label,
            'image' => trim((string) ($r['profile_image_path'] ?? ''))];
    }
    // The same person holding the same role on two overlapping records (e.g. an
    // open-ended one and a season one) must show once, not twice.
    $seen = [];
    $people = array_values(array_filter($people, static function (array $p) use (&$seen): bool {
        $key = strtolower($p['name'] . '|' . $p['position']);
        return isset($seen[$key]) ? false : ($seen[$key] = true);
    }));
    usort($people, static fn ($a, $b) => pub_staff_rank($a['position']) <=> pub_staff_rank($b['position'])
        ?: strcmp($a['name'], $b['name']));
    return $people;
}

/** Management team — the football department (manager, coaches …). */
function pub_staff_management(): array
{
    return pub_staff_in_department('football');
}

/** Club officials — the committee (chairman, secretary, treasurer …). */
function pub_staff_officials(): array
{
    return pub_staff_in_department('committee');
}

/** Inner HTML for a staff card's round photo: the profile picture, else the initial. */
function pub_staff_photo_html(array $person): string
{
    $image = (string) ($person['image'] ?? '');
    if ($image !== '' && is_file(PUBLIC_ROOT . '/' . ltrim($image, '/'))) {
        return '<img src="' . e('/' . ltrim($image, '/')) . '" alt="' . e((string) $person['name']) . '" loading="lazy">';
    }
    return '<span aria-hidden="true">' . e(mb_strtoupper(mb_substr((string) $person['name'], 0, 1))) . '</span>';
}

/**
 * Volunteers — current holders of `other` department roles, one card per person
 * with their specific roles joined (just "Volunteer" if that is all they hold).
 * People already shown on the committee are left out so nobody appears twice.
 * @param list<string> $excludeNames
 * @return list<array{name:string,label:string,image:string}>
 */
function pub_staff_volunteers(array $excludeNames = []): array
{
    $stmt = db()->prepare(
        "SELECT p.id, p.display_name, p.profile_image_path, hp.name AS role, hp.sort_order
         FROM person_club_roles pp
         JOIN club_roles hp ON hp.id = pp.club_role_id
         JOIN people p ON p.id = pp.person_id
         WHERE hp.department = 'other'
           AND (pp.end_date IS NULL OR pp.end_date >= CURDATE())
           AND (pp.start_date IS NULL OR pp.start_date <= CURDATE())
           AND p.is_active = 1
         ORDER BY hp.sort_order ASC, hp.name ASC"
    );
    $stmt->execute();
    $skip = array_map('strtolower', $excludeNames);
    $by = [];
    foreach ($stmt->fetchAll() as $r) {
        $name = (string) $r['display_name'];
        if (in_array(strtolower($name), $skip, true)) {
            continue;
        }
        $by[(int) $r['id']] ??= ['name' => $name, 'roles' => [], 'image' => trim((string) ($r['profile_image_path'] ?? ''))];
        $by[(int) $r['id']]['roles'][(string) $r['role']] = true;
    }
    $out = [];
    foreach ($by as $v) {
        $roles = array_keys($v['roles']);
        $specific = array_values(array_filter($roles, static fn (string $x): bool => strtolower($x) !== 'volunteer'));
        $out[] = ['name' => $v['name'], 'label' => $specific !== [] ? implode(' · ', $specific) : 'Volunteer', 'image' => $v['image']];
    }
    usort($out, static fn ($a, $b) => strcmp($a['name'], $b['name']));
    return $out;
}

/**
 * Club roles nobody holds right now, for the Vacancies page. A role with no current
 * holder is listed automatically unless it is marked "never list as a vacancy".
 * @return list<array{id:int,name:string,department:string,blurb:string}>
 */
function pub_staff_vacancies(): array
{
    try {
        $rows = db()->query(
            "SELECT r.id, r.name, r.department, r.vacancy_blurb
             FROM club_roles r
             WHERE r.vacancy_hidden = 0
               AND NOT EXISTS (
                   SELECT 1 FROM person_club_roles pp
                   JOIN people p ON p.id = pp.person_id AND p.is_active = 1
                   WHERE pp.club_role_id = r.id
                     AND (pp.end_date IS NULL OR pp.end_date >= CURDATE())
                     AND (pp.start_date IS NULL OR pp.start_date <= CURDATE())
               )
             ORDER BY FIELD(r.department, 'committee', 'football', 'other'), r.sort_order, r.name"
        )->fetchAll();
    } catch (Throwable) {
        return [];
    }
    return array_map(static fn (array $r): array => [
        'id' => (int) $r['id'],
        'name' => (string) $r['name'],
        'department' => (string) $r['department'],
        'blurb' => trim((string) ($r['vacancy_blurb'] ?? '')),
    ], $rows);
}
