<?php

declare(strict_types=1);

/*
 * 301 map for URLs of the previous saltcoatsvictoria.co.uk site (the
 * SVFC__Backup scrape), so old Google results, bookmarks and inbound links
 * land on the matching page instead of a 404. Only consulted when the router
 * finds no match, so it can never shadow a live route.
 */

/** Exact old path (no leading slash) => new path. */
function legacy_redirect_exact(): array
{
    return [
        'info'                         => '/club/history',
        'campbell-park'                => '/club/stadium',
        'committee'                    => '/club/officials',
        'management'                   => '/staff',
        'management-1'                 => '/staff',
        'players'                      => '/team',
        'players-2'                    => '/team',
        'fixtures-2'                   => '/fixtures',
        'results-2'                    => '/results',
        'sponsors'                     => '/partners',
        'hospitality'                  => '/contact',
        'club-privacy-policy'          => '/privacy',
        'child-protection-policy'      => '/club/child-protection-policy',
        'code-of-conduct'              => '/club/code-of-conduct',
        'equality-diversity-inclusion' => '/club/equality-diversity-inclusion',
        'mission-vision-values'        => '/club/mission-vision-values',
        'sipg/1062'                    => '/club/ground-regulations',
        'menu/policies-8'              => '/club/policies',
        'menu/club-2'                  => '/club/history',
        'calendar/fixtures'            => '/fixtures',
    ];
}

/** Old path prefix (matched on the first segment) => new path. */
function legacy_redirect_prefixes(): array
{
    return [
        'committee'    => '/club/officials',
        'gct'          => '/club/honours',
        'playeraward'  => '/club/player-awards',
        'link'         => '/club/links',
        'sipg'         => '/club/policies',
        'player'       => '/team',
        'management'   => '/staff',
        'leaguetable'  => '/table',
        'matchesplayed' => '/results',
        'statistics'   => '/club/records',
        'menu'         => '/club/history',
    ];
}

/**
 * Old news URLs were /news/<slug cut at 50 chars>. If that exact slug is gone,
 * find the article whose slug starts with it.
 */
function legacy_news_slug(PDO $pdo, string $oldSlug): ?string
{
    if (!preg_match('/^[a-z0-9-]{8,80}$/', $oldSlug)) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT slug FROM news_articles WHERE slug LIKE :p AND ' . news_public_where() . ' ORDER BY published_at DESC LIMIT 1'
    );
    $stmt->execute([':p' => $oldSlug . '%']);
    $slug = $stmt->fetchColumn();
    return is_string($slug) && $slug !== '' ? $slug : null;
}

/** Target path for an old URL path (no slashes either side), or null. */
function legacy_redirect_target(string $path, PDO $pdo): ?string
{
    $path = strtolower($path);
    $exact = legacy_redirect_exact();
    if (isset($exact[$path])) {
        return $exact[$path];
    }

    if (preg_match('#^news/([^/]+)$#', $path, $m)) {
        try {
            $slug = legacy_news_slug($pdo, $m[1]);
        } catch (Throwable) {
            $slug = null;
        }
        return $slug !== null ? '/news/' . $slug : null;
    }

    $first = (string) strtok($path, '/');
    $prefixes = legacy_redirect_prefixes();
    return $prefixes[$first] ?? null;
}
