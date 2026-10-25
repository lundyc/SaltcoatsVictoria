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
        // Old match / player / news / sponsor detail pages we cannot map one-to-one
        // (e.g. /results/it/365): send them to the matching section, not a 404.
        'fixtures'     => '/fixtures',
        'results'      => '/results',
        'players'      => '/team',
        'news'         => '/news',
        'sponsorplayer' => '/partners',
    ];
}

/**
 * Fixture id for an old "YYYYMMDD-opponent" match slug, or null. The old site
 * truncated the opponent name, so the date is the key; the slug only breaks a
 * tie if two fixtures ever share a date.
 */
function legacy_fixture_id(PDO $pdo, string $ymd, string $oldSlug): ?int
{
    $stmt = $pdo->prepare(
        'SELECT f.id, COALESCE(o.clubname, f.opponent) AS opp
         FROM match_fixtures f LEFT JOIN match_opponents o ON o.id = f.opponent_id
         WHERE f.match_date = :d'
    );
    $stmt->execute([':d' => substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2)]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) === 1) {
        return (int) $rows[0]['id'];
    }
    foreach ($rows as $r) {
        $opp = trim((string) preg_replace('~[^a-z0-9]+~', '-', strtolower((string) $r['opp'])), '-');
        if ($oldSlug !== '' && $opp !== '' && (str_starts_with($opp, $oldSlug) || str_starts_with($oldSlug, $opp))) {
            return (int) $r['id'];
        }
    }
    return null;
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

    // Old match pages: /fixtures|results/20260919-glasgow-univer (usually carrying
    // filter query strings) and /matchesplayed/<id>/<player>/20250405-west-park-united.
    if (preg_match('#^(?:fixtures|results|matchesplayed)/(?:.+/)?(\d{8})-([a-z0-9-]*)$#', $path, $m)) {
        try {
            $id = legacy_fixture_id($pdo, $m[1], $m[2]);
        } catch (Throwable) {
            $id = null;
        }
        if ($id !== null) {
            return '/match/' . $id;
        }
    }

    // Old player pages: /player/<name>, /players/<name>, /matchesplayed/<id>/<name>
    // -> the player's profile.
    if (preg_match('#^(?:players?|matchesplayed/\d+)/([a-z0-9-]+)$#', $path, $m) && !ctype_digit($m[1]) && $m[1] !== 'it') {
        try {
            $player = squad_public_find($pdo, $m[1]);
        } catch (Throwable) {
            $player = null;
        }
        if ($player !== null) {
            return '/team/' . $player['slug'];
        }
    }

    $first = (string) strtok($path, '/');
    $prefixes = legacy_redirect_prefixes();
    return $prefixes[$first] ?? null;
}
