<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    header('Location: /admin/match_photos.php', true, 302);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/media_library.php';

if (!hub_auth_is_authenticated()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'message' => 'Your session has expired.']); exit;
    }
    header('Location: /admin/login.php'); exit;
}
$mediaUser = hub_auth_current_user();
if ((string) ($mediaUser['role'] ?? '') !== 'admin') {
    http_response_code(403); exit('You do not have permission to manage media.');
}

function hub_media_json(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hub_auth_verify_csrf_token(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) hub_media_json(['ok' => false, 'message' => 'The security token is invalid. Refresh and try again.'], 419);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'check_upload_conflicts') {
        $targets = hub_media_upload_targets();
        $targetKey = (string) ($_POST['category'] ?? 'library');
        if (!isset($targets[$targetKey])) hub_media_json(['ok' => false, 'message' => 'Choose a valid category.'], 422);
        $descriptors = json_decode((string) ($_POST['files'] ?? '[]'), true);
        if (!is_array($descriptors)) hub_media_json(['ok' => false, 'message' => 'The selected files could not be checked.'], 422);
        $directory = __DIR__ . '/' . $targets[$targetKey]['path'];
        $mimeExtensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $conflicts = [];
        foreach ($descriptors as $index => $descriptor) {
            if (!is_array($descriptor)) continue;
            $mime = (string) ($descriptor['type'] ?? '');
            if (!isset($mimeExtensions[$mime])) continue;
            $filename = hub_media_safe_stem((string) ($descriptor['name'] ?? 'image')) . '.' . $mimeExtensions[$mime];
            $existing = $directory . '/' . $filename;
            if (is_file($existing)) {
                $relative = $targets[$targetKey]['path'] . '/' . $filename;
                $conflicts[] = ['index' => (int) $index, 'filename' => $filename, 'path' => $relative, 'url' => hub_media_web_url($relative)];
            }
        }
        hub_media_json(['ok' => true, 'conflicts' => $conflicts]);
    }
    if ($action === 'upload') {
        $targets = hub_media_upload_targets();
        $targetKey = (string) ($_POST['category'] ?? 'library');
        if (!isset($targets[$targetKey])) hub_media_json(['ok' => false, 'message' => 'Choose a valid category.'], 422);
        $directory = __DIR__ . '/' . $targets[$targetKey]['path'];
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) hub_media_json(['ok' => false, 'message' => 'The destination folder could not be created.'], 500);
        $files = $_FILES['images'] ?? null;
        if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) hub_media_json(['ok' => false, 'message' => 'Choose one or more images.'], 422);
        $policies = json_decode((string) ($_POST['conflict_policies'] ?? '{}'), true);
        $policies = is_array($policies) ? $policies : [];
        $saved = []; $errors = [];
        foreach ($files['name'] as $index => $name) {
            $file = ['name' => $name, 'type' => $files['type'][$index] ?? '', 'tmp_name' => $files['tmp_name'][$index] ?? '', 'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$index] ?? 0];
            $valid = hub_media_validate_upload($file);
            if (!$valid['ok']) { $errors[] = basename((string) $name) . ': ' . $valid['message']; continue; }
            $baseDestination = $directory . '/' . hub_media_safe_stem((string) $name) . '.' . $valid['extension'];
            $policy = (string) ($policies[(string) $index] ?? $policies[$index] ?? '');
            if (is_file($baseDestination) && !in_array($policy, ['overwrite', 'rename'], true)) {
                $errors[] = basename((string) $name) . ': a file with this name already exists.';
                continue;
            }
            $destination = is_file($baseDestination) && $policy === 'rename'
                ? hub_media_unique_path($directory, hub_media_safe_stem((string) $name), $valid['extension'])
                : $baseDestination;
            if (move_uploaded_file($valid['temp'], $destination)) $saved[] = basename($destination); else $errors[] = basename((string) $name) . ': could not be stored.';
        }
        hub_media_json(['ok' => $saved !== [], 'message' => count($saved) . ' image' . (count($saved) === 1 ? '' : 's') . ' uploaded.', 'saved' => $saved, 'errors' => $errors], $saved !== [] ? 200 : 422);
    }
    if ($action === 'save_edit') {
        $valid = hub_media_validate_upload($_FILES['image'] ?? []);
        if (!$valid['ok']) hub_media_json(['ok' => false, 'message' => $valid['message']], 422);
        $sourcePath = (string) ($_POST['source_path'] ?? '');
        $source = hub_media_resolve($sourcePath, true);
        if ($source === null) hub_media_json(['ok' => false, 'message' => 'The original image could not be found.'], 404);
        $mode = (string) ($_POST['save_mode'] ?? 'copy');
        if ($mode === 'overwrite') {
            $destination = $source;
        } else {
            $stem = hub_media_safe_stem((string) ($_POST['filename'] ?? (pathinfo($source, PATHINFO_FILENAME) . '-edited')));
            $baseDestination = dirname($source) . '/' . $stem . '.' . $valid['extension'];
            $conflictPolicy = (string) ($_POST['conflict_policy'] ?? '');
            if (is_file($baseDestination) && !in_array($conflictPolicy, ['overwrite', 'rename'], true)) {
                $relativeConflict = ltrim(substr(str_replace('\\', '/', $baseDestination), strlen(str_replace('\\', '/', __DIR__))), '/');
                hub_media_json(['ok' => false, 'conflict' => true, 'message' => 'An image with this filename already exists.', 'filename' => basename($baseDestination), 'path' => $relativeConflict, 'url' => hub_media_web_url($relativeConflict)], 409);
            }
            $destination = is_file($baseDestination) && $conflictPolicy === 'rename'
                ? hub_media_unique_path(dirname($source), $stem, $valid['extension'])
                : $baseDestination;
        }
        $temporary = dirname($destination) . '/.' . bin2hex(random_bytes(8)) . '.tmp';
        $targetExtension = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
        $sameFormat = ($targetExtension === $valid['extension']) || ($targetExtension === 'jpeg' && $valid['extension'] === 'jpg');
        $stored = $sameFormat ? copy($valid['temp'], $temporary) : hub_media_convert($valid['temp'], $temporary . '.' . $targetExtension);
        if (!$sameFormat && $stored) { @unlink($temporary); $temporary .= '.' . $targetExtension; }
        if (!$stored || !@rename($temporary, $destination)) { @unlink($temporary); hub_media_json(['ok' => false, 'message' => 'The edited image could not be saved.'], 500); }
        $relative = ltrim(substr(str_replace('\\', '/', $destination), strlen(str_replace('\\', '/', __DIR__))), '/');
        hub_media_json(['ok' => true, 'message' => $mode === 'overwrite' ? 'Image updated.' : 'New image saved.', 'path' => $relative, 'url' => hub_media_web_url($relative)]);
    }
    hub_media_json(['ok' => false, 'message' => 'Unknown media action.'], 400);
}

$mediaItems = hub_media_catalogue();
$mediaCategories = array_values(array_unique(array_column($mediaItems, 'category')));
sort($mediaCategories, SORT_NATURAL | SORT_FLAG_CASE);
$pageHero = ['eyebrow' => 'Administration', 'title' => 'Media library', 'subtitle' => 'Browse, upload and edit the images used across the Hub.', 'actions' => []];
require __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<link rel="stylesheet" href="/admin/assets/css/media-library.css?v=<?= (int) (@filemtime(__DIR__ . '/assets/css/media-library.css') ?: time()) ?>">

<div class="media-library" id="mediaLibrary" data-csrf="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <section class="media-upload-card" aria-labelledby="mediaUploadTitle">
        <div class="media-upload-copy"><span class="media-upload-icon"><i class="fa-solid fa-cloud-arrow-up"></i></span><div><h2 id="mediaUploadTitle">Upload images</h2><p>Drop JPG, PNG, WEBP or GIF files here, or choose them from your device. Maximum 25 MB each.</p></div></div>
        <label class="media-dropzone" id="mediaDropzone" for="mediaFiles"><input id="mediaFiles" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple><i class="fa-regular fa-images"></i><strong>Drop images here</strong><span>or click to browse</span></label>
        <div class="media-upload-options"><label for="mediaUploadCategory">Add to</label><select id="mediaUploadCategory" class="form-select"><?php foreach (hub_media_upload_targets() as $key => $target): ?><option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($target['label'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><button type="button" class="btn btn-primary" id="mediaUploadButton" disabled><i class="fa-solid fa-upload"></i> Upload</button></div>
        <div class="media-upload-queue" id="mediaUploadQueue" hidden></div>
    </section>

    <section class="media-browser-card" aria-labelledby="mediaBrowserTitle">
        <div class="media-browser-toolbar"><div><h2 id="mediaBrowserTitle">All media</h2><p><strong id="mediaVisibleCount"><?= count($mediaItems) ?></strong> of <?= count($mediaItems) ?> images</p></div><div class="media-filters"><label class="media-search"><i class="fa-solid fa-magnifying-glass"></i><span class="visually-hidden">Search media</span><input id="mediaSearch" type="search" placeholder="Search images…"></label><select id="mediaCategoryFilter" class="form-select" aria-label="Filter by category"><option value="">All categories</option><?php foreach ($mediaCategories as $category): ?><option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select><select id="mediaSort" class="form-select" aria-label="Sort media"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="name">Name</option></select></div></div>
        <div class="media-grid" id="mediaGrid" aria-live="polite"></div>
        <div class="media-empty" id="mediaEmpty" hidden><i class="fa-regular fa-images"></i><h3>No images found</h3><p>Try another search or upload a new image.</p></div>
    </section>
</div>

<div class="modal fade media-editor-modal" id="mediaEditorModal" tabindex="-1" aria-labelledby="mediaEditorTitle" aria-hidden="true">
 <div class="modal-dialog modal-fullscreen"><div class="modal-content">
  <div class="modal-header"><div><div class="small text-uppercase text-muted fw-bold">Image editor</div><h2 class="modal-title h5" id="mediaEditorTitle">Edit image</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close editor"></button></div>
  <div class="modal-body"><aside class="media-editor-tools" aria-label="Image editing tools">
   <div class="media-tool-group"><h3>History</h3><div class="media-tool-row"><button type="button" class="btn btn-outline-secondary" id="mediaUndo" disabled><i class="fa-solid fa-rotate-left"></i> Undo</button><button type="button" class="btn btn-outline-secondary" id="mediaRedo" disabled><i class="fa-solid fa-rotate-right"></i> Redo</button></div></div>
   <div class="media-tool-group"><h3>Aspect ratio</h3><div class="media-ratio-grid" id="mediaRatios"><button type="button" class="active" data-ratio="NaN">Free</button><button type="button" data-ratio="1">1:1</button><button type="button" data-ratio="1.3333333333">4:3</button><button type="button" data-ratio="1.7777777778">16:9</button><button type="button" data-ratio="0.8">4:5</button></div></div>
   <div class="media-tool-group"><h3>Transform</h3><div class="media-tool-row media-tool-row--icons"><button type="button" class="btn btn-outline-secondary" id="mediaRotateLeft" title="Rotate left"><i class="fa-solid fa-rotate-left"></i><span>-90°</span></button><button type="button" class="btn btn-outline-secondary" id="mediaRotateRight" title="Rotate right"><i class="fa-solid fa-rotate-right"></i><span>90°</span></button><button type="button" class="btn btn-outline-secondary" id="mediaFlipH" title="Flip horizontal"><i class="fa-solid fa-left-right"></i><span>Flip H</span></button><button type="button" class="btn btn-outline-secondary" id="mediaFlipV" title="Flip vertical"><i class="fa-solid fa-up-down"></i><span>Flip V</span></button></div></div>
   <div class="media-tool-group"><h3>Output size</h3><div class="media-size-fields"><label>Width <div><input id="mediaOutputWidth" type="number" min="1" max="8000"><span>px</span></div></label><button type="button" class="media-lock active" id="mediaSizeLock" aria-pressed="true" title="Keep aspect ratio"><i class="fa-solid fa-link"></i></button><label>Height <div><input id="mediaOutputHeight" type="number" min="1" max="8000"><span>px</span></div></label></div><label class="media-scale-label">Scale <input id="mediaScale" type="range" min="10" max="200" value="100"><output id="mediaScaleValue">100%</output></label></div>
   <button type="button" class="btn btn-outline-secondary w-100" id="mediaReset"><i class="fa-solid fa-arrow-rotate-left"></i> Reset all edits</button>
  </aside><div class="media-editor-stage"><div class="media-editor-canvas"><img id="mediaEditorImage" alt="Image being edited"></div><div class="media-editor-info" id="mediaEditorInfo"></div></div></div>
  <div class="modal-footer"><div class="media-save-name"><label for="mediaSaveName">File name</label><input id="mediaSaveName" class="form-control" type="text"></div><div class="ms-auto d-flex gap-2"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-outline-primary" id="mediaSaveCopy"><i class="fa-regular fa-copy"></i> Save as new</button><button type="button" class="btn btn-primary" id="mediaSave"><i class="fa-solid fa-floppy-disk"></i> Save</button></div></div>
 </div></div>
</div>
<div class="hub-toast-region media-toast-region" id="mediaToasts" aria-live="polite"></div>
<div class="media-conflict-overlay" id="mediaConflictDialog" hidden>
 <section class="media-conflict-dialog" role="dialog" aria-modal="true" aria-labelledby="mediaConflictTitle" aria-describedby="mediaConflictMessage">
  <header><span class="media-conflict-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><div><div class="small text-uppercase text-muted fw-bold">Filename conflict</div><h2 id="mediaConflictTitle">This image already exists</h2></div></header>
  <p id="mediaConflictMessage">Compare the existing image with the new version and choose what to do.</p>
  <div class="media-conflict-images"><figure><div><img id="mediaConflictExisting" alt="Existing image"></div><figcaption>Existing image</figcaption></figure><figure><div><img id="mediaConflictNew" alt="New image"></div><figcaption>New image</figcaption></figure></div>
  <div class="media-conflict-filename" id="mediaConflictFilename"></div>
  <footer><button type="button" class="btn btn-outline-secondary" data-conflict-choice="cancel">Cancel</button><button type="button" class="btn btn-outline-primary" data-conflict-choice="rename"><i class="fa-regular fa-copy"></i> Continue with a new name</button><button type="button" class="btn btn-danger" data-conflict-choice="overwrite"><i class="fa-solid fa-rotate"></i> Overwrite existing</button></footer>
 </section>
</div>
<script>window.HUB_MEDIA_ITEMS = <?= json_encode($mediaItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js" defer></script>
<script src="/admin/assets/js/media-library.js?v=<?= (int) (@filemtime(__DIR__ . '/assets/js/media-library.js') ?: time()) ?>" defer></script>
<?php require __DIR__ . '/footer.php'; ?>
