<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/face_match.php';
require_once __DIR__ . '/sync_social_directory.php';
require_once __DIR__ . '/lib/audit.php';
$user = hub_auth_current_user();
$accountId = (int) ($user['account_id'] ?? 0);
$legacyId = (int) ($user['id'] ?? 0);
if ($accountId <= 0 && $legacyId <= 0) { http_response_code(403); exit('Access denied.'); }
$owner = $accountId > 0 ? 'account:' . $accountId : 'legacy:' . $legacyId;
$pdo->exec("CREATE TABLE IF NOT EXISTS player_photo_review_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_key VARCHAR(64) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY owner_queue (owner_key, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$directory = __DIR__ . '/uploads/players/action_shots';
$players = $pdo->query('SELECT id, name, avatar FROM players ORDER BY name, id')->fetchAll();
$playerIds = array_fill_keys(array_map('intval', array_column($players, 'id')), true);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $errors = [];
    $saved = 0;
    if (($_POST['action'] ?? '') === 'upload') {
        $file = $_FILES['photo'] ?? [];
        $allowed = ['image/jpeg'=>'jpg', 'image/png'=>'png', 'image/gif'=>'gif', 'image/webp'=>'webp'];
        try {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                throw new RuntimeException('The upload failed. Choose an image and try again.');
            }
            if ((int) $file['size'] > 5 * 1024 * 1024) { throw new RuntimeException('Each image must be 5MB or smaller.'); }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!isset($allowed[$mime]) || !@getimagesize($file['tmp_name'])) { throw new RuntimeException('Use a JPG, PNG, GIF or WebP image.'); }
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) { throw new RuntimeException('Could not prepare photo storage.'); }
            $filename = 'reference_' . bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
            if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) { throw new RuntimeException('Could not store the image.'); }
            try {
                $stmt = $pdo->prepare('INSERT INTO player_photo_review_queue (owner_key, filename, original_name) VALUES (?, ?, ?)');
                $stmt->execute([$owner, $filename, mb_substr(basename((string) $file['name']), 0, 255)]);
            } catch (Throwable $e) { @unlink($directory . '/' . $filename); throw $e; }
            $saved = 1;
        } catch (Throwable $e) {
            $errors[] = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Could not save the upload. Please try again.';
        }
        header('Content-Type: application/json');
        http_response_code($errors ? 422 : 200);
        echo json_encode(['success' => !$errors, 'error' => implode(' ', $errors)]);
        exit;
    }
    if (($_POST['action'] ?? '') === 'delete') {
        $queueId = (int) ($_POST['queue_id'] ?? 0);
        $error = '';
        if ($queueId <= 0) {
            $error = 'Invalid image.';
        } else {
            $stmt = $pdo->prepare('SELECT filename FROM player_photo_review_queue WHERE id = ? AND owner_key = ?');
            $stmt->execute([$queueId, $owner]);
            $filename = $stmt->fetchColumn();
            if (!$filename) {
                $error = 'Image not found in your queue.';
            } else {
                $stmt = $pdo->prepare('DELETE FROM player_photo_review_queue WHERE id = ? AND owner_key = ?');
                $stmt->execute([$queueId, $owner]);
                $path = $directory . '/' . basename((string) $filename);
                if (is_file($path)) { @unlink($path); }
            }
        }
        header('Content-Type: application/json');
        http_response_code($error ? 422 : 200);
        echo json_encode(['success' => $error === '', 'error' => $error]);
        exit;
    }
    if (($_POST['action'] ?? '') === 'save') {
        $selections = $_POST['player'] ?? [];
        if (!is_array($selections)) { $selections = []; }
        $playersDir = __DIR__ . '/uploads/players';
        foreach ($selections as $queueId => $playerId) {
            if (!ctype_digit((string) $queueId) || !is_scalar($playerId)) { continue; }
            $playerId = (int) $playerId;
            if ($playerId === 0) { continue; }
            if (!isset($playerIds[$playerId])) { $errors[] = 'A selected player is no longer available.'; continue; }
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT filename FROM player_photo_review_queue WHERE id = ? AND owner_key = ? FOR UPDATE');
                $stmt->execute([(int) $queueId, $owner]);
                $filename = $stmt->fetchColumn();
                if (!$filename) { $pdo->rollBack(); continue; }
                $sourcePath = $directory . '/' . basename((string) $filename);
                if (!is_file($sourcePath)) { throw new RuntimeException('Image missing.'); }
                $stmt = $pdo->prepare('SELECT avatar FROM players WHERE id = ? FOR UPDATE');
                $stmt->execute([$playerId]);
                $playerRow = $stmt->fetch();
                if ($playerRow === false) { throw new RuntimeException('Player missing.'); }
                $existingAvatar = basename((string) ($playerRow['avatar'] ?? ''));

                // Move the queued upload into the players' profile-picture directory —
                // saving here sets it as that player's reference/profile picture,
                // replacing whatever they had before.
                $ext = strtolower(pathinfo((string) $filename, PATHINFO_EXTENSION));
                $newFilename = 'avatar_' . bin2hex(random_bytes(8)) . ($ext !== '' ? '.' . $ext : '');
                if (!rename($sourcePath, $playersDir . '/' . $newFilename)) { throw new RuntimeException('Could not save the image.'); }

                $stmt = $pdo->prepare('UPDATE players SET avatar = ? WHERE id = ?');
                $stmt->execute([$newFilename, $playerId]);
                $stmt = $pdo->prepare('DELETE FROM player_photo_review_queue WHERE id = ? AND owner_key = ?');
                $stmt->execute([(int) $queueId, $owner]);
                $pdo->commit();
                $saved++;

                if ($existingAvatar !== '' && $existingAvatar !== $newFilename && is_file($playersDir . '/' . $existingAvatar)) {
                    @unlink($playersDir . '/' . $existingAvatar);
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $errors[] = 'An image could not be assigned. It remains in your review queue.';
            }
        }
        if ($saved > 0) {
            auditLog($pdo, 'player_photo_review_assigned', "Assigned profile pictures to {$saved} player(s) via the photo review queue");
            player_sponsors_sync_social_directory();
        }
        $_SESSION['photo_review_result'] = ['saved' => $saved, 'errors' => array_unique($errors)];
        header('Location: /admin/player_photo_review.php');
        exit;
    }
    http_response_code(400); exit('Invalid action.');
}
$references = [];
// Face-match candidates in the shape lib/face_match.php expects — built from the
// exact same source photos as $references below (profile picture, action shots,
// AND tagged match photos) so the suggestion engine can find a player even when
// their only known photos are tags on match galleries, not a dedicated avatar.
$faceMatchReferences = [];
function review_add_photo(array &$references, array &$faceMatchReferences, int $id, string $relative, string $label): void {
    $absolute = __DIR__ . '/uploads/' . rawurldecode($relative);
    if (is_file($absolute)) {
        $references[$id][] = ['url' => '/uploads/' . $relative, 'label' => $label];
        $faceMatchReferences[] = ['player_id' => $id, 'path' => $absolute];
    }
}
foreach ($players as $player) {
    $filename = basename((string) ($player['avatar'] ?? ''));
    if ($filename !== '') { review_add_photo($references, $faceMatchReferences, (int) $player['id'], 'players/' . rawurlencode($filename), 'Profile picture'); }
}
foreach ($pdo->query('SELECT player_id, filename FROM player_action_shots ORDER BY sort_order, id') as $row) {
    review_add_photo($references, $faceMatchReferences, (int) $row['player_id'], 'players/action_shots/' . rawurlencode(basename($row['filename'])), 'Action shot');
}
foreach ($pdo->query('SELECT DISTINCT tp.player_id, mp.id, mp.filename, mp.match_fixture_id FROM match_photo_tags t JOIN tagged_people tp ON tp.id = t.tagged_person_id JOIN match_photos mp ON mp.id = t.match_photo_id WHERE tp.player_id IS NOT NULL ORDER BY mp.id DESC') as $row) {
    review_add_photo($references, $faceMatchReferences, (int) $row['player_id'], 'matches/gallery/' . (int) $row['match_fixture_id'] . '/' . rawurlencode(basename($row['filename'])), 'Tagged match photo');
}
$stmt = $pdo->prepare('SELECT COUNT(*) FROM player_photo_review_queue WHERE owner_key = ?');
$stmt->execute([$owner]);
$queueCount = (int) $stmt->fetchColumn();
$pageCount = max(1, (int) ceil($queueCount / 100));
$queuePage = max(1, min($pageCount, (int) ($_GET['page'] ?? 1)));
$offset = ($queuePage - 1) * 100;
$stmt = $pdo->prepare('SELECT id, filename, original_name FROM player_photo_review_queue WHERE owner_key = ? ORDER BY id LIMIT 100 OFFSET ' . $offset);
$stmt->execute([$owner]);
$queue = $stmt->fetchAll();

// Compare each queued photo against every known photo of every player — profile
// picture, action shots, AND tagged match photos — so the review table can
// pre-suggest a likely name even for players only ever identified via tags.
$suggestions = [];
if ($queue) {
    $queryPaths = [];
    foreach ($queue as $photo) {
        $queryPaths[(int) $photo['id']] = $directory . '/' . basename((string) $photo['filename']);
    }
    $suggestions = face_match_get_suggestions($faceMatchReferences, $queryPaths);
}
$playerNamesById = array_column($players, 'name', 'id');

$result = $_SESSION['photo_review_result'] ?? null;
unset($_SESSION['photo_review_result']);
$pageHero = ['eyebrow'=>'People', 'title'=>'Upload reference', 'subtitle'=>'Compare uploaded images with named player photos, then save your selections.', 'actions'=>[['label'=>'Back to people','href'=>'/admin/people.php']]];
require_once __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="/admin/assets/css/player_photo_review.css">
<?php if ($result): ?>
<div class="alert alert-success" role="status"><?= (int) $result['saved'] ?> photo(s) assigned. Unknown photos remain available for review later.</div>
<?php foreach ($result['errors'] as $error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endforeach; ?>
<?php endif; ?>
<section class="card mb-4"><div class="card-body">
<form id="reference-upload" enctype="multipart/form-data" method="post">
<?= csrf_field() ?>
<label id="reference-drop" class="reference-drop" for="reference-files">
<strong>Drag and drop photos here</strong><span>or choose images — JPG, PNG, GIF or WebP, up to 5MB each</span>
<input id="reference-files" type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple>
</label>
<button class="btn btn-primary mt-3" id="reference-upload-button" type="submit">Upload images</button>
<p id="reference-upload-status" class="mt-2" role="status" aria-live="polite"></p>
<ul id="reference-upload-errors" class="text-danger"></ul>
<a id="reference-review-link" href="/admin/player_photo_review.php" hidden>Review uploaded images</a>
<noscript><p>Enable JavaScript to upload images and browse comparison photos.</p></noscript>
</form>
</div></section>
<div class="reference-workspace">
<section class="card"><div class="card-body">
<h2 class="h4">Your review queue (<span id="reference-queue-count"><?= $queueCount ?></span>)</h2>
<p>Select an image to compare it with the named photos alongside it. Choose a player only when you are sure. Unknown images are kept here for later.</p>
<?php if ($pageCount > 1): ?>
<nav aria-label="Review queue pages" class="mb-3">
<?php if ($queuePage > 1): ?><a class="btn btn-outline-secondary" href="?page=<?= $queuePage - 1 ?>">Previous images</a><?php endif; ?>
<span>Page <?= $queuePage ?> of <?= $pageCount ?></span>
<?php if ($queuePage < $pageCount): ?><a class="btn btn-outline-secondary" href="?page=<?= $queuePage + 1 ?>">Next images</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php if (!$queue): ?><p>Your queue is empty. Upload images above to get started.</p><?php else: ?>
<form id="reference-save" method="post">
<?= csrf_field() ?><input type="hidden" name="action" value="save">
<div class="table-responsive">
<table class="table reference-queue-table mb-0 hub-data-table">
<caption>Uploaded images compared against named player photos — pick the right name for each, or leave it as-is if there's no match.</caption>
<thead><tr><th scope="col">Image</th><th scope="col">Filename</th><th scope="col">Likeness</th><th scope="col">Player</th><th scope="col"><span class="visually-hidden">Remove</span></th></tr></thead>
<tbody>
<?php foreach ($queue as $photo): ?>
<?php
$queueId = (int) $photo['id'];
$suggestion = $suggestions[$queueId] ?? null;
$score = $suggestion['score'] ?? null;
$isLikely = $score !== null && $score >= FACE_MATCH_LIKELY_THRESHOLD;
$isPossible = $score !== null && !$isLikely && $score >= FACE_MATCH_POSSIBLE_THRESHOLD;
$suggestedName = $suggestion && isset($playerNamesById[$suggestion['player_id']]) ? $playerNamesById[$suggestion['player_id']] : null;
$preselectId = ($isLikely && $suggestedName !== null) ? (int) $suggestion['player_id'] : 0;
?>
<tr class="reference-item" data-queue-id="<?= $queueId ?>">
<td>
<button type="button" class="reference-inspect" aria-label="Compare <?= h($photo['original_name']) ?>"><img src="/uploads/players/action_shots/<?= rawurlencode(basename($photo['filename'])) ?>" alt="<?= h($photo['original_name']) ?>" loading="lazy"></button>
</td>
<td class="reference-filename"><?= h($photo['original_name']) ?></td>
<td>
<?php if ($suggestedName !== null && ($isLikely || $isPossible)): ?>
<div class="reference-likeness reference-likeness--<?= $isLikely ? 'likely' : 'possible' ?>">
<i class="fa-solid <?= $isLikely ? 'fa-wand-magic-sparkles' : 'fa-circle-question' ?>" aria-hidden="true"></i>
<?= h($suggestedName) ?> (<?= (int) round($score * 100) ?>%)
</div>
<?php else: ?>
<span class="reference-likeness reference-likeness--none">No match found</span>
<?php endif; ?>
</td>
<td>
<label class="visually-hidden" for="reference-player-<?= $queueId ?>">Player for <?= h($photo['original_name']) ?></label>
<select class="form-select reference-player" id="reference-player-<?= $queueId ?>" name="player[<?= $queueId ?>]">
<option value="0">Unknown / review later</option>
<?php foreach ($players as $player): ?><option value="<?= (int) $player['id'] ?>" <?= $preselectId === (int) $player['id'] ? 'selected' : '' ?>><?= h($player['name']) ?></option><?php endforeach; ?>
</select>
</td>
<td>
<button type="button" class="btn btn-outline-danger btn-sm reference-delete" data-queue-id="<?= $queueId ?>" aria-label="Delete <?= h($photo['original_name']) ?> from queue"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="mt-3">Saving sets the chosen photo as that player's profile/reference picture, replacing their existing one. Suggestions are a starting point — check each one before saving.</p>
<button class="btn btn-primary" type="submit">Save selections</button>
</form>
<?php endif; ?>
</div></section>
<aside class="card reference-comparison"><div class="card-body">
<h2 class="h4">Compare with known players</h2>
<img id="reference-focus" alt="Selected upload" hidden>
<label for="reference-search">Search player names</label>
<input class="form-control mb-2" id="reference-search" type="search" placeholder="Type a player's name">
<label for="reference-known">View reference photos for</label>
<select class="form-select mb-3" id="reference-known"><option value="">Choose a player</option>
<?php foreach ($players as $player): ?><option value="<?= (int) $player['id'] ?>"><?= h($player['name']) ?></option><?php endforeach; ?>
</select>
<p id="reference-known-status" role="status">Choose a player to see their named photos.</p>
<div id="reference-known-photos" class="reference-known-photos"></div>
<button id="reference-assign" type="button" class="btn btn-outline-primary mt-3" disabled>Use this player for selected image</button>
</div></aside>
</div>
<script id="reference-data" type="application/json"><?= json_encode($references, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="/admin/assets/js/player_photo_review.js" defer></script>
<?php require_once __DIR__ . '/footer.php'; ?>
