<?php

declare(strict_types=1);

/**
 * Database-backed, versioned graphic template packs.
 *
 * Published versions are immutable through this API. Global and season defaults
 * may track a pack's latest published version (version_id = NULL), while fixture
 * assignments always store a concrete version to protect prepared graphics from
 * later design changes.
 */

const TEMPLATE_PACKS_LEGACY_KEY = 'legacy-current-club-templates';
const TEMPLATE_PACKS_LEGACY_FILE = __DIR__ . '/../data/match_graphic_templates.json';

/**
 * @return array<string, array{key:string,label:string,description:string,default_width:int,default_height:int}>
 */
function template_packs_action_registry(): array
{
    return [
        'starting_xi' => [
            'key' => 'starting_xi',
            'label' => 'Starting XI',
            'description' => 'Team line-up and substitutes.',
            'default_width' => 1080,
            'default_height' => 1080,
        ],
        'next_match' => [
            'key' => 'next_match',
            'label' => 'Next Match',
            'description' => 'Upcoming fixture announcement.',
            'default_width' => 1080,
            'default_height' => 1350,
        ],
        'monthly_fixtures' => [
            'key' => 'monthly_fixtures',
            'label' => 'Monthly Fixtures',
            'icon' => 'fa-calendar-days',
            'description' => 'Monthly block and calendar fixture graphics.',
            'default_width' => 1080,
            'default_height' => 1350,
        ],
        'kick_off' => [
            'key' => 'kick_off',
            'label' => 'Kick Off',
            'description' => 'Match kick-off announcement.',
            'default_width' => 1080,
            'default_height' => 1080,
        ],
        'half_time' => [
            'key' => 'half_time',
            'label' => 'Half Time',
            'description' => 'Half-time score update.',
            'default_width' => 1080,
            'default_height' => 1080,
        ],
        'full_time' => [
            'key' => 'full_time',
            'label' => 'Full Time',
            'description' => 'Full-time score update.',
            'default_width' => 1080,
            'default_height' => 1080,
        ],
        'goal' => [
            'key' => 'goal',
            'label' => 'Goal',
            'description' => 'Goal and scorer announcement.',
            'default_width' => 1080,
            'default_height' => 1080,
        ],
        'substitution' => [
            'key' => 'substitution',
            'label' => 'Substitution',
            'description' => 'Player substitution announcement.',
            'default_width' => 1080,
            'default_height' => 1080,
        ],
        'yellow_card' => [
            'key' => 'yellow_card',
            'label' => 'Yellow Card',
            'description' => 'Yellow-card match update.',
            'default_width' => 1080,
            'default_height' => 1080,
        ],
        'red_card' => [
            'key' => 'red_card',
            'label' => 'Red Card',
            'description' => 'Red-card match update.',
            'default_width' => 1080,
            'default_height' => 1080,
        ],
        'player_of_match' => [
            'key' => 'player_of_match',
            'label' => 'Player of the Match',
            'description' => 'Player of the match announcement.',
            'default_width' => 1080,
            'default_height' => 1080,
        ],
        'player_sponsorship' => [
            'key' => 'player_sponsorship',
            'label' => 'Player Sponsorship',
            'icon' => 'fa-shirt',
            'description' => 'Player sponsorship announcement.',
            'default_width' => 1080,
            'default_height' => 1080,
        ],
        'postponed' => [
            'key' => 'postponed',
            'label' => 'Match Postponed',
            'description' => 'Postponed fixture notice.',
            'default_width' => 1080,
            'default_height' => 1080,
        ],
        'abandoned' => [
            'key' => 'abandoned',
            'label' => 'Match Abandoned',
            'description' => 'Abandoned fixture notice.',
            'default_width' => 1080,
            'default_height' => 1080,
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function template_packs_default_brand_settings(): array
{
    return [
        'canvas' => ['width' => 1080, 'height' => 1080],
        'colours' => [
            'primary' => '#6f1d2b',
            'secondary' => '#f2eee7',
            'accent' => '#ffffff',
            'text' => '#ffffff',
        ],
        'fonts' => [
            'primary' => 'Poppins',
            'secondary' => 'Poppins',
            'numbers' => 'Poppins',
        ],
        'badges' => [
            'variant' => 'colour',
            'show_club' => true,
            'show_opponent' => true,
        ],
        'sponsors' => [
            'variant' => 'default',
            'show_logo' => true,
            'show_name' => false,
        ],
        'safe_zone' => ['top' => 40, 'right' => 40, 'bottom' => 40, 'left' => 40],
    ];
}

function template_packs_ensure_schema(PDO $pdo): void
{
    /** @var WeakMap<PDO, bool>|null $ensuredConnections */
    static $ensuredConnections = null;
    if (!$ensuredConnections instanceof WeakMap) {
        $ensuredConnections = new WeakMap();
    }
    if (isset($ensuredConnections[$pdo])) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS template_packs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            description TEXT NULL,
            cover_path VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            legacy_key VARCHAR(100) NULL,
            current_published_version_id BIGINT UNSIGNED NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            UNIQUE KEY template_packs_slug_unique (slug),
            UNIQUE KEY template_packs_legacy_key_unique (legacy_key),
            KEY template_packs_status_name (status, name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS template_pack_versions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            pack_id BIGINT UNSIGNED NOT NULL,
            version_number INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            brand_settings_json LONGTEXT NOT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            published_at DATETIME NULL,
            UNIQUE KEY template_pack_versions_number (pack_id, version_number),
            KEY template_pack_versions_status (pack_id, status),
            CONSTRAINT fk_template_pack_versions_pack
                FOREIGN KEY (pack_id) REFERENCES template_packs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS template_pack_actions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            version_id BIGINT UNSIGNED NOT NULL,
            action_key VARCHAR(60) NOT NULL,
            background_path VARCHAR(500) NOT NULL DEFAULT '',
            layout_settings_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY template_pack_actions_version_action (version_id, action_key),
            KEY template_pack_actions_action (action_key),
            CONSTRAINT fk_template_pack_actions_version
                FOREIGN KEY (version_id) REFERENCES template_pack_versions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS template_pack_assignments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            scope VARCHAR(20) NOT NULL,
            scope_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            pack_id BIGINT UNSIGNED NOT NULL,
            version_id BIGINT UNSIGNED NULL,
            assigned_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY template_pack_assignments_scope (scope, scope_id),
            KEY template_pack_assignments_pack (pack_id),
            KEY template_pack_assignments_version (version_id),
            CONSTRAINT fk_template_pack_assignments_pack
                FOREIGN KEY (pack_id) REFERENCES template_packs(id) ON DELETE RESTRICT,
            CONSTRAINT fk_template_pack_assignments_version
                FOREIGN KEY (version_id) REFERENCES template_pack_versions(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Avoid repeated DDL, which would otherwise implicitly commit active MySQL
    // transactions even when every table already exists.
    $ensuredConnections[$pdo] = true;
}

/**
 * @param mixed $value
 */
function template_packs_encode_json($value): string
{
    try {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (JsonException $e) {
        throw new InvalidArgumentException('Template settings contain invalid data.', 0, $e);
    }
}

/**
 * @return array<string, mixed>
 */
function template_packs_decode_json(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }

    try {
        $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function template_packs_slug(string $name): string
{
    $slug = strtolower(trim($name));
    $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
    if (is_string($transliterated)) {
        $slug = $transliterated;
    }
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');

    return $slug !== '' ? substr($slug, 0, 170) : 'template-pack';
}

function template_packs_unique_slug(PDO $pdo, string $name, ?int $excludePackId = null): string
{
    $base = template_packs_slug($name);
    $candidate = $base;
    $suffix = 2;
    $sql = 'SELECT id FROM template_packs WHERE slug = :slug';
    if ($excludePackId !== null) {
        $sql .= ' AND id <> :exclude_id';
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);

    while (true) {
        $params = [':slug' => $candidate];
        if ($excludePackId !== null) {
            $params[':exclude_id'] = $excludePackId;
        }
        $stmt->execute($params);
        if ($stmt->fetchColumn() === false) {
            return $candidate;
        }
        $candidate = substr($base, 0, 160) . '-' . $suffix++;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function template_packs_list(PDO $pdo, bool $includeArchived = false): array
{
    template_packs_ensure_schema($pdo);
    $where = $includeArchived ? '' : "WHERE p.status <> 'archived'";
    $stmt = $pdo->query(
        "SELECT p.*,
                published.version_number AS current_version_number,
                draft.id AS draft_version_id,
                draft.version_number AS draft_version_number,
                EXISTS(
                    SELECT 1 FROM template_pack_assignments global_default
                    WHERE global_default.scope = 'global'
                      AND global_default.scope_id = 0
                      AND global_default.pack_id = p.id
                ) AS is_global_default,
                (SELECT COUNT(*) FROM template_pack_actions a
                 WHERE a.version_id = COALESCE(p.current_published_version_id, draft.id)
                   AND (
                       a.background_path <> ''
                       OR JSON_EXTRACT(a.layout_settings_json, '$.canvas_width') IS NOT NULL
                       OR JSON_LENGTH(JSON_EXTRACT(a.layout_settings_json, '$.elements')) > 0
                   )) AS configured_action_count
         FROM template_packs p
         LEFT JOIN template_pack_versions published ON published.id = p.current_published_version_id
         LEFT JOIN template_pack_versions draft ON draft.pack_id = p.id AND draft.status = 'draft'
         {$where}
         ORDER BY p.status = 'archived', p.name, p.id"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return list<array<string, mixed>>
 */
function template_packs_list_available(PDO $pdo): array
{
    template_packs_ensure_schema($pdo);
    $stmt = $pdo->query(
        "SELECT p.*, p.current_published_version_id AS current_version_id,
                v.version_number AS current_version_number
         FROM template_packs p
         INNER JOIN template_pack_versions v
            ON v.id = p.current_published_version_id AND v.status = 'published'
         WHERE p.status = 'active'
         ORDER BY p.name, p.id"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array<string, mixed>|null
 */
function template_packs_get(PDO $pdo, int $packId): ?array
{
    template_packs_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT p.*, published.version_number AS current_version_number,
                draft.id AS draft_version_id, draft.version_number AS draft_version_number
         FROM template_packs p
         LEFT JOIN template_pack_versions published ON published.id = p.current_published_version_id
         LEFT JOIN template_pack_versions draft ON draft.pack_id = p.id AND draft.status = 'draft'
         WHERE p.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $packId]);
    $pack = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($pack) ? $pack : null;
}

/**
 * @return array<string, mixed>
 */
function template_packs_create(PDO $pdo, string $name, ?string $description = null, ?int $userId = null): array
{
    template_packs_ensure_schema($pdo);
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 190) {
        throw new InvalidArgumentException('A template pack name between 1 and 190 characters is required.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO template_packs (name, slug, description, created_by)
             VALUES (:name, :slug, :description, :created_by)"
        );
        $stmt->execute([
            ':name' => $name,
            ':slug' => template_packs_unique_slug($pdo, $name),
            ':description' => trim((string) $description) ?: null,
            ':created_by' => $userId,
        ]);
        $packId = (int) $pdo->lastInsertId();

        $version = $pdo->prepare(
            "INSERT INTO template_pack_versions
                (pack_id, version_number, status, brand_settings_json, created_by)
             VALUES (:pack_id, 1, 'draft', :brand, :created_by)"
        );
        $version->execute([
            ':pack_id' => $packId,
            ':brand' => template_packs_encode_json(template_packs_default_brand_settings()),
            ':created_by' => $userId,
        ]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return template_packs_get($pdo, $packId)
        ?? throw new RuntimeException('The template pack was created but could not be reloaded.');
}

/**
 * Allowed fields: name, description, cover_path.
 *
 * @param array<string, mixed> $changes
 * @return array<string, mixed>
 */
function template_packs_update(PDO $pdo, int $packId, array $changes): array
{
    $pack = template_packs_get($pdo, $packId);
    if ($pack === null) {
        throw new OutOfBoundsException('Template pack not found.');
    }

    $sets = [];
    $params = [':id' => $packId];
    if (array_key_exists('name', $changes)) {
        $name = trim((string) $changes['name']);
        if ($name === '' || mb_strlen($name) > 190) {
            throw new InvalidArgumentException('A template pack name between 1 and 190 characters is required.');
        }
        $sets[] = 'name = :name';
        $sets[] = 'slug = :slug';
        $params[':name'] = $name;
        $params[':slug'] = template_packs_unique_slug($pdo, $name, $packId);
    }
    if (array_key_exists('description', $changes)) {
        $sets[] = 'description = :description';
        $params[':description'] = trim((string) $changes['description']) ?: null;
    }
    if (array_key_exists('cover_path', $changes)) {
        $coverPath = trim((string) $changes['cover_path']);
        if (mb_strlen($coverPath) > 500) {
            throw new InvalidArgumentException('The cover asset path is too long.');
        }
        $sets[] = 'cover_path = :cover_path';
        $params[':cover_path'] = $coverPath;
    }

    if ($sets !== []) {
        $stmt = $pdo->prepare('UPDATE template_packs SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    return template_packs_get($pdo, $packId)
        ?? throw new RuntimeException('The template pack could not be reloaded.');
}

/**
 * @return array<string, mixed>|null
 */
function template_packs_get_version(PDO $pdo, int $versionId): ?array
{
    template_packs_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT v.*, p.name AS pack_name, p.slug AS pack_slug, p.status AS pack_status
         FROM template_pack_versions v
         INNER JOIN template_packs p ON p.id = v.pack_id
         WHERE v.id = :id LIMIT 1"
    );
    $stmt->execute([':id' => $versionId]);
    $version = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($version)) {
        return null;
    }

    $version['brand_settings'] = template_packs_decode_json((string) $version['brand_settings_json']);
    unset($version['brand_settings_json']);
    $version['actions'] = template_packs_actions_for_version($pdo, $versionId);

    return $version;
}

/**
 * @return array<string, mixed>|null
 */
function template_packs_get_draft(PDO $pdo, int $packId): ?array
{
    template_packs_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        "SELECT id FROM template_pack_versions
         WHERE pack_id = :pack_id AND status = 'draft'
         ORDER BY version_number DESC LIMIT 1"
    );
    $stmt->execute([':pack_id' => $packId]);
    $versionId = $stmt->fetchColumn();

    return $versionId === false ? null : template_packs_get_version($pdo, (int) $versionId);
}

/**
 * @return list<array<string, mixed>>
 */
function template_packs_list_versions(PDO $pdo, int $packId, bool $publishedOnly = true): array
{
    template_packs_ensure_schema($pdo);
    $sql = 'SELECT id, pack_id, version_number, status, created_by, created_at, updated_at, published_at
            FROM template_pack_versions WHERE pack_id = :pack_id';
    if ($publishedOnly) {
        $sql .= " AND status = 'published'";
    }
    $sql .= ' ORDER BY version_number DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':pack_id' => $packId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @return array<string, array<string, mixed>>
 */
function template_packs_actions_for_version(PDO $pdo, int $versionId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, version_id, action_key, background_path, layout_settings_json, created_at, updated_at
         FROM template_pack_actions WHERE version_id = :version_id ORDER BY id"
    );
    $stmt->execute([':version_id' => $versionId]);
    $actions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string) $row['action_key'];
        $row['layout_settings'] = template_packs_decode_json((string) $row['layout_settings_json']);
        unset($row['layout_settings_json']);
        $actions[$key] = $row;
    }

    return $actions;
}

/**
 * @param array<string, mixed> $brandSettings
 * @return array<string, mixed>
 */
function template_packs_save_draft_brand(PDO $pdo, int $packId, array $brandSettings): array
{
    $draft = template_packs_get_draft($pdo, $packId);
    if ($draft === null) {
        throw new LogicException('This pack has no editable draft.');
    }
    $stmt = $pdo->prepare(
        "UPDATE template_pack_versions SET brand_settings_json = :brand
         WHERE id = :id AND status = 'draft'"
    );
    $stmt->execute([
        ':brand' => template_packs_encode_json($brandSettings),
        ':id' => (int) $draft['id'],
    ]);

    return template_packs_get_version($pdo, (int) $draft['id'])
        ?? throw new RuntimeException('The draft could not be reloaded.');
}

/**
 * @param array<string, mixed> $layoutSettings
 * @return array<string, mixed>
 */
function template_packs_save_draft_action(
    PDO $pdo,
    int $packId,
    string $actionKey,
    array $layoutSettings,
    ?string $backgroundPath = null
): array {
    $registry = template_packs_action_registry();
    if (!isset($registry[$actionKey])) {
        throw new InvalidArgumentException('Unknown template action: ' . $actionKey);
    }
    $draft = template_packs_get_draft($pdo, $packId);
    if ($draft === null) {
        throw new LogicException('This pack has no editable draft.');
    }
    if ($backgroundPath === null) {
        $existingActions = (array) ($draft['actions'] ?? []);
        $backgroundPath = isset($existingActions[$actionKey])
            ? (string) ($existingActions[$actionKey]['background_path'] ?? '')
            : '';
    }
    $backgroundPath = trim($backgroundPath);
    if (mb_strlen($backgroundPath) > 500) {
        throw new InvalidArgumentException('The background asset path is too long.');
    }

    $stmt = $pdo->prepare(
        "INSERT INTO template_pack_actions
            (version_id, action_key, background_path, layout_settings_json)
         VALUES (:version_id, :action_key, :background_path, :layout)
         ON DUPLICATE KEY UPDATE
            background_path = VALUES(background_path),
            layout_settings_json = VALUES(layout_settings_json)"
    );
    $stmt->execute([
        ':version_id' => (int) $draft['id'],
        ':action_key' => $actionKey,
        ':background_path' => $backgroundPath,
        ':layout' => template_packs_encode_json($layoutSettings),
    ]);

    return template_packs_actions_for_version($pdo, (int) $draft['id'])[$actionKey];
}

function template_packs_delete_draft_action(PDO $pdo, int $packId, string $actionKey): void
{
    if (!isset(template_packs_action_registry()[$actionKey])) {
        throw new InvalidArgumentException('Unknown template action: ' . $actionKey);
    }
    $draft = template_packs_get_draft($pdo, $packId);
    if ($draft === null) {
        throw new LogicException('This pack has no editable draft.');
    }
    $stmt = $pdo->prepare(
        'DELETE FROM template_pack_actions WHERE version_id = :version_id AND action_key = :action_key'
    );
    $stmt->execute([':version_id' => (int) $draft['id'], ':action_key' => $actionKey]);
}

/**
 * Publishes the current draft, then creates the next editable draft as a copy.
 *
 * @return array<string, mixed> the immutable version that was published
 */
function template_packs_publish(PDO $pdo, int $packId, ?int $userId = null): array
{
    template_packs_ensure_schema($pdo);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $lock = $pdo->prepare('SELECT * FROM template_packs WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $packId]);
        $pack = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($pack)) {
            throw new OutOfBoundsException('Template pack not found.');
        }
        if ((string) $pack['status'] === 'archived') {
            throw new LogicException('An archived template pack cannot be published.');
        }

        $draftStmt = $pdo->prepare(
            "SELECT * FROM template_pack_versions
             WHERE pack_id = :pack_id AND status = 'draft'
             ORDER BY version_number DESC LIMIT 1 FOR UPDATE"
        );
        $draftStmt->execute([':pack_id' => $packId]);
        $draft = $draftStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($draft)) {
            throw new LogicException('This pack has no draft to publish.');
        }

        $publish = $pdo->prepare(
            "UPDATE template_pack_versions
             SET status = 'published', published_at = NOW()
             WHERE id = :id AND status = 'draft'"
        );
        $publish->execute([':id' => (int) $draft['id']]);
        if ($publish->rowCount() !== 1) {
            throw new RuntimeException('The template pack draft changed while it was being published.');
        }

        $pdo->prepare(
            'UPDATE template_packs SET current_published_version_id = :version_id WHERE id = :pack_id'
        )->execute([':version_id' => (int) $draft['id'], ':pack_id' => $packId]);

        $nextNumber = (int) $draft['version_number'] + 1;
        $newDraft = $pdo->prepare(
            "INSERT INTO template_pack_versions
                (pack_id, version_number, status, brand_settings_json, created_by)
             VALUES (:pack_id, :version_number, 'draft', :brand, :created_by)"
        );
        $newDraft->execute([
            ':pack_id' => $packId,
            ':version_number' => $nextNumber,
            ':brand' => (string) $draft['brand_settings_json'],
            ':created_by' => $userId,
        ]);
        $newDraftId = (int) $pdo->lastInsertId();

        $copyActions = $pdo->prepare(
            "INSERT INTO template_pack_actions
                (version_id, action_key, background_path, layout_settings_json)
             SELECT :new_version_id, action_key, background_path, layout_settings_json
             FROM template_pack_actions WHERE version_id = :source_version_id"
        );
        $copyActions->execute([
            ':new_version_id' => $newDraftId,
            ':source_version_id' => (int) $draft['id'],
        ]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return template_packs_get_version($pdo, (int) $draft['id'])
        ?? throw new RuntimeException('The published version could not be reloaded.');
}

/**
 * @return array<string, mixed>
 */
function template_packs_duplicate(PDO $pdo, int $packId, string $newName, ?int $userId = null): array
{
    $source = template_packs_get($pdo, $packId);
    if ($source === null) {
        throw new OutOfBoundsException('Template pack not found.');
    }
    $sourceVersionId = (int) ($source['draft_version_id'] ?? 0);
    if ($sourceVersionId <= 0) {
        $sourceVersionId = (int) ($source['current_published_version_id'] ?? 0);
    }
    $sourceVersion = $sourceVersionId > 0 ? template_packs_get_version($pdo, $sourceVersionId) : null;
    if ($sourceVersion === null) {
        throw new LogicException('The source pack has no version to duplicate.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $copy = template_packs_create($pdo, $newName, (string) ($source['description'] ?? ''), $userId);
        $copyId = (int) $copy['id'];
        template_packs_update($pdo, $copyId, ['cover_path' => (string) ($source['cover_path'] ?? '')]);
        template_packs_save_draft_brand($pdo, $copyId, (array) $sourceVersion['brand_settings']);
        foreach ((array) $sourceVersion['actions'] as $action) {
            template_packs_save_draft_action(
                $pdo,
                $copyId,
                (string) $action['action_key'],
                (array) $action['layout_settings'],
                (string) $action['background_path']
            );
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return template_packs_get($pdo, $copyId)
        ?? throw new RuntimeException('The duplicated pack could not be reloaded.');
}

function template_packs_archive(PDO $pdo, int $packId, bool $archived = true): void
{
    if (template_packs_get($pdo, $packId) === null) {
        throw new OutOfBoundsException('Template pack not found.');
    }
    if ($archived) {
        $defaultAssignments = $pdo->prepare(
            "SELECT COUNT(*) FROM template_pack_assignments
             WHERE pack_id = :pack_id AND scope IN ('global', 'season')"
        );
        $defaultAssignments->execute([':pack_id' => $packId]);
        if ((int) $defaultAssignments->fetchColumn() > 0) {
            throw new LogicException('Change the global or season defaults before archiving this pack.');
        }
        $stmt = $pdo->prepare(
            "UPDATE template_packs SET status = 'archived', archived_at = NOW() WHERE id = :id"
        );
    } else {
        $stmt = $pdo->prepare(
            "UPDATE template_packs SET status = 'active', archived_at = NULL WHERE id = :id"
        );
    }
    $stmt->execute([':id' => $packId]);
}

/**
 * Permanently removes a never-published, unassigned pack.
 */
function template_packs_delete(PDO $pdo, int $packId): void
{
    $pack = template_packs_get($pdo, $packId);
    if ($pack === null) {
        throw new OutOfBoundsException('Template pack not found.');
    }
    if ((int) ($pack['current_published_version_id'] ?? 0) > 0) {
        throw new LogicException('Published template packs must be archived rather than deleted.');
    }
    $assigned = $pdo->prepare('SELECT COUNT(*) FROM template_pack_assignments WHERE pack_id = :pack_id');
    $assigned->execute([':pack_id' => $packId]);
    if ((int) $assigned->fetchColumn() > 0) {
        throw new LogicException('An assigned template pack cannot be deleted.');
    }
    $pdo->prepare('DELETE FROM template_packs WHERE id = :id')->execute([':id' => $packId]);
}

/**
 * @return array<string, mixed>|null
 */
function template_packs_get_assignment(PDO $pdo, string $scope, int $scopeId): ?array
{
    template_packs_ensure_schema($pdo);
    if (!in_array($scope, ['global', 'season', 'fixture'], true)) {
        throw new InvalidArgumentException('Unknown template pack assignment scope.');
    }
    $stmt = $pdo->prepare(
        "SELECT a.*, p.name AS pack_name, p.slug AS pack_slug, p.status AS pack_status,
                v.version_number
         FROM template_pack_assignments a
         INNER JOIN template_packs p ON p.id = a.pack_id
         LEFT JOIN template_pack_versions v ON v.id = a.version_id
         WHERE a.scope = :scope AND a.scope_id = :scope_id LIMIT 1"
    );
    $stmt->execute([':scope' => $scope, ':scope_id' => $scopeId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($assignment) ? $assignment : null;
}

function template_packs_set_assignment(
    PDO $pdo,
    string $scope,
    int $scopeId,
    int $packId,
    ?int $versionId = null,
    ?int $userId = null
): void {
    if (!in_array($scope, ['global', 'season', 'fixture'], true)) {
        throw new InvalidArgumentException('Unknown template pack assignment scope.');
    }
    if ($scope === 'global') {
        $scopeId = 0;
    } elseif ($scopeId <= 0) {
        throw new InvalidArgumentException('A valid assignment target is required.');
    }

    $pack = template_packs_get($pdo, $packId);
    if ($pack === null || (string) $pack['status'] !== 'active') {
        throw new InvalidArgumentException('Choose an active template pack.');
    }

    if ($versionId === null && $scope === 'fixture') {
        $versionId = (int) ($pack['current_published_version_id'] ?? 0);
    }
    if ($versionId !== null) {
        $version = template_packs_get_version($pdo, $versionId);
        if (
            $version === null
            || (int) $version['pack_id'] !== $packId
            || (string) $version['status'] !== 'published'
        ) {
            throw new InvalidArgumentException('Choose a published version belonging to this pack.');
        }
    } elseif ((int) ($pack['current_published_version_id'] ?? 0) <= 0) {
        throw new InvalidArgumentException('This pack has no published version.');
    }

    if ($scope === 'season') {
        $exists = $pdo->prepare('SELECT id FROM seasons WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $scopeId]);
        if ($exists->fetchColumn() === false) {
            throw new InvalidArgumentException('Season not found.');
        }
    } elseif ($scope === 'fixture') {
        $exists = $pdo->prepare('SELECT id FROM match_fixtures WHERE id = :id LIMIT 1');
        $exists->execute([':id' => $scopeId]);
        if ($exists->fetchColumn() === false) {
            throw new InvalidArgumentException('Fixture not found.');
        }
    }

    $stmt = $pdo->prepare(
        "INSERT INTO template_pack_assignments
            (scope, scope_id, pack_id, version_id, assigned_by)
         VALUES (:scope, :scope_id, :pack_id, :version_id, :assigned_by)
         ON DUPLICATE KEY UPDATE
            pack_id = VALUES(pack_id),
            version_id = VALUES(version_id),
            assigned_by = VALUES(assigned_by)"
    );
    $stmt->execute([
        ':scope' => $scope,
        ':scope_id' => $scopeId,
        ':pack_id' => $packId,
        ':version_id' => $versionId,
        ':assigned_by' => $userId,
    ]);
}

function template_packs_clear_assignment(PDO $pdo, string $scope, int $scopeId): void
{
    if (!in_array($scope, ['global', 'season', 'fixture'], true)) {
        throw new InvalidArgumentException('Unknown template pack assignment scope.');
    }
    $stmt = $pdo->prepare(
        'DELETE FROM template_pack_assignments WHERE scope = :scope AND scope_id = :scope_id'
    );
    $stmt->execute([':scope' => $scope, ':scope_id' => $scope === 'global' ? 0 : $scopeId]);
}

/** @return array<string, mixed>|null */
function template_packs_get_global_default(PDO $pdo): ?array
{
    return template_packs_get_assignment($pdo, 'global', 0);
}

function template_packs_set_global_default(
    PDO $pdo,
    int $packId,
    ?int $versionId = null,
    ?int $userId = null
): void {
    template_packs_set_assignment($pdo, 'global', 0, $packId, $versionId, $userId);
}

function template_packs_clear_global_default(PDO $pdo): void
{
    template_packs_clear_assignment($pdo, 'global', 0);
}

/** @return array<string, mixed>|null */
function template_packs_get_season_default(PDO $pdo, int $seasonId): ?array
{
    return template_packs_get_assignment($pdo, 'season', $seasonId);
}

function template_packs_set_season_default(
    PDO $pdo,
    int $seasonId,
    int $packId,
    ?int $versionId = null,
    ?int $userId = null
): void {
    template_packs_set_assignment($pdo, 'season', $seasonId, $packId, $versionId, $userId);
}

function template_packs_clear_season_default(PDO $pdo, int $seasonId): void
{
    template_packs_clear_assignment($pdo, 'season', $seasonId);
}

function template_packs_set_fixture_pack(
    PDO $pdo,
    int $fixtureId,
    int $packId,
    ?int $versionId = null,
    ?int $userId = null
): void {
    template_packs_set_assignment($pdo, 'fixture', $fixtureId, $packId, $versionId, $userId);
}

function template_packs_clear_fixture_pack(PDO $pdo, int $fixtureId): void
{
    template_packs_clear_assignment($pdo, 'fixture', $fixtureId);
}

/**
 * Resolves fixture > season > global and optionally locks an inherited default
 * to the fixture. Pass an empty action key to resolve only the pack/version.
 *
 * @return array{
 *   pack:array<string,mixed>|null,
 *   version:array<string,mixed>|null,
 *   source:string,
 *   brand_settings:array<string,mixed>,
 *   action:array<string,mixed>|null,
 *   assignment:array<string,mixed>|null
 * }
 */
function template_packs_resolve_for_fixture(
    PDO $pdo,
    int $fixtureId,
    string $actionKey = '',
    bool $lock = true
): array {
    template_packs_ensure_schema($pdo);
    if ($fixtureId <= 0) {
        throw new InvalidArgumentException('A valid fixture is required.');
    }
    if ($actionKey !== '' && !isset(template_packs_action_registry()[$actionKey])) {
        throw new InvalidArgumentException('Unknown template action: ' . $actionKey);
    }

    $fixtureStmt = $pdo->prepare('SELECT id, season_id FROM match_fixtures WHERE id = :id LIMIT 1');
    $fixtureStmt->execute([':id' => $fixtureId]);
    $fixture = $fixtureStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($fixture)) {
        throw new OutOfBoundsException('Fixture not found.');
    }

    $source = 'fixture';
    $assignment = template_packs_get_assignment($pdo, 'fixture', $fixtureId);
    if ($assignment === null) {
        $source = 'season';
        $assignment = template_packs_get_assignment($pdo, 'season', (int) $fixture['season_id']);
    }
    if ($assignment === null) {
        $source = 'global';
        $assignment = template_packs_get_global_default($pdo);
    }
    if ($assignment === null) {
        return [
            'pack' => null,
            'version' => null,
            'source' => 'none',
            'brand_settings' => [],
            'action' => null,
            'assignment' => null,
        ];
    }

    $pack = template_packs_get($pdo, (int) $assignment['pack_id']);
    if ($pack === null) {
        return [
            'pack' => null,
            'version' => null,
            'source' => 'none',
            'brand_settings' => [],
            'action' => null,
            'assignment' => null,
        ];
    }
    $versionId = (int) ($assignment['version_id'] ?? 0);
    if ($versionId <= 0) {
        $versionId = (int) ($pack['current_published_version_id'] ?? 0);
    }
    $version = $versionId > 0 ? template_packs_get_version($pdo, $versionId) : null;
    if ($version === null || (string) $version['status'] !== 'published') {
        return [
            'pack' => null,
            'version' => null,
            'source' => 'none',
            'brand_settings' => [],
            'action' => null,
            'assignment' => null,
        ];
    }

    if ($source !== 'fixture' && $lock) {
        template_packs_set_fixture_pack($pdo, $fixtureId, (int) $pack['id'], $versionId);
        $assignment = template_packs_get_assignment($pdo, 'fixture', $fixtureId);
    }

    $action = null;
    if ($actionKey !== '') {
        $action = $version['actions'][$actionKey] ?? [
            'action_key' => $actionKey,
            'background_path' => '',
            'layout_settings' => [],
        ];
    }

    return [
        'pack' => $pack,
        'version' => $version,
        'source' => $source,
        'brand_settings' => (array) $version['brand_settings'],
        'action' => $action,
        'assignment' => $assignment,
    ];
}

/**
 * Imports legacy master-template JSON without modifying or deleting the source.
 * Repeated calls return the same pack. The imported pack is published and made
 * the global default only when no global default exists.
 *
 * @return array{pack:array<string,mixed>,created:bool,published:bool}
 */
function template_packs_migrate_legacy_master_templates(
    PDO $pdo,
    ?string $legacyFile = null,
    ?int $userId = null
): array {
    template_packs_ensure_schema($pdo);
    $existing = $pdo->prepare('SELECT id FROM template_packs WHERE legacy_key = :legacy_key LIMIT 1');
    $existing->execute([':legacy_key' => TEMPLATE_PACKS_LEGACY_KEY]);
    $existingId = $existing->fetchColumn();
    if ($existingId !== false) {
        return [
            'pack' => template_packs_get($pdo, (int) $existingId)
                ?? throw new RuntimeException('The migrated template pack could not be loaded.'),
            'created' => false,
            'published' => true,
        ];
    }

    $legacyFile = $legacyFile ?? TEMPLATE_PACKS_LEGACY_FILE;
    $legacy = [];
    if (is_file($legacyFile) && is_readable($legacyFile)) {
        $json = file_get_contents($legacyFile);
        if (is_string($json) && trim($json) !== '') {
            try {
                $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
                $legacy = is_array($decoded) ? $decoded : [];
            } catch (JsonException $e) {
                throw new RuntimeException('The existing master-template file is not valid JSON.', 0, $e);
            }
        }
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $pack = template_packs_create(
            $pdo,
            'Current Club Templates',
            'Imported from the original master match graphic templates.',
            $userId
        );
        $packId = (int) $pack['id'];
        $pdo->prepare('UPDATE template_packs SET legacy_key = :legacy_key WHERE id = :id')
            ->execute([':legacy_key' => TEMPLATE_PACKS_LEGACY_KEY, ':id' => $packId]);

        foreach (template_packs_action_registry() as $actionKey => $definition) {
            $legacyItem = isset($legacy[$actionKey]) && is_array($legacy[$actionKey])
                ? $legacy[$actionKey]
                : [];
            $captions = isset($legacyItem['captions']) && is_array($legacyItem['captions'])
                ? $legacyItem['captions']
                : [];
            template_packs_save_draft_action(
                $pdo,
                $packId,
                $actionKey,
                [
                    'canvas' => [
                        'width' => $definition['default_width'],
                        'height' => $definition['default_height'],
                    ],
                    'elements' => [],
                    'legacy_captions' => $captions,
                ],
                trim((string) ($legacyItem['image'] ?? ''))
            );
        }

        template_packs_publish($pdo, $packId, $userId);
        if (template_packs_get_global_default($pdo) === null) {
            template_packs_set_global_default($pdo, $packId, null, $userId);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [
        'pack' => template_packs_get($pdo, $packId)
            ?? throw new RuntimeException('The migrated template pack could not be reloaded.'),
        'created' => true,
        'published' => true,
    ];
}
