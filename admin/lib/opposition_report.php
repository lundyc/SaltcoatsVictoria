<?php
declare(strict_types=1);

/**
 * Opposition scouting reports: turn a COMET-export JSON file (one opponent's
 * played matches — score, events, and, where the export includes it, full
 * starting-lineup rosters with names/shirt numbers/captain/keeper flags) into
 * the summary stats shown on stats.php?tab=opposition and the PDF built by
 * opposition_report_pdf.php.
 *
 * Two attribution paths feed the same per-player counters, keyed by a
 * "SURNAME|INITIAL" canonical key so they line up:
 *  - homeLineup/awayLineup entries give a clean full name, shirt number,
 *    captain/goalkeeper flags and starts — but COMET only ever lists the
 *    starting XI here, never the bench, so this is starters-only.
 *  - the flat per-match "events" list (goal/card/substitution, with
 *    abbreviated "Surname I." names) covers every player, starters and subs
 *    alike, so it stays the source of truth for goals/cards/off/on counts.
 * Whenever a canonical key exists in both, the lineup wins for display name
 * and shirt number; a pure substitute (lineup never lists them) falls back to
 * a cleaned-up version of the abbreviated name with no shirt number, because
 * COMET simply doesn't record one for players who only ever came off the bench.
 */

/** Shared between the upload handler's JSON responses and stats.php's redirect-flow fallback, so both speak with the same voice. */
function hub_opposition_report_error_messages(): array
{
    return [
        'csrf'     => 'Your session expired — please try uploading again.',
        'nofile'   => 'Choose a JSON file to upload.',
        'upload'   => 'That upload failed. Please try again.',
        'toobig'   => 'That file is too large (10MB limit).',
        'invalid'  => 'That file doesn\'t look like a COMET matches export (expected a "matches" array).',
        'save'     => 'Could not save the uploaded file. Please try again.',
        'notfound' => 'That report could not be found.',
    ];
}

/** Writable folder the upload/list/PDF pages share. */
function hub_opposition_report_dir(): string
{
    return __DIR__ . '/../data/opposition_reports';
}

/**
 * Resolve an uploaded report's basename to its actual file path. Returns null
 * if it doesn't exist (or the name isn't a plain "*.json" basename).
 */
function hub_opposition_report_resolve(string $file): ?string
{
    $file = basename($file);
    if ($file === '' || !str_ends_with($file, '.json')) {
        return null;
    }
    $path = hub_opposition_report_dir() . '/' . $file;
    return is_file($path) ? $path : null;
}

/** Every uploaded report, newest first, with just enough decoded to list it. */
function hub_opposition_report_list(): array
{
    $out = [];
    foreach (glob(hub_opposition_report_dir() . '/*.json') ?: [] as $path) {
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $out[] = [
            'file'        => basename($path),
            'team'        => is_array($data) ? (string)($data['team'] ?? '') : '',
            'competition' => is_array($data) ? (string)($data['competition'] ?? '') : '',
            'matches'     => is_array($data) ? (int)($data['completedMatches'] ?? count($data['matches'] ?? [])) : 0,
            'size'        => (int)filesize($path),
            'modified'    => (int)filemtime($path),
            'valid'       => is_array($data) && isset($data['matches']) && is_array($data['matches']),
        ];
    }
    usort($out, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);
    return $out;
}

/**
 * Build the report model for one opponent's JSON export.
 *
 * Team-level P/W/D/L/GF/GA/CS come straight from each match's recorded score
 * (home/away flips resolved against $data['team']) so they always reconcile
 * with the scoreline regardless of how complete the event log is.
 */
function hub_opposition_report_build(array $data): array
{
    $target = trim((string)($data['team'] ?? ''));
    $matches = array_values(array_filter(is_array($data['matches'] ?? null) ? $data['matches'] : [], static function ($m) {
        return is_array($m) && strtoupper((string)($m['status'] ?? '')) === 'PLAYED' && is_array($m['score'] ?? null);
    }));
    usort($matches, static fn(array $a, array $b): int => ($a['dateTimeUTC'] ?? 0) <=> ($b['dateTimeUTC'] ?? 0));
    $totalMatches = count($matches);

    $summary = ['p' => 0, 'w' => 0, 'd' => 0, 'l' => 0, 'gf' => 0, 'ga' => 0, 'cs' => 0];
    $results = [];
    $goals = [];       // canonKey => count
    $off = [];         // canonKey => count (substituted off)
    $on = [];          // canonKey => count (brought on)
    $yellow = [];      // canonKey => count
    $red = [];         // canonKey => count
    $subMinutes = [];
    $rawByKey = [];    // canonKey => an example abbreviated name, for fallback display

    // Starting-lineup rosters (when the export includes them): canonKey =>
    // full name, shirt numbers worn, starts/captaincy/goalkeeper counts.
    // COMET only lists the starting XI here — never the bench — so a player
    // who only ever comes off the bench will have no entry.
    $starterInfo = [];

    foreach ($matches as $m) {
        $isHome = hub_opposition_team_is_target((string)($m['homeTeam'] ?? ''), $target);
        $opponent = $isHome ? (string)($m['awayTeam'] ?? '') : (string)($m['homeTeam'] ?? '');
        $forScore = (int)($isHome ? ($m['score']['home'] ?? 0) : ($m['score']['away'] ?? 0));
        $againstScore = (int)($isHome ? ($m['score']['away'] ?? 0) : ($m['score']['home'] ?? 0));
        $outcome = $forScore > $againstScore ? 'W' : ($forScore < $againstScore ? 'L' : 'D');

        $summary['p']++;
        $summary['gf'] += $forScore;
        $summary['ga'] += $againstScore;
        if ($outcome === 'W') $summary['w']++;
        elseif ($outcome === 'D') $summary['d']++;
        else $summary['l']++;
        if ($againstScore === 0) $summary['cs']++;

        $results[] = [
            'opponent' => $opponent,
            'for'      => $forScore,
            'against'  => $againstScore,
            'outcome'  => $outcome,
            'date'     => isset($m['dateTimeUTC']) ? (int)$m['dateTimeUTC'] : 0,
        ];

        $lineup = $isHome ? ($m['homeLineup'] ?? null) : ($m['awayLineup'] ?? null);
        foreach (is_array($lineup) ? $lineup : [] as $p) {
            if (!is_array($p)) continue;
            $fullName = trim((string)($p['name'] ?? ''));
            if ($fullName === '') continue;
            $key = hub_opposition_canonical_key_from_full($fullName);
            if (!isset($starterInfo[$key])) {
                $starterInfo[$key] = ['full_name' => $fullName, 'numbers' => [], 'starts' => 0, 'captain' => 0, 'goalkeeper' => 0];
            }
            $num = $p['shirtNumber'] ?? null;
            if (is_int($num) || (is_string($num) && ctype_digit($num))) {
                $num = (int)$num;
                $starterInfo[$key]['numbers'][$num] = ($starterInfo[$key]['numbers'][$num] ?? 0) + 1;
            }
            if (!empty($p['starter'])) $starterInfo[$key]['starts']++;
            if (!empty($p['captain'])) $starterInfo[$key]['captain']++;
            if (!empty($p['goalkeeper'])) $starterInfo[$key]['goalkeeper']++;
        }

        foreach (is_array($m['events'] ?? null) ? $m['events'] : [] as $e) {
            if (!is_array($e)) continue;
            $type = (string)($e['type'] ?? '');
            $team = (string)($e['team'] ?? '');
            $isTarget = hub_opposition_team_is_target($team, $target);
            if (!$isTarget) continue;

            // COMET labels penalties "penalty" in some exports and "unknown" in
            // others (mirroring the per-player rowEvents' unknown+alt:Penalty
            // pattern) — either way it's a goal for this team's scorer.
            if ($type === 'goal' || $type === 'penalty' || $type === 'unknown') {
                $player = trim((string)($e['player'] ?? ''));
                if ($player === '') continue;
                $key = hub_opposition_canonical_key_from_abbrev($player) ?? mb_strtoupper($player);
                $rawByKey[$key] ??= $player;
                $goals[$key] = ($goals[$key] ?? 0) + 1;
            } elseif ($type === 'substitution') {
                $outName = trim((string)($e['playerOut'] ?? ''));
                $inName = trim((string)($e['playerIn'] ?? ''));
                if ($outName !== '') {
                    $key = hub_opposition_canonical_key_from_abbrev($outName) ?? mb_strtoupper($outName);
                    $rawByKey[$key] ??= $outName;
                    $off[$key] = ($off[$key] ?? 0) + 1;
                }
                if ($inName !== '') {
                    $key = hub_opposition_canonical_key_from_abbrev($inName) ?? mb_strtoupper($inName);
                    $rawByKey[$key] ??= $inName;
                    $on[$key] = ($on[$key] ?? 0) + 1;
                }
                if (preg_match('/(\d+)/', (string)($e['minute'] ?? ''), $mm)) $subMinutes[] = (int)$mm[1];
            } elseif ($type === 'yellow_card') {
                $player = trim((string)($e['player'] ?? ''));
                if ($player === '') continue;
                $key = hub_opposition_canonical_key_from_abbrev($player) ?? mb_strtoupper($player);
                $rawByKey[$key] ??= $player;
                $yellow[$key] = ($yellow[$key] ?? 0) + 1;
            } elseif ($type === 'red_card') {
                $player = trim((string)($e['player'] ?? ''));
                if ($player === '') continue;
                $key = hub_opposition_canonical_key_from_abbrev($player) ?? mb_strtoupper($player);
                $rawByKey[$key] ??= $player;
                $red[$key] = ($red[$key] ?? 0) + 1;
            }
        }
    }

    $displayName = static function (string $key) use ($starterInfo, $rawByKey): string {
        if (isset($starterInfo[$key])) return hub_opposition_clean_full_name($starterInfo[$key]['full_name']);
        return hub_opposition_format_name($rawByKey[$key] ?? $key);
    };
    $usualNumber = static function (string $key) use ($starterInfo): string {
        return hub_opposition_usual_number($starterInfo[$key]['numbers'] ?? []);
    };

    arsort($goals);
    $goalThreats = [];
    foreach ($goals as $key => $count) {
        $goalThreats[] = ['player' => $displayName($key), 'number' => $usualNumber($key), 'goals' => $count];
    }

    // Usage: the 4 most-substituted-off players (regular starters), then fill
    // remaining rows (up to 7) with the highest-appearing impact substitutes
    // not already listed.
    $offSorted = $off;
    arsort($offSorted);
    $onSorted = $on;
    arsort($onSorted);
    $usageRows = [];
    $used = [];
    foreach ($offSorted as $key => $count) {
        if (count($usageRows) >= 4) break;
        $usageRows[] = ['key' => $key, 'off' => $count, 'on' => $on[$key] ?? 0];
        $used[$key] = true;
    }
    foreach ($onSorted as $key => $count) {
        if (count($usageRows) >= 7) break;
        if (isset($used[$key])) continue;
        $usageRows[] = ['key' => $key, 'off' => $off[$key] ?? 0, 'on' => $count];
        $used[$key] = true;
    }
    foreach ($usageRows as &$row) {
        $row['player'] = $displayName($row['key']);
        $row['number'] = $usualNumber($row['key']);
        unset($row['key']);
    }
    unset($row);

    // Discipline: only players with a notable record (2+ yellows, or any red).
    $discRows = [];
    $allCarded = array_unique(array_merge(array_keys($yellow), array_keys($red)));
    foreach ($allCarded as $key) {
        $yc = $yellow[$key] ?? 0;
        $rc = $red[$key] ?? 0;
        if ($yc >= 2 || $rc >= 1) {
            $discRows[] = ['key' => $key, 'yc' => $yc, 'rc' => $rc];
        }
    }
    usort($discRows, static fn(array $a, array $b): int => $b['yc'] <=> $a['yc'] ?: $b['rc'] <=> $a['rc']);
    foreach ($discRows as &$row) {
        $row['player'] = $displayName($row['key']);
        $row['number'] = $usualNumber($row['key']);
        unset($row['key']);
    }
    unset($row);

    // Regulars: every player the lineup export actually names, ranked by how
    // often they started — the closest thing to "their likely XI" this data
    // supports, each with the shirt number they're most often seen in.
    $regulars = [];
    foreach ($starterInfo as $key => $info) {
        if ($info['starts'] < 2) continue;
        $regulars[] = [
            'player' => hub_opposition_clean_full_name($info['full_name']),
            'number' => hub_opposition_usual_number($info['numbers']),
            'starts' => $info['starts'],
            'goals'  => $goals[$key] ?? 0,
            'yc'     => $yellow[$key] ?? 0,
            'rc'     => $red[$key] ?? 0,
        ];
    }
    usort($regulars, static fn(array $a, array $b): int => $b['starts'] <=> $a['starts'] ?: strcmp($a['player'], $b['player']));

    // Captain and goalkeeper: whoever wore the armband/gloves most often.
    $captain = null;
    $goalkeeper = null;
    foreach ($starterInfo as $info) {
        if ($info['captain'] > 0 && ($captain === null || $info['captain'] > $captain['matches'])) {
            $captain = ['player' => hub_opposition_clean_full_name($info['full_name']), 'number' => hub_opposition_usual_number($info['numbers']), 'matches' => $info['captain']];
        }
        if ($info['goalkeeper'] > 0 && ($goalkeeper === null || $info['goalkeeper'] > $goalkeeper['matches'])) {
            $goalkeeper = ['player' => hub_opposition_clean_full_name($info['full_name']), 'number' => hub_opposition_usual_number($info['numbers']), 'matches' => $info['goalkeeper']];
        }
    }

    $keyFacts = hub_opposition_report_key_facts($results, $goalThreats, $usageRows, $discRows, $subMinutes, $totalMatches);

    return [
        'team'          => $target,
        'competition'   => (string)($data['competition'] ?? ''),
        'total_matches' => $totalMatches,
        'has_lineups'   => $starterInfo !== [],
        'summary'       => $summary,
        'results'       => $results,
        'goal_threats'  => $goalThreats,
        'usage'         => $usageRows,
        'discipline'    => $discRows,
        'regulars'      => $regulars,
        'captain'       => $captain,
        'goalkeeper'    => $goalkeeper,
        'key_facts'     => $keyFacts,
    ];
}

/** Short, scannable bullet points summarising the numbers above. */
function hub_opposition_report_key_facts(
    array $results,
    array $goalThreats,
    array $usageRows,
    array $discRows,
    array $subMinutes,
    int $totalMatches
): array {
    $facts = [];
    $withNumber = static fn(array $row): string => $row['player'] . ($row['number'] !== '' ? ' (#' . $row['number'] . ')' : '');

    foreach (array_slice($goalThreats, 0, 2) as $g) {
        if ($g['goals'] < 1) continue;
        $facts[] = $withNumber($g) . ': ' . $g['goals'] . ' goal' . ($g['goals'] === 1 ? '' : 's') . '.';
    }

    $topOn = null;
    foreach ($usageRows as $row) {
        if ($topOn === null || $row['on'] > $topOn['on']) $topOn = $row;
    }
    if ($topOn !== null && $topOn['on'] >= 3 && $totalMatches > 0) {
        $facts[] = $withNumber($topOn) . ': brought on in ' . $topOn['on'] . ' of ' . $totalMatches . ' matches.';
    }

    if ($discRows) {
        $top = $discRows[0];
        if ($top['yc'] >= 2) {
            $facts[] = $withNumber($top) . ': ' . $top['yc'] . ' recorded yellows' . ($top['number'] !== '' ? ' — watch for #' . $top['number'] : '') . '.';
        }
    }

    // Current streak, most recent match backwards.
    $recent = array_reverse($results);
    if ($recent) {
        $streakOutcome = $recent[0]['outcome'];
        $streakLen = 0;
        $streakFor = 0;
        $streakAgainst = 0;
        foreach ($recent as $r) {
            if ($r['outcome'] !== $streakOutcome) break;
            $streakLen++;
            $streakFor += $r['for'];
            $streakAgainst += $r['against'];
        }
        if ($streakLen >= 3 && $streakOutcome === 'W') {
            $facts[] = 'Form: ' . hub_opposition_number_word($streakLen) . ' straight wins (' . $streakFor . ' scored, ' . $streakAgainst . ' conceded).';
        } elseif ($streakLen >= 3 && $streakOutcome === 'L') {
            $facts[] = 'Form: ' . hub_opposition_number_word($streakLen) . ' straight losses (' . $streakFor . ' scored, ' . $streakAgainst . ' conceded).';
        }
    }

    if (count($subMinutes) >= 8) {
        sort($subMinutes);
        $n = count($subMinutes);
        $p25 = $subMinutes[(int)floor(($n - 1) * 0.25)];
        $p75 = $subMinutes[(int)floor(($n - 1) * 0.75)];
        $round5 = static fn(int $v): int => (int)(round($v / 5) * 5);
        $lo = $round5($p25);
        $hi = $round5($p75);
        if ($hi > $lo) {
            $facts[] = 'Bench: multiple changes commonly made around ' . $lo . '-' . $hi . ' minutes.';
        }
    }

    return $facts;
}

function hub_opposition_number_word(int $n): string
{
    $words = [0 => 'zero', 1 => 'one', 2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six', 7 => 'seven', 8 => 'eight', 9 => 'nine', 10 => 'ten'];
    return $words[$n] ?? (string)$n;
}

/**
 * COMET's top-level "team" field is often a shortened club name (e.g.
 * "Glenvale") while homeTeam/awayTeam/event "team" strings carry the full
 * legal suffix ("Glenvale AFC", "East Kilbride Thistle F.C."). Treat it as a
 * match when one is a case-insensitive prefix of the other, ending cleanly
 * at a space/punctuation boundary rather than mid-word.
 */
function hub_opposition_team_is_target(string $teamName, string $target): bool
{
    $teamName = trim($teamName);
    $target = trim($target);
    if ($target === '' || $teamName === '') return false;
    if (strcasecmp($teamName, $target) === 0) return true;
    return (bool)preg_match('/^' . preg_quote($target, '/') . '(?=[\s.]|$)/i', $teamName);
}

/** "Surname" (or "Surname I.") canonical key, e.g. "MCDONAGH|R", used to match a lineup entry to its abbreviated-event counterpart. */
function hub_opposition_canonical_key_from_abbrev(string $raw): ?string
{
    $raw = trim($raw);
    if (!preg_match('/^(.+?)\s+([A-Za-z])\.?$/', $raw, $m)) {
        return null;
    }
    return mb_strtoupper($m[1]) . '|' . mb_strtoupper($m[2]);
}

/** Same canonical key, derived from a lineup's full "Firstname [Middlename...] Surname". */
function hub_opposition_canonical_key_from_full(string $fullName): string
{
    $parts = array_values(array_filter(preg_split('/\s+/', trim($fullName)) ?: [], static fn($p) => $p !== ''));
    if (!$parts) return mb_strtoupper($fullName);
    $initial = mb_strtoupper(mb_substr($parts[0], 0, 1));
    $surname = end($parts);
    return mb_strtoupper($surname) . '|' . $initial;
}

/**
 * Given a Counter-style [number => appearances] map, return the shirt number
 * a player is most often seen wearing — as a plain string, "7/11" when two
 * numbers are tied, "varies" when it's too scattered to call, or '' when
 * unknown (typically a substitute who never appears in a lineup roster).
 */
function hub_opposition_usual_number(array $numbers): string
{
    if (!$numbers) return '';
    $max = max($numbers);
    $tied = array_keys(array_filter($numbers, static fn(int $c): bool => $c === $max));
    sort($tied, SORT_NUMERIC);
    if (count($tied) <= 2) return implode('/', $tied);
    return 'varies';
}

/**
 * COMET's own data has erratic surname casing at the source (e.g. a lineup
 * entry named "Jack McKENNA"), not just an artefact of abbreviation — so both
 * full names and abbreviated "Surname I." forms need the same casing fix
 * (Mc/Mac/O' + hyphenated surnames handled).
 */
function hub_opposition_fix_word_casing(string $word): string
{
    $fix = static function (string $part): string {
        $lower = mb_strtolower($part);
        if (str_starts_with($lower, 'mc') && strlen($lower) > 2) {
            return 'Mc' . ucfirst(substr($lower, 2));
        }
        if (str_starts_with($lower, 'mac') && strlen($lower) > 3) {
            return 'Mac' . ucfirst(substr($lower, 3));
        }
        if (str_starts_with($lower, "o'") && strlen($lower) > 2) {
            return "O'" . ucfirst(substr($lower, 2));
        }
        return ucfirst($lower);
    };
    return implode('-', array_map($fix, explode('-', $word)));
}

/** Clean up a full "Firstname Surname" name's casing, word by word. */
function hub_opposition_clean_full_name(string $fullName): string
{
    $words = array_values(array_filter(preg_split('/\s+/', trim($fullName)) ?: [], static fn($w) => $w !== ''));
    return implode(' ', array_map('hub_opposition_fix_word_casing', $words));
}

/**
 * Reformat an abbreviated "Surname I." name (COMET's format for anyone not in
 * a lineup roster, e.g. a pure substitute) to "I. Surname" for display.
 */
function hub_opposition_format_name(string $raw): string
{
    $raw = trim($raw);
    if (!preg_match('/^(.+?)\s+([A-Za-z])\.?$/', $raw, $m)) {
        return $raw;
    }
    return mb_strtoupper($m[2]) . '. ' . hub_opposition_fix_word_casing($m[1]);
}
