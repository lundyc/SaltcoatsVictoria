<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => 'Committee Meetings',
    'subtitle' => 'Minutes, actions and AGM records.',
    'actions' => [
        ['label' => 'Add meeting', 'href' => '/committee_meeting_edit.php', 'class' => 'btn btn-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/committee_meetings.php';

$meetings = committee_meetings_list($pdo);
$nextMeeting = committee_meeting_next($pdo);
$agmMeetings = array_values(array_filter($meetings, static fn(array $m): bool => $m['meeting_type'] === 'agm'));

$typeLabels = ['committee' => 'Committee meeting', 'agm' => 'AGM', 'egm' => 'EGM'];
$typeTone = ['committee' => 'primary', 'agm' => 'warning', 'egm' => 'danger'];
?>

<div class="committee-meetings-page">
    <div class="venues-notices" aria-live="polite">
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Meeting saved.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <?php hub_render_metric_grid([
        ['label' => 'Next meeting', 'value' => $nextMeeting ? h(date('d M Y', strtotime((string) $nextMeeting['next_meeting_date']))) : 'Not scheduled', 'meta' => $nextMeeting ? h($typeLabels[$nextMeeting['meeting_type']]) : '', 'icon' => 'fa-calendar-days', 'tone' => 'primary'],
        ['label' => 'Meetings recorded', 'value' => number_format(count($meetings)), 'meta' => 'All time', 'icon' => 'fa-people-group', 'tone' => 'info'],
        ['label' => 'AGMs recorded', 'value' => number_format(count($agmMeetings)), 'meta' => 'Annual general meetings', 'icon' => 'fa-gavel', 'tone' => 'neutral'],
    ], 'Meeting summary'); ?>

    <section class="hub-section" aria-labelledby="committeeMeetingsTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Records</div>
                <h2 id="committeeMeetingsTitle">All meetings</h2>
            </div>
        </div>

        <?php if ($meetings === []): ?>
            <div class="hub-empty-state">
                <i class="fa-solid fa-people-group" aria-hidden="true"></i>
                <h3>No meetings recorded yet</h3>
            </div>
        <?php else: ?>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Next meeting</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($meetings as $meeting): ?>
                            <tr>
                                <td><?= h(date('d/m/Y', strtotime((string) $meeting['meeting_date']))) ?></td>
                                <td><span class="badge text-bg-<?= $typeTone[$meeting['meeting_type']] ?>"><?= h($typeLabels[$meeting['meeting_type']]) ?></span></td>
                                <td><span class="badge text-bg-<?= $meeting['status'] === 'final' ? 'success' : 'secondary' ?>"><?= ucfirst((string) $meeting['status']) ?></span></td>
                                <td><?= $meeting['next_meeting_date'] ? h(date('d/m/Y', strtotime((string) $meeting['next_meeting_date']))) : '<span class="venues-muted">—</span>' ?></td>
                                <td class="text-end">
                                    <a href="committee_meeting_edit.php?id=<?= (int) $meeting['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
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
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
