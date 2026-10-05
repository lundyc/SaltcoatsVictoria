<?php

declare(strict_types=1);

/**
 * Shared WOSFL standings parsing + badge resolution, used by both the live
 * scraper (wosfl-table.php, fed a curl response) and the manual paste tool
 * (league_table_manual_update.php, fed HTML a staff member copied from their
 * own browser when the live scrape is blocked). Keeping this in one place
 * means both paths stay in sync automatically.
 */

function wosfl_normalize_club_name(string $clubName): string
{
    $clubName = strtolower(trim($clubName));
    $clubName = preg_replace('/\s+/', ' ', $clubName) ?? $clubName;

    return trim($clubName);
}

/**
 * @return array<string, string> normalized club name => badge path override
 */
function wosfl_load_badge_overrides(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $overrides = [];
    foreach ($decoded as $clubName => $badgePath) {
        if (!is_string($clubName) || !is_string($badgePath)) {
            continue;
        }

        $normalizedClub = wosfl_normalize_club_name($clubName);
        if ($normalizedClub === '') {
            continue;
        }

        $overrides[$normalizedClub] = trim($badgePath);
    }

    return $overrides;
}

/**
 * Resolve a local badge path for a club, preferring an explicit override,
 * then an already-downloaded file, then attempting to download $logoUrl.
 *
 * @param array<string, string> $badgeOverrides
 * @return array{0: string, 1: string} [relative path (e.g. "badges/foo.png"), absolute path]
 */
function wosfl_resolve_badge(string $clubName, string $logoUrl, string $badgeDir, array $badgeOverrides): array
{
    $slugSource = strtolower($clubName);
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slugSource), '-');
    $defaultRelative = 'badges/' . $slug . '.png';
    $defaultPath = $badgeDir . '/' . $slug . '.png';

    $overrideKey = wosfl_normalize_club_name($clubName);
    $overrideRelative = null;

    if ($overrideKey !== '' && isset($badgeOverrides[$overrideKey])) {
        $overrideCandidate = trim((string) $badgeOverrides[$overrideKey]);
        if ($overrideCandidate !== '') {
            $overrideCandidate = str_replace('\\', '/', $overrideCandidate);
            $overrideCandidate = ltrim($overrideCandidate, '/');
            $overrideCandidate = preg_replace('#^\.\/#', '', $overrideCandidate) ?? $overrideCandidate;

            if (str_starts_with($overrideCandidate, 'badges/')) {
                $overrideRelative = $overrideCandidate;
            } else {
                $overrideRelative = 'badges/' . basename($overrideCandidate);
            }
        }
    }

    $candidates = [];
    if ($overrideRelative !== null) {
        $candidates[] = $overrideRelative;
    }
    $candidates[] = $defaultRelative;

    foreach ($candidates as $relativePath) {
        $absolutePath = dirname($badgeDir) . '/' . $relativePath;
        if (is_file($absolutePath)) {
            return [$relativePath, $absolutePath];
        }
    }

    if ($logoUrl !== '' && !file_exists($defaultPath)) {
        $img = @file_get_contents($logoUrl);
        if ($img) {
            file_put_contents($defaultPath, $img);
        }
    }

    if (is_file($defaultPath)) {
        return [$defaultRelative, $defaultPath];
    }

    if ($overrideRelative !== null) {
        return [$overrideRelative, dirname($badgeDir) . '/' . $overrideRelative];
    }

    return [$defaultRelative, $defaultPath];
}

/**
 * Parse a WOSFL standings page (or just the standings <table> itself) into
 * the site's team-row shape. Tolerates being handed a full page — it scans
 * every <table> in the document and, if more than one has standings-shaped
 * rows (exactly 10 <td>s), keeps the one with the most rows.
 *
 * @param array<string, string> $badgeOverrides
 * @return array<int, array<string, string>>
 */
function wosfl_parse_standings_html(string $html, string $badgeDir, array $badgeOverrides): array
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $tables = $xpath->query('//table');

    $bestTeams = [];

    foreach ($tables as $table) {
        $rows = $xpath->query('.//tr', $table);
        $teams = [];

        foreach ($rows as $row) {
            $cols = $row->getElementsByTagName('td');
            if ($cols->length < 10) {
                continue;
            }

            $clubLink = $cols->item(1)->getElementsByTagName('a')->item(0);
            $clubName = trim(($clubLink ?? $cols->item(1))->textContent ?? '');
            if ($clubName === '') {
                continue;
            }
            $logoTag = $clubLink ? $clubLink->getElementsByTagName('img')->item(0) : null;
            $logoUrl = $logoTag ? trim($logoTag->getAttribute('src')) : '';
            [$localLogo] = wosfl_resolve_badge($clubName, $logoUrl, $badgeDir, $badgeOverrides);

            $teams[] = [
                'pos' => trim($cols->item(0)->textContent),
                'club' => $clubName,
                'p' => trim($cols->item(2)->textContent),
                'w' => trim($cols->item(3)->textContent),
                'd' => trim($cols->item(4)->textContent),
                'l' => trim($cols->item(5)->textContent),
                'f' => trim($cols->item(6)->textContent),
                'a' => trim($cols->item(7)->textContent),
                'gd' => trim($cols->item(8)->textContent),
                'pts' => trim($cols->item(9)->textContent),
                'logo' => $localLogo,
            ];
        }

        if (count($teams) > count($bestTeams)) {
            $bestTeams = $teams;
        }
    }

    return $bestTeams;
}
