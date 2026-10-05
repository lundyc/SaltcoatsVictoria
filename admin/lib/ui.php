<?php
declare(strict_types=1);

/**
 * Shared UI-rendering helpers for the admin panel — the design-system
 * component layer described in admin/DESIGN_SYSTEM.md Phase 3.
 *
 * Modelled on hub_render_page_hero() / hub_render_metric_grid() in
 * header.php: a plain function that echoes markup, nothing more. Existing
 * pages are not required to adopt these — they exist so new/rewritten pages
 * don't reimplement patterns (sortable tables, breadcrumbs, empty states,
 * badges) that already vary across the panel.
 */

/**
 * Render a breadcrumb trail matching the `.hub-breadcrumb` markup already
 * used across the panel (e.g. shop_orders.php).
 *
 * @param list<array{label:string,href?:string}> $trail Last entry is treated
 *   as the current page (no link) regardless of whether it has an href.
 */
function hub_render_breadcrumbs(array $trail): void
{
    if ($trail === []) {
        return;
    }
    $lastIndex = count($trail) - 1;
    ?>
    <nav class="hub-breadcrumb" aria-label="Breadcrumb">
        <?php foreach ($trail as $index => $crumb): ?>
            <?php
            $label = (string) ($crumb['label'] ?? '');
            $href = (string) ($crumb['href'] ?? '');
            ?>
            <?php if ($index === $lastIndex || $href === ''): ?>
                <span aria-current="page"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
            <?php else: ?>
                <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a>
                <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <?php
}

/**
 * Render the shared "nothing here" pattern, standardised on the
 * alert-wrapped `.hub-empty-state` shape (admin/DESIGN_SYSTEM.md Phase 2).
 */
function hub_render_empty_state(string $message, string $icon = 'fa-circle-info'): void
{
    $icon = trim($icon);
    $iconClass = str_starts_with($icon, 'fa-') && !str_contains($icon, ' ') ? 'fa-solid ' . $icon : $icon;
    ?>
    <div class="alert alert-info mb-0 hub-empty-state">
        <?php if ($iconClass !== ''): ?><i class="<?= htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') ?> me-2" aria-hidden="true"></i><?php endif; ?>
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php
}

/**
 * Render a Bootstrap `badge text-bg-*` status pill — the already-consistent
 * pattern used across the panel (e.g. discipline_register.php).
 */
function hub_render_badge(string $label, string $tone = 'secondary'): void
{
    $allowedTones = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'];
    $tone = in_array($tone, $allowedTones, true) ? $tone : 'secondary';
    ?>
    <span class="badge text-bg-<?= $tone ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
    <?php
}

/**
 * Render a sortable `<thead>` row for a `.hub-data-table`. Pairs with
 * window.initHubSortableTable() in assets/js/app.js, which any table can
 * opt into either by calling it directly or by adding
 * `data-hub-sortable="<a storage key, or empty string for no persistence>"`
 * to the <table> element (auto-wired on DOMContentLoaded).
 *
 * Generalises the hand-rolled sort logic sponsors.php built for its own
 * ledger table (admin/DESIGN_SYSTEM.md Phase 3) so new sortable tables don't
 * repeat it.
 *
 * @param list<array{label:string,key?:string,type?:'text'|'number',align?:'start'|'center'|'end',class?:string}> $columns
 *   Omit `key` on a column that shouldn't be sortable (e.g. an actions column).
 */
function hub_render_table_head(array $columns): void
{
    ?>
    <thead>
        <tr>
            <?php foreach ($columns as $column): ?>
                <?php
                $label = (string) ($column['label'] ?? '');
                $key = (string) ($column['key'] ?? '');
                $type = ($column['type'] ?? 'text') === 'number' ? 'number' : 'text';
                $align = (string) ($column['align'] ?? '');
                $alignClass = match ($align) {
                    'center' => ' text-center',
                    'end' => ' text-end',
                    default => '',
                };
                $extraClass = trim((string) ($column['class'] ?? ''));
                $thClass = trim($alignClass . ' ' . $extraClass);
                ?>
                <?php if ($key !== ''): ?>
                    <th<?= $thClass !== '' ? ' class="' . htmlspecialchars($thClass, ENT_QUOTES, 'UTF-8') . '"' : '' ?> data-sort-key="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" data-sort-type="<?= $type ?>">
                        <button type="button" class="hub-sort-button<?= $align === 'center' ? ' hub-sort-button--center' : ($align === 'end' ? ' hub-sort-button--end' : '') ?>">
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> <span class="hub-sort-icon" aria-hidden="true"></span>
                        </button>
                    </th>
                <?php else: ?>
                    <th<?= $thClass !== '' ? ' class="' . htmlspecialchars($thClass, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></th>
                <?php endif; ?>
            <?php endforeach; ?>
        </tr>
    </thead>
    <?php
}

/**
 * Render a windowed pagination nav: first, last, current +/- 2 neighbours,
 * with "…" gaps and prev/next arrows. A plain 1..N page list becomes
 * unusable once there are more than a handful of pages — this is what
 * developer_analytics.php's analytics_render_pagination() and
 * season_ticket_orders.php's sto_page_window() each built independently to
 * solve that (admin/DESIGN_SYSTEM.md Phase 2). Generalises both into one
 * shared version for new paginated pages; the two existing ones are left as
 * they are since they already work correctly.
 *
 * @param callable(int): string $urlFor Given a page number, returns the URL
 *   for that page (build it from whatever query params the page needs).
 */
function hub_render_pagination(int $currentPage, int $totalPages, callable $urlFor, string $ariaLabel = 'Pages'): void
{
    if ($totalPages <= 1) {
        return;
    }

    $currentPage = max(1, min($currentPage, $totalPages));
    $window = 2;
    $pages = array_unique(array_merge(
        [1, $totalPages],
        range(max(1, $currentPage - $window), min($totalPages, $currentPage + $window))
    ));
    sort($pages);
    ?>
    <nav aria-label="<?= htmlspecialchars($ariaLabel, ENT_QUOTES, 'UTF-8') ?>">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item<?= $currentPage <= 1 ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= htmlspecialchars($urlFor(max(1, $currentPage - 1)), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous"<?= $currentPage <= 1 ? ' tabindex="-1" aria-disabled="true"' : '' ?>>&laquo;</a>
            </li>
            <?php $previous = 0; ?>
            <?php foreach ($pages as $page): ?>
                <?php if ($previous !== 0 && $page - $previous > 1): ?>
                    <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                <?php endif; ?>
                <li class="page-item<?= $page === $currentPage ? ' active' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars($urlFor($page), ENT_QUOTES, 'UTF-8') ?>"><?= $page ?></a>
                </li>
                <?php $previous = $page; ?>
            <?php endforeach; ?>
            <li class="page-item<?= $currentPage >= $totalPages ? ' disabled' : '' ?>">
                <a class="page-link" href="<?= htmlspecialchars($urlFor(min($totalPages, $currentPage + 1)), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next"<?= $currentPage >= $totalPages ? ' tabindex="-1" aria-disabled="true"' : '' ?>>&raquo;</a>
            </li>
        </ul>
    </nav>
    <?php
}

/**
 * Render the "quick add a single-field record" modal shape — a small form
 * with one text field, some hidden context fields, and a Cancel/Save
 * footer. Extracted from match.php's near-identical Add Competition / Add
 * Venue modals (admin/DESIGN_SYSTEM.md Phase 2); only worth reaching for
 * where this exact shape repeats — a modal with more than one visible field
 * should stay hand-written rather than being bent to fit this.
 *
 * @param array<string,scalar> $hidden Hidden input name => value pairs
 *   carried through to the POST target (e.g. return_to_fixture_id).
 */
function hub_render_quick_add_modal(
    string $id,
    string $title,
    string $formAction,
    string $fieldId,
    string $fieldName,
    string $fieldLabel,
    array $hidden = [],
    string $submitLabel = 'Save'
): void {
    $labelId = $id . 'Label';
    ?>
    <div class="modal fade" id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" tabindex="-1" aria-labelledby="<?= htmlspecialchars($labelId, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="<?= htmlspecialchars($labelId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?= csrf_field() ?>
                        <?php foreach ($hidden as $name => $value): ?>
                            <input type="hidden" name="<?= htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endforeach; ?>
                        <div class="mb-0">
                            <label for="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>" class="form-label"><?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="text" class="form-control" id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>" name="<?= htmlspecialchars($fieldName, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-brand"><?= htmlspecialchars($submitLabel, ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}
