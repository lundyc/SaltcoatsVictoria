<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS people (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        first_name VARCHAR(120) NULL,
        last_name VARCHAR(120) NULL,
        display_name VARCHAR(190) NOT NULL,
        date_of_birth DATE NULL,
        email VARCHAR(190) NULL,
        email_normalized VARCHAR(190) NULL,
        phone VARCHAR(50) NULL,
        address_line1 VARCHAR(190) NULL,
        address_line2 VARCHAR(190) NULL,
        town VARCHAR(120) NULL,
        postcode VARCHAR(30) NULL,
        country VARCHAR(120) NULL,
        marketing_opt_in TINYINT(1) NOT NULL DEFAULT 1,
        unsubscribe_token VARCHAR(64) NULL,
        unsubscribed_at DATETIME NULL,
        profile_image_path VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_people_display_name (display_name),
        KEY idx_people_email_normalized (email_normalized),
        KEY idx_people_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS accounts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        person_id INT UNSIGNED NOT NULL,
        email VARCHAR(190) NULL,
        email_normalized VARCHAR(190) NULL,
        password_hash VARCHAR(255) NULL,
        email_verified_at DATETIME NULL,
        last_login_at DATETIME NULL,
        reset_token_hash VARCHAR(255) NULL,
        reset_token_expires_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_accounts_person (person_id),
        UNIQUE KEY uq_accounts_email_normalized (email_normalized),
        KEY idx_accounts_active (is_active),
        CONSTRAINT fk_accounts_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS identity_migration_map (
        old_holder_id INT UNSIGNED NOT NULL,
        person_id INT UNSIGNED NOT NULL,
        account_id INT UNSIGNED NULL,
        migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (old_holder_id),
        UNIQUE KEY uq_identity_map_person (person_id),
        UNIQUE KEY uq_identity_map_account (account_id),
        CONSTRAINT fk_identity_map_holder FOREIGN KEY (old_holder_id) REFERENCES season_ticket_holders(id) ON DELETE RESTRICT,
        CONSTRAINT fk_identity_map_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE RESTRICT,
        CONSTRAINT fk_identity_map_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS person_relationships (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        manager_person_id INT UNSIGNED NOT NULL,
        dependent_person_id INT UNSIGNED NOT NULL,
        relationship_type VARCHAR(40) NOT NULL DEFAULT 'dependent',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_person_relationship (manager_person_id, dependent_person_id, relationship_type),
        KEY idx_person_relationship_dependent (dependent_person_id),
        CONSTRAINT fk_person_relationship_manager FOREIGN KEY (manager_person_id) REFERENCES people(id) ON DELETE CASCADE,
        CONSTRAINT fk_person_relationship_dependent FOREIGN KEY (dependent_person_id) REFERENCES people(id) ON DELETE CASCADE,
        CONSTRAINT chk_person_relationship_not_self CHECK (manager_person_id <> dependent_person_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_roles_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS account_roles (
        account_id INT UNSIGNED NOT NULL,
        role_id INT UNSIGNED NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (account_id, role_id),
        KEY idx_account_roles_role (role_id),
        CONSTRAINT fk_account_roles_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
        CONSTRAINT fk_account_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS person_positions (
        person_id INT UNSIGNED NOT NULL,
        position_id INT UNSIGNED NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (person_id, position_id),
        KEY idx_person_positions_position (position_id),
        CONSTRAINT fk_person_positions_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE CASCADE,
        CONSTRAINT fk_person_positions_position FOREIGN KEY (position_id) REFERENCES hub_positions(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $seedRole = $pdo->prepare("INSERT INTO roles (code, name) VALUES (:code, :name)
        ON DUPLICATE KEY UPDATE name = VALUES(name)");
    foreach ([
        'public' => 'Public account',
        'volunteer' => 'Volunteer',
        'staff' => 'Staff',
        'admin' => 'Administrator',
    ] as $code => $name) {
        $seedRole->execute([':code' => $code, ':name' => $name]);
    }
};
