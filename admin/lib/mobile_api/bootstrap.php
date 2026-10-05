<?php
declare(strict_types=1);

namespace MyClubHub\Api;

// Do not load db.php/config.php: they start web sessions and perform schema DDL.
require_once dirname(__DIR__, 2) . '/env.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Resources.php';
require_once __DIR__ . '/Application.php';

function configuration(): array
{
    $file = \app_parse_env_file(dirname(__DIR__, 2) . '/.env');
    $get = static function (string $key, string $default = '') use ($file): string {
        $value = getenv($key);
        return $value !== false && $value !== '' ? $value : ($file[$key] ?? $default);
    };
    return [
        'host' => $get('HUB_DB_HOST', 'localhost'),
        'database' => $get('HUB_DB_NAME', 'Hub'),
        'fallback' => $get('HUB_DB_FALLBACK_NAME', 'PlayerSponsors'),
        'user' => $get('HUB_DB_USER'),
        'password' => $get('HUB_DB_PASS'),
        'base_url' => rtrim($get('MCH_API_BASE_URL', 'https://saltcoatsvictoria.co.uk'), '/'),
        'timezone' => $get('MCH_API_TIMEZONE', 'Europe/London'),
        'origins' => array_values(array_filter(array_map('trim', explode(',', $get('MCH_API_CORS_ORIGINS'))))),
    ];
}

function connect(array $config): \PDO
{
    foreach (array_unique([$config['database'], $config['fallback']]) as $database) {
        try {
            $pdo = new \PDO('mysql:host=' . $config['host'] . ';dbname=' . $database . ';charset=utf8mb4',
                $config['user'], $config['password'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            $pdo->exec("SET time_zone = '+00:00'");
            return $pdo;
        } catch (\PDOException) {
            // Same database preference as the website; never disclose credentials/DSNs.
        }
    }
    throw new ApiError(503, 'service_unavailable', 'The service is temporarily unavailable.');
}
