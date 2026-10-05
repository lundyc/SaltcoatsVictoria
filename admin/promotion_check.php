<pre>
<?php
// PHASE3B_GUARD_MARKER
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Access denied.');
}
/**
 * West of Scotland Football League promotion checker
 *
 * This script downloads the current league table from the WoSFL website
 * and determines which clubs are already mathematically promoted.  In the
 * 2025‑26 season the top nine clubs of the Premier Division are due to
 * move up into the new Lowland League West.  A club is considered
 * mathematically promoted if, even in the worst case where they lose all
 * remaining matches and every other side wins as many as possible, there
 * are fewer than nine clubs that can finish ahead of them.  Ties on
 * points are treated pessimistically (a tie counts as the other team
 * finishing ahead).
 *
 * Usage: php promotion_check.php [url] [promotionSpots] [totalGames]
 *
 *  - url:            Optional.  The standings URL to parse.  Defaults
 *                    to the Premier Division standings for date
 *                    "847802708/2/‑1/‑1".
 *  - promotionSpots: Optional.  Number of promotion places available.
 *                    Defaults to 9.
 *  - totalGames:     Optional.  Total games per team this season.
 *                    If not provided, auto-calculated as 2 * (teams - 1).
 *                    Use this when a team has folded (e.g., 28 games).
 */

// Turn on strict error reporting for easier debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Parse command line arguments
$defaultUrl     = 'https://www.wosfl.co.uk/standingsForDate/847802708/2/-1/-1.html';
$defaultSpots   = 9;
$defaultGames   = null; // Auto-calculate based on team count

$url    = $argv[1] ?? $defaultUrl;
$spots  = isset($argv[2]) && is_numeric($argv[2]) ? (int) $argv[2] : $defaultSpots;
$totalGamesArg = isset($argv[3]) && is_numeric($argv[3]) ? (int) $argv[3] : $defaultGames;

if ($spots < 1) {
    fwrite(STDERR, "Error: Number of promotion spots must be at least 1.\n");
    exit(1);
}

if ($totalGamesArg !== null && $totalGamesArg < 1) {
    fwrite(STDERR, "Error: Total games per team must be at least 1.\n");
    exit(1);
}

/**
 * Fetch the contents of a remote URL.  cURL is used if available
 * because the target site may reject plain file_get_contents() requests
 * without a user agent.  If cURL is not available then
 * file_get_contents() is attempted as a fallback.
 *
 * @param string $url URL to fetch
 * @return string|false The raw HTML on success, or false on failure
 */
function fetchUrl(string $url)
{
    // Prefer cURL when available
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            // Emulate a web browser
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; PromotionChecker/1.0)',
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $result = curl_exec($ch);
        curl_close($ch);
        return $result;
    }
    // Fallback to file_get_contents
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0 (compatible; PromotionChecker/1.0)\r\n"
        ]
    ]);
    return @file_get_contents($url, false, $context);
}

// Download the standings page
$html = fetchUrl($url);
if ($html === false) {
    fwrite(STDERR, "Failed to download standings from: $url\n");
    exit(1);
}

// Parse the HTML into a DOMDocument
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$loaded = $dom->loadHTML($html);
libxml_clear_errors();
if (!$loaded) {
    fwrite(STDERR, "Failed to parse HTML from: $url\n");
    exit(1);
}

// Use XPath to extract rows from the first table body on the page.
// The standings table structure: position, team, P, W, D, L, F, A, +-, PTS
// Each team row contains at least 10 <td> elements.
$xpath = new DOMXPath($dom);
$rows  = $xpath->query('//table/tbody/tr');
if ($rows === false || $rows->length === 0) {
    fwrite(STDERR, "Could not locate standings table rows.\n");
    exit(1);
}

// Collect team data with robust parsing
$teams = [];
foreach ($rows as $row) {
    /** @var \DOMElement $row */
    $cells = $row->getElementsByTagName('td');
    
    // Skip rows with insufficient columns
    if ($cells->length < 10) {
        continue;
    }
    
    // Extract and clean each field
    $positionRaw = trim($cells->item(0)->textContent);
    $teamRaw     = trim($cells->item(1)->textContent);
    $playedRaw   = trim($cells->item(2)->textContent);
    $pointsRaw   = trim($cells->item($cells->length - 1)->textContent);
    
    // Skip header rows - position should be numeric
    if (!is_numeric($positionRaw)) {
        continue;
    }
    
    $position = (int) $positionRaw;
    $played   = (int) $playedRaw;
    $points   = (int) $pointsRaw;
    
    // Skip invalid rows: position must be > 0, played must be > 0, points must be >= 0
    if ($position < 1 || $played < 0 || $points < 0) {
        continue;
    }
    
    // Clean team name - remove extra whitespace
    $teamName = preg_replace('/\s+/', ' ', $teamRaw);
    $teamName = trim($teamName);
    
    // Skip empty team names
    if (empty($teamName)) {
        continue;
    }
    
    $teams[] = [
        'rank'       => $position,
        'team'       => $teamName,
        'played'     => $played,
        'points'     => $points,
        'max_points' => 0, // placeholder to fill later
    ];
}

// Ensure we have at least one team
if (empty($teams)) {
    fwrite(STDERR, "No team data extracted from the standings page.\n");
    exit(1);
}

// Sort teams by position ASC (1 → 16) to ensure correct ordering
usort($teams, function ($a, $b) {
    return $a['rank'] <=> $b['rank'];
});

// Separate active teams (with games played) from folded teams (0 games played)
// Folded teams still count toward total team count for schedule calculation
$activeTeams = array_filter($teams, function($t) {
    return $t['played'] > 0;
});
$foldedTeams = array_filter($teams, function($t) {
    return $t['played'] === 0;
});

// Re-index active teams for promotion calculations
$activeTeams = array_values($activeTeams);

// Count total teams including folded ones (needed for schedule calculation)
$totalTeamCount = count($teams);
$activeTeamCount = count($activeTeams);
$foldedTeamCount = count($foldedTeams);

// Compute total number of teams and total matches per season.
// Use active team count to calculate the schedule - if a team has folded,
// the season has fewer games (e.g., 15 active teams = 28 games, not 30).
$teamCount  = $totalTeamCount;
// In a double round‑robin league, each club plays (n ‑ 1) opponents twice.
// If a team has folded, the season has fewer games than normal.
// Use the provided totalGamesArg or calculate based on ACTIVE teams.
$totalGames = $totalGamesArg ?? (($activeTeamCount > 1) ? 2 * ($activeTeamCount - 1) : 0);

// Compute max points for each active team: current points + (games left * 3)
// Folded teams have 0 remaining games and 0 max points
foreach ($activeTeams as &$t) {
    $played = $t['played'];
    $remaining = $totalGames - $played;
    if ($remaining < 0) {
        // If played > totalGames then assume no remaining games
        $remaining = 0;
    }
    $t['max_points'] = $t['points'] + ($remaining * 3);
}
unset($t);

// Determine which teams in the promotion spots are mathematically safe
// Only consider active teams (folded teams have 0 points and 0 games)
$safeTeams = [];
foreach ($activeTeams as $tIndex => $team) {
    if ($team['rank'] > $spots) {
        // Only consider clubs currently in promotion positions
        continue;
    }
    $currentPoints = $team['points'];
    $possibleAhead = 0;
    foreach ($activeTeams as $other) {
        if ($other['team'] === $team['team']) {
            continue;
        }
        // If the other club has at least as many points now, it is already ahead
        // If it has fewer now but can reach or exceed the current points of $team,
        // assume it could finish ahead (ties count against $team)
        if ($other['points'] >= $currentPoints || ($other['points'] < $currentPoints && $other['max_points'] >= $currentPoints)) {
            $possibleAhead++;
        }
    }
    // If fewer than $spots clubs can finish ahead of this club, it's safe
    if ($possibleAhead < $spots) {
        $safeTeams[] = $team;
    }
}

// Fast lookup for the legacy safe-club status used in the league table
$safeTeamNames = array_fill_keys(array_column($safeTeams, 'team'), true);

// ============================================================================
// LOGIC: Calculate promotion threshold based on CURRENT 10th place team
// ============================================================================

// Find the team in 10th position among ACTIVE teams (0-based index 9)
// This is the team that would be just outside promotion if the season ended now
// Since 9 teams are promoted, 10th place is the cutoff
// Note: We use activeTeams to exclude folded teams from promotion calculations
$cutoffIndex = 9;  // 0-based index for 10th place among active teams
$cutoffPosition = $cutoffIndex + 1;  // 1-based position for display

if (!isset($activeTeams[$cutoffIndex])) {
    fwrite(STDERR, "Could not find team at index {$cutoffIndex} (10th place). Only " . count($activeTeams) . " active teams found.\n");
    exit(1);
}

$cutoffTeam = $activeTeams[$cutoffIndex];

// Calculate games remaining for the cutoff team specifically
// Uses the reduced totalGames (e.g., 28 instead of 30) based on folded team count
$cutoffGamesPlayed = $cutoffTeam['played'];
$cutoffGamesRemaining = $totalGames - $cutoffGamesPlayed;
if ($cutoffGamesRemaining < 0) {
    $cutoffGamesRemaining = 0;
}

// Calculate max possible points for ONLY the cutoff team
// Formula: max_points_10th = points + (games_remaining * 3)
$cutoffMaxPoints = $cutoffTeam['points'] + ($cutoffGamesRemaining * 3);

// Promotion threshold = max_points_10th + 1
// Any team with current points >= this threshold is mathematically promoted
// because even if the 10th place team wins all remaining games, they can only reach cutoffMaxPoints
$promotionThreshold = $cutoffMaxPoints + 1;

// Teams mathematically promoted: current points >= threshold
// Only consider active teams (exclude folded teams)
$mathematicallyPromoted = [];
$almostPromoted = []; // Within 3 points of threshold

foreach ($activeTeams as $team) {
    if ($team['points'] >= $promotionThreshold) {
        $mathematicallyPromoted[] = $team;
    } elseif ($team['points'] >= $promotionThreshold - 3 && $team['rank'] <= $spots) {
        $almostPromoted[] = $team;
    }
}

// Calculate points needed for each team to guarantee promotion
$pointsNeeded = [];
foreach ($activeTeams as $team) {
    if ($team['rank'] > $spots) {
        // Team is outside promotion spots
        $pointsNeeded[$team['team']] = max(0, $promotionThreshold - $team['points']);
    } else {
        // Team is in promotion spots - already safe or needs few points
        $pointsNeeded[$team['team']] = max(0, $promotionThreshold - $team['points']);
    }
}

// ============================================================================
// OUTPUT RESULTS
// ============================================================================
echo "=== WoSFL Premier Division League Table ===\n\n";
echo sprintf("%-4s | %-25s | %5s | %5s | %6s | %8s | %-35s\n", "Pos", "Team", "Played", "Pts", "GamesL", "MaxPts", "Status");
echo str_repeat("-", 105) . "\n";
foreach ($activeTeams as $team) {
    $remaining = $totalGames - $team['played'];
    if ($remaining < 0) {
        $remaining = 0;
    }
    $maxPoints = $team['points'] + ($remaining * 3);

    if (isset($safeTeamNames[$team['team']])) {
        $status = "Legacy: Mathematically Safe Clubs";
    } elseif ($team['rank'] <= $spots) {
        $status = "[IN SPOTS]";
    } else {
        $status = "";
    }

    echo sprintf(
        "%-4d | %-25s | %5d | %5d | %6d | %8d | %-35s\n",
        $team['rank'],
        $team['team'],
        $team['played'],
        $team['points'],
        $remaining,
        $maxPoints,
        $status
    );
}
?>
</pre>
