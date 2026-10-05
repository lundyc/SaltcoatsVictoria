<?php

namespace App\Http\Controllers;

use App\Services\AuthService;

class DashboardController
{
    public function index(): void
    {
        // Platform admins should never land here
        if (!empty($_SESSION['is_platform_admin'])) {
            header('Location: /platform');
            exit;
        }

        // Ensure club context
        if (empty($_SESSION['club_id'])) {
            $auth = new AuthService();
            $clubs = $auth->getUserClubs($_SESSION['user_id']);

            if (count($clubs) === 0) {
                abort(403, ['message' => 'No club access assigned.'], 'layouts/auth');
            }

            // For now: auto-select first club
            $_SESSION['club_id'] = $clubs[0]['id'];
        }

        view('dashboard/index');
    }
}
