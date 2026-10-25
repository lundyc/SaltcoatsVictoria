<?php
/** Route: /matchday-sponsorship — Matchday and Match Ball sponsorship: pick games and send a request; the club approves before anyone pays. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/match_sponsorship_shop.php';

$pdo = db();
match_sponsorship_ensure_schema($pdo);
match_sponsorship_basket_start();

$ground = club('ground_name', 'Campbell Park');
$clubName = club('club_name', 'Saltcoats Victoria FC');
$seasonId = pub_current_season_id();

$enquire = static function (string $subject, string $message): string {
    return url('enquire/commercial') . '?' . http_build_query(['subject' => $subject, 'message' => $message]);
};
$dealUrl = $enquire('Multi-game sponsorship deal', "Hello, I'd like to sponsor several games and ask about a deal.\n\nGames I'm interested in:\nBusiness name:\nBest contact number:");

/** The basket panel (also returned as HTML by the add/remove calls). */
function msp_render_basket(array $summary, string $csrf, string $dealUrl): string
{
    ob_start();
    ?>
    <aside class="spx-basket mds-basket" id="basket" aria-live="polite" aria-label="Your sponsorship request">
      <div class="spx-basket__head"><h2>Your request</h2><span><?= count($summary['items']) ?> game<?= count($summary['items']) === 1 ? '' : 's' ?></span></div>
      <div class="spx-basket__body">
        <?php if (!$summary['items']): ?>
          <p class="spx-basket__empty">Nothing here yet. Choose a game and add it to your request.</p>
        <?php else: ?>
          <ul class="spx-basket__items">
            <?php foreach ($summary['items'] as $it): ?>
              <li>
                <span><strong><?= e((string) $it['label']) ?></strong></span>
                <span class="spx-basket__price"><?= e(gbp((float) $it['price'])) ?></span>
                <form method="post" data-basket-form><?= $csrf ?>
                  <input type="hidden" name="action" value="remove"><input type="hidden" name="item_id" value="<?= e((string) $it['id']) ?>">
                  <button type="submit" class="bline__remove" aria-label="Remove from request">Remove</button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
          <div class="spx-basket__total"><span>Guide total</span><strong><?= e(gbp((float) $summary['total'])) ?></strong></div>
          <a class="btn btn--lg" style="width:100%;justify-content:center" href="<?= e(url('matchday-sponsorship/checkout')) ?>">Send my request &rarr;</a>
          <p class="mds-deal-note">No payment now. The club checks every request and will email you within 3 working days.</p>
          <?php if (count($summary['items']) >= 2): ?>
            <p class="mds-deal-note">Booking several games? <a href="<?= e($dealUrl) ?>">Ask about a multi-game deal</a> as part of your request.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </aside>
    <?php
    return (string) ob_get_clean();
}

$notice = '';
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $ajax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if (!csrf_check()) {
        $error = 'Your session expired. Please reload the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'add') {
            $fixtureId = (int) ($_POST['fixture_id'] ?? 0);
            $code = (string) ($_POST['package_code'] ?? '');
            $ok = false;
            foreach (member_sponsorship_match_options($pdo, $seasonId) as $opt) {
                if ((string) $opt['package']['code'] === $code) {
                    foreach ($opt['open_fixtures'] as $f) {
                        if ((int) $f['id'] === $fixtureId) {
                            $ok = true;
                        }
                    }
                }
            }
            if (!$ok) {
                $error = 'Sorry, that game is no longer available.';
            } elseif (isset(match_sponsorship_basket_key_set()[$fixtureId . ':' . $code])) {
                $error = 'That game is already in your request.';
            } else {
                match_sponsorship_basket_add($fixtureId, $code);
                $notice = 'Added to your request.';
            }
        } elseif ($action === 'remove') {
            match_sponsorship_basket_remove((string) ($_POST['item_id'] ?? ''));
            $notice = 'Removed from your request.';
        }
    }
    if ($ajax) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => $error === '', 'message' => $error !== '' ? $error : $notice,
            'basket_html' => msp_render_basket(match_sponsorship_basket_summary($pdo, $seasonId), csrf_field(), $dealUrl),
        ], JSON_THROW_ON_ERROR);
        exit;
    }
    if ($error === '') {
        redirect(url('matchday-sponsorship') . '#basket');
    }
}

$options = [];
try {
    foreach (member_sponsorship_match_options($pdo, $seasonId) as $opt) {
        $options[(string) $opt['package']['code']] = $opt;
    }
} catch (Throwable) {
    $options = [];
}
$summary = match_sponsorship_basket_summary($pdo, $seasonId);
$inBasket = match_sponsorship_basket_key_set();
$csrf = csrf_field();

set_meta([
    'title' => 'Matchday and match ball sponsorship',
    'description' => 'Sponsor a ' . $clubName . ' game or the match ball. Choose your games and send a request. No payment is taken until the club has approved it.',
]);
seo_breadcrumbs([['Commercial', url('commercial')], ['Matchday sponsorship', url('matchday-sponsorship')]]);

$sections = [
    'match_day' => ['title' => 'Matchday sponsorship', 'blurb' => 'Be the sponsor of the whole matchday.'],
    'match_ball' => ['title' => 'Match ball sponsorship', 'blurb' => 'Sponsor the match ball for a game.'],
];
$priceLabel = static function (array $pk): string {
    $home = (float) $pk['amount'];
    $away = ($pk['away_amount'] ?? null) !== null && $pk['away_amount'] !== '' ? (float) $pk['away_amount'] : null;
    return $away !== null && $away !== $home ? gbp($home) . ' home · ' . gbp($away) . ' away' : gbp($home) . ' per game';
};
$available = array_filter($sections, static fn (string $code): bool => isset($options[$code]), ARRAY_FILTER_USE_KEY);
$firstCode = array_key_first($available);
?>
<?php partial('page_hero', [
    'eyebrow' => 'Commercial',
    'title'   => 'Matchday & match ball sponsorship',
    'sub'     => 'Sponsor a game or the match ball and put your business at the heart of the day.',
    'back'    => ['href' => url('commercial'), 'label' => 'Commercial'],
]); ?>

<div class="page">
  <div class="container">
    <?php if ($error !== ''): ?><div class="notice notice--err"><p><?= e($error) ?></p></div><?php endif; ?>
    <?php if (isset($_GET['cancelled'])): ?><div class="notice notice--warn"><p>Payment was cancelled and nothing has been charged. You can use your payment link again whenever you are ready.</p></div><?php endif; ?>

    <section class="mds-banner">
      <div class="mds-banner__text">
        <h2>Sponsor a game</h2>
        <p>Pick a fixture and be the face of the day: sponsor the whole matchday, or the match ball. Prices shown are a guide and depend on the package and whether the game is at home or away. Choose your games and send us a request. There is no payment now: the club checks every request, replies within 3 working days, and only then will you be asked to pay.</p>
        <a class="btn btn--lg btn--gold" href="#choose">Choose your games</a>
      </div>
    </section>

    <section class="mds-deal">
      <div>
        <h3>Want to sponsor several games?</h3>
        <p>Booking multiple matches? Get in touch and we&rsquo;ll put together a deal for you.</p>
      </div>
      <a class="btn btn--lg" href="<?= e($dealUrl) ?>">Ask about a multi-game deal</a>
    </section>

    <div class="mds-layout" id="choose">
      <div class="mds-main">
        <div class="mds-tabs" role="tablist" aria-label="Sponsorship type">
          <?php foreach ($available as $code => $info): ?>
            <button type="button" role="tab" class="mds-tab<?= $code === $firstCode ? ' is-active' : '' ?>" data-mds-tab="<?= e($code) ?>" aria-selected="<?= $code === $firstCode ? 'true' : 'false' ?>">
              <strong><?= $code === 'match_day' ? 'Matchday' : 'Match Ball' ?></strong>
            </button>
          <?php endforeach; ?>
        </div>

        <?php foreach ($available as $code => $info):
            $pk = $options[$code]['package']; $fixtures = $options[$code]['open_fixtures']; ?>
          <section class="mds-panel<?= $code === $firstCode ? ' is-active' : '' ?>" id="<?= e($code) ?>" data-mds-panel="<?= e($code) ?>" role="tabpanel">
            <div class="pb-heading mds-panel__head">
              <h2><?= e($info['title']) ?></h2>
              <p><?= e($info['blurb']) ?> <strong>Guide price: <?= e($priceLabel($pk)) ?></strong> &middot; <span class="mds-count" style="margin:0"><?= count($fixtures) ?> game<?= count($fixtures) === 1 ? '' : 's' ?> available</span></p>
            </div>
            <?php if (!$fixtures): ?>
              <div class="emptystate"><p>No games are currently available for this package. Please check back soon.</p></div>
            <?php else: ?>
              <div class="mds-grid">
                <?php foreach ($fixtures as $f):
                    $ts = strtotime((string) $f['match_date']);
                    $isHome = (int) $f['is_home'] === 1;
                    $price = member_sponsorship_package_price($pdo, $pk, (int) $f['id']);
                    $homeName = $isHome ? 'Saltcoats Victoria' : (string) $f['opponent'];
                    $awayName = $isHome ? (string) $f['opponent'] : 'Saltcoats Victoria';
                    $oppCrest = pub_opponent_crest($f['opponent_logo'] ?? '', (string) $f['opponent']);
                    $homeCrest = $isHome ? club_crest() : $oppCrest;
                    $awayCrest = $isHome ? $oppCrest : club_crest();
                    $ko = !empty($f['kickoff_time']) ? format_time((string) $f['kickoff_time']) : '';
                    $added = isset($inBasket[(int) $f['id'] . ':' . $code]);
                ?>
                  <article class="mds-card">
                    <div class="mds-card__head">
                      <span class="mds-ha mds-ha--<?= $isHome ? 'home' : 'away' ?>"><?= $isHome ? 'Home' : 'Away' ?></span>
                      <span class="mds-card__comp"><?= e((string) ($f['competition'] ?? '')) ?></span>
                    </div>
                    <div class="mds-card__teams">
                      <div><?php if ($homeCrest !== ''): ?><img src="<?= e($homeCrest) ?>" alt="" loading="lazy"><?php endif; ?><strong><?= e($homeName) ?></strong></div>
                      <span>v</span>
                      <div><?php if ($awayCrest !== ''): ?><img src="<?= e($awayCrest) ?>" alt="" loading="lazy"><?php endif; ?><strong><?= e($awayName) ?></strong></div>
                    </div>
                    <div class="mds-card__when">
                      <span><b><?= e(date('D, j M Y', (int) $ts)) ?></b><small><?= $ko !== '' ? 'KO ' . e($ko) : 'KO TBC' ?></small></span>
                      <strong class="mds-card__price">From <?= e(gbp($price)) ?></strong>
                    </div>
                    <form method="post" data-basket-form class="mds-card__form">
                      <?= $csrf ?>
                      <input type="hidden" name="action" value="add">
                      <input type="hidden" name="fixture_id" value="<?= (int) $f['id'] ?>">
                      <input type="hidden" name="package_code" value="<?= e($code) ?>">
                      <button type="submit" class="btn btn--lg" style="width:100%;justify-content:center">Add to my request</button>
                    </form>
                  </article>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </section>
        <?php endforeach; ?>
      </div>

      <?= msp_render_basket($summary, $csrf, $dealUrl) ?>
    </div>
  </div>
</div>

<script>
(function () {
  var tabs = document.querySelectorAll('[data-mds-tab]');
  var panels = document.querySelectorAll('[data-mds-panel]');
  function show(id, push) {
    var found = false;
    panels.forEach(function (p) { var on = p.getAttribute('data-mds-panel') === id; p.classList.toggle('is-active', on); if (on) found = true; });
    if (!found) return;
    tabs.forEach(function (t) { var on = t.getAttribute('data-mds-tab') === id; t.classList.toggle('is-active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
    if (push && history.replaceState) { history.replaceState(null, '', '#' + id); }
  }
  tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-mds-tab'), true); }); });
  var h = (location.hash || '').replace('#', '');
  if (h && h !== 'basket' && h !== 'choose') { show(h, false); }
  document.documentElement.classList.add('mds-js');

  // add / remove without a page reload, with a toast
  var stack = document.createElement('div');
  stack.className = 'spx-toasts'; stack.setAttribute('aria-live', 'polite'); document.body.appendChild(stack);
  function toast(message, isError) {
    if (!message) return;
    var t = document.createElement('div');
    t.className = 'spx-toast' + (isError ? ' spx-toast--error' : ''); t.setAttribute('role', isError ? 'alert' : 'status');
    var span = document.createElement('span'); span.textContent = message;
    var x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'Dismiss'); x.innerHTML = '&times;';
    t.appendChild(span); t.appendChild(x); stack.appendChild(t);
    function close() { t.classList.add('is-leaving'); setTimeout(function () { t.remove(); }, 300); }
    x.addEventListener('click', close); setTimeout(close, 10000);
  }
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-basket-form]')) return;
    e.preventDefault();
    var btn = e.submitter || form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    fetch(window.location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }, body: new FormData(form) })
      .then(function (r) { return r.json(); })
      .then(function (p) {
        var basket = document.getElementById('basket');
        if (basket && p.basket_html) basket.outerHTML = p.basket_html;
        toast(p.message, !p.ok);
      })
      .catch(function () { toast('Sorry, that could not be updated. Please try again.', true); })
      .then(function () { if (btn) btn.disabled = false; });
  });
})();
</script>
