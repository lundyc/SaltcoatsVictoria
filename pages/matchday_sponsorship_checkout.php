<?php
/** Route: /matchday-sponsorship/checkout — the request form. No payment: the club reviews it and emails a payment link once approved. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/match_sponsorship_shop.php';
require_once HUB_ROOT . '/lib/sponsorship_requests.php';

$pdo = db();
match_sponsorship_ensure_schema($pdo);
match_sponsorship_basket_start();
$seasonId = pub_current_season_id();
$summary = match_sponsorship_basket_summary($pdo, $seasonId);
if (!$summary['items']) {
    redirect(url('matchday-sponsorship'));
}

$errors = [];
$f = ['name' => '', 'email' => '', 'phone' => '', 'business' => '', 'address' => '', 'website' => '', 'message' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($f as $k => $_) {
        $f[$k] = mb_substr(trim((string) ($_POST['buyer_' . $k] ?? '')), 0, $k === 'message' ? 1500 : 200);
    }
    $isBusiness = isset($_POST['is_business']);
    if (!csrf_check()) { $errors[] = 'Your session expired. Please try again.'; }
    if ($f['name'] === '') { $errors[] = 'Please enter your name.'; }
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Please enter a valid email address.'; }
    if ($f['phone'] === '') { $errors[] = 'Please enter a contact phone number.'; }
    if ($isBusiness && $f['business'] === '') { $errors[] = 'Please enter your business name.'; }

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
            redirect(url('matchday-sponsorship/thankyou') . '?sent=1');
        }
    }

    if (!$errors) {
        $res = sponsorship_request_submit($pdo, 'match', [
            'name' => $f['name'], 'email' => $f['email'], 'phone' => $f['phone'], 'is_business' => $isBusiness,
            'business_name' => $isBusiness ? $f['business'] : '',
            'address' => $isBusiness ? $f['address'] : '', 'website_url' => $isBusiness ? $f['website'] : '',
            'consent_email' => isset($_POST['consent_email']), 'consent_sms' => isset($_POST['consent_sms']), 'consent_whatsapp' => isset($_POST['consent_whatsapp']),
        ], $seasonId, $summary['items'], $f['message']);
        if ($res['ok']) {
            enquiry_guard_record($pdo, $guard);
            $hits[] = $now;
            $_SESSION['contact_hits'] = array_values($hits);
            match_sponsorship_basket_clear();
            redirect(url('matchday-sponsorship/thankyou') . '?access=' . rawurlencode((string) $res['token']));
        }
        $errors = $res['errors'];
    }
}

set_meta(['title' => 'Sponsorship request', 'robots' => 'noindex, nofollow']);
?>
<?php seo_breadcrumbs([['Commercial', url('commercial')], ['Matchday sponsorship', url('matchday-sponsorship')], ['Request', url('matchday-sponsorship/checkout')]]); ?>
<?php partial('page_hero', [
    'eyebrow' => 'Matchday & match ball sponsorship',
    'title'   => 'Send your request',
    'sub'     => 'Tell us who is sponsoring. There is no payment now: we check every request and email you within 3 working days.',
    'back'    => ['href' => url('matchday-sponsorship'), 'label' => 'Back to my request'],
]); ?>
<div class="page">
  <div class="container">
    <?php foreach ($errors as $err): ?><div class="notice notice--err"><p><?= e($err) ?></p></div><?php endforeach; ?>
    <div class="basket-layout">
      <form method="post" class="checkout-form">
        <?= csrf_field() ?>
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
          <p>We&rsquo;d like to contact you about your sponsorship &mdash; for example to collect your logo and details and keep you updated. Please tick how we may contact you (as many as you like, or none):</p>
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
        <?php foreach ($summary['items'] as $it): ?>
          <div class="osummary__row"><span><?= e((string) $it['label']) ?></span><span><?= e(gbp((float) $it['price'])) ?></span></div>
        <?php endforeach; ?>
        <div class="osummary__row osummary__row--total"><span>Guide total</span><span><?= e(gbp((float) $summary['total'])) ?></span></div>
        <p class="pdp__note"><a href="<?= e(url('matchday-sponsorship')) ?>">Change my request</a></p>
      </aside>
    </div>
  </div>
</div>
<script>
(function () {
  var cb = document.getElementById('isBusiness'), f = document.getElementById('businessFields');
  if (cb && f) cb.addEventListener('change', function () { f.hidden = !cb.checked; });
})();
</script>
