<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Club',
    'title' => 'People',
    'subtitle' => 'Reference people used for photo tagging across players, staff, fans, sponsors and volunteers.',
    'actions' => [
        ['label' => 'Upload reference', 'href' => '/admin/player_photo_review.php', 'class' => 'btn btn-brand'],
    ],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/tagged_people.php';

$flashToast = $_SESSION['flash_toast'] ?? null;
unset($_SESSION['flash_toast']);

$categoryLabels = TAGGED_PEOPLE_CATEGORIES;
$editableCategoryLabels = $categoryLabels;
unset($editableCategoryLabels['player']);

tagged_people_sync_players($pdo);

$stmt = $pdo->query("
    SELECT tp.id, tp.name, tp.category, tp.player_id, tp.created_at,
           tpp.id AS photo_id, tpp.filename
    FROM tagged_people tp
    LEFT JOIN tagged_people_photos tpp ON tpp.tagged_person_id = tp.id
    ORDER BY FIELD(tp.category, 'manager', 'coach', 'committee_volunteer', 'player', 'fan', 'sponsor', 'other'), tp.name ASC, tpp.id ASC
");

$people = [];
foreach ($stmt as $row) {
    $id = (int) $row['id'];
    if (!isset($people[$id])) {
        $people[$id] = [
            'id' => $id,
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'player_id' => $row['player_id'] !== null ? (int) $row['player_id'] : null,
            'photos' => [],
        ];
    }
    if ($row['photo_id'] !== null) {
        $people[$id]['photos'][] = ['id' => (int) $row['photo_id'], 'filename' => (string) $row['filename']];
    }
}

$grouped = [];
foreach ($people as $person) {
    $grouped[$person['category']][] = $person;
}

$playerReferences = [];
$playerMeta = [];
$playerStmt = $pdo->query("SELECT id, status, active FROM players");
foreach ($playerStmt as $row) {
    $playerMeta[(int) $row['id']] = [
        'status' => (string) ($row['status'] ?? 'current'),
        'active' => (int) ($row['active'] ?? 0),
    ];
}

$avatarStmt = $pdo->query("SELECT id, avatar FROM players WHERE avatar IS NOT NULL AND avatar <> ''");
foreach ($avatarStmt as $row) {
    $filename = basename((string) $row['avatar']);
    if ($filename !== '' && is_file(__DIR__ . '/uploads/players/' . $filename)) {
        $playerReferences[(int) $row['id']][] = [
            'url' => '/uploads/players/' . rawurlencode($filename),
            'label' => 'Profile',
        ];
    }
}
$actionShotStmt = $pdo->query("SELECT player_id, filename FROM player_action_shots ORDER BY sort_order ASC, id ASC");
foreach ($actionShotStmt as $row) {
    $filename = basename((string) $row['filename']);
    if ($filename !== '' && is_file(__DIR__ . '/uploads/players/action_shots/' . $filename)) {
        $playerReferences[(int) $row['player_id']][] = [
            'url' => '/uploads/players/action_shots/' . rawurlencode($filename),
            'label' => 'Action shot',
        ];
    }
}

$playerTaggedPhotos = [];
$taggedPhotoStmt = $pdo->query("
    SELECT tp.player_id,
           mp.id AS photo_id,
           mp.match_fixture_id,
           mp.filename,
           f.match_date,
           COALESCE(NULLIF(f.opponent, ''), o.clubname, 'Match') AS opponent
    FROM match_photo_tags mpt
    JOIN tagged_people tp ON tp.id = mpt.tagged_person_id
    JOIN match_photos mp ON mp.id = mpt.match_photo_id
    LEFT JOIN match_fixtures f ON f.id = mp.match_fixture_id
    LEFT JOIN match_opponents o ON o.id = f.opponent_id
    WHERE tp.player_id IS NOT NULL
    ORDER BY mp.uploaded_at DESC, mp.id DESC
");
foreach ($taggedPhotoStmt as $row) {
    $playerId = (int) $row['player_id'];
    $fixtureId = (int) $row['match_fixture_id'];
    $filename = basename((string) $row['filename']);
    if ($playerId <= 0 || $fixtureId <= 0 || $filename === '') {
        continue;
    }
    if (!is_file(__DIR__ . '/uploads/matches/gallery/' . $fixtureId . '/' . $filename)) {
        continue;
    }
    $matchDate = trim((string) ($row['match_date'] ?? ''));
    $opponent = trim((string) ($row['opponent'] ?? 'Match'));
    $playerTaggedPhotos[$playerId][] = [
        'url' => '/uploads/matches/gallery/' . $fixtureId . '/' . rawurlencode($filename),
        'href' => '/match_photos.php?photo=' . (int) $row['photo_id'],
        'label' => ($matchDate !== '' ? date('d M Y', strtotime($matchDate)) . ' - ' : '') . $opponent,
    ];
}

$playerCount = (int) $pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
$referencePhotoCount = (int) $pdo->query("SELECT COUNT(*) FROM tagged_people_photos")->fetchColumn();
$nonPlayerCount = count(array_filter($people, static fn (array $person): bool => $person['category'] !== 'player'));

function people_player_status_label(string $status): string
{
    return match ($status) {
        'trialist' => 'Trialist',
        'loan' => 'On loan',
        'injured' => 'Injured',
        'left' => 'Left',
        'retired' => 'Retired',
        default => 'Current squad',
    };
}

function people_render_player_card(array $person, array $playerMeta, array $playerReferences, array $playerTaggedPhotos): void
{
    $playerId = (int) ($person['player_id'] ?? 0);
    $meta = $playerMeta[$playerId] ?? ['status' => 'current', 'active' => 1];
    $statusLabel = people_player_status_label((string) ($meta['status'] ?? 'current'));
    $references = $playerId > 0 ? ($playerReferences[$playerId] ?? []) : [];
    $taggedPhotos = $playerId > 0 ? ($playerTaggedPhotos[$playerId] ?? []) : [];
    ?>
    <div class="card hub-table-card people-card" data-person-id="<?= (int) $person['id'] ?>">
        <div class="card-body">
            <div class="people-card__header people-card__header--managed">
                <div>
                    <h3><?= h((string) $person['name']) ?></h3>
                    <span><?= h($statusLabel) ?></span>
                </div>
                <?php if ($playerId > 0): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="/admin/player_photos.php?id=<?= $playerId ?>">Open</a>
                <?php endif; ?>
            </div>

            <?php if ($references): ?>
                <div class="people-photo-block">
                    <div class="people-photo-block__title">Reference photos</div>
                    <div class="people-player-reference-grid" aria-label="Player reference photos">
                        <?php foreach (array_slice($references, 0, 6) as $reference): ?>
                            <div class="people-player-reference">
                                <img src="<?= h((string) $reference['url']) ?>" alt="<?= h((string) $person['name']) ?> <?= h(strtolower((string) $reference['label'])) ?>" loading="lazy">
                                <span><?= h((string) $reference['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($references) > 6): ?>
                            <div class="people-player-reference people-player-reference--count">
                                <strong>+<?= count($references) - 6 ?></strong>
                                <span>More</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="people-managed-note">No player photo found yet. Add a profile image or action shots from this player's profile.</div>
            <?php endif; ?>

            <?php if ($taggedPhotos): ?>
                <div class="people-photo-block people-photo-block--tagged">
                    <div class="people-photo-block__title">Tagged in matches</div>
                    <div class="people-player-reference-grid" aria-label="Tagged match photos">
                        <?php foreach (array_slice($taggedPhotos, 0, 6) as $photo): ?>
                            <a class="people-player-reference" href="<?= h((string) $photo['href']) ?>">
                                <img src="<?= h((string) $photo['url']) ?>" alt="<?= h((string) $person['name']) ?> tagged in <?= h((string) $photo['label']) ?>" loading="lazy">
                                <span><?= h((string) $photo['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                        <?php if (count($taggedPhotos) > 6): ?>
                            <a class="people-player-reference people-player-reference--count" href="/admin/match_photos.php?person_id=<?= (int) $person['id'] ?>">
                                <strong>+<?= count($taggedPhotos) - 6 ?></strong>
                                <span>More</span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="people-managed-note">No tagged match photos yet.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>
<link rel="stylesheet" href="/admin/assets/css/player_edit.css">
<link rel="stylesheet" href="/admin/assets/css/people.css">

<div class="people-page">
    <?php if (!empty($flashToast['message'])): ?>
        <div class="alert alert-<?= h((string) ($flashToast['type'] ?? 'success')) ?>"><?= h((string) $flashToast['message']) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['photo_success'])): ?>
        <div class="alert alert-success"><?= (int) $_GET['photo_success'] ?> photo<?= (int) $_GET['photo_success'] === 1 ? '' : 's' ?> uploaded.</div>
    <?php endif; ?>
    <?php if (isset($_GET['photo_error'])): ?>
        <div class="alert alert-danger">Upload failed: <?= h((string) $_GET['photo_error']) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['photo_deleted'])): ?>
        <div class="alert alert-success">Photo removed.</div>
    <?php endif; ?>

    <section class="people-overview">
        <div>
            <h2>Tagging Directory</h2>
            <p><?= $playerCount ?> squad player<?= $playerCount === 1 ? '' : 's' ?> are synced from the Players page. Add everyone else here so match photos can be tagged more accurately.</p>
        </div>
        <div class="people-stats" aria-label="People summary">
            <div>
                <strong><?= count($people) ?></strong>
                <span>Total people</span>
            </div>
            <div>
                <strong><?= $nonPlayerCount ?></strong>
                <span>Manual records</span>
            </div>
            <div>
                <strong><?= $referencePhotoCount ?></strong>
                <span>Reference photos</span>
            </div>
        </div>
    </section>

    <div class="people-add-panel mb-4">
        <div class="people-add-panel__intro">
            <h2>Add a person</h2>
            <p>Use this for managers, coaches, volunteers, fans, sponsors and any other non-player reference.</p>
        </div>
        <div class="people-add-panel__form">
            <form method="post" action="/admin/person_save.php" class="row g-3 align-items-end">
                <?= csrf_field() ?>
                <div class="col-12 col-lg-5">
                    <label class="form-label" for="personName">Name</label>
                    <input type="text" class="form-control" id="personName" name="name" required>
                </div>
                <div class="col-12 col-lg-5">
                    <label class="form-label" for="personCategory">Category</label>
                    <select class="form-select" id="personCategory" name="category">
                        <?php foreach ($editableCategoryLabels as $catValue => $catLabel): ?>
                            <option value="<?= h($catValue) ?>" <?= $catValue === 'fan' ? 'selected' : '' ?>><?= h($catLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-2 d-grid">
                    <button type="submit" class="btn btn-brand">Add</button>
                </div>
            </form>
        </div>
    </div>

    <?php foreach ($categoryLabels as $catValue => $catLabel): ?>
        <?php $categoryPeople = $grouped[$catValue] ?? []; ?>
        <section class="people-section mb-4">
            <div class="people-section__header">
                <div>
                    <h2><?= h($catLabel) ?></h2>
                    <p><?= $catValue === 'player' ? 'Synced from the squad list and managed from player profiles.' : 'Reference records available for match-photo tagging.' ?></p>
                </div>
                <span><?= count($categoryPeople) ?></span>
            </div>
            <?php if ($categoryPeople): ?>
                <?php if ($catValue === 'player'): ?>
                    <?php
                    $currentPlayers = [];
                    $leftPlayers = [];
                    foreach ($categoryPeople as $person) {
                        $playerId = (int) ($person['player_id'] ?? 0);
                        $meta = $playerMeta[$playerId] ?? ['status' => 'current', 'active' => 1];
                        $status = (string) ($meta['status'] ?? 'current');
                        $isLeft = in_array($status, ['left', 'retired'], true) || (int) ($meta['active'] ?? 0) === 0;
                        if ($isLeft) {
                            $leftPlayers[] = $person;
                        } else {
                            $currentPlayers[] = $person;
                        }
                    }
                    ?>
                    <div class="people-player-groups">
                        <div class="people-player-group">
                            <div class="people-player-group__header">
                                <h3>Current Squad</h3>
                                <span><?= count($currentPlayers) ?></span>
                            </div>
                            <?php if ($currentPlayers): ?>
                                <div class="people-grid">
                                    <?php foreach ($currentPlayers as $person): ?>
                                        <?php people_render_player_card($person, $playerMeta, $playerReferences, $playerTaggedPhotos); ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="people-section__empty">No current squad players found.</div>
                            <?php endif; ?>
                        </div>
                        <div class="people-player-group">
                            <div class="people-player-group__header">
                                <h3>Players Who Have Left</h3>
                                <span><?= count($leftPlayers) ?></span>
                            </div>
                            <?php if ($leftPlayers): ?>
                                <div class="people-grid">
                                    <?php foreach ($leftPlayers as $person): ?>
                                        <?php people_render_player_card($person, $playerMeta, $playerReferences, $playerTaggedPhotos); ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="people-section__empty">No former players found.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="people-grid">
                    <?php foreach ($categoryPeople as $person): ?>
                        <div class="card hub-table-card people-card" data-person-id="<?= (int) $person['id'] ?>">
                            <div class="card-body">
                                <div class="people-card__header">
                                    <input type="text" class="form-control form-control-sm people-name-input" value="<?= h($person['name']) ?>" data-id="<?= (int) $person['id'] ?>">
                                    <select class="form-select form-select-sm people-category-select" data-id="<?= (int) $person['id'] ?>">
                                        <?php foreach ($editableCategoryLabels as $optValue => $optLabel): ?>
                                            <option value="<?= h($optValue) ?>" <?= $optValue === $catValue ? 'selected' : '' ?>><?= h($optLabel) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="player-action-shots-grid people-photos-grid">
                                    <?php foreach ($person['photos'] as $photo): ?>
                                        <div class="player-action-shot">
                                            <img src="/uploads/tagged_people/<?= rawurlencode($photo['filename']) ?>" alt="">
                                            <form method="post" action="/admin/person_photo_delete.php" class="player-action-shot__remove">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="photo_id" value="<?= (int) $photo['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove photo" aria-label="Remove photo" data-confirm="This reference photo will be removed." data-confirm-title="Remove this photo?" data-confirm-action="Remove photo"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                    <form method="post" enctype="multipart/form-data" action="/admin/person_photo_upload.php" class="player-action-shot player-action-shot--add people-photo-upload-form">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="person_id" value="<?= (int) $person['id'] ?>">
                                        <label class="player-action-shot-uploader people-photo-dropzone" tabindex="0">
                                            <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
                                            <span class="avatar-dropzone-title">Add photos</span>
                                            <input type="file" name="photos[]" accept="image/png,image/jpeg,image/gif,image/webp" multiple class="avatar-dropzone-input">
                                        </label>
                                    </form>
                                </div>
                                <?php if (!$person['photos']): ?>
                                    <p class="people-card__hint">No reference photos yet. Add one so photos of <?= h($person['name']) ?> can be tagged automatically.</p>
                                <?php endif; ?>

                                <form method="post" action="/admin/person_delete.php" class="people-card__delete">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $person['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm="<?= h($person['name']) ?> and their tags will be removed." data-confirm-title="Remove this person?" data-confirm-action="Remove">
                                        <i class="fa-solid fa-trash me-1" aria-hidden="true"></i>Remove
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="people-section__empty">No <?= h(strtolower($catLabel)) ?> added yet.</div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php if (!$people): ?>
        <div class="hub-empty-state"><p class="text-muted mb-0">No one added yet. Use the form above to add your manager, coaches, volunteers, fans or sponsors.</p></div>
    <?php endif; ?>
</div>

<div id="peopleCsrf" data-csrf="<?= h($_SESSION['csrf_token'] ?? '') ?>" class="d-none"></div>
<script src="/admin/assets/js/people.js?v=<?= h((string) filemtime(__DIR__ . '/assets/js/people.js')) ?>" defer></script>

<?php require __DIR__ . '/footer.php'; ?>
