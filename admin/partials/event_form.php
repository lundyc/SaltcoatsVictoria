<?php
declare(strict_types=1);

/**
 * Edit view for an existing event (included by event_edit.php): a summary at the top, then every section
 * on one page, with a sticky Save bar. New events use the step-by-step wizard instead.
 *
 * Expects: $event, $form, $venues, $canManage, $errors, $existingTypes, $pdo.
 */

$dt = static fn ($v): string => $v ? date('Y-m-d\TH:i', strtotime((string) $v)) : '';
$ro = $canManage ? '' : ' disabled';
$statusTone = ['draft' => 'secondary', 'on_sale' => 'success', 'sold_out' => 'warning', 'postponed' => 'warning', 'cancelled' => 'danger', 'finished' => 'dark'];
$summary = events_capacity_summary($pdo, $event);
$pct = $summary['capacity'] > 0 ? min(100, (int) round($summary['allocated'] / $summary['capacity'] * 100)) : 0;
$startTs = strtotime((string) $event['starts_at']);
$daysAway = (int) floor(($startTs - time()) / 86400);
$when = $startTs < time() ? 'Past' : ($daysAway <= 0 ? 'Today' : ($daysAway === 1 ? 'Tomorrow' : 'In ' . $daysAway . ' days'));
?>
<style>
.ev-sticky { position: sticky; bottom: 0; z-index: 5; display: flex; justify-content: space-between; align-items: center; gap: .5rem; padding: .85rem 4.5rem .85rem 0; background: linear-gradient(to top, var(--hub-canvas, #f6ecde) 70%, transparent); }
.ev-panel { padding: 1.15rem 1.25rem; margin-bottom: 1rem; }
.ev-panel > h3 { margin: 0 0 .15rem; font-size: 1rem; font-weight: 700; }
.ev-panel > .ev-sub { margin: 0 0 1rem; color: var(--hub-muted, #70676b); font-size: .88rem; }
.ev-types li { display: flex; justify-content: space-between; gap: 1rem; padding: .5rem 0; border-bottom: 1px solid var(--hub-line, rgba(75,8,24,.12)); }
.ev-types li:last-child { border-bottom: 0; }
</style>

<?php hub_render_breadcrumbs([['label' => 'Events', 'href' => 'events.php'], ['label' => (string) $event['title']]]); ?>
<?php $eventNavActive = 'details'; require __DIR__ . '/event_subnav.php'; ?>

<?php if (!empty($_GET['saved'])): ?><div class="alert alert-success">Event saved.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!$canManage): ?><div class="alert alert-info">You can view this event but not change it.</div><?php endif; ?>

<div class="hub-section-commandbar">
    <div>
        <h2><?= h((string) $event['title']) ?> <?php hub_render_badge(EVENT_STATUSES[$event['status']] ?? (string) $event['status'], $statusTone[$event['status']] ?? 'secondary'); ?></h2>
        <p><?= h(date('l j F Y, H:i', $startTs)) ?> · <?= h((string) $event['venue_name']) ?></p>
    </div>
    <div class="hub-local-actions">
        <a href="event_tickets.php?event_id=<?= (int) $event['id'] ?>" class="btn btn-brand"><i class="fa-solid fa-ticket me-1" aria-hidden="true"></i>Ticket types &amp; prices</a>
    </div>
</div>

<?php hub_render_metric_grid([
    ['label' => 'When', 'value' => $when, 'meta' => date('D j M, H:i', $startTs), 'icon' => 'fa-calendar-days', 'tone' => 'primary'],
    ['label' => 'Seats allocated', 'value' => $summary['allocated'] . ' / ' . $summary['capacity'], 'meta' => $summary['over'] ? 'Over capacity' : max(0, $summary['remaining']) . ' not yet allocated', 'icon' => 'fa-chair', 'tone' => $summary['over'] ? 'danger' : ($pct >= 90 ? 'warning' : 'success')],
    ['label' => 'Ticket types', 'value' => (string) count($existingTypes), 'meta' => $existingTypes ? 'on this event' : 'none yet', 'icon' => 'fa-ticket', 'tone' => 'info'],
    ['label' => 'Age limit', 'value' => EVENT_MIN_AGES[(int) $event['min_age']] ?? 'None', 'meta' => $event['visibility'] === 'hidden' ? 'Hidden (link only)' : 'Public', 'icon' => 'fa-id-card', 'tone' => 'neutral'],
], 'Event summary'); ?>

<form method="post" id="eventEditForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
    <div class="row g-3">
        <div class="col-xl-8">
            <div class="hub-panel ev-panel">
                <h3>Event details</h3>
                <p class="ev-sub">The name and description people will see.</p>
                <fieldset<?= $ro ?>>
                    <div class="mb-3"><label class="form-label" for="evTitle">Title</label><input class="form-control" id="evTitle" name="title" value="<?= h((string) $form['title']) ?>" maxlength="200" required></div>
                    <div class="mb-3"><label class="form-label" for="evShort">Short description</label><input class="form-control" id="evShort" name="short_description" value="<?= h((string) ($form['short_description'] ?? '')) ?>" maxlength="255"><div class="form-text">One line shown in event lists.</div></div>
                    <div class="mb-3"><label class="form-label" for="evDesc">Full description</label><textarea class="form-control" id="evDesc" name="description" rows="6"><?= h((string) ($form['description'] ?? '')) ?></textarea></div>
                    <div><label class="form-label" for="evImage">Image path</label><input class="form-control" id="evImage" name="image_path" value="<?= h((string) ($form['image_path'] ?? '')) ?>" placeholder="/uploads/site/..."></div>
                </fieldset>
            </div>

            <div class="hub-panel ev-panel">
                <h3>Date &amp; time</h3>
                <p class="ev-sub">Doors, start and finish.</p>
                <fieldset<?= $ro ?>>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="evDoors">Doors open</label><input class="form-control" id="evDoors" type="datetime-local" name="doors_at" value="<?= h($dt($form['doors_at'])) ?>"></div>
                        <div class="col-md-4"><label class="form-label" for="evStart">Starts</label><input class="form-control" id="evStart" type="datetime-local" name="starts_at" value="<?= h($dt($form['starts_at'])) ?>" required></div>
                        <div class="col-md-4"><label class="form-label" for="evEnd">Ends</label><input class="form-control" id="evEnd" type="datetime-local" name="ends_at" value="<?= h($dt($form['ends_at'])) ?>"></div>
                    </div>
                </fieldset>
            </div>

            <div class="hub-panel ev-panel">
                <h3>Venue &amp; capacity</h3>
                <p class="ev-sub">Where it is held and how many people it can take.</p>
                <fieldset<?= $ro ?>>
                    <div class="row g-3">
                        <div class="col-md-7"><label class="form-label" for="evVenue">Venue</label>
                            <select class="form-select" id="evVenue" name="venue_id" required>
                                <?php foreach ($venues as $venue): ?>
                                    <option value="<?= (int) $venue['id'] ?>" <?= (int) $venue['id'] === (int) $form['venue_id'] ? 'selected' : '' ?>><?= h((string) $venue['name']) ?> (holds <?= (int) $venue['capacity'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text"><a href="event_venues.php">Manage venues</a></div>
                        </div>
                        <div class="col-md-5"><label class="form-label" for="evCap">Capacity for this event</label><input class="form-control" id="evCap" type="number" min="0" name="capacity_override" value="<?= h((string) ($form['capacity_override'] ?? '')) ?>" placeholder="Venue capacity"><div class="form-text">Blank uses the whole venue.</div></div>
                        <div class="col-md-6"><label class="form-label" for="evAccess">Accessible places held back</label><input class="form-control" id="evAccess" type="number" min="0" name="access_places" value="<?= (int) ($form['access_places'] ?? 0) ?>"><div class="form-text">Wheelchair spaces, step-free seats and carer places kept for people who need them. Only accessible tickets can use them.</div></div>
                        <div class="col-md-6"><label class="form-label" for="evAccessHrs">Open unsold ones to everyone</label><div class="input-group"><input class="form-control" id="evAccessHrs" type="number" min="0" max="720" name="access_release_hours" value="<?= (int) ($form['access_release_hours'] ?? 48) ?>"><span class="input-group-text">hours before the event</span></div><div class="form-text">So accessible places are not left empty on the night.</div></div>
                    </div>
                </fieldset>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="hub-panel ev-panel">
                <h3>Status &amp; audience</h3>
                <p class="ev-sub">Who can see and attend.</p>
                <fieldset<?= $ro ?>>
                    <div class="mb-3"><label class="form-label" for="evStatus">Status</label>
                        <select class="form-select" id="evStatus" name="status">
                            <?php foreach (EVENT_STATUSES as $key => $label): ?><option value="<?= h($key) ?>" <?= $key === $form['status'] ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label" for="evVis">Visibility</label>
                        <select class="form-select" id="evVis" name="visibility">
                            <option value="public" <?= $form['visibility'] === 'public' ? 'selected' : '' ?>>Public</option>
                            <option value="hidden" <?= $form['visibility'] === 'hidden' ? 'selected' : '' ?>>Hidden (link only)</option>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label" for="evAge">Age limit</label>
                        <select class="form-select" id="evAge" name="min_age">
                            <?php foreach (EVENT_MIN_AGES as $key => $label): ?><option value="<?= (int) $key ?>" <?= (int) $key === (int) $form['min_age'] ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                        </select>
                        <input class="form-control mt-2" name="age_note" value="<?= h((string) ($form['age_note'] ?? '')) ?>" maxlength="120" placeholder="Optional note, e.g. under 16s with an adult" aria-label="Age note">
                    </div>
                    <div><label class="form-label" for="evEmail">Organiser contact email</label><input class="form-control" id="evEmail" type="email" name="organiser_email" value="<?= h((string) ($form['organiser_email'] ?? '')) ?>" placeholder="Club default"><div class="form-text">Shown to customers for problems and queries.</div></div>
                </fieldset>
            </div>

            <div class="hub-panel ev-panel">
                <h3>Tickets</h3>
                <p class="ev-sub"><?= $existingTypes ? 'What is on sale for this event.' : 'Nothing on sale yet.' ?></p>
                <?php if ($existingTypes): ?>
                    <ul class="list-unstyled ev-types mb-3">
                        <?php foreach ($existingTypes as $type): ?>
                            <li>
                                <span><strong><?= h((string) $type['name']) ?></strong><?php if ((int) $type['is_active'] !== 1): ?> <span class="badge bg-secondary">Off sale</span><?php endif; ?><?php if ($type['visibility'] === 'hidden'): ?> <span class="badge bg-secondary">Hidden</span><?php endif; ?><br><small class="text-muted"><?= (int) $type['allocation'] ?> available<?= (int) $type['admits_count'] > 1 ? ' · ' . (int) $type['admits_count'] . ' people each' : '' ?></small></span>
                                <span class="text-end"><?= (float) $type['price'] > 0 ? gbp((float) $type['price']) : 'Free' ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <a class="btn btn-outline-primary w-100" href="event_tickets.php?event_id=<?= (int) $event['id'] ?>">Manage ticket types &amp; prices</a>
            </div>
        </div>
    </div>

    <?php if ($canManage && (int) ($event['access_places'] ?? 0) > 0): ?>
        <div class="hub-panel ev-panel">
            <h3>Accessible places</h3>
            <p class="ev-sub"><?= events_access_released($event) ? 'Unsold accessible places are open to everyone.' : 'Unsold accessible places are being held back until ' . h(date('D j M, H:i', strtotime((string) $event['starts_at']) - (int) $event['access_release_hours'] * 3600)) . '.' ?></p>
            <?php if (!events_access_released($event)): ?>
                <button class="btn btn-outline-primary" type="submit" form="releaseAccessForm" onclick="return confirm('Open the unsold accessible places to everyone now?')">Release them now</button>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="ev-sticky">
        <a href="events.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to events</a>
        <?php if ($canManage): ?><button type="submit" class="btn btn-brand"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Save changes</button><?php endif; ?>
    </div>
</form>
<?php if ($canManage): ?>
<form method="post" id="releaseAccessForm"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $event['id'] ?>"><input type="hidden" name="action" value="release_access"></form>
<?php endif; ?>
