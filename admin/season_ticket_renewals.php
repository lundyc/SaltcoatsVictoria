<?php
$pageHero = [
    'eyebrow' => 'Season tickets',
    'title' => 'Renewal reminders',
    'subtitle' => 'Holders who paid last season but haven\'t renewed yet.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/audit.php';
ensureSeasonTicketSchema($pdo);

$currentSeason = getCurrentSeason($pdo);
$previousSeasonId = $currentSeason ? getPreviousSeasonId($pdo, (int) $currentSeason['id']) : 0;
$lapsed = ($currentSeason && $previousSeasonId) ? getLapsedSeasonTicketHolders($pdo, $previousSeasonId, (int) $currentSeason['id']) : [];

$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$signupUrl = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'lundy.me.uk') . '/season-tickets';

$sentCount = 0;
$failedNames = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $holderIds = array_map('intval', (array) ($_POST['holder_ids'] ?? []));
    foreach ($lapsed as $holder) {
        if (!in_array((int) $holder['id'], $holderIds, true)) {
            continue;
        }
        if (seasonTicketSendRenewalReminder($holder, $signupUrl)) {
            markSeasonTicketRenewalReminderSent($pdo, (int) $holder['id']);
            $sentCount++;
        } else {
            $failedNames[] = (string) $holder['name'];
        }
    }
    if ($sentCount > 0) {
        auditLog($pdo, 'season_ticket_renewal_reminders_sent', "Sent renewal reminder to {$sentCount} lapsed season ticket holder(s)");
    }
    // Refresh so the "last reminded" column reflects what just happened.
    $lapsed = getLapsedSeasonTicketHolders($pdo, $previousSeasonId, (int) $currentSeason['id']);
}
?>

<?php if ($sentCount > 0): ?><div class="alert alert-success">Sent <?= $sentCount ?> renewal reminder<?= $sentCount === 1 ? '' : 's' ?>.</div><?php endif; ?>
<?php if ($failedNames): ?><div class="alert alert-warning">Could not email: <?= h(implode(', ', $failedNames)) ?> (no valid email on file).</div><?php endif; ?>

<?php if (!$currentSeason || !$previousSeasonId): ?>
    <div class="card shadow-sm border-0"><div class="card-body text-center text-muted py-4">Need a current season and a previous season to compare against.</div></div>
<?php elseif (!$lapsed): ?>
    <div class="card shadow-sm border-0"><div class="card-body text-center text-muted py-4">Everyone who paid last season has already renewed (or there's nothing to compare yet).</div></div>
<?php else: ?>
<form method="post">
    <?= csrf_field() ?>
    <div class="hub-section-commandbar">
        <div><h2>Not yet renewed</h2><p><?= count($lapsed) ?> holder<?= count($lapsed) === 1 ? '' : 's' ?> paid last season with nothing on file for this one.</p></div>
        <div class="hub-local-actions"><button type="submit" class="btn btn-brand btn-sm">Send reminder to selected</button></div>
    </div>
    <div class="card hub-list-card hub-section hub-table-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle hub-data-table hub-data-table--responsive">
                    <thead><tr><th><input type="checkbox" id="selectAllLapsed"></th><th>Name</th><th>Email</th><th>Last season's ticket</th><th>Last reminded</th></tr></thead>
                    <tbody>
                        <?php foreach ($lapsed as $holder): ?>
                            <tr>
                                <td data-label=""><input type="checkbox" name="holder_ids[]" value="<?= (int) $holder['id'] ?>" class="lapsed-checkbox" checked></td>
                                <td data-label="Name" class="fw-semibold"><?= h((string) $holder['name']) ?></td>
                                <td data-label="Email"><?= h((string) $holder['email']) ?></td>
                                <td data-label="Last season's ticket"><?= h((string) $holder['previous_type_name']) ?> (<?= gbp((float) $holder['previous_price']) ?>)</td>
                                <td data-label="Last reminded"><?= $holder['renewal_reminder_sent_at'] ? h(date('d/m/Y', strtotime((string) $holder['renewal_reminder_sent_at']))) : '<span class="text-muted">Never</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</form>
<script>document.getElementById('selectAllLapsed')?.addEventListener('change', (e) => { document.querySelectorAll('.lapsed-checkbox').forEach((c) => { c.checked = e.target.checked; }); });</script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
