<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/facebook_photo_import.php';

if (!hub_auth_is_authenticated()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Your session has expired.']);
        exit;
    }
    header('Location: /admin/login.php');
    exit;
}

$currentUser = hub_auth_current_user();
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('You do not have permission to import Facebook photos.');
}

function facebook_photo_import_json(array $payload, int $status = 200): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

facebook_photo_import_ensure_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hub_auth_verify_csrf_token(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null)) {
        facebook_photo_import_json(['ok' => false, 'message' => 'The security token is invalid. Refresh and try again.'], 419);
    }

    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'fetch') {
        $result = facebook_photo_import_fetch($pdo, (int) ($_POST['limit'] ?? 25), trim((string) ($_POST['after'] ?? '')), trim((string) ($_POST['album_id'] ?? '')));
        facebook_photo_import_json($result['ok']
            ? ['ok' => true, 'photos' => $result['photos'], 'next_after' => $result['next_after']]
            : ['ok' => false, 'message' => $result['error']], $result['ok'] ? 200 : 502);
    }

    if ($action === 'detect_faces') {
        $imageUrl = trim((string) ($_POST['image_url'] ?? ''));
        if ($imageUrl === '') {
            facebook_photo_import_json(['ok' => false, 'message' => 'Choose a photo before finding faces.'], 422);
        }
        $result = facebook_photo_import_detect_faces($pdo, $imageUrl);
        facebook_photo_import_json($result['ok']
            ? ['ok' => true, 'faces' => $result['faces']]
            : ['ok' => false, 'message' => $result['error']], $result['ok'] ? 200 : 422);
    }

    if ($action === 'add_person') {
        tagged_people_ensure_schema($pdo);
        $name = trim((string) ($_POST['name'] ?? ''));
        $category = (string) ($_POST['category'] ?? 'other');
        $nonPlayerCategories = array_diff(array_keys(TAGGED_PEOPLE_CATEGORIES), ['player']);
        if ($name === '') {
            facebook_photo_import_json(['ok' => false, 'message' => 'Enter a name for the new person.'], 422);
        }
        if (!in_array($category, $nonPlayerCategories, true)) {
            facebook_photo_import_json(['ok' => false, 'message' => 'Choose a valid person category.'], 422);
        }

        $stmt = $pdo->prepare('INSERT INTO tagged_people (name, category) VALUES (:name, :category)');
        $stmt->execute([':name' => $name, ':category' => $category]);
        facebook_photo_import_json([
            'ok' => true,
            'person' => ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'category' => $category],
            'people' => facebook_photo_import_people($pdo),
        ]);
    }

    if ($action === 'add_fixture') {
        $seasonId = getSelectedSeasonId($pdo);
        $season = getSeasonById($pdo, $seasonId);
        if (!$season) {
            facebook_photo_import_json(['ok' => false, 'message' => 'No working season is selected.'], 422);
        }
        if ((int) ($season['is_locked'] ?? 0) === 1) {
            facebook_photo_import_json(['ok' => false, 'message' => 'This season is locked.'], 422);
        }

        $matchDate = trim((string) ($_POST['match_date'] ?? ''));
        $opponent = trim((string) ($_POST['opponent'] ?? ''));
        $competition = trim((string) ($_POST['competition'] ?? ''));
        $isHome = ((string) ($_POST['is_home'] ?? '1') === '1') ? 1 : 0;
        if ($matchDate === '' || strtotime($matchDate) === false) {
            facebook_photo_import_json(['ok' => false, 'message' => 'Enter a valid match date.'], 422);
        }
        if ($opponent === '') {
            facebook_photo_import_json(['ok' => false, 'message' => 'Enter the opponent name.'], 422);
        }

        try {
            $result = matchImportUpsertFixture($pdo, $seasonId, [
                'match_date' => date('Y-m-d', strtotime($matchDate)),
                'opponent' => $opponent,
                'competition' => $competition,
                'is_home' => $isHome,
                'status' => 'scheduled',
                'notes' => 'Created from Facebook photo import.',
            ]);
        } catch (Throwable $e) {
            facebook_photo_import_json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $fixture = getMatchFixtureById($pdo, (int) $result['fixture_id']);
        if (!$fixture) {
            facebook_photo_import_json(['ok' => false, 'message' => 'Fixture was not created.'], 422);
        }
        $fixtures = facebook_photo_import_fixtures($pdo);
        facebook_photo_import_json([
            'ok' => true,
            'fixture' => [
                'id' => (int) $fixture['id'],
                'label' => facebook_photo_import_fixture_label($fixture),
                'default_kit' => ((int) ($fixture['is_home'] ?? 1)) === 1 ? 'home' : 'away',
            ],
            'fixtures' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'],
                'label' => facebook_photo_import_fixture_label($row),
                'default_kit' => ((int) ($row['is_home'] ?? 1)) === 1 ? 'home' : 'away',
            ], $fixtures),
        ]);
    }

    if ($action === 'import') {
        $items = json_decode((string) ($_POST['items'] ?? '[]'), true);
        if (!is_array($items) || $items === []) {
            facebook_photo_import_json(['ok' => false, 'message' => 'Select at least one photo to import.'], 422);
        }
        $mode = (string) ($_POST['mode'] ?? 'match');
        $userId = isset($currentUser['id']) ? (int) $currentUser['id'] : null;
        if ($mode === 'mixed') {
            $matchItems = [];
            $referenceItems = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ((string) ($item['save_mode'] ?? 'match') === 'reference') {
                    $referenceItems[] = $item;
                } else {
                    $matchItems[] = $item;
                }
            }

            $summary = ['imported' => 0, 'skipped' => 0, 'tagged' => 0, 'errors' => []];
            foreach ([
                $matchItems !== [] ? facebook_photo_import_save($pdo, $matchItems, $userId) : null,
                $referenceItems !== [] ? facebook_photo_import_save_references($pdo, $referenceItems, $userId) : null,
            ] as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $summary['imported'] += (int) ($part['imported'] ?? 0);
                $summary['skipped'] += (int) ($part['skipped'] ?? 0);
                $summary['tagged'] += (int) ($part['tagged'] ?? 0);
                $summary['errors'] = array_merge($summary['errors'], (array) ($part['errors'] ?? []));
            }
        } else {
            $summary = $mode === 'reference'
                ? facebook_photo_import_save_references($pdo, $items, $userId)
                : facebook_photo_import_save($pdo, $items, $userId);
        }
        facebook_photo_import_json(['ok' => $summary['imported'] > 0, 'summary' => $summary], $summary['imported'] > 0 ? 200 : 422);
    }

    if ($action === 'reject') {
        $photoIds = json_decode((string) ($_POST['photo_ids'] ?? '[]'), true);
        if (!is_array($photoIds) || $photoIds === []) {
            facebook_photo_import_json(['ok' => false, 'message' => 'Choose at least one photo to reject.'], 422);
        }
        $rejected = facebook_photo_import_reject($pdo, array_values(array_map('strval', $photoIds)), isset($currentUser['id']) ? (int) $currentUser['id'] : null);
        facebook_photo_import_json(['ok' => true, 'rejected' => $rejected]);
    }

    facebook_photo_import_json(['ok' => false, 'message' => 'Unknown import action.'], 400);
}

$fixtures = facebook_photo_import_fixtures($pdo);
$albums = facebook_photo_import_albums();
$people = facebook_photo_import_people($pdo);
$fixtureOptions = array_map(static function (array $fixture): array {
    return [
        'id' => (int) $fixture['id'],
        'label' => facebook_photo_import_fixture_label($fixture),
        'default_kit' => ((int) ($fixture['is_home'] ?? 1)) === 1 ? 'home' : 'away',
    ];
}, $fixtures);

$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'Facebook Photo Import',
    'subtitle' => 'Preview Facebook page photos, match them to fixtures, then import selected images into match galleries.',
    'actions' => [
        ['label' => 'Media Library', 'href' => '/match_photos.php', 'class' => 'btn btn-outline-light btn-sm'],
    ],
];
require __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="/admin/assets/css/facebook-photo-import.css?v=<?= (int) (@filemtime(__DIR__ . '/assets/css/facebook-photo-import.css') ?: time()) ?>">

<div class="facebook-import" id="facebookPhotoImport" data-csrf="<?= h(hub_auth_csrf_token()) ?>">
    <section class="facebook-import-toolbar" aria-labelledby="facebookImportTitle">
        <div class="facebook-import-toolbar__header">
            <div>
                <h2 id="facebookImportTitle">Find Facebook photos</h2>
                <p>Loads recent Facebook page photos. Choose the save type and assignment on each card before saving.</p>
            </div>
            <div class="facebook-import-status" id="facebookImportStatus" role="status" aria-live="polite"></div>
        </div>
        <div class="facebook-import-toolbar__actions">
            <label for="facebookImportAlbum">Source</label>
            <select id="facebookImportAlbum" class="form-select facebook-import-album-select">
                <option value="">Recent page photos</option>
                <?php foreach ($albums as $album): ?>
                    <option value="<?= h($album['id']) ?>"><?= h($album['name']) ?><?= (int) $album['count'] > 0 ? ' (' . (int) $album['count'] . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
            <label for="facebookImportLimit">Batch</label>
            <select id="facebookImportLimit" class="form-select">
                <option value="10">10</option>
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="200">200</option>
                <option value="500">500</option>
            </select>
            <button type="button" class="btn btn-primary" id="facebookImportFetch"><i class="fa-brands fa-facebook" aria-hidden="true"></i> Fetch photos</button>
        </div>
    </section>

    <section class="facebook-import-actions" id="facebookImportSelection" hidden>
        <div><strong id="facebookImportSelectedCount">0</strong> selected</div>
        <div class="facebook-import-actions__buttons">
            <button type="button" class="btn btn-outline-danger" id="facebookImportReject" disabled><i class="fa-solid fa-ban" aria-hidden="true"></i> Reject selected</button>
            <button type="button" class="btn btn-success" id="facebookImportSave" disabled><i class="fa-solid fa-download" aria-hidden="true"></i> Save selected</button>
        </div>
    </section>

    <section class="facebook-import-grid" id="facebookImportGrid" aria-live="polite"></section>
    <div class="facebook-import-loadmore" id="facebookImportLoadMore" hidden>
        <button type="button" class="btn btn-outline-primary" id="facebookImportMore"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Load 25 more photos</button>
        <button type="button" class="btn btn-outline-secondary" id="facebookImportAll"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Load all</button>
    </div>
</div>

<script>
window.FACEBOOK_IMPORT_FIXTURES = <?= json_encode($fixtureOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
window.FACEBOOK_IMPORT_PEOPLE = <?= json_encode($people, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
</script>
<script src="/admin/assets/js/facebook-photo-import.js?v=<?= (int) (@filemtime(__DIR__ . '/assets/js/facebook-photo-import.js') ?: time()) ?>" defer></script>
<?php require __DIR__ . '/footer.php'; ?>
