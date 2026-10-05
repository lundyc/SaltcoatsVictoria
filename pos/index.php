<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/db.php';
require_once __DIR__ . '/../admin/lib/functions.php';
require_once __DIR__ . '/../admin/lib/pos.php';
require_once __DIR__ . '/../admin/lib/pos_trading_days.php';

pos_ensure_schema($pdo);
pos_trading_day_ensure_schema($pdo);
$actor = pos_require_actor($pdo);
$activeTradingDay = pos_trading_day_active($pdo);
if (!$activeTradingDay) {
    if (pos_actor_is_manager($pdo)) {
        header('Location: /admin/pos_overview.php?day_closed=1');
        exit;
    }
    http_response_code(423);
    exit('The POS day has not been opened. Ask a manager to open the POS day before using the till.');
}
$locations = pos_locations_for_actor($pdo, $actor);
$requestedLocationId = (int) ($_GET['location_id'] ?? 0);
if ($requestedLocationId <= 0 && count($locations) > 1) {
    header('Location: /admin/pos_overview.php?select_till=1');
    exit;
}
$locationId = $requestedLocationId > 0 ? $requestedLocationId : (int) ($locations[0]['id'] ?? 0);
$location = pos_location($pdo, $locationId);
if (!$location && $locations) {
    if (count($locations) > 1) {
        header('Location: /admin/pos_overview.php?select_till=1');
        exit;
    }
    $location = $locations[0];
    $locationId = (int) $location['id'];
}
$activeTillSession = $locationId > 0 ? pos_till_session_active($pdo, (int) $activeTradingDay['id'], $locationId) : null;
if ($locationId > 0 && !$activeTillSession) {
    if (pos_actor_is_manager($pdo)) {
        header('Location: /admin/pos_overview.php?till_closed=1');
        exit;
    }
    http_response_code(423);
    exit('This till has not been opened for the current POS day.');
}
$products = $locationId > 0 ? pos_products_for_location($pdo, $locationId) : [];
$categories = [];
foreach ($products as $product) {
    $categories[(string) $product['category_name']][] = $product;
}
$summary = pos_sales_summary($pdo);
$csrfToken = (string) ($_SESSION['csrf_token'] ?? '');
$canViewFinancials = pos_actor_is_manager($pdo);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#4b0818">
    <title>POS - Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
    <style>
        :root { --brand:#4b0818; --brand-2:#8a1538; --gold:#ba8f2a; --paper:#f7efe4; --ink:#24151a; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; background:var(--paper); color:var(--ink); }
        .pos-app { min-height:100vh; display:grid; grid-template-rows:auto 1fr; }
        .pos-topbar { position:sticky; top:0; z-index:20; display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:.75rem 1rem; background:linear-gradient(135deg,var(--brand),var(--brand-2)); color:#fff; box-shadow:0 10px 30px rgba(36,21,26,.22); }
        .pos-brand { display:flex; align-items:center; gap:.75rem; font-weight:900; font-size:1.1rem; }
        .pos-brand__menu { position:relative; }
        .pos-brand__icon { width:42px; height:42px; display:grid; place-items:center; border:0; border-radius:.8rem; background:rgba(255,255,255,.12); color:#fff; }
        .pos-brand__icon:focus-visible { outline:3px solid rgba(255,255,255,.72); outline-offset:3px; }
        .pos-topbar a { color:#fff; text-decoration:none; }
        .pos-current-location { margin-left:auto; color:rgba(255,255,255,.78); font-weight:850; }
        .pos-menu { position:absolute; top:calc(100% + .65rem); left:0; width:min(330px, calc(100vw - 2rem)); display:none; gap:.75rem; padding:.9rem; border:1px solid rgba(255,255,255,.14); border-radius:1rem; background:#212a31; box-shadow:0 22px 60px rgba(0,0,0,.32); }
        .pos-menu.is-open { display:grid; }
        .pos-menu__label { color:rgba(247,239,228,.68); font-size:.72rem; font-weight:900; letter-spacing:.08em; text-transform:uppercase; }
        .pos-menu__identity { display:flex; align-items:center; gap:.6rem; color:#f7efe4; font-weight:900; }
        .pos-menu__identity i { color:#e0b42a; }
        .pos-location-select { display:grid; gap:.35rem; color:#f7efe4; font-weight:900; }
        .pos-manage { display:grid; gap:.45rem; }
        .pos-manage a, .pos-manage button { display:flex; align-items:center; justify-content:space-between; border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.045); color:#f7efe4; border-radius:.75rem; padding:.65rem .75rem; font-weight:850; text-decoration:none; }
        .pos-manage a:hover, .pos-manage a:focus { color:#e0b42a; background:rgba(255,255,255,.08); }
        .pos-manage button { width:100%; cursor:pointer; }
        .pos-manage .danger { color:#ffd8df; border-color:rgba(255,216,223,.2); }
        .pos-layout { display:grid; grid-template-columns:minmax(0,1fr) 390px; gap:1rem; padding:1rem; align-items:start; }
        .pos-panel { background:#fff; border:1px solid rgba(75,8,24,.12); border-radius:1rem; box-shadow:0 14px 36px rgba(36,21,26,.08); overflow:hidden; }
        .pos-panel__head { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1rem; border-bottom:1px solid rgba(75,8,24,.1); }
        .pos-panel__head h1, .pos-panel__head h2 { margin:0; font-size:1.1rem; font-weight:900; color:var(--brand); }
        .pos-panel__body { padding:1rem; }
        .pos-summary { display:flex; gap:.5rem; flex-wrap:wrap; }
        .pos-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.45rem .65rem; border-radius:999px; background:rgba(255,255,255,.12); color:#fff; font-weight:800; font-size:.85rem; }
        .pos-category { margin-bottom:1.25rem; }
        .pos-category h2 { color:var(--brand); font-weight:900; font-size:1rem; margin:0 0 .65rem; }
        .pos-product-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:.75rem; }
        .pos-product { min-height:106px; border:0; border-radius:1rem; color:#fff; padding:.85rem; text-align:left; display:flex; flex-direction:column; justify-content:space-between; box-shadow:inset 0 -34px 70px rgba(0,0,0,.14), 0 10px 24px rgba(36,21,26,.12); touch-action:manipulation; }
        .pos-product strong { display:block; font-size:1.02rem; line-height:1.1; }
        .pos-product span { font-weight:900; font-size:1.15rem; }
        .pos-side { position:sticky; top:78px; display:grid; gap:1rem; }
        .pos-cart-list { display:grid; gap:.55rem; max-height:36vh; overflow:auto; padding-right:.25rem; }
        .pos-cart-item { display:grid; grid-template-columns:1fr auto; gap:.5rem; align-items:center; padding:.7rem; border:1px solid rgba(75,8,24,.1); border-radius:.85rem; }
        .pos-cart-item strong, .pos-cart-item span { display:block; }
        .pos-cart-item span { color:#6f6470; font-size:.86rem; }
        .pos-qty { display:flex; align-items:center; gap:.35rem; }
        .pos-qty button { width:34px; height:34px; border:0; border-radius:.55rem; background:#f1e4d7; color:var(--brand); font-weight:900; }
        .pos-qty b { min-width:22px; text-align:center; }
        .pos-member { border:1px solid rgba(75,8,24,.1); border-radius:.9rem; padding:.8rem; background:#fbf7f1; }
        .pos-member.is-active { background:#effaf3; border-color:#b6e3c5; }
        .pos-member-actions { display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.75rem; }
        .pos-member-actions button { border:0; background:transparent; color:var(--brand); padding:0; font-weight:900; text-decoration:underline; text-underline-offset:3px; }
        .pos-total-row { display:flex; justify-content:space-between; gap:1rem; padding:.35rem 0; }
        .pos-total-row strong { font-size:1.45rem; color:var(--brand); }
        .pos-payments { display:grid; grid-template-columns:1fr 1fr; gap:.7rem; }
        .pos-payments button { display:inline-flex; align-items:center; justify-content:center; gap:.55rem; border:0; border-radius:1rem; min-height:64px; color:#fff; font-weight:900; font-size:1.05rem; line-height:1; }
        .pos-payments button i,
        .pos-payments button svg { flex:0 0 auto; width:1.15rem; height:1.15rem; margin:0 !important; position:static !important; }
        .pos-payments .cash { background:#1f6b4a; }
        .pos-payments .card { background:#4b0818; }
        .pos-toast { position:fixed; inset:0; z-index:50; display:none; place-items:center; padding:1rem; background:rgba(36,21,26,.35); }
        .pos-toast.show { display:grid; }
        .pos-toast__card { width:min(420px, calc(100vw - 2rem)); border-radius:1.25rem; background:#fff; padding:2rem; text-align:center; box-shadow:0 30px 90px rgba(0,0,0,.28); }
        .pos-toast__icon { width:82px; height:82px; margin:0 auto 1rem; border-radius:50%; display:grid; place-items:center; color:#fff; font-size:2.2rem; }
        .pos-toast.ok .pos-toast__icon { background:#0ca65d; }
        .pos-toast.err .pos-toast__icon { background:#d53845; }
        .pos-camera { position:fixed; inset:0; z-index:60; display:none; background:#17080d; color:#fff; }
        .pos-camera.is-open { display:grid; grid-template-rows:auto 1fr auto; }
        .pos-camera__head, .pos-camera__foot { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1rem; background:rgba(0,0,0,.32); }
        .pos-camera__head h2 { margin:0; font-size:1.1rem; font-weight:900; }
        .pos-camera__viewport { position:relative; overflow:hidden; display:grid; place-items:center; }
        .pos-camera video { width:100%; height:100%; object-fit:cover; }
        .pos-camera__frame { position:absolute; width:min(72vw,340px); aspect-ratio:1; border:4px solid #fff; border-radius:1.4rem; box-shadow:0 0 0 999px rgba(0,0,0,.38); }
        .pos-camera__message { position:absolute; left:1rem; right:1rem; bottom:1rem; padding:.85rem; border-radius:.85rem; background:rgba(75,8,24,.88); text-align:center; font-weight:800; }
        .pos-camera button { border:0; border-radius:.75rem; padding:.65rem .85rem; font-weight:900; }
        .pos-card-flow { position:fixed; inset:0; z-index:55; display:none; place-items:center; padding:1rem; background:rgba(36,21,26,.58); }
        .pos-card-flow.is-open { display:grid; }
        .pos-card-flow__card { width:min(480px, calc(100vw - 2rem)); background:#fff; border-radius:1.25rem; overflow:hidden; box-shadow:0 30px 90px rgba(0,0,0,.32); }
        .pos-card-flow__head { padding:1.2rem; color:#fff; background:linear-gradient(135deg,var(--brand),var(--brand-2)); }
        .pos-card-flow__head h2 { margin:0; font-size:1.15rem; font-weight:900; }
        .pos-card-flow__body { padding:1.2rem; }
        .pos-card-flow__amount { display:block; color:var(--brand); font-size:2.4rem; line-height:1; font-weight:950; margin:.4rem 0 1rem; }
        .pos-card-flow__steps { margin:0; padding-left:1.2rem; color:#5f5358; }
        .pos-card-flow__note { border:1px solid rgba(75,8,24,.12); border-radius:.85rem; background:#fbf7f1; color:#5f5358; padding:.8rem; font-size:.9rem; margin-top:1rem; }
        .pos-card-flow__actions { display:grid; gap:.65rem; padding:1.2rem; background:#fbf7f1; }
        .pos-card-flow__actions .primary { border:0; border-radius:.9rem; background:#4b0818; color:#fff; padding:.9rem 1rem; font-weight:900; text-align:center; text-decoration:none; }
        .pos-card-flow__actions .success { border:0; border-radius:.9rem; background:#0d7a4a; color:#fff; padding:.9rem 1rem; font-weight:900; }
        .pos-card-flow__actions .secondary { border:1px solid rgba(75,8,24,.18); border-radius:.9rem; background:#fff; color:#4b0818; padding:.8rem 1rem; font-weight:900; }
        .pos-card-flow__status { min-height:1.15rem; color:#6f6470; font-size:.86rem; font-weight:800; text-align:center; }
        .pos-cash-flow { position:fixed; inset:0; z-index:55; display:none; place-items:center; padding:.75rem; background:rgba(36,21,26,.58); }
        .pos-cash-flow.is-open { display:grid; }
        .pos-cash-flow__card { width:min(420px, calc(100vw - 1rem)); max-height:calc(100dvh - 1.5rem); display:grid; grid-template-rows:auto minmax(0,1fr) auto; background:#fff; border-radius:1rem; overflow:hidden; box-shadow:0 30px 90px rgba(0,0,0,.32); }
        .pos-cash-flow__head { padding:.9rem 1rem; color:#fff; background:linear-gradient(135deg,#1f6b4a,#0d7a4a); }
        .pos-cash-flow__head h2 { margin:0; font-size:1.15rem; font-weight:900; }
        .pos-cash-flow__body { min-height:0; overflow:auto; padding:.9rem 1rem; }
        .pos-cash-row { display:flex; justify-content:space-between; gap:1rem; padding:.22rem 0; color:#5f5358; font-weight:850; }
        .pos-cash-row strong { color:#4b0818; font-size:1.1rem; }
        .pos-cash-tendered { display:block; border:1px solid rgba(75,8,24,.12); border-radius:.8rem; background:#fbf7f1; padding:.55rem .75rem; color:#4b0818; font-size:1.55rem; font-weight:950; text-align:right; }
        .pos-keypad { display:grid; grid-template-columns:repeat(3,1fr); gap:.42rem; margin-top:.75rem; }
        .pos-keypad button { min-height:46px; border:0; border-radius:.7rem; background:#f1e4d7; color:#21141a; font-size:1.05rem; font-weight:950; }
        .pos-keypad button.action { background:#4b0818; color:#fff; font-size:.95rem; }
        .pos-cash-flow__actions { display:grid; grid-template-columns:1fr 1fr; gap:.55rem; padding:.85rem 1rem; background:#fbf7f1; }
        .pos-cash-flow__actions button { border:0; border-radius:.8rem; padding:.75rem .85rem; font-weight:900; }
        .pos-cash-flow__actions .confirm { background:#0d7a4a; color:#fff; }
        .pos-cash-flow__actions .cancel { background:#fff; color:#4b0818; border:1px solid rgba(75,8,24,.18); }
        .pos-close-till-flow { position:fixed; inset:0; z-index:65; display:none; place-items:center; padding:1rem; background:rgba(36,21,26,.58); }
        .pos-close-till-flow.is-open { display:grid; }
        .pos-close-till-flow__card { width:min(460px, calc(100vw - 2rem)); background:#fff; border-radius:1.1rem; overflow:hidden; box-shadow:0 30px 90px rgba(0,0,0,.32); }
        .pos-close-till-flow__head { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; padding:1rem 1.1rem; color:#fff; background:linear-gradient(135deg,var(--brand),var(--brand-2)); }
        .pos-close-till-flow__head h2 { margin:0; font-size:1.15rem; font-weight:900; }
        .pos-close-till-flow__head button { border:0; background:transparent; color:#fff; font-size:1.2rem; }
        .pos-close-till-flow__body { padding:1.1rem; }
        .pos-close-till-flow__body label { color:var(--brand); font-weight:900; }
        .pos-close-till-flow__help { color:#6f6470; font-size:.86rem; font-weight:700; margin:.35rem 0 0; }
        .pos-close-till-flow__actions { display:flex; justify-content:flex-end; gap:.65rem; padding:1rem 1.1rem; background:#fbf7f1; }
        .pos-close-till-flow__actions button { border-radius:.85rem; padding:.7rem .95rem; font-weight:900; }
        .pos-close-till-flow__primary { border:0; background:#4b0818; color:#fff; }
        .pos-close-till-flow__danger { border:0; background:#b4233a; color:#fff; }
        .pos-close-till-flow__secondary { border:1px solid rgba(75,8,24,.18); background:#fff; color:#4b0818; }
        .pos-close-till-check { display:grid; gap:.35rem; min-height:8rem; padding:1.15rem; border-radius:.85rem; border:1px solid rgba(75,8,24,.12); align-content:center; }
        .pos-close-till-check strong, .pos-close-till-check span { display:block; }
        .pos-close-till-check strong { color:#24151a; font-weight:950; }
        .pos-close-till-check span { color:#6f6470; font-size:.9rem; font-weight:750; }
        .pos-close-till-check--balanced { border-color:rgba(13,122,74,.22); background:rgba(13,122,74,.07); }
        .pos-close-till-check--variance { border-color:rgba(184,116,0,.26); background:rgba(255,190,92,.12); }
        @media (max-height:700px) {
            .pos-cash-flow { padding:.35rem; }
            .pos-cash-flow__card { max-height:calc(100dvh - .7rem); border-radius:.85rem; }
            .pos-cash-flow__head { padding:.65rem .85rem; }
            .pos-cash-flow__head h2 { font-size:1rem; }
            .pos-cash-flow__body { padding:.65rem .85rem; }
            .pos-cash-row { padding:.12rem 0; font-size:.9rem; }
            .pos-cash-row strong { font-size:1rem; }
            .pos-cash-tendered { padding:.42rem .6rem; font-size:1.25rem; }
            .pos-keypad { gap:.32rem; margin-top:.55rem; }
            .pos-keypad button { min-height:38px; border-radius:.58rem; font-size:.98rem; }
            .pos-keypad button.action { font-size:.82rem; }
            .pos-cash-flow__actions { padding:.6rem .85rem; gap:.45rem; }
            .pos-cash-flow__actions button { padding:.58rem .65rem; border-radius:.65rem; font-size:.9rem; }
        }
        @media (max-width:991.98px) {
            .pos-layout { grid-template-columns:1fr; padding:.75rem; }
            .pos-side { position:static; }
            .pos-current-location { font-size:.88rem; }
        }
    </style>
</head>
<body>
<div class="pos-app" data-csrf="<?= h($csrfToken) ?>" data-location-id="<?= (int) $locationId ?>">
    <header class="pos-topbar">
        <div class="pos-brand">
            <div class="pos-brand__menu">
                <button class="pos-brand__icon" type="button" id="posMenuToggle" aria-controls="posMenu" aria-expanded="false" aria-label="Open POS menu"><i class="fa-solid fa-cash-register" aria-hidden="true"></i></button>
                <div class="pos-menu" id="posMenu">
                    <div class="pos-location-select">
                        <div class="pos-menu__label">Location</div>
                        <div><?= h((string) ($location['name'] ?? 'No location selected')) ?></div>
                    </div>
                    <div>
                        <div class="pos-menu__label">Signed in as</div>
                        <div class="pos-menu__identity"><i class="fa-solid fa-user" aria-hidden="true"></i><span><?= h((string) $actor['name']) ?></span></div>
                    </div>
                    <div class="pos-manage">
                        <?php if ($canViewFinancials): ?><a href="/pos/reports.php">Reports</a><?php endif; ?>
                        <?php if (pos_actor_is_manager($pdo)): ?>
                            <a href="/pos/gate_fixture.php">Gate Fixture</a>
                            <a href="/pos/products.php">Products</a>
                            <a href="/pos/operators.php">Operators</a>
                        <?php endif; ?>
                        <button type="button" id="noSaleDrawer">No sale drawer</button>
                        <button class="danger" type="button" id="closeTillOpen">Close this till</button>
                        <?php if (hub_auth_is_authenticated()): ?>
                            <a href="/">Hub</a>
                            <a href="/pos/switch_operator.php">Operator login</a>
                            <a href="/admin/logout.php">Log out Hub</a>
                        <?php else: ?>
                            <a href="/pos/logout.php">Logout</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <span>Vics POS</span>
        </div>
        <?php if ($location): ?><div class="pos-current-location"><?= h((string) $location['name']) ?></div><?php endif; ?>
    </header>

    <main class="pos-layout">
        <section class="pos-panel">
            <div class="pos-panel__head">
                <h1>Products</h1>
                <?php if ($canViewFinancials): ?>
                    <div class="pos-summary">
                        <span class="badge text-bg-light"><?= (int) $summary['sales_count'] ?> sales today</span>
                        <span class="badge text-bg-light"><?= gbp((float) $summary['total']) ?> taken</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="pos-panel__body">
                <?php if (!$products): ?>
                    <p class="text-muted mb-0">No products are available for this location yet.</p>
                <?php endif; ?>
                <?php foreach ($categories as $categoryName => $categoryProducts): ?>
                    <section class="pos-category">
                        <h2><?= h($categoryName) ?></h2>
                        <div class="pos-product-grid">
                            <?php foreach ($categoryProducts as $product): ?>
                                <button class="pos-product" type="button"
                                    style="background:<?= h((string) $product['button_colour']) ?>"
                                    data-product='<?= h(json_encode([
                                        'id' => (int) $product['id'],
                                        'name' => (string) $product['name'],
                                        'category' => (string) $product['category_name'],
                                        'price' => (float) $product['sell_price'],
                                    ], JSON_UNESCAPED_SLASHES)) ?>'>
                                    <strong><?= h((string) $product['name']) ?></strong>
                                    <span><?= gbp((float) $product['sell_price']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        </section>

        <aside class="pos-side">
            <section class="pos-panel">
                <div class="pos-panel__head">
                    <h2>Season Ticket / Member</h2>
                </div>
                <div class="pos-panel__body">
                    <div class="pos-member mb-3" id="memberBox">
                        <strong>No member attached</strong>
                        <div class="small text-muted">Scan a season ticket to apply discounts and points.</div>
                    </div>
                    <div id="memberEntry">
                        <label class="form-label fw-bold" for="memberScan">Manual lookup</label>
                        <div class="input-group">
                            <input class="form-control" id="memberScan" placeholder="Manual code or email">
                            <button class="btn btn-outline-secondary" type="button" id="memberLookup">Find</button>
                        </div>
                    </div>
                    <button class="btn btn-dark w-100 mt-3" type="button" id="memberCameraOpen"><i class="fa-solid fa-camera me-1" aria-hidden="true"></i>Scan member QR</button>
                </div>
            </section>

            <section class="pos-panel pos-cart">
                <div class="pos-panel__head">
                    <h2>Basket</h2>
                    <button class="btn btn-sm btn-outline-danger" type="button" id="clearCart">Clear</button>
                </div>
                <div class="pos-panel__body">
                <div class="pos-cart-list mb-3" id="cartList">
                    <p class="text-muted mb-0">No products added yet.</p>
                </div>
                <div class="border-top pt-3">
                    <div class="pos-total-row"><span>Subtotal</span><b id="subtotal">£0.00</b></div>
                    <div class="pos-total-row"><span>Discount</span><b id="discount">£0.00</b></div>
                    <div class="pos-total-row align-items-center">
                        <span>Redeem points</span>
                        <input class="form-control form-control-sm" id="pointsRedeemed" type="number" min="0" step="1" value="0" style="max-width:110px">
                    </div>
                    <div class="pos-total-row"><span>Total</span><strong id="total">£0.00</strong></div>
                </div>
                <div class="pos-payments mt-3">
                    <button class="cash" type="button" data-pay="cash"><i class="fa-solid fa-money-bill-wave me-1"></i>Cash</button>
                    <button class="card" type="button" data-pay="card"><i class="fa-solid fa-credit-card me-1"></i>Card</button>
                </div>
            </div>
            </section>
        </aside>
    </main>
</div>

<div class="pos-camera" id="memberCamera" aria-hidden="true">
    <div class="pos-camera__head">
        <h2>Scan Member QR</h2>
        <button type="button" id="memberCameraClose">Close</button>
    </div>
    <div class="pos-camera__viewport">
        <video id="memberCameraVideo" playsinline muted></video>
        <div class="pos-camera__frame" aria-hidden="true"></div>
        <div class="pos-camera__message" id="memberCameraMessage">Point the camera at the member season ticket QR code.</div>
    </div>
    <div class="pos-camera__foot">
        <span class="small">The member details will appear once the QR code is found.</span>
        <button type="button" id="memberCameraRetry">Retry</button>
    </div>
</div>

<div class="pos-card-flow" id="cardFlow" aria-hidden="true">
    <div class="pos-card-flow__card">
        <div class="pos-card-flow__head">
            <div class="small text-uppercase fw-bold opacity-75">Stripe App Payment</div>
            <h2 id="cardFlowRef">Card payment pending</h2>
        </div>
        <div class="pos-card-flow__body">
            <div class="text-muted fw-bold">Amount to charge</div>
            <strong class="pos-card-flow__amount" id="cardFlowAmount">£0.00</strong>
            <ol class="pos-card-flow__steps">
                <li>Tap Try Open Stripe App.</li>
                <li>Tap the add button and choose card charge / Tap to Pay.</li>
                <li>Enter this exact amount and take the contactless payment.</li>
                <li>Return here and confirm once Stripe shows the payment succeeded.</li>
            </ol>
            <div class="pos-card-flow__note">
                If your phone blocks the handoff, leave this page open, switch to Stripe manually, then return here after payment.
            </div>
            <div class="mt-3">
                <label class="form-label fw-bold" for="stripePaymentReference">Stripe reference</label>
                <input class="form-control" id="stripePaymentReference" maxlength="120" placeholder="PaymentIntent, receipt, or Stripe app reference">
                <div class="form-text">Enter the Stripe reference shown after payment. This is saved against the POS sale for reconciliation.</div>
            </div>
        </div>
        <div class="pos-card-flow__actions">
            <button class="primary" type="button" id="tryOpenStripeApp">Try Open Stripe App</button>
            <button class="primary" type="button" id="copyStripeAmount">Copy amount</button>
            <div class="pos-card-flow__status" id="stripeOpenStatus"></div>
            <button class="success" type="button" id="confirmStripePaid">Payment successful - complete sale</button>
            <button class="secondary" type="button" id="cancelStripePayment">Cancel pending card sale</button>
        </div>
    </div>
</div>

<div class="pos-cash-flow" id="cashFlow" aria-hidden="true">
    <div class="pos-cash-flow__card">
        <div class="pos-cash-flow__head">
            <div class="small text-uppercase fw-bold opacity-75">Cash Payment</div>
            <h2>Enter Cash Tendered</h2>
        </div>
        <div class="pos-cash-flow__body">
            <div class="pos-cash-row"><span>Total due</span><strong id="cashTotalDue">£0.00</strong></div>
            <div class="pos-cash-row"><span>Cash given</span><strong id="cashGivenDisplay">£0.00</strong></div>
            <div class="pos-cash-row"><span>Change due</span><strong id="cashChangeDue">£0.00</strong></div>
            <div class="pos-cash-tendered" id="cashTenderedDisplay">0.00</div>
            <div class="pos-keypad" aria-label="Cash keypad">
                <button type="button" data-cash-key="7">7</button>
                <button type="button" data-cash-key="8">8</button>
                <button type="button" data-cash-key="9">9</button>
                <button type="button" data-cash-key="4">4</button>
                <button type="button" data-cash-key="5">5</button>
                <button type="button" data-cash-key="6">6</button>
                <button type="button" data-cash-key="1">1</button>
                <button type="button" data-cash-key="2">2</button>
                <button type="button" data-cash-key="3">3</button>
                <button type="button" data-cash-key=".">.</button>
                <button type="button" data-cash-key="0">0</button>
                <button class="action" type="button" data-cash-key="back">Back</button>
                <button class="action" type="button" data-cash-quick="exact">Exact</button>
                <button class="action" type="button" data-cash-key="clear">Clear</button>
                <button class="action" type="button" data-cash-quick="next">Next £</button>
            </div>
        </div>
        <div class="pos-cash-flow__actions">
            <button class="cancel" type="button" id="cancelCashPayment">Cancel</button>
            <button class="confirm" type="button" id="confirmCashPayment">Complete cash sale</button>
        </div>
    </div>
</div>

<div class="pos-close-till-flow" id="closeTillFlow" aria-hidden="true">
    <div class="pos-close-till-flow__card">
        <div class="pos-close-till-flow__head">
            <div>
                <div class="small text-uppercase fw-bold opacity-75">Close till</div>
                <h2><?= h((string) ($location['name'] ?? 'Current till')) ?></h2>
            </div>
            <button type="button" id="closeTillDismiss" aria-label="Cancel closing till"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <div class="pos-close-till-flow__body">
            <div id="closeTillEntry">
                <div class="mb-3">
                    <label class="form-label" for="closeTillCountedCash">Cash counted in drawer</label>
                    <input class="form-control form-control-lg" id="closeTillCountedCash" type="number" min="0" step="0.01" inputmode="decimal" required>
                    <p class="pos-close-till-flow__help">Enter the actual cash physically counted in this till, including the opening float.</p>
                </div>
                <div>
                    <label class="form-label" for="closeTillNotes">Closing notes</label>
                    <textarea class="form-control" id="closeTillNotes" rows="3" maxlength="255" placeholder="Optional notes, for example who counted the till or why there is a shortage."></textarea>
                    <p class="pos-close-till-flow__help">Notes are saved for audit. Leave blank if there is nothing to record.</p>
                </div>
            </div>
            <div class="pos-close-till-check d-none" id="closeTillResult" role="status" aria-live="polite"></div>
        </div>
        <div class="pos-close-till-flow__actions">
            <button class="pos-close-till-flow__secondary" type="button" id="closeTillCancel">Cancel</button>
            <button class="pos-close-till-flow__primary" type="button" id="closeTillCheck">Close till</button>
            <button class="pos-close-till-flow__secondary d-none" type="button" id="closeTillRecheck">Recheck cash</button>
            <button class="pos-close-till-flow__danger d-none" type="button" id="closeTillAccept">Accept and close</button>
        </div>
    </div>
</div>

<div class="pos-toast" id="posToast" role="status" aria-live="polite">
    <div class="pos-toast__card">
        <div class="pos-toast__icon"><i class="fa-solid fa-check"></i></div>
        <h2 class="h4 fw-bold" id="toastTitle">Sale complete</h2>
        <p class="text-muted mb-0" id="toastText"></p>
    </div>
</div>

<script>
(() => {
    const app = document.querySelector('.pos-app');
    const csrf = app.dataset.csrf;
    const locationId = Number(app.dataset.locationId || 0);
    const storageKey = `vics-pos-state:${locationId}`;
    const money = value => new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' }).format(Number(value || 0));
    let cart = [];
    let member = null;
    let detector = null;
    let stream = null;
    let scanning = false;
    let pendingCardSale = null;
    let pendingCashSale = null;

    const cartList = document.getElementById('cartList');
    const memberBox = document.getElementById('memberBox');
    const memberEntry = document.getElementById('memberEntry');
    const pointsInput = document.getElementById('pointsRedeemed');
    const camera = document.getElementById('memberCamera');
    const cameraVideo = document.getElementById('memberCameraVideo');
    const cameraMessage = document.getElementById('memberCameraMessage');
    const cardFlow = document.getElementById('cardFlow');
    const cardFlowRef = document.getElementById('cardFlowRef');
    const cardFlowAmount = document.getElementById('cardFlowAmount');
    const stripePaymentReference = document.getElementById('stripePaymentReference');
    const stripeOpenStatus = document.getElementById('stripeOpenStatus');
    const posMenu = document.getElementById('posMenu');
    const posMenuToggle = document.getElementById('posMenuToggle');
    const cashFlow = document.getElementById('cashFlow');
    const cashTotalDue = document.getElementById('cashTotalDue');
    const cashGivenDisplay = document.getElementById('cashGivenDisplay');
    const cashChangeDue = document.getElementById('cashChangeDue');
    const cashTenderedDisplay = document.getElementById('cashTenderedDisplay');
    const closeTillFlow = document.getElementById('closeTillFlow');
    const closeTillEntry = document.getElementById('closeTillEntry');
    const closeTillResult = document.getElementById('closeTillResult');
    const closeTillCountedCash = document.getElementById('closeTillCountedCash');
    const closeTillNotes = document.getElementById('closeTillNotes');
    const closeTillCheck = document.getElementById('closeTillCheck');
    const closeTillAccept = document.getElementById('closeTillAccept');
    const closeTillRecheck = document.getElementById('closeTillRecheck');
    const closeTillCancel = document.getElementById('closeTillCancel');
    let cashTendered = '';

    function saveState() {
        try {
            localStorage.setItem(storageKey, JSON.stringify({
                cart,
                member,
                points_redeemed: Number(pointsInput.value || 0),
                pending_card_sale: pendingCardSale,
                pending_cash_sale: pendingCashSale,
                saved_at: Date.now()
            }));
        } catch (error) {}
    }

    function clearState() {
        try {
            localStorage.removeItem(storageKey);
        } catch (error) {}
    }

    async function restoreState() {
        let state = null;
        try {
            state = JSON.parse(localStorage.getItem(storageKey) || 'null');
        } catch (error) {}
        if (!state || typeof state !== 'object') return;
        if (Date.now() - Number(state.saved_at || 0) > 12 * 60 * 60 * 1000) {
            clearState();
            return;
        }
        cart = Array.isArray(state.cart) ? state.cart.filter(item => item && Number(item.id) > 0 && Number(item.qty) > 0) : [];
        member = state.member && typeof state.member === 'object' ? state.member : null;
        pointsInput.value = Math.max(0, Number(state.points_redeemed || 0));
        renderMember(false);
        renderCart(false);

        if (state.pending_card_sale && Number(state.pending_card_sale.sale_id) > 0) {
            try {
                const data = await api({ action: 'pending_card_status', sale_id: Number(state.pending_card_sale.sale_id) });
                if (data.sale) {
                    openCardFlow(data.sale, false);
                    return;
                }
                pendingCardSale = null;
                saveState();
            } catch (error) {}
        }

        if (state.pending_cash_sale && Number(state.pending_cash_sale.sale_id) > 0) {
            try {
                const data = await api({ action: 'pending_cash_status', sale_id: Number(state.pending_cash_sale.sale_id) });
                if (data.sale) {
                    openCashFlow(data.sale, false);
                    return;
                }
                pendingCashSale = null;
                saveState();
            } catch (error) {}
        }

        if (cart.length || member) {
            showToast(true, 'Sale restored', 'Your basket was restored after refresh.');
        }
    }

    function renderCart(persist = true) {
        if (!cart.length) {
            cartList.innerHTML = '<p class="text-muted mb-0">No products added yet.</p>';
        } else {
            cartList.innerHTML = cart.map(item => `
                <div class="pos-cart-item">
                    <div><strong>${escapeHtml(item.name)}</strong><span>${escapeHtml(item.category)} · ${money(item.price)}</span></div>
                    <div class="pos-qty">
                        <button type="button" data-dec="${item.id}">-</button>
                        <b>${item.qty}</b>
                        <button type="button" data-inc="${item.id}">+</button>
                    </div>
                </div>
            `).join('');
        }
        const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
        document.getElementById('subtotal').textContent = money(subtotal);
        document.getElementById('discount').textContent = member?.season_ticket_valid ? 'Calculated at payment' : money(0);
        document.getElementById('total').textContent = member?.season_ticket_valid ? 'Pending' : money(Math.max(0, subtotal - (Number(pointsInput.value || 0) / 100)));
        if (persist) saveState();
    }

    function basketSubtotal() {
        return cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
    }

    function estimatedTotal() {
        if (pendingCashSale) return Number(pendingCashSale.total || 0);
        return Math.max(0, basketSubtotal() - (Number(pointsInput.value || 0) / 100));
    }

    function resetCompletedSale() {
        cart = [];
        member = null;
        pendingCardSale = null;
        pendingCashSale = null;
        pointsInput.value = 0;
        closeCardFlow();
        closeCashFlow();
        clearState();
        renderMember(false);
        renderCart(false);
    }

    function renderMember(persist = true) {
        if (!member) {
            memberBox.classList.remove('is-active');
            memberBox.innerHTML = '<strong>No member attached</strong><div class="small text-muted">Scan a season ticket to apply discounts and points.</div>';
            memberEntry.style.display = '';
            pointsInput.max = 0;
            pointsInput.value = 0;
            if (persist) saveState();
            return;
        }
        memberBox.classList.add('is-active');
        memberEntry.style.display = 'none';
        pointsInput.max = member.points_balance || 0;
        memberBox.innerHTML = `<strong>${escapeHtml(member.name)}</strong>
            <div class="small">${member.season_ticket_valid ? 'Valid season ticket' : 'Member account'}${member.ticket_type ? ' · ' + escapeHtml(member.ticket_type) : ''}</div>
            <div class="small text-muted">Points balance: ${member.points_balance || 0}</div>
            <div class="pos-member-actions">
                <button type="button" id="changeMember">Change member</button>
            </div>`;
        if (persist) saveState();
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    }

    async function api(payload) {
        const response = await fetch('/pos/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...payload, csrf_token: csrf })
        });
        const data = await response.json();
        if (!data.ok) throw new Error(data.error || 'POS request failed.');
        return data;
    }

    function showToast(ok, title, text) {
        const toast = document.getElementById('posToast');
        toast.className = 'pos-toast show ' + (ok ? 'ok' : 'err');
        toast.querySelector('.pos-toast__icon i').className = ok ? 'fa-solid fa-check' : 'fa-solid fa-triangle-exclamation';
        document.getElementById('toastTitle').textContent = title;
        document.getElementById('toastText').textContent = text;
        setTimeout(() => toast.classList.remove('show'), 1800);
    }

    function resetCloseTillFlow() {
        closeTillResult.className = 'pos-close-till-check d-none';
        closeTillResult.textContent = '';
        closeTillEntry.classList.remove('d-none');
        closeTillCheck.classList.remove('d-none');
        closeTillCancel.classList.remove('d-none');
        closeTillAccept.classList.add('d-none');
        closeTillRecheck.classList.add('d-none');
    }

    function openCloseTillFlow() {
        if (cart.length) {
            showToast(false, 'Basket active', 'Clear or complete the basket before closing this till.');
            return;
        }
        if (pendingCardSale || pendingCashSale) {
            showToast(false, 'Sale pending', 'Complete or cancel the pending sale before closing this till.');
            return;
        }
        resetCloseTillFlow();
        closeTillFlow.classList.add('is-open');
        closeTillFlow.setAttribute('aria-hidden', 'false');
        closeTillCountedCash.focus();
    }

    function closeCloseTillFlow() {
        closeTillFlow.classList.remove('is-open');
        closeTillFlow.setAttribute('aria-hidden', 'true');
    }

    function renderCloseTillResult(variance, balanced) {
        closeTillResult.className = 'pos-close-till-check ' + (balanced ? 'pos-close-till-check--balanced' : 'pos-close-till-check--variance');
        closeTillResult.innerHTML = balanced
            ? '<strong>Till balances.</strong><span>No variance found. You can accept and close this till.</span>'
            : '<strong>Variance found: ' + money(variance) + '</strong><span>Accept this variance if it is correct, or recheck the drawer and enter the cash count again.</span>';
        closeTillEntry.classList.add('d-none');
        closeTillCheck.classList.add('d-none');
        closeTillCancel.classList.add('d-none');
        closeTillAccept.classList.remove('d-none');
        closeTillRecheck.classList.remove('d-none');
    }

    function openCardFlow(sale, persist = true) {
        pendingCardSale = sale;
        cardFlowRef.textContent = sale.sale_ref;
        cardFlowAmount.textContent = money(sale.total);
        stripePaymentReference.value = sale.payment_reference || '';
        stripeOpenStatus.textContent = '';
        cardFlow.classList.add('is-open');
        cardFlow.setAttribute('aria-hidden', 'false');
        if (persist) saveState();
    }

    function closeCardFlow() {
        cardFlow.classList.remove('is-open');
        cardFlow.setAttribute('aria-hidden', 'true');
    }

    function openCashFlow(sale, persist = true) {
        pendingCashSale = sale;
        cashTendered = '';
        cashTotalDue.textContent = money(pendingCashSale.total);
        renderCashTender();
        cashFlow.classList.add('is-open');
        cashFlow.setAttribute('aria-hidden', 'false');
        if (persist) saveState();
    }

    function closeCashFlow() {
        cashFlow.classList.remove('is-open');
        cashFlow.setAttribute('aria-hidden', 'true');
    }

    function renderCashTender() {
        const tendered = Number(cashTendered || 0);
        const total = Number(pendingCashSale?.total || estimatedTotal());
        cashTenderedDisplay.textContent = tendered.toFixed(2);
        cashGivenDisplay.textContent = money(tendered);
        cashChangeDue.textContent = tendered >= total ? money(tendered - total) : 'Short ' + money(total - tendered);
        document.getElementById('confirmCashPayment').disabled = tendered < total;
    }

    function addCashKey(key) {
        if (key === 'clear') {
            cashTendered = '';
        } else if (key === 'back') {
            cashTendered = cashTendered.slice(0, -1);
        } else if (key === '.') {
            if (!cashTendered.includes('.')) cashTendered = cashTendered === '' ? '0.' : cashTendered + '.';
        } else if (/^\d$/.test(key)) {
            const parts = cashTendered.split('.');
            if (parts[1] && parts[1].length >= 2) return;
            cashTendered = (cashTendered + key).replace(/^0(?=\d)/, '');
        }
        renderCashTender();
    }

    function formatManualCode(value) {
        const clean = String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 7);
        return clean.length > 3 ? `${clean.slice(0, 3)}-${clean.slice(3)}` : clean;
    }

    async function attachMember(input) {
        const data = await api({ action: 'member_lookup', input });
        member = data.member;
        document.getElementById('memberScan').value = '';
        renderMember();
        renderCart();
        showToast(true, 'Member attached', member.name);
    }

    function stopCameraStreamOnly() {
        scanning = false;
        if (stream) stream.getTracks().forEach(track => track.stop());
        stream = null;
        cameraVideo.srcObject = null;
    }

    function stopMemberCamera() {
        stopCameraStreamOnly();
        camera.classList.remove('is-open');
        camera.setAttribute('aria-hidden', 'true');
    }

    async function scanLoop() {
        if (!scanning || !detector) return;
        try {
            const codes = await detector.detect(cameraVideo);
            if (codes && codes.length && codes[0].rawValue) {
                const rawValue = codes[0].rawValue;
                stopMemberCamera();
                await attachMember(rawValue);
                return;
            }
        } catch (error) {}
        if (scanning) window.setTimeout(scanLoop, 450);
    }

    async function startMemberCamera() {
        camera.classList.add('is-open');
        camera.setAttribute('aria-hidden', 'false');
        cameraMessage.textContent = 'Point the camera at the member season ticket QR code.';
        if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
            cameraMessage.textContent = 'Camera QR scanning is not supported on this browser. Use manual lookup instead.';
            return;
        }
        stopCameraStreamOnly();
        try {
            detector = new BarcodeDetector({ formats: ['qr_code'] });
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
            cameraVideo.srcObject = stream;
            await cameraVideo.play();
            scanning = true;
            scanLoop();
        } catch (error) {
            cameraMessage.textContent = 'Camera could not be started. Check permissions or use manual lookup.';
        }
    }

    document.querySelectorAll('.pos-product').forEach(button => {
        button.addEventListener('click', () => {
            const product = JSON.parse(button.dataset.product || '{}');
            const existing = cart.find(item => item.id === product.id);
            if (existing) existing.qty += 1;
            else cart.push({ ...product, qty: 1 });
            renderCart();
        });
    });

    posMenuToggle.addEventListener('click', () => {
        const isOpen = posMenu.classList.toggle('is-open');
        posMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    document.addEventListener('click', event => {
        if (event.target.closest('.pos-brand__menu')) return;
        posMenu.classList.remove('is-open');
        posMenuToggle.setAttribute('aria-expanded', 'false');
    });

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        posMenu.classList.remove('is-open');
        posMenuToggle.setAttribute('aria-expanded', 'false');
    });

    cartList.addEventListener('click', event => {
        const dec = event.target.closest('[data-dec]');
        const inc = event.target.closest('[data-inc]');
        if (!dec && !inc) return;
        const id = Number((dec || inc).dataset[dec ? 'dec' : 'inc']);
        const item = cart.find(row => row.id === id);
        if (!item) return;
        item.qty += inc ? 1 : -1;
        if (item.qty <= 0) cart = cart.filter(row => row.id !== id);
        renderCart();
    });

    document.getElementById('clearCart').addEventListener('click', () => {
        cart = [];
        member = null;
        pendingCardSale = null;
        closeCardFlow();
        clearState();
        renderMember();
        renderCart();
    });

    document.getElementById('noSaleDrawer')?.addEventListener('click', async () => {
        const reason = window.prompt('Reason for opening the drawer?', 'No sale drawer open');
        if (reason === null) return;
        try {
            await api({ action: 'no_sale_drawer', location_id: locationId, reason });
            showToast(true, 'Drawer event recorded', 'No-sale drawer opening has been added to audit.');
        } catch (error) {
            showToast(false, 'Could not record drawer event', error.message);
        }
    });

    document.getElementById('memberLookup').addEventListener('click', async () => {
        try {
            const input = document.getElementById('memberScan').value.trim();
            await attachMember(input);
        } catch (error) {
            showToast(false, 'Member not found', error.message);
        }
    });

    document.getElementById('memberScan').addEventListener('input', event => {
        const input = event.target;
        if (input.value.includes('@')) return;
        const formatted = formatManualCode(input.value);
        if (input.value !== formatted) input.value = formatted;
    });

    memberBox.addEventListener('click', event => {
        if (event.target.closest('#changeMember')) {
            member = null;
            renderMember();
            renderCart();
            document.getElementById('memberScan').focus();
        }
    });

    document.getElementById('memberCameraOpen').addEventListener('click', startMemberCamera);
    document.getElementById('memberCameraClose').addEventListener('click', stopMemberCamera);
    document.getElementById('memberCameraRetry').addEventListener('click', startMemberCamera);
    window.addEventListener('beforeunload', stopMemberCamera);

    document.querySelectorAll('[data-pay]').forEach(button => {
        button.addEventListener('click', async () => {
            if (!cart.length) {
                showToast(false, 'Basket empty', 'Add at least one product first.');
                return;
            }
            button.disabled = true;
            try {
                if (button.dataset.pay === 'card') {
                    const data = await api({
                        action: 'create_card_pending',
                        location_id: locationId,
                        member,
                        points_redeemed: Number(pointsInput.value || 0),
                        items: cart.map(item => ({ product_id: item.id, qty: item.qty }))
                    });
                    openCardFlow(data.sale);
                    return;
                }
                if (button.dataset.pay === 'cash') {
                    const data = await api({
                        action: 'create_cash_pending',
                        location_id: locationId,
                        member,
                        points_redeemed: Number(pointsInput.value || 0),
                        items: cart.map(item => ({ product_id: item.id, qty: item.qty }))
                    });
                    openCashFlow(data.sale);
                    return;
                }
                const data = await api({
                    action: 'create_sale',
                    location_id: locationId,
                    payment_method: button.dataset.pay,
                    member,
                    points_redeemed: Number(pointsInput.value || 0),
                    items: cart.map(item => ({ product_id: item.id, qty: item.qty }))
                });
                resetCompletedSale();
                showToast(true, 'Sale complete', `${data.sale.sale_ref} · ${money(data.sale.total)}`);
            } catch (error) {
                showToast(false, 'Sale failed', error.message);
            } finally {
                button.disabled = false;
            }
        });
    });

    document.getElementById('confirmStripePaid').addEventListener('click', async () => {
        if (!pendingCardSale) return;
        try {
            if (!stripePaymentReference.value.trim()) {
                stripePaymentReference.focus();
                showToast(false, 'Reference required', 'Enter the Stripe reference before completing the card sale.');
                return;
            }
            const data = await api({ action: 'complete_card_pending', sale_id: pendingCardSale.sale_id, payment_reference: stripePaymentReference.value.trim() });
            resetCompletedSale();
            showToast(true, 'Card sale complete', `${data.sale.sale_ref} · ${money(data.sale.total)}`);
        } catch (error) {
            showToast(false, 'Could not complete sale', error.message);
        }
    });

    document.getElementById('cancelStripePayment').addEventListener('click', async () => {
        if (!pendingCardSale) {
            closeCardFlow();
            return;
        }
        try {
            await api({ action: 'cancel_card_pending', sale_id: pendingCardSale.sale_id });
            pendingCardSale = null;
            closeCardFlow();
            saveState();
            showToast(false, 'Card sale cancelled', 'The basket is still on screen.');
        } catch (error) {
            showToast(false, 'Could not cancel sale', error.message);
        }
    });

    document.getElementById('copyStripeAmount').addEventListener('click', async () => {
        if (!pendingCardSale) return;
        const amount = Number(pendingCardSale.total || 0).toFixed(2);
        try {
            await navigator.clipboard.writeText(amount);
            showToast(true, 'Amount copied', money(pendingCardSale.total));
        } catch (error) {
            showToast(false, 'Copy failed', `Enter ${money(pendingCardSale.total)} in Stripe.`);
        }
    });

    document.getElementById('tryOpenStripeApp').addEventListener('click', () => {
        if (!pendingCardSale) return;
        const ua = navigator.userAgent || '';
        stripeOpenStatus.textContent = 'Trying to open Stripe...';

        if (/Android/i.test(ua)) {
            window.location.href = 'intent://dashboard.stripe.com/#Intent;scheme=https;package=com.stripe.android.dashboard;end';
            window.setTimeout(() => {
                stripeOpenStatus.textContent = 'If Stripe did not open, use the app switcher and open Stripe manually.';
            }, 1400);
            return;
        }

        if (/iPhone|iPad|iPod/i.test(ua)) {
            const schemes = ['stripe://', 'stripedashboard://'];
            let index = 0;
            const tryNext = () => {
                if (document.hidden) return;
                if (index >= schemes.length) {
                    stripeOpenStatus.textContent = 'If Stripe did not open, use the app switcher and open Stripe manually.';
                    return;
                }
                window.location.href = schemes[index];
                index += 1;
                window.setTimeout(tryNext, 900);
            };
            tryNext();
            return;
        }

        stripeOpenStatus.textContent = 'Open the Stripe Dashboard app manually on this device.';
    });

    document.querySelectorAll('[data-cash-key]').forEach(button => {
        button.addEventListener('click', () => addCashKey(button.dataset.cashKey || ''));
    });

    document.querySelectorAll('[data-cash-quick]').forEach(button => {
        button.addEventListener('click', () => {
            const total = estimatedTotal();
            cashTendered = button.dataset.cashQuick === 'exact'
                ? total.toFixed(2)
                : Math.ceil(total).toFixed(2);
            renderCashTender();
        });
    });

    document.getElementById('cancelCashPayment').addEventListener('click', async () => {
        if (!pendingCashSale) {
            closeCashFlow();
            return;
        }
        try {
            await api({ action: 'cancel_cash_pending', sale_id: pendingCashSale.sale_id });
            pendingCashSale = null;
            closeCashFlow();
            saveState();
            showToast(false, 'Cash sale cancelled', 'The basket is still on screen.');
        } catch (error) {
            showToast(false, 'Could not cancel cash sale', error.message);
        }
    });

    document.getElementById('confirmCashPayment').addEventListener('click', async () => {
        if (!pendingCashSale || Number(cashTendered || 0) < Number(pendingCashSale.total || 0)) return;
        try {
            const data = await api({ action: 'complete_cash_pending', sale_id: pendingCashSale.sale_id });
            const change = Number(cashTendered || 0) - Number(data.sale.total || 0);
            resetCompletedSale();
            showToast(true, 'Cash sale complete', `${data.sale.sale_ref} · Change ${money(change)}`);
        } catch (error) {
            showToast(false, 'Cash sale failed', error.message);
        }
    });

    document.getElementById('closeTillOpen')?.addEventListener('click', openCloseTillFlow);
    document.getElementById('closeTillDismiss')?.addEventListener('click', closeCloseTillFlow);
    closeTillCancel?.addEventListener('click', closeCloseTillFlow);
    closeTillRecheck?.addEventListener('click', () => {
        resetCloseTillFlow();
        closeTillCountedCash.focus();
        closeTillCountedCash.select();
    });
    closeTillCountedCash?.addEventListener('input', resetCloseTillFlow);
    closeTillCheck?.addEventListener('click', async () => {
        if (!closeTillCountedCash.reportValidity()) return;
        closeTillCheck.disabled = true;
        try {
            const data = await api({
                action: 'audit_till_close_count',
                location_id: locationId,
                counted_cash: Number(closeTillCountedCash.value || 0),
                closing_notes: closeTillNotes.value || ''
            });
            renderCloseTillResult(Number(data.variance || 0), Boolean(data.balanced));
        } catch (error) {
            closeTillResult.className = 'pos-close-till-check pos-close-till-check--variance';
            closeTillResult.innerHTML = '<strong>Count check was not saved.</strong><span>' + escapeHtml(error.message) + '</span>';
            closeTillEntry.classList.add('d-none');
        } finally {
            closeTillCheck.disabled = false;
        }
    });
    closeTillAccept?.addEventListener('click', async () => {
        closeTillAccept.disabled = true;
        try {
            const data = await api({
                action: 'close_till',
                location_id: locationId,
                counted_cash: Number(closeTillCountedCash.value || 0),
                closing_notes: closeTillNotes.value || ''
            });
            clearState();
            showToast(true, 'Till closed', 'This till has been closed.');
            window.setTimeout(() => { window.location.href = data.redirect || '/pos/logout.php'; }, 900);
        } catch (error) {
            closeTillResult.className = 'pos-close-till-check pos-close-till-check--variance';
            closeTillResult.innerHTML = '<strong>Till was not closed.</strong><span>' + escapeHtml(error.message) + '</span>';
            closeTillAccept.disabled = false;
        }
    });

    pointsInput.addEventListener('input', () => renderCart());
    renderMember(false);
    renderCart(false);
    restoreState();
})();
</script>
</body>
</html>
