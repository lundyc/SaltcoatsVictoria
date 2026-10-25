<?php
$pageHero = [
          'eyebrow' => 'Season settings',
          'title' => 'Seasons',
          'subtitle' => 'Manage season dates, status, and sponsorship pricing.',
          'actions' => [],
];
require_once __DIR__ . '/header.php';

require_once __DIR__ . '/lib/season.php';
$allSeasonRows = array_reverse(getSeasons($pdo)); // newest first
$activeRows = array_values(array_filter($allSeasonRows, static fn (array $r): bool => (int) ($r['is_archived'] ?? 0) !== 1));
$archivedRows = array_values(array_filter($allSeasonRows, static fn (array $r): bool => (int) ($r['is_archived'] ?? 0) === 1));
$awaiting = getSeasonsAwaitingArchive($pdo);

$archiveForm = static function (int $id, string $do, string $label, string $class, string $confirm = ''): void {
    ?>
    <form method="post" action="season_archive_commit.php" class="d-inline"<?= $confirm !== '' ? ' onsubmit="return confirm(' . h(json_encode($confirm)) . ');"' : '' ?>>
        <?= csrf_field() ?>
        <input type="hidden" name="season_id" value="<?= $id ?>">
        <input type="hidden" name="do" value="<?= h($do) ?>">
        <input type="hidden" name="return" value="seasons.php">
        <button type="submit" class="<?= h($class) ?>"><?= h($label) ?></button>
    </form>
    <?php
};
$fmtDate = static fn (?string $d): string => $d ? date('d/m/Y', strtotime($d)) : '-';
?>

<?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">Season deleted successfully.</div><?php endif; ?>
<?php if (isset($_GET['archived'])): ?><div class="alert alert-success">Season committed to the archive. It is now locked and kept as historical data.</div><?php endif; ?>
<?php if (isset($_GET['restored'])): ?><div class="alert alert-success">Season restored from the archive.</div><?php endif; ?>
<?php if (isset($_GET['kept'])): ?><div class="alert alert-info">Season kept active. You can archive it later from this page.</div><?php endif; ?>
<?php if (($_GET['archive_error'] ?? '') === 'current'): ?><div class="alert alert-warning">The current season cannot be archived. Make another season current first.</div><?php endif; ?>

<?php foreach ($awaiting as $aw): ?>
    <div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 justify-content-between" role="alert">
        <div><strong><?= h($aw['name']) ?></strong> has finished (<?= h($fmtDate($aw['end_date'])) ?>). Commit it to the archive? It will be locked and kept as historical data.</div>
        <div class="d-flex gap-2">
            <?php $archiveForm((int) $aw['id'], 'archive', 'Archive season', 'btn btn-sm btn-brand', 'Archive ' . $aw['name'] . '? It will be locked and moved out of the everyday season lists. Nothing is deleted.'); ?>
            <?php $archiveForm((int) $aw['id'], 'dismiss', 'Not yet', 'btn btn-sm btn-outline-secondary'); ?>
        </div>
    </div>
<?php endforeach; ?>

<div class="hub-section-commandbar">
          <div><h2>Club seasons</h2><p>Active seasons, newest first. Finished seasons are committed to the archive and kept as history.</p></div>
          <div class="hub-local-actions"><a href="season.php?action=new" class="btn btn-brand"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add season</a></div>
</div>

<div class="card hub-list-card hub-table-card">
          <div class="card-body p-0">
                    <div class="table-responsive">
                              <table class="table align-middle hub-data-table hub-data-table--responsive">
                                        <caption class="visually-hidden">Active club seasons, dates, status, and actions</caption>
                                        <thead>
                                                  <tr>
                                                            <th>Season</th>
                                                            <th>Dates</th>
                                                            <th>Status</th>
                                                            <th>Actions</th>
                                                  </tr>
                                        </thead>
                                        <tbody>
                                                  <?php foreach ($activeRows as $row): ?>
                                                            <tr>
                                                                      <td data-label="Season">
                                                                                <strong><?= h($row['name']) ?></strong>
                                                                                <?php if ((int)$row['is_current'] === 1): ?>
                                                                                          <span class="badge bg-primary ms-2">Current</span>
                                                                                <?php endif; ?>
                                                                      </td>
                                                                      <td data-label="Dates">
                                                                                <span><?= h($fmtDate($row['start_date'])) ?> <span class="text-muted">to</span> <?= h($fmtDate($row['end_date'])) ?></span>
                                                                      </td>
                                                                      <td data-label="Status">
                                                                                <?php if ((int)$row['is_locked'] === 1): ?>
                                                                                          <span class="badge bg-danger">Locked</span>
                                                                                <?php else: ?>
                                                                                          <span class="badge bg-success">Open</span>
                                                                                <?php endif; ?>
                                                                      </td>
                                                                      <td data-label="Actions" class="text-nowrap">
                                                                                <div class="hub-row-actions hub-actions"><a href="season.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                                                                <?php if ((int)$row['is_current'] !== 1): ?>
                                                                                          <?php $archiveForm((int) $row['id'], 'archive', 'Archive', 'btn btn-sm btn-outline-secondary', 'Archive ' . $row['name'] . '? It will be locked and moved out of the everyday season lists. Nothing is deleted.'); ?>
                                                                                <?php endif; ?>
                                                                                <a href="season_delete.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-danger" aria-label="Review deletion of <?= h((string)$row['name']) ?>">Delete</a></div>
                                                                      </td>
                                                            </tr>
                                                  <?php endforeach; ?>
                                                  <?php if (!$activeRows): ?>
                                                            <tr>
                                                                      <td colspan="4" class="hub-record-empty hub-empty-state">No active seasons. Add a season to organise fixtures and sponsorships.</td>
                                                            </tr>
                                                  <?php endif; ?>
                                        </tbody>
                              </table>
                    </div>
          </div>
</div>

<?php if ($archivedRows): ?>
<details class="card hub-list-card mt-3 seasons-archive">
          <summary class="card-body d-flex align-items-center justify-content-between" style="cursor:pointer">
                    <span><strong>Archived seasons</strong> <span class="badge bg-secondary ms-1"><?= count($archivedRows) ?></span><span class="text-muted small ms-2">Historical data. Locked and read-only.</span></span>
                    <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
          </summary>
          <div class="table-responsive border-top">
                    <table class="table table-sm align-middle mb-0 hub-data-table hub-data-table--responsive">
                              <caption class="visually-hidden">Archived seasons</caption>
                              <thead><tr><th>Season</th><th>Dates</th><th>Archived</th><th>Actions</th></tr></thead>
                              <tbody>
                                        <?php foreach ($archivedRows as $row): ?>
                                                  <tr>
                                                            <td data-label="Season"><strong><?= h($row['name']) ?></strong></td>
                                                            <td data-label="Dates"><?= h($fmtDate($row['start_date'])) ?> <span class="text-muted">to</span> <?= h($fmtDate($row['end_date'])) ?></td>
                                                            <td data-label="Archived" class="text-muted small"><?= h($fmtDate($row['archived_at'] ? substr((string) $row['archived_at'], 0, 10) : null)) ?></td>
                                                            <td data-label="Actions" class="text-nowrap">
                                                                      <div class="hub-row-actions hub-actions">
                                                                                <a href="matches.php?season_id=<?= (int) $row['id'] ?>" class="btn btn-sm btn-outline-primary">View matches</a>
                                                                                <?php $archiveForm((int) $row['id'], 'restore', 'Restore', 'btn btn-sm btn-outline-secondary', 'Restore ' . $row['name'] . ' to the everyday season lists?'); ?>
                                                                      </div>
                                                            </td>
                                                  </tr>
                                        <?php endforeach; ?>
                              </tbody>
                    </table>
          </div>
</details>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
