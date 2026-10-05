<?php
declare(strict_types=1);

$id = (int) ($_GET['id'] ?? 0);
$action = $id > 0 ? 'edit' : 'new';

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => $action === 'new' ? 'Log Discipline Incident' : 'Edit Discipline Incident',
    'subtitle' => $action === 'new'
        ? 'Record a booking or sending-off and track its verification with COMET/the SFA.'
        : 'Update the verification status and suspension details for this incident.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/discipline_register.php';
require_once __DIR__ . '/lib/audit.php';

$errors = [];

if ($action === 'edit') {
    $incident = discipline_get_incident($pdo, $id);
    if (!$incident) {
        echo '<div><div class="alert alert-danger">Discipline incident not found.</div></div>';
        require __DIR__ . '/footer.php';
        exit;
    }
} else {
    $incident = [
        'player_id' => (int) ($_GET['player_id'] ?? 0),
        'fixture_id' => (int) ($_GET['fixture_id'] ?? 0),
        'match_event_id' => (string) ($_GET['match_event_id'] ?? ''),
        'card_type' => (string) ($_GET['card_type'] ?? ''),
        'incident_date' => (string) ($_GET['incident_date'] ?? ''),
        'competition' => (string) ($_GET['competition'] ?? ''),
        'status' => 'unverified',
        'suspension_summary' => '',
        'suspension_clear_date' => '',
        'next_check_at' => '',
        'notes' => '',
        'evidence_reference' => '',
    ];
}

$formPlayerId = (int) ($incident['player_id'] ?? 0);
$formFixtureId = (int) ($incident['fixture_id'] ?? 0);
$formMatchEventId = (string) ($incident['match_event_id'] ?? '');
$formCardType = (string) ($incident['card_type'] ?? '');
$formIncidentDate = (string) ($incident['incident_date'] ?? '');
$formCompetition = (string) ($incident['competition'] ?? '');
$formStatus = (string) ($incident['status'] ?? 'unverified');
$formSuspensionSummary = (string) ($incident['suspension_summary'] ?? '');
$formSuspensionClearDate = (string) ($incident['suspension_clear_date'] ?? '');
$formNextCheckAt = (string) ($incident['next_check_at'] ?? '');
$formNotes = (string) ($incident['notes'] ?? '');
$formEvidenceReference = (string) ($incident['evidence_reference'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    $formPlayerId = (int) ($_POST['player_id'] ?? 0);
    $formFixtureId = (int) ($_POST['fixture_id'] ?? 0);
    $formMatchEventId = trim((string) ($_POST['match_event_id'] ?? ''));
    $formCardType = (string) ($_POST['card_type'] ?? '');
    $formIncidentDate = trim((string) ($_POST['incident_date'] ?? ''));
    $formCompetition = trim((string) ($_POST['competition'] ?? ''));
    $formStatus = (string) ($_POST['status'] ?? 'unverified');
    $formSuspensionSummary = trim((string) ($_POST['suspension_summary'] ?? ''));
    $formSuspensionClearDate = trim((string) ($_POST['suspension_clear_date'] ?? ''));
    $formNextCheckAt = trim((string) ($_POST['next_check_at'] ?? ''));
    $formNotes = trim((string) ($_POST['notes'] ?? ''));
    $formEvidenceReference = trim((string) ($_POST['evidence_reference'] ?? ''));

    if ($formPlayerId <= 0) {
        $errors[] = 'Select a player.';
    }
    if (!in_array($formCardType, ['yellow', 'red'], true)) {
        $errors[] = 'Select a card type.';
    }

    if (!$errors) {
        try {
            $userId = (int) ($_SESSION['hub_user_id'] ?? 0) ?: null;
            $incidentId = discipline_save_incident($pdo, $action === 'edit' ? $id : null, [
                'player_id' => $formPlayerId,
                'fixture_id' => $formFixtureId,
                'match_event_id' => $formMatchEventId,
                'card_type' => $formCardType,
                'incident_date' => $formIncidentDate,
                'competition' => $formCompetition,
                'status' => $formStatus,
                'suspension_summary' => $formSuspensionSummary,
                'suspension_clear_date' => $formSuspensionClearDate,
                'next_check_at' => $formNextCheckAt,
                'notes' => $formNotes,
                'evidence_reference' => $formEvidenceReference,
            ], $userId);
            $savedIncident = discipline_get_incident($pdo, $incidentId);
            $incidentPlayerName = $savedIncident['player_name'] ?? ('player #' . $formPlayerId);
            auditLog($pdo, $action === 'edit' ? 'discipline_incident_updated' : 'discipline_incident_created', ($action === 'edit' ? 'Updated' : 'Logged') . ' ' . $formCardType . ' card discipline incident for ' . $incidentPlayerName);
            header('Location: discipline_register.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$players = $pdo->query(
    "SELECT id, name, status FROM players ORDER BY FIELD(status,'current','trialist','loan','injured','left','retired'), name"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$fixtures = $pdo->query(
    "SELECT id, match_date, opponent, competition FROM match_fixtures ORDER BY match_date DESC LIMIT 150"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>

<div class="discipline-incident-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="discipline_register.php">Discipline register</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Log incident' : 'Edit incident' ?></span></nav>

    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">This incident could not be saved.</div>
                <ul class="mb-0 mt-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="post" action="discipline_incident.php<?= $action === 'edit' ? '?id=' . (int) $id : '' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="match_event_id" value="<?= h($formMatchEventId) ?>">

        <div class="hub-section">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="incidentPlayer" class="form-label">Player <span aria-hidden="true">*</span></label>
                    <select class="form-select" id="incidentPlayer" name="player_id" required>
                        <option value="">Select a player</option>
                        <?php foreach ($players as $player): ?>
                            <option value="<?= (int) $player['id'] ?>" <?= $formPlayerId === (int) $player['id'] ? 'selected' : '' ?>>
                                <?= h((string) $player['name']) ?><?= $player['status'] !== 'current' ? ' (' . h((string) $player['status']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="incidentCardType" class="form-label">Card type <span aria-hidden="true">*</span></label>
                    <select class="form-select" id="incidentCardType" name="card_type" required>
                        <option value="">Select card type</option>
                        <option value="yellow" <?= $formCardType === 'yellow' ? 'selected' : '' ?>>Yellow card</option>
                        <option value="red" <?= $formCardType === 'red' ? 'selected' : '' ?>>Red card</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="incidentFixture" class="form-label">Fixture</label>
                    <select class="form-select" id="incidentFixture" name="fixture_id">
                        <option value="0">Not tied to a specific fixture</option>
                        <?php foreach ($fixtures as $fixture): ?>
                            <option value="<?= (int) $fixture['id'] ?>" <?= $formFixtureId === (int) $fixture['id'] ? 'selected' : '' ?>>
                                <?= h(date('d/m/Y', strtotime((string) $fixture['match_date']))) ?> vs <?= h((string) $fixture['opponent']) ?><?= $fixture['competition'] ? ' (' . h((string) $fixture['competition']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="incidentDate" class="form-label">Incident date</label>
                    <input type="date" class="form-control" id="incidentDate" name="incident_date" value="<?= h($formIncidentDate) ?>">
                </div>

                <div class="col-md-6">
                    <label for="incidentCompetition" class="form-label">Competition</label>
                    <input type="text" class="form-control" id="incidentCompetition" name="competition" value="<?= h($formCompetition) ?>" placeholder="e.g. WoSFL Premier Division">
                </div>

                <div class="col-md-6">
                    <label for="incidentStatus" class="form-label">Status <span aria-hidden="true">*</span></label>
                    <select class="form-select" id="incidentStatus" name="status" required>
                        <option value="unverified" <?= $formStatus === 'unverified' ? 'selected' : '' ?>>Unverified — checking COMET/SFA</option>
                        <option value="suspension_confirmed" <?= $formStatus === 'suspension_confirmed' ? 'selected' : '' ?>>Suspension confirmed</option>
                        <option value="cleared" <?= $formStatus === 'cleared' ? 'selected' : '' ?>>Cleared — no suspension / suspension served</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label for="incidentSuspensionSummary" class="form-label">Suspension summary</label>
                    <input type="text" class="form-control" id="incidentSuspensionSummary" name="suspension_summary" value="<?= h($formSuspensionSummary) ?>" placeholder="e.g. 2-match ban, WoSFL League only — confirmed via COMET">
                </div>

                <div class="col-md-6">
                    <label for="incidentSuspensionClearDate" class="form-label">Eligible again from</label>
                    <input type="date" class="form-control" id="incidentSuspensionClearDate" name="suspension_clear_date" value="<?= h($formSuspensionClearDate) ?>">
                </div>

                <div class="col-md-6">
                    <label for="incidentNextCheckAt" class="form-label">Next check date</label>
                    <input type="date" class="form-control" id="incidentNextCheckAt" name="next_check_at" value="<?= h($formNextCheckAt) ?>">
                </div>

                <div class="col-md-6">
                    <label for="incidentEvidenceReference" class="form-label">Evidence / reference</label>
                    <input type="text" class="form-control" id="incidentEvidenceReference" name="evidence_reference" value="<?= h($formEvidenceReference) ?>" placeholder="COMET reference, email subject, file location">
                </div>

                <div class="col-12">
                    <label for="incidentNotes" class="form-label">Notes</label>
                    <textarea class="form-control" id="incidentNotes" name="notes" rows="4" placeholder="Anything else worth recording against this incident"><?= h($formNotes) ?></textarea>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <div>
                    <a href="discipline_register.php" class="btn btn-outline-secondary">Cancel</a>
                    <?php if ($action === 'edit'): ?>
                        <a href="discipline_incident_delete.php?id=<?= (int) $id ?>" class="btn btn-outline-danger">
                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                            Delete
                        </a>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-brand">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    <?= $action === 'new' ? 'Log Incident' : 'Save Changes' ?>
                </button>
            </div>
        </div>
    </form>
</div>

<?php require __DIR__ . '/footer.php'; ?>
