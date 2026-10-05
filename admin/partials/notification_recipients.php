<?php

declare(strict_types=1);

// Renders the payment-notification recipients table + "Add Users"/"Add
// Email" modals. The including page must set, before requiring this file:
//   $notificationRows            - from notification_recipients_data()['rows']
//   $notificationAccountOptions  - from notification_recipients_data()['accountOptions']
//   $notificationAccountIdSet    - from notification_recipients_data()['accountIdSet']
//   $notificationCsrfField       - pre-rendered hidden CSRF <input>, matching
//                                  whatever csrf_check()/verify the page uses
//   $notificationHiddenFields    - pre-rendered hidden <input>s that route the
//                                  POST back into the including page's own
//                                  handler (e.g. an "action" or "section" field)
?>
<div class="settings-note mb-3">
    These emails are sent after Stripe confirms payment for season tickets, match tickets, shop orders, sponsorships, player sponsorship orders and fundraiser payments.
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addNotificationUsersModal">
        <i class="fa-solid fa-user-plus me-1" aria-hidden="true"></i>Add Users
    </button>
    <button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#addNotificationEmailModal">
        <i class="fa-solid fa-envelope me-1" aria-hidden="true"></i>Add Email
    </button>
</div>

<?php if ($notificationRows === []): ?>
    <div class="settings-note mb-0">No notification recipients are currently configured.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle hub-data-table hub-data-table--responsive mb-0">
            <caption class="visually-hidden">Payment notification recipients</caption>
            <thead><tr><th>Name</th><th>Email</th><th>Type</th><th>Role</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php foreach ($notificationRows as $row): ?>
                    <tr>
                        <td data-label="Name" class="fw-semibold"><?= h((string) $row['name']) ?></td>
                        <td data-label="Email"><?= h((string) $row['email']) ?></td>
                        <td data-label="Type"><span class="badge text-bg-light"><?= h((string) $row['source']) ?></span></td>
                        <td data-label="Role" class="text-muted"><?= h((string) ($row['roles'] ?: '—')) ?></td>
                        <td data-label="Actions" class="text-end">
                            <div class="d-inline-flex gap-1">
                                <?php if ($row['type'] === 'account'): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="/admin/club_person.php?id=<?= (int) $row['person_id'] ?>" title="Edit user profile" aria-label="Edit <?= h((string) $row['name']) ?>">
                                        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                    </a>
                                    <form method="post" class="d-inline">
                                        <?= $notificationCsrfField ?>
                                        <?= $notificationHiddenFields ?>
                                        <input type="hidden" name="notification_action" value="remove_account">
                                        <input type="hidden" name="account_id" value="<?= (int) $row['account_id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit" title="Remove from notifications" aria-label="Remove <?= h((string) $row['name']) ?>">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                <?php elseif ($row['type'] === 'email'): ?>
                                    <button class="btn btn-sm btn-outline-secondary" type="button" title="Edit email" aria-label="Edit <?= h((string) $row['email']) ?>" data-bs-toggle="modal" data-bs-target="#editNotificationEmailModal" data-email="<?= h((string) $row['email']) ?>">
                                        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
                                    </button>
                                    <form method="post" class="d-inline">
                                        <?= $notificationCsrfField ?>
                                        <?= $notificationHiddenFields ?>
                                        <input type="hidden" name="notification_action" value="delete_email">
                                        <input type="hidden" name="email" value="<?= h((string) $row['email']) ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit" title="Delete email" aria-label="Delete <?= h((string) $row['email']) ?>">
                                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small">Fallback</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="modal fade" id="addNotificationUsersModal" tabindex="-1" aria-labelledby="addNotificationUsersModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="post" class="modal-content">
            <?= $notificationCsrfField ?>
            <?= $notificationHiddenFields ?>
            <input type="hidden" name="notification_action" value="add_accounts">
            <div class="modal-header">
                <h2 class="modal-title h5" id="addNotificationUsersModalTitle">Add Users</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($notificationAccountOptions === []): ?>
                    <div class="settings-note mb-0">No active users are available.</div>
                <?php else: ?>
                    <div class="list-group" data-notification-user-picker>
                        <?php foreach ($notificationAccountOptions as $accountOption): ?>
                            <?php
                            $accountId = (int) $accountOption['id'];
                            $profileEmail = trim((string) ($accountOption['profile_email'] ?? ''));
                            $accountEmail = trim((string) ($accountOption['account_email'] ?? ''));
                            $deliveryEmail = $profileEmail !== '' ? $profileEmail : $accountEmail;
                            $hasValidEmail = filter_var($deliveryEmail, FILTER_VALIDATE_EMAIL);
                            $alreadySelected = isset($notificationAccountIdSet[$accountId]);
                            ?>
                            <div class="list-group-item list-group-item-action d-flex flex-column flex-md-row justify-content-between gap-2<?= (!$hasValidEmail || $alreadySelected) ? ' disabled' : '' ?>" role="button" tabindex="<?= (!$hasValidEmail || $alreadySelected) ? '-1' : '0' ?>" data-notification-user-option>
                                <span>
                                    <strong><?= h((string) ($accountOption['display_name'] ?: 'Account #' . $accountId)) ?></strong>
                                    <span class="d-block small text-muted">
                                        <?= $hasValidEmail ? h($deliveryEmail) : 'No valid email on profile or account' ?>
                                    </span>
                                </span>
                                <span class="d-flex align-items-center gap-2">
                                    <span class="badge text-bg-light"><?= $alreadySelected ? 'Already added' : h((string) ($accountOption['roles'] ?: 'No role')) ?></span>
                                    <i class="fa-solid fa-check text-success d-none" aria-hidden="true"></i>
                                </span>
                                <input class="visually-hidden" type="checkbox" name="account_ids[]" value="<?= $accountId ?>" tabindex="-1"<?= (!$hasValidEmail || $alreadySelected) ? ' disabled' : '' ?>>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Selected Users</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="addNotificationEmailModal" tabindex="-1" aria-labelledby="addNotificationEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <?= $notificationCsrfField ?>
            <?= $notificationHiddenFields ?>
            <input type="hidden" name="notification_action" value="add_email">
            <div class="modal-header">
                <h2 class="modal-title h5" id="addNotificationEmailModalTitle">Add Email Recipient</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold" for="notificationEmailAdd">Email address</label>
                <input class="form-control" id="notificationEmailAdd" type="email" name="email" autocomplete="email" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Add Email</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editNotificationEmailModal" tabindex="-1" aria-labelledby="editNotificationEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <?= $notificationCsrfField ?>
            <?= $notificationHiddenFields ?>
            <input type="hidden" name="notification_action" value="update_email">
            <input type="hidden" name="old_email" id="notificationEmailOld" value="">
            <div class="modal-header">
                <h2 class="modal-title h5" id="editNotificationEmailModalTitle">Edit Email Recipient</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="form-label fw-semibold" for="notificationEmailEdit">Email address</label>
                <input class="form-control" id="notificationEmailEdit" type="email" name="email" autocomplete="email" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Email</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('[data-notification-user-option]').forEach(function (button) {
        function toggleUser() {
            if (button.classList.contains('disabled')) return;
            var input = button.querySelector('input[type="checkbox"]');
            var icon = button.querySelector('.fa-check');
            if (!input) return;
            input.checked = !input.checked;
            button.classList.toggle('active', input.checked);
            if (icon) icon.classList.toggle('d-none', !input.checked);
        }
        button.addEventListener('click', toggleUser);
        button.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggleUser();
            }
        });
    });

    var editModal = document.getElementById('editNotificationEmailModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            var trigger = event.relatedTarget;
            var email = trigger ? (trigger.getAttribute('data-email') || '') : '';
            var oldInput = document.getElementById('notificationEmailOld');
            var editInput = document.getElementById('notificationEmailEdit');
            if (oldInput) oldInput.value = email;
            if (editInput) editInput.value = email;
        });
    }
})();
</script>
