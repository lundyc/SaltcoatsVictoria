<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => 'Secretary Dashboard',
    'subtitle' => 'Next fixture and discipline at a glance.',
    'actions' => [
        ['label' => 'Discipline register', 'href' => '/discipline_register.php', 'class' => 'btn btn-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/discipline_register.php';
require_once __DIR__ . '/lib/player_registration.php';
require_once __DIR__ . '/lib/secretary_tasks.php';
require_once __DIR__ . '/lib/fixture_change_requests.php';
require_once __DIR__ . '/lib/committee_meetings.php';

$today = date('Y-m-d');

$nextFixture = $pdo->query(
    "SELECT * FROM match_fixtures WHERE match_date >= CURDATE() ORDER BY match_date ASC, kickoff_time ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC) ?: null;

$scanFixtures = $pdo->query(
    "SELECT id, match_date, opponent, competition FROM match_fixtures
     WHERE match_date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
     ORDER BY match_date DESC"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pendingIncidents = discipline_pending_incidents($pdo, $scanFixtures);
$pendingRedCount = count(array_filter($pendingIncidents, static fn(array $row): bool => $row['card_type'] === 'red'));
$pendingYellowCount = count($pendingIncidents) - $pendingRedCount;

$register = discipline_list_register($pdo);
$confirmedSuspensions = array_values(array_filter($register, static function (array $row) use ($today): bool {
    if ($row['status'] !== 'suspension_confirmed') {
        return false;
    }
    $clearDate = trim((string) ($row['suspension_clear_date'] ?? ''));
    return $clearDate === '' || $clearDate >= $today;
}));

$nextFixtureMeta = '';
if ($nextFixture) {
    $parts = [date('D d M', strtotime((string) $nextFixture['match_date']))];
    if (!empty($nextFixture['kickoff_time'])) {
        $parts[] = date('H:i', strtotime((string) $nextFixture['kickoff_time']));
    }
    if (!empty($nextFixture['venue'])) {
        $parts[] = (string) $nextFixture['venue'];
    }
    $nextFixtureMeta = implode(' · ', $parts);
}

$registrationRows = registration_current_all($pdo);
$registrationCounts = ['green' => 0, 'amber' => 0, 'red' => 0];
foreach ($registrationRows as $row) {
    $light = registration_traffic_light(!empty($row['registration_id']) ? $row : null);
    $registrationCounts[$light['status']]++;
}

$correspondenceOpen = correspondence_list($pdo);
$correspondenceOpen = array_values(array_filter($correspondenceOpen, static fn(array $c): bool => $c['status'] !== 'filed'));
$correspondenceUrgent = array_values(array_filter($correspondenceOpen, static fn(array $c): bool => $c['label'] === 'action_urgent'));

$upcomingTasks = secretary_task_upcoming($pdo, 14);
$overdueTasks = array_values(array_filter($upcomingTasks, static fn(array $t): bool => !empty($t['due_at']) && $t['due_at'] < $today));

$openFixtureChangeRequests = array_values(array_filter(
    fixture_change_requests_list($pdo),
    static fn(array $r): bool => !in_array($r['status'], ['confirmed', 'rejected'], true)
));

$nextMeeting = committee_meeting_next($pdo);
?>

<div class="secretary-dashboard-page">
    <?php hub_render_metric_grid([
        [
            'label' => 'Next match',
            'value' => $nextFixture ? ((int) $nextFixture['is_home'] === 1 ? 'vs ' : '@ ') . (string) $nextFixture['opponent'] : 'No fixture scheduled',
            'meta' => $nextFixtureMeta,
            'icon' => 'fa-calendar-day',
            'tone' => 'primary',
            'href' => $nextFixture ? '/match.php?id=' . (int) $nextFixture['id'] : '',
        ],
        [
            'label' => 'Needs logging',
            'value' => number_format(count($pendingIncidents)),
            'meta' => count($pendingIncidents) > 0
                ? trim($pendingRedCount . ' red' . ($pendingYellowCount > 0 ? ', ' . $pendingYellowCount . ' yellow' : ''))
                : 'All caught up',
            'icon' => 'fa-square-exclamation',
            'tone' => $pendingRedCount > 0 ? 'danger' : ($pendingYellowCount > 0 ? 'warning' : 'success'),
            'href' => '/discipline_register.php',
        ],
        [
            'label' => 'Active suspensions',
            'value' => number_format(count($confirmedSuspensions)),
            'meta' => 'Confirmed and not yet cleared',
            'icon' => 'fa-person-circle-xmark',
            'tone' => count($confirmedSuspensions) > 0 ? 'danger' : 'success',
            'href' => '/discipline_register.php',
        ],
    ], 'Secretary summary'); ?>

    <?php hub_render_metric_grid([
        [
            'label' => 'Registrations',
            'value' => number_format($registrationCounts['amber'] + $registrationCounts['red']),
            'meta' => $registrationCounts['red'] > 0 ? $registrationCounts['red'] . ' not eligible' : 'Need checking or confirming',
            'icon' => 'fa-id-card-clip',
            'tone' => $registrationCounts['red'] > 0 ? 'danger' : ($registrationCounts['amber'] > 0 ? 'warning' : 'success'),
            'href' => '/player_registrations.php',
        ],
        [
            'label' => 'Correspondence',
            'value' => number_format(count($correspondenceOpen)),
            'meta' => count($correspondenceUrgent) > 0 ? count($correspondenceUrgent) . ' urgent' : 'Open items',
            'icon' => 'fa-envelope',
            'tone' => count($correspondenceUrgent) > 0 ? 'danger' : (count($correspondenceOpen) > 0 ? 'warning' : 'success'),
            'href' => '/secretary_correspondence.php',
        ],
        [
            'label' => 'Fixture changes',
            'value' => number_format(count($openFixtureChangeRequests)),
            'meta' => 'Awaiting approval/confirmation',
            'icon' => 'fa-calendar-days',
            'tone' => count($openFixtureChangeRequests) > 0 ? 'warning' : 'success',
            'href' => '/fixture_change_requests.php',
        ],
        [
            'label' => 'Compliance & tasks',
            'value' => number_format(count($upcomingTasks)),
            'meta' => count($overdueTasks) > 0 ? count($overdueTasks) . ' overdue' : 'Due within 14 days',
            'icon' => 'fa-list-check',
            'tone' => count($overdueTasks) > 0 ? 'danger' : (count($upcomingTasks) > 0 ? 'warning' : 'success'),
            'href' => '/secretary_tasks.php',
        ],
    ], 'Registrations, correspondence, fixtures and tasks'); ?>

    <?php if ($nextMeeting): ?>
        <section class="hub-context-bar mb-4" role="status">
            <div>
                <i class="fa-solid fa-people-group" aria-hidden="true"></i>
                <span>Next committee meeting: <strong><?= h(date('d M Y', strtotime((string) $nextMeeting['next_meeting_date']))) ?></strong></span>
            </div>
            <a href="committee_meetings.php">View committee &amp; AGM records</a>
        </section>
    <?php endif; ?>

    <section class="hub-section" aria-labelledby="secretaryDisciplineTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Discipline</div>
                <h2 id="secretaryDisciplineTitle">Needs your attention</h2>
                <p>Every red card is an automatic suspension the moment it happens — it applies immediately, whether or not confirmation has arrived. Verify these before naming a squad.</p>
            </div>
            <a href="discipline_register.php" class="btn btn-brand venues-directory__add">
                <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                <span>Open discipline register</span>
            </a>
        </div>

        <?php if ($pendingIncidents === [] && $confirmedSuspensions === []): ?>
            <div class="hub-empty-state">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <h3>Nothing outstanding</h3>
                <p>No unlogged cards and no active suspensions on record.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Player</th>
                            <th>Status</th>
                            <th>Detail</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($pendingIncidents, 0, 8) as $pending): ?>
                            <tr>
                                <td><?= h($pending['player_name']) ?></td>
                                <td><span class="badge text-bg-<?= $pending['card_type'] === 'red' ? 'danger' : 'warning' ?>"><?= $pending['card_type'] === 'red' ? 'Red — needs logging' : 'Yellow — needs logging' ?></span></td>
                                <td>vs <?= h($pending['opponent']) ?><?= $pending['incident_date'] !== '' ? ' · ' . h(date('d/m/Y', strtotime($pending['incident_date']))) : '' ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-brand" href="discipline_incident.php?<?= http_build_query([
                                        'player_id' => $pending['player_id'] ?? '',
                                        'fixture_id' => $pending['fixture_id'],
                                        'match_event_id' => $pending['match_event_id'],
                                        'card_type' => $pending['card_type'],
                                        'incident_date' => $pending['incident_date'],
                                        'competition' => $pending['competition'],
                                    ]) ?>">Log</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($confirmedSuspensions as $suspension): ?>
                            <tr>
                                <td><?= h((string) $suspension['player_name']) ?></td>
                                <td><span class="badge text-bg-danger">Suspension confirmed</span></td>
                                <td>
                                    <?= h((string) ($suspension['suspension_summary'] ?: 'No summary recorded')) ?>
                                    <?php if (!empty($suspension['suspension_clear_date'])): ?>
                                        · Eligible from <?= h(date('d/m/Y', strtotime((string) $suspension['suspension_clear_date']))) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="discipline_incident.php?id=<?= (int) $suspension['id'] ?>">Review</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($upcomingTasks !== []): ?>
        <section class="hub-section" aria-labelledby="secretaryTasksSectionTitle">
            <div class="venues-directory__header">
                <div>
                    <div class="venues-directory__eyebrow">Season planner</div>
                    <h2 id="secretaryTasksSectionTitle">Due within 14 days</h2>
                </div>
                <a href="secretary_tasks.php" class="btn btn-brand venues-directory__add">
                    <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                    <span>Open tasks &amp; planner</span>
                </a>
            </div>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr><th>Task</th><th>Category</th><th>Due</th><th class="text-end">Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($upcomingTasks, 0, 8) as $task): ?>
                            <?php $isOverdue = !empty($task['due_at']) && $task['due_at'] < $today; ?>
                            <tr>
                                <td><?= h((string) $task['title']) ?></td>
                                <td><?= h(SECRETARY_TASK_CATEGORIES[$task['category']] ?? (string) $task['category']) ?></td>
                                <td class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>"><?= h(date('d/m/Y', strtotime((string) $task['due_at']))) ?><?= $isOverdue ? ' · overdue' : '' ?></td>
                                <td class="text-end"><a href="secretary_task_edit.php?id=<?= (int) $task['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen" aria-hidden="true"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
