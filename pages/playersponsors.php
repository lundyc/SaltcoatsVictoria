<?php
declare(strict_types=1);

/** Route: /playersponsors — step 1: browse players, add open Home/Away kit sponsorship
 *  slots to a request (no payment: the club approves first). Logic mirrors admin/playersponsors.php; presentation is the
 *  public-site design. Checkout is /playersponsors/checkout. */

require_once HUB_ROOT . '/lib/season.php';
require_once HUB_ROOT . '/lib/sponsorship_catalog.php';
require_once HUB_ROOT . '/lib/player_sponsorship_shop.php';
require_once HUB_ROOT . '/lib/player_sponsorship_basket.php';
$pdo = db();
ensurePlayerSponsorshipShopSchema($pdo);
player_sponsorship_basket_start();

$currentSeason = getCurrentSeason($pdo);
$seasonId = (int) ($currentSeason['id'] ?? 0);
$players = $seasonId > 0 ? player_sponsorship_shop_players_with_availability($pdo, $seasonId) : [];
$packages = $seasonId > 0 ? player_sponsorship_shop_packages($pdo) : [];
$seasonPricing = $seasonId > 0 ? getSeasonPlayerPricing($pdo, $seasonId) : [];


function ps_render_basket(array $basket, string $csrf): string
{
    ob_start();
    ?>
    <aside class="osummary stbasket" id="basket" aria-live="polite" aria-label="Your sponsorship request">
      <h2>Your request</h2>
      <?php if (empty($basket['items'])): ?>
        <p class="stbasket__empty">No sponsorships added yet. Choose a kit slot to add to your request.</p>
      <?php else: ?>
        <ul class="stbasket__items">
          <?php foreach ($basket['items'] as $item): ?>
            <li>
              <span><strong><?= e((string) $item['player_name']) ?></strong><br><small><?= e(str_replace(' Sponsorship', '', (string) player_sponsorship_shop_package_label((string) $item['package_code']))) ?></small></span>
              <span class="stbasket__price"><?= e(gbp((float) $item['price'])) ?></span>
              <form method="post" data-basket-form>
                <?= $csrf ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="item_id" value="<?= e((string) $item['id']) ?>">
                <button type="submit" class="bline__remove" aria-label="Remove <?= e((string) $item['player_name']) ?> from request">Remove</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
        <div class="osummary__row osummary__row--total"><span>Guide total</span><span><?= e(gbp((float) $basket['total'])) ?></span></div>
        <a class="btn btn--lg" style="width:100%;margin-top:1rem" href="<?= e(url('playersponsors/checkout')) ?>">Send my request →</a>
        <p class="pdp__note">No payment now. The club checks every request and will email you within 3 working days.</p>
      <?php endif; ?>
    </aside>
    <?php
    return (string) ob_get_clean();
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $isAjaxBasketRequest = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if (!csrf_check()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add') {
            $playerId = (int) ($_POST['player_id'] ?? 0);
            $packageCode = (string) ($_POST['package_code'] ?? '');
            $valid = false;
            foreach ($players as $player) {
                if ($player['id'] !== $playerId) {
                    continue;
                }
                foreach ($player['packages'] as $packageState) {
                    if ($packageState['package_code'] === $packageCode && $packageState['available']) {
                        $valid = true;
                    }
                }
            }
            if (!$valid) {
                $error = 'Sorry, that sponsorship slot is no longer available.';
            } else {
                player_sponsorship_basket_add($playerId, $packageCode);
                if (!$isAjaxBasketRequest) {
                    redirect(url('playersponsors') . '#basket');
                }
            }
        } elseif ($action === 'remove') {
            player_sponsorship_basket_remove((string) ($_POST['item_id'] ?? ''));
            if (!$isAjaxBasketRequest) {
                redirect(url('playersponsors') . '#basket');
            }
        }

        if ($isAjaxBasketRequest) {
            $basket = player_sponsorship_basket_summary($players);
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => $error === '',
                'error' => $error,
                'basket_html' => ps_render_basket($basket, csrf_field()),
                'basket_keys' => array_keys(player_sponsorship_basket_key_set()),
            ], JSON_THROW_ON_ERROR);
            exit;
        }
    }
    if ($isAjaxBasketRequest) {
        $basket = player_sponsorship_basket_summary($players);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => $error ?: 'Your request could not be updated.',
            'basket_html' => ps_render_basket($basket, csrf_field()),
            'basket_keys' => array_keys(player_sponsorship_basket_key_set()),
        ], JSON_THROW_ON_ERROR);
        exit;
    }
}

$basket = player_sponsorship_basket_summary($players);
$basketKeys = player_sponsorship_basket_key_set();
$csrfFieldHtml = csrf_field();

$seasonLabel = $currentSeason ? trim((string) $currentSeason['name']) : '';
$seasonShort = $seasonLabel;
if (preg_match('#^(\d{4})\s*[/-]\s*(?:\d{2})?(\d{2})$#', $seasonLabel, $m)) {
    $seasonShort = $m[1] . '/' . $m[2];
}
set_meta([
    'title' => 'Sponsor a Player' . ($seasonShort !== '' ? ' ' . $seasonShort : ''),
    'description' => 'Sponsor a ' . club('club_name') . ' player\'s home or away kit' . ($seasonShort !== '' ? ' for ' . $seasonShort : '') . ' and support the club. Send a request: no payment is taken until the club has approved it.',
]);
seo_breadcrumbs([['Commercial', url('commercial')], ['Sponsor a player', url('playersponsors')]]);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Back the lads',
    'title'   => 'Sponsor a Player' . ($seasonLabel !== '' ? ' — ' . $seasonLabel : ''),
    'sub'     => 'Choose a player\'s Home or Away kit to sponsor and send us a request. There is no payment now: the club replies within 3 working days, and you only pay once it is approved.',
]); ?>

<div class="page">
  <div class="container">
    <?php if ($error !== ''): ?><div class="notice notice--err"><p><?= e($error) ?></p></div><?php endif; ?>

    <?php if (!$currentSeason || !$packages || !$players): ?>
      <div class="emptystate">
        <p><strong>Player kit sponsorship isn&rsquo;t currently available.</strong></p>
        <p>Please check back soon or <a href="<?= e(url('contact')) ?>">contact the club</a>.</p>
      </div>
    <?php else: ?>
      <div class="basket-layout">
        <div class="pslist">
          <div class="pslist__head">
            <span>Player</span>
            <span>Position</span>
            <?php foreach ($packages as $package): ?>
              <span class="pslist__pkg"><?= e((string) $package['name']) ?><small>From <?= e(gbp(player_sponsorship_shop_package_price($package, $seasonPricing))) ?></small></span>
            <?php endforeach; ?>
          </div>
          <?php foreach ($players as $player): ?>
            <div class="pslist__row">
              <div class="pslist__player">
                <?php if ($player['avatar_url'] !== ''): ?>
                  <img src="<?= e($player['avatar_url']) ?>" alt="" loading="lazy">
                <?php else: ?>
                  <span class="pslist__fallback"><?= e(strtoupper(substr((string) $player['name'], 0, 1))) ?></span>
                <?php endif; ?>
                <strong><?= e((string) $player['name']) ?></strong>
              </div>
              <div class="pslist__pos" data-label="Position"><?= e((string) $player['position']) ?></div>
              <?php foreach ($player['packages'] as $packageState): ?>
                <?php $key = $player['id'] . ':' . $packageState['package_code']; ?>
                <div class="pslist__cell" data-label="<?= e((string) $packageState['package_code']) ?>">
                  <?php if (isset($basketKeys[$key])): ?>
                    <span class="pstag pstag--added">&#10003; Added</span>
                  <?php elseif (!$packageState['available']): ?>
                    <span class="pstag pstag--taken" title="Sponsored by <?= e((string) ($packageState['sponsor_name'] ?? '')) ?>"><?= ($packageState['sponsor_name'] ?? '') !== '' ? e((string) $packageState['sponsor_name']) : 'Sponsored' ?></span>
                  <?php else: ?>
                    <form method="post" data-basket-form>
                      <?= $csrfFieldHtml ?>
                      <input type="hidden" name="action" value="add">
                      <input type="hidden" name="player_id" value="<?= (int) $player['id'] ?>">
                      <input type="hidden" name="package_code" value="<?= e((string) $packageState['package_code']) ?>">
                      <button type="submit" class="btn btn--sm">Add</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?= ps_render_basket($basket, $csrfFieldHtml) ?>
      </div>
    <?php endif; ?>
    <p class="stlinks"><a href="<?= e(url('fixtures')) ?>">Fixtures &amp; results &rarr;</a></p>
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
    var isAdd = (form.querySelector('[name="action"]') || {}).value === 'add';
    fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
      body: new FormData(form)
    }).then(function (r) { return r.json().then(function (p) { return [r, p]; }); })
      .then(function (rp) {
        if (!rp[0].ok || !rp[1].ok) throw new Error(rp[1].error || 'Your request could not be updated.');
        var basket = document.getElementById('basket');
        if (basket && rp[1].basket_html) basket.outerHTML = rp[1].basket_html;
        if (isAdd) {
          var cell = form.closest('.pslist__cell');
          if (cell) cell.innerHTML = '<span class="pstag pstag--added">&#10003; Added</span>';
        } else {
          window.location.reload();
        }
      })
      .catch(function (err) { window.alert(err.message || 'Your request could not be updated.'); if (button) button.disabled = false; });
  });
})();
</script>
