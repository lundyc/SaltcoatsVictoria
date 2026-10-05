<?php

declare(strict_types=1);

// A season ticket "shopping basket" — plain PHP session storage (the generic
// default session, same one csrf_field()/csrf_check() already use), since
// this exists entirely before anyone is identified or logged in.

const SEASON_TICKET_BASKET_SESSION_KEY = 'season_ticket_basket';
const SEASON_TICKET_FREE_SIGNUP_CODE_SESSION_KEY = 'season_ticket_free_signup_code';

function season_ticket_basket_start(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION[SEASON_TICKET_BASKET_SESSION_KEY]) || !is_array($_SESSION[SEASON_TICKET_BASKET_SESSION_KEY])) {
        $_SESSION[SEASON_TICKET_BASKET_SESSION_KEY] = [];
    }
}

/**
 * @return list<array{id: string, type_id: int, name?: string}>
 */
function season_ticket_basket_items(): array
{
    season_ticket_basket_start();
    return $_SESSION[SEASON_TICKET_BASKET_SESSION_KEY];
}

function season_ticket_basket_add(int $typeId, string $name = ''): void
{
    season_ticket_basket_start();
    $_SESSION[SEASON_TICKET_BASKET_SESSION_KEY][] = [
        'id' => bin2hex(random_bytes(6)),
        'type_id' => $typeId,
        'name' => trim($name),
    ];
}

function season_ticket_basket_remove(string $itemId): void
{
    season_ticket_basket_start();
    $_SESSION[SEASON_TICKET_BASKET_SESSION_KEY] = array_values(array_filter(
        $_SESSION[SEASON_TICKET_BASKET_SESSION_KEY],
        static fn(array $item): bool => $item['id'] !== $itemId
    ));
}

function season_ticket_basket_clear(): void
{
    season_ticket_basket_start();
    $_SESSION[SEASON_TICKET_BASKET_SESSION_KEY] = [];
    unset($_SESSION[SEASON_TICKET_FREE_SIGNUP_CODE_SESSION_KEY]);
}

/**
 * Clears the basket in the *default* session by its exact name/id, safe to
 * call even after $_SESSION has since been switched over to a different
 * named session (e.g. member_auth's isolated member_session) — reopening a
 * session by session_name()+session_start() alone is unreliable once
 * another session has been active earlier in the same request (see
 * member_auth_start_session()'s own handling of the same underlying PHP
 * behaviour), so this takes the exact id captured before any switch
 * happened rather than trying to "get back" to it implicitly.
 */
function season_ticket_basket_clear_default_session(string $defaultSessionName, string $defaultSessionId): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    session_name($defaultSessionName);
    session_id($defaultSessionId);
    // member_auth_start_session() (if it ran earlier this request) leaves its
    // path=/members cookie params as PHP's session-cookie default for the
    // rest of the request — without resetting them here, re-opening the
    // default session below would emit a second, wrongly-scoped copy of this
    // cookie alongside the correct one.
    session_set_cookie_params(['path' => '/']);
    session_start();
    $_SESSION[SEASON_TICKET_BASKET_SESSION_KEY] = [];
    unset($_SESSION[SEASON_TICKET_FREE_SIGNUP_CODE_SESSION_KEY]);
    session_write_close();
}

/**
 * @param list<array<string, mixed>> $types season_ticket_types rows, keyed usable by id
 * @return array{items: list<array<string, mixed>>, total: float}
 */
function season_ticket_basket_summary(array $types): array
{
    $typesById = [];
    foreach ($types as $type) {
        $typesById[(int) $type['id']] = $type;
    }

    $items = [];
    $total = 0.0;
    foreach (season_ticket_basket_items() as $item) {
        $type = $typesById[$item['type_id']] ?? null;
        if (!$type) {
            continue; // Ticket type no longer on sale — silently drop from the summary.
        }
        $price = (float) $type['price'];
        $items[] = [
            'id' => $item['id'],
            'name' => (string) ($item['name'] ?? ''),
            'type_id' => $item['type_id'],
            'type_name' => (string) $type['name'],
            'price' => $price,
        ];
        $total += $price;
    }

    return ['items' => $items, 'total' => round($total, 2)];
}
