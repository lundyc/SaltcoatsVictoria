<?php

use PDO;
use RuntimeException;

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $base = dirname(__DIR__, 2);
        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . ltrim($path, '/');
    }
}

if (!function_exists('app_config')) {
    function app_config(?string $key = null, $default = null)
    {
        global $appConfig;

        if ($key === null) {
            return $appConfig;
        }

        $segments = explode('.', $key);
        $value = $appConfig;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('db')) {
    function db(): PDO
    {
        static $connection;

        if ($connection instanceof PDO) {
            return $connection;
        }

        $configPath = base_path('config/database.php');
        if (!file_exists($configPath)) {
            throw new RuntimeException('Database configuration file not found.');
        }

        $config = require $configPath;
        if (!is_array($config)) {
            throw new RuntimeException('Database configuration is invalid.');
        }

        foreach (['host', 'database', 'username', 'password', 'charset'] as $key) {
            if (!is_string($config[$key] ?? null) || $config[$key] === '') {
                throw new RuntimeException('Database configuration missing: ' . $key);
            }
        }

        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $config['host'],
            $config['database'],
            $config['charset']
        );

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $connection = new PDO($dsn, $config['username'], $config['password'], $options);

        return $connection;
    }
}

if (!function_exists('request_context')) {
    function request_context(?string $key = null, $value = null)
    {
        if (!isset($GLOBALS['_requestContext'])) {
            $GLOBALS['_requestContext'] = [];
        }

        if ($key === null) {
            return $GLOBALS['_requestContext'];
        }

        if ($value === null && func_num_args() === 1) {
            return $GLOBALS['_requestContext'][$key] ?? null;
        }

        $GLOBALS['_requestContext'][$key] = $value;
        return $value;
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = [], ?string $layout = 'layouts/app')
    {
        $shared = [
            'club' => request_context('club') ?? [
                'name' => app_config('name'),
                'slug' => request_context('club_slug'),
                'theme' => app_config('theme'),
            ],
            'clubSlug' => request_context('club_slug'),
            'authUser' => request_context('user'),
        ];

        foreach ($shared as $key => $value) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        $viewPath = base_path('app/Views/' . $template . '.php');
        if (!file_exists($viewPath)) {
            throw new RuntimeException("View {$template} not found");
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $viewPath;
        $content = ob_get_clean();

        if ($layout === null) {
            echo $content;
            return $content;
        }

        $layoutPath = base_path('app/Views/' . $layout . '.php');
        if (!file_exists($layoutPath)) {
            throw new RuntimeException("Layout {$layout} not found");
        }

        include $layoutPath;
        return '';
    }
}

if (!function_exists('component')) {
    function component(string $component, array $data = []): void
    {
        $componentPath = base_path('app/Views/components/' . $component . '.php');
        if (!file_exists($componentPath)) {
            throw new RuntimeException("Component {$component} not found");
        }

        extract($data, EXTR_SKIP);
        include $componentPath;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        $token = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_token" value="' . $token . '">';
    }
}

if (!function_exists('verify_csrf')) {
    function verify_csrf(?string $token = null): void
    {
        $provided = $token ?? ($_POST['_token'] ?? '');
        if (!hash_equals(csrf_token(), (string) $provided)) {
            abort(403, ['message' => 'Invalid CSRF token.'], 'layouts/auth');
        }
    }
}

if (!function_exists('club_url')) {
    function club_url(string $path = ''): string
    {
        $slug = request_context('club_slug');
        if (empty($slug)) {
            return '/';
        }

        $path = trim($path, '/');
        return '/' . rawurlencode($slug) . ($path !== '' ? '/' . $path : '');
    }
}

if (!function_exists('flash')) {
    function flash(string $key, $value = null)
    {
        if (!isset($_SESSION['_flash'])) {
            $_SESSION['_flash'] = [];
        }

        if ($value === null) {
            $stored = $_SESSION['_flash'][$key] ?? null;
            unset($_SESSION['_flash'][$key]);
            return $stored;
        }

        $_SESSION['_flash'][$key] = $value;
        return $value;
    }
}

if (!function_exists('slugify')) {
    function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
        $value = trim($value, '-');
        $value = preg_replace('/-+/', '-', $value);

        return $value ?? '';
    }
}

if (!function_exists('current_club_role')) {
    function current_club_role(): ?string
    {
        $cached = request_context('club_role');
        if ($cached !== null) {
            return $cached;
        }

        $club = request_context('club');
        $userId = $_SESSION['user_id'] ?? null;
        if (!$club || !$userId) {
            return null;
        }

        if (!isset($_SESSION['_membership_roles'])) {
            $_SESSION['_membership_roles'] = [];
        }

        $clubId = (int) $club['id'];
        if (array_key_exists($clubId, $_SESSION['_membership_roles'])) {
            $role = $_SESSION['_membership_roles'][$clubId];
            request_context('club_role', $role);
            return $role;
        }

        $stmt = db()->prepare('SELECT r.code
            FROM club_memberships cm
            INNER JOIN roles r ON r.id = cm.role_id
            WHERE cm.club_id = :club AND cm.user_id = :user LIMIT 1');
        $stmt->execute([
            'club' => $clubId,
            'user' => $userId,
        ]);
        $role = $stmt->fetchColumn() ?: null;

        $_SESSION['_membership_roles'][$clubId] = $role;
        request_context('club_role', $role);

        return $role;
    }
}

if (!function_exists('is_club_admin')) {
    function is_club_admin(): bool
    {
        return current_club_role() === 'club_admin';
    }
}
