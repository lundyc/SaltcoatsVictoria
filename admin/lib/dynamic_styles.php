<?php
declare(strict_types=1);

/**
 * Register rendered, page-specific CSS and return a session-bound stylesheet URL.
 * Source rules live in assets/css/*.dynamic.php templates; this only transports
 * their rendered result to the browser as a real stylesheet response.
 */
function hub_dynamic_stylesheet_register(string $css): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $token = bin2hex(random_bytes(16));
    $styles = is_array($_SESSION['hub_dynamic_stylesheets'] ?? null) ? $_SESSION['hub_dynamic_stylesheets'] : [];
    $styles[$token] = ['css' => $css, 'created' => time()];
    $styles = array_filter($styles, static fn(array $entry): bool => (int) ($entry['created'] ?? 0) >= time() - 1800);
    if (count($styles) > 20) {
        uasort($styles, static fn(array $a, array $b): int => ((int) ($a['created'] ?? 0)) <=> ((int) ($b['created'] ?? 0)));
        $styles = array_slice($styles, -20, null, true);
    }
    $_SESSION['hub_dynamic_stylesheets'] = $styles;
    return '/assets/css/dynamic.php?token=' . rawurlencode($token);
}
