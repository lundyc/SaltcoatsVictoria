<?php
declare(strict_types=1);

/** Route: /playersponsors/checkout — step 2: the request form (no payment; the club approves, then emails a pay link).
 *  Formerly a genuine multi-item
 *  Stripe Checkout Session (the sponsorship_agreements rows are only created once
 *  Stripe confirms payment, in the webhook — see lib/player_sponsorship_shop.php).
 *  Logic mirrors admin/playersponsors_checkout.php; presentation is the public design. */

require_once HUB_ROOT . '/lib/season.php';
require_once HUB_ROOT . '/lib/sponsorship_catalog.php';
require_once HUB_ROOT . '/lib/player_sponsorship_shop.php';
require_once HUB_ROOT . '/lib/player_sponsorship_basket.php';
require_once HUB_ROOT . '/lib/stripe.php';
require_once HUB_ROOT . '/lib/audit.php';
require_once HUB_ROOT . '/lib/sponsorship_requests.php';
$pdo = db();
ensurePlayerSponsorshipShopSchema($pdo);
player_sponsorship_basket_start();

$currentSeason = getCurrentSeason($pdo);
$seasonId = (int) ($currentSeason['id'] ?? 0);
$players = $seasonId > 0 ? player_sponsorship_shop_players_with_availability($pdo, $seasonId) : [];
$basket = player_sponsorship_basket_summary($players);

if (!$currentSeason || !$basket['items']) {
    header('Location: /playersponsors');
    exit;
}

$errors = [];
$csrfFieldHtml = csrf_field();
$f = ['name' => '', 'email' => '', 'phone' => '', 'business' => '', 'address' => '', 'website' => '', 'message' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($f as $k => $_) {
        $f[$k] = mb_substr(trim((string) ($_POST['buyer_' . $k] ?? '')), 0, $k === 'message' ? 1500 : 200);
    }
    $isBusiness = isset($_POST['is_business']);
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    if ($f['name'] === '') { $errors[] = 'Enter your name.'; }
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid email address.'; }
    if ($f['phone'] === '') { $errors[] = 'Enter a contact phone number.'; }
    if ($isBusiness && $f['business'] === '') { $errors[] = 'Enter your business name.'; }

    $now = time();
    $hits = array_filter((array) ($_SESSION['contact_hits'] ?? []), static fn ($t) => $t > $now - 900);
    if (!$errors && count($hits) >= 3) { $errors[] = 'You have sent several requests recently. Please try again later.'; }
    $guard = null;
    if (!$errors) {
        $guard = enquiry_guard($pdo, $_POST + ['name' => $f['name'], 'email' => $f['email'], 'message' => $f['message']]);
        if ($guard['status'] === 'limit') {
            $errors[] = $guard['message'];
        } elseif ($guard['status'] === 'spam') {
            enquiry_spam_log($guard['reason']);
            redirect(url('playersponsors/thankyou') . '?sent=1');
        }
    }

    if (!$errors) {
        $res = sponsorship_request_submit($pdo, 'player', [
            'name' => $f['name'], 'email' => $f['email'], 'phone' => $f['phone'], 'is_business' => $isBusiness,
            'business_name' => $isBusiness ? $f['business'] : '',
            'address' => $isBusiness ? $f['address'] : '', 'website_url' => $isBusiness ? $f['website'] : '',
            'consent_email' => isset($_POST['consent_email']), 'consent_sms' => isset($_POST['consent_sms']), 'consent_whatsapp' => isset($_POST['consent_whatsapp']),
        ], $seasonId, $basket['items'], $f['message']);
        if ($res['ok']) {
            enquiry_guard_record($pdo, $guard);
            $hits[] = $now;
            $_SESSION['contact_hits'] = array_values($hits);
            player_sponsorship_basket_clear();
            redirect(url('playersponsors/thankyou') . '?access=' . rawurlencode((string) $res['token']));
        }
        $errors = $res['errors'];
        $basket = player_sponsorship_basket_summary($players);
    }
}
?>
<?php set_meta(['title' => 'Sponsor a player request', 'robots' => 'noindex, nofollow']); ?>
<?php seo_breadcrumbs([['Commercial', url('commercial')], ['Sponsor a player', url('playersponsors')], ['Request', url('playersponsors/checkout')]]); ?>
<?php partial('page_hero', [
    'eyebrow' => 'Sponsor a player',
    'title'   => 'Send your request',
    'sub'     => 'Tell us who is sponsoring. There is no payment now: we check every request and email you within 3 working days.',
    'back'    => ['href' => url('playersponsors'), 'label' => 'Back to my request'],
]); ?>

<div class="page">
  <div class="container">
    <?php foreach ($errors as $err): ?><div class="notice notice--err"><p><?= e($err) ?></p></div><?php endforeach; ?>
    

    <?php if (!$basket['items']): ?>
      <div class="emptystate">
        <p>Your request is empty.</p>
        <p><a class="btn btn--sm" href="<?= e(url('playersponsors')) ?>">Choose a player to sponsor</a></p>
      </div>
    <?php else: ?>
      <div class="basket-layout">
        <form method="post" class="checkout-form">
          <?= $csrfFieldHtml ?>
          <?= enquiry_spam_fields() ?>
          <p class="hp" aria-hidden="true" style="position:absolute;left:-9999px"><label>Leave this blank <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
          <h2>Your details</h2>
          <label class="field"><span>Full name</span><input type="text" name="buyer_name" required autocomplete="name" value="<?= e($f['name']) ?>"></label>
          <div class="field-row">
            <label class="field"><span>Email</span><input type="email" name="buyer_email" required autocomplete="email" value="<?= e($f['email']) ?>"></label>
            <label class="field"><span>Phone</span><input type="text" name="buyer_phone" required autocomplete="tel" value="<?= e($f['phone']) ?>"></label>
          </div>

          <label class="check"><input type="checkbox" name="is_business" id="isBusiness" value="1" <?= isset($_POST['is_business']) ? 'checked' : '' ?>><span>I&rsquo;m sponsoring on behalf of a business</span></label>
          <div id="businessFields" <?= isset($_POST['is_business']) ? '' : 'hidden' ?>>
            <label class="field"><span>Business name</span><input type="text" name="buyer_business" value="<?= e($f['business']) ?>"></label>
            <label class="field"><span>Business address <em>(optional)</em></span><input type="text" name="buyer_address" value="<?= e($f['address']) ?>"></label>
            <label class="field"><span>Website <em>(optional)</em></span><input type="text" name="buyer_website" value="<?= e($f['website']) ?>"></label>
          </div>

          <fieldset class="consentbox">
            <legend>Staying in touch</legend>
            <p>We&rsquo;d like to contact you about your sponsorship &mdash; for example to collect your logo and details and keep you updated. Please tick how we may contact you (you can tick as many as you like, or none):</p>
            <label class="check"><input type="checkbox" name="consent_email" value="1" <?= isset($_POST['consent_email']) ? 'checked' : '' ?>><span><strong>Email</strong> &mdash; to the address above</span></label>
            <label class="check"><input type="checkbox" name="consent_sms" value="1" <?= isset($_POST['consent_sms']) ? 'checked' : '' ?>><span><strong>SMS</strong> &mdash; to the phone number above</span></label>
            <label class="check"><input type="checkbox" name="consent_whatsapp" value="1" <?= isset($_POST['consent_whatsapp']) ? 'checked' : '' ?>><span><strong>WhatsApp</strong> &mdash; to the phone number above</span></label>
            <small>You can change your mind at any time by telling the club. See our <a href="<?= e(url('privacy')) ?>">privacy policy</a>.</small>
          </fieldset>

          <label class="field"><span>Anything we should know? <em>(optional)</em></span><textarea name="buyer_message" rows="4"><?= e($f['message']) ?></textarea></label>
          <button type="submit" class="btn btn--lg" style="width:100%;margin-top:1rem">Send my request</button>
          <p class="pdp__note">No payment is taken now. Once the club has approved your request we will email you a secure link to pay by card.</p>
        </form>

        <aside class="osummary" aria-label="Order summary">
          <h2>Your request</h2>
          <?php foreach ($basket['items'] as $item): ?>
            <div class="osummary__row">
              <span><?= e((string) $item['player_name']) ?> &mdash; <?= e(str_replace(' Sponsorship', '', (string) player_sponsorship_shop_package_label((string) $item['package_code']))) ?></span>
              <span><?= e(gbp((float) $item['price'])) ?></span>
            </div>
          <?php endforeach; ?>
          <div class="osummary__row osummary__row--total"><span>Guide total</span><span><?= e(gbp((float) $basket['total'])) ?></span></div>
          <p class="pdp__note"><a href="<?= e(url('playersponsors')) ?>">Change my request</a></p>
        </aside>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  var cb = document.getElementById('isBusiness');
  var fields = document.getElementById('businessFields');
  if (cb && fields) cb.addEventListener('change', function () { fields.hidden = !cb.checked; });
})();
</script>
