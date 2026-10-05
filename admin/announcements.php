<?php
$pageHero = [
    'eyebrow' => 'Members',
    'title' => 'Announcements',
    'subtitle' => 'Exclusive news, deals and offers shown to logged-in season ticket holders.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/announcements.php';
ensureAnnouncementsSchema($pdo);

$announcements = getAnnouncements($pdo);
?>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Announcement saved.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">Announcement deleted.</div><?php endif; ?>

<div class="hub-section-commandbar">
  <div><h2>All announcements</h2><p>Only published ones are visible to members.</p></div>
  <div class="hub-local-actions">
    <a class="btn btn-brand btn-sm" href="announcement.php?action=new"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add announcement</a>
  </div>
</div>

<div class="card hub-list-card hub-section hub-table-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle hub-data-table hub-data-table--responsive">
        <thead><tr><th>Title</th><th>Status</th><th>Published</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($announcements as $announcement): ?>
            <tr>
              <td data-label="Title" class="fw-semibold"><?= h((string) $announcement['title']) ?></td>
              <td data-label="Status"><span class="badge hub-status <?= (int) $announcement['is_published'] === 1 ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= (int) $announcement['is_published'] === 1 ? 'Published' : 'Draft' ?></span></td>
              <td data-label="Published"><?= $announcement['published_at'] ? h(date('d/m/Y H:i', strtotime((string) $announcement['published_at']))) : '—' ?></td>
              <td data-label="Actions" class="text-end"><a class="btn btn-sm btn-outline-primary" href="announcement.php?id=<?= (int) $announcement['id'] ?>">Edit</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$announcements): ?><tr><td colspan="4" class="text-center text-muted py-4">No announcements yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
