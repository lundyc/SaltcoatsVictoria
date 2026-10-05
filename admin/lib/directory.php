<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function player_sponsors_directory_pdo(): ?PDO
{
    static $pdo = null;
    static $initialized = false;

    if ($initialized) {
        return $pdo;
    }

    $initialized = true;

    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    } catch (Throwable) {
        $pdo = null;
    }

    return $pdo;
}

function player_sponsors_directory_format_datetime(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format(DATE_ATOM);
    } catch (Throwable) {
        return $value;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function player_sponsors_directory_players(): array
{
    $pdo = player_sponsors_directory_pdo();
    if (!$pdo) {
        return [];
    }

    $sql = "
        SELECT
            p.id,
            p.name,
            p.status,
            p.active,
            p.avatar,
            p.date_of_birth,
            p.created_at,
            p.joined_at,
            p.left_at,
            GROUP_CONCAT(
                DISTINCT sp.name
                ORDER BY FIELD(UPPER(s.slot), 'HOME', 'AWAY', 'THIRD')
                SEPARATOR '|'
            ) AS sponsor_name,
            GROUP_CONCAT(
                DISTINCT sp.id
                ORDER BY FIELD(UPPER(s.slot), 'HOME', 'AWAY', 'THIRD')
                SEPARATOR '|'
            ) AS sponsor_ids
        FROM players p
        LEFT JOIN sponsorships s
            ON s.player_id = p.id
           AND s.ended_at IS NULL
        LEFT JOIN sponsors sp
            ON sp.id = s.sponsor_id
        GROUP BY p.id, p.name, p.status, p.active, p.avatar, p.date_of_birth, p.created_at, p.joined_at, p.left_at
        ORDER BY p.active DESC, p.name ASC
    ";

    $rows = $pdo->query($sql)->fetchAll();
    $players = [];

    foreach ($rows as $row) {
        $players[] = [
            'source_id' => (string) ($row['id'] ?? ''),
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'position' => 'Unknown',
            'dob' => trim((string) ($row['date_of_birth'] ?? '')),
            'joined_date' => trim((string) ($row['joined_at'] ?? '')),
            'released_date' => trim((string) ($row['left_at'] ?? '')),
            'status' => (string) ($row['status'] ?? ''),
            'active' => (int) ($row['active'] ?? 0),
            'image' => trim((string) ($row['avatar'] ?? '')),
            'is_captain' => false,
            'sponsor_name' => trim((string) ($row['sponsor_name'] ?? '')),
            'sponsor_url' => '',
            'created_at' => player_sponsors_directory_format_datetime((string) ($row['created_at'] ?? '')),
            'updated_at' => player_sponsors_directory_format_datetime((string) ($row['created_at'] ?? '')),
        ];
    }

    return $players;
}

/**
 * @return list<array<string, mixed>>
 */
function player_sponsors_directory_sponsors(): array
{
    $pdo = player_sponsors_directory_pdo();
    if (!$pdo) {
        return [];
    }

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM sponsors') as $column) {
        $columns[(string) ($column['Field'] ?? '')] = true;
    }
    $socialSelect = [
        isset($columns['facebook_page_url']) ? 'facebook_page_url' : "'' AS facebook_page_url",
        isset($columns['facebook_page_id']) ? 'facebook_page_id' : "'' AS facebook_page_id",
        isset($columns['facebook_page_name']) ? 'facebook_page_name' : "'' AS facebook_page_name",
        isset($columns['instagram_url']) ? 'instagram_url' : "'' AS instagram_url",
        isset($columns['twitter_url']) ? 'twitter_url' : "'' AS twitter_url",
    ];
    $rows = $pdo->query(
        'SELECT id, name, is_active, created_at, ' . implode(', ', $socialSelect)
        . ' FROM sponsors ORDER BY name ASC'
    )->fetchAll();
    $sponsors = [];

    foreach ($rows as $row) {
        $sponsors[] = [
            'source_id' => (string) ($row['id'] ?? ''),
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'type' => 'club',
            'tier' => 'standard',
            'status' => !empty($row['is_active']) ? 'active' : 'inactive',
            'logo' => '',
            'website_url' => '',
            'facebook_page_url' => trim((string) ($row['facebook_page_url'] ?? '')),
            'facebook_page_id' => trim((string) ($row['facebook_page_id'] ?? '')),
            'facebook_page_name' => trim((string) ($row['facebook_page_name'] ?? '')),
            'instagram_url' => trim((string) ($row['instagram_url'] ?? '')),
            'twitter_url' => trim((string) ($row['twitter_url'] ?? '')),
            'contact_name' => '',
            'contact_email' => '',
            'contact_phone' => '',
            'tags' => [],
            'notes' => '',
            'is_active' => !empty($row['is_active']),
            'created_at' => player_sponsors_directory_format_datetime((string) ($row['created_at'] ?? '')),
            'updated_at' => player_sponsors_directory_format_datetime((string) ($row['created_at'] ?? '')),
        ];
    }

    return $sponsors;
}
