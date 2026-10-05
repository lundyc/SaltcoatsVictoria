<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Overview',
    'title' => 'Club Reminders',
    'subtitle' => 'One list of operational reminders across football, finance, secretary, facilities and matchday work.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/club_reminders.php';

function club_reminders_format_date(?string $value, string $fallback = 'No date'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }
    $timestamp = strtotime($value);
    return $timestamp !== false ? date('d/m/Y', $timestamp) : $value;
}

$withinDays = max(1, min(30, (int) ($_GET['days'] ?? 14)));
$reminders = [];
$errors = [];
try {
    $reminders = club_reminders_collect($pdo, $withinDays);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
$summary = club_reminder_summary($reminders);
?>

<div class="club-reminders-page">
    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">Reminders could not be loaded.</div>
                <ul class="mb-0 mt-1"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul>
            </div>
        </div>
    <?php endif; ?>

    <?php hub_render_metric_grid([
        ['label' => 'Total reminders', 'value' => number_format($summary['total']), 'meta' => 'Due within ' . $withinDays . ' days', 'icon' => 'fa-bell', 'tone' => 'primary'],
        ['label' => 'Urgent', 'value' => number_format($summary['urgent']), 'meta' => 'Overdue or high priority', 'icon' => 'fa-triangle-exclamation', 'tone' => $summary['urgent'] > 0 ? 'danger' : 'success'],
        ['label' => 'Warnings', 'value' => number_format($summary['warning']), 'meta' => 'Needs attention soon', 'icon' => 'fa-clock', 'tone' => $summary['warning'] > 0 ? 'warning' : 'success'],
        ['label' => 'Info', 'value' => number_format($summary['info']), 'meta' => 'Lower-risk follow-up', 'icon' => 'fa-circle-info', 'tone' => 'info'],
    ], 'Reminder summary'); ?>

    <section class="hub-section" aria-labelledby="clubRemindersTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Reminder inbox</div>
                <h2 id="clubRemindersTitle">Due and overdue operational work</h2>
                <p>These reminders are generated from live club records; update the source record to clear the reminder.</p>
            </div>
            <form method="get" class="d-flex align-items-end gap-2">
                <div>
                    <label class="form-label small fw-semibold" for="reminderDays">Window</label>
                    <select class="form-select form-select-sm" id="reminderDays" name="days">
                        <?php foreach ([7, 14, 30] as $days): ?><option value="<?= $days ?>" <?= $withinDays === $days ? 'selected' : '' ?>><?= $days ?> days</option><?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-sm btn-outline-primary" type="submit">Refresh</button>
            </form>
        </div>

        <?php if ($reminders === []): ?>
            <div class="hub-empty-state">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <h3>No reminders in this window</h3>
                <p>There are no due or overdue operational reminders for the selected period.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive hub-table-card">
                <table class="table hub-data-table align-middle mb-0">
                    <thead><tr><th>Area</th><th>Reminder</th><th>Due</th><th>Severity</th><th class="text-end">Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($reminders as $reminder): ?>
                        <tr>
                            <td><span class="badge text-bg-light"><i class="fa-solid <?= h((string) ($reminder['icon'] ?? 'fa-bell')) ?>" aria-hidden="true"></i> <?= h((string) $reminder['area']) ?></span></td>
                            <td><strong><?= h((string) $reminder['title']) ?></strong><div class="venues-muted"><?= h((string) $reminder['detail']) ?></div></td>
                            <td><?= h(club_reminders_format_date((string) ($reminder['due_at'] ?? ''), 'No date')) ?></td>
                            <td><span class="badge text-bg-<?= h(club_reminder_tone((string) ($reminder['severity'] ?? 'info'))) ?>"><?= h(ucfirst((string) ($reminder['severity'] ?? 'info'))) ?></span></td>
                            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h((string) $reminder['href']) ?>">Open</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
