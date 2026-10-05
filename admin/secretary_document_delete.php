<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => 'Delete Document',
    'subtitle' => 'Review the document before permanently removing it.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/secretary_documents.php';
require_once __DIR__ . '/lib/audit.php';

$id = (int) ($_GET['id'] ?? 0);
$document = $id > 0 ? secretary_document_get($pdo, $id) : null;

if (!$document) {
    echo '<div><div class="alert alert-danger">Document not found.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        try {
            secretary_document_delete($pdo, $id);
            auditLog($pdo, 'secretary_document_deleted', 'Deleted secretary document "' . (string) $document['title'] . '"');
            header('Location: secretary_documents.php?deleted=1');
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>

<div class="secretary-document-delete-page">
    <div class="venue-delete-panel">
        <div class="venue-delete-panel__icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></div>
        <div class="venue-delete-panel__content">
            <div class="venue-editor-panel__eyebrow">Permanent action</div>
            <h2>Delete "<?= h((string) $document['title']) ?>"?</h2>
            <p>This removes the file from storage and cannot be undone.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" class="venue-delete-actions">
                <?= csrf_field() ?>
                <a href="secretary_documents.php" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-danger">
                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                    Delete Document
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
