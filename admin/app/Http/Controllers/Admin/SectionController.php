<?php
namespace App\Http\Controllers\Admin;

use App\Services\AuditService;
use PDO;

class SectionController
{
    private AuditService $audit;

    public function __construct()
    {
        $this->audit = new AuditService();
    }

    public function index(): void
    {
        $club = request_context('club');
        $sections = $this->fetchSections((int) $club['id']);
        $status = flash('success');
        $errors = flash('errors') ?? [];

        render('admin/sections', [
            'pageTitle' => 'Sections',
            'sections' => $sections,
            'statusMessage' => $status,
            'errors' => $errors,
        ], 'layouts/app');
    }

    public function store(): void
    {
        verify_csrf();

        $club = request_context('club');
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            flash('errors', ['Section name is required.']);
            redirect(club_url('admin/sections'));
        }

        $db = db();
        $stmt = $db->prepare('INSERT INTO club_sections (club_id, name, sort_order, is_active) VALUES (:club_id, :name, :sort_order, 1)');
        $stmt->execute([
            'club_id' => $club['id'],
            'name' => $name,
            'sort_order' => $this->nextSortOrder($db, (int) $club['id']),
        ]);

        $this->audit->log('admin.sections.created', [
            'name' => $name,
        ]);

        flash('success', 'Section added.');
        redirect(club_url('admin/sections'));
    }

    public function toggle(string $sectionId): void
    {
        verify_csrf();

        $club = request_context('club');
        $section = $this->section((int) $sectionId, (int) $club['id']);
        if (!$section) {
            flash('errors', ['Section not found.']);
            redirect(club_url('admin/sections'));
        }

        $newState = (int) !$section['is_active'];
        $stmt = db()->prepare('UPDATE club_sections SET is_active = :state WHERE id = :id');
        $stmt->execute([
            'state' => $newState,
            'id' => $section['id'],
        ]);

        $this->audit->log('admin.sections.toggled', [
            'section_id' => $section['id'],
            'is_active' => $newState,
        ]);

        flash('success', 'Section status updated.');
        redirect(club_url('admin/sections'));
    }

    public function sort(string $sectionId): void
    {
        verify_csrf();

        $club = request_context('club');
        $section = $this->section((int) $sectionId, (int) $club['id']);
        if (!$section) {
            flash('errors', ['Section not found.']);
            redirect(club_url('admin/sections'));
        }

        $order = (int) ($_POST['sort_order'] ?? $section['sort_order']);
        $stmt = db()->prepare('UPDATE club_sections SET sort_order = :sort_order WHERE id = :id');
        $stmt->execute([
            'sort_order' => $order,
            'id' => $section['id'],
        ]);

        $this->audit->log('admin.sections.order_updated', [
            'section_id' => $section['id'],
            'sort_order' => $order,
        ]);

        flash('success', 'Section order updated.');
        redirect(club_url('admin/sections'));
    }

    private function fetchSections(int $clubId): array
    {
        $stmt = db()->prepare('SELECT id, name, is_active, sort_order FROM club_sections WHERE club_id = :club_id ORDER BY sort_order, name');
        $stmt->execute(['club_id' => $clubId]);
        return $stmt->fetchAll() ?: [];
    }

    private function section(int $sectionId, int $clubId): ?array
    {
        $stmt = db()->prepare('SELECT id, name, is_active, sort_order FROM club_sections WHERE id = :id AND club_id = :club_id LIMIT 1');
        $stmt->execute([
            'id' => $sectionId,
            'club_id' => $clubId,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function nextSortOrder(PDO $db, int $clubId): int
    {
        $stmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM club_sections WHERE club_id = :club_id');
        $stmt->execute(['club_id' => $clubId]);
        return (int) $stmt->fetchColumn();
    }
}
