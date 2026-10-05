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

/** @return list<array<string,mixed>> ordered by goals then appearances */
function pub_history_top_scorers(int $limit = 25): array
{
    try {
        $stmt = db()->prepare(
            'SELECT * FROM history_player_stats WHERE goals > 0
             ORDER BY goals DESC, appearances DESC, sort_name ASC LIMIT :n'
        );
        $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/** @return list<array<string,mixed>> ordered by appearances */
function pub_history_appearances(int $limit = 50): array
{
    try {
        $stmt = db()->prepare(
            'SELECT * FROM history_player_stats
             ORDER BY appearances DESC, goals DESC, sort_name ASC LIMIT :n'
        );
        $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
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

/** All albums flagged to show on the site, newest first (undated last). @return list<array<string,mixed>> */
function pub_galleries(): array
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
        return ['album' => $album, 'photos' => $pstmt->fetchAll()];
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
