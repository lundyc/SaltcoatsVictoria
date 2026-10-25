<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/score_predictor.php';
require_once __DIR__ . '/../admin/lib/squad_public.php';

// Staff accounts can get in without a season ticket holder record. The game needs one to
// save predictions against, so send them back to the account page.
if (!$currentHolder) {
    header('Location: /members/index.php');
    exit;
}

sp_ensure_schema($pdo);

const SP_PAGE_SIZE = 4;

$seasonId = pub_current_season_id();
$holderId = (int) $currentHolder['id'];
$squadNames = array_values(array_map(static fn(array $p): string => (string) $p['name'], squad_public_players($pdo)));

$message = '';
$messageIsError = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $cityRaw = trim((string) ($_POST['city_score'] ?? ''));
    $oppositionRaw = trim((string) ($_POST['opposition_score'] ?? ''));
    $goalscorer = trim((string) ($_POST['goalscorer'] ?? ''));

    if (!member_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $message = 'Your session has expired. Refresh the page and try again.';
        $messageIsError = true;
    } elseif (!ctype_digit($cityRaw) || !ctype_digit($oppositionRaw)) {
        $message = 'Enter both scores as whole numbers.';
        $messageIsError = true;
    } elseif (!in_array($goalscorer, $squadNames, true)) {
        $message = 'Pick a goalscorer from the squad list.';
        $messageIsError = true;
    } else {
        $error = sp_save_prediction(
            $pdo,
            (int) ($_POST['fixture_id'] ?? 0),
            $holderId,
            (int) $cityRaw,
            (int) $oppositionRaw,
            $goalscorer
        );
        $message = $error === '' ? 'Prediction saved.' : $error;
        $messageIsError = $error !== '';
    }
}

// Upcoming fixtures, plus yesterday's so a game that has just kicked off still shows the
// member's locked pick. Settled fixtures are listed under "Recent scores".
$fixtureStmt = $pdo->prepare("SELECT f.*, o.logo_path AS opponent_logo_path
    FROM match_fixtures f
    LEFT JOIN match_opponents o ON o.id = f.opponent_id
    WHERE f.season_id = :season AND f.status = 'scheduled' AND f.match_date >= CURDATE() - INTERVAL 1 DAY
    ORDER BY f.match_date ASC, f.kickoff_time ASC, f.id ASC");
$fixtureStmt->execute([':season' => $seasonId]);
$fixtures = $fixtureStmt->fetchAll(PDO::FETCH_ASSOC);

// Paginate so the page stays short: SP_PAGE_SIZE games per page, nearest first.
$pageCount = max(1, (int) ceil(count($fixtures) / SP_PAGE_SIZE));
$page = min($pageCount, max(1, (int) ($_GET['page'] ?? 1)));
$visibleFixtures = array_slice($fixtures, ($page - 1) * SP_PAGE_SIZE, SP_PAGE_SIZE);

$predictions = [];
$predictorCounts = [];
if ($visibleFixtures) {
    $ids = array_map(static fn(array $f): int => (int) $f['id'], $visibleFixtures);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $predStmt = $pdo->prepare("SELECT * FROM sp_predictions WHERE holder_id = ? AND fixture_id IN ({$placeholders})");
    $predStmt->execute(array_merge([$holderId], $ids));
    foreach ($predStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $predictions[(int) $row['fixture_id']] = $row;
    }

    $countStmt = $pdo->prepare("SELECT fixture_id, COUNT(*) AS total FROM sp_predictions WHERE fixture_id IN ({$placeholders}) GROUP BY fixture_id");
    $countStmt->execute($ids);
    foreach ($countStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $predictorCounts[(int) $row['fixture_id']] = (int) $row['total'];
    }
}

$recentStmt = $pdo->prepare('SELECT s.total_points, s.is_cup, s.match_date, f.opponent
    FROM sp_scores s
    JOIN match_fixtures f ON f.id = s.fixture_id
    WHERE s.holder_id = :holder AND s.season_id = :season
    ORDER BY s.match_date DESC, s.id DESC
    LIMIT 5');
$recentStmt->execute([':holder' => $holderId, ':season' => $seasonId]);
$recent = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

$points = sp_points($pdo);
$maxLeague = $points['submit'] + $points['result'] + $points['city_score'] + $points['opposition_score'] + $points['scoreline'] + $points['goalscorer'];
$maxCup = $maxLeague + $points['cup_bonus'];

$now = new DateTimeImmutable('now');

/** "Closes in 2d 4h" / "Closes in 3h 12m" / "Closes in 45m", counted to kick-off. */
$closesIn = static function (DateTimeImmutable $kickoff) use ($now): string {
    $diff = $now->diff($kickoff);
    if ($diff->days > 0) {
        return "Closes in {$diff->days}d {$diff->h}h";
    }
    if ($diff->h > 0) {
        return "Closes in {$diff->h}h {$diff->i}m";
    }
    return "Closes in {$diff->i}m";
};

$kickoffFor = static function (array $fixture): DateTimeImmutable {
    $time = trim((string) ($fixture['kickoff_time'] ?? '')) ?: '00:00:00';
    return new DateTimeImmutable($fixture['match_date'] . ' ' . $time);
};

/** Two-letter badge text for an opponent with no crest on file: "Vale of Leven" becomes "VL". */
$badgeText = static function (string $name): string {
    $words = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: [], static fn(string $w): bool => preg_match('/^[A-Za-z]/', $w) === 1 && !in_array(strtolower($w), ['of', 'the', 'and', 'fc', 'afc', 'f.c.'], true)));
    $letters = '';
    foreach (array_slice($words, 0, 2) as $word) {
        $letters .= mb_strtoupper(mb_substr($word, 0, 1));
    }
    return $letters !== '' ? $letters : '?';
};

$ourCrest = club_crest();
?>
<div class="member-page sp">
    <style>
        .sp { --sp-ink: var(--ink, #2e2c2d); --sp-muted: var(--muted-text, #7c7178); --sp-navy: var(--club-deep, #2a0a11); --sp-tile: #f4e9e3; }
        .sp__hero { position: relative; overflow: hidden; margin-bottom: 1.25rem; padding: clamp(1.4rem, 3vw, 2.2rem); border-radius: var(--radius, 10px); color: #fff; background: linear-gradient(135deg, var(--club-primary, #6d2231), var(--club-deep, #2a0a11)); border-bottom: 4px solid var(--club-accent, #e0b42a); box-shadow: var(--shadow); }
        .sp__hero h1 { margin: 0 0 .35rem; font-family: var(--font-head); font-size: clamp(1.9rem, 5vw, 2.6rem); font-weight: 800; letter-spacing: .01em; text-transform: uppercase; color: #fff; }
        .sp__hero p { margin: 0; opacity: .85; max-width: 42rem; }
        .sp__eyebrow { display: inline-block; margin-bottom: .6rem; font-family: var(--font-cond); font-size: .8rem; font-weight: 700; letter-spacing: .16em; text-transform: uppercase; color: var(--club-accent, #e0b42a); }

        .sp__points { display: grid; grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr)); gap: .6rem; margin: 0 0 1.5rem; padding: 0; list-style: none; }
        .sp__point { background: var(--paper, #fff); border: 1px solid var(--line, #e7dccb); border-radius: var(--radius, 10px); padding: .75rem .9rem; box-shadow: var(--shadow-sm); }
        .sp__point strong { display: block; font-family: var(--font-head); font-size: 1.6rem; color: var(--club-primary, #6d2231); line-height: 1.05; }
        .sp__point span { font-size: .8rem; color: var(--sp-muted); }

        .sp__rules { margin: 0 0 1.5rem; border: 1px solid var(--line, #e7dccb); border-radius: var(--radius, 10px); background: var(--paper, #fff); }
        .sp__rules summary { cursor: pointer; padding: .9rem 1rem; font-weight: 700; color: var(--sp-ink); list-style: none; display: flex; justify-content: space-between; align-items: center; }
        .sp__rules summary::-webkit-details-marker { display: none; }
        .sp__rules summary::after { content: '+'; font-size: 1.25rem; color: var(--club-primary, #6d2231); }
        .sp__rules[open] summary::after { content: '\2013'; }
        .sp__rules-body { padding: 0 1rem 1rem; font-size: .92rem; color: var(--sp-ink); }
        .sp__rules-body ul { margin: .25rem 0 .75rem; padding-left: 1.1rem; }
        .sp__rules-body a { color: var(--club-primary, #6d2231); font-weight: 700; }

        .sp__head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .5rem; margin: 0 0 1rem; }
        .sp__head h2 { margin: 0; font-family: var(--font-head); font-size: 1.5rem; font-weight: 800; text-transform: uppercase; color: var(--sp-ink); }
        .sp__head small { color: var(--sp-muted); }

        /* The prediction card: crests either side of the two score boxes, as on the reference layout. */
        .sp__grid { display: grid; grid-template-columns: 1fr; gap: 1.25rem; }
        @media (min-width: 900px) { .sp__grid { grid-template-columns: 1fr 1fr; } }
        .sp__card { display: flex; flex-direction: column; background: #fff; border: 1px solid var(--line, #e7dccb); border-radius: 14px; box-shadow: var(--shadow-sm); padding: 1.1rem 1.1rem 1.2rem; }
        .sp__card-top { display: flex; justify-content: space-between; align-items: center; gap: .5rem; font-family: var(--font-cond); font-size: .8rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--sp-muted); }
        .sp__pill { display: inline-flex; align-items: center; padding: .25rem .65rem; border-radius: 999px; font-size: .72rem; font-weight: 700; letter-spacing: .04em; white-space: nowrap; }
        .sp__pill--open { background: #e6f4ea; color: #1b6b35; }
        .sp__pill--closed { background: #ece8ea; color: #5d5358; }
        .sp__pill--cup { background: #fff4d6; color: var(--club-accent-ink, #8a6410); }
        .sp__title { margin: .75rem 0 .2rem; text-align: center; font-family: var(--font-head); font-size: 1.9rem; font-weight: 800; text-transform: uppercase; line-height: 1; color: var(--sp-navy); }
        .sp__meta { text-align: center; font-size: .85rem; color: var(--sp-muted); margin-bottom: 1rem; }

        .sp__versus { display: grid; grid-template-columns: 4.5rem 1fr 1fr 4.5rem; align-items: center; gap: .6rem; }
        .sp__crest { width: 4.5rem; height: 4.5rem; border-radius: 50%; display: grid; place-items: center; background: #fff; border: 2px solid var(--line, #e7dccb); overflow: hidden; }
        .sp__crest img { width: 78%; height: 78%; object-fit: contain; }
        .sp__crest--text { font-family: var(--font-head); font-size: 1.3rem; font-weight: 800; color: var(--club-primary, #6d2231); background: var(--sp-tile); border-color: transparent; }
        .sp__score { display: flex; flex-direction: column; gap: .3rem; }
        .sp__score label { font-family: var(--font-cond); font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--sp-muted); text-align: center; }
        .sp__score input { width: 100%; height: 4.2rem; text-align: center; font-family: var(--font-head); font-size: 2.4rem; font-weight: 800; color: var(--sp-navy); background: var(--sp-tile); border: 2px solid transparent; border-radius: 12px; -moz-appearance: textfield; }
        .sp__score input::-webkit-outer-spin-button, .sp__score input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .sp__score input:focus { outline: none; border-color: var(--club-accent, #e0b42a); background: #fff; }
        .sp__pick { height: 4.2rem; display: grid; place-items: center; font-family: var(--font-head); font-size: 2.4rem; font-weight: 800; color: var(--sp-navy); background: var(--sp-tile); border-radius: 12px; }

        .sp__scorer { margin-top: 1rem; }
        .sp__scorer label { display: block; margin-bottom: .3rem; font-family: var(--font-cond); font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--sp-muted); }
        .sp__scorer select { width: 100%; height: 2.8rem; padding: 0 .6rem; border: 1px solid var(--line-strong, #d9cbb4); border-radius: 10px; background: #fff; color: var(--sp-ink); font-size: .95rem; }
        .sp__scorer select:focus { outline: 2px solid var(--club-accent, #e0b42a); outline-offset: 1px; }

        .sp__cta { margin-top: 1.1rem; width: 100%; height: 3.1rem; border: 0; border-radius: 10px; background: var(--sp-navy); color: #fff; font-family: var(--font-cond); font-size: 1rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; cursor: pointer; }
        .sp__cta:hover { background: var(--club-primary, #6d2231); }
        .sp__count { margin-top: .9rem; display: flex; align-items: center; justify-content: center; gap: .5rem; font-size: .85rem; color: var(--sp-muted); }
        .sp__count strong { color: var(--sp-ink); }
        .sp__stack { display: inline-flex; }
        .sp__stack span { width: 1.5rem; height: 1.5rem; border-radius: 50%; background: var(--club-primary, #6d2231); border: 2px solid #fff; margin-left: -.4rem; }
        .sp__stack span:first-child { margin-left: 0; }
        .sp__stack span:nth-child(2) { background: var(--club-accent, #e0b42a); }
        .sp__stack span:nth-child(3) { background: var(--sp-navy); }

        .sp__closed { margin-top: 1rem; padding: .8rem; text-align: center; border-radius: 10px; background: var(--surface, #f8f2e9); font-size: .9rem; color: var(--sp-muted); }
        .sp__closed strong { display: block; color: var(--sp-ink); font-size: .95rem; margin-bottom: .15rem; }
        .sp__mine { display: grid; grid-template-columns: 1fr; gap: .4rem; margin-top: .9rem; text-align: center; }

        .sp__pager { display: flex; justify-content: space-between; align-items: center; gap: .75rem; margin: 1.5rem 0 2rem; font-size: .9rem; color: var(--sp-muted); }
        .sp__pager a { display: inline-block; padding: .55rem 1rem; border-radius: 10px; background: #fff; border: 1px solid var(--line-strong, #d9cbb4); color: var(--sp-navy); font-weight: 700; text-decoration: none; }
        .sp__pager a:hover { background: var(--surface, #f8f2e9); }
        .sp__pager span.is-disabled { opacity: .4; padding: .55rem 1rem; }

        .sp__recent { background: #fff; border: 1px solid var(--line, #e7dccb); border-radius: 14px; overflow: hidden; box-shadow: var(--shadow-sm); margin-bottom: 2rem; }
        .sp__recent table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        .sp__recent th, .sp__recent td { padding: .7rem 1rem; text-align: left; border-bottom: 1px solid var(--line, #e7dccb); }
        .sp__recent th { font-family: var(--font-cond); font-size: .75rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--sp-muted); background: var(--surface, #f8f2e9); }
        .sp__recent td.num, .sp__recent th.num { text-align: right; }
        .sp__recent td.num { font-family: var(--font-head); font-size: 1.1rem; font-weight: 800; color: var(--club-primary, #6d2231); }

        .sp__alert { margin: 0 0 1.1rem; padding: .8rem 1rem; border-radius: 10px; font-size: .92rem; font-weight: 600; }
        .sp__alert--ok { background: #e6f4ea; color: #1b6b35; }
        .sp__alert--err { background: #fbe9e9; color: #8a1f1f; }
        .sp__empty { padding: 1.5rem; text-align: center; color: var(--sp-muted); background: #fff; border: 1px dashed var(--line-strong, #d9cbb4); border-radius: 14px; }
    </style>

    <section class="sp__hero">
        <span class="sp__eyebrow">My Vics</span>
        <h1>Score Predictor</h1>
        <p>Predict the score and the goalscorer for our next games. Predictions close at kick-off.</p>
    </section>

    <?php if ($message !== ''): ?>
        <div class="sp__alert <?= $messageIsError ? 'sp__alert--err' : 'sp__alert--ok' ?>"><?= h($message) ?></div>
    <?php endif; ?>

    <ul class="sp__points" aria-label="Points">
        <li class="sp__point"><strong><?= (int) $points['submit'] ?></strong><span>for submitting</span></li>
        <li class="sp__point"><strong><?= (int) $points['result'] ?></strong><span>correct result</span></li>
        <li class="sp__point"><strong><?= (int) $points['city_score'] ?></strong><span>each correct score or goalscorer</span></li>
        <li class="sp__point"><strong>+<?= (int) $points['cup_bonus'] ?></strong><span>on every cup game</span></li>
    </ul>

    <details class="sp__rules">
        <summary>How scoring works</summary>
        <div class="sp__rules-body">
            <ul>
                <li><strong><?= (int) $points['submit'] ?></strong> for submitting a prediction</li>
                <li><strong><?= (int) $points['result'] ?></strong> for the correct result (win, draw or loss)</li>
                <li><strong><?= (int) $points['city_score'] ?></strong> for our correct score</li>
                <li><strong><?= (int) $points['opposition_score'] ?></strong> for the correct opponent score</li>
                <li><strong><?= (int) $points['scoreline'] ?></strong> for the exact scoreline</li>
                <li><strong><?= (int) $points['goalscorer'] ?></strong> for a correct goalscorer</li>
                <li><strong>+<?= (int) $points['cup_bonus'] ?></strong> extra on every cup game</li>
            </ul>
            <p>Up to <?= $maxLeague ?> points per league match and <?= $maxCup ?> per cup match. Points count towards the monthly and season leaderboards, see the <a href="/members/predictor_leaderboard.php">leaderboard</a>.</p>
        </div>
    </details>

    <div class="sp__head">
        <h2>Upcoming games</h2>
        <?php if ($fixtures): ?><small>Showing <?= count($visibleFixtures) ?> of <?= count($fixtures) ?></small><?php endif; ?>
    </div>

    <?php if (!$fixtures): ?>
        <div class="sp__empty">No games to predict right now. Check back when the next fixture is added.</div>
    <?php endif; ?>

    <div class="sp__grid">
        <?php foreach ($visibleFixtures as $fixture):
            $fixtureId = (int) $fixture['id'];
            $locked = sp_fixture_is_locked($fixture, $now);
            $isCup = sp_fixture_is_cup($pdo, $fixture);
            $mine = $predictions[$fixtureId] ?? null;
            $kickoff = $kickoffFor($fixture);
            $hasTime = trim((string) ($fixture['kickoff_time'] ?? '')) !== '';
            $theirSide = (string) $fixture['opponent'];
            $where = $fixture['is_home'] ? 'Home game' : 'Away game';
            $predicted = $predictorCounts[$fixtureId] ?? 0;

            // The opponent's badge when we have one on file, otherwise their initials.
            $crestUrl = pub_opponent_crest((string) ($fixture['opponent_logo_path'] ?? ''), $theirSide);
            $oppositionCrest = $crestUrl !== ''
                ? '<div class="sp__crest"><img src="' . e($crestUrl) . '" alt="' . e($theirSide) . '"></div>'
                : '<div class="sp__crest sp__crest--text" title="' . e($theirSide) . '">' . e($badgeText($theirSide)) . '</div>';
        ?>
            <article class="sp__card">
                <div class="sp__card-top">
                    <span><?= h((string) ($fixture['competition'] ?? 'Fixture')) ?></span>
                    <span>
                        <?php if ($isCup): ?><span class="sp__pill sp__pill--cup">Cup +<?= (int) $points['cup_bonus'] ?></span> <?php endif; ?>
                        <?php if ($locked): ?>
                            <span class="sp__pill sp__pill--closed">Closed</span>
                        <?php else: ?>
                            <span class="sp__pill sp__pill--open"><?= h($closesIn($kickoff)) ?></span>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="sp__title">Predict the score</div>
                <div class="sp__meta">
                    <?= h($where) ?> &middot; <?= h($kickoff->format('l j F')) ?><?= $hasTime ? ' &middot; ' . h($kickoff->format('H:i')) : '' ?>
                </div>

                <?php if ($locked): ?>
                    <div class="sp__versus">
                        <div class="sp__crest"><img src="<?= e($ourCrest) ?>" alt="Saltcoats Victoria"></div>
                        <div class="sp__pick"><?= $mine ? (int) $mine['city_score'] : '?' ?></div>
                        <div class="sp__pick"><?= $mine ? (int) $mine['opposition_score'] : '?' ?></div>
                        <?= $oppositionCrest ?>
                    </div>
                    <?php if ($mine): ?>
                        <div class="sp__closed"><strong>Your pick: <?= h((string) $mine['goalscorer']) ?></strong>Predictions closed at kick-off.</div>
                    <?php else: ?>
                        <div class="sp__closed"><strong>Closed</strong>You did not make a prediction for this game.</div>
                    <?php endif; ?>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
                        <input type="hidden" name="fixture_id" value="<?= $fixtureId ?>">
                        <div class="sp__versus">
                            <div class="sp__crest"><img src="<?= e($ourCrest) ?>" alt="Saltcoats Victoria"></div>
                            <div class="sp__score">
                                <label for="city-<?= $fixtureId ?>">Saltcoats</label>
                                <input type="number" inputmode="numeric" min="0" max="20" step="1" required id="city-<?= $fixtureId ?>" name="city_score" value="<?= $mine ? (int) $mine['city_score'] : '' ?>" aria-label="Saltcoats score">
                            </div>
                            <div class="sp__score">
                                <label for="opp-<?= $fixtureId ?>">Opponent</label>
                                <input type="number" inputmode="numeric" min="0" max="20" step="1" required id="opp-<?= $fixtureId ?>" name="opposition_score" value="<?= $mine ? (int) $mine['opposition_score'] : '' ?>" aria-label="<?= h($theirSide) ?> score">
                            </div>
                            <?= $oppositionCrest ?>
                        </div>

                        <div class="sp__scorer">
                            <label for="gs-<?= $fixtureId ?>">First goalscorer</label>
                            <select id="gs-<?= $fixtureId ?>" name="goalscorer" required>
                                <option value="">Choose a player</option>
                                <?php foreach ($squadNames as $name): ?>
                                    <option value="<?= h($name) ?>" <?= $mine && (string) $mine['goalscorer'] === $name ? 'selected' : '' ?>><?= h($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="sp__cta"><?= $mine ? 'Update prediction' : 'Save prediction' ?></button>
                    </form>
                <?php endif; ?>

                <div class="sp__count">
                    <?php if ($predicted > 0): ?>
                        <span class="sp__stack" aria-hidden="true"><span></span><span></span><span></span></span>
                        <span><strong><?= $predicted ?></strong> <?= $predicted === 1 ? 'Vic has' : 'Vics have' ?> predicted</span>
                    <?php else: ?>
                        <span>Be the first to predict this game</span>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($pageCount > 1): ?>
        <nav class="sp__pager" aria-label="Upcoming games pages">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>">&larr; Previous</a>
            <?php else: ?>
                <span class="is-disabled">&larr; Previous</span>
            <?php endif; ?>
            <span>Page <?= $page ?> of <?= $pageCount ?></span>
            <?php if ($page < $pageCount): ?>
                <a href="?page=<?= $page + 1 ?>">Next &rarr;</a>
            <?php else: ?>
                <span class="is-disabled">Next &rarr;</span>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <?php if ($recent): ?>
        <div class="sp__head"><h2>Your recent scores</h2></div>
        <div class="sp__recent">
            <table>
                <thead><tr><th>Date</th><th>Opponent</th><th class="num">Points</th></tr></thead>
                <tbody>
                    <?php foreach ($recent as $row): ?>
                        <tr>
                            <td><?= h(date('j M Y', strtotime((string) $row['match_date']))) ?></td>
                            <td><?= h((string) $row['opponent']) ?><?= (int) $row['is_cup'] === 1 ? ' <span class="sp__pill sp__pill--cup">Cup</span>' : '' ?></td>
                            <td class="num"><?= (int) $row['total_points'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
