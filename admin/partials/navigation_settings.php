<?php
$navigationPreferences = hub_navigation_preferences();
$navigationDefinitions = hub_navigation_definitions();
?>
<div class="tab-pane fade show active">
    <form method="post" action="?tab=navigation" class="settings-card hub-form-card">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(hub_auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="section" value="navigation">
        <div class="settings-card__header d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <p class="settings-card__eyebrow">All users</p>
                <h2 class="settings-card__title">Navigation visibility</h2>
                <p class="settings-card__subtitle">Choose which sections and links appear in the menu and mobile shortcuts. Existing user permissions still apply.</p>
                <p class="small text-muted mb-0 mt-2">Hiding a menu item keeps its feature and data intact. Profile and logout controls always remain available.</p>
            </div>
            <button type="submit" class="btn btn-brand">Save navigation</button>
        </div>
        <div class="row g-3 p-3">
            <?php foreach ($navigationDefinitions as $groupKey => $group): ?>
                <?php $groupEnabled = hub_navigation_group_enabled($groupKey, $navigationPreferences); ?>
                <div class="col-12 col-xl-6">
                    <section class="border rounded-3 p-3 h-100 bg-white" aria-labelledby="navGroupTitle-<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="d-flex justify-content-between align-items-center gap-3 mb-2">
                            <h3 class="h6 mb-0" id="navGroupTitle-<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="navGroup-<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>" name="nav_groups[<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>]" value="1" <?= $groupEnabled ? 'checked' : '' ?> data-nav-group-switch>
                                <label class="form-check-label small" for="navGroup-<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>">Show section<span class="visually-hidden">: <?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></span></label>
                            </div>
                        </div>
                        <p class="small text-muted mb-2" data-nav-group-message <?= $groupEnabled ? 'hidden' : '' ?>>This whole section is hidden. Its individual choices are kept below.</p>
                        <details>
                            <summary class="small fw-semibold py-2">Menu items (<?= count($group['items']) ?>)</summary>
                            <div class="d-grid gap-2 pt-2">
                                <?php foreach ($group['items'] as $itemKey => $item): ?>
                                    <?php $key = $groupKey . '.' . $itemKey; ?>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="navItem-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" name="nav_items[<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>]" value="1" <?= hub_navigation_item_enabled($key, $navigationPreferences) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="navItem-<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?><?php if ($key === 'finance.matchday_finance'): ?><span class="d-block small text-muted">The separate Match Day link has its own switch.</span><?php endif; ?><?php if (!empty($item['developer_only'])): ?><span class="small text-muted">(developers only)</span><?php endif; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    </section>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="p-3 border-top d-flex justify-content-end"><button type="submit" class="btn btn-brand">Save navigation</button></div>
    </form>
</div>
<script>
document.querySelectorAll('[data-nav-group-switch]').forEach(function (input) {
    input.addEventListener('change', function () {
        input.closest('section').querySelector('[data-nav-group-message]').hidden = input.checked;
    });
});
</script>
