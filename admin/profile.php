<?php
declare(strict_types=1);
require_once __DIR__ . '/account_auth.php';
require_once __DIR__ . '/lib/functions.php';
$currentUser = hub_auth_current_user();
if (!$currentUser) {
    header('Location: /admin/login.php');
    exit;
}
$accountId = (int) ($currentUser['account_id'] ?? 0);
if ($accountId <= 0) {
    $legacyAccount = getAccountByLegacyHolderId($pdo, (int) ($currentUser['id'] ?? 0));
    $accountId = (int) ($legacyAccount['id'] ?? 0);
}
$account = $accountId > 0 ? getAccount($pdo, $accountId) : null;
$personId = (int) ($account['person_id'] ?? 0);
$person = $personId > 0 ? getPerson($pdo, $personId) : null;
if (!$person || !$account) {
    http_response_code(403);
    exit('Your profile is not linked to a login account. Please contact an administrator.');
}
ensureSeasonTicketSchema($pdo);
ensureAuditLogSchema($pdo);


$errors = [];
$success = '';
$profileCsrfToken = hub_auth_csrf_token();

function member_profile_upload_image(array $file, int $personId): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Profile photo upload failed.');
    }
    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Profile photo must be under 2MB.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $info = @getimagesize($tmp);
    $mime = (string) ($info['mime'] ?? '');
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => '',
    };
    if ($ext === '') {
        throw new RuntimeException('Profile photo must be a JPG, PNG or WebP image.');
    }
    $dir = __DIR__ . '/../uploads/member_profiles';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filename = 'person-' . $personId . '-' . bin2hex(random_bytes(5)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $filename)) {
        throw new RuntimeException('Could not save profile photo.');
    }
    return 'uploads/member_profiles/' . $filename;
}

function member_profile_address_summary(array $person): string
{
    return trim(implode(', ', array_filter([
        trim((string) ($person['address_line1'] ?? '')),
        trim((string) ($person['address_line2'] ?? '')),
        trim((string) ($person['town'] ?? '')),
        trim((string) ($person['country'] ?? '')),
        trim((string) ($person['postcode'] ?? '')),
    ], static fn(string $part): bool => $part !== '')));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $csrfOk = hub_auth_verify_csrf_token($postedToken);
    if (!$csrfOk) {
        $errors[] = 'Your session expired. Please reload and try again.';
    }

    $formAction = (string) ($_POST['form_action'] ?? 'profile');
    if ($formAction === 'profile') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $dob = trim((string) ($_POST['date_of_birth'] ?? ''));
        $contactEmail = trim((string) ($_POST['contact_email'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $addressLine1 = trim((string) ($_POST['address_line1'] ?? ''));
        $addressLine2 = trim((string) ($_POST['address_line2'] ?? ''));
        $town = trim((string) ($_POST['town'] ?? ''));
        $country = trim((string) ($_POST['country'] ?? ''));
        $postcode = trim((string) ($_POST['postcode'] ?? ''));
        $marketingOptIn = isset($_POST['marketing_opt_in']);
        $profileImagePath = (string) ($person['profile_image_path'] ?? '');

        if ($name === '') {
            $errors[] = 'Name is required.';
        }
        if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        if (!$errors) {
            try {
                $uploaded = member_profile_upload_image($_FILES['profile_image'] ?? [], (int) $person['id']);
                if ($uploaded !== null) {
                    $profileImagePath = $uploaded;
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                updateAccountLogin($pdo, (int) $account['id'], $contactEmail, (int) ($account['is_active'] ?? 1) === 1, accountPrimaryRole($pdo, (int) $account['id']));
                updatePerson($pdo, (int) $person['id'], [
                    'display_name' => $name,
                    'date_of_birth' => $dob,
                    'email' => $contactEmail,
                    'phone' => $phone,
                    'address_line1' => $addressLine1,
                    'address_line2' => $addressLine2,
                    'town' => $town,
                    'country' => $country,
                    'postcode' => $postcode,
                    'marketing_opt_in' => $marketingOptIn ? 1 : 0,
                    'is_active' => (int) ($person['is_active'] ?? 1) === 1,
                ]);
                if ($profileImagePath !== (string) ($person['profile_image_path'] ?? '')) {
                    updatePersonProfileImage($pdo, (int) $person['id'], $profileImagePath);
                }
                $pdo->commit();
                $success = 'Profile updated.';
                $person = getPerson($pdo, $personId) ?: $person;
                $account = getAccount($pdo, $accountId) ?: $account;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $e->getMessage();
            }
        }
    } elseif ($formAction === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        if (!password_verify($current, (string) ($account['password_hash'] ?? ''))) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        }

        if (!$errors) {
            updateAccountPasswordHash($pdo, (int) $account['id'], password_hash($new, PASSWORD_DEFAULT));
            identityAuditLog($pdo, 'member_password_changed', 'Member changed password for account #' . (int) $account['id']);
            $success = 'Password updated.';
            $account = getAccount($pdo, $accountId) ?: $account;
        }
    }
}

$contactEmail = (string) ($account['email'] ?? '');
$pageHero = [
    'eyebrow' => 'Account settings',
    'title' => 'Your profile',
    'subtitle' => 'Keep your contact details, address and communication preferences up to date.',
    'actions' => [],
];
$pageStyles = ['member-profile.css'];
require_once __DIR__ . '/header.php';
?>

<div class="member-page profile-page">
    <?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="member-grid member-grid--aside">
        <div class="member-list">
            <section class="member-card">
                <div class="member-card__header"><h2>Profile information</h2></div>
                <div class="member-card__body">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= h($profileCsrfToken) ?>">
                        <input type="hidden" name="form_action" value="profile">

                        <div class="profile-identity">
                            <div class="profile-photo-control">
                                <input type="file" class="profile-photo-input" id="profileImage" name="profile_image" accept="image/jpeg,image/png,image/webp" aria-label="Change profile photo" aria-describedby="profilePhotoStatus">
                                <label class="member-avatar profile-photo" for="profileImage" title="Change profile photo">
                                    <?php if (!empty($person['profile_image_path'])): ?><img id="profilePhotoPreview" src="/<?= h((string) $person['profile_image_path']) ?>" alt="Your profile photo"><?php else: ?><span id="profilePhotoInitials"><?= h(mb_strtoupper(mb_substr((string) $person['display_name'], 0, 1))) ?></span><img id="profilePhotoPreview" alt="Selected profile photo" hidden><?php endif; ?>
                                    <span class="profile-photo-overlay"><i class="fa-solid fa-camera" aria-hidden="true"></i><span>Change photo</span></span>
                                    <span class="profile-photo-badge"><i class="fa-solid fa-camera" aria-hidden="true"></i></span>
                                </label>
                            </div>
                            <div class="profile-identity-copy">
                                <h2><?= h((string) $person['display_name']) ?></h2>
                                <div class="profile-photo-status" id="profilePhotoStatus" role="status" aria-live="polite"></div>
                            </div>
                        </div>
                        <div class="profile-section-heading"><h3>Personal details</h3><p>Your name and the best way to reach you.</p></div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="profileName">Name</label>
                                <input type="text" class="form-control" id="profileName" name="name" autocomplete="name" value="<?= h((string) $person['display_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="profileDob">Date of birth</label>
                                <input type="date" class="form-control" id="profileDob" name="date_of_birth" value="<?= h((string) ($person['date_of_birth'] ?? '')) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="profileContactEmail">Email</label>
                                <input type="email" class="form-control" id="profileContactEmail" name="contact_email" value="<?= h($contactEmail) ?>" autocomplete="email" required>
                                <div class="form-text">Used to sign in and for club communications.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="profilePhone">Phone</label>
                                <input type="text" class="form-control" id="profilePhone" name="phone" autocomplete="tel" value="<?= h((string) ($person['phone'] ?? '')) ?>">
                            </div>
                            <div class="col-12 profile-section-heading"><h3>Address</h3><p>Keep your club contact record up to date.</p></div>
                            <div class="col-md-6"><label class="form-label" for="addressLine1">Address line 1</label><input type="text" class="form-control" id="addressLine1" name="address_line1" value="<?= h((string) ($person['address_line1'] ?? '')) ?>"></div>
                            <div class="col-md-6"><label class="form-label" for="addressLine2">Address line 2</label><input type="text" class="form-control" id="addressLine2" name="address_line2" value="<?= h((string) ($person['address_line2'] ?? '')) ?>"></div>
                            <div class="col-md-4"><label class="form-label" for="addressTown">Town</label><input type="text" class="form-control" id="addressTown" name="town" value="<?= h((string) ($person['town'] ?? '')) ?>"></div>
                            <div class="col-md-4"><label class="form-label" for="addressCountry">Country</label><input type="text" class="form-control" id="addressCountry" name="country" value="<?= h((string) ($person['country'] ?? '')) ?>"></div>
                            <div class="col-md-4"><label class="form-label" for="addressPostcode">Postcode</label><input type="text" class="form-control" id="addressPostcode" name="postcode" value="<?= h((string) ($person['postcode'] ?? '')) ?>"></div>
                            <div class="col-12">
                                <div class="profile-section-heading"><h3>Communication preferences</h3></div>
                                <div class="form-check profile-preference">
                                    <input type="checkbox" class="form-check-input" id="profileOptIn" name="marketing_opt_in" value="1" <?= (int) ($person['marketing_opt_in'] ?? 0) === 1 ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="profileOptIn">Keep me posted about club news, deals and offers</label>
                                </div>
                            </div>
                        </div>

                        <div class="profile-form-footer"><button type="submit" class="btn btn-brand">Save changes</button></div>
                    </form>
                </div>
            </section>
        </div>

        <aside class="member-grid">

            <section class="member-card">
                <div class="member-card__header"><h2>Change password</h2></div>
                <div class="member-card__body">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h($profileCsrfToken) ?>">
                        <input type="hidden" name="form_action" value="password">
                        <div class="mb-3">
                            <label class="form-label" for="currentPassword">Current password</label>
                            <input type="password" class="form-control" id="currentPassword" name="current_password" autocomplete="current-password" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="newPassword">New password</label>
                            <input type="password" class="form-control" id="newPassword" name="new_password" autocomplete="new-password" minlength="8" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="newPasswordConfirm">Confirm new password</label>
                            <input type="password" class="form-control" id="newPasswordConfirm" name="new_password_confirm" autocomplete="new-password" minlength="8" required>
                        </div>
                        <button type="submit" class="btn btn-outline-secondary">Update password</button>
                    </form>
                </div>
            </section>
        </aside>
    </div>
</div>

<script src="/admin/assets/js/member-profile.js?v=1" defer></script>
<?php require_once __DIR__ . '/footer.php'; ?>
