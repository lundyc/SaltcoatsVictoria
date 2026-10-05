<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/hidden_team.php';
require_once __DIR__ . '/../admin/lib/hidden_team_stripe.php';

/**
 * Picks a column count so every row has the same number of boxes — the
 * largest divisor of $count that's no bigger than its square root, so rows
 * and columns land as close to even as possible while keeping columns >=
 * rows (a wide board, not a tall one). E.g. 10 -> 5 columns (2 rows), 24 ->
 * 6 columns (4 rows). A prime count has no such divisor besides 1, so it
 * falls back to a single row.
 */
function hidden_team_grid_columns(int $count): int
{
    if ($count < 1) {
        return 1;
    }
    for ($rows = (int) floor(sqrt($count)); $rows >= 1; $rows--) {
        if ($count % $rows === 0) {
            return (int) ($count / $rows);
        }
    }

    return $count;
}

/**
 * Renders the team grid. Shared by the normal page load and the AJAX
 * add/remove/clear responses so both always render from the exact same
 * markup — no separate JS-side template to drift out of sync.
 */
function hidden_team_render_board(array $game, array $teams, array $cartIds, array $currentHolder): string
{
    $currentPersonId = (int) ($currentHolder['person_id'] ?? 0);
    $currentLegacyHolderId = (int) ($currentHolder['legacy_holder_id'] ?? $currentHolder['id'] ?? 0);
    ob_start();
    ?>
    <div class="ht-board" style="grid-template-columns:repeat(<?= hidden_team_grid_columns(count($teams)) ?>, minmax(0, 1fr));">
        <?php foreach ($teams as $t): ?>
            <?php
            $taken = (int) $t['is_taken'] === 1;
            $paid = (int) $t['paid'] === 1;
            $mine = ($currentPersonId > 0 && (int) ($t['person_id'] ?? 0) === $currentPersonId)
                || ($currentLegacyHolderId > 0 && (int) ($t['holder_id'] ?? 0) === $currentLegacyHolderId);
            $inCart = !$taken && in_array((int) $t['id'], $cartIds, true);
            $classes = 'ht-box ' . ($paid ? 'ht-box-paid' : ($taken ? 'ht-box-taken' : ($inCart ? 'ht-box-basket' : 'ht-box-open'))) . ($mine ? ' ht-box-mine' : '');
            ?>
            <?php if ($taken): ?>
                <div class="<?= h($classes) ?>">
                    <div class="ht-box-name"><?= h((string) $t['team_name']) ?></div>
                    <div class="ht-box-state"><?= $mine ? 'Yours!' : h((string) $t['supporter_name']) ?></div>
                </div>
            <?php elseif ($inCart): ?>
                <form method="post" data-ht-ajax="1"><input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>"><input type="hidden" name="cart_action" value="remove"><input type="hidden" name="team_id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="<?= h($classes) ?> ht-box-btn">
                        <div class="ht-box-name"><?= h((string) $t['team_name']) ?></div>
                        <div class="ht-box-state"><i class="fa-solid fa-check me-1" aria-hidden="true"></i>In basket — tap to remove</div>
                    </button>
                </form>
            <?php else: ?>
                <form method="post" data-ht-ajax="1"><input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>"><input type="hidden" name="cart_action" value="add"><input type="hidden" name="team_id" value="<?= (int) $t['id'] ?>">
                    <button type="submit" class="<?= h($classes) ?> ht-box-btn">
                        <div class="ht-box-name"><?= h((string) $t['team_name']) ?></div>
                        <div class="ht-box-state">Tap to add — <?= gbp((float) $game['cost_per_team']) ?></div>
                    </button>
                </form>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

/**
 * Renders the basket panel's body (item list, total, checkout/clear
 * buttons, or the empty state). Shared the same way as the board.
 */
function hidden_team_render_basket(array $game, array $cartTeams, float $cartTotal): string
{
    ob_start();
    ?>
    <?php if (!$cartTeams): ?>
        <p class="text-muted">Your basket is empty — tap teams on the board to add them.</p>
    <?php else: ?>
        <div class="ht-basket-list mb-3">
            <?php foreach ($cartTeams as $ct): ?>
                <div class="ht-basket-item">
                    <div class="ht-basket-item__name"><?= h((string) $ct['team_name']) ?></div>
                    <div class="ht-basket-item__price"><?= gbp((float) $game['cost_per_team']) ?></div>
                    <form method="post" data-ht-ajax="1" class="ht-basket-item__remove-form"><input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>"><input type="hidden" name="cart_action" value="remove"><input type="hidden" name="team_id" value="<?= (int) $ct['id'] ?>">
                        <button class="ht-basket-item__remove" type="submit" aria-label="Remove <?= h((string) $ct['team_name']) ?>" title="Remove"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="d-flex justify-content-between align-items-center border-top pt-3 mb-3">
            <strong>Total</strong>
            <strong class="fs-5"><?= gbp($cartTotal) ?></strong>
        </div>
        <form method="post" class="mb-2"><input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>"><input type="hidden" name="cart_action" value="checkout">
            <button class="btn btn-brand w-100" type="submit">Pay <?= gbp($cartTotal) ?> now</button>
        </form>
        <form method="post" data-ht-ajax="1"><input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>"><input type="hidden" name="cart_action" value="clear">
            <button class="btn btn-outline-secondary w-100" type="submit">Clear basket</button>
        </form>
    <?php endif; ?>
    <?php
    return (string) ob_get_clean();
}

$error = '';
$notice = '';
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk') . '/members/hidden_team.php';

if (!isset($_SESSION['hidden_team_cart']) || !is_array($_SESSION['hidden_team_cart'])) {
    $_SESSION['hidden_team_cart'] = [];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!member_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        $action = (string) ($_POST['cart_action'] ?? 'add');
        try {
            if ($action === 'add') {
                $teamId = (int) ($_POST['team_id'] ?? 0);
                $team = getHiddenTeamTeam($pdo, $teamId);
                if (!$team || (string) $team['game_status'] !== 'open') {
                    throw new RuntimeException('That team is not available.');
                }
                if ((int) $team['is_taken'] === 1) {
                    throw new RuntimeException('Sorry, that team has just been claimed by someone else.');
                }
                if (!in_array($teamId, $_SESSION['hidden_team_cart'], true)) {
                    $_SESSION['hidden_team_cart'][] = $teamId;
                }
                $notice = 'Added to your basket.';
            } elseif ($action === 'remove') {
                $teamId = (int) ($_POST['team_id'] ?? 0);
                $_SESSION['hidden_team_cart'] = array_values(array_diff($_SESSION['hidden_team_cart'], [$teamId]));
                $notice = 'Removed from your basket.';
            } elseif ($action === 'clear') {
                $_SESSION['hidden_team_cart'] = [];
                $notice = 'Your basket has been cleared.';
            } elseif ($action === 'checkout') {
                $cart = $_SESSION['hidden_team_cart'];
                if (!$cart) {
                    throw new RuntimeException('Add at least one team to your basket first.');
                }
                $result = hidden_team_checkout_start($pdo, $currentHolder, $cart, $baseUrl . '?success=1', $baseUrl . '?cancelled=1');
                $_SESSION['hidden_team_cart'] = [];
                header('Location: ' . $result['url']);
                exit;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$game = getHiddenTeamCurrentOpenGame($pdo);
$teams = $game ? getHiddenTeamTeams($pdo, (int) $game['id']) : [];

// Basket items still need to be valid (open, in this game) — drop anything
// that's been claimed by someone else since it was added, rather than
// letting a stale team_id reach checkout.
$cartIds = [];
$cartTeams = [];
if ($game) {
    $teamsById = [];
    foreach ($teams as $t) {
        $teamsById[(int) $t['id']] = $t;
    }
    foreach ($_SESSION['hidden_team_cart'] as $cid) {
        $cid = (int) $cid;
        if (isset($teamsById[$cid]) && (int) $teamsById[$cid]['is_taken'] === 0) {
            $cartIds[] = $cid;
            $cartTeams[] = $teamsById[$cid];
        }
    }
    $_SESSION['hidden_team_cart'] = $cartIds;
}
$cartTotal = $game ? count($cartTeams) * (float) $game['cost_per_team'] : 0.0;

// AJAX add/remove/clear: respond with JSON + freshly rendered fragments
// instead of the full page, so the basket updates live without a reload.
// Checkout is deliberately excluded — it needs a real page navigation to
// reach Stripe, so that form has no data-ht-ajax attribute and always
// falls through to a normal full-page POST below.
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
if ($isAjax && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string) ($_POST['cart_action'] ?? '') !== 'checkout') {
    // header.php (required at the top of this file) already buffered a
    // full HTML page shell (doctype, nav, etc.) via its own ob_start()
    // before control ever reached here — discard it so the response body
    // is clean JSON, not JSON glued onto a page fragment.
    ob_clean();
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => $error === '',
        'error' => $error,
        'notice' => $notice,
        'board_html' => $game ? hidden_team_render_board($game, $teams, $cartIds, $currentHolder) : '',
        'basket_html' => $game ? hidden_team_render_basket($game, $cartTeams, $cartTotal) : '',
        'basket_count' => count($cartTeams),
    ]);
    exit;
}

$previousWinners = [];
foreach (getHiddenTeamGames($pdo, ['status' => 'drawn']) as $drawnGame) {
    if ($drawnGame['winner_team_name']) {
        $previousWinners[] = $drawnGame;
    }
    if (count($previousWinners) >= 3) {
        break;
    }
}
$currentPersonId = member_auth_current_person_id() ?? (int) ($currentHolder['person_id'] ?? 0);
$currentLegacyHolderId = member_auth_current_legacy_holder_id() ?? (int) ($currentHolder['legacy_holder_id'] ?? $currentHolder['id'] ?? 0);

// Scoped to the game currently on screen, not the member's whole history —
// otherwise this section fills up with claims from an old, already-drawn
// game the moment a sellout auto-starts a new one, which reads as if those
// old teams belong to the new board being shown above.
$myClaims = [];
if ($currentPersonId > 0 && $game) {
    foreach (getHiddenTeamPersonClaims($pdo, $currentPersonId, $currentLegacyHolderId) as $claim) {
        if ((int) $claim['game_id'] === (int) $game['id']) {
            $myClaims[] = $claim;
        }
    }
}

// Build a real receipt from the Stripe session that was just paid, instead
// of trusting that "the current open game" (rendered below) is still the
// one the payment was for — a basket that sells out the board triggers an
// auto-draw and a new game before Stripe even redirects the browser back.
$receipt = null;
if (isset($_GET['success'])) {
    $sessionId = (string) ($_GET['session_id'] ?? '');
    if ($sessionId !== '') {
        $paidRows = getHiddenTeamPaymentsBySession($pdo, $sessionId);
        if ($paidRows) {
            $receipt = [
                'game_name' => (string) $paidRows[0]['game_name'],
                'items' => $paidRows,
                'total' => array_sum(array_map(static fn(array $r): float => (float) $r['amount'], $paidRows)),
                'has_conflict' => (bool) array_filter($paidRows, static fn(array $r): bool => (string) $r['status'] === 'conflict'),
            ];
        }
    }
}
?>
<style>
    .ht-layout { display:grid; gap:1rem; align-items:start; }
    @media (min-width:900px) { .ht-layout { grid-template-columns:minmax(0,1fr) 320px; } }
    .ht-board { display:grid; gap:.65rem; }
    .ht-box { display:flex; flex-direction:column; justify-content:center; gap:.35rem; width:100%; min-height:6rem; padding:.85rem; border-radius:.85rem; border:1px solid rgba(75,8,24,.12); text-align:center; background:#fff; }
    .ht-box-open { cursor:pointer; background:rgba(246,236,222,.55); transition:background-color .15s ease,border-color .15s ease; }
    .ht-box-open:hover, .ht-box-open:focus-visible { background:#fff; border-color:rgba(106,32,54,.35); }
    .ht-box-taken { background:rgba(224,180,42,.14); border-color:rgba(224,180,42,.4); }
    .ht-box-paid { background:rgba(57,191,180,.14); border-color:rgba(57,191,180,.5); }
    .ht-box-mine { outline:2px solid var(--brand-primary,#6a2036); outline-offset:-2px; }
    .ht-box-basket { background:rgba(106,32,54,.1); border-color:rgba(106,32,54,.45); }
    .ht-box-name { font-weight:800; }
    .ht-box-state { font-size:.78rem; color:rgba(31,26,29,.65); }
    .ht-box form { margin:0; width:100%; height:100%; }
    .ht-box button.ht-box-btn { all:unset; display:flex; flex-direction:column; justify-content:center; gap:.35rem; width:100%; height:100%; text-align:center; cursor:pointer; box-sizing:border-box; }
    #htFlash { position:fixed; top:1rem; left:50%; transform:translateX(-50%); z-index:1080; width:min(90vw, 420px); pointer-events:none; }
    #htFlash .alert { box-shadow:0 12px 30px rgba(33,20,26,.18); pointer-events:auto; opacity:0; transition:opacity .2s ease; }
    #htFlash .alert.ht-flash-show { opacity:1; }
    .ht-basket { position:sticky; top:1rem; }
    .ht-basket-list { max-height:22rem; overflow-y:auto; }
    .ht-basket-item { display:grid; grid-template-columns:1fr auto auto; align-items:center; column-gap:.75rem; padding:.55rem 0; border-bottom:1px solid var(--member-line,#eadfdf); }
    .ht-basket-item:last-child { border-bottom:0; }
    .ht-basket-item__name { font-weight:700; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ht-basket-item__price { color:var(--member-muted,#6f6470); font-weight:600; white-space:nowrap; }
    .ht-basket-item__remove-form { margin:0; }
    .ht-basket-item__remove { all:unset; cursor:pointer; width:2rem; height:2rem; display:flex; align-items:center; justify-content:center; border-radius:.5rem; color:#a3364a; }
    .ht-basket-item__remove:hover, .ht-basket-item__remove:focus-visible { background:rgba(163,54,74,.1); }
    .ht-stats { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.85rem; }
    .ht-stat { display:flex; align-items:center; gap:.85rem; padding:1rem 1.1rem; border-radius:1rem; }
    .ht-stat i { font-size:1.5rem; flex:0 0 auto; }
    .ht-stat-label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; opacity:.75; }
    .ht-stat-value { font-size:1.6rem; font-weight:800; line-height:1.2; }
    .ht-stat-cost { background:linear-gradient(135deg, rgba(106,32,54,.14), rgba(75,8,24,.05)); color:var(--brand-primary,#6a2036); }
    .ht-stat-prize { background:linear-gradient(135deg, rgba(224,180,42,.26), rgba(224,180,42,.08)); color:#7a5c0d; }
    @media (max-width:420px) { .ht-stats { grid-template-columns:1fr; } }
</style>

<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">Fundraiser</div>
            <h1>Hidden Team</h1>
            <p>Add as many hidden teams as you like to your basket, then pay for them all in one go.</p>
        </div>
    </section>

    <?php if (isset($_GET['success'])): ?>
        <?php if ($receipt): ?>
            <div class="alert alert-success">
                <strong>Payment received<?= $receipt['has_conflict'] ? '' : ' — your team(s) are claimed. Good luck!' ?></strong>
                <div class="mt-1">You paid <?= gbp($receipt['total']) ?> for <?= h(implode(', ', array_map(static fn(array $r): string => (string) $r['team_name'], $receipt['items']))) ?> in &quot;<?= h($receipt['game_name']) ?>&quot;.</div>
                <?php if ($receipt['has_conflict']): ?>
                    <div class="mt-1 text-danger"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>One or more of these had already been claimed by someone else the moment your payment landed. The club will be in touch to sort out a refund or move you to a different box.</div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-success">Payment received — confirming your team(s) now. If they don't show below in a few seconds, refresh the page.</div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (isset($_GET['cancelled'])): ?><div class="alert alert-warning">Payment was cancelled. No money was taken and your teams are still open.</div><?php endif; ?>
    <div id="htFlash">
        <?php if ($notice !== ''): ?><div class="alert alert-success ht-flash-show"><?= h($notice) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="alert alert-danger ht-flash-show"><?= h($error) ?></div><?php endif; ?>
    </div>

    <?php if (!$game): ?>
        <div class="member-card"><div class="member-card__body text-muted">There's no Hidden Team fundraiser running right now — check back soon.</div></div>
    <?php else: ?>
        <section class="member-card mb-3">
            <div class="member-card__header">
                <h2><?= h((string) $game['name']) ?></h2>
            </div>
            <div class="member-card__body">
                <div class="ht-stats">
                    <div class="ht-stat ht-stat-cost">
                        <i class="fa-solid fa-sterling-sign" aria-hidden="true"></i>
                        <div>
                            <div class="ht-stat-label">Cost per team</div>
                            <div class="ht-stat-value"><?= gbp((float) $game['cost_per_team']) ?></div>
                        </div>
                    </div>
                    <div class="ht-stat ht-stat-prize">
                        <i class="fa-solid fa-trophy" aria-hidden="true"></i>
                        <div>
                            <div class="ht-stat-label">Prize on offer</div>
                            <div class="ht-stat-value"><?= gbp($game['prize_amount']) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="ht-layout">
            <section class="member-card">
                <div class="member-card__body">
                    <div id="htBoard"><?= hidden_team_render_board($game, $teams, $cartIds, $currentHolder) ?></div>
                </div>
            </section>

            <aside class="member-cart member-card ht-basket">
                <div class="member-card__header">
                    <h2>Basket</h2>
                    <span class="member-badge" id="htBasketCount"><?= count($cartTeams) ?> item<?= count($cartTeams) === 1 ? '' : 's' ?></span>
                </div>
                <div class="member-card__body" id="htBasketBody">
                    <?= hidden_team_render_basket($game, $cartTeams, $cartTotal) ?>
                </div>
            </aside>
        </div>
    <?php endif; ?>

    <?php if ($myClaims): ?>
    <section class="member-card mt-3">
        <div class="member-card__header"><h2>Your teams</h2></div>
        <div class="member-card__body member-list">
            <?php foreach ($myClaims as $claim): ?>
                <div class="member-row">
                    <div>
                        <div class="member-row__title"><?= h((string) $claim['team_name']) ?> — <?= h((string) $claim['game_name']) ?></div>
                        <div class="member-row__meta">
                            <?= (int) $claim['paid'] === 1 ? gbp((float) $claim['cost_per_team']) . ' paid' : 'Payment not confirmed' ?>
                            <?php if ((int) $claim['winner_team_id'] === (int) $claim['id']): ?> · <strong class="text-success">Winner! 🏆</strong><?php endif; ?>
                        </div>
                    </div>
                    <span class="member-badge"><?= h(ucfirst((string) $claim['game_status'])) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($previousWinners): ?>
    <section class="member-card mt-3">
        <div class="member-card__header"><h2>Previous winners</h2></div>
        <div class="member-card__body member-list">
            <?php foreach ($previousWinners as $w): ?>
                <div class="member-row">
                    <div>
                        <div class="member-row__title"><?= h((string) $w['winner_team_name']) ?></div>
                        <div class="member-row__meta"><?= h((string) $w['winner_supporter_name']) ?> · <?= h((string) $w['name']) ?></div>
                    </div>
                    <span class="member-badge"><?= gbp($w['prize_amount']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>

<script>
(function () {
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    var flashTimer = null;

    function showFlash(kind, message) {
        var flash = document.getElementById('htFlash');
        if (!flash) {
            return;
        }
        if (flashTimer) {
            clearTimeout(flashTimer);
            flashTimer = null;
        }
        if (!message) {
            flash.innerHTML = '';
            return;
        }
        flash.innerHTML = '<div class="alert alert-' + kind + '">' + escapeHtml(message) + '</div>';
        var alertEl = flash.firstElementChild;
        requestAnimationFrame(function () {
            alertEl.classList.add('ht-flash-show');
        });
        flashTimer = setTimeout(function () {
            alertEl.classList.remove('ht-flash-show');
            setTimeout(function () {
                if (flash.firstElementChild === alertEl) {
                    flash.innerHTML = '';
                }
            }, 250);
        }, 3500);
    }

    // Fade out (and clear) any alert already on the page from a normal
    // (non-AJAX) submit, so it behaves the same as an AJAX-triggered one.
    var initialAlert = document.querySelector('#htFlash .alert');
    if (initialAlert) {
        flashTimer = setTimeout(function () {
            initialAlert.classList.remove('ht-flash-show');
            setTimeout(function () {
                var flash = document.getElementById('htFlash');
                if (flash && flash.firstElementChild === initialAlert) {
                    flash.innerHTML = '';
                }
            }, 250);
        }, 3500);
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ht-ajax')) {
            return;
        }
        e.preventDefault();

        var submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
        }

        fetch(window.location.pathname, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form)
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Request failed.');
            }
            return response.json();
        }).then(function (data) {
            var board = document.getElementById('htBoard');
            var basketBody = document.getElementById('htBasketBody');
            var count = document.getElementById('htBasketCount');

            if (board) {
                board.innerHTML = data.board_html;
            }
            if (basketBody) {
                basketBody.innerHTML = data.basket_html;
            }
            if (count) {
                count.textContent = data.basket_count + ' item' + (data.basket_count === 1 ? '' : 's');
            }
            if (data.notice) {
                showFlash('success', data.notice);
            } else if (data.error) {
                showFlash('danger', data.error);
            } else {
                showFlash('success', '');
            }
        }).catch(function () {
            // Network hiccup or unexpected response — fall back to a real
            // page submit rather than leaving the board looking stuck.
            form.removeAttribute('data-ht-ajax');
            form.submit();
        });
    });
}());
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
