<?php

declare(strict_types=1);

/**
 * Photo album — create an album, edit its details, link it to a game, upload
 * photos, and arrange them. Photos are stored under
 * uploads/history/gallery/album-<id>/ (full + thumb) so they also appear in the
 * Media Library under "Club archive".
 */

$idParam = $_GET['id'] ?? '';
$isNew = $idParam === 'new';
$id = $isNew ? 0 : (int) $idParam;

$pageHero = [
    'eyebrow' => 'Administration',
    'title' => $isNew ? 'New photo album' : 'Photo album',
    'subtitle' => $isNew
        ? 'Create a photo album for the website gallery.'
        : 'Edit an album’s details, link it to a game, and upload photos.',
    'actions' => [
        ['label' => 'All albums', 'href' => 'photo_albums.php', 'class' => 'btn btn-outline-secondary btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/history.php';
require_once __DIR__ . '/lib/history_gallery.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/media_library.php';

if ((string) ($currentRole ?? 'guest') !== 'admin') {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage photo albums.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

history_ensure_schema($pdo);

$album = null;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM history_galleries WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $album = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($album === null) {
        $pageHero = ['eyebrow' => 'Administration', 'title' => 'Photo album'];
        echo '<div class="alert alert-danger">Album not found. <a href="photo_albums.php">Back to albums</a>.</div>';
        require __DIR__ . '/footer.php';
        exit;
    }
}

$errors = [];

/** Validate a YYYY-MM-DD string; '' -> null, invalid -> null + error. */
$parseDate = static function (string $raw) use (&$errors): ?string {
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    if ($d === false || $d->format('Y-m-d') !== $raw) {
        $errors[] = 'That date was not understood — leave it blank or use the picker.';
        return null;
    }
    return $raw;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!csrf_check()) {
        $errors[] = 'Your session could not be verified. Reload the page and try again.';
    } elseif ($action === 'create') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            $errors[] = 'Give the album a title.';
        } else {
            $fixtureId = (int) ($_POST['match_fixture_id'] ?? 0) ?: null;
            $date = $parseDate((string) ($_POST['album_date'] ?? ''));
            $ins = $pdo->prepare(
                'INSERT INTO history_galleries (slug, title, description, album_date, match_fixture_id, display_on_site)
                 VALUES (:slug, :title, :description, :album_date, :fixture, :display)'
            );
            $ins->execute([
                ':slug' => history_gallery_slug($pdo, $title),
                ':title' => mb_substr($title, 0, 190),
                ':description' => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255),
                ':album_date' => $date,
                ':fixture' => $fixtureId,
                ':display' => !empty($_POST['display_on_site']) ? 1 : 0,
            ]);
            $newId = (int) $pdo->lastInsertId();
            auditLog($pdo, 'photo_album_created', "Created photo album '{$title}' (#{$newId}).");
            header('Location: photo_album.php?id=' . $newId . '&status=created');
            exit;
        }
    } elseif ($album !== null && $action === 'save_meta') {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            $errors[] = 'Give the album a title.';
        }
        $date = $parseDate((string) ($_POST['album_date'] ?? ''));
        if ($errors === []) {
            $fixtureId = (int) ($_POST['match_fixture_id'] ?? 0) ?: null;
            $slug = $title !== (string) $album['title']
                ? history_gallery_slug($pdo, $title, $id)
                : (string) $album['slug'];
            $upd = $pdo->prepare(
                'UPDATE history_galleries
                 SET slug = :slug, title = :title, description = :description,
                     album_date = :album_date, match_fixture_id = :fixture, display_on_site = :display
                 WHERE id = :id'
            );
            $upd->execute([
                ':slug' => $slug,
                ':title' => mb_substr($title, 0, 190),
                ':description' => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255),
                ':album_date' => $date,
                ':fixture' => $fixtureId,
                ':display' => !empty($_POST['display_on_site']) ? 1 : 0,
                ':id' => $id,
            ]);
            auditLog($pdo, 'photo_album_updated', "Updated photo album '{$title}' (#{$id}).");
            header('Location: photo_album.php?id=' . $id . '&status=meta');
            exit;
        }
    } elseif ($album !== null && $action === 'upload') {
        @set_time_limit(300);
        $files = $_FILES['photos'] ?? null;
        $names = is_array($files['name'] ?? null) ? $files['name'] : [];
        $stored = 0;
        $failed = 0;
        for ($i = 0, $n = count($names); $i < $n; $i++) {
            if ((int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $one = [
                'name' => (string) ($files['name'][$i] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                'error' => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                'size' => (int) ($files['size'][$i] ?? 0),
            ];
            history_gallery_store_upload($pdo, $id, $one) !== null ? $stored++ : $failed++;
        }
        if ($stored > 0) {
            history_gallery_recount($pdo, $id);
            auditLog($pdo, 'photo_album_photos_added', "Added {$stored} photo(s) to album '{$album['title']}' (#{$id}).");
        }
        if ($stored === 0 && $failed === 0) {
            $errors[] = 'Choose at least one image to upload.';
        } else {
            header('Location: photo_album.php?id=' . $id . '&status=uploaded&n=' . $stored . ($failed > 0 ? '&f=' . $failed : ''));
            exit;
        }
    } elseif ($album !== null && $action === 'delete_photo') {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        if ($photoId > 0 && history_gallery_delete_photo($pdo, $photoId) === $id) {
            history_gallery_recount($pdo, $id);
        }
        header('Location: photo_album.php?id=' . $id . '&status=photo_deleted');
        exit;
    } elseif ($album !== null && $action === 'set_cover') {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $p = $pdo->prepare('SELECT thumb_path FROM history_gallery_photos WHERE id = :id AND gallery_id = :g');
        $p->execute([':id' => $photoId, ':g' => $id]);
        $thumb = (string) ($p->fetchColumn() ?: '');
        if ($thumb !== '') {
            $pdo->prepare('UPDATE history_galleries SET cover_path = :c WHERE id = :id')->execute([':c' => $thumb, ':id' => $id]);
        }
        header('Location: photo_album.php?id=' . $id . '&status=cover');
        exit;
    } elseif ($album !== null && $action === 'move_photo') {
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $dir = (string) ($_POST['dir'] ?? '');
        $check = $pdo->prepare('SELECT COUNT(*) FROM history_gallery_photos WHERE id = :id AND gallery_id = :g');
        $check->execute([':id' => $photoId, ':g' => $id]);
        if ((int) $check->fetchColumn() === 1 && in_array($dir, ['up', 'down'], true)) {
            history_gallery_move_photo($pdo, $photoId, $dir);
        }
        header('Location: photo_album.php?id=' . $id . '#photos');
        exit;
    } elseif ($album !== null && $action === 'delete_album') {
        $title = (string) $album['title'];
        history_gallery_delete_album($pdo, $id);
        auditLog($pdo, 'photo_album_deleted', "Deleted photo album '{$title}' (#{$id}) and its photos.");
        header('Location: photo_albums.php?status=deleted');
        exit;
    }
}

// ---- View model ----------------------------------------------------------

$photos = [];
if ($id > 0) {
    $pstmt = $pdo->prepare('SELECT * FROM history_gallery_photos WHERE gallery_id = :g ORDER BY sort_order ASC, id ASC');
    $pstmt->execute([':g' => $id]);
    $photos = $pstmt->fetchAll(PDO::FETCH_ASSOC);
}

$fixtures = $pdo->query(
    "SELECT f.id, f.match_date, f.is_home, f.competition,
            COALESCE(o.clubname, f.opponent) AS opponent
     FROM match_fixtures f
     LEFT JOIN match_opponents o ON o.id = f.opponent_id
     ORDER BY f.match_date DESC, f.kickoff_time DESC, f.id DESC
     LIMIT 500"
)->fetchAll(PDO::FETCH_ASSOC);

$form = [
    'title' => (string) ($album['title'] ?? ''),
    'description' => (string) ($album['description'] ?? ''),
    'album_date' => (string) ($album['album_date'] ?? ''),
    'match_fixture_id' => (int) ($album['match_fixture_id'] ?? 0),
    'display_on_site' => (int) ($album['display_on_site'] ?? 1),
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors !== []) {
    $form['title'] = trim((string) ($_POST['title'] ?? $form['title']));
    $form['description'] = trim((string) ($_POST['description'] ?? $form['description']));
    $form['album_date'] = trim((string) ($_POST['album_date'] ?? $form['album_date']));
    $form['match_fixture_id'] = (int) ($_POST['match_fixture_id'] ?? $form['match_fixture_id']);
    $form['display_on_site'] = !empty($_POST['display_on_site']) ? 1 : 0;
}

$coverThumb = (string) ($album['cover_path'] ?? '');

$status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
$statusText = [
    'created' => 'Album created — add some photos below.',
    'meta' => 'Album details saved.',
    'uploaded' => (int) ($_GET['n'] ?? 0) . ' photo(s) uploaded.' . (isset($_GET['f']) ? ' ' . (int) $_GET['f'] . ' skipped (not an image or too large).' : ''),
    'photo_deleted' => 'Photo deleted.',
    'cover' => 'Cover photo set.',
];
?>

<div class="photo-album-page">
    <?php if (!$isNew): ?>
        <p class="mb-3">
            <a href="/gallery/<?= h(rawurlencode((string) $album['slug'])) ?>" target="_blank" rel="noopener">View on the public site ↗</a>
            <span class="text-muted">·</span>
            <span class="text-muted">/gallery/<?= h((string) $album['slug']) ?></span>
            <?php if ((int) $form['display_on_site'] !== 1): ?>
                <span class="badge text-bg-secondary">Hidden from site</span>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if (isset($statusText[$status])): ?>
        <div class="alert alert-success"><?= h($statusText[$status]) ?></div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-warning"><?= h($error) ?></div>
    <?php endforeach; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card">
                <div class="card-body">
                    <h2 class="h5 mb-3"><?= $isNew ? 'New album' : 'Album details' ?></h2>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="<?= $isNew ? 'create' : 'save_meta' ?>">

                        <div class="mb-3">
                            <label class="form-label" for="title">Title</label>
                            <input type="text" class="form-control" id="title" name="title" maxlength="190" required
                                   value="<?= h($form['title']) ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="description">Short description <span class="text-muted">(optional)</span></label>
                            <input type="text" class="form-control" id="description" name="description" maxlength="255"
                                   value="<?= h($form['description']) ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="album_date">Date <span class="text-muted">(optional)</span></label>
                            <input type="date" class="form-control" id="album_date" name="album_date"
                                   value="<?= h($form['album_date']) ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="match_fixture_id">Linked game <span class="text-muted">(optional)</span></label>
                            <select class="form-select" id="match_fixture_id" name="match_fixture_id">
                                <option value="0">— Not linked to a game —</option>
                                <?php foreach ($fixtures as $fx): ?>
                                    <option value="<?= (int) $fx['id'] ?>" <?= (int) $fx['id'] === $form['match_fixture_id'] ? 'selected' : '' ?>>
                                        <?= h(($fx['match_date'] ? date('j M Y', strtotime((string) $fx['match_date'])) . ' · ' : '')
                                            . ((int) $fx['is_home'] === 1 ? 'v ' : 'at ') . (string) $fx['opponent']
                                            . ((string) $fx['competition'] !== '' ? ' (' . (string) $fx['competition'] . ')' : '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="display_on_site"
                                   name="display_on_site" value="1" <?= $form['display_on_site'] === 1 ? 'checked' : '' ?>>
                            <label class="form-check-label" for="display_on_site">Show on the public site</label>
                        </div>

                        <button type="submit" class="btn btn-brand"><?= $isNew ? 'Create album' : 'Save details' ?></button>
                    </form>
                </div>
            </div>

            <?php if (!$isNew): ?>
                <div class="card mt-3 border-danger-subtle">
                    <div class="card-body">
                        <h2 class="h6 text-danger">Delete album</h2>
                        <p class="text-muted small mb-2">Removes the album and all <?= count($photos) ?> photo(s), including the image files. This cannot be undone.</p>
                        <form method="post" data-confirm="Delete this album and all its photos? This cannot be undone." data-confirm-action="Delete">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_album">
                            <button type="submit" class="btn btn-outline-danger btn-sm">Delete album</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$isNew): ?>
            <div class="col-lg-7" id="photos">
                <div class="card">
                    <div class="card-body">
                        <h2 class="h5 mb-3">Photos <span class="text-muted">(<?= count($photos) ?>)</span></h2>

                        <form method="post" enctype="multipart/form-data" class="mb-4">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="upload">
                            <div class="input-group">
                                <input type="file" class="form-control" name="photos[]" accept="image/jpeg,image/png,image/webp,image/gif" multiple required>
                                <button type="submit" class="btn btn-brand">Upload</button>
                            </div>
                            <div class="form-text">JPG, PNG, WEBP or GIF. Large images are resized automatically. Up to 20 files per upload.</div>
                        </form>

                        <?php if ($photos === []): ?>
                            <div class="alert alert-info mb-0">No photos in this album yet.</div>
                        <?php else: ?>
                            <div class="row g-2">
                                <?php foreach ($photos as $index => $photo): ?>
                                    <?php
                                    $thumb = trim((string) $photo['thumb_path']);
                                    $thumbUrl = $thumb !== '' ? hub_media_web_url('uploads/' . $thumb) : '';
                                    $isCover = $thumb !== '' && $thumb === $coverThumb;
                                    $pid = (int) $photo['id'];
                                    ?>
                                    <div class="col-6 col-md-4">
                                        <div class="card h-100">
                                            <div style="position:relative;aspect-ratio:1/1;overflow:hidden;border-radius:6px 6px 0 0;background:#eee;">
                                                <?php if ($thumbUrl !== ''): ?>
                                                    <img src="<?= h($thumbUrl) ?>" alt="" loading="lazy" style="width:100%;height:100%;object-fit:cover;">
                                                <?php endif; ?>
                                                <?php if ($isCover): ?>
                                                    <span class="badge text-bg-warning" style="position:absolute;top:6px;left:6px;">Cover</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="card-body p-2 d-flex flex-wrap gap-1 justify-content-center">
                                                <form method="post" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="move_photo">
                                                    <input type="hidden" name="photo_id" value="<?= $pid ?>">
                                                    <button name="dir" value="up" class="btn btn-sm btn-outline-secondary" title="Move earlier" <?= $index === 0 ? 'disabled' : '' ?>>↑</button>
                                                    <button name="dir" value="down" class="btn btn-sm btn-outline-secondary" title="Move later" <?= $index === count($photos) - 1 ? 'disabled' : '' ?>>↓</button>
                                                </form>
                                                <form method="post" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="set_cover">
                                                    <input type="hidden" name="photo_id" value="<?= $pid ?>">
                                                    <button class="btn btn-sm btn-outline-secondary" title="Use as cover" <?= $isCover ? 'disabled' : '' ?>>★</button>
                                                </form>
                                                <form method="post" class="d-inline" data-confirm="Delete this photo?" data-confirm-action="Delete">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="delete_photo">
                                                    <input type="hidden" name="photo_id" value="<?= $pid ?>">
                                                    <button class="btn btn-sm btn-outline-danger" title="Delete">✕</button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
