<?php
declare(strict_types=1);

require_once __DIR__ . '/schema_guard.php';

/**
 * Log of scanner problems (errors, session expiry, no signal, camera trouble,
 * unknown codes, slow scans). Ticket results themselves (valid / already scanned /
 * not found) stay in scan_logs; this table is for what went wrong around a scan.
 */
const SCAN_PROBLEM_KINDS = [
    'server_error'      => 'Server error',
    'not_logged_in'     => 'Not logged in',
    'no_permission'     => 'No permission',
    'session_expired'   => 'Session expired',
    'bad_request'       => 'Bad request',
    'unknown_code'      => 'Unknown code',
    'slow_scan'         => 'Slow scan',
    'network'           => 'No signal',
    'timeout'           => 'Timed out',
    'bad_response'      => 'Bad response',
    'camera_error'      => 'Camera error',
    'camera_unsupported' => 'Camera unsupported',
    'client_error'      => 'Page error',
    'wrong_day'         => 'Wrong day',
    'offline_duplicate' => 'Offline duplicate',
    'offline_sync'      => 'Offline sync',
];

function scan_problems_ensure_schema(PDO $pdo): void
{
    if (schema_guard_done('scan_problems')) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS scan_problems (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ref CHAR(10) NOT NULL,
        source VARCHAR(8) NOT NULL DEFAULT 'server',
        kind VARCHAR(30) NOT NULL,
        fixture_id INT UNSIGNED NULL,
        user_id INT UNSIGNED NULL,
        http_status SMALLINT UNSIGNED NULL,
        duration_ms INT UNSIGNED NULL,
        message VARCHAR(255) NOT NULL DEFAULT '',
        detail TEXT NULL,
        user_agent VARCHAR(255) NULL,
        ip VARCHAR(45) NULL,
        PRIMARY KEY (id),
        KEY idx_scan_problems_created (created_at),
        KEY idx_scan_problems_kind (kind, created_at),
        KEY idx_scan_problems_fixture (fixture_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    schema_guard_mark('scan_problems');
}

/**
 * Record a problem. Never throws: if the database is the thing that is broken,
 * the entry goes to logs/scan_problems.log instead. Returns a short reference
 * the steward can quote ("ref K3F9XQP2").
 *
 * @param array{fixture_id?:int,user_id?:int,http_status?:int,duration_ms?:int,detail?:string,source?:string} $ctx
 */
function scan_problem_log(?PDO $pdo, string $kind, string $message, array $ctx = []): string
{
    $ref = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
    $row = [
        'ref' => $ref,
        'source' => ($ctx['source'] ?? 'server') === 'client' ? 'client' : 'server',
        'kind' => substr($kind, 0, 30),
        'fixture_id' => isset($ctx['fixture_id']) && (int) $ctx['fixture_id'] > 0 ? (int) $ctx['fixture_id'] : null,
        'user_id' => isset($ctx['user_id']) && (int) $ctx['user_id'] > 0 ? (int) $ctx['user_id'] : null,
        'http_status' => $ctx['http_status'] ?? null,
        'duration_ms' => $ctx['duration_ms'] ?? null,
        'message' => mb_substr($message, 0, 255),
        'detail' => isset($ctx['detail']) ? mb_substr((string) $ctx['detail'], 0, 2000) : null,
        'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
    ];
    try {
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('no database');
        }
        scan_problems_ensure_schema($pdo);
        $pdo->prepare('INSERT INTO scan_problems (ref, source, kind, fixture_id, user_id, http_status, duration_ms, message, detail, user_agent, ip)
            VALUES (:ref, :source, :kind, :fixture_id, :user_id, :http_status, :duration_ms, :message, :detail, :user_agent, :ip)')->execute($row);
        if (random_int(1, 100) === 1) {
            $pdo->exec('DELETE FROM scan_problems WHERE created_at < (NOW() - INTERVAL 90 DAY)');
        }
    } catch (Throwable $e) {
        $dir = dirname(__DIR__, 2) . '/logs';
        @file_put_contents($dir . '/scan_problems.log', date('c') . ' ' . json_encode($row) . ' db_error=' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
    }
    return $ref;
}
