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
$formNotes = (string)($venue['notes'] ?? '');

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
                $formNotes !== '' ? $formNotes : null
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
                               placeholder="e.g. Campbell Park"
                               required>
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

<?php require __DIR__ . '/footer.php'; ?>
