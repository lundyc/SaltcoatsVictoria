<?php
/** Route: /hospitality/book?fixture_id=N — booking form, then Stripe Checkout. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/hospitality.php';
require_once HUB_ROOT . '/lib/stripe.php';

$pdo = db();
$settings = hospitality_settings($pdo);
$fixtureId = (int) ($_GET['fixture_id'] ?? $_POST['fixture_id'] ?? 0);
$fixture = $fixtureId > 0 ? hospitality_fixture($pdo, $fixtureId) : null;
$valid = $fixture && (int) $fixture['is_home'] === 1 && (string) $fixture['match_date'] >= date('Y-m-d')
    && stripos((string) $fixture['competition'], 'friendly') === false;

if (!$valid) {
    redirect(url('hospitality'));
}

$errors = [];
$f = ['type' => 'individual', 'guests' => '2', 'name' => '', 'email' => '', 'phone' => '', 'company' => '', 'dietary' => '', 'notes' => ''];
$left = hospitality_places_left($pdo, $settings, $fixtureId);
$groupMin = (int) $settings['group_min'];
$indMax = (int) $settings['individual_max'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($f as $k => $_) {
        $f[$k] = trim((string) ($_POST[$k] ?? $f[$k]));
    }
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    }
    if ($f['name'] === '') { $errors[] = 'Please enter your name.'; }
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Please enter a valid email address.'; }
    if ($f['phone'] === '') { $errors[] = 'Please enter a contact phone number.'; }
    if (!isset($_POST['dietary_declared'])) { $errors[] = 'Please confirm that you have declared any dietary requirements for your party.'; }
    if (!isset($_POST['no_refunds'])) { $errors[] = 'Please confirm that you understand bookings are non-refundable.'; }
    if (!stripe_is_configured()) { $errors[] = 'Online payment isn\'t available right now. Please contact the club.'; }

    if (!$errors) {
        try {
            $created = hospitality_create_booking($pdo, $fixture, [
                'type' => $f['type'], 'guests' => (int) $f['guests'], 'name' => $f['name'], 'email' => $f['email'], 'phone' => $f['phone'],
                'company' => $f['company'], 'dietary' => $f['dietary'], 'notes' => $f['notes'],
            ]);
            $booking = hospitality_booking($pdo, $created['id']);
            $base = current_url_origin();
            $checkout = hospitality_stripe_checkout(
                $pdo, $booking, $fixture,
                $base . url('hospitality/booking/' . $created['token']) . '?paid=1',
                $base . url('hospitality/book') . '?fixture_id=' . $fixtureId . '&cancelled=1'
            );
            redirect($checkout['url']);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$ts = strtotime((string) $fixture['match_date']);
$ko = !empty($fixture['kickoff_time']) ? format_time((string) $fixture['kickoff_time']) : '';
$arrive = hospitality_arrival_time($settings, (string) ($fixture['kickoff_time'] ?? ''));
$isCup = hospitality_is_cup($fixture);
$priceInd = hospitality_price($settings, $fixture, 'individual');
$priceGrp = hospitality_price($settings, $fixture, 'group');

set_meta(['title' => 'Book Big Js hospitality', 'robots' => 'noindex, nofollow']);
?>
<?php seo_breadcrumbs([['Commercial', url('commercial')], ['Hospitality', url('hospitality')], ['Book', url('hospitality/book')]]); ?>
<?php partial('page_hero', [
    'eyebrow' => 'Big Js hospitality',
    'title'   => 'Book your places',
    'sub'     => 'Saltcoats Victoria v ' . $fixture['opponent'] . ' · ' . date('l j F Y', (int) $ts) . ($ko !== '' ? ' · KO ' . $ko : ''),
    'back'    => ['href' => url('hospitality'), 'label' => 'All games'],
]); ?>

<div class="page">
  <div class="container">
    <?php if (isset($_GET['cancelled'])): ?><div class="notice notice--warn"><p>Payment was cancelled and nothing has been charged. Your places are held for a few minutes while you try again.</p></div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="notice notice--err"><p><?= e($err) ?></p></div><?php endforeach; ?>

    <?php if ($left < 1): ?>
      <div class="emptystate"><p>Sorry, this game is fully booked. <a href="<?= e(url('hospitality')) ?>">See other games</a>.</p></div>
    <?php else: ?>
    <div class="basket-layout">
      <form method="post" class="checkout-form" id="hospForm" data-ind="<?= e((string) $priceInd) ?>" data-grp="<?= e((string) $priceGrp) ?>" data-group-min="<?= $groupMin ?>" data-ind-max="<?= $indMax ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="fixture_id" value="<?= $fixtureId ?>">

        <h2>Your booking</h2>
        <div class="hosp-types">
          <label class="hosp-type"><input type="radio" name="type" value="individual" <?= $f['type'] !== 'group' ? 'checked' : '' ?>><span><strong>Individual</strong><small>1 to <?= $indMax ?> places &middot; <?= e(gbp($priceInd)) ?> each</small></span></label>
          <label class="hosp-type"><input type="radio" name="type" value="group" <?= $f['type'] === 'group' ? 'checked' : '' ?>><span><strong>Group</strong><small><?= $groupMin ?>+ places &middot; <?= e(gbp($priceGrp)) ?> each</small></span></label>
        </div>
        <label class="field"><span>Number of places <?php if ($left <= 8): ?><em>(only <?= $left ?> left for this game)</em><?php endif; ?></span>
          <input type="number" name="guests" id="hospGuests" min="1" max="<?= $left ?>" value="<?= e($f['guests']) ?>" required></label>

        <h2>Your details</h2>
        <label class="field"><span>Full name</span><input name="name" required value="<?= e($f['name']) ?>" autocomplete="name"></label>
        <div class="field-row">
          <label class="field"><span>Email</span><input name="email" type="email" required value="<?= e($f['email']) ?>" autocomplete="email"></label>
          <label class="field"><span>Phone</span><input name="phone" required value="<?= e($f['phone']) ?>" autocomplete="tel"></label>
        </div>
        <label class="field"><span>Company <em>(optional)</em></span><input name="company" value="<?= e($f['company']) ?>" autocomplete="organization"></label>

        <h2>Dietary requirements</h2>
        <label class="field"><span>Any dietary requirements for your party <em>(please list everyone's)</em></span>
          <textarea name="dietary" rows="3" placeholder="e.g. 2 vegetarian, 1 coeliac. Leave blank if none."><?= e($f['dietary']) ?></textarea></label>
        <label class="field"><span>Anything else we should know <em>(optional)</em></span><textarea name="notes" rows="2"><?= e($f['notes']) ?></textarea></label>

        <label class="check"><input type="checkbox" name="dietary_declared" value="1" required <?= isset($_POST['dietary_declared']) ? 'checked' : '' ?>><span>I have declared all dietary requirements for my party above (or none apply).</span></label>
        <label class="check"><input type="checkbox" name="no_refunds" value="1" required <?= isset($_POST['no_refunds']) ? 'checked' : '' ?>><span>I understand that bookings are <strong>non-refundable</strong>.</span></label>

        <button type="submit" class="btn btn--lg" style="width:100%;margin-top:1rem" id="hospPay">Pay <?= e(gbp($priceInd * (int) $f['guests'])) ?> by card</button>
        <p class="pdp__note">You&rsquo;ll be taken to Stripe&rsquo;s secure payment page. Card details are never seen by the club.</p>
      </form>

      <aside class="osummary" aria-label="Booking summary">
        <h2>Summary</h2>
        <div class="osummary__row"><span>Game</span><span>v <?= e((string) $fixture['opponent']) ?></span></div>
        <div class="osummary__row"><span>Date</span><span><?= e(date('D, j M Y', (int) $ts)) ?></span></div>
        <div class="osummary__row"><span>Kick-off</span><span><?= $ko !== '' ? e($ko) : 'TBC' ?></span></div>
        <div class="osummary__row"><span>Arrive from</span><span><?= $arrive !== '' ? e($arrive) : '1h 30m before KO' ?></span></div>
        <div class="osummary__row"><span>Game type</span><span><?= $isCup ? 'Cup' : 'League' ?></span></div>
        <div class="osummary__row"><span>Price each</span><span id="hospUnit"><?= e(gbp($priceInd)) ?></span></div>
        <div class="osummary__row osummary__row--total"><span>Total</span><span id="hospTotal"><?= e(gbp($priceInd * (int) $f['guests'])) ?></span></div>
        <p class="pdp__note">Big Js, <?= e(club('ground_name', 'Campbell Park')) ?>. Non-refundable.</p>
      </aside>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  var form = document.getElementById('hospForm');
  if (!form) return;
  var guests = document.getElementById('hospGuests');
  var ind = parseFloat(form.dataset.ind), grp = parseFloat(form.dataset.grp);
  var gMin = parseInt(form.dataset.groupMin, 10), iMax = parseInt(form.dataset.indMax, 10);
  function money(n) { return '£' + n.toFixed(2); }
  function update() {
    var type = form.querySelector('input[name="type"]:checked').value;
    var n = parseInt(guests.value, 10) || 0;
    if (type === 'group' && n < gMin) { n = gMin; guests.value = n; }
    if (type === 'individual' && n > iMax) { n = iMax; guests.value = n; }
    guests.min = type === 'group' ? gMin : 1;
    var unit = type === 'group' ? grp : ind;
    document.getElementById('hospUnit').textContent = money(unit);
    document.getElementById('hospTotal').textContent = money(unit * n);
    document.getElementById('hospPay').textContent = 'Pay ' + money(unit * n) + ' by card';
  }
  form.addEventListener('change', update); guests.addEventListener('input', update); update();
})();
</script>
