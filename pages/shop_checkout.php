<?php
/** Route: /shop/checkout. Mirrors live shop/checkout.php; Stripe return URLs
 *  point back at the public routes. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/shop.php';
require_once HUB_ROOT . '/lib/shop_basket.php';

shop_ensure_schema(db());
$settings = shop_get_settings(db());

if (($settings['shop_enabled'] ?? '1') !== '1') {
    redirect(url('shop'));
}

$summary = shop_basket_summary(db());
if (!$summary['items']) {
    redirect(url('shop/basket'));
}
if ($summary['has_blocked']) {
    redirect(url('shop/basket') . '?m=' . rawurlencode('Please remove the unavailable items before checking out.'));
}

$origin = current_url_origin();
$deliveryEnabled = shop_delivery_enabled(db());
$deliveryFee = 0.0; // set from the chosen shipping method once an address is entered
$countries = shop_countries();
$preInfoCheckout = shop_preorder_info(db(), array_map(static fn(array $i): int => (int) $i['product_id'], $summary['items']));
$errors = [];
$form = [
    'name' => trim((string) ($_POST['customer_name'] ?? '')),
    'email' => trim((string) ($_POST['customer_email'] ?? '')),
    'phone' => trim((string) ($_POST['customer_phone'] ?? '')),
    'note' => trim((string) ($_POST['customer_note'] ?? '')),
    'marketing' => !empty($_POST['marketing_opt_in']),
    'fulfilment_method' => ($deliveryEnabled && (string) ($_POST['fulfilment_method'] ?? 'collection') === 'delivery') ? 'delivery' : 'collection',
    'delivery_address' => trim((string) ($_POST['delivery_address'] ?? '')),
    'delivery_country' => strtoupper(trim((string) ($_POST['delivery_country'] ?? 'GB'))),
    'delivery_postcode' => trim((string) ($_POST['delivery_postcode'] ?? '')),
    'shipping_method_id' => (int) ($_POST['shipping_method_id'] ?? 0),
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['action'] ?? '') === 'place_order') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    }
    if ($form['name'] === '') {
        $errors[] = 'Please enter your name.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($form['phone'] === '') {
        $errors[] = 'Please enter a contact phone number.';
    }
    if (empty($_POST['accept_terms'])) {
        $errors[] = 'Please tick the box to accept the order terms.';
    }
    if ($form['fulfilment_method'] === 'delivery' && $form['delivery_address'] === '') {
        $errors[] = 'Please enter a delivery address.';
    }
    if (!stripe_is_configured()) {
        $errors[] = 'Online payment is not available right now. Please contact the club to place your order.';
    }

    $summary = shop_basket_summary(db());
    if (!$summary['items']) {
        redirect(url('shop/basket'));
    }
    if ($summary['has_blocked']) {
        $errors[] = 'Some items are no longer available — please review your basket.';
    }

    if (!$errors) {
        $createdOrder = null;
        try {
            $order = shop_create_order(
                db(),
                [
                    'name' => $form['name'], 'email' => $form['email'], 'phone' => $form['phone'],
                    'note' => $form['note'], 'marketing_opt_in' => $form['marketing'],
                ],
                shop_basket_order_lines($summary),
                [
                    'discount_code' => $summary['discount_code'], 'terms_accepted' => true,
                    'fulfilment_method' => $form['fulfilment_method'], 'delivery_address' => $form['delivery_address'],
                    'delivery_country' => $form['delivery_country'], 'delivery_postcode' => $form['delivery_postcode'],
                    'shipping_method_id' => $form['shipping_method_id']
                ]
            );
            $createdOrder = $order;

            $viewUrl = $origin . url('shop/order/' . rawurlencode((string) $order['access_token']));

            if ((float) $order['total'] <= 0.0) {
                shop_mark_order_paid(db(), (int) $order['id'], null);
                shop_basket_clear();
                redirect($viewUrl);
            }

            $checkout = shop_create_checkout_session(
                db(),
                $order,
                $viewUrl . '?paid=1',
                $origin . url('shop/checkout') . '?cancelled=1'
            );
            shop_basket_clear();
            redirect($checkout['url']);
        } catch (Throwable $e) {
            error_log('[public shop checkout] ' . $e->getMessage());
            // The order was created (reserving stock) but never got a Stripe
            // session to redeem it — release that reservation so it isn't
            // held forever with no checkout.session.expired to free it.
            if ($createdOrder && (string) ($createdOrder['status'] ?? '') === 'pending_payment'
                && empty($createdOrder['stripe_checkout_session_id'])) {
                shop_cancel_order(db(), (int) $createdOrder['id'], 'Failed to start Stripe checkout');
            }
            $errors[] = 'Something went wrong starting your payment. Please try again — you have not been charged.';
        }
    }
}

$cancelled = isset($_GET['cancelled']);
set_meta(['title' => 'Checkout']);
?>
<?php partial('shop_bar', ['basketCount' => $summary['count']]); ?>

<?php seo_breadcrumbs([['Club shop', url('shop')], ['Checkout', url('shop/checkout')]]); ?>
<?php partial('page_hero', ['eyebrow' => 'Club shop', 'title' => 'Checkout']); ?>

<div class="page">
  <div class="container">
    <?php if ($cancelled): ?>
      <div class="notice notice--warn"><p>Your payment was cancelled and nothing has been charged. Your basket is still here whenever you're ready.</p></div>
    <?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="notice notice--err"><?= e($e) ?></div><?php endforeach; ?>

    <div class="basket-layout">
      <form method="post" action="<?= e(url('shop/checkout')) ?>" class="checkout-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="place_order">
        <h2>Your details</h2>
        <label class="field"><span>Full name</span><input name="customer_name" required value="<?= e($form['name']) ?>" autocomplete="name"></label>
        <div class="field-row">
          <label class="field"><span>Email</span><input name="customer_email" type="email" required value="<?= e($form['email']) ?>" autocomplete="email"></label>
          <label class="field"><span>Phone</span><input name="customer_phone" required value="<?= e($form['phone']) ?>" autocomplete="tel"></label>
        </div>
        <label class="field"><span>Order note <em>(optional)</em></span><textarea name="customer_note" rows="2" placeholder="Anything we should know — e.g. who this is for"><?= e($form['note']) ?></textarea></label>

        <h2><?= $deliveryEnabled ? 'Collection, delivery &amp; terms' : 'Collection &amp; terms' ?></h2>
        <?php if ($deliveryEnabled): ?>
          <div class="field-row" data-fulfilment-choice>
            <label class="check"><input type="radio" name="fulfilment_method" value="collection" <?= $form['fulfilment_method'] !== 'delivery' ? 'checked' : '' ?>>
              <span>Collection — free, from <?= e((string) ($settings['collection_point'] ?? 'Campbell Park')) ?></span></label>
            <label class="check"><input type="radio" name="fulfilment_method" value="delivery" <?= $form['fulfilment_method'] === 'delivery' ? 'checked' : '' ?>>
              <span>Delivery — price depends on where it's going</span></label>
          </div>
          <?php if (($settings['collection_address'] ?? '') !== '' || ($settings['collection_map_url'] ?? '') !== ''): ?>
            <p class="pdp__note">
              <?php if (($settings['collection_address'] ?? '') !== ''): ?><span style="white-space:pre-line"><?= e((string) $settings['collection_address']) ?></span><?php endif; ?>
              <?php if (($settings['collection_map_url'] ?? '') !== ''): ?> <a href="<?= e((string) $settings['collection_map_url']) ?>" target="_blank" rel="noopener">Get directions</a><?php endif; ?>
            </p>
          <?php if (($settings['collection_map_embed'] ?? '') !== ''): ?><iframe class="shopmap" src="<?= e((string) $settings['collection_map_embed']) ?>" width="100%" height="220" style="border:0" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" title="Collection point map" allowfullscreen></iframe><?php endif; ?>
          <?php endif; ?>
          <div data-delivery-address <?= $form['fulfilment_method'] === 'delivery' ? '' : 'hidden' ?>>
            <div class="field-row">
              <label class="field"><span>Country</span>
                <select name="delivery_country" data-ship-country autocomplete="country">
                  <?php foreach ($countries as $code => $cname): ?>
                    <option value="<?= e($code) ?>" <?= $form['delivery_country'] === $code ? 'selected' : '' ?>><?= e($cname) ?></option>
                  <?php endforeach; ?>
                </select></label>
              <label class="field"><span>Postcode / ZIP</span>
                <input name="delivery_postcode" data-ship-postcode value="<?= e($form['delivery_postcode']) ?>" autocomplete="postal-code"></label>
            </div>
            <label class="field"><span>Delivery address</span>
              <textarea name="delivery_address" rows="3" placeholder="Street, town / city, region" <?= $form['fulfilment_method'] === 'delivery' ? 'required' : '' ?>><?= e($form['delivery_address']) ?></textarea></label>
            <fieldset class="optgroup" data-ship-methods-wrap>
              <legend><span>Delivery options</span></legend>
              <div data-ship-methods></div>
              <p class="pdp__note" data-ship-message></p>
              <?php if (trim((string) ($settings['free_delivery_over'] ?? '')) !== '' && (float) $settings['free_delivery_over'] > 0): ?>
                <p class="pdp__note"><strong>Free delivery</strong> on orders over <?= e(gbp((float) $settings['free_delivery_over'])) ?>.</p>
              <?php endif; ?>
              <?php if (trim((string) ($settings['delivery_notice'] ?? '')) !== ''): ?>
                <p class="pdp__note" style="white-space:pre-line"><?= e((string) $settings['delivery_notice']) ?></p>
              <?php endif; ?>
            </fieldset>
          </div>
        <?php else: ?>
          <p class="pdp__note"><strong>Collection only</strong> from <?= e((string) ($settings['collection_point'] ?? 'Campbell Park')) ?>.
            <?= e((string) ($settings['delivery_note'] ?? 'There is no delivery option for this shop.')) ?></p>
          <?php if (($settings['collection_address'] ?? '') !== '' || ($settings['collection_map_url'] ?? '') !== ''): ?>
            <p class="pdp__note">
              <?php if (($settings['collection_address'] ?? '') !== ''): ?><span style="white-space:pre-line"><?= e((string) $settings['collection_address']) ?></span><?php endif; ?>
              <?php if (($settings['collection_map_url'] ?? '') !== ''): ?> <a href="<?= e((string) $settings['collection_map_url']) ?>" target="_blank" rel="noopener">Get directions</a><?php endif; ?>
            </p>
          <?php if (($settings['collection_map_embed'] ?? '') !== ''): ?><iframe class="shopmap" src="<?= e((string) $settings['collection_map_embed']) ?>" width="100%" height="220" style="border:0" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" title="Collection point map" allowfullscreen></iframe><?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (trim((string) ($settings['terms'] ?? '')) !== ''): ?>
          <div class="checkout-terms"><?= nl2br(e((string) $settings['terms'])) ?></div>
        <?php endif; ?>
        <label class="check"><input type="checkbox" name="accept_terms" value="1" required>
          <span>I understand payment is taken in full today<?php if (!empty($summary['is_preorder'])): ?>, that pre-order items will be ready after the pre-order closes<?= !empty($preInfoCheckout['lead']) ? ' (VSN manufacturing typically takes ' . e((string) $preInfoCheckout['lead']) . ')' : '' ?><?php endif; ?>, and — unless I've chosen
            delivery above — that my order is collected from <?= e((string) ($settings['collection_point'] ?? 'Campbell Park')) ?>.</span></label>
        <label class="check"><input type="checkbox" name="marketing_opt_in" value="1" <?= $form['marketing'] ? 'checked' : '' ?>>
          <span>Keep me updated by email about club news and offers.</span></label>

        <button type="submit" class="btn btn--lg" style="width:100%;margin-top:1rem" data-pay-button data-subtotal="<?= e((string) $summary['total']) ?>" data-delivery-fee="<?= e((string) $deliveryFee) ?>">Pay <?= e(gbp((float) $summary['total'])) ?> with Stripe</button>
        <p class="pdp__note">You'll be taken to Stripe's secure payment page. Card details are never seen by the club.</p>
      </form>

      <aside class="osummary" aria-label="Order summary">
        <h2>Order summary</h2>
        <?php foreach ($summary['items'] as $item): ?>
          <div class="osummary__row">
            <span><?= e((string) $item['product_name']) ?><?= $item['options_label'] !== '' ? ' (' . e((string) $item['options_label']) . ')' : '' ?> × <?= (int) $item['quantity'] ?></span>
            <span><?= e(gbp((float) $item['line_total'])) ?></span>
          </div>
        <?php endforeach; ?>
        <div class="osummary__row"><span>Subtotal</span><span><?= e(gbp((float) $summary['subtotal'])) ?></span></div>
        <?php if ((float) $summary['discount_total'] > 0): ?>
          <div class="osummary__row"><span>Discount</span><span>−<?= e(gbp((float) $summary['discount_total'])) ?></span></div>
        <?php endif; ?>
        <?php if ($deliveryEnabled): ?>
          <div class="osummary__row" data-delivery-row <?= $form['fulfilment_method'] === 'delivery' ? '' : 'hidden' ?>><span>Delivery</span><span data-delivery-amount>—</span></div>
        <?php endif; ?>
        <div class="osummary__row osummary__row--total"><span>Total due</span><span data-total-due><?= e(gbp((float) $summary['total'])) ?></span></div>
        <p class="pdp__note"><a href="<?= e(url('shop/basket')) ?>">Edit basket</a></p>
      </aside>
    </div>
  </div>
</div>

<?php if ($deliveryEnabled): ?>
<script>
(function () {
  var radios = document.querySelectorAll('input[name="fulfilment_method"]');
  var addressBox = document.querySelector('[data-delivery-address]');
  var addressInput = addressBox ? addressBox.querySelector('textarea') : null;
  var deliveryRow = document.querySelector('[data-delivery-row]');
  var deliveryAmount = document.querySelector('[data-delivery-amount]');
  var totalEl = document.querySelector('[data-total-due]');
  var payBtn = document.querySelector('[data-pay-button]');
  var country = document.querySelector('[data-ship-country]');
  var postcode = document.querySelector('[data-ship-postcode]');
  var methodsBox = document.querySelector('[data-ship-methods]');
  var message = document.querySelector('[data-ship-message]');
  if (!radios.length || !payBtn) { return; }

  var subtotal = parseFloat(payBtn.getAttribute('data-subtotal')) || 0;
  var chosenFee = null, wantedMethod = <?= (int) $form['shipping_method_id'] ?>, timer = null, seq = 0;
  var gbp = function (n) { return '£' + n.toFixed(2); };
  function isDelivery() { return document.querySelector('input[name="fulfilment_method"]:checked').value === 'delivery'; }

  function render() {
    var d = isDelivery();
    if (addressBox) { addressBox.hidden = !d; }
    if (addressInput) { addressInput.required = d; }
    if (deliveryRow) { deliveryRow.hidden = !d; }
    if (methodsBox) { methodsBox.querySelectorAll('input').forEach(function (i) { i.required = d; i.disabled = !d; }); }
    var fee = d && chosenFee !== null ? chosenFee : 0;
    if (deliveryAmount) { deliveryAmount.textContent = chosenFee === null ? '—' : (chosenFee === 0 ? 'Free' : gbp(chosenFee)); }
    var total = subtotal + fee;
    if (totalEl) { totalEl.textContent = gbp(total); }
    payBtn.textContent = 'Pay ' + gbp(total) + ' with Stripe';
    payBtn.disabled = d && chosenFee === null;
  }

  function pick(input, cost) { chosenFee = cost; wantedMethod = parseInt(input.value, 10); render(); }

  function load() {
    if (!isDelivery()) { render(); return; }
    var mine = ++seq;
    message.textContent = 'Checking delivery options…';
    fetch(<?= json_encode(url('shop/shipping-quote')) ?> + '?country=' + encodeURIComponent(country.value) + '&postcode=' + encodeURIComponent(postcode.value), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (mine !== seq) { return; }
        methodsBox.innerHTML = '';
        chosenFee = null;
        message.textContent = data.message || '';
        (data.methods || []).forEach(function (m, i) {
          var label = document.createElement('label'); label.className = 'check';
          var input = document.createElement('input'); input.type = 'radio'; input.name = 'shipping_method_id'; input.value = m.id; input.required = true;
          var span = document.createElement('span'); span.textContent = m.name + ' — ' + (m.cost === 0 ? 'Free' : gbp(m.cost));
          label.appendChild(input); label.appendChild(span); methodsBox.appendChild(label);
          input.addEventListener('change', function () { pick(input, m.cost); });
          if (m.id === wantedMethod || (i === 0 && !(data.methods || []).some(function (x) { return x.id === wantedMethod; }))) { input.checked = true; pick(input, m.cost); }
        });
        render();
      })
      .catch(function () { if (mine === seq) { message.textContent = 'Could not load delivery options. Please try again.'; chosenFee = null; render(); } });
  }
  function soon() { clearTimeout(timer); timer = setTimeout(load, 350); }

  radios.forEach(function (r) { r.addEventListener('change', load); });
  if (country) { country.addEventListener('change', load); }
  if (postcode) { postcode.addEventListener('input', soon); postcode.addEventListener('change', load); }
  load();
})();
</script>
<?php endif; ?>
