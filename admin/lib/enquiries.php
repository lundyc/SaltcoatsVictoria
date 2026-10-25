<?php

declare(strict_types=1);

/**
 * Website enquiries. Every public enquiry form (contact page and the
 * "get in touch" buttons around the site) saves a row here, emails the right
 * department, and shows in the Hub Inbox. If the email fails the row is still
 * saved, so nothing is lost.
 *
 * Department recipients live in site_settings as enquiry_email_<department>;
 * an empty one falls back to contact_email.
 */

/** @return array<string,array{label:string,blurb:string,fields:list<array{name:string,label:string,type:string,required?:bool,options?:list<string>,placeholder?:string}>}> */
require_once __DIR__ . '/enquiry_mail.php';

function enquiry_departments(): array
{
    return [
        'general' => [
            'label' => 'General enquiry',
            'blurb' => 'Any question for the club.',
            'fields' => [],
        ],
        'commercial' => [
            'label' => 'Sponsorship & advertising',
            'blurb' => 'Sponsoring a game, a player or the captain, advertising and partnerships.',
            'fields' => [
                ['name' => 'business', 'label' => 'Business name', 'type' => 'text', 'required' => true],
                ['name' => 'phone', 'label' => 'Best contact number', 'type' => 'tel', 'required' => true],
                ['name' => 'interest', 'label' => 'What are you interested in?', 'type' => 'select', 'options' => [
                    'Match sponsorship', 'Match ball sponsorship', 'Dugout sponsorship', 'Captain sponsorship', 'Player sponsorship',
                    'Pitchside boards', 'Programme advertising', 'Digital media sponsorship', 'Club partnership', 'Something else',
                ]],
            ],
        ],
        'hire' => [
            'label' => 'Ground hire',
            'blurb' => 'Birthdays, weddings, events, conferences and remembrance gatherings.',
            'fields' => [
                ['name' => 'phone', 'label' => 'Best contact number', 'type' => 'tel', 'required' => true],
                ['name' => 'occasion', 'label' => 'Occasion', 'type' => 'select', 'options' => ['Birthday', 'Wedding', 'Conference or event', 'Funeral or remembrance', 'Something else']],
                ['name' => 'date', 'label' => 'Preferred date', 'type' => 'date'],
                ['name' => 'guests', 'label' => 'Approximate number of guests', 'type' => 'number'],
            ],
        ],
        'media' => [
            'label' => 'Media',
            'blurb' => 'Interview requests and matchday accreditation.',
            'fields' => [
                ['name' => 'organisation', 'label' => 'Organisation', 'type' => 'text', 'required' => true],
                ['name' => 'role', 'label' => 'Your role', 'type' => 'text'],
                ['name' => 'phone', 'label' => 'Best contact number', 'type' => 'tel'],
                ['name' => 'fixture', 'label' => 'Fixture you want to cover (if any)', 'type' => 'text'],
                ['name' => 'equipment', 'label' => 'Camera / recording equipment', 'type' => 'text'],
            ],
        ],
        'supporters' => [
            'label' => 'Supporter services',
            'blurb' => 'Supporter liaison, accessibility, supporters clubs and the 50/50 draw.',
            'fields' => [
                ['name' => 'topic', 'label' => 'What is it about?', 'type' => 'select', 'options' => [
                    'Supporter liaison officer', 'Disabled supporter access', 'Supporters club', '50/50 draw', 'Something else',
                ]],
                ['name' => 'phone', 'label' => 'Best contact number (optional)', 'type' => 'tel'],
            ],
        ],
        'volunteers' => [
            'label' => 'Volunteering & vacancies',
            'blurb' => 'Offer your time or ask about roles at the club.',
            'fields' => [
                ['name' => 'phone', 'label' => 'Best contact number', 'type' => 'tel'],
                ['name' => 'help', 'label' => 'How could you help?', 'type' => 'select', 'options' => [
                    'Matchday', 'Ground maintenance', 'Fundraising', 'Social media and media', 'Coaching or youth', 'Committee', 'Something else',
                ]],
                ['name' => 'availability', 'label' => 'When are you available?', 'type' => 'text'],
            ],
        ],
    ];
}

function enquiry_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS enquiries (
            id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ref           VARCHAR(20) NOT NULL,
            department    VARCHAR(30) NOT NULL,
            name          VARCHAR(150) NOT NULL,
            email         VARCHAR(190) NOT NULL,
            phone         VARCHAR(60) NOT NULL DEFAULT '',
            subject       VARCHAR(190) NOT NULL DEFAULT '',
            message       TEXT NOT NULL,
            details       TEXT NULL,
            status        ENUM('new','replied','done') NOT NULL DEFAULT 'new',
            emailed_to    VARCHAR(190) NOT NULL DEFAULT '',
            email_sent    TINYINT(1) NOT NULL DEFAULT 0,
            handled_by    VARCHAR(150) NOT NULL DEFAULT '',
            internal_note TEXT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_enquiries_ref (ref),
            KEY idx_enquiries_status (status, created_at),
            KEY idx_enquiries_dept (department, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS enquiry_messages (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            enquiry_id  INT UNSIGNED NOT NULL,
            kind        VARCHAR(20) NOT NULL,
            author      VARCHAR(150) NOT NULL DEFAULT '',
            body        TEXT NOT NULL,
            email_sent  TINYINT(1) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_enq_msg (enquiry_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM enquiries')->fetchAll(PDO::FETCH_COLUMN) as $col) {
        $have[(string) $col] = true;
    }
    if (!isset($have['priority'])) {
        $pdo->exec("ALTER TABLE enquiries ADD COLUMN priority VARCHAR(10) NOT NULL DEFAULT 'normal'");
    }
    if (!isset($have['assigned_to'])) {
        $pdo->exec("ALTER TABLE enquiries ADD COLUMN assigned_to VARCHAR(150) NOT NULL DEFAULT ''");
    }
    if (!isset($have['sponsorship_order_id'])) {
        $pdo->exec('ALTER TABLE enquiries ADD COLUMN sponsorship_order_id INT UNSIGNED NULL DEFAULT NULL');
    }
    if (!isset($have['sponsorship_kind'])) {
        $pdo->exec("ALTER TABLE enquiries ADD COLUMN sponsorship_kind VARCHAR(10) NULL DEFAULT NULL");
    }
    if (!isset($have['follow_up_on'])) {
        $pdo->exec('ALTER TABLE enquiries ADD COLUMN follow_up_on DATE NULL');
    }
    $done = true;
}

/** Add a row to an enquiry's conversation. kind: reply_out | reply_in | note | event. */
function enquiry_add_message(PDO $pdo, int $enquiryId, string $kind, string $author, string $body, bool $emailSent = false): void
{
    $pdo->prepare('INSERT INTO enquiry_messages (enquiry_id, kind, author, body, email_sent) VALUES (:e, :k, :a, :b, :s)')
        ->execute([':e' => $enquiryId, ':k' => $kind, ':a' => mb_substr($author, 0, 150), ':b' => $body, ':s' => $emailSent ? 1 : 0]);
}

/** Ready-made replies. {name} = sender's first name, {club} = club name. @return array<string,array{label:string,body:string}> */
function enquiry_reply_templates(string $dept): array
{
    $t = [
        'thanks' => ['label' => 'Thanks, we will come back to you', 'body' => "Hello {name},\n\nThanks for getting in touch with {club}. We have passed your message to the right person and they will come back to you shortly.\n\nKind regards,\n{club}"],
        'more' => ['label' => 'Need a little more information', 'body' => "Hello {name},\n\nThanks for your message. So we can help, could you let us know a little more about what you need?\n\nKind regards,\n{club}"],
        'phone' => ['label' => 'Please give us a call', 'body' => "Hello {name},\n\nThanks for your message. It is easiest to talk this through, so please give us a call or reply with a good time to ring you.\n\nKind regards,\n{club}"],
        'closed' => ['label' => 'All sorted - closing off', 'body' => "Hello {name},\n\nJust to let you know this is now sorted. If you need anything else, reply to this email.\n\nKind regards,\n{club}"],
    ];
    if ($dept === 'commercial') {
        $t['sponsor'] = ['label' => 'Sponsorship: send details and arrange a chat', 'body' => "Hello {name},\n\nThank you for your interest in sponsoring {club}. We have a range of options from match and ball sponsorship to pitchside boards and partnerships. I would be happy to talk you through them - when would suit you for a quick call or a visit to the ground?\n\nKind regards,\n{club}"];
    } elseif ($dept === 'hire') {
        $t['hire'] = ['label' => 'Ground hire: check date and guests', 'body' => "Hello {name},\n\nThanks for your enquiry about hiring the ground. We will check availability for your date. Could you confirm the approximate number of guests and the times you would need?\n\nKind regards,\n{club}"];
    } elseif ($dept === 'volunteers') {
        $t['volunteer'] = ['label' => 'Volunteering: thanks and next steps', 'body' => "Hello {name},\n\nThank you for offering your time to {club} - volunteers make a huge difference. We will be in touch about the best way for you to get involved.\n\nKind regards,\n{club}"];
    }
    return $t;
}

/** A club setting, whether this runs on the public site (club()) or in the Hub (site_settings). */
function enquiry_club(string $key): string
{
    if (function_exists('club')) {
        return club($key);
    }
    require_once __DIR__ . '/site_settings.php';
    return site_setting($GLOBALS['pdo'], $key, '');
}

/** Setting key holding a department's recipient address. */
function enquiry_setting_key(string $dept): string
{
    return 'enquiry_email_' . $dept;
}

/** Recipient for a department: its own address, else the club contact email, else ''. */
function enquiry_recipient(string $dept): string
{
    $own = trim(enquiry_club(enquiry_setting_key($dept)));
    return $own !== '' ? $own : trim(enquiry_club('contact_email'));
}

/**
 * Validate and store an enquiry, then email the department.
 *
 * @param array<string,mixed> $input  raw POST
 * @return array{ok:bool,errors:list<string>,ref?:string}
 */
function enquiry_submit(PDO $pdo, string $dept, array $input): array
{
    $deps = enquiry_departments();
    if (!isset($deps[$dept])) {
        $dept = 'general';
    }
    $def = $deps[$dept];
    $errors = [];

    $name = trim((string) ($input['name'] ?? ''));
    $email = trim((string) ($input['email'] ?? ''));
    $subject = mb_substr(trim((string) ($input['subject'] ?? '')), 0, 190);
    $message = trim((string) ($input['message'] ?? ''));
    $phone = '';
    $details = [];

    if ($name === '' || $message === '') {
        $errors[] = 'Please add your name and a message.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    foreach ($def['fields'] as $f) {
        $val = mb_substr(trim((string) ($input[$f['name']] ?? '')), 0, 200);
        if (!empty($f['required']) && $val === '') {
            $errors[] = 'Please fill in: ' . rtrim($f['label'], '?') . '.';
        }
        if ($f['type'] === 'select' && $val !== '' && !in_array($val, $f['options'] ?? [], true)) {
            $val = '';
        }
        if ($f['name'] === 'phone') {
            $phone = $val;
        } elseif ($val !== '') {
            $details[$f['label']] = $val;
        }
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    enquiry_ensure_schema($pdo);
    $ref = 'ENQ-' . strtoupper(bin2hex(random_bytes(3)));
    $to = enquiry_recipient($dept);
    $pdo->prepare(
        'INSERT INTO enquiries (ref, department, name, email, phone, subject, message, details, emailed_to)
         VALUES (:r, :d, :n, :e, :p, :s, :m, :x, :t)'
    )->execute([
        ':r' => $ref, ':d' => $dept, ':n' => mb_substr($name, 0, 150), ':e' => mb_substr($email, 0, 190),
        ':p' => $phone, ':s' => $subject, ':m' => $message,
        ':x' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, ':t' => $to,
    ]);
    $id = (int) $pdo->lastInsertId();

    if ($to !== '') {
        $body = 'New ' . strtolower($def['label']) . " from the club website ($ref)\n\n"
            . "Name:    $name\nEmail:   $email\n" . ($phone !== '' ? "Phone:   $phone\n" : '')
            . ($subject !== '' ? "Subject: $subject\n" : '');
        foreach ($details as $label => $val) {
            $body .= $label . ': ' . $val . "\n";
        }
        $body .= "\n$message\n\nReply to this email to answer them, or manage it in the Hub Inbox.\n";
        if (enquiry_mailbox_enabled()) {
            $body = str_replace("Reply to this email to answer them, or manage it in the Hub Inbox.\n", '', $body)
                . "Please reply from the Hub, not from your mail app, so the customer sees the club address and the conversation is recorded:\n"
                . 'https://saltcoatsvictoria.co.uk/admin/enquiry.php?id=' . $id . "\n";
            $sent = enquiry_send_mail($to, 'Website enquiry (' . $def['label'] . '): ' . ($subject !== '' ? $subject : $name) . ' [' . $ref . ']', $body);
        } else {
            $sent = hub_send_mail($to, 'Website enquiry (' . $def['label'] . '): ' . ($subject !== '' ? $subject : $name), $body, false, $email);
        }
        if ($sent) {
            $pdo->prepare('UPDATE enquiries SET email_sent = 1 WHERE id = :i')->execute([':i' => $id]);
        }
    }

    // Acknowledgement to the sender (best effort), sent as the club mailbox once it is set up.
    enquiry_send_mail(
        $email,
        'We have your message — ' . enquiry_club('club_name') . ' [' . $ref . ']',
        "Hello $name,\n\nThanks for getting in touch with " . enquiry_club('club_name') . ". We have your enquiry ($ref) and the right person will reply as soon as they can. " . trim((string) ($input['_ack_note'] ?? '')) . " You can reply to this email if you need to add anything.\n\nYour message:\n$message\n",
        $to !== '' ? $to : null
    );

    return ['ok' => true, 'errors' => [], 'ref' => $ref];
}

/* --------------------------------------------------------------------------
 * Spam protection for the public contact and enquiry forms.
 * Layers: honeypot, signed form timestamp (too fast / too old / forged), a
 * JavaScript-set token, content checks, duplicate detection and per-IP,
 * per-email and site-wide rate limits. Anything caught as spam is dropped
 * silently (the sender is shown the normal "sent" page) so bots learn nothing,
 * and no acknowledgement email is ever sent to an address a bot supplied.
 * ------------------------------------------------------------------------ */

function enquiry_spam_sign(string $ts): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return hash_hmac('sha256', $ts, (string) ($_SESSION['csrf_token'] ?? ''));
}

/** Hidden inputs + script for the form. Echo inside the <form>. */
function enquiry_turnstile_keys(): array
{
    return [hub_mail_config_value('TURNSTILE_SITE_KEY', ''), hub_mail_config_value('TURNSTILE_SECRET_KEY', '')];
}

/** Cloudflare Turnstile is used only once both keys are in admin/.env. */
function enquiry_turnstile_enabled(): bool
{
    [$site, $secret] = enquiry_turnstile_keys();
    return $site !== '' && $secret !== '';
}

function enquiry_turnstile_verify(string $response): bool
{
    [, $secret] = enquiry_turnstile_keys();
    if ($response === '' || strlen($response) > 4096) {
        return false;
    }
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $response, 'remoteip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '')]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if ($raw === false) {
        // Cloudflare unreachable: fail open so real people are never locked out; the other checks still apply.
        error_log('[enquiry-spam] turnstile verify unreachable');
        return true;
    }
    $data = json_decode((string) $raw, true);
    return is_array($data) && !empty($data['success']);
}

function enquiry_spam_fields(bool $withTurnstile = true): string
{
    $ts = (string) time();
    $v = $ts . '.' . substr(enquiry_spam_sign($ts), 0, 24);
    return '<input type="hidden" name="_t" value="' . htmlspecialchars($v, ENT_QUOTES) . '">'
        . '<input type="hidden" name="_j" value="" data-enq-j>'
        . '<script>(function(){var t=document.querySelector("input[name=_t]"),j=document.querySelector("[data-enq-j]");'
        . 'if(t&&j){var n=parseInt(t.value,10);j.value=String(n*7+13);}})();</script>'
        . ($withTurnstile ? enquiry_turnstile_widget() : '');
}

/** The Cloudflare Turnstile widget on its own, for forms that place it next to the submit button. */
function enquiry_turnstile_widget(): string
{
    return enquiry_turnstile_enabled()
        ? '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars(enquiry_turnstile_keys()[0], ENT_QUOTES) . '" style="margin:0.75rem 0"></div>'
          . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
        : '';
}

function enquiry_ensure_throttle(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS enquiry_throttle (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip_hash CHAR(64) NOT NULL,
        email_hash CHAR(64) NOT NULL,
        msg_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        KEY idx_ip (ip_hash, created_at),
        KEY idx_email (email_hash, created_at),
        KEY idx_msg (msg_hash, created_at),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("DELETE FROM enquiry_throttle WHERE created_at < (NOW() - INTERVAL 3 DAY)");
    $done = true;
}

/**
 * @return array{status:string,message:string,reason:string}
 *   status 'ok' = carry on; 'spam' = pretend it worked, send nothing; 'limit' = tell a real person to wait.
 */
function enquiry_guard(PDO $pdo, array $input): array
{
    $ok = ['status' => 'ok', 'message' => '', 'reason' => ''];
    $spam = static fn (string $why): array => ['status' => 'spam', 'message' => '', 'reason' => $why];

    if (trim((string) ($input['website'] ?? '')) !== '') {
        return $spam('honeypot');
    }

    // Signed timestamp: must be ours, for this session, between 4 seconds and 24 hours old.
    $t = (string) ($input['_t'] ?? '');
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{24})$/', $t, $m) || !hash_equals(substr(enquiry_spam_sign($m[1]), 0, 24), $m[2])) {
        return $spam('bad-timestamp');
    }
    $age = time() - (int) $m[1];
    if ($age < 4) {
        return $spam('too-fast');
    }
    if ($age > 86400) {
        return ['status' => 'limit', 'message' => 'This form has been open too long. Please reload the page and try again.', 'reason' => 'expired'];
    }
    if ((string) ($input['_j'] ?? '') !== (string) (((int) $m[1]) * 7 + 13)) {
        return $spam('no-javascript');
    }

    if (enquiry_turnstile_enabled() && !enquiry_turnstile_verify((string) ($input['cf-turnstile-response'] ?? ''))) {
        return ['status' => 'limit', 'message' => 'Please complete the security check and send again.', 'reason' => 'turnstile'];
    }

    // Content checks.
    $name = (string) ($input['name'] ?? '');
    $message = (string) ($input['message'] ?? '');
    $subject = (string) ($input['subject'] ?? '');
    $all = $name . ' ' . $subject . ' ' . $message;
    if (preg_match('~https?://|www\.~i', $name . ' ' . $subject)) {
        return $spam('link-in-name');
    }
    $links = preg_match_all('~https?://|www\.|\[url|<a\s~i', $message);
    if ($links > 2) {
        return $spam('many-links');
    }
    if (preg_match('/(viagra|cialis|casino|crypto|bitcoin|backlink|seo (service|expert|package)|guest post|payday loan|forex|porn|escort|click here|buy now|work from home|telegram|whatsapp me)/i', $all)) {
        return $spam('keywords');
    }
    if (preg_match('/[\x{0400}-\x{04FF}\x{4E00}-\x{9FFF}\x{0600}-\x{06FF}]{6,}/u', $all)) {
        return $spam('foreign-script');
    }
    if (preg_match('/(.)\1{14,}/u', $all)) {
        return $spam('repeated-characters');
    }

    // Rate limits that survive a bot throwing away its session cookie.
    enquiry_ensure_throttle($pdo);
    $ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|enq');
    $emailHash = hash('sha256', strtolower(trim((string) ($input['email'] ?? ''))) . '|enq');
    $msgHash = hash('sha256', strtolower(preg_replace('/\s+/', ' ', trim($message))) . '|enq');
    $count = static function (string $col, string $hash, string $interval) use ($pdo): int {
        $s = $pdo->prepare("SELECT COUNT(*) FROM enquiry_throttle WHERE $col = ? AND created_at > (NOW() - INTERVAL $interval)");
        $s->execute([$hash]);
        return (int) $s->fetchColumn();
    };
    if ($count('msg_hash', $msgHash, '1 DAY') > 0) {
        return $spam('duplicate');
    }
    if ($count('ip_hash', $ipHash, '15 MINUTE') >= 3 || $count('ip_hash', $ipHash, '1 DAY') >= 10) {
        return ['status' => 'limit', 'message' => 'You have sent several messages recently. Please try again later.', 'reason' => 'ip-limit'];
    }
    if ($count('email_hash', $emailHash, '1 DAY') >= 3) {
        return ['status' => 'limit', 'message' => 'We already have several messages from that email address today. Please wait for a reply.', 'reason' => 'email-limit'];
    }
    $total = (int) $pdo->query("SELECT COUNT(*) FROM enquiry_throttle WHERE created_at > (NOW() - INTERVAL 1 HOUR)")->fetchColumn();
    if ($total >= 40) {
        return ['status' => 'limit', 'message' => 'We are receiving a lot of messages right now. Please try again in a little while.', 'reason' => 'site-flood'];
    }

    $ok['hashes'] = [$ipHash, $emailHash, $msgHash];
    return $ok;
}

/** Count a submission against the limits. Call only after the enquiry was really saved, so fixing a typo and resending is never blocked. */
function enquiry_guard_record(PDO $pdo, array $guard): void
{
    if (!empty($guard['hashes'])) {
        $pdo->prepare('INSERT INTO enquiry_throttle (ip_hash, email_hash, msg_hash, created_at) VALUES (?, ?, ?, NOW())')
            ->execute($guard['hashes']);
    }
}

/** Log a dropped submission (no personal details) so it can be reviewed in the server error log. */
function enquiry_spam_log(string $reason): void
{
    error_log('[enquiry-spam] dropped (' . $reason . ') ip=' . substr(hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 10));
}
