<?php

declare(strict_types=1);

/**
 * Single shared entry point for everything that talks to the Facebook Graph
 * API from Hub. Every Facebook-publishing route (match events, league table,
 * next match, starting XI, monthly fixtures, sponsor shoutouts, and any
 * future automated post) must go through facebook_reserve_or_reject() before
 * generating/sending content, and facebook_send_photo_post() to actually
 * publish it.
 *
 * This file intentionally does NOT change the publishing mechanism itself:
 * every post is still a binary image upload to POST /{page-id}/photos with
 * published=false, followed by POST /{page-id}/feed with attached_media
 * referencing that photo. What this file adds is a single, atomic,
 * server-side dedupe gate (unique DB index, not a check-then-insert race),
 * shared HTTP/auth plumbing, structured logging, and post-type configuration
 * (priority / auto-publish / sponsor inclusion) instead of copies of this
 * logic living separately in each posting script.
 */

require_once __DIR__ . '/../social_post_settings.php';

const FACEBOOK_PUBLISH_LOG_FILE = __DIR__ . '/../logs/facebook_post.log';

/** @return array{page_id: string, page_access_token: string, app_secret: string, graph_api_version: string} */
function facebook_env(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $path = __DIR__ . '/../.env';
    $vars = [];
    if (is_file($path) && is_readable($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            $vars[$key] = $value;
        }
    }

    $graphApiVersion = trim((string) ($vars['GRAPH_API_VERSION'] ?? 'v24.0'));
    if (!preg_match('/^v\d+\.\d+$/', $graphApiVersion)) {
        $graphApiVersion = 'v24.0';
    }

    $cached = [
        'page_id' => trim((string) ($vars['PAGE_ID'] ?? '')),
        'page_access_token' => trim((string) ($vars['PAGE_ACCESS_TOKEN'] ?? '')),
        'app_secret' => trim((string) ($vars['APP_SECRET'] ?? '')),
        'graph_api_version' => $graphApiVersion,
    ];

    return $cached;
}

function facebook_log(string $level, string $message, array $context = [], string $logFile = FACEBOOK_PUBLISH_LOG_FILE): void
{
    $line = sprintf('[%s] %s %s', date('Y-m-d H:i:s'), strtoupper($level), $message);
    if ($context !== []) {
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            $line .= ' | ' . $json;
        }
    }
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

function facebook_sanitize_url_for_log(string $url): string
{
    $parts = parse_url($url);
    if ($parts === false) {
        return '[unparseable-url]';
    }

    $sanitized = '';
    if (isset($parts['scheme'])) {
        $sanitized .= $parts['scheme'] . '://';
    }
    if (isset($parts['host'])) {
        $sanitized .= $parts['host'];
    }
    if (isset($parts['port'])) {
        $sanitized .= ':' . $parts['port'];
    }
    $sanitized .= $parts['path'] ?? '';

    if (isset($parts['query'])) {
        parse_str($parts['query'], $queryParams);
        foreach (['access_token', 'input_token', 'appsecret_proof'] as $sensitiveKey) {
            if (array_key_exists($sensitiveKey, $queryParams)) {
                $queryParams[$sensitiveKey] = '[redacted]';
            }
        }
        $queryString = http_build_query($queryParams);
        if ($queryString !== '') {
            $sanitized .= '?' . $queryString;
        }
    }

    return $sanitized;
}

/**
 * Strip anything that looks like a credential out of a decoded Facebook
 * response before it is logged or persisted. Facebook does not normally echo
 * tokens back, but this is defence in depth for the diagnostics view.
 */
function facebook_redact_response(mixed $value): mixed
{
    if (is_array($value)) {
        $redacted = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/token|secret|proof/i', $key) === 1) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = facebook_redact_response($item);
        }
        return $redacted;
    }
    return $value;
}

/**
 * Low-level Graph API HTTP call. Never exits the process — every caller gets
 * a structured result back so this can be shared by web endpoints and CLI
 * scripts alike.
 *
 * @return array{ok: bool, status: int, body: array<string, mixed>|null, raw: string, error: array<string, mixed>|null, curl_error: string|null}
 */
function facebook_api_request(string $method, string $url, array $params, array $opts = []): array
{
    $timeoutSeconds = (int) ($opts['timeout'] ?? 60);
    $retryTransient = (bool) ($opts['retry_transient'] ?? false);
    $logFile = (string) ($opts['log_file'] ?? FACEBOOK_PUBLISH_LOG_FILE);

    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'raw' => '', 'error' => null, 'curl_error' => 'Failed to initialize cURL'];
    }

    $method = strtoupper($method);
    $curlOpts = [
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
        $curlOpts[CURLOPT_POSTFIELDS] = $params;
    }

    $curlOpts[CURLOPT_URL] = $url;
    curl_setopt_array($ch, $curlOpts);

    $rawBody = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrNo !== 0) {
        facebook_log('ERROR', 'cURL request failed', [
            'curl_errno' => $curlErrNo,
            'curl_error' => $curlError,
            'url' => facebook_sanitize_url_for_log($url),
        ], $logFile);
        return ['ok' => false, 'status' => $httpStatus, 'body' => null, 'raw' => (string) $rawBody, 'error' => null, 'curl_error' => $curlError];
    }

    if (!is_string($rawBody) || $rawBody === '') {
        facebook_log('ERROR', 'Facebook API returned empty response', [
            'http_status' => $httpStatus,
            'url' => facebook_sanitize_url_for_log($url),
        ], $logFile);
        return ['ok' => false, 'status' => $httpStatus, 'body' => null, 'raw' => '', 'error' => null, 'curl_error' => 'Empty response'];
    }

    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        facebook_log('ERROR', 'Facebook API returned non-JSON response', [
            'http_status' => $httpStatus,
            'url' => facebook_sanitize_url_for_log($url),
            'body' => $rawBody,
        ], $logFile);
        return ['ok' => false, 'status' => $httpStatus, 'body' => null, 'raw' => $rawBody, 'error' => null, 'curl_error' => 'Non-JSON response'];
    }

    if (isset($decoded['error']) && is_array($decoded['error'])) {
        $fbCode = isset($decoded['error']['code']) ? (int) $decoded['error']['code'] : 0;
        $fbSubcode = isset($decoded['error']['error_subcode']) ? (int) $decoded['error']['error_subcode'] : 0;
        $isTransient = ($decoded['error']['is_transient'] ?? false) === true
            || $httpStatus >= 500
            || in_array($fbCode, [1, 2, 4, 17, 341], true)
            || $fbSubcode === 99;

        if ($retryTransient && $isTransient) {
            facebook_log('WARN', 'Transient Facebook API error; retrying once', [
                'http_status' => $httpStatus,
                'error_code' => $fbCode,
                'error_subcode' => $fbSubcode,
                'url' => facebook_sanitize_url_for_log($url),
            ], $logFile);
            usleep(750000);
            $retryOpts = $opts;
            $retryOpts['retry_transient'] = false;
            return facebook_api_request($method, $url, $params, $retryOpts);
        }

        facebook_log($fbCode === 190 ? 'WARN' : 'ERROR', 'Facebook API error', [
            'http_status' => $httpStatus,
            'response_json' => $decoded,
            'url' => facebook_sanitize_url_for_log($url),
        ], $logFile);

        return ['ok' => false, 'status' => $httpStatus, 'body' => $decoded, 'raw' => $rawBody, 'error' => $decoded['error'], 'curl_error' => null];
    }

    return ['ok' => true, 'status' => $httpStatus, 'body' => $decoded, 'raw' => $rawBody, 'error' => null, 'curl_error' => null];
}

function facebook_has_permission(array $tokenData, string $permission): bool
{
    $permission = strtolower(trim($permission));

    foreach ((array) ($tokenData['scopes'] ?? []) as $scope) {
        if (is_string($scope) && strtolower($scope) === $permission) {
            return true;
        }
    }
    foreach ((array) ($tokenData['granular_scopes'] ?? []) as $scopeInfo) {
        if (is_array($scopeInfo) && strtolower((string) ($scopeInfo['scope'] ?? '')) === $permission) {
            return true;
        }
    }

    return false;
}

/**
 * Validate the configured token via /debug_token, exchanging a non-Page
 * token for the Page's own token via /me/accounts if necessary, and
 * confirming pages_manage_posts is granted. Consolidates the three
 * near-identical copies of this logic that previously lived in
 * post_to_facebook.php, post_event_to_facebook.php, and
 * post_sponsor_shoutout_to_facebook.php.
 *
 * @return array{ok: bool, token: string, proof: string, graph_base: string, page_id: string, error: string|null}
 */
function facebook_resolve_page_token(string $logFile = FACEBOOK_PUBLISH_LOG_FILE): array
{
    $env = facebook_env();
    if ($env['page_id'] === '' || $env['page_access_token'] === '' || $env['app_secret'] === '') {
        return ['ok' => false, 'token' => '', 'proof' => '', 'graph_base' => '', 'page_id' => '', 'error' => 'Facebook posting is not configured (missing PAGE_ID, PAGE_ACCESS_TOKEN, or APP_SECRET in .env).'];
    }

    $graphBase = 'https://graph.facebook.com/' . $env['graph_api_version'];
    $configuredProof = hash_hmac('sha256', $env['page_access_token'], $env['app_secret']);

    $debug = facebook_api_request('GET', $graphBase . '/debug_token', [
        'input_token' => $env['page_access_token'],
        'access_token' => $env['page_access_token'],
        'appsecret_proof' => $configuredProof,
    ], ['log_file' => $logFile]);

    $debugData = is_array($debug['body']['data'] ?? null) ? $debug['body']['data'] : [];
    if (!$debug['ok'] || $debugData === []) {
        $message = $debug['error']['message'] ?? 'Facebook could not validate the configured access token.';
        return ['ok' => false, 'token' => '', 'proof' => '', 'graph_base' => $graphBase, 'page_id' => $env['page_id'], 'error' => (string) $message];
    }
    if (($debugData['is_valid'] ?? true) === false) {
        return ['ok' => false, 'token' => '', 'proof' => '', 'graph_base' => $graphBase, 'page_id' => $env['page_id'], 'error' => 'Facebook access token is invalid or expired.'];
    }

    $resolvedToken = $env['page_access_token'];
    $configuredType = strtoupper(trim((string) ($debugData['type'] ?? '')));

    if ($configuredType !== 'PAGE') {
        facebook_log('INFO', 'Configured token is not a PAGE token; resolving via /me/accounts', [
            'token_type' => $configuredType !== '' ? $configuredType : 'UNKNOWN',
            'page_id' => $env['page_id'],
        ], $logFile);

        $accounts = facebook_api_request('GET', $graphBase . '/me/accounts', [
            'fields' => 'id,name,access_token',
            'access_token' => $env['page_access_token'],
            'appsecret_proof' => $configuredProof,
        ], ['log_file' => $logFile]);

        if (!$accounts['ok']) {
            $message = $accounts['error']['message'] ?? 'Facebook did not return the managed Pages list.';
            return ['ok' => false, 'token' => '', 'proof' => '', 'graph_base' => $graphBase, 'page_id' => $env['page_id'], 'error' => (string) $message];
        }

        $found = '';
        foreach ((array) ($accounts['body']['data'] ?? []) as $page) {
            if (is_array($page) && trim((string) ($page['id'] ?? '')) === $env['page_id']) {
                $found = trim((string) ($page['access_token'] ?? ''));
                break;
            }
        }
        if ($found === '') {
            return ['ok' => false, 'token' => '', 'proof' => '', 'graph_base' => $graphBase, 'page_id' => $env['page_id'], 'error' => 'Unable to resolve the Facebook Page access token for the configured PAGE_ID.'];
        }
        $resolvedToken = $found;
    }

    $resolvedProof = hash_hmac('sha256', $resolvedToken, $env['app_secret']);

    $resolvedDebug = facebook_api_request('GET', $graphBase . '/debug_token', [
        'input_token' => $resolvedToken,
        'access_token' => $resolvedToken,
        'appsecret_proof' => $resolvedProof,
    ], ['log_file' => $logFile]);
    $resolvedData = is_array($resolvedDebug['body']['data'] ?? null) ? $resolvedDebug['body']['data'] : [];

    if (!$resolvedDebug['ok'] || $resolvedData === []) {
        $message = $resolvedDebug['error']['message'] ?? 'Facebook could not validate the resolved Page token.';
        return ['ok' => false, 'token' => '', 'proof' => '', 'graph_base' => $graphBase, 'page_id' => $env['page_id'], 'error' => (string) $message];
    }
    if (($resolvedData['is_valid'] ?? true) === false) {
        return ['ok' => false, 'token' => '', 'proof' => '', 'graph_base' => $graphBase, 'page_id' => $env['page_id'], 'error' => 'Resolved Page token is invalid or expired.'];
    }
    if (!facebook_has_permission($resolvedData, 'pages_manage_posts')) {
        return ['ok' => false, 'token' => '', 'proof' => '', 'graph_base' => $graphBase, 'page_id' => $env['page_id'], 'error' => 'Resolved Page token is missing the pages_manage_posts permission.'];
    }

    facebook_log('INFO', 'Using PAGE token for upload', [
        'configured_token_type' => $configuredType !== '' ? $configuredType : 'UNKNOWN',
        'resolved_token_type' => strtoupper((string) ($resolvedData['type'] ?? '')),
        'page_id' => $env['page_id'],
    ], $logFile);

    return ['ok' => true, 'token' => $resolvedToken, 'proof' => $resolvedProof, 'graph_base' => $graphBase, 'page_id' => $env['page_id'], 'error' => null];
}

/**
 * THE single central platform/event configuration. Every route that decides
 * "should this post type go to Facebook / X automatically" reads this one
 * table (via facebook_post_type_config() below) — nothing else in the
 * codebase should hard-code per-type platform eligibility.
 *
 * Matchday publishing strategy: Facebook carries only the major stages of a
 * matchday (Matchday/next_match, Starting XI, Kick-off, Half-time, Second
 * Half, Full-time). X is the live commentary platform and is eligible for
 * everything, including goals/cards/subs/penalties/general updates that are
 * deliberately excluded from automatic Facebook publishing.
 *
 * `facebook_enabled` / `x_enabled` gate whether Hub will publish that post
 * type to that platform AUTOMATICALLY; they never remove the event from
 * Hub's own live match console, timeline, statistics, or graphics — see
 * facebook_post_type_config() doc-block for the manual-override escape
 * hatch on Facebook.
 * `facebook_include_sponsor` gates whether the fixture/player sponsor
 * credit lines are appended to the caption — applied to both Facebook and X
 * captions for that post type (see social_post_resolve_caption()).
 *
 * 'match_update' also covers event types with no dedicated template yet
 * (e.g. "injury") via event_share_build_text()'s fallback to match_update.
 *
 * @return array<string, array{priority: string, facebook_enabled: bool, x_enabled: bool, facebook_include_sponsor: bool}>
 */
function facebook_post_type_defaults(): array
{
    return [
        'league_table' => ['priority' => 'high', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => false],
        'next_match' => ['priority' => 'high', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => true], // advance announcement, posted days ahead
        'matchday' => ['priority' => 'high', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => true], // same-day reminder, posted matchday morning
        'monthly_fixtures' => ['priority' => 'high', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => false],
        'starting_xi' => ['priority' => 'high', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => true],
        'kickoff' => ['priority' => 'high', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => false],
        'goal' => ['priority' => 'low', 'facebook_enabled' => false, 'x_enabled' => true, 'facebook_include_sponsor' => false],
        'player_of_match' => ['priority' => 'high', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => true],
        'half_time' => ['priority' => 'high', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => true],
        'second_half' => ['priority' => 'high', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => false],
        'full_time' => ['priority' => 'high', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => true],
        'substitution' => ['priority' => 'low', 'facebook_enabled' => false, 'x_enabled' => true, 'facebook_include_sponsor' => false],
        'yellow_card' => ['priority' => 'low', 'facebook_enabled' => false, 'x_enabled' => true, 'facebook_include_sponsor' => false],
        'red_card' => ['priority' => 'low', 'facebook_enabled' => false, 'x_enabled' => true, 'facebook_include_sponsor' => false],
        'penalty' => ['priority' => 'low', 'facebook_enabled' => false, 'x_enabled' => true, 'facebook_include_sponsor' => false],
        'match_update' => ['priority' => 'low', 'facebook_enabled' => false, 'x_enabled' => true, 'facebook_include_sponsor' => false], // covers "injury" / general live events too
        'sponsor_shoutout' => ['priority' => 'high', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => true],
    ];
}

/**
 * Resolve the effective platform config for a post type: defaults above,
 * overridden by whatever an administrator has saved in
 * data/publishing_preferences.json (Settings → Publishing → Automation).
 *
 * Automatic publishing must always respect `facebook_enabled`/`x_enabled`.
 * The one deliberate exception is the Facebook manual override: an
 * authorised administrator can publish a single, otherwise-ineligible event
 * to Facebook via the "Post to Facebook Anyway" action in the match
 * console (see post_event_to_facebook.php's `override` handling) — that
 * bypasses this config check for that one request only, never the
 * deduplication gate.
 *
 * @return array{priority: string, facebook_enabled: bool, x_enabled: bool, facebook_include_sponsor: bool}
 */
function facebook_post_type_config(string $postType): array
{
    $defaults = facebook_post_type_defaults();
    $default = $defaults[$postType] ?? ['priority' => 'low', 'facebook_enabled' => true, 'x_enabled' => true, 'facebook_include_sponsor' => false];

    $preferences = social_publishing_preferences_load();
    $saved = $preferences['automation'][$postType] ?? [];

    return [
        'priority' => in_array($saved['facebook_priority'] ?? null, ['high', 'low'], true) ? $saved['facebook_priority'] : $default['priority'],
        'facebook_enabled' => array_key_exists('facebook_enabled', $saved) ? (bool) $saved['facebook_enabled'] : $default['facebook_enabled'],
        'x_enabled' => array_key_exists('x_enabled', $saved) ? (bool) $saved['x_enabled'] : $default['x_enabled'],
        'facebook_include_sponsor' => array_key_exists('facebook_include_sponsor', $saved) ? (bool) $saved['facebook_include_sponsor'] : $default['facebook_include_sponsor'],
    ];
}

/**
 * Build the deterministic content fingerprint used as the single dedupe key
 * for every Facebook publish across every route. Only non-empty fields are
 * hashed so routes that don't have a concept of e.g. "minute" (league table)
 * don't need to pass it.
 */
function facebook_build_fingerprint(array $fields): string
{
    $normalized = [];
    foreach ($fields as $key => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $normalized[(string) $key] = $value;
        }
    }
    ksort($normalized);

    return hash('sha256', json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
}

/**
 * Atomically reserve a fingerprint for publishing. Relies entirely on the
 * `social_posts_fingerprint_unique` DB index rather than a
 * check-then-insert, so two simultaneous requests for the same content
 * cannot both win: MySQL's unique index guarantees only one INSERT
 * succeeds, and the loser is told this is a duplicate. If a prior attempt
 * with the same fingerprint failed, this call safely reclaims that row for
 * a retry instead of blocking forever.
 *
 * @param array<string, mixed> $meta fixture_id, event_id, post_type, event_type, platform, page_id, caption, caption_hash, image_hash, is_automatic, is_override, created_by
 * @return array{ok: bool, duplicate: bool, id: int}
 */
function facebook_reserve(PDO $pdo, string $fingerprint, array $meta): array
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO social_posts
                (fixture_id, event_id, post_type, event_type, platform, page_id, caption, image_url, status, attempts,
                 dedupe_key, content_fingerprint, caption_hash, image_hash, is_automatic, is_override, created_by)
             VALUES
                (:fixture_id, :event_id, :post_type, :event_type, :platform, :page_id, :caption, :image_url, "publishing", 1,
                 :dedupe_key, :fingerprint, :caption_hash, :image_hash, :is_automatic, :is_override, :created_by)'
        );
        $stmt->execute([
            ':fixture_id' => (int) ($meta['fixture_id'] ?? 0) > 0 ? (int) $meta['fixture_id'] : null,
            ':event_id' => (string) ($meta['event_id'] ?? ''),
            ':post_type' => (string) ($meta['post_type'] ?? ''),
            ':event_type' => (string) ($meta['event_type'] ?? ($meta['post_type'] ?? '')),
            ':platform' => (string) ($meta['platform'] ?? 'facebook'),
            ':page_id' => (string) ($meta['page_id'] ?? ''),
            ':caption' => (string) ($meta['caption'] ?? ''),
            ':image_url' => (string) ($meta['image_url'] ?? ''),
            ':dedupe_key' => $fingerprint,
            ':fingerprint' => $fingerprint,
            ':caption_hash' => (string) ($meta['caption_hash'] ?? '') !== '' ? $meta['caption_hash'] : null,
            ':image_hash' => (string) ($meta['image_hash'] ?? '') !== '' ? $meta['image_hash'] : null,
            ':is_automatic' => !empty($meta['is_automatic']) ? 1 : 0,
            ':is_override' => !empty($meta['is_override']) ? 1 : 0,
            ':created_by' => (int) ($meta['created_by'] ?? 0) ?: null,
        ]);
        return ['ok' => true, 'duplicate' => false, 'id' => (int) $pdo->lastInsertId()];
    } catch (PDOException $e) {
        if ($e->getCode() !== '23000') {
            throw $e;
        }

        $existing = $pdo->prepare('SELECT id, status FROM social_posts WHERE content_fingerprint = :fp LIMIT 1');
        $existing->execute([':fp' => $fingerprint]);
        $row = $existing->fetch();
        if (!$row) {
            // Extremely unlikely (constraint hit but row not found, e.g. concurrent
            // delete): treat as a duplicate to be safe rather than double-post.
            return ['ok' => false, 'duplicate' => true, 'id' => 0];
        }

        if (in_array((string) $row['status'], ['publishing', 'published'], true)) {
            return ['ok' => false, 'duplicate' => true, 'id' => (int) $row['id']];
        }

        // A prior attempt with this exact content failed outright — reclaim it
        // atomically for a genuine retry rather than leaving it stuck.
        $reclaim = $pdo->prepare('UPDATE social_posts SET status = "publishing", attempts = attempts + 1, error_message = NULL, updated_at = NOW() WHERE id = :id AND status = "failed"');
        $reclaim->execute([':id' => $row['id']]);
        if ($reclaim->rowCount() === 1) {
            return ['ok' => true, 'duplicate' => false, 'id' => (int) $row['id']];
        }

        return ['ok' => false, 'duplicate' => true, 'id' => (int) $row['id']];
    }
}

/** @param array<string, mixed> $context */
function facebook_record_duplicate_attempt(PDO $pdo, int $matchedPostId, string $fingerprint, array $context = []): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO facebook_duplicate_attempts (matched_post_id, content_fingerprint, post_type, platform, attempted_by, context)
         VALUES (:matched_post_id, :fingerprint, :post_type, :platform, :attempted_by, :context)'
    );
    $stmt->execute([
        ':matched_post_id' => $matchedPostId,
        ':fingerprint' => $fingerprint,
        ':post_type' => (string) ($context['post_type'] ?? ''),
        ':platform' => (string) ($context['platform'] ?? 'facebook'),
        ':attempted_by' => (int) ($context['created_by'] ?? 0) ?: null,
        ':context' => json_encode(facebook_redact_response($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
    ]);

    facebook_log('WARN', 'Duplicate Facebook post prevented', [
        'matched_post_id' => $matchedPostId,
        'fingerprint' => $fingerprint,
        'post_type' => $context['post_type'] ?? '',
        'fixture_id' => $context['fixture_id'] ?? null,
        'event_id' => $context['event_id'] ?? '',
        'attempted_by' => $context['created_by'] ?? null,
    ]);
}

/**
 * Combined "build fingerprint, try to reserve it, log+record if it's a
 * duplicate" step used by every publishing route before any Facebook API
 * call or (where practical) expensive image generation is done.
 *
 * @param array<string, mixed> $fingerprintFields
 * @param array<string, mixed> $meta
 * @return array{ok: bool, duplicate: bool, id: int, fingerprint: string}
 */
function facebook_reserve_or_reject(PDO $pdo, array $fingerprintFields, array $meta): array
{
    $fingerprint = facebook_build_fingerprint($fingerprintFields);
    $meta['caption_hash'] = $meta['caption_hash'] ?? (isset($meta['caption']) ? hash('sha256', (string) $meta['caption']) : '');

    $result = facebook_reserve($pdo, $fingerprint, $meta);
    if ($result['duplicate']) {
        facebook_record_duplicate_attempt($pdo, $result['id'], $fingerprint, $meta);
    }

    return [
        'ok' => $result['ok'],
        'duplicate' => $result['duplicate'],
        'id' => $result['id'],
        'fingerprint' => $fingerprint,
    ];
}

function facebook_finish(PDO $pdo, int $id, bool $success, array $result = []): void
{
    facebook_set_status($pdo, $id, $success ? 'published' : 'failed', $result);
}

/**
 * Generic status-setter behind facebook_finish(). Facebook publishes are
 * always terminal 'published'/'failed' (a real API call succeeded or
 * didn't). X sharing today is a manual compose-intent handoff — Hub can
 * confirm it prepared the content, not that a human actually sent the
 * tweet — so it uses its own honest 'prepared' status via this same
 * reservation/status machinery rather than borrowing Facebook's semantics.
 *
 * @param array<string, mixed> $result post_id, media_id, image_hash, api_response, error
 */
function facebook_set_status(PDO $pdo, int $id, string $status, array $result = []): void
{
    $isTerminalSuccess = in_array($status, ['published', 'prepared'], true);
    $stmt = $pdo->prepare(
        'UPDATE social_posts
         SET status = :status,
             published_at = IF(:is_success = 1, NOW(), published_at),
             external_post_id = :external_id,
             media_id = :media_id,
             image_hash = COALESCE(:image_hash, image_hash),
             api_response = :api_response,
             error_message = :error
         WHERE id = :id'
    );
    $stmt->execute([
        ':status' => $status,
        ':is_success' => $isTerminalSuccess ? 1 : 0,
        ':external_id' => (string) ($result['post_id'] ?? ''),
        ':media_id' => (string) ($result['media_id'] ?? ''),
        ':image_hash' => (string) ($result['image_hash'] ?? '') !== '' ? $result['image_hash'] : null,
        ':api_response' => isset($result['api_response']) ? (json_encode(facebook_redact_response($result['api_response']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null) : null,
        ':error' => $isTerminalSuccess ? null : (string) ($result['error'] ?? 'Unknown error'),
        ':id' => $id,
    ]);
}

/**
 * The actual Graph API publish: stage an unpublished photo, attach it to a
 * feed post, and record the outcome against the already-reserved history
 * row. This is the ONLY function in the codebase that should call
 * POST /{page-id}/photos and POST /{page-id}/feed.
 *
 * @return array{ok: bool, post_id: string, media_id: string, error: string|null}
 */
function facebook_send_photo_post(PDO $pdo, int $historyId, string $imagePath, string $caption, string $logFile = FACEBOOK_PUBLISH_LOG_FILE): array
{
    if (!is_file($imagePath) || !is_readable($imagePath)) {
        facebook_finish($pdo, $historyId, false, ['error' => 'Generated image is missing or unreadable.']);
        return ['ok' => false, 'post_id' => '', 'media_id' => '', 'error' => 'Generated image is missing or unreadable.'];
    }
    $imageHash = hash_file('sha256', $imagePath) ?: '';

    $auth = facebook_resolve_page_token($logFile);
    if (!$auth['ok']) {
        facebook_finish($pdo, $historyId, false, ['error' => $auth['error'], 'image_hash' => $imageHash]);
        return ['ok' => false, 'post_id' => '', 'media_id' => '', 'error' => $auth['error']];
    }

    $photoResponse = facebook_api_request('POST', sprintf('%s/%s/photos', $auth['graph_base'], rawurlencode($auth['page_id'])), [
        'published' => 'false',
        'source' => new CURLFile($imagePath),
        'access_token' => $auth['token'],
        'appsecret_proof' => $auth['proof'],
    ], ['log_file' => $logFile, 'retry_transient' => true]);

    $mediaId = (string) ($photoResponse['body']['id'] ?? '');
    if (!$photoResponse['ok'] || $mediaId === '') {
        $error = $photoResponse['error']['message'] ?? 'Facebook did not return an uploaded photo ID.';
        facebook_finish($pdo, $historyId, false, ['error' => $error, 'api_response' => $photoResponse['body'], 'image_hash' => $imageHash]);
        return ['ok' => false, 'post_id' => '', 'media_id' => '', 'error' => (string) $error];
    }

    $feedResponse = facebook_api_request('POST', sprintf('%s/%s/feed', $auth['graph_base'], rawurlencode($auth['page_id'])), [
        'message' => $caption,
        'attached_media[0]' => json_encode(['media_fbid' => $mediaId], JSON_UNESCAPED_SLASHES),
        'access_token' => $auth['token'],
        'appsecret_proof' => $auth['proof'],
    ], ['log_file' => $logFile, 'retry_transient' => true]);

    $postId = (string) ($feedResponse['body']['id'] ?? '');
    if (!$feedResponse['ok'] || $postId === '') {
        // Don't leave an unpublished orphan photo behind when feed creation fails.
        facebook_api_request('DELETE', sprintf('%s/%s', $auth['graph_base'], rawurlencode($mediaId)), [
            'access_token' => $auth['token'],
            'appsecret_proof' => $auth['proof'],
        ], ['log_file' => $logFile]);

        $error = $feedResponse['error']['message'] ?? 'Facebook did not return a feed post ID.';
        facebook_finish($pdo, $historyId, false, ['error' => $error, 'media_id' => $mediaId, 'api_response' => $feedResponse['body'], 'image_hash' => $imageHash]);
        return ['ok' => false, 'post_id' => '', 'media_id' => $mediaId, 'error' => (string) $error];
    }

    facebook_finish($pdo, $historyId, true, [
        'post_id' => $postId,
        'media_id' => $mediaId,
        'api_response' => $feedResponse['body'],
        'image_hash' => $imageHash,
    ]);

    facebook_log('INFO', 'Facebook post successful', [
        'history_id' => $historyId,
        'post_id' => $postId,
        'media_id' => $mediaId,
    ], $logFile);

    return ['ok' => true, 'post_id' => $postId, 'media_id' => $mediaId, 'error' => null];
}

/**
 * Parse the structured `RESULT_JSON:{...}` line that post_to_facebook.php
 * (the CLI script) always prints as its last line of stdout, so callers that
 * exec() it (generate_and_post.php, post_next_match.php, post_starting_11.php,
 * post_monthly_fixtures.php) get a reliable structured result instead of
 * scraping free-text output for "post_id=".
 *
 * @param array<int, string> $lines
 * @return array<string, mixed>|null
 */
function facebook_parse_cli_result(array $lines): ?array
{
    foreach (array_reverse($lines) as $line) {
        if (str_starts_with($line, 'RESULT_JSON:')) {
            $decoded = json_decode(substr($line, strlen('RESULT_JSON:')), true);
            return is_array($decoded) ? $decoded : null;
        }
    }
    return null;
}

/**
 * Posting-frequency safeguard (task 6): not an attempt to model Facebook's
 * algorithm, just a guard rail against accidental bursts caused by bugs or
 * repeated operator clicks. Counts recently PUBLISHED Facebook posts
 * (successful, non-duplicate) in the trailing window.
 *
 * @return array{count: int, warn: bool, threshold: int, window_minutes: int}
 */
function facebook_burst_check(PDO $pdo, int $windowMinutes = 15, int $threshold = 5): array
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM social_posts
         WHERE platform = 'facebook' AND status = 'published'
           AND published_at >= (NOW() - INTERVAL :minutes MINUTE)"
    );
    $stmt->bindValue(':minutes', $windowMinutes, PDO::PARAM_INT);
    $stmt->execute();
    $count = (int) $stmt->fetchColumn();

    return [
        'count' => $count,
        'warn' => $count >= $threshold,
        'threshold' => $threshold,
        'window_minutes' => $windowMinutes,
    ];
}
