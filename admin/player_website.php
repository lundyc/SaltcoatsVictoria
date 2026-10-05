<?php

declare(strict_types=1);

/**
 * Player — website profile fields (public site). Squad number, nationality,
 * short bio, URL slug, and the "show on website" toggle. Kept separate from the
 * big player_edit.php so the public-site fields have one obvious home.
 */

$id = (int) ($_GET['id'] ?? 0);

$pageHero = [
    'eyebrow' => 'Public website',
    'title' => 'Player website profile',
    'subtitle' => 'Controls how this player appears in the Team section of the club website.',
    'actions' => [
        ['label' => '← All players', 'href' => 'players.php', 'class' => 'btn btn-outline-secondary btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/squad_public.php';
require_once __DIR__ . '/lib/audit.php';

squad_public_ensure_schema($pdo);

$stmt = $pdo->prepare('SELECT * FROM players WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$player) {
    echo '<div class="alert alert-danger">Player not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$pdo->exec('CREATE TABLE IF NOT EXISTS player_website_photos (
  player_id INT UNSIGNED NOT NULL,
  uploaded_to_website TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$onWebsiteStmt = $pdo->prepare('SELECT uploaded_to_website FROM player_website_photos WHERE player_id = :id');
$onWebsiteStmt->execute([':id' => $id]);
$onWebsiteRaw = $onWebsiteStmt->fetchColumn();
// No row = shown by default (matches the public site: every current player
// appears unless explicitly toggled off here).
$onWebsite = $onWebsiteRaw === false ? 1 : (int) $onWebsiteRaw;

$errors = [];
$saved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $squadNumber = trim((string) ($_POST['squad_number'] ?? ''));
        $squadNumber = $squadNumber === '' ? null : max(1, min(999, (int) $squadNumber));
        $nationality = trim((string) ($_POST['nationality'] ?? ''));
        $bio = trim((string) ($_POST['bio'] ?? ''));
        $slugInput = trim((string) ($_POST['website_slug'] ?? ''));
        $slug = $slugInput !== ''
            ? squad_public_slug($slugInput, $id)
            : squad_public_slug((string) $player['name'], $id);
        $onWebsite = isset($_POST['on_website']) ? 1 : 0;

        // Slug uniqueness (nullable unique column)
        $dupe = $pdo->prepare('SELECT id FROM players WHERE website_slug = :s AND id <> :id LIMIT 1');
        $dupe->execute([':s' => $slug, ':id' => $id]);
        if ($dupe->fetchColumn()) {
            $slug .= '-' . $id;
        }

        $pdo->prepare(
            'UPDATE players SET squad_number = :num, nationality = :nat, bio = :bio, website_slug = :slug WHERE id = :id'
        )->execute([
            ':num' => $squadNumber,
            ':nat' => $nationality,
            ':bio' => $bio,
            ':slug' => $slug,
            ':id' => $id,
        ]);

        $pdo->prepare(
            'INSERT INTO player_website_photos (player_id, uploaded_to_website) VALUES (:id, :w)
             ON DUPLICATE KEY UPDATE uploaded_to_website = VALUES(uploaded_to_website)'
        )->execute([':id' => $id, ':w' => $onWebsite]);

        auditLog($pdo, 'player_website_profile_updated', "Updated website profile for player #{$id} ({$player['name']})");

        $stmt->execute([':id' => $id]);
        $player = $stmt->fetch(PDO::FETCH_ASSOC);
        $saved = true;
    }
}

$slug = (string) ($player['website_slug'] ?? '') ?: squad_public_slug((string) $player['name'], $id);
?>

<?php if ($saved): ?><div class="alert alert-success">Website profile saved.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb">
  <a href="players.php">Players</a>
  <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
  <a href="player_edit.php?id=<?= (int) $id ?>"><?= h((string) $player['name']) ?></a>
  <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
  <span aria-current="page">Website profile</span>
</nav>

<form method="post" class="card hub-section" style="max-width:680px">
  <div class="card-body">
    <?= csrf_field() ?>

    <div class="form-check form-switch mb-4">
      <input class="form-check-input" type="checkbox" role="switch" id="on_website" name="on_website" value="1" <?= $onWebsite === 1 ? 'checked' : '' ?>>
      <label class="form-check-label" for="on_website"><strong>Show this player on the club website</strong></label>
      <div class="form-text">Only current players with this switch on appear in the Team section.</div>
    </div>

    <div class="row g-3">
      <div class="col-sm-4">
        <label class="form-label" for="squad_number">Squad number</label>
        <input class="form-control" type="number" min="1" max="999" id="squad_number" name="squad_number" value="<?= h((string) ($player['squad_number'] ?? '')) ?>">
      </div>
      <div class="col-sm-8">
        <label class="form-label" for="nationality">Nationality</label>
        <input class="form-control" id="nationality" name="nationality" value="<?= h((string) ($player['nationality'] ?? '')) ?>" placeholder="e.g. Scotland">
      </div>
    </div>

    <div class="mt-3">
      <label class="form-label" for="website_slug">URL slug</label>
      <div class="input-group">
        <span class="input-group-text">/team/</span>
        <input class="form-control" id="website_slug" name="website_slug" value="<?= h($slug) ?>">
      </div>
      <div class="form-text">Used in the player's profile link. Leave to auto-generate from the name.</div>
    </div>

    <div class="mt-3">
      <label class="form-label" for="bio">Short bio</label>
      <textarea class="form-control" id="bio" name="bio" rows="5" placeholder="A paragraph or two about the player."><?= h((string) ($player['bio'] ?? '')) ?></textarea>
    </div>

    <p class="text-muted small mt-3 mb-0">
      Player name, position, date of birth and photo come from the
      <a href="player_edit.php?id=<?= (int) $id ?>">main player record</a>.
    </p>
  </div>
  <div class="card-footer d-flex justify-content-between">
    <?php if ($onWebsite === 1 && ($player['status'] ?? '') === 'current'): ?>
      <a class="btn btn-outline-secondary" href="/team/<?= h($slug) ?>" target="_blank" rel="noopener">View on website</a>
    <?php else: ?><span></span><?php endif; ?>
    <button class="btn btn-brand" type="submit">Save profile</button>
  </div>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>
