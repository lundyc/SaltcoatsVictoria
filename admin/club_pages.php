<?php

declare(strict_types=1);

/**
 * Club pages — editable static content for the public website (history,
 * ground & directions, club officials, privacy policy, plus any custom pages).
 */

$pageHero = [
    'eyebrow' => 'Public website',
    'title' => 'Club pages',
    'subtitle' => 'Static content shown under /club on the website. Markdown supported.',
    'actions' => [
        ['label' => 'New page', 'href' => 'club_pages.php?id=new', 'class' => 'btn btn-brand btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/club_pages.php';
require_once __DIR__ . '/lib/audit.php';

club_pages_ensure_schema($pdo);
club_pages_seed($pdo);

$editId = $_GET['id'] ?? null;
$isNew = $editId === 'new';
$id = $isNew ? 0 : (int) $editId;
$errors = [];
$saved = isset($_GET['saved']);

$page = $id > 0 ? club_page_find($pdo, $id) : null;
if ($id > 0 && !$page) {
    echo '<div class="alert alert-danger">Page not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    }
    $action = (string) ($_POST['form_action'] ?? 'save');

    if ($action === 'delete' && $id > 0) {
        if (!$errors) {
            club_page_delete($pdo, $id);
            auditLog($pdo, 'club_page_deleted', "Deleted club page '" . (string) ($page['title'] ?? '') . "'");
            header('Location: club_pages.php');
            exit;
        }
    } else {
        $data = [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'slug' => trim((string) ($_POST['slug'] ?? '')),
            'body' => (string) ($_POST['body'] ?? ''),
            'body_format' => 'markdown',
            'is_published' => isset($_POST['is_published']) ? 1 : 0,
            'menu_label' => trim((string) ($_POST['menu_label'] ?? '')),
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
        ];
        if ($data['title'] === '') {
            $errors[] = 'A title is required.';
        }
        if (!$errors) {
            $newId = club_page_save($pdo, $id ?: null, $data, (int) ($currentUser['id'] ?? 0) ?: null);
            auditLog($pdo, $id > 0 ? 'club_page_updated' : 'club_page_created', ($id > 0 ? 'Updated' : 'Created') . " club page '{$data['title']}'");
            header('Location: club_pages.php?id=' . $newId . '&saved=1');
            exit;
        }
        $page = array_merge((array) $page, $data);
    }
}

$pages = club_pages_all($pdo);
$showForm = $isNew || $id > 0;
$form = array_merge([
    'title' => '', 'slug' => '', 'body' => '', 'is_published' => 1, 'menu_label' => '', 'sort_order' => 0,
], $page ?: []);
?>

<?php if ($saved): ?><div class="alert alert-success">Page saved.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card hub-section">
      <div class="list-group list-group-flush">
        <?php foreach ($pages as $p): ?>
          <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?= $id === (int) $p['id'] ? 'active' : '' ?>" href="club_pages.php?id=<?= (int) $p['id'] ?>">
            <span>
              <?= h((string) $p['title']) ?>
              <span class="d-block small <?= $id === (int) $p['id'] ? '' : 'text-muted' ?>">/club/<?= h((string) $p['slug']) ?></span>
            </span>
            <span class="badge <?= (int) $p['is_published'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $p['is_published'] === 1 ? 'Live' : 'Draft' ?></span>
          </a>
        <?php endforeach; ?>
        <?php if (!$pages): ?><div class="list-group-item text-muted">No pages yet.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <?php if (!$showForm): ?>
      <div class="card hub-section"><div class="card-body text-muted">Select a page to edit, or create a new one.</div></div>
    <?php else: ?>
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.css">
      <form method="post" class="card hub-section">
        <div class="card-body">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="title">Title</label>
            <input class="form-control" id="title" name="title" value="<?= h((string) $form['title']) ?>" required>
          </div>
          <div class="row g-3">
            <div class="col-sm-6">
              <label class="form-label" for="slug">Slug</label>
              <div class="input-group">
                <span class="input-group-text">/club/</span>
                <input class="form-control" id="slug" name="slug" value="<?= h((string) $form['slug']) ?>" placeholder="auto from title">
              </div>
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="menu_label">Menu label</label>
              <input class="form-control" id="menu_label" name="menu_label" value="<?= h((string) $form['menu_label']) ?>" placeholder="Leave blank to hide from menus">
            </div>
          </div>
          <div class="row g-3 mt-0 align-items-end">
            <div class="col-sm-4">
              <label class="form-label" for="sort_order">Sort order</label>
              <input class="form-control" type="number" id="sort_order" name="sort_order" value="<?= (int) $form['sort_order'] ?>">
            </div>
            <div class="col-sm-8">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="is_published" name="is_published" value="1" <?= (int) $form['is_published'] === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="is_published">Published</label>
              </div>
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label" for="body">Content (Markdown)</label>
            <textarea id="body" name="body"><?= h((string) $form['body']) ?></textarea>
          </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
          <?php if ($id > 0): ?>
            <button class="btn btn-outline-danger" name="form_action" value="delete" formnovalidate
                    data-confirm="This permanently deletes the page." data-confirm-title="Delete this page?" data-confirm-action="Delete">Delete</button>
          <?php else: ?><span></span><?php endif; ?>
          <div class="d-flex gap-2">
            <?php if ($id > 0 && (int) $form['is_published'] === 1): ?>
              <a class="btn btn-outline-secondary" href="/club/<?= h((string) $form['slug']) ?>" target="_blank" rel="noopener">View</a>
            <?php endif; ?>
            <button class="btn btn-brand" name="form_action" value="save" type="submit"><?= $id > 0 ? 'Save changes' : 'Create page' ?></button>
          </div>
        </div>
      </form>
      <script src="https://cdn.jsdelivr.net/npm/easymde@2.18.0/dist/easymde.min.js"></script>
      <script>new EasyMDE({ element: document.getElementById('body'), spellChecker: false, status: ['lines', 'words'], minHeight: '320px',
        toolbar: ['bold','italic','heading','|','quote','unordered-list','ordered-list','|','link','table','|','preview','guide'] });</script>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
