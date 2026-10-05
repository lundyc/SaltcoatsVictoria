<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => 'Tasks & Season Planner',
    'subtitle' => 'Every deadline in one place — from a match-readiness check to an AGM notice period.',
    'actions' => [
        ['label' => 'Add task', 'href' => '/secretary_task_edit.php', 'class' => 'btn btn-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/secretary_tasks.php';

$today = date('Y-m-d');
$tasks = secretary_task_list($pdo);
$openTasks = array_values(array_filter($tasks, static fn(array $t): bool => $t['status'] !== 'done'));
$doneTasks = array_values(array_filter($tasks, static fn(array $t): bool => $t['status'] === 'done'));
$overdueTasks = array_values(array_filter($openTasks, static fn(array $t): bool => !empty($t['due_at']) && $t['due_at'] < $today));
$urgentTasks = array_values(array_filter($openTasks, static fn(array $t): bool => $t['priority'] === 'urgent'));

$priorityTone = ['urgent' => 'danger', 'normal' => 'info', 'low' => 'neutral'];
$statusTone = ['open' => 'warning', 'waiting' => 'info', 'done' => 'success'];
?>

<div class="secretary-tasks-page">
    <div class="venues-notices" aria-live="polite">
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Task saved.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Task deleted.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <?php hub_render_metric_grid([
        ['label' => 'Open tasks', 'value' => number_format(count($openTasks)), 'meta' => 'Not yet done', 'icon' => 'fa-list-check', 'tone' => 'primary'],
        ['label' => 'Overdue', 'value' => number_format(count($overdueTasks)), 'meta' => 'Past due date', 'icon' => 'fa-clock-rotate-left', 'tone' => count($overdueTasks) > 0 ? 'danger' : 'success'],
        ['label' => 'Urgent', 'value' => number_format(count($urgentTasks)), 'meta' => 'Marked urgent', 'icon' => 'fa-triangle-exclamation', 'tone' => count($urgentTasks) > 0 ? 'warning' : 'success'],
        ['label' => 'Done', 'value' => number_format(count($doneTasks)), 'meta' => 'Completed', 'icon' => 'fa-circle-check', 'tone' => 'neutral'],
    ], 'Task summary'); ?>

    <section class="hub-section" aria-labelledby="secretaryTasksTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Open</div>
                <h2 id="secretaryTasksTitle">Open tasks, soonest due first</h2>
                <p>Includes correspondence deadlines, registration/competition deadlines, and compliance/AGM dates — anything with a due date lives here.</p>
            </div>
        </div>

        <?php if ($openTasks === []): ?>
            <div class="hub-empty-state">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <h3>Nothing open</h3>
                <p>Add a task whenever an email, deadline or committee action needs tracking.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Category</th>
                            <th>Owner</th>
                            <th>Due</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($openTasks as $task): ?>
                            <?php $isOverdue = !empty($task['due_at']) && $task['due_at'] < $today; ?>
                            <tr>
                                <td>
                                    <?= h((string) $task['title']) ?>
                                    <?php if (!empty($task['source'])): ?><div class="venues-muted"><?= h((string) $task['source']) ?></div><?php endif; ?>
                                </td>
                                <td><?= h(SECRETARY_TASK_CATEGORIES[$task['category']] ?? (string) $task['category']) ?></td>
                                <td><?= $task['owner'] ? h((string) $task['owner']) : '<span class="venues-muted">Unassigned</span>' ?></td>
                                <td class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
                                    <?= $task['due_at'] ? h(date('d/m/Y', strtotime((string) $task['due_at']))) : '<span class="venues-muted">No date</span>' ?>
                                    <?= $isOverdue ? ' · overdue' : '' ?>
                                </td>
                                <td><span class="badge text-bg-<?= $priorityTone[$task['priority']] ?>"><?= ucfirst((string) $task['priority']) ?></span></td>
                                <td><span class="badge text-bg-<?= $statusTone[$task['status']] ?>"><?= ucfirst((string) $task['status']) ?></span></td>
                                <td class="text-end">
                                    <a href="secretary_task_edit.php?id=<?= (int) $task['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
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

    <?php if ($doneTasks !== []): ?>
        <section class="hub-section" aria-labelledby="secretaryTasksDoneTitle">
            <div class="venues-directory__header">
                <div>
                    <div class="venues-directory__eyebrow">History</div>
                    <h2 id="secretaryTasksDoneTitle">Completed</h2>
                </div>
            </div>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Category</th>
                            <th>Completed</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($doneTasks, 0, 25) as $task): ?>
                            <tr>
                                <td><?= h((string) $task['title']) ?></td>
                                <td><?= h(SECRETARY_TASK_CATEGORIES[$task['category']] ?? (string) $task['category']) ?></td>
                                <td><?= $task['completed_at'] ? h(date('d/m/Y', strtotime((string) $task['completed_at']))) : '—' ?></td>
                                <td class="text-end">
                                    <a href="secretary_task_edit.php?id=<?= (int) $task['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
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
