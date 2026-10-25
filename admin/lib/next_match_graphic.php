<?php

declare(strict_types=1);

/**
 * "Next Match" graphic: data, player/photo choice and the server-side render.
 *
 * The card itself is plain HTML (match_next_match_card.php). Headless Chrome
 * screenshots it (render_lib.php), so a PNG can be produced with nobody
 * logged in, which is what lets the cron job prepare it ahead of time.
 */

require_once __DIR__ . '/match_sponsorship.php';
require_once dirname(__DIR__) . '/render_lib.php';
require_once __DIR__ . '/render_access_token.php';

const NEXT_MATCH_CARD_WIDTH = 1080;
const NEXT_MATCH_CARD_HEIGHT = 1350;

/** Normalise a graphic type to 'next_match', 'matchday' or 'starting_xi'. */
function next_match_graphic_type($value): string
{
    $v = (string)$value;
    if ($v === 'penalty') { // the single Penalty graphic is now Awarded / Saved / Missed
        return 'penalty_awarded';
    }
    if ($v === 'next_match_2') { // retired: the second design is now the Next Match design
        return 'next_match';
    }
    return in_array($v, ['next_match_2', 'matchday', 'starting_xi', 'half_time', 'full_time', 'substitution', 'goal', 'yellow_card', 'red_card', 'penalty', 'penalty_awarded', 'penalty_saved', 'penalty_missed', 'kickoff', 'second_half'], true) ? $v : 'next_match';
}

/** Column names that hold the photo choice and zoom for a graphic type ('next_match' or 'matchday'). */
function next_match_graphic_cols(string $type): array
{
    $p = next_match_graphic_type($type);
    return ['player' => $p . '_player_id', 'shot' => $p . '_shot_id', 'bg' => $p . '_background_image',
        'zoom' => $p . '_bg_zoom', 'x' => $p . '_bg_x', 'y' => $p . '_bg_y'];
}

function next_match_graphic_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $have = [];
    foreach ($pdo->query('SHOW COLUMNS FROM match_fixtures') ?: [] as $col) {
        $have[(string)$col['Field']] = true;
    }
    if (!isset($have['next_match_player_id'])) {
        $pdo->exec('ALTER TABLE match_fixtures ADD COLUMN next_match_player_id INT NULL DEFAULT NULL');
    }
    foreach (['next_match_bg_zoom' => 100, 'next_match_bg_x' => 50, 'next_match_bg_y' => 22] as $col => $default) {
        if (!isset($have[$col])) {
            $pdo->exec('ALTER TABLE match_fixtures ADD COLUMN ' . $col . ' SMALLINT NOT NULL DEFAULT ' . $default);
        }
    }
    if (!isset($have['event_graphic_json'])) {
        $pdo->exec('ALTER TABLE match_fixtures ADD COLUMN event_graphic_json TEXT NULL DEFAULT NULL');
    }
    if (!isset($have['substitution_graphic_json'])) {
        $pdo->exec('ALTER TABLE match_fixtures ADD COLUMN substitution_graphic_json TEXT NULL DEFAULT NULL');
    }
    foreach (['next_match_2', 'matchday', 'starting_xi', 'half_time', 'full_time', 'substitution', 'goal', 'yellow_card', 'red_card', 'penalty', 'penalty_awarded', 'penalty_saved', 'penalty_missed', 'kickoff', 'second_half'] as $prefix) {
        foreach ([$prefix . '_player_id' => 'INT NULL DEFAULT NULL', $prefix . '_shot_id' => 'INT NULL DEFAULT NULL', $prefix . '_background_image' => 'VARCHAR(255) NULL DEFAULT NULL',
            $prefix . '_bg_zoom' => 'SMALLINT NOT NULL DEFAULT 100', $prefix . '_bg_x' => 'SMALLINT NOT NULL DEFAULT 50', $prefix . '_bg_y' => 'SMALLINT NOT NULL DEFAULT 22'] as $col => $def) {
            if (!isset($have[$col])) {
                $pdo->exec('ALTER TABLE match_fixtures ADD COLUMN ' . $col . ' ' . $def);
            }
        }
    }
    if (!isset($have['next_match_shot_id'])) {
        $pdo->exec('ALTER TABLE match_fixtures ADD COLUMN next_match_shot_id INT NULL DEFAULT NULL');
    }
}

function next_match_graphic_slug(string $value): string
{
    return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))), '-');
}

/** Public URL (root-relative) of a club badge, white version first. */
function next_match_graphic_badge(PDO $pdo, string $club, bool $white = true): string
{
    $url = matchOpponentBadgeAssetUrl($pdo, $club, $white);
    if ($url !== '') {
        return $url;
    }
    $slug = next_match_graphic_slug($club);
    $dir = dirname(__DIR__) . '/badges' . ($white ? '/white' : '');
    $best = '';
    foreach (is_dir($dir) ? (glob($dir . '/*') ?: []) : [] as $path) {
        if (!is_file($path) || !in_array(strtolower((string)pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
            continue;
        }
        $fileSlug = next_match_graphic_slug((string)pathinfo($path, PATHINFO_FILENAME));
        if ($fileSlug === $slug) {
            $best = $path;
            break;
        }
        if ($best === '' && ($fileSlug !== '' && (str_starts_with($fileSlug, $slug . '-') || str_starts_with($slug, $fileSlug . '-')))) {
            $best = $path;
        }
    }
    if ($best === '') {
        return $white ? next_match_graphic_badge($pdo, $club, false) : '';
    }
    return '/badges/' . ($white ? 'white/' : '') . rawurlencode(basename($best));
}

/**
 * Current players that have at least one action shot.
 *
 * @return list<array{id:int,name:string,squad_number:?int,shots:list<array{id:int,url:string}>}>
 */
function next_match_graphic_players_with_shots(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT p.id AS player_id, p.name, p.squad_number, s.id AS shot_id, s.filename
         FROM players p
         JOIN player_action_shots s ON s.player_id = p.id
         WHERE p.active = 1 AND p.status = 'current'
         ORDER BY p.name ASC, s.sort_order ASC, s.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $file = basename((string)$row['filename']);
        if ($file === '' || !is_file(dirname(__DIR__) . '/uploads/players/action_shots/' . $file)) {
            continue;
        }
        $pid = (int)$row['player_id'];
        $out[$pid] ??= [
            'id' => $pid,
            'name' => (string)$row['name'],
            'squad_number' => $row['squad_number'] !== null ? (int)$row['squad_number'] : null,
            'shots' => [],
        ];
        $out[$pid]['shots'][] = ['id' => (int)$row['shot_id'], 'url' => '/uploads/players/action_shots/' . rawurlencode($file)];
    }
    return array_values($out);
}

/**
 * Who is on the graphic. Honours the fixture's saved choice; otherwise picks the
 * player whose action shot was used least recently on an earlier fixture so the
 * same face does not come round twice in a row.
 *
 * @return array{player_id:int,shot_id:int,name:string,url:string,auto:bool}|null
 */
function next_match_graphic_choose_photo(PDO $pdo, int $fixtureId, bool $persist = false, string $type = 'next_match'): ?array
{
    $c = next_match_graphic_cols($type);
    next_match_graphic_ensure_schema($pdo);
    $players = next_match_graphic_players_with_shots($pdo);
    if ($players === []) {
        return null;
    }
    $byShot = [];
    foreach ($players as $p) {
        foreach ($p['shots'] as $s) {
            $byShot[$s['id']] = ['player_id' => $p['id'], 'shot_id' => $s['id'], 'name' => $p['name'], 'url' => $s['url'], 'auto' => false];
        }
    }

    $stmt = $pdo->prepare('SELECT ' . $c['player'] . ' AS pid, ' . $c['shot'] . ' AS sid FROM match_fixtures WHERE id = :id');
    $stmt->execute([':id' => $fixtureId]);
    $saved = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $savedShot = (int)($saved['sid'] ?? 0);
    $savedPlayer = (int)($saved['pid'] ?? 0);
    if ($savedShot > 0 && isset($byShot[$savedShot])) {
        return $byShot[$savedShot];
    }
    if ($savedPlayer > 0) {
        foreach ($players as $p) {
            if ($p['id'] === $savedPlayer) {
                $shot = $p['shots'][array_rand($p['shots'])];
                return $byShot[$shot['id']];
            }
        }
    }

    // Auto: least recently featured player (by the latest earlier fixture that used them).
    $last = [];
    foreach ($pdo->query(
        'SELECT ' . $c['player'] . ' AS pid, MAX(match_date) AS d FROM match_fixtures
         WHERE ' . $c['player'] . ' IS NOT NULL AND id <> ' . (int)$fixtureId . ' GROUP BY ' . $c['player']
    ) ?: [] as $r) {
        $last[(int)$r['pid']] = (string)$r['d'];
    }
    usort($players, static fn(array $a, array $b): int => strcmp($last[$a['id']] ?? '0000-00-00', $last[$b['id']] ?? '0000-00-00') ?: random_int(-1, 1));
    $player = $players[0];
    $shot = $player['shots'][array_rand($player['shots'])];
    $choice = $byShot[$shot['id']];
    $choice['auto'] = true;
    if ($persist) {
        next_match_graphic_save_choice($pdo, $fixtureId, $choice['player_id'], $choice['shot_id'], $type);
    }
    return $choice;
}

function next_match_graphic_save_choice(PDO $pdo, int $fixtureId, ?int $playerId, ?int $shotId, string $type = 'next_match'): void
{
    next_match_graphic_ensure_schema($pdo);
    $c = next_match_graphic_cols($type);
    $pdo->prepare('UPDATE match_fixtures SET ' . $c['player'] . ' = :p, ' . $c['shot'] . ' = :s WHERE id = :id')
        ->execute([':p' => $playerId ?: null, ':s' => $shotId ?: null, ':id' => $fixtureId]);
}

/**
 * Everything the card needs, as plain strings/urls.
 *
 * @return array<string,mixed>|null
 */
/**
 * True for a logo with a see-through background and no white version to use.
 * Those get turned white so they show on the dark card; logos on a solid
 * background (a photo card, say) are left alone.
 */
function next_match_graphic_is_cutout(string $path): bool
{
    $info = @getimagesize($path);
    if (!$info || $info[2] !== IMAGETYPE_PNG) {
        return false;
    }
    $im = @imagecreatefrompng($path);
    if (!$im) {
        return false;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    $clear = 0;
    foreach ([[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]] as [$x, $y]) {
        if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 100) {
            $clear++;
        }
    }
    imagedestroy($im);
    return $clear >= 3;
}

function next_match_graphic_data(PDO $pdo, int $fixtureId): ?array
{
    $fixture = getMatchFixtureById($pdo, $fixtureId);
    if (!$fixture) {
        return null;
    }
    $tz = new DateTimeZone('Europe/London');
    $time = trim((string)$fixture['kickoff_time']);
    $at = new DateTimeImmutable(trim((string)$fixture['match_date']) . ' ' . ($time !== '' ? $time : '00:00:00'), $tz);

    $venue = trim((string)$fixture['venue']);
    if ($venue === '') {
        $venue = !empty($fixture['is_home']) ? 'Campbell Park' : (trim((string)($fixture['opponent_ground_location'] ?? '')) ?: 'Away');
    }

    $opponent = trim((string)$fixture['opponent']);
    $club = 'Saltcoats Victoria';
    $home = !empty($fixture['is_home']);

    $sponsors = [];
    foreach (getActiveMainSponsorAgreements($pdo, (int)$fixture['season_id'], (string)$fixture['match_date']) as $s) {
        $white = basename(trim((string)($s['white_logo_path'] ?? '')));
        $colour = basename(trim((string)($s['logo_path'] ?? '')));
        $file = $white !== '' && is_file(dirname(__DIR__) . '/uploads/sponsors/' . $white) ? $white : $colour;
        if ($file === '' || !is_file(dirname(__DIR__) . '/uploads/sponsors/' . $file)) {
            continue;
        }
        $sponsors[] = ['name' => (string)($s['name'] ?? ''), 'url' => '/uploads/sponsors/' . rawurlencode($file), 'white' => $file === $white];
    }

    // Matchday and match ball sponsors always go on the graphic, whoever is on the fixture.
    $featured = [];
    foreach (getDisplayableMatchSponsorshipRows($pdo, $fixtureId) as $row) {
        $role = (string)($row['sponsorship_role'] ?? '');
        if (!in_array($role, ['match_day', 'match_ball'], true) || isset($featured[$role])) {
            continue;
        }
        $white = basename(trim((string)($row['sponsor_white_logo'] ?? '')));
        $colour = basename(trim((string)($row['sponsor_logo'] ?? '')));
        // Featured sponsors use the coloured logo, falling back to white only if there isn't one.
        $file = $colour !== '' && is_file(dirname(__DIR__) . '/uploads/sponsors/' . $colour) ? $colour : $white;
        $hasLogo = $file !== '' && is_file(dirname(__DIR__) . '/uploads/sponsors/' . $file);
        $featured[$role] = [
            'label' => $role === 'match_day' ? 'Matchday sponsor' : 'Match ball sponsor',
            'name' => (string)($row['sponsor_name'] ?? ''),
            'url' => $hasLogo ? '/uploads/sponsors/' . rawurlencode($file) : '',
            'mono' => $hasLogo && $file === $white && $file !== $colour && next_match_graphic_is_cutout(dirname(__DIR__) . '/uploads/sponsors/' . $file),
        ];
    }
    $featured = array_values(array_filter([$featured['match_day'] ?? null, $featured['match_ball'] ?? null]));

    $competitionBadge = '';
    $competition = getMatchCompetitionByName($pdo, (string)$fixture['competition']);
    if ($competition) {
        $img = trim((string)($competition['white_badge_image'] ?? '')) ?: trim((string)($competition['badge_image'] ?? ''));
        $competitionBadge = $img !== '' ? '/' . ltrim($img, '/') : '';
    }

    return [
        'fixture' => $fixture,
        'date_label' => strtoupper($at->format('D, d M')),
        'venue_label' => strtoupper($venue),
        'ko_label' => $time !== '' ? 'KO ' . $at->format('H:i') . ' ' . $at->format('T') : 'KO TBC',
        'versus' => strtoupper($club . ' vs ' . $opponent),
        'club_badge' => next_match_graphic_badge($pdo, $club, true),
        'opponent_badge' => next_match_graphic_badge($pdo, $opponent, true),
        'competition_badge' => $competitionBadge,
        'home' => $home,
        'sponsors' => $sponsors,
        'featured' => $featured,
    ];
}

function next_match_graphic_export_path(int $fixtureId): string
{
    return dirname(__DIR__) . '/export/matches/next-match-card-' . $fixtureId . '.png';
}

/** Render the card to a PNG on disk. @return array{ok:bool,path:string,error:string} */
function next_match_graphic_render(PDO $pdo, int $fixtureId, ?string $backgroundUrl = null, string $type = 'next_match'): array
{
    $path = next_match_graphic_export_path($fixtureId);
    if (RENDER_ACCESS_TOKEN === '') {
        return ['ok' => false, 'path' => $path, 'error' => 'RENDER_ACCESS_TOKEN is not set.'];
    }
    $url = APP_ORIGIN . '/admin/match_next_match_card.php?fixture_id=' . $fixtureId
        . '&render=1&_token=' . rawurlencode(RENDER_ACCESS_TOKEN) . '&v=' . time();
    if ($type !== 'next_match') {
        $url .= '&type=' . rawurlencode(next_match_graphic_type($type));
    }
    if ($backgroundUrl !== null && $backgroundUrl !== '') {
        $url .= '&bg=' . rawurlencode($backgroundUrl);
    }
    $res = render_capture_image($url, $path, '#nextMatchCard', NEXT_MATCH_CARD_WIDTH, NEXT_MATCH_CARD_HEIGHT, '#nextMatchCard');
    return ['ok' => $res['ok'], 'path' => $path, 'error' => $res['ok'] ? '' : trim($res['error'] . ' ' . implode(' ', $res['output']))];
}
