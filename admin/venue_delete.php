<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Fixture management',
    'title' => 'Delete Venue',
    'subtitle' => 'Review the venue record before permanently removing it.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/audit.php';

$id = (int)($_GET['id'] ?? 0);
$venue = $id > 0 ? getMatchVenueById($pdo, $id) : null;

if (!$venue) {
    echo '<div><div class="alert alert-danger">Venue not found.</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$fixtureCountStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM match_fixtures
    WHERE LOWER(TRIM(COALESCE(venue, ''))) = LOWER(TRIM(:name))
");
$fixtureCountStmt->execute([':name' => (string)$venue['name']]);
$fixtureCount = (int)$fixtureCountStmt->fetchColumn();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        try {
            deleteMatchVenue($pdo, $id);
            auditLog($pdo, 'venue_deleted', "Deleted venue '" . (string)$venue['name'] . "'");
            header('Location: venues.php?deleted=1');
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>

<div class="venue-delete-page">
    <div class="venue-delete-panel">
        <div class="venue-delete-panel__icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></div>
        <div class="venue-delete-panel__content">
            <div class="venue-editor-panel__eyebrow">Permanent action</div>
            <h2>Delete <?= h((string)$venue['name']) ?>?</h2>
            <p>This removes the venue directory record and cannot be undone.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>

            <div class="venue-delete-impact">
                <span><i class="fa-regular fa-calendar" aria-hidden="true"></i></span>
                <div>
                    <strong><?= number_format($fixtureCount) ?> fixture<?= $fixtureCount === 1 ? '' : 's' ?></strong>
                    <p>
                        <?= $fixtureCount > 0
                            ? 'Existing fixtures will keep their saved venue text.'
                            : 'No fixtures currently use this venue.' ?>
                    </p>
                </div>
            </div>

            <form method="post" class="venue-delete-actions">
                <?= csrf_field() ?>
                <a href="venue.php?id=<?= (int)$id ?>" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-danger">
                    <i class="fa-solid fa-trash" aria-hidden="true"></i>
                    Delete Venue
                </button>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
