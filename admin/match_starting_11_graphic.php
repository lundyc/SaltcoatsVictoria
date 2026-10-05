<?php
$useSharedHubLayout = true;
$previewOnly = (string)($_GET['preview_only'] ?? '') === '1';

if ($useSharedHubLayout) {
          require_once __DIR__ . '/db.php';
          require_once __DIR__ . '/lib/season.php';

          $headerSeasonId = (int)($_GET['season_id'] ?? 0);
          if ($headerSeasonId <= 0) {
                    $headerSeasonId = getSelectedSeasonId($pdo);
          }
          $headerSeason = getSeasonById($pdo, $headerSeasonId);

          $pageHero = [
                    'eyebrow' => 'Fixture management',
                    'title' => 'Match Fixture',
                    'subtitle' => 'Season: ' . ($headerSeason['name'] ?? 'Unknown'),
                    'actions' => [],
          ];
          require_once __DIR__ . '/header.php';
} else {
          require_once __DIR__ . '/header.php';
}
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/fixture_tabs.php';
require_once __DIR__ . '/lib/template_pack_render.php';
require_once __DIR__ . '/social_post_settings.php';

$layoutFooter = $useSharedHubLayout ? __DIR__ . '/footer.php' : __DIR__ . '/footer.php';

function starting11GraphicFormatPlayerName(string $name): string
{
          $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
          if ($name === '') {
                    return '';
          }

          $parts = preg_split('/\s+/', $name) ?: [];
          $parts = array_values(array_filter($parts, static fn($part) => $part !== ''));
          if ($parts === []) {
                    return '';
          }

          if (count($parts) === 1) {
                    return $parts[0];
          }

          $firstName = array_shift($parts);
          $surname = implode(' ', $parts);
          $initial = strtoupper(substr((string)$firstName, 0, 1));

          return $initial . '. ' . $surname;
}

function starting11GraphicAssetUrl(string $path): string
{
          $path = trim($path);
          if ($path === '' || preg_match('#^(?:https?:)?//#i', $path) === 1 || str_contains($path, '?')) {
                    return $path;
          }

          $absolutePath = __DIR__ . '/' . ltrim($path, '/');
          $publicPath = $path;
          if (str_starts_with($path, 'uploads/')) {
                    $publicPath = '/' . $path;
          }

          if (!is_file($absolutePath)) {
                    return $publicPath;
          }

          return $publicPath . '?v=' . rawurlencode((string)filemtime($absolutePath));
}

function starting11GraphicPlayerAvatarUrl(?string $avatar): string
{
          $avatar = trim((string)$avatar);
          if ($avatar === '') {
                    return '';
          }

          return starting11GraphicAssetUrl('uploads/players/' . $avatar);
}

function starting11GraphicChunkSubstitutes(array $substitutes, int $columns): array
{
          $columns = max(1, $columns);
          $chunks = array_fill(0, $columns, []);
          $total = count($substitutes);
          if ($total === 0) {
                    return $chunks;
          }

          $perColumn = (int)ceil($total / $columns);
          for ($i = 0; $i < $columns; $i++) {
                    $chunks[$i] = array_slice($substitutes, $i * $perColumn, $perColumn);
          }

          return $chunks;
}

/**
 * @param array<string, mixed>|null $element
 */
function starting11GraphicPackElementStyle(?array $element): string
{
          if (!$element) {
                    return '';
          }
          if (array_key_exists('visible', $element) && !$element['visible']) {
                    return 'display:none;';
          }

          $declarations = ['position:absolute'];
          foreach (['x' => 'left', 'y' => 'top', 'width' => 'width', 'height' => 'height'] as $key => $property) {
                    if (isset($element[$key]) && is_numeric($element[$key])) {
                              $declarations[] = $property . ':' . max(0, (int)$element[$key]) . 'px';
                    }
          }
          if (isset($element['font_size']) && is_numeric($element['font_size'])) {
                    $declarations[] = '--pack-element-font-size:' . max(1, (int)$element['font_size']) . 'px';
                    $declarations[] = 'font-size:var(--pack-element-font-size)';
          }
          $alignment = (string)($element['text_align'] ?? '');
          if (in_array($alignment, ['left', 'center', 'right'], true)) {
                    $declarations[] = 'text-align:' . $alignment;
          }
          $colour = (string)($element['color'] ?? '');
          if (preg_match('/^#[0-9a-f]{6}$/i', $colour) === 1) {
                    $declarations[] = 'color:' . $colour;
          }
          $declarations[] = 'margin:0';

          return implode(';', $declarations) . ';';
}

function starting11GraphicTeamAbbreviation(string $name): string
{
          $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
          if ($name === '') {
                    return 'TBC';
          }

          $words = preg_split('/\s+/', strtoupper($name)) ?: [];
          $words = array_values(array_filter(array_map(
                    static function (string $word): string {
                              return preg_replace('/[^A-Z0-9]/', '', $word) ?? '';
                    },
                    $words
          ), static function (string $word): bool {
                    return $word !== '' && !in_array($word, ['FC', 'AFC', 'SC', 'THE'], true);
          }));

          if ($words === []) {
                    return strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($name)) ?? 'TBC', 0, 3));
          }

          if (count($words) >= 3) {
                    return substr(implode('', array_map(static fn(string $word): string => substr($word, 0, 1), $words)), 0, 3);
          }

          return substr($words[0], 0, 3);
}

function starting11GraphicBadgeSlug(string $club): string
{
          $club = trim($club);
          if ($club === '') {
                    return '';
          }

          $overrideFile = __DIR__ . '/data/wosfl_badge_overrides.json';
          $badgeFile = '';
          if (is_file($overrideFile)) {
                    $decoded = json_decode((string)file_get_contents($overrideFile), true);
                    if (is_array($decoded)) {
                              foreach ($decoded as $clubName => $fileName) {
                                        if (is_string($clubName) && is_string($fileName) && strcasecmp(trim($clubName), $club) === 0) {
                                                  $badgeFile = trim($fileName);
                                                  break;
                                        }
                              }
                    }
          }

          $slug = strtolower($badgeFile !== '' ? (string)pathinfo($badgeFile, PATHINFO_FILENAME) : $club);
          $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
          return trim($slug, '-');
}

function starting11GraphicBadgeUrl(string $club, bool $white): string
{
          global $pdo;
          if ($pdo instanceof PDO) {
                    $opponentBadge = matchOpponentBadgeAssetUrl($pdo, $club, $white);
                    if ($opponentBadge !== '') {
                              return $opponentBadge;
                    }
          }

          $slug = starting11GraphicBadgeSlug($club);
          if ($slug === '') {
                    return '';
          }

          $directory = __DIR__ . '/badges' . ($white ? '/white' : '');
          $exactMatches = [];
          $closeMatches = [];
          foreach (is_dir($directory) ? (glob($directory . '/*') ?: []) : [] as $path) {
                    if (!is_file($path) || !in_array(strtolower((string)pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
                              continue;
                    }

                    $fileSlug = strtolower((string)pathinfo($path, PATHINFO_FILENAME));
                    $fileSlug = trim(preg_replace('/[^a-z0-9]+/', '-', $fileSlug) ?? '', '-');
                    if ($fileSlug === $slug) {
                              $exactMatches[] = $path;
                    } elseif (str_starts_with($fileSlug, $slug . '-') || str_starts_with($slug, $fileSlug . '-')) {
                              $closeMatches[] = $path;
                    }
          }

          $matches = $exactMatches !== [] ? $exactMatches : $closeMatches;
          if ($matches === []) {
                    return '';
          }

          usort($matches, static fn(string $left, string $right): int => strlen(basename($left)) <=> strlen(basename($right)));
          $folder = $white ? 'white/' : '';
          return '/badges/' . $folder . rawurlencode(basename($matches[0])) . '?v=' . filemtime($matches[0]);
}

$seasonId = getSelectedSeasonId($pdo);
$fixtureId = (int)($_GET['fixture_id'] ?? 0);
$requestedSeasonId = (int)($_GET['season_id'] ?? 0);
$publishingPreferences = social_publishing_preferences_load();
$starting11VisualDefaults = $publishingPreferences['visuals']['starting_xi'] ?? [];
$defaultViewMode = in_array((string)($starting11VisualDefaults['layout'] ?? ''), ['list', 'grid'], true)
          ? (string)$starting11VisualDefaults['layout'] : 'list';
$viewMode = isset($_GET['view']) && in_array((string)$_GET['view'], ['list', 'grid'], true) ? (string)$_GET['view'] : $defaultViewMode;
$showNumbers = isset($_GET['show_numbers']) && $_GET['show_numbers'] === '1';
$useInitialNames = isset($_GET['use_initial_names']) && $_GET['use_initial_names'] === '1';
$showSponsorNames = isset($_GET['show_sponsor_name']) && $_GET['show_sponsor_name'] === '1';
$showSponsorGraphics = isset($_GET['show_sponsor_graphics']) && $_GET['show_sponsor_graphics'] === '1';
$useWhiteSponsorLogos = array_key_exists('use_white_sponsor_logos', $_GET)
          ? $_GET['use_white_sponsor_logos'] === '1'
          : !empty($starting11VisualDefaults['white_sponsor_logos']);
$useWhiteBadges = array_key_exists('use_white_badges', $_GET)
          ? $_GET['use_white_badges'] === '1'
          : !empty($starting11VisualDefaults['white_badges']);
if ($requestedSeasonId > 0) {
          $seasonId = $requestedSeasonId;
}

$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;
if (!$fixture) {
          echo '<div><div class="alert alert-warning">Fixture not found.</div></div>';
          require_once $layoutFooter;
          exit;
}

$fixture = matchStarting11FixtureState($fixture);
$templatePackContext = matchTemplatePackContext($pdo, $fixtureId, 'starting_xi', true);

// The authenticated graphic workspace is also the live preview for the pack
// editor. Prefer the latest saved draft for the assigned pack so a saved move
// or resize is visible immediately, without weakening published-version
// locking in background/automated renderers.
$resolvedPackId = (int)($templatePackContext['pack']['id'] ?? 0);
if ($resolvedPackId > 0) {
          try {
                    $latestSavedDraft = template_packs_get_draft($pdo, $resolvedPackId);
                    $draftAction = isset($latestSavedDraft['actions']['starting_xi']) && is_array($latestSavedDraft['actions']['starting_xi'])
                              ? $latestSavedDraft['actions']['starting_xi']
                              : null;
                    if ($draftAction !== null) {
                              $templatePackContext['version'] = $latestSavedDraft;
                              $templatePackContext['brand_settings'] = isset($latestSavedDraft['brand_settings']) && is_array($latestSavedDraft['brand_settings'])
                                        ? $latestSavedDraft['brand_settings']
                                        : $templatePackContext['brand_settings'];
                              $templatePackContext['action'] = $draftAction;
                    }
          } catch (Throwable $draftError) {
                    error_log('Starting XI draft preview resolution failed: ' . $draftError->getMessage());
          }
}

$templatePackBrand = isset($templatePackContext['brand_settings']) && is_array($templatePackContext['brand_settings'])
          ? $templatePackContext['brand_settings'] : [];
$templatePackLayout = matchTemplatePackLayout($templatePackContext);
$templatePackElements = isset($templatePackLayout['elements']) && is_array($templatePackLayout['elements'])
          ? $templatePackLayout['elements'] : [];
if ((int)($templatePackContext['pack']['id'] ?? 0) === 5 && !isset($templatePackElements['sponsor_logos'])) {
          $templatePackElements['sponsor_logos'] = [
                    'label' => 'Sponsor logos',
                    'x' => 80,
                    'y' => 1245,
                    'width' => 780,
                    'height' => 70,
                    'font_size' => 16,
                    'text_align' => 'center',
                    'color' => '#ffffff',
                    'visible' => true,
          ];
}
$starting11SocialCaptions = [];
foreach (['facebook', 'instagram', 'x'] as $socialChannel) {
          $starting11SocialCaptions[$socialChannel] = social_post_resolve_caption($socialChannel, 'starting_xi', $fixture);
}
$seasonId = (int)$fixture['season_id'];
$fixtureLabel = ($fixture['is_home'] ? 'Home v ' : 'Away v ') . (string)$fixture['opponent'];
$templatePackBackgroundImage = matchTemplatePackAssetUrl((string)($templatePackContext['action']['background_path'] ?? ''));
$fixtureBackgroundImage = trim((string)($fixture['starting11_background_image'] ?? ''));
$backgroundImage = $fixtureBackgroundImage !== ''
          ? $fixtureBackgroundImage
          : $templatePackBackgroundImage;
$templatePackPrimary = matchTemplatePackColour($templatePackBrand, 'primary', '#7b2238');
$templatePackSecondary = matchTemplatePackColour($templatePackBrand, 'secondary', '#68172b');
$templatePackAccent = matchTemplatePackColour($templatePackBrand, 'accent', '#f0c11a');
$templatePackText = matchTemplatePackColour($templatePackBrand, 'text', '#ffffff');
$templatePackHeadingFont = matchTemplatePackFont($templatePackBrand, 'heading', 'Futura');
$templatePackBodyFont = matchTemplatePackFont($templatePackBrand, 'body', 'Futura');
$templatePackCanvasWidth = max(320, min(4096, (int)($templatePackLayout['canvas_width'] ?? 1080)));
$templatePackCanvasHeight = max(320, min(4096, (int)($templatePackLayout['canvas_height'] ?? 1080)));
$templatePackBackgroundFitCandidate = (string)($templatePackLayout['background_fit'] ?? 'cover');
$templatePackBackgroundFit = in_array($templatePackBackgroundFitCandidate, ['cover', 'contain', 'stretch'], true)
          ? $templatePackBackgroundFitCandidate : 'cover';
$templatePackBackgroundSize = $templatePackBackgroundFit === 'stretch' ? '100% 100%' : $templatePackBackgroundFit;
$templatePackGradientColour = matchTemplatePackColour(
          ['gradient_color' => (string)($templatePackLayout['gradient_color'] ?? '')],
          'gradient',
          '#000000'
);
$templatePackGradientStrength = max(0, min(100, (int)($templatePackLayout['gradient_strength'] ?? 0)));
if (!array_key_exists('use_white_badges', $_GET) && array_key_exists('use_white_badges', $templatePackBrand)) {
          $useWhiteBadges = !empty($templatePackBrand['use_white_badges']);
}
if (!array_key_exists('show_sponsor_name', $_GET) && array_key_exists('show_sponsor_names', $templatePackBrand)) {
          $showSponsorNames = !empty($templatePackBrand['show_sponsor_names']);
}
$starters = matchStarting11PrepareLineup(matchStarting11PrepareStarterSlots($fixture['starting11_starters'] ?? []));
$substitutes = matchStarting11PrepareLineup($fixture['starting11_substitutes'] ?? []);
$captain = trim((string)($fixture['starting11_captain'] ?? ''));
$displayStarters = array_map(static fn(string $name): string => matchStarting11PublicPlayerName($pdo, $name), $starters);
$displaySubstitutes = array_map(static fn(string $name): string => matchStarting11PublicPlayerName($pdo, $name), $substitutes);
$formattedStarters = array_map('starting11GraphicFormatPlayerName', $displayStarters);
$formattedSubstitutes = array_map('starting11GraphicFormatPlayerName', $displaySubstitutes);
$substitutesLine = implode(' / ', $formattedSubstitutes);
$fullSubstitutesLine = implode(' / ', $displaySubstitutes);
$substituteColumns = starting11GraphicChunkSubstitutes($displaySubstitutes, 3);
$saltcoatsName = 'Saltcoats Victoria';
$opponentTeamName = trim((string)($fixture['opponent'] ?? ''));
$saltcoatsAbbr = 'SVC';
$opponentTeamAbbr = trim((string)($fixture['opponent_abbreviation'] ?? '')) !== ''
          ? strtoupper(trim((string)$fixture['opponent_abbreviation']))
          : starting11GraphicTeamAbbreviation($opponentTeamName);
$homeTeamName = (int)$fixture['is_home'] === 1 ? $saltcoatsName : $opponentTeamName;
$awayTeamName = (int)$fixture['is_home'] === 1 ? $opponentTeamName : $saltcoatsName;
$homeTeamAbbr = (int)$fixture['is_home'] === 1 ? $saltcoatsAbbr : $opponentTeamAbbr;
$awayTeamAbbr = (int)$fixture['is_home'] === 1 ? $opponentTeamAbbr : $saltcoatsAbbr;
$homeColourBadgeUrl = starting11GraphicBadgeUrl($homeTeamName, false);
$awayColourBadgeUrl = starting11GraphicBadgeUrl($awayTeamName, false);
$homeWhiteBadgeUrl = starting11GraphicBadgeUrl($homeTeamName, true);
$awayWhiteBadgeUrl = starting11GraphicBadgeUrl($awayTeamName, true);
$homeColourBadgeUrl = $homeColourBadgeUrl !== '' ? $homeColourBadgeUrl : $homeWhiteBadgeUrl;
$awayColourBadgeUrl = $awayColourBadgeUrl !== '' ? $awayColourBadgeUrl : $awayWhiteBadgeUrl;
$homeWhiteBadgeUrl = $homeWhiteBadgeUrl !== '' ? $homeWhiteBadgeUrl : $homeColourBadgeUrl;
$awayWhiteBadgeUrl = $awayWhiteBadgeUrl !== '' ? $awayWhiteBadgeUrl : $awayColourBadgeUrl;
$homeBadgeUrl = $useWhiteBadges ? $homeWhiteBadgeUrl : $homeColourBadgeUrl;
$awayBadgeUrl = $useWhiteBadges ? $awayWhiteBadgeUrl : $awayColourBadgeUrl;
$saltcoatsWhiteBadgeUrl = (int)$fixture['is_home'] === 1 ? $homeWhiteBadgeUrl : $awayWhiteBadgeUrl;
$opponentWhiteBadgeUrl = (int)$fixture['is_home'] === 1 ? $awayWhiteBadgeUrl : $homeWhiteBadgeUrl;
$squadNumbers = isset($fixture['starting11_squad_numbers']) && is_array($fixture['starting11_squad_numbers'])
          ? $fixture['starting11_squad_numbers'] : [];
$useTemplatePackRenderer = $templatePackElements !== [] && !empty($templatePackContext['action']);

$competitionBadgeUrl = '';
$competitionName = trim((string)($fixture['competition'] ?? ''));
if ($competitionName !== '') {
          $competitionRow = getMatchCompetitionByName($pdo, $competitionName);
          if (!$competitionRow || trim((string)($competitionRow['white_badge_image'] ?? '')) === '') {
                    foreach (getMatchCompetitions($pdo) as $competitionOption) {
                              $optionName = trim((string)($competitionOption['name'] ?? ''));
                              $optionWhiteBadge = trim((string)($competitionOption['white_badge_image'] ?? ''));
                              if ($optionWhiteBadge !== '' && (
                                        stripos($optionName, $competitionName) !== false
                                        || stripos($competitionName, $optionName) !== false
                              )) {
                                        $competitionRow = $competitionOption;
                                        break;
                              }
                    }
          }
          $competitionBadgePath = trim((string)($competitionRow['white_badge_image'] ?? $competitionRow['badge_image'] ?? ''));
          if ($competitionBadgePath !== '') {
                    $competitionBadgeUrl = starting11GraphicAssetUrl($competitionBadgePath);
          }
}

$fixtureSponsorRows = getMatchSponsorshipRows($pdo, $fixtureId);
$graphicSponsors = getActiveMainSponsorAgreements($pdo, $seasonId, (string)$fixture['match_date']);
$matchDaySponsor = null;
$matchdayBallSponsors = [];
$matchdayBallSponsorRoles = [];
foreach ($fixtureSponsorRows as $row) {
          if ($matchDaySponsor === null && (string)($row['sponsorship_role'] ?? '') === 'match_day') {
                    $matchDaySponsor = [
                              'name' => (string)($row['sponsor_name'] ?? ''),
                              'logo_path' => (string)($row['sponsor_logo'] ?? ''),
                    ];
          }
          $rowRole = (string)($row['sponsorship_role'] ?? '');
          if (
                    in_array($rowRole, ['match_day', 'match_ball'], true)
                    && !isset($matchdayBallSponsorRoles[$rowRole])
                    && matchSponsorshipCanBeDisplayed($row)
          ) {
                    $matchdayBallSponsorRoles[$rowRole] = true;
                    $matchdayBallSponsors[] = [
                              'name' => (string)($row['sponsor_name'] ?? ''),
                              'logo_path' => (string)($row['sponsor_logo'] ?? ''),
                              'white_logo_path' => (string)($row['sponsor_white_logo'] ?? ''),
                    ];
          }
}

$starterPlayerIds = [];
$starterPlayerMetaByName = [];
if ($starters !== []) {
          $playerStmt = $pdo->prepare("
    SELECT id, avatar
    FROM players
    WHERE name = :name
    LIMIT 1
  ");
          foreach ($starters as $starter) {
                    $playerStmt->execute([':name' => $starter]);
                    $playerRow = $playerStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    $playerId = (int)($playerRow['id'] ?? 0);
                    if ($playerId > 0) {
                              $starterPlayerIds[$starter] = $playerId;
                              $starterPlayerMetaByName[$starter] = [
                                        'id' => $playerId,
                                        'avatar' => (string)($playerRow['avatar'] ?? ''),
                              ];
                    }
          }
}

$playerSponsorsByName = [];
if ($starterPlayerIds !== []) {
          $idsSql = implode(',', array_map('intval', array_values($starterPlayerIds)));
          $playerSponsorStmt = $pdo->prepare("
    SELECT sp.player_id, sp.slot, s.name AS sponsor_name, s.logo_path
    FROM sponsorships sp
    JOIN sponsors s ON s.id = sp.sponsor_id
    WHERE sp.season_id = :season_id
      AND sp.ended_at IS NULL
      AND sp.player_id IN ($idsSql)
    ORDER BY sp.player_id ASC, FIELD(UPPER(sp.slot), 'HOME', 'AWAY', 'THIRD'), s.name ASC
  ");
          $playerSponsorStmt->execute([':season_id' => $seasonId]);
          $playerSponsorRows = $playerSponsorStmt->fetchAll(PDO::FETCH_ASSOC);

          $nameByPlayerId = array_flip($starterPlayerIds);
          foreach ($playerSponsorRows as $row) {
                    $playerId = (int)($row['player_id'] ?? 0);
                    $playerName = $nameByPlayerId[$playerId] ?? null;
                    if ($playerName === null) {
                              continue;
                    }
                    $playerSponsorsByName[$playerName][] = [
                              'slot' => strtolower((string)($row['slot'] ?? '')),
                              'name' => (string)($row['sponsor_name'] ?? ''),
                              'logo_path' => (string)($row['logo_path'] ?? ''),
                    ];
          }
}

if ($previewOnly && ob_get_level() > 0) {
          ob_clean();
}
?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&amp;family=Montserrat:wght@400;600;700;800&amp;family=Oswald:wght@400;600;700&amp;family=Roboto:wght@400;500;700&amp;family=Roboto+Condensed:wght@400;600;700&amp;display=swap">
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<?php
require_once __DIR__ . '/lib/dynamic_styles.php';
ob_start();
require __DIR__ . '/assets/css/player-sponsors-match-starting-11-graphic.dynamic.php';
$hubDynamicCss1 = (string) ob_get_clean();
$hubDynamicStyleHref1 = hub_dynamic_stylesheet_register($hubDynamicCss1);
?>
<link rel="stylesheet" href="<?= htmlspecialchars($hubDynamicStyleHref1, ENT_QUOTES, 'UTF-8') ?>">

<?php if ($previewOnly): ?>
<link rel="stylesheet" href="/admin/assets/css/player-sponsors-match-starting-11-graphic.css">
<?php endif; ?>

<div class="<?= $previewOnly ? 'starting11-graphic-page starting11-graphic-page--preview' : 'starting11-graphic-page' ?>">
  <?php if (!$previewOnly): ?>
  <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/matches.php">Fixtures</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><a href="/admin/match_starting_11.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>">Starting 11</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Graphic</span></nav>
  <?php if (!$useSharedHubLayout): ?>
  <div class="page-hero mb-4">
    <div class="page-hero-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
      <div>
        <div class="page-hero-eyebrow">Generated artwork</div>
        <h1 class="page-hero-title">Starting 11 Graphic</h1>
        <p class="page-hero-subtitle"><?= h($fixtureLabel) ?></p>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php renderFixtureTabs((int)$fixtureId, (int)$seasonId, 'starting11'); ?>

  <?php if ($starters === []): ?>
    <div class="alert alert-warning">No starters have been selected for this fixture yet.</div>
  <?php endif; ?>

  <div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="fixture_id" value="<?= (int)$fixtureId ?>">
        <input type="hidden" name="season_id" value="<?= (int)$seasonId ?>">
        <input type="hidden" name="view" id="graphicViewMode" value="<?= h($viewMode) ?>">
        <div class="col-12">
          <div class="d-flex flex-column flex-xl-row align-items-stretch align-items-xl-center justify-content-xl-between gap-3">
            <div class="d-flex flex-wrap align-items-center gap-3">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="showNumbersToggle" name="show_numbers" value="1" <?= $showNumbers ? 'checked' : '' ?>>
                <label class="form-check-label" for="showNumbersToggle">Show player numbers</label>
              </div>
              <div class="form-check">
                <input class="form-check-input" type="checkbox" id="useInitialNamesToggle" name="use_initial_names" value="1" <?= $useInitialNames ? 'checked' : '' ?>>
                <label class="form-check-label" for="useInitialNamesToggle">Use first-name initial</label>
              </div>
              <?php if ($viewMode === 'grid'): ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="showSponsorNameToggle" name="show_sponsor_name" value="1" <?= $showSponsorNames ? 'checked' : '' ?> onchange="this.form.submit()">
                  <label class="form-check-label" for="showSponsorNameToggle">Show sponsor name</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="showSponsorGraphicsToggle" name="show_sponsor_graphics" value="1" <?= $showSponsorGraphics ? 'checked' : '' ?> onchange="this.form.submit()">
                  <label class="form-check-label" for="showSponsorGraphicsToggle">Show sponsor graphics</label>
                </div>
                <div class="form-check">
                  <input type="hidden" name="use_white_badges" value="0">
                  <input class="form-check-input" type="checkbox" id="useWhiteBadgesToggle" name="use_white_badges" value="1" <?= $useWhiteBadges ? 'checked' : '' ?>>
                  <label class="form-check-label" for="useWhiteBadgesToggle">Use white badges</label>
                </div>
              <?php endif; ?>
              <?php if ($graphicSponsors !== [] && $viewMode !== 'grid'): ?>
                <div class="form-check">
                  <input type="hidden" name="use_white_sponsor_logos" value="0">
                  <input class="form-check-input" type="checkbox" id="useWhiteSponsorLogosToggle" name="use_white_sponsor_logos" value="1" <?= $useWhiteSponsorLogos ? 'checked' : '' ?>>
                  <label class="form-check-label" for="useWhiteSponsorLogosToggle">Use white sponsor logos</label>
                </div>
              <?php endif; ?>
            </div>
            <div class="btn-group" role="group" aria-label="Graphic view">
              <button type="button" class="btn btn-sm <?= $viewMode === 'list' ? 'btn-brand' : 'btn-outline-secondary' ?>" onclick="document.getElementById('graphicViewMode').value='list'; this.form.submit();" title="List view" aria-label="List view" aria-pressed="<?= $viewMode === 'list' ? 'true' : 'false' ?>">
                <i class="fa-solid fa-list" aria-hidden="true"></i>
              </button>
              <button type="button" class="btn btn-sm <?= $viewMode === 'grid' ? 'btn-brand' : 'btn-outline-secondary' ?>" onclick="document.getElementById('graphicViewMode').value='grid'; this.form.submit();" title="Grid view" aria-label="Grid view" aria-pressed="<?= $viewMode === 'grid' ? 'true' : 'false' ?>">
                <i class="fa-solid fa-table-cells-large" aria-hidden="true"></i>
              </button>
            </div>
            <div class="d-flex flex-wrap gap-2" role="group" aria-label="Graphic actions">
              <button id="downloadStarting11GraphicBtn" type="button" class="btn btn-brand" title="Download PNG" aria-label="Download PNG">
                <i class="fa-solid fa-download" aria-hidden="true"></i>
              </button>
              <button type="button" class="btn btn-outline-primary js-post-starting11" data-platform="facebook" title="Post to Facebook" aria-label="Post to Facebook">
                <i class="fa-brands fa-facebook" aria-hidden="true"></i>
              </button>
              <button type="button" class="btn btn-outline-primary js-post-starting11" data-platform="instagram" title="Post to Instagram" aria-label="Post to Instagram">
                <i class="fa-brands fa-instagram" aria-hidden="true"></i>
              </button>
              <button type="button" class="btn btn-outline-dark js-post-starting11" data-platform="x" title="Post to Twitter/X" aria-label="Post to Twitter/X">
                <i class="fa-brands fa-x-twitter" aria-hidden="true"></i>
              </button>
            </div>
          </div>
          <div class="mt-3">
            <label class="form-label fw-semibold" for="starting11SocialCaption">Post text</label>
            <textarea class="form-control" id="starting11SocialCaption" rows="3"><?= h($starting11SocialCaptions['facebook']) ?></textarea>
            <div class="form-text">The saved platform template loads automatically. You can edit it before publishing.</div>
          </div>
          <div id="starting11SocialStatus" class="alert d-none mt-3 mb-0" role="status" aria-live="polite"></div>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <section class="starting11-graphic-preview-wrap">
    <div id="starting11GraphicCapture">
        <div class="starting11-graphic-card<?= $useTemplatePackRenderer ? ' starting11-graphic-card--template-pack' : '' ?>" <?= $backgroundImage !== '' ? ' style="background-image:url(\'' . h(starting11GraphicAssetUrl($backgroundImage)) . '\')"' : '' ?>>
          <div class="starting11-graphic-card__backdrop"></div>
          <div class="starting11-graphic-card__pattern"></div>
          <?php if ($useTemplatePackRenderer): ?>
            <?php
              $headlineElement = isset($templatePackElements['headline']) && is_array($templatePackElements['headline']) ? $templatePackElements['headline'] : null;
              $clubBadgeElement = isset($templatePackElements['club_badge']) && is_array($templatePackElements['club_badge']) ? $templatePackElements['club_badge'] : null;
              $opponentBadgeElement = isset($templatePackElements['opponent_badge']) && is_array($templatePackElements['opponent_badge']) ? $templatePackElements['opponent_badge'] : null;
              $lineupElement = isset($templatePackElements['lineup']) && is_array($templatePackElements['lineup']) ? $templatePackElements['lineup'] : null;
              $substitutesElement = isset($templatePackElements['substitutes']) && is_array($templatePackElements['substitutes']) ? $templatePackElements['substitutes'] : null;
              $sponsorLogosElement = isset($templatePackElements['sponsor_logos']) && is_array($templatePackElements['sponsor_logos']) ? $templatePackElements['sponsor_logos'] : null;
              $matchdaySponsorsElement = isset($templatePackElements['matchday_sponsors']) && is_array($templatePackElements['matchday_sponsors']) ? $templatePackElements['matchday_sponsors'] : null;
              $competitionElement = isset($templatePackElements['sponsor']) && is_array($templatePackElements['sponsor']) ? $templatePackElements['sponsor'] : null;
            ?>
            <div class="starting11-template-element starting11-template-headline" style="<?= h(starting11GraphicPackElementStyle($headlineElement)) ?>">
              STARTING XI V <?= h(strtoupper($opponentTeamName)) ?>
            </div>
            <?php if ($saltcoatsWhiteBadgeUrl !== ''): ?>
              <div class="starting11-template-element starting11-template-badge" style="<?= h(starting11GraphicPackElementStyle($clubBadgeElement)) ?>">
                <img src="<?= h($saltcoatsWhiteBadgeUrl) ?>" alt="Saltcoats Victoria FC badge" loading="eager">
              </div>
            <?php endif; ?>
            <?php if ($opponentWhiteBadgeUrl !== ''): ?>
              <div class="starting11-template-element starting11-template-badge" style="<?= h(starting11GraphicPackElementStyle($opponentBadgeElement)) ?>">
                <img src="<?= h($opponentWhiteBadgeUrl) ?>" alt="<?= h($opponentTeamName) ?> badge" loading="eager">
              </div>
            <?php endif; ?>
            <section class="starting11-template-element starting11-template-lineup" style="<?= h(starting11GraphicPackElementStyle($lineupElement)) ?>" aria-label="Starting lineup">
              <?php foreach ($starters as $index => $starter): ?>
                <?php if (trim((string)$starter) === '') { continue; } ?>
                <?php $displayStarter = $displayStarters[$index] ?? $starter; ?>
                <div class="starting11-template-player">
                  <span class="starting11-template-player__number js-starting11-player-number<?= $showNumbers ? '' : ' d-none' ?>"><?= h(str_pad((string)($squadNumbers[$starter] ?? ($index + 1)), 2, '0', STR_PAD_LEFT)) ?></span>
                  <span
                    class="starting11-template-player__name js-starting11-player-name"
                    data-full-name="<?= h(strtoupper($displayStarter)) ?>"
                    data-initial-name="<?= h(strtoupper(starting11GraphicFormatPlayerName($displayStarter))) ?>"
                  ><?= h(strtoupper($useInitialNames ? starting11GraphicFormatPlayerName($displayStarter) : $displayStarter)) ?></span>
                  <?php if ($captain !== '' && $captain === $starter): ?>
                    <em class="starting11-template-player__captain">C</em>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </section>
            <section class="starting11-template-element starting11-template-subs" style="<?= h(starting11GraphicPackElementStyle($substitutesElement)) ?>" aria-label="Substitutes">
              <strong>SUBSTITUTES</strong>
              <span
                class="js-starting11-player-name"
                data-full-name="<?= h(strtoupper(implode(', ', $displaySubstitutes))) ?>"
                data-initial-name="<?= h(strtoupper(implode(', ', $formattedSubstitutes))) ?>"
              ><?= h(strtoupper(implode(', ', $useInitialNames ? $formattedSubstitutes : $displaySubstitutes))) ?></span>
            </section>
            <?php if ($graphicSponsors !== []): ?>
              <section class="starting11-template-element starting11-template-sponsors" style="<?= h(starting11GraphicPackElementStyle($sponsorLogosElement)) ?>" aria-label="Club sponsors">
                <?php foreach ($graphicSponsors as $sponsor): ?>
                  <?php
                    $colourSponsorLogo = trim((string)($sponsor['logo_path'] ?? ''));
                    $whiteSponsorLogo = trim((string)($sponsor['white_logo_path'] ?? ''));
                    $selectedSponsorLogo = $useWhiteSponsorLogos && $whiteSponsorLogo !== ''
                              ? $whiteSponsorLogo
                              : ($colourSponsorLogo !== '' ? $colourSponsorLogo : $whiteSponsorLogo);
                  ?>
                  <?php if ($selectedSponsorLogo !== ''): ?>
                    <img
                      class="js-starting11-sponsor-logo"
                      src="<?= h(starting11GraphicAssetUrl('uploads/sponsors/' . $selectedSponsorLogo)) ?>"
                      data-colour="<?= h(starting11GraphicAssetUrl('uploads/sponsors/' . ($colourSponsorLogo !== '' ? $colourSponsorLogo : $whiteSponsorLogo))) ?>"
                      data-white="<?= h(starting11GraphicAssetUrl('uploads/sponsors/' . ($whiteSponsorLogo !== '' ? $whiteSponsorLogo : $colourSponsorLogo))) ?>"
                      alt="<?= h((string)($sponsor['name'] ?? 'Sponsor')) ?>"
                      loading="eager"
                    >
                  <?php elseif ($showSponsorNames): ?>
                    <span class="starting11-template-sponsors__name"><?= h(strtoupper((string)($sponsor['name'] ?? ''))) ?></span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </section>
            <?php endif; ?>
            <?php if ($matchdayBallSponsors !== [] && $matchdaySponsorsElement !== null): ?>
              <section class="starting11-template-element starting11-template-sponsors starting11-template-matchday-sponsors" style="<?= h(starting11GraphicPackElementStyle($matchdaySponsorsElement)) ?>" aria-label="Matchday and matchball sponsors">
                <?php foreach ($matchdayBallSponsors as $sponsor): ?>
                  <?php
                    $colourSponsorLogo = trim((string)($sponsor['logo_path'] ?? ''));
                    $whiteSponsorLogo = trim((string)($sponsor['white_logo_path'] ?? ''));
                    $selectedSponsorLogo = $useWhiteSponsorLogos && $whiteSponsorLogo !== ''
                              ? $whiteSponsorLogo
                              : ($colourSponsorLogo !== '' ? $colourSponsorLogo : $whiteSponsorLogo);
                  ?>
                  <?php if ($selectedSponsorLogo !== ''): ?>
                    <img
                      class="js-starting11-sponsor-logo"
                      src="<?= h(starting11GraphicAssetUrl('uploads/sponsors/' . $selectedSponsorLogo)) ?>"
                      data-colour="<?= h(starting11GraphicAssetUrl('uploads/sponsors/' . ($colourSponsorLogo !== '' ? $colourSponsorLogo : $whiteSponsorLogo))) ?>"
                      data-white="<?= h(starting11GraphicAssetUrl('uploads/sponsors/' . ($whiteSponsorLogo !== '' ? $whiteSponsorLogo : $colourSponsorLogo))) ?>"
                      alt="<?= h((string)($sponsor['name'] ?? 'Sponsor')) ?>"
                      loading="eager"
                    >
                  <?php elseif ($showSponsorNames): ?>
                    <span class="starting11-template-sponsors__name"><?= h(strtoupper((string)($sponsor['name'] ?? ''))) ?></span>
                  <?php endif; ?>
                <?php endforeach; ?>
              </section>
            <?php endif; ?>
            <?php if ($competitionBadgeUrl !== ''): ?>
              <div class="starting11-template-element starting11-template-competition" style="<?= h(starting11GraphicPackElementStyle($competitionElement)) ?>">
                <img src="<?= h($competitionBadgeUrl) ?>" alt="<?= h($competitionName) ?> badge" loading="eager">
              </div>
            <?php endif; ?>
          <?php else: ?>
          <div class="starting11-graphic-card__content<?= $viewMode === 'grid' ? ' starting11-graphic-card__content--grid' : '' ?>">
            <?php if ($viewMode !== 'grid'): ?>
              <header class="starting11-graphic-card__header" style="<?= h(starting11GraphicPackElementStyle(isset($templatePackElements['headline']) && is_array($templatePackElements['headline']) ? $templatePackElements['headline'] : null)) ?>">
                <div class="starting11-graphic-card__title">
                  <span class="starting11-graphic-card__title-main">STARTING</span>
                  <span class="starting11-graphic-card__title-accent">XI</span>
                </div>
                <div class="starting11-graphic-card__meta">
                  <span class="starting11-graphic-card__line"></span>
                  <span class="starting11-graphic-card__opponent"><?= h(strtoupper('VS ' . (string)$fixture['opponent'])) ?></span>
                </div>
              </header>
            <?php endif; ?>

            <?php if ($viewMode === 'grid'): ?>
              <section class="starting11-graphic-card__lineup starting11-graphic-card__lineup--grid" style="<?= h(starting11GraphicPackElementStyle(isset($templatePackElements['lineup']) && is_array($templatePackElements['lineup']) ? $templatePackElements['lineup'] : null)) ?>" aria-label="Starting lineup">
                <article class="starting11-graphic-card__grid-intro" aria-label="Fixture heading">
                  <div class="starting11-graphic-card__grid-intro-inner">
                    <div class="starting11-graphic-card__grid-intro-badge-wrap">
                      <?php if ($homeBadgeUrl !== ''): ?>
                        <img class="starting11-graphic-card__grid-intro-badge js-starting11-team-badge" src="<?= h($homeBadgeUrl) ?>" data-colour="<?= h($homeColourBadgeUrl) ?>" data-white="<?= h($homeWhiteBadgeUrl) ?>" alt="<?= h($homeTeamName) ?> badge" loading="eager">
                      <?php endif; ?>
                      <?php if ($awayBadgeUrl !== ''): ?>
                        <img class="starting11-graphic-card__grid-intro-badge js-starting11-team-badge" src="<?= h($awayBadgeUrl) ?>" data-colour="<?= h($awayColourBadgeUrl) ?>" data-white="<?= h($awayWhiteBadgeUrl) ?>" alt="<?= h($awayTeamName) ?> badge" loading="eager">
                      <?php endif; ?>
                    </div>
                    <div class="starting11-graphic-card__grid-intro-score" aria-label="<?= h($homeTeamName) ?> versus <?= h($awayTeamName) ?>">
                      <span class="starting11-graphic-card__grid-intro-abbr starting11-graphic-card__grid-intro-abbr--home"><?= h($homeTeamAbbr) ?></span>
                      <span class="starting11-graphic-card__grid-intro-vs">VS</span>
                      <span class="starting11-graphic-card__grid-intro-abbr starting11-graphic-card__grid-intro-abbr--away"><?= h($awayTeamAbbr) ?></span>
                    </div>
                    <?php if ($matchDaySponsor !== null): ?>
                      <div class="starting11-graphic-card__grid-intro-sponsor">
                        <?php if (trim((string)($matchDaySponsor['logo_path'] ?? '')) !== ''): ?>
                          <img class="starting11-graphic-card__grid-intro-sponsor-logo" src="<?= h(starting11GraphicAssetUrl('uploads/sponsors/' . (string)$matchDaySponsor['logo_path'])) ?>" alt="<?= h((string)$matchDaySponsor['name']) ?>" loading="eager">
                        <?php else: ?>
                          <div class="starting11-graphic-card__grid-intro-sponsor-text"><?= h(strtoupper((string)$matchDaySponsor['name'])) ?></div>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <div class="starting11-graphic-card__grid-intro-opponent"><?= h(strtoupper($homeTeamName . ' VS ' . $awayTeamName)) ?></div>
                    <?php endif; ?>
                  </div>
                </article>
                <?php foreach ($starters as $index => $starter): ?>
                  <?php $displayStarter = $displayStarters[$index] ?? $starter; ?>
                  <?php $starterSponsors = $playerSponsorsByName[$starter] ?? []; ?>
                  <?php $starterMeta = $starterPlayerMetaByName[$starter] ?? ['avatar' => '']; ?>
                  <?php $avatarUrl = starting11GraphicPlayerAvatarUrl((string)($starterMeta['avatar'] ?? '')); ?>
                  <article class="starting11-graphic-card__grid-player">
                    <div class="starting11-graphic-card__grid-number js-starting11-player-number<?= $showNumbers ? '' : ' d-none' ?>"><?= (int)($index + 1) ?></div>
                    <div class="starting11-graphic-card__grid-photo-wrap">
                      <?php if ($avatarUrl !== ''): ?>
                        <img class="starting11-graphic-card__grid-photo" src="<?= h($avatarUrl) ?>" alt="<?= h($displayStarter) ?>" loading="lazy">
                      <?php else: ?>
                        <div class="starting11-graphic-card__grid-photo-placeholder">
                          <i class="fa-solid fa-user"></i>
                        </div>
                      <?php endif; ?>
                    </div>
                    <?php if ($showSponsorNames && !empty($starterSponsors[0]['name'])): ?>
                      <div class="starting11-graphic-card__grid-player-name-band"><?= h(strtoupper((string)($starterSponsors[0]['name'] ?? ''))) ?></div>
                    <?php endif; ?>
                    <div class="starting11-graphic-card__grid-name<?= $index % 2 === 1 ? ' starting11-graphic-card__grid-name--alt' : '' ?>">
                      <span
                        class="js-starting11-player-name"
                        data-full-name="<?= h(strtoupper($displayStarter)) ?>"
                        data-initial-name="<?= h(strtoupper($formattedStarters[$index] ?? $displayStarter)) ?>"
                      ><?= h(strtoupper($useInitialNames ? ($formattedStarters[$index] ?? $displayStarter) : $displayStarter)) ?></span>
                      <?php if ($captain !== '' && $captain === $starter): ?>
                        <span class="starting11-graphic-card__captain" aria-hidden="true"></span>
                      <?php endif; ?>
                    </div>
                  </article>
                <?php endforeach; ?>
                <article class="starting11-graphic-card__grid-subs" aria-label="Substitutes">
                  <h2 class="starting11-graphic-card__grid-subs-title">Subs</h2>
                </article>
                <?php foreach ($substituteColumns as $subsColumn): ?>
                  <article class="starting11-graphic-card__grid-subs-list-card" aria-label="Substitutes list">
                    <div class="starting11-graphic-card__grid-subs-list">
                      <?php foreach ($subsColumn as $subName): ?>
                        <span
                          class="starting11-graphic-card__grid-subs-list-item js-starting11-player-name"
                          data-full-name="<?= h(strtoupper($subName)) ?>"
                          data-initial-name="<?= h(strtoupper(starting11GraphicFormatPlayerName($subName))) ?>"
                        ><?= h(strtoupper($useInitialNames ? starting11GraphicFormatPlayerName($subName) : $subName)) ?></span>
                      <?php endforeach; ?>
                    </div>
                  </article>
                <?php endforeach; ?>
              </section>
            <?php else: ?>
              <section class="starting11-graphic-card__lineup" style="<?= h(starting11GraphicPackElementStyle(isset($templatePackElements['lineup']) && is_array($templatePackElements['lineup']) ? $templatePackElements['lineup'] : null)) ?>" aria-label="Starting lineup">
                <?php foreach ($starters as $index => $starter): ?>
                  <?php $displayStarter = $displayStarters[$index] ?? $starter; ?>
                  <div class="starting11-graphic-card__player">
                    <span class="starting11-graphic-card__player-number js-starting11-player-number<?= $showNumbers ? '' : ' d-none' ?>"><?= (int)($index + 1) ?></span>
                    <span
                      class="js-starting11-player-name"
                      data-full-name="<?= h(strtoupper($displayStarter)) ?>"
                      data-initial-name="<?= h(strtoupper($formattedStarters[$index] ?? $displayStarter)) ?>"
                    ><?= h(strtoupper($useInitialNames ? ($formattedStarters[$index] ?? $displayStarter) : $displayStarter)) ?></span>
                    <?php if ($captain !== '' && $captain === $starter): ?>
                      <span class="starting11-graphic-card__captain" aria-hidden="true"></span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </section>
            <?php endif; ?>

            <?php if ($viewMode !== 'grid'): ?>
              <section class="starting11-graphic-card__subs" style="<?= h(starting11GraphicPackElementStyle(isset($templatePackElements['substitutes']) && is_array($templatePackElements['substitutes']) ? $templatePackElements['substitutes'] : null)) ?>" aria-label="Substitutes">
                <h2 class="starting11-graphic-card__subs-title">SUBSTITUTES</h2>
                <div class="starting11-graphic-card__subs-list">
                  <p
                    class="starting11-graphic-card__subs-row js-starting11-player-name"
                    data-full-name="<?= h(strtoupper($fullSubstitutesLine)) ?>"
                    data-initial-name="<?= h(strtoupper($substitutesLine)) ?>"
                  ><?= h(strtoupper($useInitialNames ? $substitutesLine : $fullSubstitutesLine)) ?></p>
                </div>
              </section>
            <?php endif; ?>

            <?php if ($graphicSponsors !== [] && $viewMode !== 'grid'): ?>
              <section class="starting11-graphic-card__sponsor-strip" style="<?= h(starting11GraphicPackElementStyle(isset($templatePackElements['sponsor']) && is_array($templatePackElements['sponsor']) ? $templatePackElements['sponsor'] : null)) ?>" aria-label="Main sponsors">
                <?php foreach ($graphicSponsors as $sponsor): ?>
                  <?php
                    $colourSponsorLogo = trim((string)($sponsor['logo_path'] ?? ''));
                    $whiteSponsorLogo = trim((string)($sponsor['white_logo_path'] ?? ''));
                    $whiteSponsorLogo = $whiteSponsorLogo !== '' ? $whiteSponsorLogo : $colourSponsorLogo;
                    $selectedSponsorLogo = $useWhiteSponsorLogos ? $whiteSponsorLogo : $colourSponsorLogo;
                  ?>
                  <div class="starting11-graphic-card__sponsor">
                    <?php if ($selectedSponsorLogo !== ''): ?>
                      <img class="starting11-graphic-card__sponsor-logo js-starting11-sponsor-logo" src="<?= h(starting11GraphicAssetUrl('uploads/sponsors/' . $selectedSponsorLogo)) ?>" data-colour="<?= h(starting11GraphicAssetUrl('uploads/sponsors/' . ($colourSponsorLogo !== '' ? $colourSponsorLogo : $whiteSponsorLogo))) ?>" data-white="<?= h(starting11GraphicAssetUrl('uploads/sponsors/' . $whiteSponsorLogo)) ?>" alt="<?= h((string)$sponsor['name']) ?>" loading="eager">
                    <?php else: ?>
                      <span class="starting11-graphic-card__sponsor-name"><?= h((string)$sponsor['name']) ?></span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </section>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
    </div>
  </section>
</div>

<script>
  (function () {
    var previewOnly = <?= $previewOnly ? 'true' : 'false' ?>;
    var downloadButton = document.getElementById('downloadStarting11GraphicBtn');
    var socialButtons = Array.prototype.slice.call(document.querySelectorAll('.js-post-starting11'));
    var socialStatus = document.getElementById('starting11SocialStatus');
    var graphic = document.querySelector('#starting11GraphicCapture .starting11-graphic-card');
    var showNumbersToggle = document.getElementById('showNumbersToggle');
    var useInitialNamesToggle = document.getElementById('useInitialNamesToggle');
    var whiteSponsorLogosToggle = document.getElementById('useWhiteSponsorLogosToggle');
    var whiteBadgesToggle = document.getElementById('useWhiteBadgesToggle');
    var socialCaptionInput = document.getElementById('starting11SocialCaption');
    var mainCapture = document.getElementById('starting11GraphicCapture');
    if (graphic && mainCapture) {
      var resizePreview = function () {
        var available = mainCapture.parentElement.clientWidth || window.innerWidth;
        var scale = Math.min(1, available / <?= (int)$templatePackCanvasWidth ?>);
        mainCapture.style.width = <?= (int)$templatePackCanvasWidth ?> + 'px';
        mainCapture.style.height = <?= (int)$templatePackCanvasHeight ?> + 'px';
        mainCapture.style.transform = 'scale(' + scale + ')';
        mainCapture.style.transformOrigin = 'top left';
        mainCapture.parentElement.style.height = (<?= (int)$templatePackCanvasHeight ?> * scale) + 'px';
      };
      resizePreview();
      window.addEventListener('resize', resizePreview);
    }
    if (previewOnly) {
      return;
    }
    if (!downloadButton || !graphic || typeof html2canvas !== 'function') {
      return;
    }

    var fixtureId = <?= (int)$fixtureId ?>;
    var exportWidth = <?= (int)$templatePackCanvasWidth ?>;
    var exportHeight = <?= (int)$templatePackCanvasHeight ?>;
    var csrfToken = <?= json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
    var socialCaptions = <?= json_encode($starting11SocialCaptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var preparedSocialCaption = socialCaptions.facebook || '';
    var confirmBeforeLivePost = <?= !empty($publishingPreferences['global']['confirm_before_live_post']) ? 'true' : 'false' ?>;

    function updateSponsorLogos() {
      if (!whiteSponsorLogosToggle) {
        return;
      }
      graphic.querySelectorAll('.js-starting11-sponsor-logo').forEach(function (logo) {
        var nextSource = whiteSponsorLogosToggle.checked ? logo.dataset.white : logo.dataset.colour;
        if (nextSource) {
          logo.src = nextSource;
        }
      });
    }

    function updatePlayerNumbers() {
      if (!showNumbersToggle) {
        return;
      }
      graphic.querySelectorAll('.js-starting11-player-number').forEach(function (number) {
        number.classList.toggle('d-none', !showNumbersToggle.checked);
      });
    }

    function updatePlayerNameFormat() {
      if (!useInitialNamesToggle) {
        return;
      }
      graphic.querySelectorAll('.js-starting11-player-name').forEach(function (name) {
        var nextName = useInitialNamesToggle.checked ? name.dataset.initialName : name.dataset.fullName;
        if (nextName) {
          name.textContent = nextName;
        }
      });
    }

    if (showNumbersToggle) {
      showNumbersToggle.addEventListener('change', updatePlayerNumbers);
      updatePlayerNumbers();
    }

    if (useInitialNamesToggle) {
      useInitialNamesToggle.addEventListener('change', updatePlayerNameFormat);
      updatePlayerNameFormat();
    }

    if (whiteSponsorLogosToggle) {
      whiteSponsorLogosToggle.addEventListener('change', updateSponsorLogos);
      updateSponsorLogos();
    }

    function updateTeamBadges() {
      if (!whiteBadgesToggle) {
        return;
      }
      graphic.querySelectorAll('.js-starting11-team-badge').forEach(function (badge) {
        var nextSource = whiteBadgesToggle.checked ? badge.dataset.white : badge.dataset.colour;
        if (nextSource) {
          badge.src = nextSource;
        }
      });
    }

    if (whiteBadgesToggle) {
      whiteBadgesToggle.addEventListener('change', updateTeamBadges);
      updateTeamBadges();
    }

    function waitForImages(container) {
      var images = Array.prototype.slice.call(container.querySelectorAll('img'));
      if (!images.length) {
        return Promise.resolve();
      }

      return Promise.all(images.map(function (img) {
        if (img.complete && img.naturalWidth > 0) {
          return Promise.resolve();
        }

        return new Promise(function (resolve) {
          var done = function () {
            img.removeEventListener('load', done);
            img.removeEventListener('error', done);
            resolve();
          };
          img.addEventListener('load', done, { once: true });
          img.addEventListener('error', done, { once: true });
        });
      }));
    }

    function waitForFonts() {
      if (document.fonts && typeof document.fonts.ready === 'object') {
        return document.fonts.ready.catch(function () {
          return undefined;
        });
      }

      return Promise.resolve();
    }

    function cacheBustUrl(src, token) {
      try {
        var url = new URL(src, window.location.href);
        url.searchParams.set('render_token', token);
        return url.toString();
      } catch (error) {
        var separator = src.indexOf('?') === -1 ? '?' : '&';
        return src + separator + 'render_token=' + encodeURIComponent(token);
      }
    }

    function renderGraphic() {
      var renderToken = Date.now().toString();
      return Promise.all([waitForFonts(), waitForImages(graphic)]).then(function () {
        var exportWrap = document.createElement('div');
        exportWrap.className = 'starting11-graphic-page';
        exportWrap.style.position = 'fixed';
        exportWrap.style.left = '-10000px';
        exportWrap.style.top = '0';
        exportWrap.style.width = exportWidth + 'px';
        exportWrap.style.height = exportHeight + 'px';
        exportWrap.style.pointerEvents = 'none';
        exportWrap.style.opacity = '1';

        var exportClone = graphic.cloneNode(true);
        exportClone.classList.add('starting11-graphic-card--export');
        exportClone.style.width = exportWidth + 'px';
        exportClone.style.height = exportHeight + 'px';
        exportClone.style.minWidth = exportWidth + 'px';
        exportClone.style.minHeight = exportHeight + 'px';
        exportClone.style.maxWidth = exportWidth + 'px';
        exportClone.style.maxHeight = exportHeight + 'px';

        var clonedImages = exportClone.querySelectorAll('img');
        clonedImages.forEach(function (img) {
          img.loading = 'eager';
          img.decoding = 'sync';
          if (img.getAttribute('src')) {
            img.src = cacheBustUrl(img.getAttribute('src'), renderToken);
          }
        });

        exportWrap.appendChild(exportClone);
        document.body.appendChild(exportWrap);

        return Promise.all([waitForFonts(), waitForImages(exportClone)]).then(function () {
          return html2canvas(exportClone, {
            useCORS: true,
            backgroundColor: null,
            scale: 1,
            width: exportWidth,
            height: exportHeight,
            windowWidth: exportWidth,
            windowHeight: exportHeight,
            imageTimeout: 0
          }).finally(function () {
            document.body.removeChild(exportWrap);
          });
        });
      });
    }

    function setSocialStatus(type, message) {
      if (!socialStatus) {
        return;
      }
      socialStatus.className = 'alert mt-3 mb-0 alert-' + (type === 'success' ? 'success' : type === 'info' ? 'info' : 'danger');
      socialStatus.textContent = message;
    }

    function setSocialButtonsDisabled(disabled) {
      socialButtons.forEach(function (button) {
        button.disabled = disabled;
      });
    }

    downloadButton.addEventListener('click', function () {
      var originalText = downloadButton.textContent;
      var previewWindow = window.open('', '_blank');
      if (!previewWindow) {
        window.hubToast('Popup blocked. Allow popups for this site and try again.', 'danger');
        return;
      }

      previewWindow.document.write(
        '<!doctype html><html><head><title>Generating PNG...</title><link rel="stylesheet" href="/admin/assets/css/player-sponsors-match-starting-11-graphic-2.css"></head><body>Generating PNG...</body></html>'
      );
      previewWindow.document.close();

      downloadButton.disabled = true;
      downloadButton.textContent = 'Generating...';

      renderGraphic().then(function (canvas) {
        var imageUrl = canvas.toDataURL('image/png');
        previewWindow.document.write(
          '<!doctype html><html><head><title>Starting 11 PNG Preview</title><link rel="stylesheet" href="/admin/assets/css/player-sponsors-match-starting-11-graphic-3.css"></head><body><img src="' + imageUrl + '" alt="Starting 11 PNG preview"></body></html>'
        );
        previewWindow.document.close();
      }).catch(function (error) {
        previewWindow.document.write(
          '<!doctype html><html><head><title>PNG Preview Error</title><link rel="stylesheet" href="/admin/assets/css/player-sponsors-match-starting-11-graphic-4.css"></head><body>' + String(error && error.message ? error.message : 'Failed to generate PNG.') + '</body></html>'
        );
        previewWindow.document.close();
      }).finally(function () {
        downloadButton.disabled = false;
        downloadButton.textContent = originalText;
      });
    });

    socialButtons.forEach(function (button) {
      button.addEventListener('click', async function () {
        var platform = button.getAttribute('data-platform') || '';
        if (socialCaptionInput && socialCaptionInput.value.trim() === preparedSocialCaption.trim() && socialCaptions[platform]) {
          preparedSocialCaption = socialCaptions[platform];
          socialCaptionInput.value = preparedSocialCaption;
        }
        var socialCaption = socialCaptionInput ? socialCaptionInput.value.trim() : (socialCaptions[platform] || '');
        var platformLabel = platform === 'x' ? 'Twitter/X' : platform.charAt(0).toUpperCase() + platform.slice(1);
        if (confirmBeforeLivePost && !(await window.hubConfirm('Post this Starting XI to ' + platformLabel + '?', { actionLabel: 'Post', actionClass: 'btn-primary' }))) {
          return;
        }

        var xWindow = platform === 'x' ? window.open('', '_blank') : null;
        if (platform === 'x' && !xWindow) {
          window.hubToast('Popup blocked. Allow popups for this site and try again.', 'danger');
          return;
        }

        setSocialButtonsDisabled(true);
        setSocialStatus('info', platform === 'x' ? 'Preparing Twitter/X share...' : 'Posting to ' + platformLabel + '...');

        renderGraphic().then(function (canvas) {
          var imageData = canvas.toDataURL('image/png');

          if (platform === 'x') {
            var downloadLink = document.createElement('a');
            downloadLink.href = imageData;
            downloadLink.download = 'starting-xi-fixture-' + fixtureId + '.png';
            document.body.appendChild(downloadLink);
            downloadLink.click();
            document.body.removeChild(downloadLink);
            xWindow.location.href = 'https://x.com/intent/tweet?text=' + encodeURIComponent(socialCaption);
            var shareRecord = new FormData();
            shareRecord.set('csrf_token', csrfToken);
            shareRecord.set('fixture_id', String(fixtureId));
            shareRecord.set('post_type', 'starting_xi');
            shareRecord.set('caption', socialCaption);
            shareRecord.set('image_url', '/match_starting_11_graphic.php?fixture_id=' + fixtureId);
            fetch('/admin/record_manual_share.php', { method: 'POST', body: shareRecord }).catch(function () {});
            setSocialStatus('success', 'Twitter/X composer opened and the Starting XI image was downloaded for attachment.');
            return null;
          }

          var formData = new FormData();
          formData.set('csrf_token', csrfToken);
          formData.set('fixture_id', String(fixtureId));
          formData.set('target', platform);
          formData.set('image_data', imageData);
          formData.set('caption', socialCaption);

          function submitStartingEleven(forceRepost) {
            if (forceRepost) {
              formData.set('allow_repost', '1');
            } else {
              formData.delete('allow_repost');
            }

            return fetch('post_starting_11.php', {
              method: 'POST',
              body: formData
            }).then(function (response) {
              return response.json().then(function (json) {
                return { ok: response.ok, status: response.status, json: json };
              });
            }).then(function (result) {
              if (result.status === 409 && !forceRepost) {
                return window.hubConfirm(
                  'This exact Starting XI was posted before. If you deleted the original post, click OK to publish it again.',
                  { actionLabel: 'Publish again', actionClass: 'btn-primary' }
                ).then(function (replaceConfirmed) {
                  if (replaceConfirmed) {
                    setSocialStatus('info', 'Publishing replacement Starting XI...');
                    return submitStartingEleven(true);
                  }
                  return result;
                });
              }
              return result;
            });
          }

          return submitStartingEleven(false).then(function (result) {
            if (!result.ok || !result.json || !result.json.ok) {
              var details = result.json && Array.isArray(result.json.details) ? result.json.details.join(' ') : '';
              throw new Error(details || (result.json && result.json.message) || 'Social post failed.');
            }
            setSocialStatus('success', result.json.message || ('Starting XI posted to ' + platformLabel + '.'));
          });
        }).catch(function (error) {
          if (xWindow && !xWindow.closed) {
            xWindow.close();
          }
          setSocialStatus('error', error && error.message ? error.message : 'Social post failed.');
        }).finally(function () {
          setSocialButtonsDisabled(false);
        });
      });
    });
  }());
</script>

<?php if (!$previewOnly) { require_once $layoutFooter; } ?>
