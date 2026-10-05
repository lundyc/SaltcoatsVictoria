<?php

declare(strict_types=1);

// "Hidden Team" fundraiser board — migrated in from the standalone
// lundy.me.uk/hidden_team/ app. Each fundraiser round is a "game": a fixed
// list of team-name boxes at a set price, a supporter claims a box (now via
// Stripe Checkout instead of a free-text name), and a winner is drawn from
// the claimed boxes for a prize worth a percentage of the pot. Unlike the
// original app (which mutated one shared team list in place on every
// "reset"), each game here is its own permanent set of rows — starting a new
// game never destroys a previous one's history.

function ensureHiddenTeamSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS hidden_team_games (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        season_id INT UNSIGNED NULL,
        name VARCHAR(150) NOT NULL,
        cost_per_team DECIMAL(8,2) NOT NULL DEFAULT 0.00,
        prize_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        winner_team_id INT UNSIGNED NULL,
        winner_drawn_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_hidden_team_games_status (status),
        CONSTRAINT fk_hidden_team_games_season FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hidden_team_teams (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        game_id INT UNSIGNED NOT NULL,
        team_name VARCHAR(120) NOT NULL,
        person_id INT UNSIGNED NULL,
        holder_id INT UNSIGNED NULL,
        supporter_name VARCHAR(120) NOT NULL DEFAULT '',
        is_taken TINYINT(1) NOT NULL DEFAULT 0,
        paid TINYINT(1) NOT NULL DEFAULT 0,
        claimed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_hidden_team_teams_game_name (game_id, team_name),
        KEY idx_hidden_team_teams_game (game_id, is_taken),
        KEY idx_hidden_team_teams_person (person_id),
        KEY idx_hidden_team_teams_holder (holder_id),
        CONSTRAINT fk_hidden_team_teams_game FOREIGN KEY (game_id) REFERENCES hidden_team_games(id) ON DELETE CASCADE,
        CONSTRAINT fk_hidden_team_teams_person FOREIGN KEY (person_id) REFERENCES people(id) ON DELETE SET NULL,
        CONSTRAINT fk_hidden_team_teams_holder FOREIGN KEY (holder_id) REFERENCES season_ticket_holders(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS hidden_team_payments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        team_id INT UNSIGNED NOT NULL,
        amount DECIMAL(8,2) NOT NULL,
        method VARCHAR(30) NOT NULL DEFAULT 'stripe',
        status VARCHAR(20) NOT NULL DEFAULT 'settled',
        stripe_checkout_session_id VARCHAR(255) NULL,
        stripe_payment_intent_id VARCHAR(255) NULL,
        paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        note VARCHAR(255) NULL,
        PRIMARY KEY (id),
        KEY idx_hidden_team_payments_team (team_id),
        KEY idx_hidden_team_payments_session (stripe_checkout_session_id),
        CONSTRAINT fk_hidden_team_payments_team FOREIGN KEY (team_id) REFERENCES hidden_team_teams(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // The reusable pool of club names a game's board is randomly drawn from —
    // managed once, independently of any individual game, rather than
    // retyping/pasting a team list every time a new game is started.
    $pdo->exec("CREATE TABLE IF NOT EXISTS hidden_team_club_pool (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_hidden_team_club_pool_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $poolCount = (int) $pdo->query('SELECT COUNT(*) FROM hidden_team_club_pool')->fetchColumn();
    if ($poolCount === 0) {
        $insert = $pdo->prepare('INSERT INTO hidden_team_club_pool (name) VALUES (:name) ON DUPLICATE KEY UPDATE name = VALUES(name)');
        foreach (hidden_team_default_club_names() as $clubName) {
            $insert->execute([':name' => $clubName]);
        }
    }

    $done = true;
}

/** The 80 real club names the pool table is seeded with the first time it's used. */
function hidden_team_default_club_names(): array
{
    return [
        'Arthurlie', 'Auchinleck Talbot', 'Beith Juniors', 'Cumnock Juniors', 'Drumchapel United',
        'Glenafton Athletic', 'Hurlford United', 'Johnstone Burgh', 'Kilwinning Rangers', 'Largs Thistle',
        'Pollok', 'Renfrew', 'Rutherglen Glencairn', 'Shotts Bon Accord', "St Cadoc's", 'Troon',
        'Ardrossan Winton Rovers', 'Benburb', 'Cumbernauld United', 'Darvel', 'Gartcairn',
        'Irvine Meadow XI', 'Kilbirnie Ladeside', 'Kirkintilloch Rob Roy', 'Lanark United', 'Muirkirk Juniors',
        'Neilston', 'Petershill', "St Roch's", 'Thorniewood United', 'Vale of Clyde', 'Whitletts Victoria',
        'Ashfield', 'Bellshill Athletic', 'Blantyre Victoria', 'Bonnyton Thistle', 'Caledonian Locomotives',
        'Cambuslang Rangers', 'Forth Wanderers', 'Greenock Juniors', 'Kilsyth Athletic', 'Kilsyth Rangers',
        'Larkhall Thistle', 'Lesmahagow Juniors', 'Maryhill', 'Maybole Juniors', 'Thorn Athletic',
        'Threave Rovers', 'Ardeer Thistle', 'Craigmark Burntonians', 'Dalry Thistle', 'Easterhouse',
        'Finnart', 'Girvan', 'Glasgow Perthshire', 'Glasgow United', 'Glasgow University', 'Glenvale',
        'Kello Rovers', 'Knightswood', 'Lugar Boswell Thistle', 'Port Glasgow Juniors', "St Anthony's",
        'Yoker Athletic', 'BSC Glasgow', 'Campbeltown Pupils', 'Carluke Rovers', 'East Kilbride Thistle',
        'East Kilbride Y.M.', 'Eglinton', 'Giffnock SC', 'Irvine Victoria', 'Newmains United', 'Rossvale',
        'Royal Albert', 'Saltcoats Victoria', "St. Peter's", 'Vale of Leven', 'West Park United', 'Wishaw',
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function getHiddenTeamClubPool(PDO $pdo, bool $activeOnly = false): array
{
    ensureHiddenTeamSchema($pdo);
    $sql = 'SELECT * FROM hidden_team_club_pool' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name ASC';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function hiddenTeamClubPoolCount(PDO $pdo, bool $activeOnly = true): int
{
    ensureHiddenTeamSchema($pdo);
    $sql = 'SELECT COUNT(*) FROM hidden_team_club_pool' . ($activeOnly ? ' WHERE is_active = 1' : '');

    return (int) $pdo->query($sql)->fetchColumn();
}

function hiddenTeamAddClubToPool(PDO $pdo, string $name): void
{
    ensureHiddenTeamSchema($pdo);
    $name = trim($name);
    if ($name === '') {
        throw new RuntimeException('Team name is required.');
    }
    $stmt = $pdo->prepare('INSERT INTO hidden_team_club_pool (name, is_active) VALUES (:name, 1) ON DUPLICATE KEY UPDATE is_active = 1');
    $stmt->execute([':name' => $name]);
}

function hiddenTeamRemoveClubFromPool(PDO $pdo, int $id): void
{
    ensureHiddenTeamSchema($pdo);
    $pdo->prepare('DELETE FROM hidden_team_club_pool WHERE id = :id')->execute([':id' => $id]);
}

/**
 * @return list<string> $count random, distinct club names from the active pool
 */
function hiddenTeamRandomClubNames(PDO $pdo, int $count): array
{
    ensureHiddenTeamSchema($pdo);
    $available = hiddenTeamClubPoolCount($pdo, true);
    if ($count > $available) {
        throw new RuntimeException("Only {$available} team(s) are in the pool — reduce the count or add more teams to the pool first.");
    }
    $stmt = $pdo->prepare('SELECT name FROM hidden_team_club_pool WHERE is_active = 1 ORDER BY RAND() LIMIT ' . max(0, $count));
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function hidden_team_format_money(float $amount): string
{
    return '£' . number_format($amount, 2);
}

/** Total pot assuming every box in the game sells at cost_per_team (matches the original app's model). */
function hidden_team_total_raised(array $game): float
{
    return (float) $game['team_count'] * (float) $game['cost_per_team'];
}

function hidden_team_prize_amount(array $game): float
{
    return hidden_team_total_raised($game) * ((float) $game['prize_percentage'] / 100);
}

/**
 * @return list<array<string, mixed>> each game plus team_count/taken_count/paid_count/total_raised/prize_amount
 */
function getHiddenTeamGames(PDO $pdo, array $filters = []): array
{
    ensureHiddenTeamSchema($pdo);
    $where = [];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'g.status = :status';
        $params[':status'] = (string) $filters['status'];
    }
    $sql = "SELECT g.*,
            (SELECT COUNT(*) FROM hidden_team_teams t WHERE t.game_id = g.id) AS team_count,
            (SELECT COUNT(*) FROM hidden_team_teams t WHERE t.game_id = g.id AND t.is_taken = 1) AS taken_count,
            (SELECT COUNT(*) FROM hidden_team_teams t WHERE t.game_id = g.id AND t.paid = 1) AS paid_count,
            w.team_name AS winner_team_name, w.supporter_name AS winner_supporter_name
        FROM hidden_team_games g
        LEFT JOIN hidden_team_teams w ON w.id = g.winner_team_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . ' ORDER BY g.created_at DESC, g.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $games = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($games as &$game) {
        $game['total_raised'] = hidden_team_total_raised($game);
        $game['prize_amount'] = hidden_team_prize_amount($game);
    }
    unset($game);

    return $games;
}

function getHiddenTeamGame(PDO $pdo, int $id): ?array
{
    foreach (getHiddenTeamGames($pdo) as $game) {
        if ((int) $game['id'] === $id) {
            return $game;
        }
    }

    return null;
}

/** The single currently-open game shown to members — mirrors the original app's "one active board" model. */
function getHiddenTeamCurrentOpenGame(PDO $pdo): ?array
{
    foreach (getHiddenTeamGames($pdo, ['status' => 'open']) as $game) {
        return $game;
    }

    return null;
}

/**
 * @param list<string> $teamNames
 */
function createHiddenTeamGame(PDO $pdo, string $name, float $costPerTeam, float $prizePercentage, ?int $seasonId, int $teamCount): int
{
    ensureHiddenTeamSchema($pdo);
    $name = trim($name) ?: ('Hidden Team — ' . date('d/m/Y'));
    if ($teamCount < 1) {
        throw new RuntimeException('Choose at least 1 team for the board.');
    }
    $teamNames = hiddenTeamRandomClubNames($pdo, $teamCount);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO hidden_team_games (season_id, name, cost_per_team, prize_percentage, status) VALUES (:season, :name, :cost, :prize, "open")');
        $stmt->execute([
            ':season' => $seasonId ?: null,
            ':name' => $name,
            ':cost' => max(0, $costPerTeam),
            ':prize' => max(0, min(100, $prizePercentage)),
        ]);
        $gameId = (int) $pdo->lastInsertId();

        $insertTeam = $pdo->prepare('INSERT INTO hidden_team_teams (game_id, team_name) VALUES (:game, :name)');
        foreach ($teamNames as $teamName) {
            $insertTeam->execute([':game' => $gameId, ':name' => $teamName]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $gameId;
}

function saveHiddenTeamGameSettings(PDO $pdo, int $gameId, string $name, float $costPerTeam, float $prizePercentage): void
{
    ensureHiddenTeamSchema($pdo);
    $stmt = $pdo->prepare('UPDATE hidden_team_games SET name = :name, cost_per_team = :cost, prize_percentage = :prize WHERE id = :id');
    $stmt->execute([
        ':name' => trim($name) ?: 'Hidden Team',
        ':cost' => max(0, $costPerTeam),
        ':prize' => max(0, min(100, $prizePercentage)),
        ':id' => $gameId,
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function getHiddenTeamTeams(PDO $pdo, int $gameId): array
{
    ensureHiddenTeamSchema($pdo);
    $stmt = $pdo->prepare('SELECT t.*, COALESCE(p.email, h.email) AS holder_email
        FROM hidden_team_teams t
        LEFT JOIN people p ON p.id = t.person_id
        LEFT JOIN season_ticket_holders h ON h.id = t.holder_id
        WHERE t.game_id = :game_id
        ORDER BY t.team_name ASC');
    $stmt->execute([':game_id' => $gameId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getHiddenTeamTeam(PDO $pdo, int $teamId): ?array
{
    ensureHiddenTeamSchema($pdo);
    $stmt = $pdo->prepare('SELECT t.*, g.status AS game_status, g.cost_per_team, g.name AS game_name
        FROM hidden_team_teams t
        JOIN hidden_team_games g ON g.id = t.game_id
        WHERE t.id = :id LIMIT 1');
    $stmt->execute([':id' => $teamId]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function hiddenTeamReleaseTeam(PDO $pdo, int $teamId): void
{
    ensureHiddenTeamSchema($pdo);
    $pdo->prepare("UPDATE hidden_team_teams SET supporter_name = '', person_id = NULL, holder_id = NULL, is_taken = 0, paid = 0, claimed_at = NULL WHERE id = :id")
        ->execute([':id' => $teamId]);
}

/**
 * @return array{team_id: int, team_name: string, supporter_name: string}
 */
function hiddenTeamDrawWinner(PDO $pdo, int $gameId): array
{
    ensureHiddenTeamSchema($pdo);
    $game = getHiddenTeamGame($pdo, $gameId);
    if (!$game) {
        throw new RuntimeException('Game not found.');
    }
    if ((string) $game['status'] !== 'open') {
        throw new RuntimeException('This game already has a winner. Start a new game to draw again.');
    }

    $pool = $pdo->prepare('SELECT id, team_name, supporter_name FROM hidden_team_teams WHERE game_id = :game AND is_taken = 1');
    $pool->execute([':game' => $gameId]);
    $teams = $pool->fetchAll(PDO::FETCH_ASSOC);
    if (!$teams) {
        throw new RuntimeException('No teams have been claimed yet — nothing to draw from.');
    }

    $winner = $teams[array_rand($teams)];

    $stmt = $pdo->prepare("UPDATE hidden_team_games SET status = 'drawn', winner_team_id = :winner, winner_drawn_at = NOW() WHERE id = :id");
    $stmt->execute([':winner' => (int) $winner['id'], ':id' => $gameId]);

    // Keep there always being an open board for members — start the next
    // game automatically the moment this one gets a winner, whether that
    // draw was triggered manually or by the board selling out. Carries
    // forward this game's price/prize so nobody has to re-decide them, and
    // draws a fresh random board from whatever's currently in the pool.
    // Non-fatal if this fails (e.g. the pool is empty) — the draw itself
    // already succeeded and stands regardless.
    try {
        $justDrawnGame = getHiddenTeamGame($pdo, $gameId);
        if ($justDrawnGame && !getHiddenTeamCurrentOpenGame($pdo)) {
            hiddenTeamAutoStartNextGame($pdo, $justDrawnGame);
        }
    } catch (Throwable $e) {
        error_log('[hidden_team] Could not auto-start the next game after game ' . $gameId . ': ' . $e->getMessage());
    }

    return [
        'team_id' => (int) $winner['id'],
        'team_name' => (string) $winner['team_name'],
        'supporter_name' => (string) $winner['supporter_name'],
    ];
}

/**
 * Draws a winner automatically once every team on the board has been
 * claimed — called after any claim path (Stripe basket checkout, admin
 * manual claim) so the admin never has to notice a sold-out board and click
 * Draw themselves.
 */
function hiddenTeamMaybeAutoDrawIfSoldOut(PDO $pdo, int $gameId): void
{
    $game = getHiddenTeamGame($pdo, $gameId);
    if (!$game || (string) $game['status'] !== 'open') {
        return;
    }
    if ((int) $game['team_count'] < 1 || (int) $game['taken_count'] < (int) $game['team_count']) {
        return; // Not sold out yet.
    }

    try {
        hiddenTeamDrawWinner($pdo, $gameId);
    } catch (Throwable $e) {
        error_log('[hidden_team] Auto-draw failed for sold-out game ' . $gameId . ': ' . $e->getMessage());
    }
}

/**
 * Starts a new game carrying forward the previous game's price and prize
 * percentage, named automatically, drawing a fresh random board sized to
 * however many teams are currently active in the pool.
 */
function hiddenTeamAutoStartNextGame(PDO $pdo, array $previousGame): int
{
    $poolCount = hiddenTeamClubPoolCount($pdo, true);
    if ($poolCount < 1) {
        throw new RuntimeException('The team pool is empty — add teams to the pool before a new game can start.');
    }

    return createHiddenTeamGame(
        $pdo,
        'Hidden Team — ' . date('d/m/Y'),
        (float) $previousGame['cost_per_team'],
        (float) $previousGame['prize_percentage'],
        isset($previousGame['season_id']) ? (int) $previousGame['season_id'] : null,
        $poolCount
    );
}

/**
 * @return list<array<string, mixed>>
 */
function getHiddenTeamPayments(PDO $pdo, int $teamId): array
{
    ensureHiddenTeamSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM hidden_team_payments WHERE team_id = :team_id ORDER BY paid_at DESC, id DESC');
    $stmt->execute([':team_id' => $teamId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Every payment row from one Stripe Checkout session, so the success
 * redirect can show a real receipt (which teams, which game, how much) —
 * rather than assuming the page's "currently open game" is the one the
 * member just paid into, which is often no longer true once a purchase has
 * triggered a sellout auto-draw.
 * @return list<array<string, mixed>>
 */
function getHiddenTeamPaymentsBySession(PDO $pdo, string $sessionId): array
{
    if ($sessionId === '') {
        return [];
    }
    ensureHiddenTeamSchema($pdo);
    $stmt = $pdo->prepare('SELECT p.*, t.team_name, t.game_id, g.name AS game_name
        FROM hidden_team_payments p
        JOIN hidden_team_teams t ON t.id = p.team_id
        JOIN hidden_team_games g ON g.id = t.game_id
        WHERE p.stripe_checkout_session_id = :session_id
        ORDER BY p.id ASC');
    $stmt->execute([':session_id' => $sessionId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * A person's own claimed boxes across every game, for their member account view.
 * @return list<array<string, mixed>>
 */
function getHiddenTeamPersonClaims(PDO $pdo, int $personId, ?int $legacyHolderId = null): array
{
    ensureHiddenTeamSchema($pdo);
    $stmt = $pdo->prepare('SELECT t.*, g.name AS game_name, g.status AS game_status, g.winner_team_id, g.prize_percentage, g.cost_per_team
        FROM hidden_team_teams t
        JOIN hidden_team_games g ON g.id = t.game_id
        WHERE t.person_id = :person_id' . ($legacyHolderId !== null && $legacyHolderId > 0 ? ' OR t.holder_id = :holder_id' : '') . '
        ORDER BY t.claimed_at DESC, t.id DESC');
    $params = [':person_id' => $personId];
    if ($legacyHolderId !== null && $legacyHolderId > 0) {
        $params[':holder_id'] = $legacyHolderId;
    }
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getHiddenTeamHolderClaims(PDO $pdo, int $holderId): array
{
    $personId = function_exists('personIdFromLegacyHolderId') ? (personIdFromLegacyHolderId($pdo, $holderId) ?? 0) : 0;
    return $personId > 0 ? getHiddenTeamPersonClaims($pdo, $personId, $holderId) : [];
}
