<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Events',
    'title' => 'Event Venues',
    'subtitle' => 'Places events are held, and how many people each can hold.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/events.php';
require_once __DIR__ . '/lib/audit.php';
ensureEventsSchema($pdo);

$errors = [];
$success = '';
$canManage = hub_auth_has_capability('events_manage');
$editingId = (int) ($_GET['id'] ?? 0);
$adding = isset($_GET['add']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please reload and try again.';
    } elseif (!$canManage) {
        $errors[] = 'You do not have permission to change venues.';
    } else {
        try {
            $postedId = (int) ($_POST['id'] ?? 0) ?: null;
            $savedId = events_save_venue($pdo, $postedId, $_POST);
            auditLog($pdo, $postedId ? 'event_venue_updated' : 'event_venue_created', 'Saved event venue #' . $savedId . ': ' . trim((string) ($_POST['name'] ?? '')));
            $success = 'Venue saved.';
            $editingId = 0;
            $adding = false;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $editingId = (int) ($_POST['id'] ?? 0);
            $adding = $editingId === 0;
        }
    }
}

$venues = events_venues($pdo);
$editing = $editingId > 0 ? events_venue($pdo, $editingId) : null;
$showForm = $canManage && ($editing || $adding);
$form = $editing ?? ['id' => 0, 'name' => '', 'address' => '', 'postcode' => '', 'map_url' => '', 'photo_path' => '', 'capacity' => '', 'access_notes' => '', 'notes' => '', 'is_active' => 1];
if ($errors && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $form = array_merge($form, array_intersect_key($_POST, $form), ['is_active' => isset($_POST['is_active']) ? 1 : 0]);
}
$activeVenues = array_filter($venues, static fn (array $v): bool => (int) $v['is_active'] === 1);
$biggest = 0;
foreach ($activeVenues as $v) {
    $biggest = max($biggest, (int) $v['capacity']);
}
?>

<?php hub_render_metric_grid([
    ['label' => 'Venues in use', 'value' => (string) count($activeVenues), 'meta' => count($venues) - count($activeVenues) > 0 ? (count($venues) - count($activeVenues)) . ' not in use' : 'All venues active', 'icon' => 'fa-location-dot', 'tone' => 'primary'],
    ['label' => 'Largest capacity', 'value' => $biggest > 0 ? (string) $biggest : 'None', 'meta' => 'people', 'icon' => 'fa-users', 'tone' => 'info'],
    ['label' => 'Events held', 'value' => (string) array_sum(array_map(static fn (array $v): int => (int) $v['event_count'], $venues)), 'meta' => 'across all venues', 'icon' => 'fa-calendar-days', 'tone' => 'success'],
], 'Venues summary'); ?>

<?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="hub-section-commandbar">
    <div><h2>Venues</h2><p>Each venue has a total capacity. An event can set a lower limit, never a higher one.</p></div>
    <div class="hub-local-actions">
        <?php if ($canManage && !$showForm): ?><a href="event_venues.php?add=1" class="btn btn-brand"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add venue</a><?php endif; ?>
    </div>
</div>

<?php if ($showForm): ?>
<div class="hub-panel mb-3" style="padding:1.15rem 1.25rem">
    <h3 class="h5 mb-3"><?= $editing ? 'Edit ' . h((string) $editing['name']) : 'Add venue' ?></h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="venueName">Name</label><input class="form-control" id="venueName" name="name" value="<?= h((string) $form['name']) ?>" maxlength="150" required></div>
            <div class="col-md-3"><label class="form-label" for="venueCapacity">Total capacity</label><input class="form-control" id="venueCapacity" type="number" min="1" name="capacity" value="<?= h((string) $form['capacity']) ?>" required><div class="form-text">The most people it can hold.</div></div>
            <div class="col-md-3 d-flex align-items-center"><div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" role="switch" id="venueActive" name="is_active" value="1" <?= (int) $form['is_active'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="venueActive">Venue is in use</label></div></div>
            <div class="col-md-6"><label class="form-label" for="venueAddress">Address</label><input class="form-control" id="venueAddress" name="address" value="<?= h((string) ($form['address'] ?? '')) ?>" maxlength="255"></div>
            <div class="col-md-3"><label class="form-label" for="venuePostcode">Postcode</label><input class="form-control text-uppercase" id="venuePostcode" name="postcode" value="<?= h((string) ($form['postcode'] ?? '')) ?>" maxlength="12"></div>
            <div class="col-md-3"><label class="form-label" for="venueMap">Map link</label><input class="form-control" id="venueMap" type="url" name="map_url" value="<?= h((string) ($form['map_url'] ?? '')) ?>" placeholder="https://maps.google.com/..."></div>
            <div class="col-md-6"><label class="form-label" for="venuePhoto">Photo path</label><input class="form-control" id="venuePhoto" name="photo_path" value="<?= h((string) ($form['photo_path'] ?? '')) ?>" placeholder="/uploads/site/big-jays.jpg"></div>
            <div class="col-md-6"><label class="form-label" for="venueAccess">Access and parking notes</label><textarea class="form-control" id="venueAccess" name="access_notes" rows="2"><?= h((string) ($form['access_notes'] ?? '')) ?></textarea><div class="form-text">Step-free access, accessible parking, hearing loop. Shown to customers.</div></div>
            <div class="col-12"><label class="form-label" for="venueNotes">Internal notes</label><textarea class="form-control" id="venueNotes" name="notes" rows="2"><?= h((string) ($form['notes'] ?? '')) ?></textarea></div>
        </div>
        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-brand" type="submit">Save venue</button>
            <a class="btn btn-outline-secondary" href="event_venues.php">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card hub-list-card hub-table-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle hub-data-table hub-data-table--responsive">
                <caption class="visually-hidden">Event venues, capacity, events held, status and actions</caption>
                <thead>
                    <tr>
                        <th>Venue</th>
                        <th>Capacity</th>
                        <th>Events</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($venues as $venue): ?>
                        <tr>
                            <td data-label="Venue">
                                <div class="d-flex align-items-center gap-3">
                                    <?php if (!empty($venue['photo_path'])): ?><img src="<?= h((string) $venue['photo_path']) ?>" alt="" width="56" height="40" style="object-fit:cover;border-radius:.4rem;flex:none" loading="lazy"><?php endif; ?>
                                    <div>
                                        <strong><?= h((string) $venue['name']) ?></strong>
                                        <div class="text-muted small"><?= h(trim((string) $venue['address'] . ' ' . (string) $venue['postcode'])) ?: 'No address yet' ?></div>
                                    </div>
                                </div>
                            </td>
                            <td data-label="Capacity"><strong><?= (int) $venue['capacity'] ?></strong> <span class="text-muted small">people</span></td>
                            <td data-label="Events"><?= (int) $venue['event_count'] ?></td>
                            <td data-label="Status"><?php hub_render_badge((int) $venue['is_active'] === 1 ? 'In use' : 'Not in use', (int) $venue['is_active'] === 1 ? 'success' : 'secondary'); ?></td>
                            <td data-label="Actions" class="text-nowrap">
                                <div class="hub-row-actions hub-actions">
                                    <?php if (!empty($venue['map_url'])): ?><a href="<?= h((string) $venue['map_url']) ?>" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">Map</a><?php endif; ?>
                                    <?php if ($canManage): ?><a href="event_venues.php?id=<?= (int) $venue['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a><?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$venues): ?>
                        <tr><td colspan="5" class="hub-record-empty hub-empty-state">No venues yet. Add a venue to hold events.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
