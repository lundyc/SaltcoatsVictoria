<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => 'Player Registrations',
    'subtitle' => 'COMET registration status and eligibility, player by player.',
    'actions' => [
        ['label' => 'Log registration', 'href' => '/player_registration_edit.php', 'class' => 'btn btn-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/player_registration.php';

$rows = registration_current_all($pdo);

$toneClass = ['green' => 'success', 'amber' => 'warning', 'red' => 'danger'];
$counts = ['green' => 0, 'amber' => 0, 'red' => 0];
foreach ($rows as $row) {
    $light = registration_traffic_light(!empty($row['registration_id']) ? $row : null);
    $counts[$light['status']]++;
}

$registrationTypeLabels = [
    'new' => 'New registration',
    'transfer' => 'Transfer',
    're_registration' => 'Re-registration',
    'loan' => 'Loan',
    'international' => 'International clearance',
    'termination' => 'Termination',
    'other' => 'Other',
];
$cometStatusLabels = [
    'not_started' => 'Not started',
    'entered' => 'Entered — not yet confirmed',
    'confirmed' => 'Confirmed',
    'rejected' => 'Rejected',
    'terminated' => 'Terminated',
];
?>

<div class="player-registrations-page">
    <div class="venues-notices" aria-live="polite">
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Registration event saved.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Registration event deleted.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <?php hub_render_metric_grid([
        ['label' => 'Confirmed eligible', 'value' => number_format($counts['green']), 'meta' => 'GREEN', 'icon' => 'fa-circle-check', 'tone' => 'success'],
        ['label' => 'Needs checking', 'value' => number_format($counts['amber']), 'meta' => 'AMBER — verify before selecting', 'icon' => 'fa-circle-question', 'tone' => 'warning'],
        ['label' => 'Not eligible', 'value' => number_format($counts['red']), 'meta' => 'RED', 'icon' => 'fa-circle-xmark', 'tone' => count($rows) > 0 && $counts['red'] > 0 ? 'danger' : 'neutral'],
    ], 'Registration summary'); ?>

    <section class="hub-section" aria-labelledby="registrationsTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Squad</div>
                <h2 id="registrationsTitle">Current registration status</h2>
                <p>Latest logged registration event per player. A player with no event logged shows AMBER — not yet confirmed, per the handbook's default of never assuming eligibility.</p>
            </div>
        </div>

        <?php if ($rows === []): ?>
            <div class="hub-empty-state">
                <i class="fa-solid fa-id-card" aria-hidden="true"></i>
                <h3>No current-squad players found</h3>
            </div>
        <?php else: ?>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Player</th>
                            <th>Status</th>
                            <th>Latest event</th>
                            <th>COMET status</th>
                            <th>Eligibility checked</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $hasEvent = !empty($row['registration_id']);
                            $light = registration_traffic_light($hasEvent ? $row : null);
                            ?>
                            <tr>
                                <td><?= h((string) $row['player_name']) ?></td>
                                <td><span class="badge text-bg-<?= $toneClass[$light['status']] ?>"><?= h($light['label']) ?></span></td>
                                <td>
                                    <?php if ($hasEvent): ?>
                                        <?= h($registrationTypeLabels[$row['registration_type']] ?? (string) $row['registration_type']) ?>
                                        <?php if (!empty($row['submitted_at'])): ?>
                                            <div class="venues-muted"><?= h(date('d/m/Y', strtotime((string) $row['submitted_at']))) ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="venues-muted">No event logged</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $hasEvent ? h($cometStatusLabels[$row['comet_status']] ?? (string) $row['comet_status']) : '<span class="venues-muted">—</span>' ?></td>
                                <td class="text-center"><?= $hasEvent && (int) $row['competition_eligibility_checked'] === 1 ? '<i class="fa-solid fa-check text-success" title="Checked"></i>' : '<i class="fa-solid fa-xmark text-danger" title="Not checked"></i>' ?></td>
                                <td class="text-end">
                                    <a href="player_registration_edit.php?player_id=<?= (int) $row['player_id'] ?>" class="btn btn-sm btn-brand">Log event</a>
                                    <?php if ($hasEvent): ?>
                                        <a href="player_registration_edit.php?id=<?= (int) $row['registration_id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit latest event">
                                            <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
