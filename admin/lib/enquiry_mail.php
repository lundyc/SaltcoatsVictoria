<?php

declare(strict_types=1);

/**
 * The club enquiries mailbox (enquiries@saltcoatsvictoria.co.uk).
 *
 *  - Out: replies and acknowledgements are sent FROM the mailbox, so customers only ever see the club
 *    address and any answer they send comes back to it.
 *  - In: the Hub reads the mailbox over IMAP (localhost), matches each message to its enquiry by the
 *    [ENQ-XXXXXX] reference in the subject and adds it to the conversation.
 *
 * Everything here switches on only once ENQUIRIES_MAIL_PASSWORD is in admin/.env.
 */

require_once __DIR__ . '/mailer.php';

/** The club enquiries mailbox. Empty until one is created and ENQUIRIES_MAIL_ADDRESS / ENQUIRIES_MAIL_PASSWORD are set in admin/.env. */
define('ENQUIRY_MAILBOX', strtolower(hub_mail_config_value('ENQUIRIES_MAIL_ADDRESS', '')));

function enquiry_mailbox_password(): string
{
    return hub_mail_config_value('ENQUIRIES_MAIL_PASSWORD', '');
}

function enquiry_mailbox_enabled(): bool
{
    return ENQUIRY_MAILBOX !== '' && enquiry_mailbox_password() !== '';
}

/** Send as the club mailbox (falls back to the normal Hub sender until the mailbox password is set). */
function enquiry_send_mail(string $to, string $subject, string $body, ?string $fallbackReplyTo = null): bool
{
    if (!enquiry_mailbox_enabled()) {
        return hub_send_mail($to, $subject, $body, false, $fallbackReplyTo);
    }
    return hub_send_mail($to, $subject, $body, false, ENQUIRY_MAILBOX, [
        'username' => ENQUIRY_MAILBOX,
        'password' => enquiry_mailbox_password(),
        'from_email' => ENQUIRY_MAILBOX,
        'from_name' => enquiry_club('club_name'),
    ]);
}

/* ---------------------------- IMAP (minimal) ---------------------------- */

/** @return resource */
function enquiry_imap_open()
{
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $sock = @stream_socket_client('ssl://127.0.0.1:993', $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        throw new RuntimeException('Could not reach the mail server (' . $errstr . ').');
    }
    stream_set_timeout($sock, 20);
    fgets($sock); // greeting
    return $sock;
}

/**
 * Run one IMAP command; returns [untagged lines/literals as a string, final status line].
 * Literals ({n}) are read exactly and appended so FETCH bodies come back intact.
 */
function enquiry_imap_cmd($sock, string $cmd, string $tag): array
{
    fwrite($sock, $tag . ' ' . $cmd . "\r\n");
    $out = '';
    while (($line = fgets($sock)) !== false) {
        if (str_starts_with($line, $tag . ' ')) {
            return [$out, trim($line)];
        }
        $out .= $line;
        if (preg_match('/\{(\d+)\}\r?\n$/', $line, $m)) {
            $need = (int) $m[1];
            $buf = '';
            while (strlen($buf) < $need && ($chunk = fread($sock, $need - strlen($buf))) !== false && $chunk !== '') {
                $buf .= $chunk;
            }
            $out .= $buf;
        }
    }
    throw new RuntimeException('The mail server stopped responding.');
}

/* ------------------------------ MIME parse ------------------------------ */

function enquiry_mime_decode_header(string $v): string
{
    $v = trim(preg_replace('/\r?\n[ \t]+/', ' ', $v));
    $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    return $d !== false ? $d : $v;
}

/** @return array{0:array<string,string>,1:string} lower-cased headers and raw body */
function enquiry_mime_split(string $raw): array
{
    $raw = str_replace("\r\n", "\n", $raw);
    $pos = strpos($raw, "\n\n");
    $head = $pos === false ? $raw : substr($raw, 0, $pos);
    $body = $pos === false ? '' : substr($raw, $pos + 2);
    $headers = [];
    foreach (preg_split('/\n(?![ \t])/', $head) as $line) {
        if (strpos($line, ':') !== false) {
            [$k, $v] = explode(':', $line, 2);
            $k = strtolower(trim($k));
            if (!isset($headers[$k])) {
                $headers[$k] = trim(preg_replace('/\n[ \t]+/', ' ', $v));
            }
        }
    }
    return [$headers, $body];
}

function enquiry_mime_text(array $headers, string $body, int $depth = 0): string
{
    $type = strtolower($headers['content-type'] ?? 'text/plain');
    if (str_starts_with($type, 'multipart/') && $depth < 6 && preg_match('/boundary="?([^";\s]+)"?/i', $headers['content-type'] ?? '', $bm)) {
        $parts = preg_split('/\n--' . preg_quote($bm[1], '/') . '(?:--)?[ \t]*(?:\n|$)/', "\n" . $body);
        $plain = '';
        $html = '';
        foreach ($parts as $part) {
            if (trim($part) === '') {
                continue;
            }
            [$ph, $pb] = enquiry_mime_split($part);
            if (!$ph) {
                continue;
            }
            $pt = strtolower($ph['content-type'] ?? 'text/plain');
            if (str_contains(strtolower($ph['content-disposition'] ?? ''), 'attachment')) {
                continue;
            }
            $text = enquiry_mime_text($ph, $pb, $depth + 1);
            if ($text === '') {
                continue;
            }
            if (str_starts_with($pt, 'text/html')) {
                $html = $html !== '' ? $html : $text;
            } else {
                $plain = $plain !== '' ? $plain : $text;
            }
        }
        return $plain !== '' ? $plain : $html;
    }
    if (!str_starts_with($type, 'text/')) {
        return '';
    }
    $enc = strtolower($headers['content-transfer-encoding'] ?? '7bit');
    if ($enc === 'base64') {
        $body = (string) base64_decode($body, false);
    } elseif ($enc === 'quoted-printable') {
        $body = quoted_printable_decode($body);
    }
    if (preg_match('/charset="?([\w\-]+)"?/i', $headers['content-type'] ?? '', $cm) && strtolower($cm[1]) !== 'utf-8') {
        $conv = @iconv($cm[1], 'UTF-8//IGNORE', $body);
        if ($conv !== false) {
            $body = $conv;
        }
    }
    if (str_starts_with($type, 'text/html')) {
        $body = preg_replace('~<(script|style)\b.*?</\1>~is', '', $body);
        $body = preg_replace('~<br\s*/?>|</p>|</div>|</tr>|</li>~i', "\n", $body);
        $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return trim($body);
}

/** Keep only what the person wrote: drop quoted history and signatures' "sent from" footers. */
function enquiry_mail_strip_quote(string $text): string
{
    $text = str_replace("\r", '', $text);
    $out = [];
    $lines = explode("\n", $text);
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $l = $lines[$i];
        if (preg_match('/^\s*On .{5,200}wrote:\s*$/i', $l)
            || (preg_match('/^\s*On .{5,200}$/i', $l) && isset($lines[$i + 1]) && preg_match('/wrote:\s*$/i', $lines[$i + 1]))
            || preg_match('/^\s*-{2,}\s*(Original Message|Forwarded message)/i', $l)
            || preg_match('/^_{10,}\s*$/', $l)
            || (preg_match('/^\s*From:\s.+/i', $l) && isset($lines[$i + 1]) && preg_match('/^\s*(Sent|Date):/i', $lines[$i + 1]))) {
            break;
        }
        if (preg_match('/^\s*>/', $l)) {
            continue;
        }
        $out[] = $l;
    }
    $res = trim(implode("\n", $out));
    $res = preg_replace("/\n{3,}/", "\n\n", $res);
    return mb_substr($res !== '' ? $res : trim($text), 0, 8000);
}

/**
 * Parse one raw message and, if it belongs to an enquiry, add it to the conversation.
 * @return string 'logged' | 'skipped:<reason>'
 */
function enquiry_mail_ingest(PDO $pdo, string $raw): string
{
    enquiry_mail_ensure_schema($pdo);
    [$h, $body] = enquiry_mime_split($raw);
    $from = '';
    if (preg_match('/<([^>]+)>/', $h['from'] ?? '', $fm)) {
        $from = strtolower(trim($fm[1]));
    } else {
        $from = strtolower(trim($h['from'] ?? ''));
    }
    $subject = enquiry_mime_decode_header($h['subject'] ?? '');
    if ($from === '' || ($from === ENQUIRY_MAILBOX && ENQUIRY_MAILBOX !== '') || preg_match('/^(mailer-daemon|postmaster)@|^(noreply|orders)@saltcoatsvictoria\.co\.uk$/i', $from)) {
        return 'skipped:own-or-system';
    }
    if (strtolower($h['auto-submitted'] ?? 'no') !== 'no' || preg_match('/bulk|junk|list|auto_reply/i', $h['precedence'] ?? '')) {
        return 'skipped:automatic';
    }
    $text = enquiry_mime_text($h, $body);
    if (!preg_match('/\[(ENQ-[A-F0-9]{6})\]/i', $subject, $rm) && !preg_match('/\[(ENQ-[A-F0-9]{6})\]/i', $text, $rm)) {
        return 'skipped:no-reference';
    }
    $ref = strtoupper($rm[1]);
    $msgId = trim($h['message-id'] ?? '');
    if ($msgId === '') {
        $msgId = 'sha-' . sha1($raw);
    }
    $stmt = $pdo->prepare('SELECT * FROM enquiries WHERE ref = ?');
    $stmt->execute([$ref]);
    $enq = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$enq) {
        return 'skipped:unknown-reference';
    }
    $seen = $pdo->prepare('INSERT IGNORE INTO enquiry_mail_seen (message_id, created_at) VALUES (?, NOW())');
    $seen->execute([mb_substr($msgId, 0, 250)]);
    if ($seen->rowCount() === 0) {
        return 'skipped:duplicate';
    }
    $clean = enquiry_mail_strip_quote($text);
    if ($clean === '') {
        return 'skipped:empty';
    }
    $enquiryId = (int) $enq['id'];
    if ($from === strtolower((string) $enq['email'])) {
        enquiry_add_message($pdo, $enquiryId, 'reply_in', (string) $enq['name'], $clean);
        if ((string) $enq['status'] !== 'new') {
            $pdo->prepare("UPDATE enquiries SET status = 'new', handled_by = '' WHERE id = ?")->execute([$enquiryId]);
            enquiry_add_message($pdo, $enquiryId, 'event', 'Mailbox', 'Customer replied - status set back to New');
        }
    } else {
        enquiry_add_message($pdo, $enquiryId, 'note', $from, "Replied from a mail app (not through the Hub), as " . $from . ":\n\n" . $clean);
    }
    return 'logged';
}

function enquiry_mail_ensure_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS enquiry_mail_seen (
        message_id VARCHAR(250) NOT NULL PRIMARY KEY,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS enquiry_mail_state (
        k VARCHAR(40) NOT NULL PRIMARY KEY,
        v VARCHAR(100) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Read new mail from the club mailbox and log anything that belongs to an enquiry.
 * The first run only notes where the mailbox is up to, so old mail is never imported.
 * @return array{ok:bool,logged:int,skipped:int,error:string}
 */
function enquiry_mail_pull(PDO $pdo): array
{
    $res = ['ok' => false, 'logged' => 0, 'skipped' => 0, 'error' => ''];
    if (!enquiry_mailbox_enabled()) {
        $res['error'] = 'Mailbox not set up yet.';
        return $res;
    }
    try {
        enquiry_mail_ensure_schema($pdo);
        $state = [];
        foreach ($pdo->query('SELECT k, v FROM enquiry_mail_state')->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            $state[$k] = $v;
        }
        $sock = enquiry_imap_open();
        $quote = static fn (string $v): string => '"' . addcslashes($v, "\"\\") . '"';
        [, $st] = enquiry_imap_cmd($sock, 'LOGIN ' . $quote(ENQUIRY_MAILBOX) . ' ' . $quote(enquiry_mailbox_password()), 'a1');
        if (!str_contains($st, 'OK')) {
            throw new RuntimeException('The mailbox login was refused. Check ENQUIRIES_MAIL_PASSWORD.');
        }
        [$sel] = enquiry_imap_cmd($sock, 'SELECT INBOX', 'a2');
        $validity = preg_match('/UIDVALIDITY (\d+)/', $sel, $m) ? $m[1] : '0';
        $next = preg_match('/UIDNEXT (\d+)/', $sel, $m) ? (int) $m[1] : 1;
        $last = isset($state['last_uid']) && ($state['uidvalidity'] ?? '') === $validity ? (int) $state['last_uid'] : null;
        $save = static function (string $k, string $v) use ($pdo): void {
            $pdo->prepare('INSERT INTO enquiry_mail_state (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
        };
        if ($last === null) {
            $save('uidvalidity', $validity);
            $save('last_uid', (string) ($next - 1));
            enquiry_imap_cmd($sock, 'LOGOUT', 'a9');
            fclose($sock);
            $res['ok'] = true;
            return $res;
        }
        [$srch] = enquiry_imap_cmd($sock, 'UID SEARCH UID ' . ($last + 1) . ':*', 'a3');
        $uids = [];
        if (preg_match('/\* SEARCH ([\d ]+)/', $srch, $m)) {
            foreach (preg_split('/\s+/', trim($m[1])) as $u) {
                if ((int) $u > $last) {
                    $uids[] = (int) $u;
                }
            }
        }
        sort($uids);
        $tag = 10;
        foreach (array_slice($uids, 0, 40) as $uid) {
            [$data] = enquiry_imap_cmd($sock, 'UID FETCH ' . $uid . ' BODY.PEEK[]', 'a' . ++$tag);
            $nl = strpos($data, "\n");
            $raw = '';
            if ($nl !== false && preg_match('/\{(\d+)\}\r?$/', rtrim(substr($data, 0, $nl)), $lm)) {
                $raw = substr($data, $nl + 1, (int) $lm[1]);
            }
            if ($raw !== '') {
                try {
                    enquiry_mail_ingest($pdo, $raw) === 'logged' ? $res['logged']++ : $res['skipped']++;
                } catch (Throwable $e) {
                    error_log('[enquiry-mail] could not process uid ' . $uid . ': ' . $e->getMessage());
                    $res['skipped']++;
                }
            }
            $save('last_uid', (string) $uid);
        }
        enquiry_imap_cmd($sock, 'LOGOUT', 'a99');
        fclose($sock);
        $res['ok'] = true;
    } catch (Throwable $e) {
        $res['error'] = $e->getMessage();
        error_log('[enquiry-mail] ' . $e->getMessage());
    }
    return $res;
}

/** Called when an admin opens the Inbox or an enquiry: reads the mailbox at most every 90 seconds. */
function enquiry_mail_pull_throttled(PDO $pdo): void
{
    if (!enquiry_mailbox_enabled()) {
        return;
    }
    $file = sys_get_temp_dir() . '/hub_enquiry_mail_pull';
    $last = @filemtime($file);
    if ($last !== false && time() - $last < 90) {
        return;
    }
    @touch($file);
    enquiry_mail_pull($pdo);
}
