<?php
namespace App\Http\Controllers\Admin;

use PDO;
use Throwable;

class AdminDashboardController
{
    public function index(): void
    {
        $clubContext = request_context('club');
        $db = db();
        $club = $this->clubRecord($db, (int) $clubContext['id']);

        $stats = [
            'sections' => $this->countOrZero($db, 'SELECT COUNT(*) FROM club_sections WHERE club_id = :club_id', ['club_id' => $club['id']]),
            'teams' => $this->countOrZero($db, 'SELECT COUNT(*) FROM club_teams WHERE club_id = :club_id', ['club_id' => $club['id']]),
            'users' => $this->countOrZero($db, 'SELECT COUNT(*) FROM club_memberships WHERE club_id = :club_id', ['club_id' => $club['id']]),
        ];

        render('admin/dashboard', [
            'pageTitle' => 'Admin Dashboard',
            'clubInfo' => $club,
            'stats' => $stats,
        ], 'layouts/app');
    }

    private function clubRecord(PDO $db, int $clubId): array
    {
        $stmt = $db->prepare('SELECT id, name, short_name, slug, status, primary_colour, secondary_colour, accent_colour, logo_path
            FROM clubs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $clubId]);
        return $stmt->fetch() ?: ['id' => $clubId];
    }

    private function countOrZero(PDO $db, string $query, array $params = []): int
    {
        try {
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $exception) {
            return 0;
        }
    }
}
