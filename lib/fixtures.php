<?php

declare(strict_types=1);

/*
 * Public, read-only fixture/result queries against the Hub `match_fixtures`
 * table. Everything is scoped to the current season unless asked otherwise.
 * (Build step 5 extends this with season/competition filtering + match centre.)
 */

/** id of the season flagged is_current, or the most recent one. */
function pub_current_season_id(): int
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    $row = db()->query(
        'SELECT id FROM seasons ORDER BY is_current DESC, start_date DESC, id DESC LIMIT 1'
    )->fetch();
    return $id = (int) ($row['id'] ?? 0);
}

/** Common SELECT with the opponent crest joined in. */
function pub_fixture_select(): string
{
    return 'SELECT f.id, f.match_date, f.kickoff_time, f.opponent, f.competition,
                   f.competition_stage, f.is_home, f.venue, f.status,
                   f.full_time_home_score, f.full_time_away_score, f.notes,
                   f.half_time_home_score, f.half_time_away_score, f.veo_url,
                   f.home_penalties, f.away_penalties,
                   o.clubname      AS opponent_name,
                   o.logo_path     AS opponent_logo,
                   o.ground_location AS opponent_ground,
                   o.venue_id      AS opponent_venue_id
            FROM match_fixtures f
            LEFT JOIN match_opponents o ON o.id = f.opponent_id';
}

/** A fixture counts as "played" once it has a full-time score or that status. */
function pub_fixture_is_played(array $f): bool
{
    return $f['status'] === 'played'
        || ($f['full_time_home_score'] !== null && $f['full_time_away_score'] !== null);
}

/**
 * Result from the club's point of view. A cup tie drawn after full time and
 * decided on penalties resolves to 'W'/'L' (never 'D') using the shootout
 * score — the label calls this out explicitly, and penalties_us/them carry
 * the shootout score for display, while us/them stay the full-time score.
 *
 * @return array{outcome:'W'|'D'|'L'|null,label:string,us:int,them:int,decided_by_penalties:bool,penalties_us:?int,penalties_them:?int}
 */
function pub_fixture_outcome(array $f): array
{
    if (!pub_fixture_is_played($f)
        || $f['full_time_home_score'] === null
        || $f['full_time_away_score'] === null
    ) {
        return ['outcome' => null, 'label' => '', 'us' => 0, 'them' => 0, 'decided_by_penalties' => false, 'penalties_us' => null, 'penalties_them' => null];
    }
    $home = (int) $f['full_time_home_score'];
    $away = (int) $f['full_time_away_score'];
    $us = $f['is_home'] ? $home : $away;
    $them = $f['is_home'] ? $away : $home;

    $penaltiesUs = null;
    $penaltiesThem = null;
    $decidedByPenalties = false;
    if ($us === $them && $f['home_penalties'] !== null && $f['away_penalties'] !== null) {
        $homePens = (int) $f['home_penalties'];
        $awayPens = (int) $f['away_penalties'];
        $penaltiesUs = $f['is_home'] ? $homePens : $awayPens;
        $penaltiesThem = $f['is_home'] ? $awayPens : $homePens;
        $decidedByPenalties = true;
    }

    if ($decidedByPenalties) {
        $outcome = $penaltiesUs > $penaltiesThem ? 'W' : 'L';
        $label = $outcome === 'W' ? 'Won on penalties' : 'Lost on penalties';
    } else {
        $outcome = $us > $them ? 'W' : ($us < $them ? 'L' : 'D');
        $label = ['W' => 'Win', 'L' => 'Defeat', 'D' => 'Draw'][$outcome];
    }

    return [
        'outcome' => $outcome,
        'label' => $label,
        'us' => $us,
        'them' => $them,
        'decided_by_penalties' => $decidedByPenalties,
        'penalties_us' => $penaltiesUs,
        'penalties_them' => $penaltiesThem,
    ];
}

/** The next unplayed fixture (this season), or null. */
function pub_next_fixture(): ?array
{
    $sql = pub_fixture_select()
        . ' WHERE f.season_id = :s
              AND (f.status IS NULL OR f.status <> "played")
              AND f.full_time_home_score IS NULL
              AND f.match_date >= CURDATE()
            ORDER BY f.match_date ASC, f.kickoff_time ASC
            LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([':s' => pub_current_season_id()]);
    return $stmt->fetch() ?: null;
}

/** Most recent played fixtures (this season), newest first. */
function pub_recent_results(int $limit = 5): array
{
    $limit = max(1, min(20, $limit));
    $sql = pub_fixture_select()
        . ' WHERE f.season_id = :s
              AND (f.status = "played" OR f.full_time_home_score IS NOT NULL)
            ORDER BY f.match_date DESC, f.kickoff_time DESC
            LIMIT ' . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute([':s' => pub_current_season_id()]);
    return $stmt->fetchAll();
}

/** Upcoming fixtures (this season), soonest first. */
function pub_upcoming_fixtures(int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    $sql = pub_fixture_select()
        . ' WHERE f.season_id = :s
              AND (f.status IS NULL OR f.status <> "played")
              AND f.full_time_home_score IS NULL
              AND f.match_date >= CURDATE()
            ORDER BY f.match_date ASC, f.kickoff_time ASC
            LIMIT ' . $limit;
    $stmt = db()->prepare($sql);
    $stmt->execute([':s' => pub_current_season_id()]);
    return $stmt->fetchAll();
}

/**
 * Best available crest URL for an opponent, or '' if none resolves.
 *
 * match_opponents.logo_path is often set to a slug ("vale-of-leven.png") that
 * was never uploaded to uploads/opponents/ but DOES exist in the Hub's WOSFL
 * badge cache (/badges/). Try both, then a slug of the club name.
 */
function pub_opponent_crest(?string $logoPath, string $name = ''): string
{
    static $cache = [];
    $logoPath = trim((string) $logoPath);
    $key = $logoPath . '|' . $name;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $candidates = [];
    if ($logoPath !== '') {
        $candidates[] = ['uploads/opponents/' . $logoPath, uploads('opponents/' . $logoPath)];
        $candidates[] = ['badges/' . $logoPath, '/badges/' . rawurlencode($logoPath)];
    }
    if ($name !== '') {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        if ($slug !== '') {
            $candidates[] = ['badges/' . $slug . '.png', '/badges/' . $slug . '.png'];
        }
    }

    foreach ($candidates as [$rel, $urlPath]) {
        if (is_file(HUB_ROOT . '/' . $rel)) {
            return $cache[$key] = $urlPath;
        }
    }
    return $cache[$key] = '';
}

/** "Saltcoats Victoria" home/away label pair for a fixture. */
function pub_fixture_teams(array $f): array
{
    $opp = $f['opponent_name'] ?: $f['opponent'] ?: 'TBC';
    $short = club('club_short_name', 'Saltcoats Vics');
    return $f['is_home']
        ? ['home' => $short, 'away' => $opp]
        : ['home' => $opp, 'away' => $short];
}

/* -------------------------------------------------------------------------
 * Season-aware listing (fixtures / results pages)
 * ---------------------------------------------------------------------- */

/** @return list<array{id:int,name:string,is_current:int}> newest first */
function pub_seasons(): array
{
    return db()->query(
        'SELECT id, name, is_current FROM seasons ORDER BY start_date DESC, id DESC'
    )->fetchAll();
}

function pub_season(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM seasons WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

/**
 * Distinct competition names used by a season's fixtures, or across every
 * season when $seasonId is 0 (the "All seasons" filter). @return list<string>
 */
function pub_competitions_in_season(int $seasonId): array
{
    if ($seasonId > 0) {
        $stmt = db()->prepare(
            "SELECT DISTINCT competition FROM match_fixtures
             WHERE season_id = :s AND competition <> '' ORDER BY competition"
        );
        $stmt->execute([':s' => $seasonId]);
    } else {
        $stmt = db()->query(
            "SELECT DISTINCT competition FROM match_fixtures
             WHERE competition <> '' ORDER BY competition"
        );
    }
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Fixture IDs that currently have match tickets on sale online — at least one
 * active fixture package sitting on an active template. Queried once and cached
 * for the request so the fixtures list can flag rows without an N+1.
 *
 * @return array<int,true> set keyed by fixture id
 */
function pub_ticketed_fixture_ids(): array
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $ids = [];
    try {
        $rows = db()->query(
            'SELECT DISTINCT ftp.fixture_id
               FROM fixture_ticket_packages ftp
               JOIN match_ticket_packages p ON p.id = ftp.package_id
              WHERE ftp.is_active = 1 AND p.is_active = 1'
        )->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $fid) {
            $ids[(int) $fid] = true;
        }
    } catch (Throwable $e) {
        $ids = []; // ticket schema not present — treat as "none on sale"
    }
    return $ids;
}

/** Whether online match tickets are on sale for a given fixture. */
function pub_fixture_has_tickets(int $fixtureId): bool
{
    return isset(pub_ticketed_fixture_ids()[$fixtureId]);
}

/**
 * @param array{season_id?:int,competition?:string,type?:'upcoming'|'results'|'all'} $opts
 *        season_id 0 = every season (the "All seasons" filter).
 * @return list<array<string,mixed>>
 */
function pub_fixtures(array $opts = []): array
{
    $seasonId = (int) ($opts['season_id'] ?? pub_current_season_id());
    $type = $opts['type'] ?? 'all';

    $conds = [];
    $params = [];
    if ($seasonId > 0) {
        $conds[] = 'f.season_id = :s';
        $params[':s'] = $seasonId;
    }
    if (!empty($opts['competition'])) {
        $conds[] = 'f.competition = :c';
        $params[':c'] = (string) $opts['competition'];
    }
    if ($type === 'results') {
        $conds[] = '(f.status = "played" OR f.full_time_home_score IS NOT NULL)';
        $order = ' ORDER BY f.match_date DESC, f.kickoff_time DESC';
    } elseif ($type === 'upcoming') {
        $conds[] = '(f.status IS NULL OR f.status <> "played") AND f.full_time_home_score IS NULL AND f.match_date >= CURDATE()';
        $order = ' ORDER BY f.match_date ASC, f.kickoff_time ASC';
    } else {
        $order = ' ORDER BY f.match_date ASC, f.kickoff_time ASC';
    }

    $sql = pub_fixture_select()
        . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '')
        . $order;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Group fixture rows by "F Y" month key, preserving row order within a month.
 * @param list<array<string,mixed>> $rows
 * @return array<string, list<array<string,mixed>>>
 */
function pub_fixtures_by_month(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $ts = strtotime((string) $row['match_date']) ?: time();
        $out[date('F Y', $ts)][] = $row;
    }
    return $out;
}

/* -------------------------------------------------------------------------
 * Single fixture + match centre (events / line-ups from data/matches.json)
 * ---------------------------------------------------------------------- */

function pub_fixture(int $id): ?array
{
    $stmt = db()->prepare(pub_fixture_select() . ' WHERE f.id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

/** Raw entry for a fixture from the Hub's data/matches.json, or null. */
function pub_match_detail(int $fixtureId): ?array
{
    static $all = null;
    if ($all === null) {
        $file = HUB_ROOT . '/data/matches.json';
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $all = is_array($decoded) ? $decoded : [];
    }
    foreach ($all as $m) {
        if ((string) ($m['id'] ?? '') === (string) $fixtureId) {
            return $m;
        }
    }
    return null;
}

/**
 * Normalised, minute-sorted events for a fixture.
 * @return list<array{minute:string,type:string,side:'us'|'opp',player:string,detail:string}>
 */
function pub_match_events(int $fixtureId): array
{
    $detail = pub_match_detail($fixtureId);
    $events = is_array($detail['events'] ?? null) ? $detail['events'] : [];
    $out = [];
    foreach ($events as $e) {
        $type = (string) ($e['type'] ?? '');
        $side = (string) ($e['team'] ?? '') === 'svfc' ? 'us' : 'opp';
        $label = match ($type) {
            'goal' => !empty($e['own_goal']) ? 'Own goal' : 'Goal',
            'card' => ((string) ($e['card_type'] ?? '') === 'red' ? 'Red card' : 'Yellow card'),
            'yellow_card' => 'Yellow card',
            'red_card' => 'Red card',
            'substitution', 'sub' => 'Substitution',
            'penalty_miss' => 'Penalty missed',
            default => ucfirst(str_replace('_', ' ', $type ?: 'Event')),
        };
        $out[] = [
            'minute' => (string) ($e['minute'] ?? ''),
            'type' => $type,
            'label' => $label,
            'side' => $side,
            'player' => (string) ($e['player'] ?? ''),
            'detail' => (string) ($e['secondary_player'] ?? ($e['note'] ?? '')),
            'sequence' => (int) ($e['sequence'] ?? 0),
        ];
    }
    usort($out, static function (array $a, array $b): int {
        $ma = (int) preg_replace('/\D+/', '', $a['minute'] ?: '0');
        $mb = (int) preg_replace('/\D+/', '', $b['minute'] ?: '0');
        return $ma <=> $mb ?: $a['sequence'] <=> $b['sequence'];
    });
    return $out;
}

/** @return array{starters:list<string>,substitutes:list<string>,captain:string} */
function pub_match_lineup(int $fixtureId): array
{
    $detail = pub_match_detail($fixtureId) ?? [];
    return [
        'starters' => array_values(array_filter((array) ($detail['starters'] ?? []), 'strlen')),
        'substitutes' => array_values(array_filter((array) ($detail['substitutes'] ?? []), 'strlen')),
        'captain' => (string) ($detail['captain'] ?? ''),
    ];
}

/**
 * Both-teams match record (starting XI, subs, events) from the Hub's normalised
 * matchday_* tables — the store the COMET PDF importer / Matchday Record editor
 * writes to. Returns null when the tables or a fixture's rows are absent, so
 * callers fall back to the legacy svfc-only projection (pub_match_lineup /
 * pub_match_events, which read admin/data/matches.json).
 *
 * @return array{
 *   lineups: array{us: array<int,array<string,mixed>>, opp: array<int,array<string,mixed>>},
 *   captain: array{us:string, opp:string},
 *   events: list<array{minute:string,type:string,label:string,side:'us'|'opp',player:string,detail:string}>
 * }|null
 */
function pub_match_record(int $fixtureId): ?array
{
    static $cache = [];
    if (array_key_exists($fixtureId, $cache)) {
        return $cache[$fixtureId];
    }
    if ($fixtureId <= 0) {
        return $cache[$fixtureId] = null;
    }

    try {
        $ls = db()->prepare(
            'SELECT side, player_name, shirt_number, position_label, is_starting, is_captain
               FROM matchday_lineups
              WHERE fixture_id = :f
              ORDER BY side ASC, is_starting DESC, sort_order ASC, shirt_number ASC, id ASC'
        );
        $ls->execute([':f' => $fixtureId]);
        $lineRows = $ls->fetchAll();
        if (!$lineRows) {
            return $cache[$fixtureId] = null;
        }

        $es = db()->prepare(
            'SELECT minute, minute_extra, side, type, player_name, secondary_player_name,
                    own_goal, card_type, outcome
               FROM matchday_events
              WHERE fixture_id = :f
              ORDER BY minute ASC, minute_extra ASC, sequence ASC, id ASC'
        );
        $es->execute([':f' => $fixtureId]);
        $eventRows = $es->fetchAll();
    } catch (Throwable) {
        return $cache[$fixtureId] = null; // tables not present on this install
    }

    $key = static fn (string $side): string => $side === 'svfc' ? 'us' : 'opp';

    $lineups = ['us' => [], 'opp' => []];
    $captain = ['us' => '', 'opp' => ''];
    foreach ($lineRows as $r) {
        $k = $key((string) $r['side']);
        $name = (string) $r['player_name'];
        $lineups[$k][] = [
            'name' => $name,
            'number' => $r['shirt_number'] !== null ? (int) $r['shirt_number'] : null,
            'pos' => trim((string) ($r['position_label'] ?? '')),
            'captain' => (bool) $r['is_captain'],
            'starting' => (bool) $r['is_starting'],
        ];
        if ($r['is_captain'] && $captain[$k] === '') {
            $captain[$k] = $name;
        }
    }

    $events = [];
    $shootout = ['us' => [], 'opp' => []];
    $shirtOf = [];
    foreach ($lineups as $sideKey => $rows) {
        foreach ($rows as $p) {
            $shirtOf[$sideKey . '|' . mb_strtolower(trim($p['name']), 'UTF-8')] = $p['number'];
        }
    }
    foreach ($eventRows as $r) {
        $type = (string) $r['type'];
        if ($type === 'shootout_penalty') {
            // Penalty-shootout kicks are shown apart from the match timeline and never count as goals.
            $sk = $key((string) $r['side']);
            if (isset($shootout[$sk])) {
                $kname = (string) $r['player_name'];
                $shootout[$sk][] = [
                    'n' => count($shootout[$sk]) + 1,
                    'name' => $kname,
                    'number' => $shirtOf[$sk . '|' . mb_strtolower(trim($kname), 'UTF-8')] ?? null,
                    'scored' => (string) $r['outcome'] === 'scored',
                ];
            }
            continue;
        }
        $isOG = (bool) $r['own_goal'] || $type === 'own_goal';
        $min = $r['minute'] !== null ? (string) (int) $r['minute'] : '';
        if ($min !== '' && (int) $r['minute_extra'] > 0) {
            $min .= '+' . (int) $r['minute_extra'];
        }
        $label = match (true) {
            $isOG => 'Own goal',
            $type === 'goal', $type === 'penalty_scored' => 'Goal',
            $type === 'yellow_card' => 'Yellow card',
            $type === 'second_yellow' => 'Second yellow',
            $type === 'red_card' => 'Red card',
            $type === 'substitution' => 'Substitution',
            $type === 'penalty_missed' => 'Penalty missed',
            default => ucfirst(str_replace('_', ' ', $type ?: 'Event')),
        };
        $events[] = [
            'minute' => $min,
            'type' => ($type === 'own_goal' || $type === 'penalty_scored') ? 'goal' : $type,
            'label' => $label,
            'side' => $key((string) $r['side']),
            'player' => (string) $r['player_name'],
            'detail' => (string) $r['secondary_player_name'],
        ];
    }

    return $cache[$fixtureId] = ['lineups' => $lineups, 'captain' => $captain, 'events' => $events, 'shootout' => $shootout];
}

/** Match photos for a fixture (from the Hub match_photos table). */
function pub_match_photos(int $fixtureId, int $limit = 24): array
{
    $limit = max(1, min(60, $limit));
    $stmt = db()->prepare(
        "SELECT filename, kit FROM match_photos WHERE match_fixture_id = :f
         ORDER BY uploaded_at DESC, id DESC LIMIT $limit"
    );
    $stmt->execute([':f' => $fixtureId]);
    return $stmt->fetchAll();
}

/* -------------------------------------------------------------------------
 * Upcoming-fixture preview extras: weather, head-to-head, form
 * ---------------------------------------------------------------------- */

/** Hub cache dir (writable by the web user; shared with the WOSFL scraper). */
function pub_pref_cache_dir(): string
{
    return HUB_ROOT . '/cache';
}

/** GET a JSON endpoint, fail-soft. Short timeouts — this runs during render. */
function pub_http_json(string $url, int $timeout = 4): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_USERAGENT => 'SaltcoatsVicsSite/1.0 (+https://saltcoatsvictoria.co.uk)',
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (!is_string($body) || $code < 200 || $code >= 300) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * Head-to-head vs this fixture's opponent, from the results archive
 * (history_matches — scores are stored our-team-first).
 *
 * @return array{played:int,w:int,d:int,l:int,gf:int,ga:int,meetings:list<array<string,mixed>>}|null
 */
function pub_head_to_head(array $fixture, int $limit = 6): ?array
{
    $opp = trim((string) ($fixture['opponent_name'] ?? $fixture['opponent'] ?? ''));
    if ($opp === '') {
        return null;
    }
    try {
        $rows = db()->query(
            "SELECT f.id, f.match_date, s.name AS season, f.competition, f.is_home,
                    COALESCE(o.clubname, f.opponent) AS opponent,
                    f.full_time_home_score AS fh, f.full_time_away_score AS fa
             FROM match_fixtures f
             LEFT JOIN match_opponents o ON o.id = f.opponent_id
             LEFT JOIN seasons s ON s.id = f.season_id
             WHERE f.status = 'played'
               AND f.full_time_home_score IS NOT NULL AND f.full_time_away_score IS NOT NULL
               AND COALESCE(o.clubname, f.opponent) <> ''
             ORDER BY f.match_date DESC, f.id DESC"
        )->fetchAll();
    } catch (Throwable) {
        return null;
    }

    $target = pub_norm_club($opp);
    $aliases = [
        'irvinevics' => 'irvinevictoria',
        'lugarbothwellthistle' => 'lugarboswellthistle',
        'ardrossanwintonrovers' => 'wintonrovers',
        'murikirkjuniors' => 'muirkirk',
    ];
    $isMatch = static function (string $name) use ($target, $aliases): bool {
        $n = pub_norm_club($name);
        if ($n === '' || $target === '') {
            return false;
        }
        if ($n === $target) {
            return true;
        }
        if (($aliases[$n] ?? null) === $target || ($aliases[$target] ?? null) === $n) {
            return true;
        }
        [$short, $long] = strlen($n) <= strlen($target) ? [$n, $target] : [$target, $n];
        return strlen($short) >= 8 && str_starts_with($long, $short);
    };

    $meetings = [];
    $w = $d = $l = $gf = $ga = 0;
    foreach ($rows as $r) {
        if (!$isMatch((string) $r['opponent'])) {
            continue;
        }
        $us = (int) ($r['is_home'] ? $r['fh'] : $r['fa']);
        $them = (int) ($r['is_home'] ? $r['fa'] : $r['fh']);
        $res = $us > $them ? 'W' : ($us < $them ? 'L' : 'D');
        $res === 'W' ? $w++ : ($res === 'L' ? $l++ : $d++);
        $gf += $us;
        $ga += $them;
        if (count($meetings) < max(1, $limit)) {
            $meetings[] = [
                'date' => (string) $r['match_date'],
                'season' => (string) $r['season'],
                'competition' => (string) $r['competition'],
                'is_home' => (bool) $r['is_home'],
                'us' => $us,
                'them' => $them,
                'result' => $res,
                'url' => url('match/' . (int) $r['id']),
            ];
        }
    }

    if (!$meetings) {
        return null;
    }
    return [
        'played' => $w + $d + $l,
        'w' => $w, 'd' => $d, 'l' => $l,
        'gf' => $gf, 'ga' => $ga,
        'meetings' => $meetings,
    ];
}

/** Town-level coordinates for the WOSFL grounds (fine for a weather forecast). */
function pub_known_venue_coords(): array
{
    return [
        'saltcoatsvictoria'     => ['lat' => 55.6336, 'lon' => -4.7721, 'label' => 'Saltcoats'],
        'eastkilbridethistle'   => ['lat' => 55.7645, 'lon' => -4.1770, 'label' => 'East Kilbride'],
        'eastkilbrideym'        => ['lat' => 55.7645, 'lon' => -4.1770, 'label' => 'East Kilbride'],
        'glenvale'              => ['lat' => 55.8451, 'lon' => -4.4284, 'label' => 'Paisley'],
        'royalalbert'           => ['lat' => 55.7375, 'lon' => -3.9724, 'label' => 'Larkhall'],
        'carlukerovers'         => ['lat' => 55.7348, 'lon' => -3.8403, 'label' => 'Carluke'],
        'westparkunited'        => ['lat' => 55.9345, 'lon' => -4.6903, 'label' => 'Port Glasgow'],
        'kellorovers'           => ['lat' => 55.3807, 'lon' => -3.9924, 'label' => 'Kirkconnel'],
        'glasgowperthshire'     => ['lat' => 55.8636, 'lon' => -4.2369, 'label' => 'Glasgow'],
        'eglinton'              => ['lat' => 55.6541, 'lon' => -4.6957, 'label' => 'Kilwinning'],
        'valeofleven'           => ['lat' => 55.9879, 'lon' => -4.5824, 'label' => 'Alexandria'],
        'giffnock'              => ['lat' => 55.8062, 'lon' => -4.2929, 'label' => 'Giffnock'],
        'giffnocksc'            => ['lat' => 55.8062, 'lon' => -4.2929, 'label' => 'Giffnock'],
        'lugarboswellthistle'   => ['lat' => 55.4656, 'lon' => -4.2288, 'label' => 'Lugar'],
        'newmainsunited'        => ['lat' => 55.7897, 'lon' => -3.8760, 'label' => 'Newmains'],
        'irvinevictoria'        => ['lat' => 55.6156, 'lon' => -4.6649, 'label' => 'Irvine'],
        'stanthonys'            => ['lat' => 55.9485, 'lon' => -4.7572, 'label' => 'Greenock'],
    ];
}

/** Place-name guesses to geocode an unknown away ground by. */
function pub_place_candidates(array $fixture): array
{
    $out = [];
    $venue = trim((string) ($fixture['venue'] ?? ''));
    if ($venue !== '' && str_word_count($venue) <= 2
        && !preg_match('/\b(park|stadium|ground|field|stad|complex|centre|arena)\b/i', $venue)
    ) {
        $out[] = $venue;
    }

    $opp = trim((string) ($fixture['opponent_name'] ?? $fixture['opponent'] ?? ''));
    $stop = ['fc', 'afc', 'jfc', 'united', 'rovers', 'thistle', 'athletic', 'juniors',
        'junior', 'victoria', 'vics', 'vale', 'of', 'the', 'town', 'city', 'club', 'sc',
        'ym', 'y', 'm', 'albert', 'royal', 'st', 'saints', 'academy', 'amateurs', 'violet',
        'boswell', 'bothwell'];
    $tokens = array_values(array_filter(
        preg_split('/\s+/', strtolower(preg_replace('/[^a-z0-9 ]+/i', ' ', $opp) ?? '')),
        static fn (string $t): bool => $t !== '' && !in_array($t, $stop, true)
    ));
    if ($tokens) {
        $out[] = ucwords(implode(' ', $tokens));
    }
    if ($opp !== '') {
        $out[] = $opp;
    }
    // Town-level guess from the venue, e.g. "Meadow Park Irvine" -> "Irvine".
    $bare = preg_split('/\s+/', trim((string) preg_replace('/\b(park|stadium|ground|field|stad|complex|centre|arena)\b/i', '', $venue))) ?: [];
    if (count(array_filter($bare)) > 1) {
        $out[] = (string) end($bare);
    }
    // Last resort: the full venue name.
    if ($venue !== '') {
        $out[] = $venue;
    }
    return array_values(array_unique($out));
}

/** {lat,lon,label} for a fixture's venue, or null. Cached in the Hub cache dir. */
function pub_venue_coords(array $fixture): ?array
{
    // A pin saved on the venue record (Admin > Venues > Quick find) always wins.
    $saved = pub_saved_venue_pin($fixture);
    if ($saved !== null) {
        return $saved;
    }
    $isHome = (bool) $fixture['is_home'];
    if ($isHome) {
        return null; // our ground has no pin saved yet
    }
    $opp = trim((string) ($fixture['opponent_name'] ?? $fixture['opponent'] ?? ''));
    $file = pub_pref_cache_dir() . '/pub_venue_coords.json';
    $store = is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
    if (!is_array($store)) {
        $store = [];
    }
    // Away grounds are keyed by opponent, or by venue when the opponent is not recorded,
    // so two fixtures with no opponent never share one cached location.
    $awayName = $opp !== '' ? $opp : trim((string) ($fixture['venue'] ?? ''));
    if ($awayName === '') {
        return null;
    }
    $key = 'away:' . pub_norm_club($awayName);

    if (isset($store[$key]['lat'], $store[$key]['lon'])) {
        return ['lat' => (float) $store[$key]['lat'], 'lon' => (float) $store[$key]['lon'], 'label' => (string) ($store[$key]['label'] ?? '')];
    }
    if (isset($store[$key]['miss']) && (time() - (int) $store[$key]['miss']) < 604800) {
        return null; // don't re-hammer geocoders for a week
    }

    $hit = pub_known_venue_coords()[pub_norm_club($opp)] ?? null;
    if ($hit === null) {
        foreach (pub_place_candidates($fixture) as $cand) {
            $j = pub_http_json('https://api.postcodes.io/places?limit=5&q=' . rawurlencode($cand));
            foreach ((array) ($j['result'] ?? []) as $r) {
                if (!isset($r['latitude'], $r['longitude'])) {
                    continue;
                }
                $scottish = ($r['region'] ?? '') === 'Scotland' || ($r['country'] ?? '') === 'Scotland';
                if ($scottish || $hit === null) {
                    $hit = ['lat' => (float) $r['latitude'], 'lon' => (float) $r['longitude'], 'label' => (string) ($r['name_1'] ?? $cand)];
                }
                if ($scottish) {
                    break 2;
                }
            }
        }
    }

    $store[$key] = $hit ? ($hit + ['ts' => time()]) : ['miss' => time()];
    @file_put_contents($file, json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $hit ? ['lat' => $hit['lat'], 'lon' => $hit['lon'], 'label' => $hit['label']] : null;
}

/**
 * The pin saved on a venue record (match_venues.latitude/longitude).
 * Home fixtures use our ground by name; away fixtures use the opponent's venue,
 * falling back to a venue whose name matches the fixture's venue text.
 */
function pub_saved_venue_pin(array $fixture): ?array
{
    $venueName = trim((string) ($fixture['venue'] ?? ''));
    $select = 'SELECT latitude, longitude, COALESCE(NULLIF(town, \'\'), name) AS label
               FROM match_venues
               WHERE latitude IS NOT NULL AND longitude IS NOT NULL';
    $fetch = static function (string $where, array $params) use ($select): ?array {
        $stmt = db()->prepare($select . ' AND ' . $where . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    };
    try {
        $row = null;
        if (!empty($fixture['is_home'])) {
            if ($venueName !== '') {
                $row = $fetch('LOWER(TRIM(name)) = LOWER(?)', [$venueName]);
            }
        } else {
            if (!empty($fixture['opponent_venue_id'])) {
                $row = $fetch('id = ?', [(int) $fixture['opponent_venue_id']]);
            }
            if (!$row && $venueName !== '') {
                $row = $fetch('LOWER(TRIM(name)) = LOWER(?)', [$venueName]);
            }
        }
    } catch (Throwable) {
        return null; // the venue table is optional for the public site
    }
    if (!$row) {
        return null;
    }
    return ['lat' => (float) $row['latitude'], 'lon' => (float) $row['longitude'], 'label' => (string) $row['label']];
}

/** WMO weather code -> short label + emoji. */
function pub_wmo(int $code): array
{
    return match (true) {
        $code === 0 => ['label' => 'Clear', 'emoji' => "\u{2600}\u{FE0F}"],
        $code === 1 => ['label' => 'Mainly clear', 'emoji' => "\u{1F324}\u{FE0F}"],
        $code === 2 => ['label' => 'Partly cloudy', 'emoji' => "\u{26C5}"],
        $code === 3 => ['label' => 'Overcast', 'emoji' => "\u{2601}\u{FE0F}"],
        $code === 45 || $code === 48 => ['label' => 'Fog', 'emoji' => "\u{1F32B}\u{FE0F}"],
        $code >= 51 && $code <= 57 => ['label' => 'Drizzle', 'emoji' => "\u{1F326}\u{FE0F}"],
        $code >= 61 && $code <= 64 || $code === 80 || $code === 81 => ['label' => 'Rain', 'emoji' => "\u{1F327}\u{FE0F}"],
        $code === 65 || $code === 82 => ['label' => 'Heavy rain', 'emoji' => "\u{1F327}\u{FE0F}"],
        $code === 66 || $code === 67 => ['label' => 'Freezing rain', 'emoji' => "\u{1F327}\u{FE0F}"],
        $code >= 71 && $code <= 77 || $code === 85 || $code === 86 => ['label' => 'Snow', 'emoji' => "\u{2744}\u{FE0F}"],
        $code >= 95 => ['label' => 'Thunderstorm', 'emoji' => "\u{26C8}\u{FE0F}"],
        default => ['label' => 'Unsettled', 'emoji' => "\u{1F325}\u{FE0F}"],
    };
}

/**
 * Match-day forecast for an upcoming fixture (Open-Meteo), or null when it's
 * out of range / no coordinates / the API is unreachable. Cached ~3h.
 *
 * @return array{label:string,emoji:string,temp:?int,temp_max:int,temp_min:int,precip:int,wind:int,at_kickoff:bool,place:string,days_out:int}|null
 */
function pub_weather_forecast(array $fixture): ?array
{
    $date = trim((string) $fixture['match_date']);
    $koTime = trim((string) ($fixture['kickoff_time'] ?? '')) ?: '15:00';
    $ts = strtotime($date . ' ' . $koTime);
    if ($date === '' || $ts === false) {
        return null;
    }
    $daysOut = (int) floor(($ts - time()) / 86400);
    if ($daysOut < 0 || $daysOut > 14) {
        return null;
    }

    // A forecast only needs town-level accuracy, so home games fall back to the club's town.
    $coords = pub_venue_coords($fixture)
        ?? ($fixture['is_home'] ? pub_known_venue_coords()['saltcoatsvictoria'] : null);
    if ($coords === null) {
        return null;
    }

    $lat = number_format($coords['lat'], 3, '.', '');
    $lon = number_format($coords['lon'], 3, '.', '');
    $cacheFile = pub_pref_cache_dir() . '/pub_weather_' . str_replace(['.', '-'], ['p', 'm'], $lat . '_' . $lon) . '.json';

    $data = null;
    if (is_file($cacheFile) && (time() - (int) @filemtime($cacheFile)) < 10800) {
        $data = json_decode((string) @file_get_contents($cacheFile), true) ?: null;
    }
    if (!is_array($data)) {
        $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . $lat . '&longitude=' . $lon
            . '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,wind_speed_10m_max'
            . '&hourly=temperature_2m,weather_code,precipitation_probability,wind_speed_10m'
            . '&wind_speed_unit=mph&timezone=' . rawurlencode('Europe/London') . '&forecast_days=16';
        $data = pub_http_json($url, 5);
        if (!is_array($data)) {
            return null;
        }
        @file_put_contents($cacheFile, json_encode($data), LOCK_EX);
    }

    $daily = $data['daily'] ?? [];
    $di = array_search($date, (array) ($daily['time'] ?? []), true);
    if ($di === false) {
        return null;
    }
    $hourly = $data['hourly'] ?? [];
    $hi = array_search($date . 'T' . substr($koTime, 0, 2) . ':00', (array) ($hourly['time'] ?? []), true);

    $pick = static fn (array $arr, $idx, $fallback) => ($idx !== false && isset($arr[$idx]) && $arr[$idx] !== null) ? $arr[$idx] : $fallback;

    $code = (int) $pick((array) ($hourly['weather_code'] ?? []), $hi, $daily['weather_code'][$di] ?? 3);
    $wmo = pub_wmo($code);

    return [
        'label' => $wmo['label'],
        'emoji' => $wmo['emoji'],
        'temp' => ($hi !== false && isset($hourly['temperature_2m'][$hi])) ? (int) round((float) $hourly['temperature_2m'][$hi]) : null,
        'temp_max' => (int) round((float) ($daily['temperature_2m_max'][$di] ?? 0)),
        'temp_min' => (int) round((float) ($daily['temperature_2m_min'][$di] ?? 0)),
        'precip' => (int) $pick((array) ($hourly['precipitation_probability'] ?? []), $hi, $daily['precipitation_probability_max'][$di] ?? 0),
        'wind' => (int) round((float) $pick((array) ($hourly['wind_speed_10m'] ?? []), $hi, $daily['wind_speed_10m_max'][$di] ?? 0)),
        'at_kickoff' => $hi !== false,
        'place' => (string) $coords['label'],
        'days_out' => $daysOut,
    ];
}

/**
 * Our last few results as W/D/L, oldest-first, each linking to its match page.
 * @return list<array{outcome:string,href:string,label:string}>
 */
function pub_our_form(int $limit = 5): array
{
    $out = [];
    foreach (pub_recent_results($limit) as $f) {
        $o = pub_fixture_outcome($f);
        if ($o['outcome'] === null) {
            continue;
        }
        $opp = (string) ($f['opponent_name'] ?: $f['opponent'] ?: 'TBC');
        $out[] = [
            'outcome' => $o['outcome'],
            'href' => url('match/' . (int) $f['id']),
            'label' => ($f['is_home'] ? 'v ' : 'at ') . $opp . ' ' . (int) $o['us'] . "\u{2013}" . (int) $o['them'],
        ];
    }
    return array_reverse($out);
}

/**
 * Crawlable neighbours for a match page: the previous and next indexable match
 * (played, or still to come) and up to 6 other meetings with the same opponent.
 * @return array{prev:?array,next:?array,meetings:list<array>}
 */
function pub_fixture_links(array $f): array
{
    $indexable = '(f.status = "played" OR f.full_time_home_score IS NOT NULL OR f.match_date >= CURDATE())';
    $when = 'CONCAT(f.match_date, " ", COALESCE(f.kickoff_time, "00:00:00"))';
    $here = (string) $f['match_date'] . ' ' . ((string) ($f['kickoff_time'] ?? '') ?: '00:00:00');
    $one = static function (string $cmp, string $order) use ($indexable, $when, $here, $f): ?array {
        $stmt = db()->prepare(pub_fixture_select() . " WHERE $indexable AND f.id <> :id AND $when $cmp :here ORDER BY $when $order LIMIT 1");
        $stmt->execute([':id' => $f['id'], ':here' => $here]);
        return $stmt->fetch() ?: null;
    };
    $meetings = [];
    $opp = trim((string) ($f['opponent'] ?? ''));
    if ($opp !== '') {
        $stmt = db()->prepare(pub_fixture_select() . " WHERE $indexable AND f.id <> :id AND f.opponent = :opp ORDER BY f.match_date DESC LIMIT 6");
        $stmt->execute([':id' => $f['id'], ':opp' => $opp]);
        $meetings = $stmt->fetchAll();
    }
    return ['prev' => $one('<', 'DESC'), 'next' => $one('>', 'ASC'), 'meetings' => $meetings];
}

/** One-line label for a match link: "12 Sep 2026 · Saltcoats Vics 3–1 Opponent". */
function pub_fixture_link_label(array $f): string
{
    $t = pub_fixture_teams($f);
    $mid = pub_fixture_is_played($f)
        ? (int) $f['full_time_home_score'] . '–' . (int) $f['full_time_away_score']
        : 'v';
    return format_date($f['match_date'], 'j M Y') . ' · ' . $t['home'] . ' ' . $mid . ' ' . $t['away'];
}

/** Short competition label for tight spaces: "McBookie.com West of Scotland League Two" becomes "WoSFL League Two". */
function pub_competition_short(string $name): string
{
    $name = trim($name);
    $name = (string) preg_replace('/^McBookie\.com\s+/i', '', $name);
    $name = (string) preg_replace('/West of Scotland (?:Football )?League/i', 'WoSFL', $name);
    return $name !== '' ? $name : 'Fixture';
}

/** Ticket page URL for a home fixture that really has tickets on sale, or '' otherwise. */
function pub_fixture_tickets_url(array $fixture): string
{
    if (empty($fixture['is_home'])) {
        return '';
    }
    try {
        require_once HUB_ROOT . '/lib/match_tickets.php';
        if (getFixtureTicketPackages(db(), (int) $fixture['id'], true) !== []) {
            return url('tickets') . '?fixture_id=' . (int) $fixture['id'];
        }
    } catch (Throwable $e) {
        // the match page must still work if ticketing is unavailable
    }
    return '';
}

