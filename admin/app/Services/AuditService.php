<?php
namespace App\Services;

use Throwable;

class AuditService
{
    public function log(string $action, array $meta = [], ?int $clubId = null, ?int $userId = null): void
    {
        $club = request_context('club');
        $clubId = $clubId ?? ($club['id'] ?? null);
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);

        try {
            $stmt = db()->prepare('INSERT INTO audit_log (club_id, user_id, action, ip_address, user_agent, meta_json, created_at)
                VALUES (:club_id, :user_id, :action, :ip_address, :user_agent, :meta_json, NOW())');
            $stmt->execute([
                'club_id' => $clubId,
                'user_id' => $userId,
                'action' => $action,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'meta_json' => json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ]);
        } catch (Throwable $exception) {
            // Silently ignore audit failures.
        }
    }
}
