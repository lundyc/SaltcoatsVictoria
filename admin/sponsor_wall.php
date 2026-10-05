<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/sponsor_wall.php';
require_once __DIR__ . '/account_auth.php';

// Gate here, before any of the POST handling below, rather than relying on
// header.php's require further down — that require used to come after the
// upload/delete/settings actions ran, so those write actions executed for
// anyone who could POST to this file, authenticated or not.
hub_auth_require_capability('content_social');

$messageType = '';
$message = '';
$details = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? trim($_POST['action']) : '';
    $uploads = sponsors_list_uploads();
    $uploadsByFilename = [];
    foreach ($uploads as $upload) {
        $uploadsByFilename[(string) $upload['name']] = $upload;
    }

    if ($action === 'upload') {
        $result = sponsors_store_uploaded_images($_FILES['images'] ?? []);
        $savedCount = count($result['saved']);

        if ($savedCount > 0) {
            $messageType = 'success';
            $message = $savedCount . ' image' . ($savedCount === 1 ? '' : 's') . ' uploaded.';
            $details = $result['errors'];
        } else {
            $messageType = 'error';
            $message = 'No images were uploaded.';
            $details = $result['errors'] !== [] ? $result['errors'] : ['Choose one or more image files.'];
        }
    } elseif ($action === 'delete_upload') {
        $filename = isset($_POST['filename']) && is_string($_POST['filename']) ? trim($_POST['filename']) : '';
        if ($filename !== '' && sponsors_delete_upload($filename)) {
            sponsors_current_wall_layout();
            $messageType = 'success';
            $message = 'Image deleted.';
        } else {
            $messageType = 'error';
            $message = 'Could not delete that image.';
        }
    } elseif ($action === 'save_layout') {
        $layoutJson = isset($_POST['layout_json']) && is_string($_POST['layout_json']) ? trim($_POST['layout_json']) : '[]';
        $decoded = json_decode($layoutJson, true);
        $savedLayout = [];
        $errors = [];

        if (!is_array($decoded)) {
            $errors[] = 'The layout data could not be read.';
        } else {
            foreach ($decoded as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $filename = basename(trim((string) ($entry['filename'] ?? '')));
                if ($filename === '' || !isset($uploadsByFilename[$filename])) {
                    continue;
                }

                $uid = trim((string) ($entry['uid'] ?? ''));
                if ($uid === '') {
                    $uid = sponsors_generate_uid();
                }

                $savedLayout[] = [
                    'uid' => $uid,
                    'filename' => $filename,
                ];
            }
        }

        if ($errors === [] && sponsors_save_wall_layout($savedLayout)) {
            $messageType = 'success';
            $message = 'Layout saved.';
        } else {
            $messageType = 'error';
            $message = 'The layout could not be saved.';
            $details = $errors !== [] ? $errors : ['Please try again.'];
        }
    } elseif ($action === 'save_settings') {
        $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? trim($_POST['mode']) : 'auto';
        $widthRaw = isset($_POST['width']) && is_string($_POST['width']) ? trim($_POST['width']) : '';
        $heightRaw = isset($_POST['height']) && is_string($_POST['height']) ? trim($_POST['height']) : '';
        $errors = [];

        if ($mode === 'custom') {
            $width = (int) $widthRaw;
            $height = (int) $heightRaw;
            if ($width < 200 || $height < 200) {
                $errors[] = 'Enter a custom width and height of at least 200 pixels.';
            }
            $settings = sponsors_normalize_wall_settings([
                'mode' => 'custom',
                'width' => $width,
                'height' => $height,
            ]);
        } else {
            $settings = sponsors_default_wall_settings();
        }

        if ($errors === [] && sponsors_save_wall_settings($settings)) {
            $messageType = 'success';
            $message = $settings['mode'] === 'custom'
                ? 'Wall size saved as ' . $settings['width'] . ' x ' . $settings['height'] . ' px.'
                : 'Wall size set to auto.';
        } else {
            $messageType = 'error';
            $message = 'The wall size could not be saved.';
            $details = $errors !== [] ? $errors : ['Please try again.'];
        }
    }
}

$uploads = sponsors_list_uploads();
$layout = sponsors_current_wall_layout();
$wallSettings = sponsors_current_wall_settings();
$uploadsByFilename = [];
foreach ($uploads as $upload) {
    $uploadsByFilename[(string) $upload['name']] = $upload;
}

$version = (string) sponsors_wall_cache_version();
$wallUrl = 'sponsor_wall_render.php?v=' . rawurlencode($version);
$downloadUrl = 'sponsor_wall_render.php?download=1&v=' . rawurlencode($version);
$uploadCount = count($uploads);
$layoutCount = count($layout);
$duplicateCount = $layoutCount - count(array_unique(array_map(static fn(array $entry): string => (string) $entry['filename'], $layout)));
$layoutFiles = [];
foreach ($layout as $entry) {
    $filename = (string) ($entry['filename'] ?? '');
    if ($filename !== '') {
        $layoutFiles[$filename] = true;
    }
}
$wallSizeLabel = $wallSettings['mode'] === 'custom'
    ? $wallSettings['width'] . ' x ' . $wallSettings['height'] . ' px'
    : 'Auto';

require_once __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="/admin/assets/css/sponsor_wall.css">

<div class="sponsor-wall-shell">
    <div class="page-hero mb-4">
        <div class="page-hero-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
            <div>
                <div class="page-hero-eyebrow">Creative studio</div>
                <h1 class="page-hero-title">Step & Repeat Display Wall</h1>
                <p class="page-hero-subtitle">Upload sponsor artwork, arrange the repeat, and export a branded display wall in one place.</p>
            </div>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert <?= $messageType === 'success' ? 'alert-success' : 'alert-danger' ?> border-0 shadow-sm">
            <strong><?= sponsors_safe($message) ?></strong>
            <?php if ($details !== []): ?>
                <div class="mt-1">
                    <?php foreach ($details as $detail): ?>
                        <div><?= sponsors_safe($detail) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php hub_render_metric_grid([
        ['label' => 'Uploaded files', 'value' => (int) $uploadCount, 'meta' => 'Available sponsor artwork', 'icon' => 'fa-images', 'tone' => 'primary', 'value_attrs' => ['id' => 'upload-count']],
        ['label' => 'Panels on wall', 'value' => (int) $layoutCount, 'meta' => 'Current layout total', 'icon' => 'fa-border-all', 'tone' => 'info', 'value_attrs' => ['id' => 'layout-count']],
        ['label' => 'Extra repeats', 'value' => max(0, (int) $duplicateCount), 'meta' => 'Repeated artwork panels', 'icon' => 'fa-clone', 'tone' => 'warning', 'value_attrs' => ['id' => 'duplicate-count']],
        ['label' => 'Backdrop size', 'value' => $wallSizeLabel, 'meta' => 'Export dimensions', 'icon' => 'fa-expand', 'tone' => 'neutral', 'value_attrs' => ['id' => 'wall-size-label']],
    ], 'Sponsor wall summary'); ?>

    <div class="card dashboard-card hub-form-card mb-4">
        <div class="card-body">
            <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-2 mb-3">
                <div>
                    <h2 class="h5 card-title mb-1">Backdrop size</h2>
                    <div class="text-muted">Set a custom export size for the rendered display wall or leave it on auto.</div>
                </div>
                <span class="wall-size-badge"><?= sponsors_safe($wallSizeLabel) ?></span>
            </div>

            <form method="post" class="row g-3 align-items-end hub-form-section">
                <div class="col-12 col-md-4">
                    <label for="wall-mode" class="form-label">Mode</label>
                    <select id="wall-mode" name="mode" class="form-select">
                        <option value="auto"<?= $wallSettings['mode'] === 'auto' ? ' selected' : '' ?>>Auto</option>
                        <option value="custom"<?= $wallSettings['mode'] === 'custom' ? ' selected' : '' ?>>Custom</option>
                    </select>
                </div>
                <div class="col-6 col-md-4">
                    <label for="wall-width" class="form-label">Width</label>
                    <input id="wall-width" name="width" type="number" min="200" max="20000" step="1" class="form-control" value="<?= $wallSettings['mode'] === 'custom' ? sponsors_safe((string) $wallSettings['width']) : '' ?>" placeholder="e.g. 1200">
                </div>
                <div class="col-6 col-md-4">
                    <label for="wall-height" class="form-label">Height</label>
                    <input id="wall-height" name="height" type="number" min="200" max="20000" step="1" class="form-control" value="<?= $wallSettings['mode'] === 'custom' ? sponsors_safe((string) $wallSettings['height']) : '' ?>" placeholder="e.g. 720">
                </div>
                <div class="col-12 d-flex flex-wrap gap-2 hub-actions">
                    <input type="hidden" name="action" value="save_settings">
                    <button type="submit" class="btn btn-brand">Save size</button>
                    <button type="button" class="btn btn-outline-secondary" id="wall-auto-button">Use auto</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-12 col-xl-8">
            <div class="card dashboard-card hub-section h-100">
                <div class="card-body">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-2 mb-3">
                        <div>
                            <h2 class="h5 card-title mb-1">Display wall layout</h2>
                            <div class="text-muted">Drag sponsor panels to build the step-and-repeat pattern.</div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 hub-actions">
                            <a href="<?= sponsors_safe($downloadUrl) ?>" class="btn btn-outline-secondary" download="display-wall.png"><i class="fa-solid fa-download me-1"></i>Download PNG</a>
                            <a href="<?= sponsors_safe($wallUrl) ?>" class="btn btn-outline-secondary" target="_blank" rel="noopener"><i class="fa-regular fa-eye me-1"></i>Open render</a>
                            <button type="button" class="btn btn-outline-secondary" id="resetLayoutBtn"><i class="fa-solid fa-rotate-left me-1"></i>Reset view</button>
                            <form method="post" id="save-layout-form" class="d-inline">
                                <input type="hidden" name="action" value="save_layout">
                                <input type="hidden" name="layout_json" id="layout-json" value="[]">
                                <button type="button" class="btn btn-brand" id="saveLayoutBtn">
                                    <i class="fa-solid fa-floppy-disk me-1"></i>Save layout
                                </button>
                            </form>
                            <button type="button" class="btn btn-outline-secondary" id="addAllBtn">
                                <i class="fa-solid fa-plus me-1"></i>Add all
                            </button>
                        </div>
                    </div>

                    <div class="wall-canvas">
                        <div class="layout-editor" id="layoutEditor">
                            <?php if ($layout === []): ?>
                                <div class="layout-editor-empty hub-empty-state">No sponsor tiles on the wall yet. Add one from the uploaded files below.</div>
                            <?php else: ?>
                                <?php foreach ($layout as $entry): ?>
                                    <?php
                                        $filename = (string) ($entry['filename'] ?? '');
                                        $upload = $uploadsByFilename[$filename] ?? null;
                                        $imageUrl = $upload !== null ? (string) $upload['url'] : sponsors_wall_image_url($filename);
                                    ?>
                                    <article class="layout-tile" draggable="true" data-uid="<?= sponsors_safe((string) ($entry['uid'] ?? '')) ?>" data-filename="<?= sponsors_safe($filename) ?>" data-url="<?= sponsors_safe($imageUrl) ?>">
                                        <img class="layout-tile__image" src="<?= sponsors_safe($imageUrl) ?>" alt="<?= sponsors_safe($filename) ?>" loading="lazy">
                                        <div class="layout-tile__overlay">
                                            <button class="btn btn-sm btn-outline-light js-duplicate" type="button" title="Duplicate tile">Duplicate</button>
                                            <button class="btn btn-sm btn-outline-danger js-remove" type="button" title="Remove tile">Remove</button>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card dashboard-card hub-form-card mb-3">
                <div class="card-body">
                    <h2 class="h5 card-title mb-3">Upload images</h2>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload">
                        <label class="upload-dropzone">
                            <input id="images" type="file" name="images[]" accept=".jpg,.jpeg,.png,.webp,.gif,image/*" multiple>
                            <div class="upload-dropzone__icon">+</div>
                            <strong>Drag and drop images here</strong>
                            <div class="upload-dropzone__hint">or click to choose multiple files</div>
                            <div class="small text-muted" id="selected-files">No files selected.</div>
                        </label>
                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="submit" class="btn btn-brand">Upload</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card dashboard-card hub-table-card">
                <div class="card-body">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-2 mb-3">
                        <h2 class="h5 card-title mb-0">Uploaded files</h2>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm hub-data-table align-middle files-table mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Image</th>
                                    <th>File</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="uploadedFilesList">
                                <?php if ($uploads === []): ?>
                                    <tr id="uploaded-files-empty">
                                        <td colspan="4" class="text-muted hub-record-empty">No images uploaded yet.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($uploads as $item): ?>
                                        <tr data-upload-row data-filename="<?= sponsors_safe((string) $item['name']) ?>" data-url="<?= sponsors_safe((string) $item['url']) ?>">
                                            <td>
                                                <img src="<?= sponsors_safe((string) $item['url']) ?>" alt="<?= sponsors_safe((string) $item['name']) ?>" loading="lazy">
                                            </td>
                                            <td>
                                                <strong><?= sponsors_safe((string) $item['name']) ?></strong>
                                                <div class="small text-muted">
                                                    <?= number_format((int) round(((int) $item['size']) / 1024)) ?> KB |
                                                    <?= date('j M Y, H:i', (int) $item['mtime']) ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php if (isset($layoutFiles[(string) $item['name']])): ?>
                                                    <span class="badge bg-brand-paid">On Wall</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Idle</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="d-flex flex-wrap gap-2">
                                                    <button class="btn btn-outline-secondary btn-sm js-add-to-wall" type="button" data-filename="<?= sponsors_safe((string) $item['name']) ?>" data-url="<?= sponsors_safe((string) $item['url']) ?>">Add</button>
                                                    <a class="btn btn-outline-secondary btn-sm" href="<?= sponsors_safe((string) $item['url']) ?>" target="_blank" rel="noopener">View</a>
                                                    <form method="post" data-confirm="This uploaded image will be permanently deleted." data-confirm-title="Delete this image?" data-confirm-action="Delete image" class="d-inline">
                                                        <input type="hidden" name="action" value="delete_upload">
                                                        <input type="hidden" name="filename" value="<?= sponsors_safe((string) $item['name']) ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const layoutEditor = document.getElementById('layoutEditor');
    const layoutInput = document.getElementById('layout-json');
    const saveLayoutForm = document.getElementById('save-layout-form');
    const saveLayoutBtn = document.getElementById('saveLayoutBtn');
    const resetLayoutBtn = document.getElementById('resetLayoutBtn');
    const addAllBtn = document.getElementById('addAllBtn');
    const wallAutoButton = document.getElementById('wall-auto-button');
    const wallMode = document.getElementById('wall-mode');
    const wallWidth = document.getElementById('wall-width');
    const wallHeight = document.getElementById('wall-height');
    const selectedFiles = document.getElementById('selected-files');
    const imagesInput = document.getElementById('images');
    const layoutEmptyTemplate = 'No sponsor tiles on the wall yet. Add one from the uploaded files below.';
    const uploadedRows = () => Array.from(document.querySelectorAll('[data-upload-row]'));

    function uid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'tile-' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
    }

    function tiles() {
        return Array.from(layoutEditor.querySelectorAll('.layout-tile'));
    }

    function syncEmptyState() {
        const existingEmpty = layoutEditor.querySelector('.layout-editor-empty');
        const tileCount = tiles().length;

        if (tileCount === 0) {
            if (!existingEmpty) {
                const empty = document.createElement('div');
                empty.className = 'layout-editor-empty';
                empty.textContent = layoutEmptyTemplate;
                layoutEditor.prepend(empty);
            }
            return;
        }

        existingEmpty?.remove();
    }

    function buildLayoutJson() {
        return tiles().map((tile) => ({
            uid: tile.dataset.uid || uid(),
            filename: tile.dataset.filename || '',
        })).filter((entry) => entry.filename !== '');
    }

    function updateLayoutInput() {
        if (layoutInput) {
            layoutInput.value = JSON.stringify(buildLayoutJson());
        }
    }

    function updateStats() {
        const uploadCount = document.getElementById('upload-count');
        const layoutCount = document.getElementById('layout-count');
        const duplicateCount = document.getElementById('duplicate-count');
        const uploadTotal = uploadedRows().length;
        const layoutEntries = buildLayoutJson();
        const filenames = layoutEntries.map((entry) => entry.filename);
        const uniqueCount = new Set(filenames).size;

        if (uploadCount) uploadCount.textContent = String(uploadTotal);
        if (layoutCount) layoutCount.textContent = String(layoutEntries.length);
        if (duplicateCount) duplicateCount.textContent = String(Math.max(0, layoutEntries.length - uniqueCount));

        uploadedRows().forEach((row) => {
            const badgeCell = row.children[2];
            const filename = row.dataset.filename || '';
            if (!badgeCell) {
                return;
            }
            badgeCell.innerHTML = filenames.includes(filename)
                ? '<span class="badge bg-brand-paid">On Wall</span>'
                : '<span class="badge bg-secondary">Idle</span>';
        });
    }

    function bindTile(tile) {
        tile.addEventListener('dragstart', (event) => {
            tile.classList.add('dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', tile.dataset.uid || '');
        });

        tile.addEventListener('dragend', () => {
            tile.classList.remove('dragging');
            updateLayoutInput();
            updateStats();
        });

        tile.querySelector('.js-remove')?.addEventListener('click', () => {
            tile.remove();
            syncEmptyState();
            updateLayoutInput();
            updateStats();
        });

        tile.querySelector('.js-duplicate')?.addEventListener('click', () => {
            const clone = tile.cloneNode(true);
            clone.dataset.uid = uid();
            bindTile(clone);
            tile.insertAdjacentElement('afterend', clone);
            syncEmptyState();
            updateLayoutInput();
            updateStats();
        });
    }

    function addTile(filename, url, uidValue) {
        if (!filename || !url) {
            return;
        }

        const tile = document.createElement('article');
        tile.className = 'layout-tile';
        tile.draggable = true;
        tile.dataset.uid = uidValue || uid();
        tile.dataset.filename = filename;
        tile.dataset.url = url;
        tile.innerHTML = `
            <img class="layout-tile__image" src="${url}" alt="${filename}" loading="lazy">
            <div class="layout-tile__overlay">
                <button class="btn btn-sm btn-outline-light js-duplicate" type="button">Duplicate</button>
                <button class="btn btn-sm btn-outline-danger js-remove" type="button">Remove</button>
            </div>
        `;
        bindTile(tile);
        syncEmptyState();
        layoutEditor.appendChild(tile);
        updateLayoutInput();
        updateStats();
    }

    layoutEditor.addEventListener('dragover', (event) => {
        event.preventDefault();
        const target = event.target.closest('.layout-tile');
        const dragging = layoutEditor.querySelector('.layout-tile.dragging');
        if (!dragging || !target || dragging === target) {
            return;
        }
        const rect = target.getBoundingClientRect();
        if ((event.clientY - rect.top) > rect.height / 2) {
            target.insertAdjacentElement('afterend', dragging);
        } else {
            target.insertAdjacentElement('beforebegin', dragging);
        }
    });

    layoutEditor.addEventListener('drop', (event) => {
        event.preventDefault();
        updateLayoutInput();
        updateStats();
    });

    tiles().forEach(bindTile);
    syncEmptyState();

    document.querySelectorAll('.js-add-to-wall').forEach((button) => {
        button.addEventListener('click', () => {
            addTile(button.dataset.filename || '', button.dataset.url || '');
        });
    });

    addAllBtn?.addEventListener('click', () => {
        uploadedRows().forEach((row) => {
            addTile(row.dataset.filename || '', row.dataset.url || '');
        });
    });

    saveLayoutBtn?.addEventListener('click', () => {
        updateLayoutInput();
        saveLayoutForm.submit();
    });

    resetLayoutBtn?.addEventListener('click', () => {
        window.location.reload();
    });

    wallAutoButton?.addEventListener('click', () => {
        if (wallMode) {
            wallMode.value = 'auto';
        }
        if (wallWidth) {
            wallWidth.value = '';
        }
        if (wallHeight) {
            wallHeight.value = '';
        }
    });

    imagesInput?.addEventListener('change', () => {
        if (!selectedFiles) {
            return;
        }

        const names = Array.from(imagesInput.files || []).map((file) => file.name);
        selectedFiles.textContent = names.length ? names.join(', ') : 'No files selected.';
    });

    updateLayoutInput();
    updateStats();
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
