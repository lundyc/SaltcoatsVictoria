<?php

declare(strict_types=1);

/*
 * League table for the public site.
 *
 * The Hub scrapes the WOSFL standings (wosfl-table.php, refreshed whenever
 * staff view /league_table.php) and writes them to cache/wosfl_table.json. The
 * public site only ever READS that cache — it never runs the scraper, which has
 * die()s and other side effects unsuitable for a public request. If the cache
 * is missing we degrade to a "view on WOSFL" link.
 */

function pub_league_cache_path(): string
{
    return HUB_ROOT . '/cache/wosfl_table.json';
}

/**
 * @return array{ok:bool, rows:list<array<string,mixed>>, updated:?int, url:string, title:string}
 */
function pub_league_table(): array
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }

    $config = pub_league_config();
    $path = pub_league_cache_path();
    $rows = [];
    $updated = null;

    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $rows = array_values(array_filter($decoded, 'is_array'));
            $updated = @filemtime($path) ?: null;
        }
    }

    return $result = [
        'ok' => $rows !== [],
        'rows' => $rows,
        'updated' => $updated,
        'url' => $config['url'],
        'title' => $config['title'],
    ];
}

/**
 * @return array{title:string, url:string, promotion:int, relegation:int}
 */
function pub_league_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $data = [];
    $file = HUB_ROOT . '/league-config.json';
    if (is_file($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    // A club-configured override always wins.
    $override = club('league_table_url');

    return $cfg = [
        'title'      => (string) ($data['league_title'] ?? 'League table'),
        'url'        => $override !== '' ? $override : (string) ($data['wosfl_table_url'] ?? 'https://www.wosfl.co.uk/'),
        'promotion'  => max(0, (int) ($data['promotion_spots'] ?? 0)),
        'relegation' => max(0, (int) ($data['relegation_spots'] ?? 0)),
    ];
}

/* -------------------------------------------------------------------------
 * Season picker (/table?season=<id>)
 *
 * The current season always reads the live scraper cache above — untouched.
 * Any other season reads a snapshot an admin pasted in via the Hub's
 * "Historical tables" tool (admin/league_table_history.php,
 * league_table_history DB table) — a separate, parallel store nothing
 * scrapes automatically, so read-only here just like every other public
 * query against Hub-managed data.
 * ---------------------------------------------------------------------- */

/** @return list<array{id:int,name:string,is_current:int}> every season, newest first. */
function pub_league_table_seasons(): array
{
    try {
        return db()->query('SELECT id, name, is_current FROM seasons ORDER BY start_date DESC, id DESC')->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

/**
 * The league table for a given season: the live scrape for the current
 * season, otherwise whatever was pasted into league_table_history for it.
 *
 * @return array{ok:bool, rows:list<array<string,mixed>>, updated:?int, url:string, title:string, promotion:int, relegation:int, is_live:bool}
 */
function pub_league_table_for_season(int $seasonId): array
{
    $config = pub_league_config();

    if ($seasonId <= 0 || $seasonId === pub_current_season_id()) {
        $live = pub_league_table();
        return $live + ['promotion' => $config['promotion'], 'relegation' => $config['relegation'], 'is_live' => true];
    }

    $row = null;
    try {
        $stmt = db()->prepare('SELECT * FROM league_table_history WHERE season_id = :s LIMIT 1');
        $stmt->execute([':s' => $seasonId]);
        $row = $stmt->fetch();
    } catch (Throwable) {
        $row = null; // table not migrated yet, or DB hiccup — degrade to "no data"
    }

    if (!$row) {
        return [
            'ok' => false, 'rows' => [], 'updated' => null,
            'url' => $config['url'], 'title' => $config['title'],
            'promotion' => $config['promotion'], 'relegation' => $config['relegation'],
            'is_live' => false,
        ];
    }

    $rows = json_decode((string) $row['standings_json'], true);
    $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    $title = trim((string) ($row['competition_title'] ?? ''));
    $updatedRaw = $row['updated_at'] ?: $row['created_at'] ?? null;

    return [
        'ok' => $rows !== [],
        'rows' => $rows,
        'updated' => $updatedRaw ? strtotime((string) $updatedRaw) : null,
        'url' => $config['url'],
        'title' => $title !== '' ? $title : $config['title'],
        'promotion' => $row['promotion_spots'] !== null ? (int) $row['promotion_spots'] : $config['promotion'],
        'relegation' => $row['relegation_spots'] !== null ? (int) $row['relegation_spots'] : $config['relegation'],
        'is_live' => false,
    ];
}

/** Is this row our club? */
function pub_league_is_us(array $row): bool
{
    return stripos((string) ($row['club'] ?? ''), 'saltcoats') !== false;
}

/** Our row in the table, or null. */
function pub_league_our_row(): ?array
{
    foreach (pub_league_table()['rows'] as $row) {
        if (pub_league_is_us($row)) {
            return $row;
        }
    }
    return null;
}

/** Ordinal like 1st, 2nd, 13th. */
function pub_ordinal(int $n): string
{
    if ($n <= 0) {
        return '—';
    }
    $mod100 = $n % 100;
    $suffix = ($mod100 >= 11 && $mod100 <= 13) ? 'th'
        : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$n % 10] ?? 'th');
    return $n . $suffix;
}

/** Squash a club name to a comparison key ("Giffnock SC" -> "giffnock"). */
function pub_norm_club(string $name): string
{
    $n = strtolower(trim($name));
    $n = preg_replace('/\b(a?fc|f\.?c\.?|jfc|junior football club|association football club|football club)\b/', ' ', $n) ?? $n;
    return preg_replace('/[^a-z0-9]+/', '', $n) ?? '';
}

/** The WOSFL table row for a club name, matched loosely, or null. */
function pub_league_find_row(string $name): ?array
{
    $target = pub_norm_club($name);
    if ($target === '') {
        return null;
    }
    $rows = pub_league_table()['rows'];
    foreach ($rows as $row) {
        if (pub_norm_club((string) ($row['club'] ?? '')) === $target) {
            return $row;
        }
    }
    // "Giffnock" vs "Giffnock SC" etc. — one is a long prefix of the other.
    foreach ($rows as $row) {
        $rc = pub_norm_club((string) ($row['club'] ?? ''));
        if ($rc === '') {
            continue;
        }
        [$short, $long] = strlen($rc) <= strlen($target) ? [$rc, $target] : [$target, $rc];
        if (strlen($short) >= 7 && str_starts_with($long, $short)) {
            return $row;
        }
    }
    return null;
}

/** Does this competition string look like the league (not a cup / friendly)? */
function pub_is_league_fixture(string $competition): bool
{
    $c = strtolower(trim($competition));
    if ($c === '') {
        return false;
    }
    foreach (['cup', 'trophy', 'shield', 'friendly', 'final', 'play-off', 'playoff'] as $needle) {
        if (str_contains($c, $needle)) {
            return false;
        }
    }
    $title = strtolower(pub_league_config()['title']);
    return str_contains($c, 'wosfl')
        || str_contains($c, 'west of scotland')
        || str_contains($c, 'division')
        || str_contains($c, 'league')
        || ($title !== '' && str_contains($c, $title));
}
