<?php
declare(strict_types=1);

// Public page — /playersponsors (see .htaccess). No login required.
// Step 1 of the shopping-cart flow: browse current players, add open Home/
// Away kit sponsorship slots to a basket, then continue to
// playersponsors_checkout.php. Mirrors season_tickets_signup.php's basket
// pattern (lib/season_ticket_basket.php).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/player_sponsorship_shop.php';
require_once __DIR__ . '/lib/player_sponsorship_basket.php';
ensurePlayerSponsorshipShopSchema($pdo);
player_sponsorship_basket_start();

$currentSeason = getCurrentSeason($pdo);
$seasonId = (int) ($currentSeason['id'] ?? 0);
$players = $seasonId > 0 ? player_sponsorship_shop_players_with_availability($pdo, $seasonId) : [];
$packages = $seasonId > 0 ? player_sponsorship_shop_packages($pdo) : [];
$seasonPricing = $seasonId > 0 ? getSeasonPlayerPricing($pdo, $seasonId) : [];

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $isAjaxBasketRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if (!csrf_check()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add') {
            $playerId = (int) ($_POST['player_id'] ?? 0);
            $packageCode = (string) ($_POST['package_code'] ?? '');
            $valid = false;
            foreach ($players as $player) {
                if ($player['id'] !== $playerId) {
                    continue;
                }
                foreach ($player['packages'] as $packageState) {
                    if ($packageState['package_code'] === $packageCode && $packageState['available']) {
                        $valid = true;
                    }
                }
            }
            if (!$valid) {
                $error = 'Sorry, that sponsorship slot is no longer available.';
            } else {
                player_sponsorship_basket_add($playerId, $packageCode);
                if (!$isAjaxBasketRequest) {
                    header('Location: ' . (strtok($_SERVER['REQUEST_URI'], '?') ?: 'playersponsors') . '#basket');
                    exit;
                }
            }
        } elseif ($action === 'remove') {
            player_sponsorship_basket_remove((string) ($_POST['item_id'] ?? ''));
            if (!$isAjaxBasketRequest) {
                header('Location: ' . (strtok($_SERVER['REQUEST_URI'], '?') ?: 'playersponsors') . '#basket');
                exit;
            }
        }

        if ($isAjaxBasketRequest) {
            $basket = player_sponsorship_basket_summary($players);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => $error === '',
                'error' => $error,
                'basket_html' => player_sponsorship_render_basket($basket, csrf_field()),
                'basket_keys' => array_keys(player_sponsorship_basket_key_set()),
            ], JSON_THROW_ON_ERROR);
            exit;
        }
    }
    if ($isAjaxBasketRequest) {
        $basket = player_sponsorship_basket_summary($players);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => $error ?: 'Basket could not be updated.',
            'basket_html' => player_sponsorship_render_basket($basket, csrf_field()),
            'basket_keys' => array_keys(player_sponsorship_basket_key_set()),
        ], JSON_THROW_ON_ERROR);
        exit;
    }
}

$basket = player_sponsorship_basket_summary($players);
$basketKeys = player_sponsorship_basket_key_set();
$csrfFieldHtml = csrf_field();

function player_sponsorship_render_basket(array $basket, string $csrfFieldHtml): string
{
    ob_start();
    ?>
            <div class="ps-basket" id="basket" aria-live="polite">
                <h2 class="h5" style="color:var(--ps-maroon);">Your basket</h2>
                <?php if (empty($basket['items'])): ?>
                    <p class="text-muted mb-0">No sponsorships added yet.</p>
                <?php else: ?>
                    <?php foreach ($basket['items'] as $item): ?>
                        <div class="ps-basket-item">
                            <div>
                                <div class="fw-semibold"><?= h((string) $item['player_name']) ?></div>
                                <div class="small text-muted"><?= h(str_replace(' Sponsorship', '', (string) player_sponsorship_shop_package_label((string) $item['package_code']))) ?></div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span><?= gbp((float) $item['price']) ?></span>
                                <form method="post" class="m-0" data-basket-form>
                                    <?= $csrfFieldHtml ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="item_id" value="<?= h((string) $item['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger ps-basket-remove" aria-label="Remove <?= h((string) $item['player_name']) ?> from basket" title="Remove from basket">
                                        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="ps-basket-total"><span>Total</span><span><?= gbp((float) $basket['total']) ?></span></div>
                    <a href="/playersponsors/checkout" class="btn btn-brand w-100 mt-3">Next: your details</a>
                <?php endif; ?>
            </div>
    <?php
    return (string) ob_get_clean();
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Player Sponsorship - <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css" rel="stylesheet">
    <style>
        :root { --ps-maroon: #4b0818; --ps-gold: #e0b42a; --ps-cream: #f6ecde; }
        body { background: var(--ps-cream); font-family: 'Inter', sans-serif; margin: 0; }
        .ps-hero { background: linear-gradient(135deg, var(--ps-maroon), #6a2036); color: #fff; padding: 2rem 1rem 1.5rem; text-align: center; }
        .ps-hero img.ps-badge { height: 56px; margin-bottom: .75rem; }
        .ps-hero h1 { font-weight: 700; margin: 0 0 .25rem; }
        .ps-wrap { max-width: 1120px; margin: 0 auto; padding: 1.25rem 1rem 4rem; }
        .ps-shop-layout { display: grid; gap: 1rem; align-items: start; }
        .ps-card { background: #fff; border-radius: .75rem; box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 1.1rem 1.25rem; }
        .ps-table-wrap { overflow-x: auto; }
        .ps-table { width: 100%; border-collapse: collapse; min-width: 480px; }
        .ps-table th { text-align: left; font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: #6c757d; padding: 0 .5rem .6rem; border-bottom: 2px solid #f0e9df; }
        .ps-table th.ps-col-package { text-align: center; }
        .ps-table td { padding: .55rem .5rem; border-bottom: 1px solid #f0e9df; vertical-align: middle; }
        .ps-table td.ps-col-package { text-align: center; }
        .ps-player-cell { display: flex; align-items: center; gap: .6rem; }
        .ps-avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; background: #eee; flex: none; }
        .ps-avatar-fallback { width: 38px; height: 38px; border-radius: 50%; background: var(--ps-cream); color: var(--ps-maroon); display: flex; align-items: center; justify-content: center; font-weight: 700; flex: none; }
        .ps-add-btn { white-space: nowrap; }
        .ps-badge-taken { display: inline-block; padding: .3rem .6rem; border-radius: 999px; background: #eee; color: #888; font-size: .78rem; font-weight: 600; }
        .ps-badge-added { display: inline-block; padding: .3rem .6rem; border-radius: 999px; background: #e6f6ec; color: #1b7a3e; font-size: .78rem; font-weight: 700; }
        .ps-basket { position: static; background: #fff; border-top: 3px solid var(--ps-gold); box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 1rem; margin: 1.5rem 0 0; border-radius: .75rem; }
        .ps-basket-item { display: flex; justify-content: space-between; align-items: center; padding: .4rem 0; border-bottom: 1px solid #eee; gap: .5rem; }
        .ps-basket-remove { width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center; padding: 0; }
        .ps-basket-total { display: flex; justify-content: space-between; font-weight: 700; font-size: 1.1rem; margin-top: .5rem; color: var(--ps-maroon); }
        @media (min-width: 900px) {
            .ps-shop-layout { grid-template-columns: minmax(0, 1fr) 340px; }
            .ps-basket { position: sticky; top: 1rem; margin: 0; }
        }
    </style>
</head>
<body>
<div class="ps-hero">
    <img src="/admin/Saltcoats Victoria FC -White_Transparent.png" alt="Saltcoats Victoria FC" class="ps-badge">
    <h1>Sponsor a Player<?= $currentSeason ? ' — ' . h((string) $currentSeason['name']) : '' ?></h1>
    <p class="mb-0">Choose a player's Home or Away kit to sponsor, add as many as you like, then check out.</p>
    <p class="mt-2 mb-0"><a href="/members/matches.php" style="color:inherit;font-weight:700;">Fixtures &amp; results &rarr;</a></p>
</div>

<div class="ps-wrap">
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <?php if (!$currentSeason || !$packages || !$players): ?>
        <div class="ps-card text-center text-muted">Player kit sponsorship isn't currently available. Please check back soon or contact the club.</div>
    <?php else: ?>
        <div class="ps-shop-layout">
            <div class="ps-card">
                <div class="ps-table-wrap">
                    <table class="ps-table">
                        <thead>
                            <tr>
                                <th>Player</th>
                                <?php foreach ($packages as $package): ?>
                                    <th class="ps-col-package"><?= h((string) $package['name']) ?><br><span class="text-muted fw-normal"><?= gbp(player_sponsorship_shop_package_price($package, $seasonPricing)) ?></span></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($players as $player): ?>
                                <tr>
                                    <td>
                                        <div class="ps-player-cell">
                                            <?php if ($player['avatar_url'] !== ''): ?>
                                                <img src="<?= h($player['avatar_url']) ?>" alt="" class="ps-avatar">
                                            <?php else: ?>
                                                <span class="ps-avatar-fallback"><?= h(strtoupper(substr((string) $player['name'], 0, 1))) ?></span>
                                            <?php endif; ?>
                                            <span class="fw-semibold"><?= h((string) $player['name']) ?></span>
                                        </div>
                                    </td>
                                    <?php foreach ($player['packages'] as $packageState): ?>
                                        <?php $key = $player['id'] . ':' . $packageState['package_code']; ?>
                                        <td class="ps-col-package">
                                            <?php if (isset($basketKeys[$key])): ?>
                                                <span class="ps-badge-added"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Added</span>
                                            <?php elseif (!$packageState['available']): ?>
                                                <span class="ps-badge-taken">Taken</span>
                                            <?php else: ?>
                                                <form method="post" data-basket-form>
                                                    <?= $csrfFieldHtml ?>
                                                    <input type="hidden" name="action" value="add">
                                                    <input type="hidden" name="player_id" value="<?= (int) $player['id'] ?>">
                                                    <input type="hidden" name="package_code" value="<?= h((string) $packageState['package_code']) ?>">
                                                    <button type="submit" class="btn btn-sm btn-brand ps-add-btn">Add — <?= gbp((float) $packageState['price']) ?></button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?= player_sponsorship_render_basket($basket, $csrfFieldHtml) ?>
        </div>
    <?php endif; ?>
</div>
<script>(() => {
    const submitBasketForm = async (form) => {
        const button = form.querySelector('button[type="submit"]');
        button?.setAttribute('disabled', 'disabled');
        try {
            const response = await fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: new FormData(form),
            });
            const payload = await response.json();
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Basket could not be updated.');
            }
            const basket = document.getElementById('basket');
            if (basket && payload.basket_html) {
                basket.outerHTML = payload.basket_html;
            }
            // Swap the clicked cell's Add button for an "Added" badge (or, for a
            // remove, put the row back to its pre-add state) without a full reload.
            const isAdd = form.querySelector('[name="action"]')?.value === 'add';
            if (isAdd) {
                const cell = form.closest('td');
                if (cell) {
                    cell.innerHTML = '<span class="ps-badge-added"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>Added</span>';
                }
            } else {
                window.location.reload();
            }
        } catch (error) {
            window.alert(error instanceof Error ? error.message : 'Basket could not be updated.');
        } finally {
            button?.removeAttribute('disabled');
        }
    };

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (!form.matches('[data-basket-form]')) return;
        event.preventDefault();
        submitBasketForm(form);
    });
})();</script>
</body>
</html>
