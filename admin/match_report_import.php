<?php

declare(strict_types=1);

/**
 * Import a COMET match report (PDF or pasted text) into a fixture.
 *
 * Reached from the "Import match report" card on the fixture Overview tab.
 * Two POST phases:
 *   form_action=parse    -> extract + parse + resolve, render a preview
 *   form_action=confirm  -> re-parse the carried text, apply, redirect back
 *
 * Both teams: XI + subs + captain + goals/cards/subs for each side and the
 * full-time score, written to the normalised matchday_* tables. Opponent
 * players are stored as names (they are not in the players table).
 * See lib/comet_match_report.php + lib/comet_match_report_apply.php.
 */

$pageStyles = ['match-fixture-tabs.css'];
$useSharedHubLayout = true;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/audit.php';
require_once __DIR__ . '/matches_lib.php';
require_once __DIR__ . '/lib/comet_match_report.php';
require_once __DIR__ . '/lib/comet_match_report_apply.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
if (!hub_auth_has_capability('matchday')) {
    http_response_code(403);
    exit('Access denied.');
}

const COMET_IMPORT_MAX_PDF_BYTES = 12 * 1024 * 1024;

$fixtureId = (int) ($_GET['fixture_id'] ?? $_POST['fixture_id'] ?? 0);
$requestedSeasonId = (int) ($_GET['season_id'] ?? $_POST['season_id'] ?? 0);
$fixture = $fixtureId > 0 ? getMatchFixtureById($pdo, $fixtureId) : null;

if (!$fixture) {
    $pageHero = ['eyebrow' => 'Fixture management', 'title' => 'Import match report', 'subtitle' => 'The requested fixture could not be found.'];
    require_once __DIR__ . '/header.php';
    echo '<div class="alert alert-danger">Fixture not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$seasonId = (int) ($fixture['season_id'] ?? $requestedSeasonId);
$season = $seasonId > 0 ? getSeasonById($pdo, $seasonId) : null;
$seasonLocked = $season !== null && (int) ($season['is_locked'] ?? 0) === 1;
$opponent = trim((string) ($fixture['opponent'] ?? 'Opponent')) ?: 'Opponent';
$backUrl = 'match.php?id=' . $fixtureId . '&season_id=' . $seasonId . '&tab=overview';

$errors = [];
$stage = 'form';                 // form | preview
$reportText = '';
$parsed = null;
$resolved = null;
$manualMap = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $formAction = (string) ($_POST['form_action'] ?? '');
    if (!csrf_check()) {
        $errors[] = 'Your session has expired. Please try again.';
    } elseif ($formAction === 'parse') {
        try {
            $upload = $_FILES['report_pdf'] ?? null;
            $hasUpload = is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($hasUpload) {
                if ((int) $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
                    throw new RuntimeException('The file upload did not complete. Please try again.');
                }
                if ((int) $upload['size'] > COMET_IMPORT_MAX_PDF_BYTES) {
                    throw new RuntimeException('That PDF is larger than 12 MB.');
                }
                $mime = (string) @mime_content_type((string) $upload['tmp_name']);
                $looksPdf = $mime === 'application/pdf'
                    || strtolower((string) pathinfo((string) $upload['name'], PATHINFO_EXTENSION)) === 'pdf';
                if (!$looksPdf) {
                    throw new RuntimeException('That file is not a PDF.');
                }
                $reportText = comet_report_extract_text((string) $upload['tmp_name']);
            } elseif (trim((string) ($_POST['report_text'] ?? '')) !== '') {
                $reportText = (string) $_POST['report_text'];
            } else {
                throw new RuntimeException('Choose a PDF file or paste the report text.');
            }

            $parsed = comet_report_parse($reportText);
            $resolved = comet_report_resolve($parsed, $pdo, $fixture);
            if (!$resolved['ok']) {
                throw new RuntimeException((string) $resolved['error']);
            }
            $stage = 'preview';
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
            $stage = 'form';
        }
    } elseif ($formAction === 'confirm') {
        $reportText = (string) ($_POST['report_text'] ?? '');
        $manualMap = is_array($_POST['map'] ?? null)
            ? array_filter(array_map('strval', $_POST['map']), static fn(string $v): bool => $v !== '')
            : [];
        try {
            if ($seasonLocked) {
                throw new RuntimeException('This season is locked, so the fixture cannot be changed.');
            }
            if (trim($reportText) === '') {
                throw new RuntimeException('The report text was lost. Please start again.');
            }
            $parsed = comet_report_parse($reportText);
            $resolved = comet_report_resolve($parsed, $pdo, $fixture, $manualMap);
            if (!$resolved['ok']) {
                throw new RuntimeException((string) $resolved['error']);
            }
            comet_report_import_apply($pdo, $fixture, $resolved);
            auditLog(
                $pdo,
                'match_report_imported',
                'Imported COMET match report for fixture vs ' . $opponent . ' on ' . (string) ($fixture['match_date'] ?? '')
            );
            header('Location: ' . $backUrl . '&imported=1');
            exit;
        } catch (Throwable $error) {
            $errors[] = $error->getMessage();
            $stage = ($resolved !== null && ($resolved['ok'] ?? false)) ? 'preview' : 'form';
        }
    }
}

// --- Presentation helpers ---------------------------------------------------

$playerOptions = [];
try {
    $playerOptions = $pdo->query("SELECT name FROM players WHERE active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable) {
    $playerOptions = [];
}

$pageHero = [
    'eyebrow' => 'Fixture management',
    'title' => 'Import match report',
    'subtitle' => 'Saltcoats Victoria v ' . $opponent,
    'actions' => [],
];
require_once __DIR__ . '/header.php';
?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb">
    <a href="/admin/matches.php">Fixtures</a>
    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
    <a href="<?= h($backUrl) ?>"><?= h($opponent) ?></a>
    <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
    <span aria-current="page">Import match report</span>
</nav>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-danger"><?= h($error) ?></div>
<?php endforeach; ?>

<?php if ($seasonLocked): ?>
    <div class="alert alert-warning">This season is locked. You can preview a report, but it cannot be imported.</div>
<?php endif; ?>

<?php if ($stage === 'form'): ?>

    <div class="card shadow-sm mb-4" style="max-width: 720px;">
        <div class="card-body p-4">
            <div class="small fw-semibold text-uppercase text-muted mb-1">COMET</div>
            <h2 class="h4 mb-2">Match report</h2>
            <p class="text-muted">
                Upload the PDF you downloaded from COMET for this fixture, or paste its text.
                You will see exactly what will be imported before anything changes.
            </p>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="parse">
                <input type="hidden" name="fixture_id" value="<?= (int) $fixtureId ?>">
                <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="reportPdf">Match report PDF</label>
                    <input type="file" class="form-control" id="reportPdf" name="report_pdf" accept="application/pdf,.pdf">
                    <div class="form-text">Up to 12&nbsp;MB. The text layer is read on the server; scans will not work.</div>
                </div>

                <div class="text-center text-muted small my-2">or</div>

                <div class="mb-3">
                    <label class="form-label fw-semibold" for="reportText">Paste the report text</label>
                    <textarea class="form-control" id="reportText" name="report_text" rows="6"
                              placeholder="Open the PDF, select all, copy, and paste here…"><?= h($reportText) ?></textarea>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-brand"><i class="fa-solid fa-magnifying-glass me-1" aria-hidden="true"></i>Preview import</button>
                    <a href="<?= h($backUrl) ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <?php if (trim($reportText) !== ''): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h5 mb-2">Text read from the PDF</h2>
                <p class="text-muted small mb-2">
                    This is exactly what was pulled out of the file. If the line-ups or events look
                    jumbled here, copy this text, fix the spacing, and paste it into the box above.
                </p>
                <?php if ($parsed !== null && !empty($parsed['notes'])): ?>
                    <ul class="small text-muted">
                        <?php foreach ($parsed['notes'] as $note): ?><li><?= h((string) $note) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <pre class="border rounded bg-body-tertiary p-3 small mb-0" style="max-height: 22rem; overflow: auto; white-space: pre-wrap;"><?= h($reportText) ?></pre>
            </div>
        </div>
    <?php endif; ?>

<?php else: /* preview */ ?>

    <?php
    $unmatchedStarters = array_values(array_filter($resolved['starters'], static fn(array $s): bool => $s['name'] === null));
    $matchedStarterCount = count($resolved['starters']) - count($unmatchedStarters);
    $ourScore = $resolved['score']['svfc'];
    $oppScoreValue = $resolved['score']['opponent'];
    $scoreText = ($ourScore !== null && $oppScoreValue !== null) ? $ourScore . ' – ' . $oppScoreValue : 'not read';
    $ourPens = $resolved['penalties']['svfc'] ?? null;
    $oppPens = $resolved['penalties']['opponent'] ?? null;
    $pensText = ($ourPens !== null && $oppPens !== null) ? ' (pens ' . $ourPens . '–' . $oppPens . ')' : '';
    ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <h2 class="h4 mb-3">Detected</h2>
            <dl class="row mb-0">
                <dt class="col-sm-3">Fixture</dt>
                <dd class="col-sm-9">
                    Saltcoats Victoria (<?= $resolved['is_home'] ? 'home' : 'away' ?>)
                    <strong><?= h((string) $scoreText) ?></strong>
                    <?= h((string) $resolved['opponent']) ?>
                    <?php if ($pensText !== ''): ?><span class="text-muted"><?= h($pensText) ?></span><?php endif; ?>
                </dd>
                <?php if ($resolved['competition'] !== ''): ?>
                    <dt class="col-sm-3">Competition</dt>
                    <dd class="col-sm-9"><?= h((string) $resolved['competition']) ?><?= $resolved['stage'] !== '' ? ' · ' . h((string) $resolved['stage']) : '' ?></dd>
                <?php endif; ?>
                <?php if ($resolved['match_date'] !== ''): ?>
                    <dt class="col-sm-3">Date</dt>
                    <dd class="col-sm-9"><?= h(date('D j M Y', strtotime((string) $resolved['match_date']))) ?></dd>
                <?php endif; ?>
            </dl>
            <?php if ($resolved['warnings'] !== []): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    <div class="fw-semibold mb-1">Check before importing</div>
                    <ul class="mb-0">
                        <?php foreach ($resolved['warnings'] as $warning): ?><li><?= h((string) $warning) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <p class="small text-muted mt-3 mb-0">
                Importing <strong>replaces</strong> both teams' line-ups, substitutes, captains and match
                events (goals, cards, substitutions) with what is below, and sets the full-time score.
                Man-of-the-match and kick-off/half-time markers are left alone. Competition, opponent and
                kick-off time are <em>not</em> changed. Opponent players are stored as names.
            </p>
        </div>
    </div>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="confirm">
        <input type="hidden" name="fixture_id" value="<?= (int) $fixtureId ?>">
        <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
        <input type="hidden" name="report_text" value="<?= h($reportText) ?>">

        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h2 class="h4 mb-0">Starting XI</h2>
                    <span class="badge text-bg-light"><?= (int) $matchedStarterCount ?> of <?= count($resolved['starters']) ?> matched</span>
                </div>
                <?php if ($unmatchedStarters !== []): ?>
                    <div class="alert alert-warning">
                        <?= count($unmatchedStarters) ?> player<?= count($unmatchedStarters) === 1 ? '' : 's' ?>
                        could not be matched to your squad. Pick the right player, or leave blank to fill the slot
                        in later from the Starting&nbsp;XI editor.
                    </div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 hub-data-table">
                        <thead>
                            <tr><th style="width:3rem;">#</th><th>In the report</th><th>Squad player</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resolved['starters'] as $starter): ?>
                                <tr>
                                    <td class="text-muted"><?= $starter['pdf_number'] !== null ? (int) $starter['pdf_number'] : '' ?></td>
                                    <td>
                                        <?= h((string) $starter['raw']) ?>
                                        <?php if ($starter['is_captain']): ?><span class="badge text-bg-warning ms-1">C</span><?php endif; ?>
                                        <?php if ($starter['is_gk']): ?><span class="badge text-bg-secondary ms-1">GK</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($starter['name'] !== null): ?>
                                            <span class="text-success"><i class="fa-solid fa-check me-1" aria-hidden="true"></i><?= h((string) $starter['name']) ?></span>
                                        <?php else: ?>
                                            <?php $suggested = count($starter['suggestions']) === 1 ? $starter['suggestions'][0] : ''; ?>
                                            <select class="form-select form-select-sm" name="map[<?= h((string) $starter['raw']) ?>]">
                                                <option value="">— leave blank —</option>
                                                <?php foreach ($playerOptions as $optionName): ?>
                                                    <option value="<?= h((string) $optionName) ?>" <?= $optionName === $suggested ? 'selected' : '' ?>><?= h((string) $optionName) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-3">Substitutes <span class="badge text-bg-light"><?= count($resolved['substitutes']) ?></span></h2>
                        <?php if ($resolved['substitutes'] === []): ?>
                            <p class="text-muted mb-0">No Saltcoats substitutes were matched.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($resolved['substitutes'] as $sub): ?>
                                    <li><i class="fa-solid fa-check text-success me-1" aria-hidden="true"></i><?= h((string) $sub['name']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-3">Goals <span class="badge text-bg-light"><?= count($resolved['goals']) ?></span></h2>
                        <?php if ($resolved['goals'] === []): ?>
                            <p class="text-muted mb-0">No goals.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($resolved['goals'] as $goal): ?>
                                    <?php $isOwn = !empty($goal['own_goal']); $forTeam = ($goal['team'] ?? 'svfc') === 'opponent' ? (string) $resolved['opponent'] : 'Saltcoats'; ?>
                                    <li>
                                        <span class="text-muted"><?= h((string) ($goal['minute'] !== '' ? $goal['minute'] . "'" : '—')) ?></span>
                                        <?= h((string) $goal['name']) ?>
                                        <?php if ($isOwn): ?><span class="badge text-bg-secondary ms-1" title="Own goal — counts for <?= h($forTeam) ?>">OG · <?= h($forTeam) ?></span><?php endif; ?>
                                        <?= $goal['note'] !== '' ? ' <span class="small text-muted">(' . h((string) $goal['note']) . ')</span>' : '' ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body p-4">
                        <h2 class="h5 mb-3">Cards <span class="badge text-bg-light"><?= count($resolved['cards']) ?></span></h2>
                        <?php if ($resolved['cards'] === []): ?>
                            <p class="text-muted mb-0">No Saltcoats cards.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($resolved['cards'] as $card): ?>
                                    <li><span class="text-muted"><?= h((string) ($card['minute'] !== '' ? $card['minute'] . "'" : '—')) ?></span>
                                        <?= h((string) $card['name']) ?>
                                        <span class="badge <?= $card['card_type'] === 'red' ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= h(ucfirst((string) $card['card_type'])) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h5 mb-3">Substitutions <span class="badge text-bg-light"><?= count($resolved['substitutions']) ?></span></h2>
                <?php if ($resolved['substitutions'] === []): ?>
                    <p class="text-muted mb-0">No Saltcoats substitutions were matched.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($resolved['substitutions'] as $change): ?>
                            <li>
                                <span class="text-muted"><?= h((string) ($change['minute'] !== '' ? $change['minute'] . "'" : '—')) ?></span>
                                <i class="fa-solid fa-arrow-down text-danger" aria-hidden="true"></i> <?= h((string) $change['off']) ?>
                                <i class="fa-solid fa-arrow-up text-success ms-2" aria-hidden="true"></i> <?= h((string) $change['on']) ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <?php
        $oppTotal = count($resolved['opponent_starters']) + count($resolved['opponent_substitutes'])
            + count($resolved['opponent_goals']) + count($resolved['opponent_cards']) + count($resolved['opponent_substitutions']);
        ?>
        <?php if ($oppTotal > 0): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <h2 class="h5 mb-3"><?= h((string) $resolved['opponent']) ?> <span class="text-muted fw-normal">— imported as names</span></h2>
                <div class="row g-4">
                    <div class="col-md-6">
                        <h3 class="h6 text-muted">Starting XI <span class="badge text-bg-light"><?= count($resolved['opponent_starters']) ?></span></h3>
                        <ol class="mb-3 ps-3 small">
                            <?php foreach ($resolved['opponent_starters'] as $s): ?>
                                <li><?= h((string) $s['name']) ?><?php if (!empty($s['is_gk'])): ?> <span class="badge text-bg-secondary">GK</span><?php endif; ?><?php if (!empty($s['is_captain'])): ?> <span class="badge text-bg-dark">C</span><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ol>
                        <?php if ($resolved['opponent_substitutes'] !== []): ?>
                            <h3 class="h6 text-muted">Substitutes <span class="badge text-bg-light"><?= count($resolved['opponent_substitutes']) ?></span></h3>
                            <p class="small mb-0"><?= h(implode(', ', array_column($resolved['opponent_substitutes'], 'name'))) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <?php if ($resolved['opponent_goals'] !== []): ?>
                            <h3 class="h6 text-muted">Goals <span class="badge text-bg-light"><?= count($resolved['opponent_goals']) ?></span></h3>
                            <ul class="list-unstyled small mb-3">
                                <?php foreach ($resolved['opponent_goals'] as $g): ?>
                                    <li><span class="text-muted"><?= h((string) ($g['minute'] !== '' ? $g['minute'] . "'" : '—')) ?></span> <?= h((string) $g['name']) ?><?= (string) ($g['note'] ?? '') === 'Penalty' ? ' (pen)' : '' ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($resolved['opponent_cards'] !== []): ?>
                            <h3 class="h6 text-muted">Cards <span class="badge text-bg-light"><?= count($resolved['opponent_cards']) ?></span></h3>
                            <ul class="list-unstyled small mb-3">
                                <?php foreach ($resolved['opponent_cards'] as $c): ?>
                                    <li><span class="text-muted"><?= h((string) ($c['minute'] !== '' ? $c['minute'] . "'" : '—')) ?></span> <?= h((string) $c['name']) ?> <span class="badge text-bg-<?= ($c['card_type'] ?? '') === 'red' ? 'danger' : 'warning' ?>"><?= h((string) $c['card_type']) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if ($resolved['opponent_substitutions'] !== []): ?>
                            <h3 class="h6 text-muted">Substitutions <span class="badge text-bg-light"><?= count($resolved['opponent_substitutions']) ?></span></h3>
                            <ul class="list-unstyled small mb-0">
                                <?php foreach ($resolved['opponent_substitutions'] as $ch): ?>
                                    <li><span class="text-muted"><?= h((string) ($ch['minute'] !== '' ? $ch['minute'] . "'" : '—')) ?></span> <?= h((string) $ch['off']) ?> <i class="fa-solid fa-arrow-right-arrow-left small"></i> <?= h((string) $ch['on']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($resolved['add_candidates'])): ?>
            <div class="card shadow-sm mb-4 border-warning-subtle">
                <div class="card-body p-4">
                    <h2 class="h5 mb-1">Players not on your squad yet</h2>
                    <p class="text-muted small mb-3">
                        The report ties these names to Saltcoats but they aren't in your player list.
                        Add them and this preview refreshes so their goals/cards/substitutions import too.
                    </p>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($resolved['add_candidates'] as $candidate): ?>
                            <li class="d-flex align-items-center gap-2 mb-2">
                                <span class="fw-semibold"><?= h((string) $candidate) ?></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-add-player="<?= h((string) $candidate) ?>">
                                    <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i>Add this player
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($resolved['not_imported'] !== []): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">
                    <h2 class="h5 mb-3">Not imported <span class="badge text-bg-light"><?= count($resolved['not_imported']) ?></span></h2>
                    <p class="text-muted small">Opponent events and own goals are shown here for reference only.</p>
                    <ul class="mb-0 small text-muted">
                        <?php foreach ($resolved['not_imported'] as $line): ?><li><?= h((string) $line) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" value="1" id="confirmReplace" required>
                    <label class="form-check-label" for="confirmReplace">
                        I've checked this. Replace both teams' line-ups, substitutes, captains and match events.
                    </label>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-brand" <?= $seasonLocked ? 'disabled' : '' ?>>
                        <i class="fa-solid fa-file-import me-1" aria-hidden="true"></i>Import
                    </button>
                    <a href="<?= h($backUrl) ?>" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </div>
        </div>
    </form>

    <?php if (!empty($resolved['add_candidates'])): ?>
        <form id="reportReparseForm" method="post" class="d-none">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="parse">
            <input type="hidden" name="fixture_id" value="<?= (int) $fixtureId ?>">
            <input type="hidden" name="season_id" value="<?= (int) $seasonId ?>">
            <input type="hidden" name="report_text" value="<?= h($reportText) ?>">
        </form>

        <div class="modal fade" id="addPlayerModal" tabindex="-1" aria-labelledby="addPlayerModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="h5 modal-title" id="addPlayerModalLabel">Add player</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger d-none" id="addPlayerError" role="alert"></div>
                        <div class="mb-3">
                            <label class="form-label" for="addPlayerName">Name</label>
                            <input type="text" class="form-control" id="addPlayerName" autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="addPlayerStatus">Status</label>
                            <select class="form-select" id="addPlayerStatus">
                                <option value="current" selected>Current</option>
                                <option value="trialist">Trialist</option>
                                <option value="loan">On loan</option>
                            </select>
                        </div>
                        <div class="mb-0">
                            <label class="form-label" for="addPlayerDob">Date of birth <span class="text-muted">(optional)</span></label>
                            <input type="date" class="form-control" id="addPlayerDob">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-brand" id="addPlayerSave">Save &amp; refresh</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var csrf = document.querySelector('#reportReparseForm input[name="csrf_token"]').value;
            var modalEl = document.getElementById('addPlayerModal');
            var modal = new bootstrap.Modal(modalEl);
            var nameInput = document.getElementById('addPlayerName');
            var statusInput = document.getElementById('addPlayerStatus');
            var dobInput = document.getElementById('addPlayerDob');
            var errorBox = document.getElementById('addPlayerError');
            var saveBtn = document.getElementById('addPlayerSave');

            document.querySelectorAll('[data-add-player]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    nameInput.value = btn.getAttribute('data-add-player') || '';
                    statusInput.value = 'current';
                    dobInput.value = '';
                    errorBox.classList.add('d-none');
                    errorBox.textContent = '';
                    modal.show();
                    setTimeout(function () { nameInput.focus(); }, 250);
                });
            });

            saveBtn.addEventListener('click', function () {
                var name = nameInput.value.trim();
                if (name === '') { nameInput.focus(); return; }
                errorBox.classList.add('d-none');
                saveBtn.disabled = true;
                saveBtn.textContent = 'Saving…';

                var body = new URLSearchParams();
                body.set('csrf_token', csrf);
                body.set('name', name);
                body.set('status', statusInput.value);
                body.set('active', '1');
                if (dobInput.value) { body.set('date_of_birth', dobInput.value); }

                fetch('/admin/players_save.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString()
                }).then(function (r) {
                    return r.json().catch(function () { throw new Error('The player could not be saved.'); });
                }).then(function (data) {
                    if (!data || !data.success) { throw new Error((data && data.error) || 'The player could not be saved.'); }
                    document.getElementById('reportReparseForm').submit();
                }).catch(function (err) {
                    errorBox.textContent = err.message || 'The player could not be saved.';
                    errorBox.classList.remove('d-none');
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Save & refresh';
                });
            });
        });
        </script>
    <?php endif; ?>

    <details class="mb-4">
        <summary class="text-muted small">Show the text read from the PDF</summary>
        <?php if ($parsed !== null && !empty($parsed['notes'])): ?>
            <ul class="small text-muted mt-2 mb-1">
                <?php foreach ($parsed['notes'] as $note): ?><li><?= h((string) $note) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <pre class="border rounded bg-body-tertiary p-3 small mt-2 mb-0" style="max-height: 22rem; overflow: auto; white-space: pre-wrap;"><?= h($reportText) ?></pre>
    </details>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
