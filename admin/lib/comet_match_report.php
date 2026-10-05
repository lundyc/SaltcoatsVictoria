<?php

declare(strict_types=1);

/**
 * COMET match-report (Scottish FA) PDF importer.
 *
 * Pure parsing + resolution helpers — no database writes, no file writes.
 * The controller (match_report_import.php) is responsible for persistence.
 *
 * Pipeline:
 *   comet_report_extract_text()  PDF  -> plain text   (smalot/pdfparser)
 *   comet_report_parse()         text -> structured report (both teams)
 *   comet_report_resolve()       report + fixture -> Saltcoats-only import plan
 *
 * Scope of an import is deliberately "Saltcoats only": the club squad is the
 * only source of canonical player names, so opponent goals/cards/subs and
 * Saltcoats own-goals (which count for the opponent) are reported but not
 * written. Squad numbers stay positional, matching the Starting XI editor.
 */

const COMET_CLUB_NAME_HINTS = ['saltcoats victoria', 'saltcoats vics', 'saltcoats vic', 'saltcoats'];

/**
 * Extract the text layer from a COMET match-report PDF.
 *
 * @throws RuntimeException when the parser is missing or the file cannot be read.
 */
function comet_report_extract_text(string $pdfPath): string
{
    if (!is_file($pdfPath) || !is_readable($pdfPath)) {
        throw new RuntimeException('The uploaded PDF could not be read.');
    }

    if (!class_exists(\Smalot\PdfParser\Parser::class)) {
        foreach ([__DIR__ . '/../vendor/autoload.php', dirname(__DIR__, 2) . '/vendor/autoload.php'] as $autoloader) {
            if (is_file($autoloader)) {
                require_once $autoloader;
                break;
            }
        }
    }
    if (!class_exists(\Smalot\PdfParser\Parser::class)) {
        throw new RuntimeException('The PDF text extractor is not installed on this server.');
    }

    try {
        $parser = new \Smalot\PdfParser\Parser();
        $document = $parser->parseFile($pdfPath);
        $text = $document->getText();
    } catch (Throwable $error) {
        throw new RuntimeException('This file could not be read as a PDF (' . $error->getMessage() . ').');
    }

    $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
    if (trim($text) === '') {
        throw new RuntimeException('No readable text was found in this PDF. If it is a scan, paste the report text instead.');
    }

    return $text;
}

/**
 * Normalise a person name for comparison: lower-case, strip accents and
 * punctuation, collapse whitespace. "Adam Zając" -> "adam zajac".
 */
function comet_report_normalize_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    if ($ascii !== false && $ascii !== '') {
        $name = $ascii;
    }

    $name = strtolower($name);
    $name = str_replace(['.', "'", '`', '-'], [' ', '', '', ' '], $name);
    $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name) ?? '';
    $name = preg_replace('/\s+/', ' ', $name) ?? '';

    return trim($name);
}

/** Strip a trailing minute apostrophe and validate COMET's "45" / "45+2" form. */
function comet_report_clean_minute(string $raw): string
{
    $raw = trim(str_replace(['’', "'"], '', $raw));
    return preg_match('/^\d{1,3}(?:\+\d{1,2})?$/', $raw) === 1 ? $raw : '';
}

/**
 * Break a "GOALS" / "SUBSTITUTIONS" block into one chunk per minute-stamped
 * entry, whether the PDF stacked them one per line, wrapped one entry across
 * two lines (COMET does this for substitutions), or ran two across a line.
 * All whitespace inside a chunk is collapsed to single spaces.
 *
 * @return list<string>
 */
function comet_report_split_on_minute(string $block): array
{
    $block = trim($block);
    if ($block === '') {
        return [];
    }

    $parts = preg_split('/(?=(?<![\d+])\d{1,3}(?:\+\d{1,2})?[ \t]*[’\'])/u', $block);
    if ($parts === false) {
        return [];
    }

    $chunks = [];
    foreach ($parts as $part) {
        $part = trim((string) preg_replace('/\s+/u', ' ', (string) $part));
        if ($part !== '' && preg_match('/^\d{1,3}(?:\+\d{1,2})?[ \t]*[’\']/u', $part) === 1) {
            $chunks[] = $part;
        }
    }

    return $chunks;
}

/**
 * Slice the substring that starts after the first of $startMarkers and ends
 * before the first following marker in $endMarkers. COMET normally renders
 * section headers on their own line; if a header got glued to neighbouring
 * text (some PDF text extractors do this) we fall back to a word-boundary
 * match so the section can still be found.
 */
function comet_report_section(string $text, array $startMarkers, array $endMarkers): string
{
    $find = static function (string $haystack, string $marker): ?array {
        $quoted = preg_quote($marker, '/');
        if (preg_match('/^[ \t]*' . $quoted . '[ \t]*$/mi', $haystack, $m, PREG_OFFSET_CAPTURE)) {
            return [(int) $m[0][1], strlen($m[0][0])];
        }
        if (preg_match('/(?<![A-Za-z])' . $quoted . '(?![A-Za-z])/i', $haystack, $m, PREG_OFFSET_CAPTURE)) {
            return [(int) $m[0][1], strlen($m[0][0])];
        }
        return null;
    };

    $start = null;
    foreach ($startMarkers as $marker) {
        $hit = $find($text, $marker);
        if ($hit !== null) {
            $offset = $hit[0] + $hit[1];
            if ($start === null || $offset < $start) {
                $start = $offset;
            }
        }
    }
    if ($start === null) {
        return '';
    }

    $slice = substr($text, $start);
    $end = strlen($slice);
    foreach ($endMarkers as $marker) {
        $hit = $find($slice, $marker);
        if ($hit !== null) {
            $end = min($end, $hit[0]);
        }
    }

    return trim(substr($slice, 0, $end));
}

/** @return list<string> non-empty trimmed lines */
function comet_report_lines(string $block): array
{
    $lines = [];
    foreach (explode("\n", $block) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return $lines;
}

/**
 * One COMET player entry. The real PDF glues the shirt number to the name and
 * separates the name from the person id with a TAB; the GK / captain marker
 * arrives on its own line and is joined back on by comet_report_parse() before
 * this runs. Space-separated variants are still accepted.
 *
 *   "2Ross Aitchison\t812028 SCO"            (no marker)
 *   "4Ross Grant C\t755545 SCO"              (captain, marker joined from next line)
 *   "1Thomas Barrett G\t1029227 SCO"         (goalkeeper)
 *   "3Connor Fry\tT926436 SCO"               (trialist — marker glued to the id)
 *   "5 Andrew McIntyre CP 890213 SCO"        (older / hand-pasted, spaces + GK/CP)
 *
 * Groups: 1 shirt no · 2 name · 3 marker (optional) · 4 COMET person id · 5 nationality.
 */
const COMET_PLAYER_RE =
    '/(?<!\d)(\d{1,3})[ \t]*([A-Za-z\x{00C0}-\x{024F}][^\t\n]*?)[ \t]+(?:(GK|CP|G|C|T)[ \t]*)?(\d{5,10})[ \t]+(N\/A|[A-Za-z]{2,4})(?![A-Za-z])/u';

/**
 * Find every player entry in a chunk of text, in reading order.
 *
 * @return list<array{number:int,name:string,is_gk:bool,is_captain:bool,offset:int}>
 */
function comet_report_scan_players(string $text): array
{
    if (!preg_match_all(COMET_PLAYER_RE, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $out = [];
    foreach ($matches as $m) {
        $markers = strtoupper((string) ($m[3][0] ?? ''));
        $name = trim((string) $m[2][0], " \t.-'");
        if ($name === '' || mb_strlen($name) < 2) {
            continue;
        }
        $out[] = [
            'number' => (int) $m[1][0],
            'name' => $name,
            'is_gk' => preg_match('/(?<![A-Z])G[K]?(?![A-Z])/', $markers) === 1,
            'is_captain' => preg_match('/(?<![A-Z])C[P]?(?![A-Z])/', $markers) === 1,
            'offset' => (int) $m[0][1],
        ];
    }

    return $out;
}

/**
 * De-dupe + cap a list of scanned players to a starting XI shape.
 *
 * @param list<array{number:int,name:string,is_gk:bool,is_captain:bool}> $players
 * @return list<array{number:int,name:string,is_gk:bool,is_captain:bool}>
 */
function comet_report_clean_lineup(array $players): array
{
    $seen = [];
    $out = [];
    foreach ($players as $player) {
        $key = comet_report_normalize_name((string) $player['name']);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = [
            'number' => (int) $player['number'],
            'name' => (string) $player['name'],
            'is_gk' => (bool) $player['is_gk'],
            'is_captain' => (bool) $player['is_captain'],
        ];
        if (count($out) >= 11) {
            break;
        }
    }
    return $out;
}

/**
 * Parse COMET report text into a structured, team-agnostic report.
 *
 * @return array<string,mixed>
 */
function comet_report_parse(string $text): array
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $notes = [];

    // COMET renders Material Symbols ligature names as literal words. Drop the
    // decorative ones (keep "chevron_forward" — it separates substitutions).
    $text = (string) preg_replace(
        '/(?<![A-Za-z])(calendar_month|stadium|groups|frame_person|trophy|assignment_turned_in|check_circle|schedule|sports_soccer|sports|location_on|person)/i',
        ' ',
        $text
    );
    // The GK / captain marker (G, C, GK, CP, T) sits alone on the line under the
    // player's name — pull it back up so each player is one line.
    $text = (string) preg_replace('/\n(?=(?:GK|CP|G|C|T)\t\d)/', ' ', $text);
    // Collapse the "label.someKey" i18n leftovers.
    $text = (string) preg_replace('/\blabel\.[A-Za-z.]+/', ' ', $text);

    $beforeLineups = $text;
    if (preg_match('/^[ \t]*LINEUPS[ \t]*$/mi', $text, $lm, PREG_OFFSET_CAPTURE)) {
        $beforeLineups = substr($text, 0, $lm[0][1]);
    }
    // Drop "Date/Time: 05.09.2026 14:30" so a kick-off time can't read as a score.
    $scoreHaystack = preg_replace('/Date\/Time:\s*[\d.]+\s+\d{1,2}:\d{2}/i', ' ', $beforeLineups) ?? $beforeLineups;

    // --- Team names -------------------------------------------------------
    $homeTeam = '';
    $awayTeam = '';
    $homeGoals = null;
    $awayGoals = null;

    // Preferred: the "Match report: Home - Away" line in every page header/footer.
    if (preg_match('/Match report:\s*(.+?)\s+-\s+(.+?)\s*(?:Page\s*\d|\n|$)/i', $text, $m)) {
        $homeTeam = trim($m[1]);
        $awayTeam = trim($m[2]);
    }
    // Fallback: the title line "Home 5:2 Away" near the top of page 1.
    if (($homeTeam === '' || $awayTeam === '')
        && preg_match('/(?:^|\n)[ \t]*([A-Za-z][A-Za-z0-9 .&\'\/-]{2,}?)\s+(\d{1,2})\s*:\s*(\d{1,2})\s+([A-Za-z][A-Za-z0-9 .&\'\/-]{2,}?)[ \t]*(?:\n|$)/', $scoreHaystack, $m)) {
        $homeTeam = trim($m[1]);
        $awayTeam = trim($m[4]);
        $homeGoals = (int) $m[2];
        $awayGoals = (int) $m[3];
    }

    // --- Score --------------------------------------------------------------
    if ($homeGoals !== null && $awayGoals !== null) {
        // already taken from the title line above
    } elseif ($homeTeam !== '' && $awayTeam !== ''
        && preg_match('/' . preg_quote($homeTeam, '/') . '\s+(\d{1,2})\s*:\s*(\d{1,2})\s+' . preg_quote($awayTeam, '/') . '/', $scoreHaystack, $sm)) {
        $homeGoals = (int) $sm[1];
        $awayGoals = (int) $sm[2];
    } elseif (preg_match_all('/(?<![:.\d])(\d{1,2})\s*:\s*(\d{1,2})(?![:.\d])/', $scoreHaystack, $sm, PREG_SET_ORDER)) {
        foreach ($sm as $candidate) {
            $a = (int) $candidate[1];
            $b = (int) $candidate[2];
            // Football scores: modest, and not the :00/:15/:30/:45 of a clock time.
            if ($a <= 20 && $b <= 20 && !in_array($candidate[2], ['00', '15', '30', '45'], true)) {
                $homeGoals = $a;
                $awayGoals = $b;
                break;
            }
        }
    }

    // --- Penalty shootout ---------------------------------------------------
    // COMET prints "Home 3:3 (PEN 4:2) Away" — the shootout score sits right
    // after the full-time score, in the same home:away order. It's extracted
    // independently of the score regexes above (rather than folded into
    // them) so a drawn-then-decided-on-penalties match can't have its PEN
    // score mistaken for the match score by the generic N:N scanner: the
    // full-time score always appears first in reading order, so that scanner
    // already lands on the right pair on its own, and this just captures the
    // shootout score separately.
    $homePenalties = null;
    $awayPenalties = null;
    if (preg_match('/\(\s*PEN\s+(\d{1,2})\s*:\s*(\d{1,2})\s*\)/i', $scoreHaystack, $pm)) {
        $homePenalties = (int) $pm[1];
        $awayPenalties = (int) $pm[2];
    }

    // --- Meta (competition / round / date / kickoff) -------------------------
    $competition = '';
    if (preg_match('/Match report\s*\n\s*(.+)/i', $text, $m)) {
        $competition = trim($m[1]);
    }
    $stage = '';
    if (preg_match('/\bRound:\s*([0-9A-Za-z ]+)/i', $text, $m)) {
        $stage = 'Round ' . trim($m[1]);
    }
    $matchDate = '';
    $kickoff = '';
    if (preg_match('/Date\/Time:\s*(\d{2})\.(\d{2})\.(\d{4})\s+(\d{1,2}:\d{2})/i', $text, $m)) {
        $matchDate = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
        $kickoff = $m[4];
    }

    // --- Lineups ----------------------------------------------------------
    $lineupBlock = comet_report_section(
        $text,
        ['LINEUPS'],
        ['SUBSTITUTES', 'STAFF', 'MATCH OFFICIALS', 'GOALS', 'DISCIPLINARY']
    );
    [$homeLineup, $awayLineup] = comet_report_split_lineups($lineupBlock, $homeTeam, $awayTeam, $notes);

    // --- Substitutes (union of both benches; resolve() filters to Saltcoats) --
    $subsBlock = comet_report_section($text, ['SUBSTITUTES'], ['STAFF', 'GOALS', 'DISCIPLINARY', 'SUBSTITUTIONS', 'MATCH OFFICIALS']);
    $allSubs = [];
    foreach (comet_report_scan_players($subsBlock) as $player) {
        $allSubs[] = ['number' => $player['number'], 'name' => $player['name']];
    }

    // --- Goals (split on the minute tick so column layout works too) --------
    $goalsBlock = comet_report_section(
        $text,
        ['GOALS'],
        ['PENALTY SHOOTOUT', 'DISCIPLINARY', 'SUBSTITUTIONS', 'YELLOW CARDS', 'RED CARDS', 'My Scottish Football', 'COMET -', 'Match report:', 'PLAYED', 'Printed by']
    );
    $goals = [];
    foreach (comet_report_split_on_minute($goalsBlock) as $chunk) {
        if (preg_match('/^(\d{1,3}(?:\+\d{1,2})?)\s*[’\']\s*(?:(\d{1,2})[ \t]+)?(.+)$/u', $chunk, $m) !== 1) {
            continue;
        }
        $rest = trim((string) $m[3]);
        $ownGoal = false;
        $note = '';
        if (preg_match('/\bown goal\b/i', $rest)) {
            $ownGoal = true;
            $rest = trim((string) preg_replace('/\s*\bown goal\b\s*/i', ' ', $rest));
        }
        if (preg_match('/\b(pen\.?|penalty)\b/i', $rest)) {
            $note = 'Penalty';
            $rest = trim((string) preg_replace('/\s*\(?\b(pen\.?|penalty)\b\)?\s*/i', ' ', $rest));
        }
        $rest = trim($rest, " .-");
        if ($rest === '' || preg_match('/[A-Za-z]{2}/', $rest) !== 1) {
            continue;
        }
        $goals[] = [
            'minute' => comet_report_clean_minute($m[1]),
            'number' => isset($m[2]) && $m[2] !== '' ? (int) $m[2] : null,
            'name' => $rest,
            'own_goal' => $ownGoal,
            'note' => $note,
        ];
    }

    // --- Cards --------------------------------------------------------------
    $cards = comet_report_parse_cards(
        comet_report_section($text, ['DISCIPLINARY'], ['SUBSTITUTIONS', 'My Scottish Football'])
    );

    // --- Substitutions (split on the minute tick) --------------------------
    $substitutions = [];
    $subsMadeBlock = comet_report_section(
        $text,
        ['SUBSTITUTIONS'],
        ['PLAYED', 'CONFIRMED', 'SCHEDULED', 'My Scottish Football', 'COMET -', 'Match report:', 'Printed by']
    );
    $sep = '(?:chevron_forward|chevron_right|arrow_forward|arrow_right_alt|›|»|>|→|—>|-->)';
    foreach (comet_report_split_on_minute($subsMadeBlock) as $chunk) {
        if (preg_match('/^(\d{1,3}(?:\+\d{1,2})?)\s*[’\']\s*(\d{1,2})\s*(.+?)\s*' . $sep . '\s*(\d{1,2})\s*(.+?)\s*$/u', $chunk, $m) !== 1) {
            continue;
        }
        $substitutions[] = [
            'minute' => comet_report_clean_minute($m[1]),
            'off_number' => (int) $m[2],
            'off_name' => trim($m[3]),
            'on_number' => (int) $m[4],
            'on_name' => trim($m[5]),
        ];
    }

    return [
        'raw_text' => $text,
        'home_team' => $homeTeam,
        'away_team' => $awayTeam,
        'score' => [$homeGoals, $awayGoals],
        'penalties' => ($homePenalties !== null && $awayPenalties !== null) ? [$homePenalties, $awayPenalties] : null,
        'competition' => $competition,
        'stage' => $stage,
        'match_date' => $matchDate,
        'kickoff' => $kickoff,
        'home_lineup' => $homeLineup,
        'away_lineup' => $awayLineup,
        'all_subs' => $allSubs,
        'goals' => $goals,
        'cards' => $cards,
        'substitutions' => $substitutions,
        'notes' => $notes,
    ];
}

/**
 * Split the LINEUPS block into the two teams' XIs. Handles both PDF layouts:
 *  - two columns, "home player   away player" on each line, and
 *  - stacked, home XI then away XI, separated by the away team's name.
 *
 * @return array{0:list<array>,1:list<array>}
 */
function comet_report_split_lineups(string $block, string $homeTeam, string $awayTeam, array &$notes): array
{
    $lines = comet_report_lines($block);

    // --- Two-column layout: most rows carry exactly two player entries. -----
    $twoPerLine = 0;
    $withPlayers = 0;
    foreach ($lines as $line) {
        $count = preg_match_all(COMET_PLAYER_RE, $line);
        if ($count >= 1) {
            $withPlayers++;
        }
        if ($count === 2) {
            $twoPerLine++;
        }
    }

    if ($twoPerLine >= 3 && $twoPerLine >= $withPlayers - 3) {
        $home = [];
        $away = [];
        foreach ($lines as $line) {
            $players = comet_report_scan_players($line);
            if (count($players) >= 2) {
                $home[] = $players[0];
                $away[] = $players[1];
            } elseif (count($players) === 1) {
                if (count($home) <= count($away)) {
                    $home[] = $players[0];
                } else {
                    $away[] = $players[0];
                }
            }
        }
        $notes[] = 'Line-ups read from a two-column layout (' . count($home) . ' / ' . count($away) . ').';
        return [comet_report_clean_lineup($home), comet_report_clean_lineup($away)];
    }

    // --- Stacked layout: all entries in order, split at the away team name. -
    $entries = comet_report_scan_players($block);
    if ($entries === []) {
        $notes[] = 'No player rows could be read from the line-ups section.';
        return [[], []];
    }

    $awayNorm = comet_report_normalize_name($awayTeam);
    $splitOffset = null;
    if ($awayNorm !== '') {
        foreach ($lines as $line) {
            $lineNorm = comet_report_normalize_name($line);
            if ($lineNorm !== '' && str_contains($lineNorm, $awayNorm) && comet_report_scan_players($line) === []) {
                $pos = strpos($block, $line);
                if ($pos !== false) {
                    $splitOffset = $pos;
                    break;
                }
            }
        }
    }

    $home = [];
    $away = [];
    if ($splitOffset !== null) {
        foreach ($entries as $entry) {
            if ($entry['offset'] < $splitOffset) {
                $home[] = $entry;
            } else {
                $away[] = $entry;
            }
        }
        $notes[] = 'Line-ups read stacked, split at the away team name (' . count($home) . ' / ' . count($away) . ').';
    } else {
        $home = array_slice($entries, 0, 11);
        $away = array_slice($entries, 11, 11);
        $notes[] = 'Line-ups split by position — team headings were not found (' . count($home) . ' / ' . count($away) . ').';
    }

    return [comet_report_clean_lineup($home), comet_report_clean_lineup($away)];
}

/**
 * Parse the DISCIPLINARY block. Copes with all three shapes COMET's PDF
 * produces: one line ("59' 10 Jordan Cropley B1 ..."), a bare minute on its
 * own line above "<num> <name>", and two cards across a line (column layout).
 * Yellow vs red is decided by whether the entry sits after a "RED CARDS"
 * heading. Reasons are not kept — minute, player and colour are what matter.
 *
 * @return list<array{minute:string,number:int|null,name:string,card_type:string,reason:string}>
 */
function comet_report_parse_cards(string $block): array
{
    if (trim($block) === '') {
        return [];
    }

    // Pull a lone "59'" up onto the "10Jordan Cropley" line that follows it
    // (the shirt number may be glued to the name).
    $block = (string) preg_replace(
        '/(\b\d{1,3}(?:\+\d{1,2})?[ \t]*[’\'])\s*\n[ \t]*(\d{1,2}[ \t]*[A-Za-z\x{00C0}-\x{024F}])/u',
        '$1 $2',
        $block
    );

    $redFrom = null;
    if (preg_match('/(?<![A-Za-z])(RED CARDS?|SECOND YELLOW|SIN[ -]?BIN)(?![A-Za-z])/i', $block, $m, PREG_OFFSET_CAPTURE)) {
        $redFrom = (int) $m[0][1];
    }

    $entryRe = '/(\d{1,3}(?:\+\d{1,2})?)[ \t]*[’\'][ \t]*(\d{1,2})[ \t]*([A-Z][A-Za-z\x{00C0}-\x{024F}\'.\- ]+?)'
        . '(?=\s{2,}|\s+[A-Z]\d\b|\s+\d{1,3}(?:\+\d{1,2})?[ \t]*[’\']|\t|\s*$)/mu';
    if (!preg_match_all($entryRe, $block, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        return [];
    }

    $cards = [];
    $seen = [];
    foreach ($matches as $m) {
        $name = trim((string) $m[3][0], " \t.-'");
        if (mb_strlen($name) < 3) {
            continue;
        }
        $minute = comet_report_clean_minute((string) $m[1][0]);
        $key = $minute . '|' . comet_report_normalize_name($name) . '|' . ($redFrom !== null && (int) $m[0][1] >= $redFrom ? 'red' : 'yellow');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $cards[] = [
            'minute' => $minute,
            'number' => (int) $m[2][0],
            'name' => $name,
            'card_type' => ($redFrom !== null && (int) $m[0][1] >= $redFrom) ? 'red' : 'yellow',
            'reason' => '',
        ];
    }

    return $cards;
}

/**
 * Build the Saltcoats-only import plan from a parsed report and the fixture.
 *
 * @param array<string,mixed> $parsed    comet_report_parse() output
 * @param array<string,mixed> $fixture   getMatchFixtureById() row
 * @param array<string,string> $manualMap  raw PDF name => canonical squad name (from the preview screen)
 * @return array<string,mixed>
 */
function comet_report_resolve(array $parsed, PDO $pdo, array $fixture, array $manualMap = []): array
{
    $result = [
        'ok' => false,
        'error' => null,
        'our_side' => null,
        'is_home' => null,
        'home_team' => (string) $parsed['home_team'],
        'away_team' => (string) $parsed['away_team'],
        'opponent' => '',
        'competition' => (string) $parsed['competition'],
        'stage' => (string) $parsed['stage'],
        'match_date' => (string) $parsed['match_date'],
        'score' => ['svfc' => null, 'opponent' => null],
        'penalties' => ['svfc' => null, 'opponent' => null],
        'starters' => [],
        'captain' => null,
        'captain_raw' => '',
        'substitutes' => [],
        'goals' => [],
        'cards' => [],
        'substitutions' => [],
        // Opponent side — names only (opponents are not in the players table).
        'opponent_starters' => [],
        'opponent_substitutes' => [],
        'opponent_captain_raw' => '',
        'opponent_goals' => [],
        'opponent_cards' => [],
        'opponent_substitutions' => [],
        'not_imported' => [],
        'unmatched' => [],
        'add_candidates' => [],
        'warnings' => [],
    ];

    // --- Player index from the club squad -----------------------------------
    $playerByNorm = [];
    try {
        $rows = $pdo->query("SELECT name FROM players WHERE active = 1")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable) {
        $rows = [];
    }
    foreach ($rows as $playerName) {
        $norm = comet_report_normalize_name((string) $playerName);
        if ($norm !== '') {
            $playerByNorm[$norm] = (string) $playerName;
        }
    }
    $result['squad_size'] = count($playerByNorm);

    $normManualMap = [];
    foreach ($manualMap as $raw => $canonical) {
        $canonical = trim((string) $canonical);
        if ($canonical !== '') {
            $normManualMap[comet_report_normalize_name((string) $raw)] = $canonical;
        }
    }

    $resolveName = static function (string $raw) use ($playerByNorm, $normManualMap): ?string {
        $norm = comet_report_normalize_name($raw);
        if ($norm === '') {
            return null;
        }
        if (isset($normManualMap[$norm])) {
            return $normManualMap[$norm];
        }
        if (isset($playerByNorm[$norm])) {
            return $playerByNorm[$norm];
        }
        // One squad name a single edit away (COMET name typos, e.g.
        // "McGillvray" vs "McGillivray") — only auto-match when it is unique.
        if (mb_strlen($norm) >= 6) {
            $close = [];
            foreach ($playerByNorm as $pNorm => $canonical) {
                if (abs(strlen($pNorm) - strlen($norm)) <= 1 && levenshtein($pNorm, $norm) <= 1) {
                    $close[$canonical] = true;
                }
            }
            if (count($close) === 1) {
                return array_key_first($close);
            }
        }
        return null;
    };
    $suggestFor = static function (string $raw) use ($playerByNorm): array {
        $norm = comet_report_normalize_name($raw);
        if ($norm === '') {
            return [];
        }
        $parts = explode(' ', $norm);
        $last = end($parts);
        $out = [];
        foreach ($playerByNorm as $pNorm => $canonical) {
            $pParts = explode(' ', $pNorm);
            if (end($pParts) === $last || levenshtein($pNorm, $norm) <= 2) {
                $out[$canonical] = true;
            }
        }
        return array_keys($out);
    };

    // --- Which side is Saltcoats? -----------------------------------------
    $sideForName = static function (string $team): ?string {
        $norm = comet_report_normalize_name($team);
        foreach (COMET_CLUB_NAME_HINTS as $hint) {
            if ($norm !== '' && str_contains($norm, comet_report_normalize_name($hint))) {
                return 'match';
            }
        }
        return null;
    };
    $homeIsUs = $sideForName((string) $parsed['home_team']) !== null;
    $awayIsUs = $sideForName((string) $parsed['away_team']) !== null;

    if ($homeIsUs === $awayIsUs) {
        $result['error'] = 'Could not tell which team in this report is Saltcoats Victoria. '
            . 'Teams read as "' . ($parsed['home_team'] ?: '?') . '" and "' . ($parsed['away_team'] ?: '?') . '".';
        return $result;
    }

    $ourSide = $homeIsUs ? 'home' : 'away';
    $result['our_side'] = $ourSide;
    $result['is_home'] = $homeIsUs;
    $result['opponent'] = $homeIsUs ? (string) $parsed['away_team'] : (string) $parsed['home_team'];

    $ourLineup = $homeIsUs ? $parsed['home_lineup'] : $parsed['away_lineup'];
    $oppLineup = $homeIsUs ? $parsed['away_lineup'] : $parsed['home_lineup'];

    // Every opponent name we can see: their XI plus their bench. Any report
    // name that isn't one of ours is treated as the opponent's (this is a
    // two-team match report) — the one exception is a Saltcoats player not yet
    // added to the site, detected below by pairing in a substitution.
    $oppNames = [];
    foreach ($oppLineup as $row) {
        $n = comet_report_normalize_name((string) $row['name']);
        if ($n !== '') {
            $oppNames[$n] = true;
        }
    }
    foreach ($parsed['all_subs'] as $row) {
        if ($resolveName((string) $row['name']) !== null) {
            continue; // resolves to our squad -> our bench, not the opponent's
        }
        $n = comet_report_normalize_name((string) $row['name']);
        if ($n !== '') {
            $oppNames[$n] = true;
        }
    }

    // Names paired with a known Saltcoats player in a substitution but with no
    // close squad match — very likely a team-mate missing from the site.
    $missingSvfcNames = [];
    foreach ($parsed['substitutions'] as $change) {
        $offName = (string) $change['off_name'];
        $onName = (string) $change['on_name'];
        $offSv = $resolveName($offName) !== null;
        $onSv = $resolveName($onName) !== null;
        if ($offSv === $onSv) {
            continue; // both ours or neither ours -> no signal
        }
        $other = $offSv ? $onName : $offName;
        $otherNorm = comet_report_normalize_name($other);
        if ($otherNorm !== '' && $suggestFor($other) === []) {
            $missingSvfcNames[$otherNorm] = true;
            unset($oppNames[$otherNorm]);
        }
    }

    // --- Opponent starting XI (names as printed, no squad matching) --------
    foreach (array_slice($oppLineup, 0, 11) as $slot => $row) {
        $result['opponent_starters'][] = [
            'slot' => $slot,
            'pdf_number' => $row['number'],
            'name' => (string) $row['name'],
            'is_gk' => (bool) $row['is_gk'],
            'is_captain' => (bool) $row['is_captain'],
        ];
        if (!empty($row['is_captain'])) {
            $result['opponent_captain_raw'] = (string) $row['name'];
        }
    }

    // --- Score -------------------------------------------------------------
    [$homeScore, $awayScore] = $parsed['score'];
    if ($homeScore !== null && $awayScore !== null) {
        $result['score']['svfc'] = $homeIsUs ? (int) $homeScore : (int) $awayScore;
        $result['score']['opponent'] = $homeIsUs ? (int) $awayScore : (int) $homeScore;
    } else {
        $result['warnings'][] = 'No full-time score could be read from the report.';
    }
    if ($parsed['penalties'] !== null) {
        [$homePens, $awayPens] = $parsed['penalties'];
        $result['penalties']['svfc'] = $homeIsUs ? (int) $homePens : (int) $awayPens;
        $result['penalties']['opponent'] = $homeIsUs ? (int) $awayPens : (int) $homePens;
    }

    // --- Starting XI -----------------------------------------------------------
    $squadNorm = [];   // raw/canonical norm => canonical, for cross-referencing events
    $starters = [];
    foreach (array_slice($ourLineup, 0, 11) as $slot => $row) {
        $canonical = $resolveName((string) $row['name']);
        $starters[] = [
            'slot' => $slot,
            'pdf_number' => $row['number'],
            'raw' => (string) $row['name'],
            'name' => $canonical,
            'is_gk' => (bool) $row['is_gk'],
            'is_captain' => (bool) $row['is_captain'],
            'suggestions' => $canonical === null ? $suggestFor((string) $row['name']) : [],
        ];
        if ($canonical !== null) {
            $squadNorm[comet_report_normalize_name((string) $row['name'])] = $canonical;
            $squadNorm[comet_report_normalize_name($canonical)] = $canonical;
        } else {
            $result['unmatched'][(string) $row['name']] = true;
        }
        if ($row['is_captain']) {
            $result['captain_raw'] = (string) $row['name'];
            $result['captain'] = $canonical;
        }
    }
    $result['starters'] = $starters;
    if (count($starters) !== 11) {
        $result['warnings'][] = 'Read ' . count($starters) . ' Saltcoats starters from the report (expected 11).';
    }
    if ($result['captain'] === null) {
        $result['warnings'][] = $result['captain_raw'] === ''
            ? 'No captain (CP) was marked in the report.'
            : 'The captain "' . $result['captain_raw'] . '" is not in your squad list.';
    }

    // --- Substitutes (keep only names that resolve to the club squad) ------
    $subs = [];
    foreach ($parsed['all_subs'] as $row) {
        $canonical = $resolveName((string) $row['name']);
        if ($canonical === null) {
            continue; // opponent bench (handled below) or a missing team-mate
        }
        if (in_array($canonical, array_column($subs, 'name'), true)) {
            continue;
        }
        $subs[] = ['pdf_number' => $row['number'], 'raw' => (string) $row['name'], 'name' => $canonical];
        $squadNorm[comet_report_normalize_name((string) $row['name'])] = $canonical;
        $squadNorm[comet_report_normalize_name($canonical)] = $canonical;
    }
    $result['substitutes'] = $subs;

    // Opponent bench = report substitutes that don't resolve to the club squad
    // and aren't a likely-missing team-mate.
    $oppSubSeen = [];
    $missingBench = 0;
    foreach ($parsed['all_subs'] as $row) {
        if ($resolveName((string) $row['name']) !== null) {
            continue;
        }
        $key = comet_report_normalize_name((string) $row['name']);
        if ($key === '' || isset($oppSubSeen[$key])) {
            continue;
        }
        $oppSubSeen[$key] = true;
        if (isset($missingSvfcNames[$key])) {
            $missingBench++;
            continue;
        }
        $result['opponent_substitutes'][] = ['pdf_number' => $row['number'], 'name' => (string) $row['name']];
    }
    if ($missingBench > 0) {
        $result['not_imported'][] = $missingBench . ' substitute name'
            . ($missingBench === 1 ? '' : 's')
            . ' could not be matched to your squad (add them, then re-import).';
    }

    $classify = static function (string $raw) use ($squadNorm, $resolveName, $missingSvfcNames): array {
        $norm = comet_report_normalize_name($raw);
        if (isset($squadNorm[$norm])) {
            return ['svfc', $squadNorm[$norm]];
        }
        $canonical = $resolveName($raw);
        if ($canonical !== null) {
            return ['svfc', $canonical];
        }
        if (isset($missingSvfcNames[$norm])) {
            return ['unknown', null]; // probably one of ours, not yet on the site
        }
        return ['opponent', null]; // two-team report: anything else is the opponent
    };

    // --- Goals (Saltcoats goals + all own goals) --------------------------
    // An own goal is stored as a goal for the team it counted FOR, flagged
    // own_goal, with `player` = the player who put it into their own net.
    foreach ($parsed['goals'] as $goal) {
        $label = ($goal['minute'] !== '' ? $goal['minute'] . "' " : '') . $goal['name'];
        [$side, $canonical] = $classify((string) $goal['name']);

        if (!empty($goal['own_goal'])) {
            if ($side === 'svfc' && $canonical !== null) {
                $result['goals'][] = [
                    'minute' => $goal['minute'], 'name' => $canonical,
                    'team' => 'opponent', 'own_goal' => true, 'note' => (string) $goal['note'],
                ];
            } elseif ($side === 'opponent') {
                $result['goals'][] = [
                    'minute' => $goal['minute'], 'name' => (string) $goal['name'],
                    'team' => 'svfc', 'own_goal' => true, 'note' => (string) $goal['note'],
                ];
            } else {
                $result['not_imported'][] = 'Own goal — ' . $label . ' (could not tell which side it counts for)';
            }
            continue;
        }

        if ($side === 'svfc' && $canonical !== null) {
            $result['goals'][] = [
                'minute' => $goal['minute'], 'name' => $canonical,
                'team' => 'svfc', 'own_goal' => false, 'note' => (string) $goal['note'],
            ];
        } elseif ($side === 'opponent') {
            $result['opponent_goals'][] = [
                'minute' => $goal['minute'], 'name' => (string) $goal['name'],
                'own_goal' => false, 'note' => (string) $goal['note'],
            ];
        } else {
            $result['not_imported'][] = 'Goal — ' . $label . ' (scorer not matched to your squad)';
        }
    }

    // --- Cards (Saltcoats only) -------------------------------------------
    foreach ($parsed['cards'] as $card) {
        $label = ($card['minute'] !== '' ? $card['minute'] . "' " : '') . $card['name']
            . ' (' . $card['card_type'] . ')';
        [$side, $canonical] = $classify((string) $card['name']);
        if ($side === 'svfc' && $canonical !== null) {
            $result['cards'][] = [
                'minute' => $card['minute'],
                'name' => $canonical,
                'card_type' => $card['card_type'] === 'red' ? 'red' : 'yellow',
                'reason' => (string) $card['reason'],
            ];
        } elseif ($side === 'opponent') {
            $result['opponent_cards'][] = [
                'minute' => $card['minute'],
                'name' => (string) $card['name'],
                'card_type' => $card['card_type'] === 'red' ? 'red' : 'yellow',
                'reason' => (string) $card['reason'],
            ];
        } else {
            $result['not_imported'][] = 'Card — ' . $label . ' (player not matched to your squad)';
        }
    }

    // Names the report ties to Saltcoats but that aren't on the squad list yet —
    // offered on the preview as "add this player" so the user need not leave.
    $addCandidates = [];
    $addCandidate = static function (string $raw) use (&$addCandidates): void {
        $raw = trim($raw);
        $key = comet_report_normalize_name($raw);
        if ($raw !== '' && $key !== '' && !isset($addCandidates[$key])) {
            $addCandidates[$key] = $raw;
        }
    };
    foreach ($result['starters'] as $starter) {
        // Only when there's no close existing name — otherwise it's a spelling
        // difference and the "map to a squad player" dropdown is the fix.
        if ($starter['name'] === null && $starter['suggestions'] === []) {
            $addCandidate((string) $starter['raw']);
        }
    }

    // --- Substitutions (Saltcoats only, both players must resolve) --------
    foreach ($parsed['substitutions'] as $change) {
        $label = ($change['minute'] !== '' ? $change['minute'] . "' " : '')
            . $change['off_name'] . ' → ' . $change['on_name'];
        [$offSide, $off] = $classify((string) $change['off_name']);
        [$onSide, $on] = $classify((string) $change['on_name']);
        if ($offSide === 'svfc' && $onSide === 'svfc' && $off !== null && $on !== null) {
            $result['substitutions'][] = ['minute' => $change['minute'], 'off' => $off, 'on' => $on];
        } elseif ($offSide === 'opponent' || $onSide === 'opponent') {
            $result['opponent_substitutions'][] = [
                'minute' => $change['minute'],
                'off' => (string) $change['off_name'],
                'on' => (string) $change['on_name'],
            ];
        } else {
            // One partner is a known Saltcoats player -> the other is very likely
            // a Saltcoats player who has not been added to the site yet (unless a
            // close existing name suggests it's just a spelling difference).
            $missing = ($offSide === 'svfc' && $onSide === 'unknown') ? (string) $change['on_name']
                : (($onSide === 'svfc' && $offSide === 'unknown') ? (string) $change['off_name'] : '');
            if ($missing !== '' && $suggestFor($missing) === []) {
                $addCandidate($missing);
            }
            $result['not_imported'][] = 'Substitution — ' . $label . ' (players not matched to your squad)';
        }
    }
    $result['add_candidates'] = array_values($addCandidates);

    // --- Cross-checks against the saved fixture --------------------------------
    if (isset($fixture['is_home']) && (int) $fixture['is_home'] !== ($homeIsUs ? 1 : 0)) {
        $result['warnings'][] = 'The report has Saltcoats ' . ($homeIsUs ? 'at home' : 'away')
            . ' but this fixture is saved as ' . ((int) $fixture['is_home'] === 1 ? 'home' : 'away') . '.';
    }
    if ($result['score']['svfc'] !== null
        && $fixture['full_time_home_score'] !== null && $fixture['full_time_away_score'] !== null) {
        $savedUs = (int) $fixture['is_home'] === 1 ? (int) $fixture['full_time_home_score'] : (int) $fixture['full_time_away_score'];
        $savedThem = (int) $fixture['is_home'] === 1 ? (int) $fixture['full_time_away_score'] : (int) $fixture['full_time_home_score'];
        if ($savedUs !== $result['score']['svfc'] || $savedThem !== $result['score']['opponent']) {
            $result['warnings'][] = 'This fixture already has a full-time score of ' . $savedUs . '–' . $savedThem
                . '; the report says ' . $result['score']['svfc'] . '–' . $result['score']['opponent'] . '.';
        }
    }

    $result['unmatched'] = array_keys($result['unmatched']);
    $result['ok'] = true;
    return $result;
}
