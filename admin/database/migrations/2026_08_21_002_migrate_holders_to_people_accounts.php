<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $holders = $pdo->query('SELECT * FROM season_ticket_holders ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

    $insertPerson = $pdo->prepare("INSERT INTO people
        (display_name, date_of_birth, email, email_normalized, phone, address_line1, address_line2, town, postcode, country,
         marketing_opt_in, unsubscribe_token, unsubscribed_at, profile_image_path, is_active, created_at, updated_at)
        VALUES
        (:display_name, :date_of_birth, :email, :email_normalized, :phone, :address_line1, :address_line2, :town, :postcode, :country,
         :marketing_opt_in, :unsubscribe_token, :unsubscribed_at, :profile_image_path, :is_active, :created_at, :updated_at)");

    $insertAccount = $pdo->prepare("INSERT INTO accounts
        (person_id, email, email_normalized, password_hash, email_verified_at, last_login_at, reset_token_hash, reset_token_expires_at, is_active, created_at, updated_at)
        VALUES
        (:person_id, :email, :email_normalized, :password_hash, :email_verified_at, :last_login_at, :reset_token_hash, :reset_token_expires_at, :is_active, :created_at, :updated_at)");

    $insertMap = $pdo->prepare("INSERT INTO identity_migration_map (old_holder_id, person_id, account_id, migrated_at)
        VALUES (:old_holder_id, :person_id, :account_id, NOW())");

    $roleIdStmt = $pdo->prepare('SELECT id FROM roles WHERE code = :code LIMIT 1');
    $insertRole = $pdo->prepare('INSERT IGNORE INTO account_roles (account_id, role_id) VALUES (:account_id, :role_id)');

    foreach ($holders as $holder) {
        $existingMap = $pdo->prepare('SELECT old_holder_id FROM identity_migration_map WHERE old_holder_id = :id LIMIT 1');
        $existingMap->execute([':id' => $holder['id']]);
        if ($existingMap->fetchColumn()) {
            continue;
        }

        $displayName = trim((string) ($holder['name'] ?? ''));
        if ($displayName === '') {
            $displayName = 'Holder #' . (string) $holder['id'];
        }

        $createdAt = !empty($holder['created_at']) ? date('Y-m-d H:i:s', strtotime((string) $holder['created_at'])) : date('Y-m-d H:i:s');
        $updatedAt = !empty($holder['updated_at']) ? date('Y-m-d H:i:s', strtotime((string) $holder['updated_at'])) : null;

        $insertPerson->execute([
            ':display_name' => $displayName,
            ':date_of_birth' => $holder['date_of_birth'] ?: null,
            ':email' => $holder['email'] ?: null,
            ':email_normalized' => $holder['email_normalized'] ?: null,
            ':phone' => $holder['phone'] ?: null,
            ':address_line1' => ($holder['address_line1'] ?: ($holder['address'] ?: null)),
            ':address_line2' => $holder['address_line2'] ?: null,
            ':town' => $holder['town'] ?: null,
            ':postcode' => $holder['postcode'] ?: null,
            ':country' => $holder['country'] ?: null,
            ':marketing_opt_in' => (int) ($holder['marketing_opt_in'] ?? 1),
            ':unsubscribe_token' => $holder['unsubscribe_token'] ?: null,
            ':unsubscribed_at' => $holder['unsubscribed_at'] ?: null,
            ':profile_image_path' => $holder['profile_image_path'] ?: null,
            ':is_active' => (int) ($holder['is_active'] ?? 1),
            ':created_at' => $createdAt,
            ':updated_at' => $updatedAt,
        ]);
        $personId = (int) $pdo->lastInsertId();

        $role = (string) ($holder['role'] ?? 'public');
        $hasLoginIdentity = (($holder['password_hash'] ?? '') !== '')
            || in_array($role, ['volunteer', 'staff', 'admin'], true);
        $accountId = null;

        if ($hasLoginIdentity && !empty($holder['email_normalized'])) {
            $insertAccount->execute([
                ':person_id' => $personId,
                ':email' => $holder['email'] ?: null,
                ':email_normalized' => $holder['email_normalized'],
                ':password_hash' => $holder['password_hash'] ?: null,
                ':email_verified_at' => $holder['email_verified_at'] ?: null,
                ':last_login_at' => $holder['last_login_at'] ?: null,
                ':reset_token_hash' => $holder['reset_token_hash'] ?: null,
                ':reset_token_expires_at' => $holder['reset_token_expires_at'] ?: null,
                ':is_active' => (int) ($holder['is_active'] ?? 1),
                ':created_at' => $createdAt,
                ':updated_at' => $updatedAt,
            ]);
            $accountId = (int) $pdo->lastInsertId();

            $roleCode = in_array($role, ['public', 'volunteer', 'staff', 'admin'], true) ? $role : 'public';
            $roleIdStmt->execute([':code' => $roleCode]);
            $roleId = (int) $roleIdStmt->fetchColumn();
            if ($roleId > 0) {
                $insertRole->execute([':account_id' => $accountId, ':role_id' => $roleId]);
            }
        }

        $insertMap->execute([
            ':old_holder_id' => (int) $holder['id'],
            ':person_id' => $personId,
            ':account_id' => $accountId,
        ]);
    }

    $insertRelationship = $pdo->prepare("INSERT IGNORE INTO person_relationships
        (manager_person_id, dependent_person_id, relationship_type)
        SELECT manager_map.person_id, dependent_map.person_id, 'dependent'
        FROM season_ticket_holders h
        JOIN identity_migration_map dependent_map ON dependent_map.old_holder_id = h.id
        JOIN identity_migration_map manager_map ON manager_map.old_holder_id = h.managed_by_holder_id
        WHERE h.managed_by_holder_id IS NOT NULL
          AND h.managed_by_holder_id <> h.id");
    $insertRelationship->execute();

    $insertPosition = $pdo->prepare("INSERT IGNORE INTO person_positions (person_id, position_id)
        SELECT m.person_id, h.position_id
        FROM season_ticket_holders h
        JOIN identity_migration_map m ON m.old_holder_id = h.id
        JOIN hub_positions p ON p.id = h.position_id
        WHERE h.position_id IS NOT NULL");
    $insertPosition->execute();
};
