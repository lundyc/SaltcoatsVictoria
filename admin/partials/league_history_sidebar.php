<?php
/** Seasons sidebar body. Expects $seasons, $savedBySeason, $pageSeasons, $sidebarSeasons, $page, $totalPages, $filter, $search, $seasonId, $currentSeasonId, $isImportView, $url, $esc. */
?>

<div class="lth-side__head">
  <h2>Seasons</h2>
  <a class="btn btn-brand btn-sm" href="<?= $esc($url(['view' => 'import', 'tab' => null])) ?>"><i class="fa-solid fa-file-import me-1" aria-hidden="true"></i>Import</a>
</div>
<form method="get" class="mb-2" id="lthSearchForm">
  <input type="hidden" name="season_id" value="<?= $seasonId ?>">
  <?php if ($filter === 'saved'): ?><input type="hidden" name="show" value="saved"><?php endif; ?>
  <input class="form-control form-control-sm" type="search" name="q" value="<?= $esc($search) ?>" placeholder="Search seasons, e.g. 1988" aria-label="Search seasons">
</form>
<div class="lth-filter" role="group" aria-label="Filter seasons" data-ajax-links>
  <a class="<?= $filter === 'all' ? 'is-active' : '' ?>" href="<?= $esc($url(['show' => null, 'page' => null])) ?>">All <?= count($seasons) ?></a>
  <a class="<?= $filter === 'saved' ? 'is-active' : '' ?>" href="<?= $esc($url(['show' => 'saved', 'page' => null])) ?>">Saved <?= count($savedBySeason) ?></a>
</div>

<?php if (!$pageSeasons): ?>
  <p class="text-muted small mb-0">No seasons match.</p>
<?php else: ?>
  <ul class="lth-list">
    <?php foreach ($pageSeasons as $s): ?>
      <?php
      $sid = (int) $s['id'];
      $isCurrent = $sid === $currentSeasonId;
      $row = $savedBySeason[$sid] ?? null;
      ?>
      <li>
        <a class="lth-item <?= !$isImportView && $sid === $seasonId ? 'is-active' : '' ?>" href="<?= $esc($url(['season_id' => $sid, 'view' => null, 'tab' => null])) ?>">
          <span>
            <span class="lth-item__name"><?= $esc($s['name']) ?></span>
            <span class="lth-item__sub"><?= $isCurrent ? 'Current season' : ($row ? (int) $row['rows'] . ' clubs' : 'No table saved') ?></span>
          </span>
          <span class="lth-dot <?= $isCurrent ? 'is-current' : ($row ? 'is-saved' : '') ?>" title="<?= $isCurrent ? 'Current' : ($row ? 'Saved' : 'Not saved') ?>"></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($totalPages > 1): ?>
  <nav class="lth-pager" aria-label="Season pages" data-total="<?= $totalPages ?>">
    <?php
    $btn = static function (string $icon, string $label, ?int $target, bool $active = false) use ($url, $esc): string {
        if ($target === null) {
            return '<span class="lth-pager__btn is-disabled" aria-hidden="true">' . $icon . '</span>';
        }
        return '<a class="lth-pager__btn' . ($active ? ' is-active' : '') . '" href="' . $esc($url(['page' => $target > 1 ? $target : null])) . '" data-page="' . $target . '" aria-label="' . $esc($label) . '"' . ($active ? ' aria-current="page"' : '') . '>' . $icon . '</a>';
    };
    echo $btn('&laquo;', 'First page', $page > 1 ? 1 : null);
    echo $btn('&lsaquo;', 'Newer seasons', $page > 1 ? $page - 1 : null);
    $from = max(1, min($page - 1, $totalPages - 2));
    $to = min($totalPages, $from + 2);
    for ($p = $from; $p <= $to; $p++) {
        echo $btn((string) $p, 'Page ' . $p, $p, $p === $page);
    }
    echo $btn('&rsaquo;', 'Older seasons', $page < $totalPages ? $page + 1 : null);
    echo $btn('&raquo;', 'Last page', $page < $totalPages ? $totalPages : null);
    ?>
  </nav>
  <div class="lth-pager__count"><?= count($sidebarSeasons) ?> seasons &middot; page <?= $page ?> of <?= $totalPages ?></div>
<?php endif; ?>
