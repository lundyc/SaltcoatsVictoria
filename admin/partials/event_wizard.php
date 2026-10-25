<?php
declare(strict_types=1);

/**
 * Event wizard (included by event_edit.php, for adding and editing an event). Same look and behaviour as the
 * season wizard: step tabs, panels, a sticky Back/Next bar and a review step.
 *
 * Expects: $event (array|null), $form (array), $venues, $canManage, $ticketRows (posted rows after an error),
 * $existingTypes (when editing), $overrideChecked.
 */

$isNew = !$event;
$steps = $isNew ? ['Basics', 'When & where', 'Audience', 'Tickets', 'Review'] : ['Basics', 'When & where', 'Audience', 'Tickets', 'Review'];
$dt = static fn ($v): string => $v ? date('Y-m-d\TH:i', strtotime((string) $v)) : '';
$ro = $canManage ? '' : ' disabled';
$ticketRows = $ticketRows ?? [];
if ($isNew && !$ticketRows) {
    $ticketRows = [['name' => 'Standard', 'price' => '', 'allocation' => '', 'admits_count' => 1, 'audience' => 'everyone']];
}
?>
<style>
.wiz-steps { display: flex; gap: .25rem; margin: 0 0 1.25rem; padding: 0; list-style: none; overflow-x: auto; }
.wiz-steps li { flex: 1 1 0; min-width: 7.5rem; }
.wiz-steps button { width: 100%; display: flex; align-items: center; gap: .55rem; padding: .6rem .75rem; border: 1px solid var(--hub-line, rgba(75,8,24,.12)); border-radius: .7rem; background: var(--hub-surface, #fffdf9); color: var(--hub-muted, #70676b); font-size: .88rem; font-weight: 600; text-align: left; }
.wiz-steps .wiz-num { flex: none; display: inline-grid; place-items: center; width: 1.6rem; height: 1.6rem; border-radius: 50%; background: rgba(75,8,24,.08); color: var(--brand-dark, #4b0818); font-size: .8rem; }
.wiz-steps li.is-current button { border-color: var(--brand-primary, #6a2036); color: var(--brand-dark, #4b0818); box-shadow: inset 0 -3px 0 var(--brand-warning, #e0b42a); }
.wiz-steps li.is-current .wiz-num { background: var(--brand-primary, #6a2036); color: #fff; }
.wiz-steps li.is-done .wiz-num { background: #2e9e6b; color: #fff; }
.js .wiz-step { display: none; }
.js .wiz-step.is-current { display: block; }
.wiz-step > header { margin-bottom: 1rem; }
.wiz-step > header h2 { margin: 0; font-size: 1.25rem; font-weight: 700; }
.wiz-step > header p { margin: .25rem 0 0; color: var(--hub-muted, #70676b); }
.wiz-card { padding: 1.15rem 1.25rem; margin-bottom: 1rem; }
.wiz-card > h3 { margin: 0 0 .15rem; font-size: 1rem; font-weight: 700; }
.wiz-card > p.wiz-sub { margin: 0 0 1rem; color: var(--hub-muted, #70676b); font-size: .88rem; }
.wiz-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: .9rem 1.1rem; }
.wiz-nav { position: sticky; bottom: 0; display: flex; justify-content: space-between; gap: .5rem; padding: .85rem 0; background: linear-gradient(to top, var(--hub-canvas, #f6ecde) 70%, transparent); }
.wiz-review li { padding: .35rem 0; border-bottom: 1px solid var(--hub-line, rgba(75,8,24,.12)); }
.wiz-cap { height: 12px; }
@media (max-width: 767.98px) { .wiz-steps li { min-width: 3rem; } .wiz-steps .wiz-label { display: none; } .wiz-steps li.is-current .wiz-label { display: inline; } }
</style>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="events.php">Events</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page"><?= $isNew ? 'Add event' : h((string) $form['title']) ?></span></nav>

<?php if (!empty($_GET['saved'])): ?><div class="alert alert-success">Event saved.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!$venues): ?><div class="alert alert-warning">Add a venue first on the <a href="/admin/event_venues.php">Venues</a> page.</div><?php endif; ?>

<form method="post" id="eventWizard" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">

    <ol class="wiz-steps" id="wizSteps" aria-label="Event steps">
        <?php foreach ($steps as $i => $label): ?>
            <li data-step="<?= $i ?>"><button type="button"><span class="wiz-num"><?= $i + 1 ?></span><span class="wiz-label"><?= h($label) ?></span></button></li>
        <?php endforeach; ?>
    </ol>

    <!-- 1. Basics -->
    <section class="wiz-step" data-step="0">
        <header><h2>What is the event?</h2><p>The name and description people will see.</p></header>
        <div class="hub-panel wiz-card">
            <fieldset<?= $ro ?>>
                <div class="mb-3"><label class="form-label" for="evTitle">Title</label><input class="form-control" id="evTitle" name="title" value="<?= h((string) $form['title']) ?>" maxlength="200" placeholder="e.g. Tribute Night" required></div>
                <div class="mb-3"><label class="form-label" for="evShort">Short description</label><input class="form-control" id="evShort" name="short_description" value="<?= h((string) ($form['short_description'] ?? '')) ?>" maxlength="255"><div class="form-text">One line shown in event lists.</div></div>
                <div class="mb-3"><label class="form-label" for="evDesc">Full description</label><textarea class="form-control" id="evDesc" name="description" rows="6"><?= h((string) ($form['description'] ?? '')) ?></textarea></div>
                <div><label class="form-label" for="evImage">Image path</label><input class="form-control" id="evImage" name="image_path" value="<?= h((string) ($form['image_path'] ?? '')) ?>" placeholder="/uploads/site/..."></div>
            </fieldset>
        </div>
    </section>

    <!-- 2. When & where -->
    <section class="wiz-step" data-step="1">
        <header><h2>When and where</h2><p>Dates, venue and how many people it can hold.</p></header>
        <div class="hub-panel wiz-card">
            <h3>Date &amp; time</h3>
            <fieldset<?= $ro ?>>
                <div class="wiz-grid mt-3">
                    <div><label class="form-label" for="evDoors">Doors open</label><input class="form-control" id="evDoors" type="datetime-local" name="doors_at" value="<?= h($dt($form['doors_at'])) ?>"></div>
                    <div><label class="form-label" for="evStart">Starts</label><input class="form-control" id="evStart" type="datetime-local" name="starts_at" value="<?= h($dt($form['starts_at'])) ?>" required></div>
                    <div><label class="form-label" for="evEnd">Ends</label><input class="form-control" id="evEnd" type="datetime-local" name="ends_at" value="<?= h($dt($form['ends_at'])) ?>"></div>
                </div>
            </fieldset>
        </div>
        <div class="hub-panel wiz-card">
            <h3>Venue &amp; capacity</h3>
            <fieldset<?= $ro ?>>
                <div class="wiz-grid mt-3">
                    <div><label class="form-label" for="evVenue">Venue</label>
                        <select class="form-select" id="evVenue" name="venue_id" required>
                            <?php foreach ($venues as $venue): ?>
                                <option value="<?= (int) $venue['id'] ?>" data-capacity="<?= (int) $venue['capacity'] ?>" <?= (int) $venue['id'] === (int) $form['venue_id'] ? 'selected' : '' ?>><?= h((string) $venue['name']) ?> (holds <?= (int) $venue['capacity'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><a href="/admin/event_venues.php">Manage venues</a></div>
                    </div>
                    <div><label class="form-label" for="evCap">Capacity for this event</label><input class="form-control" id="evCap" type="number" min="0" name="capacity_override" value="<?= h((string) ($form['capacity_override'] ?? '')) ?>" placeholder="Venue capacity"><div class="form-text">Leave blank to use the whole venue. Cannot be more than the venue holds.</div></div>
                    <div><label class="form-label" for="evAccess">Accessible places held back</label><input class="form-control" id="evAccess" type="number" min="0" name="access_places" value="<?= (int) ($form['access_places'] ?? 0) ?>"><div class="form-text">Seats kept for people who need them, released to everyone shortly before the event. Add accessible tickets afterwards on the Tickets page.</div></div>
                    <div><label class="form-label" for="evAccessHrs">Open unsold ones to everyone</label><div class="input-group"><input class="form-control" id="evAccessHrs" type="number" min="0" max="720" name="access_release_hours" value="<?= (int) ($form['access_release_hours'] ?? 48) ?>"><span class="input-group-text">hours before</span></div></div>
                </div>
            </fieldset>
        </div>
    </section>

    <!-- 3. Audience -->
    <section class="wiz-step" data-step="2">
        <header><h2>Who is it for?</h2><p>Age limit, visibility and who customers contact.</p></header>
        <div class="hub-panel wiz-card">
            <fieldset<?= $ro ?>>
                <div class="wiz-grid">
                    <div><label class="form-label" for="evAge">Age limit</label>
                        <select class="form-select" id="evAge" name="min_age">
                            <?php foreach (EVENT_MIN_AGES as $key => $label): ?><option value="<?= (int) $key ?>" <?= (int) $key === (int) $form['min_age'] ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                        </select>
                        <input class="form-control mt-2" name="age_note" value="<?= h((string) ($form['age_note'] ?? '')) ?>" maxlength="120" placeholder="Optional note, e.g. under 16s with an adult">
                    </div>
                    <div><label class="form-label" for="evVis">Visibility</label>
                        <select class="form-select" id="evVis" name="visibility">
                            <option value="public" <?= $form['visibility'] === 'public' ? 'selected' : '' ?>>Public</option>
                            <option value="hidden" <?= $form['visibility'] === 'hidden' ? 'selected' : '' ?>>Hidden (link only)</option>
                        </select>
                    </div>
                    <div><label class="form-label" for="evStatus">Status</label>
                        <select class="form-select" id="evStatus" name="status">
                            <?php foreach (EVENT_STATUSES as $key => $label): ?><option value="<?= h($key) ?>" <?= $key === $form['status'] ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-text">Keep as Draft until the tickets are right.</div>
                    </div>
                    <div><label class="form-label" for="evEmail">Organiser contact email</label><input class="form-control" id="evEmail" type="email" name="organiser_email" value="<?= h((string) ($form['organiser_email'] ?? '')) ?>" placeholder="Club default"><div class="form-text">Shown to customers for problems and queries.</div></div>
                </div>
            </fieldset>
        </div>
    </section>

    <!-- 4. Tickets -->
    <section class="wiz-step" data-step="3">
        <header><h2>Tickets</h2><p><?= $isNew ? 'What is sold, for how much, and how many. You can add more later.' : 'Ticket types for this event.' ?></p></header>
        <?php if ($isNew): ?>
        <div class="hub-panel wiz-card">
            <div class="d-flex justify-content-between"><strong>Seats allocated</strong><span id="capText"></span></div>
            <div class="progress mt-2 wiz-cap" role="progressbar" aria-label="Seats allocated"><div class="progress-bar bg-success" id="capBar" style="width:0%"></div></div>
            <div class="small text-muted mt-2">A ticket that admits several people counts as that many seats (a Table of 8 uses 8).</div>
            <div class="form-check mt-2" id="overBox" hidden><input class="form-check-input" type="checkbox" id="evOver" name="allow_over_capacity" value="1" <?= !empty($overrideChecked) ? 'checked' : '' ?>><label class="form-check-label" for="evOver">Allow going over the event's capacity (override, recorded in the audit log)</label></div>
        </div>
        <div class="hub-panel wiz-card">
            <div class="table-responsive">
                <table class="table align-middle mb-2" id="wizTypes">
                    <thead><tr><th>Ticket</th><th style="min-width:7rem">Price (£)</th><th style="min-width:6rem">Available</th><th style="min-width:6rem">People each</th><th>Who can buy</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($ticketRows as $i => $row): ?>
                            <tr>
                                <td><input class="form-control form-control-sm" name="tt[<?= (int) $i ?>][name]" value="<?= h((string) ($row['name'] ?? '')) ?>" placeholder="e.g. Standard" aria-label="Ticket name"></td>
                                <td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="tt[<?= (int) $i ?>][price]" value="<?= h((string) ($row['price'] ?? '')) ?>" placeholder="0 = free" aria-label="Price"></td>
                                <td><input class="form-control form-control-sm" type="number" min="1" name="tt[<?= (int) $i ?>][allocation]" value="<?= h((string) ($row['allocation'] ?? '')) ?>" aria-label="Number available"></td>
                                <td><input class="form-control form-control-sm" type="number" min="1" max="50" name="tt[<?= (int) $i ?>][admits_count]" value="<?= (int) ($row['admits_count'] ?? 1) ?>" aria-label="People per ticket"></td>
                                <td><select class="form-select form-select-sm" name="tt[<?= (int) $i ?>][audience]" aria-label="Who can buy"><?php foreach (EVENT_AUDIENCES as $key => $label): ?><option value="<?= h($key) ?>" <?= $key === ($row['audience'] ?? 'everyone') ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></td>
                                <td><button type="button" class="btn btn-sm btn-outline-secondary" data-remove-row aria-label="Remove this ticket type">&times;</button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="wizAddType"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add ticket type</button>
            <div class="form-text mt-2">Members are active season ticket holders and get <?= h((string) EVENT_DEFAULT_MEMBER_DISCOUNT_PCT) ?>% off by default. Sale dates, per-order limits and hidden tickets are set after the event is created. A booking fee of 1.5% + 20p is added once per paid order.</div>
        </div>
        <?php else: ?>
        <div class="hub-panel wiz-card">
            <?php if ($existingTypes): ?>
                <ul class="list-unstyled mb-3">
                    <?php foreach ($existingTypes as $type): ?><li class="py-1 border-bottom"><strong><?= h((string) $type['name']) ?></strong> · <?= (float) $type['price'] > 0 ? gbp((float) $type['price']) : 'Free' ?> · <?= (int) $type['allocation'] ?> available<?= (int) $type['is_active'] === 1 ? '' : ' <span class="badge text-bg-secondary">Off sale</span>' ?></li><?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted">No ticket types yet.</p>
            <?php endif; ?>
            <a class="btn btn-outline-secondary" href="/admin/event_tickets.php?event_id=<?= (int) $form['id'] ?>">Manage ticket types &amp; prices</a>
        </div>
        <?php endif; ?>
    </section>

    <!-- 5. Review -->
    <section class="wiz-step" data-step="4">
        <header><h2>Review</h2><p><?= $isNew ? 'Check everything before the event is created.' : 'Check your changes before saving.' ?></p></header>
        <div class="hub-panel wiz-card">
            <ul class="list-unstyled wiz-review mb-0" id="wizReview"></ul>
        </div>
        <?php if ($isNew): ?><div class="alert alert-light border small">The event is created as <strong id="wizReviewStatus">Draft</strong>. Nothing is on sale to the public until you set it to On sale.</div><?php endif; ?>
    </section>

    <div class="wiz-nav">
        <a href="events.php" class="btn btn-outline-secondary">Cancel</a>
        <span class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" id="wizBack" hidden><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back</button>
            <button type="button" class="btn btn-brand" id="wizNext">Next <i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></button>
            <?php if ($canManage): ?><button type="submit" class="btn btn-brand" id="wizCreate" hidden><i class="fa-solid fa-check me-1" aria-hidden="true"></i><?= $isNew ? 'Create event' : 'Save changes' ?></button><?php endif; ?>
        </span>
    </div>
</form>

<template id="wizTypeRow">
    <tr>
        <td><input class="form-control form-control-sm" name="tt[__I__][name]" placeholder="e.g. Member" aria-label="Ticket name"></td>
        <td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="tt[__I__][price]" placeholder="0 = free" aria-label="Price"></td>
        <td><input class="form-control form-control-sm" type="number" min="1" name="tt[__I__][allocation]" aria-label="Number available"></td>
        <td><input class="form-control form-control-sm" type="number" min="1" max="50" name="tt[__I__][admits_count]" value="1" aria-label="People per ticket"></td>
        <td><select class="form-select form-select-sm" name="tt[__I__][audience]" aria-label="Who can buy"><?php foreach (EVENT_AUDIENCES as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></td>
        <td><button type="button" class="btn btn-sm btn-outline-secondary" data-remove-row aria-label="Remove this ticket type">&times;</button></td>
    </tr>
</template>

<script>
(function () {
    document.documentElement.classList.add('js');
    var form = document.getElementById('eventWizard');
    var steps = Array.prototype.slice.call(form.querySelectorAll('.wiz-step'));
    var tabs = Array.prototype.slice.call(document.querySelectorAll('#wizSteps li'));
    var back = document.getElementById('wizBack'), next = document.getElementById('wizNext'), create = document.getElementById('wizCreate');
    var isNew = <?= $isNew ? 'true' : 'false' ?>;
    var current = 0, furthest = 0;
    var typeBody = document.querySelector('#wizTypes tbody');
    var typeIndex = typeBody ? typeBody.querySelectorAll('tr').length : 0;

    function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function val(id) { var el = document.getElementById(id); return el ? el.value : ''; }
    function sel(id) { var el = document.getElementById(id); return el && el.selectedOptions[0] ? el.selectedOptions[0].textContent.trim() : ''; }
    function when(id) { var v = val(id); if (!v) { return ''; } var d = new Date(v); return isNaN(d) ? v : d.toLocaleString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }); }

    function capacity() {
        var own = parseInt(val('evCap'), 10);
        var venue = document.getElementById('evVenue');
        var vcap = venue && venue.selectedOptions[0] ? parseInt(venue.selectedOptions[0].dataset.capacity, 10) || 0 : 0;
        return own > 0 ? own : vcap;
    }
    function rows() {
        if (!typeBody) { return []; }
        return Array.prototype.map.call(typeBody.querySelectorAll('tr'), function (tr) {
            var f = function (n) { var el = tr.querySelector('[name$="[' + n + ']"]'); return el ? el.value : ''; };
            return { name: f('name').trim(), price: parseFloat(f('price')) || 0, allocation: parseInt(f('allocation'), 10) || 0, admits: Math.max(1, parseInt(f('admits_count'), 10) || 1) };
        }).filter(function (r) { return r.name !== ''; });
    }
    function capacityBar() {
        var text = document.getElementById('capText'); if (!text) { return; }
        var cap = capacity(), used = rows().reduce(function (s, r) { return s + r.allocation * r.admits; }, 0);
        var pct = cap > 0 ? Math.min(100, Math.round(used / cap * 100)) : 0, over = used > cap;
        text.textContent = used + ' of ' + cap + (over ? ' (over capacity)' : '');
        var bar = document.getElementById('capBar'); bar.style.width = pct + '%'; bar.className = 'progress-bar ' + (over ? 'bg-danger' : (pct >= 90 ? 'bg-warning' : 'bg-success'));
        document.getElementById('overBox').hidden = !over;
    }

    var addType = document.getElementById('wizAddType');
    if (addType) {
        addType.addEventListener('click', function () {
            typeBody.insertAdjacentHTML('beforeend', document.getElementById('wizTypeRow').innerHTML.replace(/__I__/g, String(typeIndex++)));
            var r = typeBody.querySelectorAll('tr'); r[r.length - 1].querySelector('input').focus();
        });
        typeBody.addEventListener('click', function (e) { var b = e.target.closest('[data-remove-row]'); if (b) { b.closest('tr').remove(); capacityBar(); } });
        typeBody.addEventListener('input', capacityBar);
    }
    ['evCap', 'evVenue'].forEach(function (id) { var el = document.getElementById(id); if (el) { el.addEventListener('input', capacityBar); el.addEventListener('change', capacityBar); } });

    function review() {
        var list = [
            ['Title', val('evTitle') || '<em>not set</em>', true],
            ['Starts', when('evStart') || '<em>not set</em>', true],
            ['Doors', when('evDoors') || 'not set'],
            ['Ends', when('evEnd') || 'not set'],
            ['Venue', sel('evVenue')],
            ['Capacity', capacity() + ' people'],
            ['Age limit', sel('evAge') + (val('evAge') !== '0' && form.elements.age_note.value ? ' (' + form.elements.age_note.value + ')' : '')],
            ['Visibility', sel('evVis')],
            ['Status', sel('evStatus')],
            ['Contact', val('evEmail') || 'club default']
        ];
        if (isNew) {
            var r = rows();
            list.push(['Tickets', r.length ? r.map(function (t) { return esc(t.name) + ' ' + (t.price > 0 ? '£' + t.price.toFixed(2) : 'free') + ' × ' + t.allocation + (t.admits > 1 ? ' (' + t.admits + ' people each)' : ''); }).join('<br>') : 'none yet', true]);
        }
        document.getElementById('wizReview').innerHTML = list.map(function (x) { return '<li><strong>' + esc(x[0]) + ':</strong> ' + (x[2] ? x[1] : esc(x[1])) + '</li>'; }).join('');
        var st = document.getElementById('wizReviewStatus'); if (st) { st.textContent = sel('evStatus'); }
    }

    function show(i) {
        current = Math.max(0, Math.min(steps.length - 1, i));
        furthest = Math.max(furthest, current);
        steps.forEach(function (s, n) { s.classList.toggle('is-current', n === current); });
        tabs.forEach(function (t, n) { t.classList.toggle('is-current', n === current); t.classList.toggle('is-done', n < furthest && n !== current); });
        back.hidden = current === 0;
        next.hidden = current === steps.length - 1;
        if (create) { create.hidden = current !== steps.length - 1; }
        if (current === 3) { capacityBar(); }
        if (current === steps.length - 1) { review(); }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function valid(upTo) {
        var title = document.getElementById('evTitle'), start = document.getElementById('evStart');
        title.classList.toggle('is-invalid', title.value.trim() === '');
        if (title.value.trim() === '') { show(0); title.focus(); return false; }
        if (upTo >= 2) {
            start.classList.toggle('is-invalid', start.value === '');
            if (start.value === '') { show(1); start.focus(); return false; }
        }
        return true;
    }
    next.addEventListener('click', function () { if (!valid(current + 1)) { return; } show(current + 1); });
    back.addEventListener('click', function () { show(current - 1); });
    tabs.forEach(function (t, n) { t.querySelector('button').addEventListener('click', function () { if (n > current && !valid(n)) { return; } show(n); }); });
    form.addEventListener('submit', function (e) { if (!valid(4)) { e.preventDefault(); } });
    form.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT' && current < steps.length - 1) { e.preventDefault(); next.click(); } });
    capacityBar();
    show(0);
})();
</script>
