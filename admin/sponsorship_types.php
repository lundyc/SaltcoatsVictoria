<?php
header('Location: sponsorship_packages.php', true, 302);
exit;
$pageHero = [
    'eyebrow' => 'Sponsorship management',
    'title' => 'Sponsorship Types',
    'subtitle' => 'Manage the sponsorship options available when assigning a sponsor to a fixture.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$types = getMatchSponsorshipTypes($pdo);
?>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert alert-success">Sponsorship type saved.</div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">Sponsorship type deleted.</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0 hub-data-table">
                <thead>
                    <tr>
                        <th>Sort</th>
                        <th>Name</th>
                        <th>Code</th>
                        <th>Default Amount</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($types as $type): ?>
                        <tr>
                            <td><?= h((string)($type['sort_order'] ?? '')) ?></td>
                            <td class="fw-semibold"><?= h((string)$type['name']) ?></td>
                            <td><code><?= h((string)$type['code']) ?></code></td>
                            <td><?= gbp((float)$type['default_amount']) ?></td>
                            <td class="text-nowrap">
                                <a href="sponsorship_type.php?id=<?= (int)$type['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                <a href="sponsorship_type_delete.php?id=<?= (int)$type['id'] ?>" class="btn btn-sm btn-outline-danger">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$types): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No sponsorship types created yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
