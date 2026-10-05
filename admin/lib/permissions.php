<?php
declare(strict_types=1);

require_once __DIR__ . '/accounts.php';

function ensurePermissionSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS permissions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        code VARCHAR(80) NOT NULL,
        name VARCHAR(140) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_permissions_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
        role_id INT UNSIGNED NOT NULL,
        permission_id INT UNSIGNED NOT NULL,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (role_id, permission_id),
        KEY idx_role_permissions_permission (permission_id),
        CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
        CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS account_permissions (
        account_id INT UNSIGNED NOT NULL,
        permission_id INT UNSIGNED NOT NULL,
        granted TINYINT(1) NOT NULL DEFAULT 1,
        assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (account_id, permission_id),
        KEY idx_account_permissions_permission (permission_id),
        CONSTRAINT fk_account_permissions_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
        CONSTRAINT fk_account_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    seedDefaultPermissions($pdo);
}

function defaultPermissionCatalogue(): array
{
    return [
        'people.view' => 'View people',
        'people.manage' => 'Manage people',
        'accounts.manage' => 'Manage accounts',
        'tickets.view' => 'View tickets',
        'tickets.manage' => 'Manage tickets',
        'tickets.scan' => 'Scan tickets',
        'tickets.refund' => 'Refund tickets',
        'tickets.comp' => 'Issue complimentary admissions',
        'season_passes.manage' => 'Manage season tickets',
        'fixtures.manage' => 'Manage fixtures',
        'pos.use' => 'Use POS',
        'pos.manage' => 'Manage POS',
        'finance.view' => 'View finance',
        'finance.refund' => 'Issue finance refunds',
        'reports.view' => 'View reports',
        'social.manage' => 'Manage social content',
    ];
}

function seedDefaultPermissions(PDO $pdo): void
{
    $permissionStmt = $pdo->prepare('INSERT INTO permissions (code, name) VALUES (:code, :name) ON DUPLICATE KEY UPDATE name = VALUES(name)');
    foreach (defaultPermissionCatalogue() as $code => $name) {
        $permissionStmt->execute([':code' => $code, ':name' => $name]);
    }

    $roleDefaults = [
        'admin' => array_keys(defaultPermissionCatalogue()),
        'staff' => ['people.view', 'tickets.view', 'tickets.manage', 'tickets.scan', 'season_passes.manage', 'fixtures.manage', 'pos.use', 'reports.view', 'social.manage'],
        'volunteer' => ['tickets.scan', 'pos.use'],
        'public' => [],
    ];
    $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE code = :code LIMIT 1');
    $permStmt = $pdo->prepare('SELECT id FROM permissions WHERE code = :code LIMIT 1');
    $insert = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)');
    foreach ($roleDefaults as $roleCode => $permissionCodes) {
        $roleStmt->execute([':code' => $roleCode]);
        $roleId = (int) $roleStmt->fetchColumn();
        if ($roleId <= 0) {
            continue;
        }
        foreach ($permissionCodes as $permissionCode) {
            $permStmt->execute([':code' => $permissionCode]);
            $permissionId = (int) $permStmt->fetchColumn();
            if ($permissionId > 0) {
                $insert->execute([':role_id' => $roleId, ':permission_id' => $permissionId]);
            }
        }
    }
}

function accountHasPermission(PDO $pdo, int $accountId, string $permission): bool
{
    ensurePermissionSchema($pdo);
    if ($accountId <= 0 || trim($permission) === '') {
        return false;
    }
    if (in_array('admin', accountRoleCodes($pdo, $accountId), true)) {
        return true;
    }
    $stmt = $pdo->prepare("SELECT ap.granted
        FROM account_permissions ap
        JOIN permissions p ON p.id = ap.permission_id
        WHERE ap.account_id = :account_id AND p.code = :permission
        LIMIT 1");
    $stmt->execute([':account_id' => $accountId, ':permission' => $permission]);
    $explicit = $stmt->fetchColumn();
    if ($explicit !== false) {
        return (int) $explicit === 1;
    }
    $stmt = $pdo->prepare("SELECT 1
        FROM account_roles ar
        JOIN role_permissions rp ON rp.role_id = ar.role_id
        JOIN permissions p ON p.id = rp.permission_id
        WHERE ar.account_id = :account_id AND p.code = :permission
        LIMIT 1");
    $stmt->execute([':account_id' => $accountId, ':permission' => $permission]);
    return (bool) $stmt->fetchColumn();
}

function rolePermissionMatrix(PDO $pdo): array
{
    ensurePermissionSchema($pdo);
    $rows = $pdo->query("SELECT r.code AS role_code, p.code AS permission_code
        FROM roles r
        LEFT JOIN role_permissions rp ON rp.role_id = r.id
        LEFT JOIN permissions p ON p.id = rp.permission_id
        ORDER BY r.code, p.code")->fetchAll(PDO::FETCH_ASSOC);
    $matrix = [];
    foreach ($rows as $row) {
        $role = (string) $row['role_code'];
        $matrix[$role] ??= [];
        if (!empty($row['permission_code'])) {
            $matrix[$role][] = (string) $row['permission_code'];
        }
    }
    return $matrix;
}
