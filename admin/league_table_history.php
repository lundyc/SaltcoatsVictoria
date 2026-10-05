<?php

declare(strict_types=1);

/**
 * Historical league tables.
 *
 * Primary flow ("Quick import"): paste {"season": "...", "league": "...",
 * "table": [...]} — the season and competition are created automatically if
 * they don't exist, and the table is saved. No season needs to be picked
 * first, so this is a straight paste-and-go for the exported-JSON shape
 * these come in as.
 *
 * Secondary flow (below): pick a season, paste HTML / a bare JSON array /
 * plain text into it — for fixing up one season, or pastes that don't carry
 * season info. Also where promotion/relegation spots and the competition
 * title can be edited after the fact, and where a season's table is deleted.
 *
 * The *current* season keeps using the existing scraper
 * (cache/wosfl_table.json, league_table_manual_update.php) exactly as
 * before — this page refuses to save over it either way.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/wosfl_table.php';
require_once __DIR__ . '/lib/league_table_history.php';
require_once __DIR__ . '/lib/audit.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}

$badgeDir = __DIR__ . '/badges';
$badgeOverridesFile = __DIR__ . '/data/wosfl_badge_overrides.json';
$badgeOverrides = wosfl_load_badge_overrides($badgeOverridesFile);
if (!is_dir($badgeDir)) {
    mkdir($badgeDir, 0777, true);
}

$currentSeason = getCurrentSeason($pdo);
$currentSeasonId = $currentSeason ? (int) $currentSeason['id'] : 0;

/* --------------------------------------------------------------------- */
/* Quick import — POST action=import                                     */
/* --------------------------------------------------------------------- */

$importErrors = [];
$importResult = null;
$importInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import') {
    $importInput = (string) ($_POST['import_json'] ?? '');
    if (!csrf_check()) {
        $importErrors[] = 'Your session expired. Please try again.';
    } elseif (trim($importInput) === '') {
        $importErrors[] = 'Paste the season JSON first.';
    } else {
        $result = leagueTableHistoryImportFull($pdo, $importInput, $badgeDir, $badgeOverrides);
        if (!$result['ok']) {
            $importErrors = $result['errors'];
        } else {
            $importResult = $result;
            $importInput = '';
            auditLog(
                $pdo,
                'league_table_history_import',
                ($result['season']['created'] ? 'Created' : 'Updated') . ' season "' . $result['season']['name'] . '"'
                . ($result['competition'] ? ' (' . ($result['competition']['created'] ? 'new' : 'existing') . ' competition "' . $result['competition']['name'] . '")' : '')
                . ' and saved its league table (' . $result['rows'] . ' clubs)'
                . ($result['errors'] ? '; ' . count($result['errors']) . ' row(s) skipped' : '')
            );
        }
    }
}

/* --------------------------------------------------------------------- */
/* Browse / fine-tune one season                                         */
/* --------------------------------------------------------------------- */

$seasons = getSeasons($pdo);
$savedSeasonIds = array_map(static fn (array $r): int => (int) $r['season_id'], leagueTableHistoryList($pdo));

$seasonId = (int) ($_GET['season_id'] ?? $_POST['season_id'] ?? 0);
if ($importResult !== null) {
    $seasonId = (int) $importResult['season']['id']; // jump straight to what was just imported
}
$validSeasonIds = array_map(static fn (array $s): int => (int) $s['id'], $seasons);
if ($seasonId <= 0 || !in_array($seasonId, $validSeasonIds, true)) {
    $nonCurrent = array_values(array_filter($seasons, static fn (array $s): bool => (int) $s['id'] !== $currentSeasonId));
    $seasonId = $nonCurrent !== [] ? (int) end($nonCurrent)['id'] : ($seasons !== [] ? (int) $seasons[0]['id'] : 0);
}
$season = null;
foreach ($seasons as $s) {
    if ((int) $s['id'] === $seasonId) {
        $season = $s;
        break;
    }
}

$errors = [];
$saved = null;
$existing = $seasonId > 0 ? leagueTableHistoryGet($pdo, $seasonId) : null;

$tableInput = '';
$titleInput = (string) ($existing['competition_title'] ?? '');
$promoInput = $existing['promotion_spots'] ?? '';
$relegInput = $existing['relegation_spots'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($seasonId <= 0) {
        $errors[] = 'Choose a season first.';
    } else {
        leagueTableHistoryDelete($pdo, $seasonId);
        auditLog($pdo, 'league_table_history_delete', 'Deleted the saved league table for season #' . $seasonId . ' (' . ($season['name'] ?? '') . ')');
        header('Location: /admin/league_table_history.php?season_id=' . $seasonId . '&deleted=1');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_season') {
    $tableInput = (string) ($_POST['table_input'] ?? '');
    $titleInput = (string) ($_POST['competition_title'] ?? '');
    $promoInput = (string) ($_POST['promotion_spots'] ?? '');
    $relegInput = (string) ($_POST['relegation_spots'] ?? '');

    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($seasonId <= 0) {
        $errors[] = 'Choose a season first.';
    } elseif ($seasonId === $currentSeasonId) {
        $errors[] = 'That\'s the current season — use "Update from WOSFL" on the League Table page for it instead. This tool is for finished seasons.';
    } else {
        $parsed = leagueTableHistoryParseInput($tableInput, $badgeDir, $badgeOverrides);

        if ($parsed['rows'] === []) {
            $errors[] = 'No standings rows could be read from that paste.';
            $errors = array_merge($errors, $parsed['errors']);
        } else {
            $promo = trim($promoInput) !== '' ? max(0, (int) $promoInput) : null;
            $releg = trim($relegInput) !== '' ? max(0, (int) $relegInput) : null;
            leagueTableHistorySave($pdo, $seasonId, $parsed['rows'], $titleInput, $promo, $releg, 'manual:' . $parsed['format']);
            auditLog(
                $pdo,
                'league_table_history_save',
                'Saved the ' . ($season['name'] ?? '') . ' league table (' . count($parsed['rows']) . ' clubs, pasted as ' . $parsed['format'] . ')'
                . ($parsed['errors'] ? '; ' . count($parsed['errors']) . ' line(s) skipped' : '')
            );
            $saved = [
                'rows' => $parsed['rows'],
                'count' => count($parsed['rows']),
                'skipped' => $parsed['errors'],
                'format' => $parsed['format'],
            ];
            $existing = leagueTableHistoryGet($pdo, $seasonId);
            $tableInput = '';
        }
    }
}

$pageHero = [
    'eyebrow' => 'Publishing',
    'title' => 'Historical League Tables',
    'subtitle' => 'Paste a finished season\'s standings so it shows up on the public /table page\'s season picker.',
    'actions' => [
        ['label' => 'League table', 'href' => '/admin/league_table.php', 'class' => 'btn btn-outline-secondary btn-sm'],
        ['label' => 'View public /table', 'href' => '/table', 'class' => 'btn btn-outline-secondary btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
$esc = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>

<div class="card dashboard-card hub-section mb-4 border-brand">
  <div class="card-body">
    <h2 class="h5 mb-1">Quick import</h2>
    <p class="text-muted">Paste the whole season — it creates the season and competition if they're new, and saves the table. One paste, one click.</p>

    <?php if ($importErrors): ?>
      <div class="alert alert-danger">
        <?php foreach ($importErrors as $error): ?><div><?= $esc($error) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($importResult !== null): ?>
      <div class="alert alert-success">
        <div class="fw-bold mb-1">
          <?= $esc($importResult['season']['name']) ?><?= $importResult['season']['created'] ? ' (new season)' : ' (existing season, table updated)' ?>
          — <?= (int) $importResult['rows'] ?> clubs saved.
        </div>
        <?php if ($importResult['competition']): ?>
          <div>Competition: <?= $esc($importResult['competition']['name']) ?><?= $importResult['competition']['created'] ? ' (newly created)' : ' (already existed, reused)' ?></div>
        <?php endif; ?>
        <?php if ($importResult['errors']): ?>
          <div class="mt-1">⚠ <?= count($importResult['errors']) ?> row(s) skipped:</div>
          <ul class="mb-0 small"><?php foreach ($importResult['errors'] as $skip): ?><li><?= $esc($skip) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <div class="mt-2">
          <a class="btn btn-sm btn-outline-success" href="/table?season=<?= (int) $importResult['season']['id'] ?>" target="_blank" rel="noopener">View on public site</a>
          <a class="btn btn-sm btn-outline-success" href="#fine-tune">Adjust promotion/relegation spots ↓</a>
        </div>
      </div>
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="import">
      <textarea class="form-control" name="import_json" rows="10" style="font-family:monospace;font-size:.85rem"
        placeholder='{&#10;  "season": "1988-89",&#10;  "league": "Western Scottish League Second Division",&#10;  "table": [&#10;    {"position": 1, "team": "Kilwinning Rangers", "played": 22, "won": 17, "drawn": 2, "lost": 3, "goals_for": 65, "goals_against": 20, "goal_difference": 45, "points": 36},&#10;    …&#10;  ]&#10;}'><?= $esc($importInput) ?></textarea>
      <div class="form-text">Season accepts "1988-89", "1988/1989" or just "1988". Field names are flexible — <code>team</code>/<code>club</code>, <code>played</code>/<code>p</code>, <code>points</code>/<code>pts</code> etc. all work, and <code>goal_difference</code> is worked out automatically if you leave it out.</div>
      <button class="btn btn-brand mt-2" type="submit">Import season</button>
    </form>
  </div>
</div>

<div class="card dashboard-card hub-section mb-4" id="fine-tune">
  <div class="card-body">
    <h2 class="h5 mb-3">Browse &amp; fine-tune a season</h2>
    <p class="text-muted small">Already-imported seasons land here too — set promotion/relegation spots, re-paste to fix a mistake (HTML, a bare JSON array, or plain text also work here), or delete.</p>

    <form method="get" class="row g-2 align-items-end mb-3">
      <div class="col-md-5">
        <label class="form-label fw-bold" for="season_id">Season</label>
        <select class="form-select" id="season_id" name="season_id" onchange="this.form.submit()">
          <?php foreach (array_reverse($seasons) as $s): ?>
            <?php
            $sid = (int) $s['id'];
            $isCurrent = $sid === $currentSeasonId;
            $hasSaved = in_array($sid, $savedSeasonIds, true);
            ?>
            <option value="<?= $sid ?>" <?= $sid === $seasonId ? 'selected' : '' ?>>
              <?= $esc($s['name']) ?><?= $isCurrent ? ' · current (use WOSFL update)' : '' ?><?= $hasSaved ? ' · saved' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <noscript><button class="btn btn-outline-secondary w-100" type="submit">Load season</button></noscript>
      </div>
    </form>

    <?php if (isset($_GET['deleted'])): ?>
      <div class="alert alert-success">Saved table for <?= $esc($season['name'] ?? '') ?> deleted.</div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?><div><?= $esc($error) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($saved !== null): ?>
      <div class="alert alert-success">
        <div class="fw-bold mb-1"><?= $esc($season['name'] ?? '') ?> table saved — <?= (int) $saved['count'] ?> clubs parsed as <?= $esc($saved['format']) ?>.</div>
        <?php if ($saved['skipped']): ?>
          <div>⚠ <?= count($saved['skipped']) ?> line(s) skipped:</div>
          <ul class="mb-0 small"><?php foreach ($saved['skipped'] as $skip): ?><li><?= $esc($skip) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($seasonId > 0): ?>
      <?php if ($seasonId === $currentSeasonId): ?>
        <p class="text-muted mb-0">This is the working season — its table comes from the live scraper. Use <a href="/admin/league_table_manual_update.php">Update League Table</a> if the automatic scrape is blocked.</p>
      <?php else: ?>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_season">
          <input type="hidden" name="season_id" value="<?= $seasonId ?>">
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label" for="competition_title">Competition name</label>
              <input class="form-control" type="text" id="competition_title" name="competition_title" value="<?= $esc($titleInput) ?>" placeholder="e.g. West of Scotland Football League Conference A">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="promotion_spots">Promotion spots</label>
              <input class="form-control" type="number" min="0" id="promotion_spots" name="promotion_spots" value="<?= $esc($promoInput) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="relegation_spots">Relegation spots</label>
              <input class="form-control" type="number" min="0" id="relegation_spots" name="relegation_spots" value="<?= $esc($relegInput) ?>">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-bold" for="table_input">Standings (HTML, JSON, or plain text) — leave blank to just update the fields above</label>
            <textarea class="form-control" id="table_input" name="table_input" rows="8" placeholder="Paste standings here to replace the saved table…"><?= $esc($tableInput) ?></textarea>
          </div>
          <button class="btn btn-outline-primary" type="submit">Save</button>
        </form>

        <?php if ($existing !== null): ?>
          <hr>
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
            <div>
              <span class="fw-bold">Currently saved:</span>
              <?= count($existing['rows']) ?> clubs
              <?php if (!empty($existing['updated_at']) || !empty($existing['created_at'])): ?>
                · updated <?= $esc(date('j M Y', strtotime((string) ($existing['updated_at'] ?: $existing['created_at'])))) ?>
              <?php endif; ?>
            </div>
            <form method="post" data-confirm="Delete the saved table for <?= $esc($season['name'] ?? '') ?>?" data-confirm-action="Delete">
              <?= csrf_field() ?>
              <input type="hidden" name="season_id" value="<?= $seasonId ?>">
              <input type="hidden" name="action" value="delete">
              <button class="btn btn-sm btn-outline-danger" type="submit">Delete this season's table</button>
            </form>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 hub-data-table">
              <thead><tr><th class="c">#</th><th>Club</th><th class="c">P</th><th class="c">W</th><th class="c">D</th><th class="c">L</th><th class="c">F</th><th class="c">A</th><th class="c">GD</th><th class="c">Pts</th></tr></thead>
              <tbody>
                <?php foreach ($existing['rows'] as $i => $r): ?>
                  <tr class="<?= stripos((string) ($r['club'] ?? ''), 'saltcoats') !== false ? 'table-warning' : '' ?>">
                    <td class="c"><?= $esc($r['pos'] ?? ($i + 1)) ?></td>
                    <td><?= $esc($r['club'] ?? '') ?></td>
                    <td class="c"><?= $esc($r['p'] ?? '') ?></td>
                    <td class="c"><?= $esc($r['w'] ?? '') ?></td>
                    <td class="c"><?= $esc($r['d'] ?? '') ?></td>
                    <td class="c"><?= $esc($r['l'] ?? '') ?></td>
                    <td class="c"><?= $esc($r['f'] ?? '') ?></td>
                    <td class="c"><?= $esc($r['a'] ?? '') ?></td>
                    <td class="c"><?= $esc($r['gd'] ?? '') ?></td>
                    <td class="c"><b><?= $esc($r['pts'] ?? '') ?></b></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card dashboard-card hub-section">
  <div class="card-body">
    <h2 class="h5 mb-3">Seasons with a saved table</h2>
    <?php $list = leagueTableHistoryList($pdo); ?>
    <?php if (!$list): ?>
      <p class="text-muted mb-0">None yet — paste a season above.</p>
    <?php else: ?>
      <ul class="list-group list-group-flush">
        <?php foreach ($list as $row): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center px-0">
            <span>
              <a href="/admin/league_table_history.php?season_id=<?= (int) $row['season_id'] ?>"><?= $esc($row['season_name']) ?></a>
              <span class="text-muted small">— <?= (int) $row['rows'] ?> clubs<?= $row['competition_title'] ? ' · ' . $esc($row['competition_title']) : '' ?></span>
            </span>
            <a class="btn btn-sm btn-outline-secondary" href="/table?season=<?= (int) $row['season_id'] ?>" target="_blank" rel="noopener">View public</a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
