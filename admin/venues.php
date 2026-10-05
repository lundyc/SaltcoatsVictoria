<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Fixture management',
    'title' => 'Venues',
    'subtitle' => 'Maintain ground details and the locations used across your fixture list.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';

$venues = getMatchVenues($pdo);

$venueIdsByName = [];
foreach ($venues as $venue) {
    $venueIdsByName[strtolower(trim((string)$venue['name']))][] = (int)$venue['id'];
}

$usageByVenueId = [];
$usageStmt = $pdo->query("
    SELECT f.venue, f.match_date, o.venue_id AS opponent_venue_id
    FROM match_fixtures f
    LEFT JOIN match_opponents o ON o.id = f.opponent_id
    WHERE f.venue IS NOT NULL
      AND TRIM(f.venue) <> ''
");
foreach ($usageStmt as $usage) {
    $candidateIds = $venueIdsByName[strtolower(trim((string)$usage['venue']))] ?? [];
    $opponentVenueId = (int)($usage['opponent_venue_id'] ?? 0);
    $venueId = count($candidateIds) === 1
        ? $candidateIds[0]
        : (in_array($opponentVenueId, $candidateIds, true) ? $opponentVenueId : 0);
    if ($venueId <= 0) {
        continue;
    }

    if (!isset($usageByVenueId[$venueId])) {
        $usageByVenueId[$venueId] = ['fixture_count' => 0, 'latest_fixture' => null];
    }
    $usageByVenueId[$venueId]['fixture_count']++;
    if (
        $usageByVenueId[$venueId]['latest_fixture'] === null
        || (string)$usage['match_date'] > (string)$usageByVenueId[$venueId]['latest_fixture']
    ) {
        $usageByVenueId[$venueId]['latest_fixture'] = $usage['match_date'];
    }
}

$venueStats = [
    'total' => count($venues),
    'in_use' => 0,
    'complete' => 0,
    'needs_details' => 0,
];

foreach ($venues as &$venue) {
    $usage = $usageByVenueId[(int)$venue['id']] ?? [];
    $hasAddress = trim((string)($venue['address_line1'] ?? '')) !== ''
        && trim((string)($venue['town'] ?? '')) !== ''
        && trim((string)($venue['postcode'] ?? '')) !== '';

    $venue['fixture_count'] = (int)($usage['fixture_count'] ?? 0);
    $venue['latest_fixture'] = $usage['latest_fixture'] ?? null;
    $venue['has_complete_address'] = $hasAddress;
    $venue['search_text'] = strtolower(implode(' ', [
        (string)($venue['name'] ?? ''),
        (string)($venue['club_name'] ?? ''),
        (string)($venue['address_line1'] ?? ''),
        (string)($venue['town'] ?? ''),
        (string)($venue['postcode'] ?? ''),
    ]));

    $mapParts = array_filter([
        trim((string)($venue['name'] ?? '')),
        trim((string)($venue['address_line1'] ?? '')),
        trim((string)($venue['town'] ?? '')),
        trim((string)($venue['postcode'] ?? '')),
    ]);
    $venue['map_url'] = $mapParts
        ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(implode(', ', $mapParts))
        : '';

    if ($venue['fixture_count'] > 0) {
        $venueStats['in_use']++;
    }
    if ($hasAddress) {
        $venueStats['complete']++;
    } else {
        $venueStats['needs_details']++;
    }
}
unset($venue);

usort($venues, static function (array $a, array $b): int {
    $usageComparison = ((int)$b['fixture_count']) <=> ((int)$a['fixture_count']);
    return $usageComparison !== 0
        ? $usageComparison
        : strcasecmp((string)$a['name'], (string)$b['name']);
});
?>

<div class="venues-page">
    <div class="venues-notices" aria-live="polite">
        <?php if (isset($_GET['saved'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Venue saved successfully.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_GET['deleted'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <span>Venue deleted successfully.</span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
    </div>

    <?php hub_render_metric_grid([
        ['label' => 'Total venues', 'value' => number_format($venueStats['total']), 'meta' => 'Venue records', 'icon' => 'fa-location-dot', 'tone' => 'primary'],
        ['label' => 'Used by fixtures', 'value' => number_format($venueStats['in_use']), 'meta' => 'Currently assigned', 'icon' => 'fa-calendar-check', 'tone' => 'success'],
        ['label' => 'Complete addresses', 'value' => number_format($venueStats['complete']), 'meta' => 'Ready for match details', 'icon' => 'fa-map-location-dot', 'tone' => 'info'],
        ['label' => 'Needs details', 'value' => number_format($venueStats['needs_details']), 'meta' => 'Missing address data', 'icon' => 'fa-circle-exclamation', 'tone' => 'warning'],
    ], 'Venue summary'); ?>

    <section class="venues-directory hub-section" aria-labelledby="venuesDirectoryTitle">
        <div class="venues-directory__header">
            <div>
                <div class="venues-directory__eyebrow">Venue directory</div>
                <h2 id="venuesDirectoryTitle">Grounds and locations</h2>
                <p>Search venue records and review their fixture usage.</p>
            </div>
            <a href="venue.php?action=new" class="btn btn-brand venues-directory__add">
                <i class="fa-solid fa-plus" aria-hidden="true"></i>
                <span>Add venue</span>
            </a>
        </div>

        <div class="venues-toolbar hub-toolbar">
            <div class="venues-search">
                <label class="visually-hidden" for="venueSearch">Search venues</label>
                <input id="venueSearch" type="search" class="form-control" placeholder="Search venue, club, town or postcode" autocomplete="off">
                <button type="button" class="venues-search__clear" id="clearVenueSearch" aria-label="Clear venue search" title="Clear search" hidden>
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </div>

            <div class="venues-filter" role="group" aria-label="Filter venues">
                <button type="button" class="venues-filter__option active" data-venue-filter="all" aria-pressed="true">All</button>
                <button type="button" class="venues-filter__option" data-venue-filter="used" aria-pressed="false">In use</button>
                <button type="button" class="venues-filter__option" data-venue-filter="incomplete" aria-pressed="false">Needs details</button>
            </div>

            <div class="venues-toolbar__count"><strong id="visibleVenueCount"><?= count($venues) ?></strong> venues</div>
        </div>

        <div class="venues-desktop d-none d-xl-block">
            <div class="table-responsive hub-table-card">
                <table class="table venues-table hub-data-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Venue</th>
                            <th>Location</th>
                            <th class="text-center">Fixture usage</th>
                            <th>Record status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="venueTableBody">
                        <?php foreach ($venues as $venue): ?>
                            <tr class="js-venue-item"
                                data-search="<?= h($venue['search_text']) ?>"
                                data-used="<?= $venue['fixture_count'] > 0 ? '1' : '0' ?>"
                                data-complete="<?= $venue['has_complete_address'] ? '1' : '0' ?>">
                                <td>
                                    <div class="venues-primary">
                                        <span class="venues-primary__icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                                        <div>
                                            <a href="venue.php?id=<?= (int)$venue['id'] ?>" class="venues-primary__name"><?= h((string)$venue['name']) ?></a>
                                            <div class="venues-primary__club"><?= h((string)($venue['club_name'] ?: 'No club assigned')) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($venue['has_complete_address']): ?>
                                        <div class="venues-address"><?= h((string)$venue['address_line1']) ?></div>
                                        <div class="venues-address__secondary"><?= h(trim((string)$venue['town'] . ' · ' . (string)$venue['postcode'], ' ·')) ?></div>
                                    <?php else: ?>
                                        <span class="venues-muted">Address not completed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($venue['fixture_count'] > 0): ?>
                                        <span class="venues-usage">
                                            <strong><?= number_format($venue['fixture_count']) ?></strong>
                                            fixture<?= $venue['fixture_count'] === 1 ? '' : 's' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="venues-muted">Not used</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($venue['has_complete_address']): ?>
                                        <span class="venues-status venues-status--complete"><i class="fa-solid fa-check" aria-hidden="true"></i> Complete</span>
                                    <?php else: ?>
                                        <span class="venues-status venues-status--attention"><i class="fa-solid fa-exclamation" aria-hidden="true"></i> Needs details</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="venues-actions hub-actions">
                                        <?php if ($venue['map_url'] !== ''): ?>
                                            <a href="<?= h($venue['map_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary" title="Open in Google Maps" aria-label="Open <?= h((string)$venue['name']) ?> in Google Maps">
                                                <i class="fa-solid fa-map" aria-hidden="true"></i>
                                            </a>
                                        <?php endif; ?>
                                        <a href="venue.php?id=<?= (int)$venue['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit venue" aria-label="Edit <?= h((string)$venue['name']) ?>">
                                            <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                        </a>
                                        <a href="venue_delete.php?id=<?= (int)$venue['id'] ?>" class="btn btn-sm btn-outline-danger" title="Delete venue" aria-label="Delete <?= h((string)$venue['name']) ?>">
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

        <div class="venues-mobile d-xl-none" id="venueMobileList">
            <?php foreach ($venues as $venue): ?>
                <article class="venues-mobile-card hub-record-card js-venue-item"
                         data-search="<?= h($venue['search_text']) ?>"
                         data-used="<?= $venue['fixture_count'] > 0 ? '1' : '0' ?>"
                         data-complete="<?= $venue['has_complete_address'] ? '1' : '0' ?>">
                    <div class="venues-mobile-card__header">
                        <div class="venues-primary">
                            <span class="venues-primary__icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                            <div>
                                <a href="venue.php?id=<?= (int)$venue['id'] ?>" class="venues-primary__name"><?= h((string)$venue['name']) ?></a>
                                <div class="venues-primary__club"><?= h((string)($venue['club_name'] ?: 'No club assigned')) ?></div>
                            </div>
                        </div>
                        <?php if ($venue['has_complete_address']): ?>
                            <span class="venues-status venues-status--complete" title="Complete record" aria-label="Complete record"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
                        <?php else: ?>
                            <span class="venues-status venues-status--attention" title="Needs details" aria-label="Needs details"><i class="fa-solid fa-exclamation" aria-hidden="true"></i></span>
                        <?php endif; ?>
                    </div>

                    <div class="venues-mobile-card__details">
                        <div>
                            <i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i>
                            <span>
                                <?php if ($venue['has_complete_address']): ?>
                                    <?= h(implode(', ', array_filter([
                                        (string)$venue['address_line1'],
                                        (string)$venue['town'],
                                        (string)$venue['postcode'],
                                    ]))) ?>
                                <?php else: ?>
                                    Address not completed
                                <?php endif; ?>
                            </span>
                        </div>
                        <div>
                            <i class="fa-regular fa-calendar" aria-hidden="true"></i>
                            <span><?= $venue['fixture_count'] > 0 ? number_format($venue['fixture_count']) . ' fixture' . ($venue['fixture_count'] === 1 ? '' : 's') : 'Not used by a fixture' ?></span>
                        </div>
                    </div>

                    <div class="venues-mobile-card__actions hub-actions">
                        <?php if ($venue['map_url'] !== ''): ?>
                            <a href="<?= h($venue['map_url']) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary">
                                <i class="fa-solid fa-map" aria-hidden="true"></i> Map
                            </a>
                        <?php endif; ?>
                        <a href="venue.php?id=<?= (int)$venue['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fa-solid fa-pen" aria-hidden="true"></i> Edit
                        </a>
                        <a href="venue_delete.php?id=<?= (int)$venue['id'] ?>" class="btn btn-sm btn-outline-danger" aria-label="Delete <?= h((string)$venue['name']) ?>">
                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="venues-empty hub-empty-state" id="venueEmptyState" <?= $venues ? 'hidden' : '' ?>>
            <i class="fa-solid fa-map-location-dot" aria-hidden="true"></i>
            <h3><?= $venues ? 'No venues match your search' : 'No venues created yet' ?></h3>
            <p><?= $venues ? 'Try a different search or filter.' : 'Add your first venue to use it in fixtures.' ?></p>
            <?php if (!$venues): ?><a href="venue.php?action=new" class="btn btn-brand">Add venue</a><?php endif; ?>
        </div>
    </section>
</div>

<script>
    (function () {
        var searchInput = document.getElementById('venueSearch');
        var clearButton = document.getElementById('clearVenueSearch');
        var filterButtons = document.querySelectorAll('[data-venue-filter]');
        var venueItems = document.querySelectorAll('.js-venue-item');
        var visibleCount = document.getElementById('visibleVenueCount');
        var emptyState = document.getElementById('venueEmptyState');
        var activeFilter = 'all';

        if (!searchInput || !venueItems.length) {
            return;
        }

        function normalize(value) {
            return String(value || '').toLowerCase().trim();
        }

        function applyFilters() {
            var query = normalize(searchInput.value);
            var visibleIds = new Set();

            venueItems.forEach(function (item) {
                var matchesSearch = !query || normalize(item.dataset.search).includes(query);
                var matchesFilter = activeFilter === 'all'
                    || (activeFilter === 'used' && item.dataset.used === '1')
                    || (activeFilter === 'incomplete' && item.dataset.complete === '0');
                var isVisible = matchesSearch && matchesFilter;

                item.hidden = !isVisible;
                if (isVisible) {
                    var link = item.querySelector('a[href*="venue.php?id="]');
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
                activeFilter = button.dataset.venueFilter || 'all';
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
