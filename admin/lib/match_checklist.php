<?php

declare(strict_types=1);

require_once __DIR__ . '/publishing_history.php';
require_once __DIR__ . '/match_overview.php';
require_once __DIR__ . '/match_sponsorship.php';

function match_checklist_ensure_schema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS match_checklist_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            fixture_id BIGINT UNSIGNED NOT NULL,
            item_key VARCHAR(100) NULL,
            label VARCHAR(255) NOT NULL DEFAULT '',
            is_custom TINYINT(1) NOT NULL DEFAULT 0,
            checked TINYINT(1) NOT NULL DEFAULT 0,
            checked_at DATETIME NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY match_checklist_items_fixture_key (fixture_id, item_key),
            KEY match_checklist_items_fixture (fixture_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $ensured = true;
}

/**
 * Every match-day graphic/post the club can generate is a natural checklist
 * item, so the pre-match items are always offered and the post-match items
 * are derived straight from the events actually logged for this fixture —
 * there is nothing to keep in sync by hand.
 *
 * @param list<array<string, mixed>> $overviewEvents
 * @param list<string> $overviewStarters
 * @param list<array<string, mixed>> $sponsorShareRows
 * @return list<array{key: string, label: string, auto: bool}>
 */
function match_checklist_system_items(
    PDO $pdo,
    array $fixture,
    array $overviewEvents,
    array $overviewStarters,
    array $sponsorShareRows,
    string $opponent
): array {
    $fixtureId = (int) ($fixture['id'] ?? 0);
    $items = [];

    // Matchday prep workflow, in the order it actually happens:
    // 1. Confirm sponsors paid, 2. Next Match posted, 3. Sponsor shoutout
    // posted, 4. Matchday post/graphic posted, 5. Starting XI selected/
    // posted — then live match events below.
    $activeSponsorships = array_values(array_filter(
        getMatchSponsorshipRows($pdo, $fixtureId),
        static fn(array $row): bool => in_array((string) ($row['sponsorship_role'] ?? ''), ['match_day', 'match_ball'], true)
    ));
    if ($activeSponsorships !== []) {
        $allSponsorsPaid = true;
        foreach ($activeSponsorships as $sponsorshipRow) {
            $isComplimentary = (int) ($sponsorshipRow['is_complimentary'] ?? 0) === 1;
            if (!$isComplimentary && (int) ($sponsorshipRow['paid'] ?? 0) !== 1) {
                $allSponsorsPaid = false;
                break;
            }
        }
        $items[] = [
            'key' => 'sponsors_paid',
            'label' => 'Confirm sponsors paid',
            'auto' => $allSponsorsPaid,
        ];
    }

    $items[] = [
        'key' => 'next_match_posted',
        'label' => 'Next Match posted',
        'auto' => hub_social_post_exists($pdo, $fixtureId, 'next_match'),
    ];

    if ($sponsorShareRows !== []) {
        $items[] = [
            'key' => 'sponsors_posted',
            'label' => 'Match sponsors shoutout posted',
            'auto' => hub_social_post_exists($pdo, $fixtureId, 'sponsor_shoutout'),
        ];
    }

    $items[] = [
        'key' => 'matchday_posted',
        'label' => 'Matchday post/graphic posted',
        'auto' => hub_social_post_exists($pdo, $fixtureId, 'matchday'),
    ];

    $items[] = [
        'key' => 'lineup_selected',
        'label' => 'Starting XI selected',
        'auto' => $overviewStarters !== [],
    ];
    $items[] = [
        'key' => 'starting_xi_posted',
        'label' => 'Starting XI graphic posted',
        'auto' => hub_social_post_exists($pdo, $fixtureId, 'starting_xi'),
    ];

    $shareableEventTypes = [
        'kickoff' => 'Kick Off',
        'goal' => 'Goal',
        'half_time' => 'Half Time',
        'full_time' => 'Full Time',
        'yellow_card' => 'Yellow Card',
        'red_card' => 'Red Card',
        'substitution' => 'Substitution',
        'player_of_match' => 'Player of the Match',
    ];
    foreach (matchOverviewSortedEvents($overviewEvents) as $event) {
        $type = (string) ($event['type'] ?? '');
        if (!isset($shareableEventTypes[$type])) {
            continue;
        }
        $eventId = (string) ($event['id'] ?? '');
        if ($eventId === '') {
            continue;
        }
        $items[] = [
            'key' => 'event_' . $eventId,
            'label' => matchOverviewEventTitle($event, $opponent) . ' graphic posted',
            'auto' => hub_social_post_exists($pdo, $fixtureId, $type, $eventId),
        ];
    }

    return $items;
}

/**
 * @return list<array<string, mixed>>
 */
function match_checklist_load_rows(PDO $pdo, int $fixtureId): array
{
    match_checklist_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM match_checklist_items WHERE fixture_id = :fixture_id ORDER BY is_custom ASC, sort_order ASC, id ASC'
    );
    $stmt->execute([':fixture_id' => $fixtureId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Build the full checklist for a fixture: system items (auto-detected, with
 * any manual override applied) followed by freeform custom items.
 *
 * @param list<array{key: string, label: string, auto: bool}> $systemItems
 * @return list<array{key: string, label: string, checked: bool, auto: bool, is_custom: bool, id: int|null}>
 */
function match_checklist_build_list(PDO $pdo, int $fixtureId, array $systemItems): array
{
    $rows = match_checklist_load_rows($pdo, $fixtureId);
    $byKey = [];
    $custom = [];
    foreach ($rows as $row) {
        if ((int) ($row['is_custom'] ?? 0) === 1) {
            $custom[] = $row;
        } elseif (trim((string) ($row['item_key'] ?? '')) !== '') {
            $byKey[(string) $row['item_key']] = $row;
        }
    }

    $list = [];
    foreach ($systemItems as $definition) {
        $stored = $byKey[$definition['key']] ?? null;
        $manualChecked = $stored !== null && (int) ($stored['checked'] ?? 0) === 1;
        $list[] = [
            'key' => $definition['key'],
            'label' => $definition['label'],
            'checked' => $definition['auto'] || $manualChecked,
            'auto' => $definition['auto'],
            'is_custom' => false,
            'id' => null,
        ];
    }
    foreach ($custom as $row) {
        $list[] = [
            'key' => null,
            'label' => (string) ($row['label'] ?? ''),
            'checked' => (int) ($row['checked'] ?? 0) === 1,
            'auto' => false,
            'is_custom' => true,
            'id' => (int) $row['id'],
        ];
    }

    return $list;
}

function match_checklist_set_system_checked(PDO $pdo, int $fixtureId, string $itemKey, bool $checked, ?int $userId = null): void
{
    match_checklist_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO match_checklist_items (fixture_id, item_key, label, is_custom, checked, checked_at, created_by)
         VALUES (:fixture_id, :item_key, \'\', 0, :checked, IF(:checked = 1, NOW(), NULL), :created_by)
         ON DUPLICATE KEY UPDATE
            checked = VALUES(checked),
            checked_at = VALUES(checked_at)'
    );
    $stmt->execute([
        ':fixture_id' => $fixtureId,
        ':item_key' => $itemKey,
        ':checked' => $checked ? 1 : 0,
        ':created_by' => $userId,
    ]);
}

function match_checklist_add_custom(PDO $pdo, int $fixtureId, string $label, ?int $userId = null): int
{
    match_checklist_ensure_schema($pdo);
    $label = trim($label);
    if ($label === '') {
        throw new InvalidArgumentException('Checklist item text is required.');
    }
    $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM match_checklist_items WHERE fixture_id = :fixture_id AND is_custom = 1');
    $orderStmt->execute([':fixture_id' => $fixtureId]);
    $nextOrder = (int) $orderStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO match_checklist_items (fixture_id, item_key, label, is_custom, checked, sort_order, created_by)
         VALUES (:fixture_id, NULL, :label, 1, 0, :sort_order, :created_by)'
    );
    $stmt->execute([
        ':fixture_id' => $fixtureId,
        ':label' => mb_substr($label, 0, 255),
        ':sort_order' => $nextOrder,
        ':created_by' => $userId,
    ]);
    return (int) $pdo->lastInsertId();
}

function match_checklist_set_custom_checked(PDO $pdo, int $fixtureId, int $id, bool $checked): bool
{
    match_checklist_ensure_schema($pdo);
    $stmt = $pdo->prepare(
        'UPDATE match_checklist_items
         SET checked = :checked, checked_at = IF(:checked2 = 1, NOW(), NULL)
         WHERE id = :id AND fixture_id = :fixture_id AND is_custom = 1'
    );
    $stmt->execute([
        ':checked' => $checked ? 1 : 0,
        ':checked2' => $checked ? 1 : 0,
        ':id' => $id,
        ':fixture_id' => $fixtureId,
    ]);
    return $stmt->rowCount() > 0;
}

function match_checklist_delete_custom(PDO $pdo, int $fixtureId, int $id): bool
{
    match_checklist_ensure_schema($pdo);
    $stmt = $pdo->prepare('DELETE FROM match_checklist_items WHERE id = :id AND fixture_id = :fixture_id AND is_custom = 1');
    $stmt->execute([':id' => $id, ':fixture_id' => $fixtureId]);
    return $stmt->rowCount() > 0;
}
