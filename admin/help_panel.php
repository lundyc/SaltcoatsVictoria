<?php
declare(strict_types=1);

/** HTML fragment for the "?" slide-out: help articles linked to ?page=<script>.php */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/help.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store');

if (hub_auth_current_user() === null) {
    http_response_code(401);
    exit;
}

$script = basename((string) ($_GET['page'] ?? ''));
$articles = [];
try {
    $articles = $script !== '' ? help_articles_for_page($pdo, $script) : [];
} catch (Throwable $e) {
    error_log('help_panel: ' . $e->getMessage());
}
?>
<?php if (!$articles && in_array($script, ['help.php', 'help_edit.php'], true)): ?>
    <p>You are already in the Help Centre. Use the search box at the top of the page, or pick an area to browse the guides.</p>
<?php elseif (!$articles): ?>
    <p class="text-muted">There is no specific help for this page yet. Try the Help Centre, or ask a Site Admin.</p>
<?php endif; ?>
<?php $multi = count($articles) > 1; ?>
<?php if ($multi): ?><div class="accordion" id="helpPanelAccordion"><?php endif; ?>
<?php foreach ($articles as $n => $a): ?>
    <?php if ($multi): ?>
    <div class="accordion-item">
        <h3 class="accordion-header"><button class="accordion-button <?= $n > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#helpPanelItem<?= (int) $n ?>" aria-expanded="<?= $n === 0 ? 'true' : 'false' ?>"><?= h($a['title']) ?></button></h3>
        <div id="helpPanelItem<?= (int) $n ?>" class="accordion-collapse collapse <?= $n === 0 ? 'show' : '' ?>" data-bs-parent="#helpPanelAccordion"><div class="accordion-body">
    <?php else: ?>
    <section class="help-panel__article">
        <h3 class="h6 text-uppercase text-muted"><?= h($a['area']) ?></h3>
        <h4 class="h5"><?= h($a['title']) ?></h4>
    <?php endif; ?>
        <div class="help-article-body"><?= help_render_markdown((string) $a['body']) ?></div>
        <a class="small" href="/admin/help.php?a=<?= h(rawurlencode((string) $a['slug'])) ?>">Open as a full page</a>
    <?php if ($multi): ?></div></div></div><?php else: ?></section><?php endif; ?>
<?php endforeach; ?>
<?php if ($multi): ?></div><?php endif; ?>
<hr>
<a class="btn btn-outline-secondary btn-sm" href="/admin/help.php"><i class="fa-solid fa-book-open me-1" aria-hidden="true"></i>Browse all help</a>
