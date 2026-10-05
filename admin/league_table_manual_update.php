<?php

declare(strict_types=1);

/**
 * Manual "paste the WOSFL table" update tool.
 *
 * WOSFL's site (wosfl.co.uk, via LeagueRepublic) sits behind an AWS WAF that
 * challenges (HTTP 202, empty body) a request made straight to a standings
 * page — this held true across a VPS IP change too, so it reads as an
 * IP-reputation rule on that specific URL pattern, not a one-off IP ban.
 *
 * 2026-09-20: found that the WAF lets the request through once it's carrying
 * the ALB/session cookies a normal visit to the site's homepage picks up
 * first — wosfl-table.php now does that warm-up request before asking for
 * the standings page, and live scraping works again (see its comments for
 * detail). Kept this manual-paste tool as the fallback for whenever that
 * stops working again — WAF rules change without notice — rather than
 * removing it.
 */

$pageHero = [
    'eyebrow' => 'Publishing',
    'title' => 'Update League Table',
    'subtitle' => 'Paste the WOSFL standings table to update the site — used because WOSFL blocks this server from fetching it automatically.',
    'actions' => [
        ['label' => 'League table', 'href' => '/league_table.php', 'class' => 'btn btn-outline-secondary btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/wosfl_table.php';
require_once __DIR__ . '/lib/league_table_form.php';
require_once __DIR__ . '/lib/audit.php';

$cacheFile = __DIR__ . '/cache/wosfl_table.json';
$badgeDir = __DIR__ . '/badges';
$badgeOverridesFile = __DIR__ . '/data/wosfl_badge_overrides.json';
$generatorScript = __DIR__ . '/generate_table_image.php';

$errors = [];
$result = null;
$pastedHtml = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $pastedHtml = (string) ($_POST['table_html'] ?? '');

        if (trim($pastedHtml) === '') {
            $errors[] = 'Paste the standings table HTML first.';
        } else {
            $badgeOverrides = wosfl_load_badge_overrides($badgeOverridesFile);
            if (!is_dir($badgeDir)) {
                mkdir($badgeDir, 0777, true);
            }

            $teams = wosfl_parse_standings_html($pastedHtml, $badgeDir, $badgeOverrides);

            if (count($teams) < 6) {
                $errors[] = 'Could not find a standings table in that paste (found ' . count($teams) . ' rows — expected the full league). '
                    . 'Make sure you copied the table itself, the same way as before.';
            } else {
                $cachedTeams = is_file($cacheFile) ? (json_decode((string) file_get_contents($cacheFile), true) ?: []) : [];

                if (is_file($cacheFile)) {
                    copy($cacheFile, $cacheFile . '.bak');
                }

                $teams = league_table_form_attach($teams, null, $cachedTeams);
                file_put_contents($cacheFile, json_encode($teams, JSON_PRETTY_PRINT));

                $imageOk = false;
                $phpBinary = trim((string) shell_exec('command -v php 2>/dev/null'));
                if ($phpBinary !== '' && is_file($generatorScript)) {
                    exec(escapeshellarg($phpBinary) . ' ' . escapeshellarg($generatorScript) . ' 2>&1', $imgLines, $imgStatus);
                    $imageOk = $imgStatus === 0;
                }

                $ourRow = null;
                foreach ($teams as $team) {
                    if (stripos((string) ($team['club'] ?? ''), 'saltcoats') !== false) {
                        $ourRow = $team;
                        break;
                    }
                }

                auditLog($pdo, 'wosfl_table_manual_update', 'Manually updated WOSFL league table (' . count($teams) . ' clubs, pasted)');

                $result = [
                    'teams' => $teams,
                    'count' => count($teams),
                    'our_row' => $ourRow,
                    'image_ok' => $imageOk,
                ];
                $pastedHtml = '';
            }
        }
    }
}
?>

<div class="card dashboard-card hub-section mb-4">
  <div class="card-body">
    <h2 class="h5 mb-3">How this works</h2>
    <ol class="mb-0 ps-3">
      <li>Open the WOSFL standings in your own browser — <a href="https://www.wosfl.co.uk/fg/1_184170169.html" target="_blank" rel="noopener">wosfl.co.uk</a> (this works fine on your computer; it's only blocked from this server).</li>
      <li>Click into the standings table, select it, and copy it — e.g. right-click → Inspect, right-click the highlighted <code>&lt;table&gt;</code> element → Copy → Copy outerHTML. If that's fiddly, selecting and copying the whole page also works.</li>
      <li>Paste it into the box below and click <strong>Update table</strong>.</li>
    </ol>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <?php foreach ($errors as $error): ?>
      <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($result !== null): ?>
  <div class="alert alert-success">
    <div class="fw-bold mb-1">Table updated — <?= (int) $result['count'] ?> clubs parsed.</div>
    <?php if ($result['our_row']): ?>
      <div>Saltcoats Victoria: <?= htmlspecialchars((string) $result['our_row']['pos'], ENT_QUOTES, 'UTF-8') ?><?php
        $pos = (int) $result['our_row']['pos'];
        echo htmlspecialchars(match (true) {
            $pos % 100 >= 11 && $pos % 100 <= 13 => 'th',
            $pos % 10 === 1 => 'st',
            $pos % 10 === 2 => 'nd',
            $pos % 10 === 3 => 'rd',
            default => 'th',
        }, ENT_QUOTES, 'UTF-8');
      ?> — P<?= htmlspecialchars((string) $result['our_row']['p'], ENT_QUOTES, 'UTF-8') ?>
      W<?= htmlspecialchars((string) $result['our_row']['w'], ENT_QUOTES, 'UTF-8') ?>
      D<?= htmlspecialchars((string) $result['our_row']['d'], ENT_QUOTES, 'UTF-8') ?>
      L<?= htmlspecialchars((string) $result['our_row']['l'], ENT_QUOTES, 'UTF-8') ?>
      GD<?= htmlspecialchars((string) $result['our_row']['gd'], ENT_QUOTES, 'UTF-8') ?>
      Pts<?= htmlspecialchars((string) $result['our_row']['pts'], ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <div>Social graphic (export/latest_wosfl.png): <?= $result['image_ok'] ? 'regenerated' : 'could not be regenerated — check it separately' ?>.</div>
    <div class="mt-2">
      <a class="btn btn-sm btn-outline-success" href="/admin/league_table.php">View Hub table</a>
      <a class="btn btn-sm btn-outline-success" href="/table" target="_blank" rel="noopener">View public table</a>
    </div>
  </div>
<?php endif; ?>

<div class="card dashboard-card hub-section">
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label fw-bold" for="table_html">Pasted standings table</label>
        <textarea class="form-control" id="table_html" name="table_html" rows="14" placeholder="Paste the WOSFL standings table HTML here…"><?= htmlspecialchars($pastedHtml, ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
      <button class="btn btn-brand" type="submit">Update table</button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
