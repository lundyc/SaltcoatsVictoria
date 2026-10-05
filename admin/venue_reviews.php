<?php
$pageHero = [
    'eyebrow' => 'Members',
    'title' => 'Venue Reviews',
    'subtitle' => 'What season ticket holders think of matchday facilities and the grounds we visit.',
    'actions' => [],
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/venue_reviews.php';
ensureVenueReviewsSchema($pdo);

$venueFilter = trim((string) ($_GET['venue'] ?? ''));
$summaries = getVenueReviewSummaries($pdo);
$reviews = getAllVenueReviews($pdo, $venueFilter);
?>

<div class="hub-section-commandbar">
  <div><h2>Venues</h2><p><?= count($summaries) ?> venue<?= count($summaries) === 1 ? '' : 's' ?> reviewed.</p></div>
</div>

<?php if (!$summaries): ?>
  <div class="card shadow-sm border-0"><div class="card-body text-center text-muted py-4">No venue reviews yet.</div></div>
<?php else: ?>

<div class="row g-3 mb-4">
  <?php foreach ($summaries as $summary): ?>
    <div class="col-sm-6 col-lg-4 col-xl-3">
      <a href="venue_reviews.php?venue=<?= urlencode((string) $summary['venue_name']) ?>" class="card shadow-sm border-0 text-decoration-none h-100 <?= $venueFilter === $summary['venue_name'] ? 'border border-2' : '' ?>" style="<?= $venueFilter === $summary['venue_name'] ? 'border-color:var(--brand-primary, #6a2036) !important;' : '' ?>">
        <div class="card-body">
          <div class="fw-semibold text-truncate" title="<?= h((string) $summary['venue_name']) ?>"><?= h((string) $summary['venue_name']) ?></div>
          <div class="h4 mb-0 mt-1"><?= str_repeat('★', (int) round($summary['average_rating'])) . str_repeat('☆', 5 - (int) round($summary['average_rating'])) ?></div>
          <div class="small text-muted"><?= $summary['average_rating'] ?> / 5 · <?= (int) $summary['review_count'] ?> review<?= (int) $summary['review_count'] === 1 ? '' : 's' ?></div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<div class="hub-section-commandbar">
  <div><h2><?= $venueFilter !== '' ? h($venueFilter) : 'All reviews' ?></h2><p><?= count($reviews) ?> review<?= count($reviews) === 1 ? '' : 's' ?><?= $venueFilter !== '' ? ' for this venue' : '' ?>.</p></div>
  <?php if ($venueFilter !== ''): ?><div class="hub-local-actions"><a class="btn btn-outline-secondary btn-sm" href="venue_reviews.php">Show all venues</a></div><?php endif; ?>
</div>

<div class="card hub-list-card hub-section hub-table-card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle hub-data-table hub-data-table--responsive">
        <thead><tr><th>Reviewer</th><th>Match</th><th>Venue</th><th>Rating</th><th>Comment</th><th>Submitted</th></tr></thead>
        <tbody>
          <?php foreach ($reviews as $review): ?>
            <tr>
              <td data-label="Reviewer"><?= h((string) $review['holder_name']) ?></td>
              <td data-label="Match"><?= (int) $review['is_home'] === 1 ? 'vs' : '@' ?> <?= h((string) $review['opponent']) ?><div class="small text-muted"><?= h(date('d/m/Y', strtotime((string) $review['match_date']))) ?></div></td>
              <td data-label="Venue"><?= h((string) $review['venue_name']) ?></td>
              <td data-label="Rating"><?= str_repeat('★', (int) $review['rating']) . str_repeat('☆', 5 - (int) $review['rating']) ?></td>
              <td data-label="Comment" style="max-width:320px;"><?= h((string) ($review['comment'] ?: '—')) ?></td>
              <td data-label="Submitted"><?= h(date('d/m/Y', strtotime((string) $review['created_at']))) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$reviews): ?><tr><td colspan="6" class="text-center text-muted py-4">No reviews found.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
