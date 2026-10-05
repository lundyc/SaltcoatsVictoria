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
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

function match_graphic_format_player_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') {
        return '';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $parts = array_values(array_filter($parts, static fn(string $part): bool => $part !== ''));
    if ($parts === []) {
        return '';
    }

    if (count($parts) === 1) {
        return $parts[0];
    }

    $firstName = array_shift($parts);
    $surname = implode(' ', $parts);
    $initial = strtoupper(substr((string) $firstName, 0, 1));

    return $initial . '. ' . $surname;
}

function match_graphic_asset_url(string $path): string
{
    $path = trim($path);
    if ($path === '' || preg_match('#^(?:https?:)?//#i', $path) === 1 || str_contains($path, '?')) {
        return $path;
    }

    if (str_starts_with($path, '/')) {
        $absolutePath = __DIR__ . $path;
    } else {
        $absolutePath = __DIR__ . '/' . ltrim($path, '/');
    }

    if (!is_file($absolutePath)) {
        return $path;
    }

    return $path . '?v=' . rawurlencode((string) filemtime($absolutePath));
}

function match_graphic_player_avatar_url(?string $avatar): string
{
    $avatar = trim((string) $avatar);
    if ($avatar === '') {
        return '';
    }

    return match_graphic_asset_url(players_public_image_url($avatar));
}

function match_graphic_chunk_substitutes(array $substitutes, int $columns): array
{
    $columns = max(1, $columns);
    $chunks = array_fill(0, $columns, []);
    $total = count($substitutes);
    if ($total === 0) {
        return $chunks;
    }

    $perColumn = (int) ceil($total / $columns);
    for ($i = 0; $i < $columns; $i++) {
        $chunks[$i] = array_slice($substitutes, $i * $perColumn, $perColumn);
    }

    return $chunks;
}

function match_graphic_team_abbreviation(string $name): string
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

function match_graphic_badge_path(string $club): string
{
    global $pdo;
    if ($pdo instanceof PDO) {
        $opponentBadge = matchOpponentBadgeAssetUrl($pdo, $club);
        if ($opponentBadge !== '') {
            return $opponentBadge;
        }
    }

    $club = trim($club);
    if ($club === '') {
        return '';
    }

    $overrideFile = __DIR__ . '/data/wosfl_badge_overrides.json';
    $badgeFile = '';

    if (is_file($overrideFile)) {
        $decoded = json_decode((string) file_get_contents($overrideFile), true);
        if (is_array($decoded)) {
            foreach ($decoded as $clubName => $fileName) {
                if (is_string($clubName) && is_string($fileName) && strcasecmp(trim($clubName), $club) === 0) {
                    $badgeFile = trim($fileName);
                    break;
                }
            }
        }
    }

    if ($badgeFile !== '') {
        $relative = 'badges/' . ltrim($badgeFile, '/');
        return is_file(__DIR__ . '/' . $relative) ? $relative : '';
    }

    $slug = strtolower($club);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        return '';
    }

    $relative = 'badges/' . $slug . '.png';
    return is_file(__DIR__ . '/' . $relative) ? $relative : '';
}

function match_graphic_player_name_key(string $name): string
{
    return strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
}

/**
 * @param list<string> $starters
 * @param list<string> $formattedStarters
 * @param list<list<string>> $substituteColumns
 * @param array<string, array<string, mixed>> $playersByName
 */
function match_graphic_render_canvas(
    string $backgroundImage,
    string $titlePrimary,
    string $titleAccent,
    string $opponentPrefix,
    string $homeTeamName,
    string $awayTeamName,
    string $homeTeamAbbr,
    string $awayTeamAbbr,
    array $starters,
    array $formattedStarters,
    string $captain,
    array $substituteColumns,
    array $playersByName,
    bool $showNumbers = false
): void {
    ?>
    <div id="match-graphic" class="starting11-graphic-card"<?= $backgroundImage !== '' ? ' style="background-image:url(\'' . h(match_graphic_asset_url($backgroundImage)) . '\')"' : '' ?>>
      <div class="starting11-graphic-card__backdrop"></div>
      <div class="starting11-graphic-card__pattern"></div>
      <div class="starting11-graphic-card__content starting11-graphic-card__content--grid">
        <section class="starting11-graphic-card__lineup starting11-graphic-card__lineup--grid" aria-label="Starting lineup">
          <article class="starting11-graphic-card__grid-intro" aria-label="Fixture heading">
            <div class="starting11-graphic-card__grid-intro-inner">
              <div class="starting11-graphic-card__grid-intro-badge-wrap">
                <?php if ($homeBadge = match_graphic_badge_path($homeTeamName)): ?>
                  <img class="starting11-graphic-card__grid-intro-badge" src="<?= h(match_graphic_asset_url($homeBadge)) ?>" alt="<?= h($homeTeamName) ?> badge" loading="eager">
                <?php endif; ?>
                <?php if ($awayBadge = match_graphic_badge_path($awayTeamName)): ?>
                  <img class="starting11-graphic-card__grid-intro-badge" src="<?= h(match_graphic_asset_url($awayBadge)) ?>" alt="<?= h($awayTeamName) ?> badge" loading="eager">
                <?php endif; ?>
              </div>
              <div class="starting11-graphic-card__grid-intro-score" aria-label="<?= h($homeTeamName) ?> versus <?= h($awayTeamName) ?>">
                <span class="starting11-graphic-card__grid-intro-abbr starting11-graphic-card__grid-intro-abbr--home"><?= h($homeTeamAbbr) ?></span>
                <span class="starting11-graphic-card__grid-intro-vs">VS</span>
                <span class="starting11-graphic-card__grid-intro-abbr starting11-graphic-card__grid-intro-abbr--away"><?= h($awayTeamAbbr) ?></span>
              </div>
              <div class="starting11-graphic-card__grid-intro-opponent"><?= h(strtoupper($homeTeamName . ' VS ' . $awayTeamName)) ?></div>
            </div>
          </article>

          <?php foreach ($starters as $index => $starter): ?>
            <?php
              $starterKey = match_graphic_player_name_key($starter);
              $player = $playersByName[$starterKey] ?? null;
              $avatarUrl = $player !== null ? match_graphic_player_avatar_url((string) ($player['image'] ?? '')) : '';
            ?>
            <article class="starting11-graphic-card__grid-player">
              <?php if ($showNumbers): ?>
                <div class="starting11-graphic-card__grid-number"><?= (int) ($index + 1) ?></div>
              <?php endif; ?>
              <div class="starting11-graphic-card__grid-photo-wrap">
                <?php if ($avatarUrl !== ''): ?>
                  <img class="starting11-graphic-card__grid-photo" src="<?= h($avatarUrl) ?>" alt="<?= h($starter) ?>" loading="eager">
                <?php else: ?>
                  <div class="starting11-graphic-card__grid-photo-placeholder"><?= h('NO PHOTO') ?></div>
                <?php endif; ?>
              </div>
              <div class="starting11-graphic-card__grid-name<?= $index % 2 === 1 ? ' starting11-graphic-card__grid-name--alt' : '' ?>">
                <span><?= h(strtoupper($formattedStarters[$index] ?? $starter)) ?></span>
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
                  <span class="starting11-graphic-card__grid-subs-list-item"><?= h(strtoupper($subName)) ?></span>
                <?php endforeach; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      </div>
    </div>
    <?php
}

$isRender = isset($_GET['render']);
$app = app_bootstrap_state();
$isAuthenticated = $isRender ? true : $app['isAuthenticated'];
$styleVersion = $app['styleVersion'];
$matchId = 0;
if (isset($_GET['fixture_id'])) {
    $matchId = (int) $_GET['fixture_id'];
} elseif (isset($_GET['id'])) {
    $matchId = (int) $_GET['id'];
}

$viewMode = isset($_GET['view']) && in_array((string) $_GET['view'], ['list', 'grid'], true) ? (string) $_GET['view'] : 'grid';
$showNumbers = isset($_GET['show_numbers']) && (string) $_GET['show_numbers'] === '1';

$matches = matches_load_all();
$match = $matchId > 0 ? matches_find_by_id($matches, (string) $matchId) : null;

if ($match === null) {
    http_response_code(404);
}

$backgroundImage = $match !== null ? matches_match_lineup_background($match) : '';
$titlePrimary = $match !== null ? strtoupper((string) ($match['title_primary'] ?? 'STARTING')) : 'STARTING';
$titleAccent = $match !== null ? strtoupper((string) ($match['title_accent'] ?? 'XI')) : 'XI';
$opponentPrefix = $match !== null ? strtoupper((string) ($match['opponent_prefix'] ?? 'VS')) : 'VS';
$opponentName = $match !== null ? trim((string) ($match['opponent'] ?? '')) : '';
$venue = $match !== null ? matches_normalize_venue((string) ($match['venue'] ?? 'H')) : 'H';
$isHome = $venue === 'H';
$homeTeamName = $isHome ? 'Saltcoats Victoria' : $opponentName;
$awayTeamName = $isHome ? $opponentName : 'Saltcoats Victoria';
$homeTeamAbbr = $isHome ? 'SVC' : match_graphic_team_abbreviation($opponentName);
$awayTeamAbbr = $isHome ? match_graphic_team_abbreviation($opponentName) : 'SVC';
$starters = $match !== null ? matches_prepare_lineup($match['starters'] ?? []) : [];
$substitutes = $match !== null ? matches_prepare_lineup($match['substitutes'] ?? []) : [];
$captain = $match !== null ? trim((string) ($match['captain'] ?? '')) : '';
$formattedStarters = array_map('match_graphic_format_player_name', $starters);
$formattedSubstitutes = array_map('match_graphic_format_player_name', $substitutes);
$substitutesLine = implode(' / ', $formattedSubstitutes);
$substituteColumns = match_graphic_chunk_substitutes($formattedSubstitutes, 3);

$squadPlayers = players_load_all();
$playersByName = [];
foreach ($squadPlayers as $player) {
    $nameKey = match_graphic_player_name_key((string) ($player['name'] ?? ''));
    if ($nameKey === '' || isset($playersByName[$nameKey])) {
        continue;
    }

    $playersByName[$nameKey] = $player;
}

$bodyClass = 'bg-cream' . ($isRender ? ' page-render' : '') . ' starting11-graphic-page';
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $match !== null ? h(matches_fixture_label($match)) . ' Starting XI graphic – Club Hub' : 'Starting XI graphic – Club Hub' ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&amp;family=Poppins:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link rel="stylesheet" href="assets/css/social-publishing.css?v=<?= $styleVersion ?>">
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
  <link rel="stylesheet" href="/admin/assets/css/match_graphic.css">
</head>

<body class="<?= h($bodyClass) ?><?= !$isRender ? ' legacy-shell' : '' ?>">
  <?php if (!$isRender && !$isAuthenticated): ?>
    <?php app_render_login_modal('Login Required', 'Enter your username or email and password to access the Saltcoats Victoria match graphics.'); ?>
  <?php endif; ?>

  <?php if (!$isRender && $isAuthenticated): ?>
    <header class="site-header py-3">
      <div class="container-fluid">
        <?php app_render_primary_nav('matches'); ?>
      </div>
    </header>
  <?php endif; ?>

  <?php if ($match === null): ?>
    <main id="hubMainContent" class="pb-4" tabindex="-1">
      <div class="container-fluid">
        <section class="utility-panel">
          <p class="page-kicker">Graphic Not Found</p>
          <h1 class="feature-card__title">The requested match could not be found.</h1>
          <a class="btn btn-brand" href="matches.php">Back to Matches</a>
        </section>
      </div>
    </main>
  <?php else: ?>
    <?php if (!$isRender): ?>
      <main id="hubMainContent" tabindex="-1">
        <div class="container-fluid">
        <div class="page-hero mb-4">
          <div class="page-hero-body d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
            <div>
              <div class="page-hero-eyebrow">Generated artwork</div>
              <h1 class="page-hero-title">Starting XI graphic</h1>
              <p class="page-hero-subtitle"><?= h(matches_fixture_label($match)) ?></p>
            </div>
          </div>
        </div>

        <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="match_starting_11.php?id=<?= h((string)$matchId) ?>">Starting XI editor</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Line-up graphic</span></nav>

        <?php if ($starters === []): ?>
          <div class="alert alert-warning">No starters have been selected for this fixture yet.</div>
        <?php endif; ?>

        <div class="card hub-form-card shadow-sm border-0 mb-3">
          <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
              <input type="hidden" name="id" value="<?= h((string) $matchId) ?>">
              <input type="hidden" name="fixture_id" value="<?= h((string) $matchId) ?>">
              <input type="hidden" name="view" id="graphicViewMode" value="<?= h($viewMode) ?>">
              <div class="col-12">
                <div class="d-flex flex-column flex-xl-row align-items-stretch align-items-xl-center justify-content-xl-between gap-3">
                  <div class="d-flex flex-wrap align-items-center gap-3">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" id="showNumbersToggle" name="show_numbers" value="1" <?= $showNumbers ? 'checked' : '' ?> onchange="this.form.submit()">
                      <label class="form-check-label" for="showNumbersToggle">Show player numbers</label>
                    </div>
                  </div>
                  <div class="btn-group" role="group" aria-label="Graphic view">
                    <button type="button" class="btn btn-sm <?= $viewMode === 'list' ? 'btn-brand' : 'btn-outline-secondary' ?>" onclick="document.getElementById('graphicViewMode').value='list'; this.form.submit();" title="List view">
                      List
                    </button>
                    <button type="button" class="btn btn-sm <?= $viewMode === 'grid' ? 'btn-brand' : 'btn-outline-secondary' ?>" onclick="document.getElementById('graphicViewMode').value='grid'; this.form.submit();" title="Grid view">
                      Grid
                    </button>
                  </div>
                  <button id="downloadStarting11GraphicBtn" type="button" class="btn btn-brand">Download PNG</button>
                </div>
              </div>
            </form>
          </div>
        </div>
    <?php endif; ?>

    <section class="starting11-graphic-preview-wrap<?= $isRender ? ' match-preview-wrap--render' : '' ?>">
      <div id="starting11GraphicCapture">
        <?php if ($isRender): ?>
          <?php match_graphic_render_canvas($backgroundImage, $titlePrimary, $titleAccent, $opponentPrefix, $homeTeamName, $awayTeamName, $homeTeamAbbr, $awayTeamAbbr, $starters, $formattedStarters, $captain, $substituteColumns, $playersByName, $showNumbers); ?>
        <?php else: ?>
          <?php if ($viewMode === 'grid'): ?>
            <?php match_graphic_render_canvas($backgroundImage, $titlePrimary, $titleAccent, $opponentPrefix, $homeTeamName, $awayTeamName, $homeTeamAbbr, $awayTeamAbbr, $starters, $formattedStarters, $captain, $substituteColumns, $playersByName, $showNumbers); ?>
          <?php else: ?>
            <div class="starting11-graphic-card<?= $viewMode === 'grid' ? ' starting11-graphic-card--export' : '' ?>" <?= $backgroundImage !== '' ? ' style="background-image:url(\'' . h(match_graphic_asset_url($backgroundImage)) . '\')"' : '' ?>>
              <div class="starting11-graphic-card__backdrop"></div>
              <div class="starting11-graphic-card__pattern"></div>
              <div class="starting11-graphic-card__content">
                <header class="starting11-graphic-card__header">
                  <div class="starting11-graphic-card__title">
                    <span class="starting11-graphic-card__title-main"><?= h($titlePrimary) ?></span>
                    <span class="starting11-graphic-card__title-accent"><?= h($titleAccent) ?></span>
                  </div>
                  <div class="starting11-graphic-card__meta">
                    <span class="starting11-graphic-card__line"></span>
                    <span class="starting11-graphic-card__opponent"><?= h($opponentPrefix . ' ' . $opponentName) ?></span>
                  </div>
                </header>

                <section class="starting11-graphic-card__lineup" aria-label="Starting lineup">
                  <?php foreach ($starters as $index => $starter): ?>
                    <div class="starting11-graphic-card__player">
                      <?php if ($showNumbers): ?>
                        <span class="starting11-graphic-card__player-number"><?= (int) ($index + 1) ?></span>
                      <?php endif; ?>
                      <span><?= h(strtoupper($formattedStarters[$index] ?? $starter)) ?></span>
                      <?php if ($captain !== '' && $captain === $starter): ?>
                        <span class="starting11-graphic-card__captain" aria-hidden="true"></span>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </section>

                <section class="starting11-graphic-card__subs" aria-label="Substitutes">
                  <h2 class="starting11-graphic-card__subs-title">SUBSTITUTES</h2>
                  <div class="starting11-graphic-card__subs-list">
                    <p class="starting11-graphic-card__subs-row"><?= h(strtoupper($substitutesLine)) ?></p>
                  </div>
                </section>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>
    <?php if (!$isRender): ?>
        </div>
      </main>
    <?php endif; ?>
  <?php endif; ?>

  <?php app_render_auth_scripts($isAuthenticated); ?>
  <?php if (!$isRender && $isAuthenticated && $match !== null): ?>
    <script>
      (function () {
        var downloadButton = document.getElementById('downloadStarting11GraphicBtn');
        var graphic = document.querySelector('#starting11GraphicCapture .starting11-graphic-card');
        if (!downloadButton || !graphic || typeof html2canvas !== 'function') {
          return;
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

        downloadButton.addEventListener('click', function () {
          var originalText = downloadButton.textContent;
          var renderToken = Date.now().toString();
          var previewWindow = window.open('', '_blank');
          if (!previewWindow) {
            window.alert('Popup blocked. Allow popups for this site and try again.');
            return;
          }

          previewWindow.document.write(
            '<!doctype html><html><head><title>Generating PNG...</title><link rel="stylesheet" href="/admin/assets/css/match_graphic-2.css"></head><body>Generating PNG...</body></html>'
          );
          previewWindow.document.close();

          downloadButton.disabled = true;
          downloadButton.textContent = 'Generating...';

          Promise.all([waitForFonts(), waitForImages(graphic)]).then(function () {
            var exportWrap = document.createElement('div');
            exportWrap.className = 'starting11-graphic-page';
            exportWrap.style.position = 'fixed';
            exportWrap.style.left = '-10000px';
            exportWrap.style.top = '0';
            exportWrap.style.width = '1080px';
            exportWrap.style.height = '1080px';
            exportWrap.style.pointerEvents = 'none';
            exportWrap.style.opacity = '1';

            var exportClone = graphic.cloneNode(true);
            exportClone.classList.add('starting11-graphic-card--export');
            exportClone.style.width = '1080px';
            exportClone.style.height = '1080px';
            exportClone.style.minWidth = '1080px';
            exportClone.style.minHeight = '1080px';
            exportClone.style.maxWidth = '1080px';
            exportClone.style.maxHeight = '1080px';

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
                width: 1080,
                height: 1080,
                windowWidth: 1080,
                windowHeight: 1080,
                imageTimeout: 0
              }).finally(function () {
                document.body.removeChild(exportWrap);
              });
            });
          }).then(function (canvas) {
            var imageUrl = canvas.toDataURL('image/png');
            previewWindow.document.write(
              '<!doctype html><html><head><title>Starting XI PNG Preview</title><link rel="stylesheet" href="/admin/assets/css/match_graphic-3.css"></head><body><img src="' + imageUrl + '" alt="Starting XI PNG preview"></body></html>'
            );
            previewWindow.document.close();
          }).catch(function (error) {
            previewWindow.document.write(
              '<!doctype html><html><head><title>PNG Preview Error</title><link rel="stylesheet" href="/admin/assets/css/match_graphic-4.css"></head><body>' + String(error && error.message ? error.message : 'Failed to generate PNG.') + '</body></html>'
            );
            previewWindow.document.close();
          }).finally(function () {
            downloadButton.disabled = false;
            downloadButton.textContent = originalText;
          });
        });
      }());
    </script>
  <?php endif; ?>
</body>

</html>
