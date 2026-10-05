<?php

declare(strict_types=1);

const SOCIAL_DIRECTORY_PLAYERS_FILE = __DIR__ . '/../data/players.json';
const SOCIAL_DIRECTORY_SPONSORS_FILE = __DIR__ . '/../data/sponsors.json';

/**
 * @return list<array<string, mixed>>
 */
function social_directory_load_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $rows = [];
    foreach ($decoded as $row) {
        if (is_array($row)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function social_directory_public_path(?string $value, string $prefix): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }

    if (str_starts_with($value, '/')) {
        return $value;
    }

    return rtrim($prefix, '/') . '/' . ltrim($value, '/');
}

function social_directory_player_status(string $status): array
{
    $status = strtolower(trim($status));

    if (in_array($status, ['released', 'trial_ended'], true)) {
        return ['status' => 'left', 'active' => 0];
    }

    if (in_array($status, ['trial', 'trialist'], true)) {
        return ['status' => 'trialist', 'active' => 1];
    }

    return ['status' => 'current', 'active' => 1];
}

/**
 * @return list<array<string, mixed>>
 */
function social_directory_load_players(): array
{
    $players = [];

    foreach (social_directory_load_json(SOCIAL_DIRECTORY_PLAYERS_FILE) as $row) {
        $status = social_directory_player_status((string) ($row['status'] ?? ''));
        $players[] = [
            'source_id' => (string) ($row['id'] ?? ''),
            'name' => trim((string) ($row['name'] ?? '')),
            'avatar' => social_directory_public_path((string) ($row['image'] ?? ''), ''),
            'status' => $status['status'],
            'active' => $status['active'],
            'joined_at' => trim((string) ($row['joined_date'] ?? '')) ?: null,
            'left_at' => trim((string) ($row['released_date'] ?? '')) ?: null,
            'date_of_birth' => trim((string) ($row['dob'] ?? '')) ?: null,
            'position' => trim((string) ($row['position'] ?? '')),
            'is_captain' => !empty($row['is_captain']),
            'created_at' => trim((string) ($row['created_at'] ?? '')),
            'updated_at' => trim((string) ($row['updated_at'] ?? '')),
        ];
    }

    return $players;
}

/**
 * @return list<array<string, mixed>>
 */
function social_directory_load_sponsors(): array
{
    $sponsors = [];

    foreach (social_directory_load_json(SOCIAL_DIRECTORY_SPONSORS_FILE) as $row) {
        $sponsors[] = [
            'source_id' => (string) ($row['id'] ?? ''),
            'name' => trim((string) ($row['name'] ?? '')),
            'type' => trim((string) ($row['type'] ?? '')),
            'tier' => trim((string) ($row['tier'] ?? '')),
            'status' => trim((string) ($row['status'] ?? '')),
            'is_active' => !empty($row['is_active']),
            'logo' => social_directory_public_path((string) ($row['logo'] ?? ''), ''),
            'website_url' => trim((string) ($row['website_url'] ?? '')),
            'contact_name' => trim((string) ($row['contact_name'] ?? '')),
            'contact_email' => trim((string) ($row['contact_email'] ?? '')),
            'contact_phone' => trim((string) ($row['contact_phone'] ?? '')),
            'tags' => is_array($row['tags'] ?? null) ? array_values(array_filter(array_map('strval', $row['tags']), 'strlen')) : [],
            'notes' => trim((string) ($row['notes'] ?? '')),
            'created_at' => trim((string) ($row['created_at'] ?? '')),
            'updated_at' => trim((string) ($row['updated_at'] ?? '')),
        ];
    }

    return $sponsors;
}
