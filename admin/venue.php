<?php
declare(strict_types=1);

$id = (int)($_GET['id'] ?? 0);
$action = (string)($_GET['action'] ?? ($id > 0 ? 'edit' : 'new'));
if (!in_array($action, ['new', 'edit'], true)) {
    $action = $id > 0 ? 'edit' : 'new';
}

$pageHero = [
    'eyebrow' => 'Fixture management',
    'title' => $action === 'new' ? 'Add Venue' : 'Edit Venue',
    'subtitle' => $action === 'new'
        ? 'Create a ground record for fixtures and match-day information.'
        : 'Update ground identity, address, and fixture information.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/match_sponsorship.php';
require_once __DIR__ . '/lib/audit.php';

$errors = [];

if ($action === 'edit') {
    if ($id <= 0) {
        echo '<div><div class="alert alert-danger">Invalid venue ID.</div></div>';
        require __DIR__ . '/footer.php';
        exit;
    }

    $venue = getMatchVenueById($pdo, $id);
    if (!$venue) {
        echo '<div><div class="alert alert-danger">Venue not found.</div></div>';
        require __DIR__ . '/footer.php';
        exit;
    }
} else {
    $venue = [
        'club_name' => '',
        'name' => '',
        'address_line1' => '',
        'town' => '',
        'postcode' => '',
        'notes' => '',
        'created_at' => null,
        'updated_at' => null,
    ];
}

$formClubName = (string)($venue['club_name'] ?? '');
$formName = (string)($venue['name'] ?? '');
$formAddressLine1 = (string)($venue['address_line1'] ?? '');
$formTown = (string)($venue['town'] ?? '');
$formPostcode = (string)($venue['postcode'] ?? '');
$formLatitude = (string)($venue['latitude'] ?? '');
$formLongitude = (string)($venue['longitude'] ?? '');
$formNotes = (string)($venue['notes'] ?? '');
// Details stay hidden for a new venue until a Quick find result is picked or "Enter manually" is used.
$showDetails = $action === 'edit' || $_SERVER['REQUEST_METHOD'] === 'POST';

$clubOptionsByKey = [];
foreach ($pdo->query("
    SELECT DISTINCT COALESCE(NULLIF(TRIM(v.club_name), ''), TRIM(o.clubname)) AS club_name
    FROM match_opponents o
    LEFT JOIN match_venues v ON v.id = o.venue_id
    WHERE o.clubname IS NOT NULL AND TRIM(o.clubname) <> ''
") as $clubRow) {
    $clubName = trim((string)$clubRow['club_name']);
    $clubOptionsByKey[strtolower($clubName)] = $clubName;
}
if ($formClubName !== '') {
    $clubOptionsByKey[strtolower($formClubName)] = $formClubName;
}
$clubOptions = array_values($clubOptionsByKey);
usort($clubOptions, 'strcasecmp');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Refresh the page and try again.';
    }

    $formClubName = trim((string)($_POST['club_name'] ?? ''));
    $formName = trim((string)($_POST['name'] ?? ''));
    $formAddressLine1 = trim((string)($_POST['address_line1'] ?? ''));
    $formTown = trim((string)($_POST['town'] ?? ''));
    $formPostcode = strtoupper(trim((string)($_POST['postcode'] ?? '')));
    $formLatitude = trim((string)($_POST['latitude'] ?? ''));
    $formLongitude = trim((string)($_POST['longitude'] ?? ''));
    $formNotes = trim((string)($_POST['notes'] ?? ''));
    $submittedVenueId = $action === 'edit' ? (int)($venue['id'] ?? $id) : (int)($_POST['venue_id'] ?? 0);

    if ($formName === '') {
        $errors[] = 'Venue name is required.';
    }

    if (!$errors) {
        try {
            saveMatchVenue(
                $pdo,
                $submittedVenueId > 0 ? $submittedVenueId : null,
                $formName,
                $formClubName !== '' ? $formClubName : null,
                $formAddressLine1 !== '' ? $formAddressLine1 : null,
                $formTown !== '' ? $formTown : null,
                $formPostcode !== '' ? $formPostcode : null,
                $formNotes !== '' ? $formNotes : null,
                is_numeric($formLatitude) ? (float)$formLatitude : null,
                is_numeric($formLongitude) ? (float)$formLongitude : null
            );
            auditLog($pdo, $submittedVenueId > 0 ? 'venue_updated' : 'venue_created', ($submittedVenueId > 0 ? 'Updated' : 'Created') . " venue '{$formName}'");
            header('Location: venues.php?saved=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$fixtureCount = 0;
if ($action === 'edit') {
    $fixtureCountStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM match_fixtures
        WHERE LOWER(TRIM(COALESCE(venue, ''))) = LOWER(TRIM(:venue))
    ");
    $fixtureCountStmt->execute([':venue' => (string)$venue['name']]);
    $fixtureCount = (int)$fixtureCountStmt->fetchColumn();
}

$hasCompleteAddress = $formAddressLine1 !== '' && $formTown !== '' && $formPostcode !== '';
$mapParts = array_filter([$formName, $formAddressLine1, $formTown, $formPostcode]);
$mapUrl = $mapParts
    ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(implode(', ', $mapParts))
    : '';
$recordDate = (string)($venue['updated_at'] ?: ($venue['created_at'] ?? ''));
?>

<div class="venue-editor-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="venues.php">Venues</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $action === 'new' ? 'Add venue' : h($formName) ?></span></nav>
    <?php if ($errors): ?>
        <div class="alert alert-danger venue-editor-alert" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <div>
                <div class="fw-semibold">The venue could not be saved.</div>
                <ul class="mb-0 mt-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?= h($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="post"
          class="venue-editor-layout"
          action="venue.php?action=<?= h($action) ?><?= $id > 0 ? '&id=' . (int)$id : '' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="venue_id" value="<?= (int)$id ?>">

        <div class="venue-editor-panel">
            <div class="venue-editor-panel__header">
                <div>
                    <div class="venue-editor-panel__eyebrow">Venue record</div>
                    <h2><?= $action === 'new' ? 'Venue details' : h($formName) ?></h2>
                    <p>Fields marked required must be completed before saving.</p>
                </div>
                <?php if ($action === 'edit'): ?>
                    <span class="venue-editor-id">ID <?= (int)$id ?></span>
                <?php endif; ?>
            </div>

            <section class="venue-editor-section venue-find" aria-labelledby="venueFindHeading">
                <div class="venue-editor-section__heading">
                    <span><i class="fa-solid fa-magnifying-glass-location" aria-hidden="true"></i></span>
                    <div>
                        <h3 id="venueFindHeading">Quick find</h3>
                        <p>Search for the ground to fill in the name, address and map pin.</p>
                    </div>
                </div>
                <div class="venue-editor-fields">
                    <div class="venue-editor-field venue-editor-field--full">
                        <input type="search"
                               class="form-control"
                               id="venueLookup"
                               placeholder="Type the ground name and town, e.g. Campbell Park Saltcoats"
                               autocomplete="off">
                        <div id="venueLookupResults" class="venue-lookup__results" aria-live="polite"></div>
                        <p id="venueLookupStatus" class="venue-lookup__status"></p>
                    </div>
                    <div class="venue-editor-field venue-editor-field--full" id="venueManualWrap"<?= $showDetails ? ' hidden' : '' ?>>
                        <button type="button" class="btn btn-outline-secondary" id="venueManualBtn">Enter manually</button>
                    </div>
                </div>
            </section>

            <div id="venueDetails"<?= $showDetails ? '' : ' hidden' ?>>
            <section class="venue-editor-section" aria-labelledby="venueIdentityHeading">
                <div class="venue-editor-section__heading">
                    <span><i class="fa-solid fa-landmark" aria-hidden="true"></i></span>
                    <div>
                        <h3 id="venueIdentityHeading">Identity</h3>
                        <p>The ground name and associated club.</p>
                    </div>
                </div>
                <div class="venue-editor-fields">
                    <div class="venue-editor-field venue-editor-field--full">
                        <label for="venueName" class="form-label">Venue name <span aria-hidden="true">*</span></label>
                        <input type="text"
                               class="form-control"
                               id="venueName"
                               name="name"
                               value="<?= h($formName) ?>"
                               placeholder="e.g. Campbell Park">
                    </div>
                    <div class="venue-editor-field venue-editor-field--full">
                        <label for="venueClubName" class="form-label">Club name</label>
                        <select class="form-select" id="venueClubName" name="club_name">
                            <option value="">No club assigned</option>
                            <?php foreach ($clubOptions as $clubOption): ?>
                                <option value="<?= h($clubOption) ?>" <?= strcasecmp($formClubName, $clubOption) === 0 ? 'selected' : '' ?>>
                                    <?= h($clubOption) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </section>

            <section class="venue-editor-section" aria-labelledby="venueLocationHeading">
                <div class="venue-editor-section__heading">
                    <span><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                    <div>
                        <h3 id="venueLocationHeading">Location</h3>
                        <p>Address details used for match-day directions.</p>
                    </div>
                </div>
                <div class="venue-editor-fields">
                    <div class="venue-editor-field venue-editor-field--full">
                        <label for="venueAddress" class="form-label">Street address</label>
                        <input type="text"
                               class="form-control"
                               id="venueAddress"
                               name="address_line1"
                               value="<?= h($formAddressLine1) ?>"
                               placeholder="Street and building number">
                    </div>
                    <div class="venue-editor-field">
                        <label for="venueTown" class="form-label">Town or city</label>
                        <input type="text"
                               class="form-control"
                               id="venueTown"
                               name="town"
                               value="<?= h($formTown) ?>"
                               placeholder="Town or city">
                    </div>
                    <div class="venue-editor-field">
                        <label for="venuePostcode" class="form-label">Postcode</label>
                        <input type="text"
                               class="form-control text-uppercase"
                               id="venuePostcode"
                               name="postcode"
                               value="<?= h($formPostcode) ?>"
                               placeholder="e.g. KA21 5JQ"
                               autocomplete="postal-code">
                    </div>
                    <div class="venue-editor-field venue-editor-field--full">
                        <input type="hidden" name="latitude" id="venueLatitude" value="<?= h($formLatitude) ?>">
                        <input type="hidden" name="longitude" id="venueLongitude" value="<?= h($formLongitude) ?>">
                        <p id="venuePinStatus" class="venue-lookup__status"><?= $formLatitude !== '' && $formLongitude !== '' ? 'Map pin set: ' . h($formLatitude) . ', ' . h($formLongitude) : 'No map pin set yet.' ?></p>
                        <iframe id="venueMapPreview"
                                class="venue-lookup__map"
                                title="Map preview"
                                loading="lazy"
                                <?php if ($formLatitude !== '' && $formLongitude !== ''): ?>
                                src="https://maps.google.com/maps?q=<?= h($formLatitude . ',' . $formLongitude) ?>&amp;z=16&amp;output=embed"
                                <?php else: ?>
                                hidden
                                <?php endif; ?>></iframe>
                    </div>
                    <style>
                        .venue-lookup__results { display: grid; gap: .35rem; margin-top: .5rem; }
                        .venue-lookup__item { text-align: left; padding: .5rem .7rem; border: 1px solid #d6d6d6; border-radius: 8px; background: #fff; }
                        .venue-lookup__item:hover, .venue-lookup__item:focus { border-color: #7a1730; outline: none; }
                        .venue-lookup__status { margin: .4rem 0 0; font-size: .85rem; color: #6c757d; }
                        .venue-lookup__map { display: block; width: 100%; height: 240px; border: 0; border-radius: 8px; margin-top: .6rem; }
                    </style>
                </div>
            </section>

            <section class="venue-editor-section" aria-labelledby="venueNotesHeading">
                <div class="venue-editor-section__heading">
                    <span><i class="fa-solid fa-note-sticky" aria-hidden="true"></i></span>
                    <div>
                        <h3 id="venueNotesHeading">Internal notes</h3>
                        <p>Operational information retained with the venue.</p>
                    </div>
                </div>
                <div class="venue-editor-fields">
                    <div class="venue-editor-field venue-editor-field--full">
                        <label for="venueNotes" class="form-label">Notes</label>
                        <textarea class="form-control"
                                  id="venueNotes"
                                  name="notes"
                                  rows="4"
                                  placeholder="Access, parking, pitch or contact information"><?= h($formNotes) ?></textarea>
                    </div>
                </div>
            </section>

            <div class="venue-editor-footer">
                <div class="venue-editor-footer__secondary">
                    <a href="venues.php" class="btn btn-outline-secondary">Cancel</a>
                    <?php if ($action === 'edit'): ?>
                        <a href="venue_delete.php?id=<?= (int)$id ?>" class="btn btn-outline-danger">
                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                            Delete
                        </a>
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn btn-brand">
                    <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                    <?= $action === 'new' ? 'Create Venue' : 'Save Changes' ?>
                </button>
            </div>
            </div>
        </div>

        <aside class="venue-editor-overview" aria-label="Venue record overview">
            <div class="venue-editor-overview__map">
                <span><i class="fa-solid fa-map-location-dot" aria-hidden="true"></i></span>
                <div>
                    <div class="venue-editor-overview__label">Record status</div>
                    <?php if ($hasCompleteAddress): ?>
                        <strong class="venue-editor-overview__status text-success">Address complete</strong>
                    <?php else: ?>
                        <strong class="venue-editor-overview__status text-warning-emphasis">Address incomplete</strong>
                    <?php endif; ?>
                </div>
            </div>

            <dl class="venue-editor-overview__details">
                <div>
                    <dt>Fixtures</dt>
                    <dd><?= number_format($fixtureCount) ?></dd>
                </div>
                <div>
                    <dt>Club</dt>
                    <dd><?= h($formClubName !== '' ? $formClubName : 'Not assigned') ?></dd>
                </div>
                <div>
                    <dt>Location</dt>
                    <dd><?= h(implode(', ', array_filter([$formTown, $formPostcode])) ?: 'Not completed') ?></dd>
                </div>
                <?php if ($recordDate !== ''): ?>
                    <div>
                        <dt>Record date</dt>
                        <dd><?= h(date('d/m/Y', strtotime($recordDate))) ?></dd>
                    </div>
                <?php endif; ?>
            </dl>

            <?php if ($mapUrl !== ''): ?>
                <a href="<?= h($mapUrl) ?>"
                   class="btn btn-outline-secondary w-100"
                   target="_blank"
                   rel="noopener noreferrer">
                    <i class="fa-solid fa-map" aria-hidden="true"></i>
                    Open in Google Maps
                </a>
            <?php endif; ?>
        </aside>
    </form>
</div>

<script>
(function () {
    var input = document.getElementById('venueLookup');
    if (!input) return;
    var results = document.getElementById('venueLookupResults');
    var status = document.getElementById('venueLookupStatus');
    var latField = document.getElementById('venueLatitude');
    var lonField = document.getElementById('venueLongitude');
    var pinStatus = document.getElementById('venuePinStatus');
    var preview = document.getElementById('venueMapPreview');
    var timer = null;
    var seq = 0;

    function setPin(lat, lon) {
        latField.value = lat;
        lonField.value = lon;
        pinStatus.textContent = 'Map pin set: ' + lat + ', ' + lon;
        preview.src = 'https://maps.google.com/maps?q=' + lat + ',' + lon + '&z=16&output=embed';
        preview.hidden = false;
    }

    function showDetails() {
        document.getElementById('venueDetails').hidden = false;
        document.getElementById('venueManualWrap').hidden = true;
    }

    document.getElementById('venueManualBtn').addEventListener('click', function () {
        showDetails();
        document.getElementById('venueName').focus();
    });

    function pick(r) {
        document.getElementById('venueName').value = r.name || r.label;
        document.getElementById('venueAddress').value = r.address_line1 || '';
        document.getElementById('venueTown').value = r.town || '';
        document.getElementById('venuePostcode').value = r.postcode || '';
        showDetails();
        setPin(r.latitude, r.longitude);
        results.innerHTML = '';
        status.textContent = 'Address and pin filled in. Check the map, then save.';
    }

    function render(list) {
        results.innerHTML = '';
        list.forEach(function (r) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'venue-lookup__item';
            b.textContent = (r.name ? r.name + ' - ' : '') + r.label;
            b.addEventListener('click', function () { pick(r); });
            results.appendChild(b);
        });
    }

    input.addEventListener('input', function () {
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 3) {
            results.innerHTML = '';
            status.textContent = '';
            return;
        }
        timer = setTimeout(function () {
            var mine = ++seq;
            status.textContent = 'Searching...';
            fetch('venue_lookup.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (mine !== seq) return;
                    if (d.error) { status.textContent = d.error; return; }
                    if (!d.results.length) {
                        results.innerHTML = '';
                        status.textContent = 'No matches. Try the ground name with the town.';
                        return;
                    }
                    status.textContent = 'Pick the right one:';
                    render(d.results);
                })
                .catch(function () {
                    status.textContent = 'The map search failed. Enter the details by hand.';
                });
        }, 400);
    });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
