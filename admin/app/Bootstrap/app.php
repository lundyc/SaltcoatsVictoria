<?php
require_once __DIR__ . '/../Support/helpers.php';
require_once __DIR__ . '/../Support/response.php';

$appConfig = require base_path('config/app.php');
$databaseConfig = require base_path('config/database.php');

date_default_timezone_set($appConfig['timezone'] ?? 'UTC');

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
if (!headers_sent() && session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = base_path('app/');

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});
