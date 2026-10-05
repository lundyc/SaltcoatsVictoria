<?php
declare(strict_types=1);

// Public page — /season-tickets (see .htaccess). No login required.
// Step 1 of the shopping-cart flow: browse ticket types, add one per ticket
// to a basket, then continue to season_tickets_checkout.php.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/season_ticket_basket.php';
ensureSeasonTicketSchema($pdo);
season_ticket_basket_start();

$currentSeason = getCurrentSeason($pdo);
$types = $currentSeason ? getSeasonTicketTypes($pdo, (int) $currentSeason['id'], true) : [];
$sponsors = $currentSeason ? getSeasonTicketSponsors($pdo, (int) $currentSeason['id']) : [];
$freeSignupCodeMessage = '';
if ($currentSeason && isset($_GET['code'])) {
    $submittedCode = seasonTicketNormalizeFreeSignupCode((string) $_GET['code']);
    $codeRow = $submittedCode !== '' ? getSeasonTicketFreeSignupCode($pdo, $submittedCode, (int) $currentSeason['id']) : null;
    if (seasonTicketFreeSignupCodeIsUsable($codeRow)) {
        $_SESSION[SEASON_TICKET_FREE_SIGNUP_CODE_SESSION_KEY] = $submittedCode;
        $freeSignupCodeMessage = 'Free signup code applied. This basket will bypass online payment.';
    } else {
        unset($_SESSION[SEASON_TICKET_FREE_SIGNUP_CODE_SESSION_KEY]);
        $freeSignupCodeMessage = 'That free signup code is invalid or has already been used.';
    }
}
$freeSignupCode = $currentSeason ? seasonTicketNormalizeFreeSignupCode((string) ($_SESSION[SEASON_TICKET_FREE_SIGNUP_CODE_SESSION_KEY] ?? '')) : '';
$freeSignupCodeRow = $freeSignupCode !== '' && $currentSeason ? getSeasonTicketFreeSignupCode($pdo, $freeSignupCode, (int) $currentSeason['id']) : null;
$freeSignupCodeActive = seasonTicketFreeSignupCodeIsUsable($freeSignupCodeRow);
if ($freeSignupCode !== '' && !$freeSignupCodeActive) {
    unset($_SESSION[SEASON_TICKET_FREE_SIGNUP_CODE_SESSION_KEY]);
    $freeSignupCode = '';
}
$typesById = [];
foreach ($types as $type) {
    $typesById[(int) $type['id']] = $type;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $isAjaxBasketRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if (!csrf_check()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add') {
            $typeId = (int) ($_POST['type_id'] ?? 0);
            if (!isset($typesById[$typeId])) {
                $error = 'Choose a valid ticket type.';
            } else {
                season_ticket_basket_add($typeId);
                if (!$isAjaxBasketRequest) {
                    header('Location: ' . (strtok($_SERVER['REQUEST_URI'], '?') ?: 'season-tickets') . '#basket');
                    exit;
                }
            }
        } elseif ($action === 'remove') {
            season_ticket_basket_remove((string) ($_POST['item_id'] ?? ''));
            if (!$isAjaxBasketRequest) {
                header('Location: ' . (strtok($_SERVER['REQUEST_URI'], '?') ?: 'season-tickets') . '#basket');
                exit;
            }
        }

        if ($isAjaxBasketRequest) {
            $basket = season_ticket_basket_summary($types);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => $error === '',
                'error' => $error,
                'basket_html' => season_ticket_render_basket($basket, $typesById, csrf_field(), $freeSignupCodeActive),
            ], JSON_THROW_ON_ERROR);
            exit;
        }
    }
    if ($isAjaxBasketRequest) {
        $basket = season_ticket_basket_summary($types);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => $error ?: 'Basket could not be updated.',
            'basket_html' => season_ticket_render_basket($basket, $typesById, csrf_field(), $freeSignupCodeActive),
        ], JSON_THROW_ON_ERROR);
        exit;
    }
}

$basket = season_ticket_basket_summary($types);
$csrfFieldHtml = csrf_field();

require_once __DIR__ . '/member_auth.php';
$loggedInHolder = member_auth_current_holder();

function season_ticket_product_description(array $type): string
{
    $code = seasonTicketTypeCode((string) ($type['code'] ?? $type['name'] ?? ''));
    if (str_starts_with($code, 'adult')) {
        return 'Full season ticket for adult supporters.';
    }
    if ($code === 'concession') {
        return 'Discounted season ticket for eligible concession supporters.';
    }
    if ($code === 'wee_vics') {
        return 'Free junior season ticket for young supporters.';
    }
    return 'Season ticket product for this season.';
}

function season_ticket_basket_item_code(array $item): string
{
    return seasonTicketTypeCode((string) ($item['type_code'] ?? $item['code'] ?? $item['type_name'] ?? ''));
}

function season_ticket_basket_item_is_wee_vics(array $item): bool
{
    $code = season_ticket_basket_item_code($item);
    return str_contains($code, 'wee_vics') || str_contains($code, 'under_16') || str_contains($code, 'kid');
}

function season_ticket_basket_item_is_adult_or_concession(array $item): bool
{
    $code = season_ticket_basket_item_code($item);
    return str_contains($code, 'adult') || str_contains($code, 'concession');
}

function season_ticket_signup_sponsor_logo_path(array $sponsor): string
{
    $nameCode = seasonTicketTypeCode((string) ($sponsor['name'] ?? ''));
    if (str_contains($nameCode, 'jazza') && str_contains($nameCode, 'photography')) {
        return 'season-ticket-jazzamcg-photography-black.png';
    }
    return basename((string) ($sponsor['logo_path'] ?? ''));
}

function season_ticket_signup_sponsor_logo_class(array $sponsor): string
{
    return '';
}

function season_ticket_signup_basket_rule_errors(array $basketItems, array $typesById): array
{
    if (!$basketItems) {
        return [];
    }
    $hasWeeVics = false;
    $hasAdultOrConcession = false;
    foreach ($basketItems as $basketItem) {
        $type = $typesById[(int) $basketItem['type_id']] ?? [];
        $ruleItem = $basketItem + [
            'code' => (string) ($type['code'] ?? ''),
            'type_code' => (string) ($type['code'] ?? ''),
        ];
        $hasWeeVics = $hasWeeVics || season_ticket_basket_item_is_wee_vics($ruleItem);
        $hasAdultOrConcession = $hasAdultOrConcession || season_ticket_basket_item_is_adult_or_concession($ruleItem);
    }
    return $hasWeeVics && !$hasAdultOrConcession
        ? ['A Wee Vics season ticket must be ordered with an Adult or Concession season ticket.']
        : [];
}

function season_ticket_render_basket(array $basket, array $typesById, string $csrfFieldHtml, bool $freeSignupCodeActive): string
{
    $basketRuleErrors = season_ticket_signup_basket_rule_errors($basket['items'] ?? [], $typesById);
    ob_start();
    ?>
            <div class="st-basket" id="basket" aria-live="polite">
                <h2 class="h5" style="color:var(--st-maroon);">Your basket</h2>
                <?php if ($freeSignupCodeActive): ?>
                    <div class="alert alert-success py-2 small mb-3">Free signup code applied.</div>
                <?php endif; ?>
                <?php if (empty($basket['items'])): ?>
                    <p class="text-muted mb-0">No tickets added yet.</p>
                <?php else: ?>
                    <?php foreach ($basket['items'] as $item): ?>
                        <div class="st-basket-item">
                            <div><?= h((string) $item['type_name']) ?></div>
                            <div class="d-flex align-items-center gap-2">
                                <span><?= gbp((float) $item['price']) ?></span>
                                <form method="post" class="m-0" data-basket-form>
                                    <?= $csrfFieldHtml ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="item_id" value="<?= h((string) $item['id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger st-basket-remove" aria-label="Remove <?= h((string) $item['type_name']) ?> from basket" title="Remove from basket">
                                        <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="st-basket-total"><span>Total</span><span><?= gbp((float) $basket['total']) ?></span></div>
                    <?php foreach ($basketRuleErrors as $ruleError): ?>
                        <div class="alert alert-warning py-2 small mt-3 mb-0"><?= h($ruleError) ?></div>
                    <?php endforeach; ?>
                    <?php if (!$basketRuleErrors): ?>
                        <a href="/season-tickets/checkout" class="btn btn-brand w-100 mt-3">Enter ticket details</a>
                    <?php else: ?>
                        <button type="button" class="btn btn-brand w-100 mt-3" disabled>Enter ticket details</button>
                    <?php endif; ?>
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
    <title>Season Tickets - <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css" rel="stylesheet">
    <style>
        :root { --st-maroon: #4b0818; --st-gold: #e0b42a; --st-cream: #f6ecde; }
        body { background: var(--st-cream); font-family: 'Inter', sans-serif; margin: 0; }
        .st-hero { background: linear-gradient(135deg, var(--st-maroon), #6a2036); color: #fff; padding: 2rem 1rem 1.5rem; text-align: center; }
        .st-hero img.st-badge { height: 56px; margin-bottom: .75rem; }
        .st-hero h1 { font-weight: 700; margin: 0 0 .25rem; }
        .st-sponsors { display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 1.5rem; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,.2); }
        .st-sponsors img { height: 64px; max-width: 220px; object-fit: contain; background: #fff; border-radius: .45rem; padding: 8px 12px; }
        .st-sponsors .st-sponsors-label { width: 100%; text-align: center; font-size: .8rem; opacity: .8; margin-bottom: .25rem; }
        .st-wrap { max-width: 1120px; margin: 0 auto; padding: 1.25rem 1rem 4rem; }
        .st-account { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .75rem; background: #fff; border-left: 4px solid var(--st-gold); border-radius: .75rem; box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: .9rem 1rem; margin-bottom: 1rem; }
        .st-account strong { color: var(--st-maroon); }
        .st-account p { margin: 0; }
        .st-account-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
        .st-shop-layout { display: grid; gap: 1rem; align-items: start; }
        .st-products { display: grid; gap: 1rem; }
        .st-card { background: #fff; border-radius: .75rem; box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 1.1rem 1.25rem; margin-bottom: 1rem; }
        .st-products .st-card { margin-bottom: 0; }
        .st-type-row { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: .5rem; }
        .st-type-row h2 { font-size: 1.1rem; margin: 0; color: var(--st-maroon); }
        .st-type-price { font-weight: 700; color: var(--st-maroon); }
        .st-add-form { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: .75rem 1rem; margin-top: .75rem; }
        .st-product-description { flex: 1 1 260px; margin: 0; color: #6c757d; font-size: .92rem; }
        .st-add-form button { flex: 0 0 auto; margin-left: auto; }
        .st-basket { position: static; background: #fff; border-top: 3px solid var(--st-gold); box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 1rem; margin: 1.5rem 0 0; border-radius: .75rem; }
        .st-basket-item { display: flex; justify-content: space-between; align-items: center; padding: .35rem 0; border-bottom: 1px solid #eee; gap: .5rem; }
        .st-basket-remove { width: 2rem; height: 2rem; display: inline-flex; align-items: center; justify-content: center; padding: 0; }
        .st-basket-total { display: flex; justify-content: space-between; font-weight: 700; font-size: 1.1rem; margin-top: .5rem; color: var(--st-maroon); }
        @media (min-width: 576px) {
            .st-basket { border-top: 3px solid var(--st-gold); }
        }
        @media (min-width: 900px) {
            .st-shop-layout { grid-template-columns: minmax(0, 1fr) 360px; }
            .st-basket { position: sticky; top: 1rem; margin: 0; }
        }
    </style>
</head>
<body>
<div class="st-hero">
    <img src="/admin/Saltcoats Victoria FC -White_Transparent.png" alt="Saltcoats Victoria FC" class="st-badge">
    <h1>Season Tickets<?= $currentSeason ? ' ' . h((string) $currentSeason['name']) : '' ?></h1>
    <p class="mb-0">Add season tickets to your basket, then enter the holder details.</p>
    <p class="mt-2 mb-0"><a href="/members/matches.php" style="color:inherit;font-weight:700;">Fixtures &amp; results &rarr;</a></p>
    <?php if ($sponsors): ?>
    <div class="st-sponsors">
        <div class="st-sponsors-label">This season's tickets are proudly sponsored by</div>
        <?php foreach ($sponsors as $sponsor): ?>
            <img src="/uploads/sponsors/<?= rawurlencode(season_ticket_signup_sponsor_logo_path($sponsor)) ?>" alt="<?= h((string) $sponsor['name']) ?>" class="<?= h(trim(season_ticket_signup_sponsor_logo_class($sponsor))) ?>">
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="st-wrap">
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <?php if ($freeSignupCodeMessage !== ''): ?><div class="alert <?= $freeSignupCodeActive ? 'alert-success' : 'alert-warning' ?>"><?= h($freeSignupCodeMessage) ?></div><?php endif; ?>
    <?php if ($freeSignupCodeActive && $freeSignupCodeMessage === ''): ?><div class="alert alert-success">Free signup code applied. This basket will bypass online payment.</div><?php endif; ?>
    <?php if ($loggedInHolder): ?>
        <div class="st-account">
            <p>Logged in as <strong><?= h((string) $loggedInHolder['name']) ?></strong><?php if (!empty($loggedInHolder['email'])): ?> <span class="text-muted">(<?= h((string) $loggedInHolder['email']) ?>)</span><?php endif; ?></p>
            <div class="st-account-actions">
                <a href="/members/index.php" class="btn btn-sm btn-outline-secondary">Account details</a>
                <a href="/members/logout.php?all=1&amp;return=%2Fhub%2Fseason-tickets" class="btn btn-sm btn-outline-danger">Log out</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$currentSeason || !$types): ?>
        <div class="st-card text-center text-muted">Season tickets aren't currently on sale. Please check back soon or contact the club.</div>
    <?php else: ?>
        <div class="st-shop-layout">
            <div class="st-products">
                <?php foreach ($types as $type): ?>
                    <div class="st-card">
                        <div class="st-type-row">
                            <h2><?= h((string) $type['name']) ?></h2>
                            <span class="st-type-price"><?= gbp((float) $type['price']) ?></span>
                        </div>
                        <form method="post" class="st-add-form">
                            <?= $csrfFieldHtml ?>
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
                            <p class="st-product-description"><?= h(season_ticket_product_description($type)) ?></p>
                            <button type="submit" class="btn btn-brand">Add to basket</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

            <?= season_ticket_render_basket($basket, $typesById, $csrfFieldHtml, $freeSignupCodeActive) ?>
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
        } catch (error) {
            window.alert(error instanceof Error ? error.message : 'Basket could not be updated.');
        } finally {
            button?.removeAttribute('disabled');
        }
    };

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (!form.classList.contains('st-add-form') && !form.matches('[data-basket-form]')) return;
        event.preventDefault();
        submitBasketForm(form);
    });
})();</script>
</body>
</html>
