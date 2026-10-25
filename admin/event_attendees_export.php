<?php
declare(strict_types=1);

/** CSV download of an event's attendees (personal data: needs events_manage). */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/events_admin.php';
require_once __DIR__ . '/lib/audit.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
hub_auth_require_capability('events_manage');

$eventId = (int) ($_GET['event_id'] ?? 0);
$event = $eventId > 0 ? events_get($pdo, $eventId) : null;
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}
$show = in_array($_GET['show'] ?? '', ['live', 'admitted', 'waiting', 'cancelled'], true) ? (string) $_GET['show'] : 'all';
$rows = events_attendee_rows($pdo, $eventId, ['q' => trim((string) ($_GET['q'] ?? '')), 'show' => $show]);
auditLog($pdo, 'event_attendees_exported', 'Downloaded the attendee list for event #' . $eventId . ' (' . count($rows) . ' tickets)');

$file = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $event['title'])) . '-attendees-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . trim((string) $file, '-') . '"');
header('Cache-Control: no-store');
echo "\xEF\xBB\xBF" . events_attendees_csv($rows); // BOM so Excel reads accents correctly
