<?php
namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

class SetupService
{
    public function createClubWithAdmin(array $input): array
    {
        $db = db();
        $db->beginTransaction();

        try {
            $clubId = $this->insertClub($db, $input);
            $userId = $this->insertUser($db, $input);
            $roleId = $this->clubAdminRoleId($db);
            $this->attachMembership($db, $clubId, $userId, $roleId);
            $this->seedSections($db, $clubId);
            $this->enableModules($db, $clubId);

            $db->commit();

            return [
                'club' => ['id' => $clubId, 'slug' => $input['club_slug'], 'name' => $input['club_name']],
                'user' => ['id' => $userId, 'email' => $input['admin_email'], 'name' => $input['admin_name']],
            ];
        } catch (Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    private function insertClub(PDO $db, array $input): int
    {
        $stmt = $db->prepare('INSERT INTO clubs (name, slug, status, primary_colour, secondary_colour, accent_colour)
            VALUES (:name, :slug, :status, :primary, :secondary, :accent)');
        $stmt->execute([
            'name' => $input['club_name'],
            'slug' => $input['club_slug'],
            'status' => 'active',
            'primary' => $input['primary_colour'] ?: '#1d4ed8',
            'secondary' => $input['secondary_colour'] ?: '#0f172a',
            'accent' => $input['accent_colour'] ?: '#22d3ee',
        ]);

        return (int) $db->lastInsertId();
    }

    private function insertUser(PDO $db, array $input): int
    {
        $stmt = $db->prepare('INSERT INTO users (full_name, email, password_hash, is_platform_admin)
            VALUES (:name, :email, :password, 0)');
        $stmt->execute([
            'name' => $input['admin_name'],
            'email' => $input['admin_email'],
            'password' => password_hash($input['admin_password'], PASSWORD_BCRYPT),
        ]);

        return (int) $db->lastInsertId();
    }

    private function clubAdminRoleId(PDO $db): int
    {
        $stmt = $db->prepare('SELECT id FROM roles WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => 'club_admin']);
        $roleId = $stmt->fetchColumn();

        if (!$roleId) {
            throw new RuntimeException('Club admin role is not configured.');
        }

        return (int) $roleId;
    }

    private function attachMembership(PDO $db, int $clubId, int $userId, int $roleId): void
    {
        $stmt = $db->prepare('INSERT INTO club_memberships (club_id, user_id, role_id)
            VALUES (:club_id, :user_id, :role_id)');
        $stmt->execute([
            'club_id' => $clubId,
            'user_id' => $userId,
            'role_id' => $roleId,
        ]);
    }

    private function seedSections(PDO $db, int $clubId): void
    {
        $sections = ['Men', 'Women', 'Youth'];
        $stmt = $db->prepare('INSERT INTO club_sections (club_id, name, sort_order, is_active) VALUES (:club_id, :name, :sort_order, 1)');
        $order = 1;

        foreach ($sections as $section) {
            $stmt->execute([
                'club_id' => $clubId,
                'name' => $section,
                'sort_order' => $order++,
            ]);
        }
    }

    private function enableModules(PDO $db, int $clubId): void
    {
        $modules = $db->query('SELECT id FROM modules')->fetchAll(PDO::FETCH_COLUMN);
        if (empty($modules)) {
            return;
        }

        $stmt = $db->prepare('INSERT INTO club_modules (club_id, module_id, is_enabled, source)
            VALUES (:club_id, :module_id, 1, :source)');

        foreach ($modules as $moduleId) {
            $stmt->execute([
                'club_id' => $clubId,
                'module_id' => $moduleId,
                'source' => 'manual',
            ]);
        }
    }
}
