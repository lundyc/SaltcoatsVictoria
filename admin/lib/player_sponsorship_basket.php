<?php

declare(strict_types=1);

// A player kit sponsorship "shopping basket" — plain PHP session storage,
// same idiom as lib/season_ticket_basket.php, since this exists entirely
// before anyone is identified (this shop is guest-checkout only, no login).

const PLAYER_SPONSORSHIP_BASKET_SESSION_KEY = 'player_sponsorship_basket';

function player_sponsorship_basket_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION[PLAYER_SPONSORSHIP_BASKET_SESSION_KEY]) || !is_array($_SESSION[PLAYER_SPONSORSHIP_BASKET_SESSION_KEY])) {
        $_SESSION[PLAYER_SPONSORSHIP_BASKET_SESSION_KEY] = [];
    }
}

/**
 * @return list<array{id: string, player_id: int, package_code: string}>
 */
function player_sponsorship_basket_items(): array
{
    player_sponsorship_basket_start();
    return $_SESSION[PLAYER_SPONSORSHIP_BASKET_SESSION_KEY];
}

function player_sponsorship_basket_add(int $playerId, string $packageCode): void
{
    player_sponsorship_basket_start();
    foreach ($_SESSION[PLAYER_SPONSORSHIP_BASKET_SESSION_KEY] as $item) {
        if ((int) $item['player_id'] === $playerId && (string) $item['package_code'] === $packageCode) {
            return; // Already in the basket — adding again is a no-op, not a duplicate line.
        }
    }
    $_SESSION[PLAYER_SPONSORSHIP_BASKET_SESSION_KEY][] = [
        'id' => bin2hex(random_bytes(6)),
        'player_id' => $playerId,
        'package_code' => $packageCode,
    ];
}

function player_sponsorship_basket_remove(string $itemId): void
{
    player_sponsorship_basket_start();
    $_SESSION[PLAYER_SPONSORSHIP_BASKET_SESSION_KEY] = array_values(array_filter(
        $_SESSION[PLAYER_SPONSORSHIP_BASKET_SESSION_KEY],
        static fn(array $item): bool => $item['id'] !== $itemId
    ));
}

function player_sponsorship_basket_clear(): void
{
    player_sponsorship_basket_start();
    $_SESSION[PLAYER_SPONSORSHIP_BASKET_SESSION_KEY] = [];
}

/**
 * Re-checks each basket line against *live* availability, silently dropping
 * anything that's since gone (someone else bought it) — same principle as
 * season_ticket_basket_summary() dropping a discontinued ticket type.
 *
 * @param list<array<string, mixed>> $playersWithAvailability from player_sponsorship_shop_players_with_availability()
 * @return array{items: list<array<string, mixed>>, total: float}
 */
function player_sponsorship_basket_summary(array $playersWithAvailability): array
{
    $playersById = [];
    foreach ($playersWithAvailability as $player) {
        $playersById[(int) $player['id']] = $player;
    }

    $items = [];
    $total = 0.0;
    foreach (player_sponsorship_basket_items() as $item) {
        $player = $playersById[(int) $item['player_id']] ?? null;
        if (!$player) {
            continue;
        }
        $packageState = null;
        foreach ($player['packages'] as $candidate) {
            if ($candidate['package_code'] === $item['package_code']) {
                $packageState = $candidate;
                break;
            }
        }
        if (!$packageState || !$packageState['available']) {
            continue;
        }
        $items[] = [
            'id' => $item['id'],
            'player_id' => (int) $player['id'],
            'player_name' => (string) $player['name'],
            'package_code' => (string) $packageState['package_code'],
            'package_name' => (string) $packageState['package_name'],
            'price' => (float) $packageState['price'],
        ];
        $total += (float) $packageState['price'];
    }

    return ['items' => $items, 'total' => round($total, 2)];
}

/**
 * @return array<string, bool> keyed "playerId:packageCode" for quick "is this already in the basket?" lookups
 */
function player_sponsorship_basket_key_set(): array
{
    $set = [];
    foreach (player_sponsorship_basket_items() as $item) {
        $set[$item['player_id'] . ':' . $item['package_code']] = true;
    }
    return $set;
}
