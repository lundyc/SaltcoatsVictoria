<?php
$pageHero = [
    'eyebrow' => 'Season tickets',
    'title' => 'Free Signup Codes',
    'subtitle' => 'Create one-time links that let testers sign up without Stripe payment.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/audit.php';
ensureSeasonTicketSchema($pdo);

$currentSeason = getCurrentSeason($pdo);
$errors = [];
$createdCode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Invalid security token. Please try again.';
    }

    $seasonId = (int) ($_POST['season_id'] ?? ($currentSeason['id'] ?? 0));
    $code = seasonTicketNormalizeFreeSignupCode((string) ($_POST['code'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    if ($seasonId <= 0) {
        $errors[] = 'Choose a season.';
    }

    if (!$errors) {
        try {
            $createdCode = createSeasonTicketFreeSignupCode($pdo, $seasonId, $code !== '' ? $code : null, $note !== '' ? $note : null);
            auditLog($pdo, 'season_ticket_free_code_created', "Created free signup code '{$createdCode}' for season #{$seasonId}");
        } catch (Throwable $e) {
            $errors[] = str_contains(strtolower($e->getMessage()), 'duplicate')
                ? 'That code already exists. Enter another code or leave it blank to generate one.'
                : $e->getMessage();
        }
    }
}

$seasons = $pdo->query('SELECT id, name FROM seasons ORDER BY start_date DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
$codes = $pdo->query('
    SELECT c.*, s.name AS season_name, COALESCE(p.display_name, h.name) AS used_by_name
    FROM season_ticket_free_signup_codes c
    JOIN seasons s ON s.id = c.season_id
    LEFT JOIN people p ON p.id = c.used_by_person_id
    LEFT JOIN season_ticket_holders h ON h.id = c.used_by_holder_id
    ORDER BY c.created_at DESC, c.id DESC
')->fetchAll(PDO::FETCH_ASSOC);

$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk');
?>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($createdCode !== ''): ?>
    <div class="alert alert-success">
        Free signup link created:
        <a href="<?= h($scheme . '://' . $host . '/season-tickets?code=' . rawurlencode($createdCode)) ?>" target="_blank" rel="noopener"><?= h($scheme . '://' . $host . '/season-tickets?code=' . rawurlencode($createdCode)) ?></a>
    </div>
<?php endif; ?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="season_ticket_orders.php">Season tickets</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page">Free signup codes</span></nav>

<section class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Create a one-time link</h2>
        <form method="post" class="row g-3">
            <?= csrf_field() ?>
            <div class="col-md-4">
                <label class="form-label" for="freeCodeSeason">Season</label>
                <select class="form-select" id="freeCodeSeason" name="season_id" required>
                    <?php foreach ($seasons as $season): ?>
                        <option value="<?= (int) $season['id'] ?>" <?= (int) ($currentSeason['id'] ?? 0) === (int) $season['id'] ? 'selected' : '' ?>><?= h((string) $season['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="freeCodeCode">Code</label>
                <input class="form-control" id="freeCodeCode" name="code" maxlength="64" placeholder="Leave blank to generate">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="freeCodeNote">Note</label>
                <input class="form-control" id="freeCodeNote" name="note" maxlength="255" placeholder="e.g. John test signup">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-brand">Create free signup link</button>
            </div>
        </form>
    </div>
</section>

<section class="card hub-list-card hub-section hub-table-card">
    <div class="card-header bg-transparent"><h2 class="h5 mb-0">Existing codes</h2></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle hub-data-table hub-data-table--responsive">
                <thead><tr><th>Code</th><th>Season</th><th>Status</th><th>Used by</th><th>Link</th><th>Note</th></tr></thead>
                <tbody>
                    <?php foreach ($codes as $code): ?>
                        <?php
                        $used = (int) $code['used_count'] >= (int) $code['max_uses'];
                        $link = $scheme . '://' . $host . '/season-tickets?code=' . rawurlencode((string) $code['code']);
                        ?>
                        <tr>
                            <td data-label="Code"><code><?= h((string) $code['code']) ?></code></td>
                            <td data-label="Season"><?= h((string) $code['season_name']) ?></td>
                            <td data-label="Status"><span class="badge hub-status <?= $used ? 'text-bg-secondary' : 'text-bg-success' ?>"><?= $used ? 'Used' : 'Available' ?></span></td>
                            <td data-label="Used by"><?= h((string) ($code['used_by_name'] ?? '')) ?></td>
                            <td data-label="Link"><a href="<?= h($link) ?>" target="_blank" rel="noopener">Open</a></td>
                            <td data-label="Note"><?= h((string) ($code['note'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$codes): ?><tr><td colspan="6" class="text-center text-muted py-4">No free signup codes created yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
