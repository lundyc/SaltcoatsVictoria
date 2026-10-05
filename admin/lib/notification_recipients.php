<?php

declare(strict_types=1);

// Shared "who gets emailed when Stripe confirms a payment" list — used by
// season tickets, match tickets, shop orders, sponsorships and fundraisers.
// One underlying table (payment_notification_recipients, see lib/stripe.php)
// with two admin entry points: the main Hub Settings > Payments tab, and the
// Shop Settings > Order notifications tab. Both call these same functions so
// edits made from either page apply everywhere.

require_once __DIR__ . '/stripe.php';

/**
 * Apply one add/remove/update action from the Add Users / Add Email /
 * Edit / Delete forms and persist the result.
 *
 * @param array<string,mixed> $post
 * @return array{ok:bool,message:string}
 */
function notification_recipients_handle_action(PDO $pdo, array $post, ?int $currentUserId): array
{
    stripe_notification_seed_from_env($pdo, stripe_env(), $currentUserId);
    $accountIds = stripe_notification_account_ids($pdo);
    $manualEmails = stripe_notification_manual_emails($pdo);
    $action = isset($post['notification_action']) && is_string($post['notification_action']) ? trim($post['notification_action']) : '';

    if ($action === 'add_accounts') {
        $postedAccountIds = isset($post['account_ids']) && is_array($post['account_ids']) ? $post['account_ids'] : [];
        foreach ($postedAccountIds as $accountId) {
            $accountId = is_scalar($accountId) ? trim((string) $accountId) : '';
            if ($accountId !== '' && ctype_digit($accountId) && (int) $accountId > 0) {
                $accountIds[] = (int) $accountId;
            }
        }
    } elseif ($action === 'remove_account') {
        $removeId = (int) ($post['account_id'] ?? 0);
        $accountIds = array_values(array_filter($accountIds, static fn(int $id): bool => $id !== $removeId));
    } elseif ($action === 'add_email') {
        $email = trim((string) ($post['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Enter a valid email address.'];
        }
        $manualEmails[] = $email;
    } elseif ($action === 'update_email') {
        $oldEmail = strtolower(trim((string) ($post['old_email'] ?? '')));
        $newEmail = trim((string) ($post['email'] ?? ''));
        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Enter a valid email address.'];
        }
        foreach ($manualEmails as $index => $email) {
            if (strtolower($email) === $oldEmail) {
                unset($manualEmails[$index]);
            }
        }
        $manualEmails[] = $newEmail;
    } elseif ($action === 'delete_email') {
        $deleteEmail = strtolower(trim((string) ($post['email'] ?? '')));
        $manualEmails = array_values(array_filter($manualEmails, static fn(string $email): bool => strtolower($email) !== $deleteEmail));
    } else {
        return ['ok' => false, 'message' => 'Unknown notification action.'];
    }

    if (!stripe_notification_save_lists($pdo, $accountIds, $manualEmails, $currentUserId)) {
        return ['ok' => false, 'message' => 'Notification recipients could not be saved. Please try again.'];
    }

    return ['ok' => true, 'message' => 'Notification recipients saved.'];
}

/**
 * Data needed to render the recipients table and the "Add Users" picker.
 *
 * @return array{rows:list<array<string,mixed>>,accountOptions:list<array<string,mixed>>,accountIdSet:array<int,bool>}
 */
function notification_recipients_data(PDO $pdo): array
{
    stripe_notification_seed_from_env($pdo, stripe_env());
    $accountIds = stripe_notification_account_ids($pdo);
    $manualEmails = stripe_notification_manual_emails($pdo);
    $accountIdSet = array_fill_keys($accountIds, true);

    $accountOptions = [];
    try {
        if (stripe_table_exists($pdo, 'accounts') && stripe_table_exists($pdo, 'people')) {
            $accountOptions = $pdo->query("SELECT a.id, a.person_id, a.email AS account_email, a.is_active AS account_is_active,
                    p.display_name, p.email AS profile_email, p.is_active AS person_is_active,
                    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ', ') AS roles
                FROM accounts a
                JOIN people p ON p.id = a.person_id
                LEFT JOIN account_roles ar ON ar.account_id = a.id
                LEFT JOIN roles r ON r.id = ar.role_id
                WHERE a.is_active = 1 AND p.is_active = 1
                GROUP BY a.id, a.email, a.is_active, p.display_name, p.email, p.is_active
                ORDER BY p.display_name, a.email")->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $accountOptions = [];
    }

    $accountOptionsById = [];
    foreach ($accountOptions as $accountOption) {
        $accountOptionsById[(int) $accountOption['id']] = $accountOption;
    }

    $rows = [];
    foreach ($accountIds as $accountId) {
        $accountOption = $accountOptionsById[$accountId] ?? null;
        if (!$accountOption) {
            continue;
        }
        $profileEmail = trim((string) ($accountOption['profile_email'] ?? ''));
        $accountEmail = trim((string) ($accountOption['account_email'] ?? ''));
        $deliveryEmail = $profileEmail !== '' ? $profileEmail : $accountEmail;
        $rows[] = [
            'type' => 'account',
            'account_id' => $accountId,
            'person_id' => (int) ($accountOption['person_id'] ?? 0),
            'name' => (string) ($accountOption['display_name'] ?: 'Account #' . $accountId),
            'email' => $deliveryEmail,
            'source' => 'Hub user',
            'roles' => (string) ($accountOption['roles'] ?: 'No role'),
        ];
    }
    foreach ($manualEmails as $email) {
        $rows[] = [
            'type' => 'email',
            'account_id' => 0,
            'person_id' => 0,
            'name' => 'Manual recipient',
            'email' => $email,
            'source' => 'Additional email',
            'roles' => '',
        ];
    }
    if ($rows === []) {
        foreach (stripe_notification_recipients($pdo) as $email) {
            $rows[] = [
                'type' => 'fallback',
                'account_id' => 0,
                'person_id' => 0,
                'name' => 'Fallback admin recipient',
                'email' => (string) $email,
                'source' => 'Fallback',
                'roles' => 'Active admin/treasurer',
            ];
        }
    }

    return ['rows' => $rows, 'accountOptions' => $accountOptions, 'accountIdSet' => $accountIdSet];
}
