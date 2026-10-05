<?php
declare(strict_types=1);

if (!defined('MATCH_POSTER_PARTIAL_ALLOWED')) {
    http_response_code(404);
    exit('Not found.');
}
// Shared poster markup — the ONLY place the <article class="match-poster">
// HTML is written. Both match_poster.php (the interactive admin page) and
// match_poster_fragment.php (the bare page server-side Chrome screenshots
// for the PNG/PDF export) include this file, so the exported image can never
// drift from what's shown on screen — there is only one template.
//
// Expects $pdo and $fixture (a getMatchFixtureById() row) to already be set
// by the caller. Reads admission prices from $_GET (adult/concession/under16),
// same as the rest of this feature.

require_once __DIR__ . '/lib/match_sponsorship.php';

function matchPosterBadgeUrl(PDO $pdo, string $club): string
{
    if (strcasecmp(trim($club), 'Saltcoats Victoria') === 0) {
        return '/admin/assets/images/Saltcoats Victoria FC.png';
    }
    return matchOpponentBadgeAssetUrl($pdo, $club, false);
}

function matchPosterTeamName(string $club): string
{
    $club = trim($club);
    if (strcasecmp($club, 'Saltcoats Victoria') === 0) {
        return 'Saltcoats Victoria';
    }
    return preg_replace('/\s+FC$/i', '', $club) ?? $club;
}

$opponent = trim((string)$fixture['opponent']) ?: 'Opponent';
$isHome = (int)($fixture['is_home'] ?? 1) === 1;
$homeTeam = $isHome ? 'Saltcoats Victoria' : $opponent;
$awayTeam = $isHome ? $opponent : 'Saltcoats Victoria';
$homeBadge = matchPosterBadgeUrl($pdo, $homeTeam);
$awayBadge = matchPosterBadgeUrl($pdo, $awayTeam);

$matchDate = trim((string)$fixture['match_date']);
$kickoff = trim((string)$fixture['kickoff_time']);
$timestamp = strtotime($matchDate . ' ' . ($kickoff !== '' ? $kickoff : '15:00:00'));
$dateWeekday = $timestamp ? strtoupper(date('l', $timestamp)) : '';
$dateDay = $timestamp ? (int)date('j', $timestamp) : 0;
$dateOrdinal = $timestamp ? strtoupper(date('S', $timestamp)) : '';
$dateMonth = $timestamp ? strtoupper(date('F', $timestamp)) : '';
$dateYear = $timestamp ? date('y', $timestamp) : '';
$dateLabel = $timestamp ? strtoupper(date('l jS F y', $timestamp)) : strtoupper($matchDate);
$kickoffLabel = $kickoff !== '' && $timestamp ? date('g:i A', $timestamp) : 'TBC';
$competition = strtoupper(trim((string)$fixture['competition']) ?: 'FIXTURE');
if (!str_contains($competition, 'WOSFL')) {
    $competition = 'WOSFL - ' . $competition;
}

$venueName = trim((string)$fixture['venue']) ?: 'VENUE TBC';
$venue = null;
if (!$isHome && (int)($fixture['opponent_id'] ?? 0) > 0) {
    $stmt = $pdo->prepare("
        SELECT v.*
        FROM match_opponents o
        INNER JOIN match_venues v ON v.id = o.venue_id
        WHERE o.id = :opponent_id
        LIMIT 1
    ");
    $stmt->execute([':opponent_id' => (int)$fixture['opponent_id']]);
    $venue = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$venue && $venueName !== '') {
    $stmt = $pdo->prepare("
        SELECT *
        FROM match_venues
        WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))
        ORDER BY
            CASE WHEN postcode IS NOT NULL AND TRIM(postcode) <> '' THEN 0 ELSE 1 END,
            id ASC
        LIMIT 1
    ");
    $stmt->execute([':name' => $venueName]);
    $venue = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$venueAddressLine1 = trim((string)($venue['address_line1'] ?? ''));
$venueTown = trim((string)($venue['town'] ?? ''));
$postcode = strtoupper(trim((string)($venue['postcode'] ?? ''))) ?: 'POSTCODE TBC';
$venueFullAddress = strtoupper(trim(implode(', ', array_filter([$venueAddressLine1, $venueTown, $postcode]))));

$adultPrice = trim((string)($_GET['adult'] ?? '6.00'));
$concessionPrice = trim((string)($_GET['concession'] ?? '3.00'));
$under16Price = trim((string)($_GET['under16'] ?? 'FREE'));
?>
<article class="match-poster" id="matchPoster" aria-label="Fixture poster">
  <div class="match-poster__border">
    <div class="match-poster__teams">
      <div class="match-poster__team">
        <div class="match-poster__badge-wrap">
          <?php if ($homeBadge !== ''): ?>
            <img class="match-poster__badge" src="<?= h($homeBadge) ?>" alt="<?= h($homeTeam) ?> badge">
          <?php else: ?>
            <span class="match-poster__badge-placeholder"><?= h(substr($homeTeam, 0, 1)) ?></span>
          <?php endif; ?>
        </div>
        <div class="match-poster__team-name"><?= h(matchPosterTeamName($homeTeam)) ?></div>
      </div>
      <div class="match-poster__versus">- VS -</div>
      <div class="match-poster__team">
        <div class="match-poster__badge-wrap">
          <?php if ($awayBadge !== ''): ?>
            <img class="match-poster__badge" src="<?= h($awayBadge) ?>" alt="<?= h($awayTeam) ?> badge">
          <?php else: ?>
            <span class="match-poster__badge-placeholder"><?= h(substr($awayTeam, 0, 1)) ?></span>
          <?php endif; ?>
        </div>
        <div class="match-poster__team-name"><?= h(matchPosterTeamName($awayTeam)) ?></div>
      </div>
    </div>

    <div class="match-poster__competition"><?= h($competition) ?></div>

    <div class="match-poster__fixture">
      <div class="match-poster__date">
        <?php if ($timestamp): ?>
          <?= h($dateWeekday) ?> <?= (int)$dateDay ?><span class="match-poster__date-ordinal"><?= h($dateOrdinal) ?></span> <?= h($dateMonth) ?> <?= h($dateYear) ?>
        <?php else: ?>
          <?= h($dateLabel) ?>
        <?php endif; ?>
      </div>
      <div class="match-poster__kickoff">KICK-OFF: <strong><?= h($kickoffLabel) ?></strong></div>
    </div>

    <div class="match-poster__venue">
      <div class="match-poster__venue-name"><?= h($venueName) ?></div>
      <div class="match-poster__venue-address"><?= h($venueFullAddress) ?></div>
    </div>

    <div class="match-poster__admission">
      <div>Adults: <strong>£<span data-poster-adult><?= h($adultPrice) ?></span></strong></div>
      <div>Concession: <strong>£<span data-poster-concession><?= h($concessionPrice) ?></span></strong></div>
      <div>Under 16s: <strong data-poster-under16><?= h($under16Price) ?></strong></div>
      <div class="match-poster__note">(accompanied by an adult)</div>
    </div>
  </div>
</article>
