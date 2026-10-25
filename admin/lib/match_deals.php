<?php

declare(strict_types=1);

/**
 * Season deals: one sponsor taking a sponsorship (match ball, match day, ...)
 * across many fixtures for an agreed total.
 *
 * A deal creates ordinary match_sponsorships rows, one per fixture and
 * package, so fixture pages, graphics, payments and agreements keep working
 * exactly as before. The deal only groups them (match_sponsorships.deal_id)
 * and controls things at deal level: the agreed total, how it is billed, and
 * whether the sponsor appears on public graphics.
 */

require_once __DIR__ . '/match_sponsorship.php';

function matchDealsEnsureSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ensureMatchSchema($pdo);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS match_sponsorship_deals (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            season_id INT UNSIGNED NOT NULL,
            sponsor_id INT UNSIGNED NOT NULL,
            roles VARCHAR(255) NOT NULL,
            scope VARCHAR(20) NOT NULL DEFAULT 'all',
            billing VARCHAR(20) NOT NULL DEFAULT 'single',
            total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            is_complimentary TINYINT(1) NOT NULL DEFAULT 0,
            show_on_graphics TINYINT(1) NOT NULL DEFAULT 1,
            notes VARCHAR(255) NULL DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_by INT UNSIGNED NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_match_deals_season (season_id),
            KEY idx_match_deals_sponsor (sponsor_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM match_sponsorships') as $col) {
        $columns[$col['Field']] = true;
    }
    if (!isset($columns['deal_id'])) {
        $pdo->exec('ALTER TABLE match_sponsorships ADD COLUMN deal_id INT UNSIGNED NULL DEFAULT NULL');
        $pdo->exec('ALTER TABLE match_sponsorships ADD KEY idx_match_sponsorships_deal (deal_id)');
    }
    if (!isset($columns['hide_from_public'])) {
        $pdo->exec('ALTER TABLE match_sponsorships ADD COLUMN hide_from_public TINYINT(1) NOT NULL DEFAULT 0');
    }
}

/**
 * Split a total into n amounts that add up exactly (pennies go to the first rows).
 *
 * @return list<float>
 */
function matchDealSplitTotal(float $total, int $parts): array
{
    if ($parts <= 0) {
        return [];
    }
    $pennies = (int) round(max(0.0, $total) * 100);
    $base = intdiv($pennies, $parts);
    $extra = $pennies % $parts;
    $out = [];
    for ($i = 0; $i < $parts; $i++) {
        $out[] = ($base + ($i < $extra ? 1 : 0)) / 100;
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function matchDealsList(PDO $pdo, int $seasonId): array
{
    matchDealsEnsureSchema($pdo);
    $stmt = $pdo->prepare("
        SELECT d.*, s.name AS sponsor_name,
               COUNT(ms.id) AS row_count,
               COALESCE(SUM(ms.paid = 1 OR ms.is_complimentary = 1), 0) AS settled_count,
               COALESCE(SUM(ms.amount), 0) AS rows_total
        FROM match_sponsorship_deals d
        JOIN sponsors s ON s.id = d.sponsor_id
        LEFT JOIN match_sponsorships ms ON ms.deal_id = d.id AND ms.ended_at IS NULL
        WHERE d.season_id = :season_id
        GROUP BY d.id
        ORDER BY d.status = 'active' DESC, d.created_at DESC, d.id DESC
    ");
    $stmt->execute([':season_id' => $seasonId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** @return array<string,mixed>|null */
function matchDealGet(PDO $pdo, int $dealId): ?array
{
    matchDealsEnsureSchema($pdo);
    $stmt = $pdo->prepare('
        SELECT d.*, s.name AS sponsor_name, se.name AS season_name, se.is_locked AS season_is_locked
        FROM match_sponsorship_deals d
        JOIN sponsors s ON s.id = d.sponsor_id
        JOIN seasons se ON se.id = d.season_id
        WHERE d.id = :id LIMIT 1
    ');
    $stmt->execute([':id' => $dealId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Active placements belonging to a deal, with fixture details and amount paid. @return list<array<string,mixed>> */
function matchDealRows(PDO $pdo, int $dealId): array
{
    matchDealsEnsureSchema($pdo);
    $stmt = $pdo->prepare("
        SELECT ms.*, f.match_date, f.is_home, COALESCE(o.clubname, f.opponent) AS opponent,
               COALESCE(pay.total_paid, 0) AS paid_total
        FROM match_sponsorships ms
        JOIN match_fixtures f ON f.id = ms.fixture_id
        LEFT JOIN match_opponents o ON o.id = f.opponent_id
        LEFT JOIN (
            SELECT match_sponsorship_id, SUM(amount) AS total_paid
            FROM match_sponsorship_payments GROUP BY match_sponsorship_id
        ) pay ON pay.match_sponsorship_id = ms.id
        WHERE ms.deal_id = :deal AND ms.ended_at IS NULL
        ORDER BY f.match_date ASC, f.id ASC, ms.sponsorship_role ASC
    ");
    $stmt->execute([':deal' => $dealId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Create a deal and its placements.
 *
 * @param array{season_id:int,sponsor_id:int,roles:list<string>,fixture_ids:list<int>,scope:string,billing:string,total:float,complimentary:bool,show_on_graphics:bool,notes:?string,user_id:?int} $in
 * @return array{deal_id:int, created:int, skipped:list<string>}
 */
function matchDealCreate(PDO $pdo, array $in): array
{
    matchDealsEnsureSchema($pdo);
    ensureSponsorshipCatalogSchema($pdo);

    $season = getSeasonById($pdo, (int) $in['season_id']);
    if (!$season) {
        throw new RuntimeException('Season not found.');
    }
    if ((int) $season['is_locked'] === 1) {
        throw new RuntimeException('This season is locked.');
    }

    $roles = array_values(array_unique(array_filter(array_map('strval', $in['roles']))));
    if ($roles === []) {
        throw new RuntimeException('Choose at least one sponsorship package.');
    }
    foreach ($roles as $role) {
        if (!getMatchSponsorshipTypeByCode($pdo, $role)) {
            throw new RuntimeException('Unknown sponsorship package: ' . $role . '.');
        }
    }

    $sponsorCheck = $pdo->prepare('SELECT COUNT(*) FROM sponsors WHERE id = :id AND is_active = 1');
    $sponsorCheck->execute([':id' => (int) $in['sponsor_id']]);
    if ((int) $sponsorCheck->fetchColumn() === 0) {
        throw new RuntimeException('Choose an active sponsor.');
    }

    if (!in_array($in['billing'], ['single', 'per_match'], true)) {
        throw new RuntimeException('Choose how the deal is billed.');
    }
    $complimentary = !empty($in['complimentary']);
    $total = $complimentary ? 0.0 : round((float) $in['total'], 2);
    if (!$complimentary && $total <= 0) {
        throw new RuntimeException('Enter the agreed total, or mark the deal as complimentary.');
    }

    // Only fixtures of this season.
    $fixtures = [];
    foreach (getMatchFixtures($pdo, (int) $in['season_id']) as $fixture) {
        $fixtures[(int) $fixture['id']] = $fixture;
    }
    $selected = [];
    foreach (array_unique(array_map('intval', $in['fixture_ids'])) as $fixtureId) {
        if (isset($fixtures[$fixtureId])) {
            $selected[] = $fixtureId;
        }
    }
    if ($selected === []) {
        throw new RuntimeException('Choose at least one fixture.');
    }
    usort($selected, static fn (int $a, int $b): int => [$fixtures[$a]['match_date'], $a] <=> [$fixtures[$b]['match_date'], $b]);

    // Placements that are free to take; anything already sponsored is left alone.
    $taken = $pdo->prepare('SELECT COUNT(*) FROM match_sponsorships WHERE fixture_id = :f AND sponsorship_role = :r AND ended_at IS NULL');
    $earlier = $pdo->prepare('SELECT COALESCE(SUM(p.amount), 0) FROM match_sponsorships ms JOIN match_sponsorship_payments p ON p.match_sponsorship_id = ms.id WHERE ms.fixture_id = :f AND ms.sponsorship_role = :r');
    $slots = [];
    $skipped = [];
    foreach ($selected as $fixtureId) {
        foreach ($roles as $role) {
            $taken->execute([':f' => $fixtureId, ':r' => $role]);
            $label = date('j M', strtotime((string) $fixtures[$fixtureId]['match_date'])) . ' ' . ((int) $fixtures[$fixtureId]['is_home'] === 1 ? 'v' : '@') . ' ' . ($fixtures[$fixtureId]['opponent'] ?? '') . ' (' . ucwords(str_replace('_', ' ', $role)) . ')';
            if ((int) $taken->fetchColumn() > 0) {
                $skipped[] = $label . ' - already sponsored';
                continue;
            }
            // A previously ended placement keeps its old payments when re-used, which would show as paid.
            $earlier->execute([':f' => $fixtureId, ':r' => $role]);
            if ((float) $earlier->fetchColumn() > 0) {
                $skipped[] = $label . ' - an earlier sponsorship has payments recorded';
                continue;
            }
            $slots[] = [$fixtureId, $role];
        }
    }
    if ($slots === []) {
        throw new RuntimeException('Every chosen fixture already has a sponsor for those packages, so nothing was created.');
    }

    $amounts = $complimentary ? array_fill(0, count($slots), 0.0) : matchDealSplitTotal($total, count($slots));
    $notes = isset($in['notes']) && trim((string) $in['notes']) !== '' ? trim((string) $in['notes']) : null;
    $show = !empty($in['show_on_graphics']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO match_sponsorship_deals
                (season_id, sponsor_id, roles, scope, billing, total_amount, is_complimentary, show_on_graphics, notes, created_by)
            VALUES (:season, :sponsor, :roles, :scope, :billing, :total, :comp, :show, :notes, :user)
        ")->execute([
            ':season' => (int) $in['season_id'],
            ':sponsor' => (int) $in['sponsor_id'],
            ':roles' => implode(',', $roles),
            ':scope' => in_array($in['scope'], ['all', 'home', 'away', 'custom'], true) ? $in['scope'] : 'custom',
            ':billing' => $in['billing'],
            ':total' => $total,
            ':comp' => $complimentary ? 1 : 0,
            ':show' => $show ? 1 : 0,
            ':notes' => $notes,
            ':user' => $in['user_id'] ?? null,
        ]);
        $dealId = (int) $pdo->lastInsertId();

        $link = $pdo->prepare('UPDATE match_sponsorships SET deal_id = :deal, hide_from_public = :hide WHERE id = :id');
        foreach ($slots as $i => [$fixtureId, $role]) {
            $row = assignMatchSponsorship($pdo, $fixtureId, (int) $in['sponsor_id'], $role, $amounts[$i], $notes, $complimentary);
            if (empty($row['id'])) {
                throw new RuntimeException('A placement could not be created.');
            }
            $link->execute([':deal' => $dealId, ':hide' => $show ? 0 : 1, ':id' => (int) $row['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['deal_id' => $dealId, 'created' => count($slots), 'skipped' => $skipped];
}

/** Record the outstanding amount on every placement as paid (a deal paid in one go). */
function matchDealMarkPaid(PDO $pdo, int $dealId): int
{
    $count = 0;
    foreach (matchDealRows($pdo, $dealId) as $row) {
        if ((int) $row['is_complimentary'] === 1) {
            continue;
        }
        if (getMatchSponsorshipOutstandingAmount($pdo, (int) $row['id']) > 0) {
            markMatchSponsorshipPaid($pdo, (int) $row['id'], 'Season deal', 'Season deal marked as paid');
            $count++;
        }
    }
    return $count;
}

function matchDealMarkUnpaid(PDO $pdo, int $dealId): int
{
    $count = 0;
    foreach (matchDealRows($pdo, $dealId) as $row) {
        if ((int) $row['is_complimentary'] !== 1 && (float) $row['paid_total'] > 0) {
            unpayMatchSponsorship($pdo, (int) $row['id']);
            $count++;
        }
    }
    return $count;
}

function matchDealSetVisibility(PDO $pdo, int $dealId, bool $show): void
{
    matchDealsEnsureSchema($pdo);
    $pdo->prepare('UPDATE match_sponsorship_deals SET show_on_graphics = :s WHERE id = :id')->execute([':s' => $show ? 1 : 0, ':id' => $dealId]);
    $pdo->prepare('UPDATE match_sponsorships SET hide_from_public = :h WHERE deal_id = :id')->execute([':h' => $show ? 0 : 1, ':id' => $dealId]);
}

/** End the deal and every placement in it (the rows are kept as history, like any ended sponsorship). */
function matchDealEnd(PDO $pdo, int $dealId): int
{
    matchDealsEnsureSchema($pdo);
    // Schema checks inside the catalogue code can issue DDL (an implicit commit), so run them
    // before the transaction opens rather than part-way through it.
    ensureSponsorshipCatalogSchema($pdo);
    $rows = matchDealRows($pdo, $dealId);
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            endMatchSponsorship($pdo, (int) $row['id'], 'deal_ended');
        }
        $pdo->prepare("UPDATE match_sponsorship_deals SET status = 'ended' WHERE id = :id")->execute([':id' => $dealId]);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return count($rows);
}
