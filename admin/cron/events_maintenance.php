<?php
declare(strict_types=1);

/**
 * Events housekeeping. Optional: the same work also happens when event pages are visited, but running this every
 * 10 minutes makes abandoned-checkout reminders and waiting-list emails go out promptly even on a quiet site.
 *
 *   php /path/to/httpdocs/admin/cron/events_maintenance.php
 *
 * It releases unpaid holds, sends the one abandoned-checkout reminder (to people who asked for it), emails the waiting
 * list when tickets come back, and deletes access information and waiting-list entries 30 days after an event.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/events_followups.php';
$r = events_run_followups($pdo);
echo date('c'), ' holds released: ', $r['holds'], ', reminders sent: ', $r['reminders'], ', waiting-list emails: ', $r['waitlist'], ', old records deleted: ', $r['purged'], "\n";
