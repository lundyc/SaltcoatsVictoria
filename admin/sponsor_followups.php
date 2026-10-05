<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Sponsorship',
    'title' => 'Sponsor Follow-ups',
    'subtitle' => 'Track renewal calls, sponsor leads and commercial next steps.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/sponsorship_catalog.php';
require_once __DIR__ . '/lib/sponsor_followups.php';
require_once __DIR__ . '/lib/audit.php';

ensureSponsorshipCatalogSchema($pdo);
sponsor_followups_ensure_schema($pdo);

$errors = [];
$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    } else {
        $postAction = (string) ($_POST['post_action'] ?? 'save_followup');
        try {
            if ($postAction === 'update_stage') {
                $followupId = (int) ($_POST['id'] ?? 0);
                sponsor_followup_update_stage($pdo, $followupId, (string) ($_POST['stage'] ?? 'contacted'));
                auditLog($pdo, 'sponsor_followup_stage_updated', 'Updated sponsor follow-up #' . $followupId);
                header('Location: sponsor_followups.php?updated=1');
                exit;
            }

            $userId = (int) ($_SESSION['hub_user_id'] ?? 0) ?: null;
            $followupId = sponsor_followup_save($pdo, null, [
                'sponsor_id' => (int) ($_POST['sponsor_id'] ?? 0),
                'stage' => (string) ($_POST['stage'] ?? 'lead'),
                'title' => (string) ($_POST['title'] ?? ''),
                'owner' => (string) ($_POST['owner'] ?? ''),
                'next_contact_at' => (string) ($_POST['next_contact_at'] ?? ''),
                'expected_value' => (float) ($_POST['expected_value'] ?? 0),
                'priority' => (string) ($_POST['priority'] ?? 'normal'),
                'last_contact_at' => (string) ($_POST['last_contact_at'] ?? ''),
                'notes' => (string) ($_POST['notes'] ?? ''),
            ], $userId);
            auditLog($pdo, 'sponsor_followup_created', 'Created sponsor follow-up #' . $followupId);
            header('Location: sponsor_followups.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

if (isset($_GET['saved'])) {
    $notice = 'Sponsor follow-up added.';
} elseif (isset($_GET['updated'])) {
    $notice = 'Sponsor follow-up updated.';
}

$sponsors = $pdo->query('SELECT id, name FROM sponsors WHERE is_active = 1 ORDER BY name ASC')->fetchAll(PDO::FETCH_ASSOC);
$openFollowups = sponsor_followup_list($pdo, 'open');
$closedFollowups = sponsor_followup_list($pdo, 'closed');
$summary = sponsor_followup_summary(array_merge($openFollowups, $closedFollowups));
?>

<div class="sponsor-followups-page">
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
                    <div class="fw-semibold">The follow-up could not be saved.</div>
                    <ul class="mb-0 mt-1"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php hub_render_metric_grid([
        ['label' => 'Open follow-ups', 'value' => number_format($summary['open']), 'meta' => 'Active opportunities', 'icon' => 'fa-phone-volume', 'tone' => 'primary'],
        ['label' => 'Overdue', 'value' => number_format($summary['overdue']), 'meta' => 'Past next-contact date', 'icon' => 'fa-clock-rotate-left', 'tone' => $summary['overdue'] > 0 ? 'danger' : 'success'],
        ['label' => 'Urgent', 'value' => number_format($summary['urgent']), 'meta' => 'Open urgent items', 'icon' => 'fa-triangle-exclamation', 'tone' => $summary['urgent'] > 0 ? 'warning' : 'success'],
        ['label' => 'Pipeline value', 'value' => gbp($summary['pipeline_value']), 'meta' => 'Open expected value', 'icon' => 'fa-sterling-sign', 'tone' => 'info'],
        ['label' => 'Won value', 'value' => gbp($summary['won_value']), 'meta' => 'Closed won', 'icon' => 'fa-circle-check', 'tone' => 'success'],
    ], 'Sponsor follow-up summary'); ?>

    <section class="hub-section" aria-labelledby="sponsorFollowupAddTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">New follow-up</div>
                <h2 id="sponsorFollowupAddTitle">Add sponsor follow-up</h2>
                <p>Use this for renewal calls, proposal chasing, warm leads and sponsor relationship tasks.</p>
            </div>
        </div>
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <input type="hidden" name="post_action" value="save_followup">
            <div class="col-md-4">
                <label class="form-label" for="followupSponsor">Sponsor</label>
                <select class="form-select" id="followupSponsor" name="sponsor_id" required>
                    <option value="">Select sponsor</option>
                    <?php foreach ($sponsors as $sponsor): ?><option value="<?= (int) $sponsor['id'] ?>"><?= h((string) $sponsor['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="followupTitle">Follow-up</label>
                <input class="form-control" id="followupTitle" name="title" required maxlength="255" placeholder="e.g. Renew pitch-side board">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="followupStage">Stage</label>
                <select class="form-select" id="followupStage" name="stage">
                    <?php foreach (SPONSOR_FOLLOWUP_STAGES as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="followupOwner">Owner</label>
                <input class="form-control" id="followupOwner" name="owner" maxlength="190">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="followupNextContact">Next contact</label>
                <input class="form-control" id="followupNextContact" name="next_contact_at" type="date">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="followupValue">Expected value</label>
                <input class="form-control" id="followupValue" name="expected_value" type="number" min="0" step="0.01">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="followupPriority">Priority</label>
                <select class="form-select" id="followupPriority" name="priority">
                    <?php foreach (SPONSOR_FOLLOWUP_PRIORITIES as $key => $label): ?><option value="<?= h($key) ?>" <?= $key === 'normal' ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="followupLastContact">Last contact</label>
                <input class="form-control" id="followupLastContact" name="last_contact_at" type="date">
            </div>
            <div class="col-12">
                <label class="form-label" for="followupNotes">Notes</label>
                <textarea class="form-control" id="followupNotes" name="notes" rows="3"></textarea>
            </div>
            <div class="col-12">
                <button class="btn btn-brand" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add follow-up</button>
            </div>
        </form>
    </section>

    <section class="hub-section" aria-labelledby="sponsorFollowupOpenTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Open</div>
                <h2 id="sponsorFollowupOpenTitle">Open commercial follow-ups</h2>
            </div>
        </div>
        <?php if ($openFollowups === []): ?>
            <div class="hub-empty-state">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <h3>No open sponsor follow-ups</h3>
            </div>
        <?php else: ?>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead><tr><th>Sponsor</th><th>Follow-up</th><th>Owner</th><th>Next contact</th><th>Value</th><th>Priority</th><th>Stage</th><th class="text-end">Update</th></tr></thead>
                    <tbody>
                    <?php foreach ($openFollowups as $followup): ?>
                        <?php $isOverdue = sponsor_followup_is_overdue($followup); ?>
                        <tr>
                            <td>
                                <a href="sponsor.php?id=<?= (int) $followup['sponsor_id'] ?>" class="fw-semibold"><?= h((string) $followup['sponsor_name']) ?></a>
                                <?php if ((string) ($followup['contact_email'] ?? '') !== ''): ?><div class="venues-muted"><?= h((string) $followup['contact_email']) ?></div><?php endif; ?>
                            </td>
                            <td>
                                <strong><?= h((string) $followup['title']) ?></strong>
                                <?php if ((string) ($followup['notes'] ?? '') !== ''): ?><div class="venues-muted"><?= h((string) $followup['notes']) ?></div><?php endif; ?>
                            </td>
                            <td><?= $followup['owner'] ? h((string) $followup['owner']) : '<span class="venues-muted">Unassigned</span>' ?></td>
                            <td class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>"><?= $followup['next_contact_at'] ? h(date('d/m/Y', strtotime((string) $followup['next_contact_at']))) : '<span class="venues-muted">No date</span>' ?><?= $isOverdue ? ' overdue' : '' ?></td>
                            <td><?= gbp((float) $followup['expected_value']) ?></td>
                            <td><span class="badge text-bg-<?= h(sponsor_followup_priority_tone((string) $followup['priority'])) ?>"><?= h(SPONSOR_FOLLOWUP_PRIORITIES[$followup['priority']] ?? (string) $followup['priority']) ?></span></td>
                            <td><span class="badge text-bg-<?= h(sponsor_followup_stage_tone((string) $followup['stage'])) ?>"><?= h(SPONSOR_FOLLOWUP_STAGES[$followup['stage']] ?? (string) $followup['stage']) ?></span></td>
                            <td class="text-end">
                                <form method="post" class="d-inline-flex gap-2 justify-content-end">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="post_action" value="update_stage">
                                    <input type="hidden" name="id" value="<?= (int) $followup['id'] ?>">
                                    <select class="form-select form-select-sm" name="stage" aria-label="Stage for <?= h((string) $followup['title']) ?>">
                                        <?php foreach (SPONSOR_FOLLOWUP_STAGES as $key => $label): ?><option value="<?= h($key) ?>" <?= (string) $followup['stage'] === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
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

    <?php if ($closedFollowups !== []): ?>
        <section class="hub-section" aria-labelledby="sponsorFollowupClosedTitle">
            <div class="venues-directory__header">
                <div>
                    <div class="venues-directory__eyebrow">History</div>
                    <h2 id="sponsorFollowupClosedTitle">Closed follow-ups</h2>
                </div>
            </div>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead><tr><th>Sponsor</th><th>Follow-up</th><th>Stage</th><th>Value</th><th>Closed</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($closedFollowups, 0, 25) as $followup): ?>
                        <tr>
                            <td><?= h((string) $followup['sponsor_name']) ?></td>
                            <td><?= h((string) $followup['title']) ?></td>
                            <td><span class="badge text-bg-<?= h(sponsor_followup_stage_tone((string) $followup['stage'])) ?>"><?= h(SPONSOR_FOLLOWUP_STAGES[$followup['stage']] ?? (string) $followup['stage']) ?></span></td>
                            <td><?= gbp((float) $followup['expected_value']) ?></td>
                            <td><?= $followup['closed_at'] ? h(date('d/m/Y', strtotime((string) $followup['closed_at']))) : '<span class="venues-muted">-</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
