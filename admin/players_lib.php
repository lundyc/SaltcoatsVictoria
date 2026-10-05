<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/directory.php';

const PLAYERS_DATA_FILE = __DIR__ . '/data/players.json';
const PLAYERS_MASTER_UPLOAD_DIR = __DIR__ . '/uploads/players';

/**
 * @return list<array<string, mixed>>
 */
function players_load_local_json(): array
{
    if (!is_file(PLAYERS_DATA_FILE)) {
        return [];
    }

    $json = file_get_contents(PLAYERS_DATA_FILE);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [];
    }

    $players = [];
    foreach ($data as $item) {
        if (is_array($item)) {
            $players[] = players_normalize($item);
        }
    }

    return $players;
}

function players_index_by_name(array $players): array
{
    $index = [];
    foreach ($players as $player) {
        $name = strtolower(trim((string) ($player['name'] ?? '')));
        if ($name !== '' && !isset($index[$name])) {
            $index[$name] = $player;
        }
    }

    return $index;
}

function players_index_by_id(array $players): array
{
    $index = [];
    foreach ($players as $player) {
        $id = strtolower(trim((string) ($player['id'] ?? '')));
        if ($id !== '' && !isset($index[$id])) {
            $index[$id] = $player;
        }
    }

    return $index;
}

function players_index_by_source_id(array $players): array
{
    $index = [];
    foreach ($players as $player) {
        $sourceId = strtolower(trim((string) ($player['source_id'] ?? ($player['id'] ?? ''))));
        if ($sourceId !== '' && !isset($index[$sourceId])) {
            $index[$sourceId] = $player;
        }
    }

    return $index;
}

function players_master_pdo(): ?PDO
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

/**
 * @return array{status: string, active: int, left_at: string}
 */
function players_master_sync_state(array $player): array
{
    $status = players_normalize_status((string) ($player['status'] ?? 'current'));
    $joinedDate = trim((string) ($player['joined_date'] ?? ''));
    $releasedDate = trim((string) ($player['released_date'] ?? ''));

    if ($status === 'left') {
        return [
            'status' => 'left',
            'active' => 0,
            'left_at' => $releasedDate !== '' ? $releasedDate : date('Y-m-d'),
        ];
    }

    return [
        'status' => $status,
        'active' => 1,
        'left_at' => '',
    ];
}

function players_social_status_from_master(array $player, array $local = []): string
{
    $localStatus = players_normalize_status((string) ($local['status'] ?? ''));
    if (in_array($localStatus, ['trial', 'trial_ended'], true)) {
        return 'current';
    }

    $masterStatus = strtolower(trim((string) ($player['status'] ?? '')));
    $active = (int) ($player['active'] ?? 0);

    if (in_array($masterStatus, ['current', 'trialist', 'left', 'retired', 'loan', 'injured'], true)) {
        return $masterStatus;
    }

    if ($masterStatus === '' && $active === 0) {
        return 'left';
    }

    return $active === 0 ? 'left' : 'current';
}

function players_master_avatar_filename(?string $imagePath): string
{
    $imagePath = trim((string) $imagePath);
    if ($imagePath === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $imagePath) || str_starts_with($imagePath, '//')) {
        return '';
    }

    return basename($imagePath);
}

function players_master_avatar_source_path(string $imagePath): string
{
    $imagePath = trim($imagePath);
    if ($imagePath === '') {
        return '';
    }

    if (str_starts_with($imagePath, '/uploads/players/')) {
        return __DIR__ . '/uploads/players/' . basename($imagePath);
    }

    if (str_starts_with($imagePath, 'uploads/players/')) {
        return __DIR__ . '/' . $imagePath;
    }

    if (str_starts_with($imagePath, '/')) {
        return __DIR__ . '/' . ltrim($imagePath, '/');
    }

    return __DIR__ . '/uploads/players/' . basename($imagePath);
}

/**
 * @param array<string, mixed> $player
 * @return array{ok: bool, message: string, master_id?: string}
 */
function players_sync_master_player(array $player): array
{
    $pdo = players_master_pdo();
    if ($pdo === null) {
        return [
            'ok' => false,
            'message' => 'The master player database is unavailable.',
        ];
    }

    $name = trim((string) ($player['name'] ?? ''));
    if ($name === '') {
        return [
            'ok' => false,
            'message' => 'Player name is required for master sync.',
        ];
    }

    $state = players_master_sync_state($player);
    $avatarFilename = players_master_avatar_filename((string) ($player['image'] ?? ''));
    if ($avatarFilename !== '') {
        $sourcePath = players_master_avatar_source_path((string) ($player['image'] ?? ''));
        $targetPath = PLAYERS_MASTER_UPLOAD_DIR . '/' . $avatarFilename;
        if (is_file($sourcePath)) {
            if (!is_dir(PLAYERS_MASTER_UPLOAD_DIR) && !@mkdir(PLAYERS_MASTER_UPLOAD_DIR, 0775, true) && !is_dir(PLAYERS_MASTER_UPLOAD_DIR)) {
                return [
                    'ok' => false,
                    'message' => 'The master player upload directory could not be created.',
                ];
            }

            if (!is_file($targetPath) || md5_file($sourcePath) !== md5_file($targetPath)) {
                if (!@copy($sourcePath, $targetPath)) {
                    return [
                        'ok' => false,
                        'message' => 'The player image could not be copied to the master directory.',
                    ];
                }
            }
        }
    }

    $lookupId = trim((string) ($player['id'] ?? ''));
    $existing = null;
    if ($lookupId !== '' && ctype_digit($lookupId)) {
        $stmt = $pdo->prepare('SELECT id, avatar FROM players WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $lookupId]);
        $existing = $stmt->fetch();
    }

    if ($existing === null) {
        $stmt = $pdo->prepare('SELECT id, avatar FROM players WHERE name = :name LIMIT 1');
        $stmt->execute([':name' => $name]);
        $existing = $stmt->fetch();
    }

    $joinedDate = trim((string) ($player['joined_date'] ?? ''));

    if ($existing !== false && $existing !== null) {
        $updateSql = 'UPDATE players SET name = :name, status = :status, active = :active, joined_at = :joined_at, left_at = :left_at';
        $params = [
            ':name' => $name,
            ':status' => $state['status'],
            ':active' => $state['active'],
            ':joined_at' => $joinedDate !== '' ? $joinedDate : null,
            ':left_at' => $state['left_at'] !== '' ? $state['left_at'] : null,
            ':id' => (int) $existing['id'],
        ];

        if ($avatarFilename !== '') {
            $updateSql .= ', avatar = :avatar';
            $params[':avatar'] = $avatarFilename;
        }

        $updateSql .= ' WHERE id = :id';
        $stmt = $pdo->prepare($updateSql);
        $stmt->execute($params);

        return [
            'ok' => true,
            'message' => 'Master player record updated.',
            'master_id' => (string) $existing['id'],
        ];
    }

    $stmt = $pdo->prepare('
        INSERT INTO players (name, avatar, status, active, joined_at, left_at)
        VALUES (:name, :avatar, :status, :active, :joined_at, :left_at)
    ');
    $stmt->execute([
        ':name' => $name,
        ':avatar' => $avatarFilename,
        ':status' => $state['status'],
        ':active' => $state['active'],
        ':joined_at' => $joinedDate !== '' ? $joinedDate : null,
        ':left_at' => $state['left_at'] !== '' ? $state['left_at'] : null,
    ]);

    return [
        'ok' => true,
        'message' => 'Master player record created.',
        'master_id' => (string) $pdo->lastInsertId(),
    ];
}
const PLAYERS_UPLOAD_DIR = __DIR__ . '/uploads/players';

function players_public_image_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path) || str_starts_with($path, '//')) {
        return $path;
    }

    if (str_starts_with($path, '/')) {
        return $path;
    }

    if (str_starts_with($path, 'uploads/players/')) {
        return $path;
    }

    $filename = basename($path);
    $localFile = __DIR__ . '/uploads/players/' . $filename;
    if (is_file($localFile)) {
        return 'uploads/players/' . $filename;
    }

    $sharedFile = __DIR__ . '/uploads/players/' . $filename;
    if (is_file($sharedFile)) {
        return '/uploads/players/' . $filename;
    }

    return 'uploads/players/' . ltrim($path, '/');
}

/**
 * @return list<array<string, mixed>>
 */
function players_load_all(): array
{
    $remotePlayers = player_sponsors_directory_players();
    $localPlayers = players_load_local_json();

    if ($remotePlayers === []) {
        return $localPlayers;
    }

    $localById = players_index_by_id($localPlayers);
    $localBySourceId = players_index_by_source_id($localPlayers);
    $localByName = players_index_by_name($localPlayers);
    $players = [];

    foreach ($remotePlayers as $player) {
        $idKey = strtolower(trim((string) ($player['id'] ?? '')));
        $sourceIdKey = strtolower(trim((string) ($player['source_id'] ?? '')));
        $nameKey = strtolower(trim((string) ($player['name'] ?? '')));
        $local = null;
        if ($idKey !== '' && isset($localById[$idKey])) {
            $local = $localById[$idKey];
        } elseif ($sourceIdKey !== '' && isset($localBySourceId[$sourceIdKey])) {
            $local = $localBySourceId[$sourceIdKey];
        } elseif ($nameKey !== '' && isset($localByName[$nameKey])) {
            $local = $localByName[$nameKey];
        }

        $players[] = players_normalize([
            'source_id' => (string) ($player['source_id'] ?? ($local['source_id'] ?? '')),
            'id' => (string) ($player['id'] ?? ($local['id'] ?? '')),
            'name' => (string) ($player['name'] ?? ($local['name'] ?? '')),
            'position' => (string) ($local['position'] ?? ($player['position'] ?? 'Unknown')),
            'dob' => (string) ($local['dob'] ?? ($player['dob'] ?? '')),
            'joined_date' => (string) ($player['joined_date'] ?? ($local['joined_date'] ?? '')),
            'released_date' => (string) ($player['released_date'] ?? ($local['released_date'] ?? '')),
            'status' => players_social_status_from_master($player, $local),
            'image' => (string) ($player['image'] ?? ($local['image'] ?? '')),
            'is_captain' => !empty($local['is_captain']),
            'sponsor_name' => (string) ($local['sponsor_name'] ?? ($player['sponsor_name'] ?? '')),
            'sponsor_url' => (string) ($local['sponsor_url'] ?? ($player['sponsor_url'] ?? '')),
            'created_at' => (string) ($local['created_at'] ?? ($player['created_at'] ?? '')),
            'updated_at' => (string) ($local['updated_at'] ?? ($player['updated_at'] ?? '')),
        ]);
    }

    usort($players, static function (array $a, array $b): int {
        $aHasLeft = players_has_left($a);
        $bHasLeft = players_has_left($b);

        if ($aHasLeft !== $bHasLeft) {
            return $aHasLeft ? 1 : -1;
        }

        if ($a['status'] !== $b['status']) {
            $order = ['current' => 0, 'trialist' => 1, 'loan' => 2, 'injured' => 3, 'retired' => 4, 'left' => 5];
            return ($order[$a['status']] ?? 99) <=> ($order[$b['status']] ?? 99);
        }

        if ($a['is_captain'] !== $b['is_captain']) {
            return $a['is_captain'] ? -1 : 1;
        }

        return strcasecmp((string) $a['name'], (string) $b['name']);
    });

    return $players;
}

/**
 * @param list<array<string, mixed>> $players
 */
function players_save_all(array $players): bool
{
    if (!is_dir(dirname(PLAYERS_DATA_FILE))) {
        @mkdir(dirname(PLAYERS_DATA_FILE), 0775, true);
    }

    $json = json_encode(array_values($players), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents(PLAYERS_DATA_FILE, $json . PHP_EOL, LOCK_EX) !== false;
}

/**
 * @param array<string, mixed> $player
 * @return array<string, mixed>
 */
function players_normalize(array $player): array
{
    $status = players_normalize_status(
        isset($player['status']) ? (string) $player['status'] : null,
        !empty($player['is_trialist']),
        trim((string) ($player['released_date'] ?? '')) !== ''
    );

    return [
        'source_id' => trim((string) ($player['source_id'] ?? '')),
        'id' => (string) ($player['id'] ?? ''),
        'name' => trim((string) ($player['name'] ?? '')),
        'position' => players_normalize_position((string) ($player['position'] ?? '')),
        'dob' => trim((string) ($player['dob'] ?? '')),
        'joined_date' => trim((string) ($player['joined_date'] ?? '')),
        'released_date' => trim((string) ($player['released_date'] ?? '')),
        'status' => $status,
        'image' => trim((string) ($player['image'] ?? '')),
        'is_captain' => !empty($player['is_captain']),
        'sponsor_name' => trim((string) ($player['sponsor_name'] ?? '')),
        'sponsor_url' => trim((string) ($player['sponsor_url'] ?? '')),
        'created_at' => trim((string) ($player['created_at'] ?? '')),
        'updated_at' => trim((string) ($player['updated_at'] ?? '')),
    ];
}

/**
 * @param list<array<string, mixed>> $players
 */
function players_find_by_id(array $players, string $id): ?array
{
    foreach ($players as $player) {
        if ((string) ($player['id'] ?? '') === $id) {
            return $player;
        }
    }

    return null;
}

function players_has_left(array $player): bool
{
    return (string) ($player['status'] ?? '') === 'left'
        || trim((string) ($player['released_date'] ?? '')) !== '';
}

function players_normalize_status(?string $status, bool $wasTrialist = false, bool $hasReleasedDate = false): string
{
    $status = strtolower(trim((string) $status));
    if (in_array($status, ['current', 'trialist', 'left', 'retired', 'loan', 'injured'], true)) {
        return $status;
    }

    if ($status === 'trial') {
        return 'trialist';
    }

    if ($status === 'signed') {
        return 'current';
    }

    if ($status === 'released') {
        return 'left';
    }

    if ($hasReleasedDate) {
        return 'left';
    }

    if ($wasTrialist) {
        return 'trialist';
    }

    return 'current';
}

/**
 * @return array<string, string>
 */
function players_status_options(): array
{
    return [
        'current' => 'Current',
        'trialist' => 'Trialist',
        'left' => 'Left',
        'retired' => 'Retired',
        'loan' => 'On Loan',
        'injured' => 'Injured',
    ];
}

function players_generate_id(): string
{
    return bin2hex(random_bytes(8));
}

/**
 * @return array<string, string>
 */
function players_position_options(): array
{
    return [
        '10 Goalkeeper' => 'Goalkeeper',
        '20 Defender' => 'Defender',
        '30 Defender/Midfielder' => 'Defender / Midfielder',
        '35 Defender/Forward' => 'Defender / Forward',
        '40 Midfielder' => 'Midfielder',
        '50 Midfielder/Forward' => 'Midfielder / Forward',
        '60 Forward' => 'Forward',
    ];
}

function players_normalize_position(?string $position): string
{
    $position = trim((string) $position);
    if ($position === '') {
        return '';
    }

    $normalizedKey = strtolower(preg_replace('/\s+/', ' ', str_replace(' / ', '/', $position)) ?? $position);
    $map = [
        '10 goalkeeper' => '10 Goalkeeper',
        'goalkeeper' => '10 Goalkeeper',
        'gk' => '10 Goalkeeper',
        '20 defender' => '20 Defender',
        'defender' => '20 Defender',
        'rb' => '20 Defender',
        'cb' => '20 Defender',
        'lb' => '20 Defender',
        '30 defender/midfielder' => '30 Defender/Midfielder',
        'defender/midfielder' => '30 Defender/Midfielder',
        '35 defender/forward' => '35 Defender/Forward',
        'defender/forward' => '35 Defender/Forward',
        '40 midfielder' => '40 Midfielder',
        'midfielder' => '40 Midfielder',
        'rm' => '40 Midfielder',
        'cm' => '40 Midfielder',
        'lm' => '40 Midfielder',
        'cam' => '40 Midfielder',
        '50 midfielder/forward' => '50 Midfielder/Forward',
        'midfielder/forward' => '50 Midfielder/Forward',
        '60 forward' => '60 Forward',
        'forward' => '60 Forward',
        'rw' => '60 Forward',
        'lw' => '60 Forward',
        'st' => '60 Forward',
        'unknown' => '',
        'coach' => '',
        'staff' => '',
    ];

    return $map[$normalizedKey] ?? '';
}

function players_position_label(?string $position): string
{
    $normalized = players_normalize_position($position);
    if ($normalized === '') {
        return '';
    }

    $options = players_position_options();
    return $options[$normalized] ?? $normalized;
}

/**
 * @return array{path: string, error: string}
 */
function players_handle_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['path' => '', 'error' => ''];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['path' => '', 'error' => 'Image upload failed.'];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['path' => '', 'error' => 'Uploaded image could not be validated.'];
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        return ['path' => '', 'error' => 'Please upload a valid image file.'];
    }

    $mime = (string) ($imageInfo['mime'] ?? '');
    $extensionMap = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensionMap[$mime])) {
        return ['path' => '', 'error' => 'Only JPG, PNG, or WebP images are allowed.'];
    }

    if (!is_dir(PLAYERS_UPLOAD_DIR) && !@mkdir(PLAYERS_UPLOAD_DIR, 0775, true) && !is_dir(PLAYERS_UPLOAD_DIR)) {
        return ['path' => '', 'error' => 'The player upload directory could not be created.'];
    }

    $filename = 'player-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensionMap[$mime];
    $destination = PLAYERS_UPLOAD_DIR . '/' . $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        return ['path' => '', 'error' => 'The uploaded image could not be saved.'];
    }

    return ['path' => 'uploads/players/' . $filename, 'error' => ''];
}

function players_delete_image(?string $path): void
{
    $path = trim((string) $path);
    if ($path === '' || !str_starts_with($path, 'uploads/players/')) {
        return;
    }

    $absolutePath = __DIR__ . '/' . $path;
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}
