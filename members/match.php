<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/../admin/lib/member_matches.php';
require_once __DIR__ . '/../admin/lib/venue_reviews.php';
require_once __DIR__ . '/../admin/lib/motm.php';
ensureVenueReviewsSchema($pdo);
ensureMotmSchema($pdo);

$fixtureId = (int) ($_GET['id'] ?? 0);
$detail = $fixtureId > 0 ? member_match_detail($pdo, $fixtureId) : null;

if (!$detail) {
    echo '<div class="alert alert-danger">Match not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$fixture = $detail['fixture'];
$isHome = (int) $fixture['is_home'] === 1;
$isPlayed = (string) $fixture['status'] === 'played' || ($fixture['full_time_home_score'] !== null && $fixture['full_time_away_score'] !== null);
$venueInfo = getVenueInfoForFixture($pdo, $fixture);
$venueName = $venueInfo ? (string) $venueInfo['name'] : venueReviewFixtureName($fixture);
$currentPersonId = member_auth_current_person_id() ?? (is_array($currentHolder) ? (int) ($currentHolder['person_id'] ?? 0) : 0);
$currentLegacyHolderId = member_auth_current_legacy_holder_id() ?? (is_array($currentHolder) ? (int) ($currentHolder['legacy_holder_id'] ?? $currentHolder['id'] ?? 0) : 0);
$h2hMatches = member_match_head_to_head($pdo, $fixture);
$h2hRecord = member_match_head_to_head_record($h2hMatches);

$tableTeams = [];
$cachePath = __DIR__ . '/../admin/cache/wosfl_table.json';
if (is_file($cachePath)) {
    $decoded = json_decode((string) file_get_contents($cachePath), true);
    $tableTeams = is_array($decoded) ? $decoded : [];
}
$saltcoatsRow = null;
$opponentRow = null;
foreach ($tableTeams as $team) {
    $club = (string) ($team['club'] ?? '');
    if ($saltcoatsRow === null && stripos($club, 'saltcoats') !== false) {
        $saltcoatsRow = $team;
    }
    if ($opponentRow === null && $club !== '' && stripos($club, (string) $fixture['opponent']) !== false) {
        $opponentRow = $team;
    }
}

$matchPhotosStmt = $pdo->prepare("
    SELECT filename, kit
    FROM match_photos
    WHERE match_fixture_id = :fixture_id
    ORDER BY uploaded_at DESC
");
$matchPhotosStmt->execute([':fixture_id' => $fixtureId]);
$matchPhotos = $matchPhotosStmt->fetchAll(PDO::FETCH_ASSOC);
$veoUrl = trim((string) ($fixture['veo_url'] ?? ''));
$squadNumbers = json_decode((string) ($fixture['starting11_squad_numbers_json'] ?? ''), true);
$squadNumbers = is_array($squadNumbers) ? $squadNumbers : [];
$matchPhotoItems = [];
foreach ($matchPhotos as $index => $photo) {
    $matchPhotoItems[] = [
        'url' => '/uploads/matches/gallery/' . (int) $fixtureId . '/' . rawurlencode((string) $photo['filename']),
        'label' => 'Match photo ' . ($index + 1) . ' of ' . count($matchPhotos),
    ];
}

$addressParts = array_filter([
    trim((string) ($venueInfo['address_line1'] ?? '')),
    trim((string) ($venueInfo['town'] ?? '')),
    trim((string) ($venueInfo['postcode'] ?? '')),
], static fn(string $part): bool => $part !== '');
$mapQuery = trim($venueName . ' ' . implode(' ', $addressParts));

function member_match_team_result(array $fixture): string
{
    if ($fixture['full_time_home_score'] === null || $fixture['full_time_away_score'] === null) {
        return 'Fixture';
    }
    $home = (int) $fixture['full_time_home_score'];
    $away = (int) $fixture['full_time_away_score'];
    $saltcoats = (int) $fixture['is_home'] === 1 ? $home : $away;
    $opponent = (int) $fixture['is_home'] === 1 ? $away : $home;
    if ($saltcoats > $opponent) {
        return 'Win';
    }
    if ($saltcoats === $opponent) {
        return 'Draw';
    }
    return 'Loss';
}

function member_match_event_side(array $event): string
{
    return (string) ($event['team'] ?? '') === 'opponent' ? 'opponent' : 'svfc';
}

function member_match_event_minute(array $event): string
{
    $minute = trim((string) ($event['minute'] ?? ''));
    return $minute !== '' ? rtrim($minute, "'") . "'" : '';
}

function member_match_event_icon(array $event): string
{
    return match ((string) ($event['type'] ?? '')) {
        'goal' => 'fa-futbol',
        'substitution' => 'fa-right-left',
        'yellow_card', 'red_card', 'card' => 'fa-square',
        'corner' => 'fa-flag',
        'shot', 'chance' => 'fa-bullseye',
        'half_time', 'full_time' => 'fa-whistle',
        default => 'fa-circle-info',
    };
}

function member_match_event_title(array $event, string $opponent): string
{
    $type = (string) ($event['type'] ?? '');
    $player = trim((string) ($event['player'] ?? ''));
    $knownPlayer = $player !== '' && strcasecmp($player, 'Unknown Player') !== 0;
    $teamName = member_match_event_side($event) === 'opponent' ? $opponent : 'Saltcoats Victoria';

    if ($type === 'goal') {
        return $knownPlayer ? $player : $teamName;
    }
    if ($type === 'substitution') {
        $changes = isset($event['substitutions']) && is_array($event['substitutions']) ? $event['substitutions'] : [];
        if (count($changes) > 1) {
            return count($changes) . ' substitutions';
        }
        return trim((string) ($event['secondary_player'] ?? '')) ?: 'Substitution';
    }
    if (in_array($type, ['yellow_card', 'red_card', 'card'], true)) {
        return $knownPlayer ? $player : $teamName;
    }
    if (in_array($type, ['shot', 'chance', 'corner'], true)) {
        return $knownPlayer ? $player : ucwords(str_replace('_', ' ', $type));
    }

    return matchOverviewEventTitle($event, $opponent);
}

function member_match_event_detail(array $event): string
{
    $parts = [];
    $type = (string) ($event['type'] ?? '');
    $outcome = trim((string) ($event['outcome'] ?? ''));
    $origin = trim((string) ($event['origin'] ?? ''));
    $target = trim((string) ($event['target'] ?? ''));
    $note = trim((string) ($event['note'] ?? ''));

    if ($type === 'substitution') {
        $off = trim((string) ($event['player'] ?? ''));
        if ($off !== '') {
            $parts[] = 'for ' . $off;
        }
    } elseif (in_array($type, ['yellow_card', 'red_card', 'card'], true)) {
        $cardType = $type === 'card' ? trim((string) ($event['card_type'] ?? '')) : str_replace('_card', '', $type);
        $parts[] = ucfirst($cardType ?: 'card') . ' card';
    } elseif ($outcome !== '') {
        $parts[] = ucwords(str_replace('_', ' ', $outcome));
    }
    if ($origin !== '') {
        $parts[] = $origin;
    }
    if ($target !== '') {
        $parts[] = $target;
    }
    if ($note !== '') {
        $parts[] = $note;
    }

    return implode(' · ', array_filter($parts));
}

function member_match_stat_count(array $events, string $team, callable $filter): int
{
    $count = 0;
    foreach ($events as $event) {
        if (member_match_event_side($event) === $team && $filter($event)) {
            $count++;
        }
    }
    return $count;
}

function member_match_top_stats(array $events): array
{
    $isShot = static fn(array $event): bool => in_array((string) ($event['type'] ?? ''), ['shot', 'goal', 'penalty'], true);
    $isOnTarget = static function (array $event): bool {
        $type = (string) ($event['type'] ?? '');
        $outcome = (string) ($event['outcome'] ?? '');
        return $type === 'goal' || $outcome === 'on_target' || ($type === 'penalty' && $outcome === 'scored');
    };

    return [
        ['label' => 'Ball possession', 'svfc' => '-', 'opponent' => '-'],
        ['label' => 'Total shots', 'svfc' => member_match_stat_count($events, 'svfc', $isShot), 'opponent' => member_match_stat_count($events, 'opponent', $isShot)],
        ['label' => 'Shots on target', 'svfc' => member_match_stat_count($events, 'svfc', $isOnTarget), 'opponent' => member_match_stat_count($events, 'opponent', $isOnTarget)],
        ['label' => 'Corners', 'svfc' => member_match_stat_count($events, 'svfc', static fn(array $event): bool => (string) ($event['type'] ?? '') === 'corner'), 'opponent' => member_match_stat_count($events, 'opponent', static fn(array $event): bool => (string) ($event['type'] ?? '') === 'corner')],
        ['label' => 'Chances', 'svfc' => member_match_stat_count($events, 'svfc', static fn(array $event): bool => (string) ($event['type'] ?? '') === 'chance'), 'opponent' => member_match_stat_count($events, 'opponent', static fn(array $event): bool => (string) ($event['type'] ?? '') === 'chance')],
    ];
}

function member_match_player_number(array $squadNumbers, string $player, int $fallback): int|string
{
    $player = trim($player);
    if ($player !== '') {
        foreach ($squadNumbers as $name => $number) {
            if (is_string($name) && strcasecmp(trim($name), $player) === 0 && trim((string) $number) !== '') {
                return trim((string) $number);
            }
        }
    }
    return $fallback;
}

function member_match_same_player(string $left, string $right): bool
{
    return strcasecmp(trim($left), trim($right)) === 0;
}

function member_match_sub_changes(array $event): array
{
    if (isset($event['substitutions']) && is_array($event['substitutions']) && $event['substitutions']) {
        return array_values(array_filter($event['substitutions'], 'is_array'));
    }

    return [[
        'off' => trim((string) ($event['player'] ?? '')),
        'on' => trim((string) ($event['secondary_player'] ?? '')),
    ]];
}

function member_match_player_events(string $player, array $events): array
{
    $items = [];
    foreach ($events as $event) {
        if (member_match_event_side($event) !== 'svfc') {
            continue;
        }
        $type = (string) ($event['type'] ?? '');
        $minute = member_match_event_minute($event);
        $eventPlayer = trim((string) ($event['player'] ?? ''));
        $secondaryPlayer = trim((string) ($event['secondary_player'] ?? ''));

        if (($type === 'goal' || ((string) ($event['type'] ?? '') === 'penalty' && (string) ($event['outcome'] ?? '') === 'scored')) && member_match_same_player($eventPlayer, $player)) {
            $suffix = (string) ($event['own_goal'] ?? '') === '1' ? ' OG' : (((string) ($event['outcome'] ?? '') === 'penalty' || $type === 'penalty') ? ' pen' : '');
            $items[] = ['type' => 'goal', 'label' => trim($minute . $suffix), 'title' => 'Goal'];
        } elseif (in_array($type, ['yellow_card', 'red_card', 'card'], true) && member_match_same_player($eventPlayer, $player)) {
            $cardType = $type === 'card' ? (string) ($event['card_type'] ?? 'yellow') : str_replace('_card', '', $type);
            $items[] = ['type' => $cardType === 'red' ? 'red-card' : 'yellow-card', 'label' => $minute, 'title' => ucfirst($cardType) . ' card'];
        } elseif ($type === 'substitution') {
            foreach (member_match_sub_changes($event) as $change) {
                $off = trim((string) ($change['off'] ?? $eventPlayer));
                $on = trim((string) ($change['on'] ?? $secondaryPlayer));
                if ($off !== '' && member_match_same_player($off, $player)) {
                    $items[] = ['type' => 'sub-off', 'label' => $minute, 'title' => 'Substituted off'];
                }
                if ($on !== '' && member_match_same_player($on, $player)) {
                    $items[] = ['type' => 'sub-on', 'label' => $minute, 'title' => $off !== '' ? 'On for ' . $off : 'Substituted on'];
                }
            }
        }
    }
    return $items;
}

function member_match_player_event_icon(string $type): string
{
    return match ($type) {
        'goal' => 'fa-futbol',
        'yellow-card', 'red-card' => 'fa-square',
        'sub-on', 'sub-off' => 'fa-right-left',
        default => 'fa-circle-info',
    };
}

$reviewError = '';
if ($isPlayed && member_auth_is_authenticated() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'venue_review') {
    if (!member_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $reviewError = 'Your session expired. Please try again.';
    } else {
        $rating = (int) ($_POST['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            $reviewError = 'Choose a star rating first.';
        } else {
            $reviewOptions = array_values(array_filter(array_map('trim', (array) ($_POST['review_options'] ?? [])), static fn(string $value): bool => $value !== ''));
            $comment = trim((string) ($_POST['comment'] ?? ''));
            if ($reviewOptions) {
                $comment = trim('Selected: ' . implode(', ', $reviewOptions) . ($comment !== '' ? "\n\n" . $comment : ''));
            }
            saveVenueReview($pdo, $currentPersonId, $fixtureId, $venueName, $rating, $comment, $currentLegacyHolderId);
        }
    }
}

$ratingSummary = getVenueRatingSummary($pdo, $venueName);
$myReview = member_auth_is_authenticated() ? getMyVenueReview($pdo, $currentPersonId, $fixtureId, $currentLegacyHolderId) : null;

$motmError = '';
if ($isPlayed && member_auth_is_authenticated() && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'motm_vote') {
    if (!member_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $motmError = 'Your session expired. Please try again.';
    } else {
        $candidates = array_merge($detail['starters'], $detail['substitutes']);
        $choice = trim((string) ($_POST['player_name'] ?? ''));
        if (!in_array($choice, $candidates, true)) {
            $motmError = 'Choose a player from the squad list.';
        } else {
            saveMotmVote($pdo, $currentPersonId, $fixtureId, $choice, $currentLegacyHolderId);
        }
    }
}
$myMotmVote = member_auth_is_authenticated() ? getMyMotmVote($pdo, $currentPersonId, $fixtureId, $currentLegacyHolderId) : null;
$motmResults = getMotmResults($pdo, $fixtureId);
$motmCandidates = array_values(array_unique(array_merge($detail['starters'], $detail['substitutes'])));
$topStats = member_match_top_stats($detail['events']);
?>

<style>
    .member-veo-card { display:flex; align-items:center; gap:1rem; padding:1rem; border-radius:14px; background:linear-gradient(135deg,#4b0818 0%,#7a1730 100%); color:#fff; text-decoration:none; margin-bottom:1rem; }
    .member-veo-card:hover { opacity:.92; color:#fff; }
    .member-veo-card__play { display:flex; align-items:center; justify-content:center; width:44px; height:44px; border-radius:50%; background:rgba(255,255,255,.18); flex:0 0 auto; font-size:1.05rem; }
    .member-veo-card strong { display:block; }
    .member-veo-card .text-muted { color:#f7efe4 !important; opacity:.86; }
    .member-photo-carousel { display:grid; gap:.75rem; }
    .member-photo-carousel__top { display:flex; align-items:center; justify-content:space-between; gap:1rem; }
    .member-photo-carousel__controls { display:flex; gap:.45rem; }
    .member-photo-carousel__control { display:inline-grid; width:2.25rem; height:2.25rem; place-items:center; border:1px solid #eadfdf; border-radius:999px; color:#4b0818; background:#fff; }
    .member-photo-carousel__track { display:grid; grid-auto-flow:column; grid-auto-columns:minmax(210px,38%); gap:.75rem; overflow-x:auto; padding:.15rem .1rem .65rem; scroll-snap-type:x mandatory; scrollbar-width:thin; }
    .member-photo-carousel__item { position:relative; display:block; aspect-ratio:4/3; width:100%; padding:0; border:0; border-radius:14px; overflow:hidden; background:#21141a; scroll-snap-align:start; box-shadow:0 10px 24px rgba(33,20,26,.1); }
    .member-photo-carousel__item img { width:100%; height:100%; object-fit:cover; display:block; transition:transform .18s ease, opacity .18s ease; }
    .member-photo-carousel__item:hover img { transform:scale(1.035); opacity:.88; }
    .member-photo-carousel__item:focus-visible { outline:3px solid #e0b42a; outline-offset:3px; }
    .member-lightbox .modal-dialog { width:auto; max-width:none; margin-right:auto; margin-left:auto; }
    .member-lightbox .modal-content { position:relative; width:max-content; max-width:94vw; margin:auto; border:0; background:transparent; box-shadow:none; }
    .member-lightbox__stage { position:relative; display:grid; place-items:center; min-height:0; background:transparent; }
    .member-lightbox__image { display:block; max-width:min(94vw,1180px); max-height:88vh; border-radius:8px; object-fit:contain; box-shadow:0 22px 70px rgba(0,0,0,.55); }
    .member-lightbox__close { position:absolute; top:-.75rem; right:-.75rem; z-index:3; display:inline-grid; width:2.4rem; height:2.4rem; place-items:center; border:0; border-radius:999px; color:#fff; background:#4b0818; box-shadow:0 .65rem 1.6rem rgba(0,0,0,.35); }
    .member-lightbox__nav { position:absolute; top:50%; transform:translateY(-50%); display:inline-grid; width:2.75rem; height:2.75rem; place-items:center; border:0; border-radius:999px; color:#fff; background:rgba(75,8,24,.72); }
    .member-lightbox__nav:hover { background:rgba(75,8,24,.86); }
    .member-lightbox__nav--prev { left:1rem; }
    .member-lightbox__nav--next { right:1rem; }
    .member-page .member-grid--aside { grid-template-columns:minmax(0,1fr) 420px; }
    .member-lineup { display:grid; grid-template-columns:minmax(0,1.55fr) minmax(220px,.9fr); gap:1rem; align-items:start; }
    .member-lineup__panel { border:1px solid #eadfdf; border-radius:14px; overflow:hidden; background:#fff; }
    .member-lineup__panel--starters { background:linear-gradient(180deg,#fff 0%,#f9f6f4 100%); }
    .member-lineup__title { display:flex; align-items:center; justify-content:space-between; gap:.75rem; padding:.85rem 1rem; color:#fff; background:#4b0818; font-weight:850; }
    .member-lineup__list { list-style:none; margin:0; padding:.55rem; display:grid; gap:.45rem; }
    .member-lineup__player { display:flex; align-items:center; gap:.65rem; min-height:2.75rem; padding:.55rem .65rem; border:1px solid rgba(75,8,24,.09); border-radius:10px; background:#fff; }
    .member-lineup__number { display:inline-grid; width:2rem; height:2rem; flex:0 0 2rem; place-items:center; border-radius:999px; color:#fff; background:#4b0818; font-weight:850; font-size:.85rem; }
    .member-lineup__name { min-width:0; font-weight:800; color:#21141a; overflow-wrap:anywhere; }
    .member-lineup__captain { margin-left:auto; flex:0 0 auto; border-radius:999px; padding:.2rem .45rem; background:#f7f2e6; color:#5a3f00; font-size:.68rem; font-weight:850; letter-spacing:.06em; }
    .member-lineup__empty { margin:0; padding:1rem; color:#6f6470; }
    .member-lineup__events { display:flex; flex-wrap:wrap; gap:.35rem; margin-left:auto; justify-content:flex-end; }
    .member-lineup__event { display:inline-flex; align-items:center; gap:.25rem; min-height:1.55rem; padding:.18rem .42rem; border-radius:999px; color:#21141a; background:#f7f2e6; font-size:.76rem; font-weight:850; white-space:nowrap; }
    .member-lineup__event--goal { color:#fff; background:#4b0818; }
    .member-lineup__event--yellow-card { background:#ffe45c; }
    .member-lineup__event--red-card { color:#fff; background:#b51d2a; }
    .member-lineup__event--sub-on { color:#035a32; background:#d9f6e8; }
    .member-lineup__event--sub-off { color:#7a1730; background:#f6e2e8; }
    .member-lineup__event--unused { color:#6f6470; background:#f3eeee; font-style:italic; }
    .member-top-stats { display:grid; gap:.7rem; }
    .member-top-stats__teams { display:grid; grid-template-columns:minmax(4rem,.32fr) minmax(0,1fr) minmax(4rem,.32fr); gap:.85rem; align-items:center; color:#6f6470; font-size:.72rem; font-weight:850; letter-spacing:.08em; text-transform:uppercase; }
    .member-top-stat { display:grid; grid-template-columns:minmax(4rem,.32fr) minmax(0,1fr) minmax(4rem,.32fr); gap:.85rem; align-items:center; }
    .member-top-stat__value { color:#4b0818; font-size:1.15rem; font-weight:900; text-align:center; }
    .member-top-stat__label { position:relative; display:grid; gap:.3rem; color:#21141a; font-size:.78rem; font-weight:850; text-align:center; text-transform:uppercase; letter-spacing:.04em; }
    .member-top-stat__bar { position:relative; height:.42rem; overflow:hidden; border-radius:999px; background:#eadfdf; }
    .member-top-stat__fill { position:absolute; inset:0 auto 0 0; width:var(--stat-share, 50%); border-radius:999px; background:linear-gradient(90deg,#4b0818,#e0b42a); }
    .member-events-timeline { padding:1rem; border-radius:14px;}
    .member-events-timeline__title { margin:0 0 1rem; font-size:.95rem; font-weight:850; text-align:center; }
    .member-event-row { display:grid; grid-template-columns:minmax(0,1fr) 3.2rem minmax(0,1fr); gap:.75rem; align-items:center; min-height:3.45rem; }
    .member-event-row--stage { margin:.65rem 0; }
    .member-event-row--stage:before,
    .member-event-row--stage:after { content:""; height:1px; background:rgba(255,255,255,.14); }
    .member-event-row__minute { display:grid; width:2.6rem; height:2.6rem; place-items:center; justify-self:center; border-radius:999px; background:#4a4a4a; color:#fff; font-size:.86rem; font-weight:900; }
    .member-event-row__team { display:flex; align-items:center; gap:.65rem; min-width:0; }
    .member-event-row__team--svfc { justify-content:flex-end; text-align:right; }
    .member-event-row__team--opponent { justify-content:flex-start; text-align:left; }
    .member-event-row__copy { min-width:0; }
    .member-event-row__title { color:#000; font-weight:850; line-height:1.15; overflow-wrap:anywhere; }
    .member-event-row__detail { margin-top:.12rem; font-size:.78rem; line-height:1.15; }
    .member-event-row__icon { display:inline-grid; width:1.6rem; height:1.6rem; flex:0 0 1.6rem; place-items:center; border:1px solid rgba(255,255,255,.18); border-radius:999px; color:#f7efe4; background:#111; font-size:.72rem; }
    .member-event-row--goal .member-event-row__icon { color:#fff; }
    .member-event-row--substitution .member-event-row__title { color:#000000; }
    .member-event-row--substitution .member-event-row__detail { color:#ff3434; font-weight:800; }
    .member-event-row--yellow_card .member-event-row__icon,
    .member-event-row--card .member-event-row__icon { color:#ffc928; }
    .member-event-row--red_card .member-event-row__icon { color:#ff3434; }
    .venue-review-options { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.45rem; margin:.65rem 0; }
    .venue-review-option { display:flex; align-items:center; gap:.45rem; padding:.45rem .55rem; border:1px solid #eadfdf; border-radius:.65rem; background:#fff; font-size:.82rem; font-weight:700; }
    @media (max-width: 767.98px) {
        .member-photo-carousel__track { grid-auto-columns:minmax(175px,74%); }
        .member-lineup { grid-template-columns:1fr; }
        .member-lightbox__image { max-width:94vw; max-height:82vh; }
        .member-lightbox__close { top:.5rem; right:.5rem; }
        .member-lightbox__nav { width:2.5rem; height:2.5rem; }
        .member-lightbox__nav--prev { left:.5rem; }
        .member-lightbox__nav--next { right:.5rem; }
        .member-event-row { grid-template-columns:minmax(0,1fr) 2.8rem minmax(0,1fr); gap:.45rem; }
        .member-event-row__minute { width:2.35rem; height:2.35rem; }
        .venue-review-options { grid-template-columns:1fr; }
    }
</style>

<div class="member-page">
    <section class="member-hero">
        <div class="member-hero__content">
            <div class="member-hero__eyebrow"><?= $isPlayed ? 'Match Result' : 'Upcoming Fixture' ?></div>
            <h1><?= $isHome ? 'Saltcoats Victoria vs ' . h((string) $fixture['opponent']) : h((string) $fixture['opponent']) . ' vs Saltcoats Victoria' ?></h1>
            <p><?= h(member_format_date((string) $fixture['match_date'])) ?><?= !empty($fixture['kickoff_time']) ? ' at ' . h(member_format_time((string) $fixture['kickoff_time'])) : '' ?><?= !empty($fixture['competition']) ? ' · ' . h((string) $fixture['competition']) : '' ?></p>
            <?php if (!$isPlayed && $isHome): ?>
            <div class="member-hero__actions">
                <a class="btn btn-light fw-bold" href="/tickets?fixture_id=<?= (int) $fixture['id'] ?>">Buy match tickets</a>
                <a class="btn btn-outline-light" href="/season-tickets">Season tickets</a>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <nav class="mb-3"><a href="matches.php">&larr; All matches</a></nav>

    <div class="member-grid member-grid--3 mb-3">
        <div class="member-stat"><span>Date</span><strong><?= h(member_format_date((string) $fixture['match_date'], 'TBC')) ?></strong></div>
        <div class="member-stat"><span>Kick-off</span><strong><?= h(member_format_time((string) ($fixture['kickoff_time'] ?? '')) ?: 'TBC') ?></strong></div>
        <div class="member-stat"><span>Venue</span><strong><?= h($venueName) ?></strong></div>
    </div>

    <div class="member-grid member-grid--aside">
        <div class="member-list">
            <?php if ($detail['events']): ?>
                <section class="member-card">
                    <div class="member-card__header"><h2>Top Stats</h2></div>
                    <div class="member-card__body">
                        <div class="member-top-stats">
                            <div class="member-top-stats__teams"><span>Vics</span><span></span><span><?= h((string) $fixture['opponent']) ?></span></div>
                            <?php foreach ($topStats as $stat): ?>
                                <?php
                                    $leftNumeric = is_numeric($stat['svfc']);
                                    $rightNumeric = is_numeric($stat['opponent']);
                                    $total = $leftNumeric && $rightNumeric ? (int) $stat['svfc'] + (int) $stat['opponent'] : 0;
                                    $share = $total > 0 ? round(((int) $stat['svfc'] / $total) * 100) : 50;
                                ?>
                                <div class="member-top-stat">
                                    <div class="member-top-stat__value"><?= h((string) $stat['svfc']) ?></div>
                                    <div class="member-top-stat__label">
                                        <span><?= h((string) $stat['label']) ?></span>
                                        <span class="member-top-stat__bar" aria-hidden="true"><span class="member-top-stat__fill" style="--stat-share:<?= (int) $share ?>%"></span></span>
                                    </div>
                                    <div class="member-top-stat__value"><?= h((string) $stat['opponent']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($detail['events']): ?>
                <section class="member-card">
                    <div class="member-card__header"><h2>Events</h2></div>
                    <div class="member-card__body">
                        <div class="member-events-timeline">
                      
                            <?php foreach ($detail['events'] as $event): ?>
                                <?php
                                    $eventType = (string) ($event['type'] ?? '');
                                    $eventSide = member_match_event_side($event);
                                    $isStageEvent = in_array($eventType, ['half_time', 'full_time'], true);
                                    $minuteLabel = member_match_event_minute($event);
                                    $eventTitle = member_match_event_title($event, (string) $fixture['opponent']);
                                    $eventDetail = member_match_event_detail($event);
                                ?>
                                <?php if ($isStageEvent): ?>
                                    <div class="member-event-row member-event-row--stage">
                                        <strong><?= h($eventType === 'half_time' ? 'HT' : 'FT') ?></strong>
                                    </div>
                                <?php else: ?>
                                    <div class="member-event-row member-event-row--<?= h($eventType) ?>">
                                        <div class="member-event-row__team member-event-row__team--svfc">
                                            <?php if ($eventSide === 'svfc'): ?>
                                                <div class="member-event-row__copy">
                                                    <div class="member-event-row__title"><?= h($eventTitle) ?></div>
                                                    <?php if ($eventDetail !== ''): ?><div class="member-event-row__detail"><?= h($eventDetail) ?></div><?php endif; ?>
                                                </div>
                                                <span class="member-event-row__icon"><i class="fa-solid <?= h(member_match_event_icon($event)) ?>" aria-hidden="true"></i></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="member-event-row__minute"><?= h($minuteLabel ?: '-') ?></div>
                                        <div class="member-event-row__team member-event-row__team--opponent">
                                            <?php if ($eventSide === 'opponent'): ?>
                                                <span class="member-event-row__icon"><i class="fa-solid <?= h(member_match_event_icon($event)) ?>" aria-hidden="true"></i></span>
                                                <div class="member-event-row__copy">
                                                    <div class="member-event-row__title"><?= h($eventTitle) ?></div>
                                                    <?php if ($eventDetail !== ''): ?><div class="member-event-row__detail"><?= h($eventDetail) ?></div><?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($detail['starters']): ?>
                <section class="member-card">
                    <div class="member-card__header"><h2>Line-up</h2></div>
                    <div class="member-card__body">
                        <?php $captain = trim((string) ($fixture['starting11_captain'] ?? '')); ?>
                        <div class="member-lineup">
                            <div class="member-lineup__panel member-lineup__panel--starters">
                                <div class="member-lineup__title">Starting XI</div>
                                <ol class="member-lineup__list">
                                    <?php foreach ($detail['starters'] as $index => $player): ?>
                                        <?php $playerEvents = member_match_player_events($player, $detail['events']); ?>
                                        <li class="member-lineup__player">
                                            <span class="member-lineup__number"><?= h((string) member_match_player_number($squadNumbers, $player, $index + 1)) ?></span>
                                            <span class="member-lineup__name"><?= h($player) ?></span>
                                            <?php if ($captain !== '' && strcasecmp($captain, (string) $player) === 0): ?><span class="member-lineup__captain">C</span><?php endif; ?>
                                            <?php if ($playerEvents): ?>
                                                <span class="member-lineup__events">
                                                    <?php foreach ($playerEvents as $item): ?>
                                                        <span class="member-lineup__event member-lineup__event--<?= h((string) $item['type']) ?>" title="<?= h((string) $item['title']) ?>">
                                                            <i class="fa-solid <?= h(member_match_player_event_icon((string) $item['type'])) ?>" aria-hidden="true"></i><?= h((string) $item['label']) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </span>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                            <div class="member-lineup__panel">
                                <div class="member-lineup__title">Substitutes</div>
                                <?php if ($detail['substitutes']): ?>
                                    <ol class="member-lineup__list">
                                        <?php foreach ($detail['substitutes'] as $index => $player): ?>
                                            <?php $playerEvents = member_match_player_events($player, $detail['events']); ?>
                                            <li class="member-lineup__player">
                                                <span class="member-lineup__number"><?= h((string) member_match_player_number($squadNumbers, $player, $index + 12)) ?></span>
                                                <span class="member-lineup__name"><?= h($player) ?></span>
                                                <?php if ($captain !== '' && strcasecmp($captain, (string) $player) === 0): ?><span class="member-lineup__captain">C</span><?php endif; ?>
                                                <span class="member-lineup__events">
                                                    <?php if ($playerEvents): ?>
                                                        <?php foreach ($playerEvents as $item): ?>
                                                            <span class="member-lineup__event member-lineup__event--<?= h((string) $item['type']) ?>" title="<?= h((string) $item['title']) ?>">
                                                                <i class="fa-solid <?= h(member_match_player_event_icon((string) $item['type'])) ?>" aria-hidden="true"></i><?= h((string) $item['label']) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <span class="member-lineup__event member-lineup__event--unused">Unused</span>
                                                    <?php endif; ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php else: ?>
                                    <p class="member-lineup__empty">No substitutes named.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($veoUrl !== '' || $matchPhotos): ?>
                <section class="member-card">
                    <div class="member-card__header"><h2>Match Media</h2></div>
                    <div class="member-card__body">
                        <?php if ($veoUrl !== ''): ?>
                            <a href="<?= h($veoUrl) ?>" target="_blank" rel="noopener" class="member-veo-card">
                                <span class="member-veo-card__play"><i class="fa-solid fa-play" aria-hidden="true"></i></span>
                                <span>
                                    <strong>Watch the full match on VEO</strong>
                                    <span class="d-block text-muted small">Opens in a new tab — VEO doesn't allow its player to be embedded on other sites.</span>
                                </span>
                            </a>
                        <?php endif; ?>
                        <?php if ($matchPhotos): ?>
                            <?php if ($veoUrl !== ''): ?><hr><?php endif; ?>
                            <div class="member-photo-carousel" data-member-photo-carousel>
                                <div class="member-photo-carousel__top">
                                    <h3 class="h6 text-muted text-uppercase small mb-0">Photos (<?= count($matchPhotos) ?>)</h3>
                                    <?php if (count($matchPhotos) > 1): ?>
                                        <div class="member-photo-carousel__controls" aria-label="Photo carousel controls">
                                            <button type="button" class="member-photo-carousel__control" data-photo-scroll="prev" aria-label="Scroll photos left"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                                            <button type="button" class="member-photo-carousel__control" data-photo-scroll="next" aria-label="Scroll photos right"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="member-photo-carousel__track" data-photo-track>
                                    <?php foreach ($matchPhotoItems as $index => $photo): ?>
                                        <button type="button" class="member-photo-carousel__item" data-photo-index="<?= (int) $index ?>" aria-label="Open <?= h(strtolower($photo['label'])) ?>">
                                            <img src="<?= h($photo['url']) ?>" alt="<?= h($photo['label']) ?>" loading="lazy">
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

        </div>

        <aside class="member-list">
            <?php if ($isPlayed && $motmCandidates): ?>
                <section class="member-card">
                    <div class="member-card__header"><h2>Player of the Match</h2></div>
                    <div class="member-card__body">
                        <?php if ($motmError !== ''): ?><div class="alert alert-danger py-2"><?= h($motmError) ?></div><?php endif; ?>
                        <?php if (member_auth_is_authenticated()): ?>
                        <form method="post" class="row g-2 align-items-end mb-3">
                            <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
                            <input type="hidden" name="form_action" value="motm_vote">
                            <div class="col-sm-8">
                                <label class="form-label">Who was your Player of the Match?</label>
                                <select class="form-select" name="player_name">
                                    <?php foreach ($motmCandidates as $name): ?>
                                        <option value="<?= h($name) ?>" <?= $myMotmVote === $name ? 'selected' : '' ?>><?= h($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-4"><button type="submit" class="btn btn-brand w-100"><?= $myMotmVote ? 'Change Vote' : 'Vote' ?></button></div>
                        </form>
                        <?php else: ?>
                        <p class="text-muted small mb-3"><a href="/members/login.php">Log in</a> or <a href="/members/register.php">create a free account</a> to cast your vote.</p>
                        <?php endif; ?>
                        <?php if ($motmResults): ?>
                            <?php $topVotes = (int) $motmResults[0]['votes']; ?>
                            <h3 class="h6 text-muted text-uppercase small">Results so far</h3>
                            <?php foreach ($motmResults as $result): ?>
                                <div class="d-flex justify-content-between align-items-center border-bottom py-1">
                                    <span><?= h((string) $result['player_name']) ?><?= (int) $result['votes'] === $topVotes ? ' - leading' : '' ?></span>
                                    <span class="badge text-bg-light"><?= (int) $result['votes'] ?> vote<?= (int) $result['votes'] === 1 ? '' : 's' ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="member-card">
                <div class="member-card__header"><h2>Venue</h2><span class="member-badge"><?= $ratingSummary['count'] ?> review<?= $ratingSummary['count'] === 1 ? '' : 's' ?></span></div>
                <div class="member-card__body">
                    <h3 class="h6 fw-bold mb-1"><?= h($venueName) ?></h3>
                    <?php if ($addressParts): ?><p class="text-muted mb-2"><?= h(implode(', ', $addressParts)) ?></p><?php endif; ?>
                    <?php if ($venueInfo && !empty($venueInfo['notes'])): ?><p class="text-muted mb-2"><?= h((string) $venueInfo['notes']) ?></p><?php endif; ?>
                    <?php if ($ratingSummary['count'] > 0): ?>
                        <div class="fw-bold mb-2"><?= str_repeat('★', (int) round($ratingSummary['average'])) . str_repeat('☆', 5 - (int) round($ratingSummary['average'])) ?> <span class="text-muted fw-normal"><?= $ratingSummary['average'] ?> / 5</span></div>
                    <?php else: ?>
                        <p class="text-muted mb-2">No venue reviews yet.</p>
                    <?php endif; ?>
                    <?php if ($mapQuery !== ''): ?>
                        <iframe title="Venue map" loading="lazy" style="width:100%;min-height:180px;border:0;border-radius:12px;" src="https://www.google.com/maps?q=<?= rawurlencode($mapQuery) ?>&output=embed"></iframe>
                    <?php else: ?>
                        <div class="text-muted">A venue map is not available for this fixture.</div>
                    <?php endif; ?>

                    <?php if ($isPlayed): ?>
                        <hr>
                        <?php if (member_auth_is_authenticated()): ?>
                        <h3 class="h6"><?= $myReview ? 'Update your review' : 'Rate the facilities and matchday experience' ?></h3>
                        <?php if ($reviewError !== ''): ?><div class="alert alert-danger py-2"><?= h($reviewError) ?></div><?php endif; ?>
                        <?php if ($myReview && !isset($_POST['form_action'])): ?>
                            <p class="mb-2"><?= str_repeat('★', (int) $myReview['rating']) . str_repeat('☆', 5 - (int) $myReview['rating']) ?><?php if ($myReview['comment']): ?> - <?= h((string) $myReview['comment']) ?><?php endif; ?></p>
                        <?php endif; ?>
                        <form method="post" class="venue-review-form">
                            <input type="hidden" name="csrf_token" value="<?= h(member_auth_csrf_token()) ?>">
                            <input type="hidden" name="form_action" value="venue_review">
                            <input type="hidden" name="rating" class="venue-review-rating" value="<?= (int) ($myReview['rating'] ?? 0) ?>">
                            <div class="venue-review-stars mb-2">
                                <?php for ($i = 1; $i <= 5; $i++): ?><button type="button" class="feedback-star venue-star <?= (int) ($myReview['rating'] ?? 0) >= $i ? 'is-filled' : '' ?>" data-value="<?= $i ?>" style="color:<?= (int) ($myReview['rating'] ?? 0) >= $i ? '#ffc107' : '#ccc' ?>;font-size:1.6rem;background:none;border:none;">&#9733;</button><?php endfor; ?>
                            </div>
                            <div class="venue-review-options" aria-label="Review options">
                                <?php foreach (['Parking', 'Toilets', 'Food & drink', 'Covered area', 'Accessibility', 'Atmosphere', 'Stewarding', 'Value'] as $option): ?>
                                    <label class="venue-review-option"><input type="checkbox" name="review_options[]" value="<?= h($option) ?>"> <?= h($option) ?></label>
                                <?php endforeach; ?>
                            </div>
                            <textarea class="form-control mb-2" name="comment" rows="3" placeholder="Anything else about the facilities or matchday experience?"><?= h((string) ($myReview['comment'] ?? '')) ?></textarea>
                            <button type="submit" class="btn btn-sm btn-brand">Submit Review</button>
                        </form>
                        <script>(() => {
                            const form = document.querySelector('.venue-review-form');
                            if (!form) return;
                            const ratingInput = form.querySelector('.venue-review-rating');
                            const stars = form.querySelectorAll('.venue-star');
                            stars.forEach((star) => star.addEventListener('click', () => {
                                ratingInput.value = star.dataset.value;
                                stars.forEach((s) => { s.style.color = Number(s.dataset.value) <= Number(star.dataset.value) ? '#ffc107' : '#ccc'; });
                            }));
                        })();</script>
                        <?php else: ?>
                        <h3 class="h6">Rate the facilities and matchday experience</h3>
                        <p class="text-muted small mb-0"><a href="/members/login.php">Log in</a> or <a href="/members/register.php">create a free account</a> to leave a review.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-muted small mb-0 mt-3">Venue reviews open once the match has been played.</p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="member-card">
                <div class="member-card__header"><h2>Head to Head</h2></div>
                <div class="member-card__body">
                    <?php if (!$h2hMatches): ?>
                        <p class="text-muted mb-0">No recent head-to-head results are recorded yet.</p>
                    <?php else: ?>
                        <div class="member-grid member-grid--3 mb-3">
                            <div class="member-stat"><span>Wins</span><strong><?= (int) $h2hRecord['wins'] ?></strong></div>
                            <div class="member-stat"><span>Draws</span><strong><?= (int) $h2hRecord['draws'] ?></strong></div>
                            <div class="member-stat"><span>Losses</span><strong><?= (int) $h2hRecord['losses'] ?></strong></div>
                        </div>
                        <div class="member-list">
                            <?php foreach ($h2hMatches as $match): ?>
                                <a class="member-row" href="match.php?id=<?= (int) $match['id'] ?>">
                                    <div>
                                        <div class="member-row__title"><?= h(member_format_date((string) $match['match_date'])) ?></div>
                                        <div class="member-row__meta"><?= (int) $match['full_time_home_score'] ?> - <?= (int) $match['full_time_away_score'] ?></div>
                                    </div>
                                    <span class="member-badge"><?= h(member_match_team_result($match)) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if (!$isPlayed): ?>
                <section class="member-card">
                    <div class="member-card__header"><h2>League Positions</h2><a class="small fw-semibold" href="table.php">Full table</a></div>
                    <div class="member-card__body">
                        <?php if (!$saltcoatsRow && !$opponentRow): ?>
                            <p class="text-muted mb-0">League table data is not available right now.</p>
                        <?php else: ?>
                            <div class="member-list">
                                <?php foreach ([$saltcoatsRow, $opponentRow] as $row): if (!$row) continue; ?>
                                    <div class="member-row">
                                        <div>
                                            <div class="member-row__title"><?= h((string) $row['club']) ?></div>
                                            <div class="member-row__meta">Played <?= (int) $row['p'] ?> · Points <?= (int) $row['pts'] ?></div>
                                        </div>
                                        <span class="member-badge">#<?= (int) $row['pos'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>
        </aside>
    </div>
</div>

<?php if ($matchPhotoItems): ?>
<div class="modal fade member-lightbox" id="memberPhotoLightbox" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="member-lightbox__stage">
                <button type="button" class="member-lightbox__close" data-bs-dismiss="modal" aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                <?php if (count($matchPhotoItems) > 1): ?>
                    <button type="button" class="member-lightbox__nav member-lightbox__nav--prev" data-lightbox-nav="prev" aria-label="Previous photo"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
                <?php endif; ?>
                <img class="member-lightbox__image" src="<?= h($matchPhotoItems[0]['url']) ?>" alt="<?= h($matchPhotoItems[0]['label']) ?>" data-lightbox-image>
                <?php if (count($matchPhotoItems) > 1): ?>
                    <button type="button" class="member-lightbox__nav member-lightbox__nav--next" data-lightbox-nav="next" aria-label="Next photo"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const photos = <?= json_encode($matchPhotoItems, JSON_UNESCAPED_SLASHES) ?>;
    const carousel = document.querySelector('[data-member-photo-carousel]');
    const track = carousel?.querySelector('[data-photo-track]');
    const modalEl = document.getElementById('memberPhotoLightbox');
    const image = modalEl?.querySelector('[data-lightbox-image]');
    let activeIndex = 0;

    if (!photos.length || !carousel || !track || !modalEl || !image) {
        return;
    }

    const modal = window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modalEl) : null;

    const showPhoto = (index) => {
        activeIndex = (index + photos.length) % photos.length;
        const photo = photos[activeIndex];
        image.src = photo.url;
        image.alt = photo.label;
    };

    carousel.querySelectorAll('[data-photo-scroll]').forEach((button) => {
        button.addEventListener('click', () => {
            const direction = button.dataset.photoScroll === 'prev' ? -1 : 1;
            track.scrollBy({ left: direction * Math.max(220, track.clientWidth * 0.82), behavior: 'smooth' });
        });
    });

    carousel.querySelectorAll('[data-photo-index]').forEach((button) => {
        button.addEventListener('click', () => {
            showPhoto(Number(button.dataset.photoIndex || 0));
            if (modal) modal.show();
        });
    });

    modalEl.querySelectorAll('[data-lightbox-nav]').forEach((button) => {
        button.addEventListener('click', () => {
            showPhoto(activeIndex + (button.dataset.lightboxNav === 'prev' ? -1 : 1));
        });
    });

    document.addEventListener('keydown', (event) => {
        if (!modalEl.classList.contains('show')) return;
        if (event.key === 'ArrowLeft') showPhoto(activeIndex - 1);
        if (event.key === 'ArrowRight') showPhoto(activeIndex + 1);
    });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
