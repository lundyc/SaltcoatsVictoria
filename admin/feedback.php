<?php
$pageHero = [
    'eyebrow' => 'Members',
    'title' => 'Feedback',
    'subtitle' => 'What season ticket holders think of the new members area.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/feedback.php';
ensureFeedbackSchema($pdo);

$status = trim((string) ($_GET['status'] ?? ''));
$filters = $status !== '' ? ['status' => $status] : [];
$items = getFeedbackList($pdo, $filters);
$statusOptions = feedbackStatusOptions();
$average = feedbackAverageRating($pdo);
?>

<div class="hub-section-commandbar">
  <div><h2>All feedback</h2><p><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?><?= $average > 0 ? ' · average rating ' . $average . ' / 5' : '' ?></p></div>
</div>

<form method="get" class="mb-3">
    <select class="form-select form-select-sm" style="max-width:220px;" name="status" onchange="this.form.submit()">
        <option value="">Any status</option>
        <?php foreach ($statusOptions as $value => $label): ?>
            <option value="<?= h($value) ?>" <?= $status === $value ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<div class="card hub-list-card hub-section hub-table-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle hub-data-table hub-data-table--responsive">
        <thead><tr><th>Rating</th><th>From</th><th>Comment</th><th>Page</th><th>Status</th><th>Received</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td data-label="Rating"><?= str_repeat('★', (int) $item['rating']) . str_repeat('☆', 5 - (int) $item['rating']) ?></td>
              <td data-label="From"><?= h((string) $item['holder_name']) ?></td>
              <td data-label="Comment" style="max-width:320px;"><?= h((string) ($item['comment'] ?: '—')) ?><?php if ((int) $item['comment_count'] > 0): ?><div class="small text-muted"><?= (int) $item['comment_count'] ?> admin comment<?= (int) $item['comment_count'] === 1 ? '' : 's' ?></div><?php endif; ?></td>
              <td data-label="Page"><?= h((string) ($item['page'] ?: '—')) ?></td>
              <td data-label="Status"><span class="badge hub-status <?= $item['status'] === 'done' ? 'text-bg-success' : ($item['status'] === 'in_progress' ? 'text-bg-warning' : 'text-bg-secondary') ?>"><?= h($statusOptions[$item['status']] ?? (string) $item['status']) ?></span></td>
              <td data-label="Received"><?= h(date('d/m/Y', strtotime((string) $item['created_at']))) ?></td>
              <td data-label="Actions" class="text-end"><a class="btn btn-sm btn-outline-primary" href="feedback_item.php?id=<?= (int) $item['id'] ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$items): ?><tr><td colspan="7" class="text-center text-muted py-4">No feedback yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
