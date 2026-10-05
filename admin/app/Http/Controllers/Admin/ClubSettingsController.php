<?php
namespace App\Http\Controllers\Admin;

use App\Services\AuditService;
use RuntimeException;

class ClubSettingsController
{
    private AuditService $audit;

    public function __construct()
    {
        $this->audit = new AuditService();
    }

    public function edit(): void
    {
        $club = $this->clubRecord();
        $status = flash('success');
        $errors = flash('errors') ?? [];

        render('admin/club_settings', [
            'pageTitle' => 'Club Settings',
            'club' => $club,
            'statusMessage' => $status,
            'errors' => $errors,
        ], 'layouts/app');
    }

    public function update(): void
    {
        verify_csrf();

        $club = $this->clubRecord();
        $input = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'short_name' => trim((string) ($_POST['short_name'] ?? '')),
            'primary_colour' => trim((string) ($_POST['primary_colour'] ?? '')),
            'secondary_colour' => trim((string) ($_POST['secondary_colour'] ?? '')),
            'accent_colour' => trim((string) ($_POST['accent_colour'] ?? '')),
        ];

        $errors = $this->validate($input);
        if (!empty($errors)) {
            flash('errors', $errors);
            redirect(club_url('admin/club'));
        }

        try {
            $logoPath = $this->handleLogoUpload($club['slug']);
        } catch (RuntimeException $exception) {
            flash('errors', [$exception->getMessage()]);
            redirect(club_url('admin/club'));
        }
        if ($logoPath !== null) {
            $input['logo_path'] = $logoPath;
        }

        $this->updateClub($club['id'], $input);

        $this->audit->log('admin.club.settings_updated', [
            'fields' => array_keys(array_filter($input, static fn ($value) => $value !== '')),
        ]);

        flash('success', 'Club settings updated successfully.');
        redirect(club_url('admin/club'));
    }

    private function clubRecord(): array
    {
        $clubContext = request_context('club');
        $stmt = db()->prepare('SELECT id, name, short_name, slug, primary_colour, secondary_colour, accent_colour, logo_path FROM clubs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $clubContext['id']]);
        return $stmt->fetch() ?: $clubContext;
    }

    private function validate(array $input): array
    {
        $errors = [];

        if ($input['name'] === '') {
            $errors[] = 'Club name is required.';
        }

        foreach (['primary_colour', 'secondary_colour', 'accent_colour'] as $key) {
            if ($input[$key] !== '' && !$this->validColour($input[$key])) {
                $errors[] = ucfirst(str_replace('_', ' ', $key)) . ' must be a valid hex colour (e.g. #1d4ed8).';
            }
        }

        return $errors;
    }

    private function validColour(string $value): bool
    {
        return (bool) preg_match('/^#[0-9a-fA-F]{6}$/', $value);
    }

    private function handleLogoUpload(string $slug): ?string
    {
        if (empty($_FILES['logo']) || ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $file = $_FILES['logo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Logo upload failed.');
        }

        $allowed = ['png', 'jpg', 'jpeg', 'svg'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed, true)) {
            throw new RuntimeException('Logo must be PNG, JPG, or SVG.');
        }

        $directory = base_path('public/assets/logos');
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $filename = $slug . '_' . time() . '.' . $extension;
        $target = $directory . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $target)) {
            throw new RuntimeException('Unable to save uploaded logo.');
        }

        return '/assets/logos/' . $filename;
    }

    private function updateClub(int $clubId, array $input): void
    {
        $fields = [];
        $params = ['id' => $clubId];
        foreach ($input as $column => $value) {
            if ($value === '' && !in_array($column, ['short_name'], true)) {
                continue;
            }
            $fields[] = $column . ' = :' . $column;
            $params[$column] = $value;
        }

        if (empty($fields)) {
            return;
        }

        $sql = 'UPDATE clubs SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
    }
}
