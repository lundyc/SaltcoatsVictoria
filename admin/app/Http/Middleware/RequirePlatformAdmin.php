<?php

namespace App\Http\Middleware;

class RequirePlatformAdmin
{
    public function handle(): void
    {
        // Must be logged in
        if (empty($_SESSION['user_id'])) {
            abort(
                403,
                ['message' => 'Platform access requires authentication.'],
                'layouts/auth'
            );
        }

        // Must be platform admin
        if (empty($_SESSION['is_platform_admin'])) {
            abort(
                403,
                ['message' => 'You do not have platform administrator access.'],
                'layouts/auth'
            );
        }
    }
}
