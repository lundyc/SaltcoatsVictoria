<?php
// player_photos_bulk.php — Bulk-upload player profile pictures.
// Step 1: upload a batch of photos. Step 2: match each photo to a player by name.
// Saving the match list sets that player's profile picture (avatar), replacing any existing one.
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/face_match.php';
require_once __DIR__ . '/sync_social_directory.php';
require_once __DIR__ . '/lib/audit.php';

$pageHero = [
    'eyebrow' => 'Club',
    'title' => 'Bulk player photos',
    'subtitle' => 'Upload photos, then match each one to a player to set their profile picture.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';

$stagingDir = __DIR__ . '/uploads/players/_staging';
$uploadDir = __DIR__ . '/uploads/players';
$allowedMimes = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

function player_photos_bulk_prepare_dir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }
    return mkdir($dir, 0775, true) && is_dir($dir);
}

function player_photos_bulk_cleanup_staging(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $cutoff = time() - 3600;
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_file($path) && filemtime($path) < $cutoff) {
            @unlink($path);
        }
    }
}

$stage = $_POST['stage'] ?? '';
$errors = [];
$stagedPhotos = []; // ['filename' => staged filename, 'original' => original name]
$assignResults = null;

$players = $pdo->query("SELECT id, name FROM players ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($stage !== 'assign') {
    player_photos_bulk_cleanup_staging($stagingDir);
}

if ($stage === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $files = $_FILES['photos'] ?? null;
    if (!$files || !is_array($files['name'] ?? null) || count(array_filter($files['name'])) === 0) {
        $errors[] = 'Choose at least one photo to upload.';
    } elseif (!player_photos_bulk_prepare_dir($stagingDir)) {
        $errors[] = 'Failed to prepare upload directory.';
    } else {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                $errors[] = htmlspecialchars((string) $files['name'][$i]) . ' failed to upload.';
                continue;
            }

            $tmpName = $files['tmp_name'][$i];
            $size = (int) ($files['size'][$i] ?? 0);
            $originalName = (string) $files['name'][$i];

            if (!is_uploaded_file($tmpName)) {
                $errors[] = htmlspecialchars($originalName) . ' is not a valid upload.';
                continue;
            }
            if ($size > 5 * 1024 * 1024) {
                $errors[] = htmlspecialchars($originalName) . ' is larger than 5MB.';
                continue;
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $tmpName) : null;
            if ($finfo) finfo_close($finfo);

            if (!isset($allowedMimes[$mime ?? ''])) {
                $errors[] = htmlspecialchars($originalName) . ' is not a supported image format.';
                continue;
            }

            try {
                $random = bin2hex(random_bytes(8));
            } catch (Exception $e) {
                $errors[] = 'Failed to prepare filename for ' . htmlspecialchars($originalName) . '.';
                continue;
            }

            $stagedFilename = 'staged_' . $random . '.' . $allowedMimes[$mime];
            $destination = $stagingDir . '/' . $stagedFilename;

            if (!move_uploaded_file($tmpName, $destination)) {
                $errors[] = 'Failed to store ' . htmlspecialchars($originalName) . '.';
                continue;
            }

            $stagedPhotos[] = ['filename' => $stagedFilename, 'original' => $originalName];
        }

        if (!$stagedPhotos && !$errors) {
            $errors[] = 'No photos were uploaded.';
        }

        if ($stagedPhotos) {
            $queryPaths = [];
            foreach ($stagedPhotos as $photo) {
                $queryPaths[$photo['filename']] = $stagingDir . '/' . $photo['filename'];
            }
            $suggestions = face_match_get_suggestions(face_match_build_references($pdo, $uploadDir), $queryPaths);

            $playersById = array_column($players, 'name', 'id');
            foreach ($stagedPhotos as &$photo) {
                $suggestion = $suggestions[$photo['filename']] ?? null;
                if ($suggestion && isset($playersById[$suggestion['player_id']])) {
                    $photo['suggested_player_id'] = $suggestion['player_id'];
                    $photo['suggested_player_name'] = $playersById[$suggestion['player_id']];
                    $photo['suggested_score'] = $suggestion['score'];
                } else {
                    $photo['suggested_player_id'] = null;
                    $photo['suggested_player_name'] = null;
                    $photo['suggested_score'] = null;
                }
            }
            unset($photo);
        }
    }
}

if ($stage === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $filenames = $_POST['filename'] ?? [];
    $playerIds = $_POST['player_id'] ?? [];
    $assignResults = ['updated' => 0, 'skipped' => 0];

    $playerIdSet = array_column($players, 'id');

    foreach ($filenames as $index => $stagedFilename) {
        $stagedFilename = basename((string) $stagedFilename);
        $sourcePath = $stagingDir . '/' . $stagedFilename;
        $playerId = (int) ($playerIds[$index] ?? 0);

        if ($playerId <= 0 || !in_array($playerId, $playerIdSet, true) || !is_file($sourcePath)) {
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }
            $assignResults['skipped']++;
            continue;
        }

        $stmt = $pdo->prepare('SELECT avatar FROM players WHERE id = :id');
        $stmt->execute([':id' => $playerId]);
        $existingAvatar = basename((string) ($stmt->fetchColumn() ?: ''));

        $ext = strtolower(pathinfo($stagedFilename, PATHINFO_EXTENSION));
        $finalFilename = 'avatar_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $finalPath = $uploadDir . '/' . $finalFilename;

        if (!player_photos_bulk_prepare_dir($uploadDir) || !rename($sourcePath, $finalPath)) {
            $assignResults['skipped']++;
            continue;
        }

        if ($existingAvatar !== '' && is_file($uploadDir . '/' . $existingAvatar)) {
            @unlink($uploadDir . '/' . $existingAvatar);
        }

        $update = $pdo->prepare('UPDATE players SET avatar = :avatar WHERE id = :id');
        $update->execute([':avatar' => $finalFilename, ':id' => $playerId]);
        $assignResults['updated']++;
    }

    if ($assignResults['updated'] > 0) {
        auditLog($pdo, 'player_photo_bulk_assigned', "Bulk-assigned profile pictures to {$assignResults['updated']} player(s)");
        player_sponsors_sync_social_directory();
    }
}
?>

<link rel="stylesheet" href="/admin/assets/css/player_edit.css">

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/players.php">Players</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Bulk photos</span></nav>

<div class="player-edit-page">
    <?php if ($assignResults !== null): ?>
        <div class="alert alert-success">
            <?= (int) $assignResults['updated'] ?> profile picture<?= $assignResults['updated'] === 1 ? '' : 's' ?> updated.
            <?php if ($assignResults['skipped'] > 0): ?>
                <?= (int) $assignResults['skipped'] ?> photo<?= $assignResults['skipped'] === 1 ? '' : 's' ?> skipped (no player selected).
            <?php endif; ?>
        </div>
        <a href="/admin/players.php" class="btn btn-brand">Back to players</a>
    <?php elseif ($stagedPhotos): ?>
        <div class="card shadow-sm hub-form-card">
            <div class="card-body">
                <h2 class="h5 card-title">Match photos to players</h2>
                <p class="player-edit-help">Pick the player for each photo. Their existing profile picture will be replaced. Leave a photo unmatched to skip it.</p>
                <p class="player-edit-help">Photos are compared automatically against players' existing profile pictures — check any highlighted suggestion before saving, it isn't always right.</p>
                <?php if ($errors): ?>
                    <div class="alert alert-warning"><?php foreach ($errors as $e): ?><div><?= $e ?></div><?php endforeach; ?></div>
                <?php endif; ?>
                <form method="post" action="/admin/player_photos_bulk.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="stage" value="assign">
                    <div class="player-photos-bulk-grid">
                        <?php foreach ($stagedPhotos as $photo): ?>
                            <?php
                                $score = $photo['suggested_score'];
                                $isLikely = $score !== null && $score >= FACE_MATCH_LIKELY_THRESHOLD;
                                $isPossible = $score !== null && !$isLikely && $score >= FACE_MATCH_POSSIBLE_THRESHOLD;
                                $preselectId = $isLikely ? $photo['suggested_player_id'] : null;
                            ?>
                            <div class="player-photos-bulk-item">
                                <div class="player-edit-avatar-preview">
                                    <img src="/uploads/players/_staging/<?= rawurlencode($photo['filename']) ?>" alt="">
                                </div>
                                <div class="player-photos-bulk-name text-truncate" title="<?= h($photo['original']) ?>"><?= h($photo['original']) ?></div>
                                <input type="hidden" name="filename[]" value="<?= h($photo['filename']) ?>">
                                <select name="player_id[]" class="form-select form-select-sm">
                                    <option value="">Skip this photo</option>
                                    <?php foreach ($players as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>" <?= $preselectId === (int) $p['id'] ? 'selected' : '' ?>><?= h((string) $p['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($isLikely): ?>
                                    <div class="player-photos-bulk-suggestion player-photos-bulk-suggestion--likely"><i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Suggested: <?= h((string) $photo['suggested_player_name']) ?> (<?= (int) round($score * 100) ?>%)</div>
                                <?php elseif ($isPossible): ?>
                                    <div class="player-photos-bulk-suggestion player-photos-bulk-suggestion--possible"><i class="fa-regular fa-circle-question" aria-hidden="true"></i> Possible: <?= h((string) $photo['suggested_player_name']) ?> (<?= (int) round($score * 100) ?>%)</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-grid d-md-flex hub-actions mt-3">
                        <button type="submit" class="btn btn-brand">Save and update profile pictures</button>
                        <a href="/admin/player_photos_bulk.php" class="btn btn-outline-secondary">Start over</a>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm hub-form-card">
            <div class="card-body">
                <h2 class="h5 card-title">Upload photos</h2>
                <p class="player-edit-help">Choose one photo per player. On the next step you'll match each photo to a player name.</p>
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><?php foreach ($errors as $e): ?><div><?= $e ?></div><?php endforeach; ?></div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" action="/admin/player_photos_bulk.php" id="bulkPhotosUploadForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="stage" value="upload">
                    <label class="player-edit-avatar-uploader player-photos-bulk-dropzone" id="bulkPhotosDropzone" tabindex="0">
                        <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
                        <span class="avatar-dropzone-title">Drop photos here</span>
                        <span class="avatar-dropzone-subtitle">or click to choose one or more JPG, PNG, GIF or WebP files</span>
                        <span class="player-photos-bulk-filelist" id="bulkPhotosFileList"></span>
                        <input type="file" name="photos[]" accept="image/png,image/jpeg,image/gif,image/webp" multiple class="avatar-dropzone-input" id="bulkPhotosInput" required>
                    </label>
                    <div class="d-grid d-md-flex hub-actions mt-3">
                        <button type="submit" class="btn btn-brand">Upload photos</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.player-photos-bulk-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 1rem;
}
.player-photos-bulk-item {
    display: flex;
    flex-direction: column;
    gap: .4rem;
}
.player-photos-bulk-item .player-edit-avatar-preview {
    width: 100%;
    height: 140px;
    margin: 0;
}
.player-photos-bulk-name {
    font-size: .75rem;
    color: rgba(31, 26, 29, .58);
}
.player-photos-bulk-suggestion {
    font-size: .72rem;
    font-weight: 600;
    line-height: 1.3;
}
.player-photos-bulk-suggestion--likely {
    color: var(--brand-primary);
}
.player-photos-bulk-suggestion--possible {
    color: rgba(31, 26, 29, .55);
    font-weight: 500;
}
.player-photos-bulk-dropzone {
    width: 100%;
    min-height: 220px;
    margin: 0;
}
.player-photos-bulk-filelist {
    margin-top: .6rem;
    max-width: 90%;
    font-size: .78rem;
    font-weight: 600;
    color: var(--brand-primary);
    text-align: center;
    word-break: break-word;
}
</style>

<script>
$(function () {
    var dropzone = $('#bulkPhotosDropzone');
    var input = $('#bulkPhotosInput');
    var fileList = $('#bulkPhotosFileList');

    function describeFiles(files) {
        if (!files || files.length === 0) {
            fileList.text('');
            return;
        }
        if (files.length === 1) {
            fileList.text(files[0].name);
        } else {
            fileList.text(files.length + ' photos selected');
        }
    }

    input.on('change', function () {
        describeFiles(this.files);
    });

    dropzone.on('dragover', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('drag-over');
    });

    dropzone.on('dragleave', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');
    });

    dropzone.on('drop', function (e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('drag-over');
        var dt = e.originalEvent.dataTransfer;
        if (!dt || !dt.files || dt.files.length === 0) return;
        input[0].files = dt.files;
        describeFiles(dt.files);
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
