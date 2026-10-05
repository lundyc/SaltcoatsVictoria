<?php
declare(strict_types=1);

$id = (int) ($_GET['id'] ?? 0);
$action = $id > 0 ? 'edit' : 'new';

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => $action === 'new' ? 'Log Fixture Change Request' : 'Edit Fixture Change Request',
    'subtitle' => 'Follow the competition\'s approval process — this is the audit trail, not the fixture edit itself.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/fixture_change_requests.php';
require_once __DIR__ . '/lib/audit.php';

$errors = [];

if ($action === 'edit') {
    $request = fixture_change_request_get($pdo, $id);
    if (!$request) {
        echo '<div><div class="alert alert-danger">Fixture change request not found.</div></div>';
        require __DIR__ . '/footer.php';
        exit;
    }
} else {
    $request = [
        'fixture_id' => (int) ($_GET['fixture_id'] ?? 0),
        'requested_by' => '',
        'reason' => '',
        'requested_date' => '',
        'requested_kickoff_time' => '',
        'requested_venue' => '',
        'club_availability_checked' => 0,
        'opposition_agreement_recorded' => 0,
        'competition_approval_requested' => 0,
        'competition_approval_received' => 0,
        'officials_informed' => 0,
        'public_updated' => 0,
        'status' => 'requested',
        'notes' => '',
    ];
}

$formFixtureId = (int) ($request['fixture_id'] ?? 0);
$formRequestedBy = (string) ($request['requested_by'] ?? '');
$formReason = (string) ($request['reason'] ?? '');
$formRequestedDate = (string) ($request['requested_date'] ?? '');
$formRequestedKickoffTime = (string) ($request['requested_kickoff_time'] ?? '');
$formRequestedVenue = (string) ($request['requested_venue'] ?? '');
$formStatus = (string) ($request['status'] ?? 'requested');
$formNotes = (string) ($request['notes'] ?? '');
$checklistFields = [
    'club_availability_checked' => 'Change requested in writing / club availability checked',
    'opposition_agreement_recorded' => 'Opposition agreement recorded (if required)',
    'competition_approval_requested' => 'League/competition approval requested',
    'competition_approval_received' => 'Official approval received',
    'officials_informed' => 'Referee/officials informed',
    'public_updated' => 'Internal calendar/website/social updated',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    $formFixtureId = (int) ($_POST['fixture_id'] ?? 0);
    $formRequestedBy = trim((string) ($_POST['requested_by'] ?? ''));
    $formReason = trim((string) ($_POST['reason'] ?? ''));
    $formRequestedDate = trim((string) ($_POST['requested_date'] ?? ''));
    $formRequestedKickoffTime = trim((string) ($_POST['requested_kickoff_time'] ?? ''));
    $formRequestedVenue = trim((string) ($_POST['requested_venue'] ?? ''));
    $formStatus = (string) ($_POST['status'] ?? 'requested');
    $formNotes = trim((string) ($_POST['notes'] ?? ''));

    if ($formFixtureId <= 0) {
        $errors[] = 'Select a fixture.';
    }

    if (!$errors) {
        try {
            $userId = (int) ($_SESSION['hub_user_id'] ?? 0) ?: null;
            $data = [
                'fixture_id' => $formFixtureId,
                'requested_by' => $formRequestedBy,
                'reason' => $formReason,
                'requested_date' => $formRequestedDate,
                'requested_kickoff_time' => $formRequestedKickoffTime,
                'requested_venue' => $formRequestedVenue,
                'status' => $formStatus,
                'notes' => $formNotes,
            ];
            foreach (array_keys($checklistFields) as $field) {
                $data[$field] = !empty($_POST[$field]) ? 1 : 0;
            }
            $requestId = fixture_change_request_save($pdo, $action === 'edit' ? $id : null, $data, $userId);
            $savedRequest = fixture_change_request_get($pdo, $requestId);
            $requestFixtureLabel = $savedRequest ? ('vs ' . (string) $savedRequest['opponent'] . ' (' . date('d/m/Y', strtotime((string) $savedRequest['match_date'])) . ')') : ('fixture #' . $formFixtureId);
            auditLog($pdo, $action === 'edit' ? 'fixture_change_request_updated' : 'fixture_change_request_created', ($action === 'edit' ? 'Updated' : 'Logged') . ' fixture change request for ' . $requestFixtureLabel);
            header('Location: fixture_change_requests.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$fixtures = $pdo->query(
    "SELECT id, match_date, opponent, competition FROM match_fixtures WHERE match_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) ORDER BY match_date ASC LIMIT 150"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>

<div class="fixture-change-request-edit-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="fixture_change_requests.php">Fixture change requests</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Log request' : 'Edit request' ?></span></nav>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">This request could not be saved.</div>
                <ul class="mb-0 mt-1">
                    <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="fixture_change_request_edit.php<?= $action === 'edit' ? '?id=' . (int) $id : '' ?>">
        <?= csrf_field() ?>

        <div class="hub-section">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="fcrFixture" class="form-label">Fixture <span aria-hidden="true">*</span></label>
                    <select class="form-select" id="fcrFixture" name="fixture_id" required>
                        <option value="">Select a fixture</option>
                        <?php foreach ($fixtures as $fixture): ?>
                            <option value="<?= (int) $fixture['id'] ?>" <?= $formFixtureId === (int) $fixture['id'] ? 'selected' : '' ?>>
                                <?= h(date('d/m/Y', strtotime((string) $fixture['match_date']))) ?> vs <?= h((string) $fixture['opponent']) ?><?= $fixture['competition'] ? ' (' . h((string) $fixture['competition']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="fcrRequestedBy" class="form-label">Requested by</label>
                    <input type="text" class="form-control" id="fcrRequestedBy" name="requested_by" value="<?= h($formRequestedBy) ?>" placeholder="e.g. Opposition Secretary, our manager">
                </div>

                <div class="col-12">
                    <label for="fcrReason" class="form-label">Reason</label>
                    <textarea class="form-control" id="fcrReason" name="reason" rows="2"><?= h($formReason) ?></textarea>
                </div>

                <div class="col-md-4">
                    <label for="fcrRequestedDate" class="form-label">Requested new date</label>
                    <input type="date" class="form-control" id="fcrRequestedDate" name="requested_date" value="<?= h($formRequestedDate) ?>">
                </div>
                <div class="col-md-4">
                    <label for="fcrRequestedKickoffTime" class="form-label">Requested kick-off</label>
                    <input type="time" class="form-control" id="fcrRequestedKickoffTime" name="requested_kickoff_time" value="<?= h($formRequestedKickoffTime) ?>">
                </div>
                <div class="col-md-4">
                    <label for="fcrRequestedVenue" class="form-label">Requested venue</label>
                    <input type="text" class="form-control" id="fcrRequestedVenue" name="requested_venue" value="<?= h($formRequestedVenue) ?>">
                </div>

                <div class="col-md-6">
                    <label for="fcrStatus" class="form-label">Status</label>
                    <select class="form-select" id="fcrStatus" name="status">
                        <option value="requested" <?= $formStatus === 'requested' ? 'selected' : '' ?>>Requested</option>
                        <option value="pending_approval" <?= $formStatus === 'pending_approval' ? 'selected' : '' ?>>Pending competition approval</option>
                        <option value="approved" <?= $formStatus === 'approved' ? 'selected' : '' ?>>Approved — not yet confirmed</option>
                        <option value="confirmed" <?= $formStatus === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                        <option value="rejected" <?= $formStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label d-block">Approval checklist</label>
                    <?php foreach ($checklistFields as $field => $label): ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="fcr_<?= h($field) ?>" name="<?= h($field) ?>" value="1" <?= !empty($request[$field]) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="fcr_<?= h($field) ?>"><?= h($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="col-12">
                    <label for="fcrNotes" class="form-label">Notes</label>
                    <textarea class="form-control" id="fcrNotes" name="notes" rows="3"><?= h($formNotes) ?></textarea>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <a href="fixture_change_requests.php" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-brand">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    <?= $action === 'new' ? 'Log Request' : 'Save Changes' ?>
                </button>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
