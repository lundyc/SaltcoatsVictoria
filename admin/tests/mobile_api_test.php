<?php
declare(strict_types=1);

// CLI only. Uses an ephemeral database populated with synthetic records, never live accounts.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/mobile_api/bootstrap.php';

use MyClubHub\Api\{Application, Auth, Request, Resources};

function testConnection(?string $database = null): PDO
{
    $p = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;' . ($database ? 'dbname=' . $database . ';' : '') . 'charset=utf8mb4', 'root', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $p->exec("SET time_zone = '+00:00'");
    return $p;
}

if (($argv[1] ?? '') === '--refresh-worker') {
    $db = $argv[2] ?? '';
    if (!preg_match('/^mch_api_test_[a-f0-9]{16}$/D', $db)) exit(2);
    $p = testConnection($db);
    $app = new Application($p, MyClubHub\Api\configuration());
    $token = trim(stream_get_contents(STDIN));
    $res = $app->handle(new Request('POST', '/api/v1/auth/refresh', [], ['content-type' => 'application/json'], json_encode(['refresh_token' => $token]), '192.0.2.250'));
    echo $res->status;
    exit;
}

$root = testConnection();
$db = 'mch_api_test_' . bin2hex(random_bytes(8));
$root->exec('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4');
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo 'PASS ' . $message . "\n";
}

try {
    $p = testConnection($db);
    $source = MyClubHub\Api\connect(MyClubHub\Api\configuration());
    $tables = ['accounts','people','season_ticket_holders','identity_migration_map','orders','order_items','entitlements','ticket_credentials',
        'match_tickets','season_passes','season_ticket_types','seasons','match_fixtures','match_opponents','players','player_website_photos',
        'news_articles','site_settings','league_table_history','admissions'];
    foreach ($tables as $table) {
        $ddl = $source->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        $ddl = implode("\n", array_filter(explode("\n", $ddl), static fn($line) => !preg_match('/^\s*CONSTRAINT\s/', $line)));
        $ddl = preg_replace('/,\n\)/', "\n)", $ddl);
        $p->exec($ddl);
    }
    $source = null;
    $migration = require __DIR__ . '/../database/migrations/2026_09_18_001_mobile_api.php';
    $migration($p);
    $migration($p); // idempotent install
    $insert = static function (string $table, array $row) use ($p): void {
        $p->prepare('INSERT INTO `' . $table . '` (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
    };
    $password = 'Synthetic-only-password!';
    foreach ([1,2,3] as $id) {
        $insert('people', ['id' => $id, 'display_name' => 'API Test ' . $id, 'email' => 'test' . $id . '@example.invalid', 'email_normalized' => 'test' . $id . '@example.invalid']);
        $insert('accounts', ['id' => $id, 'person_id' => $id, 'email' => 'test' . $id . '@example.invalid', 'email_normalized' => 'test' . $id . '@example.invalid', 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $insert('season_ticket_holders', ['id' => $id + 100, 'name' => 'API Test ' . $id, 'unsubscribe_token' => bin2hex(random_bytes(16))]);
        $insert('identity_migration_map', ['old_holder_id' => $id + 100, 'person_id' => $id, 'account_id' => $id]);
    }
    $insert('seasons', ['id' => 1, 'name' => 'Test season', 'start_date' => date('Y-01-01'), 'is_current' => 1]);
    $insert('seasons', ['id' => 2, 'name' => 'Historical test', 'start_date' => '2020-01-01']);
    $insert('site_settings', ['setting_key' => 'bank_details', 'setting_value' => 'PRIVATE_BANK_DATA']);
    $insert('site_settings', ['setting_key' => 'club_name', 'setting_value' => 'Test Club']);
    $insert('season_ticket_types', ['id' => 1, 'season_id' => 1, 'name' => 'Adult', 'code' => 'adult']);
    foreach ([1,2,3] as $id) {
        $insert('match_fixtures', ['id' => $id, 'season_id' => 1, 'match_date' => $id === 1 ? date('Y-m-d', time() + 86400) : '2026-01-01',
            'opponent' => 'Test Opponent', 'is_home' => 1, 'status' => $id === 1 ? 'scheduled' : 'played',
            'full_time_home_score' => $id === 1 ? null : 1, 'full_time_away_score' => $id === 1 ? null : 1,
            'home_penalties' => $id === 1 ? null : 5, 'away_penalties' => $id === 1 ? null : 4, 'notes' => 'PRIVATE_MATCH_NOTE']);
    }
    foreach ([1,2,3] as $id) {
        $insert('news_articles', ['id' => $id, 'slug' => 'story-' . $id, 'title' => 'Story ' . $id,
            'body' => '<script>alert(1)</script><p>News</p>', 'body_format' => 'html', 'status' => $id === 2 ? 'draft' : 'published',
            'published_at' => $id === 3 ? '2099-01-01 00:00:00' : '2020-01-01 00:00:00']);
        $insert('players', ['id' => $id, 'name' => 'Player ' . $id, 'avatar' => 'photo.png', 'status' => $id === 3 ? 'left' : 'current', 'date_of_birth' => '2000-01-01']);
    }
    $insert('player_website_photos', ['player_id' => 2, 'uploaded_to_website' => 0]);
    foreach ([1,2] as $person) {
        $insert('orders', ['id' => $person, 'person_id' => $person, 'customer_name' => 'Test', 'status' => 'paid', 'total_amount' => '12.50']);
        $insert('order_items', ['id' => $person, 'order_id' => $person, 'product_type' => 'match_ticket', 'description_snapshot' => 'Entry', 'unit_price' => '12.50', 'line_total' => '12.50']);
        $insert('entitlements', ['id' => $person, 'order_item_id' => $person, 'person_id' => $person, 'type' => 'match_ticket', 'status' => 'active']);
        $insert('ticket_credentials', ['id' => $person, 'entitlement_id' => $person, 'token' => str_repeat((string) $person, 64), 'manual_code' => 'CODE' . $person]);
        $insert('match_tickets', ['id' => $person, 'entitlement_id' => $person, 'order_id' => $person, 'order_item_id' => $person,
            'fixture_id' => 1, 'package_id' => 1, 'ticket_label' => 'Adult']);
        $insert('entitlements', ['id' => $person + 10, 'person_id' => $person, 'type' => 'season_pass', 'status' => 'active']);
        $insert('ticket_credentials', ['id' => $person + 10, 'entitlement_id' => $person + 10, 'token' => str_repeat((string) $person, 63) . 'a', 'manual_code' => 'PASS' . $person]);
        $insert('season_passes', ['id' => $person, 'entitlement_id' => $person + 10, 'season_id' => 1, 'season_ticket_type_id' => 1]);
    }
    // Same email alone must never grant order access.
    $insert('orders', ['id' => 3, 'customer_name' => 'Guest', 'customer_email' => 'test1@example.invalid', 'status' => 'paid']);
    $config = MyClubHub\Api\configuration();
    $app = new Application($p, $config);
    $samples = [];
    $call = static function (string $method, string $path, ?array $body = null, ?string $token = null, array $query = [], array $headers = [], bool $secure = true) use ($app, &$samples) {
        $headers += ['content-type' => 'application/json'];
        if ($token) $headers['authorization'] = 'Bearer ' . $token;
        $response = $app->handle(new Request($method, '/api/v1' . $path, $query, $headers, $body === null ? '' : json_encode($body), '192.0.2.10', $secure));
        $samples[] = ['method' => strtolower($method), 'path' => $path, 'status' => $response->status, 'body' => $response->body];
        return $response;
    };
    $login = static fn(int $id = 1) => $call('POST', '/auth/login', ['email' => 'test' . $id . '@example.invalid', 'password' => $password, 'device_name' => 'Test phone']);
    check($call('GET', '/health')->status === 200, 'health route and schema ready');
    check($call('GET', '/health', secure: false)->status === 400, 'HTTP rejected');
    check($call('GET', '/missing')->status === 404, 'unknown route returns JSON 404');
    check($call('POST', '/club')->status === 405, 'unsupported method returns 405');
    check($call('GET', '/club', headers: ['origin' => 'https://evil.example'])->status === 403, 'untrusted browser origin rejected');
    $cors = $call('OPTIONS', '/me', headers: ['origin' => $config['base_url']]);
    check($cors->status === 204 && $cors->headers['Access-Control-Allow-Origin'] === $config['base_url'], 'allowlisted CORS preflight');
    check($call('GET', '/me')->status === 401, 'profile requires bearer token');
    check($call('GET', '/me', headers: ['cookie' => 'PHPSESSID=anything'])->status === 401, 'website cookies cannot authenticate API');
    check($call('GET', '/news', query: ['page' => ['1']])->status === 422, 'array pagination rejected');
    check($call('GET', '/news', query: ['per_page' => '999'])->status === 422, 'pagination bounded');
    check($call('GET', '/fixtures/9999999999')->status === 422, 'oversized IDs rejected');
    check($call('GET', '/news', query: ['unexpected' => 'value'])->status === 422, 'unknown query fields rejected');
    $badJson = $app->handle(new Request('POST', '/api/v1/auth/login', [], ['content-type' => 'application/json'], '{'));
    check($badJson->status === 400, 'malformed JSON rejected');
    $arrayJson = $app->handle(new Request('POST', '/api/v1/auth/login', [], ['content-type' => 'application/json'], '[]'));
    check($arrayJson->status === 400, 'JSON array rejected');
    check($call('POST', '/auth/login', [], headers: ['content-type' => 'text/plain'])->status === 415, 'incorrect content type rejected');
    check($call('POST', '/auth/login', ['email' => str_repeat('x', 17000)])->status === 413, 'body size bounded');
    check($call('POST', '/auth/login', ['email' => 'test1@example.invalid', 'password' => 'wrong'])->status === 401, 'wrong password rejected');
    $result = $login();
    check($result->status === 201, 'existing account can sign in');
    $tokens = $result->body['data']; $access = $tokens['access_token'];
    check($tokens['expires_in'] === 900 && $tokens['refresh_expires_in'] === 2592000, 'documented token lifetimes');
    $stored = $p->query('SELECT * FROM mobile_api_sessions')->fetch();
    check(!str_contains(json_encode($stored), $access) && $stored['access_hash'] === hash('sha256', $access), 'access tokens stored only as hashes');
    check($p->query('SELECT token_hash FROM mobile_api_refresh_tokens')->fetchColumn() === hash('sha256', $tokens['refresh_token']), 'refresh tokens stored only as hashes');
    $me = $call('GET', '/me', token: $access);
    check($me->status === 200 && $me->body['data']['account_id'] === 1 && $me->body['data']['person_id'] === 1, 'canonical IDs returned instead of legacy holder IDs');
    check(!str_contains(json_encode($me->body), 'password') && !isset($me->body['data']['role']), 'profile response excludes credentials and internal fields');
    check($me->headers['Cache-Control'] === 'no-store' && isset($me->headers['X-Request-ID']), 'private responses are not cached and have request IDs');
    check($call('PATCH', '/me', ['role' => 'admin'], $access)->status === 422, 'profile cannot escalate permissions');
    check($call('PATCH', '/me', ['phone' => null], $access)->status === 422, 'null rejected where a string is required');
    check($call('PATCH', '/me', ['email' => 'other@example.invalid'], $access)->status === 422, 'profile cannot silently change login identity');
    check($call('PATCH', '/me', ['display_name' => 'Updated Name', 'town' => 'Test Town'], $access)->status === 200, 'profile update accepted');
    check($p->query('SELECT name FROM season_ticket_holders WHERE id=101')->fetchColumn() === 'Updated Name', 'legacy profile stays synchronized');
    check($p->query('SELECT display_name FROM people WHERE id=2')->fetchColumn() === 'API Test 2', 'profile update leaves other members unchanged');
    $orders = $call('GET', '/me/orders', token: $access);
    check($orders->status === 200 && $orders->body['meta']['total'] === 1, 'orders scoped by person, not matching email');
    check($call('GET', '/me/orders/1', token: $access)->body['data']['total_amount'] === '12.50', 'money is an exact decimal string');
    check($call('GET', '/me/orders/2', token: $access)->status === 404, 'another member order is inaccessible');
    check($call('GET', '/me/orders/3', token: $access)->status === 404, 'unlinked guest order is inaccessible');
    check($call('GET', '/me/tickets', token: $access)->body['meta']['total'] === 1, 'ticket list scoped to owner or buyer');
    check($call('GET', '/me/tickets/1', token: $access)->body['data']['qr_payload'] === str_repeat('1',64), 'own active ticket QR returned');
    check($call('GET', '/me/tickets/2', token: $access)->status === 404, 'another member ticket is inaccessible');
    $p->exec("UPDATE entitlements SET status='cancelled' WHERE id=1");
    check($call('GET', '/me/tickets/1', token: $access)->body['data']['qr_payload'] === null, 'cancelled ticket QR withheld');
    $p->exec("UPDATE entitlements SET status='active' WHERE id=1");
    $p->exec('UPDATE ticket_credentials SET revoked_at=UTC_TIMESTAMP() WHERE id=1');
    check($call('GET', '/me/tickets/1', token: $access)->body['data']['qr_payload'] === null, 'revoked ticket QR withheld');
    $passes = $call('GET', '/me/season-passes', token: $access);
    check($passes->status === 200 && $passes->body['meta']['total'] === 1 && $passes->body['data'][0]['id'] === 1, 'season passes restricted to owner');
    $p->exec("UPDATE entitlements SET valid_until='2020-01-01' WHERE id=11");
    check($call('GET', '/me/season-passes', token: $access)->body['data'][0]['qr_payload'] === null, 'expired season pass QR withheld');
    check($call('GET', '/me/sessions', token: $access)->body['meta']['total'] === 1, 'active device sessions listed');
    check($call('GET', '/seasons')->body['meta']['total'] === 2, 'season listing includes current and historical seasons');
    $news = $call('GET', '/news');
    check($news->status === 200 && $news->body['meta']['total'] === 1, 'draft and future news excluded');
    check($call('GET', '/news/story-2')->status === 404 && $call('GET', '/news/story-3')->status === 404, 'unpublished article details inaccessible');
    check(!str_contains($call('GET', '/news/story-1')->body['data']['body_html'], '<script'), 'news HTML sanitized using website renderer');
    $players = $call('GET', '/players');
    check($players->status === 200 && $players->body['meta']['total'] === 1 && !isset($players->body['data'][0]['date_of_birth']), 'player publication rules and field whitelist');
    check($call('GET', '/players/2')->status === 404 && $call('GET', '/players/3')->status === 404, 'hidden and departed players not exposed');
    check(!str_contains(json_encode($call('GET', '/club')->body), 'PRIVATE_BANK_DATA'), 'club settings whitelist excludes bank details');
    check($call('GET', '/fixtures')->body['meta']['total'] === 1, 'upcoming fixtures correctly filtered');
    $results = $call('GET', '/results', query: ['per_page' => '1', 'page' => '2']);
    check($results->status === 200 && count($results->body['data']) === 1 && $results->body['meta']['total'] === 2, 'result pagination deterministic');
    check($results->body['data'][0]['outcome']['outcome'] === 'W', 'shootout result follows website logic');
    check(!str_contains(json_encode($call('GET', '/fixtures/1')->body), 'PRIVATE_MATCH_NOTE'), 'fixture notes excluded');
    check($call('GET', '/table', query: ['season_id' => '2'])->body['data']['available'] === false, 'missing historical table explicitly unavailable');
    $other = $login(2)->body['data'];
    check($call('DELETE', '/me/sessions/' . $other['session_id'], token: $access)->status === 404, 'cannot revoke another account session');
    $refresh = $call('POST', '/auth/refresh', ['refresh_token' => $tokens['refresh_token']]);
    check($refresh->status === 200, 'refresh rotates tokens');
    $rotated = $refresh->body['data'];
    check($call('GET', '/me', token: $access)->status === 401, 'rotation invalidates previous access token');
    check($call('GET', '/me', token: $rotated['access_token'])->status === 200, 'rotated token works');
    check($call('POST', '/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->status === 401, 'refresh replay rejected');
    check($call('GET', '/me', token: $rotated['access_token'])->status === 401, 'refresh replay revokes entire device session');
    $logoutTokens = $login(2)->body['data'];
    check($call('POST', '/auth/logout', token: $logoutTokens['access_token'])->status === 204, 'logout succeeds without response body');
    check($call('POST', '/auth/refresh', ['refresh_token' => $logoutTokens['refresh_token']])->status === 401, 'logout revokes refresh token');
    $resetTokens = $login(2)->body['data'];
    $p->prepare('UPDATE accounts SET password_hash = ? WHERE id=2')->execute([password_hash('Changed-password', PASSWORD_DEFAULT)]);
    check($call('GET', '/me', token: $resetTokens['access_token'])->status === 401, 'website password changes invalidate API access');
    check($call('POST', '/auth/refresh', ['refresh_token' => $resetTokens['refresh_token']])->status === 401, 'website password changes invalidate API refresh');
    $p->exec('UPDATE people SET is_active=0 WHERE id=3');
    check($login(3)->status === 401, 'inactive person cannot log in');
    $p->exec('UPDATE people SET is_active=1 WHERE id=3');
    $disabled = $login(3)->body['data'];
    $p->exec('UPDATE accounts SET is_active=0 WHERE id=3');
    check($call('GET', '/me', token: $disabled['access_token'])->status === 401, 'disabled account loses existing access');
    $p->exec('UPDATE accounts SET is_active=1 WHERE id=3');
    $first = $login(3)->body['data']; $second = $login(3)->body['data'];
    check($call('POST', '/auth/logout-all', token: $first['access_token'])->status === 204, 'logout all succeeds');
    check($call('GET', '/me', token: $second['access_token'])->status === 401, 'logout all revokes other device');
    $expired = $login(3)->body['data'];
    $p->prepare('UPDATE mobile_api_sessions SET access_expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?')->execute([$expired['session_id']]);
    check($call('GET', '/me', token: $expired['access_token'])->status === 401, 'expired access token rejected');
    check($call('POST', '/auth/refresh', ['refresh_token' => $expired['refresh_token']])->status === 200, 'expired access can still refresh valid session');
    $concurrent = $login(3)->body['data'];
    $workers = [];
    for ($i=0;$i<2;$i++) {
        $proc = proc_open([PHP_BINARY, __FILE__, '--refresh-worker', $db], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes);
        fwrite($pipes[0], $concurrent['refresh_token']); fclose($pipes[0]);
        $workers[] = [$proc,$pipes];
    }
    $statuses = [];
    foreach ($workers as [$proc,$pipes]) {
        $statuses[] = (int) stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($proc) === 0 && $err === '', 'concurrent worker completes cleanly');
    }
    sort($statuses);
    check($statuses === [200,401], 'simultaneous refresh cannot issue two valid token pairs');
    $q=$p->prepare('SELECT revoked_at FROM mobile_api_sessions WHERE id=?');$q->execute([$concurrent['session_id']]);
    check($q->fetchColumn() !== null, 'concurrent refresh replay revokes the session');
    $limiter = new Auth($p, str_repeat('f',32));
    $limiter->rateLimit('test-limit',1,900);
    try { $limiter->rateLimit('test-limit',1,900); throw new RuntimeException('Limit not enforced'); }
    catch (MyClubHub\Api\ApiError $e) { check($e->status===429 && (int)$e->headers['Retry-After']>0, 'atomic rate limit and retry delay'); }
    check(!str_contains(json_encode($p->query('SELECT * FROM mobile_api_audit')->fetchAll()), $password), 'audit trail excludes passwords');
    $spec = $call('GET', '/openapi.json');
    check($spec->status===200 && isset($spec->body['openapi']), 'OpenAPI document served');
    foreach(Application::ROUTES as [$method,$path]) check(isset($spec->body['paths'][$path][strtolower($method)]), "OpenAPI covers $method $path");
    if (($argv[1] ?? '') === '--samples' && isset($argv[2])) {
        file_put_contents($argv[2], json_encode($samples, JSON_THROW_ON_ERROR));
        chmod($argv[2], 0600);
    }
    echo "SUCCESS: $checks checks; synthetic database cleaned up.\n";
} finally {
    $p = null;
    $root->exec('DROP DATABASE `' . $db . '`');
}
