<?php

declare(strict_types=1);

/*
 * Public, read-only queries over the imported historical archive:
 * history_matches, history_player_stats, history_galleries(+ _photos).
 * Populated by tools/import_svfc_history.php; empty tables just render as
 * "nothing here yet".
 */

/** @return list<array{season:string,played:int,w:int,d:int,l:int,gf:int,ga:int}> newest first */
function pub_history_seasons(): array
{
    try {
        return db()->query(
            "SELECT season,
                    COUNT(*)          AS played,
                    SUM(result = 'W') AS w,
                    SUM(result = 'D') AS d,
                    SUM(result = 'L') AS l,
                    SUM(home_score)   AS gf,
                    SUM(away_score)   AS ga
             FROM history_matches
             WHERE season <> '' AND home_score IS NOT NULL AND away_score IS NOT NULL
             GROUP BY season
             ORDER BY season DESC"
        )->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/** Played matches in a season, chronological. @return list<array<string,mixed>> */
function pub_history_results(string $season): array
{
    try {
        $stmt = db()->prepare(
            "SELECT * FROM history_matches
             WHERE season = :s AND home_score IS NOT NULL AND away_score IS NOT NULL
             ORDER BY match_date ASC, id ASC"
        );
        $stmt->execute([':s' => $season]);
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

function pub_history_match(string $slug): ?array
{
    try {
        $stmt = db()->prepare('SELECT * FROM history_matches WHERE slug = :s LIMIT 1');
        $stmt->execute([':s' => $slug]);
        return $stmt->fetch() ?: null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * The archived written report / scorer text for a live fixture, if the results
 * archive was merged in (history_matches.fixture_id links back). Lets the public
 * match page show an old match report on a migrated historical fixture.
 *
 * @return array{report_html:?string,report_by:string,scorers_text:?string,has_report:bool}|null
 */
function pub_fixture_history_report(int $fixtureId): ?array
{
    if ($fixtureId <= 0 || !pub_history_has_column('history_matches', 'fixture_id')) {
        return null;
    }
    try {
        $stmt = db()->prepare(
            'SELECT report_html, report_by, scorers_text, has_report
             FROM history_matches WHERE fixture_id = :f LIMIT 1'
        );
        $stmt->execute([':f' => $fixtureId]);
        $row = $stmt->fetch();
    } catch (Throwable) {
        return null;
    }
    if (!$row) {
        return null;
    }
    $hasContent = trim((string) $row['report_html']) !== '' || trim((string) $row['scorers_text']) !== '';
    if (!$hasContent) {
        return null;
    }
    return [
        'report_html' => $row['report_html'],
        'report_by' => (string) $row['report_by'],
        'scorers_text' => $row['scorers_text'],
        'has_report' => (bool) $row['has_report'],
    ];
}

/** Overall totals across every played archive match. @return array<string,int> */
function pub_history_totals(): array
{
    try {
        $row = db()->query(
            "SELECT COUNT(*) played, SUM(result='W') w, SUM(result='D') d, SUM(result='L') l,
                    MIN(YEAR(match_date)) y0, MAX(YEAR(match_date)) y1
             FROM history_matches
             WHERE home_score IS NOT NULL AND away_score IS NOT NULL"
        )->fetch();
        return array_map(static fn ($v) => (int) $v, $row ?: []);
    } catch (Throwable) {
        return [];
    }
}

/*
 * All-time records. history_player_stats now holds only each player's totals
 * from BEFORE the match-record era (seasons up to 2023-24, where the old site
 * never kept per-match line-ups). Everything from PUB_RECORDS_ERA_FROM onward
 * is derived live from the Hub's match record (matchday_lineups/events/subs),
 * so a goal recorded on a match is the only place a goal is stored.
 * Friendlies are excluded, matching the old site's statistics.
 */
const PUB_RECORDS_ERA_FROM = '2024-07-01';

/** Spelling variants between the old site and the Hub -> one key per person. */
function pub_records_key(string $name): string
{
    static $alias = [
        'josephyoung' => 'joeyoung', 'graigbreen' => 'craigbreen', 'christopherlamb' => 'chrislamb',
        'christopherbrogan' => 'chrisbrogan', 'michealyoung' => 'michaelyoung', 'ewanfarrel' => 'ewanfarrell',
        'camerongeorgetaylor' => 'cameron taylor', 'dylanmclintock' => 'dylanmcclintock',
        'derekgemmel' => 'derekgemmell',
    ];
    $k = str_replace(' ', '', preg_replace('/[^a-z ]/', '', mb_strtolower($name)) ?? '');
    $k = $alias[$k] ?? $k;
    return str_replace(' ', '', $k);
}

/**
 * Pre-2024-25 totals (history_player_stats) + match-derived totals since.
 * @return list<array{name:string,appearances:int,starts:int,subs:int,goals:int,penalties:int}>
 */
function pub_history_records(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $by = [];
    $add = static function (string $name, array $v) use (&$by): void {
        $k = pub_records_key($name);
        if ($k === '' || $k === 'unknownplayer') {
            return;
        }
        $by[$k] ??= ['name' => $name, 'appearances' => 0, 'starts' => 0, 'subs' => 0, 'goals' => 0, 'penalties' => 0, 'seasons' => []];
        foreach (['appearances', 'starts', 'subs', 'goals', 'penalties'] as $f) {
            $by[$k][$f] += (int) ($v[$f] ?? 0);
        }
        // Season start years the player appeared in, across both sources.
        foreach (array_filter(array_map('intval', explode(',', (string) ($v['seasons'] ?? '')))) as $yr) {
            $by[$k]['seasons'][$yr] = $yr;
        }
    };
    try {
        $pdo = db();
        foreach ($pdo->query('SELECT name, appearances, starts, subs, goals, penalties, seasons FROM history_player_stats')->fetchAll() as $r) {
            $add((string) $r['name'], $r);
        }

        $fx = "f.status = 'played' AND f.match_date >= :era AND f.competition NOT LIKE '%riendly%'";
        $stmt = $pdo->prepare(
            "SELECT p.name, COUNT(*) appearances, SUM(l.is_starting = 1) starts, SUM(l.is_starting = 0) subs,
                    GROUP_CONCAT(DISTINCT YEAR(f.match_date) - (MONTH(f.match_date) < 7)) seasons
               FROM matchday_lineups l
               JOIN match_fixtures f ON f.id = l.fixture_id
               JOIN players p ON p.id = l.player_id
              WHERE l.side = 'svfc' AND $fx
                AND (l.is_starting = 1 OR EXISTS (SELECT 1 FROM matchday_subs s WHERE s.player_on_lineup_id = l.id))
              GROUP BY p.id, p.name"
        );
        $stmt->execute([':era' => PUB_RECORDS_ERA_FROM]);
       
        foreach ($stmt->fetchAll() as $r) {
            $add((string) $r['name'], $r);
        }

        $stmt = $pdo->prepare(
            "SELECT p.name, SUM(e.type IN ('goal','penalty_scored')) goals, SUM(e.type = 'penalty_scored') penalties
               FROM matchday_events e
               JOIN match_fixtures f ON f.id = e.fixture_id
               JOIN matchday_lineups l ON l.id = e.player_lineup_id
               JOIN players p ON p.id = l.player_id
              WHERE e.side = 'svfc' AND e.own_goal = 0 AND e.participant_type = 'player'
                AND e.type IN ('goal','penalty_scored') AND $fx
              GROUP BY p.id, p.name"
        );
        $stmt->execute([':era' => PUB_RECORDS_ERA_FROM]);
        foreach ($stmt->fetchAll() as $r) {
            $add((string) $r['name'], ['goals' => $r['goals'], 'penalties' => $r['penalties']]);
        }
    } catch (Throwable) {
        // fall through with whatever was gathered
    }
    return $cache = array_values($by);
}

/**
 * Spells at the club from season start years, e.g. [2012,2014,2015,2016,2024]
 * -> "2012, 2014–2016, 2024–present". Consecutive seasons join into one range.
 * @param array<int,int> $seasons
 */
function pub_records_years(array $seasons): string
{
    $ys = array_values(array_unique(array_map('intval', $seasons)));
    sort($ys);
    if (!$ys) {
        return '';
    }
    $now = (int) date('Y') - ((int) date('n') < 7 ? 1 : 0);
    $spells = [];
    $start = $prev = $ys[0];
    foreach (array_slice($ys, 1) as $y) {
        if ($y === $prev + 1) {
            $prev = $y;
            continue;
        }
        $spells[] = [$start, $prev];
        $start = $prev = $y;
    }
    $spells[] = [$start, $prev];
    return implode(', ', array_map(static function (array $s) use ($now): string {
        [$a, $b] = $s;
        $to = $b >= $now ? 'present' : (string) $b;
        return (string) $a === $to ? (string) $a : $a . '–' . $to;
    }, $spells));
}

/** @return list<array<string,mixed>> ordered by goals then appearances */
function pub_history_top_scorers(int $limit = 25): array
{
    $rows = array_values(array_filter(pub_history_records(), static fn (array $r): bool => $r['goals'] > 0));
    usort($rows, static fn (array $a, array $b): int => [$b['goals'], $b['appearances'], $a['name']] <=> [$a['goals'], $a['appearances'], $b['name']]);
    return array_slice($rows, 0, $limit);
}

/** @return list<array<string,mixed>> ordered by appearances then goals */
function pub_history_appearances(int $limit = 50): array
{
    $rows = pub_history_records();
    usort($rows, static fn (array $a, array $b): int => [$b['appearances'], $b['goals'], $a['name']] <=> [$a['appearances'], $a['goals'], $b['name']]);
    return array_slice($rows, 0, $limit);
}

/**
 * Whether an optional column exists on a table (memoised). Lets new Hub flags
 * (e.g. history_galleries.display_on_site) roll out before the schema ALTER has
 * run in every environment without the public queries hard-failing.
 */
function pub_history_has_column(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        try {
            $stmt = db()->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c
                 LIMIT 1'
            );
            $stmt->execute([':t' => $table, ':c' => $column]);
            $cache[$key] = (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}

/** Does an uploaded file (path relative to /uploads) exist on disk? Missing files are hidden, never linked. */
function pub_upload_exists(string $path): bool
{
    return $path !== '' && is_file(PUBLIC_ROOT . '/uploads/' . ltrim($path, '/'));
}

/** All albums flagged to show on the site, newest first (undated last). @return list<array<string,mixed>> */
function pub_galleries(): array
{
    $albums = pub_galleries_raw();
    try {
        $files = [];
        foreach (db()->query('SELECT gallery_id, file_path FROM history_gallery_photos ORDER BY sort_order ASC, id ASC') as $row) {
            $files[(int) $row['gallery_id']][] = (string) $row['file_path'];
        }
        foreach ($albums as &$a) {
            $present = array_values(array_filter($files[(int) $a['id']] ?? [], 'pub_upload_exists'));
            $a['real_count'] = count($present);
            if (!empty($a['cover_path']) && !pub_upload_exists((string) $a['cover_path'])) {
                $a['cover_path'] = $present[0] ?? '';
            }
        }
        unset($a);
    } catch (Throwable) {
        // keep the raw list
    }
    return $albums;
}

/** @return list<array<string,mixed>> */
function pub_galleries_raw(): array
{
    $visible = pub_history_has_column('history_galleries', 'display_on_site')
        ? 'WHERE g.display_on_site = 1'
        : '';
    try {
        return db()->query(
            "SELECT g.*,
                    (SELECT COUNT(*) FROM history_gallery_photos p WHERE p.gallery_id = g.id) AS real_count
             FROM history_galleries g
             $visible
             ORDER BY album_date IS NULL, album_date DESC, id DESC"
        )->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/**
 * One album with its photos — only if it is flagged to show on the site.
 * When the album is linked to a fixture, $album gets fixture_id / fixture_opponent
 * / fixture_date / fixture_is_home.
 * @return array{album:array,photos:list<array>}|null
 */
function pub_gallery(string $slug): ?array
{
    try {
        $hasFixture = pub_history_has_column('history_galleries', 'match_fixture_id');
        $join = $hasFixture
            ? 'LEFT JOIN match_fixtures f ON f.id = g.match_fixture_id
               LEFT JOIN match_opponents o ON o.id = f.opponent_id'
            : '';
        $extra = $hasFixture
            ? ', g.match_fixture_id AS fixture_id, f.match_date AS fixture_date,
               f.is_home AS fixture_is_home, COALESCE(o.clubname, f.opponent) AS fixture_opponent'
            : '';
        $stmt = db()->prepare("SELECT g.* {$extra} FROM history_galleries g {$join} WHERE g.slug = :s LIMIT 1");
        $stmt->execute([':s' => $slug]);
        $album = $stmt->fetch();
        if (!$album) {
            return null;
        }
        if (pub_history_has_column('history_galleries', 'display_on_site') && (int) ($album['display_on_site'] ?? 1) !== 1) {
            return null;
        }
        $pstmt = db()->prepare(
            'SELECT * FROM history_gallery_photos WHERE gallery_id = :g ORDER BY sort_order ASC, id ASC'
        );
        $pstmt->execute([':g' => $album['id']]);
        $photos = array_values(array_filter($pstmt->fetchAll(), static fn (array $p): bool => pub_upload_exists((string) $p['file_path'])));
        foreach ($photos as &$p) {
            if (($p['thumb_path'] ?? '') !== '' && !pub_upload_exists((string) $p['thumb_path'])) {
                $p['thumb_path'] = '';
            }
        }
        unset($p);
        if (!empty($album['cover_path']) && !pub_upload_exists((string) $album['cover_path'])) {
            $album['cover_path'] = $photos[0]['file_path'] ?? '';
        }
        $album['photo_count'] = count($photos);
        return ['album' => $album, 'photos' => $photos];
    } catch (Throwable) {
        return null;
    }
}

/**
 * The visible album linked to a fixture, if any (for the public match page).
 * @return array{slug:string,title:string,photo_count:int,cover_path:string}|null
 */
function pub_gallery_for_fixture(int $fixtureId): ?array
{
    if ($fixtureId <= 0 || !pub_history_has_column('history_galleries', 'match_fixture_id')) {
        return null;
    }
    $visible = pub_history_has_column('history_galleries', 'display_on_site') ? 'AND display_on_site = 1' : '';
    try {
        $stmt = db()->prepare(
            "SELECT slug, title, photo_count, cover_path
             FROM history_galleries
             WHERE match_fixture_id = :f {$visible}
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':f' => $fixtureId]);
        $row = $stmt->fetch();
        if ($row && !empty($row['cover_path']) && !pub_upload_exists((string) $row['cover_path'])) {
            $row['cover_path'] = '';
        }
        return $row ?: null;
    } catch (Throwable) {
        return null;
    }
}

/** "2016-2017" -> "2016/17" for display. */
function pub_history_season_label(string $season): string
{
    if (preg_match('/^(\d{4})-(\d{4})$/', $season, $m)) {
        return $m[1] . '/' . substr($m[2], 2);
    }
    return $season;
}

/** Additional competitive records; archive scores before 2024 are club-first. */
function pub_records_extra(): array
{
    $pdo = db();
    $matches = $pdo->query("SELECT match_date, opponent, home_score AS us, away_score AS them, season
        FROM history_matches WHERE match_date < '2024-07-01'
        AND home_score IS NOT NULL AND away_score IS NOT NULL AND competition NOT LIKE '%riendly%'
        UNION ALL
        SELECT match_date, opponent,
        IF(is_home, full_time_home_score, full_time_away_score),
        IF(is_home, full_time_away_score, full_time_home_score),
        CONCAT(YEAR(match_date)-(MONTH(match_date)<7), '-', YEAR(match_date)-(MONTH(match_date)<7)+1)
        FROM match_fixtures WHERE status = 'played' AND match_date >= '2024-07-01'
        AND full_time_home_score IS NOT NULL AND full_time_away_score IS NOT NULL
        AND competition NOT LIKE '%riendly%' ORDER BY match_date")->fetchAll();
    $unique = [];
    foreach ($matches as $m) {
        $key = $m['match_date'] . '|' . pub_records_key((string) $m['opponent']);
        $unique[$key] = $m;
    }
    $matches = array_values($unique);
    $wins = array_values(array_filter($matches, static fn ($m) => $m['us'] > $m['them']));
    usort($wins, static fn ($a, $b) => [(int)$b['us']-(int)$b['them'], (int)$b['us']] <=> [(int)$a['us']-(int)$a['them'], (int)$a['us']]);
    $high = $matches;
    usort($high, static fn ($a, $b) => (int)$b['us']+(int)$b['them'] <=> (int)$a['us']+(int)$a['them']);
    $clean = [];
    $runs = [];
    foreach (['Winning' => false, 'Unbeaten' => true] as $label => $allowDraw) {
        $current = null;
        $best = null;
        foreach ($matches as $m) {
            if ($m['match_date'] < PUB_RECORDS_ERA_FROM) continue;
            if ($m['us'] > $m['them'] || ($allowDraw && $m['us'] == $m['them'])) {
                $current ??= ['count' => 0, 'from' => $m['match_date'], 'to' => $m['match_date']];
                $current['count']++;
                $current['to'] = $m['match_date'];
                if ($best === null || $current['count'] > $best['count']) $best = $current;
            } else {
                $current = null;
            }
        }
        if ($best !== null) $runs[] = ['label' => $label] + $best;
    }
    foreach ($matches as $m) {
        if ((int)$m['them'] === 0) $clean[$m['season']] = ($clean[$m['season']] ?? 0) + 1;
    }
    arsort($clean);
    $goals = $pdo->query("SELECT p.name, f.id fixture_id, f.match_date, f.opponent,
        YEAR(f.match_date)-(MONTH(f.match_date)<7) season_year, COUNT(*) goals
        FROM matchday_events e JOIN match_fixtures f ON f.id=e.fixture_id
        JOIN matchday_lineups l ON l.id=e.player_lineup_id JOIN players p ON p.id=l.player_id
        WHERE f.status='played' AND f.match_date >= '2024-07-01' AND f.competition NOT LIKE '%riendly%'
        AND e.side='svfc' AND e.own_goal=0 AND e.participant_type='player'
        AND e.type IN ('goal','penalty_scored') GROUP BY p.id,p.name,f.id,f.match_date,f.opponent
        ORDER BY goals DESC, f.match_date DESC")->fetchAll();
    $seasonGoals = [];
    foreach ($goals as $g) {
        $key = pub_records_key($g['name']) . '|' . $g['season_year'];
        $seasonGoals[$key] ??= ['name' => $g['name'], 'season' => $g['season_year'].'-'.($g['season_year']+1), 'goals' => 0];
        $seasonGoals[$key]['goals'] += (int)$g['goals'];
    }
    usort($seasonGoals, static fn ($a,$b) => $b['goals'] <=> $a['goals']);
    $penalties = array_values(array_filter(pub_history_records(), static fn ($p) => $p['penalties'] > 0));
    usort($penalties, static fn ($a,$b) => $b['penalties'] <=> $a['penalties']);
    $ratios = array_values(array_filter(pub_history_records(), static fn ($p) => $p['appearances'] >= 20 && $p['goals'] > 0));
    usort($ratios, static fn ($a,$b) => $b['goals']/$b['appearances'] <=> $a['goals']/$a['appearances']);
    return compact('wins','high','runs','clean','goals','seasonGoals','penalties','ratios');
}
