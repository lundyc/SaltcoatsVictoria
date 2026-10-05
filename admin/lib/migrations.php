<?php
declare(strict_types=1);

function ensureSchemaMigrationsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        migration VARCHAR(190) NOT NULL,
        executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_schema_migrations_migration (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * @return list<string>
 */
function migrationExecutedNames(PDO $pdo): array
{
    ensureSchemaMigrationsTable($pdo);
    return array_map('strval', $pdo->query('SELECT migration FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * @return list<string>
 */
function migrationFiles(string $directory): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = glob(rtrim($directory, '/') . '/*.{php,sql}', GLOB_BRACE) ?: [];
    sort($files, SORT_STRING);
    return array_values($files);
}

/**
 * @return list<array{migration:string,status:string,message:string}>
 */
function runDatabaseMigrations(PDO $pdo, string $directory): array
{
    ensureSchemaMigrationsTable($pdo);
    $executed = array_flip(migrationExecutedNames($pdo));
    $results = [];

    foreach (migrationFiles($directory) as $file) {
        $name = basename($file);
        if (isset($executed[$name])) {
            $results[] = ['migration' => $name, 'status' => 'skipped', 'message' => 'Already executed.'];
            continue;
        }

        $pdo->beginTransaction();
        try {
            if (str_ends_with($name, '.php')) {
                $migration = require $file;
                if (!is_callable($migration)) {
                    throw new RuntimeException("PHP migration {$name} must return a callable.");
                }
                $migration($pdo);
            } else {
                $sql = (string) file_get_contents($file);
                if (trim($sql) !== '') {
                    $pdo->exec($sql);
                }
            }

            $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
            $stmt->execute([':migration' => $name]);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            $results[] = ['migration' => $name, 'status' => 'executed', 'message' => 'Executed successfully.'];
            $executed[$name] = true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $results[] = ['migration' => $name, 'status' => 'failed', 'message' => $e->getMessage()];
            break;
        }
    }

    return $results;
}
