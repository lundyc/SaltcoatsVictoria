<?php
$id = (int) ($_GET['id'] ?? 0);
$action = $id > 0 ? 'edit' : 'new';
$pageHero = [
    'eyebrow' => 'Members',
    'title' => $action === 'new' ? 'Add Announcement' : 'Edit Announcement',
    'subtitle' => 'Shown to logged-in season ticket holders once published.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/announcements.php';
require_once __DIR__ . '/lib/audit.php';
ensureAnnouncementsSchema($pdo);

$errors = [];
$announcement = $id > 0 ? getAnnouncement($pdo, $id) : null;
if ($id > 0 && !$announcement) {
    echo '<div class="alert alert-danger">Announcement not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$data = array_merge([
    'title' => '',
    'body' => '',
    'is_published' => 0,
    'published_at' => date('Y-m-d\TH:i'),
], $announcement ?: []);
if (!empty($announcement['published_at'])) {
    $data['published_at'] = date('Y-m-d\TH:i', strtotime((string) $announcement['published_at']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Invalid security token. Please try again.';
    }
    $formAction = (string) ($_POST['form_action'] ?? 'save');

    if ($formAction === 'delete') {
        if (!$id) {
            $errors[] = 'Announcement not found.';
        }
        if (!$errors) {
            deleteAnnouncement($pdo, $id);
            auditLog($pdo, 'announcement_deleted', "Deleted announcement '" . (string) ($data['title'] ?? '') . "'");
            header('Location: announcements.php?deleted=1');
            exit;
        }
    } else {
        $data['title'] = trim((string) ($_POST['title'] ?? ''));
        $data['body'] = trim((string) ($_POST['body'] ?? ''));
        $data['published_at'] = trim((string) ($_POST['published_at'] ?? ''));
        $data['is_published'] = isset($_POST['is_published']) ? 1 : 0;

        if ($data['title'] === '') {
            $errors[] = 'Title is required.';
        }
        if ($data['body'] === '') {
            $errors[] = 'Body is required.';
        }

        if (!$errors) {
            $saveData = $data;
            $saveData['published_at'] = $data['published_at'] !== '' ? str_replace('T', ' ', $data['published_at']) . ':00' : '';
            saveAnnouncement($pdo, $id ?: null, $saveData, (int) ($currentUser['id'] ?? 0) ?: null);
            auditLog($pdo, $id > 0 ? 'announcement_updated' : 'announcement_created', ($id > 0 ? 'Updated' : 'Created') . " announcement '{$data['title']}'");
            header('Location: announcements.php?saved=1');
            exit;
        }
    }
}
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0">
        <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
    </ul></div>
<?php endif; ?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="announcements.php">Announcements</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Add' : 'Edit' ?></span></nav>

<form method="post" class="card shadow-sm border-0">
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label for="announcementTitle" class="form-label">Title</label>
            <input type="text" class="form-control" id="announcementTitle" name="title" value="<?= h((string) $data['title']) ?>" required>
        </div>
        <div class="mb-3">
            <label for="announcementBody" class="form-label">Body</label>
            <textarea class="form-control" id="announcementBody" name="body" rows="6" required><?= h((string) $data['body']) ?></textarea>
        </div>
        <div class="row g-3 align-items-end">
            <div class="col-md-6">
                <label for="announcementPublishedAt" class="form-label">Publish at</label>
                <input type="datetime-local" class="form-control" id="announcementPublishedAt" name="published_at" value="<?= h((string) $data['published_at']) ?>">
            </div>
            <div class="col-md-6">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="announcementPublished" name="is_published" value="1" <?= !empty($data['is_published']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="announcementPublished">Published (visible to members)</label>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex flex-wrap justify-content-between gap-2">
        <div class="d-flex gap-2">
            <a href="announcements.php" class="btn btn-outline-secondary">Cancel</a>
            <?php if ($id > 0): ?><button type="submit" class="btn btn-outline-danger" name="form_action" value="delete" formnovalidate data-confirm="This will permanently delete this announcement." data-confirm-title="Delete this announcement?" data-confirm-action="Delete">Delete</button><?php endif; ?>
        </div>
        <button type="submit" class="btn btn-brand" name="form_action" value="save"><?= $action === 'new' ? 'Create' : 'Save changes' ?></button>
    </div>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>
