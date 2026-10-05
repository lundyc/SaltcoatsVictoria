<?php
declare(strict_types=1);

$id = (int) ($_GET['id'] ?? 0);
$action = $id > 0 ? 'edit' : 'new';

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => $action === 'new' ? 'Add Committee Meeting' : 'Edit Committee Meeting',
    'subtitle' => 'Record decisions and actions, not a transcript of every argument.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/committee_meetings.php';
require_once __DIR__ . '/lib/secretary_tasks.php';
require_once __DIR__ . '/lib/committee_actions.php';
require_once __DIR__ . '/lib/audit.php';

$errors = [];

if ($action === 'edit') {
    $meeting = committee_meeting_get($pdo, $id);
    if (!$meeting) {
        echo '<div><div class="alert alert-danger">Meeting not found.</div></div>';
        require __DIR__ . '/footer.php';
        exit;
    }
} else {
    $meeting = [
        'meeting_type' => 'committee',
        'meeting_date' => date('Y-m-d'),
        'location' => '',
        'attendees' => '',
        'apologies' => '',
        'minutes' => '',
        'next_meeting_date' => '',
        'status' => 'draft',
        'agm_constitution_version' => '',
        'agm_notice_issued' => 0,
        'agm_reports_prepared' => 0,
        'agm_nominations_handled' => 0,
        'agm_quorum_recorded' => 0,
    ];
}

$formMeetingType = (string) ($meeting['meeting_type'] ?? 'committee');
$formMeetingDate = (string) ($meeting['meeting_date'] ?? '');
$formLocation = (string) ($meeting['location'] ?? '');
$formAttendees = (string) ($meeting['attendees'] ?? '');
$formApologies = (string) ($meeting['apologies'] ?? '');
$formMinutes = (string) ($meeting['minutes'] ?? '');
$formNextMeetingDate = (string) ($meeting['next_meeting_date'] ?? '');
$formStatus = (string) ($meeting['status'] ?? 'draft');
$formAgmConstitutionVersion = (string) ($meeting['agm_constitution_version'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['post_action'] ?? 'save_meeting') === 'create_actions') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    if ($action !== 'edit') {
        $errors[] = 'Save the meeting before creating actions.';
    }

    if (!$errors) {
        try {
            $userId = (int) ($_SESSION['hub_user_id'] ?? 0) ?: null;
            $created = committee_meeting_create_actions_from_minutes($pdo, $id, $userId);
            auditLog($pdo, 'committee_meeting_actions_created', 'Created ' . $created . ' action(s) from committee meeting #' . $id);
            header('Location: committee_meeting_edit.php?id=' . (int) $id . '&actions_created=' . $created);
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    $formMeetingType = (string) ($_POST['meeting_type'] ?? 'committee');
    $formMeetingDate = trim((string) ($_POST['meeting_date'] ?? ''));
    $formLocation = trim((string) ($_POST['location'] ?? ''));
    $formAttendees = trim((string) ($_POST['attendees'] ?? ''));
    $formApologies = trim((string) ($_POST['apologies'] ?? ''));
    $formMinutes = trim((string) ($_POST['minutes'] ?? ''));
    $formNextMeetingDate = trim((string) ($_POST['next_meeting_date'] ?? ''));
    $formStatus = (string) ($_POST['status'] ?? 'draft');
    $formAgmConstitutionVersion = trim((string) ($_POST['agm_constitution_version'] ?? ''));

    if ($formMeetingDate === '') {
        $errors[] = 'A meeting date is required.';
    }

    if (!$errors) {
        try {
            $userId = (int) ($_SESSION['hub_user_id'] ?? 0) ?: null;
            $savedId = committee_meeting_save($pdo, $action === 'edit' ? $id : null, [
                'meeting_type' => $formMeetingType,
                'meeting_date' => $formMeetingDate,
                'location' => $formLocation,
                'attendees' => $formAttendees,
                'apologies' => $formApologies,
                'minutes' => $formMinutes,
                'next_meeting_date' => $formNextMeetingDate,
                'status' => $formStatus,
                'agm_constitution_version' => $formAgmConstitutionVersion,
                'agm_notice_issued' => !empty($_POST['agm_notice_issued']) ? 1 : 0,
                'agm_reports_prepared' => !empty($_POST['agm_reports_prepared']) ? 1 : 0,
                'agm_nominations_handled' => !empty($_POST['agm_nominations_handled']) ? 1 : 0,
                'agm_quorum_recorded' => !empty($_POST['agm_quorum_recorded']) ? 1 : 0,
            ], $userId);
            auditLog($pdo, $action === 'edit' ? 'committee_meeting_updated' : 'committee_meeting_created', ($action === 'edit' ? 'Updated' : 'Created') . ' ' . $formMeetingType . ' meeting for ' . $formMeetingDate);
            header('Location: committee_meeting_edit.php?id=' . $savedId . '&saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$meetingActions = $action === 'edit' ? secretary_tasks_for_meeting($pdo, $id) : [];
$agendaTemplate = [
    'Apologies', 'Approval of previous minutes', 'Matters arising', "Chairman's update",
    "Secretary's report", "Treasurer's report", 'Football/manager update', 'Facilities/ground',
    'Commercial/fundraising', 'Media/community', 'Safeguarding/compliance', 'Any other competent business',
    'Date of next meeting',
];
?>

<div class="committee-meeting-edit-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="committee_meetings.php">Committee meetings</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Add meeting' : 'Edit meeting' ?></span></nav>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">This meeting could not be saved.</div>
                <ul class="mb-0 mt-1"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul>
            </div>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>Meeting saved.</span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if (isset($_GET['actions_created'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span><?= (int) $_GET['actions_created'] ?> action<?= (int) $_GET['actions_created'] === 1 ? '' : 's' ?> created from the minutes.</span>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form method="post" action="committee_meeting_edit.php<?= $action === 'edit' ? '?id=' . (int) $id : '' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="post_action" value="save_meeting">

        <div class="hub-section">
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="cmType" class="form-label">Meeting type</label>
                    <select class="form-select" id="cmType" name="meeting_type">
                        <option value="committee" <?= $formMeetingType === 'committee' ? 'selected' : '' ?>>Committee meeting</option>
                        <option value="agm" <?= $formMeetingType === 'agm' ? 'selected' : '' ?>>AGM</option>
                        <option value="egm" <?= $formMeetingType === 'egm' ? 'selected' : '' ?>>EGM</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="cmDate" class="form-label">Meeting date <span aria-hidden="true">*</span></label>
                    <input type="date" class="form-control" id="cmDate" name="meeting_date" value="<?= h($formMeetingDate) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="cmStatus" class="form-label">Status</label>
                    <select class="form-select" id="cmStatus" name="status">
                        <option value="draft" <?= $formStatus === 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="final" <?= $formStatus === 'final' ? 'selected' : '' ?>>Final</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="cmLocation" class="form-label">Location</label>
                    <input type="text" class="form-control" id="cmLocation" name="location" value="<?= h($formLocation) ?>">
                </div>
                <div class="col-md-6">
                    <label for="cmNextMeetingDate" class="form-label">Date of next meeting</label>
                    <input type="date" class="form-control" id="cmNextMeetingDate" name="next_meeting_date" value="<?= h($formNextMeetingDate) ?>">
                </div>

                <div class="col-md-6">
                    <label for="cmAttendees" class="form-label">Attended</label>
                    <textarea class="form-control" id="cmAttendees" name="attendees" rows="3" placeholder="Names, one per line"><?= h($formAttendees) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label for="cmApologies" class="form-label">Apologies</label>
                    <textarea class="form-control" id="cmApologies" name="apologies" rows="3" placeholder="Names, one per line"><?= h($formApologies) ?></textarea>
                </div>

                <div class="col-12">
                    <label for="cmMinutes" class="form-label">Minutes</label>
                    <?php if ($action === 'new'): ?>
                        <div class="form-text mb-2">Suggested agenda: <?= h(implode(' · ', $agendaTemplate)) ?></div>
                    <?php endif; ?>
                    <textarea class="form-control" id="cmMinutes" name="minutes" rows="10" placeholder="Decisions made, not a transcript of every argument"><?= h($formMinutes) ?></textarea>
                </div>

                <div class="col-12" id="cmAgmFields" style="<?= $formMeetingType === 'agm' ? '' : 'display:none;' ?>">
                    <fieldset class="hub-section">
                        <legend class="h6">AGM checklist</legend>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="cmAgmConstitutionVersion" class="form-label">Constitution version identified</label>
                                <input type="text" class="form-control" id="cmAgmConstitutionVersion" name="agm_constitution_version" value="<?= h($formAgmConstitutionVersion) ?>" placeholder="e.g. Adopted 2023, last amended 2025">
                            </div>
                            <div class="col-md-6 d-flex flex-column justify-content-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="cmAgmNoticeIssued" name="agm_notice_issued" value="1" <?= !empty($meeting['agm_notice_issued']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cmAgmNoticeIssued">Required notice issued correctly</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="cmAgmReportsPrepared" name="agm_reports_prepared" value="1" <?= !empty($meeting['agm_reports_prepared']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cmAgmReportsPrepared">Reports/accounts prepared by responsible officers</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="cmAgmNominationsHandled" name="agm_nominations_handled" value="1" <?= !empty($meeting['agm_nominations_handled']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cmAgmNominationsHandled">Nominations/elections handled under constitution</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="cmAgmQuorumRecorded" name="agm_quorum_recorded" value="1" <?= !empty($meeting['agm_quorum_recorded']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="cmAgmQuorumRecorded">Attendance/quorum recorded</label>
                                </div>
                            </div>
                        </div>
                    </fieldset>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <div>
                    <a href="committee_meetings.php" class="btn btn-outline-secondary">Cancel</a>
                    <?php if ($action === 'edit'): ?>
                        <a href="committee_meeting_delete.php?id=<?= (int) $id ?>" class="btn btn-outline-danger">
                            <i class="fa-solid fa-trash" aria-hidden="true"></i> Delete
                        </a>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-brand">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    <?= $action === 'new' ? 'Save Meeting' : 'Save Changes' ?>
                </button>
            </div>
        </div>
    </form>

    <?php if ($action === 'edit'): ?>
        <section class="hub-section" aria-labelledby="cmActionsTitle">
            <div class="venues-directory__header">
                <div>
                    <div class="venues-directory__eyebrow">Actions</div>
                    <h2 id="cmActionsTitle">Actions from this meeting</h2>
                    <p>Every committee action needs an owner and a due date — "committee to look at this" is not an action.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <form method="post" action="committee_meeting_edit.php?id=<?= (int) $id ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="post_action" value="create_actions">
                        <button type="submit" class="btn btn-outline-primary venues-directory__add">
                            <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i>
                            <span>Create from minutes</span>
                        </button>
                    </form>
                    <a href="secretary_task_edit.php?<?= http_build_query(['category' => 'committee', 'meeting_id' => $id]) ?>" class="btn btn-brand venues-directory__add">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                        <span>Add action</span>
                    </a>
                </div>
            </div>

            <?php if ($meetingActions === []): ?>
                <div class="hub-empty-state">
                    <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                    <h3>No actions logged for this meeting</h3>
                </div>
            <?php else: ?>
                <div class="table-responsive hub-table-card">
                    <table class="table hub-data-table align-middle mb-0">
                        <thead>
                            <tr><th>Action</th><th>Owner</th><th>Due</th><th>Status</th><th class="text-end">Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($meetingActions as $taskRow): ?>
                                <tr>
                                    <td><?= h((string) $taskRow['title']) ?></td>
                                    <td><?= $taskRow['owner'] ? h((string) $taskRow['owner']) : '<span class="venues-muted">Unassigned</span>' ?></td>
                                    <td><?= $taskRow['due_at'] ? h(date('d/m/Y', strtotime((string) $taskRow['due_at']))) : '<span class="venues-muted">—</span>' ?></td>
                                    <td><span class="badge text-bg-<?= $taskRow['status'] === 'done' ? 'success' : ($taskRow['status'] === 'waiting' ? 'info' : 'warning') ?>"><?= ucfirst((string) $taskRow['status']) ?></span></td>
                                    <td class="text-end">
                                        <a href="secretary_task_edit.php?id=<?= (int) $taskRow['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<script>
    (function () {
        var typeSelect = document.getElementById('cmType');
        var agmFields = document.getElementById('cmAgmFields');
        if (!typeSelect || !agmFields) return;
        typeSelect.addEventListener('change', function () {
            agmFields.style.display = typeSelect.value === 'agm' ? '' : 'none';
        });
    }());
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
