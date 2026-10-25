<?php
$pageHero = [
    'eyebrow' => 'Match Day',
    'title' => 'Match report import',
    'subtitle' => 'Drop in COMET match report PDFs. Each one is read, its details are saved, and the PDF is deleted straight away.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
if (!hub_auth_has_capability('matchday')) {
    echo '<div class="alert alert-danger">You do not have access to this page.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}
$mrEsc = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$mrSeasons = $pdo->query('SELECT id, name, is_current FROM seasons WHERE id IN (1,2,15) ORDER BY start_date DESC')->fetchAll(PDO::FETCH_ASSOC);
$mrSeasonId = (int)($_GET['season_id'] ?? 0);
$mrHighlight = (int)($_GET['fixture_id'] ?? 0);
if ($mrHighlight && !$mrSeasonId) { $q = $pdo->prepare('SELECT season_id FROM match_fixtures WHERE id = ?'); $q->execute([$mrHighlight]); $mrSeasonId = (int)$q->fetchColumn(); }
if (!$mrSeasonId) { foreach ($mrSeasons as $sRow) { if ($sRow['is_current']) { $mrSeasonId = (int)$sRow['id']; } } }
$mrStmt = $pdo->prepare('SELECT f.id, f.match_date, f.kickoff_time, f.opponent, f.is_home, f.status, f.competition, f.full_time_home_score h, f.full_time_away_score a, l.url, l.last_fetched_at
    FROM match_fixtures f LEFT JOIN fixture_comet_links l ON l.fixture_id = f.id WHERE ' . ($mrHighlight ? 'f.id = ?' : 'f.season_id = ?') . '
    ORDER BY f.match_date >= CURDATE() DESC, CASE WHEN f.match_date >= CURDATE() THEN f.match_date END ASC, f.match_date DESC');
$mrStmt->execute([$mrHighlight ?: $mrSeasonId]);
$mrFixtures = $mrStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
        .mr-dropzone { border: 2px dashed #ced4da; border-radius: .5rem; padding: 2rem 1rem; text-align: center; cursor: pointer; transition: border-color .15s ease, background-color .15s ease; }
        .mr-dropzone:hover, .mr-dropzone:focus-visible { border-color: #86b7fe; background: #f8f9fa; outline: none; }
        .mr-dropzone.is-dragover { border-color: #0d6efd; background: #eaf2ff; }
        .mr-dropzone__icon { font-size: 1.75rem; color: #6c757d; margin-bottom: .5rem; }
        .mr-dropzone__text { color: #495057; font-size: .9rem; }
        .mr-dropzone__browse { color: #0d6efd; text-decoration: underline; }
        @media (max-width: 767.98px) {
            #mrFetchCard .table-responsive { overflow: visible; }
            #mrFetchCard table, #mrFetchCard tbody { display: block; width: 100%; }
            #mrFetchCard thead { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
            #mrFetchCard tr.mr-row { display: grid; grid-template-columns: 1fr auto; gap: .35rem .75rem; margin-bottom: .75rem; padding: .85rem; border: 1px solid rgba(75, 8, 24, .12); border-radius: .75rem; background: #fff; }
            #mrFetchCard tr.mr-row.table-warning { background: #fff3cd; }
            #mrFetchCard tr.mr-row > td { display: block; padding: 0; border: 0; background: transparent; box-shadow: none; text-align: left !important; }
            #mrFetchCard .mr-c-date { grid-column: 1; grid-row: 1; display: flex !important; gap: .5rem; align-items: baseline; font-weight: 700; }
            #mrFetchCard .mr-c-result { grid-column: 2; grid-row: 1; font-weight: 700; text-align: right !important; }
            #mrFetchCard .mr-c-match { grid-column: 1 / -1; grid-row: 2; }
            #mrFetchCard .mr-c-link { grid-column: 1 / -1; grid-row: 3; overflow-wrap: anywhere; padding-top: .35rem; border-top: 1px solid rgba(75, 8, 24, .08); }
            #mrFetchCard .mr-c-btn { grid-column: 1 / -1; grid-row: 4; }
            #mrFetchCard .mr-c-btn .btn { width: 100%; min-height: 44px; }
            #mrFetchCard tr.mr-statusrow { display: block; margin: -.4rem 0 .75rem; }
            #mrFetchCard tr.mr-statusrow[hidden] { display: none; }
            #mrFetchCard tr.mr-statusrow > td { display: block; width: 100%; }
        }
</style>
<div class="card card-body shadow-sm mb-4" id="mrFetchCard">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
            <h2 class="h5 mb-1">Fetch from COMET</h2>
            <p class="text-muted small mb-0">Each fixture&rsquo;s COMET address is kept on its match page (add it before the game). Once the match has been played, press <strong>Fetch</strong>:
                the report is downloaded, its details are saved, and the PDF is deleted straight away. Matches already played have been linked for you.</p>
        </div>
        <?php if (!$mrHighlight): ?>
        <form method="get" class="d-flex align-items-center gap-2">
            <label class="small text-muted" for="mrSeason">Season</label>
            <select id="mrSeason" name="season_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($mrSeasons as $sRow): ?><option value="<?= (int)$sRow['id'] ?>"<?= (int)$sRow['id'] === $mrSeasonId ? ' selected' : '' ?>><?= $mrEsc($sRow['name']) ?></option><?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>
    <form id="mrFetchForm" class="mt-3"><?= csrf_field() ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0 hub-data-table">
            <thead><tr><th scope="col">Date</th><th scope="col">Match</th><th scope="col">Result</th><th scope="col" style="min-width:20rem">COMET match page</th><th scope="col" class="text-end">Report</th></tr></thead>
            <tbody>
            <?php foreach ($mrFixtures as $fx): $played = $fx['status'] === 'played'; ?>
                <tr data-fixture="<?= (int)$fx['id'] ?>" class="mr-row<?= (int)$fx['id'] === $mrHighlight ? ' table-warning' : '' ?>" id="fx<?= (int)$fx['id'] ?>">
                    <td class="mr-c-date"><div><?= $mrEsc(date('j M Y', strtotime((string)$fx['match_date']))) ?></div><div class="text-muted small"><?= $fx['kickoff_time'] ? $mrEsc(substr((string)$fx['kickoff_time'], 0, 5)) : '' ?></div></td>
                    <td class="mr-c-match"><div><?= $mrEsc($fx['is_home'] ? 'Saltcoats Victoria v ' . $fx['opponent'] : $fx['opponent'] . ' v Saltcoats Victoria') ?></div><div class="text-muted small"><?= $mrEsc($fx['competition']) ?></div></td>
                    <td class="mr-c-result"><?= $played && $fx['h'] !== null ? $mrEsc($fx['h'] . '-' . $fx['a']) : '<span class="text-muted small">' . $mrEsc($played ? '' : ucfirst((string)$fx['status'])) . '</span>' ?></td>
                    <td class="mr-c-link">
                        <?php if (!empty($fx['url'])): ?>
                            <div class="small"><i class="fa-solid fa-link me-1 text-success" aria-hidden="true"></i><a href="<?= $mrEsc($fx['url']) ?>" target="_blank" rel="noopener"><?= $mrEsc(preg_replace('~^https://(www\.)?~', '', (string)$fx['url'])) ?></a></div>
                        <?php else: ?>
                            <div class="small text-muted"><i class="fa-solid fa-link-slash me-1" aria-hidden="true"></i>No COMET address yet</div>
                        <?php endif; ?>
                        <div class="small mr-linkmsg mt-1 text-muted"><span class="mr-last"><?= !empty($fx['last_fetched_at']) ? 'Last fetched ' . $mrEsc(date('j M Y H:i', strtotime((string)$fx['last_fetched_at']))) . ' &middot; ' : '' ?></span><a href="/admin/match.php?id=<?= (int)$fx['id'] ?>&amp;season_id=<?= (int)$mrSeasonId ?>"><?= empty($fx['url']) ? 'Add it on the match page' : 'Edit Link' ?></a></div>
                    </td>
                    <td class="text-end mr-c-btn"><button type="button" class="btn btn-sm btn-primary mr-fetch"<?= empty($fx['url']) ? ' disabled' : '' ?>><i class="fa-solid fa-cloud-arrow-down me-1" aria-hidden="true"></i><span>Fetch</span></button></td>
                </tr>
                <tr class="mr-statusrow" hidden><td colspan="5" class="p-0 border-0"><div class="mr-status m-2"></div></td></tr>
            <?php endforeach; ?>
            <?php if (!$mrFixtures): ?><tr><td colspan="5" class="text-muted text-center py-4"><?= $mrHighlight ? 'That fixture was not found.' : 'No fixtures for this season.' ?></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    </form>
</div>
<div class="card card-body shadow-sm mb-4">
    <h2 class="h5">Or upload match report PDFs</h2>
    <p class="text-muted small mb-3">In COMET open a match, choose <strong>Print &gt; Match report</strong> and upload the PDF here (several at once is fine).
        From each report the Hub saves the match officials, adds any squad members, goals, cards, substitutions or staff cards it doesn&rsquo;t already have, and fills in missing card reasons.
        Existing records are never changed or deleted. The PDF is not stored, and the referee&rsquo;s written incident notes are never read.</p>
    <form id="mrForm">
        <?= csrf_field() ?>
        <input type="file" id="mrFile" accept="application/pdf,.pdf" multiple class="visually-hidden">
        <div id="mrDrop" class="mr-dropzone" tabindex="0" role="button" aria-label="Upload match report PDFs">
            <div class="mr-dropzone__icon"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i></div>
            <div class="mr-dropzone__text"><strong>Drag &amp; drop</strong> match report PDFs here, or <span class="mr-dropzone__browse">click to browse</span></div>
        </div>
    </form>
    <div id="mrResults" class="mt-3 d-grid gap-2" aria-live="polite"></div>
</div>
<script>
(function () {
    var drop = document.getElementById('mrDrop'), input = document.getElementById('mrFile'), results = document.getElementById('mrResults'), form = document.getElementById('mrForm');
    var queue = [], running = false;
    function el(tag, cls, html) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (html != null) { e.innerHTML = html; } return e; }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function plural(n, w) { return n + ' ' + w + (n === 1 ? '' : 's'); }
    function uk(d) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : (d || ''); }
    function card(file) {
        var box = el('div', 'alert alert-primary d-flex align-items-start gap-3 mb-0');
        box.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin fs-5 mt-1" aria-hidden="true"></i><div class="flex-grow-1"><div class="fw-semibold">' + esc(file.name) + '</div><div class="small">Reading the report&hellip;</div></div>';
        results.prepend(box); return box;
    }
    function done(box, d) {
        var icon = box.querySelector('i'), body = box.lastElementChild;
        if (!d.ok) { box.className = 'alert alert-danger d-flex align-items-start gap-3 mb-0'; icon.className = 'fa-solid fa-circle-exclamation fs-5 mt-1'; body.lastElementChild.textContent = d.message || 'Failed.'; return; }
        var s = d.summary, bits = [];
        bits.push(plural(s.officials_total, 'official') + (s.officials_new ? ' (' + s.officials_new + ' new)' : ' (already on record)'));
        if (s.players_added) bits.push('+' + plural(s.players_added, 'squad member'));
        if (s.goals_added) bits.push('+' + plural(s.goals_added, 'goal'));
        if (s.cards_added) bits.push('+' + plural(s.cards_added, 'card'));
        if (s.staff_cards_added) bits.push('+' + plural(s.staff_cards_added, 'staff card'));
        if (s.subs_added) bits.push('+' + plural(s.subs_added, 'substitution'));
        if (s.reasons_filled) bits.push(plural(s.reasons_filled, 'card reason') + ' filled in');
        var nothing = !(s.players_added || s.goals_added || s.cards_added || s.staff_cards_added || s.subs_added || s.reasons_filled);
        box.className = 'alert alert-success d-flex align-items-start gap-3 mb-0'; icon.className = 'fa-solid fa-circle-check fs-5 mt-1';
        body.innerHTML = '<div class="fw-semibold">' + esc(s.match) + ' <span class="fw-normal text-muted">&middot; ' + esc(uk(s.date)) + (s.score ? ' &middot; ' + esc(s.score) : '') + '</span></div>' +
            '<div class="small">' + bits.map(esc).join(' &middot; ') + (nothing ? ' &middot; nothing else needed adding' : '') + '</div>' +
            (s.warnings && s.warnings.length ? '<div class="small text-warning-emphasis mt-1">' + s.warnings.map(esc).join('<br>') + '</div>' : '') +
            '';
    }
    function next() {
        if (running || !queue.length) { return; }
        running = true;
        var file = queue.shift(), box = card(file), fd = new FormData(form);
        fd.append('pdf', file);
        fetch('/admin/match_report_import_upload.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { done(box, d); })
            .catch(function () { done(box, { ok: false, message: 'Could not reach the server. Please try again.' }); })
            .then(function () { running = false; next(); });
    }
    function add(files) {
        Array.prototype.forEach.call(files, function (f) {
            if (!/\.pdf$/i.test(f.name) && f.type !== 'application/pdf') { var b = card(f); done(b, { ok: false, message: 'Only PDF files can be imported.' }); return; }
            queue.push(f);
        });
        input.value = ''; next();
    }
    drop.addEventListener('click', function () { input.click(); });
    drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
    input.addEventListener('change', function () { if (input.files.length) { add(input.files); } });
    ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('is-dragover'); }); });
    ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.remove('is-dragover'); }); });
    drop.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) { add(e.dataTransfer.files); } });
})();
</script>
<script>
(function () {
    var card = document.getElementById('mrFetchCard'); if (!card) { return; }
    var form = document.getElementById('mrFetchForm');
    function post(fields) { var fd = new FormData(form); Object.keys(fields).forEach(function (k) { fd.set(k, fields[k]); }); return fetch('/admin/match_report_fetch.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) { return r.json(); }); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function plural(n, w) { return n + ' ' + w + (n === 1 ? '' : 's'); }
    function uk(d) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(d || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : (d || ''); }
    function rowOf(el) { return el.closest('tr.mr-row'); }
    function statusBox(row) { var sr = row.nextElementSibling; sr.hidden = false; return sr.querySelector('.mr-status'); }
    function setStatus(row, kind, head, sub) {
        var box = statusBox(row), icons = { working: 'fa-circle-notch fa-spin', success: 'fa-circle-check', error: 'fa-circle-exclamation' }, cls = { working: 'alert-primary', success: 'alert-success', error: 'alert-danger' };
        box.className = 'mr-status m-2 alert d-flex align-items-start gap-3 mb-2 ' + cls[kind];
        box.innerHTML = '<i class="fa-solid ' + icons[kind] + ' fs-5 mt-1" aria-hidden="true"></i><div><div class="fw-semibold">' + esc(head) + '</div>' + (sub ? '<div class="small">' + sub + '</div>' : '') + '</div>';
    }
    function linkMsg(row, text, bad) { var m = row.querySelector('.mr-linkmsg'); m.textContent = text; m.className = 'small mr-linkmsg mt-1 ' + (bad ? 'text-danger' : 'text-muted'); }
    function describe(s) {
        var bits = [plural(s.officials_total, 'official') + (s.officials_new ? ' (' + s.officials_new + ' new)' : ' (already on record)')];
        if (s.players_added) bits.push('+' + plural(s.players_added, 'squad member'));
        if (s.goals_added) bits.push('+' + plural(s.goals_added, 'goal'));
        if (s.cards_added) bits.push('+' + plural(s.cards_added, 'card'));
        if (s.staff_cards_added) bits.push('+' + plural(s.staff_cards_added, 'staff card'));
        if (s.subs_added) bits.push('+' + plural(s.subs_added, 'substitution'));
        if (s.reasons_filled) bits.push(plural(s.reasons_filled, 'card reason') + ' filled in');
        if (bits.length === 1) bits.push('nothing else needed adding');
        return bits.map(esc).join(' &middot; ') + (s.warnings && s.warnings.length ? '<div class="text-warning-emphasis mt-1">' + s.warnings.map(esc).join('<br>') + '</div>' : '');
    }
    function poll(row, id, btn, tries) {
        fetch('/admin/match_report_fetch.php?id=' + id, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) { return r.json(); }).then(function (d) {
            var st = d.state || {};
            if (st.phase === 'done') {
                btn.disabled = false; btn.querySelector('span').textContent = 'Fetch'; btn.querySelector('i').className = 'fa-solid fa-cloud-arrow-down me-1';
                if (st.ok) { setStatus(row, 'success', st.summary.match + ' \u00b7 ' + uk(st.summary.date) + (st.summary.score ? ' \u00b7 ' + st.summary.score : ''), describe(st.summary)); var ls = row.querySelector('.mr-last'); if (ls) { ls.textContent = 'Last fetched just now \u00b7 '; } }
                else { setStatus(row, 'error', 'Could not fetch the report', esc(st.message || 'Unknown error.')); }
                return;
            }
            var labels = { queued: ['Waiting to start\u2026', 'This starts within a minute.'], connecting: ['Connecting to COMET\u2026', ''], downloading: ['Downloading the report\u2026', ''], reading: ['Reading the report\u2026', 'Saving the details; the PDF is deleted straight after.'] };
            var l = labels[st.phase] || labels.queued; setStatus(row, 'working', l[0], l[1]);
            if (tries > 150) { btn.disabled = false; setStatus(row, 'error', 'Taking longer than expected', 'Check back in a few minutes.'); return; }
            setTimeout(function () { poll(row, id, btn, tries + 1); }, 3000);
        }).catch(function () { setTimeout(function () { poll(row, id, btn, tries + 1); }, 4000); });
    }
    card.addEventListener('click', function (e) {
        var row = rowOf(e.target); if (!row) { return; }
        var fid = row.getAttribute('data-fixture'), fetchBtn = row.querySelector('.mr-fetch');
        if (e.target.closest('.mr-save')) {
            post({ action: 'save_link', fixture_id: fid, url: row.querySelector('.mr-url').value }).then(function (d) {
                if (d.ok) { row.querySelector('.mr-url').value = d.url; row.querySelector('.mr-clear').hidden = false; fetchBtn.disabled = false; linkMsg(row, 'Link saved.', false); }
                else { linkMsg(row, d.message || 'Could not save.', true); }
            });
        } else if (e.target.closest('.mr-clear')) {
            post({ action: 'clear_link', fixture_id: fid }).then(function (d) { if (d.ok) { row.querySelector('.mr-url').value = ''; row.querySelector('.mr-clear').hidden = true; fetchBtn.disabled = true; linkMsg(row, 'Link removed.', false); } });
        } else if (e.target.closest('.mr-fetch')) {
            fetchBtn.disabled = true; fetchBtn.querySelector('span').textContent = 'Fetching\u2026'; fetchBtn.querySelector('i').className = 'fa-solid fa-circle-notch fa-spin me-1';
            setStatus(row, 'working', 'Sending request\u2026', '');
            post({ action: 'fetch', fixture_id: fid }).then(function (d) {
                if (d.ok) { poll(row, d.id, fetchBtn, 0); }
                else { fetchBtn.disabled = false; fetchBtn.querySelector('span').textContent = 'Fetch'; fetchBtn.querySelector('i').className = 'fa-solid fa-cloud-arrow-down me-1'; setStatus(row, 'error', 'Could not start', esc(d.message || 'Please try again.')); }
            }).catch(function () { fetchBtn.disabled = false; setStatus(row, 'error', 'Could not reach the server', 'Please try again.'); });
        }
    });
    card.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.classList && e.target.classList.contains('mr-url')) { e.preventDefault(); var b = rowOf(e.target).querySelector('.mr-save'); if (b) { b.click(); } } });
    var hl = card.querySelector('tr.table-warning'); if (hl) { setTimeout(function () { hl.scrollIntoView({ block: 'center', behavior: 'smooth' }); }, 200); }
})();
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
