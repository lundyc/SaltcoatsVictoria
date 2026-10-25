<?php
declare(strict_types=1);

/** Event stats (also the scanner overview) in the scanner's own style: live entry numbers for today's match or event (data from admin/door_dashboard_data.php). */
require_once __DIR__ . '/_sub.php';
require_once __DIR__ . '/../admin/lib/events_scan.php';
require_once __DIR__ . '/../admin/lib/door_dashboard.php';
if (!hub_auth_has_any_capability(['tickets_ops', 'events_view'])) {
    http_response_code(403);
    exit('You do not have permission to view this page.');
}

$targets = door_targets($pdo);
$kind = ($_GET['kind'] ?? '') === 'event' ? 'event' : 'match';
$id = (int) ($_GET['id'] ?? 0);
$current = null;
foreach ($targets as $t) {
    if ($t['kind'] === $kind && $t['id'] === $id) {
        $current = $t;
    }
}
$current ??= $targets[0] ?? null;

scan_sub_open('Event stats', $current ? $current['title'] . ' · ' . $current['sub'] : '');
if (!$current) {
    echo '<div class="sub-panel sub-empty"><strong>No match or event today.</strong><br>Stats show for a home match or event on the day it is played.</div>';
    scan_sub_close();
    return;
}
// One match or event per page: opened from that card's ... menu, no switcher.
?>
<div id="alerts"></div>
<div class="sub-grid">
    <div class="sub-stat is-good"><span>People in</span><strong id="in">–</strong><small id="of"></small></div>
    <div class="sub-stat"><span>Arriving now</span><strong><span id="rate">–</span> <small style="font-size:.9rem;font-weight:600">per min</small></strong><small id="rate10"></small></div>
    <div class="sub-stat"><span>Total scans</span><strong id="total">–</strong><small>all attempts</small></div>
    <div class="sub-stat is-good"><span>Valid</span><strong id="valid">–</strong><small>accepted scans</small></div>
    <div class="sub-stat is-bad" style="grid-column:1 / -1"><span>Refused scans</span><strong id="refused">–</strong><small>unknown, cancelled or already used elsewhere</small></div>
</div>
<div class="sub-panel">
    <h2>Entry progress</h2>
    <div class="sub-progress"><div id="bar"></div></div>
</div>
<div class="sub-panel">
    <h2>Arrivals, last hour <small class="text-muted fw-normal">(per 5 minutes)</small></h2>
    <div class="sub-bars" id="bars" aria-hidden="true"></div>
    <div class="sub-bartext"><span>60 min ago</span><span>now</span></div>
</div>
<div class="sub-panel">
    <h2>Latest scans</h2>
    <div id="feed"><p class="sub-empty mb-0">Loading…</p></div>
</div>
<p class="sub-note" id="stamp"></p>
<script>
(() => {
    const KIND = <?= json_encode($current['kind']) ?>, ID = <?= (int) $current['id'] ?>;
    const $ = (i) => document.getElementById(i);
    const esc = (t) => String(t == null ? '' : t).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const statusText = { checked_in: 'In', already_scanned: 'Already used', not_active: 'Cancelled', invalid_token: 'Unknown code', not_found: 'Unknown code', wrong_fixture: 'Other match', wrong_event: 'Other event', wrong_ticket_type: 'Other ticket', individual_required: 'Order link' };
    let failures = 0;
    const render = (d) => {
        const total = d.capacity > 0 ? d.capacity : d.expected;
        $('in').textContent = d.admitted;
        $('of').textContent = d.capacity > 0 ? 'of ' + d.capacity + ' capacity' : (d.expected ? 'of ' + d.expected + ' tickets' : '');
        $('bar').style.width = total > 0 ? Math.min(100, Math.round(d.admitted / total * 100)) + '%' : '0%';
        $('rate').textContent = d.per_minute;
        $('rate10').textContent = d.rate10 + ' in the last 10 min';
        $('refused').textContent = d.refused;
        $('total').textContent = d.scans_total;
        $('valid').textContent = d.scans_valid;
        $('alerts').innerHTML = d.alerts.map((a) => '<div class="sub-alert' + (a.level === 'danger' ? ' is-danger' : '') + '">' + esc(a.text) + '</div>').join('');
        const max = Math.max(...d.buckets, 1);
        $('bars').innerHTML = d.buckets.map((n) => '<span title="' + n + '" style="height:' + Math.max(2, Math.round(n / max * 100)) + '%"></span>').join('');
        $('feed').innerHTML = d.recent.length ? d.recent.map((r) => '<div class="sub-row ' + (r.accepted ? 'is-success' : 'is-danger') + '"><div><strong>' + esc(r.name || 'Unknown ticket') + '</strong><span>' + esc(statusText[r.status] || r.status) + (r.label ? ' · ' + esc(r.label) : '') + (r.offline ? ' · offline' : '') + '</span></div><div class="sub-time">' + esc(String(r.at).slice(0, 5)) + '</div></div>').join('') : '<p class="sub-empty mb-0">Nothing scanned yet.</p>';
        $('stamp').textContent = 'Updated ' + d.server_time + '. Refreshes every 5 seconds.';
    };
    const tick = () => {
        if (document.hidden) return;
        fetch('/admin/door_dashboard_data.php?kind=' + KIND + '&id=' + ID, { credentials: 'same-origin', cache: 'no-store' })
            .then((r) => { if (r.status === 401) { location.href = '/admin/login.php?next=' + encodeURIComponent(location.pathname + location.search); throw new Error('login'); } return r.json(); })
            .then((d) => { if (!d.ok) throw new Error(d.message || 'error'); failures = 0; render(d); })
            .catch(() => { failures++; if (failures >= 2) $('stamp').textContent = 'Connection lost. Showing the last numbers, retrying…'; });
    };
    tick();
    setInterval(tick, 5000);
    document.addEventListener('visibilitychange', tick);
})();
</script>
<?php scan_sub_close();
