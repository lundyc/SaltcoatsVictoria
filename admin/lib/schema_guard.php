<?php
declare(strict_types=1);

/**
 * Run-once guard for the ensure*Schema() functions.
 *
 * Those functions issue dozens of CREATE/ALTER/SHOW statements, and a request-level
 * `static $done` only helps within one request. Every scan is a new request, so the
 * work repeated on each scan. The guard remembers (in a small flag file) that the
 * setup has already completed for the current version of the ticket libraries, and
 * skips it. Editing any of those library files changes the key, so the setup runs
 * again once after a code change. If the flag cannot be read or written, the setup
 * simply runs every time, as before.
 */
function schema_guard_key(string $name): string
{
    static $stamp = null;
    if ($stamp === null) {
        $stamp = '';
        foreach (['schema_guard', 'admissions', 'season_ticket_attendance', 'match_tickets', 'season_tickets', 'season_passes', 'scan_problems', 'events', 'events_orders', 'events_scan'] as $lib) {
            $stamp .= (string) @filemtime(__DIR__ . '/' . $lib . '.php') . '-';
        }
        $stamp = substr(sha1($stamp . (defined('DB_NAME') ? (string) DB_NAME : '')), 0, 16);
    }
    return preg_replace('/[^A-Za-z0-9_]/', '', $name) . '-' . $stamp;
}

function schema_guard_dir(): string
{
    return dirname(__DIR__, 2) . '/cache/schema';
}

function schema_guard_done(string $name): bool
{
    return is_file(schema_guard_dir() . '/' . schema_guard_key($name) . '.ok');
}

function schema_guard_mark(string $name): void
{
    $dir = schema_guard_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return;
    }
    @file_put_contents($dir . '/' . schema_guard_key($name) . '.ok', date('c'));
}
