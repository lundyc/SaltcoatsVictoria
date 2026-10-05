<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/media_library.php';
require_once __DIR__ . '/lib/tagged_people.php';

function match_photos_media_json(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Merges the filesystem-backed media catalogue with match-photo metadata (kit,
 * tags, fixture) for every item that lives under uploads/matches/gallery/, so
 * the Media Library can render one grid of match photos and general files
 * alike — match photos just carry extra fields the grid/modal can act on.
 */
function match_photos_catalogue(PDO $pdo): array
{
    $items = hub_media_catalogue();

    $matchStmt = $pdo->query("
        SELECT mp.id AS photo_id, mp.filename, mp.kit, mp.match_fixture_id,
               f.opponent, f.match_date, f.season_id,
               pt.id AS tag_id, pt.tagged_person_id, pt.source, tp.name AS person_name
        FROM match_photos mp
        JOIN match_fixtures f ON f.id = mp.match_fixture_id
        LEFT JOIN match_photo_tags pt ON pt.match_photo_id = mp.id
        LEFT JOIN tagged_people tp ON tp.id = pt.tagged_person_id
    ");
    $byPath = [];
    foreach ($matchStmt as $row) {
        $path = 'uploads/matches/gallery/' . (int) $row['match_fixture_id'] . '/' . (string) $row['filename'];
        if (!isset($byPath[$path])) {
            $byPath[$path] = [
                'match_photo_id' => (int) $row['photo_id'],
                'fixture_id' => (int) $row['match_fixture_id'],
                'season_id' => (int) $row['season_id'],
                'opponent' => (string) $row['opponent'],
                'match_date' => $row['match_date'],
                'kit' => $row['kit'],
                'tags' => [],
            ];
        }
        if ($row['tag_id'] !== null) {
            $byPath[$path]['tags'][] = [
                'tag_id' => (int) $row['tag_id'],
                'person_id' => (int) $row['tagged_person_id'],
                'name' => (string) $row['person_name'],
                'source' => (string) $row['source'],
            ];
        }
    }

    foreach ($items as &$item) {
        $match = $byPath[$item['path']] ?? null;
        $item['is_match_photo'] = $match !== null;
        $item['match_photo_id'] = $match['match_photo_id'] ?? null;
        $item['fixture_id'] = $match['fixture_id'] ?? null;
        $item['season_id'] = $match['season_id'] ?? null;
        $item['opponent'] = $match['opponent'] ?? null;
        $item['match_date'] = $match['match_date'] ?? null;
        $item['kit'] = $match['kit'] ?? null;
        $item['tags'] = $match['tags'] ?? [];
    }
    unset($item);

    return array_values($items);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) ($_POST['action'] ?? ''), ['check_upload_conflicts', 'upload', 'save_edit', 'delete_media'], true)) {
    if (!hub_auth_is_authenticated()) {
        match_photos_media_json(['ok' => false, 'message' => 'Your session has expired.'], 401);
    }
    if (!hub_auth_has_capability('content_social')) {
        match_photos_media_json(['ok' => false, 'message' => 'You do not have permission to manage media.'], 403);
    }
    if (!hub_auth_verify_csrf_token(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        match_photos_media_json(['ok' => false, 'message' => 'The security token is invalid. Refresh and try again.'], 419);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'check_upload_conflicts') {
        $targets = hub_media_upload_targets();
        $targetKey = (string) ($_POST['category'] ?? 'library');
        if (!isset($targets[$targetKey])) {
            match_photos_media_json(['ok' => false, 'message' => 'Choose a valid category.'], 422);
        }
        $descriptors = json_decode((string) ($_POST['files'] ?? '[]'), true);
        if (!is_array($descriptors)) {
            match_photos_media_json(['ok' => false, 'message' => 'The selected files could not be checked.'], 422);
        }
        $directory = __DIR__ . '/' . $targets[$targetKey]['path'];
        $mimeExtensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $conflicts = [];
        foreach ($descriptors as $index => $descriptor) {
            if (!is_array($descriptor)) {
                continue;
            }
            $mime = (string) ($descriptor['type'] ?? '');
            if (!isset($mimeExtensions[$mime])) {
                continue;
            }
            $filename = hub_media_safe_stem((string) ($descriptor['name'] ?? 'image')) . '.' . $mimeExtensions[$mime];
            $existing = $directory . '/' . $filename;
            if (is_file($existing)) {
                $relative = $targets[$targetKey]['path'] . '/' . $filename;
                $conflicts[] = ['index' => (int) $index, 'filename' => $filename, 'path' => $relative, 'url' => hub_media_web_url($relative)];
            }
        }
        match_photos_media_json(['ok' => true, 'conflicts' => $conflicts]);
    }

    if ($action === 'upload') {
        $targets = hub_media_upload_targets();
        $targetKey = (string) ($_POST['category'] ?? 'library');
        if (!isset($targets[$targetKey])) {
            match_photos_media_json(['ok' => false, 'message' => 'Choose a valid category.'], 422);
        }
        $directory = __DIR__ . '/' . $targets[$targetKey]['path'];
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            match_photos_media_json(['ok' => false, 'message' => 'The destination folder could not be created.'], 500);
        }
        $files = $_FILES['images'] ?? null;
        if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
            match_photos_media_json(['ok' => false, 'message' => 'Choose one or more images.'], 422);
        }
        $policies = json_decode((string) ($_POST['conflict_policies'] ?? '{}'), true);
        $policies = is_array($policies) ? $policies : [];
        $saved = [];
        $errors = [];
        foreach ($files['name'] as $index => $name) {
            $file = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
            $valid = hub_media_validate_upload($file);
            if (!$valid['ok']) {
                $errors[] = basename((string) $name) . ': ' . $valid['message'];
                continue;
            }
            $baseDestination = $directory . '/' . hub_media_safe_stem((string) $name) . '.' . $valid['extension'];
            $policy = (string) ($policies[(string) $index] ?? $policies[$index] ?? '');
            if (is_file($baseDestination) && !in_array($policy, ['overwrite', 'rename'], true)) {
                $errors[] = basename((string) $name) . ': a file with this name already exists.';
                continue;
            }
            $destination = is_file($baseDestination) && $policy === 'rename'
                ? hub_media_unique_path($directory, hub_media_safe_stem((string) $name), $valid['extension'])
                : $baseDestination;
            if (move_uploaded_file($valid['temp'], $destination)) {
                $saved[] = basename($destination);
            } else {
                $errors[] = basename((string) $name) . ': could not be stored.';
            }
        }
        match_photos_media_json([
            'ok' => $saved !== [],
            'message' => count($saved) . ' image' . (count($saved) === 1 ? '' : 's') . ' uploaded.',
            'saved' => $saved,
            'errors' => $errors,
        ], $saved !== [] ? 200 : 422);
    }

    if ($action === 'save_edit') {
        $valid = hub_media_validate_upload($_FILES['image'] ?? []);
        if (!$valid['ok']) {
            match_photos_media_json(['ok' => false, 'message' => $valid['message']], 422);
        }
        $sourcePath = (string) ($_POST['source_path'] ?? '');
        $source = hub_media_resolve($sourcePath, true);
        if ($source === null) {
            match_photos_media_json(['ok' => false, 'message' => 'The original image could not be found.'], 404);
        }
        $mode = (string) ($_POST['save_mode'] ?? 'copy');
        if ($mode === 'overwrite') {
            $destination = $source;
        } else {
            $stem = hub_media_safe_stem((string) ($_POST['filename'] ?? (pathinfo($source, PATHINFO_FILENAME) . '-edited')));
            $baseDestination = dirname($source) . '/' . $stem . '.' . $valid['extension'];
            $conflictPolicy = (string) ($_POST['conflict_policy'] ?? '');
            if (is_file($baseDestination) && !in_array($conflictPolicy, ['overwrite', 'rename'], true)) {
                $relativeConflict = ltrim(substr(str_replace('\\', '/', $baseDestination), strlen(str_replace('\\', '/', __DIR__))), '/');
                match_photos_media_json([
                    'ok' => false,
                    'conflict' => true,
                    'message' => 'An image with this filename already exists.',
                    'filename' => basename($baseDestination),
                    'path' => $relativeConflict,
                    'url' => hub_media_web_url($relativeConflict),
                ], 409);
            }
            $destination = is_file($baseDestination) && $conflictPolicy === 'rename'
                ? hub_media_unique_path(dirname($source), $stem, $valid['extension'])
                : $baseDestination;
        }
        $temporary = dirname($destination) . '/.' . bin2hex(random_bytes(8)) . '.tmp';
        $targetExtension = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
        $sameFormat = ($targetExtension === $valid['extension']) || ($targetExtension === 'jpeg' && $valid['extension'] === 'jpg');
        $stored = $sameFormat ? copy($valid['temp'], $temporary) : hub_media_convert($valid['temp'], $temporary . '.' . $targetExtension);
        if (!$sameFormat && $stored) {
            @unlink($temporary);
            $temporary .= '.' . $targetExtension;
        }
        if (!$stored || !@rename($temporary, $destination)) {
            @unlink($temporary);
            match_photos_media_json(['ok' => false, 'message' => 'The edited image could not be saved.'], 500);
        }
        $relative = ltrim(substr(str_replace('\\', '/', $destination), strlen(str_replace('\\', '/', __DIR__))), '/');
        match_photos_media_json(['ok' => true, 'message' => $mode === 'overwrite' ? 'Image updated.' : 'New image saved.', 'path' => $relative, 'url' => hub_media_web_url($relative)]);
    }

    if ($action === 'delete_media') {
        $path = (string) ($_POST['path'] ?? '');
        $resolved = hub_media_resolve($path, true);
        if ($resolved === null) {
            match_photos_media_json(['ok' => false, 'message' => 'The image could not be found.'], 404);
        }
        if (!@unlink($resolved)) {
            match_photos_media_json(['ok' => false, 'message' => 'The image could not be deleted.'], 500);
        }
        match_photos_media_json(['ok' => true, 'message' => 'Image deleted.']);
    }
}

$pageHero = [
    'eyebrow' => 'Club',
    'title' => 'Media Library',
    'subtitle' => 'Browse, upload and manage every hub image — match photos, sponsors, badges and more — in one place.',
    'actions' => hub_auth_has_capability('content_social') ? [
        ['label' => 'Facebook import', 'href' => '/facebook_photo_import.php', 'class' => 'btn btn-outline-light btn-sm'],
    ] : [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/functions.php';

$canManageMedia = hub_auth_has_capability('content_social');

// Ensures $_SESSION['csrf_token'] exists — match_photo_ajax.php / match_photo_upload.php /
// match_photo_delete.php all verify against it (a separate token system from
// hub_auth_csrf_token(), which guards this file's own POST actions above).
csrf_field();
$matchCsrfToken = (string) ($_SESSION['csrf_token'] ?? '');

$people = tagged_people_all($pdo);
$peopleByCategory = tagged_people_grouped($people);
$newPersonCategories = array_filter(TAGGED_PEOPLE_CATEGORIES, static fn(string $key): bool => $key !== 'player', ARRAY_FILTER_USE_KEY);

$allFixtureOptions = $pdo->query("
    SELECT id, opponent, match_date
    FROM match_fixtures
    ORDER BY match_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$kitLabels = ['home' => 'Home kit', 'away' => 'Away kit', 'third' => 'Third kit'];

$allItems = match_photos_catalogue($pdo);
$mediaItems = $canManageMedia ? $allItems : array_values(array_filter($allItems, static fn(array $i): bool => $i['is_match_photo']));
$mediaCategories = array_values(array_unique(array_column($mediaItems, 'category')));
sort($mediaCategories, SORT_NATURAL | SORT_FLAG_CASE);
$uploadTargets = $canManageMedia ? hub_media_upload_targets() : [];
?>
<?php if ($canManageMedia): ?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
<?php endif; ?>
<link rel="stylesheet" href="/admin/assets/css/media-library.css?v=<?= (int) (@filemtime(__DIR__ . '/assets/css/media-library.css') ?: time()) ?>">
<link rel="stylesheet" href="/admin/assets/css/match_media.css">

<div>
    <?php if (isset($_GET['bulk_uploaded'])): ?>
        <?php
            $bulkUploaded = (int) $_GET['bulk_uploaded'];
            $bulkTagged = (int) ($_GET['bulk_tagged'] ?? 0);
            $bulkErrors = trim((string) ($_GET['bulk_errors'] ?? ''));
        ?>
        <div class="alert alert-<?= $bulkErrors !== '' ? 'warning' : 'success' ?>">
            <?= $bulkUploaded ?> photo<?= $bulkUploaded === 1 ? '' : 's' ?> uploaded<?= $bulkTagged > 0 ? ', ' . $bulkTagged . ' tag' . ($bulkTagged === 1 ? '' : 's') . ' suggested automatically' : '' ?>.
            <?= $bulkErrors !== '' ? h($bulkErrors) : '' ?>
        </div>
    <?php endif; ?>

    <section class="hub-section mb-4">
        <div class="card shadow-sm hub-form-card">
            <div class="card-body">
                <h2 class="h5 card-title">Upload images</h2>
                <div class="row g-3 align-items-start">
                    <div class="col-12 col-md-4 col-lg-3">
                        <?php if ($canManageMedia): ?>
                            <label class="form-label" for="mediaUploadCategory">Add to</label>
                            <select id="mediaUploadCategory" class="form-select">
                                <option value="match_photos">Match photos</option>
                                <?php foreach ($uploadTargets as $key => $target): ?>
                                    <?php $label = $key === 'matches' ? 'Matches (other uploads)' : $target['label']; ?>
                                    <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="hidden" id="mediaUploadCategory" value="match_photos">
                            <p class="text-muted small fw-bold mb-0">Uploading match photos</p>
                        <?php endif; ?>

                        <div id="mediaUploadMatchFields" class="mt-3">
                            <label class="form-label" for="mediaUploadFixture">Match</label>
                            <select id="mediaUploadFixture" class="form-select">
                                <option value="">Choose a match…</option>
                                <?php foreach ($allFixtureOptions as $f): ?>
                                    <option value="<?= (int) $f['id'] ?>"><?= h(date('d M Y', strtotime((string) $f['match_date']))) ?> vs <?= h((string) $f['opponent']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="form-label mt-3" for="mediaUploadKit">Kit worn</label>
                            <select name="kit" id="mediaUploadKit" class="form-select">
                                <option value="home" selected>Home kit</option>
                                <option value="away">Away kit</option>
                                <option value="third">Third kit</option>
                            </select>
                            <p class="text-muted small mt-2 mb-0">Everyone in each photo is tagged automatically — check the results afterwards.</p>
                        </div>
                        <p class="text-muted small mt-2 mb-0" id="mediaUploadGenericHint" hidden>Images are stored as-is, ready to browse and edit here.</p>
                    </div>
                    <div class="col-12 col-md-8 col-lg-9">
                        <label class="media-dropzone media-dropzone--lg" id="mediaDropzone" for="mediaFiles">
                            <input id="mediaFiles" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                            <strong>Drop images here</strong>
                            <span>or click to choose one or more JPG, PNG, GIF or WebP files</span>
                        </label>
                        <div class="media-upload-queue" id="mediaUploadQueue" hidden></div>
                    </div>
                </div>
                <div class="d-grid d-md-flex hub-actions mt-3">
                    <button type="button" class="btn btn-brand" id="mediaUploadButton" disabled>Upload</button>
                </div>
                <div class="match-media-progress d-none mt-3" id="mediaUploadProgress">
                    <div class="progress" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar bg-success" id="mediaUploadProgressBar" style="width: 0%"></div>
                    </div>
                    <p class="text-muted small mt-1 mb-0" id="mediaUploadProgressLabel">Uploading…</p>
                </div>
            </div>
        </div>
    </section>

    <section class="hub-section mb-4">
        <div class="hub-form-card card shadow-sm">
            <div class="card-body">
                <div class="media-browser-toolbar media-browser-toolbar--filters">
                    <div>
                        <h2 class="h5 mb-1">Filter</h2>
                        <p class="text-muted small mb-0"><strong id="mediaVisibleCount"><?= count($mediaItems) ?></strong> of <strong id="mediaTotalCount"><?= count($mediaItems) ?></strong> images</p>
                    </div>
                    <div class="media-filters">
                        <label class="media-search">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <span class="visually-hidden">Search images</span>
                            <input id="mediaSearch" type="search" placeholder="Search images…">
                        </label>
                        <select id="mediaCategoryFilter" class="form-select" aria-label="Filter by category">
                            <option value="">All categories</option>
                            <?php foreach ($mediaCategories as $category): ?>
                                <option value="<?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="mediaMatchFilter" class="form-select" aria-label="Filter by match">
                            <option value="">All matches</option>
                        </select>
                        <select id="mediaPersonFilter" class="form-select" aria-label="Filter by person">
                            <option value="">Everyone</option>
                            <?php foreach ($peopleByCategory as $catValue => $catPeople): ?>
                                <optgroup label="<?= h(tagged_people_group_label($catValue)) ?>">
                                    <?php foreach ($catPeople as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>"><?= h((string) $p['name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <select id="mediaKitFilter" class="form-select" aria-label="Filter by kit">
                            <option value="">All kits</option>
                            <?php foreach ($kitLabels as $kitValue => $kitLabel): ?>
                                <option value="<?= h($kitValue) ?>"><?= h($kitLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="mediaSort" class="form-select" aria-label="Sort media">
                            <option value="newest">Newest first</option>
                            <option value="oldest">Oldest first</option>
                            <option value="name">Name</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="hub-section">
        <div class="media-grid" id="mediaGrid" aria-live="polite"></div>
        <div class="media-empty" id="mediaEmpty" hidden>
            <i class="fa-regular fa-images"></i>
            <h3>No images found</h3>
            <p>Try another search or filter.</p>
        </div>
    </section>
</div>

<div class="modal fade media-editor-modal media-manage-modal" id="mediaManageModal" tabindex="-1" aria-labelledby="mediaManageTitle" aria-hidden="true">
 <div class="modal-dialog modal-fullscreen"><div class="modal-content" data-view="details">
  <div class="modal-header">
   <div><div class="small text-uppercase text-muted fw-bold" id="mediaManageCategory"></div><h2 class="modal-title h5" id="mediaManageTitle">Manage image</h2></div>
   <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
  </div>
  <div class="modal-body">
   <div class="media-manage-panel media-manage-panel--details">
    <div class="media-manage-image"><img id="mediaManageImage" alt=""></div>
    <div class="media-manage-info">
     <dl class="media-manage-meta" id="mediaManageMeta"></dl>
     <div id="mediaManageMatchFields" hidden>
      <label class="form-label mt-2 mb-1" for="mediaManageKit">Kit worn</label>
      <select class="form-select form-select-sm" id="mediaManageKit">
       <?php foreach ($kitLabels as $kitValue => $kitLabel): ?>
        <option value="<?= h($kitValue) ?>"><?= h($kitLabel) ?></option>
       <?php endforeach; ?>
      </select>

      <label class="form-label mt-3 mb-1">Tagged</label>
      <div class="match-media-tags" id="mediaManageTags"></div>

      <select class="form-select form-select-sm mt-2" id="mediaManageAddTag">
       <option value="">+ Tag someone…</option>
       <?php foreach ($peopleByCategory as $catValue => $catPeople): ?>
        <optgroup label="<?= h(tagged_people_group_label($catValue)) ?>">
         <?php foreach ($catPeople as $p): ?>
          <option value="<?= (int) $p['id'] ?>"><?= h((string) $p['name']) ?></option>
         <?php endforeach; ?>
        </optgroup>
       <?php endforeach; ?>
       <option value="__new__">+ Add new person…</option>
      </select>
      <form class="match-media-new-person d-none mt-2" id="mediaManageNewPerson">
       <input type="text" class="form-control form-control-sm" name="name" placeholder="Name" required>
       <select class="form-select form-select-sm" name="category">
        <?php foreach ($newPersonCategories as $catValue => $catLabel): ?>
         <option value="<?= h($catValue) ?>" <?= $catValue === 'fan' ? 'selected' : '' ?>><?= h($catLabel) ?></option>
        <?php endforeach; ?>
       </select>
       <div class="match-media-new-person__actions">
        <button type="submit" class="btn btn-sm btn-brand">Add &amp; tag</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="mediaManageNewPersonCancel">Cancel</button>
       </div>
      </form>
     </div>
    </div>
   </div>

   <?php if ($canManageMedia): ?>
   <div class="media-manage-panel media-manage-panel--edit">
    <aside class="media-editor-tools" aria-label="Image editing tools">
     <div class="media-tool-group"><h3>History</h3><div class="media-tool-row"><button type="button" class="btn btn-outline-secondary" id="mediaUndo" disabled><i class="fa-solid fa-rotate-left"></i> Undo</button><button type="button" class="btn btn-outline-secondary" id="mediaRedo" disabled><i class="fa-solid fa-rotate-right"></i> Redo</button></div></div>
     <div class="media-tool-group"><h3>Aspect ratio</h3><div class="media-ratio-grid" id="mediaRatios"><button type="button" class="active" data-ratio="NaN">Free</button><button type="button" data-ratio="1">1:1</button><button type="button" data-ratio="1.3333333333">4:3</button><button type="button" data-ratio="1.7777777778">16:9</button><button type="button" data-ratio="0.8">4:5</button></div></div>
     <div class="media-tool-group"><h3>Transform</h3><div class="media-tool-row media-tool-row--icons"><button type="button" class="btn btn-outline-secondary" id="mediaRotateLeft" title="Rotate left"><i class="fa-solid fa-rotate-left"></i><span>-90°</span></button><button type="button" class="btn btn-outline-secondary" id="mediaRotateRight" title="Rotate right"><i class="fa-solid fa-rotate-right"></i><span>90°</span></button><button type="button" class="btn btn-outline-secondary" id="mediaFlipH" title="Flip horizontal"><i class="fa-solid fa-left-right"></i><span>Flip H</span></button><button type="button" class="btn btn-outline-secondary" id="mediaFlipV" title="Flip vertical"><i class="fa-solid fa-up-down"></i><span>Flip V</span></button></div></div>
     <div class="media-tool-group"><h3>Output size</h3><div class="media-size-fields"><label>Width <div><input id="mediaOutputWidth" type="number" min="1" max="8000"><span>px</span></div></label><button type="button" class="media-lock active" id="mediaSizeLock" aria-pressed="true" title="Keep aspect ratio"><i class="fa-solid fa-link"></i></button><label>Height <div><input id="mediaOutputHeight" type="number" min="1" max="8000"><span>px</span></div></label></div><label class="media-scale-label">Scale <input id="mediaScale" type="range" min="10" max="200" value="100"><output id="mediaScaleValue">100%</output></label></div>
     <button type="button" class="btn btn-outline-secondary w-100" id="mediaReset"><i class="fa-solid fa-arrow-rotate-left"></i> Reset all edits</button>
    </aside>
    <div class="media-editor-stage"><div class="media-editor-canvas"><img id="mediaEditorImage" alt="Image being edited"></div><div class="media-editor-info" id="mediaEditorInfo"></div></div>
   </div>
   <?php endif; ?>
  </div>

  <div class="modal-footer media-manage-footer media-manage-footer--details">
   <form id="mediaManageDeleteForm">
    <button type="submit" class="btn btn-outline-danger btn-sm" id="mediaManageDeleteBtn" data-confirm="This image will be permanently removed." data-confirm-title="Delete this image?" data-confirm-action="Delete image"><i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Delete</button>
   </form>
   <div class="ms-auto d-flex gap-2">
    <?php if ($canManageMedia): ?><button type="button" class="btn btn-outline-primary" id="mediaManageEditBtn"><i class="fa-solid fa-crop-simple" aria-hidden="true"></i> Edit image</button><?php endif; ?>
    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
   </div>
  </div>

  <?php if ($canManageMedia): ?>
  <div class="modal-footer media-manage-footer media-manage-footer--edit" hidden>
   <div class="media-save-name"><label for="mediaSaveName">File name</label><input id="mediaSaveName" class="form-control" type="text"></div>
   <div class="ms-auto d-flex gap-2">
    <button type="button" class="btn btn-outline-secondary" id="mediaManageBackToDetails">Back</button>
    <button type="button" class="btn btn-outline-primary" id="mediaSaveCopy"><i class="fa-regular fa-copy"></i> Save as new</button>
    <button type="button" class="btn btn-primary" id="mediaSave"><i class="fa-solid fa-floppy-disk"></i> Save</button>
   </div>
  </div>
  <?php endif; ?>
 </div></div>
</div>

<div class="hub-toast-region media-toast-region" id="mediaToasts" aria-live="polite"></div>

<?php if ($canManageMedia): ?>
<div class="media-conflict-overlay" id="mediaConflictDialog" hidden>
 <section class="media-conflict-dialog" role="dialog" aria-modal="true" aria-labelledby="mediaConflictTitle" aria-describedby="mediaConflictMessage">
  <header><span class="media-conflict-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><div><div class="small text-uppercase text-muted fw-bold">Filename conflict</div><h2 id="mediaConflictTitle">This image already exists</h2></div></header>
  <p id="mediaConflictMessage">Compare the existing image with the new version and choose what to do.</p>
  <div class="media-conflict-images"><figure><div><img id="mediaConflictExisting" alt="Existing image"></div><figcaption>Existing image</figcaption></figure><figure><div><img id="mediaConflictNew" alt="New image"></div><figcaption>New image</figcaption></figure></div>
  <div class="media-conflict-filename" id="mediaConflictFilename"></div>
  <footer><button type="button" class="btn btn-outline-secondary" data-conflict-choice="cancel">Cancel</button><button type="button" class="btn btn-outline-primary" data-conflict-choice="rename"><i class="fa-regular fa-copy"></i> Continue with a new name</button><button type="button" class="btn btn-danger" data-conflict-choice="overwrite"><i class="fa-solid fa-rotate"></i> Overwrite existing</button></footer>
 </section>
</div>
<?php endif; ?>

<div id="mediaLibraryConfig" class="d-none" data-csrf="<?= h(hub_auth_csrf_token()) ?>" data-match-csrf="<?= h($matchCsrfToken) ?>" data-endpoint="/admin/match_photos.php" data-can-manage="<?= $canManageMedia ? '1' : '0' ?>"></div>
<script>
window.HUB_MEDIA_ITEMS = <?= json_encode($mediaItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
window.HUB_NEW_PERSON_CATEGORIES = <?= json_encode($newPersonCategories, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
window.HUB_KIT_LABELS = <?= json_encode($kitLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<?php if ($canManageMedia): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js" defer></script>
<?php endif; ?>
<script src="/admin/assets/js/media-library.js?v=<?= (int) (@filemtime(__DIR__ . '/assets/js/media-library.js') ?: time()) ?>" defer></script>

<?php require __DIR__ . '/footer.php'; ?>
