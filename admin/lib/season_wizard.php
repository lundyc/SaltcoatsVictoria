<?php
declare(strict_types=1);

/**
 * "Add season" setup wizard: everything that has a price and belongs to a season.
 *
 * Per-season data is written straight onto the new season:
 *   - player and match-day/match-ball sponsorship prices (columns on seasons / match_season_pricing)
 *   - season ticket types and prices, plus which competitions each one covers
 *
 * Some prices are club-wide, not per season: hospitality, matchday ticket package defaults and sponsorship
 * package prices. Changing them while the current season is still running would change the live site
 * today, so the wizard stores only the CHANGED values as a plan against the new season and applies
 * it the moment that season is made current (season_wizard_apply_plan()).
 */

require_once __DIR__ . '/hospitality.php';
require_once __DIR__ . '/match_tickets.php';
require_once __DIR__ . '/season_tickets.php';
require_once __DIR__ . '/season_pass_rules.php';
require_once __DIR__ . '/sponsorship_catalog.php';

/** Create every table the wizard touches. Call BEFORE beginTransaction(): CREATE TABLE commits implicitly. */
function season_wizard_prepare(PDO $pdo): void
{
    hospitality_ensure_schema($pdo);
    ensureMatchTicketSchema($pdo);
    ensureSeasonTicketSchema($pdo);
    ensureSeasonPassRuleSchema($pdo);
    ensureSponsorshipCatalogSchema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS season_planned_prices (
        season_id INT UNSIGNED NOT NULL,
        payload MEDIUMTEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        applied_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (season_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function season_wizard_money(mixed $value): float
{
    $raw = trim((string) $value);
    $clean = preg_replace('/[^0-9.]/', '', $raw) ?? '';
    return ($clean === '' || str_contains($raw, '-')) ? 0.0 : round((float) $clean, 2);
}

/** Hospitality setting keys the wizard can plan, with how each value is cleaned. */
function season_wizard_hospitality_keys(): array
{
    return [
        'price_individual_league' => 'money', 'price_individual_cup' => 'money',
        'price_group_league' => 'money', 'price_group_cup' => 'money',
        'price_corporate_league' => 'money', 'price_corporate_cup' => 'money',
        'capacity' => 'int', 'group_min' => 'int', 'individual_max' => 'int',
        'corporate_guests' => 'int', 'arrival_minutes_before' => 'int',
    ];
}

/**
 * Work out which club-wide prices were changed in the wizard. Only differences from today's values are
 * kept, so applying the plan later can never overwrite something somebody else edited in the meantime.
 *
 * @param array<string, mixed> $post
 * @return array{hospitality: array<string,string>, tickets: array<int,float>, packages: array<int,array<string,float|null>>}
 */
function season_wizard_build_plan(PDO $pdo, array $post): array
{
    $plan = ['hospitality' => [], 'tickets' => [], 'packages' => []];

    $current = hospitality_settings($pdo);
    foreach (season_wizard_hospitality_keys() as $key => $kind) {
        if (!isset($post['hosp'][$key])) {
            continue;
        }
        $new = $kind === 'money'
            ? number_format(season_wizard_money($post['hosp'][$key]), 2, '.', '')
            : (string) max(0, (int) $post['hosp'][$key]);
        if ($new !== (string) ($current[$key] ?? '')) {
            $plan['hospitality'][$key] = $new;
        }
    }

    foreach (getMatchTicketPackages($pdo) as $package) {
        $id = (int) $package['id'];
        if (!isset($post['tickets'][$id])) {
            continue;
        }
        $new = season_wizard_money($post['tickets'][$id]);
        if (abs($new - (float) $package['default_price']) > 0.004) {
            $plan['tickets'][$id] = $new;
        }
    }

    foreach (getSponsorshipPackages($pdo) as $package) {
        $id = (int) $package['id'];
        if (!isset($post['pkg'][$id])) {
            continue;
        }
        $row = [];
        foreach (['amount', 'away_amount', 'renewal_amount'] as $column) {
            if (!array_key_exists($column, $post['pkg'][$id])) {
                continue;
            }
            $raw = trim((string) $post['pkg'][$id][$column]);
            $old = $package[$column] === null ? null : (float) $package[$column];
            if ($raw === '' && $old === null) {
                continue;
            }
            $new = season_wizard_money($raw);
            if ($old === null || abs($new - $old) > 0.004) {
                $row[$column] = $new;
            }
        }
        if ($row !== []) {
            $plan['packages'][$id] = $row;
        }
    }
    return $plan;
}

function season_wizard_plan_is_empty(array $plan): bool
{
    return $plan['hospitality'] === [] && $plan['tickets'] === [] && $plan['packages'] === [];
}

function season_wizard_store_plan(PDO $pdo, int $seasonId, array $plan): void
{
    $pdo->prepare('INSERT INTO season_planned_prices (season_id, payload, applied_at) VALUES (:s, :p, NULL)
        ON DUPLICATE KEY UPDATE payload = VALUES(payload), applied_at = NULL')
        ->execute([':s' => $seasonId, ':p' => json_encode($plan, JSON_THROW_ON_ERROR)]);
}

/** The waiting (not yet applied) plan for a season, or null. */
function season_wizard_pending_plan(PDO $pdo, int $seasonId): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT payload FROM season_planned_prices WHERE season_id = :s AND applied_at IS NULL');
        $stmt->execute([':s' => $seasonId]);
        $payload = $stmt->fetchColumn();
    } catch (Throwable $exception) {
        return null; // table not created yet: nothing has ever been planned
    }
    if (!is_string($payload)) {
        return null;
    }
    $plan = json_decode($payload, true);
    return is_array($plan) && !season_wizard_plan_is_empty($plan) ? $plan : null;
}

/** Write a plan's club-wide prices to the live settings. Returns how many values changed. */
function season_wizard_apply_plan(PDO $pdo, int $seasonId): int
{
    $plan = season_wizard_pending_plan($pdo, $seasonId);
    if ($plan === null) {
        return 0;
    }
    $count = 0;
    if ($plan['hospitality'] !== []) {
        hospitality_save_settings($pdo, $plan['hospitality']);
        $count += count($plan['hospitality']);
    }
    $ticket = $pdo->prepare('UPDATE match_ticket_packages SET default_price = :p WHERE id = :id');
    foreach ($plan['tickets'] as $id => $price) {
        $ticket->execute([':p' => $price, ':id' => (int) $id]);
        $count++;
    }
    foreach ($plan['packages'] as $id => $columns) {
        foreach ($columns as $column => $value) {
            if (!in_array($column, ['amount', 'away_amount', 'renewal_amount'], true)) {
                continue;
            }
            $pdo->prepare("UPDATE packages SET {$column} = :v WHERE id = :id")->execute([':v' => $value, ':id' => (int) $id]);
            $count++;
        }
    }
    $pdo->prepare('UPDATE season_planned_prices SET applied_at = NOW() WHERE season_id = :s')->execute([':s' => $seasonId]);
    return $count;
}

/**
 * Create the new season's ticket types from the wizard table (pre-filled from the season it was copied
 * from) and copy each type's "which competitions it covers" rules across.
 *
 * @param list<array<string,mixed>> $rows
 */
function season_wizard_create_ticket_types(PDO $pdo, int $newSeasonId, array $rows): int
{
    $made = 0;
    $usedCodes = [];
    $copyRules = $pdo->prepare('INSERT IGNORE INTO season_pass_rules (season_ticket_type_id, competition_id, competition_type, is_home_required, included)
        SELECT :new, competition_id, competition_type, is_home_required, included FROM season_pass_rules WHERE season_ticket_type_id = :old');
    foreach ($rows as $index => $row) {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '' || !empty($row['remove'])) {
            continue;
        }
        $code = seasonTicketTypeCode((string) (($row['code'] ?? '') !== '' ? $row['code'] : $name));
        if ($code === '') {
            $code = 'type_' . ($index + 1);
        }
        $base = $code;
        for ($n = 2; isset($usedCodes[$code]); $n++) {
            $code = $base . '_' . $n;
        }
        $usedCodes[$code] = true;
        $id = saveSeasonTicketType($pdo, null, [
            'season_id' => $newSeasonId,
            'name' => mb_substr($name, 0, 120),
            'code' => $code,
            'price' => season_wizard_money($row['price'] ?? 0),
            'is_active' => !empty($row['active']),
            'sort_order' => $made,
        ]);
        $made++;
        $sourceId = (int) ($row['source_id'] ?? 0);
        if ($sourceId > 0) {
            $copyRules->execute([':new' => $id, ':old' => $sourceId]);
        }
    }
    return $made;
}

/** Suggest the next season's name ("2026 / 2027" becomes "2027 / 2028"). */
function season_wizard_next_name(?string $name): string
{
    if ($name !== null && preg_match('/^(\d{4})(\s*\/\s*)(\d{4})$/', trim($name), $m)) {
        return ((int) $m[1] + 1) . $m[2] . ((int) $m[3] + 1);
    }
    return '';
}

function season_wizard_shift_year(?string $date): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('Y-m-d', strtotime('+1 year', $ts)) : '';
}
