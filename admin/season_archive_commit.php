<?php

declare(strict_types=1);

/**
 * Commit a finished season to the archive (or bring it back).
 * POST: season_id, do = archive | restore | dismiss
 */

require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('club_setup')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}
if (!csrf_check()) {
    http_response_code(400);
    exit('Invalid CSRF token.');
}

$seasonId = (int) ($_POST['season_id'] ?? 0);
$do = (string) ($_POST['do'] ?? '');
$season = $seasonId > 0 ? getSeasonById($pdo, $seasonId) : null;
if (!$season) {
    http_response_code(404);
    exit('Season not found.');
}
$redirect = static function (string $query): never {
    $to = (string) ($_POST['return'] ?? '');
    $base = in_array($to, ['index.php', 'seasons.php'], true) ? $to : 'seasons.php';
    header('Location: /admin/' . $base . '?' . $query);
    exit;
};

switch ($do) {
    case 'archive':
        if ((int) ($season['is_current'] ?? 0) === 1) {
            $redirect('archive_error=current');
        }
        seasonArchive($pdo, $seasonId);
        auditLog($pdo, 'season_archived', 'Committed season "' . ($season['name'] ?? '') . '" to the archive.');
        $redirect('archived=' . $seasonId);
    case 'restore':
        seasonRestoreFromArchive($pdo, $seasonId);
        auditLog($pdo, 'season_restored', 'Restored season "' . ($season['name'] ?? '') . '" from the archive.');
        $redirect('restored=' . $seasonId);
    case 'dismiss':
        $pdo->prepare('UPDATE seasons SET archive_prompt_dismissed = 1 WHERE id = :id LIMIT 1')->execute([':id' => $seasonId]);
        $redirect('kept=' . $seasonId);
    default:
        http_response_code(400);
        exit('Unknown action.');
}
