<?php

declare(strict_types=1);

/**
 * Scheduled WOSFL standings refresh — Wednesdays 21:00 and Saturdays 18:00,
 * UK time (see wosfl-table.php's comments for the AWS WAF session-warm-up
 * fix this relies on).
 *
 * Runs wosfl-table.php exactly as league_table.php already does on every
 * admin page view, just without a browser/session — it needs neither, and
 * writes the same cache/wosfl_table.json file both the admin table and the
 * public /table page read (lib/table.php). So this is the only file that
 * needs updating for both surfaces to pick up the new table.
 *
 * The server's cron daemon runs in UTC only and this cron package doesn't
 * support a per-job timezone (see `man 5 crontab`, LIMITATIONS — it
 * suggests exactly this pattern: run often, let the script check the real
 * local time). So the crontab fires this every 15 minutes, and the actual
 * update only runs when it's genuinely Wednesday 21:00 or Saturday 18:00 in
 * Europe/London — computed fresh each run, so it stays correct across the
 * BST/GMT change without the crontab itself needing touched twice a year.
 * Pass --force to bypass the time check (manual runs, testing).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$force = in_array('--force', $argv ?? [], true);

if (!$force) {
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/London'));
    $isWednesdayWindow = $now->format('N') === '3' && $now->format('H:i') === '21:00';
    $isSaturdayWindow = $now->format('N') === '6' && $now->format('H:i') === '18:00';

    if (!$isWednesdayWindow && !$isSaturdayWindow) {
        exit;
    }
}

require_once __DIR__ . '/wosfl-table.php';

$count = isset($teams) && is_array($teams) ? count($teams) : 0;
$source = $tableDataSource ?? 'unknown';
echo date('Y-m-d H:i:s') . ' — WOSFL table updated: ' . $count . ' teams (source: ' . $source . ')' . PHP_EOL;
