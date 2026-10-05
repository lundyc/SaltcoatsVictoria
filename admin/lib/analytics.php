<?php
declare(strict_types=1);

function hub_analytics_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $flagFile = __DIR__ . '/../cache/analytics_schema_ensured.flag';
    if (is_file($flagFile) && (time() - (int) @filemtime($flagFile)) <= 300) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS hub_analytics_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            session_key CHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            account_id BIGINT UNSIGNED NULL,
            person_id BIGINT UNSIGNED NULL,
            user_display_name VARCHAR(190) NOT NULL DEFAULT '',
            user_role VARCHAR(50) NOT NULL DEFAULT '',
            event_type VARCHAR(40) NOT NULL,
            page_path VARCHAR(255) NOT NULL,
            page_title VARCHAR(190) NOT NULL DEFAULT '',
            feature_label VARCHAR(190) NOT NULL DEFAULT '',
            element_selector VARCHAR(255) NOT NULL DEFAULT '',
            element_text VARCHAR(190) NOT NULL DEFAULT '',
            target_href VARCHAR(500) NOT NULL DEFAULT '',
            click_x DECIMAL(6,2) NULL,
            click_y DECIMAL(6,2) NULL,
            viewport_width INT UNSIGNED NULL,
            viewport_height INT UNSIGNED NULL,
            scroll_depth TINYINT UNSIGNED NULL,
            duration_ms INT UNSIGNED NULL,
            referrer VARCHAR(500) NOT NULL DEFAULT '',
            user_agent VARCHAR(500) NOT NULL DEFAULT '',
            ip_hash CHAR(64) NOT NULL DEFAULT '',
            meta_json JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY hub_analytics_created (created_at),
            KEY hub_analytics_page_type (page_path, event_type),
            KEY hub_analytics_event_type (event_type, created_at),
            KEY hub_analytics_session (session_key, created_at),
            KEY hub_analytics_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    @file_put_contents($flagFile, (string) time(), LOCK_EX);
}

function hub_analytics_session_key(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['hub_analytics_session_key']) || !is_string($_SESSION['hub_analytics_session_key'])) {
        $_SESSION['hub_analytics_session_key'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['hub_analytics_session_key'];
}

function hub_analytics_current_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $path = is_string($path) && $path !== '' ? $path : '/';
    return mb_substr($path, 0, 255);
}

function hub_analytics_client_ip_hash(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') {
        return '';
    }
    $salt = defined('INSTALL_SECRET') && INSTALL_SECRET !== '' ? INSTALL_SECRET : DB_NAME;
    return hash('sha256', $salt . '|' . $ip);
}

function hub_analytics_sanitize_path(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '/';
    }
    $parts = parse_url($path);
    if (isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== '') {
        $path = $parts['path'];
    }
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    return mb_substr($path, 0, 255);
}

function hub_analytics_string(mixed $value, int $maxLength): string
{
    if (!is_scalar($value)) {
        return '';
    }
    $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
    return mb_substr($text, 0, $maxLength);
}

function hub_analytics_int_or_null(mixed $value, int $min = 0, ?int $max = null): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $int = filter_var($value, FILTER_VALIDATE_INT);
    if ($int === false || $int < $min || ($max !== null && $int > $max)) {
        return null;
    }
    return (int) $int;
}

function hub_analytics_float_or_null(mixed $value, float $min = 0.0, float $max = 100.0): ?float
{
    if (!is_numeric($value)) {
        return null;
    }
    $float = (float) $value;
    if ($float < $min || $float > $max) {
        return null;
    }
    return round($float, 2);
}

function hub_analytics_record_event(PDO $pdo, array $event, ?array $user): void
{
    hub_analytics_ensure_schema($pdo);

    $allowedTypes = ['page_view', 'click', 'form_submit', 'page_summary'];
    $eventType = hub_analytics_string($event['event_type'] ?? '', 40);
    if (!in_array($eventType, $allowedTypes, true)) {
        return;
    }

    $meta = $event['meta'] ?? null;
    $metaJson = is_array($meta) && $meta !== [] ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    if ($metaJson !== null && strlen($metaJson) > 4000) {
        $metaJson = null;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO hub_analytics_events (
            session_key, user_id, account_id, person_id, user_display_name, user_role,
            event_type, page_path, page_title, feature_label, element_selector, element_text,
            target_href, click_x, click_y, viewport_width, viewport_height, scroll_depth,
            duration_ms, referrer, user_agent, ip_hash, meta_json
        ) VALUES (
            :session_key, :user_id, :account_id, :person_id, :user_display_name, :user_role,
            :event_type, :page_path, :page_title, :feature_label, :element_selector, :element_text,
            :target_href, :click_x, :click_y, :viewport_width, :viewport_height, :scroll_depth,
            :duration_ms, :referrer, :user_agent, :ip_hash, :meta_json
        )"
    );

    $stmt->execute([
        ':session_key' => hub_analytics_session_key(),
        ':user_id' => $user !== null ? (int) ($user['id'] ?? 0) ?: null : null,
        ':account_id' => $user !== null ? (int) ($user['account_id'] ?? 0) ?: null : null,
        ':person_id' => $user !== null ? (int) ($user['person_id'] ?? 0) ?: null : null,
        ':user_display_name' => $user !== null ? hub_analytics_string($user['display_name'] ?? $user['username'] ?? $user['email'] ?? '', 190) : '',
        ':user_role' => $user !== null ? hub_analytics_string($user['role'] ?? '', 50) : '',
        ':event_type' => $eventType,
        ':page_path' => hub_analytics_sanitize_path((string) ($event['page_path'] ?? hub_analytics_current_path())),
        ':page_title' => hub_analytics_string($event['page_title'] ?? '', 190),
        ':feature_label' => hub_analytics_string($event['feature_label'] ?? '', 190),
        ':element_selector' => hub_analytics_string($event['element_selector'] ?? '', 255),
        ':element_text' => hub_analytics_string($event['element_text'] ?? '', 190),
        ':target_href' => hub_analytics_string($event['target_href'] ?? '', 500),
        ':click_x' => hub_analytics_float_or_null($event['click_x'] ?? null),
        ':click_y' => hub_analytics_float_or_null($event['click_y'] ?? null),
        ':viewport_width' => hub_analytics_int_or_null($event['viewport_width'] ?? null, 1, 10000),
        ':viewport_height' => hub_analytics_int_or_null($event['viewport_height'] ?? null, 1, 10000),
        ':scroll_depth' => hub_analytics_int_or_null($event['scroll_depth'] ?? null, 0, 100),
        ':duration_ms' => hub_analytics_int_or_null($event['duration_ms'] ?? null, 0, 86400000),
        ':referrer' => hub_analytics_string($event['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? ''), 500),
        ':user_agent' => hub_analytics_string($_SERVER['HTTP_USER_AGENT'] ?? '', 500),
        ':ip_hash' => hub_analytics_client_ip_hash(),
        ':meta_json' => $metaJson,
    ]);
}

function hub_analytics_interval_days(mixed $value): int
{
    $days = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
    return $days === false ? 30 : (int) $days;
}

function hub_analytics_device_case_sql(): string
{
    return "CASE
        WHEN viewport_width IS NULL THEN 'Unknown'
        WHEN viewport_width < 768 THEN 'Mobile'
        WHEN viewport_width < 1100 THEN 'Tablet'
        ELSE 'Desktop'
    END";
}
