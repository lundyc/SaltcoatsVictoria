<?php

declare(strict_types=1);

// Club Shop — settings and pre-order window. Hub admin only.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/lib/notification_recipients.php';
require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('shop');
require_once __DIR__ . '/lib/audit.php';

shop_ensure_schema($pdo);
$isAdmin = hub_auth_is_admin();

const SHOP_SETTING_KEYS = [
    'shop_enabled', 'shop_name', 'shop_intro',
    'collection_point', 'collection_address', 'collection_map_url', 'collection_details', 'delivery_note',
    'delivery_enabled', 'delivery_fee',
    'lead_time', 'preorder_close_at', 'preorder_intro', 'terms',
    'low_stock_alerts_enabled', 'low_stock_threshold',
    'schedule_enabled', 'schedule_days', 'schedule_lead_days', 'schedule_window_days', 'schedule_cutoff_time', 'schedule_max_per_day',
];

// One-off migration: fold the old single "Order notification email" setting
// into the shared recipients list, then stop writing/reading it as a field.
$legacyContactEmail = trim((string) shop_setting($pdo, 'contact_email', ''));
if ($legacyContactEmail !== '' && filter_var($legacyContactEmail, FILTER_VALIDATE_EMAIL)) {
    $existingManualEmails = stripe_notification_manual_emails($pdo);
    if (!in_array(strtolower($legacyContactEmail), array_map('strtolower', $existingManualEmails), true)) {
        stripe_notification_save_lists($pdo, stripe_notification_account_ids($pdo), array_merge($existingManualEmails, [$legacyContactEmail]), null);
    }
    shop_save_setting($pdo, 'contact_email', '');
}

if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        header('Location: /admin/shop_settings.php?e=' . rawurlencode('Session expired — try again.'));
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_settings') {
            $postedTab = (string) ($_POST['settings_tab'] ?? '');
            foreach (SHOP_SETTING_KEYS as $key) {
                if ($key === 'shop_enabled' || $key === 'delivery_enabled' || $key === 'low_stock_alerts_enabled' || $key === 'schedule_enabled') {
                    if (!array_key_exists($key . '_present', $_POST)) {
                        continue;
                    }
                    shop_save_setting($pdo, $key, empty($_POST[$key]) ? '0' : '1');
                    continue;
                }
                if ($key === 'schedule_days') {
                    if (!array_key_exists('schedule_days_present', $_POST)) {
                        continue;
                    }
                    $days = array_filter(array_map('intval', (array) ($_POST['schedule_days'] ?? [])), static fn($d) => $d >= 1 && $d <= 7);
                    shop_save_setting($pdo, $key, $days ? implode(',', array_unique($days)) : '1,2,3,4,5,6,7');
                    continue;
                }
                if (!array_key_exists($key, $_POST)) {
                    continue;
                }
                $value = (string) $_POST[$key];
                if ($key === 'preorder_close_at' && trim($value) !== '') {
                    $value = date('Y-m-d H:i:s', strtotime($value) ?: time());
                }
                if ($key === 'delivery_fee') {
                    $value = number_format(max(0, round((float) $value, 2)), 2, '.', '');
                }
                shop_save_setting($pdo, $key, trim($value));
            }
            auditLog($pdo, 'shop_settings_updated', 'Club Shop settings updated');
            $tabParam = $postedTab !== '' ? 'tab=' . rawurlencode($postedTab) . '&' : '';
            header('Location: /admin/shop_settings.php?' . $tabParam . 'm=' . rawurlencode('Settings saved.'));
            exit;
        } elseif ($action === 'save_notifications') {
            $currentUserId = (int) (hub_auth_current_user()['id'] ?? 0) ?: null;
            $result = notification_recipients_handle_action($pdo, $_POST, $currentUserId);
            if (!$result['ok']) {
                header('Location: /admin/shop_settings.php?tab=notifications&e=' . rawurlencode($result['message']));
                exit;
            }
            auditLog($pdo, 'shop_settings_updated', 'Updated shop order notification recipients');
            header('Location: /admin/shop_settings.php?tab=notifications&m=' . rawurlencode($result['message']));
            exit;
        }
        header('Location: /admin/shop_settings.php');
        exit;
    } catch (Throwable $e) {
        header('Location: /admin/shop_settings.php?e=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$pageHero = [
    'eyebrow' => 'Shop',
    'title' => 'Shop settings',
    'subtitle' => 'Storefront copy, collection details and the pre-order window.',
    'actions' => [
        ['label' => 'Discount codes', 'href' => '/admin/shop_discount_codes.php', 'class' => 'btn btn-outline-light btn-sm'],
        ['label' => 'Back to shop', 'href' => '/shop_overview.php', 'class' => 'btn btn-outline-light btn-sm'],
    ],
];
require_once __DIR__ . '/header.php';


$s = shop_get_settings($pdo);
$closeValue = '';
if (!empty($s['preorder_close_at'])) {
    $closeValue = (new DateTimeImmutable((string) $s['preorder_close_at']))->format('Y-m-d\TH:i');
}

$notificationData = notification_recipients_data($pdo);
$notificationRows = $notificationData['rows'];
$notificationAccountOptions = $notificationData['accountOptions'];
$notificationAccountIdSet = $notificationData['accountIdSet'];
$notificationCsrfField = csrf_field();
$notificationHiddenFields = '<input type="hidden" name="action" value="save_notifications">';

$validTabs = ['storefront', 'collection', 'delivery', 'scheduling', 'preorder', 'terms', 'inventory', 'notifications'];
$activeTab = in_array((string) ($_GET['tab'] ?? ''), $validTabs, true) ? (string) $_GET['tab'] : 'storefront';
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Settings</span></nav>

    <?php if (isset($_GET['m'])): ?><div class="alert alert-success"><?= h((string) $_GET['m']) ?></div><?php endif; ?>
    <?php if (isset($_GET['e'])): ?><div class="alert alert-danger"><?= h((string) $_GET['e']) ?></div><?php endif; ?>

    <ul class="nav nav-tabs reports-tabs flex-nowrap mb-3" id="shopSettingsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'storefront' ? ' active' : '' ?>" id="tab-storefront-btn" data-bs-toggle="tab" data-bs-target="#tab-storefront" type="button" role="tab" aria-controls="tab-storefront" aria-selected="<?= $activeTab === 'storefront' ? 'true' : 'false' ?>">Storefront</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'collection' ? ' active' : '' ?>" id="tab-collection-btn" data-bs-toggle="tab" data-bs-target="#tab-collection" type="button" role="tab" aria-controls="tab-collection" aria-selected="<?= $activeTab === 'collection' ? 'true' : 'false' ?>">Collection</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'delivery' ? ' active' : '' ?>" id="tab-delivery-btn" data-bs-toggle="tab" data-bs-target="#tab-delivery" type="button" role="tab" aria-controls="tab-delivery" aria-selected="<?= $activeTab === 'delivery' ? 'true' : 'false' ?>">Delivery</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'scheduling' ? ' active' : '' ?>" id="tab-scheduling-btn" data-bs-toggle="tab" data-bs-target="#tab-scheduling" type="button" role="tab" aria-controls="tab-scheduling" aria-selected="<?= $activeTab === 'scheduling' ? 'true' : 'false' ?>">Scheduling</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'preorder' ? ' active' : '' ?>" id="tab-preorder-btn" data-bs-toggle="tab" data-bs-target="#tab-preorder" type="button" role="tab" aria-controls="tab-preorder" aria-selected="<?= $activeTab === 'preorder' ? 'true' : 'false' ?>">Pre-order window</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'terms' ? ' active' : '' ?>" id="tab-terms-btn" data-bs-toggle="tab" data-bs-target="#tab-terms" type="button" role="tab" aria-controls="tab-terms" aria-selected="<?= $activeTab === 'terms' ? 'true' : 'false' ?>">Checkout terms</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'inventory' ? ' active' : '' ?>" id="tab-inventory-btn" data-bs-toggle="tab" data-bs-target="#tab-inventory" type="button" role="tab" aria-controls="tab-inventory" aria-selected="<?= $activeTab === 'inventory' ? 'true' : 'false' ?>">Inventory</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'notifications' ? ' active' : '' ?>" id="tab-notifications-btn" data-bs-toggle="tab" data-bs-target="#tab-notifications" type="button" role="tab" aria-controls="tab-notifications" aria-selected="<?= $activeTab === 'notifications' ? 'true' : 'false' ?>">Order notifications</button>
        </li>
    </ul>

    <div class="tab-content" id="shopSettingsTabsContent">
        <div class="tab-pane fade<?= $activeTab === 'storefront' ? ' show active' : '' ?>" id="tab-storefront" role="tabpanel" aria-labelledby="tab-storefront-btn">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="settings_tab" value="storefront">
                <input type="hidden" name="shop_enabled_present" value="1">
                <section class="card hub-panel p-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="shop_enabled" name="shop_enabled" value="1" <?= ($s['shop_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="shop_enabled">Storefront open to customers</label>
                    </div>
                    <div class="mb-2"><label class="form-label">Shop name</label>
                        <input class="form-control" name="shop_name" value="<?= h((string) ($s['shop_name'] ?? 'Club Shop')) ?>"></div>
                    <div class="mb-0"><label class="form-label">Intro paragraph</label>
                        <textarea class="form-control" name="shop_intro" rows="2"><?= h((string) ($s['shop_intro'] ?? '')) ?></textarea></div>
                </section>
                <div class="mt-3"><button class="btn btn-dark" type="submit">Save storefront</button></div>
            </form>
        </div>

        <div class="tab-pane fade<?= $activeTab === 'collection' ? ' show active' : '' ?>" id="tab-collection" role="tabpanel" aria-labelledby="tab-collection-btn">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="settings_tab" value="collection">
                <section class="card hub-panel p-3">
                    <div class="mb-2"><label class="form-label">Collection point</label>
                        <input class="form-control" name="collection_point" value="<?= h((string) ($s['collection_point'] ?? 'Campbell Park')) ?>">
                        <div class="form-text">Short name shown throughout the shop, e.g. "Campbell Park".</div></div>
                    <div class="mb-2"><label class="form-label">Address</label>
                        <textarea class="form-control" name="collection_address" rows="2" placeholder="Campbell Park, Meadow Road, Saltcoats, KA21 5AT"><?= h((string) ($s['collection_address'] ?? '')) ?></textarea>
                        <div class="form-text">Full postal address, shown on the order confirmation and at checkout.</div></div>
                    <div class="mb-2"><label class="form-label">Google Maps link</label>
                        <input class="form-control" type="url" name="collection_map_url" value="<?= h((string) ($s['collection_map_url'] ?? '')) ?>" placeholder="https://maps.app.goo.gl/...">
                        <div class="form-text">Paste a share link from Google Maps (search the address, tap Share, copy link). Shown as a "Get directions" button — leave blank to hide it.</div></div>
                    <div class="mb-0"><label class="form-label">Collection details</label>
                        <textarea class="form-control" name="collection_details" rows="3" placeholder="e.g. opening times, where to park, who to ask for on arrival"><?= h((string) ($s['collection_details'] ?? '')) ?></textarea>
                        <div class="form-text">Anything else customers need to know — parking, opening hours, access notes. Shown on the order confirmation page.</div></div>
                </section>
                <div class="mt-3"><button class="btn btn-dark" type="submit">Save collection</button></div>
            </form>
        </div>

        <div class="tab-pane fade<?= $activeTab === 'delivery' ? ' show active' : '' ?>" id="tab-delivery" role="tabpanel" aria-labelledby="tab-delivery-btn">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="settings_tab" value="delivery">
                <input type="hidden" name="delivery_enabled_present" value="1">
                <section class="card hub-panel p-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="delivery_enabled" name="delivery_enabled" value="1" <?= ($s['delivery_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="delivery_enabled">Offer delivery at checkout (public site shop)</label>
                        <div class="form-text">Off by default. When on, customers can choose delivery instead of collection and enter an address — this only affects the public site's shop (myclubhub.co.uk/public/shop), not the original storefront.</div>
                    </div>
                    <div class="mb-2"><label class="form-label">Delivery fee (£)</label>
                        <input class="form-control" type="number" step="0.01" min="0" name="delivery_fee" value="<?= h((string) ($s['delivery_fee'] ?? '0.00')) ?>" style="max-width:10rem">
                        <div class="form-text">Flat fee added to the order total when a customer chooses delivery.</div></div>
                    <div class="mb-0"><label class="form-label">"Collection only" note <span class="text-muted small">(shown while delivery is off)</span></label>
                        <input class="form-control" name="delivery_note" value="<?= h((string) ($s['delivery_note'] ?? '')) ?>"></div>
                </section>
                <div class="mt-3"><button class="btn btn-dark" type="submit">Save delivery</button></div>
            </form>
        </div>

        <div class="tab-pane fade<?= $activeTab === 'scheduling' ? ' show active' : '' ?>" id="tab-scheduling" role="tabpanel" aria-labelledby="tab-scheduling-btn">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="settings_tab" value="scheduling">
                <input type="hidden" name="schedule_enabled_present" value="1">
                <input type="hidden" name="schedule_days_present" value="1">
                <?php $scheduleDaysSelected = array_map('intval', explode(',', (string) ($s['schedule_days'] ?? '1,2,3,4,5,6,7'))); ?>
                <?php $dayLabels = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday']; ?>
                <section class="card hub-panel p-3">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="schedule_enabled" name="schedule_enabled" value="1" <?= ($s['schedule_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="schedule_enabled">Let customers choose a collection/delivery date at checkout</label>
                        <div class="form-text">Off by default. When on, checkout shows a date picker limited to the days below.</div>
                    </div>
                    <label class="form-label">Available days</label>
                    <div class="d-flex flex-wrap gap-3 mb-3">
                        <?php foreach ($dayLabels as $num => $label): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="sched_day_<?= $num ?>" name="schedule_days[]" value="<?= $num ?>" <?= in_array($num, $scheduleDaysSelected, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="sched_day_<?= $num ?>"><?= $label ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label">Lead time (days)</label>
                            <input class="form-control" type="number" min="0" name="schedule_lead_days" value="<?= h((string) ($s['schedule_lead_days'] ?? '1')) ?>">
                            <div class="form-text">0 = same-day allowed (until the cut-off below).</div></div>
                        <div class="col-md-3"><label class="form-label">Same-day cut-off</label>
                            <input class="form-control" type="time" name="schedule_cutoff_time" value="<?= h((string) ($s['schedule_cutoff_time'] ?? '15:00')) ?>"></div>
                        <div class="col-md-3"><label class="form-label">Booking window (days)</label>
                            <input class="form-control" type="number" min="1" name="schedule_window_days" value="<?= h((string) ($s['schedule_window_days'] ?? '30')) ?>">
                            <div class="form-text">How many dates ahead to offer.</div></div>
                        <div class="col-md-3"><label class="form-label">Max orders per day</label>
                            <input class="form-control" type="number" min="0" name="schedule_max_per_day" value="<?= h((string) ($s['schedule_max_per_day'] ?? '')) ?>" placeholder="Unlimited">
                            <div class="form-text">Leave blank for no cap.</div></div>
                    </div>
                </section>
                <div class="mt-3"><button class="btn btn-dark" type="submit">Save scheduling</button></div>
            </form>
        </div>

        <div class="tab-pane fade<?= $activeTab === 'preorder' ? ' show active' : '' ?>" id="tab-preorder" role="tabpanel" aria-labelledby="tab-preorder-btn">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="settings_tab" value="preorder">
                <section class="card hub-panel p-3">
                    <div class="mb-2"><label class="form-label">Default close date &amp; time</label>
                        <input class="form-control" type="datetime-local" name="preorder_close_at" value="<?= h($closeValue) ?>">
                        <div class="form-text">Used for any pre-order product without its own close date. Ordering is blocked after this.</div></div>
                    <div class="mb-2"><label class="form-label">Manufacturing lead time</label>
                        <input class="form-control" name="lead_time" value="<?= h((string) ($s['lead_time'] ?? '6–8 weeks')) ?>"></div>
                    <div class="mb-0"><label class="form-label">Pre-order explainer (storefront &amp; emails)</label>
                        <textarea class="form-control" name="preorder_intro" rows="3"><?= h((string) ($s['preorder_intro'] ?? '')) ?></textarea></div>
                </section>
                <div class="mt-3"><button class="btn btn-dark" type="submit">Save pre-order window</button></div>
            </form>
        </div>

        <div class="tab-pane fade<?= $activeTab === 'terms' ? ' show active' : '' ?>" id="tab-terms" role="tabpanel" aria-labelledby="tab-terms-btn">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="settings_tab" value="terms">
                <section class="card hub-panel p-3">
                    <textarea class="form-control" name="terms" rows="8"><?= h((string) ($s['terms'] ?? '')) ?></textarea>
                    <div class="form-text">Shown on the checkout page; the customer must tick to accept.</div>
                </section>
                <div class="mt-3"><button class="btn btn-dark" type="submit">Save checkout terms</button></div>
            </form>
        </div>

        <div class="tab-pane fade<?= $activeTab === 'inventory' ? ' show active' : '' ?>" id="tab-inventory" role="tabpanel" aria-labelledby="tab-inventory-btn">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="settings_tab" value="inventory">
                <input type="hidden" name="low_stock_alerts_enabled_present" value="1">
                <section class="card hub-panel p-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="low_stock_alerts_enabled" name="low_stock_alerts_enabled" value="1" <?= ($s['low_stock_alerts_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="low_stock_alerts_enabled">Email the order notification recipients when stock runs low</label>
                    </div>
                    <div class="mb-0"><label class="form-label">Low stock threshold</label>
                        <input class="form-control" type="number" min="0" name="low_stock_threshold" value="<?= h((string) ($s['low_stock_threshold'] ?? '3')) ?>" style="max-width:10rem">
                        <div class="form-text">An alert is sent once when a product's (or a size/option's) stock drops to this number or below — not on every sale after that.</div></div>
                </section>
                <div class="mt-3"><button class="btn btn-dark" type="submit">Save inventory</button></div>
            </form>
        </div>

        <div class="tab-pane fade<?= $activeTab === 'notifications' ? ' show active' : '' ?>" id="tab-notifications" role="tabpanel" aria-labelledby="tab-notifications-btn">
            <section class="card hub-panel p-3">
                <?php require __DIR__ . '/partials/notification_recipients.php'; ?>
            </section>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
