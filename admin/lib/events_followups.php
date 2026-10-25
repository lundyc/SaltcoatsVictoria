<?php
declare(strict_types=1);

/**
 * Events follow-up emails.
 *
 *  - Abandoned checkout: ONE reminder to a buyer who ticked "remind me" but did not finish paying.
 *  - Waiting list: when a sold-out event has tickets again (a hold lapsed, an order was refunded), the next people
 *    on the list are emailed, first come first served, never more people than there are tickets.
 *
 * Both are opt-in (the buyer ticks a box / joins the list), one email each, and carry a way to stop.
 * Nothing here needs a scheduler: it runs when event pages are visited, and `admin/cron/events_maintenance.php`
 * can run it on a timer as well. Sending goes through $sendFn so it can be tested without email.
 */

require_once __DIR__ . '/events_orders.php';
require_once __DIR__ . '/events_mail.php';

/** How long a notified waiting-list person has to buy before their tickets go to the next in line. */
const EVENT_WAITLIST_CLAIM_HOURS = 3;
/** Abandoned checkout: remind this long after the hold ran out, and never later than this. */
const EVENT_REMINDER_AFTER_MINUTES = 60;
const EVENT_REMINDER_MAX_AGE_HOURS = 36;
const EVENT_WAITLIST_MAX_QTY = 6;

function ensureEventFollowupsSchema(PDO $pdo): void
{
    ensureEventOrdersSchema($pdo);
    if (schema_guard_done('event_followups')) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_order_reminders (
        order_id INT UNSIGNED NOT NULL,
        sent_at DATETIME NULL,
        skipped TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (order_id),
        CONSTRAINT fk_event_reminders_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS event_waitlist (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id INT UNSIGNED NOT NULL,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(190) NOT NULL,
        qty TINYINT UNSIGNED NOT NULL DEFAULT 1,
        token CHAR(32) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'waiting',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notified_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_event_waitlist_token (token),
        KEY idx_event_waitlist_event (event_id, status, created_at),
        CONSTRAINT fk_event_waitlist_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    schema_guard_mark('event_followups');
}

function events_public_url(array $event): string
{
    $base = function_exists('stripe_public_base_url') ? stripe_public_base_url() : 'https://saltcoatsvictoria.co.uk';
    return rtrim($base, '/') . '/events/' . rawurlencode((string) $event['slug']);
}

/** @param null|callable(string,string,string,bool,?string):bool $sendFn */
function events_followup_send(?callable $sendFn, string $to, string $subject, string $html, ?string $replyTo): bool
{
    try {
        return (bool) ($sendFn ? $sendFn($to, $subject, $html, true, $replyTo) : hub_send_mail($to, $subject, $html, true, $replyTo));
    } catch (Throwable) {
        return false;
    }
}

function events_followup_wrap(string $inner, string $contact): string
{
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    return '<div style="font-family:Arial,sans-serif;max-width:560px;margin:0 auto;color:#24151b">' . $inner
        . ($contact !== '' ? '<p style="color:#746872;font-size:13px">A problem or a question? Email <a href="mailto:' . $h($contact) . '">' . $h($contact) . '</a>.</p>' : '') . '</div>';
}

/* ------------------------------------------------------------------ abandoned checkout */

/** The buyer ticked "remind me": note it so ONE reminder can follow if they do not finish. */
function events_register_reminder(PDO $pdo, int $orderId): void
{
    $pdo->prepare('INSERT IGNORE INTO event_order_reminders (order_id) VALUES (:o)')->execute([':o' => $orderId]);
}

/**
 * Send the one reminder to buyers who asked for it and walked away. Safe to call often.
 *
 * @param null|callable $sendFn
 * @return int reminders sent
 */
function events_send_abandoned_reminders(PDO $pdo, int $limit = 10, ?callable $sendFn = null, ?int $now = null): int
{
    ensureEventFollowupsSchema($pdo);
    $now ??= time();
    $st = $pdo->prepare("SELECT o.id, o.customer_name, o.customer_email, o.created_at,
            (SELECT t.event_id FROM event_tickets t WHERE t.order_id = o.id LIMIT 1) AS event_id
        FROM event_order_reminders r JOIN orders o ON o.id = r.order_id
        WHERE r.sent_at IS NULL AND r.skipped = 0 AND o.status = 'expired' AND o.source = 'event_ticket'
          AND o.created_at > :oldest ORDER BY o.id LIMIT " . max(1, min(50, $limit)));
    $st->execute([':oldest' => date('Y-m-d H:i:s', $now - EVENT_REMINDER_MAX_AGE_HOURS * 3600)]);
    $sent = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $orderId = (int) $o['id'];
        $hold = $pdo->prepare('SELECT expires_at FROM event_order_holds WHERE order_id = :o');
        $hold->execute([':o' => $orderId]);
        $expires = $hold->fetchColumn();
        if ($expires !== false && strtotime((string) $expires) > $now - EVENT_REMINDER_AFTER_MINUTES * 60) {
            continue; // too soon: give them a chance to come back on their own
        }
        $skip = static function () use ($pdo, $orderId): void {
            $pdo->prepare('UPDATE event_order_reminders SET skipped = 1 WHERE order_id = :o')->execute([':o' => $orderId]);
        };
        $event = $o['event_id'] ? events_get($pdo, (int) $o['event_id']) : null;
        $email = trim((string) $o['customer_email']);
        if (!$event || !events_is_on_sale($event) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $skip();
            continue;
        }
        // They already came back and bought, or there is nothing left to remind them about.
        $bought = $pdo->prepare("SELECT COUNT(*) FROM orders o2 JOIN event_tickets t2 ON t2.order_id = o2.id WHERE t2.event_id = :e AND LOWER(o2.customer_email) = LOWER(:m) AND o2.status IN ('paid', 'complete', 'pending_payment') AND o2.id > :id");
        $bought->execute([':e' => (int) $event['id'], ':m' => $email, ':id' => $orderId]);
        $lines = $pdo->prepare("SELECT t.ticket_label, COUNT(*) AS n, ty.id AS type_id FROM event_tickets t JOIN event_ticket_types ty ON ty.id = t.ticket_type_id WHERE t.order_id = :o GROUP BY t.ticket_label, ty.id");
        $lines->execute([':o' => $orderId]);
        $wanted = $lines->fetchAll(PDO::FETCH_ASSOC);
        $anyLeft = false;
        foreach ($wanted as $w) {
            $type = events_ticket_type($pdo, (int) $w['type_id']);
            if ($type && (int) $type['allocation'] - events_type_sold($pdo, (int) $type['id']) >= (int) $w['n']) {
                $anyLeft = true;
            }
        }
        if ((int) $bought->fetchColumn() > 0 || !$anyLeft) {
            $skip();
            continue;
        }
        $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $what = implode(', ', array_map(static fn (array $w): string => (int) $w['n'] . ' x ' . $w['ticket_label'], $wanted));
        $url = events_public_url($event);
        $html = events_followup_wrap(
            '<h2 style="color:#4b0818;margin-bottom:4px">Still want to come?</h2>'
            . '<p>Hi ' . $h((string) $o['customer_name']) . ', you started booking <strong>' . $h($what) . '</strong> for <strong>' . $h((string) $event['title']) . '</strong> but did not finish, so the tickets were released.</p>'
            . '<p>There are still some left, but they are not being held for you: first come, first served.</p>'
            . '<p><a href="' . $h($url) . '" style="display:inline-block;background:#4b0818;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Choose my tickets</a></p>'
            . '<p style="color:#746872;font-size:13px">You are getting this once because you asked us to remind you when you booked. We will not email you about it again.</p>',
            events_contact_email($pdo, $event)
        );
        $contact = events_contact_email($pdo, $event);
        if (events_followup_send($sendFn, $email, 'Your tickets for ' . $event['title'] . ' were released', $html, $contact !== '' ? $contact : null)) {
            $pdo->prepare('UPDATE event_order_reminders SET sent_at = NOW() WHERE order_id = :o')->execute([':o' => $orderId]);
            $sent++;
        }
    }
    return $sent;
}

/* ------------------------------------------------------------------ waiting list */

/** Tickets that could be sold to a new buyer right now (general seats only). */
function events_waitlist_free_tickets(PDO $pdo, array $event): int
{
    $free = 0;
    foreach (events_ticket_types($pdo, (int) $event['id'], true) as $t) {
        if ($t['visibility'] === 'hidden' || ($t['pool'] ?? 'general') === 'access' || $t['audience'] === 'members') {
            continue;
        }
        if (events_type_availability_problem($t, false) !== '') {
            continue;
        }
        $free += max(0, (int) $t['allocation'] - events_type_sold($pdo, (int) $t['id']));
    }
    $by = events_seats_by_pool($pdo, (int) $event['id']);
    $room = events_pool_room($event, $by['general'], $by['access']);
    return min($free, $room['general']);
}

/** Join (or update) the waiting list. @return array{id:int,token:string} @throws RuntimeException */
function events_waitlist_join(PDO $pdo, int $eventId, string $name, string $email, int $qty): array
{
    ensureEventFollowupsSchema($pdo);
    $event = events_get($pdo, $eventId);
    if (!$event || !events_is_on_sale($event)) {
        throw new RuntimeException('This event is not taking a waiting list.');
    }
    $name = trim($name);
    $email = trim($email);
    if ($name === '') {
        throw new RuntimeException('Enter your name.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }
    if ($qty < 1 || $qty > EVENT_WAITLIST_MAX_QTY) {
        throw new RuntimeException('Choose between 1 and ' . EVENT_WAITLIST_MAX_QTY . ' tickets.');
    }
    $st = $pdo->prepare("SELECT id, token FROM event_waitlist WHERE event_id = :e AND LOWER(email) = LOWER(:m) AND status IN ('waiting', 'notified')");
    $st->execute([':e' => $eventId, ':m' => $email]);
    $existing = $st->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $pdo->prepare('UPDATE event_waitlist SET name = :n, qty = :q WHERE id = :id')->execute([':n' => mb_substr($name, 0, 150), ':q' => $qty, ':id' => (int) $existing['id']]);
        return ['id' => (int) $existing['id'], 'token' => (string) $existing['token']];
    }
    $count = $pdo->prepare("SELECT COUNT(*) FROM event_waitlist WHERE event_id = :e AND status IN ('waiting', 'notified')");
    $count->execute([':e' => $eventId]);
    if ((int) $count->fetchColumn() >= 500) {
        throw new RuntimeException('The waiting list is full.');
    }
    $token = bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO event_waitlist (event_id, name, email, qty, token) VALUES (:e, :n, :m, :q, :t)')
        ->execute([':e' => $eventId, ':n' => mb_substr($name, 0, 150), ':m' => mb_substr($email, 0, 190), ':q' => $qty, ':t' => $token]);
    return ['id' => (int) $pdo->lastInsertId(), 'token' => $token];
}

function events_waitlist_leave(PDO $pdo, string $token): bool
{
    ensureEventFollowupsSchema($pdo);
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return false;
    }
    $st = $pdo->prepare("UPDATE event_waitlist SET status = 'cancelled' WHERE token = :t AND status IN ('waiting', 'notified')");
    $st->execute([':t' => $token]);
    return $st->rowCount() > 0;
}

/** When someone buys, they come off the list. */
function events_waitlist_mark_bought(PDO $pdo, int $eventId, string $email): void
{
    try {
        $pdo->prepare("UPDATE event_waitlist SET status = 'bought' WHERE event_id = :e AND LOWER(email) = LOWER(:m) AND status IN ('waiting', 'notified')")
            ->execute([':e' => $eventId, ':m' => $email]);
    } catch (Throwable) {
    }
}

/**
 * Tell the next people on the waiting list that tickets are available, never more people than tickets. A person
 * who was told and did not buy within EVENT_WAITLIST_CLAIM_HOURS loses their turn, and the tickets they were
 * holding go to the next in line.
 *
 * @param null|callable $sendFn
 * @return int people notified
 */
function events_process_waitlist(PDO $pdo, int $eventId, ?callable $sendFn = null, ?int $now = null): int
{
    ensureEventFollowupsSchema($pdo);
    $now ??= time();
    $event = events_get($pdo, $eventId);
    if (!$event || !events_is_on_sale($event)) {
        return 0;
    }
    $pdo->prepare("UPDATE event_waitlist SET status = 'expired' WHERE event_id = :e AND status = 'notified' AND notified_at < :t")
        ->execute([':e' => $eventId, ':t' => date('Y-m-d H:i:s', $now - EVENT_WAITLIST_CLAIM_HOURS * 3600)]);
    $claimed = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) FROM event_waitlist WHERE event_id = :e AND status = 'notified'");
    $claimed->execute([':e' => $eventId]);
    $remaining = events_waitlist_free_tickets($pdo, $event) - (int) $claimed->fetchColumn();
    if ($remaining <= 0) {
        return 0;
    }
    $waiting = $pdo->prepare("SELECT * FROM event_waitlist WHERE event_id = :e AND status = 'waiting' ORDER BY id");
    $waiting->execute([':e' => $eventId]);
    $contact = events_contact_email($pdo, $event);
    $h = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $url = events_public_url($event);
    $notified = 0;
    foreach ($waiting->fetchAll(PDO::FETCH_ASSOC) as $w) {
        if ($remaining <= 0) {
            break;
        }
        if ((int) $w['qty'] > $remaining) {
            continue; // does not fit yet: the next person might
        }
        $leave = $url . '?leave_waitlist=' . $w['token'];
        $html = events_followup_wrap(
            '<h2 style="color:#4b0818;margin-bottom:4px">Tickets are available</h2>'
            . '<p>Hi ' . $h((string) $w['name']) . ', good news: tickets for <strong>' . $h((string) $event['title']) . '</strong> have come back on sale and you are next on the waiting list for ' . (int) $w['qty'] . '.</p>'
            . '<p>We have set aside ' . (int) $w['qty'] . ' for you for the next ' . EVENT_WAITLIST_CLAIM_HOURS . ' hours. After that they go to the next person.</p>'
            . '<p><a href="' . $h($url) . '" style="display:inline-block;background:#4b0818;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Get my tickets</a></p>'
            . '<p style="color:#746872;font-size:13px">You are getting this because you joined the waiting list. <a href="' . $h($leave) . '">Take me off the list</a>.</p>',
            $contact
        );
        if (events_followup_send($sendFn, (string) $w['email'], 'Tickets available: ' . $event['title'], $html, $contact !== '' ? $contact : null)) {
            $pdo->prepare("UPDATE event_waitlist SET status = 'notified', notified_at = :t WHERE id = :id")->execute([':t' => date('Y-m-d H:i:s', $now), ':id' => (int) $w['id']]);
            $remaining -= (int) $w['qty'];
            $notified++;
        }
    }
    return $notified;
}

/** Waiting-list details are deleted 30 days after the event. */
function events_purge_waitlist(PDO $pdo): int
{
    try {
        return (int) $pdo->exec("DELETE w FROM event_waitlist w JOIN events e ON e.id = w.event_id WHERE e.starts_at < (NOW() - INTERVAL 30 DAY)");
    } catch (Throwable) {
        return 0;
    }
}

/** @return list<array<string,mixed>> */
function events_waitlist_rows(PDO $pdo, int $eventId): array
{
    ensureEventFollowupsSchema($pdo);
    $st = $pdo->prepare("SELECT * FROM event_waitlist WHERE event_id = :e AND status IN ('waiting', 'notified') ORDER BY id");
    $st->execute([':e' => $eventId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** One call that does all the follow-up work. Cheap when there is nothing to do. */
function events_run_followups(PDO $pdo): array
{
    ensureEventFollowupsSchema($pdo);
    $out = ['holds' => events_release_expired_holds($pdo), 'reminders' => events_send_abandoned_reminders($pdo), 'waitlist' => 0, 'purged' => events_purge_access_needs($pdo) + events_purge_waitlist($pdo)];
    $ids = $pdo->query("SELECT DISTINCT w.event_id FROM event_waitlist w JOIN events e ON e.id = w.event_id WHERE w.status IN ('waiting', 'notified') AND e.status = 'on_sale' AND e.starts_at > NOW()")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $eid) {
        $out['waitlist'] += events_process_waitlist($pdo, (int) $eid);
    }
    return $out;
}
