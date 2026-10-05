<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => 'Documents & Records',
    'subtitle' => 'The handbook\'s folder structure, kept behind login — restricted categories are not directly downloadable.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/secretary_documents.php';
require_once __DIR__ . '/lib/audit.php';

$errors = [];
$uploaded = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } else {
        $userId = (int) ($_SESSION['hub_user_id'] ?? 0) ?: null;
        $result = secretary_document_upload(
            $pdo,
            $_FILES['document'] ?? [],
            (string) ($_POST['category'] ?? 'sfa'),
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['description'] ?? ''),
            !empty($_POST['restricted']),
            $userId
        );
        if ($result['ok']) {
            auditLog($pdo, 'secretary_document_uploaded', 'Uploaded secretary document "' . (string) ($_POST['title'] ?? '') . '"');
            header('Location: secretary_documents.php?uploaded=1');
            exit;
        }
        $errors[] = $result['message'];
    }
}

$documents = secretary_documents_list($pdo);
$byCategory = [];
foreach ($documents as $document) {
    $byCategory[$document['category']][] = $document;
}
?>

<div class="secretary-documents-page">
    <div class="venues-notices" aria-live="polite">
        <?php if (isset($_GET['uploaded'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Document uploaded.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Document deleted.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
    </div>

    <?php hub_render_metric_grid([
        ['label' => 'Documents stored', 'value' => number_format(count($documents)), 'meta' => 'Across all categories', 'icon' => 'fa-folder-tree', 'tone' => 'primary'],
        ['label' => 'Restricted', 'value' => number_format(count(array_filter($documents, static fn(array $d): bool => (int) $d['restricted'] === 1))), 'meta' => 'Flagged as restricted', 'icon' => 'fa-lock', 'tone' => 'warning'],
    ], 'Document summary'); ?>

    <section class="hub-section" aria-labelledby="secretaryDocumentUploadTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Upload</div>
                <h2 id="secretaryDocumentUploadTitle">Add a document</h2>
                <p>PDF, Word, Excel, JPG or PNG, up to 15MB. Files are named automatically as YYYY-MM-DD - Title - Description.</p>
            </div>
        </div>

        <form method="post" enctype="multipart/form-data" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label for="docCategory" class="form-label">Category</label>
                <select class="form-select" id="docCategory" name="category">
                    <?php foreach (SECRETARY_DOCUMENT_CATEGORIES as $key => $label): ?>
                        <option value="<?= h($key) ?>"><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="docTitle" class="form-label">Title <span aria-hidden="true">*</span></label>
                <input type="text" class="form-control" id="docTitle" name="title" required placeholder="e.g. Newmains United Match Administration">
            </div>
            <div class="col-md-4">
                <label for="docFile" class="form-label">File <span aria-hidden="true">*</span></label>
                <input type="file" class="form-control" id="docFile" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png">
            </div>
            <div class="col-md-8">
                <label for="docDescription" class="form-label">Description</label>
                <input type="text" class="form-control" id="docDescription" name="description" placeholder="Short description for the filename">
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="docRestricted" name="restricted" value="1">
                    <label class="form-check-label" for="docRestricted">Restricted (sensitive/safeguarding content)</label>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-brand">
                    <i class="fa-solid fa-upload" aria-hidden="true"></i>
                    Upload
                </button>
            </div>
        </form>
    </section>

    <?php foreach (SECRETARY_DOCUMENT_CATEGORIES as $categoryKey => $categoryLabel): ?>
        <?php $categoryDocs = $byCategory[$categoryKey] ?? []; ?>
        <?php if ($categoryDocs === []) continue; ?>
        <section class="hub-section" aria-labelledby="docCat<?= h($categoryKey) ?>Title">
            <div class="venues-directory__header">
                <div>
                    <div class="venues-directory__eyebrow"><?= (int) count($categoryDocs) ?> document<?= count($categoryDocs) === 1 ? '' : 's' ?></div>
                    <h2 id="docCat<?= h($categoryKey) ?>Title"><?= h($categoryLabel) ?></h2>
                </div>
            </div>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr><th>Title</th><th>Uploaded</th><th></th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categoryDocs as $document): ?>
                            <tr>
                                <td>
                                    <?= h((string) $document['title']) ?>
                                    <?php if ($document['description']): ?><div class="venues-muted"><?= h((string) $document['description']) ?></div><?php endif; ?>
                                </td>
                                <td><?= h(date('d/m/Y', strtotime((string) $document['uploaded_at']))) ?></td>
                                <td><?php if ((int) $document['restricted'] === 1): ?><span class="badge text-bg-warning"><i class="fa-solid fa-lock me-1" aria-hidden="true"></i>Restricted</span><?php endif; ?></td>
                                <td class="text-end">
                                    <a href="secretary_document_download.php?id=<?= (int) $document['id'] ?>" class="btn btn-sm btn-outline-primary" title="Open" target="_blank" rel="noopener noreferrer">
                                        <i class="fa-solid fa-download" aria-hidden="true"></i>
                                    </a>
                                    <a href="secretary_document_delete.php?id=<?= (int) $document['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endforeach; ?>

    <?php if ($documents === []): ?>
        <div class="hub-empty-state">
            <i class="fa-solid fa-folder-open" aria-hidden="true"></i>
            <h3>No documents stored yet</h3>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
