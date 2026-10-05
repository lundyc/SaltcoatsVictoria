<?php

declare(strict_types=1);

/*
 * Tiny path router. Routes are  'pattern' => 'page-file'  where the pattern may
 * contain {name} placeholders: {name} matches one path segment, {path} matches
 * the rest. The page file lives in public/pages/; placeholder values are passed
 * to it as local variables.
 */

/** @return array<string,string> */
function public_routes(): array
{
    return [
        ''                     => 'home',

        'sitemap.xml'          => 'sitemap',
        'robots.txt'           => 'robots',

        'news'                 => 'news_index',
        'news/feed.xml'        => 'news_feed',
        'news/category/{slug}' => 'news_index',
        'news/{slug}'          => 'news_show',

        'fixtures'    => 'fixtures',
        'results'     => 'results',
        'table'       => 'table',
        'table/pdf'   => 'table_pdf',
        'match/{id}'  => 'match',

        // Club shop — presentation ported into the site design; all cart /
        // order / Stripe logic reuses lib/shop.php + lib/shop_basket.php.
        'shop'                  => 'shop_index',
        'shop/basket'           => 'shop_basket',
        'shop/checkout'         => 'shop_checkout',
        'shop/order/{token}'    => 'shop_order',
        'shop/p/{slug}'         => 'shop_product',

        // Match tickets — reuses lib/match_tickets.php (guest checkout).
        'tickets'               => 'tickets',
        'tickets/order/{token}' => 'ticket_order',

        'team'        => 'team',
        'team/{slug}' => 'player',
        'staff'       => 'staff',

        'gallery'        => 'gallery_index',
        'gallery/{slug}' => 'gallery_album',

        'club/history'         => 'club_history',
        'club/stadium'         => 'club_stadium',
        'club/officials'       => 'officials',
        'club/honours'         => 'honours',
        'club/player-awards'   => 'player_awards',
        'club/policies'        => 'policies',
        'club/results/{slug}'  => 'history_match',
        'club/results'         => 'history_results',
        'club/records'         => 'history_records',
        'club/{slug}'          => 'club_page',
        'contact'     => 'contact',
        'privacy'     => 'privacy',

        'partners'    => 'partners',
    ];
}

/** Normalised request path: PUBLIC_BASE stripped, no surrounding slashes/query. */
function public_request_path(): string
{
    $uri = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $uri = rawurldecode($uri);

    if (PUBLIC_BASE !== '' && str_starts_with($uri, PUBLIC_BASE)) {
        $uri = substr($uri, strlen(PUBLIC_BASE));
    }

    return trim($uri, '/');
}

/**
 * @return array{0: array{page:string,status?:int}, 1: array<string,string>}
 */
function public_router_match(string $path): array
{
    foreach (public_routes() as $pattern => $page) {
        $names = [];

        // Swap placeholders for collision-proof sentinels first, quote the
        // literal text, then expand the sentinels into capture groups.
        $sketch = preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $m) use (&$names): string {
                $names[] = $m[1];
                return $m[1] === 'path' ? "\0PATH\0" : "\0SEG\0";
            },
            $pattern
        );

        $regex = '#^' . str_replace(
            [preg_quote("\0PATH\0", '#'), preg_quote("\0SEG\0", '#')],
            ['(.+)', '([^/]+)'],
            preg_quote((string) $sketch, '#')
        ) . '$#';

        if (preg_match($regex, $path, $matches)) {
            $params = [];
            foreach ($names as $i => $name) {
                $params[$name] = $matches[$i + 1] ?? '';
            }
            $GLOBALS['pub_route'] = $path;
            return [['page' => $page, 'status' => 200], $params];
        }
    }

    $GLOBALS['pub_route'] = $path;
    return [['page' => 'error', 'status' => 404], ['title' => 'Page not found']];
}
