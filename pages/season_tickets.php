<?php
/** Route: /season-tickets — season ticket sign-up (step 1: basket). Logic mirrors
 *  the live admin/season_tickets_signup.php; presentation is the public-site design.
 *  Checkout (/season-tickets/checkout) is still served by the Hub. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/season.php';
require_once HUB_ROOT . '/lib/season_tickets.php';
require_once HUB_ROOT . '/lib/season_ticket_basket.php';

$pdo = db();
ensureSeasonTicketSchema($pdo);
season_ticket_basket_start();

$currentSeason = getCurrentSeason($pdo);
$types = $currentSeason ? getSeasonTicketTypes($pdo, (int) $currentSeason['id'], true) : [];
$sponsors = $currentSeason ? getSeasonTicketSponsors($pdo, (int) $currentSeason['id']) : [];
$typesById = [];
foreach ($types as $type) {
    $typesById[(int) $type['id']] = $type;
}

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
}

function st_type_code(array $item): string
{
    return seasonTicketTypeCode((string) ($item['type_code'] ?? $item['code'] ?? $item['type_name'] ?? $item['name'] ?? ''));
}

function st_description(array $type): string
{
    $code = st_type_code($type);
    if (str_starts_with($code, 'adult')) {
        return 'Full season ticket for adult supporters.';
    }
    if ($code === 'concession') {
        return 'Discounted season ticket for supporters aged 60 and over.';
    }
    if ($code === 'wee_vics') {
        return 'Free junior season ticket for young supporters.';
    }
    return 'Season ticket for this season.';
}

function st_sponsor_logo(array $sponsor): string
{
    $nameCode = seasonTicketTypeCode((string) ($sponsor['name'] ?? ''));
    if (str_contains($nameCode, 'jazza') && str_contains($nameCode, 'photography')) {
        return 'season-ticket-jazzamcg-photography-black.png';
    }
    return basename((string) ($sponsor['logo_path'] ?? ''));
}

/** A Wee Vics ticket must be bought alongside an Adult or Concession ticket. */
function st_rule_errors(array $basketItems, array $typesById): array
{
    $wee = false;
    $adult = false;
    foreach ($basketItems as $item) {
        $code = st_type_code(['code' => (string) ($typesById[(int) $item['type_id']]['code'] ?? '')] + $item);
        $wee = $wee || str_contains($code, 'wee_vics') || str_contains($code, 'under_16') || str_contains($code, 'kid');
        $adult = $adult || str_contains($code, 'adult') || str_contains($code, 'concession');
    }
    return $wee && !$adult ? ['A Wee Vics season ticket must be ordered with an Adult or Concession season ticket.'] : [];
}

function st_render_basket(array $basket, array $typesById, string $csrf, bool $codeActive): string
{
    $ruleErrors = st_rule_errors($basket['items'] ?? [], $typesById);
    ob_start();
    ?>
    <aside class="osummary stbasket" id="basket" aria-live="polite" aria-label="Your basket">
      <h2>Your basket</h2>
      <?php if ($codeActive): ?><p class="stbasket__code">Free signup code applied.</p><?php endif; ?>
      <?php if (empty($basket['items'])): ?>
        <p class="stbasket__empty">No tickets added yet. Choose a ticket to get started.</p>
      <?php else: ?>
        <ul class="stbasket__items">
          <?php foreach ($basket['items'] as $item): ?>
            <li>
              <span><?= e((string) $item['type_name']) ?></span>
              <span class="stbasket__price"><?= e(gbp((float) $item['price'])) ?></span>
              <form method="post" data-basket-form>
                <?= $csrf ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                <button type="submit" class="bline__remove" aria-label="Remove <?= e((string) $item['type_name']) ?> from basket">Remove</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="osummary__row osummary__row--total"><span>Total</span><span><?= e(gbp((float) $basket['total'])) ?></span></div>
        <?php foreach ($ruleErrors as $re): ?><div class="notice notice--warn"><p><?= e($re) ?></p></div><?php endforeach; ?>
        <?php if (!$ruleErrors): ?>
          <a class="btn btn--lg" style="width:100%;margin-top:1rem" href="/season-tickets/checkout">Enter ticket details →</a>
        <?php else: ?>
          <button type="button" class="btn btn--lg" style="width:100%;margin-top:1rem" disabled>Enter ticket details →</button>
        <?php endif; ?>
        <p class="pdp__note">You&rsquo;ll add the holder details next, then pay securely online.</p>
      <?php endif; ?>
    </aside>
    <?php
    return (string) ob_get_clean();
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
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
            }
        } elseif ($action === 'remove') {
            season_ticket_basket_remove((string) ($_POST['item_id'] ?? ''));
        }
        if ($error === '' && !$ajax) {
            redirect(url('season-tickets') . '#basket');
        }
    }
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => $error === '',
            'error' => $error,
            'basket_html' => st_render_basket(season_ticket_basket_summary($types), $typesById, csrf_field(), $freeSignupCodeActive),
        ], JSON_THROW_ON_ERROR);
        exit;
    }
}

$basket = season_ticket_basket_summary($types);
$csrf = csrf_field();

require_once HUB_ROOT . '/member_auth.php';
$loggedInHolder = member_auth_current_holder();

$seasonLabel = $currentSeason ? trim((string) $currentSeason['name']) : '';
$seasonShort = $seasonLabel;
if (preg_match('#^(\d{4})\s*[/-]\s*(?:\d{2})?(\d{2})$#', $seasonLabel, $m)) {
    $seasonShort = $m[1] . '/' . $m[2];
}

set_meta([
    'title' => 'Season Tickets' . ($seasonShort !== '' ? ' ' . $seasonShort : ''),
    'description' => 'Buy ' . ($seasonShort !== '' ? $seasonShort . ' ' : '') . 'season tickets for ' . club('club_name') . ' home matches at ' . club('ground_name', 'Campbell Park') . ' online.',
]);
?>
<?php seo_breadcrumbs([['Commercial', url('commercial')], ['Season tickets', url('season-tickets')]]); ?>
<?php partial('page_hero', [
    'eyebrow' => 'Every home game',
    'title'   => 'Season Tickets' . ($seasonLabel !== '' ? ' ' . $seasonLabel : ''),
    'sub'     => 'Add season tickets to your basket, then enter the holder details.',
]); ?>

<div class="page">
  <div class="container">
    <?php if (isset($_GET['success'])): ?><div class="notice notice--ok"><p><strong>Thank you!</strong> Your payment was received and your season ticket order is confirmed. A confirmation email is on its way, and you can view your tickets in <a href="/members/index.php">your account</a>.</p></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice notice--err"><p><?= e($error) ?></p></div><?php endif; ?>
    <?php if ($freeSignupCodeMessage !== ''): ?><div class="notice <?= $freeSignupCodeActive ? 'notice--ok' : 'notice--warn' ?>"><p><?= e($freeSignupCodeMessage) ?></p></div><?php endif; ?>
    <?php if ($loggedInHolder): ?>
      <div class="notice notice--ok stacct">
        <p>Logged in as <strong><?= e((string) $loggedInHolder['name']) ?></strong><?php if (!empty($loggedInHolder['email'])): ?> (<?= e((string) $loggedInHolder['email']) ?>)<?php endif; ?></p>
        <span>
          <a class="btn btn--ghost btn--sm" href="/members/index.php">Account details</a>
          <a class="btn btn--ghost btn--sm" href="/members/logout.php?all=1&amp;return=%2Fseason-tickets">Log out</a>
        </span>
      </div>
    <?php endif; ?>

    <?php if (!$currentSeason || !$types): ?>
      <div class="emptystate">
        <p><strong>Season tickets aren&rsquo;t currently on sale.</strong></p>
        <p>Please check back soon or <a href="<?= e(url('contact')) ?>">contact the club</a>.</p>
      </div>
    <?php else: ?>
      <div class="basket-layout">
        <div class="sttypes">
          <?php foreach ($types as $type): ?>
            <?php $isFree = (float) $type['price'] <= 0; $isWee = str_contains(st_type_code($type), 'wee_vics'); ?>
            <form method="post" class="stcard<?= $isFree ? ' stcard--free' : '' ?>" data-basket-form>
              <?= $csrf ?>
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="type_id" value="<?= (int) $type['id'] ?>">
              <div class="stcard__head">
                <span class="stcard__kicker">Season ticket<?= $seasonShort !== '' ? ' · ' . e($seasonShort) : '' ?></span>
                <h2><?= e((string) $type['name']) ?></h2>
              </div>
              <div class="stcard__body">
                <p><?= e(st_description($type)) ?></p>
                <ul class="stcard__covers">
                  <li class="is-yes">All WOSFL home matches</li>
                  <li class="is-no">Cup matches not included</li>
                  <li class="is-no">Friendlies not included</li>
                </ul>
                <?php if ($isWee): ?><p class="stcard__note">Must be bought with an Adult or Concession ticket.</p><?php endif; ?>
                <div class="stcard__price"><?= $isFree ? 'Free' : e(gbp((float) $type['price'])) ?></div>
                <button type="submit" class="btn btn--lg">Add to basket</button>
              </div>
            </form>
          <?php endforeach; ?>
        </div>

        <?= st_render_basket($basket, $typesById, $csrf, $freeSignupCodeActive) ?>
      </div>
    <?php endif; ?>

    <?php if ($sponsors): ?>
      <div class="stsponsors">
        <span class="eyebrow">This season&rsquo;s tickets are proudly sponsored by</span>
        <div class="stsponsors__logos">
          <?php foreach ($sponsors as $sponsor): ?>
            <img src="/uploads/sponsors/<?= rawurlencode(st_sponsor_logo($sponsor)) ?>" alt="<?= e((string) $sponsor['name']) ?>" loading="lazy">
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <p class="stlinks"><a href="<?= e(url('fixtures')) ?>">Fixtures &amp; results &rarr;</a> &nbsp;·&nbsp; <a href="<?= e(url('tickets')) ?>">Single match tickets &rarr;</a></p>
  </div>
</div>

<script>
(function () {
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-basket-form]')) return;
    event.preventDefault();
    var button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: new FormData(form)
    }).then(function (r) { return r.json().then(function (p) { return [r, p]; }); })
      .then(function (rp) {
        if (!rp[0].ok || !rp[1].ok) throw new Error(rp[1].error || 'Basket could not be updated.');
        var basket = document.getElementById('basket');
        if (basket && rp[1].basket_html) basket.outerHTML = rp[1].basket_html;
      })
      .catch(function (err) { window.alert(err.message || 'Basket could not be updated.'); })
      .then(function () { if (button) button.disabled = false; });
  });
})();
</script>
