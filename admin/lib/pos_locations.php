<?php
declare(strict_types=1);

function pos_location_normalize_code(string $value): string
{
    $code = strtolower(trim($value));
    $code = preg_replace('/[^a-z0-9]+/', '_', $code) ?? '';
    $code = trim($code, '_');

    return substr($code !== '' ? $code : 'location', 0, 40);
}

function pos_location_save(PDO $pdo, array $data): int
{
    $id = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));
    $code = pos_location_normalize_code((string) ($data['code'] ?? $name));
    $description = trim((string) ($data['description'] ?? ''));
    $sortOrder = max(0, (int) ($data['sort_order'] ?? 0));
    $isActive = !empty($data['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new InvalidArgumentException('Location name is required.');
    }

    if ($id > 0) {
        $stmt = $pdo->prepare('UPDATE pos_locations SET name = :name, code = :code, description = :description, sort_order = :sort_order, is_active = :is_active WHERE id = :id');
        $stmt->execute([
            ':id' => $id,
            ':name' => $name,
            ':code' => $code,
            ':description' => $description !== '' ? $description : null,
            ':sort_order' => $sortOrder,
            ':is_active' => $isActive,
        ]);

        return $id;
    }

    $stmt = $pdo->prepare('INSERT INTO pos_locations (name, code, description, sort_order, is_active) VALUES (:name, :code, :description, :sort_order, :is_active)');
    $stmt->execute([
        ':name' => $name,
        ':code' => $code,
        ':description' => $description !== '' ? $description : null,
        ':sort_order' => $sortOrder,
        ':is_active' => $isActive,
    ]);

    return (int) $pdo->lastInsertId();
}

function pos_location_deactivate(PDO $pdo, int $id): void
{
    if ($id <= 0) {
        throw new InvalidArgumentException('A valid location is required.');
    }

    $stmt = $pdo->prepare('UPDATE pos_locations SET is_active = 0 WHERE id = :id');
    $stmt->execute([':id' => $id]);
}

