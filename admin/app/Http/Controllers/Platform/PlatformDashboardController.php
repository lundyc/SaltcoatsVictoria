<?php

namespace App\Http\Controllers\Platform;

class PlatformDashboardController
{
    public function index(): void
    {
        view('platform/dashboard', [
            'title' => 'Platform Dashboard',
        ], 'layouts/platform');
    }
}
