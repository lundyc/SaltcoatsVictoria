<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, name, avatar FROM players WHERE id = ?');
$stmt->execute([$id]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$player) {
    http_response_code(404);
    exit('Player not found.');
}
$pageHero = [
    'eyebrow' => 'People',
    'title' => $player['name'] . ' — Photos',
    'subtitle' => 'Profile image, reference photos and tagged match photos in one place.',
];
require_once __DIR__ . '/header.php';

$references = [];
$avatar = basename((string) ($player['avatar'] ?? ''));
if ($avatar !== '' && is_file(__DIR__ . '/uploads/players/' . $avatar)) {
    $references[] = ['url' => '/uploads/players/' . rawurlencode($avatar), 'label' => 'Profile picture'];
}
$stmt = $pdo->prepare('SELECT filename FROM player_action_shots WHERE player_id = ? ORDER BY sort_order, id');
$stmt->execute([$id]);
foreach ($stmt as $row) {
    $filename = basename((string) $row['filename']);
    if ($filename !== '' && is_file(__DIR__ . '/uploads/players/action_shots/' . $filename)) {
        $references[] = ['url' => '/uploads/players/action_shots/' . rawurlencode($filename), 'label' => 'Action shot'];
    }
}
$stmt = $pdo->prepare("SELECT DISTINCT mp.id, mp.filename, mp.match_fixture_id, f.match_date,
    COALESCE(NULLIF(f.opponent, ''), o.clubname, 'Match') AS opponent
    FROM match_photo_tags mpt
    JOIN tagged_people tp ON tp.id = mpt.tagged_person_id
    JOIN match_photos mp ON mp.id = mpt.match_photo_id
    LEFT JOIN match_fixtures f ON f.id = mp.match_fixture_id
    LEFT JOIN match_opponents o ON o.id = f.opponent_id
    WHERE tp.player_id = ? ORDER BY f.match_date DESC, mp.id DESC");
$stmt->execute([$id]);
$matches = [];
foreach ($stmt as $row) {
    $filename = basename((string) $row['filename']);
    $fixtureId = (int) $row['match_fixture_id'];
    if ($filename === '' || !is_file(__DIR__ . '/uploads/matches/gallery/' . $fixtureId . '/' . $filename)) {
        continue;
    }
    $date = !empty($row['match_date']) ? date('d M Y', strtotime($row['match_date'])) . ' — ' : '';
    $matches[] = [
        'url' => '/uploads/matches/gallery/' . $fixtureId . '/' . rawurlencode($filename),
        'href' => '/admin/match_photos.php?photo=' . (int) $row['id'],
        'label' => $date . $row['opponent'],
    ];
}
?>
<style>
.player-photo-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:1rem; }
.player-photo-grid a { display:block; color:inherit; }
.player-photo-grid img { width:100%; height:190px; object-fit:contain; background:#f3f4f6; border-radius:8px; }
.player-photo-grid span { display:block; padding:.5rem 0; }
.player-photo-actions { display:flex; flex-wrap:wrap; gap:.5rem; margin-bottom:1rem; }
</style>
<div class="player-photo-actions">
    <a class="btn btn-outline-secondary" href="/admin/people.php">Back to people</a>
    <a class="btn btn-outline-primary" href="/admin/player_view.php?id=<?= $id ?>">Player profile</a>
    <a class="btn btn-outline-primary" href="/admin/player_edit.php?id=<?= $id ?>">Manage profile picture</a>
</div>
<?php if (!empty($_GET['action_shot_error'])): ?>
    <div class="alert alert-danger" role="alert"><?= h((string) $_GET['action_shot_error']) ?></div>
<?php endif; ?>
<?php if ((int) ($_GET['action_shot_success'] ?? 0) > 0): ?>
    <div class="alert alert-success" role="status"><?= (int) $_GET['action_shot_success'] ?> photo(s) uploaded.</div>
<?php endif; ?>
<section class="card mb-4"><div class="card-body">
    <h2 class="h4">Upload player photos</h2>
    <p>Choose photos of <?= h((string) $player['name']) ?>. Every image in this batch will be saved to this player's action shots and shown below. Your profile picture stays as it is.</p>
    <form method="post" enctype="multipart/form-data" action="/admin/player_action_shot_upload.php">
        <?= csrf_field() ?>
        <input type="hidden" name="player_id" value="<?= $id ?>">
        <input type="hidden" name="return_to" value="player_photos">
        <label for="player-photo-files" class="form-label">Photos (JPG, PNG, GIF or WebP; up to 5MB each)</label>
        <input id="player-photo-files" class="form-control mb-3" type="file" name="action_shots[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple required>
        <p class="text-muted">Up to <?= (int) ini_get('max_file_uploads') ?> files per batch, subject to the server's <?= h((string) ini_get('post_max_size')) ?> total upload limit. Select only photos you have confirmed belong to this player.</p>
        <button class="btn btn-primary" type="submit">Upload photos</button>
    </form>
</div></section>
<section class="card mb-4"><div class="card-body">
    <h2 class="h4">Reference photos <small class="text-muted">(<?= count($references) ?>)</small></h2>
    <?php if (!$references): ?><p>No profile picture or action shots yet.</p><?php endif; ?>
    <div class="player-photo-grid">
    <?php foreach ($references as $photo): ?>
        <a href="<?= h($photo['url']) ?>" target="_blank" rel="noopener">
            <img src="<?= h($photo['url']) ?>" alt="<?= h($player['name'] . ' — ' . $photo['label']) ?>" loading="lazy">
            <span><?= h($photo['label']) ?></span>
        </a>
    <?php endforeach; ?>
    </div>
</div></section>
<section class="card mb-4"><div class="card-body">
    <h2 class="h4">Tagged in matches <small class="text-muted">(<?= count($matches) ?>)</small></h2>
    <?php if (!$matches): ?><p>No tagged match photos yet.</p><?php endif; ?>
    <div class="player-photo-grid">
    <?php foreach ($matches as $photo): ?>
        <a href="<?= h($photo['href']) ?>">
            <img src="<?= h($photo['url']) ?>" alt="<?= h($player['name'] . ' — ' . $photo['label']) ?>" loading="lazy">
            <span><?= h($photo['label']) ?></span>
        </a>
    <?php endforeach; ?>
    </div>
</div></section>
<?php require_once __DIR__ . '/footer.php'; ?>
