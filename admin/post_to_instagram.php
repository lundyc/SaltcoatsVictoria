<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/social_post_settings.php';
require_once __DIR__ . '/config.php';

$baseDir = __DIR__;
$envPath = $baseDir . '/.env';
$imagePath = $baseDir . '/export/latest_wosfl.png';
$publicImageUrl = APP_ORIGIN . '/admin/export/latest_wosfl.png';
$logDir = $baseDir . '/logs';
$logFile = $logDir . '/instagram_post.log';
$graphicType = 'league_table';
$matchId = '';
$captionOverride = '';

global $argv;
if (isset($argv[1]) && is_string($argv[1]) && trim($argv[1]) !== '') {
    $imagePath = trim($argv[1]);
}
if (isset($argv[2]) && is_string($argv[2]) && trim($argv[2]) !== '') {
    $publicImageUrl = trim($argv[2]);
}
if (isset($argv[3]) && is_string($argv[3]) && trim($argv[3]) !== '') {
    $graphicType = strtolower(trim($argv[3]));
}
if (isset($argv[4]) && is_string($argv[4]) && trim($argv[4]) !== '') {
    $matchId = trim($argv[4]);
}
if (isset($argv[5]) && is_string($argv[5])) {
    $captionOverride = trim($argv[5]);
}

if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
    exit(1);
}

function logMessage(string $logFile, string $level, string $message, array $context = []): void
{
    $timestamp = date('Y-m-d H:i:s');
    $line = sprintf('[%s] %s %s', $timestamp, strtoupper($level), $message);

    if ($context !== []) {
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            $line .= ' | ' . $json;
        }
    }

    file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

function fail(string $logFile, string $message, array $context = [], int $exitCode = 1): void
{
    logMessage($logFile, 'ERROR', $message, $context);
    exit($exitCode);
}

function parseEnvFile(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }

    $vars = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $vars[$key] = $value;
    }

    return $vars;
}

function hasPermission(array $tokenData, string $permission): bool
{
    $permission = strtolower(trim($permission));

    if (isset($tokenData['scopes']) && is_array($tokenData['scopes'])) {
        foreach ($tokenData['scopes'] as $scope) {
            if (is_string($scope) && strtolower($scope) === $permission) {
                return true;
            }
        }
    }

    if (isset($tokenData['granular_scopes']) && is_array($tokenData['granular_scopes'])) {
        foreach ($tokenData['granular_scopes'] as $scopeInfo) {
            if (!is_array($scopeInfo)) {
                continue;
            }

            $scopeName = isset($scopeInfo['scope']) && is_string($scopeInfo['scope']) ? strtolower($scopeInfo['scope']) : '';
            if ($scopeName === $permission) {
                return true;
            }
        }
    }

    return false;
}

function igApiRequest(
    string $method,
    string $url,
    array $params,
    string $logFile,
    int $timeoutSeconds = 60
): array {
    $ch = curl_init();
    if ($ch === false) {
        fail($logFile, 'Failed to initialize cURL');
    }

    $method = strtoupper($method);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Expect:'],
    ];

    if ($method === 'GET') {
        $query = http_build_query($params);
        if ($query !== '') {
            $url .= (str_contains($url, '?') ? '&' : '?') . $query;
        }
    } else {
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }

    $opts[CURLOPT_URL] = $url;
    curl_setopt_array($ch, $opts);

    $rawBody = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrNo !== 0) {
        fail($logFile, 'cURL request failed', [
            'curl_errno' => $curlErrNo,
            'curl_error' => $curlError,
            'url' => $url,
        ]);
    }

    if (!is_string($rawBody) || $rawBody === '') {
        fail($logFile, 'Instagram API returned empty response', [
            'http_status' => $httpStatus,
            'url' => $url,
        ]);
    }

    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        fail($logFile, 'Instagram API returned non-JSON response', [
            'http_status' => $httpStatus,
            'url' => $url,
            'body' => $rawBody,
        ]);
    }

    if (isset($decoded['error']) && is_array($decoded['error'])) {
        $fbType = isset($decoded['error']['type']) ? (string) $decoded['error']['type'] : '';
        $fbCode = isset($decoded['error']['code']) ? (int) $decoded['error']['code'] : 0;
        $fbMessage = isset($decoded['error']['message']) ? (string) $decoded['error']['message'] : 'Unknown Instagram API error';
        $level = $fbCode === 190 ? 'WARN' : 'ERROR';

        logMessage($logFile, $level, 'Instagram API error', [
            'http_status' => $httpStatus,
            'error_type' => $fbType,
            'error_code' => $fbCode,
            'error_message' => $fbMessage,
            'response_json' => $decoded,
            'url' => $url,
        ]);

        exit(1);
    }

    return [
        'status' => $httpStatus,
        'body' => $decoded,
        'raw' => $rawBody,
    ];
}

if (!is_file($envPath)) {
    fail($logFile, '.env file missing', ['path' => $envPath]);
}

$env = parseEnvFile($envPath);
if ($env === []) {
    fail($logFile, '.env could not be parsed or is empty', ['path' => $envPath]);
}

$instagramAccountId = trim((string) ($env['IG_BUSINESS_ACCOUNT_ID'] ?? ''));
$accessToken = trim((string) ($env['IG_ACCESS_TOKEN'] ?? ($env['PAGE_ACCESS_TOKEN'] ?? '')));
$appSecret = trim((string) ($env['APP_SECRET'] ?? ''));
$graphApiVersion = trim((string) ($env['GRAPH_API_VERSION'] ?? 'v24.0'));

if ($instagramAccountId === '' || $accessToken === '' || $appSecret === '') {
    fail($logFile, 'Missing required .env keys', [
        'required' => ['IG_BUSINESS_ACCOUNT_ID', 'IG_ACCESS_TOKEN or PAGE_ACCESS_TOKEN', 'APP_SECRET'],
    ]);
}

if (!preg_match('/^v\d+\.\d+$/', $graphApiVersion)) {
    fail($logFile, 'Invalid GRAPH_API_VERSION format', [
        'graph_api_version' => $graphApiVersion,
        'expected' => 'v24.0',
    ]);
}

if (!is_file($imagePath) || !is_readable($imagePath)) {
    fail($logFile, 'Image missing or unreadable', ['path' => $imagePath]);
}

$graphBase = sprintf('https://graph.facebook.com/%s', $graphApiVersion);
$appsecretProof = hash_hmac('sha256', $accessToken, $appSecret);

$debugResponse = igApiRequest(
    'GET',
    $graphBase . '/debug_token',
    [
        'input_token' => $accessToken,
        'access_token' => $accessToken,
        'appsecret_proof' => $appsecretProof,
    ],
    $logFile
);

$debugData = isset($debugResponse['body']['data']) && is_array($debugResponse['body']['data'])
    ? $debugResponse['body']['data']
    : [];

if ($debugData === []) {
    fail($logFile, 'Missing token data from /debug_token', [
        'http_status' => $debugResponse['status'],
        'response_json' => $debugResponse['body'],
    ]);
}

if (isset($debugData['is_valid']) && $debugData['is_valid'] === false) {
    fail($logFile, 'Token is invalid according to /debug_token', [
        'http_status' => $debugResponse['status'],
        'token_data' => $debugData,
    ]);
}

if (!hasPermission($debugData, 'instagram_content_publish')) {
    fail($logFile, 'Required permission missing: instagram_content_publish', [
        'http_status' => $debugResponse['status'],
        'token_data' => $debugData,
    ]);
}

$match = null;
if ($graphicType === 'match' && $matchId !== '') {
    $match = matches_find_by_id(matches_load_all(), $matchId);
}

$caption = social_post_resolve_caption_with_override('instagram', $graphicType, $match, $captionOverride);

$creationResponse = igApiRequest(
    'POST',
    sprintf('%s/%s/media', $graphBase, rawurlencode($instagramAccountId)),
    [
        'image_url' => $publicImageUrl,
        'caption' => $caption,
        'access_token' => $accessToken,
        'appsecret_proof' => $appsecretProof,
    ],
    $logFile
);

$creationBody = $creationResponse['body'];
$creationId = isset($creationBody['id']) ? (string) $creationBody['id'] : '';

if ($creationId === '') {
    fail($logFile, 'Instagram media creation response missing id', [
        'http_status' => $creationResponse['status'],
        'response_json' => $creationBody,
    ]);
}

// Instagram processes image containers asynchronously. Wait until the media is
// ready instead of immediately calling media_publish and failing transiently.
$containerReady = false;
for ($attempt = 1; $attempt <= 8; $attempt++) {
    $statusResponse = igApiRequest(
        'GET',
        sprintf('%s/%s', $graphBase, rawurlencode($creationId)),
        [
            'fields' => 'status_code,status',
            'access_token' => $accessToken,
            'appsecret_proof' => $appsecretProof,
        ],
        $logFile
    );
    $statusCode = strtoupper(trim((string)($statusResponse['body']['status_code'] ?? '')));
    if ($statusCode === 'FINISHED') {
        $containerReady = true;
        break;
    }
    if (in_array($statusCode, ['ERROR', 'EXPIRED'], true)) {
        fail($logFile, 'Instagram media container could not be processed', [
            'creation_id' => $creationId,
            'status_code' => $statusCode,
            'response_json' => $statusResponse['body'],
        ]);
    }
    if ($attempt < 8) {
        usleep(1500000);
    }
}

if (!$containerReady) {
    fail($logFile, 'Instagram media container was not ready in time', ['creation_id' => $creationId]);
}

$publishResponse = igApiRequest(
    'POST',
    sprintf('%s/%s/media_publish', $graphBase, rawurlencode($instagramAccountId)),
    [
        'creation_id' => $creationId,
        'access_token' => $accessToken,
        'appsecret_proof' => $appsecretProof,
    ],
    $logFile
);

$publishBody = $publishResponse['body'];
$publishId = isset($publishBody['id']) ? (string) $publishBody['id'] : '';

if ($publishId === '') {
    fail($logFile, 'Instagram publish response missing id', [
        'http_status' => $publishResponse['status'],
        'response_json' => $publishBody,
    ]);
}

logMessage($logFile, 'INFO', 'Instagram post successful', [
    'http_status' => $publishResponse['status'],
    'creation_id' => $creationId,
    'publish_id' => $publishId,
    'ig_business_account_id' => $instagramAccountId,
    'graph_api_version' => $graphApiVersion,
    'image_url' => $publicImageUrl,
    'graphic_type' => $graphicType,
    'match_id' => $matchId,
    'caption' => $caption,
]);

echo "SUCCESS instagram_media_id={$publishId}\n";
exit(0);
