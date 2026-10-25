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
$startingXiCaptions = [];
$halfTimeCaptions = [];
$fullTimeCaptions = [];
$fixtureSponsorCredit = getMatchSponsorshipPublicCredit($pdo, $fixtureId);
// Half-time / full-time scores for the post text: the saved score, else the score so far from the match events.
require_once __DIR__ . '/lib/matchday_record.php';
$capScores = [];
$capSummary = null;
foreach (['half_time', 'full_time'] as $capKey) {
          $capRow = $pdo->query('SELECT ' . $capKey . '_home_score h, ' . $capKey . '_away_score a FROM match_fixtures WHERE id = ' . (int)$fixtureId)->fetch(PDO::FETCH_ASSOC) ?: [];
          if (($capRow['h'] ?? null) !== null && ($capRow['a'] ?? null) !== null) {
                    $capScores[$capKey] = [(int)$capRow['h'], (int)$capRow['a']];
          } else {
                    $capSummary ??= matchday_record_summary($pdo, (int)$fixtureId);
                    $capScores[$capKey] = [(int)$capSummary['home_score'], (int)$capSummary['away_score']];
          }
}
require_once __DIR__ . '/lib/next_match_graphic.php';
require_once __DIR__ . '/lib/event_graphic_data.php';
$earlyType = next_match_graphic_type($_GET['type'] ?? '');
$extraCaptions = [];
$scoreContext = static fn(string $k): array => [
          'score' => $capScores[$k][0] . ' - ' . $capScores[$k][1],
          'score_full' => $homeTeam . ' ' . $capScores[$k][0] . ' - ' . $capScores[$k][1] . ' ' . $awayTeam,
];
foreach (['facebook', 'instagram', 'x'] as $socialChannel) {
          $captionContext = [
                    'home_team' => $homeTeam,
                    'away_team' => $awayTeam,
                    'fixture_sponsor_credit' => $fixtureSponsorCredit,
          ];
          $nextMatchCaptions[$socialChannel] = social_post_resolve_caption($socialChannel, 'next_match', $fixture, $captionContext);
          $matchdayCaptions[$socialChannel] = social_post_resolve_caption($socialChannel, 'matchday', $fixture, $captionContext);
          $startingXiCaptions[$socialChannel] = social_post_resolve_caption($socialChannel, 'starting_xi', $fixture, $captionContext);
          $halfTimeCaptions[$socialChannel] = social_post_resolve_caption($socialChannel, 'half_time', $fixture, $captionContext + $scoreContext('half_time'));
          $fullTimeCaptions[$socialChannel] = social_post_resolve_caption($socialChannel, 'full_time', $fixture, $captionContext + $scoreContext('full_time'));
          foreach (['nextMatchCaptions', 'matchdayCaptions', 'startingXiCaptions', 'halfTimeCaptions', 'fullTimeCaptions'] as $captionSet) {
                    if ($fixtureSponsorCredit !== '' && !str_contains((string)${$captionSet}[$socialChannel], $fixtureSponsorCredit)) {
                              ${$captionSet}[$socialChannel] = rtrim((string)${$captionSet}[$socialChannel]) . "\n\n" . $fixtureSponsorCredit;
                    }
          }
}
// Post text for the in-game graphics: built from the chosen event (goal, cards, penalty) or the score (kick-off, second half).
if (in_array($earlyType, ['kickoff', 'second_half'], true)) {
          $kScore = $earlyType === 'kickoff' ? [0, 0] : $capScores['half_time'];
          foreach (['facebook', 'instagram', 'x'] as $socialChannel) {
                    $extraCaptions[$earlyType][$socialChannel] = social_post_resolve_caption($socialChannel, $earlyType, $fixture, $captionContext + [
                              'score' => $kScore[0] . ' - ' . $kScore[1],
                              'score_full' => $homeTeam . ' ' . $kScore[0] . ' - ' . $kScore[1] . ' ' . $awayTeam,
                    ]);
          }
} elseif (nm_event_graphic_is_event_type($earlyType)) {
          $evNow = nm_event_graphic_resolve($pdo, (int)$fixtureId, $earlyType);
          foreach (['facebook', 'instagram', 'x'] as $socialChannel) {
                    $extraCaptions[$earlyType][$socialChannel] = $evNow === null ? '' : social_post_resolve_caption($socialChannel, $earlyType, $fixture, $captionContext + [
                              'minute' => $evNow['minute'] !== '' ? $evNow['minute'] . "'" : '',
                              'player' => $earlyType === 'penalty_saved' ? ((nm_event_graphic_keeper($pdo, (int)$fixtureId)['name'] ?? '') ?: 'Our keeper') : $evNow['player_name'],
                              'team_full' => $evNow['team'],
                              'score' => $evNow['score'][0] . ' - ' . $evNow['score'][1],
                              'score_full' => $evNow['home_name'] . ' ' . $evNow['score'][0] . ' - ' . $evNow['score'][1] . ' ' . $evNow['away_name'],
                    ]);
          }
          // Cards: name the player (with shirt number) and credit their sponsors in the post text.
          $cardSubject = null;
          if ($evNow !== null && in_array($earlyType, ['yellow_card', 'red_card'], true) && trim((string)$evNow['player_name']) !== '') {
                    $cardSubject = ['name' => (string)$evNow['player_name'], 'id' => (int)$evNow['player_id'], 'side' => (string)$evNow['side']];
          } elseif ($evNow !== null && $earlyType === 'penalty_saved' && ($cardKeeper = nm_event_graphic_keeper($pdo, (int)$fixtureId)) !== null && $cardKeeper['name'] !== '') {
                    $cardSubject = ['name' => $cardKeeper['name'], 'id' => $cardKeeper['id'], 'side' => 'svfc'];
          }
          if ($cardSubject !== null) {
                    $cardLines = [];
                    $cardSponsors = [];
                    $cardNum = '';
                    if ($cardSubject['side'] === 'svfc' && $cardSubject['id'] > 0) {
                              $cardPl = $pdo->prepare('SELECT squad_number FROM players WHERE id = :id');
                              $cardPl->execute([':id' => $cardSubject['id']]);
                              $cardSq = $cardPl->fetchColumn();
                              $cardNum = ($cardSq !== false && $cardSq !== null && $cardSq !== '') ? '#' . (int)$cardSq . ' ' : '';
                              $cardQ = $pdo->prepare("SELECT DISTINCT sp.name FROM sponsorships s JOIN sponsors sp ON sp.id = s.sponsor_id
                                        WHERE s.player_id = :p AND s.season_id = :season AND s.ended_at IS NULL ORDER BY FIELD(LOWER(s.slot), 'home', 'away', 'third'), s.id DESC");
                              $cardQ->execute([':p' => $cardSubject['id'], ':season' => (int)$seasonId]);
                              $cardSponsors = array_values(array_filter(array_map('trim', $cardQ->fetchAll(PDO::FETCH_COLUMN) ?: [])));
                    }
                    $cardWho = $cardNum . $cardSubject['name'] . ($cardSubject['side'] === 'svfc' ? ' (Saltcoats Victoria)' : '');
                    foreach (['facebook', 'instagram', 'x'] as $socialChannel) {
                              $cap = (string)$extraCaptions[$earlyType][$socialChannel];
                              $add = [];
                              if (!str_contains($cap, $cardSubject['name'])) {
                                        $add[] = $cardWho;
                              }
                              if ($cardSponsors) {
                                        $add[] = 'Player sponsored by ' . implode(' & ', $cardSponsors);
                              }
                              if ($add) {
                                        $extraCaptions[$earlyType][$socialChannel] = rtrim($cap) . "\n\n" . implode("\n", $add);
                              }
                    }
          }
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
require_once __DIR__ . '/lib/next_match_graphic.php';
$templatePackCanvasWidth = NEXT_MATCH_CARD_WIDTH;
$templatePackCanvasHeight = NEXT_MATCH_CARD_HEIGHT;
$nextMatchType = next_match_graphic_type($_GET['type'] ?? '');
$nextMatchCols = next_match_graphic_cols($nextMatchType);
$subPairs = [];
$subOptions = ['pitch' => [], 'bench' => [], 'rest' => []];
$subCaptionText = '';
if ($nextMatchType === 'substitution') {
          require_once __DIR__ . '/lib/matchday_record.php';
          next_match_graphic_ensure_schema($pdo);
          $subRaw = json_decode((string)$pdo->query('SELECT substitution_graphic_json FROM match_fixtures WHERE id = ' . (int)$fixtureId)->fetchColumn(), true);
          $subPairs = is_array($subRaw) ? $subRaw : [];
          $subPlayers = [];
          foreach ($pdo->query("SELECT id, name FROM players WHERE active = 1 AND status = 'current' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) as $subP) {
                    $subPlayers[(int)$subP['id']] = (string)$subP['name'];
          }
          // Player sponsors (home / away / third shirt slots) for the post text.
          $subSponsors = [];
          $subSpStmt = $pdo->prepare("SELECT s.player_id, sp.name FROM sponsorships s JOIN sponsors sp ON sp.id = s.sponsor_id WHERE s.season_id = :season AND s.ended_at IS NULL AND sp.name IS NOT NULL AND TRIM(sp.name) <> '' ORDER BY FIELD(LOWER(s.slot), 'home', 'away', 'third'), s.id DESC");
          $subSpStmt->execute([':season' => (int)($fixture['season_id'] ?? 0)]);
          foreach ($subSpStmt->fetchAll(PDO::FETCH_ASSOC) as $subSpRow) {
                    $subSponsors[(int)$subSpRow['player_id']][trim((string)$subSpRow['name'])] = true;
          }
          $subSponsorText = static fn(int $id): string => isset($subSponsors[$id]) ? implode(', ', array_keys($subSponsors[$id])) : '';
          $subRecord = matchday_record_load($pdo, (int)$fixtureId);
          $subStarters = [];
          $subBench = [];
          foreach (($subRecord['lineups']['svfc'] ?? []) as $subRow) {
                    if (empty($subRow['player_id'])) {
                              continue;
                    }
                    if ((int)$subRow['is_starting'] === 1) {
                              $subStarters[] = (int)$subRow['player_id'];
                    } else {
                              $subBench[] = (int)$subRow['player_id'];
                    }
          }
          // Who is on the pitch now: starters, less anyone already taken off, plus anyone already on (recorded subs).
          $nameToId = array_flip($subPlayers);
          $onPitch = $subStarters;
          foreach (($subRecord['subs']['svfc'] ?? []) as $recSub) {
                    $offId = $nameToId[(string)$recSub['player_off_name']] ?? 0;
                    $onId = $nameToId[(string)$recSub['player_on_name']] ?? 0;
                    $onPitch = array_values(array_diff($onPitch, [$offId]));
                    if ($onId > 0) {
                              $onPitch[] = $onId;
                    }
                    $subBench = array_values(array_diff($subBench, [$onId]));
          }
          $subNum = [];
          foreach (($subRecord['lineups']['svfc'] ?? []) as $subRow) {
                    if (!empty($subRow['player_id']) && $subRow['shirt_number'] !== null) {
                              $subNum[(int)$subRow['player_id']] = (int)$subRow['shirt_number'];
                    }
          }
          foreach ($subStarters as $subSlot => $subStarterId) {
                    $subNum[$subStarterId] ??= $subSlot + 1;
          }
          $subOptions['pitch'] = array_values(array_unique($onPitch));
          $subOptions['bench'] = array_values(array_diff(array_unique($subBench), $subOptions['pitch']));
          $subOptions['rest'] = array_values(array_diff(array_keys($subPlayers), $subOptions['pitch'], $subOptions['bench']));
          $subLines = [];
          $subDesc = static function (int $id) use ($subPlayers, $subNum, $subSponsorText): string {
                    $txt = $subPlayers[$id] . (isset($subNum[$id]) ? ' (' . $subNum[$id] . ')' : '');
                    $sp = $subSponsorText($id);
                    return $txt . ($sp !== '' ? ' · sponsored by ' . $sp : '');
          };
          foreach ($subPairs as $subPair) {
                    if (isset($subPlayers[(int)($subPair['off'] ?? 0)], $subPlayers[(int)($subPair['on'] ?? 0)])) {
                              $subLines[] = "⬅️ " . $subDesc((int)$subPair['off']) . "\n➡️ " . $subDesc((int)$subPair['on']);
                    }
          }
          $subCaptionText = "🔄 " . (count($subLines) > 1 ? 'SUBSTITUTIONS' : 'SUBSTITUTION') . ($subLines ? "\n\n" . implode("\n\n", $subLines) : '');
}
$htHome = 0;
$htAway = 0;
if ($nextMatchType === 'half_time' || $nextMatchType === 'full_time') {
          require_once __DIR__ . '/lib/matchday_record.php';
          $htPrefix = $nextMatchType === 'full_time' ? 'full_time' : 'half_time';
          $htRow = $pdo->query('SELECT ' . $htPrefix . '_home_score h, ' . $htPrefix . '_away_score a FROM match_fixtures WHERE id = ' . (int)$fixtureId)->fetch(PDO::FETCH_ASSOC) ?: [];
          if ($htRow['h'] !== null && $htRow['a'] !== null) {
                    $htHome = (int)$htRow['h'];
                    $htAway = (int)$htRow['a'];
          } else {
                    $htSummary = matchday_record_summary($pdo, (int)$fixtureId);
                    $htHome = (int)$htSummary['home_score'];
                    $htAway = (int)$htSummary['away_score'];
          }
}
$xiSquad = [];
$xiStarters = [];
$xiSubs = [];
$xiCaptain = 0;
$xiNums = [];
if ($nextMatchType === 'starting_xi') {
          require_once __DIR__ . '/lib/matchday_record.php';
          $xiSquad = $pdo->query("SELECT id, name, squad_number FROM players WHERE active = 1 AND status = 'current' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
          foreach ((matchday_record_load($pdo, (int)$fixtureId)['lineups']['svfc'] ?? []) as $xiRow) {
                    if (empty($xiRow['player_id'])) {
                              continue;
                    }
                    if ($xiRow['shirt_number'] !== null) {
                              $xiNums[(int)$xiRow['player_id']] = (int)$xiRow['shirt_number'];
                    }
                    if ((int)$xiRow['is_starting'] === 1) {
                              $xiStarters[] = (int)$xiRow['player_id'];
                              if (!empty($xiRow['is_captain'])) {
                                        $xiCaptain = (int)$xiRow['player_id'];
                              }
                    } else {
                              $xiSubs[] = (int)$xiRow['player_id'];
                    }
          }
          // Squad number is the suggestion for anyone without a shirt number on this line-up.
          $xiKnown = [];
          foreach ($xiSquad as $xiP) {
                    $xiKnown[(int)$xiP['id']] = $xiNums[(int)$xiP['id']] ?? ($xiP['squad_number'] !== null ? (int)$xiP['squad_number'] : '');
          }
}
next_match_graphic_ensure_schema($pdo);
$nextMatchCardUrl = '/admin/match_next_match_card.php?fixture_id=' . (int)$fixtureId . '&type=' . $nextMatchType . '&v=' . time();
$nextMatchPlayers = next_match_graphic_players_with_shots($pdo);
$nextMatchChoice = null;
next_match_graphic_ensure_schema($pdo);
$nextMatchView = $pdo->query('SELECT ' . $nextMatchCols['zoom'] . ' AS z, ' . $nextMatchCols['x'] . ' AS x, ' . $nextMatchCols['y'] . ' AS y FROM match_fixtures WHERE id = ' . (int)$fixtureId)->fetch(PDO::FETCH_ASSOC) ?: ['z' => 100, 'x' => 50, 'y' => 22];
$nextMatchHasUpload = trim((string)$pdo->query('SELECT ' . $nextMatchCols['bg'] . ' FROM match_fixtures WHERE id = ' . (int)$fixtureId)->fetchColumn()) !== '';
?>

<style>
.xi-subrow { cursor: pointer; padding: .4rem .6rem; margin-bottom: .2rem; border: 1px solid transparent; border-radius: .55rem; transition: background .12s, border-color .12s; }
.xi-subrow:hover { background: #f8f4ee; }
.xi-subrow.is-on { background: #fbf1cf; border-color: #E2B72E; font-weight: 600; }
.xi-subrow .xi-tick { color: #450A14; opacity: 0; font-size: 1rem; }
.xi-subrow.is-on .xi-tick { opacity: 1; }
</style>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&amp;family=Montserrat:wght@300;400;500;600;700;800;900&amp;family=Oswald:wght@300;400;500;600;700&amp;family=Poppins:wght@300;400;500;600;700;800;900&amp;family=Roboto:wght@300;400;500;600;700;800;900&amp;family=Roboto+Condensed:wght@300;400;500;600;700;800;900&amp;display=swap">
<style>
.nm-step-title { display: flex; align-items: center; gap: .6rem; font-weight: 700; margin-bottom: .85rem; }
.nm-step-num { display: inline-flex; width: 1.7rem; height: 1.7rem; align-items: center; justify-content: center; border-radius: 50%; background: #6f1d2b; color: #fff; font-size: .85rem; flex: 0 0 auto; }
.nm-field-label { font-size: .8rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; margin-bottom: .4rem; }
.nm-current { display: flex; align-items: center; gap: .9rem; padding: .7rem; margin-bottom: 1rem; border-radius: .75rem; background: #f8f4ee; border: 1px solid #eadfce; }
.nm-current img { width: 64px; height: 80px; object-fit: cover; border-radius: .5rem; background: #ddd; flex: 0 0 auto; }
.nm-current__label { font-size: .75rem; color: #8a6d3b; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.nm-current__name { font-size: 1.05rem; font-weight: 700; }
.nm-thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(76px, 1fr)); gap: .5rem; }
.nm-thumb { position: relative; padding: 0; border: 3px solid transparent; border-radius: .6rem; overflow: hidden; aspect-ratio: 4 / 5; background: #e5e7eb; cursor: pointer; }
.nm-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.nm-thumb.is-selected { border-color: #6f1d2b; box-shadow: 0 0 0 2px #fff inset; }
.nm-thumb.is-selected::after { content: "\f00c"; font-family: "Font Awesome 6 Free"; font-weight: 900; position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border-radius: 50%; background: #6f1d2b; color: #fff; font-size: 12px; display: flex; align-items: center; justify-content: center; }
.nm-empty { display: flex; gap: .8rem; padding: .9rem; border-radius: .75rem; background: #fff7e6; border: 1px dashed #e0b42a; font-size: .92rem; }
.nm-empty i { font-size: 1.4rem; color: #b8860b; margin-top: .15rem; }
.nm-download { display: flex; width: 100%; min-height: 3rem; align-items: center; justify-content: center; gap: .6rem; margin-bottom: 1.1rem; border: 1px solid #198754; border-radius: .7rem; background: #fff; color: #146c43; font-weight: 700; }
.nm-download:hover { background: #198754; color: #fff; }
.nm-channels { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; margin-bottom: .5rem; }
.nm-chip { position: relative; display: flex; flex-direction: column; align-items: center; gap: .3rem; padding: .8rem .4rem; border: 2px solid #e5e7eb; border-radius: .8rem; background: #fff; cursor: pointer; font-size: .85rem; font-weight: 600; text-align: center; transition: border-color .15s, background .15s; }
.nm-chip > i:first-of-type { font-size: 1.6rem; }
.nm-chip--facebook > i:first-of-type { color: #1877f2; } .nm-chip--instagram > i:first-of-type { color: #d62976; } .nm-chip--x > i:first-of-type { color: #111; }
.nm-chip input { position: absolute; opacity: 0; pointer-events: none; }
.nm-chip__tick { position: absolute; top: 6px; right: 6px; font-size: 1rem; color: #198754; opacity: 0; }
.nm-chip:has(input:checked) { border-color: #198754; background: #effaf3; }
.nm-chip:has(input:checked) .nm-chip__tick { opacity: 1; }
.nm-chip:has(input:focus-visible) { outline: 3px solid #86b7fe; }
.nm-chip:has(input:disabled) { opacity: .6; cursor: default; }
.nm-post { min-height: 3rem; font-weight: 700; }
</style>
<style>.next-match-frame { display: block; border: 0; width: 1080px; height: 1350px; transform-origin: top left; background: #111; }
@media (max-width: 1199.98px) {
  .next-match-editor > .col-xl-2 { order: 1; }
  .next-match-editor > .col-xl-7 { order: 2; }
  .next-match-editor > .col-xl-3 { order: 3; }
  .next-match-editor { --bs-gutter-y: 1rem; }
  .next-match-editor .col-xl-2 .nav { flex-direction: row !important; flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none; padding-bottom: .15rem; }
  .next-match-editor .col-xl-2 .nav::-webkit-scrollbar { display: none; }
  .next-match-editor .col-xl-2 .nav .nav-link { white-space: nowrap; flex: 0 0 auto; padding: .55rem .9rem; min-height: 44px; display: inline-flex; align-items: center; }
  .next-match-editor .col-xl-2 .nav .nm-field-label { display: none; }
  .next-match-editor .col-xl-7 { max-width: 560px; margin-left: auto; margin-right: auto; }
}
@media (max-width: 575.98px) {
  .next-match-editor .col-xl-7 { max-width: none; }
  .next-match-editor .card-body.d-grid { padding: .9rem; }
  .nm-current img { width: 52px; height: 65px; }
  .nm-chip { padding: .7rem .2rem; font-size: .8rem; }
  .nm-download, .nm-post, .next-match-editor .btn { min-height: 46px; }
  .next-match-editor textarea, .next-match-editor select, .next-match-editor input { font-size: 16px; }
  #nmMediaDialog { max-height: 92vh !important; }
}
</style>
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
      <h1 class="page-hero-title">Graphics</h1>
      <p class="page-hero-subtitle"><?= h($homeTeam . ' v ' . $awayTeam) ?></p>
    </div>
  </div>
</div>
<?php endif; ?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/matches.php">Fixtures</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><a href="/admin/match.php?id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>"><?= h((string)$fixture['opponent']) ?></a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Graphics</span></nav>
<?php renderFixtureTabs((int)$fixtureId, (int)$seasonId, 'next_match'); ?>

<div class="row g-4 next-match-editor">
  <div class="col-12 col-xl-2">
    <div class="card shadow-sm border-0">
      <div class="card-body p-2">
        <div class="nm-field-label px-2 pt-1">Graphic</div>
        <nav class="nav nav-pills flex-xl-column gap-1" aria-label="Graphic type">
          <a class="nav-link<?= $nextMatchType === 'next_match' ? ' active' : '' ?>" href="match_next_match.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>&amp;type=next_match"><i class="fa-solid fa-calendar-day me-2" aria-hidden="true"></i>Next Match</a>
          <a class="nav-link<?= $nextMatchType === 'matchday' ? ' active' : '' ?>" href="match_next_match.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>&amp;type=matchday"><i class="fa-solid fa-futbol me-2" aria-hidden="true"></i>Matchday</a>
          <a class="nav-link<?= $nextMatchType === 'starting_xi' ? ' active' : '' ?>" href="match_next_match.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>&amp;type=starting_xi"><i class="fa-solid fa-people-group me-2" aria-hidden="true"></i>Starting XI</a>
          <a class="nav-link<?= $nextMatchType === 'kickoff' ? ' active' : '' ?>" href="match_next_match.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>&amp;type=kickoff"><i class="fa-solid fa-play me-2" aria-hidden="true"></i>Kick off</a>
          <a class="nav-link<?= $nextMatchType === 'half_time' ? ' active' : '' ?>" href="match_next_match.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>&amp;type=half_time"><i class="fa-solid fa-stopwatch me-2" aria-hidden="true"></i>Half Time</a>
          <a class="nav-link<?= $nextMatchType === 'second_half' ? ' active' : '' ?>" href="match_next_match.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>&amp;type=second_half"><i class="fa-solid fa-forward-step me-2" aria-hidden="true"></i>Second half</a>
          <a class="nav-link<?= $nextMatchType === 'full_time' ? ' active' : '' ?>" href="match_next_match.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>&amp;type=full_time"><i class="fa-solid fa-flag-checkered me-2" aria-hidden="true"></i>Full Time</a>
          <a class="nav-link<?= $nextMatchType === 'substitution' ? ' active' : '' ?>" href="match_next_match.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>&amp;type=substitution"><i class="fa-solid fa-arrow-right-arrow-left me-2" aria-hidden="true"></i>Substitution</a>
          <div class="nm-field-label px-2 pt-3">In-game</div>
          <?php foreach ([['goal', 'Goal', 'fa-futbol'], ['yellow_card', 'Yellow card', 'fa-square'], ['red_card', 'Red card', 'fa-square'], ['penalty_awarded', 'Penalty', 'fa-circle-dot']] as [$igKey, $igLabel, $igIcon]): ?>
          <a class="nav-link<?= ($nextMatchType === $igKey || ($igKey === 'penalty_awarded' && str_starts_with($nextMatchType, 'penalty'))) ? ' active' : '' ?>" href="match_next_match.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>&amp;type=<?= h($igKey) ?>"><i class="fa-solid <?= h($igIcon) ?> me-2" aria-hidden="true"></i><?= h($igLabel) ?></a>
          <?php endforeach; ?>
        </nav>
      </div>
    </div>
  </div>
  <div class="col-12 col-xl-3">
    <div class="card shadow-sm border-0">
      <div class="card-body d-grid gap-3">
        <div>
          <div class="visually-hidden">
            <input type="radio" name="nextMatchPostType" id="nextMatchPostTypeNextMatch" value="next_match"<?= $nextMatchType === 'next_match' ? ' checked' : '' ?>>
            <input type="radio" name="nextMatchPostType" id="nextMatchPostTypeMatchday" value="matchday"<?= $nextMatchType === 'matchday' ? ' checked' : '' ?>>
            <input type="radio" name="nextMatchPostType" id="nextMatchPostTypeStartingXi" value="starting_xi"<?= $nextMatchType === 'starting_xi' ? ' checked' : '' ?>>
            <input type="radio" name="nextMatchPostType" id="nextMatchPostTypeHalfTime" value="half_time"<?= $nextMatchType === 'half_time' ? ' checked' : '' ?>>
            <input type="radio" name="nextMatchPostType" id="nextMatchPostTypeFullTime" value="full_time"<?= $nextMatchType === 'full_time' ? ' checked' : '' ?>>
            <input type="radio" name="nextMatchPostType" id="nextMatchPostTypeSubstitution" value="substitution"<?= $nextMatchType === 'substitution' ? ' checked' : '' ?>>
            <?php foreach (['goal', 'yellow_card', 'red_card', 'penalty', 'penalty_awarded', 'penalty_saved', 'penalty_missed', 'kickoff', 'second_half'] as $igKey): ?>
            <input type="radio" name="nextMatchPostType" id="nextMatchPostType_<?= h($igKey) ?>" value="<?= h($igKey) ?>"<?= $nextMatchType === $igKey ? ' checked' : '' ?>>
            <?php endforeach; ?>
          </div>
          <label for="nextMatchCaption" class="form-label fw-semibold">Post text</label>
          <div class="next-match-caption-toolbar" role="toolbar" aria-label="Post text formatting">
            <button type="button" class="btn btn-sm btn-outline-secondary js-caption-format" data-style="bold" aria-label="Make selected text bold" title="Bold selected text"><strong>B</strong></button>
            <button type="button" class="btn btn-sm btn-outline-secondary js-caption-format" data-style="italic" aria-label="Make selected text italic" title="Italicise selected text"><em>I</em></button>
            <button type="button" class="btn btn-sm btn-outline-secondary js-caption-format" data-style="underline" aria-label="Underline selected text" title="Underline selected text"><span class="text-decoration-underline">U</span></button>
            <button type="button" class="btn btn-sm btn-outline-secondary js-caption-format" data-style="clear" aria-label="Remove formatting from selected text" title="Remove formatting"><i class="fa-solid fa-eraser" aria-hidden="true"></i></button>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="nextMatchCopyText" style="flex:0 0 auto" aria-label="Copy post text to clipboard" title="Copy post text to clipboard"><i class="fa-regular fa-copy me-1" aria-hidden="true"></i><span>Copy text</span></button>
          </div>
          <textarea class="form-control" id="nextMatchCaption" rows="11"><?= h((['matchday' => $matchdayCaptions, 'starting_xi' => $startingXiCaptions, 'half_time' => $halfTimeCaptions, 'full_time' => $fullTimeCaptions, 'substitution' => ['facebook' => $subCaptionText]][$nextMatchType] ?? ($extraCaptions[$nextMatchType] ?? $nextMatchCaptions))['facebook']) ?></textarea>
        </div>
        <?php if ($nextMatchType === 'substitution'): ?>
        <div class="next-match-control" id="subBuilder">
          <div class="nm-step-title"><span class="nm-step-num"><i class="fa-solid fa-arrow-right-arrow-left" aria-hidden="true"></i></span>Substitutions</div>
          <div class="form-text mb-2">Pick who comes off and who goes on. Add more rows for a double or triple change (up to 4).</div>
          <div id="subRows" class="d-grid gap-3 mb-2"></div>
          <button type="button" class="btn btn-sm btn-outline-secondary" id="subAdd"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add another substitution</button>
          <div class="form-text mt-2" id="subStatus">Changes save automatically and update the preview.</div>
          <template id="subOptionsTpl">
            <?php foreach (['pitch' => 'On the pitch', 'bench' => 'Bench', 'rest' => 'Rest of squad'] as $subGroup => $subLabel): if (!$subOptions[$subGroup]) { continue; } ?>
              <optgroup label="<?= h($subLabel) ?>" data-group="<?= h($subGroup) ?>">
                <?php foreach ($subOptions[$subGroup] as $subId): ?><option value="<?= (int)$subId ?>"<?= isset($subNum[$subId]) ? ' data-num="' . (int)$subNum[$subId] . '"' : '' ?><?= $subSponsorText($subId) !== '' ? ' data-sp="' . h($subSponsorText($subId)) . '"' : '' ?>><?= h($subPlayers[$subId] ?? '') ?></option><?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </template>
        </div>
        <?php endif; ?>
        <?php if (str_starts_with($nextMatchType, 'penalty')): ?>
        <div class="next-match-control">
          <div class="nm-step-title"><span class="nm-step-num"><i class="fa-solid fa-circle-dot" aria-hidden="true"></i></span>What happened?</div>
          <div class="btn-group w-100" role="group" aria-label="Penalty graphic">
            <?php foreach (['penalty_awarded' => 'Awarded', 'penalty_saved' => 'Saved', 'penalty_missed' => 'Missed'] as $penKey => $penLabel): ?>
            <a class="btn btn-sm <?= $nextMatchType === $penKey ? 'btn-brand' : 'btn-outline-secondary' ?>" href="match_next_match.php?fixture_id=<?= (int)$fixtureId ?>&amp;season_id=<?= (int)$seasonId ?>&amp;type=<?= h($penKey) ?>"><?= h($penLabel) ?></a>
            <?php endforeach; ?>
          </div>
          <div class="form-text mt-2"><?= ['penalty_awarded' => 'A penalty given to either team, before it is taken.', 'penalty_saved' => 'An opposition penalty saved by our keeper.', 'penalty_missed' => 'A penalty that was missed (not saved).'][$nextMatchType] ?? '' ?></div>
        </div>
        <?php endif; ?>
        <?php if (nm_event_graphic_is_event_type($nextMatchType)):
          $evCands = nm_event_graphic_candidates($pdo, (int)$fixtureId, $nextMatchType);
          $evChoice = nm_event_graphic_choice($pdo, (int)$fixtureId, $nextMatchType);
          $evManual = is_array($evChoice['manual'] ?? null) ? $evChoice['manual'] : [];
          $evPickedId = (int)($evChoice['event_id'] ?? 0);
          $evSquad = $pdo->query("SELECT id, name FROM players WHERE active = 1 AND status = 'current' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
          $evNoun = ['goal' => 'goal', 'yellow_card' => 'yellow card', 'red_card' => 'red card', 'penalty' => 'penalty', 'penalty_awarded' => 'penalty awarded', 'penalty_saved' => 'penalty save', 'penalty_missed' => 'penalty miss'][$nextMatchType];
        ?>
        <div class="next-match-control" id="evPicker">
          <div class="nm-step-title"><span class="nm-step-num"><i class="fa-solid fa-bolt" aria-hidden="true"></i></span>Which <?= h($evNoun) ?>?</div>
          <?php if (!$evCands && !$evManual): ?><div class="form-text mb-2">No <?= h($evNoun) ?> recorded on the Events page yet. Record it there, or enter the details below.</div><?php endif; ?>
          <select class="form-select form-select-sm mb-2" id="evSelect">
            <?php foreach ($evCands as $evIdx => $evC): ?><option value="<?= (int)$evC['id'] ?>"<?= (!$evManual && ($evPickedId === (int)$evC['id'] || ($evPickedId === 0 && $evIdx === count($evCands) - 1))) ? ' selected' : '' ?>><?= h($evC['label']) ?></option><?php endforeach; ?>
            <option value="manual"<?= $evManual || !$evCands ? ' selected' : '' ?>>Enter the details myself…</option>
          </select>
          <div id="evManual" class="gap-2 mt-2" style="display:none;flex-direction:column">
            <select class="form-select form-select-sm" id="evSide"><option value="svfc"<?= ($evManual['side'] ?? 'svfc') === 'svfc' ? ' selected' : '' ?>>Saltcoats Victoria</option><option value="opponent"<?= ($evManual['side'] ?? '') === 'opponent' ? ' selected' : '' ?>><?= h((string)$fixture['opponent']) ?></option></select>
            <select class="form-select form-select-sm" id="evPlayer"><option value=""><?= $nextMatchType === 'penalty_saved' ? 'Goalkeeper (from line-up)…' : 'Choose a player…' ?></option><?php foreach ($evSquad as $evP): ?><option value="<?= (int)$evP['id'] ?>"<?= (int)($evManual['player_id'] ?? 0) === (int)$evP['id'] ? ' selected' : '' ?>><?= h($evP['name']) ?></option><?php endforeach; ?></select>
            <input type="text" class="form-control form-control-sm" id="evPlayerName" placeholder="Player name (opposition)" value="<?= h((string)($evManual['player_name'] ?? '')) ?>">
            <div class="d-flex gap-2">
              <input type="text" class="form-control form-control-sm" id="evMinute" placeholder="Minute" style="max-width:6rem" value="<?= h((string)($evManual['minute'] ?? '')) ?>">
              <?php if ($nextMatchType === 'penalty'): ?><select class="form-select form-select-sm" id="evOutcome"><?php foreach (['scored' => 'Scored', 'missed' => 'Missed', 'saved' => 'Saved'] as $evK => $evL): ?><option value="<?= h($evK) ?>"<?= ($evManual['outcome'] ?? 'scored') === $evK ? ' selected' : '' ?>><?= h($evL) ?></option><?php endforeach; ?></select><?php endif; ?>
              <?php if ($nextMatchType === 'goal'): ?><label class="small d-flex align-items-center gap-1 text-nowrap"><input type="checkbox" class="form-check-input mt-0" id="evOwn"<?= !empty($evManual['own_goal']) ? ' checked' : '' ?>>Own goal</label><?php endif; ?>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="evApply">Use these details</button>
          </div>
          <div class="form-text mt-2" id="evStatus">Pick the one you want a graphic for. Events come from the Events tab.</div>
        </div>
        <?php endif; ?>
        <?php if ($nextMatchType === 'half_time' || $nextMatchType === 'full_time'): ?>
        <div class="next-match-control" id="htScore">
          <div class="nm-step-title"><span class="nm-step-num"><i class="fa-solid fa-stopwatch" aria-hidden="true"></i></span><?= $nextMatchType === 'full_time' ? 'Full-time' : 'Half-time' ?> score</div>
          <div class="d-flex align-items-center gap-2">
            <label class="small flex-grow-1"><?= h(!empty($fixture['is_home']) ? 'Saltcoats Victoria' : (string)$fixture['opponent']) ?><input type="number" min="0" max="99" class="form-control" id="htHome" value="<?= $htHome ?>"></label>
            <label class="small flex-grow-1"><?= h(!empty($fixture['is_home']) ? (string)$fixture['opponent'] : 'Saltcoats Victoria') ?><input type="number" min="0" max="99" class="form-control" id="htAway" value="<?= $htAway ?>"></label>
          </div>
          <div class="form-text" id="htStatus">Filled from the recorded events. Change it here if it is wrong; it saves automatically.</div>
        </div>
        <?php endif; ?>
        <?php if ($nextMatchType === 'starting_xi'): ?>
        <div class="next-match-control" id="xiPicker">
          <div class="nm-step-title"><span class="nm-step-num"><i class="fa-solid fa-people-group" aria-hidden="true"></i></span>Pick the team</div>
          <div class="nm-field-label d-flex justify-content-between"><span>Starting XI</span><span class="d-flex"><span class="text-center" style="width:3.6rem" title="Shirt number">No.</span><span class="text-center" style="width:2.6rem" title="Captain">Capt</span></span></div>
          <div class="d-grid gap-2 mb-3" id="xiStarters">
            <?php for ($i = 0; $i < 11; $i++): $xiPid = $xiStarters[$i] ?? 0; ?>
              <div class="d-flex align-items-center gap-2">
                <span class="fw-bold text-center" style="width:1.6rem"><?= $i + 1 ?></span>
                <select class="form-select form-select-sm xi-starter" aria-label="Player <?= $i + 1 ?>">
                  <option value="">—</option>
                  <?php foreach ($xiSquad as $xiP): ?><option value="<?= (int)$xiP['id'] ?>"<?= $xiPid === (int)$xiP['id'] ? ' selected' : '' ?>><?= h($xiP['name']) ?></option><?php endforeach; ?>
                </select>
                <input type="number" min="0" max="99" class="form-control form-control-sm xi-num px-1 text-center" style="width:3.6rem" placeholder="#" aria-label="Shirt number <?= $i + 1 ?>" value="<?= h((string)($xiPid > 0 ? ($xiNums[$xiPid] ?? ($i + 1)) : '')) ?>">
                <label class="d-flex justify-content-center m-0" style="width:2.6rem" title="Captain"><input type="radio" name="xiCaptain" class="xi-captain form-check-input m-0" aria-label="Captain" value="<?= $i ?>"<?= $xiPid > 0 && $xiPid === $xiCaptain ? ' checked' : '' ?>></label>
              </div>
            <?php endfor; ?>
          </div>
          <div class="nm-field-label">Subs</div>
          <div class="border rounded p-2" style="max-height:15rem;overflow:auto" id="xiSubs">
            <?php foreach ($xiSquad as $xiP): ?>
              <label class="xi-subrow d-flex align-items-center gap-2 small"><input type="checkbox" class="visually-hidden xi-sub" value="<?= (int)$xiP['id'] ?>"<?= in_array((int)$xiP['id'], $xiSubs, true) ? ' checked' : '' ?>><span class="flex-grow-1"><?= h($xiP['name']) ?></span><input type="number" min="0" max="99" class="form-control form-control-sm xi-subnum px-1 text-center" style="width:3.4rem;display:none" placeholder="#" aria-label="Shirt number" value="<?= h((string)($xiKnown[(int)$xiP['id']] ?? '')) ?>"><i class="fa-solid fa-circle-check xi-tick" aria-hidden="true"></i></label>
            <?php endforeach; ?>
          </div>
          <div class="form-text" id="xiStatus">Changes save automatically and update the preview. This is the same line-up as the Line-ups tab.</div>
        </div>
        <?php endif; ?>
        <div class="next-match-control"<?= in_array($nextMatchType, ['substitution', 'goal', 'yellow_card', 'red_card', 'penalty', 'penalty_awarded', 'penalty_saved', 'penalty_missed'], true) ? ' style="display:none"' : '' ?>>
          <div class="nm-step-title"><span class="nm-step-num">1</span>Background image</div>
          <label class="next-match-background-dropzone" id="nextMatchBackgroundDropzone" for="nextMatchBackground">
            <i class="fa-solid fa-cloud-arrow-up" aria-hidden="true"></i>
            <strong id="nextMatchBackgroundDropzoneTitle">Drop an image here</strong>
            <span class="small" id="nextMatchBackgroundDropzoneDetail">or click to upload</span>
            <input class="visually-hidden" type="file" id="nextMatchBackground" accept="image/png,image/jpeg,image/webp">
          </label>
          <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="nmChooseMedia"><i class="fa-solid fa-images me-1" aria-hidden="true"></i>Choose from media</button>
            <?php if ($nextMatchHasUpload): ?><button type="button" class="btn btn-sm btn-outline-secondary" id="nextMatchClearBackground">Remove background</button><?php endif; ?>
          </div>
          <dialog id="nmMediaDialog" style="width:min(900px,94vw);max-height:86vh;border:0;border-radius:.75rem;padding:1rem">
            <div class="d-flex justify-content-between align-items-center mb-2"><strong>Choose a photo from media</strong><button type="button" class="btn btn-sm btn-outline-secondary" id="nmMediaClose">Close</button></div>
            <div id="nmMediaGrid" class="nm-thumbs" style="grid-template-columns:repeat(auto-fill,minmax(130px,1fr));max-height:70vh;overflow:auto"></div>
          </dialog>
        </div>
        <div class="next-match-control"<?= in_array($nextMatchType, ['substitution', 'goal', 'yellow_card', 'red_card', 'penalty', 'penalty_awarded', 'penalty_saved', 'penalty_missed'], true) ? ' style="display:none"' : '' ?>>
          <details class="nm-zoom-details">
          <summary class="nm-step-title" style="cursor:pointer"><span class="nm-step-num"><i class="fa-solid fa-magnifying-glass-plus" aria-hidden="true"></i></span>Zoom the photo</summary>
          <label class="nm-field-label d-flex justify-content-between" for="nmZoom"><span>Zoom</span><span id="nmZoomValue"><?= (int)$nextMatchView['z'] ?>%</span></label>
          <input type="range" class="form-range" id="nmZoom" min="100" max="300" step="5" value="<?= (int)$nextMatchView['z'] ?>">
          <label class="nm-field-label" for="nmPanX">Left / right</label>
          <input type="range" class="form-range" id="nmPanX" min="0" max="100" value="<?= (int)$nextMatchView['x'] ?>">
          <label class="nm-field-label" for="nmPanY">Up / down</label>
          <input type="range" class="form-range" id="nmPanY" min="0" max="100" value="<?= (int)$nextMatchView['y'] ?>">
          <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="nmViewReset">Reset zoom</button>
          </details>
        </div>
        <div class="next-match-control nm-share">
          <div class="nm-step-title"><span class="nm-step-num">3</span>Share or save</div>
          <button type="button" class="nm-download js-next-match-download">
            <i class="fa-solid fa-download" aria-hidden="true"></i><span>Download image &amp; copy text</span>
          </button>
          <div hidden>
          <div class="nm-field-label">Post to</div>
          <div class="nm-channels">
            <label class="nm-chip nm-chip--facebook"><input class="js-next-match-channel" type="checkbox" value="facebook" id="nextMatchChannelFacebook"><i class="fa-brands fa-facebook" aria-hidden="true"></i><span>Facebook</span><i class="fa-solid fa-circle-check nm-chip__tick" aria-hidden="true"></i></label>
            <label class="nm-chip nm-chip--instagram"><input class="js-next-match-channel" type="checkbox" value="instagram" id="nextMatchChannelInstagram"><i class="fa-brands fa-instagram" aria-hidden="true"></i><span>Instagram</span><i class="fa-solid fa-circle-check nm-chip__tick" aria-hidden="true"></i></label>
            <label class="nm-chip nm-chip--x"><input class="js-next-match-channel" type="checkbox" value="x" id="nextMatchChannelX"><i class="fa-brands fa-x-twitter" aria-hidden="true"></i><span>X</span><i class="fa-solid fa-circle-check nm-chip__tick" aria-hidden="true"></i></label>
          </div>
          <div class="form-text mb-3">X opens a new post with your text and downloads the image to attach.</div>
          <button type="button" class="btn btn-brand w-100 nm-post" id="nextMatchPublishSelected">
            <i class="fa-solid fa-paper-plane me-2" aria-hidden="true"></i>Post now
          </button>
          </div>
        </div>
        <div class="d-none" id="nextMatchStatus" role="status" aria-live="polite"></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-xl-7">
    <div class="card shadow-sm border-0 overflow-hidden w-100">
      <div class="card-body p-0">
        <div class="next-match-stage" id="nextMatchStage">
          <iframe class="next-match-frame" id="nextMatchGraphic" src="<?= h($nextMatchCardUrl) ?>" title="Next Match graphic preview" width="1080" height="1350" scrolling="no"></iframe>
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
  const status = document.getElementById('nextMatchStatus');
  const captionFormatButtons = Array.from(document.querySelectorAll('.js-caption-format'));
  const copyTextButton = document.getElementById('nextMatchCopyText');
  if (copyTextButton) {
    const copyLabel = copyTextButton.querySelector('span');
    copyTextButton.addEventListener('click', async () => {
      const text = caption.value;
      let ok = false;
      try { await navigator.clipboard.writeText(text); ok = true; } catch (e) {
        try { caption.focus(); caption.select(); ok = document.execCommand('copy'); caption.setSelectionRange(text.length, text.length); } catch (e2) { ok = false; }
      }
      copyLabel.textContent = ok ? 'Copied!' : 'Copy failed';
      copyTextButton.classList.toggle('btn-success', ok);
      copyTextButton.classList.toggle('btn-outline-secondary', !ok);
      window.setTimeout(() => { copyLabel.textContent = 'Copy text'; copyTextButton.classList.remove('btn-success'); copyTextButton.classList.add('btn-outline-secondary'); }, 2000);
    });
  }
  const insertSponsorDetailsSelect = document.getElementById('nextMatchInsertSponsorDetails');
  const businessSponsors = <?= json_encode($nextMatchBusinessSponsors, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  const channelInputs = Array.from(document.querySelectorAll('.js-next-match-channel'));
  const publishSelectedButton = document.getElementById('nextMatchPublishSelected');
  const postTypeInputs = Array.from(document.querySelectorAll('input[name="nextMatchPostType"]'));
  const captionsByPostType = {
    next_match: <?= json_encode($nextMatchCaptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    matchday: <?= json_encode($matchdayCaptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    starting_xi: <?= json_encode($startingXiCaptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    half_time: <?= json_encode($halfTimeCaptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    full_time: <?= json_encode($fullTimeCaptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    substitution: { facebook: <?= json_encode($subCaptionText, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?> },
    ...<?= json_encode($extraCaptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
  };
  const currentPostType = () => {
    const checked = postTypeInputs.find((input) => input.checked);
    return checked ? checked.value : 'next_match';
  };
  postTypeInputs.forEach((input) => {
    input.addEventListener('change', () => {
      caption.value = (captionsByPostType[input.value] || captionsByPostType.next_match).facebook || '';
      if (typeof refreshPreview === 'function') refreshPreview();
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
    const scale = stage.clientWidth / packCanvasWidth;
    graphic.style.transform = `scale(${scale})`;
    stage.style.height = `${packCanvasHeight * scale}px`;
  };
  resize();
  window.addEventListener('resize', resize);
  if (window.ResizeObserver) new ResizeObserver(resize).observe(stage);
  window.addEventListener('load', resize);

  const postAction = async (action, extra = {}) => {
    const data = new FormData();
    data.set('csrf_token', csrfToken);
    data.set('fixture_id', String(fixtureId));
    data.set('action', action);
    data.set('type', currentPostType());
    Object.entries(extra).forEach(([key, value]) => data.set(key, String(value)));
    const response = await fetch('next_match_graphic_action.php', { method: 'POST', body: data });
    const result = await response.json().catch(() => ({ ok: false, message: 'The server returned an unexpected response.' }));
    if (!response.ok || !result.ok) throw new Error(result.message || 'That did not work.');
    return result;
  };
  const refreshPreview = () => { graphic.src = `match_next_match_card.php?fixture_id=${fixtureId}&type=${currentPostType()}&v=${Date.now()}`; };

  // The graphic is drawn on the server (headless Chrome) so what you see here is exactly what is posted.
  // Edits on this page save after a short pause; flush any that are still waiting before an image is made.
  window.nmFlushers = [];
  const debounced = (fn, wait) => {
    let timer = null;
    const run = async () => { timer = null; await fn(); };
    const d = () => { clearTimeout(timer); timer = setTimeout(run, wait); };
    window.nmFlushers.push(async () => { if (timer) { clearTimeout(timer); await run(); } });
    return d;
  };
  const render = async () => {
    for (const flush of window.nmFlushers) await flush();
    const result = await postAction('render', { type: currentPostType() });
    const image = new Image();
    await new Promise((resolve, reject) => {
      image.onload = resolve;
      image.onerror = () => reject(new Error('The generated graphic could not be loaded.'));
      image.src = result.image_url;
    });
    const outputCanvas = document.createElement('canvas');
    outputCanvas.width = packCanvasWidth;
    outputCanvas.height = packCanvasHeight;
    outputCanvas.getContext('2d').drawImage(image, 0, 0, packCanvasWidth, packCanvasHeight);
    return outputCanvas;
  };

  const clearBackground = document.getElementById('nextMatchClearBackground');
  if (clearBackground) {
    clearBackground.addEventListener('click', async () => {
      try { await postAction('clear_background'); window.location.reload(); }
      catch (error) { setStatus('danger', error.message); }
    });
  }

  const nmZoom = document.getElementById('nmZoom');
  if (nmZoom) {
    const nmX = document.getElementById('nmPanX'), nmY = document.getElementById('nmPanY'), nmLabel = document.getElementById('nmZoomValue');
    let nmTimer = null;
    const saveView = () => {
      nmLabel.textContent = nmZoom.value + '%';
      clearTimeout(nmTimer);
      nmTimer = setTimeout(async () => {
        try { await postAction('set_view', { zoom: nmZoom.value, x: nmX.value, y: nmY.value }); refreshPreview(); }
        catch (error) { setStatus('danger', error.message); }
      }, 350);
    };
    [nmZoom, nmX, nmY].forEach((input) => input.addEventListener('input', saveView));
    document.getElementById('nmViewReset').addEventListener('click', () => {
      nmZoom.value = 100; nmX.value = 50; nmY.value = 22; saveView();
    });
  }

  const evPicker = document.getElementById('evPicker');
  if (evPicker) {
    const evSelect = document.getElementById('evSelect'), evManual = document.getElementById('evManual'), evStatus = document.getElementById('evStatus');
    const evSide = document.getElementById('evSide'), evPlayer = document.getElementById('evPlayer'), evPlayerName = document.getElementById('evPlayerName');
    const showManual = () => {
      evManual.style.display = evSelect.value === 'manual' ? 'flex' : 'none';
      const ours = evSide.value === 'svfc';
      const noPlayer = currentPostType() === 'penalty_awarded';
      const isSave = currentPostType() === 'penalty_saved';
      if (isSave) { evSide.style.display = 'none'; evSide.value = 'svfc'; evPlayer.style.display = ''; evPlayerName.style.display = 'none'; return; }
      evPlayer.style.display = ours && !noPlayer ? '' : 'none';
      evPlayerName.style.display = !ours && !noPlayer ? '' : 'none';
    };
    const saveChoice = async (choice) => {
      try { evStatus.textContent = 'Saving…'; await postAction('save_event_choice', { choice: JSON.stringify(choice) }); window.location.reload(); }
      catch (error) { evStatus.textContent = error.message; }
    };
    evSelect.addEventListener('change', () => { showManual(); if (evSelect.value !== 'manual') saveChoice({ event_id: parseInt(evSelect.value, 10) }); });
    evSide.addEventListener('change', showManual);
    document.getElementById('evApply').addEventListener('click', () => {
      const own = document.getElementById('evOwn'), outcome = document.getElementById('evOutcome');
      saveChoice({ manual: {
        side: evSide.value, player_id: parseInt(evPlayer.value, 10) || 0, player_name: evPlayerName.value, minute: document.getElementById('evMinute').value,
        outcome: outcome ? outcome.value : 'scored', own_goal: own ? own.checked : false,
      } });
    });
    showManual();
  }

  const htScore = document.getElementById('htScore');
  if (htScore) {
    const htHome = document.getElementById('htHome'), htAway = document.getElementById('htAway'), htStatus = document.getElementById('htStatus');
    const scoreKey = currentPostType();
    const scoreInfo = <?= json_encode(['home' => $homeTeam, 'away' => $awayTeam, 'half_time' => $capScores['half_time'], 'full_time' => $capScores['full_time']], JSON_UNESCAPED_UNICODE) ?>;
    const lineFor = (h, a) => scoreInfo.home + ' ' + h + ' - ' + a + ' ' + scoreInfo.away;
    let scoreLine = lineFor(scoreInfo[scoreKey][0], scoreInfo[scoreKey][1]);
    const doSaveScore = async () => {
      try {
        htStatus.textContent = 'Saving…';
        const h = parseInt(htHome.value, 10) || 0, a = parseInt(htAway.value, 10) || 0;
        await postAction('save_score', { home: h, away: a });
        // keep the post text's score line in step with the boxes
        const newLine = lineFor(h, a);
        caption.value = caption.value.split(scoreLine).join(newLine);
        Object.keys(captionsByPostType[scoreKey] || {}).forEach((ch) => { captionsByPostType[scoreKey][ch] = String(captionsByPostType[scoreKey][ch]).split(scoreLine).join(newLine); });
        scoreLine = newLine;
        htStatus.textContent = 'Saved.';
        refreshPreview();
      } catch (error) { htStatus.textContent = error.message; }
    };
    const saveScore = debounced(doSaveScore, 400);
    [htHome, htAway].forEach((input) => input.addEventListener('input', saveScore));
  }

  const subBuilder = document.getElementById('subBuilder');
  if (subBuilder) {
    const rowsEl = document.getElementById('subRows'), addBtn = document.getElementById('subAdd'), subStatus = document.getElementById('subStatus');
    const tpl = document.getElementById('subOptionsTpl');
    const initial = <?= json_encode($subPairs, JSON_UNESCAPED_SLASHES) ?>;
    const makeSelect = (placeholder, groups) => {
      const sel = document.createElement('select');
      sel.className = 'form-select form-select-sm';
      sel.innerHTML = '<option value="">' + placeholder + '</option>';
      groups.forEach((g) => { const og = tpl.content.querySelector('optgroup[data-group="' + g + '"]'); if (og) sel.append(og.cloneNode(true)); });
      return sel;
    };
    const addRow = (off, on) => {
      if (rowsEl.children.length >= 4) return;
      const row = document.createElement('div');
      row.className = 'border rounded p-2';
      const offSel = makeSelect('Player coming OFF…', ['pitch', 'bench', 'rest']);
      const onSel = makeSelect('Player coming ON…', ['bench', 'rest', 'pitch']);
      offSel.className += ' mb-2'; if (off) offSel.value = String(off); if (on) onSel.value = String(on);
      const head = document.createElement('div');
      head.className = 'd-flex justify-content-between align-items-center mb-1';
      head.innerHTML = '<span class="nm-field-label mb-0">Substitution</span>';
      const del = document.createElement('button');
      del.type = 'button'; del.className = 'btn btn-sm btn-link text-danger p-0'; del.textContent = 'Remove';
      del.addEventListener('click', () => { row.remove(); save(); });
      head.append(del);
      row.append(head, offSel, onSel);
      row.addEventListener('change', save);
      rowsEl.append(row);
      addBtn.disabled = rowsEl.children.length >= 4;
    };
    const collect = () => Array.from(rowsEl.children).map((row) => { const s = row.querySelectorAll('select'); return { off: parseInt(s[0].value, 10) || 0, on: parseInt(s[1].value, 10) || 0 }; }).filter((p) => p.off && p.on && p.off !== p.on);
    const captionFor = (pairs) => {
      const names = (sel) => { const o = sel.options[sel.selectedIndex]; if (!o) return ''; return o.text + (o.dataset.num ? ' (' + o.dataset.num + ')' : '') + (o.dataset.sp ? ' · sponsored by ' + o.dataset.sp : ''); };
      const lines = Array.from(rowsEl.children).filter((row) => { const s = row.querySelectorAll('select'); return s[0].value && s[1].value && s[0].value !== s[1].value; })
        .map((row) => { const s = row.querySelectorAll('select'); return '⬅️ ' + names(s[0]) + '\n➡️ ' + names(s[1]); });
      return '🔄 ' + (lines.length > 1 ? 'SUBSTITUTIONS' : 'SUBSTITUTION') + (lines.length ? '\n\n' + lines.join('\n\n') : '');
    };
    const doSave = async () => {
      const pairs = collect();
      try {
        subStatus.textContent = 'Saving…';
        await postAction('save_subs', { subs: JSON.stringify(pairs) });
        subStatus.textContent = 'Saved.';
        const text = captionFor(pairs);
        captionsByPostType.substitution = { facebook: text };
        caption.value = text;
        refreshPreview();
      } catch (error) { subStatus.textContent = error.message; }
    };
    const saveSoon = debounced(doSave, 400);
    function save() { addBtn.disabled = rowsEl.children.length >= 4; saveSoon(); }
    addBtn.addEventListener('click', () => { addRow(0, 0); });
    (initial.length ? initial : [{ off: 0, on: 0 }]).forEach((p) => addRow(p.off, p.on));
  }

  const xiPicker = document.getElementById('xiPicker');
  if (xiPicker) {
    const starterSelects = Array.from(xiPicker.querySelectorAll('.xi-starter'));
    const captainRadios = Array.from(xiPicker.querySelectorAll('.xi-captain'));
    const subBoxes = Array.from(xiPicker.querySelectorAll('.xi-sub'));
    const paintSubs = () => subBoxes.forEach((box) => { const row = box.closest('label'); row.classList.toggle('is-on', box.checked); row.querySelector('.xi-subnum').style.display = box.checked ? '' : 'none'; });
    xiPicker.addEventListener('change', paintSubs);
    const numInputs = Array.from(xiPicker.querySelectorAll('.xi-num'));
    const known = <?= json_encode($xiKnown ?? new stdClass(), JSON_UNESCAPED_SLASHES) ?>;
    const xiStatus = document.getElementById('xiStatus');
    starterSelects.forEach((sel, i) => sel.addEventListener('change', () => { numInputs[i].value = sel.value ? String(i + 1) : ''; }));
    const syncChoices = () => {
      const used = new Set(starterSelects.map((sel) => sel.value).filter(Boolean));
      starterSelects.forEach((sel) => Array.from(sel.options).forEach((opt) => { opt.disabled = opt.value !== '' && used.has(opt.value) && sel.value !== opt.value; }));
      subBoxes.forEach((box) => { const isUsed = used.has(box.value); if (isUsed) box.checked = false; box.disabled = isUsed; const row = box.closest('label'); if (isUsed) row.style.setProperty('display', 'none', 'important'); else row.style.removeProperty('display'); });
      captainRadios.forEach((radio, i) => { radio.disabled = !starterSelects[i].value; if (radio.disabled) radio.checked = false; });
    };
    const doSaveLineup = async () => {
      const captainIndex = captainRadios.findIndex((r) => r.checked);
      const payload = {
        starters: starterSelects.map((sel, i) => ({ id: sel.value, num: numInputs[i].value })).filter((p) => p.id),
        subs: subBoxes.filter((b) => b.checked).map((b) => ({ id: b.value, num: b.closest('label').querySelector('.xi-subnum').value })),
        captain: captainIndex >= 0 ? starterSelects[captainIndex].value : '',
      };
      try {
        xiStatus.textContent = 'Saving…';
        await postAction('save_lineup', { lineup: JSON.stringify(payload) });
        xiStatus.textContent = 'Saved.';
        refreshPreview();
      } catch (error) { xiStatus.textContent = error.message; }
    };
    const saveLineupSoon = debounced(doSaveLineup, 400);
    const saveLineup = () => { syncChoices(); paintSubs(); saveLineupSoon(); };
    xiPicker.addEventListener('change', saveLineup);
    syncChoices();
    paintSubs();
  }

  const mediaDialog = document.getElementById('nmMediaDialog');
  if (mediaDialog) {
    const grid = document.getElementById('nmMediaGrid');
    let mediaLoaded = false;
    document.getElementById('nmChooseMedia').addEventListener('click', async () => {
      mediaDialog.showModal();
      if (mediaLoaded) return;
      grid.textContent = 'Loading…';
      try {
        const result = await postAction('list_media');
        grid.replaceChildren();
        mediaLoaded = true;
        if (!result.items.length) grid.textContent = 'No photos in media yet.';
        result.items.forEach((item) => {
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'nm-thumb';
          button.title = item.label;
          button.innerHTML = '<img alt="" loading="lazy">';
          button.firstChild.src = item.url;
          button.addEventListener('click', async () => {
            try { await postAction('use_media', { photo_id: item.id }); window.location.reload(); }
            catch (error) { setStatus('danger', error.message); mediaDialog.close(); }
          });
          grid.append(button);
        });
      } catch (error) { grid.textContent = error.message; }
    });
    document.getElementById('nmMediaClose').addEventListener('click', () => mediaDialog.close());
  }

  const photoPlayers = <?= json_encode($nextMatchPlayers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  let currentShotId = <?= (int)($nextMatchChoice['shot_id'] ?? 0) ?>;
  const playerSelect = document.getElementById('nextMatchPlayer');
  if (playerSelect) {
    const thumbs = document.getElementById('nmThumbs');
    const currentThumb = document.getElementById('nmCurrentThumb');
    const currentName = document.getElementById('nmCurrentName');
    const currentLabel = document.querySelector('.nm-current__label');
    const renderThumbs = () => {
      const player = photoPlayers.find((p) => String(p.id) === playerSelect.value);
      thumbs.replaceChildren();
      (player ? player.shots : []).forEach((shot) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'nm-thumb' + (shot.id === currentShotId ? ' is-selected' : '');
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', shot.id === currentShotId ? 'true' : 'false');
        button.innerHTML = '<img alt="">';
        button.firstChild.src = shot.url;
        button.addEventListener('click', async () => {
          try {
            await postAction('choose', { player_id: player.id, shot_id: shot.id });
            currentShotId = shot.id;
            currentThumb.src = shot.url; currentName.textContent = player.name; currentLabel.textContent = 'Showing now';
            renderThumbs(); refreshPreview();
          } catch (error) { setStatus('danger', error.message); }
        });
        thumbs.append(button);
      });
    };
    renderThumbs();
    playerSelect.addEventListener('change', async () => {
      const player = photoPlayers.find((p) => String(p.id) === playerSelect.value);
      if (!player) return;
      try {
        const result = await postAction('choose', { player_id: player.id, shot_id: player.shots[0].id });
        currentShotId = result.shot_id || player.shots[0].id;
        currentThumb.src = player.shots[0].url; currentName.textContent = player.name; currentLabel.textContent = 'Showing now';
        renderThumbs(); refreshPreview();
      } catch (error) { setStatus('danger', error.message); }
    });
    document.getElementById('nextMatchShuffle').addEventListener('click', async () => {
      try {
        const result = await postAction('shuffle');
        playerSelect.value = String(result.player_id);
        const player = photoPlayers.find((p) => p.id === result.player_id);
        const shot = player && player.shots.find((x) => x.id === result.shot_id);
        currentShotId = result.shot_id;
        if (shot) { currentThumb.src = shot.url; currentName.textContent = player.name; currentLabel.textContent = 'Showing now'; }
        renderThumbs(); refreshPreview();
      } catch (error) { setStatus('danger', error.message); }
    });
  }

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
    // e.g. starting-xi-vale-of-leven-2026-10-24.png
    const kind = ({ next_match: 'next-match', next_match_2: 'next-match-2', matchday: 'matchday', starting_xi: 'starting-xi', half_time: 'half-time', full_time: 'full-time', substitution: 'substitution', goal: 'goal', yellow_card: 'yellow-card', red_card: 'red-card', penalty: 'penalty', penalty_awarded: 'penalty-awarded', penalty_saved: 'penalty-saved', penalty_missed: 'penalty-missed', kickoff: 'kick-off', second_half: 'second-half' })[currentPostType()] || 'graphic';
    const slug = (v) => String(v || '').normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    link.download = [kind, slug(<?= json_encode((string)($fixture['opponent'] ?? '')) ?>), slug(<?= json_encode((string)($fixture['match_date'] ?? '')) ?>) || fixtureId].filter(Boolean).join('-') + '.png';
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
  const uploadBackgroundFile = async (file) => {
    if (!acceptedBackgroundTypes.has(file.type)) {
      setBackgroundDropzoneState('error', 'Unsupported image', 'Choose a PNG, JPEG or WebP image.', true);
      return;
    }
    setBackgroundDropzoneState('uploading', 'Uploading background…', 'Please wait while the image is saved.');
    backgroundInput.disabled = true;
    try {
      const data = new FormData();
      data.set('csrf_token', csrfToken);
      data.set('fixture_id', String(fixtureId));
      data.set('background_image', file);
      data.set('type', currentPostType());
      const response = await fetch('save_next_match_background.php', { method: 'POST', body: data });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'The background could not be saved.');
      defaultBackground = result.image_url;
      refreshPreview();
      setBackgroundDropzoneState('success', 'Background saved', result.message || 'The new image is ready to use.', true);
    } catch (error) {
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
    let copied = false;
    try { await navigator.clipboard.writeText(caption.value); copied = true; } catch (e) {
      try { caption.focus(); caption.select(); copied = document.execCommand('copy'); caption.setSelectionRange(caption.value.length, caption.value.length); } catch (e2) { copied = false; }
    }
    try { downloadCanvas(await render()); setStatus(copied ? 'success' : 'warning', copied ? 'Image downloaded and post text copied.' : 'Image downloaded, but the text could not be copied - use Copy text.'); } catch (error) { setStatus('danger', error.message || 'The image could not be generated.'); }
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
    const postTypeLabel = ({ matchday: 'Matchday', starting_xi: 'Starting XI', half_time: 'Half Time', full_time: 'Full Time', substitution: 'Substitution', goal: 'Goal', yellow_card: 'Yellow Card', red_card: 'Red Card', penalty: 'Penalty', penalty_awarded: 'Penalty Awarded', penalty_saved: 'Penalty Saved', penalty_missed: 'Penalty Missed', kickoff: 'Kick Off', second_half: 'Second Half' })[postType] || 'Next Match';
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
