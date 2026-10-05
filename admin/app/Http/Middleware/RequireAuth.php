<?php

namespace App\Http\Middleware;

class RequireAuth
{
    public function handle(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /');
            exit;
        }
    }
}
