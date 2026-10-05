<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => 'Fixture Change Requests',
    'subtitle' => 'An agreement between two clubs does not make a change official — track the approval trail.',
    'actions' => [
        ['label' => 'Log request', 'href' => '/fixture_change_request_edit.php', 'class' => 'btn btn-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/fixture_change_requests.php';

$requests = fixture_change_requests_list($pdo);
$openRequests = array_values(array_filter($requests, static fn(array $r): bool => !in_array($r['status'], ['confirmed', 'rejected'], true)));
$closedRequests = array_values(array_filter($requests, static fn(array $r): bool => in_array($r['status'], ['confirmed', 'rejected'], true)));

$statusLabels = [
    'requested' => 'Requested',
    'pending_approval' => 'Pending competition approval',
    'approved' => 'Approved — not yet confirmed',
    'confirmed' => 'Confirmed',
    'rejected' => 'Rejected',
];
$statusTone = [
    'requested' => 'warning',
    'pending_approval' => 'info',
    'approved' => 'primary',
    'confirmed' => 'success',
    'rejected' => 'danger',
];
?>

<div class="fixture-change-requests-page">
    <div class="venues-notices" aria-live="polite">
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Fixture change request saved.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <?php hub_render_metric_grid([
        ['label' => 'Open requests', 'value' => number_format(count($openRequests)), 'meta' => 'Awaiting approval/confirmation', 'icon' => 'fa-calendar-days', 'tone' => count($openRequests) > 0 ? 'warning' : 'success'],
        ['label' => 'Confirmed', 'value' => number_format(count(array_filter($closedRequests, static fn(array $r): bool => $r['status'] === 'confirmed'))), 'meta' => 'Official change', 'icon' => 'fa-circle-check', 'tone' => 'success'],
        ['label' => 'Rejected', 'value' => number_format(count(array_filter($closedRequests, static fn(array $r): bool => $r['status'] === 'rejected'))), 'meta' => 'Original fixture stands', 'icon' => 'fa-circle-xmark', 'tone' => 'neutral'],
    ], 'Fixture change summary'); ?>

    <section class="hub-section" aria-labelledby="fixtureChangeOpenTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">In progress</div>
                <h2 id="fixtureChangeOpenTitle">Open requests</h2>
                <p>Treat the original fixture as official until approval is confirmed — do not update public channels before then.</p>
            </div>
        </div>

        <?php if ($openRequests === []): ?>
            <div class="hub-empty-state">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <h3>No open requests</h3>
            </div>
        <?php else: ?>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Fixture</th>
                            <th>Requested change</th>
                            <th>Checklist</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($openRequests as $request): ?>
                            <?php
                            $checklist = [
                                (int) $request['club_availability_checked'],
                                (int) $request['opposition_agreement_recorded'],
                                (int) $request['competition_approval_requested'],
                                (int) $request['competition_approval_received'],
                                (int) $request['officials_informed'],
                                (int) $request['public_updated'],
                            ];
                            $checklistDone = array_sum($checklist);
                            ?>
                            <tr>
                                <td>
                                    <?= h(date('d/m/Y', strtotime((string) $request['match_date']))) ?> vs <?= h((string) $request['opponent']) ?>
                                    <div class="venues-muted"><?= h((string) $request['competition']) ?></div>
                                </td>
                                <td>
                                    <?= $request['requested_date'] ? h(date('d/m/Y', strtotime((string) $request['requested_date']))) : '<span class="venues-muted">Date unchanged</span>' ?>
                                    <?php if ($request['requested_venue']): ?><div class="venues-muted">Venue: <?= h((string) $request['requested_venue']) ?></div><?php endif; ?>
                                </td>
                                <td><?= $checklistDone ?>/6 steps complete</td>
                                <td><span class="badge text-bg-<?= $statusTone[$request['status']] ?>"><?= h($statusLabels[$request['status']]) ?></span></td>
                                <td class="text-end">
                                    <a href="fixture_change_request_edit.php?id=<?= (int) $request['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
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

    <?php if ($closedRequests !== []): ?>
        <section class="hub-section" aria-labelledby="fixtureChangeHistoryTitle">
            <div class="venues-directory__header">
                <div>
                    <div class="venues-directory__eyebrow">History</div>
                    <h2 id="fixtureChangeHistoryTitle">Confirmed &amp; rejected</h2>
                </div>
            </div>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Fixture</th>
                            <th>Outcome</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($closedRequests, 0, 25) as $request): ?>
                            <tr>
                                <td><?= h(date('d/m/Y', strtotime((string) $request['match_date']))) ?> vs <?= h((string) $request['opponent']) ?></td>
                                <td><span class="badge text-bg-<?= $statusTone[$request['status']] ?>"><?= h($statusLabels[$request['status']]) ?></span></td>
                                <td class="text-end">
                                    <a href="fixture_change_request_edit.php?id=<?= (int) $request['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
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
