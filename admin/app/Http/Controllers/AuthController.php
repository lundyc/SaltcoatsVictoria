<?php

namespace App\Http\Controllers;

use App\Services\AuthService;

class AuthController
{
    public function entry(): void
    {
        // Not logged in → show login
        if (empty($_SESSION['user_id'])) {
            view('auth/login', [], 'layouts/auth');
            return;
        }

        // Logged in → redirect by authority
        if (!empty($_SESSION['is_platform_admin'])) {
            header('Location: /platform');
            exit;
        }

        header('Location: /dashboard');
        exit;
    }

    public function login(): void
    {
        verify_csrf();

        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $auth = new AuthService();
        $user = $auth->attempt($email, $password);

        if (!$user) {
            flash('error', 'The provided credentials are invalid.');
            header('Location: /');
            exit;
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['is_platform_admin'] = (int) $user['is_platform_admin'];

        // Clear any old club context
        unset($_SESSION['club_id']);

        // Redirect by authority
        if ($user['is_platform_admin']) {
            header('Location: /platform');
            exit;
        }

        header('Location: /dashboard');
        exit;
    }

    public function logout(): void
    {
        verify_csrf();

        session_destroy();
        header('Location: /');
        exit;
    }
}
