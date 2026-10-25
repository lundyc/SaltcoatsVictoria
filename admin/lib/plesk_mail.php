<?php

declare(strict_types=1);

/**
 * Mailboxes and forwarding addresses for the club domain, managed through the
 * Plesk REST API (CLI gateway). The Hub never gets server admin rights: it
 * holds one API key (PLESK_API_KEY in admin/.env) that Plesk only accepts from
 * this server, and this file only ever issues `plesk bin mail` commands for
 * @saltcoatsvictoria.co.uk.
 */

require_once __DIR__ . '/mailer.php';

const PLESK_MAIL_DOMAIN = 'saltcoatsvictoria.co.uk';
/** Local parts the Hub depends on or that must never be created/removed from here. */
const PLESK_MAIL_PROTECTED = ['orders', 'postmaster', 'abuse', 'mailer-daemon', 'root', 'admin', 'no-reply', 'noreply'];

function plesk_mail_configured(): bool
{
    return hub_mail_config_value('PLESK_API_KEY', '') !== '';
}

/** @return array{ok:bool,stdout:string,stderr:string,code:int} */
function plesk_mail_cli(array $params): array
{
    $key = hub_mail_config_value('PLESK_API_KEY', '');
    $base = rtrim(hub_mail_config_value('PLESK_API_URL', 'https://127.0.0.1:8443'), '/');
    if ($key === '') {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'The Plesk API key is not set up yet.', 'code' => -1];
    }
    $ch = curl_init($base . '/api/v2/cli/mail/call');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['params' => array_values($params)]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-API-Key: ' . $key],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        // Plesk's own certificate on the loopback address is self-signed; only ever talk to this server.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $http === 0) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'Could not reach Plesk.', 'code' => -1];
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data) || !array_key_exists('code', $data)) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'Plesk refused the request (HTTP ' . $http . ').', 'code' => $http];
    }
    return ['ok' => (int) $data['code'] === 0, 'stdout' => (string) ($data['stdout'] ?? ''), 'stderr' => trim((string) ($data['stderr'] ?? '')), 'code' => (int) $data['code']];
}

function plesk_mail_valid_local(string $local): bool
{
    return (bool) preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,38}[a-z0-9])?$/', $local);
}

function plesk_mail_is_protected(string $local): bool
{
    return in_array(strtolower($local), PLESK_MAIL_PROTECTED, true);
}

function plesk_mail_address(string $local): string
{
    return strtolower($local) . '@' . PLESK_MAIL_DOMAIN;
}

/**
 * Every address on the domain with its type and forwarding targets.
 *
 * @return list<array{local:string,address:string,type:string,forwards:list<string>,protected:bool}>
 */
function plesk_mail_list(): array
{
    $res = plesk_mail_cli(['--list']);
    if (!$res['ok']) {
        throw new RuntimeException($res['stderr'] !== '' ? $res['stderr'] : 'Could not read the mail list.');
    }
    $out = [];
    foreach (preg_split('/\R/', $res['stdout']) as $line) {
        if (!preg_match('/^Mail name\s+(\S+)@' . preg_quote(PLESK_MAIL_DOMAIN, '/') . '\s*$/i', trim($line), $m)) {
            continue;
        }
        $local = strtolower($m[1]);
        $info = plesk_mail_cli(['--info', plesk_mail_address($local)]);
        $mailbox = (bool) preg_match('/^Mailbox:\s+true/mi', $info['stdout']);
        $forwards = [];
        if (preg_match('/^Mailgroup:\s+true/mi', $info['stdout'])) {
            foreach (preg_split('/\R/', $info['stdout']) as $l) {
                if (preg_match('/^\s*(?:Group member(?:\(s\))?|Member|Forwarding address(?:es)?)\s*:\s*(.+)$/i', $l, $mm)) {
                    foreach (preg_split('/[\s,]+/', trim($mm[1])) as $a) {
                        if ($a !== '') {
                            $forwards[] = strtolower($a);
                        }
                    }
                }
            }
        }
        $out[] = [
            'local' => $local,
            'address' => plesk_mail_address($local),
            'type' => $mailbox ? ($forwards ? 'mailbox + forward' : 'mailbox') : 'forward',
            'forwards' => array_values(array_unique($forwards)),
            'protected' => plesk_mail_is_protected($local),
        ];
    }
    return $out;
}

/** Create a forwarding-only address (no mailbox). @param list<string> $targets */
function plesk_mail_create_forwarder(string $local, array $targets): array
{
    $local = strtolower(trim($local));
    if (!plesk_mail_valid_local($local) || plesk_mail_is_protected($local)) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'That address name is not allowed.', 'code' => -1];
    }
    return plesk_mail_cli(['--create', plesk_mail_address($local), '-mailbox', 'false', '-forwarding', 'true', '-forwarding-addresses', 'add:' . implode(',', $targets)]);
}

/** Create a normal mailbox. */
function plesk_mail_create_mailbox(string $local, string $password): array
{
    $local = strtolower(trim($local));
    if (!plesk_mail_valid_local($local) || plesk_mail_is_protected($local)) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'That address name is not allowed.', 'code' => -1];
    }
    return plesk_mail_cli(['--create', plesk_mail_address($local), '-mailbox', 'true', '-passwd', $password, '-passwd_type', 'plain']);
}

function plesk_mail_set_password(string $local, string $password): array
{
    if (plesk_mail_is_protected($local)) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'This address is used by the Hub and cannot be changed here.', 'code' => -1];
    }
    return plesk_mail_cli(['--update', plesk_mail_address($local), '-passwd', $password, '-passwd_type', 'plain']);
}

/** @param list<string> $add @param list<string> $remove */
function plesk_mail_update_forwards(string $local, array $add, array $remove): array
{
    if (plesk_mail_is_protected($local)) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'This address is used by the Hub and cannot be changed here.', 'code' => -1];
    }
    $args = ['--update', plesk_mail_address($local), '-forwarding', 'true'];
    if ($add) {
        array_push($args, '-forwarding-addresses', 'add:' . implode(',', $add));
    }
    $res = plesk_mail_cli($args);
    if ($res['ok'] && $remove) {
        $res = plesk_mail_cli(['--update', plesk_mail_address($local), '-forwarding-addresses', 'del:' . implode(',', $remove)]);
    }
    return $res;
}

function plesk_mail_remove(string $local): array
{
    if (plesk_mail_is_protected($local)) {
        return ['ok' => false, 'stdout' => '', 'stderr' => 'This address is used by the Hub and cannot be removed.', 'code' => -1];
    }
    return plesk_mail_cli(['--remove', plesk_mail_address($local)]);
}
