<?php
declare(strict_types=1);

$id = (int) ($_GET['id'] ?? 0);
$action = $id > 0 ? 'edit' : 'new';

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => $action === 'new' ? 'Log Correspondence' : 'Edit Correspondence',
    'subtitle' => 'If it contains a deadline, put the deadline in the task system before closing it.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/secretary_tasks.php';
require_once __DIR__ . '/lib/audit.php';

$errors = [];

if ($action === 'edit') {
    $item = correspondence_get($pdo, $id);
    if (!$item) {
        echo '<div><div class="alert alert-danger">Correspondence item not found.</div></div>';
        require __DIR__ . '/footer.php';
        exit;
    }
} else {
    $item = [
        'received_at' => date('Y-m-d\TH:i'),
        'organisation' => '',
        'subject' => '',
        'label' => 'action',
        'deadline' => '',
        'action_owner' => '',
        'status' => 'open',
        'reference' => '',
        'notes' => '',
    ];
}

$formReceivedAt = (string) ($item['received_at'] ?? '');
$formOrganisation = (string) ($item['organisation'] ?? '');
$formSubject = (string) ($item['subject'] ?? '');
$formLabel = (string) ($item['label'] ?? 'action');
$formDeadline = (string) ($item['deadline'] ?? '');
$formActionOwner = (string) ($item['action_owner'] ?? '');
$formStatus = (string) ($item['status'] ?? 'open');
$formReference = (string) ($item['reference'] ?? '');
$formNotes = (string) ($item['notes'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    $formReceivedAt = trim((string) ($_POST['received_at'] ?? ''));
    $formOrganisation = trim((string) ($_POST['organisation'] ?? ''));
    $formSubject = trim((string) ($_POST['subject'] ?? ''));
    $formLabel = (string) ($_POST['label'] ?? 'action');
    $formDeadline = trim((string) ($_POST['deadline'] ?? ''));
    $formActionOwner = trim((string) ($_POST['action_owner'] ?? ''));
    $formStatus = (string) ($_POST['status'] ?? 'open');
    $formReference = trim((string) ($_POST['reference'] ?? ''));
    $formNotes = trim((string) ($_POST['notes'] ?? ''));

    if ($formSubject === '') {
        $errors[] = 'A subject is required.';
    }

    if (!$errors) {
        try {
            $userId = (int) ($_SESSION['hub_user_id'] ?? 0) ?: null;
            correspondence_save($pdo, $action === 'edit' ? $id : null, [
                'received_at' => $formReceivedAt,
                'organisation' => $formOrganisation,
                'subject' => $formSubject,
                'label' => $formLabel,
                'deadline' => $formDeadline,
                'action_owner' => $formActionOwner,
                'status' => $formStatus,
                'reference' => $formReference,
                'notes' => $formNotes,
            ], $userId);
            auditLog($pdo, $action === 'edit' ? 'secretary_correspondence_updated' : 'secretary_correspondence_created', ($action === 'edit' ? 'Updated' : 'Logged') . ' correspondence "' . $formSubject . '"');
            header('Location: secretary_correspondence.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?>

<div class="secretary-correspondence-edit-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="secretary_correspondence.php">Correspondence</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Log correspondence' : 'Edit correspondence' ?></span></nav>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">This correspondence item could not be saved.</div>
                <ul class="mb-0 mt-1">
                    <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="secretary_correspondence_edit.php<?= $action === 'edit' ? '?id=' . (int) $id : '' ?>">
        <?= csrf_field() ?>

        <div class="hub-section">
            <div class="row g-3">
                <div class="col-md-8">
                    <label for="corSubject" class="form-label">Subject <span aria-hidden="true">*</span></label>
                    <input type="text" class="form-control" id="corSubject" name="subject" value="<?= h($formSubject) ?>" required placeholder="e.g. Cup draw notification">
                </div>
                <div class="col-md-4">
                    <label for="corOrganisation" class="form-label">From</label>
                    <input type="text" class="form-control" id="corOrganisation" name="organisation" value="<?= h($formOrganisation) ?>" placeholder="e.g. WoSFL">
                </div>

                <div class="col-md-4">
                    <label for="corReceivedAt" class="form-label">Received</label>
                    <input type="datetime-local" class="form-control" id="corReceivedAt" name="received_at" value="<?= h($formReceivedAt !== '' ? date('Y-m-d\TH:i', strtotime($formReceivedAt)) : '') ?>">
                </div>
                <div class="col-md-4">
                    <label for="corLabel" class="form-label">Label</label>
                    <select class="form-select" id="corLabel" name="label">
                        <?php foreach (SECRETARY_TASK_LABELS as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $formLabel === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="corDeadline" class="form-label">Deadline</label>
                    <input type="date" class="form-control" id="corDeadline" name="deadline" value="<?= h($formDeadline) ?>">
                </div>

                <div class="col-md-6">
                    <label for="corActionOwner" class="form-label">Action owner</label>
                    <input type="text" class="form-control" id="corActionOwner" name="action_owner" value="<?= h($formActionOwner) ?>">
                </div>
                <div class="col-md-6">
                    <label for="corStatus" class="form-label">Status</label>
                    <select class="form-select" id="corStatus" name="status">
                        <option value="open" <?= $formStatus === 'open' ? 'selected' : '' ?>>Open</option>
                        <option value="waiting" <?= $formStatus === 'waiting' ? 'selected' : '' ?>>Waiting on reply</option>
                        <option value="filed" <?= $formStatus === 'filed' ? 'selected' : '' ?>>Filed — no further action</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="corReference" class="form-label">File/email reference</label>
                    <input type="text" class="form-control" id="corReference" name="reference" value="<?= h($formReference) ?>" placeholder="Where the original is filed">
                </div>

                <div class="col-12">
                    <label for="corNotes" class="form-label">Notes</label>
                    <textarea class="form-control" id="corNotes" name="notes" rows="4"><?= h($formNotes) ?></textarea>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <a href="secretary_correspondence.php" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-brand">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    <?= $action === 'new' ? 'Log Correspondence' : 'Save Changes' ?>
                </button>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
