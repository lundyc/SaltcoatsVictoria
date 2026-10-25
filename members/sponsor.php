<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/member_sponsorship.php';

$currentSeason = getCurrentSeason($pdo);
$error = '';
$notice = '';

$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk') . '/members/sponsor.php';
ensureMemberSponsorshipPackages($pdo);

if (!isset($_SESSION['member_sponsorship_cart']) || !is_array($_SESSION['member_sponsorship_cart'])) {
    $_SESSION['member_sponsorship_cart'] = [];
}

function member_sponsor_cart_label(PDO $pdo, string $packageCode, ?int $playerId, ?int $fixtureId): string
{
    $package = getSponsorshipPackageByCode($pdo, $packageCode);
    $label = $package ? (string) $package['name'] : $packageCode;
    if ($playerId) {
        $stmt = $pdo->prepare('SELECT name FROM players WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $playerId]);
        $playerName = (string) ($stmt->fetchColumn() ?: '');
        return $playerName !== '' ? $label . ' - ' . $playerName : $label;
    }
    if ($fixtureId) {
        $stmt = $pdo->prepare('SELECT opponent, match_date, is_home FROM match_fixtures WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $fixtureId]);
        $fixture = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fixture) {
            return $label . ' - ' . ((int) $fixture['is_home'] === 1 ? 'Home' : 'Away') . ' v ' . (string) $fixture['opponent'] . ' (' . date('d/m/Y', strtotime((string) $fixture['match_date'])) . ')';
        }
    }
    return $label;
}

function member_sponsor_cart_add(PDO $pdo, string $packageCode, ?int $playerId, ?int $fixtureId): void
{
    $package = getSponsorshipPackageByCode($pdo, $packageCode);
    if (!$package || (int) $package['is_active'] !== 1) {
        throw new RuntimeException('That sponsorship option is not available.');
    }
    $key = $packageCode . ':' . (int) ($playerId ?? 0) . ':' . (int) ($fixtureId ?? 0);
    foreach ($_SESSION['member_sponsorship_cart'] as $item) {
        if (($item['key'] ?? '') === $key) {
            throw new RuntimeException('That sponsorship is already in your basket.');
        }
    }
    $_SESSION['member_sponsorship_cart'][] = [
        'key' => $key,
        'package_code' => $packageCode,
        'player_id' => $playerId,
        'fixture_id' => $fixtureId,
        'label' => member_sponsor_cart_label($pdo, $packageCode, $playerId, $fixtureId),
        'amount' => member_sponsorship_package_price($pdo, $package, $fixtureId),
    ];
}

/** Cheapest price a package is ever sold at (home vs away can differ). */
function member_sponsor_lowest_price(array $package): float
{
    $prices = [(float) $package['amount']];
    if (isset($package['away_amount']) && $package['away_amount'] !== null && $package['away_amount'] !== '') {
        $prices[] = (float) $package['away_amount'];
    }
    return min($prices);
}

/** "£50.00" or "£50.00 home · £20.00 away" for a panel heading. */
function member_sponsor_price_label(array $package): string
{
    $home = (float) $package['amount'];
    if (isset($package['away_amount']) && $package['away_amount'] !== null && $package['away_amount'] !== '' && (float) $package['away_amount'] !== $home) {
        return gbp($home) . ' home · ' . gbp((float) $package['away_amount']) . ' away';
    }
    return gbp($home);
}

/** The basket panel (also returned as HTML by the add/remove AJAX calls). */
function member_sponsor_render_basket(array $cart, float $cartTotal, string $csrfToken): string
{
    ob_start();
    ?>
            <aside class="spx-basket" id="basket" aria-label="Your basket">
                <div class="spx-basket__head"><h2>Your basket</h2><span><?= count($cart) ?> item<?= count($cart) === 1 ? '' : 's' ?></span></div>
                <div class="spx-basket__body">
                    <?php if (!$cart): ?>
                        <p class="spx-basket__empty"><i class="fa-solid fa-basket-shopping" aria-hidden="true"></i>Nothing here yet. Choose a sponsorship above and add it.</p>
                    <?php else: ?>
                        <ul class="spx-basket__items">
                            <?php foreach ($cart as $index => $item): ?>
                                <li>
                                    <span><strong><?= h((string) $item['label']) ?></strong><small><?= h(gbp((float) $item['amount'])) ?></small></span>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="cart_action" value="remove">
                                        <input type="hidden" name="cart_index" value="<?= (int) $index ?>">
                                        <button class="bline__remove" type="submit" aria-label="Remove <?= h((string) $item['label']) ?>">Remove</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="spx-basket__total"><span>Total</span><strong><?= h(gbp((float) $cartTotal)) ?></strong></div>
                        <form method="post" class="vstack gap-2">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="cart_action" value="checkout">
                            <button class="btn spx-bigbtn" type="submit"><i class="fa-solid fa-lock" aria-hidden="true"></i> Pay Now</button>
                            <div class="form-text text-center">Secure card payment with Stripe. Your whole basket is paid in one go.</div>
                        </form>
                        <form method="post" class="mt-2 text-center">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="cart_action" value="clear">
                            <button class="btn btn-link text-muted p-0" type="submit">Clear basket</button>
                        </form>
                    <?php endif; ?>
                </div>
            </aside>
    <?php
    return (string) ob_get_clean();
}

/** The floating "N items · £X — view basket" pill shown on small screens. */
function member_sponsor_render_float(array $cart, float $cartTotal): string
{
    if (!$cart) {
        return '';
    }
    return '<a class="spx-float" href="#basket"><i class="fa-solid fa-basket-shopping" aria-hidden="true"></i><span>' . count($cart) . ' item' . (count($cart) === 1 ? '' : 's') . ' &middot; ' . h(gbp($cartTotal)) . '</span><b>View basket &amp; check out &rarr;</b></a>';
}

function member_sponsor_tab_icon(string $code): string
{
    if (str_starts_with($code, 'player_')) {
        return 'fa-shirt';
    }
    return match ($code) {
        'pitchside-boards' => 'fa-sign-hanging',
        'match_day' => 'fa-calendar-day',
        'match_ball' => 'fa-futbol',
        default => 'fa-handshake',
    };
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $currentSeason) {
    if (!member_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['cart_action'] ?? 'add');
        try {
            if ($action === 'remove') {
                $index = (int) ($_POST['cart_index'] ?? -1);
                if (isset($_SESSION['member_sponsorship_cart'][$index])) {
                    unset($_SESSION['member_sponsorship_cart'][$index]);
                    $_SESSION['member_sponsorship_cart'] = array_values($_SESSION['member_sponsorship_cart']);
                    $notice = 'Removed from your basket.';
                }
            } elseif ($action === 'clear') {
                $_SESSION['member_sponsorship_cart'] = [];
                $notice = 'Your basket has been cleared.';
            } elseif ($action === 'checkout') {
                // Card payment only, for the whole basket in a single Stripe Checkout.
                $cart = $_SESSION['member_sponsorship_cart'];
                if (!$cart) {
                    throw new RuntimeException('Add at least one sponsorship to your basket first.');
                }
                $result = member_sponsorship_purchase_cart(
                    $pdo,
                    $currentHolder,
                    $cart,
                    (int) $currentSeason['id'],
                    $baseUrl . '?success=1',
                    $baseUrl . '?cancelled=1'
                );
                $_SESSION['member_sponsorship_cart'] = [];
                header('Location: ' . $result['url']);
                exit;
            } else {
                $packageCode = (string) ($_POST['package_code'] ?? '');
                $playerId = !empty($_POST['player_id']) ? (int) $_POST['player_id'] : null;
                $fixtureId = !empty($_POST['fixture_id']) ? (int) $_POST['fixture_id'] : null;
                $package = getSponsorshipPackageByCode($pdo, $packageCode);
                if (!$package) {
                    throw new RuntimeException('That sponsorship option is not available.');
                }
                $isPlayerPackage = in_array($packageCode, MEMBER_SPONSORSHIP_PLAYER_CODES, true);
                $isMatchPackage = in_array($packageCode, MEMBER_SPONSORSHIP_MATCH_CODES, true);
                $isBoardPackage = in_array($packageCode, MEMBER_SPONSORSHIP_BOARD_CODES, true);
                if (!$isPlayerPackage && !$isMatchPackage && !$isBoardPackage) {
                    throw new RuntimeException('That sponsorship option is not available for self sign-up.');
                }
                if ($isPlayerPackage && !$playerId) {
                    throw new RuntimeException('Choose a player to sponsor.');
                }
                if ($isMatchPackage && !$fixtureId) {
                    throw new RuntimeException('Choose a fixture to sponsor.');
                }
                member_sponsorship_assert_slot_open($pdo, $package, $playerId, $fixtureId, (int) $currentSeason['id']);
                member_sponsor_cart_add($pdo, $packageCode, $playerId, $fixtureId);
                $notice = 'Added to your basket.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

// Basket actions made from the page (add / remove / clear) are sent with fetch(): answer with
// JSON (a toast message plus the refreshed basket) instead of reloading the page.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
    && strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
    $ajaxCart = $_SESSION['member_sponsorship_cart'];
    $ajaxTotal = (float) array_sum(array_map(static fn(array $item): float => (float) ($item['amount'] ?? 0), $ajaxCart));
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => $error === '',
        'message' => $error !== '' ? $error : $notice,
        'basket_html' => member_sponsor_render_basket($ajaxCart, $ajaxTotal, member_auth_csrf_token()),
        'float_html' => member_sponsor_render_float($ajaxCart, $ajaxTotal),
        'count' => count($ajaxCart),
    ], JSON_THROW_ON_ERROR);
    exit;
}

$playerOptions = $currentSeason ? member_sponsorship_player_options($pdo, (int) $currentSeason['id']) : [];
$matchOptions = $currentSeason ? member_sponsorship_match_options($pdo, (int) $currentSeason['id']) : [];
$boardOptions = member_sponsorship_board_options($pdo);
$cart = $_SESSION['member_sponsorship_cart'];
$cartTotal = array_sum(array_map(static fn(array $item): float => (float) ($item['amount'] ?? 0), $cart));
?>

<?php
// Build the sections once so the picker and the panels stay in step.
$sections = [];
$sections[] = ['id' => 'pitchside-boards', 'icon' => member_sponsor_tab_icon('pitchside-boards'), 'name' => 'Pitchside Boards', 'tag' => 'Campbell Park',
    'blurb' => 'Season-long advertising boards around the pitch.', 'from' => $boardOptions ? min(array_map(static fn(array $p): float => (float) $p['amount'], $boardOptions)) : null, 'count' => count($boardOptions)];
// All player-kit packages (home / away) are presented as ONE "Player Kit Sponsor" option.
$kitPlayers = []; // player id => ['name' => ..., 'slots' => [packageCode => package]]
$kitPackages = [];
foreach ($playerOptions as $option) {
    $pk = $option['package'];
    $kitPackages[(string) $pk['code']] = $pk;
    foreach ($option['open_players'] as $pl) {
        $pid = (int) $pl['id'];
        $kitPlayers[$pid]['name'] = (string) $pl['name'];
        $kitPlayers[$pid]['slots'][(string) $pk['code']] = $pk;
    }
}
uasort($kitPlayers, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
$kitLabel = static function (array $pk): string {
    $n = (string) $pk['name'];
    if (stripos($n, 'home') !== false) { return 'Home kit'; }
    if (stripos($n, 'away') !== false) { return 'Away kit'; }
    return trim(str_ireplace(['Player', 'Sponsor', 'Sponsorship'], '', $n)) ?: $n;
};
if ($kitPackages) {
    $sections[] = ['id' => 'player-kit', 'icon' => 'fa-shirt', 'name' => 'Player Kit Sponsor', 'tag' => 'Per player',
        'blurb' => 'Sponsor a player\'s home or away kit.', 'from' => min(array_map(static fn(array $p): float => (float) $p['amount'], $kitPackages)), 'count' => count($kitPlayers), 'unit' => 'player' . (count($kitPlayers) === 1 ? '' : 's')];
}
foreach ($matchOptions as $option) {
    $pk = $option['package'];
    $sections[] = ['id' => (string) $pk['code'], 'icon' => member_sponsor_tab_icon((string) $pk['code']), 'name' => (string) $pk['name'], 'tag' => 'Per match',
        'blurb' => (string) $pk['description'], 'from' => member_sponsor_lowest_price($pk), 'count' => count($option['open_fixtures'])];
}
$csrfToken = member_auth_csrf_token();
$boardWhere = [
    'pitchside_board_small' => 'On our recently renovated stand, seen by home and visiting fans.',
    'pitchside_board_medium' => 'Within view of the pitch, around the ground.',
    'pitchside_board_large' => 'Inside the ground and outside it, seen by anyone passing, including visitors to Park Dean\'s caravan park.',
    'large_board_sponsor' => 'Inside the ground and outside it, seen by anyone passing, including visitors to Park Dean\'s caravan park.',
];
?>

<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">Sponsorship</div>
            <h1>Sponsorship Opportunities</h1>
            <p>Back the Vics: sponsor a player, a matchday or a pitchside board. Pick an option, add what you like, then check out from one basket.</p>
        </div>
    </section>

    <?php if (isset($_GET['success'])): ?><div class="alert alert-success">Thanks for your sponsorship. We'll be in touch.</div><?php endif; ?>
    <?php if (isset($_GET['cancelled'])): ?><div class="alert alert-warning">Payment was cancelled. No money was taken.</div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <?php if (!$currentSeason): ?>
        <div class="member-card"><div class="member-card__body text-muted">No active season is configured.</div></div>
    <?php else: ?>
        <div class="spx-layout">
            <div class="spx-main">
                <h2 class="spx-step"><span>1</span> Choose a sponsorship</h2>
                <div class="spx-picker" role="tablist" aria-label="Sponsorship types">
                    <?php foreach ($sections as $n => $sec): ?>
                        <button type="button" role="tab" class="spx-tile<?= $n === 0 ? ' is-active' : '' ?>" data-spx-tab="<?= h($sec['id']) ?>" aria-selected="<?= $n === 0 ? 'true' : 'false' ?>">
                            <i class="fa-solid <?= h($sec['icon']) ?>" aria-hidden="true"></i>
                            <strong><?= h($sec['name']) ?></strong>
                            <?php if ($sec['from'] !== null): ?><span class="spx-tile__price">from <?= h(gbp((float) $sec['from'])) ?></span><?php endif; ?>
                            <small><?= (int) $sec['count'] ?> <?= h($sec['unit'] ?? 'available') ?><?= isset($sec['unit']) ? ' available' : '' ?></small>
                        </button>
                    <?php endforeach; ?>
                </div>

                <h2 class="spx-step"><span>2</span> Pick one to add to your basket</h2>

                <section class="spx-panel is-active" id="pitchside-boards" data-spx-panel="pitchside-boards">
                    <div class="spx-panel__head"><h3>Pitchside Boards</h3><p>Season-long advertising at Campbell Park.</p></div>
                    <div class="spx-cards">
                        <?php foreach ($boardOptions as $package): ?>
                            <form method="post" class="spx-card spx-card--board">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="cart_action" value="add">
                                <input type="hidden" name="package_code" value="<?= h((string) $package['code']) ?>">
                                <div class="spx-card__top"><i class="fa-solid fa-sign-hanging" aria-hidden="true"></i></div>
                                <h4><?= h((string) $package['name']) ?></h4>
                                <p class="spx-card__desc"><?= h((string) $package['description']) ?></p>
                                <div class="spx-card__price"><?= h(gbp((float) $package['amount'])) ?></div>
                                <ul class="spx-benefits">
                                    <?php if (isset($boardWhere[(string) $package['code']])): ?><li><?= h($boardWhere[(string) $package['code']]) ?></li><?php endif; ?>
                                    <li>Digital coverage on our website and regular social media shout-outs.</li>
                                </ul>
                                <button class="btn spx-bigbtn" type="submit">Add to basket</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php if ($kitPackages): ?>
                    <section class="spx-panel" id="player-kit" data-spx-panel="player-kit">
                        <div class="spx-panel__head">
                            <h3>Player Kit Sponsor</h3>
                            <p>Choose a player, then sponsor their home kit, away kit or both.</p>
                        </div>
                        <?php if (!$kitPlayers): ?>
                            <div class="emptystate"><p>All players are currently sponsored.</p></div>
                        <?php else: ?>
                            <div class="spx-cards spx-cards--players">
                                <?php foreach ($kitPlayers as $pid => $pl): ?>
                                    <div class="spx-card spx-card--player">
                                        <span class="spx-avatar"><?= h(member_initials($pl['name'])) ?></span>
                                        <h4><?= h($pl['name']) ?></h4>
                                        <div class="spx-kits">
                                            <?php foreach ($kitPackages as $code => $pk): ?>
                                                <?php if (isset($pl['slots'][$code])): ?>
                                                    <form method="post">
                                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                                        <input type="hidden" name="cart_action" value="add">
                                                        <input type="hidden" name="package_code" value="<?= h($code) ?>">
                                                        <input type="hidden" name="player_id" value="<?= (int) $pid ?>">
                                                        <button class="btn spx-bigbtn spx-kitbtn spx-kitbtn--<?= stripos((string) $pk['name'], 'away') !== false ? 'away' : 'home' ?>" type="submit"><?= h($kitLabel($pk)) ?> &middot; <?= h(gbp((float) $pk['amount'])) ?></button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php foreach ($matchOptions as $option): $package = $option['package']; ?>
                    <section class="spx-panel" id="<?= h((string) $package['code']) ?>" data-spx-panel="<?= h((string) $package['code']) ?>">
                        <div class="spx-panel__head"><h3><?= h((string) $package['name']) ?> &middot; <?= h(member_sponsor_price_label($package)) ?></h3><p><?= h((string) $package['description']) ?></p></div>
                        <?php if (!$option['open_fixtures']): ?>
                            <div class="emptystate"><p>No upcoming fixtures are open for this package.</p></div>
                        <?php else: ?>
                            <div class="spx-cards spx-cards--fixtures">
                                <?php foreach ($option['open_fixtures'] as $fixture):
                                    $ts = strtotime((string) $fixture['match_date']);
                                    $isHome = (int) $fixture['is_home'] === 1;
                                    $oppCrest = pub_opponent_crest($fixture['opponent_logo'] ?? '', (string) $fixture['opponent']);
                                    $ko = !empty($fixture['kickoff_time']) ? member_format_time((string) $fixture['kickoff_time']) : '';
                                    $homeName = $isHome ? 'Saltcoats Victoria' : (string) $fixture['opponent'];
                                    $awayName = $isHome ? (string) $fixture['opponent'] : 'Saltcoats Victoria';
                                    $homeCrest = $isHome ? club_crest() : $oppCrest;
                                    $awayCrest = $isHome ? $oppCrest : club_crest();
                                ?>
                                    <form method="post" class="spx-card spx-card--fixture">
                                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                        <input type="hidden" name="cart_action" value="add">
                                        <input type="hidden" name="package_code" value="<?= h((string) $package['code']) ?>">
                                        <input type="hidden" name="fixture_id" value="<?= (int) $fixture['id'] ?>">
                                        <div class="spx-fx__head">
                                            <span class="spx-ha spx-ha--<?= $isHome ? 'home' : 'away' ?>"><?= $isHome ? 'Home' : 'Away' ?></span>
                                            <span class="spx-fx__comp"><?= h((string) ($fixture['competition'] ?? '')) ?></span>
                                        </div>
                                        <div class="spx-fx__teams">
                                            <div class="spx-fx__team">
                                                <?php if ($homeCrest !== ''): ?><img src="<?= h($homeCrest) ?>" alt="" loading="lazy"><?php endif; ?>
                                                <strong><?= h($homeName) ?></strong>
                                            </div>
                                            <span class="spx-fx__v">v</span>
                                            <div class="spx-fx__team">
                                                <?php if ($awayCrest !== ''): ?><img src="<?= h($awayCrest) ?>" alt="" loading="lazy"><?php endif; ?>
                                                <strong><?= h($awayName) ?></strong>
                                            </div>
                                        </div>
                                        <div class="spx-fx__when">
                                            <span class="spx-fx__datetime"><i class="fa-regular fa-calendar" aria-hidden="true"></i><span><b><?= $ts ? h(date('D, j M Y', $ts)) : '' ?></b><small><?= $ko !== '' ? 'KO ' . h($ko) : 'KO TBC' ?></small></span></span>
                                            <strong class="spx-fx__price"><?= h(gbp(member_sponsorship_package_price($pdo, $package, (int) $fixture['id']))) ?></strong>
                                        </div>
                                        <button class="btn spx-bigbtn" type="submit">Add to Basket</button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>

            </div>

            <?= member_sponsor_render_basket($cart, (float) $cartTotal, $csrfToken) ?>
        </div>
    <?php endif; ?>
</div>

<?= member_sponsor_render_float($cart, (float) $cartTotal) ?>

<script>
(function () {
  var tabs = document.querySelectorAll('[data-spx-tab]');
  var panels = document.querySelectorAll('[data-spx-panel]');
  if (!tabs.length) return;
  function show(id) {
    var found = false;
    panels.forEach(function (p) { var on = p.getAttribute('data-spx-panel') === id; p.classList.toggle('is-active', on); if (on) found = true; });
    if (!found) return false;
    tabs.forEach(function (t) { var on = t.getAttribute('data-spx-tab') === id; t.classList.toggle('is-active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    try { sessionStorage.setItem('spxTab', id); } catch (e) {}
    return true;
  }
  tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-spx-tab')); }); });
  var saved = null; try { saved = sessionStorage.getItem('spxTab'); } catch (e) {}
  if (saved) show(saved);
  document.documentElement.classList.add('spx-js');

  // ---- add / remove / clear without a page reload, with toast feedback ----
  var stack = document.createElement('div');
  stack.className = 'spx-toasts';
  stack.setAttribute('aria-live', 'polite');
  document.body.appendChild(stack);
  function toast(message, isError) {
    if (!message) return;
    var t = document.createElement('div');
    t.className = 'spx-toast' + (isError ? ' spx-toast--error' : '');
    t.setAttribute('role', isError ? 'alert' : 'status');
    var span = document.createElement('span'); span.textContent = message;
    var x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'Dismiss'); x.innerHTML = '&times;';
    t.appendChild(span); t.appendChild(x); stack.appendChild(t);
    function close() { t.classList.add('is-leaving'); setTimeout(function () { t.remove(); }, 300); }
    x.addEventListener('click', close);
    setTimeout(close, 10000);
  }
  document.addEventListener('submit', function (e) {
    var form = e.target;
    var action = form instanceof HTMLFormElement ? form.querySelector('input[name="cart_action"]') : null;
    if (!action || ['add', 'remove', 'clear'].indexOf(action.value) === -1) return;
    e.preventDefault();
    var btn = e.submitter || form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: new FormData(form) })
      .then(function (r) { return r.json(); })
      .then(function (p) {
        var basket = document.getElementById('basket');
        if (basket && p.basket_html) basket.outerHTML = p.basket_html;
        var oldFloat = document.querySelector('.spx-float');
        if (oldFloat) oldFloat.remove();
        if (p.float_html) document.body.insertAdjacentHTML('beforeend', p.float_html);
        toast(p.message, !p.ok);
      })
      .catch(function () { toast('Sorry, that could not be added. Please try again.', true); })
      .then(function () { if (btn) btn.disabled = false; });
  });
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
