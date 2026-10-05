<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/player_birthdays.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/players_lib.php';

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    $pageHero = [
        'eyebrow' => 'Player management',
        'title' => 'Edit Player',
        'subtitle' => 'No player was selected.',
        'actions' => [],
    ];
    require_once __DIR__ . '/header.php';
    echo '<div><div class="alert alert-danger">Invalid player ID.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

players_ensure_date_of_birth_column($pdo);

$positionColumn = $pdo->query("SHOW COLUMNS FROM players LIKE 'position'")->fetch(PDO::FETCH_ASSOC);
if (!$positionColumn) {
    $pdo->exec("ALTER TABLE players ADD COLUMN position VARCHAR(80) NULL AFTER name");
}

$stmt = $pdo->prepare('SELECT * FROM players WHERE id = :id');
$stmt->execute([':id' => $id]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$player) {
    $pageHero = [
        'eyebrow' => 'Player management',
        'title' => 'Edit Player',
        'subtitle' => 'The requested player could not be found.',
        'actions' => [],
    ];
    require_once __DIR__ . '/header.php';
    echo '<div><div class="alert alert-danger">Player not found.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$seasonId = getSelectedSeasonId($pdo);
$season = getSeasonById($pdo, $seasonId);
$sponsorshipDeadlinePassed = !empty($season['sponsorship_deadline']) && strtotime((string) $season['sponsorship_deadline']) <= time();
$canEditSponsorships = hub_auth_is_sponsorship_editor() && !$sponsorshipDeadlinePassed;
$allowedSlots = getAllowedSponsorshipSlots($pdo, $seasonId);
$playerEditSlots = array_values(array_intersect($allowedSlots, ['home', 'away']));
$sponsors = getActiveSponsorsForSeason($pdo, $seasonId);
$uniqueSponsorsStmt = $pdo->prepare("
    SELECT DISTINCT sp.id, sp.name
    FROM sponsorships s
    JOIN sponsors sp ON sp.id = s.sponsor_id
    WHERE s.player_id = :pid
      AND s.season_id = :season_id
      AND s.ended_at IS NULL
      AND LOWER(s.slot) IN ('home', 'away')
    ORDER BY sp.name
");
$uniqueSponsorsStmt->execute([':pid' => $id, ':season_id' => $seasonId]);
$uniqueSponsors = $uniqueSponsorsStmt->fetchAll(PDO::FETCH_ASSOC);
$activePlayers = $pdo->query('SELECT id, name FROM players WHERE active = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);

$slotStatusStmt = $pdo->prepare("SELECT slot FROM sponsorships WHERE player_id = :pid AND season_id = :season_id AND ended_at IS NULL AND slot IN ('home','away')");
$slotStatusStmt->execute([':pid' => $id, ':season_id' => $seasonId]);
$activeSlots = array_map('strtolower', $slotStatusStmt->fetchAll(PDO::FETCH_COLUMN));
$hasHomeSponsor = in_array('home', $activeSlots, true);
$hasAwaySponsor = in_array('away', $activeSlots, true);

$homeReplacementStmt = $pdo->prepare("SELECT p.id, p.name FROM players p WHERE p.active = 1 AND p.id <> :pid AND NOT EXISTS (SELECT 1 FROM sponsorships s WHERE s.player_id = p.id AND s.season_id = :season_id AND s.slot = 'home' AND s.ended_at IS NULL) ORDER BY p.name");
$homeReplacementStmt->execute([':pid' => $id, ':season_id' => $seasonId]);
$homeReplacementPlayers = $homeReplacementStmt->fetchAll(PDO::FETCH_ASSOC);

$awayReplacementStmt = $pdo->prepare("SELECT p.id, p.name FROM players p WHERE p.active = 1 AND p.id <> :pid AND NOT EXISTS (SELECT 1 FROM sponsorships s WHERE s.player_id = p.id AND s.season_id = :season_id AND s.slot = 'away' AND s.ended_at IS NULL) ORDER BY p.name");
$awayReplacementStmt->execute([':pid' => $id, ':season_id' => $seasonId]);
$awayReplacementPlayers = $awayReplacementStmt->fetchAll(PDO::FETCH_ASSOC);

$playerName = trim((string)($player['name'] ?? 'Player'));
$avatarFilename = basename((string)($player['avatar'] ?? ''));
$avatarAvailable = $avatarFilename !== ''
    && is_file(__DIR__ . '/uploads/players/' . $avatarFilename);

$actionShotsStmt = $pdo->prepare('SELECT id, filename FROM player_action_shots WHERE player_id = :pid ORDER BY sort_order ASC, id ASC');
$actionShotsStmt->execute([':pid' => $id]);
$actionShots = array_values(array_filter(
    $actionShotsStmt->fetchAll(PDO::FETCH_ASSOC),
    static fn(array $shot): bool => is_file(__DIR__ . '/uploads/players/action_shots/' . basename((string)$shot['filename']))
));

$nameParts = preg_split('/\s+/', $playerName) ?: [];
$playerInitials = '';
foreach (array_slice($nameParts, 0, 2) as $namePart) {
    $playerInitials .= mb_strtoupper(mb_substr($namePart, 0, 1, 'UTF-8'), 'UTF-8');
}
$playerInitials = $playerInitials !== '' ? $playerInitials : 'P';

$pageHero = [
    'eyebrow' => 'Squad profile',
    'title' => 'Edit ' . $playerName,
    'subtitle' => (string)($season['name'] ?? 'Current season') . ' player details and sponsorship management.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
$playerEditJsVersion = (string) filemtime(__DIR__ . '/assets/js/player_edit.js');
$playerStatusCardsJsVersion = (string) filemtime(__DIR__ . '/assets/js/player_status_cards.js');
$playerEditCssVersion = (string) filemtime(__DIR__ . '/assets/css/player_edit.css');
echo '<script src="/admin/assets/js/player_edit.js?v=' . h($playerEditJsVersion) . '" defer></script>';
echo '<script src="/admin/assets/js/player_status_cards.js?v=' . h($playerStatusCardsJsVersion) . '" defer></script>';
?>

<link rel="stylesheet" href="/admin/assets/css/player_edit.css?v=<?= h($playerEditCssVersion) ?>">

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/players.php">Players</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><a href="/admin/player_view.php?id=<?= $id ?>"><?= h($playerName) ?></a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Edit</span></nav>
<div class="player-edit-page">
    <?php if (isset($_GET['created'])): ?>
        <div class="alert alert-success">Player added. Upload a profile picture and action shots below.</div>
    <?php endif; ?>
    <?php if (isset($_GET['avatar_success'])): ?>
        <div class="alert alert-success">Avatar updated successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['avatar_error'])): ?>
        <div class="alert alert-danger">Avatar upload failed: <?= htmlspecialchars((string) $_GET['avatar_error']) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['action_shot_success'])): ?>
        <div class="alert alert-success"><?= (int) $_GET['action_shot_success'] ?> action shot<?= (int) $_GET['action_shot_success'] === 1 ? '' : 's' ?> uploaded.</div>
    <?php endif; ?>
    <?php if (isset($_GET['action_shot_error'])): ?>
        <div class="alert alert-danger">Action shot upload failed: <?= htmlspecialchars((string) $_GET['action_shot_error']) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['action_shot_deleted'])): ?>
        <div class="alert alert-success">Action shot removed.</div>
    <?php endif; ?>

    <div class="player-edit-switcher hub-toolbar">
        <div>
            <div class="player-edit-kicker">Quick navigation</div>
            <div class="player-edit-title">Switch player</div>
        </div>
            <form id="playerSelectForm">
                <label for="playerSelect" class="visually-hidden">Select player</label>
                <select id="playerSelect" class="form-select">
                    <?php foreach ($activePlayers as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === (int) $player['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
    </div>

    <div class="card player-edit-primary hub-form-card">
        <div class="player-edit-primary-grid">
          <section class="player-edit-avatar-pane">
            <div class="player-edit-kicker">Profile image</div>
            <h2 class="player-edit-title">Player photo</h2>
            <form id="avatarUploadForm" method="post" enctype="multipart/form-data" action="/admin/player_avatar_upload.php">
                <?= csrf_field() ?>
                <input type="hidden" name="player_id" value="<?= (int) $player['id'] ?>">
                <div class="player-edit-avatar-preview">
                    <?php if ($avatarAvailable): ?>
                        <img src="/uploads/players/<?= rawurlencode($avatarFilename) ?>" alt="<?= htmlspecialchars($playerName, ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                        <span><?= htmlspecialchars($playerInitials, ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>
                <label class="player-edit-avatar-uploader" id="avatarDropzone" tabindex="0">
                    <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
                    <span class="avatar-dropzone-title">Drop a photo here</span>
                    <span class="avatar-dropzone-subtitle">or click to choose a JPG, PNG, GIF or WebP</span>
                    <input type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp" class="avatar-dropzone-input">
                </label>
                <div class="player-edit-avatar-actions hub-actions">
                    <?php if ($avatarAvailable): ?>
                        <button type="submit" name="remove_avatar" value="1" class="btn btn-outline-danger" data-confirm="The current player photo will be removed. You can upload another photo later." data-confirm-title="Remove this player photo?" data-confirm-action="Remove photo"><i class="fa-solid fa-trash" aria-hidden="true"></i><span class="ms-1">Remove photo</span></button>
                    <?php endif; ?>
                </div>
                <p class="player-edit-help mt-2 mb-0">Use a square JPG, PNG, GIF or WebP image for the best result.</p>
            </form>
          </section>
          <section class="player-edit-details-pane">
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div>
                    <div class="player-edit-kicker">Player record</div>
                    <h2 class="player-edit-title">Profile details</h2>
                </div>
            </div>
            <div class="player-edit-details-grid">
            <div class="player-edit-field--wide">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" id="playerName" value="<?= htmlspecialchars((string) $player['name']) ?>" data-id="<?= (int) $player['id'] ?>" data-field="name">
            </div>
            <div>
                <label class="form-label" for="playerDateOfBirth">Date of birth</label>
                <input type="date" class="form-control" id="playerDateOfBirth" value="<?= h((string) ($player['date_of_birth'] ?? '')) ?>" data-id="<?= (int) $player['id'] ?>" data-field="date_of_birth">
            </div>
            <div>
                <label class="form-label" for="playerPosition">Position</label>
                <?php $currentPosition = players_normalize_position((string) ($player['position'] ?? '')); ?>
                <select class="form-select" id="playerPosition" data-id="<?= (int) $player['id'] ?>" data-field="position">
                    <option value="">Position not set</option>
                    <?php foreach (players_position_options() as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $currentPosition === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="player-edit-field--wide">
                <label class="form-label">Status</label>
                <div class="player-status-cards">
                    <?php foreach (['trialist' => 'Trialist', 'current' => 'Current', 'left' => 'Left', 'retired' => 'Retired', 'loan' => 'On Loan', 'injured' => 'Injured'] as $val => $label): ?>
                        <button type="button" class="player-status-card <?= (string) $player['status'] === $val ? 'active' : '' ?>" data-status="<?= htmlspecialchars($val) ?>">
                            <span class="player-status-card-label"><?= htmlspecialchars($label) ?></span>
                            <span class="player-status-card-meta"><?php if ($val === 'trialist'): ?>Trial start<?php elseif ($val === 'current'): ?>Signed in<?php elseif ($val === 'left'): ?>Left date<?php elseif ($val === 'retired'): ?>Retired on<?php elseif ($val === 'loan'): ?>On loan<?php else: ?>Injured<?php endif; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="player-status-summary mt-3">
                    <div class="player-status-summary-row"><span>Status</span><strong id="playerStatusSummaryState"><?= htmlspecialchars(ucfirst((string) ($player['status'] ?? ''))) ?></strong></div>
                    <div class="player-status-summary-row"><span>Joined</span><strong id="playerStatusSummaryJoined"><?= !empty($player['joined_at']) ? date('d M Y', strtotime((string) $player['joined_at'])) : 'Not recorded' ?></strong></div>
                    <div class="player-status-summary-row"><span>Left</span><strong id="playerStatusSummaryLeft"><?= !empty($player['left_at']) ? date('d M Y', strtotime((string) $player['left_at'])) : '—' ?></strong></div>
                    <div class="player-status-summary-row"><span>Active squad</span><strong id="playerStatusSummaryActive"><?= !empty($player['active']) ? 'Yes' : 'No' ?></strong></div>
                </div>
                <div id="playerStatusData"
                     data-player-id="<?= (int) $player['id'] ?>"
                     data-status="<?= htmlspecialchars((string) ($player['status'] ?? '')) ?>"
                     data-joined="<?= htmlspecialchars((string) ($player['joined_at'] ?? '')) ?>"
                     data-left="<?= htmlspecialchars((string) ($player['left_at'] ?? '')) ?>"
                     data-active="<?= !empty($player['active']) ? '1' : '0' ?>"
                     class="d-none"></div>
                <div class="player-edit-danger-zone">
                    <div>
                        <div class="player-edit-kicker">Danger zone</div>
                        <p class="player-edit-help mb-0">Delete this player record only when it should be removed from the system.</p>
                    </div>
                    <a class="btn btn-outline-danger btn-sm" href="players_delete.php?id=<?= (int) $player['id'] ?>" data-confirm="Delete this player? This will also remove assignment history." data-confirm-action="Delete"><i class="fa-solid fa-trash" aria-hidden="true"></i><span class="ms-1">Delete player</span></a>
                </div>
            </div>
            </div>
          </section>
        </div>
    </div>

    <div class="card player-edit-actionshots hub-section">
        <div class="card-body">
            <div class="player-edit-kicker">Graphics library</div>
            <h2 class="player-edit-title mb-1">Action shots</h2>
            <p class="player-edit-help mb-3">Extra photos used when generating Player of the Match, Goal, Player Sponsor and other graphics.</p>
            <div class="player-action-shots-grid">
                <?php foreach ($actionShots as $shot): ?>
                    <div class="player-action-shot">
                        <img src="/uploads/players/action_shots/<?= rawurlencode((string) $shot['filename']) ?>" alt="">
                        <form method="post" action="/admin/player_action_shot_delete.php" class="player-action-shot__remove">
                            <?= csrf_field() ?>
                            <input type="hidden" name="shot_id" value="<?= (int) $shot['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove photo" aria-label="Remove photo" data-confirm="This action shot will be removed." data-confirm-title="Remove this action shot?" data-confirm-action="Remove photo"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                        </form>
                    </div>
                <?php endforeach; ?>
                <form method="post" enctype="multipart/form-data" action="/admin/player_action_shot_upload.php" id="actionShotUploadForm" class="player-action-shot player-action-shot--add">
                    <?= csrf_field() ?>
                    <input type="hidden" name="player_id" value="<?= (int) $player['id'] ?>">
                    <label class="player-action-shot-uploader" id="actionShotDropzone" tabindex="0">
                        <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
                        <span class="avatar-dropzone-title">Add photos</span>
                        <span class="avatar-dropzone-subtitle">JPG, PNG, GIF or WebP</span>
                        <input type="file" name="action_shots[]" accept="image/png,image/jpeg,image/gif,image/webp" multiple class="avatar-dropzone-input">
                    </label>
                </form>
            </div>
            <?php if (!$actionShots): ?>
                <p class="player-edit-help mt-2 mb-0">No action shots uploaded yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card player-edit-sponsorships hub-section">
        <div class="card-body">
            <div class="player-edit-kicker">Commercial</div>
            <h2 class="player-edit-title mb-3">Sponsorships</h2>
            <?php if ($sponsorshipDeadlinePassed): ?>
                <div class="alert alert-warning">This season's sponsorship deadline has passed — player sponsorships are locked for everyone and can't be changed.</div>
            <?php elseif (!$canEditSponsorships): ?>
                <div class="alert alert-info">Player sponsorships can only be changed by the club owner. The controls below are read-only.</div>
            <?php endif; ?>
            <?php if ($uniqueSponsors): ?>
                <?php foreach ($uniqueSponsors as $sponsor): ?>
                    <div class="card player-edit-sponsor-card hub-table-card">
                        <div class="card-header fw-bold"><?= htmlspecialchars((string) $sponsor['name']) ?></div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-sm table-striped hub-data-table hub-data-table--responsive align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Slot</th>
                                            <th>Amount</th>
                                            <th>Paid</th>
                                            <th>Notes</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $stmt2 = $pdo->prepare("
                                            SELECT s.id, s.slot, s.amount, s.notes, s.complimentary,
                                                   COALESCE(SUM(p.amount),0) AS paid
                                            FROM sponsorships s
                                            LEFT JOIN sponsorship_payments p ON p.sponsorship_id = s.id
                                            WHERE s.player_id = :pid AND s.sponsor_id = :sid
                                              AND s.season_id = :season_id
                                              AND s.ended_at IS NULL
                                              AND LOWER(s.slot) IN ('home', 'away')
                                            GROUP BY s.id, s.slot, s.amount, s.notes, s.complimentary
                                            ORDER BY FIELD(UPPER(s.slot),'HOME','AWAY')
                                        ");
                                        $stmt2->execute([':pid' => $id, ':sid' => $sponsor['id'], ':season_id' => $seasonId]);
                                        $slots = $stmt2->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($slots as $slot):
                                            $isComplimentary = (bool) $slot['complimentary'];
                                        ?>
                                            <tr data-id="<?= (int) $slot['id'] ?>">
                                                <td data-label="Slot"><?= htmlspecialchars(strtoupper((string) $slot['slot'])) ?></td>
                                                <td data-label="Amount">
                                                    <?php if ($isComplimentary): ?>
                                                        <span class="badge text-bg-info">Complimentary</span>
                                                    <?php else: ?>
                                                        £<?= number_format((float) $slot['amount'], 2) ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Paid"><?= $isComplimentary ? '—' : '£' . number_format((float) $slot['paid'], 2) ?></td>
                                                <td data-label="Notes" class="player-edit-note-cell">
                                                    <input type="text" class="form-control form-control-sm inline-note" value="<?= htmlspecialchars((string) ($slot['notes'] ?? '')) ?>" data-id="<?= (int) $slot['id'] ?>" data-field="notes" <?= $canEditSponsorships ? '' : 'disabled' ?>>
                                                </td>
                                                <td data-label="Actions" class="player-edit-actions-cell">
                                                  <div class="player-edit-sponsor-actions hub-actions">
                                                    <?php if (!$isComplimentary && (float) $slot['paid'] < (float) $slot['amount']): ?>
                                                        <button class="btn btn-sm btn-outline-success mark-paid" data-id="<?= (int) $slot['id'] ?>" data-remaining="<?= (float) $slot['amount'] - (float) $slot['paid'] ?>">Mark paid</button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-sm btn-outline-danger delete-sponsorship" data-id="<?= (int) $slot['id'] ?>" title="Delete sponsorship" aria-label="Delete sponsorship" <?= $canEditSponsorships ? '' : 'disabled' ?>><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                                                  </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="hub-empty-state"><p class="text-muted mb-0">No sponsorships assigned yet.</p></div>
            <?php endif; ?>

            <div class="player-edit-add">
            <div class="player-edit-kicker">New assignment</div>
            <h3 class="player-edit-title">Add sponsorship</h3>
            <form id="addSponsorshipForm" class="player-edit-add-form hub-form-section">
                <input type="hidden" name="player_id" value="<?= (int) $id ?>">
                <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
                <div class="player-edit-add-row1">
                    <label class="player-edit-add-field">
                        <span class="form-label">Sponsor</span>
                        <select name="sponsor_id" class="form-select" required <?= $canEditSponsorships ? '' : 'disabled' ?>>
                            <option value="">Select sponsor</option>
                            <?php foreach ($sponsors as $sp): ?>
                                <option value="<?= (int) $sp['id'] ?>"><?= htmlspecialchars((string) $sp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="player-edit-add-field">
                        <span class="form-label">Kit slot</span>
                        <select name="slot" class="form-select" required <?= $canEditSponsorships ? '' : 'disabled' ?>>
                            <?php
                            $freeSlots = array_diff($playerEditSlots, $activeSlots);
                            ?>
                            <?php if ($freeSlots): ?>
                                <?php foreach ($freeSlots as $slot): ?>
                                    <option value="<?= h($slot) ?>"><?= h(ucfirst($slot)) ?></option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="">Player already has a sponsorship</option>
                            <?php endif; ?>
                        </select>
                    </label>
                    <div class="player-edit-add-field player-edit-complimentary-field">
                        <span class="form-label">Complimentary</span>
                        <div class="form-check form-switch player-edit-complimentary-switch">
                            <input type="checkbox" name="complimentary" value="1" class="form-check-input" id="addSponsorshipComplimentary" role="switch" <?= $canEditSponsorships ? '' : 'disabled' ?>>
                            <label class="form-check-label" for="addSponsorshipComplimentary">Free sponsorship</label>
                        </div>
                    </div>
                    <label class="player-edit-add-field player-edit-amount-field">
                        <span class="form-label">Amount</span>
                        <input type="number" step="0.01" min="0" name="amount" class="form-control" placeholder="£0.00" <?= $canEditSponsorships ? '' : 'disabled' ?>>
                    </label>
                    <div class="player-edit-add-button d-grid">
                        <button type="submit" class="btn btn-brand" <?= ($freeSlots && $canEditSponsorships) ? '' : 'disabled' ?>><i class="fa-solid fa-plus" aria-hidden="true"></i><span class="ms-1">Add</span></button>
                    </div>
                </div>
                <div class="player-edit-add-row2">
                    <label class="player-edit-add-field player-edit-notes-field">
                        <span class="form-label">Notes</span>
                        <input type="text" name="notes" class="form-control" placeholder="Optional notes" <?= $canEditSponsorships ? '' : 'disabled' ?>>
                    </label>
                </div>
            </form>
            </div>
            <div class="player-edit-add mt-4">
                <div class="player-edit-kicker">Squad changes</div>
                <h3 class="player-edit-title">Transfer sponsorships</h3>
                <p class="player-edit-help">Move every active sponsorship for this player to a replacement player in the same season.</p>
                <form id="transferSponsorshipForm" class="player-edit-transfer-form hub-form-section">
                    <input type="hidden" name="action" value="transfer_player_sponsorships">
                    <input type="hidden" name="player_id" value="<?= (int) $id ?>">
                    <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
                    <label class="player-edit-transfer-select">
                        <span class="form-label">Replacement player</span>
                        <select name="replacement_player_id" class="form-select" required <?= $canEditSponsorships ? '' : 'disabled' ?>>
                            <option value="">Select replacement player</option>
                            <?php foreach ($activePlayers as $replacementPlayer): ?>
                                <?php if ((int) $replacementPlayer['id'] === $id) continue; ?>
                                <option value="<?= (int) $replacementPlayer['id'] ?>"><?= h((string) $replacementPlayer['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="player-edit-transfer-button-wrap">
                        <button type="submit" class="btn btn-warning player-edit-transfer-button" title="Transfer sponsorships" aria-label="Transfer sponsorships" <?= $canEditSponsorships ? '' : 'disabled' ?>><i class="fa-solid fa-right-left" aria-hidden="true"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="playerStatusModal" tabindex="-1" aria-labelledby="playerStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <p class="text-uppercase small mb-1">Status settings</p>
                    <h2 class="h5 modal-title" id="playerStatusModalLabel"><span id="playerStatusModalSelected">Status</span></h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3" id="playerStatusModalHelp">Choose the status and update the matching dates.</p>
                <input type="hidden" id="playerStatusModalStatus" value="">
                <div class="mb-3" id="playerStatusModalJoinedGroup">
                    <label class="form-label">Joined at</label>
                    <input type="date" class="form-control" id="playerStatusModalJoinedAt">
                </div>
                <div class="mb-3" id="playerStatusModalLeftGroup">
                    <label class="form-label">Left at</label>
                    <input type="date" class="form-control" id="playerStatusModalLeftAt">
                </div>
                <div class="form-check form-switch mb-3" id="playerStatusModalActiveGroup">
                    <input class="form-check-input" type="checkbox" id="playerStatusModalActive">
                    <label class="form-check-label" for="playerStatusModalActive">Active squad member</label>
                </div>
                <div class="mb-3 d-none" id="playerStatusModalSponsorActionGroup">
                    <label class="form-label">Sponsorship handling</label>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="playerStatusModalSponsorshipAction" id="playerStatusModalSponsorActionKeep" value="keep" checked>
                        <label class="form-check-label" for="playerStatusModalSponsorActionKeep">Keep sponsorships on this player</label>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="radio" name="playerStatusModalSponsorshipAction" id="playerStatusModalSponsorActionTransfer" value="transfer">
                        <label class="form-check-label" for="playerStatusModalSponsorActionTransfer">Transfer sponsorships to another player</label>
                    </div>
                    <div class="mt-2 ps-3 d-none" id="playerStatusModalReplacementHomeGroup">
                        <label class="form-label" for="playerStatusModalReplacementHomePlayer">Home sponsor replacement</label>
                        <select class="form-select" id="playerStatusModalReplacementHomePlayer" <?= $hasHomeSponsor && empty($homeReplacementPlayers) ? 'disabled' : '' ?>>
                            <option value=""><?= $hasHomeSponsor ? 'Select replacement player' : 'No Home sponsorship to transfer' ?></option>
                            <?php foreach ($homeReplacementPlayers as $replacementPlayer): ?>
                                <option value="<?= (int) $replacementPlayer['id'] ?>"><?= h((string) $replacementPlayer['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?= $hasHomeSponsor ? (empty($homeReplacementPlayers) ? 'No eligible players without an active Home sponsor this season.' : 'Transfer the Home sponsorship to a player without an active Home sponsor.') : 'This player has no Home sponsorship to transfer.' ?></div>
                    </div>
                    <div class="mt-2 ps-3 d-none" id="playerStatusModalReplacementAwayGroup">
                        <label class="form-label" for="playerStatusModalReplacementAwayPlayer">Away sponsor replacement</label>
                        <select class="form-select" id="playerStatusModalReplacementAwayPlayer" <?= $hasAwaySponsor && empty($awayReplacementPlayers) ? 'disabled' : '' ?>>
                            <option value=""><?= $hasAwaySponsor ? 'Select replacement player' : 'No Away sponsorship to transfer' ?></option>
                            <?php foreach ($awayReplacementPlayers as $replacementPlayer): ?>
                                <option value="<?= (int) $replacementPlayer['id'] ?>"><?= h((string) $replacementPlayer['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?= $hasAwaySponsor ? (empty($awayReplacementPlayers) ? 'No eligible players without an active Away sponsor this season.' : 'Transfer the Away sponsorship to a player without an active Away sponsor.') : 'This player has no Away sponsorship to transfer.' ?></div>
                    </div>
                    <div id="playerStatusModalSponsorSlotsData" data-has-home-sponsor="<?= $hasHomeSponsor ? '1' : '0' ?>" data-has-away-sponsor="<?= $hasAwaySponsor ? '1' : '0' ?>"></div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="radio" name="playerStatusModalSponsorshipAction" id="playerStatusModalSponsorActionUnassign" value="unassign">
                        <label class="form-check-label" for="playerStatusModalSponsorActionUnassign">Unassign sponsorships from this player</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-brand" id="playerStatusModalSave">Save</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
