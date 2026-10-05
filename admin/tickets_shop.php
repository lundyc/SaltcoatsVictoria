<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/match_tickets.php';
require_once __DIR__ . '/member_auth.php';
ensureMatchTicketSchema($pdo);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$currentSeason = getCurrentSeason($pdo);
$seasonId = (int) ($currentSeason['id'] ?? 0);
$fixtureId = (int) ($_GET['fixture_id'] ?? ($_POST['fixture_id'] ?? 0));
$fixtures = $seasonId > 0 ? getTicketedHomeFixtures($pdo, $seasonId, true) : [];
$fixture = null;
foreach ($fixtures as $row) {
    if ((int) $row['id'] === $fixtureId) {
        $fixture = $row;
        break;
    }
}
$errors = [];
$cart = [];
$checkoutMode = (string) ($_POST['checkout_mode'] ?? 'guest');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$fixture) {
        $errors[] = 'Choose a home game.';
    } else {
        foreach (($_POST['qty'] ?? []) as $fixturePackageId => $qty) {
            $qty = max(0, min(20, (int) $qty));
            if ($qty > 0) {
                $cart[(int) $fixturePackageId] = $qty;
            }
        }
        if (!$cart) {
            $errors[] = 'Choose at least one ticket.';
        }
        $paymentMethod = 'online';

        $buyerHolder = null;
        $buyer = [
            'holder_id' => null,
            'name' => trim((string) ($_POST['buyer_name'] ?? '')),
            'email' => trim((string) ($_POST['buyer_email'] ?? '')),
            'phone' => trim((string) ($_POST['buyer_phone'] ?? '')),
        ];

        if (!$errors && $checkoutMode === 'login') {
            $loginEmail = trim((string) ($_POST['login_email'] ?? ''));
            $loginPassword = (string) ($_POST['login_password'] ?? '');
            $hubLogin = hub_auth_attempt_login($loginEmail, $loginPassword);
            if ($hubLogin['ok']) {
                $hubUser = hub_auth_current_user();
                $hubEmail = trim((string) ($hubUser['email'] ?? $loginEmail));
                $holder = $hubEmail !== '' ? findSeasonTicketHolderByEmail($pdo, $hubEmail) : null;
                if (!$holder) {
                    $holderId = saveSeasonTicketHolder($pdo, null, [
                        'name' => (string) ($hubUser['display_name'] ?? $hubUser['username'] ?? $loginEmail),
                        'email' => $hubEmail,
                        'phone' => '',
                        'marketing_opt_in' => 0,
                        'notes' => 'Auto-created from Hub admin checkout login.',
                    ]);
                    $holder = getSeasonTicketHolder($pdo, $holderId);
                }
                member_auth_login_session((int) $holder['id']);
                $buyerHolder = $holder;
                $buyer = [
                    'holder_id' => (int) $holder['id'],
                    'name' => (string) ($holder['name'] ?? ''),
                    'email' => (string) ($holder['email'] ?? $hubEmail),
                    'phone' => (string) ($holder['phone'] ?? ''),
                ];
            } else {
                $login = member_auth_attempt_login($loginEmail, $loginPassword);
                if (!$login['ok']) {
                    $errors[] = 'Incorrect email or password.';
                } else {
                    $buyerHolder = member_auth_current_holder();
                    $buyer = [
                        'holder_id' => (int) ($buyerHolder['id'] ?? 0),
                        'name' => (string) ($buyerHolder['name'] ?? ''),
                        'email' => (string) ($buyerHolder['email'] ?? ''),
                        'phone' => (string) ($buyerHolder['phone'] ?? ''),
                    ];
                }
            }
        } elseif (!$errors && $checkoutMode === 'signup') {
            $buyer['name'] = trim((string) ($_POST['signup_name'] ?? ''));
            $buyer['email'] = trim((string) ($_POST['signup_email'] ?? ''));
            $buyer['phone'] = trim((string) ($_POST['signup_phone'] ?? ''));
            $registration = member_account_register($pdo, [
                'name' => $buyer['name'],
                'email' => $buyer['email'],
                'phone' => $buyer['phone'],
                'password' => (string) ($_POST['signup_password'] ?? ''),
                'marketing_opt_in' => isset($_POST['marketing_opt_in']),
            ]);
            if (!$registration['ok']) {
                $errors[] = (string) $registration['error'];
            } else {
                $buyer['holder_id'] = (int) $registration['holder_id'];
            }
        } else {
            if ($buyer['name'] === '') {
                $errors[] = 'Enter your name.';
            }
            if ($buyer['email'] === '' || !filter_var($buyer['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid email address.';
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $orderId = createMatchTicketOrder($pdo, $buyer, (int) $fixture['id'], $cart, $paymentMethod, $checkoutMode);
                $order = getMatchTicketOrder($pdo, $orderId);
                $pdo->commit();
                if ($paymentMethod === 'online' && $order && (float) $order['total_amount'] > 0) {
                    if (!stripe_is_configured()) {
                        header('Location: /admin/ticket_order.php?access=' . urlencode((string) $order['access_token']) . '&saved=1');
                        exit;
                    }
                    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
                    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk');
                    $viewUrl = $scheme . '://' . $host . '/ticket_order.php?access=' . urlencode((string) $order['access_token']);
                    $checkout = match_ticket_stripe_create_checkout_session($pdo, $orderId, $viewUrl . '&paid=1', $scheme . '://' . $host . '/tickets?fixture_id=' . (int) $fixture['id'] . '&cancelled=1');
                    header('Location: ' . $checkout['url']);
                    exit;
                }
                header('Location: /admin/ticket_order.php?access=' . urlencode((string) $order['access_token']) . '&saved=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $e->getMessage();
            }
        }
    }
}

$packages = $fixture ? getFixtureTicketPackages($pdo, (int) $fixture['id'], true) : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Buy Match Tickets - <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css?v=<?= (int) (@filemtime(__DIR__ . '/assets/css/style.css') ?: time()) ?>" rel="stylesheet">
    <style>
        body { background:#f6ecde; font-family:Inter,system-ui,sans-serif; }
        .ticket-wrap { max-width:1100px; margin:0 auto; padding:1rem 1rem 4rem; }
        .ticket-hero { color:#fff; background:linear-gradient(135deg,#4b0818,#8d1b39); border-radius:18px; padding:2rem; margin:1rem 0; }
        .ticket-grid { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:1rem; align-items:start; }
        .ticket-card { background:#fff; border:1px solid rgba(75,8,24,.1); border-radius:14px; box-shadow:0 10px 28px rgba(33,20,26,.06); overflow:hidden; }
        .ticket-card__body { padding:1rem; }
        .ticket-row { display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:.9rem 0; border-bottom:1px solid #eee; }
        .ticket-row:last-child { border-bottom:0; }
        .checkout-options { display:grid; gap:.55rem; margin-bottom:1rem; }
        .checkout-option { position:relative; display:flex; gap:.75rem; align-items:flex-start; width:100%; padding:.8rem; border:1px solid rgba(75,8,24,.14); border-radius:12px; background:#fff; cursor:pointer; transition:border-color .15s ease, box-shadow .15s ease, background-color .15s ease; }
        .checkout-option input { position:absolute; opacity:0; pointer-events:none; }
        .checkout-option__icon { flex:0 0 2.35rem; width:2.35rem; height:2.35rem; display:grid; place-items:center; border-radius:999px; color:#4b0818; background:#f6ecde; }
        .checkout-option__text strong, .checkout-option__text span { display:block; }
        .checkout-option__text strong { color:#21141a; line-height:1.15; }
        .checkout-option__text span { color:#6f6470; font-size:.82rem; margin-top:.12rem; }
        .checkout-option:has(input:checked) { border-color:#4b0818; background:#fff8ee; box-shadow:0 0 0 3px rgba(224,180,42,.28); }
        .checkout-option:has(input:checked) .checkout-option__icon { color:#fff; background:#4b0818; }
        @media (max-width:900px) { .ticket-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="ticket-wrap">
    <section class="ticket-hero">
        <div class="small text-uppercase fw-bold opacity-75">Saltcoats Victoria FC</div>
        <h1 class="display-5 fw-bold mb-2">Buy Match Tickets</h1>
        <p class="mb-0">Choose a home game, pick your tickets, and show your digital QR code at the gate.</p>
        <p class="mt-2 mb-0"><a href="/members/matches.php" style="color:inherit;font-weight:700;">Fixtures &amp; results &rarr;</a></p>
    </section>

    <?php if (isset($_GET['cancelled'])): ?><div class="alert alert-warning">Payment was cancelled. No money was taken.</div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <?php if (!$fixture): ?>
        <div class="ticket-card"><div class="ticket-card__body">
            <h2 class="h4">Upcoming Home Games</h2>
            <?php if (!$fixtures): ?><p class="text-muted mb-0">There are no home games available for online tickets yet.</p><?php endif; ?>
            <div class="list-group">
                <?php foreach ($fixtures as $game): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="/tickets?fixture_id=<?= (int) $game['id'] ?>">
                        <span><strong>vs <?= h((string) $game['opponent']) ?></strong><br><small><?= h(date('D j M Y', strtotime((string) $game['match_date']))) ?></small></span>
                        <span class="btn btn-sm btn-brand">Buy Tickets</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div></div>
    <?php else: ?>
        <form method="post" class="ticket-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="fixture_id" value="<?= (int) $fixture['id'] ?>">
            <div class="ticket-card">
                <div class="ticket-card__body">
                    <h2 class="h4">vs <?= h((string) $fixture['opponent']) ?></h2>
                    <p class="text-muted"><?= h(date('D j M Y', strtotime((string) $fixture['match_date']))) ?><?= !empty($fixture['kickoff_time']) ? ' · ' . h(date('H:i', strtotime((string) $fixture['kickoff_time']))) : '' ?></p>
                    <h3 class="h5 mt-4">Tickets</h3>
                    <?php if (!$packages): ?><p class="text-muted">Tickets are not yet available online for this game.</p><?php endif; ?>
                    <?php foreach ($packages as $package): ?>
                        <?php $remaining = (int) $package['allocation'] > 0 ? max(0, (int) $package['allocation'] - (int) $package['sold_qty']) : 999; ?>
                        <div class="ticket-row">
                            <div>
                                <strong><?= h((string) $package['name']) ?></strong>
                                <div class="small text-muted"><?= h((string) ($package['description'] ?? '')) ?></div>
                                <div class="fw-semibold"><?= gbp((float) $package['price']) ?></div>
                            </div>
                            <input class="form-control" style="width:96px;" type="number" min="0" max="<?= min(20, $remaining) ?>" name="qty[<?= (int) $package['id'] ?>]" value="<?= (int) ($cart[(int) $package['id']] ?? 0) ?>" <?= $remaining <= 0 ? 'disabled' : '' ?>>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <aside class="ticket-card">
                <div class="ticket-card__body">
                    <h2 class="h5">Checkout</h2>
                    <div class="checkout-options" data-checkout-mode>
                        <label class="checkout-option">
                            <input type="radio" name="checkout_mode" value="guest" <?= $checkoutMode === 'guest' ? 'checked' : '' ?>>
                            <span class="checkout-option__icon"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                            <span class="checkout-option__text"><strong>Checkout as guest</strong><span>Fastest option. Your ticket link is sent to your email.</span></span>
                        </label>
                        <label class="checkout-option">
                            <input type="radio" name="checkout_mode" value="signup" <?= $checkoutMode === 'signup' ? 'checked' : '' ?>>
                            <span class="checkout-option__icon"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></span>
                            <span class="checkout-option__text"><strong>Create an account</strong><span>Save your details and access limited members features.</span></span>
                        </label>
                        <label class="checkout-option">
                            <input type="radio" name="checkout_mode" value="login" <?= $checkoutMode === 'login' ? 'checked' : '' ?>>
                            <span class="checkout-option__icon"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i></span>
                            <span class="checkout-option__text"><strong>I already have an account</strong><span>Sign in and attach these tickets to your account.</span></span>
                        </label>
                    </div>
                    <div data-mode-panel="guest">
                        <div class="mb-2"><label class="form-label">Name</label><input class="form-control" name="buyer_name" value="<?= h((string) ($_POST['buyer_name'] ?? '')) ?>"></div>
                        <div class="mb-2"><label class="form-label">Email</label><input class="form-control" type="email" name="buyer_email" value="<?= h((string) ($_POST['buyer_email'] ?? '')) ?>"></div>
                        <div class="mb-2"><label class="form-label">Phone</label><input class="form-control" name="buyer_phone" value="<?= h((string) ($_POST['buyer_phone'] ?? '')) ?>"></div>
                    </div>
                    <div data-mode-panel="signup" class="d-none">
                        <div class="mb-2"><label class="form-label">Name</label><input class="form-control" name="signup_name" value="<?= h((string) ($_POST['signup_name'] ?? '')) ?>"></div>
                        <div class="mb-2"><label class="form-label">Email</label><input class="form-control" type="email" name="signup_email" value="<?= h((string) ($_POST['signup_email'] ?? '')) ?>"></div>
                        <div class="mb-2"><label class="form-label">Phone</label><input class="form-control" name="signup_phone" value="<?= h((string) ($_POST['signup_phone'] ?? '')) ?>"></div>
                        <div class="mb-2"><label class="form-label">Password</label><input class="form-control" type="password" minlength="8" name="signup_password"></div>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="marketingOptIn" name="marketing_opt_in" value="1" checked><label class="form-check-label" for="marketingOptIn">Keep me posted about club news</label></div>
                    </div>
                    <div data-mode-panel="login" class="d-none">
                        <div class="mb-2"><label class="form-label">Email</label><input class="form-control" type="email" name="login_email" value="<?= h((string) ($_POST['login_email'] ?? '')) ?>"></div>
                        <div class="mb-2"><label class="form-label">Password</label><input class="form-control" type="password" name="login_password"></div>
                    </div>
                    <hr>
                    <div class="alert alert-light border mb-3">
                        <div class="fw-semibold"><i class="fa-brands fa-stripe-s me-1" aria-hidden="true"></i>Secure card payment</div>
                        <div class="small text-muted">Match tickets are paid online by card through Stripe.</div>
                    </div>
                    <button class="btn btn-brand w-100" type="submit">Checkout</button>
                </div>
            </aside>
        </form>
    <?php endif; ?>
</div>
<script>
(() => {
    const modeInputs = document.querySelectorAll('[data-checkout-mode] input[name="checkout_mode"]');
    const sync = () => {
        const mode = document.querySelector('[data-checkout-mode] input[name="checkout_mode"]:checked')?.value || 'guest';
        document.querySelectorAll('[data-mode-panel]').forEach((panel) => panel.classList.toggle('d-none', panel.dataset.modePanel !== mode));
    };
    modeInputs.forEach((input) => input.addEventListener('change', sync));
    sync();
})();
</script>
</body>
</html>
