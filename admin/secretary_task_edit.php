<?php
declare(strict_types=1);

$id = (int) ($_GET['id'] ?? 0);
$action = $id > 0 ? 'edit' : 'new';

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => $action === 'new' ? 'Add Task' : 'Edit Task',
    'subtitle' => 'Every action should have an owner and a due date.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/secretary_tasks.php';
require_once __DIR__ . '/lib/audit.php';

$errors = [];

if ($action === 'edit') {
    $task = secretary_task_get($pdo, $id);
    if (!$task) {
        echo '<div><div class="alert alert-danger">Task not found.</div></div>';
        require __DIR__ . '/footer.php';
        exit;
    }
} else {
    $task = [
        'category' => (string) ($_GET['category'] ?? 'other'),
        'title' => (string) ($_GET['title'] ?? ''),
        'owner' => '',
        'due_at' => '',
        'priority' => 'normal',
        'status' => 'open',
        'source' => (string) ($_GET['source'] ?? ''),
        'notes' => '',
        'correspondence_id' => (int) ($_GET['correspondence_id'] ?? 0) ?: null,
        'meeting_id' => (int) ($_GET['meeting_id'] ?? 0) ?: null,
    ];
}

$formCategory = (string) ($task['category'] ?? 'other');
$formTitle = (string) ($task['title'] ?? '');
$formOwner = (string) ($task['owner'] ?? '');
$formDueAt = (string) ($task['due_at'] ?? '');
$formPriority = (string) ($task['priority'] ?? 'normal');
$formStatus = (string) ($task['status'] ?? 'open');
$formSource = (string) ($task['source'] ?? '');
$formNotes = (string) ($task['notes'] ?? '');
$formCorrespondenceId = (int) ($task['correspondence_id'] ?? 0);
$formMeetingId = (int) ($task['meeting_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    $formCategory = (string) ($_POST['category'] ?? 'other');
    $formTitle = trim((string) ($_POST['title'] ?? ''));
    $formOwner = trim((string) ($_POST['owner'] ?? ''));
    $formDueAt = trim((string) ($_POST['due_at'] ?? ''));
    $formPriority = (string) ($_POST['priority'] ?? 'normal');
    $formStatus = (string) ($_POST['status'] ?? 'open');
    $formSource = trim((string) ($_POST['source'] ?? ''));
    $formNotes = trim((string) ($_POST['notes'] ?? ''));
    $formCorrespondenceId = (int) ($_POST['correspondence_id'] ?? 0);
    $formMeetingId = (int) ($_POST['meeting_id'] ?? 0);

    if ($formTitle === '') {
        $errors[] = 'A task title is required.';
    }

    if (!$errors) {
        try {
            $userId = (int) ($_SESSION['hub_user_id'] ?? 0) ?: null;
            secretary_task_save($pdo, $action === 'edit' ? $id : null, [
                'category' => $formCategory,
                'title' => $formTitle,
                'owner' => $formOwner,
                'due_at' => $formDueAt,
                'priority' => $formPriority,
                'status' => $formStatus,
                'source' => $formSource,
                'notes' => $formNotes,
                'correspondence_id' => $formCorrespondenceId,
                'meeting_id' => $formMeetingId,
            ], $userId);
            auditLog($pdo, $action === 'edit' ? 'secretary_task_updated' : 'secretary_task_created', ($action === 'edit' ? 'Updated' : 'Added') . ' task "' . $formTitle . '"');
            header('Location: secretary_tasks.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?>

<div class="secretary-task-edit-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="secretary_tasks.php">Tasks &amp; season planner</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Add task' : 'Edit task' ?></span></nav>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">This task could not be saved.</div>
                <ul class="mb-0 mt-1">
                    <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="secretary_task_edit.php<?= $action === 'edit' ? '?id=' . (int) $id : '' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="correspondence_id" value="<?= (int) $formCorrespondenceId ?>">
        <input type="hidden" name="meeting_id" value="<?= (int) $formMeetingId ?>">

        <div class="hub-section">
            <div class="row g-3">
                <div class="col-md-8">
                    <label for="taskTitle" class="form-label">Task <span aria-hidden="true">*</span></label>
                    <input type="text" class="form-control" id="taskTitle" name="title" value="<?= h($formTitle) ?>" required placeholder="e.g. Submit cup entry form">
                </div>
                <div class="col-md-4">
                    <label for="taskCategory" class="form-label">Category</label>
                    <select class="form-select" id="taskCategory" name="category">
                        <?php foreach (SECRETARY_TASK_CATEGORIES as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $formCategory === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label for="taskOwner" class="form-label">Owner</label>
                    <input type="text" class="form-control" id="taskOwner" name="owner" value="<?= h($formOwner) ?>" placeholder="Who is doing this">
                </div>
                <div class="col-md-4">
                    <label for="taskDueAt" class="form-label">Due date</label>
                    <input type="date" class="form-control" id="taskDueAt" name="due_at" value="<?= h($formDueAt) ?>">
                </div>
                <div class="col-md-4">
                    <label for="taskPriority" class="form-label">Priority</label>
                    <select class="form-select" id="taskPriority" name="priority">
                        <option value="low" <?= $formPriority === 'low' ? 'selected' : '' ?>>Low</option>
                        <option value="normal" <?= $formPriority === 'normal' ? 'selected' : '' ?>>Normal</option>
                        <option value="urgent" <?= $formPriority === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="taskStatus" class="form-label">Status</label>
                    <select class="form-select" id="taskStatus" name="status">
                        <option value="open" <?= $formStatus === 'open' ? 'selected' : '' ?>>Open</option>
                        <option value="waiting" <?= $formStatus === 'waiting' ? 'selected' : '' ?>>Waiting on someone else</option>
                        <option value="done" <?= $formStatus === 'done' ? 'selected' : '' ?>>Done</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="taskSource" class="form-label">Source</label>
                    <input type="text" class="form-control" id="taskSource" name="source" value="<?= h($formSource) ?>" placeholder="e.g. WoSFL email, committee meeting">
                </div>

                <div class="col-12">
                    <label for="taskNotes" class="form-label">Notes</label>
                    <textarea class="form-control" id="taskNotes" name="notes" rows="4"><?= h($formNotes) ?></textarea>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <a href="secretary_tasks.php" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-brand">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    <?= $action === 'new' ? 'Add Task' : 'Save Changes' ?>
                </button>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
