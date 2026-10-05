<?php
declare(strict_types=1);

$pageStyles = ['people.css'];
$pageHero = [
    'eyebrow' => 'Administration',
    'title' => 'Person & User',
    'subtitle' => 'A person may have a login account, club positions, dependants, season tickets and ticket history.',
    'actions' => [
        ['label' => 'People & Users', 'href' => '/club_people.php', 'class' => 'btn btn-outline-light btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/people.php';
require_once __DIR__ . '/lib/accounts.php';
require_once __DIR__ . '/lib/positions.php';
require_once __DIR__ . '/lib/access_roles.php';
require_once __DIR__ . '/lib/affiliations.php';
require_once __DIR__ . '/lib/season.php';
require_once __DIR__ . '/lib/season_tickets.php';
require_once __DIR__ . '/lib/match_tickets.php';

if (!hub_auth_has_capability('admin_settings')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage people.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$personId = (int) ($_GET['id'] ?? $_POST['person_id'] ?? 0);
$errors = [];
$setupLink = '';

function club_person_redirect(int $personId, string $status): void
{
    header('Location: /admin/club_person.php?id=' . $personId . '&status=' . rawurlencode($status));
    exit;
}

function club_person_history_counts(PDO $pdo, ?int $holderId, int $personId): array
{
    $counts = [
        'season_passes' => 0,
        'match_ticket_orders' => 0,
        'admissions' => 0,
    ];
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM entitlements WHERE person_id = :person_id AND type = 'season_pass'");
    $stmt->execute([':person_id' => $personId]);
    $counts['season_passes'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT oi.order_id)
        FROM entitlements e
        JOIN order_items oi ON oi.id = e.order_item_id
        WHERE e.person_id = :person_id AND e.type = 'match_ticket'");
    $stmt->execute([':person_id' => $personId]);
    $counts['match_ticket_orders'] = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM admissions WHERE person_id = :person_id');
    $stmt->execute([':person_id' => $personId]);
    $counts['admissions'] = (int) $stmt->fetchColumn();
    return $counts;
}

function club_person_season_ticket_history(PDO $pdo, int $personId): array
{
    return array_slice(getSeasonPassesForPerson($pdo, $personId), 0, 50);
}

function club_person_match_ticket_history(PDO $pdo, int $personId): array
{
    ensureMatchTicketSchema($pdo);
    $stmt = $pdo->prepare("SELECT o.*, o.customer_name AS buyer_name, o.customer_email AS buyer_email,
            f.opponent, f.match_date, f.kickoff_time,
            COALESCE(ticket_counts.ticket_count, 0) AS ticket_count
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id AND oi.product_type = 'match_ticket'
        JOIN fixture_ticket_packages ftp ON ftp.id = oi.product_reference_id
        JOIN match_fixtures f ON f.id = ftp.fixture_id
        LEFT JOIN (
            SELECT oi.order_id, COUNT(*) AS ticket_count
            FROM entitlements e
            JOIN order_items oi ON oi.id = e.order_item_id
            WHERE e.type = 'match_ticket'
            GROUP BY oi.order_id
        ) ticket_counts ON ticket_counts.order_id = o.id
        WHERE o.person_id = :person_id
        GROUP BY o.id
        ORDER BY f.match_date DESC, o.created_at DESC, o.id DESC
        LIMIT 50");
    $stmt->execute([':person_id' => $personId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function club_person_format_uk_datetime(?string $value, string $fallback = 'Never'): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $fallback;
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('d/m/Y H:i', $timestamp);
}

function club_person_is_ajax_request(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || (string) ($_POST['ajax'] ?? '') === '1';
}

function club_person_json_response(array $payload, int $status = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function club_person_position_payload(PDO $pdo, int $positionRowId, int $personId): array
{
    foreach (getPersonPositions($pdo, $personId) as $position) {
        if ((int) $position['id'] === $positionRowId) {
            return $position;
        }
    }

    throw new RuntimeException('Position assignment not found.');
}

function club_person_relationship_payload(PDO $pdo, int $relationshipId, int $personId): array
{
    foreach (getPersonDependents($pdo, $personId) as $dependent) {
        if ((int) ($dependent['relationship_id'] ?? 0) === $relationshipId) {
            return $dependent;
        }
    }

    throw new RuntimeException('Relationship not found.');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $isAjaxRequest = club_person_is_ajax_request();
    if (!hub_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        if ($isAjaxRequest) {
            club_person_json_response(['ok' => false, 'error' => 'Your session could not be verified. Reload the page and try again.'], 400);
        }
        $errors[] = 'Your session could not be verified. Reload the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? $_POST['ajax_action'] ?? '');
        try {
            if ($action === 'create_person') {
                $newPersonId = createPerson($pdo, [
                    'display_name' => (string) ($_POST['display_name'] ?? ''),
                    'date_of_birth' => (string) ($_POST['date_of_birth'] ?? ''),
                    'email' => (string) ($_POST['email'] ?? ''),
                    'phone' => (string) ($_POST['phone'] ?? ''),
                    'address_line1' => (string) ($_POST['address_line1'] ?? ''),
                    'address_line2' => (string) ($_POST['address_line2'] ?? ''),
                    'town' => (string) ($_POST['town'] ?? ''),
                    'postcode' => (string) ($_POST['postcode'] ?? ''),
                    'country' => (string) ($_POST['country'] ?? ''),
                    'marketing_opt_in' => isset($_POST['marketing_opt_in']),
                    'is_active' => 1,
                ]);
                club_person_redirect($newPersonId, 'created');
            }

            if ($personId <= 0 || !getPerson($pdo, $personId)) {
                throw new RuntimeException('Person not found.');
            }

            if ($action === 'save_person') {
                updatePerson($pdo, $personId, [
                    'display_name' => (string) ($_POST['display_name'] ?? ''),
                    'date_of_birth' => (string) ($_POST['date_of_birth'] ?? ''),
                    'email' => (string) ($_POST['email'] ?? ''),
                    'phone' => (string) ($_POST['phone'] ?? ''),
                    'address_line1' => (string) ($_POST['address_line1'] ?? ''),
                    'address_line2' => (string) ($_POST['address_line2'] ?? ''),
                    'town' => (string) ($_POST['town'] ?? ''),
                    'postcode' => (string) ($_POST['postcode'] ?? ''),
                    'country' => (string) ($_POST['country'] ?? ''),
                    'marketing_opt_in' => isset($_POST['marketing_opt_in']),
                    'is_active' => isset($_POST['is_active']),
                ]);
                // The person's email is the only email in the system now --
                // keep a login account's email in sync with it, the same
                // pattern members/profile.php already uses for self-service
                // contact-detail edits.
                $account = getAccountByPersonId($pdo, $personId);
                if ($account) {
                    $newEmail = trim((string) ($_POST['email'] ?? ''));
                    if ($newEmail !== '' && account_normalize_email($newEmail) !== $account['email_normalized']) {
                        updateAccountLogin(
                            $pdo,
                            (int) $account['id'],
                            $newEmail,
                            (int) $account['is_active'] === 1,
                            accountPrimaryRole($pdo, (int) $account['id'])
                        );
                    }
                }
                club_person_redirect($personId, 'saved');
            }

            if ($action === 'archive_person') {
                archivePerson($pdo, $personId);
                header('Location: /admin/club_people.php?status=all&status_message=archived');
                exit;
            }

            if ($action === 'add_position') {
                $rowId = addPersonPosition(
                    $pdo,
                    $personId,
                    (int) ($_POST['position_id'] ?? 0),
                    0,
                    (string) ($_POST['notes'] ?? ''),
                    (string) ($_POST['start_date'] ?? ''),
                    (string) ($_POST['end_date'] ?? '')
                );
                if ($isAjaxRequest) {
                    club_person_json_response([
                        'ok' => true,
                        'message' => 'Position added.',
                        'position' => club_person_position_payload($pdo, $rowId, $personId),
                    ]);
                }
                club_person_redirect($personId, 'position_added');
            }

            if ($action === 'update_position') {
                $positionRowId = (int) ($_POST['position_row_id'] ?? 0);
                updatePersonPosition(
                    $pdo,
                    $positionRowId,
                    (int) ($_POST['position_id'] ?? 0),
                    0,
                    (string) ($_POST['notes'] ?? ''),
                    (string) ($_POST['start_date'] ?? ''),
                    (string) ($_POST['end_date'] ?? '')
                );
                if ($isAjaxRequest) {
                    club_person_json_response([
                        'ok' => true,
                        'message' => 'Position saved.',
                        'position' => club_person_position_payload($pdo, $positionRowId, $personId),
                    ]);
                }
                club_person_redirect($personId, 'position_updated');
            }

            if ($action === 'delete_position') {
                $positionRowId = (int) ($_POST['position_row_id'] ?? 0);
                deletePersonPosition($pdo, $positionRowId);
                if ($isAjaxRequest) {
                    club_person_json_response(['ok' => true, 'message' => 'Position removed.', 'position_row_id' => $positionRowId]);
                }
                club_person_redirect($personId, 'position_removed');
            }

            if ($action === 'create_account') {
                $person = getPerson($pdo, $personId);
                $personEmail = trim((string) ($person['email'] ?? ''));
                $isAdmin = isset($_POST['is_admin']);
                $roleCode = deriveAccountRoleForPerson($pdo, $personId, $isAdmin);
                $accountId = createAccountForPerson($pdo, $personId, $personEmail, $roleCode, true);
                $setupLink = createAccountPasswordSetupLink($pdo, $accountId, '/reset_password.php');
            }

            if ($action === 'save_account') {
                $accountId = (int) ($_POST['account_id'] ?? 0);
                $account = getAccount($pdo, $accountId);
                if (!$account) {
                    throw new RuntimeException('Account not found.');
                }
                // This form only ever toggles "Account active" now — role
                // (Administrator/Staff/Volunteer) is set from the Roles tab's
                // save_access_roles handler below, so preserve it here rather
                // than re-deriving it, or the two save paths would fight.
                $roleCode = accountPrimaryRole($pdo, $accountId);
                updateAccountLogin($pdo, $accountId, (string) $account['email'], isset($_POST['account_is_active']), $roleCode);
                club_person_redirect($personId, 'account_saved');
            }

            if ($action === 'save_person_tags') {
                set_person_tags($pdo, $personId, isset($_POST['person_tags']) && is_array($_POST['person_tags']) ? $_POST['person_tags'] : []);
                club_person_redirect($personId, 'person_tags_saved');
            }

            if ($action === 'add_access_override') {
                setPersonAccessOverride(
                    $pdo,
                    $personId,
                    (int) ($_POST['override_capability_id'] ?? 0),
                    (string) ($_POST['override_effect'] ?? ''),
                    (string) ($_POST['override_expires_at'] ?? ''),
                    (string) ($_POST['override_note'] ?? ''),
                    (int) (hub_auth_current_user()['person_id'] ?? 0) ?: null
                );
                club_person_redirect($personId, 'access_override_saved');
            }

            if ($action === 'remove_access_override') {
                removePersonAccessOverride($pdo, $personId, (int) ($_POST['override_id'] ?? 0));
                club_person_redirect($personId, 'access_override_removed');
            }

            if ($action === 'save_access_roles') {
                $roleIds = isset($_POST['access_role_ids']) && is_array($_POST['access_role_ids'])
                    ? array_map('intval', $_POST['access_role_ids'])
                    : [];
                setPersonAccessRoles($pdo, $personId, $roleIds);

                // Administrator/Staff/Volunteer are shown as ordinary role
                // checkboxes here, but under the hood they're still the base
                // account role that actually gates login — keep it in sync
                // with whichever of the three (if any) got ticked, same
                // priority order accountPrimaryRole() uses. If none of the
                // three is ticked, leave the base role exactly as it was:
                // this form is where an admin looks at and edits it directly
                // now, so silently re-deriving it from legacy Club Position
                // data behind the scenes is a trap — it previously demoted
                // someone straight to 'public' (locked out of login) just
                // because their only position happened to be dated to a
                // season that wasn't current anymore.
                $account = getAccountByPersonId($pdo, $personId);
                if ($account) {
                    $checkedSlugs = array_column(array_filter(
                        getAccessRoles($pdo),
                        static fn(array $role): bool => in_array((int) $role['id'], $roleIds, true)
                    ), 'slug');
                    if (in_array('admin', $checkedSlugs, true)) {
                        $baseRole = 'admin';
                    } elseif (in_array('staff', $checkedSlugs, true)) {
                        $baseRole = 'staff';
                    } elseif (in_array('volunteer', $checkedSlugs, true)) {
                        $baseRole = 'volunteer';
                    } else {
                        $baseRole = accountPrimaryRole($pdo, (int) $account['id']);
                    }
                    setAccountRole($pdo, (int) $account['id'], $baseRole);
                }
                club_person_redirect($personId, 'access_roles_saved');
            }

            if ($action === 'disable_account' || $action === 'activate_account') {
                setAccountActive($pdo, (int) ($_POST['account_id'] ?? 0), $action === 'activate_account');
                club_person_redirect($personId, $action === 'activate_account' ? 'account_activated' : 'account_disabled');
            }

            if ($action === 'setup_link') {
                $setupLink = createAccountPasswordSetupLink($pdo, (int) ($_POST['account_id'] ?? 0), '/reset_password.php');
            }

            if ($action === 'email_setup_link') {
                $account = getAccount($pdo, (int) ($_POST['account_id'] ?? 0));
                if (!$account || trim((string) ($account['email'] ?? '')) === '') {
                    throw new RuntimeException('This account has no email to send a reset link to.');
                }
                $result = issueAccountPasswordReset($pdo, (string) $account['email'], '/reset_password.php');
                if (!empty($result['reset_url'])) {
                    // mail() isn't configured/working -- fall back to showing the link.
                    $setupLink = (string) $result['reset_url'];
                } else {
                    club_person_redirect($personId, 'reset_link_emailed');
                }
            }

            if ($action === 'set_password') {
                setAccountPassword($pdo, (int) ($_POST['account_id'] ?? 0), (string) ($_POST['new_password'] ?? ''));
                club_person_redirect($personId, 'password_set');
            }

            if ($action === 'add_relationship') {
                $relationshipId = addPersonRelationship($pdo, $personId, (int) ($_POST['dependent_person_id'] ?? 0));
                if ($isAjaxRequest) {
                    club_person_json_response([
                        'ok' => true,
                        'message' => 'Dependant relationship added.',
                        'relationship' => club_person_relationship_payload($pdo, $relationshipId, $personId),
                    ]);
                }
                club_person_redirect($personId, 'relationship_added');
            }

            if ($action === 'remove_relationship') {
                $relationshipId = (int) ($_POST['relationship_id'] ?? 0);
                removePersonRelationship($pdo, $relationshipId);
                if ($isAjaxRequest) {
                    club_person_json_response(['ok' => true, 'message' => 'Dependant relationship removed.', 'relationship_id' => $relationshipId]);
                }
                club_person_redirect($personId, 'relationship_removed');
            }
        } catch (Throwable $e) {
            if ($isAjaxRequest) {
                club_person_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            $errors[] = $e->getMessage();
        }
    }
}

$isNew = $personId <= 0;
$person = $isNew ? null : getPerson($pdo, $personId);
if (!$isNew && !$person) {
    echo '<div class="alert alert-danger">Person not found.</div>';
    require __DIR__ . '/footer.php';
    exit;
}

$account = $person ? getAccountByPersonId($pdo, $personId) : null;
$currentBaseRole = $account ? accountPrimaryRole($pdo, (int) $account['id']) : null;
$isAdminAccount = $currentBaseRole === 'admin';
$currentSeason = getCurrentSeason($pdo);
$positions = getHubPositions($pdo);
$personPositions = $person ? getPersonPositions($pdo, $personId) : [];
$currentPersonPositions = $person ? getCurrentPersonPositions($pdo, $personId) : [];
$allAccessRoles = getAccessRoles($pdo);
$personAccessOverrides = $person ? getPersonAccessOverrides($pdo, $personId) : [];
$personAffiliations = $person ? person_affiliations($pdo, $personId) : [];
$personTagKeys = $person ? person_tag_keys($pdo, $personId) : [];
$accessCapabilityCatalog = getCapabilitiesCatalog($pdo);
$accessCapabilityLabels = array_column($accessCapabilityCatalog, 'label', 'slug');
$personAccessEffective = $person ? access_explain($pdo, $personId, false) : null;
// Club roles never grant access; a role may only SUGGEST a template.
$suggestedTemplateIds = [];
if ($person) {
    $suggestStmt = $pdo->prepare('SELECT DISTINCT cr.access_template_id, cr.name
        FROM person_club_roles pcr JOIN club_roles cr ON cr.id = pcr.club_role_id
        WHERE pcr.person_id = :p AND cr.access_template_id IS NOT NULL');
    $suggestStmt->execute([':p' => $personId]);
    foreach ($suggestStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $suggestedTemplateIds[(int) $row['access_template_id']][] = (string) $row['name'];
    }
}
$personAccessRoleIds = $person ? array_column(getPersonAccessRoles($pdo, $personId), 'id') : [];
$dependents = $person ? getPersonDependents($pdo, $personId) : [];
$managers = $person ? getPersonManagers($pdo, $personId) : [];
$allPeople = $person ? getPeopleDirectory($pdo, ['status' => 'active']) : [];
$legacyHolderId = $person ? getLegacyHolderIdForPerson($pdo, $personId) : null;
$history = $person ? club_person_history_counts($pdo, $legacyHolderId, $personId) : [];
$seasonTicketHistory = $person ? club_person_season_ticket_history($pdo, $personId) : [];
$matchTicketHistory = $person ? club_person_match_ticket_history($pdo, $personId) : [];

$statusMessages = [
    'created' => 'Person created.',
    'saved' => 'Person saved.',
    'position_added' => 'Position added.',
    'position_updated' => 'Position updated.',
    'position_removed' => 'Position removed.',
    'account_saved' => 'Account saved.',
    'account_activated' => 'Account activated.',
    'account_disabled' => 'Account disabled.',
    'reset_link_emailed' => 'Password reset link emailed.',
    'password_set' => 'Password set.',
    'relationship_added' => 'Dependant relationship added.',
    'relationship_removed' => 'Relationship removed.',
    'access_roles_saved' => 'Access templates saved.',
    'person_tags_saved' => 'Tags saved.',
    'access_override_saved' => 'Individual access change saved.',
    'access_override_removed' => 'Individual access change removed.',
];
$statusMessage = $statusMessages[(string) ($_GET['status'] ?? '')] ?? '';

$data = array_merge([
    'display_name' => '',
    'date_of_birth' => '',
    'email' => '',
    'phone' => '',
    'address_line1' => '',
    'address_line2' => '',
    'town' => '',
    'postcode' => '',
    'country' => '',
    'marketing_opt_in' => 0,
    'is_active' => 1,
], $person ?: []);
?>

<nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/club_people.php">People &amp; Users</a><i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span aria-current="page"><?= $isNew ? 'Add person' : h((string) $data['display_name']) ?></span></nav>

<?php if ($statusMessage !== ''): ?><div class="alert alert-success"><?= h($statusMessage) ?></div><?php endif; ?>
<?php if ($setupLink !== ''): ?><div class="alert alert-info"><strong>Password setup link:</strong> <a href="<?= h($setupLink) ?>"><?= h($setupLink) ?></a></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($isNew): ?>
<div class="row g-4">
    <div class="col-xl-7">
        <form method="post" class="card shadow-sm border-0">
            <div class="card-header bg-transparent"><h2 class="h5 mb-0">Person Details</h2></div>
            <div class="card-body">
                <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                <input type="hidden" name="action" value="create_person">
                <div class="row g-3">
                    <div class="col-md-8"><label class="form-label">Display name</label><input class="form-control" name="display_name" value="<?= h((string) $data['display_name']) ?>" required></div>
                    <div class="col-md-4"><label class="form-label">Date of birth</label><input class="form-control" type="date" name="date_of_birth" value="<?= h((string) $data['date_of_birth']) ?>"></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" value="<?= h((string) $data['email']) ?>"><div class="form-text">Used for both club contact and Hub login, if this person gets an account.</div></div>
                    <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= h((string) $data['phone']) ?>"></div>
                    <div class="col-md-6"><label class="form-label">Address line 1</label><input class="form-control" name="address_line1" value="<?= h((string) $data['address_line1']) ?>"></div>
                    <div class="col-md-6"><label class="form-label">Address line 2</label><input class="form-control" name="address_line2" value="<?= h((string) $data['address_line2']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Town</label><input class="form-control" name="town" value="<?= h((string) $data['town']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Postcode</label><input class="form-control" name="postcode" value="<?= h((string) $data['postcode']) ?>"></div>
                    <div class="col-md-4"><label class="form-label">Country</label><input class="form-control" name="country" value="<?= h((string) $data['country']) ?>"></div>
                </div>
                <div class="d-flex flex-wrap gap-3 mt-3">
                    <div class="form-check"><input class="form-check-input" type="checkbox" id="marketingOptIn" name="marketing_opt_in" <?= (int) $data['marketing_opt_in'] === 1 ? 'checked' : '' ?>><label class="form-check-label" for="marketingOptIn">Marketing opt-in</label></div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between gap-2">
                <a class="btn btn-outline-secondary" href="/admin/club_people.php">Cancel</a>
                <button class="btn btn-brand" type="submit">Create person</button>
            </div>
        </form>
    </div>
</div>
<?php else: ?>

<ul class="nav nav-tabs mb-4" id="clubPersonTabs" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link active" id="tab-details-btn" data-bs-toggle="tab" data-bs-target="#tab-details" type="button" role="tab">Details</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-account-btn" data-bs-toggle="tab" data-bs-target="#tab-account" type="button" role="tab">Account</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-roles-btn" data-bs-toggle="tab" data-bs-target="#tab-roles" type="button" role="tab">Admin access</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-positions-btn" data-bs-toggle="tab" data-bs-target="#tab-positions" type="button" role="tab">Club Position(s)</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-relationships-btn" data-bs-toggle="tab" data-bs-target="#tab-relationships" type="button" role="tab">Relationships</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" id="tab-history-btn" data-bs-toggle="tab" data-bs-target="#tab-history" type="button" role="tab">History</button></li>
</ul>

<div class="tab-content" data-club-person-page data-person-id="<?= (int) $personId ?>" data-csrf-token="<?= h(hub_auth_csrf_token()) ?>">

    <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
        <form method="post" class="people-tab-panel" data-warn-unsaved>
            <div class="people-tab-panel__header">
                <div class="people-account__title">
                    <span class="people-account__icon"><i class="fa-solid fa-id-card" aria-hidden="true"></i></span>
                    <div>
                        <h2>Person Details</h2>
                        <p>Keep the core club record, contact details and status up to date.</p>
                    </div>
                </div>
                <div class="people-account__badges" aria-label="Person status">
                    <span class="people-status-pill <?= (int) $data['is_active'] === 1 ? 'people-status-pill--success' : 'people-status-pill--danger' ?>"><?= (int) $data['is_active'] === 1 ? 'Active person' : 'Inactive person' ?></span>
                    <?php foreach ($personAffiliations as $affiliation): ?><span class="people-status-pill people-status-pill--muted" title="<?= h($affiliation['detail'] !== '' ? $affiliation['detail'] : 'Label only — grants no access') ?>"><?= h($affiliation['label']) ?></span><?php endforeach; ?>
                </div>
            </div>
            <div class="people-tab-panel__body">
                <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                <input type="hidden" name="action" value="save_person">
                <div class="people-form-grid">
                    <div class="people-form-section people-form-section--wide">
                        <h3>Identity</h3>
                        <div class="row g-3">
                            <div class="col-md-8"><label class="form-label">Display name</label><input class="form-control" name="display_name" value="<?= h((string) $data['display_name']) ?>" required></div>
                            <div class="col-md-4"><label class="form-label">Date of birth</label><input class="form-control" type="date" name="date_of_birth" value="<?= h((string) $data['date_of_birth']) ?>"></div>
                        </div>
                    </div>
                    <div class="people-form-section">
                        <h3>Contact</h3>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label">Email</label>
                                <input class="form-control" type="email" name="email" value="<?= h((string) $data['email']) ?>">
                                <div class="form-text"><?= $account ? 'This is also this person\'s Hub login email.' : 'Used for both club contact and Hub login, if this person gets an account.' ?></div>
                            </div>
                            <div class="col-12"><label class="form-label">Phone</label><input class="form-control" name="phone" value="<?= h((string) $data['phone']) ?>"></div>
                        </div>
                    </div>
                    <div class="people-form-section">
                        <h3>Address</h3>
                        <div class="row g-3">
                            <div class="col-12"><label class="form-label">Address line 1</label><input class="form-control" name="address_line1" value="<?= h((string) $data['address_line1']) ?>"></div>
                            <div class="col-12"><label class="form-label">Address line 2</label><input class="form-control" name="address_line2" value="<?= h((string) $data['address_line2']) ?>"></div>
                            <div class="col-sm-5"><label class="form-label">Town</label><input class="form-control" name="town" value="<?= h((string) $data['town']) ?>"></div>
                            <div class="col-sm-3"><label class="form-label">Postcode</label><input class="form-control" name="postcode" value="<?= h((string) $data['postcode']) ?>"></div>
                            <div class="col-sm-4"><label class="form-label">Country</label><input class="form-control" name="country" value="<?= h((string) $data['country']) ?>"></div>
                        </div>
                    </div>
                    <div class="people-form-section people-form-section--wide">
                        <h3>Preferences</h3>
                        <div class="people-choice-grid">
                            <label class="people-choice-row" for="marketingOptIn">
                                <input class="form-check-input" type="checkbox" id="marketingOptIn" name="marketing_opt_in" <?= (int) $data['marketing_opt_in'] === 1 ? 'checked' : '' ?>>
                                <span><strong>Marketing opt-in</strong><small>Allow club marketing messages for this person.</small></span>
                            </label>
                            <label class="people-choice-row" for="personActive">
                                <input class="form-check-input" type="checkbox" id="personActive" name="is_active" <?= (int) $data['is_active'] === 1 ? 'checked' : '' ?>>
                                <span><strong>Person active</strong><small>Keep this record available in active people lists.</small></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="people-tab-actions">
                <button class="btn btn-outline-danger" type="submit" name="action" value="archive_person" data-confirm="This archives the person and disables their account. History is preserved." data-confirm-title="Archive this person?" data-confirm-action="Archive">Archive</button>
                <button class="btn btn-brand" type="submit">Save person</button>
            </div>
        </form>
    </div>

    <div class="tab-pane fade" id="tab-account" role="tabpanel">
        <section class="people-account">
            <div class="people-account__header">
                <div class="people-account__title">
                    <span class="people-account__icon"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span>
                    <div>
                        <h2>Account Details</h2>
                        <p>Manage this person's Hub login, access level and password options.</p>
                    </div>
                </div>
                <div class="people-account__badges" aria-label="Account status">
                    <?php if ($account): ?>
                        <span class="people-status-pill <?= (int) $account['is_active'] === 1 ? 'people-status-pill--success' : 'people-status-pill--danger' ?>"><?= (int) $account['is_active'] === 1 ? 'Active account' : 'Disabled account' ?></span>
                        <span class="people-status-pill <?= ($personAccessEffective['bypass'] ?? false) ? 'people-status-pill--admin' : 'people-status-pill--muted' ?>"><?= ($personAccessEffective['bypass'] ?? false) ? 'Administrator' : (($personAccessEffective['capabilities'] ?? []) !== [] ? 'Access templates' : 'No admin access') ?></span>
                    <?php else: ?>
                        <span class="people-status-pill people-status-pill--warning">No login account</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="people-account__body">
                <?php if (!$account): ?>
                    <div class="people-account-empty">
                        <div>
                            <h3>Hub access has not been created</h3>
                            <p>This person exists in the club directory but cannot sign in until a login account is created.</p>
                        </div>
                        <div class="people-account-meta">
                            <span>Login email</span>
                            <strong><?= h((string) $data['email'] ?: '—') ?></strong>
                            <small>Edit this on the Details tab.</small>
                        </div>
                    </div>
                    <?php if (trim((string) $data['email']) === ''): ?><div class="alert alert-warning py-2 mb-0">Add an email to this person on the Details tab before creating an account.</div><?php endif; ?>
                    <form method="post" class="people-account-create">
                        <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                        <input type="hidden" name="action" value="create_account">
                        <label class="people-choice-row" for="createIsAdmin">
                            <input class="form-check-input" type="checkbox" id="createIsAdmin" name="is_admin">
                            <span><strong>Site Administrator</strong><small>Full access across the Hub, bypassing Club Positions.</small></span>
                        </label>
                        <button class="btn btn-brand" type="submit" <?= trim((string) $data['email']) === '' ? 'disabled' : '' ?>><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Create Hub Account</button>
                    </form>
                <?php else: ?>
                    <div class="people-account-summary">
                        <div class="people-account-meta">
                            <span>Login email</span>
                            <strong><?= h((string) $account['email']) ?></strong>
                            <small>Edit this on the Details tab.</small>
                        </div>
                        <div class="people-account-meta">
                            <span>Last login</span>
                            <strong><?= h(club_person_format_uk_datetime((string) ($account['last_login_at'] ?? ''))) ?></strong>
                        </div>
                        <div class="people-account-meta">
                            <span>Email verified</span>
                            <strong><?= h((string) ($account['email_verified_at'] ?: 'Not verified')) ?></strong>
                        </div>
                    </div>

                    <div class="people-account-panel">
                        <div class="people-account-panel__header">
                            <h3>Access &amp; Permissions</h3>
                            <p>Control whether the account can sign in. Roles (including Administrator) are set on the Roles tab.</p>
                        </div>
                        <form method="post" class="people-account-form" id="accountAccessForm">
                            <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                            <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                            <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                            <input type="hidden" name="action" value="save_account">
                            <label class="people-choice-row" for="accountActive">
                                <input class="form-check-input" type="checkbox" id="accountActive" name="account_is_active" <?= (int) $account['is_active'] === 1 ? 'checked' : '' ?>>
                                <span><strong>Account active</strong><small>Allow this person to use their Hub login.</small></span>
                            </label>
                            <?php
                            $assignedRoleNames = array_values(array_filter(array_map(
                                static function (array $role) use ($currentBaseRole, $personAccessRoleIds): ?string {
                                    $slug = (string) $role['slug'];
                                    $has = in_array($slug, ['admin', 'staff', 'volunteer'], true)
                                        ? $currentBaseRole === $slug
                                        : in_array((int) $role['id'], $personAccessRoleIds, true);
                                    return $has ? (string) $role['name'] : null;
                                },
                                $allAccessRoles
                            )));
                            ?>
                            <div class="people-account-note">Roles: <strong><?= $assignedRoleNames !== [] ? h(implode(', ', $assignedRoleNames)) : 'None' ?></strong> — <a href="#tab-roles" data-bs-toggle="tab" data-bs-target="#tab-roles">change on the Admin access tab</a>.</div>
                        </form>
                    </div>

                    <div class="people-account-panel">
                        <div class="people-account-panel__header">
                            <h3>Password</h3>
                            <p>Send a reset link, generate one to share manually, or set a temporary password.</p>
                        </div>
                        <div class="people-password-actions">
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                <button class="btn btn-brand btn-sm" type="submit" name="action" value="email_setup_link"><i class="fa-solid fa-envelope me-1" aria-hidden="true"></i>Email reset link</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                <button class="btn btn-outline-secondary btn-sm" type="submit" name="action" value="setup_link"><i class="fa-solid fa-link me-1" aria-hidden="true"></i>Copy reset link</button>
                            </form>
                        </div>
                        <form method="post" class="people-set-password">
                            <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                            <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                            <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                            <input type="hidden" name="action" value="set_password">
                            <label class="form-label" for="accountNewPassword">Set password now</label>
                            <div class="people-set-password__row">
                                <input class="form-control" id="accountNewPassword" type="password" name="new_password" minlength="8" placeholder="At least 8 characters" required>
                                <button class="btn btn-outline-primary" type="submit">Set password</button>
                            </div>
                        </form>
                    </div>

                    <div class="people-tab-actions">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                            <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                            <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                            <button class="btn btn-outline-<?= (int) $account['is_active'] === 1 ? 'danger' : 'success' ?>" type="submit" name="action" value="<?= (int) $account['is_active'] === 1 ? 'disable_account' : 'activate_account' ?>"><?= (int) $account['is_active'] === 1 ? 'Disable account' : 'Activate account' ?></button>
                        </form>
                        <button class="btn btn-brand" type="submit" form="accountAccessForm">Save account</button>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="tab-pane fade" id="tab-roles" role="tabpanel">
        <section class="people-tab-panel">
            <div class="people-tab-panel__header">
                <div class="people-account__title">
                    <span class="people-account__icon"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span>
                    <div>
                        <h2>Admin access</h2>
                        <p>What this person can do in the Hub. It is set here only &mdash; their club roles (Treasurer, Volunteer &hellip;) never grant access. Tick the access templates they should follow (they stay linked, so editing a template changes it for everyone on it; manage them under <a href="/admin/access_roles.php">Access templates</a>), then add individual extras or removals below.</p>
                    </div>
                </div>
            </div>
            <div class="people-tab-panel__body">
                <?php if (!$account): ?>
                    <p class="text-muted mb-0">This person has no Hub login account yet — create one on the Account tab first.</p>
                <?php else: ?>
                    <form method="post" id="accessRolesForm">
                        <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                        <input type="hidden" name="action" value="save_access_roles">
                        <?php foreach ($allAccessRoles as $role): ?>
                            <?php
                            $roleSlug = (string) $role['slug'];
                            $checked = in_array($roleSlug, ['admin', 'staff', 'volunteer'], true)
                                ? $currentBaseRole === $roleSlug
                                : in_array((int) $role['id'], $personAccessRoleIds, true);
                            ?>
                            <label class="people-choice-row" for="accessRole<?= (int) $role['id'] ?>">
                                <input class="form-check-input" type="checkbox" id="accessRole<?= (int) $role['id'] ?>" name="access_role_ids[]" value="<?= (int) $role['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                                <span><strong><?= h((string) $role['name']) ?></strong><?php if ((int) $role['bypass_all'] === 1): ?><small>Full access across the Hub, bypassing everything else below.</small><?php endif; ?><?php if (isset($suggestedTemplateIds[(int) $role['id']]) && !$checked): ?><small>Suggested for their club role: <?= h(implode(', ', $suggestedTemplateIds[(int) $role['id']])) ?></small><?php endif; ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if ($currentPersonPositions !== []): ?>
                            <div class="people-account-note">Club roles held: <?= h(implode(', ', array_column($currentPersonPositions, 'name'))) ?>. These are labels only &mdash; they do not give Hub access.</div>
                        <?php endif; ?>
                    </form>
                    <div class="people-account-action-row">
                        <button class="btn btn-brand" type="submit" form="accessRolesForm">Save templates</button>
                    </div>

                    <div class="people-form-section mt-4">
                        <h3>Individual extras and removals</h3>
                        <p class="text-muted small">On top of the templates above: give this person one extra permission, or take one away from them. Optional end date &mdash; it switches itself off. Not needed for Administrators.</p>
                        <?php if ($personAccessOverrides === []): ?>
                            <p class="text-muted">None &mdash; access is exactly what the templates give.</p>
                        <?php else: ?>
                            <div class="table-responsive"><table class="table table-sm align-middle">
                                <thead><tr><th>Permission</th><th>Change</th><th>Ends</th><th>Note</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ($personAccessOverrides as $override): ?>
                                    <tr<?= (int) $override['is_expired'] === 1 ? ' class="text-muted"' : '' ?>>
                                        <td><?= h((string) $override['label']) ?></td>
                                        <td><?= $override['effect'] === 'grant' ? '<span class="badge text-bg-success">Extra</span>' : '<span class="badge text-bg-danger">Removed</span>' ?></td>
                                        <td><?= $override['expires_at'] ? h((string) $override['expires_at']) . ((int) $override['is_expired'] === 1 ? ' <span class="badge text-bg-secondary">expired</span>' : '') : 'No end date' ?></td>
                                        <td><?= h((string) ($override['note'] ?? '')) ?></td>
                                        <td class="text-end">
                                            <form method="post" class="d-inline" onsubmit="return confirm('Remove this individual change?');">
                                                <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                                <input type="hidden" name="action" value="remove_access_override">
                                                <input type="hidden" name="override_id" value="<?= (int) $override['id'] ?>">
                                                <button class="btn btn-outline-secondary btn-sm" type="submit">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table></div>
                        <?php endif; ?>
                        <form method="post" class="row g-2 align-items-end">
                            <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                            <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                            <input type="hidden" name="action" value="add_access_override">
                            <div class="col-md-4"><label class="form-label small" for="ovCap">Permission</label>
                                <select class="form-select" id="ovCap" name="override_capability_id" required>
                                    <option value="">Choose&hellip;</option>
                                    <?php foreach ($accessCapabilityCatalog as $cap): ?><option value="<?= (int) $cap['id'] ?>"><?= h((string) $cap['label']) ?></option><?php endforeach; ?>
                                </select></div>
                            <div class="col-md-2"><label class="form-label small" for="ovEffect">Change</label>
                                <select class="form-select" id="ovEffect" name="override_effect"><option value="grant">Give extra</option><option value="deny">Take away</option></select></div>
                            <div class="col-md-2"><label class="form-label small" for="ovEnds">Ends (optional)</label>
                                <input class="form-control" type="date" id="ovEnds" name="override_expires_at"></div>
                            <div class="col-md-3"><label class="form-label small" for="ovNote">Note (optional)</label>
                                <input class="form-control" type="text" id="ovNote" name="override_note" maxlength="255" placeholder="Why?"></div>
                            <div class="col-md-1"><button class="btn btn-brand w-100" type="submit">Add</button></div>
                        </form>
                    </div>

                    <div class="people-form-section mt-4">
                        <h3>Club labels</h3>
                        <p class="text-muted small">Committee, football staff, volunteer and season ticket holder are worked out automatically from their club roles and tickets. These extra tags are labels only &mdash; they never give access.</p>
                        <form method="post" class="d-flex flex-wrap gap-3 align-items-center">
                            <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                            <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                            <input type="hidden" name="action" value="save_person_tags">
                            <?php foreach (PERSON_TAG_CATALOG as $tagKey => $tagLabel): ?>
                                <label class="form-check"><input class="form-check-input" type="checkbox" name="person_tags[]" value="<?= h($tagKey) ?>" <?= in_array($tagKey, $personTagKeys, true) ? 'checked' : '' ?>> <span class="form-check-label"><?= h($tagLabel) ?></span></label>
                            <?php endforeach; ?>
                            <button class="btn btn-outline-secondary btn-sm" type="submit">Save tags</button>
                        </form>
                    </div>

                    <div class="people-form-section mt-4">
                        <h3>What they can actually do</h3>
                        <?php if ($personAccessEffective['bypass'] ?? false): ?>
                            <p class="mb-0"><span class="badge text-bg-primary">Administrator</span> Full access across the Hub.</p>
                        <?php elseif (($personAccessEffective['capabilities'] ?? []) === []): ?>
                            <p class="text-muted mb-0">No admin areas. (They can still sign in if they follow a template such as Volunteer, but no admin pages are open to them.)</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($personAccessEffective['sources'] as $slug => $srcs): ?>
                                    <li><strong><?= h((string) ($accessCapabilityLabels[$slug] ?? $slug)) ?></strong> <small class="text-muted">&mdash; <?= h(implode(', ', $srcs)) ?></small></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if (!empty($personAccessEffective['denied'])): ?>
                            <p class="small text-muted mt-2 mb-0">Taken away: <?= h(implode(', ', array_map(static fn($s) => (string) ($accessCapabilityLabels[$s] ?? $s), $personAccessEffective['denied']))) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <div class="tab-pane fade" id="tab-positions" role="tabpanel">
        <section class="people-tab-panel">
            <div class="people-tab-panel__header">
                <div class="people-account__title">
                    <span class="people-account__icon"><i class="fa-solid fa-briefcase" aria-hidden="true"></i></span>
                    <div>
                        <h2>Club Position(s)</h2>
                        <p>Track club responsibilities by start and end date, including the Hub access they grant.</p>
                    </div>
                </div>
                <div class="people-account__badges" aria-label="Position count">
                    <span class="people-status-pill people-status-pill--muted"><?= count($personPositions) ?> position<?= count($personPositions) === 1 ? '' : 's' ?></span>
                </div>
            </div>
            <div class="people-tab-panel__body">
                <div class="people-account-note">Use start and end dates to keep an accurate history. Access is active when today's date falls within the position date range.</div>
                <div class="people-form-section people-add-inline-panel">
                    <h3>Add a position</h3>
                    <form method="post" class="people-add-position-form" data-people-ajax="position-add">
                        <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                        <input type="hidden" name="ajax_action" value="add_position">
                        <div>
                            <label class="form-label">Position</label>
                            <select class="form-select" name="position_id" required>
                                <?php foreach ($positions as $position): ?>
                                    <option value="<?= (int) $position['id'] ?>"><?= h((string) $position['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Start date</label>
                            <input class="form-control" type="date" name="start_date" value="<?= h((string) ($currentSeason['start_date'] ?? date('Y-m-d'))) ?>" required>
                        </div>
                        <div>
                            <label class="form-label">End date</label>
                            <input class="form-control" type="date" name="end_date" value="<?= h((string) ($currentSeason['end_date'] ?? '')) ?>">
                        </div>
                        <div>
                            <label class="form-label">Notes</label>
                            <input class="form-control" name="notes" placeholder="Optional">
                        </div>
                        <button class="btn btn-brand" type="submit">Add position</button>
                    </form>
                </div>

                <div class="people-table-panel">
                    <div class="table-responsive">
                        <table class="table align-middle hub-data-table people-inline-table" data-people-position-table>
                            <thead>
                                <tr>
                                    <th>Position</th>
                                    <th>Start date</th>
                                    <th>End date</th>
                                    <th>Notes</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($personPositions as $pp): ?>
                                    <?php $positionFormId = 'positionEditForm' . (int) $pp['id']; ?>
                                    <tr data-position-row="<?= (int) $pp['id'] ?>">
                                        <td data-label="Position">
                                            <span data-view-field="position"><?= h((string) $pp['position_name']) ?></span>
                                            <select class="form-select form-select-sm people-inline-edit-control" name="position_id" form="<?= h($positionFormId) ?>" data-edit-field="position_id" hidden>
                                                <?php foreach ($positions as $position): ?>
                                                    <option value="<?= (int) $position['id'] ?>" <?= (int) $position['id'] === (int) $pp['position_id'] ? 'selected' : '' ?>><?= h((string) $position['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td data-label="Start date">
                                            <span data-view-field="start_date"><?= h(app_format_uk_date((string) ($pp['start_date'] ?? ''), '—')) ?></span>
                                            <input class="form-control form-control-sm people-inline-edit-control" type="date" name="start_date" form="<?= h($positionFormId) ?>" data-edit-field="start_date" value="<?= h((string) ($pp['start_date'] ?? '')) ?>" required hidden>
                                        </td>
                                        <td data-label="End date">
                                            <span data-view-field="end_date"><?= h(app_format_uk_date((string) ($pp['end_date'] ?? ''), 'Ongoing')) ?></span>
                                            <input class="form-control form-control-sm people-inline-edit-control" type="date" name="end_date" form="<?= h($positionFormId) ?>" data-edit-field="end_date" value="<?= h((string) ($pp['end_date'] ?? '')) ?>" hidden>
                                        </td>
                                        <td data-label="Notes">
                                            <span data-view-field="notes"><?= h((string) ($pp['notes'] ?? '') ?: '—') ?></span>
                                            <input class="form-control form-control-sm people-inline-edit-control" name="notes" form="<?= h($positionFormId) ?>" data-edit-field="notes" value="<?= h((string) ($pp['notes'] ?? '')) ?>" placeholder="Optional" hidden>
                                        </td>
                                        <td class="text-end people-inline-actions" data-label="Actions">
                                            <form method="post" id="<?= h($positionFormId) ?>" data-people-ajax="position-update" hidden>
                                                <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                                <input type="hidden" name="ajax_action" value="update_position">
                                                <input type="hidden" name="position_row_id" value="<?= (int) $pp['id'] ?>">
                                            </form>
                                            <button class="btn btn-outline-primary btn-sm" type="button" data-position-edit>Edit</button>
                                            <button class="btn btn-brand btn-sm" type="submit" form="<?= h($positionFormId) ?>" data-position-save hidden>Save</button>
                                            <button class="btn btn-outline-secondary btn-sm" type="button" data-position-cancel hidden>Cancel</button>
                                            <form method="post" class="people-inline-delete-form" data-people-ajax="position-delete" data-confirm="This permanently removes this position entry." data-confirm-title="Remove this position?" data-confirm-action="Remove">
                                                <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                                <input type="hidden" name="ajax_action" value="delete_position">
                                                <input type="hidden" name="position_row_id" value="<?= (int) $pp['id'] ?>">
                                                <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr data-people-empty-positions <?= $personPositions === [] ? '' : 'hidden' ?>><td colspan="5"><div class="people-empty-state">No positions on file yet.</div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <div class="tab-pane fade" id="tab-relationships" role="tabpanel">
        <section class="people-tab-panel">
            <div class="people-tab-panel__header">
                <div class="people-account__title">
                    <span class="people-account__icon"><i class="fa-solid fa-people-arrows" aria-hidden="true"></i></span>
                    <div>
                        <h2>Relationships / Dependants</h2>
                        <p>Connect people who manage or are managed by this record.</p>
                    </div>
                </div>
                <div class="people-account__badges" aria-label="Relationship counts">
                    <span class="people-status-pill people-status-pill--muted"><?= count($dependents) ?> dependant<?= count($dependents) === 1 ? '' : 's' ?></span>
                </div>
            </div>
            <div class="people-tab-panel__body">
                <div class="people-relationship-grid">
                    <div class="people-form-section">
                        <h3>Managed by</h3>
                        <?php if ($managers): ?>
                            <div class="people-relationship-list">
                                <?php foreach ($managers as $manager): ?>
                                    <div class="people-relationship-item"><span><?= h((string) $manager['display_name']) ?></span></div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="people-empty-state">No manager.</div>
                        <?php endif; ?>
                    </div>
                    <div class="people-form-section people-add-inline-panel">
                        <h3>Add dependant</h3>
                        <form method="post" class="people-add-relationship-form" data-people-ajax="relationship-add">
                            <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                            <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                            <input type="hidden" name="ajax_action" value="add_relationship">
                            <div>
                                <label class="form-label">Person</label>
                                <select class="form-select" name="dependent_person_id" required>
                                    <option value="">Choose person</option>
                                    <?php foreach ($allPeople as $candidate): ?>
                                        <?php if ((int) $candidate['id'] === $personId) { continue; } ?>
                                        <option value="<?= (int) $candidate['id'] ?>"><?= h((string) $candidate['display_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button class="btn btn-outline-primary" type="submit">Add dependant</button>
                        </form>
                    </div>
                </div>
                <div class="people-table-panel">
                    <div class="table-responsive">
                        <table class="table align-middle hub-data-table people-inline-table" data-people-relationship-table>
                            <thead>
                                <tr>
                                    <th>Dependant</th>
                                    <th>Email</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dependents as $dependent): ?>
                                    <?php $relationshipId = (int) ($dependent['relationship_id'] ?? $dependent['id']); ?>
                                    <tr data-relationship-row="<?= $relationshipId ?>">
                                        <td data-label="Dependant"><?= h((string) $dependent['display_name']) ?></td>
                                        <td data-label="Email"><?= h((string) ($dependent['email'] ?? '') ?: '—') ?></td>
                                        <td class="text-end people-inline-actions" data-label="Actions">
                                            <form method="post" class="people-inline-delete-form" data-people-ajax="relationship-delete" data-confirm="This removes the dependant relationship. Person records and ticket history are preserved." data-confirm-title="Remove this dependant?" data-confirm-action="Remove">
                                                <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                                <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                                <input type="hidden" name="ajax_action" value="remove_relationship">
                                                <input type="hidden" name="relationship_id" value="<?= $relationshipId ?>">
                                                <button class="btn btn-outline-danger btn-sm" type="submit">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr data-people-empty-relationships <?= $dependents === [] ? '' : 'hidden' ?>><td colspan="3"><div class="people-empty-state">No dependants.</div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <div class="tab-pane fade" id="tab-history" role="tabpanel">
        <section class="people-tab-panel">
            <div class="people-tab-panel__header">
                <div class="people-account__title">
                    <span class="people-account__icon"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
                    <div>
                        <h2>Existing Club History</h2>
                        <p>Review ticket packages, match orders and admission records linked to this person.</p>
                    </div>
                </div>
            </div>
            <div class="people-tab-panel__body">
                <div class="people-history-stats">
                    <div><span>Season tickets</span><strong><?= (int) $history['season_passes'] ?></strong></div>
                    <div><span>Match ticket orders</span><strong><?= (int) $history['match_ticket_orders'] ?></strong></div>
                    <div><span>Admissions</span><strong><?= (int) $history['admissions'] ?></strong></div>
                </div>
                <div class="people-account-note">To cancel, refund or edit a ticket, open it from the list below. Those actions live on the season ticket and match ticket pages.</div>
                <div class="people-history-grid">
                    <div class="people-form-section">
                        <h3>Season Tickets</h3>
                        <?php if ($seasonTicketHistory): ?>
                            <div class="people-history-list">
                                <?php foreach ($seasonTicketHistory as $order): ?>
                                    <a class="people-history-item" href="/admin/season_ticket_orders.php?id=<?= (int) $order['order_id'] ?>">
                                        <span><?= h((string) $order['season_name']) ?> <?= h((string) $order['type_name']) ?><small><?= gbp((float) $order['line_total']) ?></small></span>
                                        <span class="badge hub-status <?= $order['entitlement_status'] === 'active' ? 'text-bg-success' : ($order['entitlement_status'] === 'cancelled' ? 'text-bg-secondary' : 'text-bg-warning') ?>"><?= h((string) $order['entitlement_status']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="people-empty-state">No season ticket history.</div>
                        <?php endif; ?>
                    </div>

                    <div class="people-form-section">
                        <h3>Match Ticket Orders</h3>
                        <?php if ($matchTicketHistory): ?>
                            <div class="people-history-list">
                                <?php foreach ($matchTicketHistory as $order): ?>
                                    <a class="people-history-item" href="/admin/ticket_orders.php?id=<?= (int) $order['id'] ?>">
                                        <span>vs <?= h((string) $order['opponent']) ?><small><?= h(date('d/m/Y', strtotime((string) $order['match_date']))) ?> · <?= (int) $order['ticket_count'] ?> ticket<?= (int) $order['ticket_count'] === 1 ? '' : 's' ?></small></span>
                                        <span class="badge <?= (string) $order['status'] === 'complete' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= h((string) $order['status']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="people-empty-state">No match ticket history.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>

</div>
<?php endif; ?>

<div class="card shadow-sm border-0 mt-4">
    <div class="card-body small text-muted">
        People are the master records. Account Details control login access, and full access is either Site Administrator or driven by currently active Club Position(s). Season tickets are ticket packages attached to people.
    </div>
</div>

<script src="/admin/assets/js/club-person.js?v=<?= (int) (@filemtime(__DIR__ . '/assets/js/club-person.js') ?: time()) ?>" defer></script>
<?php require_once __DIR__ . '/footer.php'; ?>
