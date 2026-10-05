<?php
declare(strict_types=1);

$pageStyles = ['match-fixture-tabs.css'];

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/fixture_tabs.php';
require_once __DIR__ . '/lib/tagged_people.php';
require_once __DIR__ . '/matches_lib.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}

$useSharedHubLayout = true;
$fixtureId = (int) ($_GET['fixture_id'] ?? $_POST['fixture_id'] ?? $_GET['id'] ?? 0);
$requestedSeasonId = (int) ($_GET['season_id'] ?? $_POST['season_id'] ?? 0);
$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;

if (!$fixture) {
    http_response_code(404);
    $pageHero = [
        'eyebrow' => 'Fixture management',
        'title' => 'Match media',
        'subtitle' => 'The requested fixture could not be found.',
    ];
    require_once __DIR__ . '/header.php';
    echo '<div><div class="alert alert-danger">Fixture not found.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$seasonId = (int) ($fixture['season_id'] ?? $requestedSeasonId);
$season = getSeasonById($pdo, $seasonId);
$opponent = trim((string) ($fixture['opponent'] ?? 'Opponent'));
$defaultKit = !empty($fixture['is_home']) ? 'home' : 'away';

$people = tagged_people_all($pdo);
$peopleByCategory = tagged_people_grouped($people);

$photoStmt = $pdo->prepare("
    SELECT mp.id, mp.filename, mp.kit, mp.uploaded_at,
           pt.id AS tag_id, pt.tagged_person_id, pt.confidence, pt.source, tp.name AS person_name
    FROM match_photos mp
    LEFT JOIN match_photo_tags pt ON pt.match_photo_id = mp.id
    LEFT JOIN tagged_people tp ON tp.id = pt.tagged_person_id
    WHERE mp.match_fixture_id = :fixture_id
    ORDER BY mp.uploaded_at DESC, mp.id DESC
");
$photoStmt->execute([':fixture_id' => $fixtureId]);

$photos = [];
foreach ($photoStmt as $row) {
    $photoId = (int) $row['id'];
    if (!isset($photos[$photoId])) {
        $photos[$photoId] = [
            'id' => $photoId,
            'filename' => (string) $row['filename'],
            'kit' => $row['kit'],
            'uploaded_at' => $row['uploaded_at'],
            'tags' => [],
        ];
    }
    if ($row['tag_id'] !== null) {
        $photos[$photoId]['tags'][] = [
            'tag_id' => (int) $row['tag_id'],
            'person_id' => (int) $row['tagged_person_id'],
            'person_name' => (string) $row['person_name'],
            'confidence' => $row['confidence'] !== null ? (float) $row['confidence'] : null,
            'source' => (string) $row['source'],
        ];
    }
}
$photos = array_values($photos);

$flashToast = $_SESSION['flash_toast'] ?? null;
unset($_SESSION['flash_toast']);

$kitLabels = ['home' => 'Home kit', 'away' => 'Away kit', 'third' => 'Third kit'];

$pageHero = [
    'eyebrow' => 'Fixture management',
    'title' => 'Match media',
    'subtitle' => 'Upload photos from Saltcoats Victoria v ' . $opponent . '. Everyone is tagged automatically.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
$matchMediaJsVersion = (string) filemtime(__DIR__ . '/assets/js/match_media.js');
echo '<script src="/admin/assets/js/match_media.js?v=' . h($matchMediaJsVersion) . '" defer></script>';
?>
<link rel="stylesheet" href="/admin/assets/css/match_media.css">
<link rel="stylesheet" href="/admin/assets/css/player_edit.css">

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/matches.php">Fixtures</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><a href="/admin/match.php?id=<?= (int) $fixtureId ?>&amp;season_id=<?= (int) $seasonId ?>"><?= h($opponent) ?></a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Media</span></nav>

<div>
    <?php renderFixtureTabs($fixtureId, $seasonId, 'media'); ?>

    <?php if (!empty($flashToast['message'])): ?>
        <div class="alert alert-<?= h((string) ($flashToast['type'] ?? 'success')) ?>"><?= h((string) $flashToast['message']) ?></div>
    <?php endif; ?>
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

    <div class="card shadow-sm hub-form-card mb-4">
        <div class="card-body">
            <h2 class="h5 card-title">Upload match photos</h2>
            <p class="player-edit-help">Everyone in each photo is tagged automatically by comparing faces against the squad's profile pictures and everyone's reference photos on the <a href="/admin/people.php">People</a> page. Check the results below — tags can be added or removed at any time.</p>
            <form method="post" enctype="multipart/form-data" action="/admin/match_photo_upload.php" id="matchPhotoUploadForm">
                <?= csrf_field() ?>
                <input type="hidden" name="fixture_id" value="<?= (int) $fixtureId ?>">
                <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
                <div class="row g-3 align-items-start">
                    <div class="col-12 col-md-4 col-lg-3">
                        <label class="form-label" for="matchPhotoKit">Kit worn</label>
                        <select name="kit" id="matchPhotoKit" class="form-select">
                            <option value="home" <?= $defaultKit === 'home' ? 'selected' : '' ?>>Home kit</option>
                            <option value="away" <?= $defaultKit === 'away' ? 'selected' : '' ?>>Away kit</option>
                            <option value="third">Third kit</option>
                        </select>
                        <p class="player-edit-help mt-1 mb-0">Applied to every photo in this batch — you can fix individual photos afterwards.</p>
                    </div>
                    <div class="col-12 col-md-8 col-lg-9">
                        <label class="player-edit-avatar-uploader match-media-dropzone" id="matchPhotoDropzone" tabindex="0">
                            <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
                            <span class="avatar-dropzone-title">Drop match photos here</span>
                            <span class="avatar-dropzone-subtitle">or click to choose one or more JPG, PNG, GIF or WebP files</span>
                            <span class="match-media-filelist" id="matchPhotoFileList"></span>
                            <input type="file" name="photos[]" accept="image/png,image/jpeg,image/gif,image/webp" multiple class="avatar-dropzone-input" id="matchPhotoInput" required>
                        </label>
                    </div>
                </div>
                <div class="d-grid d-md-flex hub-actions mt-3">
                    <button type="submit" class="btn btn-brand" id="matchPhotoUploadButton">Upload photos</button>
                </div>
                <div class="match-media-progress d-none mt-3" id="matchPhotoProgress">
                    <div class="progress" role="progressbar" aria-label="Upload progress" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar bg-success" id="matchPhotoProgressBar" style="width: 0%"></div>
                    </div>
                    <p class="player-edit-help mt-1 mb-0" id="matchPhotoProgressLabel">Uploading…</p>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm hub-section">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                <h2 class="h5 card-title mb-0">Photos from this match</h2>
                <span class="text-muted small"><?= count($photos) ?> photo<?= count($photos) === 1 ? '' : 's' ?></span>
            </div>
            <?php if (!$photos): ?>
                <div class="hub-empty-state"><p class="text-muted mb-0">No photos uploaded yet.</p></div>
            <?php else: ?>
                <div class="match-media-grid">
                    <?php foreach ($photos as $photo): ?>
                        <div class="match-media-item" data-photo-id="<?= (int) $photo['id'] ?>">
                            <div class="match-media-item__image">
                                <img src="/uploads/matches/gallery/<?= (int) $fixtureId ?>/<?= rawurlencode($photo['filename']) ?>" alt="" loading="lazy">
                                <form method="post" action="/admin/match_photo_delete.php" class="match-media-item__delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                                    <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete photo" aria-label="Delete photo" data-confirm="This photo and its tags will be removed." data-confirm-title="Delete this photo?" data-confirm-action="Delete photo"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                                </form>
                            </div>
                            <div class="match-media-item__body">
                                <select class="form-select form-select-sm match-media-kit-select" data-photo-id="<?= (int) $photo['id'] ?>">
                                    <?php foreach ($kitLabels as $kitValue => $kitLabel): ?>
                                        <option value="<?= h($kitValue) ?>" <?= $photo['kit'] === $kitValue ? 'selected' : '' ?>><?= h($kitLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="match-media-tags">
                                    <?php foreach ($photo['tags'] as $tag): ?>
                                        <span class="match-media-tag match-media-tag--<?= h($tag['source']) ?>">
                                            <?= h($tag['person_name']) ?>
                                            <button type="button" class="match-media-tag__remove" data-tag-id="<?= (int) $tag['tag_id'] ?>" title="Remove tag" aria-label="Remove tag for <?= h($tag['person_name']) ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                                <form class="match-media-add-tag" data-photo-id="<?= (int) $photo['id'] ?>">
                                    <select class="form-select form-select-sm" name="person_id">
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
                                </form>
                                <form class="match-media-new-person d-none" data-photo-id="<?= (int) $photo['id'] ?>">
                                    <input type="text" class="form-control form-control-sm" name="name" placeholder="Name" required>
                                    <select class="form-select form-select-sm" name="category">
                                        <?php foreach (TAGGED_PEOPLE_CATEGORIES as $catValue => $catLabel): ?>
                                            <?php if ($catValue === 'player') continue; ?>
                                            <option value="<?= h($catValue) ?>" <?= $catValue === 'fan' ? 'selected' : '' ?>><?= h($catLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="match-media-new-person__actions">
                                        <button type="submit" class="btn btn-sm btn-brand">Add &amp; tag</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary match-media-new-person__cancel">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="matchMediaCsrf" data-csrf="<?= h($_SESSION['csrf_token'] ?? '') ?>" class="d-none"></div>

<?php require_once __DIR__ . '/footer.php'; ?>
