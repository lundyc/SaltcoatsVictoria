<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/settings_store.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}

$currentUser = hub_auth_current_user();
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Google Callback</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="/admin/assets/css/style.css" rel="stylesheet"></head><body class="hub-shell"><main class="hub-main"><div class="container-fluid"><div class="alert alert-danger mb-0">You do not have permission to manage Google Calendar sync.</div></div></main></body></html>';
    exit;
}

function hub_google_callback_request_token(string $clientId, string $clientSecret, string $code, string $redirectUri): array
{
    $ch = curl_init('https://oauth2.googleapis.com/token');
    if ($ch === false) {
        return ['ok' => false, 'message' => 'Unable to initialise the Google token request.'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ], '', '&', PHP_QUERY_RFC3986),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'SaltcoatsVictoriaHub/1.0',
    ]);

    $response = curl_exec($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $error !== '' || $httpStatus < 200 || $httpStatus >= 300) {
        $message = $error !== '' ? $error : ('Google token request failed with HTTP ' . $httpStatus);
        $decoded = is_string($response) ? json_decode($response, true) : null;
        if (is_array($decoded) && isset($decoded['error_description']) && is_string($decoded['error_description'])) {
            $message = $decoded['error_description'];
        } elseif (is_array($decoded) && isset($decoded['error']) && is_string($decoded['error'])) {
            $message = $decoded['error'];
        }

        return [
            'ok' => false,
            'message' => $message,
            'status' => $httpStatus,
            'body' => is_string($response) ? $response : '',
        ];
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => 'Google returned an invalid token response.'];
    }

    return [
        'ok' => true,
        'message' => 'Google token exchange succeeded.',
        'status' => $httpStatus,
        'body' => $decoded,
    ];
}

function hub_google_callback_current_url(): string
{
    $scheme = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $scheme = 'https';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk');
    return $scheme . '://' . $host . '/google-callback.php';
}

$fileEnv = hub_settings_load_env();
$statusType = '';
$statusMessage = '';
$exchangeData = null;
$callbackUrl = hub_google_callback_current_url();
$calendarScope = 'https://www.googleapis.com/auth/calendar.events';
$clientId = hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_CLIENT_ID', '');
$clientSecret = hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_CLIENT_SECRET', '');
$calendarId = hub_settings_value($fileEnv, 'GOOGLE_CALENDAR_ID', '');

if (isset($_GET['error']) && is_string($_GET['error']) && $_GET['error'] !== '') {
    $statusType = 'error';
    $statusMessage = 'Google returned an error: ' . trim((string) $_GET['error']);
    if (isset($_GET['error_description']) && is_string($_GET['error_description']) && $_GET['error_description'] !== '') {
        $statusMessage .= ' - ' . trim((string) $_GET['error_description']);
    }
}

if (isset($_GET['code']) && is_string($_GET['code']) && $_GET['code'] !== '') {
    $state = isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : null;
    if (!hub_auth_verify_csrf_token($state)) {
        $statusType = 'error';
        $statusMessage = 'Invalid OAuth state. Please start the Google connection flow again.';
    } elseif ($clientId === '' || $clientSecret === '') {
        $statusType = 'error';
        $statusMessage = 'Google Client ID and Client Secret must be saved before completing the callback.';
    } else {
        $exchangeData = hub_google_callback_request_token($clientId, $clientSecret, (string) $_GET['code'], $callbackUrl);
        if (!($exchangeData['ok'] ?? false)) {
            $statusType = 'error';
            $statusMessage = (string) ($exchangeData['message'] ?? 'Google token exchange failed.');
        } else {
            $tokenBody = is_array($exchangeData['body'] ?? null) ? (array) $exchangeData['body'] : [];
            $refreshToken = trim((string) ($tokenBody['refresh_token'] ?? ''));
            $accessToken = trim((string) ($tokenBody['access_token'] ?? ''));
            $expiresIn = (int) ($tokenBody['expires_in'] ?? 0);

            $updates = [
                'GOOGLE_CALENDAR_SYNC_ENABLED' => 'true',
                'GOOGLE_CALENDAR_CLIENT_ID' => $clientId,
                'GOOGLE_CALENDAR_CLIENT_SECRET' => $clientSecret,
                'GOOGLE_CALENDAR_ID' => $calendarId,
            ];

            if ($refreshToken !== '') {
                $updates['GOOGLE_CALENDAR_REFRESH_TOKEN'] = $refreshToken;
            }
            if ($accessToken !== '') {
                $updates['GOOGLE_CALENDAR_ACCESS_TOKEN'] = $accessToken;
            }

            $saved = hub_settings_save_env($updates);
            if (!$saved) {
                $statusType = 'error';
                $statusMessage = 'Google token exchange succeeded, but the Hub env file could not be updated.';
            } else {
                $statusType = 'success';
                $statusMessage = 'Google Calendar connected successfully.';
            }

            $tokenSummary = [];
            if ($refreshToken !== '') {
                $tokenSummary['Refresh token'] = $refreshToken;
            }
            if ($accessToken !== '') {
                $tokenSummary['Access token'] = $accessToken;
            }
            if ($expiresIn > 0) {
                $tokenSummary['Expires in'] = (string) $expiresIn . ' seconds';
            }
            $exchangeData['summary'] = $tokenSummary;
        }
    }
}

$stateToken = hub_auth_csrf_token();
$authorizeUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $callbackUrl,
    'response_type' => 'code',
    'scope' => $calendarScope,
    'access_type' => 'offline',
    'prompt' => 'consent',
    'include_granted_scopes' => 'true',
    'state' => $stateToken,
], '', '&', PHP_QUERY_RFC3986);

$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'Google Callback',
    'subtitle' => 'Use this page to complete the OAuth flow and store calendar tokens for Hub sync.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
?>

<div>
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/settings.php?tab=calendar">Settings</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Google Calendar connection</span></nav>
    <?php if ($statusMessage !== ''): ?>
        <div class="alert alert-<?= $statusType === 'success' ? 'success' : 'danger' ?> shadow-sm mb-4">
            <?= htmlspecialchars($statusMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="settings-card mb-4">
        <div class="settings-card__header">
            <div>
                <p class="settings-card__eyebrow">Connect</p>
                <h2 class="settings-card__title">Google OAuth</h2>
                <p class="settings-card__subtitle">Use the button below to start the consent flow. Google must be configured to redirect back to this exact URL: <code><?= htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8') ?></code></p>
            </div>
        </div>

        <div class="settings-form-grid settings-form-grid--single">
            <div class="settings-note">
                Required Google Cloud values already saved in Hub:
                <ul class="mb-0 mt-2">
                    <li>Client ID: <?= htmlspecialchars($clientId !== '' ? 'present' : 'missing', ENT_QUOTES, 'UTF-8') ?></li>
                    <li>Client Secret: <?= htmlspecialchars($clientSecret !== '' ? 'present' : 'missing', ENT_QUOTES, 'UTF-8') ?></li>
                    <li>Calendar ID: <?= htmlspecialchars($calendarId !== '' ? $calendarId : 'not set', ENT_QUOTES, 'UTF-8') ?></li>
                </ul>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-primary<?= $clientId === '' || $clientSecret === '' ? ' disabled' : '' ?>" href="<?= htmlspecialchars($authorizeUrl, ENT_QUOTES, 'UTF-8') ?>"<?= $clientId === '' || $clientSecret === '' ? ' aria-disabled="true" tabindex="-1"' : '' ?>>
                    Start Google connection
                </a>
                <a class="btn btn-outline-primary" href="/admin/settings.php?tab=calendar">Back to calendar settings</a>
            </div>
        </div>
    </div>

    <?php if (is_array($exchangeData) && !empty($exchangeData['summary'])): ?>
        <div class="settings-card">
            <div class="settings-card__header">
                <div>
                    <p class="settings-card__eyebrow">Result</p>
                    <h2 class="settings-card__title">Token exchange output</h2>
                    <p class="settings-card__subtitle">Store the refresh token, then keep using the same client id and secret for future syncs.</p>
                </div>
            </div>

            <div class="settings-form-grid settings-form-grid--single">
                <?php foreach ($exchangeData['summary'] as $label => $value): ?>
                    <div class="settings-field">
                        <label class="form-label fw-semibold"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></label>
                        <textarea class="form-control" rows="2" readonly><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="settings-note mt-4">
        If the connection succeeds, the callback will save the refresh token back into <code>hub/.env</code> automatically so fixture sync can use it.
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
