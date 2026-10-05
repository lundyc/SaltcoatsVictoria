<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/render_access_token.php';
$isTokenRender = RENDER_ACCESS_TOKEN !== ''
    && ($_GET['render'] ?? '') === '1'
    && hash_equals(RENDER_ACCESS_TOKEN, (string) ($_GET['_token'] ?? ''));
if (!$isTokenRender && !hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/event_share_lib.php';
require_once __DIR__ . '/sponsors_lib.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/template_pack_render.php';

function match_event_graphic_template_key(string $eventType): string
{
    return match ($eventType) {
        'kickoff', 'yellow_card', 'red_card', 'substitution' => 'kick_off',
        'half_time' => 'half_time',
        'full_time' => 'full_time',
        default => '',
    };
}

/**
 * @return array{lead: string, accent: string}
 */
function match_event_graphic_headline_parts(string $headline): array
{
    $headline = trim($headline);
    if ($headline === '') {
        return ['lead' => '', 'accent' => ''];
    }

    $words = preg_split('/\s+/', $headline) ?: [];
    $words = array_values(array_filter($words, static fn(string $word): bool => $word !== ''));
    if (count($words) < 2) {
        return ['lead' => $headline, 'accent' => ''];
    }

    $accent = array_pop($words);

    return [
        'lead' => implode(' ', $words),
        'accent' => (string) $accent,
    ];
}

function match_event_graphic_badge_path(string $club, bool $useWhiteBadge = false): string
{
    global $pdo;
    if ($pdo instanceof PDO) {
        $opponentBadge = matchOpponentBadgeAssetUrl($pdo, $club, $useWhiteBadge);
        if ($opponentBadge !== '') {
            return $opponentBadge;
        }
    }

    $slug = matches_slugify($club);
    if ($slug === '') {
        return '';
    }

    if ($useWhiteBadge) {
        $whiteBadgeDir = __DIR__ . '/badges/white';
        $whiteBadgeFiles = is_dir($whiteBadgeDir)
            ? array_values(array_filter(glob($whiteBadgeDir . '/*') ?: [], static fn(string $path): bool => is_file($path)))
            : [];
        $exactMatches = [];
        $closeMatches = [];

        foreach ($whiteBadgeFiles as $whiteBadgePath) {
            $extension = strtolower((string) pathinfo($whiteBadgePath, PATHINFO_EXTENSION));
            if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
                continue;
            }

            $fileSlug = matches_slugify((string) pathinfo($whiteBadgePath, PATHINFO_FILENAME));
            if ($fileSlug === $slug) {
                $exactMatches[] = $whiteBadgePath;
            } elseif (str_starts_with($fileSlug, $slug . '-') || str_starts_with($slug, $fileSlug . '-')) {
                $closeMatches[] = $whiteBadgePath;
            }
        }

        $matches = $exactMatches !== [] ? $exactMatches : $closeMatches;
        if ($matches !== []) {
            usort($matches, static fn(string $left, string $right): int => strlen(basename($left)) <=> strlen(basename($right)));
            $whiteBadgePath = $matches[0];
            return '/badges/white/' . rawurlencode(basename($whiteBadgePath)) . '?v=' . rawurlencode((string) filemtime($whiteBadgePath));
        }
    }

    $relativePath = 'badges/' . $slug . '.png';
    $absolutePath = __DIR__ . '/' . $relativePath;

    return is_file($absolutePath) ? $relativePath : '';
}

function match_event_graphic_asset_url(string $path): string
{
    $path = trim($path);
    if ($path === '' || preg_match('#^(?:https?:)?//#i', $path) === 1 || str_contains($path, '?')) {
        return $path;
    }

    $absolutePath = __DIR__ . '/' . ltrim($path, '/');
    if (!is_file($absolutePath)) {
        return $path;
    }

    return $path . '?v=' . rawurlencode((string) filemtime($absolutePath));
}

/**
 * Squad numbers are computed per-fixture from the Starting XI selection, not
 * stored as a permanent player attribute, so look them up by fixture id.
 *
 * @return array<string, int>
 */
function match_event_graphic_squad_numbers(int $fixtureId): array
{
    global $pdo;
    static $cache = [];
    if (isset($cache[$fixtureId])) {
        return $cache[$fixtureId];
    }

    $numbers = [];
    if ($fixtureId > 0 && isset($pdo) && $pdo instanceof PDO) {
        try {
            $statement = $pdo->prepare('SELECT starting11_squad_numbers_json FROM match_fixtures WHERE id = :id LIMIT 1');
            $statement->execute([':id' => $fixtureId]);
            $decoded = json_decode((string) ($statement->fetchColumn() ?: ''), true);
            if (is_array($decoded)) {
                foreach ($decoded as $playerName => $number) {
                    if (is_string($playerName) && is_numeric($number)) {
                        $numbers[$playerName] = (int) $number;
                    }
                }
            }
        } catch (Throwable $squadNumberError) {
            error_log('Substitution squad numbers could not be loaded: ' . $squadNumberError->getMessage());
        }
    }

    $cache[$fixtureId] = $numbers;
    return $numbers;
}

/** @return array{red: int, green: int, blue: int} */
function match_event_graphic_hex_rgb(string $color): array
{
    $color = ltrim($color, '#');
    return [
        'red' => hexdec(substr($color, 0, 2)),
        'green' => hexdec(substr($color, 2, 2)),
        'blue' => hexdec(substr($color, 4, 2)),
    ];
}

$isRender = isset($_GET['render']);
$isLivePreview = isset($_GET['live_preview']);
$app = app_bootstrap_state();
$isAuthenticated = $isRender ? true : $app['isAuthenticated'];
$styleVersion = $app['styleVersion'];
$matchId = isset($_GET['id']) && is_string($_GET['id']) ? trim($_GET['id']) : '';
$eventId = isset($_GET['event_id']) && is_string($_GET['event_id']) ? trim($_GET['event_id']) : '';
$match = $matchId !== '' ? matches_find_by_id(matches_load_all(), $matchId) : null;
$event = null;

if ($match !== null) {
    $event = $eventId !== '' ? event_share_find_event($match, $eventId) : event_share_latest_event($match);
}

if ($match === null || $event === null) {
    http_response_code(404);
}

$bodyClass = 'bg-cream' . ($isRender ? ' page-render' : '');
$headline = $event !== null && $match !== null ? event_share_title($match, $event) : 'Event unavailable';
$meta = $event !== null ? event_share_meta($event) : '';
$fixture = $match !== null ? 'Saltcoats Victoria ' . (matches_normalize_venue((string) ($match['venue'] ?? 'H')) === 'A' ? 'away at ' : 'vs ') . trim((string) ($match['opponent'] ?? 'the opposition')) : '';
$competition = $match !== null ? trim((string) ($match['competition'] ?? '')) : '';
$matchDate = $match !== null ? app_format_uk_date((string) ($match['match_date'] ?? ''), '') : '';
$eventType = $event !== null ? trim((string) ($event['type'] ?? '')) : '';
if ($eventType === 'yellow_card') {
    $headline = 'Yellow Card';
} elseif ($eventType === 'red_card') {
    $headline = 'Red Card';
} elseif ($eventType === 'substitution') {
    $headline = 'Substitution';
}
$templatePackActionKey = match ($eventType) {
    'kickoff' => 'kick_off',
    'half_time' => 'half_time',
    'full_time' => 'full_time',
    'goal' => 'goal',
    'substitution' => 'substitution',
    'yellow_card' => 'yellow_card',
    'red_card' => 'red_card',
    'player_of_match' => 'player_of_match',
    'postponed' => 'postponed',
    'abandoned' => 'abandoned',
    default => '',
};
$templatePackContext = $templatePackActionKey !== '' && $match !== null && ctype_digit((string) ($match['id'] ?? ''))
    ? matchTemplatePackContext($pdo, (int) $match['id'], $templatePackActionKey, true)
    : [];
$resolvedEventPackId = (int) ($templatePackContext['pack']['id'] ?? 0);
if ($resolvedEventPackId > 0 && $templatePackActionKey !== '') {
    $templatePackContext = matchTemplatePackPreferLatest($pdo, $templatePackContext, $templatePackActionKey);
}
$templatePackBrand = isset($templatePackContext['brand_settings']) && is_array($templatePackContext['brand_settings'])
    ? $templatePackContext['brand_settings'] : [];
$templatePackLayout = matchTemplatePackLayout($templatePackContext);
$templatePackElements = isset($templatePackLayout['elements']) && is_array($templatePackLayout['elements'])
    ? $templatePackLayout['elements'] : [];
$templatePackBackground = matchTemplatePackAssetUrl((string) ($templatePackContext['action']['background_path'] ?? ''));
$templatePackPrimary = matchTemplatePackColour($templatePackBrand, 'primary', '#5c1428');
$templatePackAccent = matchTemplatePackColour($templatePackBrand, 'accent', '#f0bf1d');
$templatePackText = matchTemplatePackColour($templatePackBrand, 'text', '#ffffff');
$templatePackHeadingFont = matchTemplatePackFont($templatePackBrand, 'heading', 'Poppins');
$templatePackBodyFont = matchTemplatePackFont($templatePackBrand, 'body', 'Poppins');
$templatePackHeadingTransform = match ((string) ($templatePackBrand['heading_style'] ?? 'natural')) {
    'uppercase' => 'uppercase',
    'title' => 'capitalize',
    default => 'none',
};
$templatePackBackgroundFitCandidate = (string) ($templatePackLayout['background_fit'] ?? 'cover');
$templatePackBackgroundFit = in_array($templatePackBackgroundFitCandidate, ['cover', 'contain', 'stretch'], true)
    ? $templatePackBackgroundFitCandidate : 'cover';
$templatePackBackgroundSize = $templatePackBackgroundFit === 'stretch' ? '100% 100%' : $templatePackBackgroundFit;
$templatePackCanvasWidth = max(320, min(4096, (int) ($templatePackLayout['canvas_width'] ?? 1080)));
$templatePackCanvasHeight = max(320, min(4096, (int) ($templatePackLayout['canvas_height'] ?? 1080)));
$templatePackElementStyle = static function (string $key) use ($templatePackElements): string {
    return matchTemplatePackElementStyle(
        isset($templatePackElements[$key]) && is_array($templatePackElements[$key])
            ? $templatePackElements[$key] : null
    );
};
$scoreLine = $match !== null && $event !== null ? event_share_score_line($match, $event) : '';
$posterTemplateKey = match_event_graphic_template_key($eventType);
$posterTemplate = $posterTemplateKey !== '' ? matches_match_graphic_template($match, $posterTemplateKey) : matches_graphic_template_empty();
$backgroundImage = $templatePackBackground !== '' ? $templatePackBackground : trim((string) ($posterTemplate['image'] ?? ''));
if ($backgroundImage === '' && $posterTemplateKey !== '' && $match !== null) {
    $backgroundImage = matches_match_lineup_background($match);
}
$customBackground = $match !== null && $event !== null ? event_share_custom_background($match, $event) : '';
$preferPackGoalBackground = $resolvedEventPackId === 5
    && $templatePackActionKey === 'goal'
    && $templatePackBackground !== '';
if ($customBackground !== '' && !$preferPackGoalBackground) {
    $backgroundImage = $customBackground;
}
if ($backgroundImage === '' && $eventType === 'goal' && $match !== null) {
    $backgroundImage = matches_match_lineup_background($match);
}
$backgroundImageUrl = str_starts_with($backgroundImage, '/')
    ? $backgroundImage
    : match_event_graphic_asset_url($backgroundImage);
$isPosterGraphic = $backgroundImage !== '' && ($posterTemplateKey !== '' || $customBackground !== '' || in_array($eventType, ['goal', 'player_of_match'], true));
$isKickoffGraphic = in_array($eventType, ['kickoff', 'yellow_card', 'red_card'], true) && $isPosterGraphic;
$isGoalGraphic = $eventType === 'goal';
$isHalfTimeGraphic = $eventType === 'half_time';
$isFullTimeGraphic = $eventType === 'full_time';
$isSubstitutionGraphic = $eventType === 'substitution';
$isPlayerOfMatchGraphic = $eventType === 'player_of_match';
$usePackFiveKickOffGraphic = $eventType === 'kickoff'
    && $resolvedEventPackId === 5
    && isset($templatePackElements['badges']);
$usePackFiveRedCardGraphic = $eventType === 'red_card'
    && $resolvedEventPackId === 5
    && isset($templatePackElements['badges']);
$usePackFiveYellowCardGraphic = $eventType === 'yellow_card'
    && $resolvedEventPackId === 5
    && isset($templatePackElements['badges']);
$usePackFiveScoreGraphic = in_array($eventType, ['half_time', 'full_time'], true)
    && $resolvedEventPackId === 5
    && isset($templatePackElements['score']);
$usePackFiveGoalGraphic = $eventType === 'goal'
    && $resolvedEventPackId === 5
    && isset($templatePackElements['headline'], $templatePackElements['badges'], $templatePackElements['score']);
$usePackFivePlayerOfMatchGraphic = $eventType === 'player_of_match'
    && $resolvedEventPackId === 5
    && isset($templatePackElements['headline'], $templatePackElements['player_name'], $templatePackElements['player_image']);
$usePackFiveSubstitutionGraphic = $eventType === 'substitution'
    && $resolvedEventPackId === 5
    && isset($templatePackElements['headline'], $templatePackElements['player_on'], $templatePackElements['player_off']);
$usePackFiveCanvasGraphic = $usePackFiveKickOffGraphic || $usePackFiveRedCardGraphic || $usePackFiveYellowCardGraphic || $usePackFiveScoreGraphic || $usePackFiveGoalGraphic || $usePackFivePlayerOfMatchGraphic || $usePackFiveSubstitutionGraphic;
$usePackFiveKickOffStyleGraphic = $usePackFiveKickOffGraphic || $usePackFiveRedCardGraphic || $usePackFiveYellowCardGraphic;
$headlineParts = match_event_graphic_headline_parts($headline);
$useWhiteBadges = array_key_exists('use_white_badges', $templatePackBrand)
    ? !empty($templatePackBrand['use_white_badges'])
    : ($match !== null && $event !== null ? event_share_uses_white_badges($match, $event) : false);
if ($usePackFiveCanvasGraphic) {
    $useWhiteBadges = true;
}
$gradientSettings = $match !== null && $event !== null
    ? event_share_gradient_settings($match, $event)
    : ['level' => 70, 'color' => '#000000'];
$packGradientColor = (string) ($templatePackLayout['gradient_color'] ?? '');
if (preg_match('/^#[0-9a-f]{6}$/i', $packGradientColor) === 1) {
    $gradientSettings['color'] = $packGradientColor;
}
if (array_key_exists('gradient_strength', $templatePackLayout)) {
    $gradientSettings['level'] = max(0, min(100, (int) $templatePackLayout['gradient_strength']));
}
$gradientRgb = match_event_graphic_hex_rgb($gradientSettings['color']);
$gradientStrength = max(0, min(100, (int) $gradientSettings['level'])) / 100;
$gradientStyle = sprintf(
    'background:linear-gradient(180deg,rgba(%1$d,%2$d,%3$d,%4$.3f) 0%%,rgba(%1$d,%2$d,%3$d,%5$.3f) 46%%,rgba(%1$d,%2$d,%3$d,%6$.3f) 100%%),radial-gradient(circle at top right,rgba(240,191,29,0.16),transparent 22%%)',
    $gradientRgb['red'],
    $gradientRgb['green'],
    $gradientRgb['blue'],
    $gradientStrength * 0.45,
    $gradientStrength * 0.12,
    $gradientStrength
);
if ($usePackFiveKickOffStyleGraphic) {
    // The pack editor uses one even image treatment, so the exported artwork
    // must not introduce the legacy event gradient or gold corner glow.
    $gradientStyle = sprintf(
        'background:rgba(%d,%d,%d,%.3f)',
        $gradientRgb['red'],
        $gradientRgb['green'],
        $gradientRgb['blue'],
        $gradientStrength
    );
}
if ($isGoalGraphic || $isHalfTimeGraphic) {
    $gradientStyle = sprintf(
        'background:linear-gradient(180deg,rgba(%1$d,%2$d,%3$d,%4$.3f) 0%%,rgba(%1$d,%2$d,%3$d,%5$.3f) 45%%,rgba(%1$d,%2$d,%3$d,%6$.3f) 100%%)',
        $gradientRgb['red'],
        $gradientRgb['green'],
        $gradientRgb['blue'],
        min(1, $gradientStrength + 0.15),
        $gradientStrength * 0.75,
        $gradientStrength * 0.5
    );
}
if ($usePackFiveGoalGraphic) {
    $gradientStyle = sprintf(
        'background:rgba(%d,%d,%d,%.3f)',
        $gradientRgb['red'],
        $gradientRgb['green'],
        $gradientRgb['blue'],
        $gradientStrength
    );
}
if ($isFullTimeGraphic) {
    $gradientStyle = sprintf(
        'background:linear-gradient(180deg,rgba(%1$d,%2$d,%3$d,%4$.3f) 0%%,rgba(%1$d,%2$d,%3$d,%5$.3f) 48%%,rgba(%1$d,%2$d,%3$d,%6$.3f) 100%%)',
        $gradientRgb['red'],
        $gradientRgb['green'],
        $gradientRgb['blue'],
        $gradientStrength * 0.8,
        $gradientStrength * 0.45,
        min(1, $gradientStrength + 0.15)
    );
}
if ($usePackFiveSubstitutionGraphic) {
    $gradientStyle = sprintf(
        'background:rgba(%d,%d,%d,%.3f)',
        $gradientRgb['red'],
        $gradientRgb['green'],
        $gradientRgb['blue'],
        $gradientStrength
    );
}
if ($usePackFiveScoreGraphic) {
    // The score poster uses a deliberately black cinematic fade at both edges,
    // leaving the action photograph clear through the centre.
    $gradientStyle = sprintf(
        'background:linear-gradient(180deg,rgba(0,0,0,%1$.3f) 0%%,rgba(0,0,0,%2$.3f) 38%%,rgba(0,0,0,%3$.3f) 62%%,rgba(0,0,0,%4$.3f) 100%%)',
        min(1, $gradientStrength + 0.12),
        $gradientStrength * 0.10,
        $gradientStrength * 0.14,
        min(1, $gradientStrength + 0.18)
    );
}
$homeBadge = match_event_graphic_asset_url(match_event_graphic_badge_path('Saltcoats Victoria', $useWhiteBadges));
$awayBadge = $match !== null ? match_event_graphic_asset_url(match_event_graphic_badge_path((string) ($match['opponent'] ?? ''), $useWhiteBadges)) : '';
$venue = $match !== null ? matches_normalize_venue((string) ($match['venue'] ?? 'H')) : 'H';
$goalLeftBadge = $venue === 'H' ? $homeBadge : $awayBadge;
$goalRightBadge = $venue === 'H' ? $awayBadge : $homeBadge;
$goalPlayerName = ($match !== null && $event !== null)
    ? event_share_public_player_name($match, $event, (string) ($event['player'] ?? ''))
    : 'Unknown Player';
if ($eventType === 'goal' && !empty($event['own_goal'])) {
    $goalPlayerName = event_share_own_goal_player_label($match, $event);
}
$eventPlayer = ($match !== null && $event !== null)
    ? event_share_public_player_name($match, $event, (string) ($event['player'] ?? ''))
    : trim((string) ($event['player'] ?? ''));
$eventSecondaryPlayer = ($match !== null && $event !== null)
    ? event_share_public_player_name($match, $event, (string) ($event['secondary_player'] ?? ''))
    : trim((string) ($event['secondary_player'] ?? ''));
$eventMinute = trim((string) ($event['minute'] ?? ''));
$playerOfMatchFirstName = $eventPlayer;
$playerOfMatchSurname = '';
if ($isPlayerOfMatchGraphic && $eventPlayer !== '') {
    $playerOfMatchNameParts = preg_split('/\s+/', $eventPlayer) ?: [];
    $playerOfMatchFirstName = (string) array_shift($playerOfMatchNameParts);
    $playerOfMatchSurname = trim(implode(' ', $playerOfMatchNameParts));
}
$playerOfMatchImage = '';
if ($isPlayerOfMatchGraphic && $eventPlayer !== '') {
    try {
        $playerOfMatchStatement = $pdo->prepare('SELECT avatar FROM players WHERE TRIM(name) = :player_name LIMIT 1');
        $playerOfMatchStatement->execute([':player_name' => $eventPlayer]);
        $playerOfMatchAvatar = basename(trim((string) ($playerOfMatchStatement->fetchColumn() ?: '')));
        $playerOfMatchFile = __DIR__ . '/uploads/players/' . $playerOfMatchAvatar;
        if ($playerOfMatchAvatar !== '' && is_file($playerOfMatchFile)) {
            $playerOfMatchImage = '/uploads/players/' . rawurlencode($playerOfMatchAvatar);
        }
    } catch (Throwable $playerOfMatchError) {
        error_log('Player of the Match image could not be loaded: ' . $playerOfMatchError->getMessage());
    }
}
$substitutionPairs = $event !== null && $match !== null && $eventType === 'substitution'
    ? event_share_substitution_pairs($match, $event)
    : [];
$substitutionSquadNumbers = ($eventType === 'substitution' && $match !== null && ctype_digit((string) ($match['id'] ?? '')))
    ? match_event_graphic_squad_numbers((int) $match['id'])
    : [];
$halfTimeScore = $match !== null && $event !== null
    ? event_share_score_at_event($match, $event)
    : ['home' => 0, 'away' => 0];
$halfTimeScorers = [];
if ($match !== null && $event !== null) {
    $halfTimeSequence = (int) ($event['sequence'] ?? PHP_INT_MAX);
    foreach (event_share_events($match) as $matchEvent) {
        if ((int) ($matchEvent['sequence'] ?? 0) > $halfTimeSequence) {
            continue;
        }
        $matchEventType = (string) ($matchEvent['type'] ?? '');
        $isRecordedGoal = $matchEventType === 'goal'
            || ($matchEventType === 'penalty' && (string) ($matchEvent['outcome'] ?? '') === 'scored');
        if (!$isRecordedGoal) {
            continue;
        }

        $scorer = trim((string) ($matchEvent['player'] ?? ''));
        if (!empty($matchEvent['own_goal'])) {
            $scorer = event_share_own_goal_player_label($match, $matchEvent);
        } elseif ($scorer === '' || strcasecmp($scorer, 'Unknown Player') === 0) {
            $scorer = (string) ($matchEvent['team'] ?? '') === 'opponent'
                ? trim((string) ($match['opponent'] ?? 'Opponent'))
                : 'Unknown Player';
        }
        $minute = trim((string) ($matchEvent['minute'] ?? ''));
        $halfTimeScorers[] = ($minute !== '' ? $minute . "' — " : '') . $scorer;
    }
}
$clubSponsors = [];
if ($isKickoffGraphic) {
    $allSponsors = sponsors_load_all();
    $principalClubSponsors = array_values(array_filter($allSponsors, static function (array $sponsor): bool {
        return ($sponsor['type'] ?? '') === 'club'
            && ($sponsor['tier'] ?? '') === 'principal'
            && !empty($sponsor['is_active']);
    }));

    $clubSponsors = $principalClubSponsors !== []
        ? $principalClubSponsors
        : sponsors_active_by_type($allSponsors, 'club');
}
$packFiveFixtureSponsors = [];
$packFiveMainSponsors = [];
$packFivePlayerSponsors = [];
$packFiveGoalSponsors = [];
$packFiveCompetitionBadge = '';
$packFiveVenue = trim((string) ($match['venue_name'] ?? ''));
$packFiveVenue = $packFiveVenue !== '' ? $packFiveVenue : ($venue === 'H' ? 'Home' : 'Away');
$packFiveDate = '';
$packFiveGoalDate = '';
if ($match !== null) {
    $packFiveTimestamp = strtotime(trim((string) ($match['match_date'] ?? '')) . ' ' . (trim((string) ($match['kickoff_time'] ?? '')) ?: '00:00'));
    if ($packFiveTimestamp) {
        $packFiveDate = date('l jS F - g:ia', $packFiveTimestamp);
        $packFiveDate = str_replace(':00', '', $packFiveDate);
        $packFiveGoalDate = strtoupper(date('l jS F Y', $packFiveTimestamp));
    }
}
if ($usePackFiveCanvasGraphic && $match !== null) {
    $competitionRow = getMatchCompetitionByName($pdo, $competition);
    if (!$competitionRow || trim((string) ($competitionRow['white_badge_image'] ?? '')) === '') {
        foreach (getMatchCompetitions($pdo) as $competitionOption) {
            $optionName = trim((string) ($competitionOption['name'] ?? ''));
            if (
                trim((string) ($competitionOption['white_badge_image'] ?? '')) !== ''
                && (stripos($optionName, $competition) !== false || stripos($competition, $optionName) !== false)
            ) {
                $competitionRow = $competitionOption;
                break;
            }
        }
    }
    $competitionBadgePath = trim((string) ($competitionRow['white_badge_image'] ?? $competitionRow['badge_image'] ?? ''));
    if ($competitionBadgePath !== '') {
        $packFiveCompetitionBadge = '/' . ltrim($competitionBadgePath, '/');
    }

    foreach (getDisplayableMatchSponsorshipRows($pdo, (int) ($match['id'] ?? 0)) as $sponsorRow) {
        if (!in_array((string) ($sponsorRow['sponsorship_role'] ?? ''), ['match_day', 'match_ball'], true)) {
            continue;
        }
        $logo = basename(trim((string) ($sponsorRow['sponsor_white_logo'] ?? '')));
        if ($logo === '') $logo = basename(trim((string) ($sponsorRow['sponsor_logo'] ?? '')));
        $packFiveFixtureSponsors[] = [
            'role' => (string) ($sponsorRow['sponsorship_role'] ?? '') === 'match_ball' ? 'Matchball Sponsor' : 'Matchday Sponsor',
            'name' => trim((string) ($sponsorRow['sponsor_name'] ?? 'Sponsor')),
            'logo' => $logo !== '' ? '/uploads/sponsors/' . rawurlencode($logo) : '',
        ];
    }
    foreach (getActiveMainSponsorAgreements($pdo, (int) ($match['season_id'] ?? 0), (string) ($match['match_date'] ?? '')) as $mainSponsor) {
        $logo = basename(trim((string) ($mainSponsor['white_logo_path'] ?? '')));
        if ($logo === '') $logo = basename(trim((string) ($mainSponsor['logo_path'] ?? '')));
        if ($logo !== '') {
            $packFiveMainSponsors[] = [
                'name' => trim((string) ($mainSponsor['name'] ?? 'Sponsor')),
                'logo' => '/uploads/sponsors/' . rawurlencode($logo),
            ];
        }
    }

    if (($usePackFiveGoalGraphic || $usePackFivePlayerOfMatchGraphic) && empty($event['own_goal']) && (string) ($event['team'] ?? '') === 'svfc' && $goalPlayerName !== 'Unknown Player') {
        try {
            $playerSponsorStatement = $pdo->prepare('
                SELECT sponsorships.slot, sponsors.name, sponsors.logo_path, sponsors.white_logo_path
                FROM players
                INNER JOIN sponsorships
                    ON sponsorships.player_id = players.id
                    AND sponsorships.season_id = :season_id
                    AND sponsorships.ended_at IS NULL
                    AND sponsorships.slot IN (\'home\', \'away\')
                INNER JOIN sponsors ON sponsors.id = sponsorships.sponsor_id
                WHERE TRIM(players.name) = :player_name
                ORDER BY FIELD(sponsorships.slot, \'home\', \'away\'), sponsorships.id DESC
            ');
            $playerSponsorStatement->execute([
                ':season_id' => (int) ($match['season_id'] ?? 0),
                ':player_name' => $goalPlayerName,
            ]);
            $playerSponsorsBySlot = [];
            foreach ($playerSponsorStatement->fetchAll(PDO::FETCH_ASSOC) as $playerSponsorRow) {
                $slot = (string) ($playerSponsorRow['slot'] ?? '');
                if (isset($playerSponsorsBySlot[$slot])) {
                    continue;
                }
                $logo = basename(trim((string) ($playerSponsorRow['white_logo_path'] ?? '')));
                if ($logo === '') {
                    $logo = basename(trim((string) ($playerSponsorRow['logo_path'] ?? '')));
                }
                $playerSponsorsBySlot[$slot] = [
                    'role' => $slot === 'away' ? 'Away Player Sponsor' : 'Home Player Sponsor',
                    'name' => trim((string) ($playerSponsorRow['name'] ?? 'Sponsor')),
                    'logo' => $logo !== '' ? '/uploads/sponsors/' . rawurlencode($logo) : '',
                    'available' => false,
                ];
            }
            foreach (['home', 'away'] as $slot) {
                $packFivePlayerSponsors[] = $playerSponsorsBySlot[$slot] ?? [
                    'role' => $slot === 'away' ? 'Away Player Sponsor' : 'Home Player Sponsor',
                    'name' => 'Available for Sponsorship',
                    'logo' => '',
                    'available' => true,
                ];
            }
        } catch (Throwable $playerSponsorError) {
            error_log('Goal player sponsors could not be loaded: ' . $playerSponsorError->getMessage());
        }
    }

    if ($usePackFiveGoalGraphic) {
        $packFiveGoalSponsors = (string) ($event['team'] ?? '') === 'opponent'
            ? $packFiveFixtureSponsors
            : $packFivePlayerSponsors;
    }
}
$packFiveFixtureElement = isset($templatePackElements['fixture']) && is_array($templatePackElements['fixture'])
    ? $templatePackElements['fixture'] : [];
$packFiveFont = static function ($value, string $fallback): string {
    $value = trim((string) $value);
    return $value !== '' && preg_match('/^[a-z0-9 ._-]{1,80}$/i', $value) === 1 ? $value : $fallback;
};
$packFiveWeight = static function ($value, int $fallback): int {
    $weight = (int) $value;
    return in_array($weight, [300, 400, 500, 600, 700, 800, 900], true) ? $weight : $fallback;
};
$packFiveVenueFont = $packFiveFont($packFiveFixtureElement['venue_font'] ?? '', $templatePackBodyFont);
$packFiveDateFont = $packFiveFont($packFiveFixtureElement['date_font'] ?? '', $templatePackBodyFont);
$packFiveVenueWeight = $packFiveWeight($packFiveFixtureElement['venue_weight'] ?? null, 600);
$packFiveDateWeight = $packFiveWeight($packFiveFixtureElement['date_weight'] ?? null, 500);
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Match Event Graphic</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&amp;family=Montserrat:wght@400;600;700;800&amp;family=Oswald:wght@400;600;700&amp;family=Poppins:wght@400;600;700;800&amp;family=Roboto:wght@400;500;700&amp;family=Roboto+Condensed:wght@400;600;700&amp;display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/social-publishing.css?v=<?= $styleVersion ?>">

    <?php
require_once __DIR__ . '/lib/dynamic_styles.php';
ob_start();
require __DIR__ . '/assets/css/match_event_graphic.dynamic.php';
$hubDynamicCss1 = (string) ob_get_clean();
$hubDynamicStyleHref1 = hub_dynamic_stylesheet_register($hubDynamicCss1);
?>
<link rel="stylesheet" href="<?= htmlspecialchars($hubDynamicStyleHref1, ENT_QUOTES, 'UTF-8') ?>">

    <?php if ($isRender): ?>
        <?php
require_once __DIR__ . '/lib/dynamic_styles.php';
ob_start();
require __DIR__ . '/assets/css/match_event_graphic-export.dynamic.php';
$hubDynamicCss2 = (string) ob_get_clean();
$hubDynamicStyleHref2 = hub_dynamic_stylesheet_register($hubDynamicCss2);
?>
<link rel="stylesheet" href="<?= htmlspecialchars($hubDynamicStyleHref2, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <?php if ($isLivePreview): ?>
        <link rel="stylesheet" href="/admin/assets/css/match_event_graphic.css">
    <?php endif; ?>
</head>

<body class="<?= safe($bodyClass) ?>">
    <?php if (!$isRender && !$isAuthenticated): ?>
        <?php app_render_login_modal('Login Required', 'Enter your username or email and password to access the Saltcoats Victoria event graphic tools.'); ?>
    <?php endif; ?>

    <?php if ($isRender || $isAuthenticated): ?>
        <main class="<?= $isRender ? 'page-main--render' : 'pb-4' ?>">
            <div class="container-fluid">
                <?php if ($match === null || $event === null): ?>
                    <section class="utility-panel">
                        <p class="page-kicker">Graphic Not Found</p>
                        <h1 class="feature-card__title">The requested match event could not be found.</h1>
                    </section>
                <?php else: ?>
                    <section class="event-share-stage<?= $usePackFiveCanvasGraphic ? ' event-share-stage--pack-five-canvas' : '' ?>"<?= $usePackFiveCanvasGraphic ? ' style="width:' . (int) $templatePackCanvasWidth . 'px;height:' . (int) $templatePackCanvasHeight . 'px"' : '' ?>>
                        <article class="event-share-card<?= $isPosterGraphic ? ' event-share-card--poster' : '' ?><?= $isKickoffGraphic ? ' event-share-card--kickoff' : '' ?><?= $isGoalGraphic ? ' event-share-card--goal' : '' ?><?= $isHalfTimeGraphic ? ' event-share-card--half-time' : '' ?><?= $isFullTimeGraphic ? ' event-share-card--full-time' : '' ?><?= $isSubstitutionGraphic ? ' event-share-card--substitution' : '' ?><?= $isPlayerOfMatchGraphic ? ' event-share-card--player-of-match' : '' ?><?= $usePackFiveKickOffStyleGraphic ? ' event-share-card--pack-five-kick-off' : '' ?><?= $usePackFiveRedCardGraphic ? ' event-share-card--pack-five-red-card' : '' ?><?= $usePackFiveYellowCardGraphic ? ' event-share-card--pack-five-yellow-card' : '' ?><?= $usePackFiveScoreGraphic ? ' event-share-card--pack-five-score' : '' ?><?= $usePackFiveGoalGraphic ? ' event-share-card--pack-five-goal' : '' ?><?= $usePackFivePlayerOfMatchGraphic ? ' event-share-card--pack-five-player-of-match' : '' ?>" aria-label="Match event graphic card">
                            <?php if ($isPosterGraphic): ?>
                                <div class="event-share-card__poster-image" style="background-image: url('<?= safe($backgroundImageUrl) ?>')"></div>
                            <?php endif; ?>
                            <div class="event-share-card__poster-overlay" style="<?= safe($gradientStyle) ?>"></div>

                            <?php if ($usePackFiveKickOffStyleGraphic): ?>
                                <div class="pack-five-kick-off__element pack-five-kick-off__badges" style="<?= safe($templatePackElementStyle('badges')) ?>">
                                    <?php if ($homeBadge !== ''): ?><img class="pack-five-kick-off__badge" src="<?= safe($homeBadge) ?>" alt="Saltcoats Victoria badge"><?php endif; ?>
                                    <?php if ($awayBadge !== ''): ?><img class="pack-five-kick-off__badge" src="<?= safe($awayBadge) ?>" alt="<?= safe((string) ($match['opponent'] ?? 'Opponent')) ?> badge"><?php endif; ?>
                                </div>
                                <?php if ($usePackFiveRedCardGraphic): ?>
                                    <h1 class="pack-five-kick-off__element pack-five-kick-off__headline" style="<?= safe($templatePackElementStyle('headline')) ?>"><span>RED</span><span>CARD</span></h1>
                                    <p class="pack-five-kick-off__element pack-five-kick-off__player-name" style="<?= safe($templatePackElementStyle('player_name')) ?>"><?= safe($eventPlayer) ?></p>
                                <?php elseif ($usePackFiveYellowCardGraphic): ?>
                                    <h1 class="pack-five-kick-off__element pack-five-kick-off__headline" style="<?= safe($templatePackElementStyle('headline')) ?>"><span>YELLOW</span><span>CARD</span></h1>
                                    <p class="pack-five-kick-off__element pack-five-kick-off__player-name" style="<?= safe($templatePackElementStyle('player_name')) ?>"><?= safe($eventPlayer) ?></p>
                                <?php else: ?>
                                    <h1 class="pack-five-kick-off__element pack-five-kick-off__headline" style="<?= safe($templatePackElementStyle('headline')) ?>"><span>KICK</span><span>OFF</span></h1>
                                <?php endif; ?>
                                <div class="pack-five-kick-off__element pack-five-kick-off__fixture" style="<?= safe($templatePackElementStyle('fixture')) ?>">
                                    <strong style="font-family:'<?= safe($packFiveVenueFont) ?>',Arial,sans-serif;font-weight:<?= (int) $packFiveVenueWeight ?>"><?= safe($packFiveVenue) ?></strong>
                                    <span style="font-family:'<?= safe($packFiveDateFont) ?>',Arial,sans-serif;font-weight:<?= (int) $packFiveDateWeight ?>"><?= safe($packFiveDate) ?></span>
                                </div>
                                <div class="pack-five-kick-off__element pack-five-kick-off__footer-meta" style="<?= safe($templatePackElementStyle('footer_meta')) ?>">
                                    <?php if ($packFiveCompetitionBadge !== ''): ?><img src="<?= safe($packFiveCompetitionBadge) ?>" alt="<?= safe($competition) ?> badge"><?php endif; ?>
                                </div>
                                <?php if ($packFiveFixtureSponsors !== []): ?>
                                    <div class="pack-five-kick-off__element pack-five-kick-off__fixture-sponsors" style="<?= safe($templatePackElementStyle('fixture_sponsors')) ?>">
                                        <?php foreach ($packFiveFixtureSponsors as $fixtureSponsor): ?>
                                            <small><?= safe((string) $fixtureSponsor['role']) ?></small>
                                            <?php if ($fixtureSponsor['logo'] !== ''): ?><img src="<?= safe((string) $fixtureSponsor['logo']) ?>" alt="<?= safe((string) $fixtureSponsor['name']) ?>"><?php endif; ?>
                                            <strong><?= safe((string) $fixtureSponsor['name']) ?></strong>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($packFiveMainSponsors !== []): ?>
                                    <div class="pack-five-kick-off__element pack-five-kick-off__main-sponsors" style="<?= safe($templatePackElementStyle('sponsor_logos')) ?>">
                                        <?php foreach ($packFiveMainSponsors as $mainSponsor): ?>
                                            <img src="<?= safe((string) $mainSponsor['logo']) ?>" alt="<?= safe((string) $mainSponsor['name']) ?>">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($usePackFiveScoreGraphic): ?>
                                <h1 class="pack-five-score__element pack-five-score__headline-word pack-five-score__headline-word--first" style="<?= safe($templatePackElementStyle('headline_first')) ?>"><?= $isHalfTimeGraphic ? 'Half' : '<span class="pack-five-score__headline-kern-fix">F</span>ull' ?></h1>
                                <h1 class="pack-five-score__element pack-five-score__headline-word pack-five-score__headline-word--second" style="<?= safe($templatePackElementStyle('headline_second')) ?>">Time</h1>
                                <?php if ($packFiveCompetitionBadge !== ''): ?>
                                    <div class="pack-five-score__element pack-five-score__competition" style="<?= safe($templatePackElementStyle('sponsor')) ?>">
                                        <img src="<?= safe($packFiveCompetitionBadge) ?>" alt="<?= safe($competition) ?> badge">
                                    </div>
                                <?php endif; ?>
                                <?php if ($goalLeftBadge !== ''): ?>
                                    <div class="pack-five-score__element pack-five-score__badge" style="<?= safe($templatePackElementStyle('club_badge')) ?>">
                                        <img src="<?= safe($goalLeftBadge) ?>" alt="Home club badge">
                                    </div>
                                <?php endif; ?>
                                <div class="pack-five-score__element pack-five-score__score" style="<?= safe($templatePackElementStyle('score')) ?>" aria-label="<?= $isHalfTimeGraphic ? 'Half-time' : 'Full-time' ?> score"><span><?= (int) $halfTimeScore['home'] ?></span><i aria-hidden="true">–</i><span><?= (int) $halfTimeScore['away'] ?></span></div>
                                <?php if ($goalRightBadge !== ''): ?>
                                    <div class="pack-five-score__element pack-five-score__badge" style="<?= safe($templatePackElementStyle('opponent_badge')) ?>">
                                        <img src="<?= safe($goalRightBadge) ?>" alt="Away club badge">
                                    </div>
                                <?php endif; ?>
                                <div class="pack-five-score__element pack-five-score__scorers" style="<?= safe($templatePackElementStyle('fixture')) ?>" aria-label="Goalscorers">
                                    <?php if ($halfTimeScorers === []): ?>
                                        <span>No goals</span>
                                    <?php else: ?>
                                        <?php foreach ($halfTimeScorers as $scorerLine): ?><span><?= safe($scorerLine) ?></span><?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($packFiveFixtureSponsors !== []): ?>
                                    <div class="pack-five-score__element pack-five-score__fixture-sponsors" style="<?= safe($templatePackElementStyle('fixture_sponsors')) ?>" aria-label="Match sponsors">
                                        <?php foreach ($packFiveFixtureSponsors as $fixtureSponsor): ?>
                                            <small><?= safe((string) $fixtureSponsor['role']) ?></small>
                                            <?php if ($fixtureSponsor['logo'] !== ''): ?><img src="<?= safe((string) $fixtureSponsor['logo']) ?>" alt="<?= safe((string) $fixtureSponsor['name']) ?>"><?php endif; ?>
                                            <strong><?= safe((string) $fixtureSponsor['name']) ?></strong>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($packFiveMainSponsors !== []): ?>
                                    <div class="pack-five-score__element pack-five-score__main-sponsors" style="<?= safe($templatePackElementStyle('sponsor_logos')) ?>" aria-label="Club sponsors">
                                        <?php foreach ($packFiveMainSponsors as $mainSponsor): ?>
                                            <img src="<?= safe((string) $mainSponsor['logo']) ?>" alt="<?= safe((string) $mainSponsor['name']) ?>">
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($usePackFiveGoalGraphic): ?>
                                <?php if ($packFiveCompetitionBadge !== ''): ?>
                                    <div class="pack-five-goal__element pack-five-goal__competition" style="<?= safe($templatePackElementStyle('competition_meta')) ?>"><img src="<?= safe($packFiveCompetitionBadge) ?>" alt="<?= safe($competition) ?> badge"></div>
                                <?php endif; ?>
                                <div class="pack-five-goal__element pack-five-goal__meta pack-five-goal__meta--right" style="<?= safe($templatePackElementStyle('matchday_meta')) ?>"><?= safe($eventMinute !== '' ? rtrim($eventMinute, "'’") . "'" : 'GOAL') ?></div>
                                <p class="pack-five-goal__element pack-five-goal__player" style="<?= safe($templatePackElementStyle('player_name')) ?>"><?= safe($goalPlayerName) ?></p>
                                <h1 class="pack-five-goal__element pack-five-goal__headline" style="<?= safe($templatePackElementStyle('headline')) ?>">GOAL!</h1>
                                <div class="pack-five-goal__element pack-five-goal__badges" style="<?= safe($templatePackElementStyle('badges')) ?>" aria-label="Club badges">
                                    <?php if ($goalLeftBadge !== ''): ?><img src="<?= safe($goalLeftBadge) ?>" alt="Home club badge"><?php endif; ?>
                                    <?php if ($goalRightBadge !== ''): ?><img src="<?= safe($goalRightBadge) ?>" alt="Away club badge"><?php endif; ?>
                                </div>
                                <div class="pack-five-goal__element pack-five-goal__score" style="<?= safe($templatePackElementStyle('score')) ?>" aria-label="Score <?= (int) $halfTimeScore['home'] ?> to <?= (int) $halfTimeScore['away'] ?>"><span><?= (int) $halfTimeScore['home'] ?></span><i aria-hidden="true">-</i><span><?= (int) $halfTimeScore['away'] ?></span></div>
                                <?php if ($packFiveGoalSponsors !== []): ?>
                                    <div class="pack-five-goal__element pack-five-goal__player-sponsors<?= count($packFiveGoalSponsors) === 1 ? ' is-single' : '' ?>" style="<?= safe($templatePackElementStyle('player_sponsors')) ?>" aria-label="<?= (string) ($event['team'] ?? '') === 'opponent' ? 'Match sponsors' : safe($goalPlayerName) . ' sponsors' ?>">
                                        <?php foreach ($packFiveGoalSponsors as $goalSponsor): ?>
                                            <span class="pack-five-goal__player-sponsor<?= !empty($goalSponsor['available']) ? ' is-available' : '' ?><?= $goalSponsor['logo'] === '' ? ' pack-five-goal__player-sponsor--no-logo' : '' ?>">
                                                <small><?= safe((string) $goalSponsor['role']) ?></small>
                                                <span class="pack-five-goal__player-sponsor-body">
                                                    <?php if ($goalSponsor['logo'] !== ''): ?><img src="<?= safe((string) $goalSponsor['logo']) ?>" alt="<?= safe((string) $goalSponsor['name']) ?>"><?php endif; ?>
                                                    <strong><?= safe((string) $goalSponsor['name']) ?></strong>
                                                </span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="pack-five-goal__element pack-five-goal__meta pack-five-goal__meta--left" style="<?= safe($templatePackElementStyle('player_number')) ?>"><?= safe($packFiveGoalDate) ?></div>
                                <div class="pack-five-goal__element pack-five-goal__meta pack-five-goal__meta--right" style="<?= safe($templatePackElementStyle('venue')) ?>"><?= safe($packFiveVenue) ?></div>
                            <?php elseif ($usePackFivePlayerOfMatchGraphic): ?>
                                <h1 class="pack-five-player-of-match__element pack-five-player-of-match__headline" style="<?= safe($templatePackElementStyle('headline')) ?>"><span>Man of</span><span>the Match</span></h1>
                                <?php if ($homeBadge !== ''): ?>
                                    <div class="pack-five-player-of-match__element pack-five-player-of-match__badge" style="<?= safe($templatePackElementStyle('club_badge')) ?>">
                                        <img src="<?= safe($homeBadge) ?>" alt="Saltcoats Victoria badge">
                                    </div>
                                <?php endif; ?>
                                <?php if ($awayBadge !== ''): ?>
                                    <div class="pack-five-player-of-match__element pack-five-player-of-match__badge" style="<?= safe($templatePackElementStyle('opponent_badge')) ?>">
                                        <img src="<?= safe($awayBadge) ?>" alt="<?= safe((string) ($match['opponent'] ?? 'Opponent')) ?> badge">
                                    </div>
                                <?php endif; ?>
                                <?php if ($packFiveCompetitionBadge !== ''): ?>
                                    <div class="pack-five-player-of-match__element pack-five-player-of-match__competition" style="<?= safe($templatePackElementStyle('sponsor')) ?>">
                                        <img src="<?= safe($packFiveCompetitionBadge) ?>" alt="<?= safe($competition) ?> badge">
                                    </div>
                                <?php endif; ?>
                                <div class="pack-five-player-of-match__element pack-five-player-of-match__photo" style="<?= safe($templatePackElementStyle('player_image')) ?>">
                                    <?php if ($playerOfMatchImage !== ''): ?>
                                        <img src="<?= safe($playerOfMatchImage) ?>" alt="<?= safe($eventPlayer) ?>">
                                    <?php else: ?>
                                        <span class="pack-five-player-of-match__photo-fallback">Player photo unavailable</span>
                                    <?php endif; ?>
                                </div>
                                <p class="pack-five-player-of-match__element pack-five-player-of-match__name" style="<?= safe($templatePackElementStyle('player_name')) ?>">
                                    <span class="pack-five-player-of-match__name-first"><?= safe($playerOfMatchFirstName) ?></span>
                                    <?php if ($playerOfMatchSurname !== ''): ?><span class="pack-five-player-of-match__name-surname"><?= safe($playerOfMatchSurname) ?></span><?php endif; ?>
                                </p>
                                <?php if ($packFivePlayerSponsors !== []): ?>
                                    <div class="pack-five-player-of-match__element pack-five-player-of-match__sponsors" style="<?= safe($templatePackElementStyle('player_sponsors')) ?>" aria-label="<?= safe($eventPlayer) ?> sponsors">
                                        <?php foreach ($packFivePlayerSponsors as $playerSponsor): ?>
                                            <span class="pack-five-goal__player-sponsor<?= !empty($playerSponsor['available']) ? ' is-available' : '' ?>">
                                                <small><?= safe((string) $playerSponsor['role']) ?></small>
                                                <?php if ($playerSponsor['logo'] !== ''): ?><img src="<?= safe((string) $playerSponsor['logo']) ?>" alt="<?= safe((string) $playerSponsor['name']) ?>"><?php endif; ?>
                                                <strong><?= safe((string) $playerSponsor['name']) ?></strong>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($isGoalGraphic): ?>
                                <div class="event-share-card__goal-layout">
                                    <p class="event-share-card__goal-player" style="<?= safe($templatePackElementStyle('player_name')) ?>"><?= safe($goalPlayerName) ?></p>
                                    <div class="event-share-card__goal-stack" style="<?= safe($templatePackElementStyle('headline')) ?>" aria-label="Goal! Goal!">
                                        <span>GOAL!</span>
                                        <span>GOAL!</span>
                                    </div>
                                    <?php if ($goalLeftBadge !== '' || $goalRightBadge !== ''): ?>
                                        <div class="event-share-card__goal-badges" style="<?= safe($templatePackElementStyle('club_badge')) ?>" aria-label="Club badges">
                                            <?php if ($goalLeftBadge !== ''): ?>
                                                <img class="event-share-card__goal-badge" src="<?= safe($goalLeftBadge) ?>" alt="Home club badge" loading="eager">
                                            <?php endif; ?>
                                            <?php if ($goalRightBadge !== ''): ?>
                                                <img class="event-share-card__goal-badge" src="<?= safe($goalRightBadge) ?>" alt="Away club badge" loading="eager">
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($isHalfTimeGraphic): ?>
                                <div class="event-share-card__half-time-layout">
                                    <h1 class="event-share-card__half-time-title" style="<?= safe($templatePackElementStyle('headline')) ?>">Half Time</h1>
                                    <div class="event-share-card__half-time-scorers" style="<?= safe($templatePackElementStyle('fixture')) ?>" aria-label="First-half goalscorers">
                                        <?php if ($halfTimeScorers === []): ?>
                                            <span>No goals</span>
                                        <?php else: ?>
                                            <?php foreach ($halfTimeScorers as $scorerLine): ?>
                                                <span><?= safe($scorerLine) ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="event-share-card__half-time-score" style="<?= safe($templatePackElementStyle('score')) ?>" aria-label="Half-time score"><?= (int) $halfTimeScore['home'] ?>-<?= (int) $halfTimeScore['away'] ?></div>
                                    <?php if ($goalLeftBadge !== '' || $goalRightBadge !== ''): ?>
                                        <div class="event-share-card__half-time-badges" style="<?= safe($templatePackElementStyle('club_badge')) ?>" aria-label="Club badges">
                                            <?php if ($goalLeftBadge !== ''): ?>
                                                <img class="event-share-card__half-time-badge" src="<?= safe($goalLeftBadge) ?>" alt="Home club badge" loading="eager">
                                            <?php endif; ?>
                                            <?php if ($goalRightBadge !== ''): ?>
                                                <img class="event-share-card__half-time-badge" src="<?= safe($goalRightBadge) ?>" alt="Away club badge" loading="eager">
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($isFullTimeGraphic): ?>
                                <div class="event-share-card__full-time-layout">
                                    <div class="event-share-card__full-time-score" style="<?= safe($templatePackElementStyle('score')) ?>" aria-label="Full-time score"><?= (int) $halfTimeScore['home'] ?>-<?= (int) $halfTimeScore['away'] ?></div>
                                    <div class="event-share-card__full-time-scorers" style="<?= safe($templatePackElementStyle('fixture')) ?>" aria-label="Match goalscorers">
                                        <?php if ($halfTimeScorers === []): ?>
                                            <span>No goals</span>
                                        <?php else: ?>
                                            <?php foreach ($halfTimeScorers as $scorerLine): ?>
                                                <span><?= safe($scorerLine) ?></span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    <h1 class="event-share-card__full-time-title" style="<?= safe($templatePackElementStyle('headline')) ?>"><span>Full</span><span>Time</span></h1>
                                    <?php if ($goalLeftBadge !== '' || $goalRightBadge !== ''): ?>
                                        <div class="event-share-card__full-time-badges" style="<?= safe($templatePackElementStyle('club_badge')) ?>" aria-label="Club badges">
                                            <?php if ($goalLeftBadge !== ''): ?>
                                                <img class="event-share-card__full-time-badge" src="<?= safe($goalLeftBadge) ?>" alt="Home club badge" loading="eager">
                                            <?php endif; ?>
                                            <?php if ($goalRightBadge !== ''): ?>
                                                <img class="event-share-card__full-time-badge" src="<?= safe($goalRightBadge) ?>" alt="Away club badge" loading="eager">
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php elseif ($isSubstitutionGraphic): ?>
                                <?php
                                $substitutionTeam = (string) ($event['team'] ?? '');
                                if ($substitutionPairs === []) {
                                    $substitutionPairs[] = [
                                        'off' => $eventPlayer,
                                        'on' => $eventSecondaryPlayer,
                                    ];
                                }
                                ?>
                                <div class="subs-graphic">
                                    <div class="subs-graphic__row subs-graphic__row--on" style="<?= safe($templatePackElementStyle('player_on')) ?>">
                                        <span class="subs-graphic__tag">On</span>
                                        <div class="subs-graphic__pairs">
                                            <?php foreach ($substitutionPairs as $substitution): ?>
                                                <?php
                                                $onName = (string) ($substitution['on'] ?? '');
                                                $onNumber = $substitutionSquadNumbers[$onName] ?? null;
                                                ?>
                                                <div class="subs-graphic__pair">
                                                    <?php if ($onNumber !== null): ?><span class="subs-graphic__number"><?= (int) $onNumber ?></span><?php endif; ?>
                                                    <span class="subs-graphic__name"><?= safe(strtoupper($onName)) ?></span>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <h1 class="subs-graphic__title" style="<?= safe($templatePackElementStyle('headline')) ?>"><span>Subs</span></h1>
                                    <span class="subs-graphic__accent subs-graphic__accent--left" aria-hidden="true">&#9650;</span>
                                    <span class="subs-graphic__accent subs-graphic__accent--right" aria-hidden="true">&#9660;</span>

                                    <div class="subs-graphic__row subs-graphic__row--off" style="<?= safe($templatePackElementStyle('player_off')) ?>">
                                        <div class="subs-graphic__pairs">
                                            <?php foreach ($substitutionPairs as $substitution): ?>
                                                <?php
                                                $offName = (string) ($substitution['off'] ?? '');
                                                $offNumber = $substitutionSquadNumbers[$offName] ?? null;
                                                ?>
                                                <div class="subs-graphic__pair subs-graphic__pair--off">
                                                    <span class="subs-graphic__name"><?= safe(strtoupper($offName)) ?></span>
                                                    <?php if ($offNumber !== null): ?><span class="subs-graphic__number"><?= (int) $offNumber ?></span><?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <span class="subs-graphic__tag">Off</span>
                                    </div>

                                    <?php if ($usePackFiveSubstitutionGraphic): ?>
                                        <?php if ($homeBadge !== '' || $awayBadge !== ''): ?>
                                            <div class="subs-graphic__badges" style="<?= safe($templatePackElementStyle('badges')) ?>" aria-label="Club badges">
                                                <?php if ($homeBadge !== ''): ?><img src="<?= safe($homeBadge) ?>" alt="Saltcoats Victoria badge"><?php endif; ?>
                                                <?php if ($awayBadge !== ''): ?><img src="<?= safe($awayBadge) ?>" alt="<?= safe((string) ($match['opponent'] ?? 'Opponent')) ?> badge"><?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($packFiveFixtureSponsors !== []): ?>
                                            <div class="subs-graphic__fixture-sponsors" style="<?= safe($templatePackElementStyle('fixture_sponsors')) ?>" aria-label="Match sponsors">
                                                <?php foreach ($packFiveFixtureSponsors as $fixtureSponsor): ?>
                                                    <small><?= safe((string) $fixtureSponsor['role']) ?></small>
                                                    <?php if ($fixtureSponsor['logo'] !== ''): ?><img src="<?= safe((string) $fixtureSponsor['logo']) ?>" alt="<?= safe((string) $fixtureSponsor['name']) ?>"><?php endif; ?>
                                                    <strong><?= safe((string) $fixtureSponsor['name']) ?></strong>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($packFiveMainSponsors !== []): ?>
                                            <div class="subs-graphic__main-sponsors" style="<?= safe($templatePackElementStyle('sponsor_logos')) ?>" aria-label="Club sponsors">
                                                <?php foreach ($packFiveMainSponsors as $mainSponsor): ?>
                                                    <img src="<?= safe((string) $mainSponsor['logo']) ?>" alt="<?= safe((string) $mainSponsor['name']) ?>">
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($packFiveCompetitionBadge !== ''): ?>
                                            <div class="subs-graphic__footer-meta" style="<?= safe($templatePackElementStyle('footer_meta')) ?>">
                                                <img src="<?= safe($packFiveCompetitionBadge) ?>" alt="<?= safe($competition) ?> badge">
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                            <div class="event-share-card__inner">
                                <?php if ($isKickoffGraphic && ($homeBadge !== '' || $awayBadge !== '')): ?>
                                    <div class="event-share-card__badges" style="<?= safe($templatePackElementStyle('club_badge')) ?>" aria-label="Match badges">
                                        <?php if ($awayBadge !== ''): ?>
                                            <img class="event-share-card__badge event-share-card__badge--away" src="<?= safe($awayBadge) ?>" alt="<?= safe((string) ($match['opponent'] ?? 'Opponent')) ?> badge" loading="eager">
                                        <?php endif; ?>
                                        <?php if ($homeBadge !== ''): ?>
                                            <img class="event-share-card__badge event-share-card__badge--home" src="<?= safe($homeBadge) ?>" alt="Saltcoats Victoria badge" loading="eager">
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!$isKickoffGraphic): ?>
                                    <p class="event-share-card__kicker">Saltcoats Victoria</p>
                                <?php endif; ?>

                                <?php if ($isPosterGraphic): ?>
                                    <div class="event-share-card__poster-copy">
                                        <div class="event-share-card__headline-stack" style="<?= safe($templatePackElementStyle('headline')) ?>" aria-label="<?= safe($headline) ?>">
                                            <span class="event-share-card__headline-main"><?= safe($headlineParts['lead']) ?></span>
                                            <?php if ($headlineParts['accent'] !== ''): ?>
                                                <span class="event-share-card__headline-accent"><?= safe($headlineParts['accent']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!$isKickoffGraphic && $meta !== ''): ?>
                                            <p class="event-share-card__meta"><?= safe($meta) ?></p>
                                        <?php endif; ?>
                                        <?php if ($eventType === 'kickoff' && $scoreLine !== ''): ?>
                                            <p class="event-share-card__meta"><?= safe($scoreLine) ?></p>
                                        <?php endif; ?>
                                        <?php if (in_array($eventType, ['yellow_card', 'red_card'], true)): ?>
                                            <div class="event-share-card__poster-event-details">
                                                <span><?= safe($eventPlayer) ?><?= $eventMinute !== '' ? ' · ' . safe($eventMinute) . "'" : '' ?></span>
                                            </div>
                                        <?php elseif ($eventType === 'substitution'): ?>
                                            <div class="event-share-card__poster-event-details">
                                                <?php if ($eventMinute !== ''): ?><span><?= safe($eventMinute) ?>'</span><?php endif; ?>
                                                <?php if ((string) ($event['team'] ?? '') === 'svfc'): ?>
                                                    <?php foreach ($substitutionPairs as $substitution): ?>
                                                        <span><strong>Off</strong> <?= safe((string) ($substitution['off'] ?? 'Unknown Player')) ?></span>
                                                        <span><strong>On</strong> <?= safe((string) ($substitution['on'] ?? 'Unknown Player')) ?></span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span><?= safe(trim((string) ($match['opponent'] ?? 'Opponent'))) ?> substitution</span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <h1 class="event-share-card__headline" style="<?= safe($templatePackElementStyle('headline')) ?>"><?= safe($headline) ?></h1>
                                    <?php if ($meta !== ''): ?>
                                        <p class="event-share-card__meta"><?= safe($meta) ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php if (!$isKickoffGraphic): ?>
                                    <div class="event-share-card__details" style="<?= safe($templatePackElementStyle('fixture')) ?>">
                                        <?php if ($scoreLine !== ''): ?>
                                            <p class="event-share-card__detail"><strong><?= safe($scoreLine) ?></strong></p>
                                        <?php endif; ?>
                                        <p class="event-share-card__detail"><?= safe($fixture) ?></p>
                                        <?php if ($competition !== ''): ?>
                                            <p class="event-share-card__detail"><?= safe($competition) ?></p>
                                        <?php endif; ?>
                                        <?php if ($matchDate !== ''): ?>
                                            <p class="event-share-card__detail"><?= safe($matchDate) ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                            </div>
                            <?php endif; ?>

                            <?php if ($isKickoffGraphic && !$usePackFiveKickOffStyleGraphic && $clubSponsors !== []): ?>
                                <div class="event-share-card__sponsor-strip" style="<?= safe($templatePackElementStyle('sponsor')) ?>" aria-label="Club sponsors">
                                    <?php foreach ($clubSponsors as $sponsor): ?>
                                        <div class="event-share-card__sponsor">
                                            <?php if (trim((string) ($sponsor['logo'] ?? '')) !== ''): ?>
                                                <img class="event-share-card__sponsor-logo" src="<?= safe(match_event_graphic_asset_url((string) ($sponsor['logo'] ?? ''))) ?>" alt="<?= safe((string) ($sponsor['name'] ?? 'Sponsor')) ?>" loading="eager">
                                            <?php else: ?>
                                                <span class="event-share-card__sponsor-name"><?= safe((string) ($sponsor['name'] ?? 'Sponsor')) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    </section>
                <?php endif; ?>
            </div>
        </main>
    <?php endif; ?>

    <?php app_render_auth_scripts($isAuthenticated); ?>
    <?php if ($isLivePreview): ?>
        <script>
            (() => {
                const card = document.querySelector('.event-share-card');
                if (!card) return;
                const resize = () => {
                    const scale = Math.min(
                        window.innerWidth / <?= $usePackFiveCanvasGraphic ? (int) $templatePackCanvasWidth : 1080 ?>,
                        window.innerHeight / <?= $usePackFiveCanvasGraphic ? (int) $templatePackCanvasHeight : 1080 ?>
                    );
                    card.style.transform = `scale(${scale})`;
                };
                window.addEventListener('resize', resize);
                resize();
            })();
        </script>
    <?php endif; ?>
</body>

</html>
