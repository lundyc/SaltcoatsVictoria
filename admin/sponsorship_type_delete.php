<?php
$id = (int)($_GET['id'] ?? 0);
$pageHero = [
    'eyebrow' => 'Sponsorship management',
    'title' => 'Delete Sponsorship Type',
    'subtitle' => 'Remove a type from future fixture sponsorship assignments.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$type = $id > 0 ? getMatchSponsorshipTypeById($pdo, $id) : null;
if (!$type) {
    echo '<div class="alert alert-danger">Sponsorship type not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$usageStmt = $pdo->prepare("SELECT COUNT(*) FROM match_sponsorships WHERE sponsorship_role = :code");
$usageStmt->execute([':code' => (string)$type['code']]);
$usageCount = (int)$usageStmt->fetchColumn();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            deleteMatchSponsorshipType($pdo, $id);
            auditLog($pdo, 'sponsorship_type_deleted', "Deleted sponsorship type '{$type['name']}'");
            header('Location: sponsorship_types.php?deleted=1');
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>

<div class="card shadow-sm">
    <div class="card-body">
        <?php if ($error !== ''): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
        <p>Delete <strong><?= h((string)$type['name']) ?></strong>?</p>
        <p class="text-muted">It will no longer appear in the Add Sponsor modal. <?= $usageCount ?> existing assignment<?= $usageCount === 1 ? '' : 's' ?> will retain the saved type code for reporting and history.</p>
        <form method="post" class="d-flex gap-2">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-danger">Delete Type</button>
            <a href="sponsorship_types.php" class="btn btn-outline-secondary">Cancel</a>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
