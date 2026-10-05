<?php
$id = (int) ($_GET['id'] ?? 0);
$pageHero = [
    'eyebrow' => 'Members',
    'title' => 'Feedback',
    'subtitle' => 'Review, update progress, and leave comments.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/feedback.php';
require_once __DIR__ . '/lib/audit.php';
ensureFeedbackSchema($pdo);

$item = getFeedbackItem($pdo, $id);
if (!$item) {
    echo '<div class="alert alert-danger">Feedback not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        $formAction = (string) ($_POST['form_action'] ?? '');
        if ($formAction === 'status') {
            try {
                updateFeedbackStatus($pdo, $id, (string) ($_POST['status'] ?? ''));
                auditLog($pdo, 'feedback_status_updated', "Set status of feedback #{$id} to " . (string) ($_POST['status'] ?? ''));
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        } elseif ($formAction === 'comment') {
            addFeedbackComment($pdo, $id, (int) ($currentUser['id'] ?? 0) ?: null, (string) ($_POST['comment'] ?? ''));
            auditLog($pdo, 'feedback_comment_added', "Added a comment to feedback #{$id}");
        }
        if (!$errors) {
            header('Location: feedback_item.php?id=' . $id . '&saved=1');
            exit;
        }
    }
}

$statusOptions = feedbackStatusOptions();
$comments = getFeedbackComments($pdo, $id);
?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="feedback.php">Feedback</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">#<?= $id ?></span></nav>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Saved.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $e): ?><?= h($e) ?><?php endforeach; ?></div><?php endif; ?>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <div class="h4 mb-1"><?= str_repeat('★', (int) $item['rating']) . str_repeat('☆', 5 - (int) $item['rating']) ?></div>
                <p class="mb-1"><strong><?= h((string) $item['holder_name']) ?></strong> <span class="text-muted">(<?= h((string) $item['holder_email']) ?>)</span></p>
                <p class="text-muted small mb-0">Submitted <?= h(date('d/m/Y H:i', strtotime((string) $item['created_at']))) ?> on <?= h((string) ($item['page'] ?: 'unknown page')) ?></p>
            </div>
            <form method="post" class="d-flex align-items-center gap-2">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="status">
                <select class="form-select form-select-sm" name="status" onchange="this.form.submit()">
                    <?php foreach ($statusOptions as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $item['status'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php if (!empty($item['comment'])): ?>
            <hr>
            <p class="mb-0" style="white-space:pre-wrap;"><?= h((string) $item['comment']) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent"><h2 class="h5 mb-0">Admin comments</h2></div>
    <div class="card-body">
        <?php foreach ($comments as $comment): ?>
            <div class="border-bottom py-2">
                <div class="small text-muted"><?= h((string) ($comment['display_name'] ?: $comment['username'] ?: 'Admin')) ?> · <?= h(date('d/m/Y H:i', strtotime((string) $comment['created_at']))) ?></div>
                <div style="white-space:pre-wrap;"><?= h((string) $comment['comment']) ?></div>
            </div>
        <?php endforeach; ?>
        <?php if (!$comments): ?><p class="text-muted mb-3">No comments yet.</p><?php endif; ?>
        <form method="post" class="mt-3">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="comment">
            <textarea class="form-control mb-2" name="comment" rows="3" placeholder="Add an internal comment…" required></textarea>
            <button type="submit" class="btn btn-brand btn-sm">Add comment</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
