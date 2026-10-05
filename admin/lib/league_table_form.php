<?php

declare(strict_types=1);

/**
 * Convert a club name into the stable key used to join standings and results.
 */
function league_table_form_team_key(string $clubName): string
{
    $clubName = html_entity_decode($clubName, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $clubName = strtolower(trim($clubName));
    $clubName = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $clubName) ?? $clubName;

    return trim((string) preg_replace('/\s+/', ' ', $clubName));
}

/**
 * Read the authoritative "View All Results" URL embedded in the standings page.
 */
function league_table_form_results_url(string $standingsHtml): string
{
    if (preg_match(
        '#href=["\']([^"\']*/matchHub/[^"\']+/2/true\.html)["\']#i',
        $standingsHtml,
        $match
    ) !== 1) {
        return '';
    }

    $path = html_entity_decode((string) $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    return 'https://www.wosfl.co.uk/' . ltrim($path, '/');
}

/**
 * Extract raw per-team match rows from a single results page, unaggregated.
 *
 * @return array<int, array{played_at: int, match_order: int, team_key: string, result: string}>
 */
function league_table_form_extract_rows(string $resultsHtml): array
{
    if (trim($resultsHtml) === '') {
        return [];
    }

    libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadHTML($resultsHtml);
    libxml_clear_errors();
    if (!$loaded) {
        return [];
    }

    $xpath = new DOMXPath($document);
    $rows = $xpath->query('//tr[@data-match-href]');
    if ($rows === false) {
        return [];
    }

    $entries = [];

    foreach ($rows as $row) {
        $cells = $xpath->query('./td', $row);
        if ($cells === false || $cells->length < 4) {
            continue;
        }

        $homeTeam = trim((string) preg_replace('/\s+/', ' ', (string) $cells->item(1)?->textContent));
        $awayTeam = trim((string) preg_replace('/\s+/', ' ', (string) $cells->item(3)?->textContent));
        $scoreText = trim((string) preg_replace('/\s+/', ' ', (string) $cells->item(2)?->textContent));
        if ($homeTeam === '' || $awayTeam === '' || preg_match('/(\d+)\s*-\s*(\d+)/', $scoreText, $score) !== 1) {
            continue;
        }

        $homeScore = (int) $score[1];
        $awayScore = (int) $score[2];
        $homeResult = $homeScore > $awayScore ? 'W' : ($homeScore < $awayScore ? 'L' : 'D');
        $awayResult = $homeScore < $awayScore ? 'W' : ($homeScore > $awayScore ? 'L' : 'D');

        $dateText = trim((string) preg_replace('/\s+/', ' ', (string) $cells->item(0)?->textContent));
        $playedAt = 0;
        if (preg_match('/(\d{2}\/\d{2}\/\d{2})(?:\s+(\d{2}:\d{2}))?/', $dateText, $dateMatch) === 1) {
            $dateTime = DateTimeImmutable::createFromFormat(
                'd/m/y H:i',
                $dateMatch[1] . ' ' . ($dateMatch[2] ?? '00:00'),
                new DateTimeZone('Europe/London')
            );
            $playedAt = $dateTime instanceof DateTimeImmutable ? $dateTime->getTimestamp() : 0;
        }

        $matchHref = $row instanceof DOMElement ? $row->getAttribute('data-match-href') : '';
        $matchOrder = preg_match('/(\d+)\.html$/', $matchHref, $idMatch) === 1 ? (int) $idMatch[1] : 0;

        foreach ([[$homeTeam, $homeResult], [$awayTeam, $awayResult]] as [$clubName, $result]) {
            $teamKey = league_table_form_team_key($clubName);
            if ($teamKey === '') {
                continue;
            }

            $entries[] = [
                'played_at' => $playedAt,
                'match_order' => $matchOrder,
                'team_key' => $teamKey,
                'result' => $result,
            ];
        }
    }

    return $entries;
}

/**
 * Reduce raw match rows (possibly from multiple results pages) into five-match
 * form arrays per team, ordered oldest to newest.
 *
 * @param array<int, array{played_at: int, match_order: int, team_key: string, result: string}> $entries
 * @return array<string, array<int, string>>
 */
function league_table_form_aggregate_rows(array $entries): array
{
    $formByTeam = [];
    foreach ($entries as $entry) {
        $formByTeam[$entry['team_key']][] = $entry;
    }

    $recentForm = [];
    foreach ($formByTeam as $teamKey => $results) {
        usort($results, static function (array $left, array $right): int {
            return [$right['played_at'], $right['match_order']] <=> [$left['played_at'], $left['match_order']];
        });

        $latestFive = array_slice($results, 0, 5);
        $latestFive = array_reverse($latestFive);
        $recentForm[$teamKey] = array_values(array_map(
            static fn (array $result): string => (string) $result['result'],
            $latestFive
        ));
    }

    return $recentForm;
}

/**
 * Parse played fixtures into five-match form arrays, ordered oldest to newest.
 *
 * @return array<string, array<int, string>>
 */
function league_table_form_parse_results(string $resultsHtml): array
{
    return league_table_form_aggregate_rows(league_table_form_extract_rows($resultsHtml));
}

/**
 * Read page-number => absolute URL from a results page's pagination controls.
 *
 * @return array<int, string>
 */
function league_table_form_pagination_urls(string $resultsHtml): array
{
    if (trim($resultsHtml) === '') {
        return [];
    }

    libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadHTML($resultsHtml);
    libxml_clear_errors();
    if (!$loaded) {
        return [];
    }

    $xpath = new DOMXPath($document);
    $links = $xpath->query('//ul[contains(concat(" ", normalize-space(@class), " "), " pagination ")]//a[@href]');
    if ($links === false) {
        return [];
    }

    $pages = [];
    foreach ($links as $link) {
        $label = trim((string) $link->textContent);
        if ($label === '' || !ctype_digit($label)) {
            continue;
        }

        $href = html_entity_decode((string) ($link instanceof DOMElement ? $link->getAttribute('href') : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($href === '') {
            continue;
        }

        $pages[(int) $label] = preg_match('#^https?://#i', $href) === 1
            ? $href
            : 'https://www.wosfl.co.uk/' . ltrim($href, '/');
    }

    return $pages;
}

/**
 * Fetch a single results page using the session established for standings,
 * retrying on the API's transient "processing" status and falling back to a
 * shell curl call if the PHP cURL client gets stuck.
 */
function league_table_form_fetch_page(string $pageUrl, string $referer, string $cookieFile): ?string
{
    $html = false;
    $status = 0;

    for ($attempt = 0; $attempt < 3; $attempt++) {
        $curl = curl_init($pageUrl);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
            CURLOPT_REFERER => $referer,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_COOKIEJAR => $cookieFile,
        ]);
        $html = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (is_string($html) && $html !== '' && $status === 200) {
            break;
        }

        if ($status !== 202) {
            break;
        }

        usleep(200000);
    }

    if (!is_string($html) || $html === '' || $status !== 200) {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0.0.0 Safari/537.36';
        $warmSessionCommand = 'curl -sSL'
            . ' -A ' . escapeshellarg($userAgent)
            . ' -b ' . escapeshellarg($cookieFile)
            . ' -c ' . escapeshellarg($cookieFile)
            . ' -o /dev/null'
            . ' ' . escapeshellarg($referer);
        $resultsCommand = 'curl -sSL'
            . ' -A ' . escapeshellarg($userAgent)
            . ' -e ' . escapeshellarg($referer)
            . ' -b ' . escapeshellarg($cookieFile)
            . ' -c ' . escapeshellarg($cookieFile)
            . ' ' . escapeshellarg($pageUrl);
        $warmOutput = [];
        $warmExitCode = 0;
        exec($warmSessionCommand, $warmOutput, $warmExitCode);

        $outputLines = [];
        $exitCode = $warmExitCode;
        if ($warmExitCode === 0) {
            exec($resultsCommand, $outputLines, $exitCode);
        }
        if ($exitCode === 0 && $outputLines !== []) {
            $html = implode("\n", $outputLines);
            $status = 200;
        }
    }

    if (!is_string($html) || $html === '' || $status !== 200) {
        return null;
    }

    return $html;
}

/**
 * Fetch and parse the results feed using the session established for standings.
 *
 * The feed is paginated oldest-match-first, so the page addressed by the
 * standings page's "View All Results" link only ever holds the earliest
 * results of the season. Recent form needs the newest matches, so this also
 * follows the feed's pagination to the last couple of pages and merges them
 * in before computing each team's most recent five results.
 *
 * @return array<string, array<int, string>>|null
 */
function league_table_form_fetch(string $standingsHtml, string $cookieFile): ?array
{
    $resultsUrl = league_table_form_results_url($standingsHtml);
    if ($resultsUrl === '') {
        return null;
    }

    $referer = 'https://www.wosfl.co.uk/';
    if (preg_match('#/matchHub/\d+/(1_\d+)/#', $resultsUrl, $fixtureGroupMatch) === 1) {
        $referer = 'https://www.wosfl.co.uk/fg/' . $fixtureGroupMatch[1] . '.html';
    }

    $firstPageHtml = league_table_form_fetch_page($resultsUrl, $referer, $cookieFile);
    if ($firstPageHtml === null) {
        return null;
    }

    $entries = league_table_form_extract_rows($firstPageHtml);

    $paginationUrls = league_table_form_pagination_urls($firstPageHtml);
    $lastPageNumber = $paginationUrls === [] ? 1 : max(array_keys($paginationUrls));

    if ($lastPageNumber > 1) {
        $trailingPages = [$lastPageNumber];
        if ($lastPageNumber - 1 > 1) {
            $trailingPages[] = $lastPageNumber - 1;
        }

        foreach ($trailingPages as $pageNumber) {
            if (!isset($paginationUrls[$pageNumber])) {
                continue;
            }

            $pageHtml = league_table_form_fetch_page($paginationUrls[$pageNumber], $referer, $cookieFile);
            if ($pageHtml !== null) {
                $entries = array_merge($entries, league_table_form_extract_rows($pageHtml));
            }
        }
    }

    return league_table_form_aggregate_rows($entries);
}

/**
 * Attach live form, falling back to cached form only when the results feed fails.
 *
 * @param array<int, array<string, mixed>> $teams
 * @param array<string, array<int, string>>|null $liveForm
 * @param array<int, array<string, mixed>> $cachedTeams
 * @return array<int, array<string, mixed>>
 */
function league_table_form_attach(array $teams, ?array $liveForm, array $cachedTeams): array
{
    $cachedForm = [];
    foreach ($cachedTeams as $cachedTeam) {
        $teamKey = league_table_form_team_key((string) ($cachedTeam['club'] ?? ''));
        $form = $cachedTeam['form'] ?? [];
        if ($teamKey !== '' && is_array($form)) {
            $cachedForm[$teamKey] = array_values(array_filter(
                array_map(static fn ($result): string => strtoupper((string) $result), $form),
                static fn (string $result): bool => in_array($result, ['W', 'D', 'L'], true)
            ));
        }
    }

    foreach ($teams as &$team) {
        $teamKey = league_table_form_team_key((string) ($team['club'] ?? ''));
        $team['form'] = $liveForm !== null
            ? array_slice($liveForm[$teamKey] ?? [], -5)
            : array_slice($cachedForm[$teamKey] ?? [], -5);
    }
    unset($team);

    return $teams;
}

/**
 * Render up to five accessible form markers, with the newest result on the right.
 *
 * @param array<int, mixed> $form
 */
function league_table_form_html(array $form): string
{
    $results = array_values(array_filter(
        array_map(static fn ($result): string => strtoupper((string) $result), $form),
        static fn (string $result): bool => in_array($result, ['W', 'D', 'L'], true)
    ));
    $results = array_slice($results, -5);

    $labels = ['W' => 'Win', 'D' => 'Draw', 'L' => 'Loss'];
    $classes = ['W' => 'is-win', 'D' => 'is-draw', 'L' => 'is-loss'];
    $markers = '';
    $accessibleResults = [];

    foreach ($results as $result) {
        $label = $labels[$result];
        $accessibleResults[] = $label;
        $markers .= '<span class="form-result ' . $classes[$result] . '" title="' . $label . '" aria-hidden="true">'
            . $result
            . '</span>';
    }

    $accessibleLabel = $accessibleResults === []
        ? 'No recent results'
        : 'Recent form, oldest to newest: ' . implode(', ', $accessibleResults);

    return '<div class="form-strip" role="img" aria-label="'
        . htmlspecialchars($accessibleLabel, ENT_QUOTES, 'UTF-8')
        . '">' . $markers . '</div>';
}
