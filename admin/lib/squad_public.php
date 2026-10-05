<?php

declare(strict_types=1);

/**
 * Public squad data: the subset of `players` that has opted in to the website
 * (player_website_photos.uploaded_to_website = 1), plus the website-only
 * profile fields added to `players` here.
 *
 * Schema additions live here so both the Hub player editor and the public site
 * self-heal; the 2026_09_06 migration is the formal record.
 */

function squad_public_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM players') as $row) {
        $columns[(string) $row['Field']] = true;
    }

    if (!isset($columns['squad_number'])) {
        $pdo->exec('ALTER TABLE players ADD COLUMN squad_number SMALLINT UNSIGNED NULL AFTER position');
    }
    if (!isset($columns['nationality'])) {
        $pdo->exec("ALTER TABLE players ADD COLUMN nationality VARCHAR(80) NOT NULL DEFAULT '' AFTER date_of_birth");
    }
    if (!isset($columns['bio'])) {
        $pdo->exec('ALTER TABLE players ADD COLUMN bio TEXT NULL AFTER nationality');
    }
    if (!isset($columns['website_slug'])) {
        $pdo->exec('ALTER TABLE players ADD COLUMN website_slug VARCHAR(140) NULL');
        // Unique but nullable: many NULLs are allowed under InnoDB.
        try {
            $pdo->exec('ALTER TABLE players ADD UNIQUE KEY uq_players_website_slug (website_slug)');
        } catch (Throwable) {
            // Index may already exist from a previous partial run.
        }
    }

    $done = true;
}

/** GK / DEF / MID / FWD from the free-text players.position value. */
function squad_position_group(?string $position): string
{
    $p = strtolower((string) $position);
    $p = trim(preg_replace('/^[0-9]+/', '', $p) ?? $p); // strip the leading sort code
    return match (true) {
        str_contains($p, 'keeper') || $p === 'gk'        => 'GK',
        str_contains($p, 'forward') || str_contains($p, 'striker')
            || str_contains($p, 'winger') || str_contains($p, 'attack') => 'FWD',
        str_contains($p, 'midfield')                      => 'MID',
        str_contains($p, 'defen') || str_contains($p, 'back')
            || str_contains($p, 'full') || str_contains($p, 'centre-half') => 'DEF',
        default                                           => 'MID',
    };
}

function squad_position_label(?string $position): string
{
    $label = trim(preg_replace('/^[0-9]+\s*/', '', (string) $position) ?? '');
    return $label !== '' ? $label : [
        'GK' => 'Goalkeeper', 'DEF' => 'Defender', 'MID' => 'Midfielder', 'FWD' => 'Forward',
    ][squad_position_group($position)];
}

function squad_group_order(): array
{
    return ['GK' => 0, 'DEF' => 1, 'MID' => 2, 'FWD' => 3];
}

function squad_public_slug(string $name, int $id): string
{
    $slug = strtolower(trim(preg_replace('~[^a-z0-9]+~', '-', strtolower($name)) ?? ''));
    $slug = trim($slug, '-');
    return $slug === '' ? 'player-' . $id : $slug;
}

/**
 * Current, website-opted-in players. Sorted by position group then squad
 * number (nulls last) then name.
 * @return list<array<string,mixed>>
 */
function squad_public_players(PDO $pdo): array
{
    squad_public_ensure_schema($pdo);
    // Every current player is shown unless someone has explicitly toggled them
    // off in the Hub (player_website_photos.uploaded_to_website = 0). A player
    // with no row is shown (default on).
    $rows = $pdo->query(
        "SELECT p.id, p.name, p.position, p.squad_number, p.nationality, p.bio,
                p.avatar, p.date_of_birth, p.website_slug, p.joined_at
         FROM players p
         LEFT JOIN player_website_photos w ON w.player_id = p.id
         WHERE p.status = 'current'
           AND COALESCE(w.uploaded_to_website, 1) = 1
         ORDER BY p.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $order = squad_group_order();
    usort($rows, static function (array $a, array $b) use ($order): int {
        $ga = $order[squad_position_group($a['position'])] ?? 9;
        $gb = $order[squad_position_group($b['position'])] ?? 9;
        if ($ga !== $gb) {
            return $ga <=> $gb;
        }
        $na = $a['squad_number'] !== null ? (int) $a['squad_number'] : 999;
        $nb = $b['squad_number'] !== null ? (int) $b['squad_number'] : 999;
        if ($na !== $nb) {
            return $na <=> $nb;
        }
        return strcmp((string) $a['name'], (string) $b['name']);
    });

    foreach ($rows as &$r) {
        $r['group'] = squad_position_group($r['position']);
        $r['position_label'] = squad_position_label($r['position']);
        $r['slug'] = $r['website_slug'] ?: squad_public_slug((string) $r['name'], (int) $r['id']);
    }
    unset($r);

    return $rows;
}

/** @return array<string, list<array<string,mixed>>> keyed by GK/DEF/MID/FWD */
function squad_public_grouped(PDO $pdo): array
{
    $groups = ['GK' => [], 'DEF' => [], 'MID' => [], 'FWD' => []];
    foreach (squad_public_players($pdo) as $player) {
        $groups[$player['group']][] = $player;
    }
    return $groups;
}

function squad_public_find(PDO $pdo, string $slugOrId): ?array
{
    squad_public_ensure_schema($pdo);

    // Fast path: an explicit website_slug or a numeric id.
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.position, p.squad_number, p.nationality, p.bio,
                p.avatar, p.date_of_birth, p.website_slug, p.joined_at
         FROM players p
         LEFT JOIN player_website_photos w ON w.player_id = p.id
         WHERE p.status = 'current'
           AND COALESCE(w.uploaded_to_website, 1) = 1
           AND (p.website_slug = :s OR p.id = :id)
         LIMIT 1"
    );
    $stmt->execute([':s' => $slugOrId, ':id' => ctype_digit($slugOrId) ? (int) $slugOrId : 0]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // Fallback: match the slug auto-generated from the player's name (players
    // shown by default have no stored website_slug).
    if (!$row) {
        foreach (squad_public_players($pdo) as $player) {
            if ($player['slug'] === $slugOrId) {
                return $player;
            }
        }
        return null;
    }

    $row['group'] = squad_position_group($row['position']);
    $row['position_label'] = squad_position_label($row['position']);
    $row['slug'] = $row['website_slug'] ?: squad_public_slug((string) $row['name'], (int) $row['id']);
    return $row;
}

function squad_age(?string $dob): ?int
{
    $dob = trim((string) $dob);
    if ($dob === '' || $dob === '0000-00-00') {
        return null;
    }
    try {
        return (new DateTimeImmutable($dob))->diff(new DateTimeImmutable('today'))->y;
    } catch (Throwable) {
        return null;
    }
}
