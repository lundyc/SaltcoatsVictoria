<?php
declare(strict_types=1);

// Public page — step 2 of the shopping-cart flow. Identify the buyer (log in
// or sign up), capture the season ticket holder details for each basket item,
// and place the order. Paid public orders always go through Stripe.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/season_ticket_basket.php';
require_once __DIR__ . '/lib/season_ticket_stripe.php';
require_once __DIR__ . '/lib/people.php';
require_once __DIR__ . '/member_auth.php';
ensureSeasonTicketSchema($pdo);
ensureSeasonPassSchema($pdo);
season_ticket_basket_start();

$currentSeason = getCurrentSeason($pdo);
$types = $currentSeason ? getSeasonTicketTypes($pdo, (int) $currentSeason['id'], true) : [];
$typesById = [];
foreach ($types as $type) {
    $typesById[(int) $type['id']] = $type;
}
$sponsors = $currentSeason ? getSeasonTicketSponsors($pdo, (int) $currentSeason['id']) : [];
$basket = season_ticket_basket_summary($types);
$seasonTicketTerms = renderSeasonTicketTermsForSeason($currentSeason);
$freeSignupCode = $currentSeason ? seasonTicketNormalizeFreeSignupCode((string) ($_SESSION[SEASON_TICKET_FREE_SIGNUP_CODE_SESSION_KEY] ?? '')) : '';
$freeSignupCodeRow = $freeSignupCode !== '' && $currentSeason ? getSeasonTicketFreeSignupCode($pdo, $freeSignupCode, (int) $currentSeason['id']) : null;
$freeSignupCodeActive = seasonTicketFreeSignupCodeIsUsable($freeSignupCodeRow);
if ($freeSignupCode !== '' && !$freeSignupCodeActive) {
    unset($_SESSION[SEASON_TICKET_FREE_SIGNUP_CODE_SESSION_KEY]);
    $freeSignupCode = '';
}

if (!$basket['items'] || !$currentSeason) {
    header('Location: /season-tickets');
    exit;
}

function season_ticket_checkout_item_code(array $item, array $typesById): string
{
    $type = $typesById[(int) ($item['type_id'] ?? 0)] ?? [];
    return seasonTicketTypeCode((string) ($type['code'] ?? $item['type_name'] ?? ''));
}

function season_ticket_checkout_item_is_wee_vics(array $item, array $typesById): bool
{
    $code = season_ticket_checkout_item_code($item, $typesById);
    return str_contains($code, 'wee_vics') || str_contains($code, 'under_16') || str_contains($code, 'kid');
}

function season_ticket_checkout_item_is_adult_or_concession(array $item, array $typesById): bool
{
    $code = season_ticket_checkout_item_code($item, $typesById);
    return str_contains($code, 'adult') || str_contains($code, 'concession');
}

function season_ticket_checkout_sponsor_logo_path(array $sponsor): string
{
    $nameCode = seasonTicketTypeCode((string) ($sponsor['name'] ?? ''));
    if (str_contains($nameCode, 'jazza') && str_contains($nameCode, 'photography')) {
        return 'season-ticket-jazzamcg-photography-black.png';
    }
    return basename((string) ($sponsor['logo_path'] ?? ''));
}

function season_ticket_checkout_sponsor_logo_style(array $sponsor): string
{
    return '';
}

function season_ticket_checkout_basket_rule_errors(array $basketItems, array $typesById): array
{
    $hasWeeVics = false;
    $hasAdultOrConcession = false;
    foreach ($basketItems as $item) {
        $hasWeeVics = $hasWeeVics || season_ticket_checkout_item_is_wee_vics($item, $typesById);
        $hasAdultOrConcession = $hasAdultOrConcession || season_ticket_checkout_item_is_adult_or_concession($item, $typesById);
    }
    return $hasWeeVics && !$hasAdultOrConcession
        ? ['A Wee Vics season ticket must be ordered with an Adult or Concession season ticket.']
        : [];
}

function season_ticket_person_has_current_ticket(PDO $pdo, int $personId, int $seasonId): bool
{
    $stmt = $pdo->prepare("SELECT 1
        FROM entitlements e
        JOIN season_passes sp ON sp.entitlement_id = e.id
        WHERE e.person_id = :person_id
          AND sp.season_id = :season_id
          AND e.status IN ('active', 'pending')
          AND sp.status IN ('active', 'pending')
        LIMIT 1");
    $stmt->execute([':person_id' => $personId, ':season_id' => $seasonId]);
    return (bool) $stmt->fetchColumn();
}

function season_ticket_checkout_holder_defaults(?array $buyerHolder): array
{
    if (!$buyerHolder) {
        return ['name' => '', 'date_of_birth' => ''];
    }
    return [
        'name' => (string) ($buyerHolder['name'] ?? ''),
        'date_of_birth' => (string) ($buyerHolder['date_of_birth'] ?? ''),
    ];
}

function season_ticket_checkout_holder_details(array $basketItems, ?array $buyerHolder, bool $prefillFirst): array
{
    $posted = is_array($_POST['holders'] ?? null) ? $_POST['holders'] : [];
    $details = [];
    $buyerDefaults = season_ticket_checkout_holder_defaults($buyerHolder);
    foreach ($basketItems as $index => $item) {
        $itemId = (string) $item['id'];
        $source = is_array($posted[$itemId] ?? null) ? $posted[$itemId] : [];
        if (!$source && $prefillFirst && $index === 0) {
            $source = $buyerDefaults;
        }
        $details[$itemId] = [
            'name' => trim((string) ($source['name'] ?? '')),
            'date_of_birth' => trim((string) ($source['date_of_birth'] ?? '')),
        ];
    }
    return $details;
}

function season_ticket_checkout_first_holder_uses_buyer(bool $loggedIn, bool $buyerAlreadyHasCurrentSeasonTicket): bool
{
    if ($loggedIn && $buyerAlreadyHasCurrentSeasonTicket) {
        return false;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return true;
    }
    return isset($_POST['first_holder_uses_buyer']);
}

function season_ticket_checkout_apply_buyer_to_first_holder(array $holderDetails, array $basketItems, ?array $buyerHolder): array
{
    if (!$buyerHolder || !$basketItems) {
        return $holderDetails;
    }
    $firstItemId = (string) $basketItems[0]['id'];
    $holderDetails[$firstItemId] = season_ticket_checkout_holder_defaults($buyerHolder);
    return $holderDetails;
}

function season_ticket_valid_date_or_blank(string $value): bool
{
    if ($value === '') {
        return true;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function season_ticket_find_matching_person(PDO $pdo, array $details): ?int
{
    $name = trim((string) ($details['name'] ?? ''));
    if ($name === '') {
        return null;
    }
    if ((string) ($details['date_of_birth'] ?? '') !== '') {
        $stmt = $pdo->prepare('SELECT id FROM people WHERE display_name = :name AND date_of_birth = :dob AND is_active = 1 ORDER BY id LIMIT 1');
        $stmt->execute([':name' => $name, ':dob' => (string) $details['date_of_birth']]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
    }
    return null;
}

function season_ticket_holder_matches_buyer(array $details, array $buyerHolder): bool
{
    if (strcasecmp(trim((string) ($details['name'] ?? '')), trim((string) ($buyerHolder['name'] ?? ''))) !== 0) {
        return false;
    }
    $holderDob = trim((string) ($details['date_of_birth'] ?? ''));
    $buyerDob = trim((string) ($buyerHolder['date_of_birth'] ?? ''));
    return $holderDob === '' || $buyerDob === '' || $holderDob === $buyerDob;
}

function season_ticket_resolve_holder_person(PDO $pdo, array $details, int $buyerPersonId, ?array $buyerHolder, bool $buyerAlreadyHasTicket): int
{
    if (!$buyerAlreadyHasTicket && $buyerHolder && season_ticket_holder_matches_buyer($details, $buyerHolder)) {
        return $buyerPersonId;
    }

    $existingPersonId = season_ticket_find_matching_person($pdo, $details);
    if ($existingPersonId !== null) {
        if ($existingPersonId !== $buyerPersonId && !personRelationshipExists($pdo, $buyerPersonId, $existingPersonId)) {
            addPersonRelationship($pdo, $buyerPersonId, $existingPersonId);
        }
        return $existingPersonId;
    }

    $personId = createPerson($pdo, [
        'display_name' => (string) $details['name'],
        'date_of_birth' => (string) $details['date_of_birth'],
        'marketing_opt_in' => 0,
        'is_active' => 1,
    ]);
    if ($personId !== $buyerPersonId) {
        addPersonRelationship($pdo, $buyerPersonId, $personId);
    }
    return $personId;
}

$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk');
$signupUrl = $scheme . '://' . $host . '/season-tickets';
$checkoutUrl = $scheme . '://' . $host . '/season-tickets/checkout';

// Capture the CSRF token/validity against the *default* session now, before
// any member_auth_* call below switches $_SESSION over to the isolated
// member cookie — csrf_field()/csrf_check() would otherwise silently start
// reading/writing the wrong session and every submission would look expired.
$csrfFieldHtml = csrf_field();
$csrfValid = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || csrf_check();
$defaultSessionName = session_name();
$defaultSessionId = session_id();

$loggedIn = member_auth_is_authenticated();
$buyerHolder = $loggedIn ? member_auth_current_holder() : null;
$buyerPersonIdForDefaults = $loggedIn ? (member_auth_current_person_id() ?? 0) : 0;
$buyerAlreadyHasCurrentSeasonTicket = $buyerPersonIdForDefaults > 0
    ? season_ticket_person_has_current_ticket($pdo, $buyerPersonIdForDefaults, (int) $currentSeason['id'])
    : false;
$firstHolderUsesBuyer = season_ticket_checkout_first_holder_uses_buyer($loggedIn, $buyerAlreadyHasCurrentSeasonTicket);
$holderDetails = season_ticket_checkout_holder_details($basket['items'], $buyerHolder, $loggedIn && !$buyerAlreadyHasCurrentSeasonTicket);
if ($firstHolderUsesBuyer && $buyerHolder) {
    $holderDetails = season_ticket_checkout_apply_buyer_to_first_holder($holderDetails, $basket['items'], $buyerHolder);
}

$errors = [];
$checkoutMode = (string) ($_POST['checkout_mode'] ?? 'signup');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$csrfValid) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $paymentMethod = $freeSignupCodeActive ? 'free_code' : 'stripe';
        if ($freeSignupCode !== '' && !$freeSignupCodeActive) {
            $errors[] = 'This free signup code is invalid or has already been used.';
        }
        $errors = array_merge($errors, season_ticket_checkout_basket_rule_errors($basket['items'], $typesById));
        $termsAccepted = isset($_POST['terms_accepted']);
        if (!$termsAccepted) {
            $errors[] = 'You must accept the season ticket terms and conditions to place your order.';
        }
        foreach ($basket['items'] as $index => $item) {
            if ($index === 0 && $firstHolderUsesBuyer) {
                continue;
            }
            $itemId = (string) $item['id'];
            $details = $holderDetails[$itemId] ?? [];
            $ticketLabel = (string) $item['type_name'];
            if (trim((string) ($details['name'] ?? '')) === '') {
                $errors[] = 'Enter the season ticket holder name for ' . $ticketLabel . '.';
            }
            if (!season_ticket_valid_date_or_blank((string) ($details['date_of_birth'] ?? ''))) {
                $errors[] = 'Enter a valid date of birth for ' . $ticketLabel . '.';
            }
        }

        if (!$loggedIn) {
            if ($checkoutMode === 'login') {
                $loginEmail = trim((string) ($_POST['login_email'] ?? ''));
                $loginPassword = (string) ($_POST['login_password'] ?? '');
                if ($loginEmail === '' || $loginPassword === '') {
                    $errors[] = 'Enter your email and password.';
                } else {
                    $result = member_auth_attempt_login($loginEmail, $loginPassword);
                    if ($result['ok']) {
                        $loggedIn = true;
                        $buyerHolder = member_auth_current_holder();
                        $buyerPersonId = member_auth_current_person_id() ?? 0;
                        $buyerAlreadyHasCurrentSeasonTicket = $buyerPersonId > 0
                            ? season_ticket_person_has_current_ticket($pdo, $buyerPersonId, (int) $currentSeason['id'])
                            : false;
                        if ($buyerAlreadyHasCurrentSeasonTicket) {
                            $firstHolderUsesBuyer = false;
                        }
                    } else {
                        $errors[] = (string) ($result['error'] ?? 'Incorrect email or password.');
                    }
                }
            } else {
                $signupName = trim((string) ($_POST['signup_name'] ?? ''));
                $signupDob = trim((string) ($_POST['signup_dob'] ?? ''));
                $signupEmail = trim((string) ($_POST['signup_email'] ?? ''));
                $signupPhone = trim((string) ($_POST['signup_phone'] ?? ''));
                $signupAddress = trim((string) ($_POST['signup_address'] ?? ''));
                $signupPassword = (string) ($_POST['signup_password'] ?? '');
                $marketingOptIn = isset($_POST['marketing_opt_in']);

                if ($signupName === '') {
                    $errors[] = 'Enter your name.';
                }
                if ($signupEmail === '' || !filter_var($signupEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Enter a valid email address.';
                }
                if ($signupPassword !== '' && strlen($signupPassword) < 8) {
                    $errors[] = 'Password must be at least 8 characters (or leave it blank).';
                }

                if (!$errors) {
                    $registration = member_account_register($pdo, [
                        'name' => $signupName,
                        'date_of_birth' => $signupDob,
                        'email' => $signupEmail,
                        'phone' => $signupPhone,
                        'address' => $signupAddress,
                        'password' => $signupPassword,
                        'marketing_opt_in' => $marketingOptIn ? 1 : 0,
                    ]);
                    if (!$registration['ok']) {
                        $errors[] = (string) ($registration['error'] ?? 'Unable to create your account. Please log in or reset your password.');
                    } else {
                        $loggedIn = true;
                        $buyerHolder = member_auth_current_holder();
                        $firstHolderUsesBuyer = isset($_POST['first_holder_uses_buyer']);
                    }
                }
            }
        }

        if ($loggedIn && $buyerHolder && !$errors) {
            try {
                $pdo->beginTransaction();
                $redeemedFreeCode = null;
                $buyerPersonId = member_auth_current_person_id() ?? 0;
                if ($buyerPersonId <= 0) {
                    throw new RuntimeException('Your member identity could not be resolved.');
                }
                $buyerAlreadyHasCurrentSeasonTicket = season_ticket_person_has_current_ticket($pdo, $buyerPersonId, (int) $currentSeason['id']);
                if ($buyerAlreadyHasCurrentSeasonTicket && $firstHolderUsesBuyer) {
                    throw new RuntimeException('You already have a season ticket for this season. Enter the ticket holder details for the person this order is for.');
                }
                if ($firstHolderUsesBuyer) {
                    $holderDetails = season_ticket_checkout_apply_buyer_to_first_holder($holderDetails, $basket['items'], $buyerHolder);
                }
                $buyerLegacyHolderId = member_auth_current_legacy_holder_id() ?? (int) ($buyerHolder['id'] ?? 0);
                if ($freeSignupCodeActive) {
                    $redeemedFreeCode = redeemSeasonTicketFreeSignupCode($pdo, $freeSignupCode, (int) $currentSeason['id'], $buyerPersonId, $buyerLegacyHolderId);
                }

                $ordersToCharge = [];
                foreach ($basket['items'] as $item) {
                    $details = $holderDetails[(string) $item['id']];
                    $ownerPersonId = season_ticket_resolve_holder_person($pdo, $details, $buyerPersonId, $buyerHolder, $buyerAlreadyHasCurrentSeasonTicket);
                    $orderPrice = $redeemedFreeCode ? 0.00 : (float) $item['price'];
                    $bundle = createSeasonPassOrder(
                        $pdo,
                        $buyerPersonId,
                        $ownerPersonId,
                        (int) $item['type_id'],
                        'self_serve_signup',
                        $paymentMethod,
                        $orderPrice,
                        date('Y-m-d H:i:s'),
                        $seasonTicketTerms,
                        $redeemedFreeCode ? 'Free signup code: ' . (string) $redeemedFreeCode['code'] : 'Public Stripe checkout'
                    );
                    $ordersToCharge[] = [
                        'id' => (int) $bundle['id'],
                        'price' => $orderPrice,
                        'holder_name' => (string) $details['name'],
                        'type_name' => $item['type_name'],
                        'token' => (string) $bundle['token'],
                        'manual_code' => (string) $bundle['manual_code'],
                    ];
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'Something went wrong placing your order: ' . str_replace('pass', 'season ticket', $e->getMessage());
            }

            if (!$errors) {
                $buyerEmail = (string) ($buyerHolder['email'] ?? '');
                season_ticket_basket_clear_default_session($defaultSessionName, $defaultSessionId);

                if ($paymentMethod === 'stripe' && array_sum(array_column($ordersToCharge, 'price')) > 0) {
                    if (!stripe_is_configured()) {
                        $errors[] = 'Online payment is not available right now — please contact the club, your order has been saved.';
                    } else {
                        $checkout = season_ticket_stripe_create_checkout_session(
                            $pdo,
                            $ordersToCharge,
                            $buyerEmail,
                            $signupUrl . '?success=1',
                            $checkoutUrl . '?cancelled=1'
                        );
                        header('Location: ' . $checkout['url']);
                        exit;
                    }
                } else {
                    foreach ($ordersToCharge as $orderToConfirm) {
                        sendSeasonPassConfirmationIfNeeded($pdo, (int) $orderToConfirm['id'], true);
                    }
                    header('Location: /members/index.php?order_placed=1');
                    exit;
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Checkout - <?= h(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/admin/assets/css/style.css" rel="stylesheet">
    <style>
        :root { --st-maroon: #4b0818; --st-gold: #e0b42a; --st-cream: #f6ecde; }
        body { background: var(--st-cream); font-family: 'Inter', sans-serif; margin: 0; }
        .st-topbar { background: var(--st-maroon); color: #fff; padding: 1rem; text-align: center; }
        .st-wrap { max-width: 1040px; margin: 0 auto; padding: 1.25rem 1rem 4rem; }
        .st-checkout-layout { display: grid; gap: 1rem; align-items: start; }
        .st-checkout-main { order: 2; }
        .st-checkout-summary { order: 1; }
        .st-card { background: #fff; border-radius: .75rem; box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 1.25rem; margin-bottom: 1rem; }
        .st-summary-row { display: flex; justify-content: space-between; padding: .3rem 0; border-bottom: 1px solid #eee; }
        .st-total-row { display: flex; justify-content: space-between; font-weight: 700; font-size: 1.1rem; margin-top: .5rem; color: var(--st-maroon); }
        .st-mode-toggle { display: flex; gap: .5rem; margin-bottom: 1rem; }
        .st-mode-toggle button { flex: 1; }
        .st-ticket-fields { border: 1px solid #eee4d6; border-radius: .5rem; padding: 1rem; margin-bottom: 1rem; background: #fffdfa; }
        .st-ticket-fields h3 { color: var(--st-maroon); font-size: 1rem; margin: 0 0 .75rem; }
        .st-ticket-fields--using-buyer .st-ticket-holder-inputs { display: none; }
        .st-checkout-sponsor-logo { height: 24px; vertical-align: middle; margin: 0 4px; }
        .st-terms-box { max-height: 260px; overflow: auto; border: 1px solid #e5e1dc; border-radius: .5rem; background: #fffaf4; padding: 1rem; font-size: .9rem; line-height: 1.5; white-space: normal; }
        .st-terms-box p { margin: 0 0 .85rem; }
        .st-terms-box p:last-child { margin-bottom: 0; }
        @media (min-width: 900px) {
            .st-checkout-layout { grid-template-columns: minmax(0, 1fr) 360px; }
            .st-checkout-main { order: 1; }
            .st-checkout-summary { order: 2; position: sticky; top: 1rem; }
        }
    </style>
</head>
<body>
<div class="st-topbar"><a href="/season-tickets" class="text-white text-decoration-none">&larr; Back to basket</a> &middot; Ticket details</div>
<div class="st-wrap">
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (isset($_GET['cancelled'])): ?><div class="alert alert-warning">Payment was cancelled — no money was taken. Your order is saved below, ready to try again.</div><?php endif; ?>
    <?php if ($freeSignupCodeActive): ?><div class="alert alert-success">Free signup code applied. No payment will be taken for this order.</div><?php endif; ?>

    <div class="st-checkout-layout">
    <form method="post" class="st-card st-checkout-main">
        <?= $csrfFieldHtml ?>

        <?php if ($loggedIn && $buyerHolder): ?>
            <p class="mb-3">Checking out as <strong><?= h((string) $buyerHolder['name']) ?></strong> (<?= h((string) $buyerHolder['email']) ?>).</p>
            <div class="alert alert-light border py-2">We'll use your account contact details for this order. The ticket details below only need the person named on each season ticket.</div>
            <?php if ($buyerAlreadyHasCurrentSeasonTicket): ?>
                <div class="alert alert-info py-2">You already have a season ticket for this season, so the holder details below have been left blank for someone else.</div>
            <?php else: ?>
                <div class="alert alert-info py-2">Your details have been filled into the first ticket. You can change them if this ticket is for someone else.</div>
            <?php endif; ?>
        <?php else: ?>
            <div class="st-mode-toggle">
                <button type="button" class="btn btn-outline-secondary active" data-mode="signup" id="modeSignupBtn">I'm new here</button>
                <button type="button" class="btn btn-outline-secondary" data-mode="login" id="modeLoginBtn">I have an account</button>
            </div>
            <input type="hidden" name="checkout_mode" id="checkoutModeInput" value="<?= h($checkoutMode) ?>">

            <div id="signupFields">
                <h2 class="h6">Your contact details</h2>
                <div class="mb-2"><label class="form-label">Full name</label><input type="text" class="form-control" name="signup_name" value="<?= h((string) ($_POST['signup_name'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label">Date of birth</label><input type="date" class="form-control" name="signup_dob" value="<?= h((string) ($_POST['signup_dob'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label">Email</label><input type="email" class="form-control" name="signup_email" value="<?= h((string) ($_POST['signup_email'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label">Phone</label><input type="text" class="form-control" name="signup_phone" value="<?= h((string) ($_POST['signup_phone'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label">Address <span class="text-muted">(only if posting)</span></label><input type="text" class="form-control" name="signup_address" value="<?= h((string) ($_POST['signup_address'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label">Set a password <span class="text-muted">(optional — lets you log in next time)</span></label><input type="password" class="form-control" name="signup_password" minlength="8"></div>
                <div class="mb-2 form-check"><input type="checkbox" class="form-check-input" name="marketing_opt_in" id="signupOptIn" value="1" checked><label class="form-check-label" for="signupOptIn">Keep me posted about club news, deals and offers</label></div>
            </div>
            <div id="loginFields" class="d-none">
                <div class="mb-2"><label class="form-label">Email</label><input type="email" class="form-control" name="login_email" value="<?= h((string) ($_POST['login_email'] ?? '')) ?>"></div>
                <div class="mb-2"><label class="form-label">Password</label><input type="password" class="form-control" name="login_password"></div>
            </div>
        <?php endif; ?>

        <h2 class="h6 mt-4">Season ticket details</h2>
        <p class="text-muted small mb-3">Enter the name and date of birth for each person who will hold a season ticket. Contact details are taken once from the account or signup details above.</p>
        <?php foreach ($basket['items'] as $index => $item): ?>
            <?php $itemDetails = $holderDetails[(string) $item['id']] ?? season_ticket_checkout_holder_defaults(null); ?>
            <?php $firstTicketUsesBuyer = $index === 0 && $firstHolderUsesBuyer; ?>
            <?php $canUseBuyerForFirstTicket = $index === 0 && (!$loggedIn || !$buyerAlreadyHasCurrentSeasonTicket); ?>
            <div class="st-ticket-fields<?= $firstTicketUsesBuyer ? ' st-ticket-fields--using-buyer' : '' ?>" <?= $index === 0 ? 'data-first-ticket-holder' : '' ?>>
                <h3><?= h((string) $item['type_name']) ?><?= count($basket['items']) > 1 ? ' #' . ($index + 1) : '' ?></h3>
                <?php if ($canUseBuyerForFirstTicket): ?>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" name="first_holder_uses_buyer" id="firstHolderUsesBuyer" value="1" <?= $firstTicketUsesBuyer ? 'checked' : '' ?> data-first-holder-uses-buyer>
                        <label class="form-check-label" for="firstHolderUsesBuyer">Use my details for this season ticket</label>
                    </div>
                <?php endif; ?>
                <div class="row g-2 st-ticket-holder-inputs">
                    <div class="col-md-6">
                        <label class="form-label">Ticket holder name</label>
                        <input type="text" class="form-control" name="holders[<?= h((string) $item['id']) ?>][name]" value="<?= h((string) $itemDetails['name']) ?>" <?= $firstTicketUsesBuyer ? '' : 'required' ?> <?= $index === 0 ? 'data-first-holder-name' : '' ?>>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Date of birth</label>
                        <input type="date" class="form-control" name="holders[<?= h((string) $item['id']) ?>][date_of_birth]" value="<?= h((string) $itemDetails['date_of_birth']) ?>" <?= $index === 0 ? 'data-first-holder-dob' : '' ?>>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($freeSignupCodeActive): ?>
            <div class="alert alert-success py-2">Payment bypassed by free signup code.</div>
        <?php else: ?>
            <div class="alert alert-light border py-2">Payment is by card through Stripe after you place the order.</div>
        <?php endif; ?>

        <h2 class="h6 mt-4">Terms and conditions</h2>
        <div class="st-terms-box mb-3">
            <?php foreach (preg_split('/\R{2,}/', trim($seasonTicketTerms)) ?: [] as $paragraph): ?>
                <p><?= nl2br(h(trim($paragraph))) ?></p>
            <?php endforeach; ?>
        </div>
        <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="terms_accepted" id="termsAccepted" value="1" required <?= isset($_POST['terms_accepted']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="termsAccepted">I accept the season ticket terms and conditions.</label>
        </div>

        <button type="submit" class="btn btn-brand w-100"><?= !$freeSignupCodeActive && $basket['total'] > 0 ? 'Place order and pay by card' : 'Place order' ?></button>
    </form>

    <div class="st-card st-checkout-summary">
        <h2 class="h5" style="color:var(--st-maroon);">Order summary</h2>
        <?php foreach ($basket['items'] as $item): ?>
            <div class="st-summary-row"><span><?= h((string) $item['type_name']) ?></span><span><?= $freeSignupCodeActive ? gbp(0.00) : gbp((float) $item['price']) ?></span></div>
        <?php endforeach; ?>
        <div class="st-total-row"><span>Total</span><span><?= $freeSignupCodeActive ? gbp(0.00) : gbp($basket['total']) ?></span></div>
    </div>
    </div>

    <?php if ($sponsors): ?>
    <div class="text-center small text-muted mt-3">
        This season's tickets are proudly sponsored by
        <?php foreach ($sponsors as $sponsor): ?>
            <img src="/uploads/sponsors/<?= rawurlencode(season_ticket_checkout_sponsor_logo_path($sponsor)) ?>" alt="<?= h((string) $sponsor['name']) ?>" class="st-checkout-sponsor-logo" style="<?= h(season_ticket_checkout_sponsor_logo_style($sponsor)) ?>">
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>(() => {
    const modeInput = document.getElementById('checkoutModeInput');
    const signupBtn = document.getElementById('modeSignupBtn');
    const loginBtn = document.getElementById('modeLoginBtn');
    const signupFields = document.getElementById('signupFields');
    const loginFields = document.getElementById('loginFields');
    if (!modeInput) return;
    const setMode = (mode) => {
        modeInput.value = mode;
        signupBtn.classList.toggle('active', mode === 'signup');
        loginBtn.classList.toggle('active', mode === 'login');
        signupFields.classList.toggle('d-none', mode !== 'signup');
        loginFields.classList.toggle('d-none', mode !== 'login');
    };
    signupBtn?.addEventListener('click', () => setMode('signup'));
    loginBtn?.addEventListener('click', () => setMode('login'));
    setMode(modeInput.value || 'signup');
})();</script>
<script>(() => {
    const signupName = document.querySelector('[name="signup_name"]');
    const signupDob = document.querySelector('[name="signup_dob"]');
    const useBuyer = document.querySelector('[data-first-holder-uses-buyer]');
    const firstTicket = document.querySelector('[data-first-ticket-holder]');
    const holderName = document.querySelector('[data-first-holder-name]');
    const holderDob = document.querySelector('[data-first-holder-dob]');
    if (!signupName || !signupDob || !holderName || !holderDob) return;

    let nameEdited = false;
    let dobEdited = false;
    const holderInputs = [holderName, holderDob];
    const useBuyerChecked = () => !useBuyer || useBuyer.checked;
    const syncFirstHolder = () => {
        if (useBuyerChecked() || !nameEdited) {
            holderName.value = signupName.value;
        }
        if (useBuyerChecked() || !dobEdited) {
            holderDob.value = signupDob.value;
        }
    };
    const syncVisibility = () => {
        const checked = useBuyerChecked();
        firstTicket?.classList.toggle('st-ticket-fields--using-buyer', checked);
        holderInputs.forEach((input) => {
            input.required = !checked && input === holderName;
        });
        if (checked) {
            nameEdited = false;
            dobEdited = false;
            syncFirstHolder();
        }
    };
    holderName.addEventListener('input', () => { if (!useBuyerChecked()) nameEdited = true; });
    holderDob.addEventListener('input', () => { if (!useBuyerChecked()) dobEdited = true; });
    signupName.addEventListener('input', syncFirstHolder);
    signupName.addEventListener('change', syncFirstHolder);
    signupName.addEventListener('blur', syncFirstHolder);
    signupDob.addEventListener('input', syncFirstHolder);
    signupDob.addEventListener('change', syncFirstHolder);
    signupDob.addEventListener('blur', syncFirstHolder);
    useBuyer?.addEventListener('change', syncVisibility);
    syncFirstHolder();
    syncVisibility();
})();</script>
</body>
</html>
