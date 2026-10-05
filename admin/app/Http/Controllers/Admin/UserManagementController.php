<?php
namespace App\Http\Controllers\Admin;

use App\Services\AuditService;
use RuntimeException;

class UserManagementController
{
    private const ALLOWED_ROLES = ['club_admin', 'committee', 'treasurer', 'coach', 'staff'];

    private AuditService $audit;

    public function __construct()
    {
        $this->audit = new AuditService();
    }

    public function index(): void
    {
        $club = request_context('club');
        $db = db();

        $users = $this->fetchUsers($db, (int) $club['id']);
        $roles = $this->roleOptions($db);
        $status = flash('success');
        $errors = flash('errors') ?? [];

        render('admin/users', [
            'pageTitle' => 'User Management',
            'users' => $users,
            'roles' => $roles,
            'statusMessage' => $status,
            'errors' => $errors,
        ], 'layouts/app');
    }

    public function invite(): void
    {
        verify_csrf();

        $club = request_context('club');
        $email = strtolower(trim((string) ($_POST['invite_email'] ?? '')));
        $roleCode = trim((string) ($_POST['invite_role'] ?? ''));

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }
        if (!in_array($roleCode, self::ALLOWED_ROLES, true)) {
            $errors[] = 'Select a valid role.';
        }

        if (!empty($errors)) {
            flash('errors', $errors);
            redirect(club_url('admin/users'));
        }

        try {
            $roleId = $this->roleIdByCode($roleCode);
        } catch (RuntimeException $exception) {
            flash('errors', [$exception->getMessage()]);
            redirect(club_url('admin/users'));
        }
        $token = bin2hex(random_bytes(16));
        $stmt = db()->prepare('INSERT INTO invites (club_id, email, role_id, token, status, created_at)
            VALUES (:club_id, :email, :role_id, :token, :status, NOW())');
        $stmt->execute([
            'club_id' => $club['id'],
            'email' => $email,
            'role_id' => $roleId,
            'token' => $token,
            'status' => 'pending',
        ]);

        $this->audit->log('admin.users.invited', [
            'email' => $email,
            'role' => $roleCode,
        ]);

        flash('success', 'Invitation recorded.');
        redirect(club_url('admin/users'));
    }

    public function updateRole(string $userId): void
    {
        verify_csrf();

        $club = request_context('club');
        $roleCode = trim((string) ($_POST['role_code'] ?? ''));

        if (!in_array($roleCode, self::ALLOWED_ROLES, true)) {
            flash('errors', ['Invalid role selection.']);
            redirect(club_url('admin/users'));
        }

        try {
            $roleId = $this->roleIdByCode($roleCode);
        } catch (RuntimeException $exception) {
            flash('errors', [$exception->getMessage()]);
            redirect(club_url('admin/users'));
        }
        $db = db();
        $membership = $this->membershipForUser($db, (int) $club['id'], (int) $userId);
        if (!$membership) {
            flash('errors', ['User does not belong to this club.']);
            redirect(club_url('admin/users'));
        }

        $stmt = $db->prepare('UPDATE club_memberships SET role_id = :role_id WHERE id = :id');
        $stmt->execute([
            'role_id' => $roleId,
            'id' => $membership['id'],
        ]);

        if ((int) $userId === (int) ($_SESSION['user_id'] ?? 0)) {
            if (isset($_SESSION['_membership_roles'][$club['id']])) {
                unset($_SESSION['_membership_roles'][$club['id']]);
            }
            request_context('club_role', null);
        }

        $this->audit->log('admin.users.role_changed', [
            'user_id' => (int) $userId,
            'role' => $roleCode,
        ]);

        flash('success', 'Role updated.');
        redirect(club_url('admin/users'));
    }

    public function updateStatus(string $userId): void
    {
        verify_csrf();

        $club = request_context('club');
        $status = trim((string) ($_POST['status'] ?? ''));
        if (!in_array($status, ['active', 'suspended'], true)) {
            flash('errors', ['Invalid status value.']);
            redirect(club_url('admin/users'));
        }

        $db = db();
        $membership = $this->membershipForUser($db, (int) $club['id'], (int) $userId);
        if (!$membership) {
            flash('errors', ['User does not belong to this club.']);
            redirect(club_url('admin/users'));
        }

        $stmt = $db->prepare('UPDATE club_memberships SET status = :status WHERE id = :id');
        $stmt->execute([
            'status' => $status,
            'id' => $membership['id'],
        ]);

        $this->audit->log('admin.users.status_changed', [
            'user_id' => (int) $userId,
            'status' => $status,
        ]);

        flash('success', 'User status updated.');
        redirect(club_url('admin/users'));
    }

    private function fetchUsers($db, int $clubId): array
    {
        $stmt = $db->prepare('SELECT u.id, u.full_name, u.email, r.name AS role_name, r.code AS role_code, cm.status
            FROM club_memberships cm
            INNER JOIN users u ON u.id = cm.user_id
            INNER JOIN roles r ON r.id = cm.role_id
            WHERE cm.club_id = :club_id
            ORDER BY u.full_name');
        $stmt->execute(['club_id' => $clubId]);
        return $stmt->fetchAll() ?: [];
    }

    private function roleOptions($db): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::ALLOWED_ROLES), '?'));
        $stmt = $db->prepare('SELECT id, name, code FROM roles WHERE code IN (' . $placeholders . ') ORDER BY name');
        $stmt->execute(self::ALLOWED_ROLES);
        $roles = $stmt->fetchAll() ?: [];
        $map = [];
        foreach ($roles as $role) {
            $map[$role['code']] = $role;
        }
        return $map;
    }

    private function roleIdByCode(string $code): int
    {
        $stmt = db()->prepare('SELECT id FROM roles WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $id = $stmt->fetchColumn();
        if (!$id) {
            throw new RuntimeException('Role not found.');
        }
        return (int) $id;
    }

    private function membershipForUser($db, int $clubId, int $userId): ?array
    {
        $stmt = $db->prepare('SELECT id FROM club_memberships WHERE club_id = :club_id AND user_id = :user_id LIMIT 1');
        $stmt->execute([
            'club_id' => $clubId,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
