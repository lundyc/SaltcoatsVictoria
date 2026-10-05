<?php

declare(strict_types=1);

require_once __DIR__ . '/social_auth.php';
require_once __DIR__ . '/lib/functions.php';

// safe() and app_format_uk_date() now live in lib/functions.php (required
// above) so pages can use them without pulling in this file. The guards are
// belt-and-braces in case this file is ever loaded before lib/functions.php.
if (!function_exists('safe')) {
    /**
     * Escape output for HTML context.
     *
     * @param mixed $value
     */
    function safe($value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('app_format_uk_date')) {
    function app_format_uk_date(?string $value, string $fallback = 'Not set'): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $fallback;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($date === false) {
            return $value;
        }

        return $date->format('d/m/Y');
    }
}

/**
 * @return array{isAuthenticated: bool, authUsername: string, styleVersion: int}
 */
function app_bootstrap_state(): array
{
    $isAuthenticated = auth_is_authenticated();
    $styleCandidates = [
        @filemtime(__DIR__ . '/assets/css/style.css') ?: 0,
        @filemtime(__DIR__ . '/assets/css/social-publishing.css') ?: 0,
        @filemtime(__DIR__ . '/assets/css/player-sponsors-base.css') ?: 0,
    ];

    return [
        'isAuthenticated' => $isAuthenticated,
        'authUsername' => $isAuthenticated ? auth_get_username() : '',
        'styleVersion' => (int) max($styleCandidates) ?: time(),
    ];
}

function app_nav_link_class(string $currentPage, string $page): string
{
    return $currentPage === $page ? 'aria-current="page"' : '';
}

/**
 * The pages that call this now hard-exit unauthenticated requests (capability
 * gate) before any HTML is produced, so this only renders in states that can
 * no longer occur. It links to the single staff sign-in page rather than the
 * old in-page fetch-login form, so there is exactly one staff login surface.
 */
function app_render_login_modal(string $title, string $message): void
{
    ?>
    <div class="auth-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="loginModalTitle">
        <div class="auth-modal-card p-4">
            <h1 id="loginModalTitle" class="h4 mb-2"><?= safe($title) ?></h1>
            <p class="text-muted mb-4"><?= safe($message) ?></p>
            <a class="btn btn-brand w-100" href="/admin/login.php">Go to sign in</a>
        </div>
    </div>
    <?php
}

function app_render_primary_nav(string $currentPage, ?string $brandLabel = null): void
{
    // Match the modern header.php sidebar brand instead of a separate label.
    $brandLabel ??= (defined('APP_NAME') ? APP_NAME : 'Hub');

    $primaryLinks = [
        ['key' => 'home', 'label' => 'Club overview', 'href' => '/index.php'],
        ['key' => 'matches', 'label' => 'Match day', 'href' => '/matches.php'],
        ['key' => 'players', 'label' => 'Players', 'href' => '/players.php'],
        ['key' => 'sponsors', 'label' => 'Sponsors', 'href' => '/sponsors.php'],
        ['key' => 'league_table', 'label' => 'League table', 'href' => '/league_table.php'],
    ];
    $moreLinks = [
        ['key' => 'templates', 'label' => 'Template packs', 'href' => '/template_packs.php'],
        ['key' => 'stripe_dashboard', 'label' => 'Stripe Dashboard', 'href' => '/stripe_dashboard.php'],
        ['key' => 'settings', 'label' => 'Settings', 'href' => '/settings.php'],
    ];
    if (auth_is_admin()) {
        $moreLinks[] = ['key' => 'admin_users', 'label' => 'People & Users', 'href' => '/club_people.php'];
    }

    ?>
    <a class="hub-skip-link" href="#hubMainContent">Skip to main content</a>
    <nav class="legacy-toolbar" aria-label="Primary navigation">
        <a class="legacy-toolbar__brand" href="/admin/index.php" aria-label="<?= safe($brandLabel) ?> home">
            <img src="/admin/Saltcoats Victoria FC -White_Transparent.png" alt="" width="38" height="38">
            <span><?= safe($brandLabel) ?></span>
        </a>
        <div class="legacy-toolbar__links">
            <?php foreach ($primaryLinks as $link): ?>
                <a class="legacy-toolbar__link<?= $currentPage === $link['key'] ? ' is-active' : '' ?>" href="<?= safe($link['href']) ?>"<?= $currentPage === $link['key'] ? ' aria-current="page"' : '' ?>><?= safe($link['label']) ?></a>
            <?php endforeach; ?>
            <details class="legacy-toolbar__more">
                <summary>More</summary>
                <div class="legacy-toolbar__menu">
                    <?php foreach ($moreLinks as $link): ?>
                        <a class="legacy-toolbar__menu-link<?= $currentPage === $link['key'] ? ' is-active' : '' ?>" href="<?= safe($link['href']) ?>"<?= $currentPage === $link['key'] ? ' aria-current="page"' : '' ?>><?= safe($link['label']) ?></a>
                    <?php endforeach; ?>
                    <button id="logoutBtn" class="legacy-toolbar__menu-link legacy-toolbar__logout" type="button" data-csrf-token="<?= safe(auth_csrf_token()) ?>">Log out</button>
                </div>
            </details>
        </div>
    </nav>
    <?php
}

function app_render_auth_scripts(bool $isAuthenticated): void
{
    ?>
    <script>
        <?php if ($isAuthenticated): ?>
        (function() {
            var moreMenu = document.querySelector('.legacy-toolbar__more');
            if (moreMenu) {
                moreMenu.addEventListener('click', function(event) {
                    if (event.target.closest('a, button')) {
                        moreMenu.removeAttribute('open');
                    }
                });
                document.addEventListener('keydown', function(event) {
                    if (event.key === 'Escape' && moreMenu.open) {
                        moreMenu.removeAttribute('open');
                        moreMenu.querySelector('summary').focus();
                    }
                });
                document.addEventListener('click', function(event) {
                    if (moreMenu.open && !moreMenu.contains(event.target)) {
                        moreMenu.removeAttribute('open');
                    }
                });
            }

            var logoutButton = document.getElementById('logoutBtn');
            if (!logoutButton) {
                return;
            }

            logoutButton.addEventListener('click', function() {
                var button = this;
                var originalText = button.textContent;

                button.disabled = true;
                button.textContent = 'Logging Out...';

                fetch('auth_endpoint.php?action=logout', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: 'csrf_token=' + encodeURIComponent(button.getAttribute('data-csrf-token') || '')
                }).then(function(response) {
                    if (!response.ok) {
                        throw new Error('Logout failed.');
                    }
                }).then(function() {
                    window.location.href = 'index.php';
                }).catch(function() {
                    button.disabled = false;
                    button.textContent = originalText;
                });
            });
        })();
        <?php endif; ?>
    </script>
    <?php
}
