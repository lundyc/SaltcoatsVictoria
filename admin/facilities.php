<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Facilities',
    'title' => 'Facilities Register',
    'subtitle' => 'Track ground jobs, pitch checks, repairs and safety issues in one place.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/facility_maintenance.php';
require_once __DIR__ . '/lib/audit.php';

facility_maintenance_ensure_schema($pdo);

$errors = [];
$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } else {
        $postAction = (string) ($_POST['post_action'] ?? 'save_job');
        try {
            if ($postAction === 'update_status') {
                $jobId = (int) ($_POST['id'] ?? 0);
                facility_maintenance_update_status($pdo, $jobId, (string) ($_POST['status'] ?? 'open'));
                auditLog($pdo, 'facility_job_status_updated', 'Updated facilities job #' . $jobId);
                header('Location: facilities.php?updated=1');
                exit;
            }

            $userId = (int) ($_SESSION['hub_user_id'] ?? 0) ?: null;
            $jobId = facility_maintenance_save($pdo, null, [
                'area' => (string) ($_POST['area'] ?? ''),
                'category' => (string) ($_POST['category'] ?? 'other'),
                'title' => (string) ($_POST['title'] ?? ''),
                'owner' => (string) ($_POST['owner'] ?? ''),
                'due_at' => (string) ($_POST['due_at'] ?? ''),
                'priority' => (string) ($_POST['priority'] ?? 'normal'),
                'status' => (string) ($_POST['status'] ?? 'open'),
                'reported_by' => (string) ($_POST['reported_by'] ?? ''),
                'notes' => (string) ($_POST['notes'] ?? ''),
            ], $userId);
            auditLog($pdo, 'facility_job_created', 'Created facilities job #' . $jobId);
            header('Location: facilities.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $notice = 'Facilities job added.';
} elseif (isset($_GET['updated'])) {
    $notice = 'Facilities job updated.';
}

$openJobs = facility_maintenance_list($pdo, 'open');
$historyJobs = facility_maintenance_list($pdo, 'history');
$summary = facility_maintenance_summary(array_merge($openJobs, $historyJobs));
?>

<div class="facilities-page">
    <div class="venues-notices" aria-live="polite">
        <?php if ($notice !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span><?= h($notice) ?></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <div>
                    <div class="fw-semibold">The facilities register could not be updated.</div>
                    <ul class="mb-0 mt-1"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php hub_render_metric_grid([
        ['label' => 'Open jobs', 'value' => number_format($summary['open']), 'meta' => 'Not closed', 'icon' => 'fa-screwdriver-wrench', 'tone' => 'primary'],
        ['label' => 'Overdue', 'value' => number_format($summary['overdue']), 'meta' => 'Past due date', 'icon' => 'fa-clock-rotate-left', 'tone' => $summary['overdue'] > 0 ? 'danger' : 'success'],
        ['label' => 'Urgent', 'value' => number_format($summary['urgent']), 'meta' => 'Open urgent jobs', 'icon' => 'fa-triangle-exclamation', 'tone' => $summary['urgent'] > 0 ? 'warning' : 'success'],
        ['label' => 'Completed', 'value' => number_format($summary['done']), 'meta' => 'Closed jobs', 'icon' => 'fa-circle-check', 'tone' => 'neutral'],
    ], 'Facilities summary'); ?>

    <section class="hub-section" aria-labelledby="facilityAddTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">New job</div>
                <h2 id="facilityAddTitle">Add facilities job</h2>
                <p>Use this for pitch inspections, ground repairs, kit or equipment issues, and safety actions.</p>
            </div>
        </div>
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="save_job">
            <div class="col-md-6">
                <label class="form-label" for="facilityTitle">Job title</label>
                <input class="form-control" id="facilityTitle" name="title" required maxlength="255" placeholder="e.g. Replace broken barrier clip">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="facilityCategory">Category</label>
                <select class="form-select" id="facilityCategory" name="category">
                    <?php foreach (FACILITY_MAINTENANCE_CATEGORIES as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="facilityArea">Area</label>
                <input class="form-control" id="facilityArea" name="area" maxlength="120" placeholder="Pitch, tea hut, stand">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="facilityOwner">Owner</label>
                <input class="form-control" id="facilityOwner" name="owner" maxlength="190">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="facilityDueAt">Due date</label>
                <input class="form-control" id="facilityDueAt" name="due_at" type="date">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="facilityPriority">Priority</label>
                <select class="form-select" id="facilityPriority" name="priority">
                    <?php foreach (FACILITY_MAINTENANCE_PRIORITIES as $key => $label): ?><option value="<?= h($key) ?>" <?= $key === 'normal' ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="facilityReportedBy">Reported by</label>
                <input class="form-control" id="facilityReportedBy" name="reported_by" maxlength="190">
            </div>
            <div class="col-12">
                <label class="form-label" for="facilityNotes">Notes</label>
                <textarea class="form-control" id="facilityNotes" name="notes" rows="3"></textarea>
            </div>
            <div class="col-12">
                <button class="btn btn-brand" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add job</button>
            </div>
        </form>
    </section>

    <section class="hub-section" aria-labelledby="facilityOpenTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Open</div>
                <h2 id="facilityOpenTitle">Open facilities jobs</h2>
            </div>
        </div>
        <?php if ($openJobs === []): ?>
            <div class="hub-empty-state">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <h3>No open facilities jobs</h3>
            </div>
        <?php else: ?>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead><tr><th>Job</th><th>Category</th><th>Owner</th><th>Due</th><th>Priority</th><th>Status</th><th class="text-end">Update</th></tr></thead>
                    <tbody>
                    <?php foreach ($openJobs as $job): ?>
                        <?php $isOverdue = facility_maintenance_is_overdue($job); ?>
                        <tr>
                            <td>
                                <strong><?= h((string) $job['title']) ?></strong>
                                <?php if ((string) $job['area'] !== ''): ?><div class="venues-muted"><?= h((string) $job['area']) ?></div><?php endif; ?>
                                <?php if ((string) ($job['notes'] ?? '') !== ''): ?><div class="venues-muted"><?= h((string) $job['notes']) ?></div><?php endif; ?>
                            </td>
                            <td><?= h(FACILITY_MAINTENANCE_CATEGORIES[$job['category']] ?? (string) $job['category']) ?></td>
                            <td><?= $job['owner'] ? h((string) $job['owner']) : '<span class="venues-muted">Unassigned</span>' ?></td>
                            <td class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>"><?= $job['due_at'] ? h(date('d/m/Y', strtotime((string) $job['due_at']))) : '<span class="venues-muted">No date</span>' ?><?= $isOverdue ? ' overdue' : '' ?></td>
                            <td><span class="badge text-bg-<?= h(facility_maintenance_priority_tone((string) $job['priority'])) ?>"><?= h(FACILITY_MAINTENANCE_PRIORITIES[$job['priority']] ?? (string) $job['priority']) ?></span></td>
                            <td><span class="badge text-bg-<?= h(facility_maintenance_status_tone((string) $job['status'])) ?>"><?= h(FACILITY_MAINTENANCE_STATUSES[$job['status']] ?? (string) $job['status']) ?></span></td>
                            <td class="text-end">
                                <form method="post" class="d-inline-flex gap-2 justify-content-end">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="post_action" value="update_status">
                                    <input type="hidden" name="id" value="<?= (int) $job['id'] ?>">
                                    <select class="form-select form-select-sm" name="status" aria-label="Status for <?= h((string) $job['title']) ?>">
                                        <?php foreach (FACILITY_MAINTENANCE_STATUSES as $key => $label): ?><option value="<?= h($key) ?>" <?= (string) $job['status'] === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($historyJobs !== []): ?>
        <section class="hub-section" aria-labelledby="facilityHistoryTitle">
            <div class="venues-directory__header">
                <div>
                    <div class="venues-directory__eyebrow">History</div>
                    <h2 id="facilityHistoryTitle">Closed facilities jobs</h2>
                </div>
            </div>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead><tr><th>Job</th><th>Category</th><th>Status</th><th>Completed</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($historyJobs, 0, 25) as $job): ?>
                        <tr>
                            <td><?= h((string) $job['title']) ?></td>
                            <td><?= h(FACILITY_MAINTENANCE_CATEGORIES[$job['category']] ?? (string) $job['category']) ?></td>
                            <td><span class="badge text-bg-<?= h(facility_maintenance_status_tone((string) $job['status'])) ?>"><?= h(FACILITY_MAINTENANCE_STATUSES[$job['status']] ?? (string) $job['status']) ?></span></td>
                            <td><?= $job['completed_at'] ? h(date('d/m/Y', strtotime((string) $job['completed_at']))) : '<span class="venues-muted">-</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
