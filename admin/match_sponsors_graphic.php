<?php

declare(strict_types=1);


// PHASE3B_GUARD_MARKER
require_once __DIR__ . '/auth.php';
if (!hub_auth_has_capability('content_social')) {
    http_response_code(403);
    exit('Access denied.');
}
require_once __DIR__ . '/app_bootstrap.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/lib/template_pack_render.php';
require_once __DIR__ . '/lib/match_sponsor_share.php';

function match_sponsors_graphic_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function match_sponsors_graphic_badge_url(PDO $pdo, string $club, bool $white = false): string
{
    $opponentBadge = matchOpponentBadgeAssetUrl($pdo, $club, $white);
    if ($opponentBadge !== '') {
        return $opponentBadge;
    }

    $slug = match_sponsors_graphic_slug($club);
    if ($slug === '') {
        return '';
    }
    $directory = __DIR__ . '/badges' . ($white ? '/white' : '');
    $exact = [];
    $close = [];
    foreach (is_dir($directory) ? (glob($directory . '/*') ?: []) : [] as $path) {
        if (!is_file($path) || !in_array(strtolower((string) pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
            continue;
        }
        $fileSlug = match_sponsors_graphic_slug((string) pathinfo($path, PATHINFO_FILENAME));
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

$isRender = isset($_GET['render']);
$isLivePreview = isset($_GET['live_preview']);
$app = app_bootstrap_state();
$isAuthenticated = $isRender ? true : $app['isAuthenticated'];
$styleVersion = $app['styleVersion'];

$fixtureId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;
$sponsorRows = $fixture !== null ? match_sponsor_share_rows($pdo, $fixtureId) : [];

$sponsorsByRole = [];
foreach ($sponsorRows as $row) {
    $role = (string) ($row['sponsorship_role'] ?? '');
    if (!isset($sponsorsByRole[$role])) {
        $sponsorsByRole[$role] = $row;
    }
}
$matchDaySponsor = $sponsorsByRole['match_day'] ?? null;
$matchBallSponsor = $sponsorsByRole['match_ball'] ?? null;
$sponsorTiles = array_values(array_filter([$matchDaySponsor, $matchBallSponsor]));

$templatePackContext = $fixture !== null
    ? matchTemplatePackContext($pdo, $fixtureId, 'kick_off', false)
    : [];
$resolvedPackId = (int) ($templatePackContext['pack']['id'] ?? 0);
if ($resolvedPackId === 5) {
    try {
        $latestDraft = template_packs_get_draft($pdo, $resolvedPackId);
        $latestAction = isset($latestDraft['actions']['kick_off']) && is_array($latestDraft['actions']['kick_off'])
            ? $latestDraft['actions']['kick_off']
            : null;
        if ($latestAction !== null) {
            $templatePackContext['brand_settings'] = isset($latestDraft['brand_settings']) && is_array($latestDraft['brand_settings'])
                ? $latestDraft['brand_settings']
                : $templatePackContext['brand_settings'];
            $templatePackContext['action'] = $latestAction;
        }
    } catch (Throwable $draftError) {
        error_log('Sponsor shoutout draft preview resolution failed: ' . $draftError->getMessage());
    }
}
$templatePackBrand = isset($templatePackContext['brand_settings']) && is_array($templatePackContext['brand_settings'])
    ? $templatePackContext['brand_settings'] : [];
$templatePackPrimary = matchTemplatePackColour($templatePackBrand, 'primary', '#5c1428');
$templatePackAccent = matchTemplatePackColour($templatePackBrand, 'accent', '#f0bf1d');
$templatePackText = matchTemplatePackColour($templatePackBrand, 'text', '#ffffff');
$templatePackHeadingFont = matchTemplatePackFont($templatePackBrand, 'heading', 'Poppins');
$templatePackBodyFont = matchTemplatePackFont($templatePackBrand, 'body', 'Poppins');
$templatePackBackground = matchTemplatePackAssetUrl((string) ($templatePackContext['action']['background_path'] ?? ''));

$opponent = $fixture !== null ? trim((string) ($fixture['opponent'] ?? 'Opponent')) : 'Opponent';
$isHome = $fixture !== null ? (int) ($fixture['is_home'] ?? 1) === 1 : true;
$homeTeamName = $isHome ? 'Saltcoats Victoria' : $opponent;
$awayTeamName = $isHome ? $opponent : 'Saltcoats Victoria';
$homeBadge = match_sponsors_graphic_badge_url($pdo, 'Saltcoats Victoria', true);
$awayBadge = $fixture !== null ? match_sponsors_graphic_badge_url($pdo, $opponent, true) : '';
$matchDateDisplay = '';
if ($fixture !== null) {
    $rawDate = trim((string) ($fixture['match_date'] ?? ''));
    if ($rawDate !== '') {
        $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $rawDate);
        $matchDateDisplay = $dateObject instanceof DateTimeImmutable ? $dateObject->format('l j F') : $rawDate;
    }
}
$venueDisplay = $fixture !== null ? trim((string) ($fixture['venue'] ?? '')) : '';
if ($venueDisplay === '') {
    $venueDisplay = $isHome ? 'Campbell Park' : $opponent;
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Match Sponsors Graphic</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&amp;family=Montserrat:wght@400;600;700;800&amp;family=Oswald:wght@400;600;700&amp;family=Poppins:wght@400;600;700;800&amp;family=Roboto:wght@400;500;700&amp;family=Roboto+Condensed:wght@400;600;700&amp;display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="/admin/assets/css/social-publishing.css?v=<?= (int) $styleVersion ?>">

    <style>
        .sponsor-share-stage {
            width: 1080px;
            height: 1350px;
        }

        .sponsor-share-card {
            --pack-primary: <?= safe($templatePackPrimary) ?>;
            --pack-accent: <?= safe($templatePackAccent) ?>;
            --pack-text: <?= safe($templatePackText) ?>;
            position: relative;
            width: 1080px;
            height: 1350px;
            overflow: hidden;
            background-color: var(--pack-primary);
            color: var(--pack-text);
            font-family: "<?= safe($templatePackBodyFont) ?>", "Poppins", Arial, sans-serif;
        }

        .sponsor-share-card__image {
            position: absolute;
            inset: 0;
            z-index: 0;
            background-repeat: no-repeat;
            background-position: center;
            background-size: cover;
            <?php if ($templatePackBackground !== ''): ?>
            background-image: url('<?= safe($templatePackBackground) ?>');
            <?php endif; ?>
        }

        .sponsor-share-card__overlay {
            position: absolute;
            inset: 0;
            z-index: 1;
            background: linear-gradient(180deg, rgba(0, 0, 0, .78) 0%, rgba(0, 0, 0, .35) 30%, rgba(0, 0, 0, .5) 68%, rgba(0, 0, 0, .88) 100%);
        }

        .sponsor-share-card__badges {
            position: absolute;
            z-index: 3;
            top: 70px;
            left: 0;
            right: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 36px;
        }

        .sponsor-share-card__badges img {
            display: block;
            width: 108px;
            height: 108px;
            object-fit: contain;
            filter: drop-shadow(0 6px 14px rgba(0, 0, 0, .5));
        }

        .sponsor-share-card__badges span {
            font-family: "<?= safe($templatePackHeadingFont) ?>", "Poppins", Impact, sans-serif;
            font-size: 40px;
            font-weight: 800;
            opacity: .85;
        }

        .sponsor-share-card__meta {
            position: absolute;
            z-index: 3;
            top: 218px;
            left: 0;
            right: 0;
            text-align: center;
        }

        .sponsor-share-card__meta .fixture {
            font-family: "<?= safe($templatePackBodyFont) ?>", Arial, sans-serif;
            font-weight: 700;
            font-size: 30px;
            text-transform: uppercase;
            letter-spacing: .03em;
        }

        .sponsor-share-card__meta .details {
            margin-top: 8px;
            font-size: 20px;
            opacity: .82;
            font-weight: 500;
        }

        .sponsor-share-card__headline {
            position: absolute;
            z-index: 3;
            top: 330px;
            left: 60px;
            right: 60px;
            text-align: center;
            margin: 0;
            font-family: "<?= safe($templatePackHeadingFont) ?>", "Poppins", Impact, sans-serif;
            font-weight: 800;
            font-size: 58px;
            line-height: 1.08;
            letter-spacing: -.01em;
            text-transform: uppercase;
            color: var(--pack-accent);
        }

        .sponsor-share-card__tiles {
            position: absolute;
            z-index: 3;
            top: 500px;
            left: 70px;
            right: 70px;
            bottom: 150px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: stretch;
            gap: 40px;
        }

        .sponsor-share-tile {
            flex: 0 1 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 22px;
            max-height: 330px;
            background: rgba(255, 255, 255, .96);
            border-radius: 28px;
            padding: 40px 30px;
            box-shadow: 0 18px 40px rgba(0, 0, 0, .35);
        }

        .sponsor-share-tile__role {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: .5em 1.4em;
            border-radius: 999px;
            background: var(--pack-primary);
            color: #fff;
            font-family: "<?= safe($templatePackHeadingFont) ?>", "Poppins", Impact, sans-serif;
            font-weight: 800;
            font-size: 22px;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .sponsor-share-tile__logo {
            max-width: 88%;
            max-height: 220px;
            object-fit: contain;
        }

        .sponsor-share-tile__name {
            font-family: "<?= safe($templatePackHeadingFont) ?>", "Poppins", Impact, sans-serif;
            font-weight: 800;
            font-size: 36px;
            color: #171018;
            text-align: center;
            text-transform: uppercase;
        }

        .sponsor-share-card__footer {
            position: absolute;
            z-index: 3;
            left: 0;
            right: 0;
            bottom: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }

        .sponsor-share-card__footer img {
            width: 56px;
            height: 56px;
            object-fit: contain;
        }

        .sponsor-share-card__footer span {
            font-family: "<?= safe($templatePackHeadingFont) ?>", "Poppins", Impact, sans-serif;
            font-weight: 700;
            font-size: 24px;
            letter-spacing: .04em;
            text-transform: uppercase;
            opacity: .9;
        }

        .sponsor-share-card__empty {
            position: absolute;
            z-index: 3;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 0 100px;
            font-size: 34px;
            font-weight: 600;
        }

        <?php if ($isLivePreview): ?>
        html, body {
            margin: 0;
            padding: 0;
            background: #1c191d;
            overflow: hidden;
        }

        .sponsor-share-stage {
            transform-origin: top left;
        }
        <?php endif; ?>
    </style>
</head>

<body class="<?= safe($isRender ? 'page-render' : 'bg-cream') ?>">
    <?php if (!$isRender && !$isAuthenticated): ?>
        <?php app_render_login_modal('Login Required', 'Enter your username or email and password to access the Saltcoats Victoria sponsor graphic tools.'); ?>
    <?php endif; ?>

    <?php if ($isRender || $isAuthenticated): ?>
        <main class="<?= $isRender ? 'page-main--render' : 'pb-4' ?>">
            <div class="container-fluid">
                <?php if ($fixture === null): ?>
                    <section class="utility-panel">
                        <p class="page-kicker">Graphic Not Found</p>
                        <h1 class="feature-card__title">The requested fixture could not be found.</h1>
                    </section>
                <?php else: ?>
                    <div class="sponsor-share-stage">
                        <div class="sponsor-share-card" aria-label="Match sponsors graphic">
                            <div class="sponsor-share-card__image"></div>
                            <div class="sponsor-share-card__overlay"></div>

                            <?php if ($sponsorTiles === []): ?>
                                <div class="sponsor-share-card__empty">No confirmed Match Day or Match Ball sponsors yet for this fixture.</div>
                            <?php else: ?>
                                <div class="sponsor-share-card__badges">
                                    <?php if ($homeBadge !== ''): ?><img src="<?= safe($homeBadge) ?>" alt="<?= safe($homeTeamName) ?> badge"><?php endif; ?>
                                    <span>v</span>
                                    <?php if ($awayBadge !== ''): ?><img src="<?= safe($awayBadge) ?>" alt="<?= safe($awayTeamName) ?> badge"><?php endif; ?>
                                </div>
                                <div class="sponsor-share-card__meta">
                                    <div class="fixture"><?= safe($homeTeamName) ?> v <?= safe($awayTeamName) ?></div>
                                    <?php if ($matchDateDisplay !== '' || $venueDisplay !== ''): ?>
                                        <div class="details"><?= safe(trim($matchDateDisplay . ($matchDateDisplay !== '' && $venueDisplay !== '' ? ' · ' : '') . $venueDisplay)) ?></div>
                                    <?php endif; ?>
                                </div>
                                <h1 class="sponsor-share-card__headline">Today's Match Sponsors</h1>
                                <div class="sponsor-share-card__tiles">
                                    <?php foreach ($sponsorTiles as $sponsorTile): ?>
                                        <?php
                                        $tileRole = (string) ($sponsorTile['sponsorship_role'] ?? '');
                                        $tileLabel = $tileRole === 'match_ball' ? 'Match Ball Sponsor' : 'Matchday Sponsor';
                                        $tileLogo = match_sponsor_share_logo_url($sponsorTile, false);
                                        $tileName = trim((string) ($sponsorTile['sponsor_name'] ?? 'Sponsor'));
                                        ?>
                                        <div class="sponsor-share-tile">
                                            <span class="sponsor-share-tile__role"><?= safe($tileLabel) ?></span>
                                            <?php if ($tileLogo !== ''): ?>
                                                <img class="sponsor-share-tile__logo" src="<?= safe($tileLogo) ?>" alt="<?= safe($tileName) ?>">
                                            <?php else: ?>
                                                <span class="sponsor-share-tile__name"><?= safe($tileName) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="sponsor-share-card__footer">
                                    <?php if ($homeBadge !== ''): ?><img src="<?= safe($homeBadge) ?>" alt=""><?php endif; ?>
                                    <span>Saltcoats Victoria FC</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    <?php endif; ?>

    <?php app_render_auth_scripts($isAuthenticated); ?>
    <?php if ($isLivePreview): ?>
        <script>
            (() => {
                const stage = document.querySelector('.sponsor-share-stage');
                if (!stage) return;
                const resize = () => {
                    const scale = Math.min(window.innerWidth / 1080, window.innerHeight / 1350);
                    stage.style.transform = `scale(${scale})`;
                };
                window.addEventListener('resize', resize);
                resize();
            })();
        </script>
    <?php endif; ?>
</body>

</html>
