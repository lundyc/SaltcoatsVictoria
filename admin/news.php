<?php

declare(strict_types=1);

/**
 * News — list view for the public-website news system. Separate from
 * announcements.php (members-only notifications). Editor lives in news_edit.php.
 */

$pageHero = [
    'eyebrow' => 'Public website',
    'title' => 'News',
    'subtitle' => 'Articles published to the club website. Drafts and future-dated posts stay hidden from the public.',
    'actions' => [
        ['label' => 'Write article', 'href' => 'news_edit.php', 'class' => 'btn btn-brand btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/news.php';

news_ensure_schema($pdo);

$statusFilter = (string) ($_GET['status'] ?? 'all');
$categoryFilter = (string) ($_GET['category'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));

$filters = ['status' => $statusFilter, 'category' => $categoryFilter, 'q' => $search, 'limit' => 100];
$articles = news_all($pdo, $filters);
$total = news_count($pdo, $filters);
?>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Article saved.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">Article deleted.</div><?php endif; ?>

<form class="card hub-section mb-3" method="get">
  <div class="card-body d-flex flex-wrap gap-2 align-items-end">
    <div>
      <label class="form-label small mb-1" for="f-status">Status</label>
      <select class="form-select form-select-sm" id="f-status" name="status">
        <?php foreach (['all' => 'All', 'draft' => 'Draft', 'scheduled' => 'Scheduled', 'published' => 'Published', 'archived' => 'Archived'] as $v => $l): ?>
          <option value="<?= h($v) ?>" <?= $statusFilter === $v ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label small mb-1" for="f-cat">Category</label>
      <select class="form-select form-select-sm" id="f-cat" name="category">
        <option value="">Any</option>
        <?php foreach (news_categories() as $cat): ?>
          <option value="<?= h($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="flex-grow-1" style="min-width:180px">
      <label class="form-label small mb-1" for="f-q">Search</label>
      <input class="form-control form-control-sm" id="f-q" type="search" name="q" value="<?= h($search) ?>" placeholder="Title or summary">
    </div>
    <div class="d-flex gap-2">
      <button class="btn btn-sm btn-outline-primary" type="submit">Filter</button>
      <a class="btn btn-sm btn-outline-secondary" href="news.php">Reset</a>
    </div>
  </div>
</form>

<div class="card hub-list-card hub-section hub-table-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle hub-data-table hub-data-table--responsive">
        <thead><tr><th>Title</th><th>Category</th><th>Status</th><th>Publish date</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($articles as $a): ?>
          <?php
          $badge = match ($a['status']) {
              'published' => 'text-bg-success',
              'scheduled' => 'text-bg-info',
              'archived' => 'text-bg-dark',
              default => 'text-bg-secondary',
          };
          $isFuture = $a['status'] === 'published' && $a['published_at'] && strtotime((string) $a['published_at']) > time();
          ?>
          <tr>
            <td data-label="Title" class="fw-semibold">
              <?= h((string) $a['title']) ?>
              <?php if ((int) $a['is_featured'] === 1): ?><span class="badge text-bg-warning ms-1">Featured</span><?php endif; ?>
              
              <div class="text-muted small">/news/<?= h((string) $a['slug']) ?></div>
            </td>
            <td data-label="Category"><?= h((string) $a['category']) ?></td>
            <td data-label="Status"><span class="badge hub-status <?= $badge ?>"><?= h(ucfirst((string) $a['status'])) ?></span></td>
            <td data-label="Publish date"><?= $a['published_at'] ? h(date('d/m/Y H:i', strtotime((string) $a['published_at']))) : '—' ?></td>
            <td data-label="Actions" class="text-end">
              <?php if ($a['status'] === 'published' && !$isFuture): ?>
                <a class="btn btn-sm btn-outline-secondary" href="/news/<?= h((string) $a['slug']) ?>" target="_blank" rel="noopener">View</a>
              <?php endif; ?>
              <a class="btn btn-sm btn-outline-primary" href="news_edit.php?id=<?= (int) $a['id'] ?>">Edit</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$articles): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No articles<?= $search !== '' || $categoryFilter !== '' || $statusFilter !== 'all' ? ' match those filters' : ' yet' ?>.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<p class="text-muted small mt-2"><?= (int) $total ?> article<?= $total === 1 ? '' : 's' ?>.</p>

<?php require_once __DIR__ . '/footer.php'; ?>
