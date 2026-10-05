<?php
namespace App\Services;

class AuthService
{
    public function check(?array $club = null): bool
    {
        $userId = $_SESSION['user_id'] ?? null;
        $clubId = $_SESSION['club_id'] ?? null;

        if (!$userId || !$clubId) {
            return false;
        }

        if ($club && (int) $club['id'] !== (int) $clubId) {
            return false;
        }

        return $this->user() !== null;
    }

    public function user(): ?array
    {
        $existing = request_context('user');
        if (!empty($existing)) {
            return $existing;
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            return null;
        }

        $stmt = db()->prepare('SELECT id, email, full_name, is_platform_admin FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            $this->logout();
            return null;
        }

        request_context('user', $user);
        return $user;
    }

    public function attempt(array $club, string $email, string $password): bool
    {
        $sql = 'SELECT u.id, u.email, u.password_hash, u.full_name, u.is_platform_admin, cm.club_id
                FROM users u
                INNER JOIN club_memberships cm ON cm.user_id = u.id
                WHERE u.email = :email AND cm.club_id = :club_id
                LIMIT 1';
        $stmt = db()->prepare($sql);
        $stmt->execute([
            'email' => $email,
            'club_id' => $club['id'],
        ]);
        $record = $stmt->fetch();

        if (!$record || !password_verify($password, $record['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['_permissions'] = [];
        $_SESSION['_membership_roles'] = [];
        $_SESSION['user_id'] = $record['id'];
        $_SESSION['club_id'] = $club['id'];

        if (isset($_SESSION['_permissions'][$club['id']])) {
            unset($_SESSION['_permissions'][$club['id']]);
        }

        request_context('user', [
            'id' => $record['id'],
            'email' => $record['email'],
            'full_name' => $record['full_name'],
            'is_platform_admin' => (int) ($record['is_platform_admin'] ?? 0),
        ]);

        return true;
    }

    public function logout(): void
    {
        request_context('user', null);
        unset($_SESSION['_permissions']);
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public function forceLogin(int $userId, int $clubId): void
    {
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['_permissions'] = [];
        $_SESSION['_membership_roles'] = [];
        $_SESSION['user_id'] = $userId;
        $_SESSION['club_id'] = $clubId;
        request_context('user', null);
        $this->user();
    }
}
