<?php
declare(strict_types=1);

/**
 * Save-link / fetch / status endpoint for the "Fetch from COMET" table on match_report_import.php.
 * A fetch only queues a request; a root cron job downloads the report PDF, imports it and deletes it.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
$reply = static function (array $body, int $code = 200): never { http_response_code($code); echo json_encode($body); exit; };

if (!hub_auth_is_authenticated()) { $reply(['ok' => false, 'message' => 'Your session has expired — please reload the page and log in again.'], 401); }
if (function_exists('hub_auth_has_capability') && !hub_auth_has_capability('matchday')) { $reply(['ok' => false, 'message' => 'Access denied.'], 403); }

$dir = __DIR__ . '/data/comet';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{8,32}$/', $id)) { $reply(['ok' => false, 'message' => 'Bad request.'], 400); }
    $file = "$dir/report_$id.json";
    $state = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $reply(['ok' => true, 'state' => is_array($state) ? $state : ['ok' => null, 'phase' => 'queued']]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check()) { $reply(['ok' => false, 'message' => 'Invalid request. Please reload the page.'], 400); }

$action = (string)($_POST['action'] ?? '');
$fixtureId = (int)($_POST['fixture_id'] ?? 0);
$st = $pdo->prepare('SELECT id, match_date FROM match_fixtures WHERE id = ?'); $st->execute([$fixtureId]);
if (!$st->fetch()) { $reply(['ok' => false, 'message' => 'That fixture was not found.'], 404); }

if ($action === 'save_link') {
    $raw = trim((string)($_POST['url'] ?? ''));
    if (preg_match('~^https://(?:www\.)?scottishfacomet\.co\.uk/mycomet/private/match/(\d{5,12})(?:[/?#].*)?$~i', $raw, $m)) { $mid = (int)$m[1]; }
    elseif (preg_match('/^\d{5,12}$/', $raw)) { $mid = (int)$raw; }
    else { $reply(['ok' => false, 'message' => 'Paste the match page address from COMET, e.g. https://www.scottishfacomet.co.uk/mycomet/private/match/54097581']); }
    $dup = $pdo->prepare('SELECT fixture_id FROM fixture_comet_links WHERE comet_match_id = ? AND fixture_id <> ?'); $dup->execute([$mid, $fixtureId]);
    if ($other = $dup->fetchColumn()) { $reply(['ok' => false, 'message' => "That COMET match is already linked to another fixture (#$other)."]); }
    $url = 'https://www.scottishfacomet.co.uk/mycomet/private/match/' . $mid;
    $pdo->prepare('INSERT INTO fixture_comet_links (fixture_id, comet_match_id, url) VALUES (?,?,?) ON DUPLICATE KEY UPDATE comet_match_id = VALUES(comet_match_id), url = VALUES(url), last_fetched_at = NULL')->execute([$fixtureId, $mid, $url]);
    $reply(['ok' => true, 'url' => $url, 'message' => 'Link saved.']);
}
if ($action === 'clear_link') {
    $pdo->prepare('DELETE FROM fixture_comet_links WHERE fixture_id = ?')->execute([$fixtureId]);
    $reply(['ok' => true, 'message' => 'Link removed.']);
}
if ($action === 'fetch') {
    $st = $pdo->prepare('SELECT comet_match_id FROM fixture_comet_links WHERE fixture_id = ?'); $st->execute([$fixtureId]);
    $mid = (int)$st->fetchColumn();
    if ($mid <= 0) { $reply(['ok' => false, 'message' => 'Save the COMET link for this match first.']); }
    $pending = glob("$dir/queue/*.rep") ?: [];
    foreach ($pending as $p) { if (str_starts_with((string)file_get_contents($p), $fixtureId . ' ')) { $reply(['ok' => false, 'message' => 'A fetch for this match is already waiting.']); } }
    if (count($pending) >= 6) { $reply(['ok' => false, 'message' => 'Several fetches are already waiting. Please try again in a minute.']); }
    $id = bin2hex(random_bytes(8));
    file_put_contents("$dir/report_$id.json", json_encode(['ok' => null, 'phase' => 'queued']), LOCK_EX);
    file_put_contents("$dir/queue/$id.rep", "$fixtureId $mid $id", LOCK_EX);
    $reply(['ok' => true, 'id' => $id]);
}
$reply(['ok' => false, 'message' => 'Unknown action.'], 400);
