<?php

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

function hub_mail_config_value(string $key, string $default = ''): string
{
    if (function_exists('hub_config_value')) {
        return hub_config_value($GLOBALS['hubFileEnv'] ?? [], $key, $default);
    }

    $runtime = getenv($key);
    return $runtime !== false && trim((string) $runtime) !== '' ? (string) $runtime : $default;
}

function hub_mail_from_address(): string
{
    return hub_mail_config_value('HUB_SMTP_FROM_EMAIL', 'noreply@saltcoatsvictoria.co.uk');
}

function hub_mail_from_name(): string
{
    return hub_mail_config_value('HUB_SMTP_FROM_NAME', 'Saltcoats Victoria FC');
}

/**
 * @return array{host:string,port:int,encryption:string,username:string,password:string,from_email:string,from_name:string}
 */
function hub_mail_smtp_config(): array
{
    return [
        'host' => hub_mail_config_value('HUB_SMTP_HOST', 'saltcoatsvictoria.co.uk'),
        'port' => (int) hub_mail_config_value('HUB_SMTP_PORT', '465'),
        'encryption' => strtolower(hub_mail_config_value('HUB_SMTP_ENCRYPTION', 'ssl')),
        'username' => hub_mail_config_value('HUB_SMTP_USERNAME', 'noreply@saltcoatsvictoria.co.uk'),
        'password' => hub_mail_config_value('HUB_SMTP_PASSWORD', ''),
        'from_email' => hub_mail_from_address(),
        'from_name' => hub_mail_from_name(),
    ];
}

function hub_mail_encode_header(string $value): string
{
    return function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n")
        : $value;
}

function hub_mail_header_address(string $email, string $name = ''): string
{
    return $name !== '' ? hub_mail_encode_header($name) . ' <' . $email . '>' : $email;
}

function hub_mail_smtp_read($socket): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    return $response;
}

/**
 * @param int|list<int> $expected
 */
function hub_mail_smtp_command($socket, string $command, int|array $expected): string
{
    if ($command !== '') {
        fwrite($socket, $command . "\r\n");
    }

    $response = hub_mail_smtp_read($socket);
    $code = (int) substr($response, 0, 3);
    $expectedCodes = is_array($expected) ? $expected : [$expected];
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP command failed: ' . trim($response));
    }

    return $response;
}

function hub_mail_smtp_escape_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);
    foreach ($lines as &$line) {
        if (str_starts_with($line, '.')) {
            $line = '.' . $line;
        }
    }
    unset($line);

    return implode("\r\n", $lines);
}

/** @param array<string,string> $override optional username/password/from_email/from_name to send as a different mailbox */
function hub_mail_smtp_send(string $toEmail, string $subject, string $body, string $contentType, ?string $replyTo = null, array $override = []): bool
{
    $config = array_merge(hub_mail_smtp_config(), $override);
    if ($config['host'] === '' || $config['username'] === '' || $config['password'] === '') {
        return false;
    }

    $transport = $config['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => filter_var(hub_mail_config_value('HUB_SMTP_VERIFY_PEER', '0'), FILTER_VALIDATE_BOOL),
            'verify_peer_name' => filter_var(hub_mail_config_value('HUB_SMTP_VERIFY_PEER', '0'), FILTER_VALIDATE_BOOL),
            'allow_self_signed' => true,
        ],
    ]);
    $socket = @stream_socket_client($transport . $config['host'] . ':' . $config['port'], $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        error_log('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
        return false;
    }

    stream_set_timeout($socket, 20);

    try {
        hub_mail_smtp_command($socket, '', 220);
        hub_mail_smtp_command($socket, 'EHLO saltcoatsvictoria.co.uk', 250);
        if (in_array($config['encryption'], ['tls', 'starttls'], true)) {
            hub_mail_smtp_command($socket, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }
            hub_mail_smtp_command($socket, 'EHLO saltcoatsvictoria.co.uk', 250);
        }

        hub_mail_smtp_command($socket, 'AUTH LOGIN', 334);
        hub_mail_smtp_command($socket, base64_encode($config['username']), 334);
        hub_mail_smtp_command($socket, base64_encode($config['password']), 235);
        hub_mail_smtp_command($socket, 'MAIL FROM:<' . $config['from_email'] . '>', 250);
        hub_mail_smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        hub_mail_smtp_command($socket, 'DATA', 354);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@saltcoatsvictoria.co.uk>',
            'From: ' . hub_mail_header_address($config['from_email'], $config['from_name']),
            'To: ' . $toEmail,
            'Subject: ' . hub_mail_encode_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: ' . $contentType . '; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        fwrite($socket, hub_mail_smtp_escape_body(implode("\r\n", $headers) . "\r\n\r\n" . $body) . "\r\n.\r\n");
        hub_mail_smtp_command($socket, '', 250);
        hub_mail_smtp_command($socket, 'QUIT', 221);
        fclose($socket);

        return true;
    } catch (Throwable $e) {
        error_log($e->getMessage());
        @fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return false;
    }
}

/** The address people are told to contact: the club's contact email in Settings, so it can change without code. */
function hub_mail_help_address(): string
{
    static $addr = null;
    if ($addr === null) {
        $addr = '';
        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo instanceof PDO) {
            try {
                $q = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'contact_email' LIMIT 1");
                $q->execute();
                $v = trim((string) $q->fetchColumn());
                $addr = filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : '';
            } catch (Throwable $e) {
                $addr = '';
            }
        }
    }
    return $addr;
}

/** Footer for automatic (no-reply) emails: tells people where to go if they need a person. */
function hub_mail_system_footer(bool $html): string
{
    $help = hub_mail_help_address();
    $text = 'This is an automatic message from Saltcoats Victoria FC, so please do not reply to this address. '
        . 'If you have a question or a problem, contact us ' . ($help !== '' ? 'at ' . $help . ' or ' : '') . 'through https://saltcoatsvictoria.co.uk/contact';
    if ($html) {
        return '<p style="margin-top:24px;padding-top:12px;border-top:1px solid #dddddd;color:#666666;font-size:12px;line-height:1.5">'
            . 'This is an automatic message from Saltcoats Victoria FC, so please do not reply to this address. '
            . 'If you have a question or a problem, contact us '
            . ($help !== '' ? 'at <a href="mailto:' . htmlspecialchars($help, ENT_QUOTES) . '">' . htmlspecialchars($help, ENT_QUOTES) . '</a> or ' : '')
            . 'through <a href="https://saltcoatsvictoria.co.uk/contact">our contact page</a>.</p>';
    }
    return "\n\n--\n" . $text . "\n";
}

function hub_mail_add_footer(string $body, bool $html): string
{
    if ($html) {
        return stripos($body, '</body>') !== false
            ? preg_replace('~</body>~i', hub_mail_system_footer(true) . '</body>', $body, 1)
            : $body . hub_mail_system_footer(true);
    }
    return rtrim($body) . hub_mail_system_footer(false);
}

function hub_send_mail(string $toEmail, string $subject, string $body, bool $isHtml = false, ?string $replyTo = null, array $override = []): bool
{
    $toEmail = trim($toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if ($override === [] && $replyTo === null) {
        // Automatic Hub email (orders, tickets, accounts...): add the "contact us" footer, and send any stray reply to the club contact address.
        $body = hub_mail_add_footer($body, $isHtml);
        $replyTo = hub_mail_help_address() ?: null;
    }

    return hub_mail_smtp_send($toEmail, $subject, $body, $isHtml ? 'text/html' : 'text/plain', $replyTo, $override);
}

/**
 * Same raw-SMTP transport as hub_send_mail(), extended to a multipart/mixed
 * message so a generated PDF (invoices, etc.) can travel as an attachment —
 * hub_mail_smtp_send() itself only ever writes a single-part body, so this
 * builds the multipart envelope here and reuses its connection/auth/escaping
 * helpers directly rather than duplicating the SMTP dialogue.
 *
 * @param array{filename:string,content:string,mime?:string} $attachment
 */
function hub_send_mail_with_attachment(string $toEmail, string $subject, string $htmlBody, array $attachment, ?string $replyTo = null): bool
{
    $toEmail = trim($toEmail);
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $config = hub_mail_smtp_config();
    if ($config['host'] === '' || $config['username'] === '' || $config['password'] === '') {
        return false;
    }

    $htmlBody = hub_mail_add_footer($htmlBody, true);
    $replyTo = $replyTo ?? (hub_mail_help_address() ?: null);
    $boundary = 'hubmail-' . bin2hex(random_bytes(12));
    $mime = $attachment['mime'] ?? 'application/pdf';
    $filename = $attachment['filename'];
    $encodedFile = chunk_split(base64_encode($attachment['content']));

    $body = "--{$boundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $htmlBody . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: {$mime}; name=\"{$filename}\"\r\n"
        . "Content-Transfer-Encoding: base64\r\n"
        . "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n"
        . $encodedFile . "\r\n"
        . "--{$boundary}--";

    $transport = $config['encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => filter_var(hub_mail_config_value('HUB_SMTP_VERIFY_PEER', '0'), FILTER_VALIDATE_BOOL),
            'verify_peer_name' => filter_var(hub_mail_config_value('HUB_SMTP_VERIFY_PEER', '0'), FILTER_VALIDATE_BOOL),
            'allow_self_signed' => true,
        ],
    ]);
    $socket = @stream_socket_client($transport . $config['host'] . ':' . $config['port'], $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        error_log('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
        return false;
    }

    stream_set_timeout($socket, 20);

    try {
        hub_mail_smtp_command($socket, '', 220);
        hub_mail_smtp_command($socket, 'EHLO saltcoatsvictoria.co.uk', 250);
        if (in_array($config['encryption'], ['tls', 'starttls'], true)) {
            hub_mail_smtp_command($socket, 'STARTTLS', 220);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }
            hub_mail_smtp_command($socket, 'EHLO saltcoatsvictoria.co.uk', 250);
        }

        hub_mail_smtp_command($socket, 'AUTH LOGIN', 334);
        hub_mail_smtp_command($socket, base64_encode($config['username']), 334);
        hub_mail_smtp_command($socket, base64_encode($config['password']), 235);
        hub_mail_smtp_command($socket, 'MAIL FROM:<' . $config['from_email'] . '>', 250);
        hub_mail_smtp_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        hub_mail_smtp_command($socket, 'DATA', 354);

        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@saltcoatsvictoria.co.uk>',
            'From: ' . hub_mail_header_address($config['from_email'], $config['from_name']),
            'To: ' . $toEmail,
            'Subject: ' . hub_mail_encode_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        ];
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }

        // Only the plain-text envelope headers go through the dot-escaping pass;
        // the base64 attachment body must not be touched by it (base64 has no
        // significant leading dots, but escaping is line-oriented and unneeded
        // work for a chunk this size — headers+HTML part are the only text).
        fwrite($socket, hub_mail_smtp_escape_body(implode("\r\n", $headers) . "\r\n\r\n") . $body . "\r\n.\r\n");
        hub_mail_smtp_command($socket, '', 250);
        hub_mail_smtp_command($socket, 'QUIT', 221);
        fclose($socket);

        return true;
    } catch (Throwable $e) {
        error_log($e->getMessage());
        @fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return false;
    }
}
