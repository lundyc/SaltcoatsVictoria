<?php
/** Route: /events/{slug} — one event: details, ticket types and the checkout form (guest checkout, with
 *  member prices and early access for signed-in season ticket holders). Reuses admin/lib/events_orders.php. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/events_orders.php';
require_once HUB_ROOT . '/lib/events_mail.php';
require_once HUB_ROOT . '/lib/events_followups.php';
require_once HUB_ROOT . '/member_auth.php';

ensureEventOrdersSchema(db());
$event = events_get_by_slug(db(), (string) ($slug ?? ''));
if (!$event || $event['status'] === 'draft') {
    http_response_code(404);
    set_meta(['title' => 'Event not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Event not found</h1><p><a class="linkarrow" href="' . e(url('events')) . '">See all events</a></p></div></div>';
    return;
}
$eventUrl = url('events/' . $event['slug']);

// Capture the CSRF token against the default session before any member_auth_* call switches the session.
$csrfFieldHtml = csrf_field();
$csrfValid = ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || csrf_check();

$loggedIn = member_auth_is_authenticated();
$personId = $loggedIn ? (int) (member_auth_current_person_id() ?? 0) : 0;
$isMember = events_person_is_member(db(), $personId);

// Coming back from Stripe's cancel button: free the seats straight away instead of waiting for the hold to run out.
if (isset($_GET['cancelled']) && !empty($_GET['order'])) {
    $cancelOrder = events_order_by_token(db(), (string) $_GET['order']);
    if ($cancelOrder && $cancelOrder['status'] === 'pending_payment') {
        events_expire_order(db(), (int) $cancelOrder['id']);
    }
}

if (random_int(1, 25) === 1) {
    events_release_expired_holds(db()); // tidy-up only: expired holds already free their seats
    events_purge_access_needs(db());   // access information is deleted 30 days after the event
}
$errors = [];
$onSale = events_is_on_sale($event);
if (random_int(1, 10) === 1 && $onSale) {
    events_process_waitlist(db(), (int) $event['id']); // tell the waiting list if tickets came back
    events_send_abandoned_reminders(db(), 3);
}
$waitNotice = '';
if (!empty($_GET['leave_waitlist'])) {
    $waitNotice = events_waitlist_leave(db(), (string) $_GET['leave_waitlist']) ? 'You have been taken off the waiting list. We will not email you about this event again.' : 'That waiting-list link has already been used.';
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'waitlist') {
    try {
        if (!$csrfValid) {
            throw new RuntimeException('Your session expired. Please try again.');
        }
        if (empty($_POST['waitlist_ok'])) {
            throw new RuntimeException('Please tick the box so we know you want an email when tickets come back.');
        }
        events_waitlist_join(db(), (int) $event['id'], (string) ($_POST['wl_name'] ?? ''), (string) ($_POST['wl_email'] ?? ''), (int) ($_POST['wl_qty'] ?? 1));
        $waitNotice = 'You are on the waiting list. If tickets come back we will email you straight away, and hold them for you for ' . EVENT_WAITLIST_CLAIM_HOURS . ' hours.';
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

// A promo or invite code, from the box on this page, a shared link (?code=) or the order form.
$promoCode = events_promo_normalise((string) ($_POST['promo_code'] ?? ($_GET['code'] ?? '')));
$promo = null;
$promoNote = '';
$promoError = '';
if ($promoCode !== '') {
    $found = events_promo_find(db(), (int) $event['id'], $promoCode);
    $why = $found ? events_promo_problem($found, events_promo_uses(db(), (int) $found['id'])) : 'that code is not valid for this event.';
    if (!$found || $why !== '') {
        $promoError = 'Sorry, ' . $why;
    } else {
        $promo = $found;
        $promoNote = $found['kind'] === 'percent' ? rtrim(rtrim(number_format((float) $found['value'], 2), '0'), '.') . '% off'
            : ($found['kind'] === 'amount' ? gbp((float) $found['value']) . ' off each ticket' : 'invite-only ticket unlocked');
    }
}
$minAge = (int) $event['min_age'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') !== 'waitlist') {
    if (!$csrfValid) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$onSale) {
        $errors[] = 'Tickets for this event are not on sale.';
    } else {
        $cart = [];
        foreach ((array) ($_POST['qty'] ?? []) as $typeId => $qty) {
            $qty = max(0, min(50, (int) $qty));
            if ($qty > 0) {
                $cart[(int) $typeId] = $qty;
            }
        }
        if (!$cart) {
            $errors[] = 'Choose at least one ticket.';
        }
        if (empty($_POST['accept_terms'])) {
            $errors[] = 'Please confirm you have read the ticket terms.';
        }
        if ($minAge > 0 && empty($_POST['confirm_age'])) {
            $errors[] = 'Please confirm everyone attending is ' . EVENT_MIN_AGES[$minAge] . '.';
        }
        if (!$errors) {
            try {
                $termsText = 'Tickets are non-refundable unless the event is cancelled, and valid for the named event only.'
                    . ($minAge > 0 ? ' Buyer confirmed all attendees are ' . EVENT_MIN_AGES[$minAge] . '.' : '');
                $names = [];
                foreach ((array) ($_POST['names'] ?? []) as $typeId => $list) {
                    $names[(int) $typeId] = array_map(static fn ($n) => trim((string) $n), (array) $list);
                }
                $result = events_create_order(db(), [
                    'name' => (string) ($_POST['buyer_name'] ?? ''),
                    'email' => (string) ($_POST['buyer_email'] ?? ''),
                    'phone' => (string) ($_POST['buyer_phone'] ?? ''),
                    'person_id' => $personId > 0 ? $personId : null,
                ], (int) $event['id'], $cart, ['attendee_names' => $names, 'terms_text' => $termsText, 'is_member' => $isMember, 'promo_code' => $promoCode, 'remind' => !empty($_POST['remind_me']), 'access_needs' => (string) ($_POST['access_needs'] ?? ''), 'access_consent' => !empty($_POST['access_consent']), 'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '')]);

                $viewUrl = current_url_origin() . url('events/order/' . rawurlencode($result['access_token']));
                if ($result['needs_payment']) {
                    if (!stripe_is_configured()) {
                        events_expire_order(db(), $result['order_id']);
                        throw new RuntimeException('Online payment is not available right now. Please try again later.');
                    }
                    $checkout = events_stripe_create_checkout_session(
                        db(),
                        $result['order_id'],
                        $viewUrl . '?paid=1',
                        current_url_origin() . $eventUrl . '?cancelled=1&order=' . rawurlencode($result['access_token'])
                    );
                    redirect($checkout['url']);
                }
                events_send_confirmation_email(db(), $result['order_id']);
                redirect($viewUrl . '?saved=1');
            } catch (Throwable $ex) {
                $errors[] = $ex->getMessage();
            }
        }
    }
}

$options = events_public_ticket_options(db(), $event, $isMember, $promo);
$contact = events_contact_email(db(), $event);
$feeRate = max(0.0, (float) site_setting(db(), 'events_fee_rate_pct', '1.5')) / 100;
$feeFixed = max(0.0, (float) site_setting(db(), 'events_fee_fixed_pence', '20')) / 100;
$startTs = strtotime((string) $event['starts_at']);
$cartPosted = (array) ($_POST['qty'] ?? []);
$namesPosted = (array) ($_POST['names'] ?? []);
$anyBuyable = false;
foreach ($options as $o) {
    $anyBuyable = $anyBuyable || $o['buyable'];
}

$calUrl = 'https://calendar.google.com/calendar/render?' . http_build_query([
    'action' => 'TEMPLATE',
    'text' => (string) $event['title'],
    'dates' => gmdate('Ymd\THis\Z', $startTs) . '/' . gmdate('Ymd\THis\Z', !empty($event['ends_at']) ? strtotime((string) $event['ends_at']) : $startTs + 7200),
    'location' => trim((string) $event['venue_name'] . ', ' . (string) $event['venue_address'], ', '),
]);

$lowest = null;
foreach ($options as $o) {
    if ($o['buyable']) {
        $lowest = $lowest === null ? $o['unit_price'] : min($lowest, $o['unit_price']);
    }
}
set_meta([
    'title' => (string) $event['title'],
    'description' => (string) ($event['short_description'] ?: ('Tickets for ' . $event['title'] . ' at ' . $event['venue_name'] . ' on ' . format_date($event['starts_at'], 'l j F Y') . '.')),
]);
seo_breadcrumbs([['Events', url('events')], [(string) $event['title'], $eventUrl]]);
$statusMap = ['on_sale' => 'https://schema.org/EventScheduled', 'sold_out' => 'https://schema.org/EventScheduled', 'postponed' => 'https://schema.org/EventPostponed', 'cancelled' => 'https://schema.org/EventCancelled', 'finished' => 'https://schema.org/EventScheduled'];
pub_jsonld([
    '@type' => 'Event',
    'name' => (string) $event['title'],
    'description' => (string) ($event['short_description'] ?? ''),
    'startDate' => date('c', $startTs),
    'endDate' => !empty($event['ends_at']) ? date('c', strtotime((string) $event['ends_at'])) : null,
    'eventStatus' => $statusMap[$event['status']] ?? null,
    'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
    'location' => ['@type' => 'Place', 'name' => (string) $event['venue_name'], 'address' => trim((string) $event['venue_address'] . ' ' . (string) $event['venue_postcode'])],
    'image' => !empty($event['image_path']) ? current_url_origin() . $event['image_path'] : null,
    'organizer' => ['@type' => 'Organization', 'name' => club('club_name')],
    'offers' => $onSale && $lowest !== null ? ['@type' => 'Offer', 'url' => current_url_origin() . $eventUrl, 'price' => number_format((float) $lowest, 2, '.', ''), 'priceCurrency' => 'GBP', 'availability' => $anyBuyable ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut'] : null,
]);
if ($event['visibility'] === 'hidden') {
    set_meta(['robots' => 'noindex, follow']);
}

partial('page_hero', [
    'eyebrow' => 'Event',
    'title'   => (string) $event['title'],
    'sub'     => format_date($event['starts_at'], 'l j F Y') . ' · ' . format_time($event['starts_at']) . ' · ' . $event['venue_name'],
    'back'    => ['href' => url('events'), 'label' => 'All events'],
]);
?>
<div class="page">
  <div class="container">
    <?php if (isset($_GET['cancelled'])): ?><div class="notice notice--warn"><p>Payment was cancelled — no money was taken. Your tickets have been released.</p></div><?php endif; ?>
    <?php foreach ($errors as $er): ?><div class="notice notice--err"><p><?= e($er) ?></p></div><?php endforeach; ?>
    <?php if ($waitNotice !== ''): ?><div class="notice notice--ok"><p><?= e($waitNotice) ?></p></div><?php endif; ?>
    <?php if ($event['status'] === 'cancelled'): ?><div class="notice notice--err"><p><strong>This event has been cancelled.</strong> If you bought tickets you will be refunded in full.</p></div><?php endif; ?>
    <?php if ($event['status'] === 'postponed'): ?><div class="notice notice--warn"><p><strong>This event has been postponed.</strong> Existing tickets stay valid for the new date. We will email ticket holders with the details.</p></div><?php endif; ?>

    <div class="tkt-date" role="group" aria-label="Event date and venue">
      <div class="tkt-date__item"><span class="tkt-date__k">Date</span><span class="tkt-date__v"><?= e(format_date($event['starts_at'], 'l j F Y')) ?></span></div>
      <?php if (!empty($event['doors_at'])): ?><div class="tkt-date__item"><span class="tkt-date__k">Doors</span><span class="tkt-date__v"><?= e(format_time($event['doors_at'])) ?></span></div><?php endif; ?>
      <div class="tkt-date__item"><span class="tkt-date__k">Starts</span><span class="tkt-date__v"><?= e(format_time($event['starts_at'])) ?></span></div>
      <div class="tkt-date__item"><span class="tkt-date__k">Venue</span><span class="tkt-date__v"><?= e((string) $event['venue_name']) ?></span></div>
      <a class="btn tkt-date__cal" href="<?= e($calUrl) ?>" target="_blank" rel="noopener">Add to calendar</a>
    </div>

    <div class="tkt-event">
      <div class="tkt-event__main">
        <h2 class="tkt-event__label tkt-o-info-l">About this event</h2>
        <div class="tkt-event__card tkt-o-info">
          <?php if (!empty($event['image_path'])): ?><img src="<?= e((string) $event['image_path']) ?>" alt="" style="width:100%;border-radius:12px;margin-bottom:1rem" loading="lazy"><?php endif; ?>
          <section class="tkt-info">
            <?php if (!empty($event['description'])): ?>
              <?= nl2br(e((string) $event['description'])) ?>
            <?php elseif (!empty($event['short_description'])): ?>
              <p><?= e((string) $event['short_description']) ?></p>
            <?php endif; ?>
            <?php if ($minAge > 0): ?>
              <p><strong>Age limit: <?= e(EVENT_MIN_AGES[$minAge]) ?>.</strong> <?= e((string) ($event['age_note'] ?? '')) ?></p>
            <?php endif; ?>
            <h3 class="tkt-info__sub">Where</h3>
            <p><strong><?= e((string) $event['venue_name']) ?></strong><br><?= e(trim((string) $event['venue_address'] . ' ' . (string) $event['venue_postcode'])) ?>
              <?php if (!empty($event['venue_map_url'])): ?><br><a href="<?= e((string) $event['venue_map_url']) ?>" target="_blank" rel="noopener">View on map</a><?php endif; ?></p>
            <?php if (!empty($event['venue_access_notes'])): ?><p><?= nl2br(e((string) $event['venue_access_notes'])) ?></p><?php endif; ?>
            <h3 class="tkt-info__sub">How it works</h3>
            <ul class="tkt-info__list">
              <li>Choose your tickets and pay securely by card. Your digital tickets are emailed to you straight away.</li>
              <li>Show the QR code on your phone at the door &mdash; there&rsquo;s nothing to collect.</li>
              <li>Tickets are non-refundable unless the event is cancelled. If it is postponed, your tickets stay valid for the new date.</li>
              <?php if ((int) ($event['access_places'] ?? 0) > 0): ?><li>Accessible places (wheelchair spaces, step-free seats and carer places) are kept for people who need them. Any that are unsold are opened to everyone <?= (int) $event['access_release_hours'] ?> hours before the event.</li><?php endif; ?>
              <li>Season ticket holders get early access and member prices when they <a href="<?= e(url('members/login')) ?>">sign in</a> first.</li>
              <li>Need help or have access requirements? <?= $contact !== '' ? 'Email <a href="mailto:' . e($contact) . '">' . e($contact) . '</a> or ' : '' ?><a href="<?= e(url('contact')) ?>">get in touch</a>.</li>
            </ul>
          </section>
        </div>
      </div>

      <aside class="tkt-event__side">
        <h2 class="tkt-event__label tkt-o-order-l">Tickets</h2>
        <div class="tkt-event__card tkt-order tkt-o-order">
        <?php if (!$onSale || !$options): ?>
          <div class="emptystate tkt-empty">
            <p><strong><?= $event['status'] === 'sold_out' ? 'This event is sold out.' : (in_array($event['status'], ['cancelled', 'finished'], true) ? 'Tickets are no longer on sale.' : 'Tickets are not on sale online yet.') ?></strong></p>
            <p><a class="btn btn--sm" href="<?= e(url('events')) ?>">‹ All events</a></p>
          </div>
        <?php else: ?>
          <?php if ($isMember): ?><div class="notice notice--ok"><p>Signed in as a season ticket holder — member prices and early access applied.</p></div>
          <?php elseif (!$loggedIn): ?><p class="tkt-summary__note"><a href="<?= e(url('members/login')) ?>">Sign in</a> if you hold a season ticket for member prices.</p><?php endif; ?>

          <?php if (!$anyBuyable): ?>
            <div class="notice notice--warn"><p><strong>This event is sold out.</strong> Join the waiting list and we will email you if tickets come back.</p></div>
            <form method="post" action="<?= e($eventUrl) ?>" class="tkt-orderform" style="margin-bottom:1rem">
              <?= $csrfFieldHtml ?>
              <input type="hidden" name="action" value="waitlist">
              <label class="tkt-field"><span>Your name</span><input name="wl_name" autocomplete="name" value="<?= e((string) ($_POST['wl_name'] ?? '')) ?>" required></label>
              <label class="tkt-field"><span>Email</span><input type="email" name="wl_email" autocomplete="email" value="<?= e((string) ($_POST['wl_email'] ?? '')) ?>" required></label>
              <label class="tkt-field"><span>How many tickets?</span><input type="number" name="wl_qty" min="1" max="<?= (int) EVENT_WAITLIST_MAX_QTY ?>" value="<?= (int) ($_POST['wl_qty'] ?? 2) ?>" required></label>
              <label class="tkt-check"><input type="checkbox" name="waitlist_ok" value="1"> Email me if tickets become available. I can leave the list at any time.</label>
              <button type="submit" class="btn btn--block">Join the waiting list</button>
            </form>
          <?php endif; ?>
          <form method="get" action="<?= e($eventUrl) ?>" class="tkt-promo" style="margin-bottom:1rem">
            <label class="tkt-field"><span>Promo or invite code <em>(optional)</em></span>
              <span style="display:flex;gap:.5rem"><input name="code" value="<?= e($promoCode) ?>" maxlength="30" autocomplete="off" style="text-transform:uppercase" aria-label="Promo or invite code"><button type="submit" class="btn btn--sm">Apply</button></span></label>
            <?php if ($promo): ?><p class="tkt-summary__note" style="color:#15803d"><strong>Code <?= e((string) $promo['code']) ?> applied:</strong> <?= e($promoNote) ?>.</p><?php endif; ?>
            <?php if ($promoError !== ''): ?><p class="tkt-summary__note" style="color:#b42335"><?= e($promoError) ?></p><?php endif; ?>
          </form>

          <form method="post" class="tkt-orderform" data-ticket-form action="<?= e($eventUrl) ?>">
            <?= $csrfFieldHtml ?>
            <?php if ($promo): ?><input type="hidden" name="promo_code" value="<?= e((string) $promo['code']) ?>"><?php endif; ?>
            <div>
              <h3 class="tkt-order__h">Available tickets</h3>
              <ul class="tkt-lines">
                <?php foreach ($options as $o): ?>
                  <?php
                  $cap = min(50, $o['left'], $o['max_per_order'] !== null ? (int) $o['max_per_order'] : 50);
                  $val = (int) ($cartPosted[(int) $o['id']] ?? 0);
                  $soldOut = $o['left'] <= 0;
                  ?>
                  <li class="tkt-line<?= !$o['buyable'] ? ' is-soldout' : '' ?>" data-name="<?= e((string) $o['name']) ?>" data-price="<?= e(number_format((float) $o['unit_price'], 2, '.', '')) ?>" data-id="<?= (int) $o['id'] ?>" data-people="<?= (int) $o['admits_count'] ?>">
                    <div class="tkt-line__info">
                      <span class="tkt-line__name"><?= e((string) $o['name']) ?></span>
                      <?php if (!empty($o['description'])): ?><span class="tkt-line__desc"><?= e((string) $o['description']) ?></span><?php endif; ?>
                      <?php if ((int) $o['admits_count'] > 1): ?><span class="tkt-line__desc">Admits <?= (int) $o['admits_count'] ?> people<?= ($o['pool'] ?? 'general') === 'access' ? ' (you and a companion or carer)' : '' ?></span><?php endif; ?>
                      <?php if (($o['pool'] ?? 'general') === 'access'): ?><span class="tkt-line__desc"><strong>Accessible place</strong>: for wheelchair users and people who need step-free access.</span><?php endif; ?>
                      <?php if ($soldOut): ?><span class="tkt-line__stock is-out">Sold out</span>
                      <?php elseif ($o['problem'] !== ''): ?><span class="tkt-line__stock is-low"><?= e($o['problem']) ?></span>
                      <?php elseif ($o['left'] <= 10): ?><span class="tkt-line__stock is-low">Only <?= (int) $o['left'] ?> left</span><?php endif; ?>
                    </div>
                    <div class="tkt-line__price">
                      <?php if ((float) $o['unit_price'] > 0): ?><?= e(gbp((float) $o['unit_price'])) ?><?php if ((float) $o['unit_price'] < (float) $o['price']): ?><br><small><s><?= e(gbp((float) $o['price'])) ?></s></small><?php endif; ?><?php else: ?>Free<?php endif; ?>
                    </div>
                    <div class="tkt-line__qty">
                      <?php if (!$o['buyable']): ?>
                        <span class="tkt-line__soldtag"><?= $soldOut ? 'Sold out' : 'Unavailable' ?></span>
                      <?php else: ?>
                        <div class="stepper" data-stepper>
                          <button type="button" data-step="-1" aria-label="Fewer <?= e((string) $o['name']) ?>">−</button>
                          <input class="tkt-qty" type="number" inputmode="numeric" min="0" max="<?= (int) $cap ?>" name="qty[<?= (int) $o['id'] ?>]" value="<?= $val ?>" aria-label="Quantity — <?= e((string) $o['name']) ?>">
                          <button type="button" data-step="1" aria-label="More <?= e((string) $o['name']) ?>">+</button>
                        </div>
                      <?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>

            <div class="tkt-summary">
              <h3 class="tkt-order__h">Order summary</h3>
              <div class="osummary__items js-tkt-items"><p class="tkt-summary__empty">No tickets selected yet.</p></div>
              <div class="osummary__row js-evt-feerow" style="display:none"><span>Booking fee</span><span class="js-evt-fee">£0.00</span></div>
              <div class="osummary__row osummary__row--total"><span>Total</span><span class="js-tkt-total js-evt-total"><?= e(gbp(0)) ?></span></div>
              <p class="tkt-summary__note js-evt-feenote" style="display:none">Includes a booking fee of <?= e(rtrim(rtrim(number_format($feeRate * 100, 2), '0'), '.')) ?>% + <?= (int) round($feeFixed * 100) ?>p per order.</p>

              <?php if ($minAge > 0): ?>
                <h3 class="tkt-order__h tkt-summary__details">Who is coming</h3>
                <p class="tkt-summary__note">Enter a name for each ticket. This is an <?= e(EVENT_MIN_AGES[$minAge]) ?> event.</p>
                <div class="js-evt-names"></div>
              <?php endif; ?>

              <h3 class="tkt-order__h tkt-summary__details">Your details</h3>
              <label class="tkt-field"><span>Full name</span><input name="buyer_name" autocomplete="name" value="<?= e((string) ($_POST['buyer_name'] ?? '')) ?>" required></label>
              <label class="tkt-field"><span>Email</span><input type="email" name="buyer_email" autocomplete="email" value="<?= e((string) ($_POST['buyer_email'] ?? '')) ?>" required></label>
              <label class="tkt-field"><span>Phone <em>(optional)</em></span><input name="buyer_phone" autocomplete="tel" value="<?= e((string) ($_POST['buyer_phone'] ?? '')) ?>"></label>
              <label class="tkt-field"><span>Access requirements <em>(optional)</em></span><textarea name="access_needs" rows="2" maxlength="1000" placeholder="e.g. wheelchair space, hearing loop, step-free route"><?= e((string) ($_POST['access_needs'] ?? '')) ?></textarea></label>
              <label class="tkt-check"><input type="checkbox" name="access_consent" value="1" <?= !empty($_POST['access_consent']) ? 'checked' : '' ?>> I agree the club may keep what I have written above and use it only to arrange my visit. It is deleted 30 days after the event. (Needed only if you have filled this in.)</label>

              <?php if ($minAge > 0): ?>
                <label class="tkt-check"><input type="checkbox" name="confirm_age" value="1" <?= !empty($_POST['confirm_age']) ? 'checked' : '' ?>> I confirm everyone attending is <?= e(EVENT_MIN_AGES[$minAge]) ?>.</label>
              <?php endif; ?>
              <label class="tkt-check"><input type="checkbox" name="accept_terms" value="1" <?= !empty($_POST['accept_terms']) ? 'checked' : '' ?>> I understand tickets are non-refundable unless the event is cancelled.</label>
              <label class="tkt-check"><input type="checkbox" name="remind_me" value="1" <?= !empty($_POST['remind_me']) ? 'checked' : '' ?>> Email me one reminder if I do not finish my order <span style="opacity:.7">(optional)</span>.</label>

              <button type="submit" class="btn btn--lg btn--block js-tkt-submit" disabled>Continue</button>
              <p class="tkt-summary__note"><?= pub_icon('basket') ?> Secure card payment via Stripe. Your tickets and QR codes are emailed to you straight away.</p>
            </div>
          </form>
        <?php endif; ?>
        </div>
      </aside>
    </div>
  </div>
</div>

<?php if ($onSale && $options): ?>
<script>
(function () {
  var form = document.querySelector('[data-ticket-form]');
  if (!form) return;
  var RATE = <?= json_encode($feeRate) ?>, FIXED = <?= json_encode($feeFixed) ?>, MIN_AGE = <?= (int) $minAge ?>;
  var posted = <?= json_encode($namesPosted, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var lines = Array.prototype.slice.call(form.querySelectorAll('.tkt-line'));
  var btn = form.querySelector('.js-tkt-submit');
  var money = function (n) { return '£' + n.toFixed(2); };
  var esc = function (t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };

  function render() {
    var total = 0, count = 0, names = '';
    lines.forEach(function (line) {
      var input = line.querySelector('.tkt-qty'); if (!input) return;
      var qty = Math.max(0, parseInt(input.value || '0', 10) || 0);
      var price = parseFloat(line.getAttribute('data-price') || '0');
      total += qty * price; count += qty;
      if (MIN_AGE > 0 && qty > 0) {
        var id = line.getAttribute('data-id'), old = (posted[id] || []);
        for (var i = 0; i < qty; i++) {
          var existing = form.querySelector('[data-nm="' + id + '-' + i + '"]');
          var v = existing ? existing.value : (old[i] || '');
          names += '<label class="tkt-field"><span>' + esc(line.getAttribute('data-name')) + ' ' + (i + 1) + '</span><input data-nm="' + id + '-' + i + '" name="names[' + id + '][]" value="' + esc(v) + '" required></label>';
        }
      }
    });
    var box = form.querySelector('.js-evt-names');
    if (box && box.getAttribute('data-sig') !== names) { box.innerHTML = names; box.setAttribute('data-sig', names); }
    var fee = total > 0 ? Math.ceil(Math.round(((total * RATE + FIXED) / (1 - RATE)) * 100 * 1e6) / 1e6) / 100 : 0;
    var grand = total + fee;
    form.querySelector('.js-evt-feerow').style.display = fee > 0 ? '' : 'none';
    form.querySelector('.js-evt-feenote').style.display = fee > 0 ? '' : 'none';
    form.querySelector('.js-evt-fee').textContent = money(fee);
    form.querySelector('.js-evt-total').textContent = money(grand);
    btn.disabled = count === 0;
    btn.textContent = count === 0 ? 'Continue' : (grand > 0 ? 'Continue to payment · ' + money(grand) : 'Get free tickets');
  }
  ['input', 'change', 'click'].forEach(function (ev) { form.addEventListener(ev, function () { setTimeout(render, 0); }); });
  render();
  window.addEventListener('load', function () { setTimeout(render, 0); }); // the site script sets its own label on load
})();
</script>
<?php endif; ?>
