<?php
declare(strict_types=1);

$id = (int) ($_GET['id'] ?? 0);
$action = $id > 0 ? 'edit' : 'new';

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => $action === 'new' ? 'Log Registration Event' : 'Edit Registration Event',
    'subtitle' => 'Never assume submission equals acceptance — record the checked COMET status.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/player_registration.php';
require_once __DIR__ . '/lib/audit.php';

$errors = [];

if ($action === 'edit') {
    $event = registration_get($pdo, $id);
    if (!$event) {
        echo '<div><div class="alert alert-danger">Registration event not found.</div></div>';
        require __DIR__ . '/footer.php';
        exit;
    }
} else {
    $event = [
        'player_id' => (int) ($_GET['player_id'] ?? 0),
        'registration_type' => 'new',
        'comet_reference' => '',
        'submitted_at' => '',
        'comet_status' => 'not_started',
        'competition_eligibility_checked' => 0,
        'manager_confirmation' => 'not_sent',
        'manager_confirmation_sent_at' => '',
        'notes' => '',
        'evidence_reference' => '',
    ];
}

$formPlayerId = (int) ($event['player_id'] ?? 0);
$formRegistrationType = (string) ($event['registration_type'] ?? 'new');
$formCometReference = (string) ($event['comet_reference'] ?? '');
$formSubmittedAt = (string) ($event['submitted_at'] ?? '');
$formCometStatus = (string) ($event['comet_status'] ?? 'not_started');
$formEligibilityChecked = (int) ($event['competition_eligibility_checked'] ?? 0) === 1;
$formManagerConfirmation = (string) ($event['manager_confirmation'] ?? 'not_sent');
$formManagerConfirmationSentAt = (string) ($event['manager_confirmation_sent_at'] ?? '');
$formNotes = (string) ($event['notes'] ?? '');
$formEvidenceReference = (string) ($event['evidence_reference'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    $formPlayerId = (int) ($_POST['player_id'] ?? 0);
    $formRegistrationType = (string) ($_POST['registration_type'] ?? 'new');
    $formCometReference = trim((string) ($_POST['comet_reference'] ?? ''));
    $formSubmittedAt = trim((string) ($_POST['submitted_at'] ?? ''));
    $formCometStatus = (string) ($_POST['comet_status'] ?? 'not_started');
    $formEligibilityChecked = !empty($_POST['competition_eligibility_checked']);
    $formManagerConfirmation = (string) ($_POST['manager_confirmation'] ?? 'not_sent');
    $formManagerConfirmationSentAt = trim((string) ($_POST['manager_confirmation_sent_at'] ?? ''));
    $formNotes = trim((string) ($_POST['notes'] ?? ''));
    $formEvidenceReference = trim((string) ($_POST['evidence_reference'] ?? ''));

    if ($formPlayerId <= 0) {
        $errors[] = 'Select a player.';
    }

    if (!$errors) {
        try {
            $userId = (int) ($_SESSION['hub_user_id'] ?? 0) ?: null;
            $registrationId = registration_save($pdo, $action === 'edit' ? $id : null, [
                'player_id' => $formPlayerId,
                'registration_type' => $formRegistrationType,
                'comet_reference' => $formCometReference,
                'submitted_at' => $formSubmittedAt,
                'comet_status' => $formCometStatus,
                'competition_eligibility_checked' => $formEligibilityChecked ? 1 : 0,
                'manager_confirmation' => $formManagerConfirmation,
                'manager_confirmation_sent_at' => $formManagerConfirmationSentAt,
                'notes' => $formNotes,
                'evidence_reference' => $formEvidenceReference,
            ], $userId);
            if ($action === 'edit') {
                auditLog($pdo, 'player_registration_updated', "Updated registration event #{$registrationId} for player #{$formPlayerId}");
            } else {
                auditLog($pdo, 'player_registration_created', "Logged registration event #{$registrationId} for player #{$formPlayerId}");
            }
            header('Location: player_registrations.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$players = $pdo->query(
    "SELECT id, name, status FROM players ORDER BY FIELD(status,'current','trialist','loan','injured','left','retired'), name"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$history = $formPlayerId > 0 ? registration_list_for_player($pdo, $formPlayerId) : [];
?>

<div class="player-registration-edit-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="player_registrations.php">Player registrations</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Log event' : 'Edit event' ?></span></nav>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">This registration event could not be saved.</div>
                <ul class="mb-0 mt-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="player_registration_edit.php<?= $action === 'edit' ? '?id=' . (int) $id : '' ?>">
        <?= csrf_field() ?>

        <div class="hub-section">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="regPlayer" class="form-label">Player <span aria-hidden="true">*</span></label>
                    <select class="form-select" id="regPlayer" name="player_id" required>
                        <option value="">Select a player</option>
                        <?php foreach ($players as $player): ?>
                            <option value="<?= (int) $player['id'] ?>" <?= $formPlayerId === (int) $player['id'] ? 'selected' : '' ?>>
                                <?= h((string) $player['name']) ?><?= $player['status'] !== 'current' ? ' (' . h((string) $player['status']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="regType" class="form-label">Registration type <span aria-hidden="true">*</span></label>
                    <select class="form-select" id="regType" name="registration_type" required>
                        <option value="new" <?= $formRegistrationType === 'new' ? 'selected' : '' ?>>New registration</option>
                        <option value="transfer" <?= $formRegistrationType === 'transfer' ? 'selected' : '' ?>>Transfer</option>
                        <option value="re_registration" <?= $formRegistrationType === 're_registration' ? 'selected' : '' ?>>Re-registration</option>
                        <option value="loan" <?= $formRegistrationType === 'loan' ? 'selected' : '' ?>>Loan</option>
                        <option value="international" <?= $formRegistrationType === 'international' ? 'selected' : '' ?>>International clearance</option>
                        <option value="termination" <?= $formRegistrationType === 'termination' ? 'selected' : '' ?>>Termination</option>
                        <option value="other" <?= $formRegistrationType === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="regCometReference" class="form-label">COMET reference</label>
                    <input type="text" class="form-control" id="regCometReference" name="comet_reference" value="<?= h($formCometReference) ?>" placeholder="COMET ID / reference">
                </div>

                <div class="col-md-6">
                    <label for="regSubmittedAt" class="form-label">Submitted date/time</label>
                    <input type="datetime-local" class="form-control" id="regSubmittedAt" name="submitted_at" value="<?= h($formSubmittedAt !== '' ? date('Y-m-d\TH:i', strtotime($formSubmittedAt)) : '') ?>">
                </div>

                <div class="col-md-6">
                    <label for="regCometStatus" class="form-label">Current COMET status <span aria-hidden="true">*</span></label>
                    <select class="form-select" id="regCometStatus" name="comet_status" required>
                        <option value="not_started" <?= $formCometStatus === 'not_started' ? 'selected' : '' ?>>Not started</option>
                        <option value="entered" <?= $formCometStatus === 'entered' ? 'selected' : '' ?>>Entered — not yet confirmed</option>
                        <option value="confirmed" <?= $formCometStatus === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                        <option value="rejected" <?= $formCometStatus === 'rejected' ? 'selected' : '' ?>>Rejected / incomplete</option>
                        <option value="terminated" <?= $formCometStatus === 'terminated' ? 'selected' : '' ?>>Terminated</option>
                    </select>
                    <div class="form-text">"Entered" is not the same as confirmed/accepted — check status again before telling management a player is available.</div>
                </div>

                <div class="col-md-6">
                    <label for="regManagerConfirmation" class="form-label">Confirmation to give the manager <span aria-hidden="true">*</span></label>
                    <select class="form-select" id="regManagerConfirmation" name="manager_confirmation" required>
                        <option value="not_sent" <?= $formManagerConfirmation === 'not_sent' ? 'selected' : '' ?>>Not sent yet</option>
                        <option value="confirmed_eligible" <?= $formManagerConfirmation === 'confirmed_eligible' ? 'selected' : '' ?>>CONFIRMED ELIGIBLE</option>
                        <option value="not_yet_confirmed" <?= $formManagerConfirmation === 'not_yet_confirmed' ? 'selected' : '' ?>>NOT YET CONFIRMED</option>
                        <option value="requires_further_check" <?= $formManagerConfirmation === 'requires_further_check' ? 'selected' : '' ?>>REQUIRES FURTHER CHECK</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="regManagerConfirmationSentAt" class="form-label">Confirmation sent at</label>
                    <input type="datetime-local" class="form-control" id="regManagerConfirmationSentAt" name="manager_confirmation_sent_at" value="<?= h($formManagerConfirmationSentAt !== '' ? date('Y-m-d\TH:i', strtotime($formManagerConfirmationSentAt)) : '') ?>">
                </div>

                <div class="col-md-6">
                    <label for="regEvidenceReference" class="form-label">Evidence / notes location</label>
                    <input type="text" class="form-control" id="regEvidenceReference" name="evidence_reference" value="<?= h($formEvidenceReference) ?>" placeholder="Email subject, file location, screenshot reference">
                </div>

                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="regEligibilityChecked" name="competition_eligibility_checked" value="1" <?= $formEligibilityChecked ? 'checked' : '' ?>>
                        <label class="form-check-label" for="regEligibilityChecked">Competition-specific eligibility/registration deadline checked</label>
                    </div>
                </div>

                <div class="col-12">
                    <label for="regNotes" class="form-label">Notes</label>
                    <textarea class="form-control" id="regNotes" name="notes" rows="4" placeholder="Anything else worth recording against this registration event"><?= h($formNotes) ?></textarea>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <a href="player_registrations.php" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-brand">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    <?= $action === 'new' ? 'Log Event' : 'Save Changes' ?>
                </button>
            </div>
        </div>
    </form>

    <?php if ($history !== []): ?>
        <section class="hub-section" aria-labelledby="regHistoryTitle">
            <div class="venues-directory__header">
                <div>
                    <div class="venues-directory__eyebrow">History</div>
                    <h2 id="regHistoryTitle">Previous events for this player</h2>
                </div>
            </div>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>COMET status</th>
                            <th>Logged</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $historyRow): ?>
                            <tr>
                                <td><?= h((string) $historyRow['registration_type']) ?></td>
                                <td><?= h((string) $historyRow['comet_status']) ?></td>
                                <td><?= h(date('d/m/Y', strtotime((string) $historyRow['created_at']))) ?></td>
                                <td class="text-end">
                                    <a href="player_registration_edit.php?id=<?= (int) $historyRow['id'] ?>" class="btn btn-sm btn-outline-primary">
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
