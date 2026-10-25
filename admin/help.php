<?php
declare(strict_types=1);

$slug = trim((string) ($_GET['a'] ?? ''));

$pageHero = [
    'eyebrow' => 'Help',
    'title' => 'Help Centre',
    'subtitle' => 'Guides, answers and tours for the parts of the Hub you use.',
    'actions' => [],
];
$pageStyles = ['help.css'];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/help.php';

$isAdmin = hub_auth_is_admin();
$visible = help_visible_articles($pdo);
$offArticles = help_feature_off_articles($pdo); // admins only: guides for features switched off in the menu settings
$article = null;
if ($slug !== '') {
    foreach (array_merge($visible, $offArticles) as $candidate) {
        if ($candidate['slug'] === $slug) {
            $article = $candidate;
            break;
        }
    }
}
?>

<div class="help-page">
<?php if ($slug !== '' && !$article): ?>
    <div class="help-empty">
        <i class="fa-regular fa-face-frown" aria-hidden="true"></i>
        <h2>We couldn't find that guide</h2>
        <p>It may have been moved, or your access may not include it.</p>
        <a class="btn btn-brand" href="help.php">Back to the Help Centre</a>
    </div>

<?php elseif ($article): ?>
    <?php
    $rendered = help_render_article((string) $article['body']);
    $isOff = help_article_feature_off($article);
    $siblings = array_values(array_filter($isOff ? $offArticles : $visible, static fn ($a) => $a['area'] === $article['area']));
    $index = 0;
    foreach ($siblings as $i => $s) {
        if ($s['slug'] === $article['slug']) {
            $index = $i;
        }
    }
    $prev = $siblings[$index - 1] ?? null;
    $next = $siblings[$index + 1] ?? null;
    ?>
    <?php hub_render_breadcrumbs([
        ['label' => 'Help Centre', 'href' => 'help.php'],
        ['label' => (string) $article['area'], 'href' => 'help.php?area=' . rawurlencode((string) $article['area'])],
        ['label' => (string) $article['title']],
    ]); ?>

    <div class="help-layout">
        <nav class="help-sidebar" aria-label="More in <?= h($article['area']) ?>">
            <div class="help-sidebar__title"><i class="fa-solid <?= h(help_area_icon((string) $article['area'])) ?>" aria-hidden="true"></i><?= h($article['area']) ?></div>
            <ul>
                <?php foreach ($siblings as $s): ?>
                    <li><a href="help.php?a=<?= h(rawurlencode((string) $s['slug'])) ?>" <?= $s['slug'] === $article['slug'] ? 'class="is-current" aria-current="page"' : '' ?>><?= h($s['title']) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <a class="help-sidebar__all" href="help.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> All help</a>
        </nav>

        <article class="help-article">
            <?php if ($isOff): ?><div class="alert alert-warning small" role="note"><i class="fa-solid fa-eye-slash me-1" aria-hidden="true"></i>This guide is hidden from everyone else because its feature is switched off in the menu settings. It will come back automatically when the feature is switched on.</div><?php endif; ?>
            <header class="help-article__header">
                <div class="help-eyebrow"><?= h($article['area']) ?><?php if (!(int) $article['is_published']): ?> · <span class="help-draft">Draft</span><?php endif; ?></div>
                <h2 class="help-article__title"><?= h($article['title']) ?></h2>
                <?php if (trim((string) $article['summary']) !== ''): ?><p class="help-article__lead"><?= h($article['summary']) ?></p><?php endif; ?>
                <div class="help-meta">
                    <span><i class="fa-regular fa-clock" aria-hidden="true"></i> <?= help_read_minutes((string) $article['body']) ?> min read</span>
                    <span><i class="fa-regular fa-calendar" aria-hidden="true"></i> Updated <?= h(date('j M Y', strtotime((string) $article['updated_at']) ?: time())) ?></span>
                    <button type="button" class="help-link-btn" onclick="window.print()"><i class="fa-solid fa-print" aria-hidden="true"></i> Print</button>
                    <?php if ($isAdmin): ?><a href="help_edit.php?id=<?= (int) $article['id'] ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i> Edit</a><?php endif; ?>
                </div>
            </header>
            <div class="help-prose"><?= $rendered['html'] ?></div>

            <?php if ($prev || $next): ?>
                <nav class="help-pager" aria-label="Previous and next guide">
                    <?php if ($prev): ?><a class="help-pager__prev" href="help.php?a=<?= h(rawurlencode((string) $prev['slug'])) ?>"><span>Previous</span><?= h($prev['title']) ?></a><?php else: ?><span></span><?php endif; ?>
                    <?php if ($next): ?><a class="help-pager__next" href="help.php?a=<?= h(rawurlencode((string) $next['slug'])) ?>"><span>Next</span><?= h($next['title']) ?></a><?php endif; ?>
                </nav>
            <?php endif; ?>
            <p class="help-footnote">Something wrong or missing in this guide? Tell a Site Admin so it can be fixed.</p>
        </article>

        <?php if (count($rendered['toc']) >= 2): ?>
            <nav class="help-toc" aria-label="On this page">
                <div class="help-toc__title">On this page</div>
                <ul>
                    <?php foreach ($rendered['toc'] as $t): ?><li><a href="#<?= h($t['id']) ?>"><?= h($t['text']) ?></a></li><?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

<?php else: ?>
    <?php
    require_once __DIR__ . '/lib/help_tours.php';
    $areas = [];
    foreach ($visible as $a) {
        $areas[$a['area']][] = $a;
    }
    $startArea = trim((string) ($_GET['area'] ?? ''));
    if (!isset($areas[$startArea])) {
        $startArea = '';
    }
    $bySlug = [];
    foreach ($visible as $a) {
        $bySlug[$a['slug']] = $a;
    }
    $quick = array_values(array_filter(array_map(static fn ($s) => $bySlug[$s] ?? null, ['welcome', 'glossary', 'cannot-find-something', 'your-account'])));
    $tours = help_tours_for_user();
    $tourState = help_state_all($pdo);
    ?>

    <section class="help-search" role="search">
        <label class="visually-hidden" for="helpSearch">Search the Help Centre</label>
        <div class="help-search__box">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
            <input type="search" id="helpSearch" class="help-search__input" placeholder="Search guides, e.g. refund, line-up, add a sponsor…" autocomplete="off" spellcheck="false" aria-controls="helpResults" data-endpoint="help_search.php">
            <button type="button" class="help-search__clear" id="helpClear" hidden aria-label="Clear search"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            <kbd class="help-search__kbd" aria-hidden="true">/</kbd>
        </div>
        <div class="help-chips" role="group" aria-label="Filter by area">
            <button type="button" class="help-chip <?= $startArea === '' ? 'is-active' : '' ?>" data-area="" aria-pressed="<?= $startArea === '' ? 'true' : 'false' ?>">All <span><?= count($visible) ?></span></button>
            <?php foreach ($areas as $areaName => $items): ?>
                <button type="button" class="help-chip <?= $startArea === $areaName ? 'is-active' : '' ?>" data-area="<?= h($areaName) ?>" aria-pressed="<?= $startArea === $areaName ? 'true' : 'false' ?>"><i class="fa-solid <?= h(help_area_icon($areaName)) ?>" aria-hidden="true"></i><?= h($areaName) ?> <span><?= count($items) ?></span></button>
            <?php endforeach; ?>
        </div>
        <?php if ($isAdmin): ?><a class="btn btn-brand btn-sm help-search__new" href="help_edit.php"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>New article</a><?php endif; ?>
    </section>

    <div id="helpResults" class="help-results" aria-live="polite" hidden></div>

    <div id="helpBrowse">
        <?php if (!$visible): ?>
            <div class="help-empty"><i class="fa-regular fa-folder-open" aria-hidden="true"></i><h2>No guides for your access yet</h2><p>Ask a Site Admin if you think this is wrong.</p></div>
        <?php endif; ?>

        <?php if ($quick || $tours): ?>
            <section class="help-start" data-help-start>
                <div class="help-start__intro">
                    <h2>New to the Hub?</h2>
                    <p>Take a short, optional tour, or read the basics first.</p>
                    <?php if (isset($tours['welcome'])): ?><button type="button" class="btn btn-brand" data-hub-tour="welcome"><i class="fa-solid fa-route me-1" aria-hidden="true"></i>Take the welcome tour</button><?php endif; ?>
                </div>
                <ul class="help-start__links">
                    <?php foreach ($quick as $q): ?>
                        <li><a href="help.php?a=<?= h(rawurlencode((string) $q['slug'])) ?>"><i class="fa-solid <?= h(help_area_icon((string) $q['area'])) ?>" aria-hidden="true"></i><span><strong><?= h($q['title']) ?></strong><small><?= h($q['summary']) ?></small></span></a></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php foreach ($areas as $areaName => $items): ?>
            <section class="help-area" data-help-area="<?= h($areaName) ?>" <?= $startArea !== '' && $startArea !== $areaName ? 'hidden' : '' ?> aria-labelledby="area-<?= h(help_slugify($areaName)) ?>">
                <header class="help-area__header">
                    <span class="help-area__icon"><i class="fa-solid <?= h(help_area_icon($areaName)) ?>" aria-hidden="true"></i></span>
                    <h2 id="area-<?= h(help_slugify($areaName)) ?>"><?= h($areaName) ?></h2>
                    <span class="help-area__count"><?= count($items) ?> <?= count($items) === 1 ? 'guide' : 'guides' ?></span>
                </header>
                <div class="help-grid">
                    <?php foreach ($items as $a): ?>
                        <a class="help-card" href="help.php?a=<?= h(rawurlencode((string) $a['slug'])) ?>">
                            <?php if (!(int) $a['is_published']): ?><span class="help-draft">Draft · hidden from others</span><?php endif; ?>
                            <span class="help-card__title"><?= h($a['title']) ?></span>
                            <span class="help-card__summary"><?= h($a['summary']) ?></span>
                            <span class="help-card__more">Read guide <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>

        <?php if ($tours): ?>
            <section class="help-tours" data-help-start aria-labelledby="helpToursTitle">
                <header class="help-area__header">
                    <span class="help-area__icon"><i class="fa-solid fa-route" aria-hidden="true"></i></span>
                    <h2 id="helpToursTitle">Guided tours</h2>
                    <span class="help-area__count">Optional · stop any time</span>
                </header>
                <div class="help-grid">
                    <?php foreach ($tours as $tourKey => $tour): ?>
                        <div class="help-card help-card--tour">
                            <span class="help-card__title"><?= h($tour['title']) ?>
                                <?php if (($tourState[$tourKey]['status'] ?? '') === 'completed'): ?><span class="help-badge-done"><i class="fa-solid fa-check" aria-hidden="true"></i> Done</span><?php endif; ?></span>
                            <span class="help-card__summary"><?= h($tour['summary']) ?></span>
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-hub-tour="<?= h($tourKey) ?>"><i class="fa-solid fa-play me-1" aria-hidden="true"></i><?= ($tourState[$tourKey]['status'] ?? '') === 'completed' ? 'Replay tour' : 'Start tour' ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="help-footnote"><button type="button" class="help-link-btn" data-hub-prompt-reset>Show the welcome prompt on the Club overview again</button></p>
            </section>
        <?php endif; ?>

        <?php if ($offArticles): ?>
            <section class="help-area help-off" data-help-start aria-labelledby="helpOffTitle">
                <header class="help-area__header">
                    <span class="help-area__icon"><i class="fa-solid fa-eye-slash" aria-hidden="true"></i></span>
                    <h2 id="helpOffTitle">Hidden: features switched off</h2>
                    <span class="help-area__count"><?= count($offArticles) ?> <?= count($offArticles) === 1 ? 'guide' : 'guides' ?> · only Site Admins see this</span>
                </header>
                <p class="help-meta">These guides describe parts of the Hub that are switched off in the menu settings, so nobody else sees them. They return automatically when the feature is switched on.</p>
                <ul class="help-off__list">
                    <?php foreach ($offArticles as $a): ?>
                        <li><a href="help.php?a=<?= h(rawurlencode((string) $a['slug'])) ?>"><?= h($a['title']) ?></a> <span class="help-meta">· <?= h($a['area']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
    <script src="/admin/assets/js/help-centre.js?v=<?= (int) (@filemtime(__DIR__ . '/assets/js/help-centre.js') ?: time()) ?>" defer></script>
<?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
