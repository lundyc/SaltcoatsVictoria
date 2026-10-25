<?php
declare(strict_types=1);

$pageStyles = ['reports.css', 'people.css'];
$pageHero = [
    'eyebrow' => 'Administration',
    'title' => empty($_GET['id']) ? 'Add New User' : 'Edit User',
    'subtitle' => 'Contact details, login, role, club positions, dependants and tickets for one person.',
    'actions' => [['label' => 'All users', 'href' => '/admin/club_people.php', 'class' => 'btn btn-outline-light btn-sm']],
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
require_once __DIR__ . '/lib/member_google.php';

if (!hub_auth_has_capability('admin_settings')) {
    http_response_code(403);
    echo '<div><div class="alert alert-danger">You do not have permission to manage people.</div></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$personId = (int) ($_GET['id'] ?? $_POST['person_id'] ?? 0);
$errors = [];
$setupLink = (string) ($_SESSION['club_person_setup_link'] ?? '');
unset($_SESSION['club_person_setup_link']);
$submittedPersonData = [];
$emailError = false;

function club_person_redirect(int $personId, string $status): void
{
    $tab = preg_replace('/[^a-z-]/', '', (string) ($_POST['return_tab'] ?? '')) ?: '';
    header('Location: /admin/club_person.php?id=' . $personId . '&status=' . rawurlencode($status) . ($tab !== '' ? '#tab-' . $tab : ''));
    exit;
}

/** One radio card per role. Shared by the add-person form and the profile's Role card. */
function club_person_role_cards(array $roles, int $selectedId, array $summaries, array $descriptions, string $formId = ''): string
{
    $html = '';
    foreach ($roles as $role) {
        $id = (int) $role['id'];
        $slug = (string) $role['slug'];
        $summary = $summaries[$slug] ?? ($descriptions[$id] ?? '');
        $legacy = in_array($slug, ['staff', 'volunteer'], true) ? ' <em>(legacy)</em>' : '';
        $html .= '<label class="people-role-option">'
            . '<input class="form-check-input" type="radio" name="access_template_id" value="' . $id . '"'
            . ' data-bypass="' . (int) $role['bypass_all'] . '"' . ($id === $selectedId ? ' checked' : '')
            . ($formId !== '' ? ' form="' . h($formId) . '"' : '') . ' required>'
            . '<span><strong>' . h((string) $role['name']) . $legacy . '</strong>'
            . '<small>' . h($summary) . '</small></span>'
            . '</label>';
    }
    return $html;
}

/** Emails a sign-in link. If mail cannot be sent, the link is kept for the next screen to show. */
function club_person_send_sign_in_link(PDO $pdo, int $personId): string
{
    $account = club_person_require_account($pdo, $personId);
    $result = issueAccountPasswordReset($pdo, (string) $account['email'], '/reset_password.php');
    if (!empty($result['reset_url'])) {
        $_SESSION['club_person_setup_link'] = (string) $result['reset_url'];
        return 'created_with_link';
    }
    return 'created_invited';
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

function club_person_require_account(PDO $pdo, int $personId): array
{
    $account = getAccountByPersonId($pdo, $personId);
    if (!$account) {
        throw new RuntimeException('This person does not have a login. Add an email and save their details first.');
    }
    return $account;
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
                $pdo->beginTransaction();
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
                reconcilePersonLogin($pdo, $newPersonId, (string) ($_POST['email'] ?? ''));
                $roleTemplateId = hub_auth_is_admin() ? (int) ($_POST['access_template_id'] ?? 0) : 0;
                if ($roleTemplateId > 0) {
                    $roleTemplate = getAccessRole($pdo, $roleTemplateId);
                    if (($roleTemplate['slug'] ?? '') !== 'member' && trim((string) ($_POST['email'] ?? '')) === '') {
                        throw new InvalidArgumentException('Add an email address so they can sign in with this role.');
                    }
                    person_apply_access_role($pdo, $newPersonId, $roleTemplateId);
                }
                $pdo->commit();
                $status = 'created';
                if (hub_auth_is_admin() && isset($_POST['send_invite']) && getAccountByPersonId($pdo, $newPersonId)) {
                    $status = club_person_send_sign_in_link($pdo, $newPersonId);
                }
                club_person_redirect($newPersonId, $status);
            }

            if ($personId <= 0 || !getPerson($pdo, $personId)) {
                throw new RuntimeException('Person not found.');
            }

            if ($action === 'save_person') {
                $pdo->beginTransaction();
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
                reconcilePersonLogin($pdo, $personId, (string) ($_POST['email'] ?? ''));
                $roleTemplateId = hub_auth_is_admin() ? (int) ($_POST['access_template_id'] ?? 0) : 0;
                if ($roleTemplateId > 0) {
                    $roleTemplate = getAccessRole($pdo, $roleTemplateId);
                    if (($roleTemplate['slug'] ?? '') !== 'member' && !getAccountByPersonId($pdo, $personId)) {
                        throw new InvalidArgumentException('Add an email address so they can sign in with this role.');
                    }
                    person_apply_access_role($pdo, $personId, $roleTemplateId);
                }
                $pdo->commit();
                club_person_redirect($personId, 'saved');
            }

            if ($action === 'archive_person') {
                $pdo->beginTransaction();
                assertPersonMayLoseLoginOrAdministrator($pdo, $personId);
                archivePerson($pdo, $personId);
                $pdo->commit();
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
                    (string) ($_POST['end_date'] ?? ''),
                    $personId
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
                deletePersonPosition($pdo, $positionRowId, $personId);
                if ($isAjaxRequest) {
                    club_person_json_response(['ok' => true, 'message' => 'Position removed.', 'position_row_id' => $positionRowId]);
                }
                club_person_redirect($personId, 'position_removed');
            }

            if ($action === 'save_person_tags') {
                set_person_tags($pdo, $personId, isset($_POST['person_tags']) && is_array($_POST['person_tags']) ? $_POST['person_tags'] : []);
                club_person_redirect($personId, 'person_tags_saved');
            }

            if ($action === 'add_access_override') {
                if (!hub_auth_is_admin()) {
                    throw new RuntimeException('Only an Administrator can change individual permissions.');
                }
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
                if (!hub_auth_is_admin()) {
                    throw new RuntimeException('Only an Administrator can change individual permissions.');
                }
                removePersonAccessOverride($pdo, $personId, (int) ($_POST['override_id'] ?? 0));
                club_person_redirect($personId, 'access_override_removed');
            }

            if ($action === 'save_access_roles') {
                if (!hub_auth_is_admin()) {
                    throw new RuntimeException('Only an Administrator can change access roles.');
                }
                $pdo->beginTransaction();
                person_apply_access_role($pdo, $personId, (int) ($_POST['access_template_id'] ?? 0));
                $pdo->commit();
                club_person_redirect($personId, 'access_roles_saved');
            }

            if ($action === 'toggle_login') {
                if (!hub_auth_is_admin()) {
                    throw new RuntimeException('Only an Administrator can enable or disable a login.');
                }
                $pdo->beginTransaction();
                setPersonLoginEnabled($pdo, $personId, (string) ($_POST['enabled'] ?? '') === '1');
                $pdo->commit();
                club_person_redirect($personId, (string) ($_POST['enabled'] ?? '') === '1' ? 'account_activated' : 'account_disabled');
            }

            if ($action === 'google_welcome_link') {
                if (!hub_auth_is_admin()) {
                    throw new RuntimeException('Only an Administrator can create account linking links.');
                }
                $targetAccount = club_person_require_account($pdo, $personId);
                $accountId = (int) $targetAccount['id'];
                if (!member_google_configured()) {
                    throw new RuntimeException('Google sign-in is not configured.');
                }
                $setupLink = member_create_welcome_link($pdo, $accountId);
            }

            if ($action === 'email_setup_link') {
                if (!hub_auth_is_admin()) {
                    throw new RuntimeException('Only an Administrator can send sign-in links.');
                }
                $account = club_person_require_account($pdo, $personId);
                if (trim((string) ($account['email'] ?? '')) === '') {
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
                if (!hub_auth_is_admin()) {
                    throw new RuntimeException('Only an Administrator can set another person\'s password.');
                }
                $targetAccount = club_person_require_account($pdo, $personId);
                setAccountPassword($pdo, (int) $targetAccount['id'], (string) ($_POST['new_password'] ?? ''));
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
                removePersonRelationship($pdo, $relationshipId, $personId);
                if ($isAjaxRequest) {
                    club_person_json_response(['ok' => true, 'message' => 'Dependant relationship removed.', 'relationship_id' => $relationshipId]);
                }
                club_person_redirect($personId, 'relationship_removed');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($isAjaxRequest) {
                club_person_json_response(['ok' => false, 'error' => $e->getMessage()], 400);
            }
            $errors[] = $e->getMessage();
            if (in_array($action, ['create_person', 'save_person'], true)) {
                $submittedPersonData = [
                    'display_name' => (string) ($_POST['display_name'] ?? ''),
                    'date_of_birth' => (string) ($_POST['date_of_birth'] ?? ''),
                    'email' => (string) ($_POST['email'] ?? ''),
                    'phone' => (string) ($_POST['phone'] ?? ''),
                    'address_line1' => (string) ($_POST['address_line1'] ?? ''),
                    'address_line2' => (string) ($_POST['address_line2'] ?? ''),
                    'town' => (string) ($_POST['town'] ?? ''),
                    'postcode' => (string) ($_POST['postcode'] ?? ''),
                    'country' => (string) ($_POST['country'] ?? ''),
                    'marketing_opt_in' => isset($_POST['marketing_opt_in']) ? 1 : 0,
                    'is_active' => $action === 'create_person' || isset($_POST['is_active']) ? 1 : 0,
                ];
                $emailError = stripos($e->getMessage(), 'email') !== false;
            }
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
$googleIdentity = $account ? member_google_identity_for_account($pdo, (int) $account['id']) : null;
$currentSeason = getCurrentSeason($pdo);
$positions = getHubPositions($pdo);
$personPositions = $person ? getPersonPositions($pdo, $personId) : [];
$currentPersonPositions = $person ? getCurrentPersonPositions($pdo, $personId) : [];
$currentAccessRole = $person ? getPersonPrimaryAccessRole($pdo, $personId) : null;
$currentAccessRoleId = (int) ($currentAccessRole['id'] ?? 0);
// Legacy roles are hidden from the picker, but a person who already holds one still sees it,
// so saving any other change never silently moves them to the first option.
$allAccessRoles = array_values(array_filter(
    getAccessRoles($pdo),
    static fn(array $role): bool => !in_array((string) $role['slug'], ['staff', 'volunteer'], true)
        || (int) $role['id'] === $currentAccessRoleId
));
$defaultAccessRoleId = 0;
foreach ($allAccessRoles as $role) {
    if ((string) $role['slug'] === 'member') {
        $defaultAccessRoleId = (int) $role['id'];
    }
}
$roleSelectedId = $currentAccessRoleId ?: $defaultAccessRoleId;
// Plain-English description of each role, shown on its card. Keep in step with the role's capabilities.
$accessRoleSummaries = [
    'member' => "Signs in to the members' area only. No admin pages.",
    'admin' => "Full access to every admin area, including other people's roles and logins. Give this sparingly.",
    'treasurer' => 'Finance: view, record and refund payments. Also sponsorship, fundraising and events.',
    'chairman' => 'Broad oversight: matchday, club setup, finance, events, publishing and content.',
    'chairman_club_oversight' => 'Everything a Chairman can do, plus sponsorship, fundraising and secretary admin.',
    'football_ops' => 'Matchday: fixtures, lineups and players. Also club setup (seasons, opponents, venues).',
    'media_merchandise' => 'Public website, club shop and content & social media.',
    'sponsorship_manager' => 'Sponsors: packages, agreements, assigning sponsors to players, and recording sponsor payments. No refunds.',
    'content_social' => 'Club shop, publishing (league table) and content & social media.',
    'secretary_ops' => 'Club admin: discipline, registrations, correspondence and governance. Also events.',
    'social_media_admin' => 'Social media and the public website, plus the club shop, publishing and fundraising.',
    'staff' => 'Gate scanning: checks tickets in at the gate. Legacy role.',
    'volunteer' => 'Gate scanning: checks tickets in at the gate. Legacy role.',
];
$canManageAdminAccess = hub_auth_is_admin();
$personAccessOverrides = $person ? getPersonAccessOverrides($pdo, $personId) : [];
$personAffiliations = $person ? person_affiliations($pdo, $personId) : [];
$personTagKeys = $person ? person_tag_keys($pdo, $personId) : [];
$accessCapabilityCatalog = getCapabilitiesCatalog($pdo);
$accessCapabilityLabels = array_column($accessCapabilityCatalog, 'label', 'slug');
$accessRoleDescriptions = [];
foreach ($allAccessRoles as $accessRole) {
    if ((int) $accessRole['bypass_all'] === 1) {
        $accessRoleDescriptions[(int) $accessRole['id']] = 'Full access to every administration area.';
        continue;
    }
    $roleCapabilities = array_map(
        static fn(string $slug): string => (string) ($accessCapabilityLabels[$slug] ?? $slug),
        getAccessRoleCapabilitySlugs($pdo, (int) $accessRole['id'])
    );
    $accessRoleDescriptions[(int) $accessRole['id']] = $roleCapabilities === []
        ? 'Can use the members area but cannot access administration.'
        : implode(', ', $roleCapabilities) . '.';
}
$personAccessEffective = $person ? access_explain($pdo, $personId, false) : null;
// Club positions never grant access; a role may only SUGGEST a role.
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
$dependents = $person ? getPersonDependents($pdo, $personId) : [];
$managers = $person ? getPersonManagers($pdo, $personId) : [];
$allPeople = $person ? getPeopleDirectory($pdo, ['status' => 'active']) : [];
$legacyHolderId = $person ? getLegacyHolderIdForPerson($pdo, $personId) : null;
$history = $person ? club_person_history_counts($pdo, $legacyHolderId, $personId) : [];
$seasonTicketHistory = $person ? club_person_season_ticket_history($pdo, $personId) : [];
$matchTicketHistory = $person ? club_person_match_ticket_history($pdo, $personId) : [];

$statusMessages = [
    'created' => 'Person created.',
    'created_invited' => 'Person created and a sign-in link emailed.',
    'created_with_link' => 'Person created. The sign-in email could not be sent, so copy the link below.',
    'saved' => 'Person saved.',
    'position_added' => 'Position added.',
    'position_updated' => 'Position updated.',
    'position_removed' => 'Position removed.',
    'account_activated' => 'Account activated.',
    'account_disabled' => 'Account disabled.',
    'reset_link_emailed' => 'Sign-in link emailed.',
    'password_set' => 'Password set.',
    'relationship_added' => 'Dependant relationship added.',
    'relationship_removed' => 'Relationship removed.',
    'access_roles_saved' => 'Role saved.',
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
], $person ?: [], $submittedPersonData);
?>


<?php if ($statusMessage !== ''): ?><div class="alert alert-success"><?= h($statusMessage) ?></div><?php endif; ?>
<?php if ($setupLink !== ''): ?><div class="alert alert-info"><strong>Sign-in setup link:</strong> <a href="<?= h($setupLink) ?>"><?= h($setupLink) ?></a><div class="small mt-1">This one-time link expires after seven days. The person can use it to link Google or set a password.</div></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger" id="personErrorSummary" role="alert" tabindex="-1"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($isNew): ?>
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
    <input type="hidden" name="action" value="create_person">

    <section class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-transparent"><h2 class="h5 mb-0">Name and contact</h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="newPersonName">Name</label>
                    <input class="form-control" id="newPersonName" name="display_name" value="<?= h((string) $data['display_name']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="newPersonDob">Date of birth</label>
                    <input class="form-control" id="newPersonDob" type="date" name="date_of_birth" value="<?= h((string) $data['date_of_birth']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="newPersonEmail">Email</label>
                    <input class="form-control<?= $emailError ? ' is-invalid' : '' ?>" id="newPersonEmail" type="email" name="email" value="<?= h((string) $data['email']) ?>" <?= $emailError ? 'aria-invalid="true" aria-describedby="newPersonEmailError newPersonEmailHelp"' : 'aria-describedby="newPersonEmailHelp"' ?>>
                    <?php if ($emailError): ?><div class="invalid-feedback" id="newPersonEmailError">That email could not be used. Check it or use a different login email.</div><?php endif; ?>
                    <div class="form-text" id="newPersonEmailHelp">Adding an email gives this person a Member login. It does not give them admin access.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="newPersonPhone">Phone</label>
                    <input class="form-control" id="newPersonPhone" name="phone" value="<?= h((string) $data['phone']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="newPersonAddress1">Address line 1</label>
                    <input class="form-control" id="newPersonAddress1" name="address_line1" value="<?= h((string) $data['address_line1']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="newPersonAddress2">Address line 2</label>
                    <input class="form-control" id="newPersonAddress2" name="address_line2" value="<?= h((string) $data['address_line2']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="newPersonTown">Town</label>
                    <input class="form-control" id="newPersonTown" name="town" value="<?= h((string) $data['town']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="newPersonPostcode">Postcode</label>
                    <input class="form-control" id="newPersonPostcode" name="postcode" value="<?= h((string) $data['postcode']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="newPersonCountry">Country</label>
                    <input class="form-control" id="newPersonCountry" name="country" value="<?= h((string) $data['country']) ?>">
                </div>
            </div>
            <div class="form-check form-switch mt-3">
                <input class="form-check-input" type="checkbox" role="switch" id="marketingOptIn" name="marketing_opt_in" <?= (int) $data['marketing_opt_in'] === 1 || ($isNew && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') ? 'checked' : '' ?>>
                <label class="form-check-label" for="marketingOptIn">Marketing opt-in <span class="d-block small text-muted">Allow club marketing messages for this person.</span></label>
            </div>
        </div>
    </section>

    <section class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-transparent"><h2 class="h5 mb-0">Login and role</h2></div>
        <div class="card-body">
            <?php if (hub_auth_is_admin()): ?>
                <p class="text-muted small">Adding an email creates their login. Choose one role for them.</p>
                <div class="people-role-options mb-3" data-access-role-options data-initial-value="" data-person-name="this person">
                    <?= club_person_role_cards($allAccessRoles, (int) ($_POST['access_template_id'] ?? $roleSelectedId), $accessRoleSummaries, $accessRoleDescriptions) ?>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="newPersonSendInvite" name="send_invite" checked>
                    <label class="form-check-label" for="newPersonSendInvite">Email them a sign-in link now <span class="d-block small text-muted">Needs an email address. If the email can't be sent, the link is shown on the next screen.</span></label>
                </div>
            <?php else: ?>
                <p class="text-muted small mb-0">Adding an email creates their login. Ask an Administrator to choose their role and send their sign-in link.</p>
            <?php endif; ?>
        </div>
    </section>

    <div class="hub-actions justify-content-end">
        <a class="btn btn-outline-secondary" href="/admin/club_people.php">Cancel</a>
        <button class="btn btn-brand" type="submit">Add New User</button>
    </div>
</form>
<?php else: ?>

<div data-club-person-page data-person-id="<?= (int) $personId ?>" data-csrf-token="<?= h(hub_auth_csrf_token()) ?>">

    <div class="mb-4" id="tab-details">
        <form method="post" id="personForm" class="people-tab-panel" data-warn-unsaved>
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
                            <div class="col-md-8"><label class="form-label">Name</label><input class="form-control" name="display_name" value="<?= h((string) $data['display_name']) ?>" required></div>
                            <div class="col-md-4"><label class="form-label">Date of birth</label><input class="form-control" type="date" name="date_of_birth" value="<?= h((string) $data['date_of_birth']) ?>"></div>
                        </div>
                    </div>
                    <div class="people-form-section">
                        <h3>Contact</h3>
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label" for="personEmail">Email</label>
                                <input class="form-control<?= $emailError ? ' is-invalid' : '' ?>" id="personEmail" type="email" name="email" value="<?= h((string) $data['email']) ?>" <?= $emailError ? 'aria-invalid="true" aria-describedby="personEmailError personEmailHelp"' : 'aria-describedby="personEmailHelp"' ?>>
                                <?php if ($emailError): ?><div class="invalid-feedback" id="personEmailError">That email could not be used. Check it or use a different login email.</div><?php endif; ?>
                                <div class="form-text" id="personEmailHelp">Adding an email gives this person a Member login. It does not give them admin access.<?= $account ? ' Changing it also changes the email they use to sign in.' : '' ?></div>
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
                                <span class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" id="marketingOptIn" name="marketing_opt_in" <?= (int) $data['marketing_opt_in'] === 1 ? 'checked' : '' ?>></span>
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
        </form>

    </div>

    <div class="mb-4" id="tab-account">
        <section class="people-tab-panel">
            <div class="people-tab-panel__header">
                <div class="people-account__title">
                    <span class="people-account__icon"><i class="fa-solid fa-user-shield" aria-hidden="true"></i></span>
                    <div>
                        <h2>Account &amp; role</h2>
                        <p>Their sign-in, and the one role that decides which admin areas they can open. Positions and tags never change access.</p>
                    </div>
                </div>
            </div>
            <div class="people-tab-panel__body">
                <?php if (!$account): ?>
                    <p class="text-muted mb-0"><?= trim((string) $data['email']) === '' ? 'Add an email address on the Details tab and save. Their login is created automatically, then you can choose their role here.' : 'Save the Details tab to create their login. Then you can choose their role here.' ?></p>
                <?php else: ?>
                    <div class="people-form-section">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h3 class="mb-0">Login</h3>
                            <span class="people-status-pill <?= (int) $account['is_active'] === 1 ? 'people-status-pill--success' : 'people-status-pill--danger' ?>"><?= (int) $account['is_active'] === 1 ? 'Login available' : 'Login disabled' ?></span>
                        </div>
                        <div class="people-account-summary mt-3">
                            <div class="people-account-meta"><span>Login email</span><strong><?= h((string) $account['email']) ?></strong></div>
                            <div class="people-account-meta"><span>Last login</span><strong><?= h(club_person_format_uk_datetime((string) ($account['last_login_at'] ?? ''))) ?></strong></div>
                            <div class="people-account-meta"><span>Email verification</span><strong><?= h(club_person_format_uk_datetime((string) ($account['email_verified_at'] ?? ''), 'Not verified')) ?></strong></div>
                            <div class="people-account-meta"><span>Google</span><strong><?= $googleIdentity ? 'Linked' : 'Not linked' ?></strong></div>
                        </div>
                        <?php if ($canManageAdminAccess): ?>
                            <div class="people-password-actions mt-3">
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                    <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                    <button class="btn btn-brand btn-sm" type="submit" name="action" value="email_setup_link"><i class="fa-solid fa-envelope me-1" aria-hidden="true"></i>Send sign-in link</button>
                                </form>
                                <?php if (!$googleIdentity): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                        <button class="btn btn-outline-primary btn-sm" type="submit" name="action" value="google_welcome_link" <?= !member_google_configured() ? 'disabled' : '' ?>><i class="fa-brands fa-google me-1" aria-hidden="true"></i>Create Google linking link</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" data-confirm="<?= (int) $account['is_active'] === 1 ? 'Disable this login? Existing sessions will end immediately. Their history and tickets will be kept.' : 'Enable this login again?' ?>">
                                    <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                    <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                    <input type="hidden" name="action" value="toggle_login">
                                    <input type="hidden" name="enabled" value="<?= (int) $account['is_active'] === 1 ? '0' : '1' ?>">
                                    <button class="btn btn-outline-<?= (int) $account['is_active'] === 1 ? 'danger' : 'success' ?> btn-sm" type="submit"><?= (int) $account['is_active'] === 1 ? 'Disable login' : 'Enable login' ?></button>
                                </form>
                            </div>
                            <?php if (!$googleIdentity && !member_google_configured()): ?><p class="text-muted small mt-2 mb-0">Google sign-in is not currently configured.</p><?php endif; ?>
                            <details class="people-advanced mt-3">
                                <summary>Advanced login options</summary>
                                <div class="people-advanced__body">
                                    <form method="post" class="people-set-password">
                                        <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
                                        <input type="hidden" name="person_id" value="<?= (int) $personId ?>">
                                        <input type="hidden" name="action" value="set_password">
                                        <label class="form-label" for="accountNewPasswordSimple">Set a temporary password</label>
                                        <div class="people-set-password__row">
                                            <input class="form-control" id="accountNewPasswordSimple" type="password" name="new_password" minlength="12" maxlength="200" placeholder="At least 12 characters" required>
                                            <button class="btn btn-outline-primary" type="submit">Set password</button>
                                        </div>
                                        <div class="form-text">Share it securely and ask the person to change it after signing in. Sending a sign-in link is recommended.</div>
                                    </form>
                                </div>
                            </details>
                        <?php else: ?>
                            <p class="text-muted small mt-3 mb-0">Only an Administrator can send a sign-in link or change this login.</p>
                        <?php endif; ?>
                    </div>

                    <div class="people-form-section mt-4">
                        <h3>Role</h3>
                        <p class="text-muted small">One role per person, like WordPress. It decides which admin areas they can open.</p>
                        <?php if ($canManageAdminAccess): ?>
                            <div class="people-role-options" data-access-role-options data-initial-value="<?= $currentAccessRoleId ?>" data-person-name="<?= h((string) $data['display_name']) ?>" data-form="personForm">
                                <?= club_person_role_cards($allAccessRoles, $roleSelectedId, $accessRoleSummaries, $accessRoleDescriptions, 'personForm') ?>
                            </div>
                        <?php else: ?>
                            <p class="mb-1"><strong><?= h((string) ($currentAccessRole['name'] ?? 'No role yet')) ?></strong></p>
                            <p class="text-muted mb-0"><?= h($accessRoleDescriptions[$currentAccessRoleId] ?? 'No role has been chosen yet.') ?></p>
                            <p class="text-muted small mt-2 mb-0">Only an Administrator can change this.</p>
                        <?php endif; ?>
                        <?php if ($currentPersonPositions !== []): ?>
                            <div class="people-account-note mt-3">Club positions: <?= h(implode(', ', array_column($currentPersonPositions, 'name'))) ?>. Positions describe responsibilities and do not change site access.</div>
                        <?php endif; ?>
                    </div>

                    <div class="people-form-section mt-4">
                        <h3>What they can do</h3>
                        <?php if ($personAccessEffective['bypass'] ?? false): ?>
                            <p class="mb-0"><span class="badge text-bg-primary">Administrator</span> Full access across the Hub.</p>
                        <?php elseif (($personAccessEffective['capabilities'] ?? []) === []): ?>
                            <p class="text-muted mb-0">No admin areas. They can still sign in, but no admin pages are open to them.</p>
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

                    <?php if ($canManageAdminAccess): ?>
                        <details class="people-advanced mt-4"<?= $personAccessOverrides !== [] ? ' open' : '' ?>>
                            <summary>Extra permissions<?= $personAccessOverrides !== [] ? ' (' . count($personAccessOverrides) . ')' : '' ?></summary>
                            <div class="people-form-section people-advanced__body">
                                <h3>Extra permissions</h3>
                                <p class="text-muted small">On top of their role: give one extra permission, or take one away. An optional end date switches it off automatically. Not needed for Administrators.</p>
                                <?php if ($personAccessOverrides === []): ?>
                                    <p class="text-muted">None &mdash; access is exactly what their role gives.</p>
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
                        </details>
                    <?php endif; ?>

                    <div class="people-form-section mt-4">
                        <h3>Tags</h3>
                        <p class="text-muted small">Committee, football staff, volunteer and season ticket holder are worked out automatically from their club positions and tickets. These tags are labels only &mdash; they never give access.</p>
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
                <?php endif; ?>
            </div>
        </section>
    </div>
    <div class="mb-4" id="tab-positions">
        <section class="people-tab-panel">
            <div class="people-tab-panel__header">
                <div class="people-account__title">
                    <span class="people-account__icon"><i class="fa-solid fa-briefcase" aria-hidden="true"></i></span>
                    <div>
                        <h2>Club Position(s)</h2>
                        <p>Track club responsibilities and their dates. Positions describe what someone does; Hub access is managed separately.</p>
                    </div>
                </div>
                <div class="people-account__badges" aria-label="Position count">
                    <span class="people-status-pill people-status-pill--muted"><?= count($personPositions) ?> position<?= count($personPositions) === 1 ? '' : 's' ?></span>
                </div>
            </div>
            <div class="people-tab-panel__body">
                <div class="people-account-note">Use start and end dates to keep an accurate history. A position is shown as current when today's date falls within its date range.</div>
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

    <div class="mb-4" id="tab-relationships">
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

    <div class="mb-4" id="tab-ticketing">
        <section class="people-tab-panel">
            <div class="people-tab-panel__header">
                <div class="people-account__title">
                    <span class="people-account__icon"><i class="fa-solid fa-ticket" aria-hidden="true"></i></span>
                    <div>
                        <h2>Tickets</h2>
                        <p>View this person's season tickets, match ticket orders and recorded gate entries.</p>
                    </div>
                </div>
                <div class="people-tab-actions">
                    <a class="btn btn-brand btn-sm" href="/admin/season_ticket_order.php?action=new&amp;person_id=<?= (int) $personId ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add season ticket</a>
                    <a class="btn btn-outline-secondary btn-sm" href="/admin/ticket_orders.php"><i class="fa-solid fa-list" aria-hidden="true"></i> Match ticket orders</a>
                </div>
            </div>
            <div class="people-tab-panel__body">
                <div class="people-history-stats">
                    <div><span>Season tickets held</span><strong><?= (int) $history['season_passes'] ?></strong></div>
                    <div><span>Match orders</span><strong><?= (int) $history['match_ticket_orders'] ?></strong></div>
                    <div><span>Gate entries recorded</span><strong><?= (int) $history['admissions'] ?></strong></div>
                </div>
                <div class="people-account-note"><strong>Need to cancel or refund?</strong> Choose <em>Manage order</em> on the relevant ticket. Financial and cancellation controls remain on the full order screen for safety.</div>
                <div class="people-history-grid">
                    <section class="people-form-section people-ticket-section" aria-labelledby="seasonTicketsHeading">
                        <div class="people-ticket-section__heading">
                            <div><h3 id="seasonTicketsHeading">Season tickets</h3><p>Long-term passes assigned directly to this person.</p></div>
                            <a href="/admin/season_ticket_orders.php" class="btn btn-sm btn-link">View all</a>
                        </div>
                        <?php if ($seasonTicketHistory): ?>
                            <div class="people-history-list">
                                <?php foreach ($seasonTicketHistory as $order): ?>
                                    <?php
                                    $seasonStatus = (string) $order['entitlement_status'];
                                    $seasonStatusLabel = ucfirst(str_replace('_', ' ', $seasonStatus));
                                    ?>
                                    <article class="people-history-item people-ticket-card">
                                        <div class="people-ticket-card__main">
                                            <div class="people-ticket-card__title"><?= h((string) $order['season_name']) ?> <strong><?= h((string) $order['type_name']) ?></strong></div>
                                            <div class="people-ticket-card__meta"><span>Order #<?= (int) $order['order_id'] ?></span><span><?= gbp((float) $order['line_total']) ?></span><?php if (!empty($order['manual_code'])): ?><span>Code <?= h((string) $order['manual_code']) ?></span><?php endif; ?></div>
                                        </div>
                                        <div class="people-ticket-card__side">
                                            <span class="badge hub-status <?= $seasonStatus === 'active' ? 'text-bg-success' : ($seasonStatus === 'cancelled' || $seasonStatus === 'refunded' ? 'text-bg-secondary' : 'text-bg-warning') ?>"><?= h($seasonStatusLabel) ?></span>
                                            <div class="people-ticket-card__actions"><a class="btn btn-sm btn-outline-primary" href="/admin/season_ticket_orders.php?id=<?= (int) $order['order_id'] ?>"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Manage order</a></div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="people-empty-state"><strong>No season tickets</strong><span>This person has not been assigned a season ticket yet.</span><a class="btn btn-sm btn-brand" href="/admin/season_ticket_order.php?action=new&amp;person_id=<?= (int) $personId ?>">Add season ticket</a></div>
                        <?php endif; ?>
                    </section>

                    <section class="people-form-section people-ticket-section" aria-labelledby="matchTicketsHeading">
                        <div class="people-ticket-section__heading">
                            <div><h3 id="matchTicketsHeading">Match ticket orders</h3><p>Individual match purchases made through this person's account.</p></div>
                            <a href="/admin/ticket_orders.php" class="btn btn-sm btn-link">View all</a>
                        </div>
                        <?php if ($matchTicketHistory): ?>
                            <div class="people-history-list">
                                <?php foreach ($matchTicketHistory as $order): ?>
                                    <?php
                                    $matchStatus = (string) $order['status'];
                                    $matchStatusLabel = ucfirst(str_replace('_', ' ', $matchStatus));
                                    ?>
                                    <article class="people-history-item people-ticket-card">
                                        <div class="people-ticket-card__main">
                                            <div class="people-ticket-card__title">Saltcoats Victoria <strong>v <?= h((string) $order['opponent']) ?></strong></div>
                                            <div class="people-ticket-card__meta"><span><?= h(date('D d/m/Y', strtotime((string) $order['match_date']))) ?><?= !empty($order['kickoff_time']) ? ' · ' . h(date('H:i', strtotime((string) $order['kickoff_time']))) : '' ?></span><span><?= (int) $order['ticket_count'] ?> ticket<?= (int) $order['ticket_count'] === 1 ? '' : 's' ?></span><span>Order #<?= (int) $order['id'] ?></span></div>
                                        </div>
                                        <div class="people-ticket-card__side">
                                            <span class="badge <?= $matchStatus === 'complete' ? 'text-bg-success' : ($matchStatus === 'cancelled' || $matchStatus === 'refunded' ? 'text-bg-secondary' : 'text-bg-warning') ?>"><?= h($matchStatusLabel) ?></span>
                                            <div class="people-ticket-card__actions"><a class="btn btn-sm btn-outline-primary" href="/admin/ticket_orders.php?id=<?= (int) $order['id'] ?>"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Manage order</a></div>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="people-empty-state"><strong>No match ticket orders</strong><span>Match tickets bought through this person's account will appear here.</span><a class="btn btn-sm btn-outline-secondary" href="/admin/fixture_tickets.php">Ticketed fixtures</a></div>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </section>
    </div>

    <div class="hub-actions justify-content-end mt-4">
        <button class="btn btn-outline-danger" type="submit" form="personForm" name="action" value="archive_person" data-confirm="This archives the person and disables their account. History is preserved." data-confirm-title="Archive this person?" data-confirm-action="Archive">Archive</button>
        <button class="btn btn-brand" type="submit" form="personForm">Update User</button>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm border-0 mt-4">
    <div class="card-body small text-muted">
        People are the master records. Details holds contact information. Account &amp; role controls sign-in and what the person can open in the Hub. Club positions record responsibilities without granting access. Season tickets are ticket packages attached to people.
    </div>
</div>

<script src="/admin/assets/js/club-person.js?v=<?= (int) (@filemtime(__DIR__ . '/assets/js/club-person.js') ?: time()) ?>" defer></script>
<?php require_once __DIR__ . '/footer.php'; ?>
