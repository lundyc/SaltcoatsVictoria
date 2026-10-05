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
        $stmt = $pdo->prepare('SELECT opponent, match_date FROM match_fixtures WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $fixtureId]);
        $fixture = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fixture) {
            return $label . ' - vs ' . (string) $fixture['opponent'] . ' (' . date('d/m/Y', strtotime((string) $fixture['match_date'])) . ')';
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
        'amount' => (float) $package['amount'],
    ];
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
                $paymentMethod = (string) ($_POST['payment_method'] ?? 'online');
                $cart = $_SESSION['member_sponsorship_cart'];
                if (!$cart) {
                    throw new RuntimeException('Add at least one sponsorship to your basket first.');
                }
                if ($paymentMethod === 'online' && count($cart) > 1) {
                    throw new RuntimeException('Card checkout currently supports one sponsorship at a time. Use cash or bank transfer for multiple basket items.');
                }
                foreach ($cart as $item) {
                    $result = member_sponsorship_purchase(
                        $pdo,
                        $currentHolder,
                        (string) $item['package_code'],
                        !empty($item['player_id']) ? (int) $item['player_id'] : null,
                        !empty($item['fixture_id']) ? (int) $item['fixture_id'] : null,
                        (int) $currentSeason['id'],
                        $baseUrl . '?success=1',
                        $baseUrl . '?cancelled=1',
                        $paymentMethod
                    );
                    if ($paymentMethod === 'online') {
                        $_SESSION['member_sponsorship_cart'] = [];
                        header('Location: ' . $result['url']);
                        exit;
                    }
                }
                $_SESSION['member_sponsorship_cart'] = [];
                header('Location: ' . $baseUrl . '?success=1');
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

$playerOptions = $currentSeason ? member_sponsorship_player_options($pdo, (int) $currentSeason['id']) : [];
$matchOptions = $currentSeason ? member_sponsorship_match_options($pdo, (int) $currentSeason['id']) : [];
$boardOptions = member_sponsorship_board_options($pdo);
$cart = $_SESSION['member_sponsorship_cart'];
$cartTotal = array_sum(array_map(static fn(array $item): float => (float) ($item['amount'] ?? 0), $cart));
?>

<style>
    .sponsor-tabs {
        display:flex;
        align-items:stretch;
        gap:0;
        width:max-content;
        max-width:100%;
        margin:0 0 1rem;
        overflow-x:auto;
        padding:.15rem;
        background:#eceaed;
        border:1px solid rgba(75,8,24,.12);
        border-radius:0;
        box-shadow:0 12px 28px rgba(33,20,26,.08);
    }
    .sponsor-tab {
        position:relative;
        display:grid;
        place-items:center;
        align-content:center;
        min-width:132px;
        min-height:92px;
        padding:.8rem 1.25rem;
        color:#21141a;
        text-align:center;
        text-decoration:none;
        background:#f4f2f5;
        clip-path:polygon(12% 0,100% 0,88% 100%,0 100%);
        transition:background-color .15s ease,color .15s ease,transform .15s ease;
    }
    .sponsor-tab + .sponsor-tab { margin-left:-.6rem; }
    .sponsor-tab i {
        display:block;
        margin-bottom:.45rem;
        color:currentColor;
        font-size:1.85rem;
        line-height:1;
    }
    .sponsor-tab span {
        display:block;
        max-width:7.5rem;
        font-weight:800;
        line-height:1.15;
    }
    .sponsor-tab.is-active,
    .sponsor-tab:hover,
    .sponsor-tab:focus-visible {
        z-index:1;
        color:#fff;
        background:#4b0818;
    }
    .sponsor-tab:focus-visible {
        outline:3px solid rgba(224,180,42,.65);
        outline-offset:2px;
    }
    @media (max-width:575.98px) {
        .sponsor-tabs { width:100%; }
        .sponsor-tab { min-width:118px; min-height:84px; padding:.7rem 1rem; }
        .sponsor-tab i { font-size:1.55rem; }
        .sponsor-tab span { font-size:.86rem; }
    }
</style>

<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow">Sponsorship</div>
            <h1>Sponsorship Opportunities</h1>
            <p>Choose player, matchday and pitchside sponsorships, then check out from one basket.</p>
        </div>
    </section>

    <?php if (isset($_GET['success'])): ?><div class="alert alert-success">Thanks for your sponsorship. We'll be in touch.</div><?php endif; ?>
    <?php if (isset($_GET['cancelled'])): ?><div class="alert alert-warning">Payment was cancelled. No money was taken.</div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="alert alert-success"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

    <?php if (!$currentSeason): ?>
        <div class="member-card"><div class="member-card__body text-muted">No active season is configured.</div></div>
    <?php else: ?>
        <nav class="sponsor-tabs" aria-label="Sponsorship sections">
            <a class="sponsor-tab is-active" href="#pitchside-boards"><i class="fa-solid <?= h(member_sponsor_tab_icon('pitchside-boards')) ?>" aria-hidden="true"></i><span>Pitchside Boards</span></a>
            <?php foreach ($playerOptions as $option): $package = $option['package']; ?>
                <a class="sponsor-tab" href="#<?= h((string) $package['code']) ?>"><i class="fa-solid <?= h(member_sponsor_tab_icon((string) $package['code'])) ?>" aria-hidden="true"></i><span><?= h((string) $package['name']) ?></span></a>
            <?php endforeach; ?>
            <?php foreach ($matchOptions as $option): $package = $option['package']; ?>
                <a class="sponsor-tab" href="#<?= h((string) $package['code']) ?>"><i class="fa-solid <?= h(member_sponsor_tab_icon((string) $package['code'])) ?>" aria-hidden="true"></i><span><?= h((string) $package['name']) ?></span></a>
            <?php endforeach; ?>
        </nav>

        <div class="member-shop-layout">
            <div class="member-list">
                <section class="member-card" id="pitchside-boards">
                    <div class="member-card__header">
                        <h2>Pitchside Boards</h2>
                        <span class="member-badge">Campbell Park</span>
                    </div>
                    <div class="member-card__body member-list">
                        <?php foreach ($boardOptions as $package): ?>
                            <form method="post" class="member-product">
                                <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
                                <input type="hidden" name="cart_action" value="add">
                                <input type="hidden" name="package_code" value="<?= h((string) $package['code']) ?>">
                                <div>
                                    <h3><?= h((string) $package['name']) ?> <span class="text-muted fw-semibold"><?= h((string) $package['description']) ?></span></h3>
                                    <p>Season-long ground advertising from <?= gbp((float) $package['amount']) ?>.</p>
                                </div>
                                <button class="btn btn-brand" type="submit">Add to Basket</button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </section>

                <?php foreach ($playerOptions as $option): $package = $option['package']; ?>
                    <section class="member-card" id="<?= h((string) $package['code']) ?>">
                        <div class="member-card__header">
                            <h2><?= h((string) $package['name']) ?></h2>
                            <span class="member-badge"><?= gbp((float) $package['amount']) ?></span>
                        </div>
                        <div class="member-card__body">
                            <?php if (!$option['open_players']): ?>
                                <p class="text-muted mb-0">All players are currently sponsored for this package.</p>
                            <?php else: ?>
                                <div class="member-list">
                                    <?php foreach ($option['open_players'] as $player): ?>
                                        <form method="post" class="member-product">
                                            <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
                                            <input type="hidden" name="cart_action" value="add">
                                            <input type="hidden" name="package_code" value="<?= h((string) $package['code']) ?>">
                                            <input type="hidden" name="player_id" value="<?= (int) $player['id'] ?>">
                                            <div>
                                                <h3><?= h((string) $player['name']) ?></h3>
                                                <p><?= h((string) $package['description']) ?></p>
                                            </div>
                                            <button class="btn btn-brand" type="submit">Add to Basket</button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>

                <?php foreach ($matchOptions as $option): $package = $option['package']; ?>
                    <section class="member-card" id="<?= h((string) $package['code']) ?>">
                        <div class="member-card__header">
                            <h2><?= h((string) $package['name']) ?></h2>
                            <span class="member-badge"><?= gbp((float) $package['amount']) ?></span>
                        </div>
                        <div class="member-card__body">
                            <?php if (!$option['open_fixtures']): ?>
                                <p class="text-muted mb-0">No upcoming fixtures are open for this package.</p>
                            <?php else: ?>
                                <div class="member-list">
                                    <?php foreach ($option['open_fixtures'] as $fixture): ?>
                                        <form method="post" class="member-product">
                                            <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
                                            <input type="hidden" name="cart_action" value="add">
                                            <input type="hidden" name="package_code" value="<?= h((string) $package['code']) ?>">
                                            <input type="hidden" name="fixture_id" value="<?= (int) $fixture['id'] ?>">
                                            <div>
                                                <h3>vs <?= h((string) $fixture['opponent']) ?></h3>
                                                <p><?= h(member_format_date((string) $fixture['match_date'])) ?> · <?= h((string) $package['description']) ?></p>
                                            </div>
                                            <button class="btn btn-brand" type="submit">Add to Basket</button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <aside class="member-cart member-card">
                <div class="member-card__header">
                    <h2>Basket</h2>
                    <span class="member-badge"><?= count($cart) ?> item<?= count($cart) === 1 ? '' : 's' ?></span>
                </div>
                <div class="member-card__body">
                    <?php if (!$cart): ?>
                        <p class="text-muted">Your basket is empty.</p>
                    <?php else: ?>
                        <div class="member-list mb-3">
                            <?php foreach ($cart as $index => $item): ?>
                                <div class="member-row">
                                    <div>
                                        <div class="member-row__title"><?= h((string) $item['label']) ?></div>
                                        <div class="member-row__meta"><?= gbp((float) $item['amount']) ?></div>
                                    </div>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
                                        <input type="hidden" name="cart_action" value="remove">
                                        <input type="hidden" name="cart_index" value="<?= (int) $index ?>">
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Remove</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex justify-content-between align-items-center border-top pt-3 mb-3">
                            <strong>Total</strong>
                            <strong class="fs-5"><?= gbp((float) $cartTotal) ?></strong>
                        </div>
                        <form method="post" class="vstack gap-2">
                            <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
                            <input type="hidden" name="cart_action" value="checkout">
                            <label class="form-label fw-semibold mb-0" for="paymentMethod">Payment method</label>
                            <select class="form-select" id="paymentMethod" name="payment_method">
                                <option value="online">Card, online now</option>
                                <option value="cash">Cash, in person</option>
                                <option value="bank_transfer">Bank transfer</option>
                            </select>
                            <div class="form-text">Card checkout supports one basket item at a time. Cash and bank transfer can submit multiple items.</div>
                            <button class="btn btn-brand" type="submit">Checkout</button>
                        </form>
                        <form method="post" class="mt-2">
                            <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
                            <input type="hidden" name="cart_action" value="clear">
                            <button class="btn btn-link text-muted p-0" type="submit">Clear basket</button>
                        </form>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
