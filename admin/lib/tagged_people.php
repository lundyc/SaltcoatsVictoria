<?php
// lib/tagged_people.php — the registry of everyone who can be tagged in a match photo:
// squad players (kept in sync with the `players` table, reusing their existing avatar
// and action shots as face-match reference photos) plus anyone else — manager, staff,
// fans, sponsors and others — who has their own reference photos in tagged_people_photos.

declare(strict_types=1);

const TAGGED_PEOPLE_CATEGORIES = [
    'manager' => 'Manager',
    'coach' => 'Coaches',
    'committee_volunteer' => 'Committee / Volunteers',
    'player' => 'Players',
    'fan' => 'Fans',
    'sponsor' => 'Sponsors',
    'other' => 'Others',
];

// Display-only split of the 'player' category, used wherever tagged people are
// grouped into a "Person" picker. Doesn't correspond to a real tagged_people.category
// value — a player's group is derived from their linked players row (see
// tagged_people_group_key()).
const TAGGED_PEOPLE_GROUP_LABELS = [
    'current_player' => 'Current Players',
    'past_player' => 'Past Players',
];

function tagged_people_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $column = $pdo->query("SHOW COLUMNS FROM tagged_people LIKE 'category'")->fetch(PDO::FETCH_ASSOC);
    $type = strtolower((string) ($column['Type'] ?? ''));
    $hasCurrentCategories = str_contains($type, "'coach'")
        && str_contains($type, "'committee_volunteer'")
        && str_contains($type, "'sponsor'");

    if (!$hasCurrentCategories) {
        $pdo->exec("
            ALTER TABLE tagged_people
            MODIFY category ENUM('manager','staff','coach','committee_volunteer','player','fan','sponsor','other') NOT NULL DEFAULT 'other'
        ");
        $pdo->exec("UPDATE tagged_people SET category = 'coach' WHERE category = 'staff'");
        $pdo->exec("
            ALTER TABLE tagged_people
            MODIFY category ENUM('manager','coach','committee_volunteer','player','fan','sponsor','other') NOT NULL DEFAULT 'other'
        ");
    }

    $done = true;
}

/**
 * Makes sure every row in `players` has a matching `tagged_people` row (category
 * 'player'), and keeps the name in sync if a player is renamed. Idempotent and cheap
 * enough to call on every page load — there's no separate "provisioning" step.
 */
function tagged_people_sync_players(PDO $pdo): void
{
    tagged_people_ensure_schema($pdo);
    $pdo->exec("
        INSERT INTO tagged_people (name, category, player_id)
        SELECT p.name, 'player', p.id
        FROM players p
        LEFT JOIN tagged_people tp ON tp.player_id = p.id
        WHERE tp.id IS NULL
    ");
    $pdo->exec("
        UPDATE tagged_people tp
        JOIN players p ON p.id = tp.player_id
        SET tp.name = p.name
        WHERE tp.name <> p.name
    ");
}

/**
 * @return list<array{id:int, name:string, category:string, player_id:?int, player_status:?string, player_active:?int}>
 */
function tagged_people_all(PDO $pdo): array
{
    tagged_people_sync_players($pdo);
    return $pdo->query("
        SELECT tp.id, tp.name, tp.category, tp.player_id, p.status AS player_status, p.active AS player_active
        FROM tagged_people tp
        LEFT JOIN players p ON p.id = tp.player_id
        ORDER BY FIELD(tp.category, 'manager', 'coach', 'committee_volunteer', 'player', 'fan', 'sponsor', 'other'), tp.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * The group a person belongs to in a "Person" picker: every non-player category
 * stays as-is, while players split into 'current_player' / 'past_player' based on
 * their linked players row — mirroring the current/former split used on the
 * Players page and the club overview.
 *
 * @param array{category:string, player_status?:?string, player_active?:?int} $person
 */
function tagged_people_group_key(array $person): string
{
    if ($person['category'] !== 'player') {
        return $person['category'];
    }
    $status = (string) ($person['player_status'] ?? '');
    $active = (int) ($person['player_active'] ?? 1);
    $isCurrent = $active === 1 && in_array($status, ['current', 'trialist'], true);
    return $isCurrent ? 'current_player' : 'past_player';
}

function tagged_people_group_label(string $groupKey): string
{
    return TAGGED_PEOPLE_GROUP_LABELS[$groupKey] ?? TAGGED_PEOPLE_CATEGORIES[$groupKey] ?? ucfirst($groupKey);
}

/**
 * Groups people (as returned by tagged_people_all()) for a "Person" picker, in a
 * fixed display order — current players before past players, regardless of name
 * sort order within the 'player' category.
 *
 * @param list<array{category:string, player_status?:?string, player_active?:?int}> $people
 * @return array<string, list<array>>
 */
function tagged_people_grouped(array $people): array
{
    $byGroup = [];
    foreach ($people as $person) {
        $byGroup[tagged_people_group_key($person)][] = $person;
    }
    $order = ['manager', 'coach', 'committee_volunteer', 'current_player', 'past_player', 'fan', 'sponsor', 'other'];
    $grouped = [];
    foreach ($order as $groupKey) {
        if (!empty($byGroup[$groupKey])) {
            $grouped[$groupKey] = $byGroup[$groupKey];
        }
    }
    return $grouped;
}

/**
 * Builds the face-match reference photo set for every taggable person: players'
 * avatar + action shots, everyone else's uploaded reference photos, and — the
 * richest source, since it grows every time someone tags a match photo — any
 * match photo that has exactly one person tagged in it.
 *
 * Photos with more than one tag are deliberately excluded: we don't store *where*
 * in the photo each tagged person's face is (manual tags never ran face detection
 * at all), so there's no reliable way to know which detected face belongs to which
 * tag in a group shot. Using the wrong face as someone's reference would actively
 * hurt matching rather than help it, so those photos are skipped as references
 * (they're still tagged and shown normally everywhere else).
 *
 * @return list<array{player_id:int, path:string}> "player_id" is the wire-format key
 *   expected by hub/tools/face_match.py — here it actually carries a tagged_people.id.
 */
function tagged_people_build_references(PDO $pdo, string $playersUploadDir, string $peopleUploadDir, string $matchesUploadDir = ''): array
{
    tagged_people_sync_players($pdo);
    $references = [];

    $avatarStmt = $pdo->query("
        SELECT tp.id AS tagged_person_id, p.avatar
        FROM tagged_people tp
        JOIN players p ON p.id = tp.player_id
        WHERE p.avatar IS NOT NULL AND p.avatar <> ''
    ");
    foreach ($avatarStmt as $row) {
        $path = $playersUploadDir . '/' . basename((string) $row['avatar']);
        if (is_file($path)) {
            $references[] = ['player_id' => (int) $row['tagged_person_id'], 'path' => $path];
        }
    }

    $actionShotStmt = $pdo->query("
        SELECT tp.id AS tagged_person_id, pas.filename
        FROM player_action_shots pas
        JOIN tagged_people tp ON tp.player_id = pas.player_id
    ");
    foreach ($actionShotStmt as $row) {
        $path = $playersUploadDir . '/action_shots/' . basename((string) $row['filename']);
        if (is_file($path)) {
            $references[] = ['player_id' => (int) $row['tagged_person_id'], 'path' => $path];
        }
    }

    $peoplePhotoStmt = $pdo->query('SELECT tagged_person_id, filename FROM tagged_people_photos');
    foreach ($peoplePhotoStmt as $row) {
        $path = $peopleUploadDir . '/' . basename((string) $row['filename']);
        if (is_file($path)) {
            $references[] = ['player_id' => (int) $row['tagged_person_id'], 'path' => $path];
        }
    }

    if ($matchesUploadDir !== '') {
        $matchPhotoStmt = $pdo->query("
            SELECT mpt.tagged_person_id, mp.match_fixture_id, mp.filename
            FROM match_photo_tags mpt
            JOIN match_photos mp ON mp.id = mpt.match_photo_id
            WHERE mpt.match_photo_id IN (
                SELECT match_photo_id FROM match_photo_tags GROUP BY match_photo_id HAVING COUNT(*) = 1
            )
        ");
        foreach ($matchPhotoStmt as $row) {
            $path = $matchesUploadDir . '/' . (int) $row['match_fixture_id'] . '/' . basename((string) $row['filename']);
            if (is_file($path)) {
                $references[] = ['player_id' => (int) $row['tagged_person_id'], 'path' => $path];
            }
        }
    }

    return $references;
}
