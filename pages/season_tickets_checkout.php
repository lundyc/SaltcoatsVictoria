<?php
declare(strict_types=1);

/** Route: /season-tickets/checkout — step 2: identify the buyer (log in or sign up),
 *  capture the holder details for each ticket and place the order. Logic mirrors the
 *  live admin/season_tickets_checkout.php; presentation is the public-site design.
 *  Paid public orders always go through Stripe. */

require_once HUB_ROOT . '/lib/season.php';
require_once HUB_ROOT . '/lib/season_tickets.php';
require_once HUB_ROOT . '/lib/season_ticket_basket.php';
require_once HUB_ROOT . '/lib/season_ticket_stripe.php';
require_once HUB_ROOT . '/lib/people.php';
require_once HUB_ROOT . '/member_auth.php';
$pdo = db();
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
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'saltcoatsvictoria.co.uk');
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
<?php
set_meta(['title' => 'Season ticket checkout', 'robots' => 'noindex, nofollow']);
$stCount = count($basket['items']);
?>
<?php seo_breadcrumbs([['Commercial', url('commercial')], ['Season tickets', url('season-tickets')], ['Checkout', url('season-tickets/checkout')]]); ?>
<?php partial('page_hero', [
    'eyebrow' => 'Season tickets',
    'title'   => 'Ticket details',
    'sub'     => 'Tell us who the tickets are for, then pay securely by card.',
    'back'    => ['href' => url('season-tickets'), 'label' => 'Back to basket'],
]); ?>

<div class="page">
  <div class="container">
    <?php foreach ($errors as $err): ?><div class="notice notice--err"><p><?= e($err) ?></p></div><?php endforeach; ?>
    <?php if (isset($_GET['cancelled'])): ?><div class="notice notice--warn"><p>Payment was cancelled — no money was taken. Your order is saved below, ready to try again.</p></div><?php endif; ?>
    <?php if ($freeSignupCodeActive): ?><div class="notice notice--ok"><p>Free signup code applied. No payment will be taken for this order.</p></div><?php endif; ?>

    <div class="basket-layout">
      <form method="post" class="checkout-form">
        <?= $csrfFieldHtml ?>

        <?php if ($loggedIn && $buyerHolder): ?>
          <h2>Your account</h2>
          <p>Checking out as <strong><?= e((string) $buyerHolder['name']) ?></strong> (<?= e((string) $buyerHolder['email']) ?>). We&rsquo;ll use your account contact details for this order, so the ticket details below only need the person named on each season ticket.</p>
          <?php if ($buyerAlreadyHasCurrentSeasonTicket): ?>
            <div class="notice notice--warn"><p>You already have a season ticket for this season, so the holder details below have been left blank for someone else.</p></div>
          <?php else: ?>
            <div class="notice notice--ok"><p>Your details have been filled into the first ticket. You can change them if this ticket is for someone else.</p></div>
          <?php endif; ?>
        <?php else: ?>
          <h2>Your details</h2>
          <div class="stmode" role="tablist">
            <button type="button" class="stmode__btn is-active" data-mode="signup" id="modeSignupBtn">I&rsquo;m new here</button>
            <button type="button" class="stmode__btn" data-mode="login" id="modeLoginBtn">I have an account</button>
          </div>
          <input type="hidden" name="checkout_mode" id="checkoutModeInput" value="<?= e($checkoutMode) ?>">

          <div id="signupFields">
            <label class="field"><span>Full name</span><input type="text" name="signup_name" autocomplete="name" value="<?= e((string) ($_POST['signup_name'] ?? '')) ?>"></label>
            <div class="field-row">
              <label class="field"><span>Date of birth</span><input type="date" name="signup_dob" value="<?= e((string) ($_POST['signup_dob'] ?? '')) ?>"></label>
              <label class="field"><span>Email</span><input type="email" name="signup_email" autocomplete="email" value="<?= e((string) ($_POST['signup_email'] ?? '')) ?>"></label>
            </div>
            <div class="field-row">
              <label class="field"><span>Phone</span><input type="text" name="signup_phone" autocomplete="tel" value="<?= e((string) ($_POST['signup_phone'] ?? '')) ?>"></label>
              <label class="field"><span>Address <em>(only if posting)</em></span><input type="text" name="signup_address" autocomplete="street-address" value="<?= e((string) ($_POST['signup_address'] ?? '')) ?>"></label>
            </div>
            <label class="field"><span>Set a password <em>(optional — lets you log in next time)</em></span><input type="password" name="signup_password" minlength="8" autocomplete="new-password"></label>
            <label class="check"><input type="checkbox" name="marketing_opt_in" id="signupOptIn" value="1" checked><span>Keep me posted about club news, deals and offers</span></label>
          </div>
          <div id="loginFields" hidden>
            <label class="field"><span>Email</span><input type="email" name="login_email" autocomplete="email" value="<?= e((string) ($_POST['login_email'] ?? '')) ?>"></label>
            <label class="field"><span>Password</span><input type="password" name="login_password" autocomplete="current-password"></label>
          </div>
        <?php endif; ?>

        <h2>Season ticket details</h2>
        <p class="pdp__note">Enter the name and date of birth for each person who will hold a season ticket. Contact details are taken once from the account or signup details above.</p>
        <?php foreach ($basket['items'] as $index => $item): ?>
          <?php $itemDetails = $holderDetails[(string) $item['id']] ?? season_ticket_checkout_holder_defaults(null); ?>
          <?php $firstTicketUsesBuyer = $index === 0 && $firstHolderUsesBuyer; ?>
          <?php $canUseBuyerForFirstTicket = $index === 0 && (!$loggedIn || !$buyerAlreadyHasCurrentSeasonTicket); ?>
          <div class="stholder<?= $firstTicketUsesBuyer ? ' stholder--using-buyer' : '' ?>" <?= $index === 0 ? 'data-first-ticket-holder' : '' ?>>
            <h3><span class="stholder__no"><?= $index + 1 ?></span> <?= e((string) $item['type_name']) ?> ticket</h3>
            <?php if ($canUseBuyerForFirstTicket): ?>
              <label class="check"><input type="checkbox" name="first_holder_uses_buyer" id="firstHolderUsesBuyer" value="1" <?= $firstTicketUsesBuyer ? 'checked' : '' ?> data-first-holder-uses-buyer><span>Use my details for this season ticket</span></label>
            <?php endif; ?>
            <div class="field-row stholder__inputs">
              <label class="field"><span>Ticket holder name</span>
                <input type="text" name="holders[<?= e((string) $item['id']) ?>][name]" value="<?= e((string) $itemDetails['name']) ?>" <?= $firstTicketUsesBuyer ? '' : 'required' ?> <?= $index === 0 ? 'data-first-holder-name' : '' ?>></label>
              <label class="field"><span>Date of birth</span>
                <input type="date" name="holders[<?= e((string) $item['id']) ?>][date_of_birth]" value="<?= e((string) $itemDetails['date_of_birth']) ?>" <?= $index === 0 ? 'data-first-holder-dob' : '' ?>></label>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if ($freeSignupCodeActive): ?>
          <div class="notice notice--ok"><p>Payment bypassed by free signup code.</p></div>
        <?php else: ?>
          <p class="pdp__note">Payment is by card through Stripe after you place the order.</p>
        <?php endif; ?>

        <h2>Terms and conditions</h2>
        <div class="checkout-terms">
          <?php foreach (preg_split('/\R{2,}/', trim($seasonTicketTerms)) ?: [] as $paragraph): ?>
            <p><?= nl2br(e(trim($paragraph))) ?></p>
          <?php endforeach; ?>
        </div>
        <label class="check"><input type="checkbox" name="terms_accepted" id="termsAccepted" value="1" required <?= isset($_POST['terms_accepted']) ? 'checked' : '' ?>><span>I accept the season ticket terms and conditions.</span></label>

        <button type="submit" class="btn btn--lg" style="width:100%;margin-top:1rem"><?= !$freeSignupCodeActive && $basket['total'] > 0 ? 'Place order and pay by card' : 'Place order' ?></button>
      </form>

      <aside class="osummary" aria-label="Order summary">
        <h2>Order summary</h2>
        <?php foreach ($basket['items'] as $item): ?>
          <div class="osummary__row"><span><?= e((string) $item['type_name']) ?></span><span><?= e($freeSignupCodeActive ? gbp(0.00) : gbp((float) $item['price'])) ?></span></div>
        <?php endforeach; ?>
        <div class="osummary__row osummary__row--total"><span>Total</span><span><?= e($freeSignupCodeActive ? gbp(0.00) : gbp($basket['total'])) ?></span></div>
        <p class="pdp__note"><a href="<?= e(url('season-tickets')) ?>">Edit basket</a></p>
      </aside>
    </div>

    <?php if ($sponsors): ?>
      <div class="stsponsors">
        <span class="eyebrow">This season&rsquo;s tickets are proudly sponsored by</span>
        <div class="stsponsors__logos">
          <?php foreach ($sponsors as $sponsor): ?>
            <img src="/uploads/sponsors/<?= rawurlencode(season_ticket_checkout_sponsor_logo_path($sponsor)) ?>" alt="<?= e((string) $sponsor['name']) ?>" loading="lazy">
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var modeInput = document.getElementById('checkoutModeInput');
  if (modeInput) {
    var signupBtn = document.getElementById('modeSignupBtn');
    var loginBtn = document.getElementById('modeLoginBtn');
    var signupFields = document.getElementById('signupFields');
    var loginFields = document.getElementById('loginFields');
    var setMode = function (mode) {
      modeInput.value = mode;
      signupBtn.classList.toggle('is-active', mode === 'signup');
      loginBtn.classList.toggle('is-active', mode === 'login');
      signupFields.hidden = mode !== 'signup';
      loginFields.hidden = mode !== 'login';
    };
    signupBtn.addEventListener('click', function () { setMode('signup'); });
    loginBtn.addEventListener('click', function () { setMode('login'); });
    setMode(modeInput.value || 'signup');
  }

  var signupName = document.querySelector('[name="signup_name"]');
  var signupDob = document.querySelector('[name="signup_dob"]');
  var useBuyer = document.querySelector('[data-first-holder-uses-buyer]');
  var firstTicket = document.querySelector('[data-first-ticket-holder]');
  var holderName = document.querySelector('[data-first-holder-name]');
  var holderDob = document.querySelector('[data-first-holder-dob]');
  if (!signupName || !signupDob || !holderName || !holderDob) return;

  var nameEdited = false, dobEdited = false;
  var useBuyerChecked = function () { return !useBuyer || useBuyer.checked; };
  var syncFirstHolder = function () {
    if (useBuyerChecked() || !nameEdited) holderName.value = signupName.value;
    if (useBuyerChecked() || !dobEdited) holderDob.value = signupDob.value;
  };
  var syncVisibility = function () {
    var checked = useBuyerChecked();
    if (firstTicket) firstTicket.classList.toggle('stholder--using-buyer', checked);
    holderName.required = !checked;
    if (checked) { nameEdited = false; dobEdited = false; syncFirstHolder(); }
  };
  holderName.addEventListener('input', function () { if (!useBuyerChecked()) nameEdited = true; });
  holderDob.addEventListener('input', function () { if (!useBuyerChecked()) dobEdited = true; });
  ['input', 'change', 'blur'].forEach(function (ev) {
    signupName.addEventListener(ev, syncFirstHolder);
    signupDob.addEventListener(ev, syncFirstHolder);
  });
  if (useBuyer) useBuyer.addEventListener('change', syncVisibility);
  syncFirstHolder();
  syncVisibility();
})();
</script>
