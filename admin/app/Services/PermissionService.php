<?php
namespace App\Services;

class PermissionService
{
    public function has(string $permission, int $userId, int $clubId): bool
    {
        $permissions = $this->permissionsFor($userId, $clubId);
        return in_array($permission, $permissions, true);
    }

    public function permissionsFor(int $userId, int $clubId): array
    {
        if (isset($_SESSION['_permissions'][$clubId])) {
            return $_SESSION['_permissions'][$clubId];
        }

        $sql = 'SELECT DISTINCT p.code
                FROM club_memberships cm
                INNER JOIN roles r ON r.id = cm.role_id
                INNER JOIN role_permissions rp ON rp.role_id = r.id
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE cm.user_id = :user_id AND cm.club_id = :club_id';
        $stmt = db()->prepare($sql);
        $stmt->execute([
            'user_id' => $userId,
            'club_id' => $clubId,
        ]);

        $codes = array_map(static fn ($row) => $row['code'], $stmt->fetchAll() ?: []);
        $_SESSION['_permissions'][$clubId] = $codes;

        return $codes;
    }
}
