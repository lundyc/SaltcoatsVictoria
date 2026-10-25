<?php

declare(strict_types=1);

// Club Shop — shipping zones & delivery methods. Hub admin only.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('shop');
require_once __DIR__ . '/lib/audit.php';

shop_ensure_schema($pdo);
$isAdmin = hub_auth_is_admin();
$self = '/admin/shop_shipping.php';

if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        header('Location: ' . $self . '?e=' . rawurlencode('Session expired — try again.'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $zoneId = (int) ($_POST['zone_id'] ?? 0);
    try {
        if ($action === 'save_zone') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Zone name is required.');
            }
            $valid = shop_countries();
            $codes = [];
            foreach ((array) ($_POST['countries'] ?? []) as $c) {
                $c = strtoupper(trim((string) $c));
                if (isset($valid[$c])) {
                    $codes[$c] = $c;
                }
            }
            if (!empty($_POST['everywhere'])) {
                $codes = ['*' => '*'];
            }
            if (!$codes) {
                throw new RuntimeException('Add at least one country, or tick "Everywhere else".');
            }
            $params = [
                ':n' => mb_substr($name, 0, 100),
                ':c' => implode(',', $codes),
                ':p' => trim((string) ($_POST['postcodes'] ?? '')) ?: null,
                ':s' => (int) ($_POST['sort_order'] ?? 0),
                ':a' => !empty($_POST['is_active']) ? 1 : 0,
            ];
            if ($zoneId > 0) {
                $params[':id'] = $zoneId;
                $pdo->prepare('UPDATE shop_shipping_zones SET name=:n, countries=:c, postcodes=:p, sort_order=:s, is_active=:a WHERE id=:id')->execute($params);
            } else {
                $pdo->prepare('INSERT INTO shop_shipping_zones (name, countries, postcodes, sort_order, is_active) VALUES (:n, :c, :p, :s, :a)')->execute($params);
                $zoneId = (int) $pdo->lastInsertId();
            }
            auditLog($pdo, 'shop_shipping_zone_saved', 'Shipping zone "' . $name . '" (#' . $zoneId . ')');
            header('Location: ' . $self . '?zone=' . $zoneId . '&m=' . rawurlencode('Zone saved.'));
            exit;
        }
        if ($action === 'delete_zone' && $zoneId > 0) {
            $pdo->prepare('DELETE FROM shop_shipping_zones WHERE id = :id')->execute([':id' => $zoneId]);
            auditLog($pdo, 'shop_shipping_zone_deleted', 'Shipping zone #' . $zoneId);
            header('Location: ' . $self . '?m=' . rawurlencode('Zone deleted.'));
            exit;
        }
        if ($action === 'save_method' && $zoneId > 0) {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Method name is required.');
            }
            $type = in_array($_POST['method_type'] ?? '', ['flat', 'free_over', 'weight'], true) ? (string) $_POST['method_type'] : 'flat';
            $freeOver = trim((string) ($_POST['free_over'] ?? ''));
            $bands = trim((string) ($_POST['weight_bands'] ?? ''));
            if ($type === 'free_over' && $freeOver === '') {
                throw new RuntimeException('Enter the order value above which delivery is free.');
            }
            if ($type === 'weight' && !shop_parse_weight_bands($bands)) {
                throw new RuntimeException('Add at least one weight band, one per line, like "1: 3.99" (up to 1 kg costs £3.99).');
            }
            $params = [
                ':z' => $zoneId, ':n' => mb_substr($name, 0, 100), ':t' => $type,
                ':c' => max(0, round((float) ($_POST['cost'] ?? 0), 2)),
                ':f' => $type === 'free_over' ? max(0, round((float) $freeOver, 2)) : null,
                ':w' => $type === 'weight' ? $bands : null,
                ':s' => (int) ($_POST['sort_order'] ?? 0),
                ':a' => !empty($_POST['is_active']) ? 1 : 0,
            ];
            $methodId = (int) ($_POST['id'] ?? 0);
            if ($methodId > 0) {
                $params[':id'] = $methodId;
                $pdo->prepare('UPDATE shop_shipping_methods SET zone_id=:z, name=:n, method_type=:t, cost=:c, free_over=:f, weight_bands=:w, sort_order=:s, is_active=:a WHERE id=:id')->execute($params);
            } else {
                $pdo->prepare('INSERT INTO shop_shipping_methods (zone_id, name, method_type, cost, free_over, weight_bands, sort_order, is_active) VALUES (:z,:n,:t,:c,:f,:w,:s,:a)')->execute($params);
            }
            auditLog($pdo, 'shop_shipping_method_saved', 'Shipping method "' . $name . '" in zone #' . $zoneId);
            header('Location: ' . $self . '?zone=' . $zoneId . '&m=' . rawurlencode('Method saved.'));
            exit;
        }
        if ($action === 'delete_method' && $zoneId > 0) {
            $pdo->prepare('DELETE FROM shop_shipping_methods WHERE id = :id AND zone_id = :z')->execute([':id' => (int) ($_POST['id'] ?? 0), ':z' => $zoneId]);
            header('Location: ' . $self . '?zone=' . $zoneId . '&m=' . rawurlencode('Method deleted.'));
            exit;
        }
        header('Location: ' . $self);
        exit;
    } catch (Throwable $e) {
        header('Location: ' . $self . ($zoneId > 0 ? '?zone=' . $zoneId . '&' : '?') . 'e=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Shipping zones',
    'subtitle' => 'Where you deliver, and what each region pays.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';

$zones = shop_shipping_zones($pdo);
$editId = (int) ($_GET['zone'] ?? 0);
$zone = null;
foreach ($zones as $z) {
    if ((int) $z['id'] === $editId) {
        $zone = $z;
    }
}
$isNew = isset($_GET['new']);
$editMethodId = (int) ($_GET['method'] ?? 0);
$editMethod = null;
if ($zone) {
    foreach ($zone['methods'] as $m) {
        if ((int) $m['id'] === $editMethodId) {
            $editMethod = $m;
        }
    }
}
$zoneCodes = $zone ? array_filter(array_map('trim', explode(',', (string) $zone['countries']))) : [];
$zoneEverywhere = in_array('*', $zoneCodes, true);
$countries = shop_countries();
$typeLabels = ['flat' => 'Flat rate', 'free_over' => 'Free over a basket value', 'weight' => 'By total weight'];
$deliveryOn = shop_delivery_enabled($pdo);

$describe = static function (array $z) use ($countries): string {
    $codes = array_filter(array_map('trim', explode(',', (string) $z['countries'])));
    if (in_array('*', $codes, true)) {
        return 'Everywhere not matched above';
    }
    $names = array_map(static fn(string $c): string => $countries[$c] ?? $c, array_slice($codes, 0, 3));
    $more = count($codes) - 3;
    $txt = implode(', ', $names) . ($more > 0 ? ' +' . $more . ' more' : '');
    return $txt . (trim((string) ($z['postcodes'] ?? '')) !== '' ? ' (postcodes: ' . trim(preg_replace('/\s+/', ' ', (string) $z['postcodes'])) . ')' : '');
};
$describeMethod = static function (array $m): string {
    return match ((string) $m['method_type']) {
        'free_over' => gbp((float) $m['cost']) . ', free over ' . gbp((float) $m['free_over']),
        'weight' => 'by weight (' . count(shop_parse_weight_bands((string) $m['weight_bands'])) . ' bands)',
        default => gbp((float) $m['cost']) . ' flat',
    };
};
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <a href="/admin/shop_settings.php">Settings</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Shipping zones</span></nav>

    <?php if (isset($_GET['m'])): ?><div class="alert alert-success"><?= h((string) $_GET['m']) ?></div><?php endif; ?>
    <?php if (isset($_GET['e'])): ?><div class="alert alert-danger"><?= h((string) $_GET['e']) ?></div><?php endif; ?>
    <?php if (!$deliveryOn): ?>
        <div class="alert alert-secondary">Delivery is currently <strong>switched off</strong>, so customers only see collection. Turn it on under <a href="/admin/shop_settings.php">Shop settings</a> when your zones are ready.</div>
    <?php endif; ?>

    <div class="alert alert-light border small">
        A <strong>zone</strong> is a region. Customers enter their country and postcode at checkout and are placed in the <strong>first matching zone</strong> (lowest order number first),
        then choose from that zone's delivery methods. Put specific zones (like a few postcodes) before wider ones. Shipping class surcharges are added on top.
        Collection from the club is always free and isn't part of any zone.
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <section class="card hub-panel mb-3">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <strong>Zones</strong>
                    <a class="btn btn-sm btn-dark" href="<?= $self ?>?new=1">Add zone</a>
                </div>
                <div class="list-group list-group-flush">
                    <?php if (!$zones): ?><div class="list-group-item text-muted">No zones yet.</div><?php endif; ?>
                    <?php foreach ($zones as $z): ?>
                        <a class="list-group-item list-group-item-action <?= $zone && (int) $zone['id'] === (int) $z['id'] ? 'active' : '' ?>" href="<?= $self ?>?zone=<?= (int) $z['id'] ?>">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <span><strong><?= h((string) $z['name']) ?></strong>
                                    <?php if ((int) $z['is_active'] !== 1): ?><span class="badge text-bg-secondary ms-1">Off</span><?php endif; ?>
                                    <br><small class="<?= $zone && (int) $zone['id'] === (int) $z['id'] ? 'text-white-50' : 'text-muted' ?>"><?= h($describe($z)) ?></small></span>
                                <small class="text-nowrap"><?= count($z['methods']) ?> method<?= count($z['methods']) === 1 ? '' : 's' ?></small>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <p class="small"><a href="/admin/shop_shipping_classes.php"><i class="fa-solid fa-boxes-stacked me-1" aria-hidden="true"></i>Shipping classes &amp; surcharges</a></p>
        </div>

        <div class="col-lg-7">
            <?php if ($zone || $isNew): ?>
            <section class="card hub-panel p-3 mb-3">
                <h2 class="h6 fw-bold text-uppercase text-muted"><?= $zone ? 'Edit zone' : 'New zone' ?></h2>
                <form method="post" id="zoneForm" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_zone">
                    <?php if ($zone): ?><input type="hidden" name="zone_id" value="<?= (int) $zone['id'] ?>"><?php endif; ?>
                    <div class="col-md-8"><label class="form-label" for="z_name">Zone name</label>
                        <input class="form-control" id="z_name" name="name" required value="<?= h((string) ($zone['name'] ?? '')) ?>" placeholder="e.g. Ayrshire, Rest of UK, Europe"></div>
                    <div class="col-md-4"><label class="form-label" for="z_sort">Order</label>
                        <input class="form-control" id="z_sort" type="number" name="sort_order" value="<?= (int) ($zone['sort_order'] ?? (count($zones) + 1) * 10) ?>"></div>

                    <div class="col-12">
                        <label class="form-label">Regions</label>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="z_all" name="everywhere" value="1" <?= $zoneEverywhere ? 'checked' : '' ?>>
                            <label class="form-check-label" for="z_all">Everywhere else (any country not matched by an earlier zone)</label>
                        </div>
                        <div id="countryPicker" <?= $zoneEverywhere ? 'hidden' : '' ?>>
                            <div class="position-relative">
                                <input type="text" class="form-control" id="countrySearch" placeholder="Search for a country&hellip;" autocomplete="off" aria-label="Search for a country">
                                <div class="list-group position-absolute w-100 shadow-sm" id="countryResults" style="z-index:20;max-height:240px;overflow:auto" hidden></div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-add-set="uk">+ United Kingdom</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-add-set="europe">+ All of Europe</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-clear-countries>Clear</button>
                            </div>
                            <div class="d-flex flex-wrap gap-1 mt-2" id="countryChips"></div>
                        </div>
                    </div>

                    <div class="col-12"><label class="form-label" for="z_pc">UK postcode areas <span class="text-muted small">(optional)</span></label>
                        <textarea class="form-control" id="z_pc" name="postcodes" rows="2" placeholder="KA, G, PA, DG"><?= h((string) ($zone['postcodes'] ?? '')) ?></textarea>
                        <div class="form-text">Only applies to UK addresses in this zone: leave blank to match the whole UK, or list postcode starts (e.g. <code>KA</code> for Ayrshire, <code>KA21</code> for one district) separated by commas or spaces.</div></div>
                    <div class="col-12 d-flex flex-wrap align-items-center gap-3">
                        <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" id="z_active" name="is_active" value="1" <?= (int) ($zone['is_active'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="z_active">Active</label></div>
                        <button class="btn btn-dark" type="submit"><?= $zone ? 'Save zone' : 'Add zone' ?></button>
                        <a class="btn btn-outline-secondary" href="<?= $self ?>">Cancel</a>
                        <?php if ($zone): ?>
                            <button class="btn btn-link text-danger ms-auto" type="submit" name="action" value="delete_zone" formnovalidate
                                    onclick="return confirm('Delete this zone and its delivery methods?');">Delete zone</button>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <?php if ($zone): ?>
            <section class="card hub-panel mb-3">
                <div class="card-header bg-transparent"><strong>Delivery methods in &ldquo;<?= h((string) $zone['name']) ?>&rdquo;</strong></div>
                <table class="table align-middle mb-0 hub-data-table hub-data-table--responsive">
                    <thead><tr><th>Method</th><th>Price</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$zone['methods']): ?><tr><td colspan="4" class="text-muted text-center py-3">No methods yet &mdash; customers in this zone can't choose delivery.</td></tr><?php endif; ?>
                    <?php foreach ($zone['methods'] as $m): ?>
                        <tr>
                            <td data-label="Method" class="fw-bold"><?= h((string) $m['name']) ?><div class="small text-muted fw-normal"><?= h($typeLabels[(string) $m['method_type']] ?? '') ?></div></td>
                            <td data-label="Price"><?= h($describeMethod($m)) ?></td>
                            <td data-label="Status"><?= (int) $m['is_active'] === 1 ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Off</span>' ?></td>
                            <td data-label="Actions" class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= $self ?>?zone=<?= (int) $zone['id'] ?>&amp;method=<?= (int) $m['id'] ?>#methodForm">Edit</a>
                                <form method="post" class="d-inline" data-confirm="Delete this method?" data-confirm-action="Delete">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete_method"><input type="hidden" name="zone_id" value="<?= (int) $zone['id'] ?>"><input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit">×</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>

            <section class="card hub-panel p-3" id="methodForm">
                <h2 class="h6 fw-bold text-uppercase text-muted"><?= $editMethod ? 'Edit delivery method' : 'Add delivery method' ?></h2>
                <form method="post" class="row g-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_method">
                    <input type="hidden" name="zone_id" value="<?= (int) $zone['id'] ?>">
                    <?php if ($editMethod): ?><input type="hidden" name="id" value="<?= (int) $editMethod['id'] ?>"><?php endif; ?>
                    <div class="col-md-6"><label class="form-label" for="m_name">Name shown to customers</label>
                        <input class="form-control" id="m_name" name="name" required value="<?= h((string) ($editMethod['name'] ?? '')) ?>" placeholder="e.g. Standard delivery (Evri)"></div>
                    <div class="col-md-6"><label class="form-label" for="m_type">Price rule</label>
                        <select class="form-select" id="m_type" name="method_type">
                            <?php foreach ($typeLabels as $k => $l): ?><option value="<?= $k ?>" <?= (string) ($editMethod['method_type'] ?? 'flat') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
                        </select></div>
                    <div class="col-md-4" data-for="flat free_over"><label class="form-label" for="m_cost">Price (£)</label>
                        <input class="form-control" id="m_cost" type="number" step="0.01" min="0" name="cost" value="<?= h((string) ($editMethod['cost'] ?? '0.00')) ?>"></div>
                    <div class="col-md-4" data-for="free_over"><label class="form-label" for="m_free">Free when basket is £&hellip; or more</label>
                        <input class="form-control" id="m_free" type="number" step="0.01" min="0" name="free_over" value="<?= h((string) ($editMethod['free_over'] ?? '')) ?>"></div>
                    <div class="col-12" data-for="weight"><label class="form-label" for="m_bands">Weight bands</label>
                        <textarea class="form-control font-monospace" id="m_bands" name="weight_bands" rows="5" placeholder="1: 3.99&#10;2: 4.99&#10;5: 7.49&#10;10: 11.99"><?= h((string) ($editMethod['weight_bands'] ?? '')) ?></textarea>
                        <div class="form-text">One band per line as <code>up-to-kg: price</code>. An order up to 1 kg pays the first price, up to 2 kg the second, and so on. Heavier than the last band &rarr; this method isn't offered. Uses the weight set on each product (Shipping tab).</div></div>
                    <div class="col-md-4"><label class="form-label" for="m_sort">Order</label>
                        <input class="form-control" id="m_sort" type="number" name="sort_order" value="<?= (int) ($editMethod['sort_order'] ?? (count($zone['methods']) + 1) * 10) ?>"></div>
                    <div class="col-md-8 d-flex align-items-end"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="m_active" name="is_active" value="1" <?= (int) ($editMethod['is_active'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="m_active">Active</label></div></div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-dark" type="submit"><?= $editMethod ? 'Save method' : 'Add method' ?></button>
                        <?php if ($editMethod): ?><a class="btn btn-outline-secondary" href="<?= $self ?>?zone=<?= (int) $zone['id'] ?>">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </section>
            <?php endif; ?>
            <?php else: ?>
                <div class="alert alert-info mb-0">Pick a zone on the left to edit it, or <a href="<?= $self ?>?new=1">add a new one</a>.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
(function () {
    var typeSel = document.getElementById('m_type');
    function syncType() {
        if (!typeSel) return;
        document.querySelectorAll('[data-for]').forEach(function (el) { el.hidden = el.dataset.for.split(' ').indexOf(typeSel.value) === -1; });
    }
    if (typeSel) { typeSel.addEventListener('change', syncType); syncType(); }

    var form = document.getElementById('zoneForm');
    if (!form) return;
    var all = <?= json_encode($countries, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var europe = <?= json_encode(shop_europe_codes()) ?>;
    var chosen = <?= json_encode(array_values(array_diff($zoneCodes, ['*']))) ?>;
    var search = document.getElementById('countrySearch'), results = document.getElementById('countryResults'), chips = document.getElementById('countryChips');
    var picker = document.getElementById('countryPicker'), everywhere = document.getElementById('z_all');
    var active = -1, shown = [];
    function drawChips() {
        chips.innerHTML = '';
        chosen.forEach(function (code) {
            var c = document.createElement('span');
            c.className = 'badge text-bg-light border d-inline-flex align-items-center gap-2 fw-normal py-2';
            c.innerHTML = '<input type="hidden" name="countries[]" value="' + code + '"><span></span><button type="button" class="btn-close" style="font-size:.6rem" aria-label="Remove"></button>';
            c.querySelector('span').textContent = all[code] || code;
            c.querySelector('button').addEventListener('click', function () { chosen = chosen.filter(function (x) { return x !== code; }); drawChips(); });
            chips.appendChild(c);
        });
        if (!chosen.length) { chips.innerHTML = '<span class="text-muted small">No countries added yet.</span>'; }
    }
    function add(code) { if (all[code] && chosen.indexOf(code) === -1) chosen.push(code); }
    function closeResults() { results.hidden = true; results.innerHTML = ''; active = -1; shown = []; }
    function highlight() { Array.prototype.forEach.call(results.children, function (el, i) { el.classList.toggle('active', i === active); }); }
    function doSearch() {
        var q = search.value.trim().toLowerCase();
        if (!q) { closeResults(); return; }
        shown = Object.keys(all).filter(function (c) { return chosen.indexOf(c) === -1 && (all[c].toLowerCase().indexOf(q) !== -1 || c.toLowerCase() === q); }).slice(0, 8);
        results.innerHTML = '';
        shown.forEach(function (code) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'list-group-item list-group-item-action'; b.textContent = all[code];
            b.addEventListener('mousedown', function (e) { e.preventDefault(); add(code); search.value = ''; closeResults(); drawChips(); });
            results.appendChild(b);
        });
        if (!shown.length) { var n = document.createElement('div'); n.className = 'list-group-item text-muted small'; n.textContent = 'No matching countries'; results.appendChild(n); }
        active = shown.length ? 0 : -1; highlight(); results.hidden = false;
    }
    search.addEventListener('input', doSearch);
    search.addEventListener('blur', function () { setTimeout(closeResults, 100); });
    search.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' && shown.length) { e.preventDefault(); active = (active + 1) % shown.length; highlight(); }
        else if (e.key === 'ArrowUp' && shown.length) { e.preventDefault(); active = (active - 1 + shown.length) % shown.length; highlight(); }
        else if (e.key === 'Enter') { e.preventDefault(); if (active >= 0 && shown[active]) { add(shown[active]); search.value = ''; closeResults(); drawChips(); } }
        else if (e.key === 'Escape') { closeResults(); }
    });
    document.querySelectorAll('[data-add-set]').forEach(function (b) {
        b.addEventListener('click', function () { (b.dataset.addSet === 'europe' ? europe : ['GB', 'JE', 'GG', 'IM']).forEach(add); drawChips(); });
    });
    document.querySelector('[data-clear-countries]').addEventListener('click', function () { chosen = []; drawChips(); });
    everywhere.addEventListener('change', function () { picker.hidden = everywhere.checked; });
    drawChips();
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
