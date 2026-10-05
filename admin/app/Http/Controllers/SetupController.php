<?php
namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\SetupService;

class SetupController
{
    private AuthService $auth;
    private AuditService $audit;
    private SetupService $setup;

    public function __construct()
    {
        $this->auth = new AuthService();
        $this->audit = new AuditService();
        $this->setup = new SetupService();
    }

    public function show(): void
    {
        $old = flash('old') ?? [];
        $errors = flash('errors') ?? [];

        render('setup/index', [
            'pageTitle' => 'Create a Club',
            'old' => $old,
            'errors' => $errors,
        ], 'layouts/auth');
    }

    public function store(): void
    {
        verify_csrf();

        $input = [
            'club_name' => trim((string) ($_POST['club_name'] ?? '')),
            'club_slug' => trim((string) ($_POST['club_slug'] ?? '')),
            'primary_colour' => trim((string) ($_POST['primary_colour'] ?? '')),
            'secondary_colour' => trim((string) ($_POST['secondary_colour'] ?? '')),
            'accent_colour' => trim((string) ($_POST['accent_colour'] ?? '')),
            'admin_name' => trim((string) ($_POST['admin_name'] ?? '')),
            'admin_email' => strtolower(trim((string) ($_POST['admin_email'] ?? ''))),
            'admin_password' => (string) ($_POST['admin_password'] ?? ''),
        ];

        if ($input['club_slug'] === '') {
            $input['club_slug'] = $this->slugify($input['club_name']);
        } else {
            $input['club_slug'] = $this->slugify($input['club_slug']);
        }

        $errors = $this->validate($input);
        if (!empty($errors)) {
            $this->bounceWithErrors($errors, $input);
        }

        try {
            $result = $this->setup->createClubWithAdmin($input);
        } catch (\Throwable $exception) {
            $this->bounceWithErrors(['An unexpected error occurred while creating the club.'], $input);
            return;
        }

        $club = $result['club'];
        $user = $result['user'];

        $this->auth->forceLogin($user['id'], $club['id']);

        $this->audit->log('platform.club.created', [
            'club_slug' => $club['slug'],
            'admin_email' => $user['email'],
        ], $club['id'], $user['id']);

        redirect('/' . rawurlencode($club['slug']) . '/dashboard');
    }

    private function validate(array $input): array
    {
        $errors = [];

        if ($input['club_name'] === '') {
            $errors[] = 'Club name is required.';
        }

        if ($input['club_slug'] === '') {
            $errors[] = 'Club slug is required.';
        } elseif (!preg_match('/^[a-z0-9-]+$/', $input['club_slug'])) {
            $errors[] = 'Club slug may only contain letters, numbers, and hyphens.';
        }

        if ($input['admin_name'] === '') {
            $errors[] = 'Administrator name is required.';
        }

        if (!$this->validateEmail($input['admin_email'])) {
            $errors[] = 'A valid administrator email is required.';
        }

        if (strlen($input['admin_password']) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        foreach (['primary_colour', 'secondary_colour', 'accent_colour'] as $colourKey) {
            if ($input[$colourKey] !== '' && !$this->validateColour($input[$colourKey])) {
                $errors[] = ucfirst(str_replace('_', ' ', $colourKey)) . ' must be a valid hex value (e.g. #1d4ed8).';
            }
        }

        $errors = array_merge($errors, $this->checkUniqueness($input));

        return $errors;
    }

    private function validateColour(string $value): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value);
    }

    private function validateEmail(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    private function checkUniqueness(array $input): array
    {
        $errors = [];
        $db = db();

        $slugStmt = $db->prepare('SELECT COUNT(*) FROM clubs WHERE slug = :slug');
        $slugStmt->execute(['slug' => $input['club_slug']]);
        if ((int) $slugStmt->fetchColumn() > 0) {
            $errors[] = 'Club slug is already in use.';
        }

        $emailStmt = $db->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $emailStmt->execute(['email' => $input['admin_email']]);
        if ((int) $emailStmt->fetchColumn() > 0) {
            $errors[] = 'Administrator email is already in use.';
        }

        return $errors;
    }

    private function slugify(string $value): string
    {
        if (function_exists('slugify')) {
            return slugify($value);
        }

        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
        $value = trim($value, '-');
        return preg_replace('/-+/', '-', $value);
    }

    private function bounceWithErrors(array $errors, array $input): void
    {
        unset($input['admin_password']);
        flash('errors', $errors);
        flash('old', $input);
        redirect('/setup');
    }
}
