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
    'collection_point', 'collection_street', 'collection_town', 'collection_county', 'collection_postcode', 'collection_country',
    'collection_address', 'collection_map_url', 'collection_map_embed', 'collection_details', 'delivery_note',
    'delivery_enabled', 'delivery_fee', 'free_delivery_over', 'delivery_notice', 'dispatch_time',
    'terms',
    'email_header_image', 'email_header_image_width', 'email_header_align', 'email_base_color', 'email_bg_color', 'email_body_bg_color', 'email_text_color',
    'email_footer_text', 'email_footer_text_color', 'email_footer_bg_color', 'email_reply_to',
    'low_stock_alerts_enabled', 'low_stock_threshold',
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
                if ($key === 'shop_enabled' || $key === 'delivery_enabled' || $key === 'low_stock_alerts_enabled') {
                    if (!array_key_exists($key . '_present', $_POST)) {
                        continue;
                    }
                    if ($key === 'delivery_enabled' && !empty($_POST[$key]) && !shop_shipping_usable_zones($pdo)) {
                        throw new RuntimeException('Delivery can\'t be switched on yet: no active shipping zone has a delivery method. Add one under Shipping zones first.');
                    }
                    shop_save_setting($pdo, $key, empty($_POST[$key]) ? '0' : '1');
                    continue;
                }
                if (!array_key_exists($key, $_POST)) {
                    continue;
                }
                $value = (string) $_POST[$key];
                if (str_starts_with($key, 'email_')) {
                    $value = trim($value);
                    if (str_ends_with($key, '_color') && $value !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
                        throw new RuntimeException('Colours must look like #4b0818.');
                    }
                    if ($key === 'email_header_image' && $value !== '' && !preg_match('#^https://\S+$#i', $value)) {
                        throw new RuntimeException('The header image must be a full https:// web address.');
                    }
                    if ($key === 'email_header_image_width' && $value !== '' && (!ctype_digit($value) || (int) $value < 40 || (int) $value > 600)) {
                        throw new RuntimeException('The header image width must be a whole number between 40 and 600 pixels.');
                    }
                    if ($key === 'email_header_align' && !in_array($value, ['', 'left', 'center', 'right'], true)) {
                        throw new RuntimeException('Choose left, centre or right for the header alignment.');
                    }
                    if ($key === 'email_reply_to' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        throw new RuntimeException('Enter a valid reply-to email address, or leave it blank.');
                    }
                    shop_save_setting($pdo, $key, mb_substr($value, 0, 500));
                    continue;
                }
                if ($key === 'collection_map_embed') {
                    // Accept a pasted <iframe …> or a bare URL; keep only a Google Maps embed URL.
                    $src = $value;
                    if (preg_match('/src\s*=\s*["\']([^"\']+)["\']/i', $value, $mm)) {
                        $src = html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
                    }
                    $src = trim($src);
                    if ($src !== '' && !preg_match('#^https://www\.google\.com/maps/embed\?#', $src)) {
                        throw new RuntimeException('The map embed must be a Google Maps embed code (Share → Embed a map). Paste the whole <iframe> or just its src address.');
                    }
                    shop_save_setting($pdo, $key, $src);
                    continue;
                }
                if ($key === 'collection_address') {
                    continue; // built below from the separate address fields
                }
                if ($key === 'free_delivery_over') {
                    $value = trim($value) === '' ? '' : number_format(max(0, round((float) $value, 2)), 2, '.', '');
                }
                if ($key === 'delivery_fee') {
                    $value = number_format(max(0, round((float) $value, 2)), 2, '.', '');
                }
                shop_save_setting($pdo, $key, trim($value));
            }
            if (array_key_exists('collection_postcode', $_POST)) {
                // Keep the combined address (used by checkout, emails, confirmation page) in step with the fields.
                $parts = [];
                foreach (['collection_point', 'collection_street', 'collection_town', 'collection_county'] as $k) {
                    $v = trim((string) ($_POST[$k] ?? ''));
                    if ($v !== '') {
                        $parts[] = $v;
                    }
                }
                $pc = trim((string) ($_POST['collection_postcode'] ?? ''));
                if ($pc !== '') {
                    $parts[] = strtoupper($pc);
                }
                $country = trim((string) ($_POST['collection_country'] ?? ''));
                if ($country !== '' && strcasecmp($country, 'United Kingdom') !== 0) {
                    $parts[] = $country;
                }
                shop_save_setting($pdo, 'collection_address', implode("\n", $parts));
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
    'actions' => [],
];
require_once __DIR__ . '/header.php';


$s = shop_get_settings($pdo);

$notificationData = notification_recipients_data($pdo);
$notificationRows = $notificationData['rows'];
$notificationAccountOptions = $notificationData['accountOptions'];
$notificationAccountIdSet = $notificationData['accountIdSet'];
$notificationCsrfField = csrf_field();
$notificationHiddenFields = '<input type="hidden" name="action" value="save_notifications">';

$validTabs = ['storefront', 'collection', 'delivery', 'terms', 'inventory', 'emails', 'notifications'];
$activeTab = in_array((string) ($_GET['tab'] ?? ''), $validTabs, true) ? (string) $_GET['tab'] : 'storefront';
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/settings.php">Settings</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page">Shop settings</span></nav>

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
            <button class="nav-link<?= $activeTab === 'delivery' ? ' active' : '' ?>" id="tab-delivery-btn" data-bs-toggle="tab" data-bs-target="#tab-delivery" type="button" role="tab" aria-controls="tab-delivery" aria-selected="<?= $activeTab === 'delivery' ? 'true' : 'false' ?>">Delivery &amp; shipping</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'terms' ? ' active' : '' ?>" id="tab-terms-btn" data-bs-toggle="tab" data-bs-target="#tab-terms" type="button" role="tab" aria-controls="tab-terms" aria-selected="<?= $activeTab === 'terms' ? 'true' : 'false' ?>">Checkout terms</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'inventory' ? ' active' : '' ?>" id="tab-inventory-btn" data-bs-toggle="tab" data-bs-target="#tab-inventory" type="button" role="tab" aria-controls="tab-inventory" aria-selected="<?= $activeTab === 'inventory' ? 'true' : 'false' ?>">Inventory</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link<?= $activeTab === 'emails' ? ' active' : '' ?>" id="tab-emails-btn" data-bs-toggle="tab" data-bs-target="#tab-emails" type="button" role="tab" aria-controls="tab-emails" aria-selected="<?= $activeTab === 'emails' ? 'true' : 'false' ?>">Emails</button>
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
                        <div class="form-text">The venue name. Shown throughout the shop and used as the first line of the address below.</div></div>
                    <h2 class="h6 fw-bold text-uppercase text-muted mt-3">Rest of the address</h2>
                    <div class="row g-2 mb-2">
                        <div class="col-12"><label class="form-label" for="c_street">Street</label>
                            <input class="form-control" id="c_street" name="collection_street" value="<?= h((string) ($s['collection_street'] ?? '')) ?>" placeholder="Blakely Rd"></div>
                        <div class="col-md-4"><label class="form-label" for="c_town">Town / city</label>
                            <input class="form-control" id="c_town" name="collection_town" value="<?= h((string) ($s['collection_town'] ?? '')) ?>" placeholder="Saltcoats"></div>
                        <div class="col-md-4"><label class="form-label" for="c_county">County / region <span class="text-muted small">(optional)</span></label>
                            <input class="form-control" id="c_county" name="collection_county" value="<?= h((string) ($s['collection_county'] ?? '')) ?>" placeholder="North Ayrshire"></div>
                        <div class="col-md-4"><label class="form-label" for="c_postcode">Postcode</label>
                            <input class="form-control text-uppercase" id="c_postcode" name="collection_postcode" value="<?= h((string) ($s['collection_postcode'] ?? '')) ?>" placeholder="KA21 5JQ"></div>
                        <div class="col-md-6"><label class="form-label" for="c_country">Country</label>
                            <input class="form-control" id="c_country" name="collection_country" value="<?= h((string) ($s['collection_country'] ?? 'United Kingdom')) ?>"></div>
                        <div class="col-12"><div class="form-text">Shown on checkout, the order confirmation page and emails. The full address is assembled from these boxes automatically.</div></div>
                    </div>
                    <div class="mb-2"><label class="form-label">Google Maps link</label>
                        <input class="form-control" type="url" name="collection_map_url" value="<?= h((string) ($s['collection_map_url'] ?? '')) ?>" placeholder="https://maps.app.goo.gl/...">
                        <div class="form-text">Paste a share link from Google Maps (search the address, tap Share, copy link). Shown as a "Get directions" button — leave blank to hide it.</div></div>
                    <div class="row g-3 mb-2">
                        <div class="col-md-6">
                            <label class="form-label" for="c_embed">Google Maps embed <span class="text-muted small">(optional)</span></label>
                            <textarea class="form-control font-monospace small" id="c_embed" name="collection_map_embed" rows="9" placeholder="Paste the &lt;iframe …&gt; embed code"><?= h((string) ($s['collection_map_embed'] ?? '')) ?></textarea>
                            <div class="form-text">In Google Maps choose Share → Embed a map → Copy HTML, then paste it here (only the map address is kept). Shows a map on the order confirmation page and at checkout.</div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-label">Map preview</div>
                            <?php if (($s['collection_map_embed'] ?? '') !== ''): ?>
                                <iframe class="rounded border w-100" src="<?= h((string) $s['collection_map_embed']) ?>" height="220" style="border:0" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" title="Collection point map"></iframe>
                            <?php else: ?>
                                <div class="border rounded d-flex align-items-center justify-content-center text-muted small" style="height:220px">No map yet &mdash; paste an embed code and save.</div>
                            <?php endif; ?>
                        </div>
                    </div>
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
                <?php
                $deliveryOn = ($s['delivery_enabled'] ?? '0') === '1';
                $allZones = shop_shipping_zones($pdo);
                $activeZones = array_values(array_filter($allZones, static fn(array $z): bool => (int) $z['is_active'] === 1));
                $usableZones = shop_shipping_usable_zones($pdo);
                $zoneCountries = shop_countries();
                $zoneSummary = static function (array $z) use ($zoneCountries): string {
                    $codes = array_filter(array_map('trim', explode(',', (string) $z['countries'])));
                    if (in_array('*', $codes, true)) {
                        return 'Everywhere else';
                    }
                    $names = array_map(static fn(string $c): string => $zoneCountries[$c] ?? $c, array_slice($codes, 0, 3));
                    return implode(', ', $names) . (count($codes) > 3 ? ' +' . (count($codes) - 3) . ' more' : '')
                        . (trim((string) ($z['postcodes'] ?? '')) !== '' ? ' · ' . trim(preg_replace('/\s+/', ' ', (string) $z['postcodes'])) : '');
                };
                $priceSummary = static function (array $m): string {
                    return match ((string) $m['method_type']) {
                        'free_over' => gbp((float) $m['cost']) . ', free over ' . gbp((float) $m['free_over']),
                        'weight' => 'by weight',
                        default => gbp((float) $m['cost']),
                    };
                };
                ?>
                <section class="card hub-panel p-3 mb-3">
                    <div class="form-check form-switch mb-1">
                        <input class="form-check-input" type="checkbox" id="delivery_enabled" name="delivery_enabled" value="1" <?= $deliveryOn ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="delivery_enabled">Offer delivery at checkout</label>
                    </div>
                    <div class="form-text mb-0">Off: customers can only collect. On: they can choose delivery, enter an address and pick a delivery method for their region.</div>
                    <?php if (!$usableZones): ?>
                        <div class="alert alert-warning small mt-3 mb-0"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>
                            No active shipping zone has a delivery method, so delivery can't be switched on yet. <a href="/admin/shop_shipping.php">Set up a zone</a> first.</div>
                    <?php elseif ($deliveryOn): ?>
                        <div class="alert alert-success small mt-3 mb-0"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i>Delivery is <strong>on</strong> for <?= count($usableZones) ?> zone<?= count($usableZones) === 1 ? '' : 's' ?>.</div>
                    <?php endif; ?>
                </section>

                <section class="card hub-panel mb-3">
                    <div class="card-header bg-transparent d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <strong>Where you deliver</strong>
                        <span class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-secondary" href="/admin/shop_shipping.php"><i class="fa-solid fa-earth-europe me-1" aria-hidden="true"></i>Shipping zones</a>
                            <a class="btn btn-sm btn-outline-secondary" href="/admin/shop_shipping_classes.php"><i class="fa-solid fa-boxes-stacked me-1" aria-hidden="true"></i>Shipping classes</a>
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 hub-data-table">
                            <thead><tr><th>Zone</th><th>Regions</th><th>Delivery methods</th><th></th></tr></thead>
                            <tbody>
                            <?php if (!$allZones): ?><tr><td colspan="4" class="text-muted text-center py-3">No zones yet.</td></tr><?php endif; ?>
                            <?php foreach ($allZones as $z): ?>
                                <?php
                                $activeMethods = array_values(array_filter($z['methods'], static fn(array $m): bool => (int) $m['is_active'] === 1));
                                $zoneOn = (int) $z['is_active'] === 1;
                                ?>
                                <tr<?= $zoneOn ? '' : ' class="text-muted"' ?>>
                                    <td class="fw-bold"><a class="text-decoration-none" href="/admin/shop_shipping.php?zone=<?= (int) $z['id'] ?>"><?= h((string) $z['name']) ?></a>
                                        <?php if (!$zoneOn): ?><span class="badge text-bg-secondary ms-1">Off</span><?php endif; ?></td>
                                    <td class="small"><?= h($zoneSummary($z)) ?></td>
                                    <td class="small">
                                        <?php if ($activeMethods): ?>
                                            <?php foreach ($activeMethods as $m): ?><div><?= h((string) $m['name']) ?> <span class="text-muted">— <?= h($priceSummary($m)) ?></span></div><?php endforeach; ?>
                                        <?php elseif ($zoneOn): ?>
                                            <span class="text-warning-emphasis"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>No active method &mdash; customers here can't get delivery</span>
                                        <?php else: ?>&mdash;<?php endif; ?>
                                    </td>
                                    <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/admin/shop_shipping.php?zone=<?= (int) $z['id'] ?>">Edit</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-transparent small text-muted">Customers are placed in the first matching zone from the top. Anyone outside every active zone is told delivery isn't available to them.</div>
                </section>

                <section class="card hub-panel p-3">
                    <h2 class="h6 fw-bold text-uppercase text-muted">Delivery options</h2>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="free_delivery_over">Free delivery over (£)</label>
                            <input class="form-control" id="free_delivery_over" type="number" step="0.01" min="0" name="free_delivery_over" value="<?= h((string) ($s['free_delivery_over'] ?? '')) ?>" placeholder="Off">
                            <div class="form-text">Orders at or above this value get free delivery in every zone. Leave blank to turn off.</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="dispatch_time">Dispatch time</label>
                            <input class="form-control" id="dispatch_time" name="dispatch_time" maxlength="190" value="<?= h((string) ($s['dispatch_time'] ?? '')) ?>" placeholder="e.g. Orders are dispatched within 3 working days">
                            <div class="form-text">Shown on delivery orders in the confirmation email and on the order page.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="delivery_notice">Checkout delivery notice</label>
                            <textarea class="form-control" id="delivery_notice" name="delivery_notice" rows="2" maxlength="500" placeholder="e.g. We post with a tracked service. International orders may be subject to customs charges."><?= h((string) ($s['delivery_notice'] ?? '')) ?></textarea>
                            <div class="form-text">Appears under the delivery options when a customer chooses delivery.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="delivery_note">&ldquo;Collection only&rdquo; note</label>
                            <input class="form-control" id="delivery_note" name="delivery_note" value="<?= h((string) ($s['delivery_note'] ?? '')) ?>">
                            <div class="form-text">Shown at checkout only while delivery is switched <strong>off</strong>.</div>
                        </div>
                    </div>
                </section>
                <div class="mt-3"><button class="btn btn-dark" type="submit">Save delivery</button></div>
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

        <div class="tab-pane fade<?= $activeTab === 'emails' ? ' show active' : '' ?>" id="tab-emails" role="tabpanel" aria-labelledby="tab-emails-btn">
            <?php
            $emailDefs = shop_email_definitions();
            $adminRecipients = function_exists('stripe_notification_recipients') ? stripe_notification_recipients($pdo) : [];
            $mailCfg = function_exists('hub_mail_smtp_config') ? hub_mail_smtp_config() : ['from_email' => '', 'from_name' => ''];
            $emailStyle = shop_email_style($pdo);
            $emailStyleDefaults = shop_email_style_defaults();
            ?>
            <h2 class="h5 mb-1">Email notifications</h2>
            <p class="text-muted small">The emails the shop sends. Choose <strong>Manage</strong> to change the subject, wording and whether it is sent.</p>
            <section class="card hub-panel mb-4">
                <div class="table-responsive">
                    <table class="table align-middle mb-0 hub-data-table">
                        <thead><tr><th style="width:70px">Sent</th><th>Email</th><th>Recipient(s)</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($emailDefs as $ekey => $edef): $ecfg = shop_email_config($pdo, $ekey); ?>
                            <tr>
                                <td><?= $ecfg['enabled'] ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-secondary">No</span>' ?></td>
                                <td><a class="fw-bold text-decoration-none" href="/admin/shop_email.php?key=<?= h($ekey) ?>"><?= h($edef['name']) ?></a>
                                    <span class="ms-1 text-muted" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="<?= h($edef['description']) ?>" aria-label="<?= h($edef['description']) ?>" style="cursor:help"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span></td>
                                <td class="small"><?php if ($edef['to'] === 'customer'): ?>Customer<?php else: ?><?= $adminRecipients ? h(implode(', ', $adminRecipients)) : '<span class="text-warning-emphasis">none set</span>' ?> <a href="/admin/shop_settings.php?tab=notifications">edit</a><?php endif; ?></td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="/admin/shop_email.php?key=<?= h($ekey) ?>">Manage</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <form method="post" id="emailStyleForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="settings_tab" value="emails">

                <h2 class="h5 mb-1">Email sender options</h2>
                <p class="text-muted small">Who the shop's emails appear to come from.</p>
                <section class="card hub-panel p-3 mb-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="mail_from_name">&ldquo;From&rdquo; name</label>
                            <input class="form-control" id="mail_from_name" value="<?= h((string) $mailCfg['from_name']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="mail_from_addr">&ldquo;From&rdquo; address</label>
                            <input class="form-control" id="mail_from_addr" value="<?= h((string) $mailCfg['from_email']) ?>" readonly>
                        </div>
                        <div class="col-12"><div class="form-text mt-0">The sender is set once for the whole club in the server's mail configuration (it must match the mail account the emails are sent through), so it can't be changed here.</div></div>
                        <div class="col-md-6">
                            <label class="form-label" for="email_reply_to">Reply-to address <span class="text-muted small">(optional)</span></label>
                            <input class="form-control" id="email_reply_to" type="email" name="email_reply_to" value="<?= h((string) ($s['email_reply_to'] ?? '')) ?>" placeholder="e.g. shop@saltcoatsvictoria.co.uk">
                            <div class="form-text">When a customer replies to a shop email, their reply goes here instead of the sender address.</div>
                        </div>
                    </div>
                </section>

                <h2 class="h5 mb-1">Email template</h2>
                <p class="text-muted small">The look shared by every shop email. The preview updates as you change things; nothing changes for customers until you save.</p>
                <div class="row g-3 mb-4">
                    <div class="col-xl-5">
                        <section class="card hub-panel p-3">
                            <div class="mb-3">
                                <label class="form-label" for="email_header_image">Header image
                                    <span class="ms-1 text-muted" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-title="Choose an image from the Media Library to show at the top of every email, such as the club crest. With no image the club name is shown as text." aria-label="Choose an image from the Media Library to show at the top of every email."><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span></label>
                                <input type="hidden" id="email_header_image" name="email_header_image" value="<?= h((string) ($s['email_header_image'] ?? '')) ?>" data-style="email_header_image">
                                <div class="d-flex align-items-center gap-3 flex-wrap">
                                    <div id="headerImageThumb" class="border rounded bg-light d-flex align-items-center justify-content-center overflow-hidden" style="width:140px;height:70px">
                                        <?php if (($s['email_header_image'] ?? '') !== ''): ?><img src="<?= h(preg_match('#^(?:https?://[^/]+)?(/(?:uploads|badges)/.+\.(?:png|jpe?g))$#i', (string) $s['email_header_image'], $emh) ? '/img/400' . $emh[1] : (string) $s['email_header_image']) ?>" alt="" style="max-width:100%;max-height:100%"><?php else: ?><span class="small text-muted">No image</span><?php endif; ?>
                                    </div>
                                    <div class="d-flex flex-column gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="headerImagePick"><i class="fa-regular fa-images me-1" aria-hidden="true"></i>Choose from Media Library</button>
                                        <button type="button" class="btn btn-sm btn-link text-danger p-0 text-start" id="headerImageClear"<?= ($s['email_header_image'] ?? '') === '' ? ' hidden' : '' ?>>Remove image</button>
                                    </div>
                                </div>
                                <div class="row g-2 mt-2">
                                    <div class="col-6">
                                        <label class="form-label small mb-1" for="email_header_image_width">Image width (px)
                                            <span class="ms-1 text-muted" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-title="How wide the header image appears in the email, in pixels. A crest usually looks right at 80–160. Default 140." aria-label="How wide the header image appears in the email, in pixels."><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span></label>
                                        <input class="form-control" id="email_header_image_width" name="email_header_image_width" type="number" min="40" max="600" step="10" value="<?= h((string) ($s['email_header_image_width'] ?? '')) ?>" placeholder="<?= h($emailStyleDefaults['email_header_image_width']) ?>" data-style="email_header_image_width">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small mb-1" for="email_header_align">Header alignment</label>
                                        <select class="form-select" id="email_header_align" name="email_header_align" data-style="email_header_align">
                                            <?php foreach (['left' => 'Left', 'center' => 'Centre', 'right' => 'Right'] as $av => $al): ?>
                                                <option value="<?= $av ?>" <?= $emailStyle['email_header_align'] === $av ? 'selected' : '' ?>><?= $al ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <?php
                            $colourFields = [
                                'email_base_color' => ['Base colour', 'The colour of the header band.'],
                                'email_bg_color' => ['Background colour', 'The colour behind the email.'],
                                'email_body_bg_color' => ['Body background colour', 'The colour of the main email panel.'],
                                'email_text_color' => ['Body text colour', 'The colour of the main text.'],
                                'email_footer_bg_color' => ['Footer background colour', 'The colour of the footer band.'],
                                'email_footer_text_color' => ['Footer text colour', 'The colour of the footer text.'],
                            ];
                            foreach ($colourFields as $ck => [$clabel, $chelp]): ?>
                                <div class="row g-2 align-items-center mb-2">
                                    <label class="col-5 col-form-label small fw-semibold" for="<?= $ck ?>"><?= h($clabel) ?>
                                        <span class="ms-1 text-muted" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-title="<?= h($chelp . ' Default ' . $emailStyleDefaults[$ck] . '.') ?>" aria-label="<?= h($chelp) ?>"><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span></label>
                                    <div class="col-7 d-flex align-items-center gap-2">
                                        <input type="color" class="form-control form-control-color p-1" value="<?= h($emailStyle[$ck]) ?>" data-color-for="<?= $ck ?>" aria-label="<?= h($clabel) ?> picker">
                                        <input class="form-control font-monospace" style="max-width:7rem" id="<?= $ck ?>" name="<?= $ck ?>" value="<?= h((string) ($s[$ck] ?? '')) ?>" placeholder="<?= h($emailStyleDefaults[$ck]) ?>" maxlength="7" data-style="<?= $ck ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <div class="mt-3">
                                <label class="form-label" for="email_footer_text">Footer text
                                    <span class="ms-1 text-muted" tabindex="0" role="img" data-bs-toggle="tooltip" data-bs-title="The text in the footer of all shop emails. Available placeholders: {shop_name} {site_url}" aria-label="Footer text. Placeholders: shop_name, site_url."><i class="fa-solid fa-circle-question" aria-hidden="true"></i></span></label>
                                <textarea class="form-control" id="email_footer_text" name="email_footer_text" rows="2" data-style="email_footer_text" placeholder="<?= h($emailStyleDefaults['email_footer_text']) ?>"><?= h((string) ($s['email_footer_text'] ?? '')) ?></textarea>
                            </div>
                            <div class="form-text mt-2">Leave a colour blank to use its default.</div>
                        </section>
                    </div>
                    <div class="col-xl-7">
                        <section class="card hub-panel" style="position:sticky;top:1rem">
                            <div class="card-header bg-transparent d-flex flex-wrap align-items-center gap-2">
                                <select class="form-select form-select-sm w-auto" id="previewKey" aria-label="Email preview type">
                                    <?php foreach ($emailDefs as $ekey => $edef): ?><option value="<?= h($ekey) ?>"><?= h($edef['name']) ?></option><?php endforeach; ?>
                                </select>
                                <span class="small text-muted" id="stylePreviewState">Sample data</span>
                                <span class="ms-auto d-flex gap-1">
                                    <button type="button" class="btn btn-sm btn-outline-secondary active" data-device="desktop" title="Desktop preview"><i class="fa-solid fa-desktop" aria-hidden="true"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-device="mobile" title="Mobile preview"><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i></button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="styleSendTest">Send a test email</button>
                                </span>
                            </div>
                            <div class="px-3 py-2 border-bottom small"><span class="text-muted">Subject:</span> <strong id="stylePreviewSubject"></strong></div>
                            <div class="text-center bg-light"><iframe id="stylePreviewFrame" title="Email preview" style="width:100%;height:600px;border:0;background:#fff;transition:width .2s"></iframe></div>
                            <div class="small px-3 py-2" id="styleTestResult" aria-live="polite"></div>
                        </section>
                    </div>
                </div>
                <div class="mt-3"><button class="btn btn-dark" type="submit">Save email options</button></div>
            </form>
            <script>
            (function () {
                var form = document.getElementById('emailStyleForm'); if (!form) return;
                var frame = document.getElementById('stylePreviewFrame'), subj = document.getElementById('stylePreviewSubject'), state = document.getElementById('stylePreviewState');
                var keySel = document.getElementById('previewKey'), timer = null, seq = 0;
                function payload(extra) {
                    var fd = new FormData();
                    fd.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
                    fd.append('key', keySel.value);
                    form.querySelectorAll('[data-style]').forEach(function (el) { fd.append('style[' + el.dataset.style + ']', el.value); });
                    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
                    return fd;
                }
                function refresh() {
                    var mine = ++seq; state.textContent = 'Updating…';
                    fetch('/admin/shop_email_preview.php', { method: 'POST', body: payload(), credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) { if (mine !== seq) return; if (d.ok) { frame.srcdoc = d.html; subj.textContent = d.subject; state.textContent = 'Sample data'; } else { state.textContent = d.error || 'Preview failed'; } })
                        .catch(function () { if (mine === seq) state.textContent = 'Preview failed'; });
                }
                function soon() { clearTimeout(timer); timer = setTimeout(refresh, 400); }
                form.querySelectorAll('[data-style]').forEach(function (el) { el.addEventListener('input', soon); });
                form.querySelectorAll('[data-color-for]').forEach(function (picker) {
                    var text = document.getElementById(picker.dataset.colorFor);
                    picker.addEventListener('input', function () { text.value = picker.value; soon(); });
                    text.addEventListener('input', function () { if (/^#[0-9a-fA-F]{6}$/.test(text.value)) picker.value = text.value; });
                });
                keySel.addEventListener('change', refresh);
                document.querySelectorAll('[data-device]').forEach(function (b) {
                    b.addEventListener('click', function () {
                        document.querySelectorAll('[data-device]').forEach(function (x) { x.classList.toggle('active', x === b); });
                        frame.style.width = b.dataset.device === 'mobile' ? '375px' : '100%';
                    });
                });
                document.getElementById('styleSendTest').addEventListener('click', function () {
                    var out = document.getElementById('styleTestResult'); out.className = 'small px-3 py-2 text-muted'; out.textContent = 'Sending…';
                    fetch('/admin/shop_email_preview.php', { method: 'POST', body: payload({ send_test: '1' }), credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) { out.className = 'small px-3 py-2 ' + (d.sent ? 'text-success' : 'text-danger'); out.textContent = d.sent ? 'Test email sent to ' + d.sent_to + '.' : (d.error || 'Could not send.'); })
                        .catch(function () { out.className = 'small px-3 py-2 text-danger'; out.textContent = 'Could not send.'; });
                });
                var tabBtn = document.getElementById('tab-emails-btn');
                var loaded = false;
                function first() { if (!loaded) { loaded = true; refresh(); } }
                if (tabBtn) { tabBtn.addEventListener('shown.bs.tab', first); if (tabBtn.classList.contains('active')) first(); }
                // Run once the page (and Bootstrap, which loads deferred) is ready, and the popup markup exists.
                document.addEventListener('DOMContentLoaded', function () {
                // Header image: pick from the Media Library
                (function () {
                    var modalEl = document.getElementById('emailMediaModal'); if (!modalEl) return;
                    var hidden = document.getElementById('email_header_image'), thumb = document.getElementById('headerImageThumb'), clearBtn = document.getElementById('headerImageClear');
                    var grid = modalEl.querySelector('[data-grid]'), search = modalEl.querySelector('[data-search]'), cat = modalEl.querySelector('[data-cat]'), count = modalEl.querySelector('[data-count]');
                    var useBtn = modalEl.querySelector('[data-use]'), selLabel = modalEl.querySelector('[data-sel]');
                    var catalogue = null, chosen = null, CAP = 300;
                    function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
                    function setImage(url) {
                        hidden.value = url;
                        thumb.innerHTML = url ? '<img src="' + esc(url) + '" alt="" style="max-width:100%;max-height:100%">' : '<span class="small text-muted">No image</span>';
                        clearBtn.hidden = !url;
                        hidden.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                    function render() {
                        if (!catalogue) return;
                        var q = search.value.trim().toLowerCase(), c = cat.value;
                        var matches = catalogue.filter(function (m) { return (!c || m.category === c) && (!q || (m.name + ' ' + m.path).toLowerCase().indexOf(q) !== -1); });
                        count.textContent = matches.length + ' image' + (matches.length === 1 ? '' : 's') + (matches.length > CAP ? ' — showing first ' + CAP : '');
                        grid.innerHTML = '';
                        if (!matches.length) { grid.innerHTML = '<p class="text-muted small m-0">No images match.</p>'; return; }
                        matches.slice(0, CAP).forEach(function (m) {
                            var b = document.createElement('button'); b.type = 'button';
                            b.className = 'email-lib-tile' + (chosen && chosen.path === m.path ? ' is-sel' : ''); b.title = m.path;
                            b.innerHTML = '<img loading="lazy" src="' + esc(m.url) + '" alt=""><span>' + esc(m.name) + '</span>';
                            b.addEventListener('click', function () {
                                grid.querySelectorAll('.is-sel').forEach(function (t) { t.classList.remove('is-sel'); });
                                chosen = m; b.classList.add('is-sel'); selLabel.textContent = m.name; useBtn.disabled = false;
                            });
                            grid.appendChild(b);
                        });
                    }
                    function load() {
                        if (catalogue) { render(); return; }
                        grid.innerHTML = '<p class="text-muted small m-0">Opening the library…</p>';
                        var fd = new FormData(); fd.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
                        fetch('/admin/shop_media_catalogue.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (j) {
                                if (!j || !j.ok) throw new Error((j && j.message) || 'Could not load the library.');
                                catalogue = j.items || [];
                                var cats = {}; catalogue.forEach(function (m) { cats[m.category] = 1; });
                                Object.keys(cats).sort().forEach(function (c) { var o = document.createElement('option'); o.value = c; o.textContent = c; cat.appendChild(o); });
                                render();
                            })
                            .catch(function (e) { grid.innerHTML = '<p class="text-danger small m-0">' + esc(e.message) + '</p>'; });
                    }
                    document.getElementById('headerImagePick').addEventListener('click', function () {
                        chosen = null; useBtn.disabled = true; selLabel.textContent = 'Choose an image';
                        if (catalogue) render();
                        if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    });
                    modalEl.addEventListener('shown.bs.modal', load);
                    modalEl.addEventListener('hide.bs.modal', function () { if (modalEl.contains(document.activeElement)) document.activeElement.blur(); });
                    search.addEventListener('input', render); cat.addEventListener('change', render);
                    useBtn.addEventListener('click', function () {
                        if (!chosen) return;
                        setImage(location.origin + chosen.url);   // emails need a full web address
                        if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                    });
                    clearBtn.addEventListener('click', function () { setImage(''); });
                })();
                if (window.bootstrap) document.querySelectorAll('#tab-emails [data-bs-toggle="tooltip"]').forEach(function (t) { new bootstrap.Tooltip(t, { container: 'body', trigger: 'hover focus' }); });
                });
            })();
            </script>
        </div>

        <style>
            .email-lib-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(128px, 1fr)); gap: .7rem; }
            .email-lib-tile { border: 1px solid #d7d2c8; border-radius: 8px; background: #fff; padding: 0; overflow: hidden; cursor: pointer; text-align: left; }
            .email-lib-tile img { width: 100%; height: 96px; object-fit: contain; display: block; background: #efe9df; }
            .email-lib-tile span { display: block; padding: .35rem .5rem; font-size: .72rem; color: #6c665c; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .email-lib-tile.is-sel { border-color: #6d2231; box-shadow: inset 0 0 0 2px #6d2231; }
        </style>
        <div class="modal fade" id="emailMediaModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title">Media Library</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <input type="search" class="form-control form-control-sm" style="max-width:280px" placeholder="Search by name or folder…" data-search>
                            <select class="form-select form-select-sm" style="max-width:240px" data-cat><option value="">All categories</option></select>
                            <span class="small text-muted ms-auto align-self-center" data-count></span>
                        </div>
                        <div class="email-lib-grid" data-grid><p class="text-muted small m-0">Opening the library…</p></div>
                    </div>
                    <div class="modal-footer">
                        <span class="small text-muted me-auto" data-sel>Choose an image</span>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-brand" data-use disabled>Use this image</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade<?= $activeTab === 'notifications' ? ' show active' : '' ?>" id="tab-notifications" role="tabpanel" aria-labelledby="tab-notifications-btn">
            <section class="card hub-panel p-3">
                <?php require __DIR__ . '/partials/notification_recipients.php'; ?>
            </section>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
