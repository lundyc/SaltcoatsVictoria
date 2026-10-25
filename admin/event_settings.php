<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Events',
    'title' => 'Event Settings',
    'subtitle' => 'The booking fee added to paid orders, and where customer replies go.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/events.php';
require_once __DIR__ . '/lib/site_settings.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/lib/google_wallet.php';
ensureEventsSchema($pdo);

$canManage = hub_auth_has_capability('events_manage');
$errors = [];
$success = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please reload and try again.';
    } elseif (!$canManage) {
        $errors[] = 'You do not have permission to change event settings.';
    } else {
        $rate = trim((string) ($_POST['fee_rate_pct'] ?? ''));
        $fixed = trim((string) ($_POST['fee_fixed_pence'] ?? ''));
        $reply = trim((string) ($_POST['reply_to'] ?? ''));
        if (!is_numeric($rate) || (float) $rate < 0 || (float) $rate > 20) {
            $errors[] = 'The percentage must be a number from 0 to 20.';
        }
        if (!is_numeric($fixed) || (float) $fixed < 0 || (float) $fixed > 200) {
            $errors[] = 'The fixed amount must be a number of pence from 0 to 200.';
        }
        if ($reply !== '' && !filter_var($reply, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The reply-to address is not a valid email address.';
        }
        $walletIssuer = trim((string) ($_POST['wallet_issuer_id'] ?? ''));
        $walletEmail = trim((string) ($_POST['wallet_service_email'] ?? ''));
        $walletKey = trim((string) ($_POST['wallet_key_path'] ?? ''));
        if ($walletEmail !== '' && !filter_var($walletEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'The Wallet service account email is not valid.';
        }
        if ($walletKey !== '' && (!str_starts_with($walletKey, '/') || str_contains($walletKey, '..') || str_starts_with(realpath($walletKey) ?: $walletKey, dirname(__DIR__)))) {
            $errors[] = 'The Wallet key must be an absolute path to a file outside the website folder.';
        }
        if (!$errors) {
            site_settings_save($pdo, ['wallet_issuer_id' => $walletIssuer, 'wallet_service_email' => $walletEmail, 'wallet_key_path' => $walletKey]);
            $before = [site_setting($pdo, 'events_fee_rate_pct', '1.5'), site_setting($pdo, 'events_fee_fixed_pence', '20'), site_setting($pdo, 'events_reply_to', '')];
            site_settings_save($pdo, ['events_fee_rate_pct' => (string) (float) $rate, 'events_fee_fixed_pence' => (string) (float) $fixed, 'events_reply_to' => $reply]);
            auditLog($pdo, 'event_settings_saved', 'Event settings changed. Fee ' . $before[0] . '% + ' . $before[1] . 'p, reply-to "' . $before[2] . '" -> fee ' . (float) $rate . '% + ' . (float) $fixed . 'p, reply-to "' . $reply . '"');
            $success = 'Settings saved. New orders use them straight away.';
        }
    }
}

$rate = site_setting($pdo, 'events_fee_rate_pct', '1.5');
$fixed = site_setting($pdo, 'events_fee_fixed_pence', '20');
$reply = site_setting($pdo, 'events_reply_to', '');
$wallet = wallet_config($pdo);
$examples = [5.0, 10.0, 25.0, 60.0];
?>

<?php hub_render_breadcrumbs([['label' => 'Events', 'href' => 'events.php'], ['label' => 'Settings']]); ?>
<?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="hub-panel" style="padding:1.15rem 1.25rem">
            <h3 class="h5 mb-1">Booking fee</h3>
            <p class="text-muted small mb-3">Added once to each paid order (not per ticket) so the club receives the full ticket price after Stripe's fee. Free orders pay no fee. Check the current rates in your Stripe dashboard.</p>
            <form method="post">
                <?= csrf_field() ?>
                <fieldset<?= $canManage ? '' : ' disabled' ?>>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="feeRate">Percentage (%)</label><input class="form-control" id="feeRate" type="number" step="0.01" min="0" max="20" name="fee_rate_pct" value="<?= h($rate) ?>" required></div>
                        <div class="col-md-4"><label class="form-label" for="feeFixed">Fixed amount (pence)</label><input class="form-control" id="feeFixed" type="number" step="0.01" min="0" max="200" name="fee_fixed_pence" value="<?= h($fixed) ?>" required></div>
                        <div class="col-12"><label class="form-label" for="replyTo">Reply-to / contact address</label><input class="form-control" id="replyTo" type="email" name="reply_to" value="<?= h($reply) ?>" placeholder="e.g. tickets@yourclub.co.uk"><div class="form-text">Customers see this in ticket emails and on event pages for problems and queries. An event can set its own address instead. Leave blank to show no contact line.</div></div>
                    </div>
                </fieldset>
                <?php if ($canManage): ?><button class="btn btn-brand mt-3" type="submit">Save settings</button><?php endif; ?>
            </form>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="hub-panel" style="padding:1.15rem 1.25rem">
            <h3 class="h5 mb-1">Google Wallet <?= wallet_is_configured($pdo) ? '<span class="badge bg-success">on</span>' : '<span class="badge bg-secondary">off</span>' ?></h3>
            <p class="text-muted small mb-3">Adds an "Add to Google Wallet" button on the order page. Needs a Google Wallet issuer account and a service account; save its private key file on the server outside the website folder and enter the path here. Saved with the settings above. Passes are a snapshot: later name changes are not pushed to phones.</p>
            <fieldset<?= $canManage ? '' : ' disabled' ?>>
                <form method="post" id="walletForm"><?= csrf_field() ?>
                    <input type="hidden" name="fee_rate_pct" value="<?= h($rate) ?>"><input type="hidden" name="fee_fixed_pence" value="<?= h($fixed) ?>"><input type="hidden" name="reply_to" value="<?= h($reply) ?>">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="wIssuer">Issuer ID</label><input class="form-control" id="wIssuer" name="wallet_issuer_id" value="<?= h($wallet['issuer_id']) ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="wEmail">Service account email</label><input class="form-control" id="wEmail" type="email" name="wallet_service_email" value="<?= h($wallet['service_email']) ?>"></div>
                        <div class="col-12"><label class="form-label" for="wKey">Private key file path</label><input class="form-control" id="wKey" name="wallet_key_path" value="<?= h($wallet['key_path']) ?>" placeholder="/var/www/vhosts/.../private/wallet-key.pem"></div>
                    </div>
                    <?php if ($canManage): ?><button class="btn btn-brand mt-3" type="submit">Save Wallet settings</button><?php endif; ?>
                </form>
            </fieldset>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="hub-panel" style="padding:1.15rem 1.25rem">
            <h3 class="h5 mb-1">What customers pay</h3>
            <p class="text-muted small mb-3">With the saved settings. The fee is worked out as (order × rate + fixed) ÷ (1 − rate), rounded up to the penny.</p>
            <table class="table table-sm mb-0">
                <thead><tr><th>Tickets total</th><th class="text-end">Booking fee</th><th class="text-end">Customer pays</th></tr></thead>
                <tbody>
                    <?php foreach ($examples as $total): $fee = events_booking_fee($pdo, $total); ?>
                        <tr><td><?= h(gbp($total)) ?></td><td class="text-end"><?= h(gbp($fee)) ?></td><td class="text-end"><strong><?= h(gbp($total + $fee)) ?></strong></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
