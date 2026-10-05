<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => 'Delete Committee Meeting',
    'subtitle' => 'Review the meeting before permanently removing it.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/committee_meetings.php';
require_once __DIR__ . '/lib/audit.php';

$id = (int) ($_GET['id'] ?? 0);
$meeting = $id > 0 ? committee_meeting_get($pdo, $id) : null;

if (!$meeting) {
    echo '<div><div class="alert alert-danger">Meeting not found.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        try {
            committee_meeting_delete($pdo, $id);
            auditLog($pdo, 'committee_meeting_deleted', 'Deleted committee meeting of ' . date('d/m/Y', strtotime((string) $meeting['meeting_date'])));
            header('Location: committee_meetings.php?deleted=1');
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>

<div class="committee-meeting-delete-page">
    <div class="venue-delete-panel">
        <div class="venue-delete-panel__icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></div>
        <div class="venue-delete-panel__content">
            <div class="venue-editor-panel__eyebrow">Permanent action</div>
            <h2>Delete the meeting of <?= h(date('d/m/Y', strtotime((string) $meeting['meeting_date']))) ?>?</h2>
            <p>This removes the minutes record and cannot be undone. Any actions already logged against this meeting stay in the task list but lose their link back to it.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" class="venue-delete-actions">
                <?= csrf_field() ?>
                <a href="committee_meeting_edit.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-danger">
                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                    Delete Meeting
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
