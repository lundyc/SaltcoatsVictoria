<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/settings_store.php';
require_once __DIR__ . '/lib/navigation_settings.php';
require_once __DIR__ . '/social_post_settings.php';
require_once __DIR__ . '/lib/publishing_settings.php';
require_once __DIR__ . '/lib/publishing_history.php';
require_once __DIR__ . '/lib/publishing_health.php';
require_once __DIR__ . '/lib/publishing_automation.php';
require_once __DIR__ . '/lib/template_packs.php';
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/lib/stripe.php';
require_once __DIR__ . '/lib/audit.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}

$currentUser = hub_auth_current_user();
if (!hub_auth_has_capability('admin_settings')) {
    http_response_code(403);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Settings</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="/admin/assets/css/style.css" rel="stylesheet"></head><body class="hub-shell"><main class="hub-main"><div class="container-fluid"><div class="alert alert-danger mb-0">You do not have permission to manage Hub settings.</div></div></main></body></html>';
    exit;
}

template_packs_ensure_schema($pdo);

$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'Settings',
    'subtitle' => 'Manage Hub configuration, calendar sync, and social publishing from one screen.',
    'actions' => [],
];

$fileEnv = hub_settings_load_env();
$activeTab = isset($_GET['tab']) && is_string($_GET['tab']) ? trim($_GET['tab']) : 'general';
$allowedTabs = ['navigation', 'general', 'database', 'calendar', 'payments', 'notifications', 'publishing'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'general';
}

$statusType = '';
$statusMessage = '';
$publishingDefinitions = social_post_template_definitions();
$publishingPresetDefinitions = social_post_preset_definitions();
$publishingSettings = social_post_settings_load();
$publishingPreferences = social_publishing_preferences_load();
$publishingSections = ['overview', 'templates', 'visuals', 'automation', 'history'];
$publishingSection = isset($_REQUEST['publishing_section']) && is_string($_REQUEST['publishing_section'])
    ? trim($_REQUEST['publishing_section'])
    : 'overview';
if ($publishingSection === 'platforms') {
    $publishingSection = 'templates';
}
if (!in_array($publishingSection, $publishingSections, true)) {
    $publishingSection = 'overview';
}
$socialsDirectory = __DIR__;
$publishingPlatformHealth = hub_publishing_platform_health($socialsDirectory);
$publishingHistory = hub_publishing_history_recent($pdo, 30);
$publishingHistoryCounts = hub_publishing_history_counts($pdo);

/**
 * @return list<int>
 */
function settings_notification_account_ids(PDO $pdo): array
{
    return stripe_notification_account_ids($pdo);
}

/**
 * @return list<string>
 */
function settings_notification_manual_emails(PDO $pdo): array
{
    return stripe_notification_manual_emails($pdo);
}

/**
 * @param list<int> $accountIds
 * @param list<string> $manualEmails
 */
function settings_save_notification_lists(PDO $pdo, array $accountIds, array $manualEmails, ?int $currentUserId = null): bool
{
    return stripe_notification_save_lists($pdo, $accountIds, $manualEmails, $currentUserId);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : null;
    if (!hub_auth_verify_csrf_token($csrfToken)) {
        $statusType = 'error';
        $statusMessage = 'Your session expired. Please reload the page and try again.';
    } else {
        $section = isset($_POST['section']) && is_string($_POST['section']) ? trim($_POST['section']) : '';
        if (in_array($section, ['general', 'database', 'calendar', 'payments', 'notifications'], true)) {
            $activeTab = $section;
            $updates = [];

        if ($section === 'general') {
            $appName = isset($_POST['app_name']) && is_string($_POST['app_name']) ? trim($_POST['app_name']) : '';
            $baseUrl = isset($_POST['base_url']) && is_string($_POST['base_url']) ? trim($_POST['base_url']) : '';
            $errorLogPath = isset($_POST['error_log_path']) && is_string($_POST['error_log_path']) ? trim($_POST['error_log_path']) : '';
            $appDebug = isset($_POST['app_debug']) && is_string($_POST['app_debug']) ? trim($_POST['app_debug']) : '1';

            $updates['APP_NAME'] = $appName !== '' ? $appName : hub_settings_value($fileEnv, 'APP_NAME', 'Hub');
            $updates['BASE_URL'] = $baseUrl !== '' ? $baseUrl : '/';
            $updates['ERROR_LOG_PATH'] = $errorLogPath !== '' ? $errorLogPath : hub_settings_value($fileEnv, 'ERROR_LOG_PATH', '/var/www/vhosts/lundy.me.uk/logs/error_log');
            $updates['HUB_APP_DEBUG'] = in_array(strtolower($appDebug), ['0', 'false', 'no', 'off'], true) ? '0' : '1';
        } elseif ($section === 'database') {
            $dbHost = isset($_POST['db_host']) && is_string($_POST['db_host']) ? trim($_POST['db_host']) : '';
            $dbName = isset($_POST['db_name']) && is_string($_POST['db_name']) ? trim($_POST['db_name']) : '';
            $dbFallbackName = isset($_POST['db_fallback_name']) && is_string($_POST['db_fallback_name']) ? trim($_POST['db_fallback_name']) : '';
            $dbUser = isset($_POST['db_user']) && is_string($_POST['db_user']) ? trim($_POST['db_user']) : '';
            $dbPass = isset($_POST['db_pass']) && is_string($_POST['db_pass']) ? trim($_POST['db_pass']) : '';
            $installSecret = isset($_POST['install_secret']) && is_string($_POST['install_secret']) ? trim($_POST['install_secret']) : '';

            $updates['HUB_DB_HOST'] = $dbHost !== '' ? $dbHost : hub_settings_value($fileEnv, 'HUB_DB_HOST', 'localhost:3306');
            $updates['HUB_DB_NAME'] = $dbName !== '' ? $dbName : hub_settings_value($fileEnv, 'HUB_DB_NAME', 'Hub');
            $updates['HUB_DB_FALLBACK_NAME'] = $dbFallbackName !== '' ? $dbFallbackName : hub_settings_value($fileEnv, 'HUB_DB_FALLBACK_NAME', 'PlayerSponsors');
            $updates['HUB_DB_USER'] = $dbUser !== '' ? $dbUser : hub_settings_value($fileEnv, 'HUB_DB_USER', 'PlayerSponsors');
            if ($dbPass !== '') {
                $updates['HUB_DB_PASS'] = $dbPass;
            }
            if ($installSecret !== '') {
                $updates['HUB_INSTALL_SECRET'] = $installSecret;
            }
        } elseif ($section === 'calendar') {
            $syncEnabled = isset($_POST['google_calendar_sync_enabled']) && is_string($_POST['google_calendar_sync_enabled']) ? trim($_POST['google_calendar_sync_enabled']) : 'true';
            $calendarId = isset($_POST['google_calendar_id']) && is_string($_POST['google_calendar_id']) ? trim($_POST['google_calendar_id']) : '';
            $timezone = isset($_POST['google_calendar_timezone']) && is_string($_POST['google_calendar_timezone']) ? trim($_POST['google_calendar_timezone']) : '';
            $duration = isset($_POST['google_calendar_duration']) && is_string($_POST['google_calendar_duration']) ? trim($_POST['google_calendar_duration']) : '';
            $clientId = isset($_POST['google_calendar_client_id']) && is_string($_POST['google_calendar_client_id']) ? trim($_POST['google_calendar_client_id']) : '';
            $clientSecret = isset($_POST['google_calendar_client_secret']) && is_string($_POST['google_calendar_client_secret']) ? trim($_POST['google_calendar_client_secret']) : '';
            $refreshToken = isset($_POST['google_calendar_refresh_token']) && is_string($_POST['google_calendar_refresh_token']) ? trim($_POST['google_calendar_refresh_token']) : '';
            $accessToken = isset($_POST['google_calendar_access_token']) && is_string($_POST['google_calendar_access_token']) ? trim($_POST['google_calendar_access_token']) : '';

            $updates['GOOGLE_CALENDAR_SYNC_ENABLED'] = in_array(strtolower($syncEnabled), ['0', 'false', 'no', 'off'], true) ? 'false' : 'true';
            $updates['GOOGLE_CALENDAR_ID'] = $calendarId !== '' ? $calendarId : hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_ID', '');
            $updates['GOOGLE_CALENDAR_TIMEZONE'] = $timezone !== '' ? $timezone : hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_TIMEZONE', 'Europe/London');
            $updates['GOOGLE_CALENDAR_EVENT_DURATION_MINUTES'] = $duration !== '' ? (string) max(1, (int) $duration) : hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_EVENT_DURATION_MINUTES', '120');
            if ($clientId !== '') {
                $updates['GOOGLE_CALENDAR_CLIENT_ID'] = $clientId;
            }
            if ($clientSecret !== '') {
                $updates['GOOGLE_CALENDAR_CLIENT_SECRET'] = $clientSecret;
            }
            if ($refreshToken !== '') {
                $updates['GOOGLE_CALENDAR_REFRESH_TOKEN'] = $refreshToken;
            }
            if ($accessToken !== '') {
                $updates['GOOGLE_CALENDAR_ACCESS_TOKEN'] = $accessToken;
            }
        } elseif ($section === 'payments') {
            $stripeMode = isset($_POST['stripe_mode']) && is_string($_POST['stripe_mode']) ? trim($_POST['stripe_mode']) : 'test';
            $stripeSecretKey = isset($_POST['stripe_secret_key']) && is_string($_POST['stripe_secret_key']) ? trim($_POST['stripe_secret_key']) : '';
            $stripeWebhookSecret = isset($_POST['stripe_webhook_secret']) && is_string($_POST['stripe_webhook_secret']) ? trim($_POST['stripe_webhook_secret']) : '';
            $stripeCurrency = isset($_POST['stripe_default_currency']) && is_string($_POST['stripe_default_currency']) ? trim($_POST['stripe_default_currency']) : '';
            $stripeExpiryHours = isset($_POST['stripe_link_expiry_hours']) && is_string($_POST['stripe_link_expiry_hours']) ? trim($_POST['stripe_link_expiry_hours']) : '';
            $stripePublicExpiryDays = isset($_POST['stripe_public_link_expiry_days']) && is_string($_POST['stripe_public_link_expiry_days']) ? trim($_POST['stripe_public_link_expiry_days']) : '';

            $updates['STRIPE_MODE'] = $stripeMode === 'live' ? 'live' : 'test';
            $updates['STRIPE_DEFAULT_CURRENCY'] = $stripeCurrency !== '' ? strtolower($stripeCurrency) : hub_settings_value($fileEnv, 'STRIPE_DEFAULT_CURRENCY', 'gbp');
            $updates['STRIPE_LINK_EXPIRY_HOURS'] = $stripeExpiryHours !== '' ? (string) max(1, min(24, (int) $stripeExpiryHours)) : hub_settings_value($fileEnv, 'STRIPE_LINK_EXPIRY_HOURS', '24');
            $updates['STRIPE_PUBLIC_LINK_EXPIRY_DAYS'] = $stripePublicExpiryDays !== '' ? (string) max(1, min(30, (int) $stripePublicExpiryDays)) : hub_settings_value($fileEnv, 'STRIPE_PUBLIC_LINK_EXPIRY_DAYS', '7');
            if ($stripeSecretKey !== '') {
                $updates['STRIPE_SECRET_KEY'] = $stripeSecretKey;
            }
            if ($stripeWebhookSecret !== '') {
                $updates['STRIPE_WEBHOOK_SECRET'] = $stripeWebhookSecret;
            }
        } elseif ($section === 'notifications') {
            stripe_notification_seed_from_env($pdo, stripe_env(), (int) ($currentUser['id'] ?? 0) ?: null);
            $accountIds = settings_notification_account_ids($pdo);
            $manualEmails = settings_notification_manual_emails($pdo);
            $notificationAction = isset($_POST['notification_action']) && is_string($_POST['notification_action']) ? trim($_POST['notification_action']) : '';

            if ($notificationAction === 'add_accounts') {
                $postedAccountIds = isset($_POST['account_ids']) && is_array($_POST['account_ids']) ? $_POST['account_ids'] : [];
                foreach ($postedAccountIds as $accountId) {
                    $accountId = is_scalar($accountId) ? trim((string) $accountId) : '';
                    if ($accountId !== '' && ctype_digit($accountId) && (int) $accountId > 0) {
                        $accountIds[] = (int) $accountId;
                    }
                }
            } elseif ($notificationAction === 'remove_account') {
                $removeId = (int) ($_POST['account_id'] ?? 0);
                $accountIds = array_values(array_filter($accountIds, static fn(int $id): bool => $id !== $removeId));
            } elseif ($notificationAction === 'add_email') {
                $email = trim((string) ($_POST['email'] ?? ''));
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $statusType = 'error';
                    $statusMessage = 'Enter a valid email address.';
                } else {
                    $manualEmails[] = $email;
                }
            } elseif ($notificationAction === 'update_email') {
                $oldEmail = strtolower(trim((string) ($_POST['old_email'] ?? '')));
                $newEmail = trim((string) ($_POST['email'] ?? ''));
                if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    $statusType = 'error';
                    $statusMessage = 'Enter a valid email address.';
                } else {
                    foreach ($manualEmails as $index => $email) {
                        if (strtolower($email) === $oldEmail) {
                            unset($manualEmails[$index]);
                        }
                    }
                    $manualEmails[] = $newEmail;
                }
            } elseif ($notificationAction === 'delete_email') {
                $deleteEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
                $manualEmails = array_values(array_filter($manualEmails, static fn(string $email): bool => strtolower($email) !== $deleteEmail));
            } else {
                $statusType = 'error';
                $statusMessage = 'Unknown notification action.';
            }

            if ($statusType !== 'error') {
                $saved = settings_save_notification_lists($pdo, $accountIds, $manualEmails, (int) ($currentUser['id'] ?? 0) ?: null);
                if ($saved) {
                    auditLog($pdo, 'settings_updated', 'Updated payment notification settings');
                    header('Location: /admin/settings.php?tab=notifications&saved=1');
                    exit;
                }
                $statusType = 'error';
                $statusMessage = 'Notification recipients could not be saved. Please try again.';
            }
        }

            $saved = $section === 'notifications' || $statusType === 'error' ? false : hub_settings_save_env($updates);
            if ($saved) {
                auditLog($pdo, 'settings_updated', 'Updated club settings (' . $section . ')');
                header('Location: /admin/settings.php?tab=' . rawurlencode($activeTab) . '&saved=1');
                exit;
            }

            if ($statusMessage === '') {
                $statusType = 'error';
                $statusMessage = 'Settings could not be saved. Check file permissions for `hub/.env`.';
            }
        } elseif ($section === 'navigation') {
            $activeTab = 'navigation';
            try {
                $groups = isset($_POST['nav_groups']) && is_array($_POST['nav_groups']) ? $_POST['nav_groups'] : [];
                $items = isset($_POST['nav_items']) && is_array($_POST['nav_items']) ? $_POST['nav_items'] : [];
                hub_navigation_save($pdo, $groups, $items);
                auditLog($pdo, 'navigation_settings_updated', 'Updated navigation visibility for all users.');
                header('Location: /admin/settings.php?tab=navigation&saved=1');
                exit;
            } catch (Throwable $e) {
                $statusType = 'error';
                $statusMessage = 'Navigation settings could not be saved. Please try again.';
            }
        } elseif ($section === 'global_template_pack') {
            $activeTab = 'general';
            $packId = max(0, (int) ($_POST['template_pack_id'] ?? 0));

            try {
                if ($packId > 0) {
                    template_packs_set_global_default($pdo, $packId, null, (int) ($currentUser['id'] ?? 0) ?: null);
                    auditLog($pdo, 'settings_updated', 'Set global default template pack #' . $packId);
                } else {
                    template_packs_clear_global_default($pdo);
                    auditLog($pdo, 'settings_updated', 'Cleared global default template pack');
                }

                header('Location: /admin/settings.php?tab=general&global_pack_saved=1');
                exit;
            } catch (Throwable $exception) {
                $statusType = 'error';
                $statusMessage = $exception->getMessage();
            }
        } elseif ($section === 'publishing') {
            $activeTab = 'publishing';
            $updatedSettings = social_post_settings_defaults();
            foreach ($publishingDefinitions as $channelKey => $channel) {
                $postedChannel = isset($_POST['captions'][$channelKey]) && is_array($_POST['captions'][$channelKey])
                    ? $_POST['captions'][$channelKey]
                    : [];
                foreach ($channel['graphics'] as $graphicKey => $graphic) {
                    $submitted = isset($postedChannel[$graphicKey]) && is_string($postedChannel[$graphicKey])
                        ? $postedChannel[$graphicKey]
                        : '';
                    $updatedSettings[$channelKey][$graphicKey] = social_post_normalize_template($submitted, $graphic['default']);
                }
                foreach ($publishingPresetDefinitions[$channelKey] ?? [] as $graphicKey => $presetMap) {
                    foreach ($presetMap as $presetKey => $preset) {
                        $submitted = $_POST['presets'][$channelKey][$graphicKey][$presetKey]['caption'] ?? '';
                        $updatedSettings[$channelKey]['presets'][$graphicKey][$presetKey] = [
                            'label' => $preset['label'],
                            'caption' => social_post_normalize_template(is_string($submitted) ? $submitted : '', $preset['caption']),
                        ];
                    }
                }
            }

            $updatedPreferences = $publishingPreferences;
            $postedGlobal = isset($_POST['publishing_global']) && is_array($_POST['publishing_global']) ? $_POST['publishing_global'] : [];
            unset($updatedPreferences['global']['league_name']);
            foreach (['facebook', 'instagram', 'x'] as $channelKey) {
                $updatedPreferences['global'][$channelKey . '_hashtags'] = trim((string)($postedGlobal[$channelKey . '_hashtags'] ?? ''));
            }
            $updatedPreferences['global']['include_player_sponsor'] = !empty($postedGlobal['include_player_sponsor']);
            $updatedPreferences['global']['confirm_before_live_post'] = !empty($postedGlobal['confirm_before_live_post']);

            $postedVisuals = isset($_POST['publishing_visuals']) && is_array($_POST['publishing_visuals']) ? $_POST['publishing_visuals'] : [];
            foreach (['starting_xi', 'next_match', 'events'] as $visualType) {
                $source = isset($postedVisuals[$visualType]) && is_array($postedVisuals[$visualType]) ? $postedVisuals[$visualType] : [];
                $color = strtolower(trim((string)($source['gradient_color'] ?? '#000000')));
                $updatedPreferences['visuals'][$visualType] = [
                    'white_badges' => !empty($source['white_badges']),
                    'white_sponsor_logos' => !empty($source['white_sponsor_logos']),
                    'gradient_color' => preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : '#000000',
                    'gradient_strength' => max(0, min(100, (int)($source['gradient_strength'] ?? 70))),
                    'layout' => trim((string)($source['layout'] ?? ($visualType === 'starting_xi' ? 'list' : ($visualType === 'events' ? 'square' : 'portrait')))),
                ];
            }

            $postedAutomation = isset($_POST['publishing_automation']) && is_array($_POST['publishing_automation']) ? $_POST['publishing_automation'] : [];
            foreach (social_post_content_definitions('facebook') as $contentKey => $contentDefinition) {
                $source = isset($postedAutomation[$contentKey]) && is_array($postedAutomation[$contentKey]) ? $postedAutomation[$contentKey] : [];
                $mode = trim((string)($source['mode'] ?? 'confirm'));
                $priority = trim((string)($source['facebook_priority'] ?? 'low'));
                $updatedPreferences['automation'][$contentKey] = [
                    'mode' => in_array($mode, ['off', 'draft', 'confirm', 'auto'], true) ? $mode : 'confirm',
                    'minutes_before' => max(0, (int)($source['minutes_before'] ?? 0)),
                    'grace_seconds' => max(0, min(300, (int)($source['grace_seconds'] ?? 0))),
                    'facebook_priority' => in_array($priority, ['high', 'low'], true) ? $priority : 'low',
                    'facebook_enabled' => !empty($source['facebook_enabled']),
                    'x_enabled' => !empty($source['x_enabled']),
                    'facebook_include_sponsor' => !empty($source['facebook_include_sponsor']),
                ];
            }
            foreach (['facebook', 'instagram', 'x'] as $channelKey) {
                $updatedPreferences['platforms'][$channelKey]['enabled'] = !empty($_POST['publishing_platforms'][$channelKey]['enabled']);
            }

            $settingsSaved = social_post_settings_save($updatedSettings);
            $preferencesSaved = social_publishing_preferences_save($updatedPreferences);
            $saveOk = $settingsSaved && $preferencesSaved;

            if ($saveOk) {
                auditLog($pdo, 'settings_updated', 'Updated publishing settings');
                $preparedQuery = '';
                if (($_POST['publishing_action'] ?? '') === 'prepare_drafts') {
                    $draftResult = hub_publishing_prepare_match_drafts($pdo);
                    $preparedQuery = '&prepared=' . (int)$draftResult['prepared'];
                }
                header('Location: /admin/settings.php?tab=publishing&publishing_section=' . rawurlencode($publishingSection) . '&saved=1' . $preparedQuery);
                exit;
            }

            $publishingSettings = $updatedSettings;
            $publishingPreferences = $updatedPreferences;
            $statusType = 'error';
            $statusMessage = 'Publishing settings could not be saved.';
        }
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $statusType = 'success';
    if ($activeTab === 'publishing') {
        $statusMessage = 'Publishing settings saved.';
    } elseif ($activeTab === 'notifications') {
        $statusMessage = 'Email notification recipients updated.';
    } else {
        $statusMessage = 'Settings saved.';
    }
}
if (isset($_GET['global_pack_saved']) && $_GET['global_pack_saved'] === '1') {
    $statusType = 'success';
    $statusMessage = 'Global default template pack saved.';
}
if (isset($_GET['prepared']) && ctype_digit((string)$_GET['prepared'])) {
    $statusType = 'success';
    $statusMessage = (int)$_GET['prepared'] . ' scheduled match drafts prepared or refreshed.';
}
$appName = hub_settings_value($fileEnv, 'APP_NAME', 'Hub');
$baseUrl = hub_settings_value($fileEnv, 'BASE_URL', '/');
$errorLogPath = hub_settings_value($fileEnv, 'ERROR_LOG_PATH', '/var/www/vhosts/lundy.me.uk/logs/error_log');
$appDebug = hub_settings_value($fileEnv, 'HUB_APP_DEBUG', '1');

$dbHost = hub_settings_value($fileEnv, 'HUB_DB_HOST', 'localhost:3306');
$dbName = hub_settings_value($fileEnv, 'HUB_DB_NAME', 'Hub');
$dbFallbackName = hub_settings_value($fileEnv, 'HUB_DB_FALLBACK_NAME', 'PlayerSponsors');
$dbUser = hub_settings_value($fileEnv, 'HUB_DB_USER', 'PlayerSponsors');
$dbPass = hub_settings_value($fileEnv, 'HUB_DB_PASS', '');
$installSecret = hub_settings_value($fileEnv, 'HUB_INSTALL_SECRET', '');

$googleCalendarSyncEnabled = hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_SYNC_ENABLED', 'true');
$googleCalendarId = hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_ID', '');
$googleCalendarTimezone = hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_TIMEZONE', 'Europe/London');
$googleCalendarDuration = hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_EVENT_DURATION_MINUTES', '120');
$googleCalendarClientId = hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_CLIENT_ID', '');
$googleCalendarClientSecret = hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_CLIENT_SECRET', '');
$googleCalendarRefreshToken = hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_REFRESH_TOKEN', '');
$googleCalendarAccessToken = hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_ACCESS_TOKEN', '');

$stripeModeValue = hub_settings_value($fileEnv, 'STRIPE_MODE', 'test');
$stripeDefaultCurrency = hub_settings_value($fileEnv, 'STRIPE_DEFAULT_CURRENCY', 'gbp');
$stripeLinkExpiryHours = hub_settings_value($fileEnv, 'STRIPE_LINK_EXPIRY_HOURS', '24');
$stripePublicLinkExpiryDays = hub_settings_value($fileEnv, 'STRIPE_PUBLIC_LINK_EXPIRY_DAYS', '7');
$stripeSecretKeyConfigured = stripe_secret_key() !== '';
$stripeWebhookSecretConfigured = stripe_webhook_secret() !== '';
$stripeWebhookUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk') . '/stripe_webhook.php';
stripe_notification_seed_from_env($pdo, stripe_env(), (int) ($currentUser['id'] ?? 0) ?: null);
$orderNotificationManualEmails = settings_notification_manual_emails($pdo);
$orderNotificationAccountIds = settings_notification_account_ids($pdo);
$orderNotificationAccountIdSet = array_fill_keys($orderNotificationAccountIds, true);
$legacyPaymentNotificationEmails = array_filter([
    hub_settings_value($fileEnv, 'PAYMENT_NOTIFICATION_EMAILS', ''),
    hub_settings_value($fileEnv, 'STRIPE_NOTIFICATION_EMAILS', ''),
], static fn(string $value): bool => trim($value) !== '');
$resolvedPaymentNotificationEmails = stripe_notification_recipients($pdo);
$notificationAccountOptions = [];
try {
    if (stripe_table_exists($pdo, 'accounts') && stripe_table_exists($pdo, 'people')) {
        $notificationAccountOptions = $pdo->query("SELECT a.id, a.person_id, a.email AS account_email, a.is_active AS account_is_active,
                p.display_name, p.email AS profile_email, p.is_active AS person_is_active,
                GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS roles
            FROM accounts a
            JOIN people p ON p.id = a.person_id
            LEFT JOIN account_roles ar ON ar.account_id = a.id
            LEFT JOIN roles r ON r.id = ar.role_id
            WHERE a.is_active = 1 AND p.is_active = 1
            GROUP BY a.id, a.email, a.is_active, p.display_name, p.email, p.is_active
            ORDER BY p.display_name, a.email")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $notificationAccountOptions = [];
}
$notificationAccountOptionsById = [];
foreach ($notificationAccountOptions as $accountOption) {
    $notificationAccountOptionsById[(int) $accountOption['id']] = $accountOption;
}
$notificationDeliveryRows = [];
foreach ($orderNotificationAccountIds as $accountId) {
    $accountOption = $notificationAccountOptionsById[$accountId] ?? null;
    if (!$accountOption) {
        continue;
    }
    $profileEmail = trim((string) ($accountOption['profile_email'] ?? ''));
    $accountEmail = trim((string) ($accountOption['account_email'] ?? ''));
    $deliveryEmail = $profileEmail !== '' ? $profileEmail : $accountEmail;
    $notificationDeliveryRows[] = [
        'type' => 'account',
        'account_id' => $accountId,
        'person_id' => (int) ($accountOption['person_id'] ?? 0),
        'name' => (string) ($accountOption['display_name'] ?: 'Account #' . $accountId),
        'email' => $deliveryEmail,
        'source' => 'Hub user',
        'roles' => (string) ($accountOption['roles'] ?: 'No role'),
    ];
}
foreach ($orderNotificationManualEmails as $email) {
    $notificationDeliveryRows[] = [
        'type' => 'email',
        'account_id' => 0,
        'person_id' => 0,
        'name' => 'Manual recipient',
        'email' => $email,
        'source' => 'Additional email',
        'roles' => '',
    ];
}
if ($notificationDeliveryRows === [] && $resolvedPaymentNotificationEmails !== []) {
    foreach ($resolvedPaymentNotificationEmails as $email) {
        $notificationDeliveryRows[] = [
            'type' => 'fallback',
            'account_id' => 0,
            'person_id' => 0,
            'name' => 'Fallback admin recipient',
            'email' => (string) $email,
            'source' => 'Fallback',
            'roles' => 'Active admin/treasurer',
        ];
    }
}

$availableTemplatePacks = template_packs_list_available($pdo);
$globalTemplatePack = template_packs_get_global_default($pdo);

require_once __DIR__ . '/header.php';
?>

<div>
    <?php if ($statusMessage !== ''): ?>
        <div class="alert alert-<?= $statusType === 'success' ? 'success' : 'danger' ?> shadow-sm mb-4">
            <?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <ul class="nav nav-tabs reports-tabs flex-nowrap mb-4" id="settingsTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link<?= $activeTab === 'general' ? ' active' : '' ?>" href="?tab=general">General</a>
        </li>
        <li class="nav-item"><a class="nav-link<?= $activeTab === 'navigation' ? ' active' : '' ?>" href="?tab=navigation">Navigation</a></li>
        <li class="nav-item">
            <a class="nav-link<?= $activeTab === 'database' ? ' active' : '' ?>" href="?tab=database">Database</a>
        </li>
        <li class="nav-item">
            <a class="nav-link<?= $activeTab === 'calendar' ? ' active' : '' ?>" href="?tab=calendar">Google Calendar</a>
        </li>
        <li class="nav-item">
            <a class="nav-link<?= $activeTab === 'payments' ? ' active' : '' ?>" href="?tab=payments">Payments</a>
        </li>
        <li class="nav-item">
            <a class="nav-link<?= $activeTab === 'notifications' ? ' active' : '' ?>" href="?tab=notifications">Email Notifications</a>
        </li>
        <li class="nav-item">
            <a class="nav-link<?= $activeTab === 'publishing' ? ' active' : '' ?>" href="?tab=publishing">Publishing</a>
        </li>
    </ul>

    <div class="tab-content">
        <?php if ($activeTab === 'navigation'): ?>
            <?php require __DIR__ . '/partials/navigation_settings.php'; ?>
        <?php endif; ?>
        <?php if ($activeTab === 'general'): ?>
            <div class="tab-pane fade show active">
                <form method="post" class="settings-card hub-form-card">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="section" value="general">
                    <div class="settings-card__header">
                        <div>
                            <p class="settings-card__eyebrow">Core</p>
                            <h2 class="settings-card__title">General settings</h2>
                            <p class="settings-card__subtitle">Core values used across the Hub shell.</p>
                        </div>
                    </div>

                    <div class="settings-form-grid">
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="app_name">App name</label>
                            <input class="form-control" id="app_name" type="text" name="app_name" value="<?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="base_url">Base URL</label>
                            <input class="form-control" id="base_url" type="text" name="base_url" value="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="app_debug">Debug mode</label>
                            <select class="form-select" id="app_debug" name="app_debug">
                                <option value="1"<?= in_array(strtolower($appDebug), ['1', 'true', 'yes', 'on'], true) ? ' selected' : '' ?>>Enabled</option>
                                <option value="0"<?= in_array(strtolower($appDebug), ['0', 'false', 'no', 'off'], true) ? ' selected' : '' ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="error_log_path">Error log path</label>
                            <input class="form-control" id="error_log_path" type="text" name="error_log_path" value="<?= htmlspecialchars($errorLogPath, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>

                    <div class="settings-card__footer">
                        <button class="btn btn-primary" type="submit">Save General</button>
                    </div>
                </form>

                <form method="post" class="settings-card hub-form-card mt-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="section" value="global_template_pack">
                    <div class="settings-card__header">
                        <div>
                            <p class="settings-card__eyebrow">Graphic design system</p>
                            <h2 class="settings-card__title">Global default template pack</h2>
                            <p class="settings-card__subtitle">This pack is used when neither the season nor the fixture has its own selection.</p>
                        </div>
                    </div>

                    <div class="settings-field">
                        <label class="form-label fw-semibold" for="global_template_pack_id">Default pack</label>
                        <select class="form-select" id="global_template_pack_id" name="template_pack_id">
                            <option value="0">No global default</option>
                            <?php foreach ($availableTemplatePacks as $templatePack): ?>
                                <?php $packId = (int) ($templatePack['id'] ?? 0); ?>
                                <option value="<?= $packId ?>"<?= (int) ($globalTemplatePack['pack_id'] ?? 0) === $packId ? ' selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($templatePack['name'] ?? 'Untitled pack'), ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ((int) ($templatePack['current_version_number'] ?? 0) > 0): ?>
                                        · Version <?= (int) $templatePack['current_version_number'] ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Only published packs are available. New fixture assignments use the latest published version; fixtures already assigned to a version are not changed.</div>
                    </div>

                    <?php if ($availableTemplatePacks === []): ?>
                        <div class="alert alert-info mt-3 mb-0">
                            Publish a pack in <a class="alert-link" href="/admin/template_packs.php">Template Packs</a> before setting a default.
                        </div>
                    <?php endif; ?>

                    <div class="settings-card__footer">
                        <button class="btn btn-primary" type="submit">Save Global Default</button>
                    </div>
                </form>
            </div>
        <?php elseif ($activeTab === 'database'): ?>
            <div class="tab-pane fade show active">
                <form method="post" class="settings-card hub-form-card">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="section" value="database">
                    <div class="settings-card__header">
                        <div>
                            <p class="settings-card__eyebrow">Storage</p>
                            <h2 class="settings-card__title">Database settings</h2>
                            <p class="settings-card__subtitle">These values control the Hub connection in <code>config.php</code>.</p>
                        </div>
                    </div>

                    <div class="settings-form-grid">
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="db_host">DB host</label>
                            <input class="form-control" id="db_host" type="text" name="db_host" value="<?= htmlspecialchars($dbHost, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="db_name">DB name</label>
                            <input class="form-control" id="db_name" type="text" name="db_name" value="<?= htmlspecialchars($dbName, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="db_fallback_name">Fallback DB name</label>
                            <input class="form-control" id="db_fallback_name" type="text" name="db_fallback_name" value="<?= htmlspecialchars($dbFallbackName, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="db_user">DB user</label>
                            <input class="form-control" id="db_user" type="text" name="db_user" value="<?= htmlspecialchars($dbUser, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="db_pass">DB password</label>
                            <input class="form-control" id="db_pass" type="password" name="db_pass" value="" placeholder="Leave blank to keep current value">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="install_secret">Install secret</label>
                            <input class="form-control" id="install_secret" type="password" name="install_secret" value="" placeholder="Leave blank to keep current value">
                        </div>
                    </div>

                    <div class="settings-card__footer">
                        <button class="btn btn-primary" type="submit">Save Database</button>
                    </div>
                </form>
            </div>
        <?php elseif ($activeTab === 'calendar'): ?>
            <div class="tab-pane fade show active">
                <form method="post" class="settings-card hub-form-card">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="section" value="calendar">
                    <div class="settings-card__header">
                        <div>
                            <p class="settings-card__eyebrow">Sync</p>
                            <h2 class="settings-card__title">Google Calendar</h2>
                            <p class="settings-card__subtitle">When a fixture is saved in the Hub, these values control calendar sync.</p>
                        </div>
                    </div>

                    <div class="settings-note mb-4">
                        The sync helper will use a raw access token if you provide one, otherwise it will mint one from the refresh-token flow.
                    </div>

                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <a class="btn btn-outline-primary" href="/admin/google-callback.php">Open Google callback</a>
                    </div>

                    <div class="settings-form-grid">
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="google_calendar_sync_enabled">Sync enabled</label>
                            <select class="form-select" id="google_calendar_sync_enabled" name="google_calendar_sync_enabled">
                                <option value="true"<?= in_array(strtolower($googleCalendarSyncEnabled), ['1', 'true', 'yes', 'on'], true) ? ' selected' : '' ?>>Enabled</option>
                                <option value="false"<?= in_array(strtolower($googleCalendarSyncEnabled), ['0', 'false', 'no', 'off'], true) ? ' selected' : '' ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="google_calendar_id">Calendar ID</label>
                            <input class="form-control" id="google_calendar_id" type="text" name="google_calendar_id" value="<?= htmlspecialchars($googleCalendarId, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="google_calendar_timezone">Timezone</label>
                            <input class="form-control" id="google_calendar_timezone" type="text" name="google_calendar_timezone" value="<?= htmlspecialchars($googleCalendarTimezone, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="google_calendar_duration">Event duration minutes</label>
                            <input class="form-control" id="google_calendar_duration" type="number" min="1" name="google_calendar_duration" value="<?= htmlspecialchars($googleCalendarDuration, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="google_calendar_client_id">Client ID</label>
                            <input class="form-control" id="google_calendar_client_id" type="password" name="google_calendar_client_id" value="" placeholder="Leave blank to keep current value">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="google_calendar_client_secret">Client secret</label>
                            <input class="form-control" id="google_calendar_client_secret" type="password" name="google_calendar_client_secret" value="" placeholder="Leave blank to keep current value">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="google_calendar_refresh_token">Refresh token</label>
                            <input class="form-control" id="google_calendar_refresh_token" type="password" name="google_calendar_refresh_token" value="" placeholder="Leave blank to keep current value">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="google_calendar_access_token">Access token</label>
                            <input class="form-control" id="google_calendar_access_token" type="password" name="google_calendar_access_token" value="" placeholder="Leave blank to keep current value">
                        </div>
                    </div>

                    <div class="settings-card__footer">
                        <button class="btn btn-primary" type="submit">Save Calendar</button>
                    </div>
                </form>
            </div>
        <?php elseif ($activeTab === 'payments'): ?>
            <div class="tab-pane fade show active">
                <form method="post" class="settings-card hub-form-card">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="section" value="payments">
                    <div class="settings-card__header">
                        <div>
                            <p class="settings-card__eyebrow">Sponsorship</p>
                            <h2 class="settings-card__title">Payments (Stripe)</h2>
                            <p class="settings-card__subtitle">Lets you generate Stripe payment links for sponsorship agreements and have Stripe confirm payment automatically.</p>
                        </div>
                    </div>

                    <div class="settings-note mb-4">
                        <p class="mb-2"><strong>Secret key</strong> — from the Stripe Dashboard under Developers &gt; API keys. Use a test-mode key while trying this out, then switch mode to Live once you're ready to take real payments.</p>
                        <p class="mb-0"><strong>Webhook signing secret</strong> — create a webhook endpoint in Stripe pointing at <code><?= htmlspecialchars($stripeWebhookUrl, ENT_QUOTES, 'UTF-8') ?></code> listening for <code>checkout.session.completed</code>, <code>checkout.session.expired</code> and <code>charge.refunded</code>, then paste its signing secret here. Without this, payments won't be marked as paid automatically.</p>
                    </div>

                    <div class="settings-form-grid">
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="stripe_mode">Mode</label>
                            <select class="form-select" id="stripe_mode" name="stripe_mode">
                                <option value="test"<?= $stripeModeValue !== 'live' ? ' selected' : '' ?>>Test</option>
                                <option value="live"<?= $stripeModeValue === 'live' ? ' selected' : '' ?>>Live</option>
                            </select>
                            <div class="form-text">Informational — make sure the secret key below matches (test keys start with <code>sk_test_</code>, live keys with <code>sk_live_</code>).</div>
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="stripe_default_currency">Currency</label>
                            <input class="form-control" id="stripe_default_currency" type="text" name="stripe_default_currency" value="<?= htmlspecialchars($stripeDefaultCurrency, ENT_QUOTES, 'UTF-8') ?>" maxlength="10">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="stripe_public_link_expiry_days">Sponsor link expiry (days)</label>
                            <input class="form-control" id="stripe_public_link_expiry_days" type="number" min="1" max="30" name="stripe_public_link_expiry_days" value="<?= htmlspecialchars($stripePublicLinkExpiryDays, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="form-text">How long the Hub <code>/p/...</code> link remains usable. Default is 7 days.</div>
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="stripe_link_expiry_hours">Stripe session expiry (hours)</label>
                            <input class="form-control" id="stripe_link_expiry_hours" type="number" min="1" max="24" name="stripe_link_expiry_hours" value="<?= htmlspecialchars($stripeLinkExpiryHours, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="form-text">Maximum 24 hours — Stripe requires this. The sponsor link above can renew it automatically.</div>
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="stripe_secret_key">Secret key</label>
                            <input class="form-control" id="stripe_secret_key" type="password" name="stripe_secret_key" value="" placeholder="<?= $stripeSecretKeyConfigured ? 'Configured — leave blank to keep current value' : 'sk_test_…' ?>">
                        </div>
                        <div class="settings-field">
                            <label class="form-label fw-semibold" for="stripe_webhook_secret">Webhook signing secret</label>
                            <input class="form-control" id="stripe_webhook_secret" type="password" name="stripe_webhook_secret" value="" placeholder="<?= $stripeWebhookSecretConfigured ? 'Configured — leave blank to keep current value' : 'whsec_…' ?>">
                        </div>
                    </div>

                    <div class="settings-card__footer">
                        <button class="btn btn-primary" type="submit">Save Payments</button>
                    </div>
                </form>
            </div>
        <?php elseif ($activeTab === 'notifications'): ?>
            <div class="tab-pane fade show active">
                <div class="settings-card hub-form-card">
                    <div class="settings-card__header">
                        <div>
                            <p class="settings-card__eyebrow">Order alerts</p>
                            <h2 class="settings-card__title">Email notifications</h2>
                            <p class="settings-card__subtitle">Choose who receives an email when a Stripe order or payment is completed.</p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addNotificationUsersModal">
                                <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i>Add Users
                            </button>
                            <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#addNotificationEmailModal">
                                <i class="fa-solid fa-envelope me-1" aria-hidden="true"></i>Add Email
                            </button>
                        </div>
                    </div>

                    <div class="settings-note mb-4">
                        These emails are sent after Stripe confirms payment for season tickets, match tickets, shop orders, sponsorships, player sponsorship orders and fundraiser payments.
                    </div>

                    <?php if ($legacyPaymentNotificationEmails !== []): ?>
                        <div class="alert alert-info mt-3 mb-4">
                            Older notification keys are still present in the environment. They are used only as an import/fallback source; the editable delivery list is now stored in the database:
                            <code><?= htmlspecialchars(implode(', ', $legacyPaymentNotificationEmails), ENT_QUOTES, 'UTF-8') ?></code>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3 mb-3">
                        <div>
                            <p class="settings-card__eyebrow">Current delivery list</p>
                            <h3 class="settings-card__title h5 mb-1">Recipients</h3>
                            <p class="settings-card__subtitle mb-0">Hub users use the email saved on their profile. Additional emails are stored directly.</p>
                        </div>
                    </div>
                    <?php if ($notificationDeliveryRows === []): ?>
                        <div class="settings-note mb-0">No notification recipients are currently configured.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle hub-data-table mb-0">
                                <caption class="visually-hidden">Payment notification recipients</caption>
                                <thead><tr><th>Name</th><th>Email</th><th>Type</th><th>Role</th><th class="text-end">Actions</th></tr></thead>
                                <tbody>
                                    <?php foreach ($notificationDeliveryRows as $row): ?>
                                        <tr>
                                            <td class="fw-semibold"><?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars((string) $row['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><span class="badge text-bg-light"><?= htmlspecialchars((string) $row['source'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td class="text-muted"><?= htmlspecialchars((string) ($row['roles'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                            <td class="text-end">
                                                <div class="d-inline-flex gap-1">
                                                    <?php if ($row['type'] === 'account'): ?>
                                                        <a class="btn btn-sm btn-outline-secondary" href="/admin/club_person.php?id=<?= (int) $row['person_id'] ?>" title="Edit user profile" aria-label="Edit <?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                                        </a>
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="section" value="notifications">
                                                            <input type="hidden" name="notification_action" value="remove_account">
                                                            <input type="hidden" name="account_id" value="<?= (int) $row['account_id'] ?>">
                                                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Remove from notifications" aria-label="Remove <?= htmlspecialchars((string) $row['name'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                                            </button>
                                                        </form>
                                                    <?php elseif ($row['type'] === 'email'): ?>
                                                        <button class="btn btn-sm btn-outline-secondary" type="button" title="Edit email" aria-label="Edit <?= htmlspecialchars((string) $row['email'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#editNotificationEmailModal" data-email="<?= htmlspecialchars((string) $row['email'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                                        </button>
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="section" value="notifications">
                                                            <input type="hidden" name="notification_action" value="delete_email">
                                                            <input type="hidden" name="email" value="<?= htmlspecialchars((string) $row['email'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete email" aria-label="Delete <?= htmlspecialchars((string) $row['email'], ENT_QUOTES, 'UTF-8') ?>">
                                                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted small">Fallback</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="tab-pane fade show active">
                <form method="post" enctype="multipart/form-data" class="publishing-settings hub-form-card">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="section" value="publishing">
                    <input type="hidden" name="publishing_section" id="publishingSection" value="<?= htmlspecialchars($publishingSection, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="publishing-settings__nav" role="tablist" aria-label="Publishing settings">
                        <?php
                        $publishingNavigation = [
                            'overview' => ['Overview', 'fa-gauge-high'],
                            'templates' => ['Platforms & Templates', 'fa-share-nodes'],
                            'visuals' => ['Visual Defaults', 'fa-palette'],
                            'automation' => ['Automation', 'fa-bolt'],
                            'history' => ['History', 'fa-clock-rotate-left'],
                        ];
                        ?>
                        <?php foreach ($publishingNavigation as $sectionKey => [$sectionLabel, $sectionIcon]): ?>
                            <button class="publishing-settings__tab<?= $publishingSection === $sectionKey ? ' active' : '' ?>" type="button" role="tab" data-publishing-tab="<?= $sectionKey ?>" aria-selected="<?= $publishingSection === $sectionKey ? 'true' : 'false' ?>">
                                <i class="fa-solid <?= $sectionIcon ?>" aria-hidden="true"></i><span><?= $sectionLabel ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <section class="publishing-settings__panel" data-publishing-panel="overview"<?= $publishingSection === 'overview' ? '' : ' hidden' ?>>
                        <div class="settings-card mb-3">
                            <div class="settings-card__header"><div><p class="settings-card__eyebrow">Publishing control centre</p><h2 class="settings-card__title">Overview</h2><p class="settings-card__subtitle">Connection health, publishing activity, and the automation pipeline in one place.</p></div></div>
                            <div class="publishing-overview-grid">
                                <?php foreach ($publishingPlatformHealth as $platformKey => $health): ?>
                                    <article class="publishing-health-card hub-record-card">
                                        <div class="publishing-health-card__icon"><i class="fa-brands fa-<?= $platformKey === 'x' ? 'x-twitter' : $platformKey ?>"></i></div>
                                        <div><strong><?= htmlspecialchars((string)$health['label'], ENT_QUOTES, 'UTF-8') ?></strong><div class="small <?= $health['configured'] ? 'text-success' : 'text-danger' ?>"><?= htmlspecialchars((string)$health['status'], ENT_QUOTES, 'UTF-8') ?></div><div class="small text-muted"><?= htmlspecialchars((string)$health['last_activity'], ENT_QUOTES, 'UTF-8') ?></div></div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-3">
                                <?php hub_render_metric_grid([
                                    ['label' => 'Drafts', 'value' => (int)($publishingHistoryCounts['draft'] ?? 0), 'meta' => 'Awaiting review', 'icon' => 'fa-file-pen', 'tone' => 'neutral'],
                                    ['label' => 'Queued', 'value' => (int)($publishingHistoryCounts['queued'] ?? 0), 'meta' => 'Scheduled to publish', 'icon' => 'fa-clock', 'tone' => 'warning'],
                                    ['label' => 'Published', 'value' => (int)($publishingHistoryCounts['published'] ?? 0), 'meta' => 'Successfully posted', 'icon' => 'fa-circle-check', 'tone' => 'success'],
                                    ['label' => 'Failed', 'value' => (int)($publishingHistoryCounts['failed'] ?? 0), 'meta' => 'Needs attention', 'icon' => 'fa-triangle-exclamation', 'tone' => 'danger'],
                                ], 'Publishing activity'); ?>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-outline-primary" type="submit" name="publishing_action" value="prepare_drafts"><i class="fa-solid fa-wand-magic-sparkles me-2"></i>Prepare Scheduled Match Drafts Now</button></div>
                        </div>
                        <div class="settings-note">Templates now flow into Starting XI, Next Match, league-table, and live-event composers. Every composer remains editable before it posts.</div>
                    </section>

                    <section class="publishing-settings__panel" data-publishing-panel="visuals"<?= $publishingSection === 'visuals' ? '' : ' hidden' ?>>
                        <div class="settings-card">
                            <div class="settings-card__header"><div><p class="settings-card__eyebrow">Reusable design defaults</p><h2 class="settings-card__title">Visual defaults</h2><p class="settings-card__subtitle">These values are loaded automatically, but can still be changed for an individual fixture or event.</p></div></div>
                            <div class="publishing-visual-grid">
                                <?php foreach (['starting_xi' => 'Starting XI', 'next_match' => 'Next Match', 'events' => 'Match Events'] as $visualKey => $visualLabel): ?>
                                    <?php $visual = $publishingPreferences['visuals'][$visualKey] ?? []; ?>
                                    <article class="publishing-caption-card hub-record-card">
                                        <h3 class="h6 fw-bold mb-3"><?= $visualLabel ?></h3>
                                        <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="publishing_visuals[<?= $visualKey ?>][white_badges]" value="1" id="<?= $visualKey ?>WhiteBadges"<?= !empty($visual['white_badges']) ? ' checked' : '' ?>><label class="form-check-label" for="<?= $visualKey ?>WhiteBadges">Use white badges</label></div>
                                        <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="publishing_visuals[<?= $visualKey ?>][white_sponsor_logos]" value="1" id="<?= $visualKey ?>WhiteSponsors"<?= !empty($visual['white_sponsor_logos']) ? ' checked' : '' ?>><label class="form-check-label" for="<?= $visualKey ?>WhiteSponsors">Use white sponsor logos</label></div>
                                        <label class="form-label small fw-semibold">Gradient colour</label><input class="form-control form-control-color mb-3" type="color" name="publishing_visuals[<?= $visualKey ?>][gradient_color]" value="<?= htmlspecialchars((string)($visual['gradient_color'] ?? '#000000'), ENT_QUOTES, 'UTF-8') ?>">
                                        <label class="form-label small fw-semibold">Gradient strength</label><input class="form-range" type="range" min="0" max="100" step="5" name="publishing_visuals[<?= $visualKey ?>][gradient_strength]" value="<?= (int)($visual['gradient_strength'] ?? 70) ?>">
                                        <label class="form-label small fw-semibold">Default layout</label><select class="form-select" name="publishing_visuals[<?= $visualKey ?>][layout]">
                                            <?php $layoutOptions = $visualKey === 'starting_xi' ? ['list' => 'List', 'grid' => 'Grid'] : ($visualKey === 'events' ? ['square' => 'Square'] : ['portrait' => 'Portrait']); ?>
                                            <?php foreach ($layoutOptions as $layoutValue => $layoutLabel): ?><option value="<?= $layoutValue ?>"<?= ($visual['layout'] ?? '') === $layoutValue ? ' selected' : '' ?>><?= $layoutLabel ?></option><?php endforeach; ?>
                                        </select>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>

                    <section class="publishing-settings__panel" data-publishing-panel="automation"<?= $publishingSection === 'automation' ? '' : ' hidden' ?>>
                        <div class="settings-card">
                            <div class="settings-card__header"><div><p class="settings-card__eyebrow">Rules and safeguards</p><h2 class="settings-card__title">Automation</h2><p class="settings-card__subtitle">Choose how far each post type should progress automatically. Live posts retain a correction window.</p></div></div>
                            <p class="settings-note mb-3">Matchday publishing strategy: Facebook carries only the major stages (Matchday, Starting XI, Kick-off, Half-time, Second Half, Full-time). X is the live commentary feed and is eligible for every event by default. This is the one central place that decides platform eligibility — nothing else in Hub hard-codes it.</p>
                            <div class="table-responsive hub-table-card"><table class="table hub-data-table align-middle publishing-automation-table"><thead><tr><th>Post type</th><th>Mode</th><th>Minutes before</th><th>Grace seconds</th><th>Facebook priority</th><th class="text-center">Facebook</th><th class="text-center">X</th><th class="text-center">Sponsor credit</th></tr></thead><tbody>
                                <?php foreach (social_post_content_definitions('facebook') as $contentKey => $contentDefinition): ?>
                                    <?php $rule = $publishingPreferences['automation'][$contentKey] ?? []; ?>
                                    <?php $fbConfig = facebook_post_type_config($contentKey); ?>
                                    <tr><td class="fw-semibold"><?= htmlspecialchars($contentDefinition['label'], ENT_QUOTES, 'UTF-8') ?></td><td><select class="form-select" name="publishing_automation[<?= $contentKey ?>][mode]">
                                        <?php foreach (['off' => 'Off', 'draft' => 'Prepare draft', 'confirm' => 'Ask before publishing', 'auto' => 'Queue automatically (review required)'] as $modeValue => $modeLabel): ?><option value="<?= $modeValue ?>"<?= ($rule['mode'] ?? 'confirm') === $modeValue ? ' selected' : '' ?>><?= $modeLabel ?></option><?php endforeach; ?>
                                    </select></td><td><input class="form-control" type="number" min="0" name="publishing_automation[<?= $contentKey ?>][minutes_before]" value="<?= (int)($rule['minutes_before'] ?? 0) ?>"></td><td><input class="form-control" type="number" min="0" max="300" name="publishing_automation[<?= $contentKey ?>][grace_seconds]" value="<?= (int)($rule['grace_seconds'] ?? 0) ?>"></td>
                                    <td><select class="form-select" name="publishing_automation[<?= $contentKey ?>][facebook_priority]">
                                        <?php foreach (['high' => 'High — Facebook feed', 'low' => 'Low / optional'] as $priorityValue => $priorityLabel): ?><option value="<?= $priorityValue ?>"<?= $fbConfig['priority'] === $priorityValue ? ' selected' : '' ?>><?= $priorityLabel ?></option><?php endforeach; ?>
                                    </select></td>
                                    <td class="text-center"><div class="form-check form-switch d-flex justify-content-center m-0"><input class="form-check-input" type="checkbox" name="publishing_automation[<?= $contentKey ?>][facebook_enabled]" value="1"<?= $fbConfig['facebook_enabled'] ? ' checked' : '' ?>></div></td>
                                    <td class="text-center"><div class="form-check form-switch d-flex justify-content-center m-0"><input class="form-check-input" type="checkbox" name="publishing_automation[<?= $contentKey ?>][x_enabled]" value="1"<?= $fbConfig['x_enabled'] ? ' checked' : '' ?>></div></td>
                                    <td class="text-center"><div class="form-check form-switch d-flex justify-content-center m-0"><input class="form-check-input" type="checkbox" name="publishing_automation[<?= $contentKey ?>][facebook_include_sponsor]" value="1"<?= $fbConfig['facebook_include_sponsor'] ? ' checked' : '' ?>></div></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody></table></div>
                            <div class="settings-note mt-3">The scheduled worker prepares drafts and queues every ten minutes. It never sends a live post without someone opening the composer and reviewing it.</div>
                            <div class="settings-note mt-2">"Facebook"/"X" gate whether Hub will publish that event type to that platform at all (manual or automatic) — turning one off never removes the event from the live match console, timeline, statistics, or graphics. Administrators can still manually publish a Facebook-disabled event via "Post to Facebook Anyway" in the match console for an exceptional reason. "Sponsor credit" controls whether the fixture/matchday sponsor line is appended to that post type's Facebook and X captions.</div>
                        </div>
                    </section>

                    <section class="publishing-settings__panel" data-publishing-panel="templates"<?= $publishingSection === 'templates' ? '' : ' hidden' ?>>
                        <div class="settings-card mb-3">
                            <div class="settings-card__header">
                                <div>
                                    <p class="settings-card__eyebrow">Channels and reusable copy</p>
                                    <h2 class="settings-card__title">Platforms &amp; Templates</h2>
                                    <p class="settings-card__subtitle">Control where posts can be published, set each platform’s hashtags, and edit its captions in one workspace.</p>
                                </div>
                            </div>

                            <div class="publishing-template-global-grid">
                                <div class="publishing-template-safeguards">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="publishing_global[include_player_sponsor]" value="1" id="includePlayerSponsor"<?= !empty($publishingPreferences['global']['include_player_sponsor']) ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="includePlayerSponsor">Include player sponsor credit</label>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="publishing_global[confirm_before_live_post]" value="1" id="confirmLivePost"<?= !empty($publishingPreferences['global']['confirm_before_live_post']) ? ' checked' : '' ?>>
                                        <label class="form-check-label" for="confirmLivePost">Confirm before live publishing</label>
                                    </div>
                                </div>
                            </div>

                            <div class="settings-note mt-3">Competition names are inserted automatically from the fixture’s competition, or from the current season’s competition for league-table posts.</div>

                            <div class="publishing-channel-grid mt-4">
                                <?php foreach ($publishingDefinitions as $channelKey => $channel): ?>
                                    <?php $health = $publishingPlatformHealth[$channelKey] ?? ['configured' => false, 'status' => 'Not configured']; ?>
                                    <article class="publishing-channel-card hub-record-card">
                                        <div class="publishing-channel-card__header">
                                            <span class="publishing-health-card__icon"><i class="fa-brands fa-<?= $channelKey === 'x' ? 'x-twitter' : $channelKey ?>" aria-hidden="true"></i></span>
                                            <div class="flex-grow-1">
                                                <div class="form-check form-switch m-0">
                                                    <input class="form-check-input" type="checkbox" name="publishing_platforms[<?= $channelKey ?>][enabled]" value="1" id="<?= $channelKey ?>Enabled"<?= !empty($publishingPreferences['platforms'][$channelKey]['enabled']) ? ' checked' : '' ?>>
                                                    <label class="form-check-label fw-bold" for="<?= $channelKey ?>Enabled"><?= htmlspecialchars($channel['label'], ENT_QUOTES, 'UTF-8') ?></label>
                                                </div>
                                                <div class="small <?= !empty($health['configured']) ? 'text-success' : 'text-danger' ?>"><?= htmlspecialchars((string)$health['status'], ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                        </div>
                                        <label class="settings-field d-block mt-3">
                                            <span class="form-label small fw-semibold">Default hashtags</span>
                                            <textarea class="form-control" rows="3" name="publishing_global[<?= $channelKey ?>_hashtags]" placeholder="#SaltcoatsVictoria"><?= htmlspecialchars((string)($publishingPreferences['global'][$channelKey . '_hashtags'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                        </label>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="settings-note mb-3">Templates are grouped by post type so you can compare Facebook, Instagram and X side by side. Fixture placeholders include <strong>{Opponent}</strong>, <strong>{Home Team}</strong>, <strong>{Away Team}</strong>, <strong>{Competition}</strong>, <strong>{Competition Full}</strong>, <strong>{Match Date}</strong>, <strong>{Match Date Long}</strong>, <strong>{Kickoff Time}</strong>, <strong>{Kickoff Time 12h}</strong>, <strong>{Ground}</strong>, <strong>{Next Match Introduction}</strong>, <strong>{Admission Details}</strong>, <strong>{Match Call To Action}</strong>, and <strong>{Fixture Sponsor Credit}</strong>. Match-event templates can also use <strong>{Score}</strong>, <strong>{Scorer Credits}</strong>, <strong>{Minute}</strong>, <strong>{Player}</strong>, and <strong>{Sponsor Credit}</strong>.</div>
                        <?php foreach (social_post_content_definitions('facebook') as $graphicKey => $contentDefinition): ?>
                            <div class="settings-card mb-3">
                                <div class="settings-card__header">
                                    <div><p class="settings-card__eyebrow">Post template</p><h2 class="settings-card__title"><?= htmlspecialchars($contentDefinition['label'], ENT_QUOTES, 'UTF-8') ?></h2><p class="settings-card__subtitle">Compare and edit the copy for every platform.</p></div>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-copy-template="<?= htmlspecialchars($graphicKey, ENT_QUOTES, 'UTF-8') ?>">Copy Facebook to all</button>
                                </div>
                                <div class="publishing-template-platform-grid">
                                    <?php foreach ($publishingDefinitions as $channelKey => $channel): ?>
                                        <?php $graphic = $channel['graphics'][$graphicKey]; ?>
                                        <div class="publishing-caption-card">
                                            <label class="settings-field d-block"><span class="form-label fw-semibold d-flex justify-content-between"><span><?= htmlspecialchars($channel['label'], ENT_QUOTES, 'UTF-8') ?></span><small class="text-muted" data-character-count="<?= $channelKey ?>-<?= $graphicKey ?>"></small></span><textarea class="form-control" data-template-key="<?= $graphicKey ?>" data-template-channel="<?= $channelKey ?>" data-default-template="<?= htmlspecialchars($graphic['default'], ENT_QUOTES, 'UTF-8') ?>" name="captions[<?= $channelKey ?>][<?= $graphicKey ?>]" rows="5"><?= htmlspecialchars((string)($publishingSettings[$channelKey][$graphicKey] ?? $graphic['default']), ENT_QUOTES, 'UTF-8') ?></textarea></label>
                                            <button class="btn btn-link btn-sm px-0" type="button" data-reset-template="<?= $channelKey ?>-<?= $graphicKey ?>">Reset default</button>
                                            <?php $presetGroups = $publishingSettings[$channelKey]['presets'][$graphicKey] ?? ($publishingPresetDefinitions[$channelKey][$graphicKey] ?? []); ?>
                                            <?php if ($presetGroups !== []): ?><details class="publishing-presets"><summary class="publishing-presets__heading">Quick caption presets</summary><?php foreach ($presetGroups as $presetKey => $preset): ?><label class="settings-field d-block"><span class="form-label small fw-semibold d-block"><?= htmlspecialchars((string)$preset['label'], ENT_QUOTES, 'UTF-8') ?></span><textarea class="form-control" name="presets[<?= $channelKey ?>][<?= $graphicKey ?>][<?= htmlspecialchars((string)$presetKey, ENT_QUOTES, 'UTF-8') ?>][caption]" rows="2"><?= htmlspecialchars((string)($preset['caption'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></label><?php endforeach; ?></details><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </section>

                    <section class="publishing-settings__panel" data-publishing-panel="history"<?= $publishingSection === 'history' ? '' : ' hidden' ?>>
                        <div class="settings-card">
                            <div class="settings-card__header"><div><p class="settings-card__eyebrow">Audit and retries</p><h2 class="settings-card__title">Publishing history</h2><p class="settings-card__subtitle">Successful, failed, and blocked duplicate posts from the match workflow.</p></div></div>
                            <?php if ($publishingHistory === []): ?>
                                <div class="alert alert-info mb-0 hub-empty-state">No posts have been recorded since publishing history was enabled.</div>
                            <?php else: ?>
                                <div class="table-responsive hub-table-card"><table class="table hub-data-table align-middle"><thead><tr><th>Date</th><th>Type</th><th>Platform</th><th>Status</th><th>Fixture</th><th>Caption</th><th>Attempts</th></tr></thead><tbody>
                                    <?php foreach ($publishingHistory as $historyItem): ?>
                                        <?php $historyStatus = (string)$historyItem['status']; ?>
                                        <tr><td class="text-nowrap"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string)$historyItem['created_at'])), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$historyItem['post_type'])), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(ucfirst((string)$historyItem['platform']), ENT_QUOTES, 'UTF-8') ?></td><td><span class="badge <?= $historyStatus === 'published' ? 'text-bg-success' : ($historyStatus === 'failed' ? 'text-bg-danger' : 'text-bg-warning') ?>"><?= htmlspecialchars(ucfirst($historyStatus), ENT_QUOTES, 'UTF-8') ?></span></td><td><?= (int)($historyItem['fixture_id'] ?? 0) ?: '—' ?><?php if (!empty($historyItem['image_url'])): ?><div><a class="small fw-semibold" href="<?= htmlspecialchars((string)$historyItem['image_url'], ENT_QUOTES, 'UTF-8') ?>">Open draft</a></div><?php endif; ?></td><td><span class="publishing-history-caption" title="<?= htmlspecialchars((string)$historyItem['caption'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_strimwidth((string)$historyItem['caption'], 0, 90, '…'), ENT_QUOTES, 'UTF-8') ?></span><?php if (!empty($historyItem['error_message'])): ?><div class="small text-danger mt-1"><?= htmlspecialchars(mb_strimwidth((string)$historyItem['error_message'], 0, 120, '…'), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?></td><td><?= (int)$historyItem['attempts'] ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody></table></div>
                            <?php endif; ?>
                        </div>
                    </section>

                    <div class="publishing-settings__savebar">
                        <span>Changes apply to future social posts and generated graphics.</span>
                        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2" aria-hidden="true"></i>Save Publishing Settings</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($activeTab === 'notifications'): ?>
<div class="modal fade" id="addNotificationUsersModal" tabindex="-1" aria-labelledby="addNotificationUsersModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="section" value="notifications">
            <input type="hidden" name="notification_action" value="add_accounts">
            <div class="modal-header">
                <h2 class="modal-title h5" id="addNotificationUsersModalTitle">Add Users</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($notificationAccountOptions === []): ?>
                    <div class="settings-note mb-0">No active users are available.</div>
                <?php else: ?>
                    <div class="list-group" data-notification-user-picker>
                        <?php foreach ($notificationAccountOptions as $accountOption): ?>
                            <?php
                            $accountId = (int) $accountOption['id'];
                            $profileEmail = trim((string) ($accountOption['profile_email'] ?? ''));
                            $accountEmail = trim((string) ($accountOption['account_email'] ?? ''));
                            $deliveryEmail = $profileEmail !== '' ? $profileEmail : $accountEmail;
                            $hasValidEmail = filter_var($deliveryEmail, FILTER_VALIDATE_EMAIL);
                            $alreadySelected = isset($orderNotificationAccountIdSet[$accountId]);
                            ?>
                            <div class="list-group-item list-group-item-action d-flex flex-column flex-md-row justify-content-between gap-2<?= (!$hasValidEmail || $alreadySelected) ? ' disabled' : '' ?>" role="button" tabindex="<?= (!$hasValidEmail || $alreadySelected) ? '-1' : '0' ?>" data-notification-user-option>
                                <span>
                                    <strong><?= htmlspecialchars((string) ($accountOption['display_name'] ?: 'Account #' . $accountId), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span class="d-block small text-muted">
                                        <?= $hasValidEmail ? htmlspecialchars($deliveryEmail, ENT_QUOTES, 'UTF-8') : 'No valid email on profile or account' ?>
                                    </span>
                                </span>
                                <span class="d-flex align-items-center gap-2">
                                    <span class="badge text-bg-light"><?= $alreadySelected ? 'Already added' : htmlspecialchars((string) ($accountOption['roles'] ?: 'No role'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <i class="fa-solid fa-check text-success d-none" aria-hidden="true"></i>
                                </span>
                                <input class="visually-hidden" type="checkbox" name="account_ids[]" value="<?= $accountId ?>" tabindex="-1"<?= (!$hasValidEmail || $alreadySelected) ? ' disabled' : '' ?>>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Selected Users</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="addNotificationEmailModal" tabindex="-1" aria-labelledby="addNotificationEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="section" value="notifications">
            <input type="hidden" name="notification_action" value="add_email">
            <div class="modal-header">
                <h2 class="modal-title h5" id="addNotificationEmailModalTitle">Add Email Recipient</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold" for="notificationEmailAdd">Email address</label>
                <input class="form-control" id="notificationEmailAdd" type="email" name="email" autocomplete="email" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Email</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editNotificationEmailModal" tabindex="-1" aria-labelledby="editNotificationEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="section" value="notifications">
            <input type="hidden" name="notification_action" value="update_email">
            <input type="hidden" name="old_email" id="notificationEmailOld" value="">
            <div class="modal-header">
                <h2 class="modal-title h5" id="editNotificationEmailModalTitle">Edit Email Recipient</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold" for="notificationEmailEdit">Email address</label>
                <input class="form-control" id="notificationEmailEdit" type="email" name="email" autocomplete="email" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Email</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('[data-notification-user-option]').forEach(function (button) {
        function toggleUser() {
            if (button.classList.contains('disabled')) return;
            var input = button.querySelector('input[type="checkbox"]');
            var icon = button.querySelector('.fa-check');
            if (!input) return;
            input.checked = !input.checked;
            button.classList.toggle('active', input.checked);
            if (icon) icon.classList.toggle('d-none', !input.checked);
        }
        button.addEventListener('click', toggleUser);
        button.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggleUser();
            }
        });
    });

    var editModal = document.getElementById('editNotificationEmailModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            var trigger = event.relatedTarget;
            var email = trigger ? (trigger.getAttribute('data-email') || '') : '';
            var oldInput = document.getElementById('notificationEmailOld');
            var editInput = document.getElementById('notificationEmailEdit');
            if (oldInput) oldInput.value = email;
            if (editInput) editInput.value = email;
        });
    }
})();
</script>
<?php endif; ?>

<?php if ($activeTab === 'publishing'): ?>
<script>
(function () {
    var sectionInput = document.getElementById('publishingSection');
    var tabs = Array.prototype.slice.call(document.querySelectorAll('[data-publishing-tab]'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('[data-publishing-panel]'));
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var section = tab.getAttribute('data-publishing-tab');
            sectionInput.value = section;
            tabs.forEach(function (item) {
                var active = item === tab;
                item.classList.toggle('active', active);
                item.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(function (panel) {
                panel.hidden = panel.getAttribute('data-publishing-panel') !== section;
            });
            var url = new URL(window.location.href);
            url.searchParams.set('tab', 'publishing');
            url.searchParams.set('publishing_section', section);
            window.history.replaceState({}, '', url);
        });
    });

    function updateCharacterCount(textarea) {
        var key = textarea.getAttribute('data-template-channel') + '-' + textarea.getAttribute('data-template-key');
        var counter = document.querySelector('[data-character-count="' + key + '"]');
        if (counter) counter.textContent = textarea.value.length + ' characters';
    }
    document.querySelectorAll('[data-template-key]').forEach(function (textarea) {
        updateCharacterCount(textarea);
        textarea.addEventListener('input', function () { updateCharacterCount(textarea); });
    });
    document.querySelectorAll('[data-copy-template]').forEach(function (button) {
        button.addEventListener('click', function () {
            var key = button.getAttribute('data-copy-template');
            var source = document.querySelector('[data-template-key="' + key + '"][data-template-channel="facebook"]');
            if (!source) return;
            document.querySelectorAll('[data-template-key="' + key + '"]').forEach(function (textarea) {
                textarea.value = source.value;
                updateCharacterCount(textarea);
            });
        });
    });
    document.querySelectorAll('[data-reset-template]').forEach(function (button) {
        button.addEventListener('click', function () {
            var parts = button.getAttribute('data-reset-template').split('-');
            var channel = parts.shift();
            var key = parts.join('-');
            var textarea = document.querySelector('[data-template-key="' + key + '"][data-template-channel="' + channel + '"]');
            if (!textarea) return;
            textarea.value = textarea.getAttribute('data-default-template') || '';
            updateCharacterCount(textarea);
        });
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
