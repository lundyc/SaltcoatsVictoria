<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => 'Discipline Register',
    'subtitle' => 'Verify logged cards against COMET/the SFA and track active suspensions.',
    'actions' => [
        ['label' => 'Log incident', 'href' => '/discipline_incident.php', 'class' => 'btn btn-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/discipline_register.php';

$today = date('Y-m-d');

$scanFixtures = $pdo->query(
    "SELECT id, match_date, opponent, competition FROM match_fixtures
     WHERE match_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
     ORDER BY match_date DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pendingIncidents = discipline_pending_incidents($pdo, $scanFixtures);
$register = discipline_list_register($pdo);

$activeRegister = array_values(array_filter($register, static fn(array $row): bool => $row['status'] !== 'cleared'));
$clearedRegister = array_values(array_filter($register, static fn(array $row): bool => $row['status'] === 'cleared'));

$confirmedSuspensions = array_values(array_filter($activeRegister, static function (array $row) use ($today): bool {
    if ($row['status'] !== 'suspension_confirmed') {
        return false;
    }
    $clearDate = trim((string) ($row['suspension_clear_date'] ?? ''));
    return $clearDate === '' || $clearDate >= $today;
}));

$statusMeta = [
    'unverified' => ['label' => 'Unverified', 'class' => 'warning'],
    'suspension_confirmed' => ['label' => 'Suspension confirmed', 'class' => 'danger'],
    'cleared' => ['label' => 'Cleared', 'class' => 'success'],
];
?>

<div class="discipline-register-page">
    <div class="venues-notices" aria-live="polite">
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Discipline incident saved.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Discipline incident deleted.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <?php hub_render_metric_grid([
        ['label' => 'Needs logging', 'value' => number_format(count($pendingIncidents)), 'meta' => 'Cards with no register entry', 'icon' => 'fa-square-exclamation', 'tone' => count($pendingIncidents) > 0 ? 'warning' : 'success'],
        ['label' => 'Unverified', 'value' => number_format(count(array_filter($activeRegister, static fn(array $r): bool => $r['status'] === 'unverified'))), 'meta' => 'Logged, awaiting confirmation', 'icon' => 'fa-magnifying-glass', 'tone' => 'info'],
        ['label' => 'Active suspensions', 'value' => number_format(count($confirmedSuspensions)), 'meta' => 'Confirmed, not yet cleared', 'icon' => 'fa-person-circle-xmark', 'tone' => count($confirmedSuspensions) > 0 ? 'danger' : 'success'],
        ['label' => 'Cleared', 'value' => number_format(count($clearedRegister)), 'meta' => 'Resolved this record', 'icon' => 'fa-circle-check', 'tone' => 'neutral'],
    ], 'Discipline summary'); ?>

    <section class="hub-section" aria-labelledby="disciplinePendingTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Needs logging</div>
                <h2 id="disciplinePendingTitle">Cards awaiting Secretary action</h2>
                <p>Logged via Match Events but not yet recorded here. Red cards carry an automatic suspension that applies immediately — verify these first.</p>
            </div>
        </div>

        <?php if ($pendingIncidents === []): ?>
            <div class="hub-empty-state">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <h3>All caught up</h3>
                <p>Every logged card has a discipline register entry.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Player</th>
                            <th>Card</th>
                            <th>Fixture</th>
                            <th>Date</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingIncidents as $pending): ?>
                            <tr>
                                <td><?= h($pending['player_name']) ?></td>
                                <td>
                                    <span class="badge text-bg-<?= $pending['card_type'] === 'red' ? 'danger' : 'warning' ?>">
                                        <?= $pending['card_type'] === 'red' ? 'Red card' : 'Yellow card' ?>
                                    </span>
                                </td>
                                <td>vs <?= h($pending['opponent']) ?><?= $pending['competition'] ? ' · ' . h($pending['competition']) : '' ?></td>
                                <td><?= $pending['incident_date'] !== '' ? h(date('d/m/Y', strtotime($pending['incident_date']))) : '—' ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-brand" href="discipline_incident.php?<?= http_build_query([
                                        'player_id' => $pending['player_id'] ?? '',
                                        'fixture_id' => $pending['fixture_id'],
                                        'match_event_id' => $pending['match_event_id'],
                                        'card_type' => $pending['card_type'],
                                        'incident_date' => $pending['incident_date'],
                                        'competition' => $pending['competition'],
                                    ]) ?>">Log incident</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="hub-section" aria-labelledby="disciplineRegisterTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Register</div>
                <h2 id="disciplineRegisterTitle">Active discipline record</h2>
                <p>Unverified and confirmed-suspension entries. Cleared entries are listed below.</p>
            </div>
        </div>

        <?php if ($activeRegister === []): ?>
            <div class="hub-empty-state">
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                <h3>No open discipline entries</h3>
                <p>Log an incident above once a card needs tracking.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Player</th>
                            <th>Card</th>
                            <th>Fixture</th>
                            <th>Status</th>
                            <th>Suspension</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeRegister as $row): ?>
                            <?php $meta = $statusMeta[$row['status']] ?? ['label' => h((string) $row['status']), 'class' => 'secondary']; ?>
                            <tr>
                                <td><?= h((string) $row['player_name']) ?></td>
                                <td>
                                    <span class="badge text-bg-<?= $row['card_type'] === 'red' ? 'danger' : 'warning' ?>">
                                        <?= $row['card_type'] === 'red' ? 'Red card' : 'Yellow card' ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($row['opponent']): ?>
                                        vs <?= h((string) $row['opponent']) ?>
                                    <?php else: ?>
                                        <span class="venues-muted">Not linked</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge text-bg-<?= $meta['class'] ?>"><?= h($meta['label']) ?></span></td>
                                <td>
                                    <?php if (trim((string) ($row['suspension_summary'] ?? '')) !== ''): ?>
                                        <?= h((string) $row['suspension_summary']) ?>
                                        <?php if (!empty($row['suspension_clear_date'])): ?>
                                            <div class="venues-muted">Eligible from <?= h(date('d/m/Y', strtotime((string) $row['suspension_clear_date']))) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="venues-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a href="discipline_incident.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($clearedRegister !== []): ?>
        <section class="hub-section" aria-labelledby="disciplineClearedTitle">
            <div class="venues-directory__header">
                <div>
                    <div class="venues-directory__eyebrow">History</div>
                    <h2 id="disciplineClearedTitle">Cleared entries</h2>
                </div>
            </div>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Player</th>
                            <th>Card</th>
                            <th>Fixture</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clearedRegister as $row): ?>
                            <tr>
                                <td><?= h((string) $row['player_name']) ?></td>
                                <td>
                                    <span class="badge text-bg-<?= $row['card_type'] === 'red' ? 'danger' : 'warning' ?>">
                                        <?= $row['card_type'] === 'red' ? 'Red card' : 'Yellow card' ?>
                                    </span>
                                </td>
                                <td><?= $row['opponent'] ? 'vs ' . h((string) $row['opponent']) : '—' ?></td>
                                <td class="text-end">
                                    <a href="discipline_incident.php?id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
