<?php
declare(strict_types=1);

/** Help Centre editor — Administrators (Site Admins) only. */

$id = (int) ($_GET['id'] ?? 0);
$pageHero = [
    'eyebrow' => 'Help',
    'title' => $id > 0 ? 'Edit help article' : 'New help article',
    'subtitle' => 'Written in Markdown. Leave "who can see it" empty to show it to everyone signed in.',
    'actions' => [['label' => '← Help Centre', 'href' => 'help.php', 'class' => 'btn btn-outline-secondary btn-sm']],
];
$pageStyles = ['help.css'];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/help.php';
require_once __DIR__ . '/lib/audit.php';

if (!hub_auth_is_admin()) {
    http_response_code(403);
    echo '<div class="alert alert-danger">Only Site Admins can edit help articles.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$capabilityRows = $pdo->query('SELECT slug, label FROM capabilities ORDER BY sort_order, slug')->fetchAll(PDO::FETCH_ASSOC);
$validCaps = array_column($capabilityRows, 'slug');

$article = $id > 0 ? $pdo->query('SELECT * FROM help_articles WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC) : false;
if ($id > 0 && !$article) {
    echo '<div class="alert alert-danger">Article not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$errors = [];
$data = $article ?: ['slug' => '', 'area' => '', 'title' => '', 'summary' => '', 'body' => '', 'capabilities' => '', 'pages' => '', 'sort_order' => 100, 'is_published' => 1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'delete' && $article) {
        $pdo->prepare('DELETE FROM help_articles WHERE id = :id')->execute([':id' => $id]);
        auditLog($pdo, 'help.delete', 'Deleted help article ' . $article['slug']);
        header('Location: help.php');
        exit;
    } else {
        $caps = array_values(array_intersect($validCaps, (array) ($_POST['capabilities'] ?? [])));
        $data = [
            'slug' => help_slugify((string) ($_POST['slug'] ?? '')) ?: help_slugify((string) ($_POST['title'] ?? '')),
            'area' => trim((string) ($_POST['area'] ?? '')),
            'title' => trim((string) ($_POST['title'] ?? '')),
            'summary' => trim((string) ($_POST['summary'] ?? '')),
            'body' => (string) ($_POST['body'] ?? ''),
            'capabilities' => implode(',', $caps),
            'pages' => implode(',', array_values(array_unique(array_filter(array_map(static fn ($p) => preg_replace('/[^A-Za-z0-9_.*-]/', '', trim($p)), preg_split('/[\s,]+/', (string) ($_POST['pages'] ?? '')) ?: []))))),
            'sort_order' => (int) ($_POST['sort_order'] ?? 100),
            'is_published' => isset($_POST['is_published']) ? 1 : 0,
        ];
        if ($data['title'] === '') $errors[] = 'A title is required.';
        if ($data['area'] === '') $errors[] = 'An area is required (it groups articles on the Help Centre).';
        if (trim($data['body']) === '') $errors[] = 'The article text is empty.';
        if ($data['slug'] === '') $errors[] = 'A web address name (slug) is required.';
        if (!$errors) {
            $dup = $pdo->prepare('SELECT id FROM help_articles WHERE slug = :s AND id <> :id');
            $dup->execute([':s' => $data['slug'], ':id' => $id]);
            if ($dup->fetchColumn()) $errors[] = 'Another article already uses that web address name.';
        }
        if (!$errors) {
            $by = (string) (hub_auth_current_user()['display_name'] ?? hub_auth_current_user()['email'] ?? '');
            $params = [
                ':slug' => $data['slug'], ':area' => $data['area'], ':title' => $data['title'],
                ':summary' => mb_substr($data['summary'], 0, 255), ':body' => $data['body'],
                ':caps' => $data['capabilities'], ':pages' => $data['pages'], ':sort' => $data['sort_order'],
                ':pub' => $data['is_published'], ':by' => mb_substr($by, 0, 190),
            ];
            if ($id > 0) {
                $params[':id'] = $id;
                $pdo->prepare('UPDATE help_articles SET slug=:slug, area=:area, title=:title, summary=:summary, body=:body,
                    capabilities=:caps, pages=:pages, sort_order=:sort, is_published=:pub, updated_by=:by WHERE id=:id')->execute($params);
            } else {
                $pdo->prepare('INSERT INTO help_articles (slug, area, title, summary, body, capabilities, pages, sort_order, is_published, updated_by)
                    VALUES (:slug, :area, :title, :summary, :body, :caps, :pages, :sort, :pub, :by)')->execute($params);
            }
            auditLog($pdo, $id > 0 ? 'help.update' : 'help.create', 'Help article ' . $data['slug']);
            header('Location: help.php?a=' . rawurlencode($data['slug']));
            exit;
        }
    }
}
$selectedCaps = help_parse_caps((string) $data['capabilities']);
?>

<?php if ($errors): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="help-editor row g-3">
    <?= csrf_field() ?>
    <div class="col-lg-8">
        <div class="mb-3"><label class="form-label" for="hTitle">Title</label>
            <input class="form-control" id="hTitle" name="title" maxlength="190" value="<?= h($data['title']) ?>" required></div>
        <div class="mb-3"><label class="form-label" for="hSummary">Short summary <span class="text-muted">(shown on the card)</span></label>
            <input class="form-control" id="hSummary" name="summary" maxlength="255" value="<?= h($data['summary']) ?>"></div>
        <div class="mb-3"><label class="form-label" for="hBody">Article <span class="text-muted">(Markdown: ## headings, - lists, **bold**, [links](url))</span><?= hub_help_hint('To show a line only while a menu section is switched on, end it with [[needs:secretary]] (a section) or [[needs:sponsorship.sponsor_followups]] (one menu item). Use [[needs:!secretary]] to show a line only while it is switched off.') ?></label>
            <textarea class="form-control" id="hBody" name="body" rows="20" style="min-height:26rem;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.9rem" required><?= h($data['body']) ?></textarea>
            <div class="d-flex align-items-center gap-2 mt-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="hImgBtn"><i class="fa-solid fa-image me-1" aria-hidden="true"></i>Add image</button>
                <input type="file" id="hImgFile" accept="image/png,image/jpeg,image/webp" hidden>
                <span class="form-text m-0" id="hImgMsg">PNG, JPG or WebP under 2 MB. It is inserted where your cursor is. Only signed-in users can see it.</span>
            </div></div>
    </div>
    <div class="col-lg-4">
        <div class="mb-3"><label class="form-label" for="hArea">Area</label>
            <input class="form-control" id="hArea" name="area" maxlength="80" list="hAreas" value="<?= h($data['area']) ?>" required>
            <datalist id="hAreas"><?php foreach ($pdo->query('SELECT DISTINCT area FROM help_articles ORDER BY area')->fetchAll(PDO::FETCH_COLUMN) as $areaName): ?><option value="<?= h((string) $areaName) ?>"><?php endforeach; ?></datalist></div>
        <div class="mb-3"><label class="form-label" for="hSlug">Web address name</label>
            <input class="form-control" id="hSlug" name="slug" maxlength="120" value="<?= h($data['slug']) ?>" placeholder="auto from title">
            <div class="form-text">Changing this breaks existing links to the article.</div></div>
        <div class="mb-3"><label class="form-label" for="hPages">Show on these pages<?= hub_help_hint('Shown in the ? help panel on these admin pages. Use the page file name from the address bar, e.g. sponsors.php. A * works as a wildcard, e.g. shop_* covers every shop page. Separate with commas.') ?></label>
            <textarea class="form-control" id="hPages" name="pages" rows="2" style="min-height:0;font-family:inherit" placeholder="e.g. sponsors.php, sponsor.php"><?= h($data['pages']) ?></textarea></div>
        <div class="mb-3"><label class="form-label" for="hSort">Order</label>
            <input class="form-control" type="number" id="hSort" name="sort_order" value="<?= (int) $data['sort_order'] ?>">
            <div class="form-text">Lower numbers appear first.</div></div>
        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="hPub" name="is_published" value="1" <?= (int) $data['is_published'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="hPub">Published</label>
            <div class="form-text">Untick to keep it as a draft only Site Admins see.</div></div>
        <fieldset class="mb-3"><legend class="form-label fs-6">Who can see it</legend>
            <div class="form-text mb-2">Tick none for everyone. Otherwise people need at least one of these.</div>
            <?php foreach ($capabilityRows as $cap): ?>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="capabilities[]" id="cap_<?= h($cap['slug']) ?>" value="<?= h($cap['slug']) ?>" <?= in_array($cap['slug'], $selectedCaps, true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cap_<?= h($cap['slug']) ?>"><?= h($cap['label']) ?></label></div>
            <?php endforeach; ?>
        </fieldset>
        <div class="d-flex gap-2">
            <button class="btn btn-brand" type="submit">Save</button>
            <a class="btn btn-outline-secondary" href="help.php">Cancel</a>
        </div>
    </div>
</form>

<?php if ($article): ?>
<form method="post" class="mt-4" onsubmit="return confirm('Delete this article? This cannot be undone.');">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete article</button>
</form>
<?php endif; ?>

<script>
(function () {
    var btn = document.getElementById('hImgBtn'), file = document.getElementById('hImgFile'),
        msg = document.getElementById('hImgMsg'), body = document.getElementById('hBody');
    btn.addEventListener('click', function () { file.click(); });
    file.addEventListener('change', function () {
        if (!file.files.length) return;
        var fd = new FormData();
        fd.append('file', file.files[0]);
        fd.append('csrf_token', (window.hubHelpConfig || {}).csrf || '');
        msg.textContent = 'Uploading…';
        fetch('help_image_upload.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { msg.textContent = d.error || 'Upload failed.'; return; }
                var at = body.selectionStart || body.value.length;
                body.value = body.value.slice(0, at) + '\n\n' + d.markdown + '\n\n' + body.value.slice(at);
                msg.textContent = 'Image added. Edit the text in the square brackets to describe it.';
            })
            .catch(function () { msg.textContent = 'Upload failed. Try again.'; })
            .finally(function () { file.value = ''; });
    });
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
