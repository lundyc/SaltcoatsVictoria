<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => 'Correspondence',
    'subtitle' => 'SFA, WoSFL, competition and opposition correspondence — the inbox workflow labels from the handbook.',
    'actions' => [
        ['label' => 'Log correspondence', 'href' => '/secretary_correspondence_edit.php', 'class' => 'btn btn-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/secretary_tasks.php';

$today = date('Y-m-d');
$items = correspondence_list($pdo);
$openItems = array_values(array_filter($items, static fn(array $i): bool => $i['status'] !== 'filed'));
$filedItems = array_values(array_filter($items, static fn(array $i): bool => $i['status'] === 'filed'));

$labelTone = [
    'action_urgent' => 'danger',
    'action' => 'warning',
    'waiting' => 'info',
    'fixture' => 'primary',
    'registration' => 'primary',
    'discipline' => 'danger',
    'committee' => 'secondary',
    'filed' => 'success',
];
?>

<div class="secretary-correspondence-page">
    <div class="venues-notices" aria-live="polite">
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Correspondence saved.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <?php hub_render_metric_grid([
        ['label' => 'Open', 'value' => number_format(count($openItems)), 'meta' => 'Action or waiting', 'icon' => 'fa-envelope-open-text', 'tone' => count($openItems) > 0 ? 'warning' : 'success'],
        ['label' => 'Urgent', 'value' => number_format(count(array_filter($openItems, static fn(array $i): bool => $i['label'] === 'action_urgent'))), 'meta' => 'ACTION - URGENT', 'icon' => 'fa-triangle-exclamation', 'tone' => 'danger'],
        ['label' => 'Filed', 'value' => number_format(count($filedItems)), 'meta' => 'Retained as the record', 'icon' => 'fa-box-archive', 'tone' => 'neutral'],
    ], 'Correspondence summary'); ?>

    <section class="hub-section" aria-labelledby="correspondenceOpenTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Inbox</div>
                <h2 id="correspondenceOpenTitle">Open correspondence</h2>
                <p>If an item has a deadline, create a task for it — see "Create task" below each row.</p>
            </div>
        </div>

        <?php if ($openItems === []): ?>
            <div class="hub-empty-state">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <h3>Inbox clear</h3>
            </div>
        <?php else: ?>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>From</th>
                            <th>Label</th>
                            <th>Deadline</th>
                            <th>Owner</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($openItems as $item): ?>
                            <?php $isOverdue = !empty($item['deadline']) && $item['deadline'] < $today; ?>
                            <tr>
                                <td><?= h((string) $item['subject']) ?></td>
                                <td><?= $item['organisation'] ? h((string) $item['organisation']) : '<span class="venues-muted">—</span>' ?></td>
                                <td><span class="badge text-bg-<?= $labelTone[$item['label']] ?? 'secondary' ?>"><?= h(SECRETARY_TASK_LABELS[$item['label']] ?? (string) $item['label']) ?></span></td>
                                <td class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
                                    <?= $item['deadline'] ? h(date('d/m/Y', strtotime((string) $item['deadline']))) : '<span class="venues-muted">None</span>' ?>
                                </td>
                                <td><?= $item['action_owner'] ? h((string) $item['action_owner']) : '<span class="venues-muted">Unassigned</span>' ?></td>
                                <td class="text-end">
                                    <?php if (!empty($item['deadline'])): ?>
                                        <a href="secretary_task_edit.php?<?= http_build_query([
                                            'title' => 'Follow up: ' . $item['subject'],
                                            'category' => 'correspondence',
                                            'source' => $item['organisation'] ?: 'Correspondence',
                                            'correspondence_id' => $item['id'],
                                        ]) ?>" class="btn btn-sm btn-outline-secondary" title="Create task from this deadline">
                                            <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a href="secretary_correspondence_edit.php?id=<?= (int) $item['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
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

    <?php if ($filedItems !== []): ?>
        <section class="hub-section" aria-labelledby="correspondenceFiledTitle">
            <div class="venues-directory__header">
                <div>
                    <div class="venues-directory__eyebrow">Filed</div>
                    <h2 id="correspondenceFiledTitle">Filed correspondence</h2>
                </div>
            </div>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>From</th>
                            <th>Reference</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($filedItems, 0, 25) as $item): ?>
                            <tr>
                                <td><?= h((string) $item['subject']) ?></td>
                                <td><?= $item['organisation'] ? h((string) $item['organisation']) : '—' ?></td>
                                <td><?= $item['reference'] ? h((string) $item['reference']) : '—' ?></td>
                                <td class="text-end">
                                    <a href="secretary_correspondence_edit.php?id=<?= (int) $item['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
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
