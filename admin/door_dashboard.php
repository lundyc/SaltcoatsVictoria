<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Match day and events',
    'title' => 'Door Dashboard',
    'subtitle' => 'Live entry across every gate: who is in, how fast, and which phones are working.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/door_dashboard.php';

$targets = door_targets($pdo);
$kind = ($_GET['kind'] ?? '') === 'event' ? 'event' : 'match';
$id = (int) ($_GET['id'] ?? 0);
$current = null;
foreach ($targets as $t) {
    if ($t['kind'] === $kind && $t['id'] === $id) {
        $current = $t;
    }
}
if (!$current && $targets) {
    $current = $targets[0];
}
?>
<style>
.dd-big { font-size: 2.6rem; font-weight: 800; line-height: 1.05; }
.dd-card { padding: 1rem 1.15rem; }
.dd-bars { display: flex; align-items: flex-end; gap: 4px; height: 90px; }
.dd-bars span { flex: 1; background: var(--brand-primary, #6a2036); border-radius: 3px 3px 0 0; min-height: 2px; opacity: .85; }
.dd-feed li { display: grid; grid-template-columns: 4.4rem 7.5rem 1fr auto; gap: .5rem; padding: .35rem 0; border-bottom: 1px solid var(--hub-line, rgba(75,8,24,.12)); align-items: center; }
.dd-feed li:last-child { border-bottom: 0; }
.dd-dot { display: inline-block; width: .65rem; height: .65rem; border-radius: 50%; margin-right: .4rem; }
@media (max-width: 767.98px) { .dd-feed li { grid-template-columns: 4rem 1fr auto; } .dd-feed li .dd-gatecol { display: none; } }
</style>

<?php hub_render_breadcrumbs([['label' => 'Door dashboard']]); ?>

<?php if (!$targets): ?>
    <div class="hub-panel dd-card"><p class="mb-1"><strong>No match or event today.</strong></p><p class="text-muted mb-0">The dashboard shows today's home match and any event happening today. A scanning day runs until 5am.</p></div>
<?php else: ?>
<div class="d-flex flex-wrap gap-2 mb-3" role="tablist" aria-label="Today">
    <?php foreach ($targets as $t): $on = $current && $t['kind'] === $current['kind'] && $t['id'] === $current['id']; ?>
        <a class="btn btn-sm <?= $on ? 'btn-brand' : 'btn-outline-secondary' ?>" href="door_dashboard.php?kind=<?= h($t['kind']) ?>&amp;id=<?= (int) $t['id'] ?>"><?= h($t['title']) ?> <small class="opacity-75">· <?= h($t['sub']) ?></small></a>
    <?php endforeach; ?>
</div>

<div id="ddAlerts"></div>

<div class="row g-3 mb-3">
    <div class="col-md-6 col-xl-3"><div class="hub-panel dd-card h-100"><div class="text-muted small">People in</div><div class="dd-big" id="ddIn">-</div><div class="small text-muted" id="ddOf"></div><div class="progress mt-2" style="height:8px" role="progressbar" aria-label="Entry progress"><div class="progress-bar bg-success" id="ddBar" style="width:0%"></div></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="hub-panel dd-card h-100"><div class="text-muted small">Arriving now</div><div class="dd-big"><span id="ddRate">-</span><span class="fs-6 text-muted"> / min</span></div><div class="small text-muted" id="ddRate10"></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="hub-panel dd-card h-100"><div class="text-muted small">Gates reporting</div><div class="dd-big" id="ddGates">-</div><div class="small text-muted" id="ddGatesSub"></div></div></div>
    <div class="col-md-6 col-xl-3"><div class="hub-panel dd-card h-100"><div class="text-muted small">Refused scans</div><div class="dd-big" id="ddRefused">-</div><div class="small text-muted">cancelled, unknown or already used</div></div></div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="card hub-list-card hub-table-card mb-3">
            <div class="card-header"><h2 class="h5 mb-0">By gate</h2></div>
            <div class="card-body p-0"><div class="table-responsive">
                <table class="table align-middle hub-data-table mb-0"><thead><tr><th>Gate</th><th>Phones</th><th>In</th><th>Refused</th><th>Offline scans</th><th>Last scan</th></tr></thead><tbody id="ddGateRows"><tr><td colspan="6" class="text-muted">Loading...</td></tr></tbody></table>
            </div></div>
        </div>
        <div class="hub-panel dd-card mb-3">
            <h3 class="h5 mb-2">Arrivals, last hour <span class="small text-muted">(per 5 minutes)</span></h3>
            <div class="dd-bars" id="ddBars" aria-hidden="true"></div>
            <div class="d-flex justify-content-between small text-muted mt-1"><span>60 min ago</span><span>now</span></div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="hub-panel dd-card mb-3">
            <h3 class="h5 mb-2">Scanner phones</h3>
            <div id="ddDevices" class="small"><span class="text-muted">Loading...</span></div>
        </div>
        <div class="hub-panel dd-card mb-3">
            <h3 class="h5 mb-2">Latest scans</h3>
            <ul class="list-unstyled mb-0 small dd-feed" id="ddFeed"></ul>
        </div>
    </div>
</div>
<p class="small text-muted" id="ddStamp"></p>

<script>
(function () {
    var KIND = <?= json_encode($current['kind']) ?>, ID = <?= (int) $current['id'] ?>;
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var failures = 0;
    var statusText = { checked_in: 'In', already_scanned: 'Already used', not_active: 'Cancelled', invalid_token: 'Unknown code', not_found: 'Unknown code', wrong_fixture: 'Other match', wrong_event: 'Other event', wrong_ticket_type: 'Other ticket', individual_required: 'Order link' };

    function render(d) {
        var capOrExp = d.capacity > 0 ? d.capacity : d.expected;
        $('ddIn').textContent = d.admitted;
        $('ddOf').textContent = d.capacity > 0 ? 'of ' + d.capacity + ' capacity (' + d.expected + ' people hold tickets)' : (d.expected ? 'of ' + d.expected + ' tickets and season tickets on the system' : '');
        $('ddBar').style.width = capOrExp > 0 ? Math.min(100, Math.round(d.admitted / capOrExp * 100)) + '%' : '0%';
        $('ddRate').textContent = d.per_minute;
        $('ddRate10').textContent = d.rate10 + ' people in the last 10 minutes';
        var live = d.devices.filter(function (x) { return !x.silent; }).length;
        $('ddGates').textContent = live + ' / ' + d.devices.length;
        $('ddGatesSub').textContent = d.devices.length ? (d.devices.length - live) + ' silent' : 'No phones have reported yet';
        $('ddRefused').textContent = d.refused;

        $('ddAlerts').innerHTML = d.alerts.map(function (a) { return '<div class="alert alert-' + (a.level === 'danger' ? 'danger' : 'warning') + '">' + esc(a.text) + '</div>'; }).join('');

        $('ddGateRows').innerHTML = d.gates.length ? d.gates.map(function (g) {
            return '<tr><td><strong>' + esc(g.gate) + '</strong></td><td>' + g.devices + '</td><td><strong>' + g.admitted + '</strong></td><td>' + g.refused + '</td><td>' + g.offline_scans + '</td><td>' + esc((g.last_scan || '').slice(11, 19)) + '</td></tr>';
        }).join('') : '<tr><td colspan="6" class="text-muted">No scans yet.</td></tr>';

        var max = Math.max.apply(null, d.buckets.concat([1]));
        $('ddBars').innerHTML = d.buckets.map(function (n) { return '<span title="' + n + '" style="height:' + Math.max(2, Math.round(n / max * 100)) + '%"></span>'; }).join('');

        $('ddDevices').innerHTML = d.devices.length ? d.devices.map(function (x) {
            var colour = x.silent ? '#b42335' : (x.queued > 0 ? '#c98a00' : '#2e9e6b');
            var state = x.silent ? 'silent for ' + (x.ago >= 120 ? Math.floor(x.ago / 60) + ' min' : x.ago + ' s') : (x.queued > 0 ? 'sending ' + x.queued + ' saved scans' : 'connected');
            return '<div class="d-flex justify-content-between py-1 border-bottom"><span><span class="dd-dot" style="background:' + colour + '"></span><strong>' + esc(x.gate) + '</strong></span><span class="text-muted">' + esc(state) + '</span></div>';
        }).join('') : '<span class="text-muted">No phones have reported yet. Open the scanner on a phone and give it a gate name.</span>';

        $('ddFeed').innerHTML = d.recent.length ? d.recent.map(function (r) {
            var colour = r.accepted ? '#2e9e6b' : '#b42335';
            return '<li><span class="text-muted">' + esc(r.at) + '</span><span class="dd-gatecol text-truncate">' + esc(r.gate) + '</span><span class="text-truncate">' + esc(r.name || '-') + (r.label ? ' <span class="text-muted">· ' + esc(r.label) + '</span>' : '') + (r.offline ? ' <span class="badge bg-secondary">offline</span>' : '') + '</span><span style="color:' + colour + ';font-weight:700">' + esc(statusText[r.status] || r.status) + '</span></li>';
        }).join('') : '<li class="text-muted">Nothing scanned yet.</li>';
        $('ddStamp').textContent = 'Updated ' + d.server_time + '. Refreshes every 5 seconds.';
    }

    function tick() {
        if (document.hidden) { return; }
        fetch('door_dashboard_data.php?kind=' + KIND + '&id=' + ID, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (r.status === 401) { location.href = 'login.php'; throw new Error('login'); } return r.json(); })
            .then(function (d) { if (!d.ok) { throw new Error(d.message || 'error'); } failures = 0; render(d); })
            .catch(function (e) { failures++; if (failures >= 2) { $('ddStamp').innerHTML = '<span class="text-danger"><strong>Connection lost.</strong> Showing the last numbers. Retrying...</span>'; } });
    }
    tick();
    setInterval(tick, 5000);
    document.addEventListener('visibilitychange', tick);
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
