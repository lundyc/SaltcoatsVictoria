<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Fixture management',
    'title' => 'Competitions',
    'subtitle' => 'Maintain competition records, league settings, branding, and fixture usage.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/competition_structure.php';

ensureCompetitionStructureSchema($pdo);
$competitions = getMatchCompetitions($pdo);

function competitionDirectoryTypeMeta(array $competition): array
{
    $type = (string)($competition['competition_type'] ?? 'other');
    if (!empty($competition['is_league'])) {
        $type = 'league';
    }
    return match ($type) {
        'league' => ['label' => 'League', 'icon' => 'fa-table-list', 'class' => 'competitions-type--league'],
        'cup' => ['label' => 'Cup', 'icon' => 'fa-trophy', 'class' => 'competitions-type--standard'],
        'friendly' => ['label' => 'Friendly', 'icon' => 'fa-handshake', 'class' => 'competitions-type--standard'],
        'tournament' => ['label' => 'Tournament', 'icon' => 'fa-medal', 'class' => 'competitions-type--standard'],
        default => ['label' => 'Other', 'icon' => 'fa-futbol', 'class' => 'competitions-type--standard'],
    };
}

$usageByCompetition = [];
$usageStmt = $pdo->query("
    SELECT competition_id,
           COUNT(DISTINCT fixture_id) AS fixture_count,
           MIN(match_date) AS first_fixture,
           MAX(match_date) AS latest_fixture
    FROM (
        SELECT cs.competition_id, f.id AS fixture_id, f.match_date
        FROM match_fixtures f
        INNER JOIN competition_seasons cs ON cs.id = f.competition_season_id

        UNION ALL

        SELECT mc.id, f.id, f.match_date
        FROM match_fixtures f
        INNER JOIN match_competitions mc ON LOWER(TRIM(mc.name)) = LOWER(TRIM(f.competition))
        WHERE f.competition_season_id IS NULL
          AND f.competition IS NOT NULL
          AND TRIM(f.competition) <> ''

        UNION ALL

        SELECT ca.competition_id, f.id, f.match_date
        FROM match_fixtures f
        INNER JOIN competition_aliases ca
            ON LOWER(TRIM(ca.alias_name)) = LOWER(TRIM(f.competition))
           AND ca.review_status = 'confirmed'
           AND (ca.season_id IS NULL OR ca.season_id = f.season_id)
        WHERE f.competition_season_id IS NULL
          AND f.competition IS NOT NULL
          AND TRIM(f.competition) <> ''
    ) fixture_competitions
    GROUP BY competition_id
");
foreach ($usageStmt as $usage) {
    $usageByCompetition[(int)$usage['competition_id']] = $usage;
}

$seasonLinks = [];
$seasonStmt = $pdo->query("
    SELECT cs.competition_id,
           COUNT(*) AS season_count,
           MAX(CASE WHEN s.is_current = 1 THEN 1 ELSE 0 END) AS is_current
    FROM competition_seasons cs
    INNER JOIN seasons s ON s.id = cs.season_id
    WHERE cs.is_active = 1
    GROUP BY cs.competition_id
");
foreach ($seasonStmt as $seasonLink) {
    $seasonLinks[(int)$seasonLink['competition_id']] = $seasonLink;
}

$competitionStats = [
    'total' => count($competitions),
    'in_use' => 0,
    'fixtures' => 0,
    'leagues' => 0,
];

foreach ($competitions as &$competition) {
    $competition['type_meta'] = competitionDirectoryTypeMeta($competition);
    $usage = $usageByCompetition[(int)$competition['id']] ?? [];
    $seasonLink = $seasonLinks[(int)$competition['id']] ?? [];
    $badgePath = trim((string)($competition['badge_image'] ?? ''));
    $whiteBadgePath = trim((string)($competition['white_badge_image'] ?? ''));
    $bannerPath = trim((string)($competition['league_banner_image'] ?? ''));

    $competition['fixture_count'] = (int)($usage['fixture_count'] ?? 0);
    $competition['first_fixture'] = $usage['first_fixture'] ?? null;
    $competition['latest_fixture'] = $usage['latest_fixture'] ?? null;
    $competition['season_count'] = (int)($seasonLink['season_count'] ?? 0);
    $competition['is_current_season'] = !empty($seasonLink['is_current']);
    $competition['has_branding'] = $badgePath !== '' || $whiteBadgePath !== '' || $bannerPath !== '';
    $competition['badge_url'] = $badgePath !== ''
        ? '/' . ltrim($badgePath, '/')
        : ($whiteBadgePath !== '' ? '/' . ltrim($whiteBadgePath, '/') : '');
    $competition['search_text'] = strtolower(implode(' ', [
        (string)$competition['name'],
        (string)($competition['organiser'] ?? ''),
        (string)($competition['competition_type'] ?? ''),
    ]));

    if ($competition['fixture_count'] > 0) {
        $competitionStats['in_use']++;
        $competitionStats['fixtures'] += $competition['fixture_count'];
    }
    if ((string)($competition['competition_type'] ?? '') === 'league' || !empty($competition['is_league'])) {
        $competitionStats['leagues']++;
    }
}
unset($competition);
?>

<div class="competitions-page">
    <div class="competitions-notices" aria-live="polite">
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Competition saved successfully.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Competition deleted successfully.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <?php hub_render_metric_grid([
        ['label' => 'Total competitions', 'value' => number_format($competitionStats['total']), 'meta' => 'Competition records', 'icon' => 'fa-trophy', 'tone' => 'primary'],
        ['label' => 'Used by fixtures', 'value' => number_format($competitionStats['in_use']), 'meta' => 'Currently assigned', 'icon' => 'fa-calendar-check', 'tone' => 'success'],
        ['label' => 'Fixture assignments', 'value' => number_format($competitionStats['fixtures']), 'meta' => 'Across all seasons', 'icon' => 'fa-futbol', 'tone' => 'info'],
        ['label' => 'League configurations', 'value' => number_format($competitionStats['leagues']), 'meta' => 'Table definitions', 'icon' => 'fa-table-list', 'tone' => 'warning'],
    ], 'Competition summary'); ?>

    <section class="competitions-directory hub-section" aria-labelledby="competitionsDirectoryTitle">
        <div class="competitions-directory__header">
            <div>
                <div class="competitions-directory__eyebrow">Competition directory</div>
                <h2 id="competitionsDirectoryTitle">Fixture competitions</h2>
                <p>Review competition setup, display order, and where each record is used.</p>
            </div>
            <a href="competition.php?action=new" class="btn btn-brand competitions-directory__add">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                <span>Add competition</span>
            </a>
        </div>

        <div class="competitions-toolbar hub-toolbar">
            <div class="competitions-search">
                <label class="visually-hidden" for="competitionSearch">Search competitions</label>
                <input id="competitionSearch"
                       type="search"
                       class="form-control"
                       placeholder="Search competitions"
                       autocomplete="off">
                <button type="button"
                        class="competitions-search__clear"
                        id="clearCompetitionSearch"
                        aria-label="Clear competition search"
                        title="Clear search"
                        hidden>
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            <div class="competitions-filter" role="group" aria-label="Filter competitions">
                <button type="button" class="competitions-filter__option active" data-competition-filter="all" aria-pressed="true">All</button>
                <button type="button" class="competitions-filter__option" data-competition-filter="used" aria-pressed="false">In use</button>
                <button type="button" class="competitions-filter__option" data-competition-filter="league" aria-pressed="false">Leagues</button>
            </div>

            <div class="competitions-toolbar__count">
                <strong id="visibleCompetitionCount"><?= count($competitions) ?></strong>
                competitions
            </div>
        </div>

        <div class="competitions-desktop d-none d-xl-block">
            <div class="table-responsive hub-table-card">
                <table class="table competitions-table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Competition</th>
                            <th>Type</th>
                            <th class="text-center">Fixture usage</th>
                            <th>Season</th>
                            <th class="text-center">Order</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($competitions as $competition): ?>
                            <tr class="js-competition-item"
                                data-search="<?= h($competition['search_text']) ?>"
                                data-used="<?= $competition['fixture_count'] > 0 ? '1' : '0' ?>"
                                data-league="<?= $competition['type_meta']['label'] === 'League' ? '1' : '0' ?>">
                                <td>
                                    <div class="competitions-primary">
                                        <span class="competitions-primary__mark <?= $competition['badge_url'] !== '' ? 'competitions-primary__mark--image' : '' ?>">
                                            <?php if ($competition['badge_url'] !== ''): ?>
                                                <img src="<?= h($competition['badge_url']) ?>" alt="" loading="lazy">
                                            <?php else: ?>
                                                <i class="fa-solid fa-trophy" aria-hidden="true"></i>
                                            <?php endif; ?>
                                        </span>
                                        <div>
                                            <a href="competition.php?id=<?= (int)$competition['id'] ?>" class="competitions-primary__name"><?= h((string)$competition['name']) ?></a>
                                            <div class="competitions-primary__meta">
                                                <?php if ($competition['has_branding']): ?>
                                                    <span><i class="fa-regular fa-image" aria-hidden="true"></i> Branding added</span>
                                                <?php else: ?>
                                                    <span>No branding</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="competitions-type <?= h($competition['type_meta']['class']) ?>"><i class="fa-solid <?= h($competition['type_meta']['icon']) ?>" aria-hidden="true"></i> <?= h($competition['type_meta']['label']) ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($competition['fixture_count'] > 0): ?>
                                        <span class="competitions-usage">
                                            <strong><?= number_format($competition['fixture_count']) ?></strong>
                                            fixture<?= $competition['fixture_count'] === 1 ? '' : 's' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="competitions-muted">Not used</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($competition['is_current_season']): ?>
                                        <span class="competitions-season competitions-season--current"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Current season</span>
                                    <?php elseif ($competition['season_count'] > 0): ?>
                                        <span class="competitions-season"><?= number_format($competition['season_count']) ?> season<?= $competition['season_count'] === 1 ? '' : 's' ?></span>
                                    <?php else: ?>
                                        <span class="competitions-muted">Not assigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><span class="competitions-order"><?= h((string)($competition['sort_order'] ?? '-')) ?></span></td>
                                <td class="text-end">
                                    <div class="competitions-actions hub-actions">
                                        <?php if (!empty($competition['is_league']) && !empty($competition['league_url'])): ?>
                                            <a href="<?= h((string)$competition['league_url']) ?>"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="btn btn-sm btn-outline-secondary"
                                               title="Open league website"
                                               aria-label="Open <?= h((string)$competition['name']) ?> website">
                                                <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="competition.php?id=<?= (int)$competition['id'] ?>"
                                           class="btn btn-sm btn-outline-primary"
                                           title="Edit competition"
                                           aria-label="Edit <?= h((string)$competition['name']) ?>">
                                            <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                        </a>
                                        <a href="competition_delete.php?id=<?= (int)$competition['id'] ?>"
                                           class="btn btn-sm btn-outline-danger"
                                           title="Delete competition"
                                           aria-label="Delete <?= h((string)$competition['name']) ?>">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="competitions-mobile d-xl-none">
            <?php foreach ($competitions as $competition): ?>
                <article class="competitions-mobile-card hub-record-card js-competition-item"
                         data-search="<?= h($competition['search_text']) ?>"
                         data-used="<?= $competition['fixture_count'] > 0 ? '1' : '0' ?>"
                         data-league="<?= $competition['type_meta']['label'] === 'League' ? '1' : '0' ?>">
                    <div class="competitions-mobile-card__header">
                        <div class="competitions-primary">
                            <span class="competitions-primary__mark <?= $competition['badge_url'] !== '' ? 'competitions-primary__mark--image' : '' ?>">
                                <?php if ($competition['badge_url'] !== ''): ?>
                                    <img src="<?= h($competition['badge_url']) ?>" alt="" loading="lazy">
                                <?php else: ?>
                                    <i class="fa-solid fa-trophy" aria-hidden="true"></i>
                                <?php endif; ?>
                            </span>
                            <div>
                                <a href="competition.php?id=<?= (int)$competition['id'] ?>" class="competitions-primary__name"><?= h((string)$competition['name']) ?></a>
                                <div class="competitions-primary__meta">Display order <?= h((string)($competition['sort_order'] ?? '-')) ?></div>
                            </div>
                        </div>
                        <span class="competitions-type <?= h($competition['type_meta']['class']) ?>" title="<?= h($competition['type_meta']['label']) ?>" aria-label="<?= h($competition['type_meta']['label']) ?>"><i class="fa-solid <?= h($competition['type_meta']['icon']) ?>" aria-hidden="true"></i></span>
                    </div>

                    <div class="competitions-mobile-card__details">
                        <div>
                            <span>Fixture usage</span>
                            <strong><?= number_format($competition['fixture_count']) ?></strong>
                        </div>
                        <div>
                            <span>Season</span>
                            <strong><?= $competition['is_current_season'] ? 'Current' : ($competition['season_count'] > 0 ? number_format($competition['season_count']) . ' linked' : 'Not assigned') ?></strong>
                        </div>
                        <div>
                            <span>Branding</span>
                            <strong><?= $competition['has_branding'] ? 'Added' : 'None' ?></strong>
                        </div>
                    </div>

                    <div class="competitions-mobile-card__actions hub-actions">
                        <?php if (!empty($competition['is_league']) && !empty($competition['league_url'])): ?>
                            <a href="<?= h((string)$competition['league_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">
                                <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>
                                Website
                            </a>
                        <?php endif; ?>
                        <a href="competition.php?id=<?= (int)$competition['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-pen" aria-hidden="true"></i>
                            Edit
                        </a>
                        <a href="competition_delete.php?id=<?= (int)$competition['id'] ?>" class="btn btn-sm btn-outline-danger" aria-label="Delete <?= h((string)$competition['name']) ?>">
                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="competitions-empty hub-empty-state" id="competitionEmptyState" <?= $competitions ? 'hidden' : '' ?>>
            <i class="fa-solid fa-trophy" aria-hidden="true"></i>
            <h3><?= $competitions ? 'No competitions match your search' : 'No competitions created yet' ?></h3>
            <p><?= $competitions ? 'Try a different search or filter.' : 'Add your first competition to use it in fixtures.' ?></p>
            <?php if (!$competitions): ?><a href="competition.php?action=new" class="btn btn-brand">Add competition</a><?php endif; ?>
        </div>
    </section>
</div>

<script>
    (function () {
        var searchInput = document.getElementById('competitionSearch');
        var clearButton = document.getElementById('clearCompetitionSearch');
        var filterButtons = document.querySelectorAll('[data-competition-filter]');
        var competitionItems = document.querySelectorAll('.js-competition-item');
        var visibleCount = document.getElementById('visibleCompetitionCount');
        var emptyState = document.getElementById('competitionEmptyState');
        var activeFilter = 'all';

        if (!searchInput || !competitionItems.length) {
            return;
        }

        function normalize(value) {
            return String(value || '').toLowerCase().trim();
        }

        function applyFilters() {
            var query = normalize(searchInput.value);
            var visibleIds = new Set();

            competitionItems.forEach(function (item) {
                var matchesSearch = !query || normalize(item.dataset.search).includes(query);
                var matchesFilter = activeFilter === 'all'
                    || (activeFilter === 'used' && item.dataset.used === '1')
                    || (activeFilter === 'league' && item.dataset.league === '1');
                var isVisible = matchesSearch && matchesFilter;

                item.hidden = !isVisible;
                if (isVisible) {
                    var link = item.querySelector('a[href*="competition.php?id="]');
                    if (link) {
                        visibleIds.add(link.getAttribute('href'));
                    }
                }
            });

            if (visibleCount) {
                visibleCount.textContent = String(visibleIds.size);
            }
            if (clearButton) {
                clearButton.hidden = query === '';
            }
            if (emptyState) {
                emptyState.hidden = visibleIds.size > 0;
            }
        }

        searchInput.addEventListener('input', applyFilters);
        clearButton.addEventListener('click', function () {
            searchInput.value = '';
            searchInput.focus();
            applyFilters();
        });

        filterButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                activeFilter = button.dataset.competitionFilter || 'all';
                filterButtons.forEach(function (option) {
                    var selected = option === button;
                    option.classList.toggle('active', selected);
                    option.setAttribute('aria-pressed', selected ? 'true' : 'false');
                });
                applyFilters();
            });
        });
    }());
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
