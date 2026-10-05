<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Secretary',
    'title' => 'Delete Discipline Incident',
    'subtitle' => 'Review the incident before permanently removing it from the register.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/discipline_register.php';
require_once __DIR__ . '/lib/audit.php';

$id = (int) ($_GET['id'] ?? 0);
$incident = $id > 0 ? discipline_get_incident($pdo, $id) : null;

if (!$incident) {
    echo '<div><div class="alert alert-danger">Discipline incident not found.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        try {
            discipline_delete_incident($pdo, $id);
            auditLog($pdo, 'discipline_incident_deleted', 'Deleted ' . (string) $incident['card_type'] . ' card discipline incident for ' . (string) $incident['player_name']);
            header('Location: discipline_register.php?deleted=1');
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>

<div class="discipline-incident-delete-page">
    <div class="venue-delete-panel">
        <div class="venue-delete-panel__icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></div>
        <div class="venue-delete-panel__content">
            <div class="venue-editor-panel__eyebrow">Permanent action</div>
            <h2>Delete this <?= h((string) $incident['card_type']) ?> card record for <?= h((string) $incident['player_name']) ?>?</h2>
            <p>This removes the discipline register entry and cannot be undone. If the underlying card event is still logged on the fixture, it will reappear in the "needs logging" queue.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" class="venue-delete-actions">
                <?= csrf_field() ?>
                <a href="discipline_incident.php?id=<?= (int) $id ?>" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-danger">
                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                    Delete Incident
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
