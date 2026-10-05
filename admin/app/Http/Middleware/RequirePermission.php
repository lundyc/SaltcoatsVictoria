<?php
namespace App\Http\Middleware;

use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\PermissionService;

class RequirePermission
{
    private PermissionService $permissions;
    private AuthService $auth;
    private AuditService $audit;

    public function __construct()
    {
        $this->permissions = new PermissionService();
        $this->auth = new AuthService();
        $this->audit = new AuditService();
    }

    public function handle(string $permission): void
    {
        $club = request_context('club');
        $user = $this->auth->user();

        if (!$club || !$user) {
            redirect(club_url('login'));
        }

        if ($this->permissions->has($permission, (int) $user['id'], (int) $club['id'])) {
            return;
        }

        $this->audit->log('auth.permission.denied', [
            'permission' => $permission,
            'club_slug' => $club['slug'] ?? null,
        ]);

        abort(403, ['message' => 'You do not have permission to view this area.'], 'layouts/app');
    }
}
