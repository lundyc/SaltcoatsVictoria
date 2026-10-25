<?php

declare(strict_types=1);

// AJAX endpoint behind the Next Match page: choose or shuffle the player photo and
// render the PNG the page downloads and posts.
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/next_match_graphic.php';

header('Content-Type: application/json; charset=UTF-8');

/** @return never */
function nm_action_respond(bool $ok, string $message, array $extra = [], int $status = 200): void
{
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    nm_action_respond(false, 'Method not allowed.', [], 405);
}
if (!csrf_check()) {
    nm_action_respond(false, 'Invalid security token. Please refresh and try again.', [], 400);
}

$fixtureId = (int)($_POST['fixture_id'] ?? 0);
$stmt = $pdo->prepare('SELECT id FROM match_fixtures WHERE id = :id');
$stmt->execute([':id' => $fixtureId]);
if (!$stmt->fetchColumn()) {
    nm_action_respond(false, 'Fixture not found.', [], 404);
}

$action = (string)($_POST['action'] ?? '');
$type = next_match_graphic_type($_POST['type'] ?? '');
next_match_graphic_ensure_schema($pdo);
$c = next_match_graphic_cols($type);
if ($action === 'choose') {
    $playerId = (int)($_POST['player_id'] ?? 0);
    $shotId = (int)($_POST['shot_id'] ?? 0);
    $found = null;
    foreach (next_match_graphic_players_with_shots($pdo) as $p) {
        if ($p['id'] !== $playerId) {
            continue;
        }
        if ($shotId === 0) {
            // "Different photo": any of this player's shots, not the one showing now.
            $current = (int)($pdo->query('SELECT ' . $c['shot'] . ' FROM match_fixtures WHERE id = ' . $fixtureId)->fetchColumn() ?: 0);
            $others = array_values(array_filter($p['shots'], static fn(array $s): bool => $s['id'] !== $current));
            $pool = $others !== [] ? $others : $p['shots'];
            $found = $pool[array_rand($pool)];
            break;
        }
        foreach ($p['shots'] as $s) {
            if ($s['id'] === $shotId) {
                $found = $s;
                break 2;
            }
        }
    }
    if ($found === null) {
        nm_action_respond(false, 'That player has no action shot to use.', [], 400);
    }
    next_match_graphic_save_choice($pdo, $fixtureId, $playerId, $found['id'], $type);
    $pdo->prepare('UPDATE match_fixtures SET ' . $c['bg'] . ' = NULL WHERE id = :id')->execute([':id' => $fixtureId]);
    nm_action_respond(true, 'Photo chosen.', ['shot_id' => $found['id']]);
}

if ($action === 'clear_background') {
    $pdo->prepare('UPDATE match_fixtures SET ' . $c['bg'] . ' = NULL WHERE id = :id')->execute([':id' => $fixtureId]);
    nm_action_respond(true, 'Background removed.');
}

if ($action === 'set_view') {
    $zoom = max(100, min(300, (int)($_POST['zoom'] ?? 100)));
    $x = max(0, min(100, (int)($_POST['x'] ?? 50)));
    $y = max(0, min(100, (int)($_POST['y'] ?? 22)));
    $pdo->prepare('UPDATE match_fixtures SET ' . $c['zoom'] . ' = :z, ' . $c['x'] . ' = :x, ' . $c['y'] . ' = :y WHERE id = :id')
        ->execute([':z' => $zoom, ':x' => $x, ':y' => $y, ':id' => $fixtureId]);
    nm_action_respond(true, 'Zoom saved.');
}

if ($action === 'save_lineup') {
    if (!hub_auth_has_capability('matchday')) {
        nm_action_respond(false, 'You do not have permission to change line-ups.', [], 403);
    }
    require_once __DIR__ . '/lib/matchday_record.php';
    $payload = json_decode((string)($_POST['lineup'] ?? ''), true);
    if (!is_array($payload)) {
        nm_action_respond(false, 'The line-up could not be read.', [], 400);
    }
    $squad = [];
    foreach ($pdo->query('SELECT id, name FROM players WHERE active = 1 AND status = \'current\'')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $squad[(int)$r['id']] = (string)$r['name'];
    }
    $existing = [];
    foreach ((matchday_record_load($pdo, $fixtureId)['lineups']['svfc'] ?? []) as $r) {
        if (!empty($r['player_id'])) {
            $existing[(int)$r['player_id']] = $r;
        }
    }
    $captainId = (int)($payload['captain'] ?? 0);
    $rows = [];
    $seen = [];
    $order = 0;
    foreach ([['starters', true, 11], ['subs', false, 20]] as [$key, $starting, $max]) {
        $n = 0;
        foreach ((array)($payload[$key] ?? []) as $entry) {
            $hasNum = is_array($entry) && array_key_exists('num', $entry);
            $pid = (int)(is_array($entry) ? ($entry['id'] ?? 0) : $entry);
            if ($pid <= 0 || !isset($squad[$pid]) || isset($seen[$pid]) || $n >= $max) {
                continue;
            }
            $seen[$pid] = true;
            $n++;
            // The shirt number typed on the Starting XI picker is saved to the same line-up the Line-ups tab edits.
            $shirt = $existing[$pid]['shirt_number'] ?? null;
            if ($hasNum) {
                $typed = trim((string)$entry['num']);
                $shirt = $typed === '' ? null : max(0, min(999, (int)$typed));
            }
            if ($starting && $shirt === null) {
                $shirt = $n; // starters are numbered automatically by their place in the XI
            }
            $rows[] = [
                'player_id' => $pid,
                'player_name' => $squad[$pid],
                'shirt_number' => $shirt,
                'position_label' => $existing[$pid]['position_label'] ?? null,
                'is_starting' => $starting,
                'is_captain' => $starting && $pid === $captainId,
                'sort_order' => ($order += 10),
            ];
        }
    }
    matchday_lineup_replace($pdo, $fixtureId, 'svfc', $rows);
    nm_action_respond(true, 'Line-up saved.');
}

if ($action === 'list_media') {
    $stmt = $pdo->prepare('SELECT mp.id, mp.filename, mp.match_fixture_id AS fid, f.opponent, f.match_date
        FROM match_photos mp JOIN match_fixtures f ON f.id = mp.match_fixture_id
        ORDER BY (mp.match_fixture_id = :cur) DESC, mp.id DESC LIMIT 400');
    $stmt->execute([':cur' => $fixtureId]);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $file = basename((string)$r['filename']);
        if ($file === '' || !is_file(dirname(__DIR__) . '/uploads/matches/gallery/' . (int)$r['fid'] . '/' . $file)) {
            continue;
        }
        $items[] = ['id' => (int)$r['id'], 'url' => '/uploads/matches/gallery/' . (int)$r['fid'] . '/' . rawurlencode($file),
            'label' => trim((string)$r['opponent']) . ' · ' . (string)$r['match_date'], 'current' => (int)$r['fid'] === $fixtureId];
    }
    nm_action_respond(true, 'Media.', ['items' => $items]);
}

if ($action === 'use_media') {
    $stmt = $pdo->prepare('SELECT match_fixture_id, filename FROM match_photos WHERE id = :id');
    $stmt->execute([':id' => (int)($_POST['photo_id'] ?? 0)]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    $file = $photo ? basename((string)$photo['filename']) : '';
    $rel = $photo ? 'uploads/matches/gallery/' . (int)$photo['match_fixture_id'] . '/' . $file : '';
    if ($file === '' || !is_file(dirname(__DIR__) . '/' . $rel)) {
        nm_action_respond(false, 'That photo could not be found.', [], 404);
    }
    $prev = $pdo->prepare('SELECT ' . $c['bg'] . ' FROM match_fixtures WHERE id = :id');
    $prev->execute([':id' => $fixtureId]);
    $previous = trim((string)$prev->fetchColumn());
    $pdo->prepare('UPDATE match_fixtures SET ' . $c['bg'] . ' = :bg WHERE id = :id')->execute([':bg' => $rel, ':id' => $fixtureId]);
    if (preg_match('#^uploads/matches/fixture_[^/]+$#', $previous) === 1 && is_file(dirname(__DIR__) . '/' . $previous)) {
        @unlink(dirname(__DIR__) . '/' . $previous);
    }
    nm_action_respond(true, 'Background set.');
}

if ($action === 'save_score') {
    $h = max(0, min(99, (int)($_POST['home'] ?? 0)));
    $a = max(0, min(99, (int)($_POST['away'] ?? 0)));
    $scoreCols = $type === 'full_time' ? ['full_time_home_score', 'full_time_away_score'] : ['half_time_home_score', 'half_time_away_score'];
    $pdo->prepare('UPDATE match_fixtures SET ' . $scoreCols[0] . ' = :h, ' . $scoreCols[1] . ' = :a WHERE id = :id')->execute([':h' => $h, ':a' => $a, ':id' => $fixtureId]);
    nm_action_respond(true, 'Score saved.');
}

if ($action === 'save_subs') {
    $payload = json_decode((string)($_POST['subs'] ?? ''), true);
    if (!is_array($payload)) {
        nm_action_respond(false, 'The substitutions could not be read.', [], 400);
    }
    $known = [];
    foreach ($pdo->query('SELECT id FROM players WHERE active = 1')->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $known[(int)$pid] = true;
    }
    $clean = [];
    foreach (array_slice($payload, 0, 4) as $pair) {
        $off = (int)($pair['off'] ?? 0);
        $on = (int)($pair['on'] ?? 0);
        if ($off > 0 && $on > 0 && $off !== $on && isset($known[$off], $known[$on])) {
            $clean[] = ['off' => $off, 'on' => $on];
        }
    }
    next_match_graphic_ensure_schema($pdo);
    $pdo->prepare('UPDATE match_fixtures SET substitution_graphic_json = :j WHERE id = :id')->execute([':j' => json_encode($clean), ':id' => $fixtureId]);
    nm_action_respond(true, 'Substitutions saved.', ['count' => count($clean)]);
}

if ($action === 'save_event_choice') {
    require_once __DIR__ . '/lib/event_graphic_data.php';
    if (!nm_event_graphic_is_event_type($type)) {
        nm_action_respond(false, 'That graphic has no event to choose.', [], 400);
    }
    next_match_graphic_ensure_schema($pdo);
    $incoming = json_decode((string)($_POST['choice'] ?? ''), true);
    $incoming = is_array($incoming) ? $incoming : [];
    $store = [];
    if (isset($incoming['manual']) && is_array($incoming['manual'])) {
        $m = $incoming['manual'];
        $store = ['manual' => [
            'side' => ($m['side'] ?? '') === 'opponent' ? 'opponent' : 'svfc',
            'player_id' => (int)($m['player_id'] ?? 0),
            'player_name' => mb_substr(trim((string)($m['player_name'] ?? '')), 0, 120),
            'minute' => mb_substr(trim((string)($m['minute'] ?? '')), 0, 8),
            'outcome' => in_array(($m['outcome'] ?? ''), ['scored', 'missed', 'saved'], true) ? $m['outcome'] : 'scored',
            'own_goal' => !empty($m['own_goal']),
        ]];
        if (isset($m['score_home'], $m['score_away'])) { // otherwise the live score is used
            $store['manual']['score_home'] = max(0, min(99, (int)$m['score_home']));
            $store['manual']['score_away'] = max(0, min(99, (int)$m['score_away']));
        }
    } elseif ((int)($incoming['event_id'] ?? 0) > 0) {
        $store = ['event_id' => (int)$incoming['event_id']];
    }
    $existing = json_decode((string)$pdo->query('SELECT event_graphic_json FROM match_fixtures WHERE id = ' . $fixtureId)->fetchColumn(), true);
    $existing = is_array($existing) ? $existing : [];
    if ($store === []) {
        unset($existing[$type]);
    } else {
        $existing[$type] = $store;
    }
    $pdo->prepare('UPDATE match_fixtures SET event_graphic_json = :j WHERE id = :id')->execute([':j' => $existing ? json_encode($existing) : null, ':id' => $fixtureId]);
    nm_action_respond(true, 'Choice saved.');
}

if ($action === 'shuffle') {
    next_match_graphic_save_choice($pdo, $fixtureId, null, null, $type);
    $pdo->prepare('UPDATE match_fixtures SET ' . $c['bg'] . ' = NULL WHERE id = :id')->execute([':id' => $fixtureId]);
    $choice = next_match_graphic_choose_photo($pdo, $fixtureId, true, $type);
    if ($choice === null) {
        nm_action_respond(false, 'No players have an action shot yet.', [], 400);
    }
    nm_action_respond(true, 'Picked ' . $choice['name'] . '.', ['player_id' => $choice['player_id'], 'shot_id' => $choice['shot_id']]);
}

if ($action === 'render') {
    $res = next_match_graphic_render($pdo, $fixtureId, null, next_match_graphic_type($_POST['type'] ?? ''));
    if (!$res['ok']) {
        error_log('Next match render failed: ' . $res['error']);
        nm_action_respond(false, 'The graphic could not be generated. ' . $res['error'], [], 500);
    }
    nm_action_respond(true, 'Rendered.', ['image_url' => '/admin/export/matches/' . basename($res['path']) . '?v=' . time()]);
}

nm_action_respond(false, 'Unknown action.', [], 400);
