<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Events',
    'title' => !empty($_GET['id']) || !empty($_POST['id']) ? 'Edit Event' : 'Add Event',
    'subtitle' => !empty($_GET['id']) || !empty($_POST['id']) ? 'Update details, timing, venue and audience, and see how tickets are going.' : 'A step-by-step setup: details, date and venue, audience, tickets and review.',
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/events.php';
require_once __DIR__ . '/lib/audit.php';
ensureEventsSchema($pdo); // before any transaction: creating tables commits one

$canManage = hub_auth_has_capability('events_manage');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];
$ticketRows = [];
$overrideChecked = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please reload and try again.';
    } elseif (!$canManage) {
        $errors[] = 'You do not have permission to change events.';
    } else {
        if (($_POST['action'] ?? '') === 'release_access' && $id > 0) {
            // Open the unsold accessible places to everyone now.
            $pdo->prepare('UPDATE events SET access_released_at = NOW() WHERE id = :id')->execute([':id' => $id]);
            auditLog($pdo, 'event_access_released', 'Released unsold accessible places to general sale for event #' . $id);
            header('Location: /admin/event_edit.php?id=' . $id . '&saved=1');
            exit;
        }
        $ticketRows = array_values(array_filter((array) ($_POST['tt'] ?? []), static fn ($r) => is_array($r)));
        $overrideChecked = !empty($_POST['allow_over_capacity']);
        try {
            $account = hub_auth_current_user();
            $pdo->beginTransaction();
            $savedId = events_save($pdo, $id ?: null, $_POST, !empty($account['account_id']) ? (int) $account['account_id'] : null);
            $typeCount = 0;
            if (!$id) {
                foreach ($ticketRows as $row) {
                    if (trim((string) ($row['name'] ?? '')) === '') {
                        continue;
                    }
                    events_save_ticket_type($pdo, $savedId, null, $row + ['is_active' => 1, 'sort_order' => $typeCount], $overrideChecked);
                    $typeCount++;
                }
            }
            $pdo->commit();
            $summary = events_capacity_summary($pdo, events_get($pdo, $savedId) ?? []);
            auditLog($pdo, $id ? 'event_updated' : 'event_created', 'Saved event #' . $savedId . ': ' . trim((string) ($_POST['title'] ?? ''))
                . ($typeCount ? ' with ' . $typeCount . ' ticket type(s)' : '') . (!empty($summary['over']) ? ' - OVER CAPACITY by override' : ''));
            header('Location: ' . ($id ? '/admin/event_edit.php?id=' . $savedId . '&saved=1' : '/admin/event_tickets.php?event_id=' . $savedId));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}

$event = $id > 0 ? events_get($pdo, $id) : null;
if ($id > 0 && !$event) {
    http_response_code(404);
    echo '<div class="alert alert-danger">That event was not found. <a href="/admin/events.php">Back to events</a></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}
$venues = events_venues($pdo, true);
$form = $event ?? [
    'id' => 0, 'venue_id' => $venues[0]['id'] ?? 0, 'title' => '', 'short_description' => '', 'description' => '', 'image_path' => '',
    'starts_at' => '', 'doors_at' => '', 'ends_at' => '', 'status' => 'draft', 'min_age' => 0, 'age_note' => '', 'visibility' => 'public',
    'organiser_email' => '', 'capacity_override' => '', 'access_places' => 0, 'access_release_hours' => 48,
];
if ($errors) {
    $form = array_merge($form, array_intersect_key($_POST, $form));
}
$existingTypes = $event ? events_ticket_types($pdo, (int) $event['id']) : [];

require __DIR__ . '/partials/' . ($event ? 'event_form.php' : 'event_wizard.php');
require_once __DIR__ . '/footer.php';
