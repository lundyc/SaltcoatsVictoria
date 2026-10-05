<?php
$pageStyles = ['match-fixture-tabs.css'];
$useSharedHubLayout = true;

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

function nextMatchSlug(string $value): string
{
          $value = strtolower(trim($value));
          $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
          return trim($value, '-');
}

function nextMatchBadgeUrl(string $club, bool $white): string
{
          global $pdo;
          if ($pdo instanceof PDO) {
                    $opponentBadge = matchOpponentBadgeAssetUrl($pdo, $club, $white);
                    if ($opponentBadge !== '') {
                              return $opponentBadge;
                    }
          }

          $slug = nextMatchSlug($club);
          $directory = __DIR__ . '/badges' . ($white ? '/white' : '');
          $exact = [];
          $close = [];
          foreach (is_dir($directory) ? (glob($directory . '/*') ?: []) : [] as $path) {
                    if (!is_file($path) || !in_array(strtolower((string)pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
                              continue;
                    }
                    $fileSlug = nextMatchSlug((string)pathinfo($path, PATHINFO_FILENAME));
                    if ($fileSlug === $slug) {
                              $exact[] = $path;
                    } elseif (str_starts_with($fileSlug, $slug . '-') || str_starts_with($slug, $fileSlug . '-')) {
                              $close[] = $path;
                    }
          }
          $matches = $exact !== [] ? $exact : $close;
          if ($matches === []) {
                    return '';
          }
          usort($matches, static fn(string $left, string $right): int => strlen(basename($left)) <=> strlen(basename($right)));
          $folder = $white ? 'white/' : '';
          return '/badges/' . $folder . rawurlencode(basename($matches[0])) . '?v=' . filemtime($matches[0]);
}

$seasonId = getSelectedSeasonId($pdo);
$fixtureId = (int)($_GET['fixture_id'] ?? $_GET['id'] ?? 0);
if ((int)($_GET['season_id'] ?? 0) > 0) {
          $seasonId = (int)$_GET['season_id'];
}
$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;
if (!$fixture) {
          echo '<div><div class="alert alert-danger">Fixture not found.</div></div>';
          require_once $layoutFooter;
          exit;
}
$seasonId = (int)$fixture['season_id'];
$templatePackContext = matchTemplatePackContext($pdo, $fixtureId, 'next_match', true);
$resolvedNextMatchPackId = (int)($templatePackContext['pack']['id'] ?? 0);
if ($resolvedNextMatchPackId === 5) {
          try {
                    $latestNextMatchDraft = template_packs_get_draft($pdo, $resolvedNextMatchPackId);
                    $latestNextMatchAction = isset($latestNextMatchDraft['actions']['next_match']) && is_array($latestNextMatchDraft['actions']['next_match'])
                              ? $latestNextMatchDraft['actions']['next_match']
                              : null;
                    if ($latestNextMatchAction !== null) {
                              $templatePackContext['version'] = $latestNextMatchDraft;
                              $templatePackContext['brand_settings'] = isset($latestNextMatchDraft['brand_settings']) && is_array($latestNextMatchDraft['brand_settings'])
                                        ? $latestNextMatchDraft['brand_settings']
                                        : $templatePackContext['brand_settings'];
                              $templatePackContext['action'] = $latestNextMatchAction;
                    }
          } catch (Throwable $draftError) {
                    error_log('Next Match draft preview resolution failed: ' . $draftError->getMessage());
          }
}
$templatePackBrand = isset($templatePackContext['brand_settings']) && is_array($templatePackContext['brand_settings'])
          ? $templatePackContext['brand_settings'] : [];
$templatePackLayout = matchTemplatePackLayout($templatePackContext);
$templatePackElements = isset($templatePackLayout['elements']) && is_array($templatePackLayout['elements'])
          ? $templatePackLayout['elements'] : [];
$templatePackHeadlineStyle = matchTemplatePackElementStyle(
          isset($templatePackElements['headline']) && is_array($templatePackElements['headline'])
                    ? $templatePackElements['headline'] : null
);
$templatePackFixtureStyle = matchTemplatePackElementStyle(
          isset($templatePackElements['fixture']) && is_array($templatePackElements['fixture'])
                    ? $templatePackElements['fixture'] : null
);
$templatePackFixtureElement = isset($templatePackElements['fixture']) && is_array($templatePackElements['fixture'])
          ? $templatePackElements['fixture'] : [];
$nextMatchTypographyFont = static function ($value, string $fallback): string {
          $value = trim((string)$value);
          return $value !== '' && preg_match('/^[a-z0-9 ._-]{1,80}$/i', $value) === 1 ? $value : $fallback;
};
$nextMatchTypographyWeight = static function ($value, int $fallback): int {
          $weight = (int)$value;
          return in_array($weight, [300, 400, 500, 600, 700, 800, 900], true) ? $weight : $fallback;
};
$nextMatchVenueFont = $nextMatchTypographyFont($templatePackFixtureElement['venue_font'] ?? '', matchTemplatePackFont($templatePackBrand, 'body', 'Poppins'));
$nextMatchVenueWeight = $nextMatchTypographyWeight($templatePackFixtureElement['venue_weight'] ?? null, 600);
$nextMatchDateFont = $nextMatchTypographyFont($templatePackFixtureElement['date_font'] ?? '', matchTemplatePackFont($templatePackBrand, 'body', 'Poppins'));
$nextMatchDateWeight = $nextMatchTypographyWeight($templatePackFixtureElement['date_weight'] ?? null, 500);
$nextMatchVenueTypographyStyle = 'font-family:"' . $nextMatchVenueFont . '",Arial,sans-serif;font-weight:' . $nextMatchVenueWeight . ';';
$nextMatchDateTypographyStyle = 'font-family:"' . $nextMatchDateFont . '",Arial,sans-serif;font-weight:' . $nextMatchDateWeight . ';';
$templatePackSponsorStyle = matchTemplatePackElementStyle(
          isset($templatePackElements['sponsor']) && is_array($templatePackElements['sponsor'])
                    ? $templatePackElements['sponsor'] : null
);
$templatePackBadgesStyle = matchTemplatePackElementStyle(
          isset($templatePackElements['badges']) && is_array($templatePackElements['badges'])
                    ? $templatePackElements['badges'] : null
);
$templatePackFixtureSponsorsStyle = matchTemplatePackElementStyle(
          isset($templatePackElements['fixture_sponsors']) && is_array($templatePackElements['fixture_sponsors'])
                    ? $templatePackElements['fixture_sponsors'] : null
);
$templatePackMainSponsorsStyle = matchTemplatePackElementStyle(
          isset($templatePackElements['sponsor_logos']) && is_array($templatePackElements['sponsor_logos'])
                    ? $templatePackElements['sponsor_logos'] : null
);
$templatePackBackgroundUrl = matchTemplatePackAssetUrl((string)($templatePackContext['action']['background_path'] ?? ''));
$templatePackPrimary = matchTemplatePackColour($templatePackBrand, 'primary', '#123fbd');
$templatePackSecondary = matchTemplatePackColour($templatePackBrand, 'secondary', '#030712');
$templatePackAccent = matchTemplatePackColour($templatePackBrand, 'accent', '#ffc506');
$templatePackText = matchTemplatePackColour($templatePackBrand, 'text', '#ffffff');
$templatePackHeadingFont = matchTemplatePackFont($templatePackBrand, 'heading', 'Roboto');
$templatePackBodyFont = matchTemplatePackFont($templatePackBrand, 'body', 'Roboto');
$templatePackHeadingTransform = match ((string)($templatePackBrand['heading_style'] ?? 'natural')) {
          'uppercase' => 'uppercase',
          'title' => 'capitalize',
          default => 'none',
};
$templatePackCanvasWidth = max(320, min(4096, (int)($templatePackLayout['canvas_width'] ?? 1080)));
$templatePackCanvasHeight = max(320, min(4096, (int)($templatePackLayout['canvas_height'] ?? 1350)));
$usePackFiveNextUp = $resolvedNextMatchPackId === 5 && $templatePackElements !== [];
$templatePackBackgroundFitCandidate = (string)($templatePackLayout['background_fit'] ?? 'cover');
$templatePackBackgroundFit = in_array($templatePackBackgroundFitCandidate, ['cover', 'contain', 'stretch'], true)
          ? $templatePackBackgroundFitCandidate : 'cover';
$templatePackBackgroundObjectFit = $templatePackBackgroundFit === 'stretch' ? 'fill' : $templatePackBackgroundFit;
$opponent = trim((string)$fixture['opponent']);
$matchTimestamp = strtotime(trim((string)$fixture['match_date']) . ' ' . (trim((string)$fixture['kickoff_time']) ?: '00:00:00'));
$dateLabel = $matchTimestamp ? date('l j F Y', $matchTimestamp) : trim((string)$fixture['match_date']);
$timeLabel = trim((string)$fixture['kickoff_time']) !== '' && $matchTimestamp ? date('g:i A', $matchTimestamp) : 'Kick-off TBC';
$venue = trim((string)$fixture['venue']);
if ($venue === '') {
          $venue = !empty($fixture['is_home']) ? 'Home' : (trim((string)($fixture['opponent_ground_location'] ?? '')) ?: 'Away');
}
$competition = trim((string)$fixture['competition']) ?: 'Fixture';
$homeTeam = !empty($fixture['is_home']) ? 'Saltcoats Victoria' : $opponent;
$awayTeam = !empty($fixture['is_home']) ? $opponent : 'Saltcoats Victoria';
$homeColourBadge = nextMatchBadgeUrl($homeTeam, false);
$awayColourBadge = nextMatchBadgeUrl($awayTeam, false);
$homeWhiteBadge = nextMatchBadgeUrl($homeTeam, true) ?: $homeColourBadge;
$awayWhiteBadge = nextMatchBadgeUrl($awayTeam, true) ?: $awayColourBadge;
$homeDefaultBadge = $homeWhiteBadge !== '' ? $homeWhiteBadge : $homeColourBadge;
$awayDefaultBadge = $awayWhiteBadge !== '' ? $awayWhiteBadge : $awayColourBadge;
$competitionBadgeImage = '';
$competitionWhiteBadgeImage = '';
$competitionRow = getMatchCompetitionByName($pdo, $competition);
if ($competitionRow) {
          $competitionBadgeImage = trim((string)($competitionRow['badge_image'] ?? ''));
          $competitionWhiteBadgeImage = trim((string)($competitionRow['white_badge_image'] ?? ''));
}
if ($competitionBadgeImage === '' && $competitionWhiteBadgeImage === '') {
          $competitionNeedle = strtolower($competition);
          foreach (getMatchCompetitions($pdo) as $competitionOption) {
                    $optionName = strtolower(trim((string)($competitionOption['name'] ?? '')));
                    $optionBadge = trim((string)($competitionOption['badge_image'] ?? ''));
                    $optionWhiteBadge = trim((string)($competitionOption['white_badge_image'] ?? ''));
                    if (($optionBadge !== '' || $optionWhiteBadge !== '') && ($optionName === $competitionNeedle || str_contains($optionName, $competitionNeedle) || str_contains($competitionNeedle, $optionName))) {
                              $competitionBadgeImage = $optionBadge;
                              $competitionWhiteBadgeImage = $optionWhiteBadge;
                              break;
                    }
          }
}
$competitionBadgeUrl = $competitionBadgeImage !== ''
          ? '/' . ltrim($competitionBadgeImage, '/')
          : '';
$competitionWhiteBadgeUrl = $competitionWhiteBadgeImage !== ''
          ? '/' . ltrim($competitionWhiteBadgeImage, '/')
          : $competitionBadgeUrl;
$competitionDefaultBadgeUrl = $competitionWhiteBadgeUrl !== '' ? $competitionWhiteBadgeUrl : $competitionBadgeUrl;
$graphicTimeLabel = $matchTimestamp ? strtolower(date('g:ia', $matchTimestamp)) : 'kick-off tbc';
$graphicTimeLabel = str_replace(':00', '', $graphicTimeLabel);
$graphicDateLabel = $matchTimestamp ? date('l jS F', $matchTimestamp) . ', ' . $graphicTimeLabel : $dateLabel;
$fixtureBackground = trim((string)($fixture['next_match_background_image'] ?? ''));
$backgroundImageUrl = $fixtureBackground !== ''
          ? '/' . ltrim($fixtureBackground, '/')
          : ($templatePackBackgroundUrl !== '' ? $templatePackBackgroundUrl : '/admin/assets/images/background.png');
$fixtureGraphicSponsors = [];
$seenFixtureSponsorRoles = [];
foreach (getDisplayableMatchSponsorshipRows($pdo, $fixtureId) as $fixtureSponsorRow) {
          $role = (string)($fixtureSponsorRow['sponsorship_role'] ?? '');
          if (!in_array($role, ['match_day', 'match_ball'], true) || isset($seenFixtureSponsorRoles[$role])) {
                    continue;
          }
          $seenFixtureSponsorRoles[$role] = true;
          $colourLogoFilename = basename(trim((string)($fixtureSponsorRow['sponsor_logo'] ?? '')));
          $whiteLogoFilename = basename(trim((string)($fixtureSponsorRow['sponsor_white_logo'] ?? '')));
          $colourLogoUrl = $colourLogoFilename !== '' && is_file(__DIR__ . '/uploads/sponsors/' . $colourLogoFilename)
                    ? '/uploads/sponsors/' . rawurlencode($colourLogoFilename)
                    : '';
          $whiteLogoUrl = $whiteLogoFilename !== '' && is_file(__DIR__ . '/uploads/sponsors/' . $whiteLogoFilename)
                    ? '/uploads/sponsors/' . rawurlencode($whiteLogoFilename)
                    : $colourLogoUrl;
          $fixtureGraphicSponsors[] = [
                    'role_label' => $role === 'match_day' ? 'Matchday Sponsor' : 'Matchball Sponsor',
                    'name' => trim((string)($fixtureSponsorRow['sponsor_name'] ?? 'Sponsor')),
                    'logo_url' => $colourLogoUrl,
                    'white_logo_url' => $whiteLogoUrl,
          ];
}
$mainSponsors = [];
foreach (getActiveMainSponsorAgreements($pdo, $seasonId, (string)$fixture['match_date']) as $mainSponsor) {
                    $logoFilename = basename(trim((string)($mainSponsor['logo_path'] ?? '')));
                    if ($logoFilename === '' || !is_file(__DIR__ . '/uploads/sponsors/' . $logoFilename)) {
                              continue;
                    }
                    $mainSponsors[] = [
                              'name' => trim((string)($mainSponsor['name'] ?? 'Main Sponsor')),
                              'logo_url' => '/uploads/sponsors/' . rawurlencode($logoFilename),
                              'white_logo_url' => '',
                    ];
                    $whiteLogoFilename = basename(trim((string)($mainSponsor['white_logo_path'] ?? '')));
                    if ($whiteLogoFilename !== '' && is_file(__DIR__ . '/uploads/sponsors/' . $whiteLogoFilename)) {
                              $mainSponsors[array_key_last($mainSponsors)]['white_logo_url'] = '/uploads/sponsors/' . rawurlencode($whiteLogoFilename);
                    }
}
$nextMatchCaptions = [];
$matchdayCaptions = [];
$fixtureSponsorCredit = getMatchSponsorshipPublicCredit($pdo, $fixtureId);
foreach (['facebook', 'instagram', 'x'] as $socialChannel) {
          $captionContext = [
                    'home_team' => $homeTeam,
                    'away_team' => $awayTeam,
                    'fixture_sponsor_credit' => $fixtureSponsorCredit,
          ];
          $nextMatchCaptions[$socialChannel] = social_post_resolve_caption($socialChannel, 'next_match', $fixture, $captionContext);
          $matchdayCaptions[$socialChannel] = social_post_resolve_caption($socialChannel, 'matchday', $fixture, $captionContext);
}
$publishingPreferences = social_publishing_preferences_load();
$nextMatchVisuals = $publishingPreferences['visuals']['next_match'] ?? [];
$nextMatchCsrfToken = '';
if (session_status() !== PHP_SESSION_ACTIVE) {
          session_start();
}
if (empty($_SESSION['csrf_token'])) {
          $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$nextMatchCsrfToken = (string)$_SESSION['csrf_token'];
$nextMatchBusinessSponsors = [];
try {
          foreach ($pdo->query("SELECT id, name, address, website_url, contact_phone FROM sponsors WHERE is_business = 1 AND is_active = 1 ORDER BY name ASC") as $businessSponsorRow) {
                    $nextMatchBusinessSponsors[] = [
                              'name' => (string)$businessSponsorRow['name'],
                              'address' => trim((string)($businessSponsorRow['address'] ?? '')),
                              'website' => trim((string)($businessSponsorRow['website_url'] ?? '')),
                              'phone' => trim((string)($businessSponsorRow['contact_phone'] ?? '')),
                    ];
          }
} catch (PDOException $e) {
          // Business sponsor columns not present yet (added the first time
          // sponsor.php or a player graphic page runs) — just show no options.
}
$nextMatchShowSponsorNames = !array_key_exists('show_sponsor_names', $templatePackBrand)
          || !empty($templatePackBrand['show_sponsor_names']);
$nextMatchGradientColor = preg_match('/^#[0-9a-f]{6}$/i', (string)($templatePackLayout['gradient_color'] ?? ''))
          ? (string)$templatePackLayout['gradient_color']
          : (preg_match('/^#[0-9a-f]{6}$/i', (string)($nextMatchVisuals['gradient_color'] ?? ''))
                    ? (string)$nextMatchVisuals['gradient_color'] : '#000000');
$nextMatchGradientStrength = array_key_exists('gradient_strength', $templatePackLayout)
          ? max(0, min(100, (int)$templatePackLayout['gradient_strength']))
          : max(0, min(100, (int)($nextMatchVisuals['gradient_strength'] ?? 70)));
$nextMatchGradientRgb = sscanf(ltrim($nextMatchGradientColor, '#'), '%02x%02x%02x') ?: [0, 0, 0];
$nextMatchGradientOpacity = $nextMatchGradientStrength / 100;
?>

<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;family=Montserrat:wght@300;400;500;600;700;800;900&amp;family=Oswald:wght@300;400;500;600;700&amp;family=Poppins:wght@300;400;500;600;700;800;900&amp;family=Roboto:wght@300;400;500;600;700;800;900&amp;family=Roboto+Condensed:wght@300;400;500;600;700;800;900&amp;display=swap">
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<?php
require_once __DIR__ . '/lib/dynamic_styles.php';
ob_start();
require __DIR__ . '/assets/css/player-sponsors-match-next-match.dynamic.php';
$hubDynamicCss1 = (string) ob_get_clean();
$hubDynamicStyleHref1 = hub_dynamic_stylesheet_register($hubDynamicCss1);
?>
<link rel="stylesheet" href="<?= htmlspecialchars($hubDynamicStyleHref1, ENT_QUOTES, 'UTF-8') ?>">

<?php if (!$useSharedHubLayout): ?>
<div class="page-hero mb-4">
  <div class="page-hero-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
    <div>
      <div class="page-hero-eyebrow">Fixture promotion</div>
      <h1 class="page-hero-title">Next Match</h1>
      <p class="page-hero-subtitle"><?= h($homeTeam . ' v ' . $awayTeam) ?></p>
    </div>
  </div>
</div>
<?php endif; ?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/matches.php">Fixtures</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><a href="/admin/match.php?id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>"><?= h((string)$fixture['opponent']) ?></a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Next match graphic</span></nav>
<?php renderFixtureTabs((int)$fixtureId, (int)$seasonId, 'next_match'); ?>

<div class="row g-4 next-match-editor">
  <div class="col-12 col-xl-4">
    <div class="card shadow-sm border-0">
      <div class="card-body d-grid gap-3">
        <div>
          <div class="btn-group mb-2" role="group" aria-label="Post type">
            <input type="radio" class="btn-check" name="nextMatchPostType" id="nextMatchPostTypeNextMatch" value="next_match" checked>
            <label class="btn btn-sm btn-outline-primary" for="nextMatchPostTypeNextMatch">Next Match</label>
            <input type="radio" class="btn-check" name="nextMatchPostType" id="nextMatchPostTypeMatchday" value="matchday">
            <label class="btn btn-sm btn-outline-primary" for="nextMatchPostTypeMatchday">Matchday</label>
          </div>
          <div class="form-text mb-2">Next Match is the advance announcement; Matchday is the same-day reminder posted this morning. Both use this graphic.</div>
          <label for="nextMatchCaption" class="form-label fw-semibold">Post text</label>
          <div class="next-match-caption-toolbar" role="toolbar" aria-label="Post text formatting">
            <button type="button" class="btn btn-sm btn-outline-secondary js-caption-format" data-style="bold" aria-label="Make selected text bold" title="Bold selected text"><strong>B</strong></button>
            <button type="button" class="btn btn-sm btn-outline-secondary js-caption-format" data-style="italic" aria-label="Make selected text italic" title="Italicise selected text"><em>I</em></button>
            <button type="button" class="btn btn-sm btn-outline-secondary js-caption-format" data-style="underline" aria-label="Underline selected text" title="Underline selected text"><span class="text-decoration-underline">U</span></button>
            <button type="button" class="btn btn-sm btn-outline-secondary js-caption-format" data-style="clear" aria-label="Remove formatting from selected text" title="Remove formatting"><i class="fa-solid fa-eraser" aria-hidden="true"></i></button>
            <?php if ($nextMatchBusinessSponsors): ?>
              <select class="form-select form-select-sm w-auto" id="nextMatchInsertSponsorDetails" aria-label="Insert sponsor details">
                <option value="" selected>Insert sponsor details…</option>
                <?php foreach ($nextMatchBusinessSponsors as $businessSponsorIndex => $businessSponsorOption): ?>
                  <option value="<?= (int)$businessSponsorIndex ?>"><?= h($businessSponsorOption['name']) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
          <textarea class="form-control" id="nextMatchCaption" rows="11"><?= h($nextMatchCaptions['facebook']) ?></textarea>
        </div>
        <div class="next-match-control">
          <div class="form-label fw-semibold">Background image</div>
          <label class="next-match-background-dropzone" id="nextMatchBackgroundDropzone" for="nextMatchBackground">
            <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
            <strong id="nextMatchBackgroundDropzoneTitle">Drop an image here</strong>
            <span class="small" id="nextMatchBackgroundDropzoneDetail">or click to choose</span>
            <input class="visually-hidden" type="file" id="nextMatchBackground" accept="image/png,image/jpeg,image/webp">
          </label>
        </div>
        <fieldset class="next-match-control">
          <legend class="form-label fw-semibold mb-2">Share or save</legend>
          <div class="next-match-channel-list">
            <button type="button" class="next-match-channel-option next-match-channel-option--download js-next-match-download" title="Download PNG" aria-label="Download PNG">
              <i class="fa-solid fa-download text-success" aria-hidden="true"></i><span class="visually-hidden">Download PNG</span>
            </button>
            <label class="next-match-channel-option next-match-channel-option--facebook" for="nextMatchChannelFacebook" title="Facebook">
              <input class="js-next-match-channel" type="checkbox" value="facebook" id="nextMatchChannelFacebook">
              <i class="fa-brands fa-facebook text-primary" aria-hidden="true"></i><span class="visually-hidden">Facebook</span>
            </label>
            <label class="next-match-channel-option next-match-channel-option--instagram" for="nextMatchChannelInstagram" title="Instagram">
              <input class="js-next-match-channel" type="checkbox" value="instagram" id="nextMatchChannelInstagram">
              <i class="fa-brands fa-instagram text-danger" aria-hidden="true"></i><span class="visually-hidden">Instagram</span>
            </label>
            <label class="next-match-channel-option next-match-channel-option--x" for="nextMatchChannelX" title="X">
              <input class="js-next-match-channel" type="checkbox" value="x" id="nextMatchChannelX">
              <i class="fa-brands fa-x-twitter" aria-hidden="true"></i><span class="visually-hidden">X</span>
            </label>
          </div>
        </fieldset>
        <div class="d-grid">
          <button type="button" class="btn btn-brand" id="nextMatchPublishSelected">
            <i class="fa-solid fa-paper-plane me-2" aria-hidden="true"></i>Post Now
          </button>
        </div>
        <div class="d-none" id="nextMatchStatus" role="status" aria-live="polite"></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-xl-8">
    <div class="card shadow-sm border-0 overflow-hidden mx-auto" style="max-width: <?= (int)$templatePackCanvasWidth ?>px;">
      <div class="card-body p-0">
        <div class="next-match-stage" id="nextMatchStage">
          <article class="next-match-card<?= $usePackFiveNextUp ? ' next-match-card--pack-five-next-up' : '' ?>" id="nextMatchGraphic" aria-label="Next Match social graphic">
            <img class="next-match-card__background" id="nextMatchBackgroundPreview" src="<?= h($backgroundImageUrl) ?>" alt="">
            <div class="next-match-card__gradient"></div>
            <div class="next-match-card__content">
              <div class="next-match-card__match-panel"<?= $templatePackBadgesStyle !== '' ? ' style="' . h($templatePackBadgesStyle) . '"' : '' ?>>
                <span class="next-match-card__match-panel-accent" aria-hidden="true"></span>
                <?php if ($homeDefaultBadge !== ''): ?><img class="next-match-card__badge js-next-match-badge" src="<?= h($homeDefaultBadge) ?>" data-white="<?= h($homeWhiteBadge) ?>" data-colour="<?= h($homeColourBadge) ?>" alt="<?= h($homeTeam) ?> badge"><?php endif; ?>
                <?php if ($competitionDefaultBadgeUrl !== ''): ?>
                  <span class="next-match-card__competition-mark">
                    <img class="next-match-card__competition-image js-next-match-badge" src="<?= h($competitionDefaultBadgeUrl) ?>" data-white="<?= h($competitionWhiteBadgeUrl) ?>" data-colour="<?= h($competitionBadgeUrl) ?>" alt="<?= h($competition) ?>">
                  </span>
                <?php endif; ?>
                <?php if ($awayDefaultBadge !== ''): ?><img class="next-match-card__badge js-next-match-badge" src="<?= h($awayDefaultBadge) ?>" data-white="<?= h($awayWhiteBadge) ?>" data-colour="<?= h($awayColourBadge) ?>" alt="<?= h($awayTeam) ?> badge"><?php endif; ?>
              </div>
              <div class="next-match-card__title"<?= $templatePackHeadlineStyle !== '' ? ' style="' . h($templatePackHeadlineStyle) . '"' : '' ?> role="heading" aria-level="2">Next Up</div>
              <div class="next-match-card__details"<?= $templatePackFixtureStyle !== '' ? ' style="' . h($templatePackFixtureStyle) . '"' : '' ?>>
                <p class="next-match-card__venue" style="<?= h($nextMatchVenueTypographyStyle) ?>"><?= h($venue) ?></p>
                <p class="next-match-card__date" style="<?= h($nextMatchDateTypographyStyle) ?>"><?= h($graphicDateLabel) ?></p>
              </div>
              <?php if ($fixtureGraphicSponsors !== []): ?>
                <div class="next-match-card__fixture-sponsors" aria-label="Fixture sponsors" style="--fixture-sponsor-columns: <?= count($fixtureGraphicSponsors) > 1 ? 2 : 1 ?>;<?= h($templatePackFixtureSponsorsStyle) ?>">
                  <?php foreach ($fixtureGraphicSponsors as $fixtureGraphicSponsor): ?>
                    <div class="next-match-card__fixture-sponsor">
                      <span class="next-match-card__fixture-sponsor-role"><?= h((string)$fixtureGraphicSponsor['role_label']) ?></span>
                      <?php if ($fixtureGraphicSponsor['logo_url'] !== ''): ?>
                        <img class="next-match-card__fixture-sponsor-logo js-next-match-badge" src="<?= h((string)($fixtureGraphicSponsor['white_logo_url'] ?: $fixtureGraphicSponsor['logo_url'])) ?>" data-colour="<?= h((string)$fixtureGraphicSponsor['logo_url']) ?>" data-white="<?= h((string)($fixtureGraphicSponsor['white_logo_url'] ?: $fixtureGraphicSponsor['logo_url'])) ?>" alt="">
                      <?php endif; ?>
                      <?php if ($nextMatchShowSponsorNames): ?><span class="next-match-card__fixture-sponsor-name"><?= h((string)$fixtureGraphicSponsor['name']) ?></span><?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if ($mainSponsors !== []): ?>
                <div class="next-match-card__sponsors" aria-label="Main sponsors" style="--main-sponsor-columns: <?= min(4, max(1, count($mainSponsors))) ?>;<?= h($templatePackMainSponsorsStyle !== '' ? $templatePackMainSponsorsStyle : $templatePackSponsorStyle) ?>">
                  <?php foreach ($mainSponsors as $mainSponsor): ?>
                    <img class="next-match-card__sponsor-logo js-next-match-badge" src="<?= h((string)($mainSponsor['white_logo_url'] ?: $mainSponsor['logo_url'])) ?>" data-colour="<?= h((string)$mainSponsor['logo_url']) ?>" data-white="<?= h((string)($mainSponsor['white_logo_url'] ?: $mainSponsor['logo_url'])) ?>" alt="<?= h((string)$mainSponsor['name']) ?>">
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </article>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const stage = document.getElementById('nextMatchStage');
  const graphic = document.getElementById('nextMatchGraphic');
  const downloadButtons = Array.from(document.querySelectorAll('.js-next-match-download'));
  const caption = document.getElementById('nextMatchCaption');
  const backgroundInput = document.getElementById('nextMatchBackground');
  const backgroundDropzone = document.getElementById('nextMatchBackgroundDropzone');
  const backgroundDropzoneTitle = document.getElementById('nextMatchBackgroundDropzoneTitle');
  const backgroundDropzoneDetail = document.getElementById('nextMatchBackgroundDropzoneDetail');
  const backgroundPreview = document.getElementById('nextMatchBackgroundPreview');
  const status = document.getElementById('nextMatchStatus');
  const captionFormatButtons = Array.from(document.querySelectorAll('.js-caption-format'));
  const insertSponsorDetailsSelect = document.getElementById('nextMatchInsertSponsorDetails');
  const businessSponsors = <?= json_encode($nextMatchBusinessSponsors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const channelInputs = Array.from(document.querySelectorAll('.js-next-match-channel'));
  const publishSelectedButton = document.getElementById('nextMatchPublishSelected');
  const postTypeInputs = Array.from(document.querySelectorAll('input[name="nextMatchPostType"]'));
  const captionsByPostType = {
    next_match: <?= json_encode($nextMatchCaptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    matchday: <?= json_encode($matchdayCaptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
  };
  const currentPostType = () => {
    const checked = postTypeInputs.find((input) => input.checked);
    return checked ? checked.value : 'next_match';
  };
  postTypeInputs.forEach((input) => {
    input.addEventListener('change', () => {
      caption.value = (captionsByPostType[input.value] || captionsByPostType.next_match).facebook || '';
    });
  });
  const fixtureId = <?= (int)$fixtureId ?>;
  const csrfToken = <?= json_encode($nextMatchCsrfToken, JSON_UNESCAPED_SLASHES) ?>;
  const confirmBeforeLivePost = <?= !empty($publishingPreferences['global']['confirm_before_live_post']) ? 'true' : 'false' ?>;
  let defaultBackground = <?= json_encode($backgroundImageUrl, JSON_UNESCAPED_SLASHES) ?>;
  const packCanvasWidth = <?= (int)$templatePackCanvasWidth ?>;
  const packCanvasHeight = <?= (int)$templatePackCanvasHeight ?>;
  const packBackgroundFit = <?= json_encode($templatePackBackgroundFit, JSON_UNESCAPED_SLASHES) ?>;

  const resize = () => {
    const scale = Math.min(1, stage.clientWidth / packCanvasWidth);
    graphic.style.transform = `scale(${scale})`;
    stage.style.height = `${packCanvasHeight * scale}px`;
  };
  resize();
  window.addEventListener('resize', resize);

  const waitForAssets = () => Promise.all([
    document.fonts?.ready || Promise.resolve(),
    ...Array.from(graphic.querySelectorAll('img')).map((image) => image.complete ? Promise.resolve() : new Promise((resolve) => {
      image.addEventListener('load', resolve, { once: true });
      image.addEventListener('error', resolve, { once: true });
    })),
  ]);

  const render = async () => {
    await waitForAssets();
    const clone = graphic.cloneNode(true);
    clone.removeAttribute('id');
    clone.style.transform = 'none';
    clone.style.position = 'fixed';
    clone.style.left = '-12000px';
    clone.style.top = '0';
    clone.style.background = 'transparent';
    const clonedBackground = clone.querySelector('.next-match-card__background');
    if (clonedBackground) clonedBackground.style.visibility = 'hidden';
    document.body.append(clone);
    try {
      const overlayCanvas = await html2canvas(clone, { useCORS: true, backgroundColor: null, scale: 1, width: packCanvasWidth, height: packCanvasHeight, windowWidth: packCanvasWidth, windowHeight: packCanvasHeight, imageTimeout: 0 });
      const outputCanvas = document.createElement('canvas');
      outputCanvas.width = packCanvasWidth;
      outputCanvas.height = packCanvasHeight;
      const context = outputCanvas.getContext('2d');
      if (!context) throw new Error('The image canvas could not be created.');

      context.fillStyle = '#050505';
      context.fillRect(0, 0, packCanvasWidth, packCanvasHeight);
      const sourceWidth = backgroundPreview.naturalWidth;
      const sourceHeight = backgroundPreview.naturalHeight;
      if (sourceWidth > 0 && sourceHeight > 0) {
        if (packBackgroundFit === 'stretch') {
          context.drawImage(backgroundPreview, 0, 0, packCanvasWidth, packCanvasHeight);
        } else {
          const scale = packBackgroundFit === 'contain'
            ? Math.min(packCanvasWidth / sourceWidth, packCanvasHeight / sourceHeight)
            : Math.max(packCanvasWidth / sourceWidth, packCanvasHeight / sourceHeight);
          const renderWidth = sourceWidth * scale;
          const renderHeight = sourceHeight * scale;
          const offsetX = (packCanvasWidth - renderWidth) / 2;
          const offsetY = (packCanvasHeight - renderHeight) / 2;
          context.drawImage(backgroundPreview, offsetX, offsetY, renderWidth, renderHeight);
        }
      }
      context.drawImage(overlayCanvas, 0, 0, packCanvasWidth, packCanvasHeight);
      return outputCanvas;
    } finally {
      clone.remove();
    }
  };

  const setStatus = (type, message) => {
    platformStatusRows.clear();
    status.className = `alert mb-0 alert-${type}`;
    status.textContent = message;
  };
  const platformStatusRows = new Map();
  const platformLabel = (platform) => platform === 'x' ? 'X' : platform[0].toUpperCase() + platform.slice(1);
  const initialisePublishingStatuses = (platforms) => {
    platformStatusRows.clear();
    status.replaceChildren();
    status.className = 'next-match-status-list';
    platforms.forEach((platform) => {
      const row = document.createElement('div');
      row.className = 'alert mb-0 alert-secondary';
      row.textContent = `Waiting to post to ${platformLabel(platform)}…`;
      status.append(row);
      platformStatusRows.set(platform, row);
    });
  };
  const setPlatformStatus = (platform, type, message) => {
    const row = platformStatusRows.get(platform);
    if (!row) return;
    row.className = `alert mb-0 alert-${type}`;
    row.textContent = message;
  };
  const plainCharacters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
  const boldCharacters = [
    ...Array.from({ length: 26 }, (_, index) => String.fromCodePoint(0x1D5D4 + index)),
    ...Array.from({ length: 26 }, (_, index) => String.fromCodePoint(0x1D5EE + index)),
    ...Array.from({ length: 10 }, (_, index) => String.fromCodePoint(0x1D7EC + index)),
  ];
  const italicLowercase = Array.from({ length: 26 }, (_, index) => String.fromCodePoint(0x1D44E + index));
  italicLowercase[7] = 'ℎ';
  const italicCharacters = [
    ...Array.from({ length: 26 }, (_, index) => String.fromCodePoint(0x1D434 + index)),
    ...italicLowercase,
    ...'0123456789',
  ];
  const createCharacterMap = (characters) => new Map(Array.from(plainCharacters).map((character, index) => [character, characters[index]]));
  const boldMap = createCharacterMap(boldCharacters);
  const italicMap = createCharacterMap(italicCharacters);
  const clearMap = new Map();
  Array.from(plainCharacters).forEach((character, index) => {
    clearMap.set(boldCharacters[index], character);
    clearMap.set(italicCharacters[index], character);
  });
  const styleCaptionText = (text, style) => {
    if (style === 'underline') {
      return Array.from(text.replaceAll('\u0332', '')).map((character) => /\s/u.test(character) ? character : character + '\u0332').join('');
    }
    if (style === 'clear') {
      return Array.from(text).filter((character) => character !== '\u0332').map((character) => clearMap.get(character) || character).join('');
    }
    const characterMap = style === 'italic' ? italicMap : boldMap;
    return Array.from(text).map((character) => characterMap.get(character) || character).join('');
  };
  captionFormatButtons.forEach((button) => button.addEventListener('click', () => {
    const start = caption.selectionStart;
    const end = caption.selectionEnd;
    if (start === end) {
      setStatus('info', 'Select the text you want to format first.');
      caption.focus();
      return;
    }
    const replacement = styleCaptionText(caption.value.slice(start, end), button.dataset.style || 'bold');
    caption.setRangeText(replacement, start, end, 'select');
    caption.dispatchEvent(new Event('input', { bubbles: true }));
    caption.focus();
  }));
  if (insertSponsorDetailsSelect) {
    insertSponsorDetailsSelect.addEventListener('change', () => {
      const sponsor = businessSponsors[Number(insertSponsorDetailsSelect.value)];
      insertSponsorDetailsSelect.value = '';
      if (!sponsor) return;

      const lines = [sponsor.name];
      if (sponsor.address) lines.push(sponsor.address);
      if (sponsor.phone) lines.push(sponsor.phone);
      if (sponsor.website) lines.push(sponsor.website);
      const snippet = lines.join('\n');

      const start = caption.selectionStart ?? caption.value.length;
      const end = caption.selectionEnd ?? caption.value.length;
      const needsLeadingBreak = start > 0 && caption.value[start - 1] !== '\n';
      const insertion = (needsLeadingBreak ? '\n' : '') + snippet;
      caption.setRangeText(insertion, start, end, 'end');
      caption.dispatchEvent(new Event('input', { bubbles: true }));
      caption.focus();
    });
  }
  const downloadCanvas = (canvas) => {
    const link = document.createElement('a');
    link.href = canvas.toDataURL('image/png');
    link.download = `next-match-fixture-${fixtureId}.png`;
    document.body.append(link); link.click(); link.remove();
  };

  const acceptedBackgroundTypes = new Set(['image/png', 'image/jpeg', 'image/webp']);
  let backgroundDropzoneResetTimer = null;
  const resetBackgroundDropzone = () => {
    if (backgroundDropzoneResetTimer) window.clearTimeout(backgroundDropzoneResetTimer);
    backgroundDropzoneResetTimer = null;
    backgroundDropzone.classList.remove('is-uploading', 'is-success', 'is-error', 'is-dragover');
    backgroundDropzoneTitle.textContent = 'Drop an image here';
    backgroundDropzoneDetail.textContent = 'or click to choose';
  };
  const setBackgroundDropzoneState = (state, title, detail, resetAfter = false) => {
    if (backgroundDropzoneResetTimer) window.clearTimeout(backgroundDropzoneResetTimer);
    backgroundDropzoneResetTimer = null;
    backgroundDropzone.classList.remove('is-uploading', 'is-success', 'is-error', 'is-dragover');
    backgroundDropzone.classList.add(`is-${state}`);
    backgroundDropzoneTitle.textContent = title;
    backgroundDropzoneDetail.textContent = detail;
    if (resetAfter) backgroundDropzoneResetTimer = window.setTimeout(resetBackgroundDropzone, 20000);
  };
  const previewBackgroundFile = (file) => {
    const previewUrl = URL.createObjectURL(file);
    backgroundPreview.addEventListener('load', () => URL.revokeObjectURL(previewUrl), { once: true });
    backgroundPreview.src = previewUrl;
    backgroundPreview.style.display = 'block';
  };
  const uploadBackgroundFile = async (file) => {
    if (!acceptedBackgroundTypes.has(file.type)) {
      setBackgroundDropzoneState('error', 'Unsupported image', 'Choose a PNG, JPEG or WebP image.', true);
      return;
    }
    previewBackgroundFile(file);
    setBackgroundDropzoneState('uploading', 'Uploading background…', 'Please wait while the image is saved.');
    backgroundInput.disabled = true;
    try {
      const data = new FormData();
      data.set('csrf_token', csrfToken);
      data.set('fixture_id', String(fixtureId));
      data.set('background_image', file);
      const response = await fetch('save_next_match_background.php', { method: 'POST', body: data });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'The background could not be saved.');
      defaultBackground = result.image_url;
      backgroundPreview.src = result.image_url;
      setBackgroundDropzoneState('success', 'Background saved', result.message || 'The new image is ready to use.', true);
    } catch (error) {
      backgroundPreview.src = defaultBackground;
      setBackgroundDropzoneState('error', 'Upload failed', error.message || 'The background could not be saved.', true);
    } finally {
      backgroundInput.disabled = false;
      backgroundInput.value = '';
    }
  };
  backgroundInput.addEventListener('change', () => {
    const file = backgroundInput.files?.[0];
    if (file) uploadBackgroundFile(file);
  });
  ['dragenter', 'dragover'].forEach((eventName) => backgroundDropzone.addEventListener(eventName, (event) => {
    event.preventDefault();
    event.stopPropagation();
    backgroundDropzone.classList.add('is-dragover');
  }));
  ['dragleave', 'dragend'].forEach((eventName) => backgroundDropzone.addEventListener(eventName, (event) => {
    event.preventDefault();
    event.stopPropagation();
    backgroundDropzone.classList.remove('is-dragover');
  }));
  backgroundDropzone.addEventListener('drop', (event) => {
    event.preventDefault();
    event.stopPropagation();
    backgroundDropzone.classList.remove('is-dragover');
    const file = event.dataTransfer?.files?.[0];
    if (file) uploadBackgroundFile(file);
  });
  downloadButtons.forEach((downloadButton) => downloadButton.addEventListener('click', async () => {
    downloadButtons.forEach((button) => { button.disabled = true; });
    try { downloadCanvas(await render()); } catch (error) { setStatus('danger', error.message || 'The image could not be generated.'); }
    finally { downloadButtons.forEach((button) => { button.disabled = false; }); }
  }));

  const publishToPlatform = async (platform, text, imageData, postType) => {
    const data = new FormData();
    data.set('csrf_token', csrfToken);
    data.set('fixture_id', String(fixtureId));
    data.set('target', platform);
    data.set('caption', text);
    data.set('image_data', imageData);
    data.set('post_type', postType);
    const response = await fetch('post_next_match.php', { method: 'POST', body: data });
    const responseText = await response.text();
    let result;
    try {
      result = JSON.parse(responseText);
    } catch (parseError) {
      throw new Error(response.ok
        ? 'The publishing service returned an invalid response.'
        : `The publishing service failed (HTTP ${response.status}).`);
    }
    if (!response.ok || !result.ok) throw new Error(result.message || `${platform} post failed.`);
    return result.message;
  };

  publishSelectedButton.addEventListener('click', async () => {
    const selectedPlatforms = channelInputs.filter((input) => input.checked).map((input) => input.value);
    if (selectedPlatforms.length === 0) { setStatus('danger', 'Select at least one social channel.'); return; }
    const text = caption.value.trim();
    if (!text) { setStatus('danger', 'Add the post text first.'); return; }
    const postType = currentPostType();
    const postTypeLabel = postType === 'matchday' ? 'Matchday' : 'Next Match';
    const platformLabels = selectedPlatforms.map(platformLabel);
    if (confirmBeforeLivePost && !(await window.hubConfirm(`Publish this ${postTypeLabel} post to ${platformLabels.join(', ')}?`, { actionLabel: 'Publish', actionClass: 'btn-primary' }))) return;
    const xWindow = selectedPlatforms.includes('x') ? window.open('', '_blank') : null;
    publishSelectedButton.disabled = true;
    channelInputs.forEach((input) => { input.disabled = true; });
    initialisePublishingStatuses(selectedPlatforms);
    selectedPlatforms.forEach((platform) => setPlatformStatus(platform, 'info', `Preparing ${platformLabel(platform)} post…`));
    try {
      const canvas = await render();
      const imageData = canvas.toDataURL('image/png');
      for (const platform of selectedPlatforms) {
        const label = platformLabel(platform);
        setPlatformStatus(platform, 'info', platform === 'x' ? 'Opening X composer…' : `Posting to ${label}…`);
        if (platform === 'x') {
          if (xWindow) xWindow.location.href = `https://x.com/intent/tweet?text=${encodeURIComponent(text)}`;
          downloadCanvas(canvas);
          const shareRecord = new FormData();
          shareRecord.set('csrf_token', csrfToken); shareRecord.set('fixture_id', String(fixtureId)); shareRecord.set('post_type', postType); shareRecord.set('caption', text); shareRecord.set('image_url', `/match_next_match.php?fixture_id=${fixtureId}`);
          fetch('/admin/record_manual_share.php', { method: 'POST', body: shareRecord }).catch(() => {});
          setPlatformStatus('x', xWindow ? 'success' : 'danger', xWindow ? 'X composer opened and graphic downloaded.' : 'X popup was blocked. The graphic was downloaded.');
          continue;
        }
        try {
          const resultMessage = await publishToPlatform(platform, text, imageData, postType);
          setPlatformStatus(platform, 'success', resultMessage || `Posted to ${label} successfully.`);
        } catch (error) {
          setPlatformStatus(platform, 'danger', `${label} failed: ${error.message || 'post failed'}`);
        }
      }
    } catch (error) {
      if (xWindow && !xWindow.closed) xWindow.close();
      selectedPlatforms.forEach((platform) => {
        const row = platformStatusRows.get(platform);
        if (row && !row.classList.contains('alert-success')) {
          setPlatformStatus(platform, 'danger', `${platformLabel(platform)} failed: ${error.message || 'the graphic could not be prepared.'}`);
        }
      });
    } finally {
      publishSelectedButton.disabled = false;
      channelInputs.forEach((input) => { input.disabled = false; });
    }
  });
})();
</script>

<?php require_once $layoutFooter; ?>
