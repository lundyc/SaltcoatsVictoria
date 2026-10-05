<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/directory.php';

const SPONSORS_DATA_FILE = __DIR__ . '/data/sponsors.json';

/**
 * @return list<array<string, mixed>>
 */
function sponsors_load_local_json(): array
{
    if (!is_file(SPONSORS_DATA_FILE)) {
        return [];
    }

    $json = file_get_contents(SPONSORS_DATA_FILE);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    $sponsors = [];
    foreach ($data as $item) {
        if (is_array($item)) {
            $sponsors[] = sponsors_normalize($item);
        }
    }

    return $sponsors;
}

function sponsors_index_by_name(array $sponsors): array
{
    $index = [];
    foreach ($sponsors as $sponsor) {
        $name = strtolower(trim((string) ($sponsor['name'] ?? '')));
        if ($name !== '' && !isset($index[$name])) {
            $index[$name] = $sponsor;
        }
    }

    return $index;
}

function sponsors_index_by_source_id(array $sponsors): array
{
    $index = [];
    foreach ($sponsors as $sponsor) {
        $sourceId = strtolower(trim((string) ($sponsor['source_id'] ?? ($sponsor['id'] ?? ''))));
        if ($sourceId !== '' && !isset($index[$sourceId])) {
            $index[$sourceId] = $sponsor;
        }
    }

    return $index;
}
const SPONSOR_ASSIGNMENTS_DATA_FILE = __DIR__ . '/data/sponsor_assignments.json';
const SPONSOR_PLACEMENTS_DATA_FILE = __DIR__ . '/data/sponsor_placements.json';
const SPONSORS_UPLOAD_DIR = __DIR__ . '/uploads/sponsors';

/**
 * @return list<array<string, mixed>>
 */
function sponsors_load_all(): array
{
    $remoteSponsors = player_sponsors_directory_sponsors();
    $localSponsors = sponsors_load_local_json();

    if ($remoteSponsors === []) {
        return $localSponsors;
    }

    $localBySourceId = sponsors_index_by_source_id($localSponsors);
    $localByName = sponsors_index_by_name($localSponsors);
    $sponsors = [];

    foreach ($remoteSponsors as $sponsor) {
        $sourceId = strtolower(trim((string) ($sponsor['source_id'] ?? ($sponsor['id'] ?? ''))));
        $nameKey = strtolower(trim((string) ($sponsor['name'] ?? '')));
        $local = null;
        if ($sourceId !== '' && isset($localBySourceId[$sourceId])) {
            $local = $localBySourceId[$sourceId];
        } elseif ($nameKey !== '' && isset($localByName[$nameKey])) {
            $local = $localByName[$nameKey];
        }

        $sponsors[] = sponsors_normalize([
            'source_id' => (string) ($sponsor['source_id'] ?? ($local['source_id'] ?? '')),
            'id' => (string) ($sponsor['id'] ?? ($local['id'] ?? '')),
            'name' => (string) ($sponsor['name'] ?? ($local['name'] ?? '')),
            'type' => (string) ($local['type'] ?? ($sponsor['type'] ?? 'club')),
            'tier' => (string) ($local['tier'] ?? ($sponsor['tier'] ?? 'standard')),
            'status' => (string) ($sponsor['status'] ?? ($local['status'] ?? 'active')),
            'logo' => (string) ($local['logo'] ?? ''),
            'website_url' => (string) ($local['website_url'] ?? ''),
            'facebook_page_url' => (string) ($sponsor['facebook_page_url'] ?? ($local['facebook_page_url'] ?? '')),
            'facebook_page_id' => (string) ($sponsor['facebook_page_id'] ?? ($local['facebook_page_id'] ?? '')),
            'facebook_page_name' => (string) ($sponsor['facebook_page_name'] ?? ($local['facebook_page_name'] ?? '')),
            'instagram_url' => (string) ($sponsor['instagram_url'] ?? ($local['instagram_url'] ?? '')),
            'twitter_url' => (string) ($sponsor['twitter_url'] ?? ($local['twitter_url'] ?? '')),
            'contact_name' => (string) ($local['contact_name'] ?? ''),
            'contact_email' => (string) ($local['contact_email'] ?? ''),
            'contact_phone' => (string) ($local['contact_phone'] ?? ''),
            'tags' => $local['tags'] ?? [],
            'notes' => (string) ($local['notes'] ?? ''),
            'created_at' => (string) ($sponsor['created_at'] ?? ($local['created_at'] ?? '')),
            'updated_at' => (string) ($sponsor['updated_at'] ?? ($local['updated_at'] ?? '')),
        ]);
    }

    usort($sponsors, static function (array $a, array $b): int {
        if ((bool) ($a['is_active'] ?? false) !== (bool) ($b['is_active'] ?? false)) {
            return !empty($a['is_active']) ? -1 : 1;
        }

        if ((string) ($a['type'] ?? '') !== (string) ($b['type'] ?? '')) {
            return strcmp((string) ($a['type'] ?? ''), (string) ($b['type'] ?? ''));
        }

        if ((string) ($a['tier'] ?? '') !== (string) ($b['tier'] ?? '')) {
            return strcmp((string) ($a['tier'] ?? ''), (string) ($b['tier'] ?? ''));
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $sponsors;
}

/**
 * @param list<array<string, mixed>> $sponsors
 */
function sponsors_save_all(array $sponsors): bool
{
    if (!is_dir(dirname(SPONSORS_DATA_FILE))) {
        @mkdir(dirname(SPONSORS_DATA_FILE), 0775, true);
    }

    $json = json_encode(array_values($sponsors), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents(SPONSORS_DATA_FILE, $json . PHP_EOL, LOCK_EX) !== false;
}

/**
 * @param array<string, mixed> $sponsor
 * @return array<string, mixed>
 */
function sponsors_normalize(array $sponsor): array
{
    $type = sponsors_normalize_type(isset($sponsor['type']) ? (string) $sponsor['type'] : null);
    $status = sponsors_normalize_status(isset($sponsor['status']) ? (string) $sponsor['status'] : null);

    return [
        'source_id' => trim((string) ($sponsor['source_id'] ?? '')),
        'id' => trim((string) ($sponsor['id'] ?? '')),
        'name' => trim((string) ($sponsor['name'] ?? '')),
        'type' => $type,
        'tier' => sponsors_normalize_tier(isset($sponsor['tier']) ? (string) $sponsor['tier'] : null),
        'status' => $status,
        'logo' => trim((string) ($sponsor['logo'] ?? '')),
        'website_url' => trim((string) ($sponsor['website_url'] ?? '')),
        'facebook_page_url' => trim((string) ($sponsor['facebook_page_url'] ?? '')),
        'facebook_page_id' => trim((string) ($sponsor['facebook_page_id'] ?? '')),
        'facebook_page_name' => trim((string) ($sponsor['facebook_page_name'] ?? '')),
        'instagram_url' => trim((string) ($sponsor['instagram_url'] ?? '')),
        'twitter_url' => trim((string) ($sponsor['twitter_url'] ?? '')),
        'contact_name' => trim((string) ($sponsor['contact_name'] ?? '')),
        'contact_email' => trim((string) ($sponsor['contact_email'] ?? '')),
        'contact_phone' => trim((string) ($sponsor['contact_phone'] ?? '')),
        'tags' => sponsors_normalize_tags($sponsor['tags'] ?? []),
        'notes' => trim((string) ($sponsor['notes'] ?? '')),
        'is_active' => $status === 'active',
        'created_at' => trim((string) ($sponsor['created_at'] ?? '')),
        'updated_at' => trim((string) ($sponsor['updated_at'] ?? '')),
    ];
}

function sponsors_generate_id(): string
{
    return bin2hex(random_bytes(8));
}

function sponsors_normalize_type(?string $type): string
{
    $type = strtolower(trim((string) $type));

    return array_key_exists($type, sponsors_type_options()) ? $type : 'club';
}

function sponsors_normalize_tier(?string $tier): string
{
    $tier = strtolower(trim((string) $tier));

    return array_key_exists($tier, sponsors_tier_options()) ? $tier : 'standard';
}

function sponsors_normalize_status(?string $status): string
{
    $status = strtolower(trim((string) $status));

    return array_key_exists($status, sponsors_status_options()) ? $status : 'active';
}

/**
 * @param mixed $tags
 * @return list<string>
 */
function sponsors_normalize_tags($tags): array
{
    if (is_string($tags)) {
        $tags = preg_split('/\s*,\s*/', $tags) ?: [];
    }

    if (!is_array($tags)) {
        return [];
    }

    $values = [];
    foreach ($tags as $tag) {
        $normalized = trim((string) $tag);
        if ($normalized === '' || in_array($normalized, $values, true)) {
            continue;
        }
        $values[] = $normalized;
    }

    return $values;
}

/**
 * @return array<string, string>
 */
function sponsors_type_options(): array
{
    return [
        'club' => 'Club Sponsor',
        'stadium' => 'Stadium Board',
        'player' => 'Player Sponsor',
        'match' => 'Match Sponsor',
    ];
}

/**
 * @return array<string, string>
 */
function sponsors_tier_options(): array
{
    return [
        'principal' => 'Principal',
        'premium' => 'Premium',
        'standard' => 'Standard',
        'community' => 'Community',
    ];
}

/**
 * @return array<string, string>
 */
function sponsors_status_options(): array
{
    return [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'archived' => 'Archived',
    ];
}

/**
 * @param list<array<string, mixed>> $sponsors
 * @return array<string, mixed>|null
 */
function sponsors_find_by_id(array $sponsors, string $id): ?array
{
    foreach ($sponsors as $sponsor) {
        if ((string) ($sponsor['id'] ?? '') === $id) {
            return $sponsor;
        }
    }

    return null;
}

/**
 * @return array{path: string, error: string}
 */
function sponsors_handle_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['path' => '', 'error' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['path' => '', 'error' => 'Logo upload failed.'];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['path' => '', 'error' => 'Uploaded logo could not be validated.'];
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        return ['path' => '', 'error' => 'Please upload a valid logo image.'];
    }

    $mime = (string) ($imageInfo['mime'] ?? '');
    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($extensionMap[$mime])) {
        return ['path' => '', 'error' => 'Only JPG, PNG, WebP, or GIF logos are allowed.'];
    }

    if (!is_dir(SPONSORS_UPLOAD_DIR) && !@mkdir(SPONSORS_UPLOAD_DIR, 0775, true) && !is_dir(SPONSORS_UPLOAD_DIR)) {
        return ['path' => '', 'error' => 'The sponsor upload directory could not be created.'];
    }

    $filename = 'sponsor-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensionMap[$mime];
    $destination = SPONSORS_UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        return ['path' => '', 'error' => 'The uploaded logo could not be saved.'];
    }

    return ['path' => 'uploads/sponsors/' . $filename, 'error' => ''];
}

function sponsors_delete_logo(?string $path): void
{
    $path = trim((string) $path);
    if ($path === '' || !str_starts_with($path, 'uploads/sponsors/')) {
        return;
    }

    $absolutePath = __DIR__ . '/' . $path;
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

/**
 * @param list<array<string, mixed>> $sponsors
 * @return array{club: list<array<string, mixed>>, stadium: list<array<string, mixed>>, player: list<array<string, mixed>>, match: list<array<string, mixed>>}
 */
function sponsors_group_by_type(array $sponsors): array
{
    return [
        'club' => array_values(array_filter($sponsors, static fn(array $sponsor): bool => ($sponsor['type'] ?? '') === 'club')),
        'stadium' => array_values(array_filter($sponsors, static fn(array $sponsor): bool => ($sponsor['type'] ?? '') === 'stadium')),
        'player' => array_values(array_filter($sponsors, static fn(array $sponsor): bool => ($sponsor['type'] ?? '') === 'player')),
        'match' => array_values(array_filter($sponsors, static fn(array $sponsor): bool => ($sponsor['type'] ?? '') === 'match')),
    ];
}

/**
 * @param list<array<string, mixed>> $sponsors
 * @return list<array<string, mixed>>
 */
function sponsors_active_by_type(array $sponsors, string $type): array
{
    return array_values(array_filter($sponsors, static function (array $sponsor) use ($type): bool {
        return ($sponsor['type'] ?? '') === $type && !empty($sponsor['is_active']);
    }));
}

function sponsors_assignment_store_ready(): bool
{
    return is_file(SPONSOR_ASSIGNMENTS_DATA_FILE);
}

function sponsors_placement_store_ready(): bool
{
    return is_file(SPONSOR_PLACEMENTS_DATA_FILE);
}
