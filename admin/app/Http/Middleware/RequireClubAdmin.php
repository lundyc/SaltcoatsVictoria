<?php
namespace App\Http\Middleware;

use App\Services\AuthService;

class RequireClubAdmin
{
    private AuthService $auth;

    public function __construct()
    {
        $this->auth = new AuthService();
    }

    public function handle(): void
    {
        $club = request_context('club');
        $user = $this->auth->user();

        if (!$club || !$user) {
            redirect(club_url('login'));
        }

        if (!is_club_admin()) {
            abort(403, ['message' => 'Club admin access required.'], 'layouts/auth');
        }
    }
}
