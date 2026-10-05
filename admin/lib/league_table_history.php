<?php

declare(strict_types=1);

/**
 * Historical league tables, one saved standings snapshot per season.
 *
 * The live/current season keeps using the existing scraper cache
 * (cache/wosfl_table.json, lib/wosfl_table.php, league_table_manual_update.php)
 * exactly as before — this is deliberately a separate, parallel store for
 * *past* seasons, which nothing scrapes any more. An admin pastes a table
 * (HTML copied from a browser, JSON, or plain/CSV-ish text) on
 * league_table_history.php; the public /table page reads it back for any
 * season that isn't the current one (lib/table.php pub_league_table_for_season()).
 */

function ensureLeagueTableHistorySchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $exists = (bool) $pdo->query("SHOW TABLES LIKE 'league_table_history'")->fetchColumn();
    if (!$exists) {
        $pdo->exec("
            CREATE TABLE league_table_history (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                season_id INT UNSIGNED NOT NULL,
                competition_title VARCHAR(190) DEFAULT NULL,
                promotion_spots SMALLINT UNSIGNED DEFAULT NULL,
                relegation_spots SMALLINT UNSIGNED DEFAULT NULL,
                standings_json LONGTEXT NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
                notes VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_league_table_history_season (season_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

/** @return list<array<string,mixed>>|null every saved row for a season, plus metadata; null if nothing saved. */
function leagueTableHistoryGet(PDO $pdo, int $seasonId): ?array
{
    ensureLeagueTableHistorySchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM league_table_history WHERE season_id = :s LIMIT 1');
    $stmt->execute([':s' => $seasonId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $rows = json_decode((string) $row['standings_json'], true);
    $row['rows'] = is_array($rows) ? $rows : [];
    return $row;
}

/** @return array<int,array{season_id:int,updated_at:?string,rows:int}> every season with a saved table, newest season first. */
function leagueTableHistoryList(PDO $pdo): array
{
    ensureLeagueTableHistorySchema($pdo);
    $stmt = $pdo->query("
        SELECT h.season_id, h.competition_title, h.updated_at, h.created_at, h.standings_json, s.name AS season_name, s.start_date
        FROM league_table_history h
        JOIN seasons s ON s.id = h.season_id
        ORDER BY s.start_date DESC
    ");
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows = json_decode((string) $row['standings_json'], true);
        $row['rows'] = is_array($rows) ? count($rows) : 0;
        unset($row['standings_json']);
        $out[] = $row;
    }
    return $out;
}

/** @param list<array<string,mixed>> $rows normalised standings rows (pos/club/p/w/d/l/f/a/gd/pts[/logo][/form]) */
function leagueTableHistorySave(
    PDO $pdo,
    int $seasonId,
    array $rows,
    ?string $title,
    ?int $promotionSpots,
    ?int $relegationSpots,
    string $source,
    ?string $notes = null
): void {
    ensureLeagueTableHistorySchema($pdo);
    $title = $title !== null ? trim($title) : '';
    $stmt = $pdo->prepare('
        INSERT INTO league_table_history (season_id, competition_title, promotion_spots, relegation_spots, standings_json, source, notes)
        VALUES (:season_id, :title, :promo, :releg, :rows, :source, :notes)
        ON DUPLICATE KEY UPDATE
            competition_title = VALUES(competition_title),
            promotion_spots = VALUES(promotion_spots),
            relegation_spots = VALUES(relegation_spots),
            standings_json = VALUES(standings_json),
            source = VALUES(source),
            notes = VALUES(notes),
            updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([
        ':season_id' => $seasonId,
        ':title' => $title !== '' ? $title : null,
        ':promo' => $promotionSpots,
        ':releg' => $relegationSpots,
        ':rows' => json_encode(array_values($rows), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':source' => $source,
        ':notes' => $notes,
    ]);
}

function leagueTableHistoryDelete(PDO $pdo, int $seasonId): void
{
    ensureLeagueTableHistorySchema($pdo);
    $pdo->prepare('DELETE FROM league_table_history WHERE season_id = :s')->execute([':s' => $seasonId]);
}

/* -------------------------------------------------------------------------
 * Quick import — one paste of {"season": "...", "league": "...", "table": [...]}
 * (the shape a season-by-season history site/export tends to produce) creates
 * the season and competition if they don't exist yet, and saves the table —
 * no season needs to be picked first. This is the fast path; the season
 * picker + single-format textarea further down the page still exist for
 * fixing up one season, or for pastes that don't carry season info.
 * ---------------------------------------------------------------------- */

/**
 * "1988-89" / "1988/1989" / "1988" -> a seasons-table-shaped spec. Mirrors the
 * "1 Jul – 30 Jun" convention every other season in this DB already uses.
 * A 2-digit end year rolls over the century when it's not simply +1
 * ("1999-00" -> 2000, not 1900).
 *
 * @return array{name:string,start:string,end:string}|null
 */
function leagueTableHistoryParseSeasonLabel(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }

    if (preg_match('/(\d{4})\s*[\/\-–—]\s*(\d{2,4})/', $raw, $m) === 1) {
        $y0 = (int) $m[1];
        $second = $m[2];
        if (strlen($second) === 4) {
            $y1 = (int) $second;
        } else {
            $y1 = intdiv($y0, 100) * 100 + (int) $second;
            if ($y1 <= $y0) {
                $y1 += 100;
            }
        }
    } elseif (preg_match('/(\d{4})/', $raw, $m) === 1) {
        $y0 = (int) $m[1];
        $y1 = $y0 + 1;
    } else {
        return null;
    }

    return [
        'name' => sprintf('%d / %d', $y0, $y1),
        'start' => sprintf('%d-07-01', $y0),
        'end' => sprintf('%d-06-30', $y1),
    ];
}

/**
 * Full one-paste import: {"season": "...", "league": "...", "table": [...]}
 * (also accepts "competition" for "league", and a bare "rows" in place of
 * "table"). Creates the seasons row and match_competitions row if they don't
 * already exist (matched by exact name), then saves the table.
 *
 * @return array{ok:bool, errors:list<string>, season?:array{id:int,name:string,created:bool}, competition?:?array{id:int,name:string,created:bool}, rows?:int}
 */
function leagueTableHistoryImportFull(PDO $pdo, string $raw, string $badgeDir, array $badgeOverrides): array
{
    $decoded = json_decode(trim($raw), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return ['ok' => false, 'errors' => ['Could not parse that as JSON: ' . json_last_error_msg() . '.']];
    }

    $seasonRaw = trim((string) ($decoded['season'] ?? ''));
    $tableRaw = $decoded['table'] ?? ($decoded['rows'] ?? null);
    if ($seasonRaw === '' || !is_array($tableRaw)) {
        return ['ok' => false, 'errors' => ['Expected a "season" string (e.g. "1988-89") and a "table" array of team rows.']];
    }

    $seasonSpec = leagueTableHistoryParseSeasonLabel($seasonRaw);
    if ($seasonSpec === null) {
        return ['ok' => false, 'errors' => ['Could not work out a season from "' . $seasonRaw . '".']];
    }

    $parsed = leagueTableHistoryParseJson(json_encode($tableRaw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');
    if ($parsed['rows'] === []) {
        return ['ok' => false, 'errors' => array_merge(['No standings rows could be read from "table".'], $parsed['errors'])];
    }
    $parsed['rows'] = leagueTableHistoryAttachLogos($parsed['rows'], $badgeDir, $badgeOverrides);

    ensureSeasonSchema($pdo);
    // Match on the computed start date first — the reliable key, since two
    // imports of "1988-89" always compute the same 1988-07-01 regardless of
    // how the name gets punctuated ("1988 / 1989" vs "1988-1989" etc, which
    // is exactly how this DB ended up with two rows for the same season
    // before this check existed). Exact name is the fallback for a season
    // that was hand-entered without a start_date.
    $stmt = $pdo->prepare('SELECT id FROM seasons WHERE start_date = ? LIMIT 1');
    $stmt->execute([$seasonSpec['start']]);
    $seasonId = (int) $stmt->fetchColumn();
    if ($seasonId === 0) {
        $stmt = $pdo->prepare('SELECT id FROM seasons WHERE name = ? LIMIT 1');
        $stmt->execute([$seasonSpec['name']]);
        $seasonId = (int) $stmt->fetchColumn();
    }
    $seasonCreated = false;
    if ($seasonId === 0) {
        $ins = $pdo->prepare('INSERT INTO seasons (name, start_date, end_date, is_current, is_locked) VALUES (?, ?, ?, 0, 1)');
        $ins->execute([$seasonSpec['name'], $seasonSpec['start'], $seasonSpec['end']]);
        $seasonId = (int) $pdo->lastInsertId();
        $seasonCreated = true;
    }

    $current = getCurrentSeason($pdo);
    if ($current && (int) $current['id'] === $seasonId) {
        return ['ok' => false, 'errors' => ['"' . $seasonSpec['name'] . '" is the current working season — use "Update from WOSFL" on the League Table page for it instead.']];
    }

    $leagueName = trim((string) ($decoded['league'] ?? $decoded['competition'] ?? ''));
    $competition = null;
    if ($leagueName !== '') {
        $stmt = $pdo->prepare('SELECT id FROM match_competitions WHERE name = ?');
        $stmt->execute([$leagueName]);
        $compId = (int) $stmt->fetchColumn();
        $compCreated = false;
        if ($compId === 0) {
            $ins = $pdo->prepare("INSERT INTO match_competitions (name, competition_type, is_league) VALUES (?, 'league', 1)");
            $ins->execute([$leagueName]);
            $compId = (int) $pdo->lastInsertId();
            $compCreated = true;
        }
        $competition = ['id' => $compId, 'name' => $leagueName, 'created' => $compCreated];
    }

    leagueTableHistorySave($pdo, $seasonId, $parsed['rows'], $leagueName, null, null, 'manual:json-import');

    return [
        'ok' => true,
        'errors' => $parsed['errors'],
        'season' => ['id' => $seasonId, 'name' => $seasonSpec['name'], 'created' => $seasonCreated],
        'competition' => $competition,
        'rows' => count($parsed['rows']),
    ];
}

/* -------------------------------------------------------------------------
 * Parsing — one textarea, three accepted shapes: pasted HTML (reuses the
 * existing WOSFL scraper's parser), JSON, or a loosely-formatted text table.
 * ---------------------------------------------------------------------- */

/** @param list<array<string,string>> $rows @return list<array<string,string>> */
function leagueTableHistoryAttachLogos(array $rows, string $badgeDir, array $badgeOverrides): array
{
    foreach ($rows as &$row) {
        if (trim((string) ($row['logo'] ?? '')) === '') {
            [$localLogo] = wosfl_resolve_badge((string) $row['club'], '', $badgeDir, $badgeOverrides);
            $row['logo'] = $localLogo;
        }
    }
    unset($row);
    return $rows;
}

/** @return array{rows:list<array<string,string>>, errors:list<string>, format:string} */
function leagueTableHistoryParseInput(string $raw, string $badgeDir, array $badgeOverrides): array
{
    $trimmed = trim($raw);
    if ($trimmed === '') {
        return ['rows' => [], 'errors' => ['Paste a table first.'], 'format' => 'empty'];
    }

    if (preg_match('/<table[\s>]/i', $trimmed) === 1 || preg_match('/<tr[\s>]/i', $trimmed) === 1) {
        $rows = wosfl_parse_standings_html($trimmed, $badgeDir, $badgeOverrides);
        $errors = $rows === []
            ? ['Could not find a standings table in that HTML — it needs rows with 10 cells (Pos, Club, P, W, D, L, F, A, GD, Pts).']
            : [];
        return ['rows' => $rows, 'errors' => $errors, 'format' => 'html'];
    }

    if ($trimmed[0] === '[' || $trimmed[0] === '{') {
        $result = leagueTableHistoryParseJson($trimmed);
    } else {
        $result = leagueTableHistoryParseText($trimmed);
    }

    $result['rows'] = leagueTableHistoryAttachLogos($result['rows'], $badgeDir, $badgeOverrides);

    return $result;
}

/** @return array{rows:list<array<string,string>>, errors:list<string>, format:string} */
function leagueTableHistoryParseJson(string $raw): array
{
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return ['rows' => [], 'errors' => ['Could not parse that as JSON: ' . json_last_error_msg() . '.'], 'format' => 'json'];
    }
    $list = isset($decoded['rows']) && is_array($decoded['rows']) ? $decoded['rows'] : $decoded;

    $aliases = [
        'pos' => ['pos', 'position', 'rank', 'place'],
        'club' => ['club', 'team', 'name'],
        'p' => ['p', 'played', 'pld', 'games', 'gp'],
        'w' => ['w', 'won', 'wins'],
        'd' => ['d', 'drawn', 'draws', 'draw'],
        'l' => ['l', 'lost', 'losses', 'loss'],
        'f' => ['f', 'for', 'gf', 'goals_for'],
        'a' => ['a', 'against', 'ga', 'goals_against'],
        'gd' => ['gd', 'goal_difference', 'goaldiff', 'diff'],
        'pts' => ['pts', 'points'],
    ];

    $rows = [];
    $errors = [];
    foreach (array_values($list) as $i => $row) {
        if (!is_array($row)) {
            $errors[] = 'Row ' . ($i + 1) . ': not an object.';
            continue;
        }
        $lower = [];
        foreach ($row as $k => $v) {
            $lower[strtolower((string) $k)] = $v;
        }
        $out = [];
        foreach ($aliases as $key => $names) {
            $val = null;
            foreach ($names as $name) {
                if (array_key_exists($name, $lower) && $lower[$name] !== null && $lower[$name] !== '') {
                    $val = $lower[$name];
                    break;
                }
            }
            $out[$key] = $val;
        }
        $club = trim((string) ($out['club'] ?? ''));
        if ($club === '') {
            $errors[] = 'Row ' . ($i + 1) . ': missing club/team name.';
            continue;
        }
        $missing = [];
        foreach (['p', 'w', 'd', 'l', 'pts'] as $k) {
            if ($out[$k] === null) {
                $missing[] = $k;
            }
        }
        if ($missing !== []) {
            $errors[] = "Row " . ($i + 1) . " ($club): missing " . implode(', ', $missing) . '.';
            continue;
        }
        if ($out['f'] === null) {
            $out['f'] = '0';
        }
        if ($out['a'] === null) {
            $out['a'] = '0';
        }
        if ($out['gd'] === null) {
            $out['gd'] = (string) ((int) $out['f'] - (int) $out['a']);
        }
        $out['pos'] = $out['pos'] !== null ? (string) $out['pos'] : (string) (count($rows) + 1);
        $out['club'] = $club;
        foreach (['p', 'w', 'd', 'l', 'f', 'a', 'gd', 'pts'] as $k) {
            $out[$k] = (string) $out[$k];
        }
        if (isset($row['form'])) {
            $out['form'] = is_array($row['form'])
                ? array_values(array_map('strval', $row['form']))
                : str_split(strtoupper(trim((string) $row['form'])));
        }
        $rows[] = $out;
    }

    return ['rows' => $rows, 'errors' => $errors, 'format' => 'json'];
}

/**
 * Loosely-formatted text: one team per line, columns separated by tabs,
 * commas, "|", or 2+ spaces (whatever a copy-paste from Excel/a webpage
 * produces). Numeric columns are peeled off the end of the line — P W D L
 * [F A] [GD] Pts — so club names never need special-casing.
 *
 * @return array{rows:list<array<string,string>>, errors:list<string>, format:string}
 */
function leagueTableHistoryParseText(string $raw): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
    $rows = [];
    $errors = [];

    foreach ($lines as $lineNo => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if ($lineNo === 0 && preg_match('/\b(club|team)\b/i', $line) === 1 && preg_match('/\b(pts|points)\b/i', $line) === 1) {
            continue; // header row
        }

        $cells = null;
        foreach (["\t", '|', ','] as $delim) {
            if (str_contains($line, $delim)) {
                $cells = array_map('trim', explode($delim, $line));
                break;
            }
        }
        if ($cells === null) {
            // No explicit delimiter — split on any run of whitespace, so a
            // multi-word club name typed with single spaces ("Test Town 10 8
            // 1 1 20 5 25") still works: the trailing/leading digit-peeling
            // below re-assembles everything in between as the club name.
            $cells = preg_split('/\s+/', $line) ?: [$line];
        }
        $cells = array_values(array_filter($cells, static fn (string $c): bool => $c !== ''));

        $nums = [];
        while ($cells !== [] && preg_match('/^-?\d+$/', (string) end($cells)) === 1) {
            array_unshift($nums, array_pop($cells));
        }

        $leadPos = null;
        if ($cells !== [] && preg_match('/^\d+$/', (string) $cells[0]) === 1) {
            $leadPos = (int) array_shift($cells);
        }
        $club = trim(implode(' ', $cells));

        if ($club === '' || count($nums) < 6) {
            $errors[] = 'Line ' . ($lineNo + 1) . ': could not read "' . $line . '" (need a club name plus at least P W D L … Pts).';
            continue;
        }

        $count = count($nums);
        if ($count === 8) {
            [$p, $w, $d, $l, $f, $a, $gd, $pts] = $nums;
        } elseif ($count === 7) {
            [$p, $w, $d, $l, $f, $a, $pts] = $nums;
            $gd = (string) ((int) $f - (int) $a);
        } elseif ($count === 6) {
            [$p, $w, $d, $l, $f, $pts] = $nums;
            $a = '0';
            $gd = (string) ((int) $f - (int) $a);
        } else {
            $errors[] = 'Line ' . ($lineNo + 1) . ': expected 6-8 number columns after the club name (P W D L [F A] [GD] Pts), found ' . $count . ' ("' . $line . '").';
            continue;
        }

        $rows[] = [
            'pos' => $leadPos !== null ? (string) $leadPos : (string) (count($rows) + 1),
            'club' => $club,
            'p' => $p, 'w' => $w, 'd' => $d, 'l' => $l, 'f' => $f, 'a' => $a, 'gd' => $gd, 'pts' => $pts,
        ];
    }

    return ['rows' => $rows, 'errors' => $errors, 'format' => 'text'];
}
