<?php
/** Route: /tickets — match ticket shop (guest checkout). Reuses
 *  lib/match_tickets.php; presentation is the public-site design. The live
 *  /tickets keeps its guest/account/login options. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/season.php';
require_once HUB_ROOT . '/lib/match_tickets.php';

ensureMatchTicketSchema(db());

$currentSeason = getCurrentSeason(db());
$seasonId = (int) ($currentSeason['id'] ?? 0);
$fixtureId = (int) ($_GET['fixture_id'] ?? ($_POST['fixture_id'] ?? 0));
$fixtures = $seasonId > 0 ? getTicketedHomeFixtures(db(), $seasonId, true) : [];
$fixture = null;
foreach ($fixtures as $row) {
    if ((int) $row['id'] === $fixtureId) {
        $fixture = $row;
        break;
    }
}

$errors = [];
$cart = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$fixture) {
        $errors[] = 'Choose a home game.';
    } else {
        foreach ((array) ($_POST['qty'] ?? []) as $fixturePackageId => $qty) {
            $qty = max(0, min(20, (int) $qty));
            if ($qty > 0) {
                $cart[(int) $fixturePackageId] = $qty;
            }
        }
        if (!$cart) {
            $errors[] = 'Choose at least one ticket.';
        }
        $buyer = [
            'holder_id' => null,
            'name' => trim((string) ($_POST['buyer_name'] ?? '')),
            'email' => trim((string) ($_POST['buyer_email'] ?? '')),
            'phone' => trim((string) ($_POST['buyer_phone'] ?? '')),
        ];
        if ($buyer['name'] === '') {
            $errors[] = 'Enter your name.';
        }
        if (!filter_var($buyer['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        if (!$errors) {
            try {
                db()->beginTransaction();
                $orderId = createMatchTicketOrder(db(), $buyer, (int) $fixture['id'], $cart, 'online', 'guest');
                $order = getMatchTicketOrder(db(), $orderId);
                db()->commit();

                $viewUrl = current_url_origin() . url('tickets/order/' . rawurlencode((string) $order['access_token']));

                if ($order && (float) $order['total_amount'] > 0 && stripe_is_configured()) {
                    $checkout = match_ticket_stripe_create_checkout_session(
                        db(),
                        $orderId,
                        $viewUrl . '?paid=1',
                        current_url_origin() . url('tickets') . '?fixture_id=' . (int) $fixture['id'] . '&cancelled=1'
                    );
                    redirect($checkout['url']);
                }
                redirect($viewUrl . '?saved=1');
            } catch (Throwable $e) {
                if (db()->inTransaction()) {
                    db()->rollBack();
                }
                $errors[] = $e->getMessage();
            }
        }
    }
}

$packages = $fixture ? getFixtureTicketPackages(db(), (int) $fixture['id'], true) : [];
$clubShort = club('club_short_name', club('club_name', 'Saltcoats Victoria FC'));

/** Small helpers scoped to this page. */
$tkt_crest_light = static fn (): string => function_exists('club_crest') ? club_crest() : '';
$tkt_crest_dark  = static fn (): string => function_exists('club_crest_reverse') ? club_crest_reverse() : (function_exists('club_crest') ? club_crest() : '');
$tkt_opp_crest   = static function (array $g): string {
    if (!function_exists('pub_opponent_crest')) {
        return '';
    }
    $logo = (string) ($g['opponent_logo'] ?? '');
    $name = (string) ($g['opponent'] ?? '');

    // getTicketedHomeFixtures() selects `f.*` only — no join to match_opponents —
    // so the badge path isn't in the row. Resolve it from opponent_id the way
    // the fixtures/results pages do, otherwise pub_opponent_crest() only has the
    // club name to go on and misses any uploaded (non-slug) badge.
    if ($logo === '' && !empty($g['opponent_id'])) {
        static $oppCache = [];
        $oid = (int) $g['opponent_id'];
        if (!array_key_exists($oid, $oppCache)) {
            try {
                $st = db()->prepare('SELECT logo_path, clubname FROM match_opponents WHERE id = :id LIMIT 1');
                $st->execute([':id' => $oid]);
                $oppCache[$oid] = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {
                $oppCache[$oid] = [];
            }
        }
        $logo = (string) ($oppCache[$oid]['logo_path'] ?? '');
        if ($name === '') {
            $name = (string) ($oppCache[$oid]['clubname'] ?? '');
        }
    }
    return pub_opponent_crest($logo, $name);
};
$tkt_monogram = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $p) {
        if ($p !== '') {
            $letters .= mb_substr($p, 0, 1);
        }
        if (mb_strlen($letters) >= 2) {
            break;
        }
    }
    return mb_strtoupper($letters ?: mb_substr($name, 0, 1));
};
$tkt_datebits = static function (?string $d): array {
    return [
        'dow'   => format_date($d, 'D'),
        'day'   => format_date($d, 'j'),
        'month' => format_date($d, 'M'),
        'full'  => format_date($d, 'l j F Y'),
    ];
};
$tkt_meta = static function (array $g): string {
    $bits = [];
    if (!empty($g['competition'])) {
        $bits[] = (string) $g['competition'];
    }
    $bits[] = format_date($g['match_date'], 'l j F Y');
    if (!empty($g['kickoff_time'])) {
        $bits[] = 'KO ' . format_time($g['kickoff_time']);
    }
    if (!empty($g['venue'])) {
        $bits[] = (string) $g['venue'];
    }
    return implode('  ·  ', $bits);
};

set_meta(['title' => 'Match tickets', 'description' => 'Buy tickets for ' . club('club_name') . ' home games — digital QR entry.']);
?>
<?php if (!$fixture): ?>

  <?php partial('page_hero', [
      'eyebrow' => 'Match day',
      'title'   => 'Match tickets',
      'sub'     => 'Buy online for any home game and show your digital QR code at the gate.',
  ]); ?>

  <div class="page">
    <div class="container">
      <?php if (isset($_GET['cancelled'])): ?>
        <div class="notice notice--warn"><p>Payment was cancelled — no money was taken.</p></div>
      <?php endif; ?>
      <?php foreach ($errors as $er): ?><div class="notice notice--err"><p><?= e($er) ?></p></div><?php endforeach; ?>

      <?php if (!$fixtures): ?>
        <div class="emptystate">
          <p><strong>No home games are on sale right now.</strong></p>
          <p>Tickets open here as soon as the next fixture is confirmed. You can always pay cash at the gate on matchday.</p>
        </div>
      <?php else: ?>

        <?php $next = $fixtures[0]; $nb = $tkt_datebits($next['match_date']); $noc = $tkt_opp_crest($next); ?>
        <a class="tkt-featured" href="<?= e(url('tickets') . '?fixture_id=' . (int) $next['id']) ?>">
          <span class="tkt-featured__tag">Next home game</span>
          <span class="tkt-featured__crests">
            <?php if ($tkt_crest_dark() !== ''): ?><img src="<?= e($tkt_crest_dark()) ?>" alt="" loading="lazy"><?php endif; ?>
            <span class="tkt-featured__v">v</span>
            <?php if ($noc !== ''): ?><img src="<?= e($noc) ?>" alt="" loading="lazy"><?php else: ?><span class="tkt-mono"><?= e($tkt_monogram((string) $next['opponent'])) ?></span><?php endif; ?>
          </span>
          <span class="tkt-featured__body">
            <strong class="tkt-featured__title"><?= e($clubShort) ?> <span>v</span> <?= e((string) $next['opponent']) ?></strong>
            <span class="tkt-featured__meta"><?= e($tkt_meta($next)) ?></span>
          </span>
          <span class="tkt-featured__cta">Buy tickets</span>
        </a>

        <ol class="tkt-how">
          <li><span>1</span> Choose your home game</li>
          <li><span>2</span> Pick your tickets &amp; pay securely by card</li>
          <li><span>3</span> Show the QR code from your email at the gate</li>
        </ol>

        <h2 class="tkt-h">All home games</h2>
        <?php
        $byMonth = [];
        foreach ($fixtures as $g) {
            $byMonth[format_date($g['match_date'], 'F Y')][] = $g;
        }
        ?>
        <?php $rowNo = 0; foreach ($byMonth as $month => $games): ?>
          <div class="tkt-month"><?= e($month) ?></div>
          <ul class="tkt-list">
            <?php foreach ($games as $g): $rowNo++; $db = $tkt_datebits($g['match_date']); $oc = $tkt_opp_crest($g); ?>
              <li>
                <a class="tkt-card" href="<?= e(url('tickets') . '?fixture_id=' . (int) $g['id']) ?>">
                  <span class="tkt-card__date">
                    <b><?= e($db['dow']) ?></b>
                    <em><?= e($db['day']) ?></em>
                    <span><?= e($db['month']) ?></span>
                  </span>
                  <span class="tkt-card__crest">
                    <?php if ($oc !== ''): ?><img src="<?= e($oc) ?>" alt="" loading="lazy"><?php else: ?><span class="tkt-mono tkt-mono--sm"><?= e($tkt_monogram((string) $g['opponent'])) ?></span><?php endif; ?>
                  </span>
                  <span class="tkt-card__body">
                    <strong><?= e($clubShort) ?> <span>v</span> <?= e((string) $g['opponent']) ?><?php if ($rowNo === 1): ?> <span class="tkt-chip">Next</span><?php endif; ?></strong>
                    <span><?php
                        $sub = [];
                        if (!empty($g['competition'])) { $sub[] = (string) $g['competition']; }
                        $sub[] = !empty($g['kickoff_time']) ? 'KO ' . format_time($g['kickoff_time']) : 'Kick-off TBC';
                        echo e(implode('  ·  ', $sub));
                    ?></span>
                  </span>
                  <span class="tkt-card__cta">Buy tickets</span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endforeach; ?>

      <?php endif; ?>
    </div>
  </div>

<?php else: ?>

  <?php
  $db = $tkt_datebits($fixture['match_date']);
  $oc = $tkt_opp_crest($fixture);
  $heroSub = format_date($fixture['match_date'], 'l j F Y');
  if (!empty($fixture['kickoff_time'])) {
      $heroSub .= '  ·  KO ' . format_time($fixture['kickoff_time']);
  }
  partial('page_hero', [
      'eyebrow' => 'Match day tickets',
      'title'   => $clubShort . ' v ' . (string) $fixture['opponent'],
      'sub'     => $heroSub,
      'back'    => ['href' => url('tickets'), 'label' => '‹ All home games'],
  ]);
  ?>

  <div class="page">
    <div class="container">
      <?php if (isset($_GET['cancelled'])): ?>
        <div class="notice notice--warn"><p>Payment was cancelled — no money was taken. Your selection is below.</p></div>
      <?php endif; ?>
      <?php foreach ($errors as $er): ?><div class="notice notice--err"><p><?= e($er) ?></p></div><?php endforeach; ?>

      <div class="tkt-matchhead">
        <span class="tkt-matchhead__crests">
          <?php if ($tkt_crest_light() !== ''): ?><img src="<?= e($tkt_crest_light()) ?>" alt="" loading="lazy"><?php endif; ?>
          <span class="tkt-matchhead__v">v</span>
          <?php if ($oc !== ''): ?><img src="<?= e($oc) ?>" alt="" loading="lazy"><?php else: ?><span class="tkt-mono"><?= e($tkt_monogram((string) $fixture['opponent'])) ?></span><?php endif; ?>
        </span>
        <span class="tkt-matchhead__body">
          <strong><?= e($clubShort) ?> <span>v</span> <?= e((string) $fixture['opponent']) ?></strong>
          <span class="tkt-matchhead__meta">
            <?php if (!empty($fixture['competition'])): ?><span class="tkt-chip tkt-chip--plain"><?= e((string) $fixture['competition']) ?></span><?php endif; ?>
            <span><?= e($db['full']) ?></span>
            <?php if (!empty($fixture['kickoff_time'])): ?><span>KO <?= e(format_time($fixture['kickoff_time'])) ?></span><?php endif; ?>
            <?php if (!empty($fixture['venue'])): ?><span><?= e((string) $fixture['venue']) ?></span><?php endif; ?>
            <span class="tkt-chip tkt-chip--home">Home</span>
          </span>
        </span>
      </div>

      <?php if (!$packages): ?>
        <div class="emptystate tkt-empty">
          <p><strong>Tickets for this game aren’t on sale online yet.</strong></p>
          <p>We’ll list them here as soon as they’re released — or you can pay cash at the gate on matchday.</p>
          <p><a class="btn btn--sm" href="<?= e(url('tickets')) ?>">‹ Back to all home games</a></p>
        </div>
      <?php else: ?>
        <form method="post" class="basket-layout" data-ticket-form>
          <?= csrf_field() ?>
          <input type="hidden" name="fixture_id" value="<?= (int) $fixture['id'] ?>">

          <div>
            <h2 class="tkt-h">Choose your tickets</h2>
            <?php
            /* Group packages by their package_group (set per template in the Hub).
               "General" always leads, "Hospitality" next, anything else after. */
            $groups = [];
            foreach ($packages as $pk) {
                $gk = trim((string) ($pk['package_group'] ?? '')) ?: 'General';
                $groups[$gk][] = $pk;
            }
            $groupOrder = array_values(array_unique(array_merge(['General', 'Hospitality'], array_keys($groups))));
            $groupLabels = [
                'General'     => 'General admission',
                'Hospitality' => 'Hospitality &amp; matchday packages',
            ];
            $multiGroup = count($groups) > 1;
            foreach ($groupOrder as $gname):
                if (empty($groups[$gname])) {
                    continue;
                }
                ?>
                <section class="tkt-group<?= $gname !== 'General' ? ' tkt-group--feature' : '' ?>">
                  <?php if ($multiGroup): ?>
                    <h3 class="tkt-grouph"><?= $groupLabels[$gname] ?? e($gname) ?></h3>
                  <?php endif; ?>
                  <ul class="tkt-lines">
                    <?php foreach ($groups[$gname] as $package): ?>
                      <?php
                      $remaining = (int) $package['allocation'] > 0
                          ? max(0, (int) $package['allocation'] - (int) $package['sold_qty'])
                          : 999;
                      $soldOut = $remaining <= 0;
                      $lowStock = !$soldOut && (int) $package['allocation'] > 0 && $remaining <= 10;
                      $cap = min(20, $soldOut ? 0 : $remaining);
                      $val = (int) ($cart[(int) $package['id']] ?? 0);
                      ?>
                      <li class="tkt-line<?= $soldOut ? ' is-soldout' : '' ?>"
                          data-name="<?= e((string) $package['name']) ?>"
                          data-price="<?= e(number_format((float) $package['price'], 2, '.', '')) ?>">
                        <div class="tkt-line__info">
                          <span class="tkt-line__name"><?= e((string) $package['name']) ?></span>
                          <?php if (($package['description'] ?? '') !== ''): ?>
                            <span class="tkt-line__desc"><?= e((string) $package['description']) ?></span>
                          <?php endif; ?>
                          <?php if ($soldOut): ?>
                            <span class="tkt-line__stock is-out">Sold out</span>
                          <?php elseif ($lowStock): ?>
                            <span class="tkt-line__stock is-low">Only <?= (int) $remaining ?> left</span>
                          <?php endif; ?>
                        </div>
                        <div class="tkt-line__price"><?= e(gbp((float) $package['price'])) ?></div>
                        <div class="tkt-line__qty">
                          <?php if ($soldOut): ?>
                            <span class="tkt-line__soldtag">Sold out</span>
                            <input type="hidden" name="qty[<?= (int) $package['id'] ?>]" value="0">
                          <?php else: ?>
                            <div class="stepper" data-stepper>
                              <button type="button" data-step="-1" aria-label="Fewer <?= e((string) $package['name']) ?>">−</button>
                              <input class="tkt-qty" type="number" inputmode="numeric" min="0" max="<?= $cap ?>"
                                     name="qty[<?= (int) $package['id'] ?>]" value="<?= $val ?>"
                                     aria-label="Quantity — <?= e((string) $package['name']) ?>">
                              <button type="button" data-step="1" aria-label="More <?= e((string) $package['name']) ?>">+</button>
                            </div>
                          <?php endif; ?>
                        </div>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </section>
            <?php endforeach; ?>
          </div>

          <aside class="osummary tkt-summary">
            <h2>Order summary</h2>
            <div class="osummary__items js-tkt-items">
              <p class="tkt-summary__empty">No tickets selected yet.</p>
            </div>
            <div class="osummary__row osummary__row--total">
              <span>Total</span><span class="js-tkt-total"><?= e(gbp(0)) ?></span>
            </div>

            <h2 class="tkt-summary__details">Your details</h2>
            <label class="tkt-field">
              <span>Full name</span>
              <input name="buyer_name" autocomplete="name" value="<?= e((string) ($_POST['buyer_name'] ?? '')) ?>" required>
            </label>
            <label class="tkt-field">
              <span>Email</span>
              <input type="email" name="buyer_email" autocomplete="email" value="<?= e((string) ($_POST['buyer_email'] ?? '')) ?>" required>
            </label>
            <label class="tkt-field">
              <span>Phone <em>(optional)</em></span>
              <input name="buyer_phone" autocomplete="tel" value="<?= e((string) ($_POST['buyer_phone'] ?? '')) ?>">
            </label>

            <button type="submit" class="btn btn--lg btn--block js-tkt-submit" disabled>Continue to payment</button>
            <p class="tkt-summary__note"><?= pub_icon('basket') ?> Secure card payment via Stripe. Your tickets and QR codes are emailed to you straight away.</p>
          </aside>
        </form>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>
