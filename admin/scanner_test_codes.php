<?php
declare(strict_types=1);

/**
 * Printable sheet of QR codes for testing the gate scanner (/scan).
 * Shows live credentials for a tester's tickets plus codes that should be
 * rejected, each with the result the scanner is expected to give.
 * Staff only. Pick the tester with ?person_id= (defaults to the tester below).
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';

if (!hub_auth_is_authenticated()) {
    header('Location: /admin/login.php');
    exit;
}
hub_auth_require_capability('tickets_ops');

$personId = (int) ($_GET['person_id'] ?? 51);
$person = $pdo->prepare('SELECT display_name FROM people WHERE id = :id');
$person->execute([':id' => $personId]);
$personName = (string) ($person->fetchColumn() ?: 'Unknown person');

$stmt = $pdo->prepare("SELECT e.type, e.status AS entitlement_status, tc.token, tc.manual_code, tc.is_active,
        COALESCE(se.name, '') AS season_name, COALESCE(st.name, '') AS season_type, sp.season_ticket_type_id AS season_type_id,
        mt.fixture_id, mt.ticket_label, f.opponent, f.match_date, f.kickoff_time, f.is_home,
        ev.title AS event_title, ev.starts_at, et.ticket_label AS event_label
    FROM entitlements e
    JOIN ticket_credentials tc ON tc.entitlement_id = e.id
    LEFT JOIN season_passes sp ON sp.entitlement_id = e.id
    LEFT JOIN seasons se ON se.id = sp.season_id
    LEFT JOIN season_ticket_types st ON st.id = sp.season_ticket_type_id
    LEFT JOIN match_tickets mt ON mt.entitlement_id = e.id
    LEFT JOIN match_fixtures f ON f.id = mt.fixture_id
    LEFT JOIN event_tickets et ON et.entitlement_id = e.id
    LEFT JOIN events ev ON ev.id = et.event_id
    WHERE e.person_id = :p AND e.id > 385
    ORDER BY FIELD(e.type, 'season_pass', 'match_ticket', 'event_ticket'), e.id");
$stmt->execute([':p' => $personId]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// "The match being scanned": a fixture played today that this person holds an active ticket for;
// failing that, the fixture most of their active matchday tickets are for.
$todayDate = date('Y-m-d', time() - 5 * 3600);
$fixtureVotes = [];
$fixtureInfo = [];
foreach ($rows as $r) {
    if ($r['type'] === 'match_ticket' && (int) $r['is_active'] === 1 && (string) $r['entitlement_status'] === 'active') {
        $fid = (int) $r['fixture_id'];
        $fixtureVotes[$fid] = ($fixtureVotes[$fid] ?? 0) + 1 + ((string) $r['match_date'] === $todayDate ? 100 : 0);
        $fixtureInfo[$fid] = $r;
    }
}
arsort($fixtureVotes);
$mainFixture = (int) (array_key_first($fixtureVotes) ?? 0);
$mainLabel = $mainFixture ? 'Saltcoats v ' . ($fixtureInfo[$mainFixture]['opponent'] ?? 'the opponent') : 'the match';
$mainIsToday = $mainFixture && (string) ($fixtureInfo[$mainFixture]['match_date'] ?? '') === $todayDate;

$origin = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$sections = [
    'a' => ['Should be ACCEPTED', 'Valid tickets. Scan these at the right gate (' . $mainLabel . ' for matchday tickets, the test event for the event ticket).'],
    'b' => ['Valid ticket, WRONG match', 'Real, paid tickets that belong to a different game. They should be refused at the ' . $mainLabel . ' gate (and accepted only at their own match).'],
    'c' => ['Should be REJECTED: ticket not usable', 'These exist in the system but must not get anyone in.'],
    'd' => ['Should be REJECTED: not real tickets', 'Codes that are damaged, made up, or from somewhere else.'],
    'e' => ['Other formats', 'The same real tickets, presented differently.'],
];
$cards = [];
$add = static function (string $sec, string $title, string $sub, string $expect, string $qr, string $code, bool $ok) use (&$cards): void {
    $cards[$sec][] = ['title' => $title, 'sub' => $sub, 'expect' => $expect, 'qr' => $qr, 'code' => $code, 'ok' => $ok];
};
$firstOf = ['season' => null, 'season_link' => null, 'match' => null, 'event' => null];
foreach ($rows as $r) {
    $active = (int) $r['is_active'] === 1 && (string) $r['entitlement_status'] === 'active';
    $status = (string) $r['entitlement_status'];
    if ($r['type'] === 'season_pass') {
        $name = trim($r['season_type'] . ' season ticket') . ' · ' . $r['season_name'];
        if ($active && (int) $r['season_type_id'] === 2) {
            // Concession pass: used only for the link test, so it has its own first use.
            $firstOf['season_link'] ??= $r;
        } elseif ($active) {
            $add('a', trim($r['season_type'] . ' season ticket'), (string) $r['season_name'], 'Accepted at any home game this season.', $r['token'], $r['manual_code'], true);
            $firstOf['season'] ??= $r;
        } else {
            $label = $status === 'cancelled' ? 'Cancelled season ticket' : 'Unpaid season ticket';
            $add('c', $label, $name, $status === 'cancelled' ? 'Rejected: ticket cancelled.' : 'Rejected: payment not completed.', $r['token'], $r['manual_code'], false);
        }
    } elseif ($r['type'] === 'match_ticket') {
        $when = $r['match_date'] ? date('D j M Y', strtotime((string) $r['match_date'])) : '';
        $game = trim(($r['is_home'] ? 'Saltcoats v ' : '') . $r['opponent'] . ' · ' . $when);
        if (!$active) {
            $add('c', 'Cancelled matchday ticket', $r['ticket_label'] . ' · ' . $game, 'Rejected: ticket cancelled.', $r['token'], $r['manual_code'], false);
        } elseif ((int) $r['fixture_id'] === $mainFixture) {
            $note = $firstOf['match'] === null ? ' Then scan it a second time: the second scan should say it has already been used.' : '';
            $add('a', 'Matchday ticket: ' . $r['ticket_label'], $game, 'Accepted at this match.' . $note, $r['token'], $r['manual_code'], true);
            $firstOf['match'] ??= $r;
        } else {
            $add('b', 'Matchday ticket: ' . $r['ticket_label'], $game, 'Refused at the ' . $mainLabel . ' gate (wrong fixture).', $r['token'], $r['manual_code'], false);
        }
    } elseif ($r['type'] === 'event_ticket') {
        if ($active) {
            $add('a', 'Event ticket: ' . $r['event_label'], (string) $r['event_title'], 'Accepted when scanning this event.', $r['token'], $r['manual_code'], true);
            $firstOf['event'] ??= $r;
        } else {
            $add('c', 'Cancelled event ticket', (string) $r['event_title'], 'Rejected: ticket cancelled.', $r['token'], $r['manual_code'], false);
        }
    }
}
if ($firstOf['event']) {
    $add('b', 'Event ticket at a MATCH gate', (string) $firstOf['event']['event_title'], 'Refused when scanned in matchday mode (wrong kind of ticket).', $firstOf['event']['token'], $firstOf['event']['manual_code'], false);
}

$tok = (string) ($firstOf['season']['token'] ?? hash('sha256', 'x'));
$add('d', 'Unknown ticket', 'Well-formed code that matches nothing', 'Rejected: ticket not found.', substr(hash('sha256', 'scanner-test-unknown'), 0, 40), '', false);
$add('d', 'Not a ticket', 'Plain text QR', 'Rejected or unreadable, with no crash.', 'HELLO-NOT-A-TICKET', '', false);
$add('d', 'Wrong website', 'A link to somewhere else', 'Rejected: not a Saltcoats ticket.', 'https://example.com/?token=0000000000000000000000000000000000000000', '', false);
$add('d', 'Empty-looking code', 'Just spaces and a dash', 'Rejected, with no crash.', '   -   ', '', false);

foreach (['season_link' => 'Season ticket'] as $k => $label) {
    if ($firstOf[$k]) {
        $add('e', $label . ' as a link', 'Token inside a web address', 'Accepted (the scanner pulls the code out of the link).', $origin . '/members/ticket.php?token=' . $firstOf[$k]['token'], $firstOf[$k]['manual_code'], true);
    }
}
if (false) {
    $c = (string) $firstOf['season']['manual_code'];
    $add('e', 'Manual code, typed', 'Do not scan: type it in', 'Type ' . strtolower(str_replace('-', '', $c)) . ' (lower case, no dash). It should still be accepted.', 'MANUAL-CODE-ENTRY-TEST', $c, true);
}
$h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$fingerprint = static fn(string $v): string => sha1(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $v)));

// Latest match-gate scan for each card, from scan_card_log (written by scan_process_ticket on every scan).
$lastScan = [];
if ($mainFixture) {
    try {
        $log = $pdo->prepare('SELECT fingerprint, status, accepted, message, scanned_at FROM scan_card_log WHERE fixture_id = :f ORDER BY id ASC');
        $log->execute([':f' => $mainFixture]);
        foreach ($log->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lastScan[$row['fingerprint']] ??= $row;
        }
    } catch (Throwable) {
        $lastScan = [];
    }
}
foreach ($cards as $sec => $list) {
    foreach ($list as $i => $c) {
        $isManual = $c['qr'] === 'MANUAL-CODE-ENTRY-TEST';
        $value = $isManual ? (string) $c['code'] : (string) $c['qr'];
        $cards[$sec][$i]['fp'] = $fingerprint($value);
        // Event tickets are only scanned at the event gate, so the match sheet does not tick them.
        $cards[$sec][$i]['match_gate'] = !str_starts_with((string) $c['title'], 'Event ticket');
    }
}
$testNo = 0;
?><!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><meta http-equiv="refresh" content="10"><title>Scanner test codes</title>
<style>
:root{--maroon:#6d2231;--gold:#e0b42a;--ink:#2e2c2d;--line:#d9d2c4}
*{box-sizing:border-box}body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:#faf7f1}
header{background:var(--maroon);color:#fff;padding:1rem 1.5rem;display:flex;gap:1rem;align-items:center;flex-wrap:wrap}
header h1{margin:0;font-size:1.3rem}header p{margin:0;opacity:.85;font-size:.9rem}
.btn{margin-left:auto;background:var(--gold);color:var(--ink);border:0;border-radius:999px;padding:.6rem 1.2rem;font-weight:700;cursor:pointer}
main{max-width:1180px;margin:0 auto;padding:1.5rem}
.sec{margin-top:3.5rem}
h2{margin:0 0 .3rem;font-size:1.15rem;text-transform:uppercase;letter-spacing:.05em;color:var(--maroon);border-bottom:3px solid var(--gold);padding-bottom:.5rem}
.intro{margin:.6rem 0 1.6rem;color:#6b6168;font-size:.92rem}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:2.4rem 2rem}
.card{position:relative;background:#fff;border:3px solid var(--line);border-radius:14px;padding:1.6rem 1.4rem 1.3rem;text-align:center;break-inside:avoid}
.card.ok{border-color:#1b8a4b}.card.no{border-color:#b23b3b}
.num{position:absolute;top:-.85rem;left:1rem;background:var(--ink);color:#fff;border-radius:999px;padding:.15rem .8rem;font-size:.78rem;font-weight:700;letter-spacing:.04em}
.qr{display:flex;justify-content:center;margin:.6rem 0 1rem;min-height:230px;align-items:center}
.qr img,.qr canvas{width:230px!important;height:230px!important}
.nocode{border:3px dashed var(--line);border-radius:10px;font-weight:700;color:#6b6168;font-size:1.1rem;line-height:1.5}
.card h3{margin:.3rem 0 .2rem;font-size:1.05rem}.sub{color:#6b6168;font-size:.88rem;margin-bottom:.7rem}
.expect{font-size:.86rem;background:#f4efe6;border-radius:8px;padding:.6rem .7rem;margin-top:.9rem;line-height:1.45}
.card.ok .expect{background:#e6f4ec}.card.no .expect{background:#fbe9e9}
.code{font:700 1.15rem ui-monospace,Menlo,Consolas,monospace;letter-spacing:.1em;margin-top:.5rem}
.tick{margin-top:1rem;font-size:1rem;color:#6b6168;font-weight:700}
.tick.pass{color:#1b8a4b}.tick.fail{color:#b23b3b}.tick.none{font-weight:600;font-size:.9rem}
.tick .got{font-weight:400;font-size:.82rem;margin-top:.3rem;color:#5b5258}.tick .got span{opacity:.75}
.note{font-size:.9rem;color:#6b6168}
@media print{header .btn,.noprint{display:none}body{background:#fff}header{background:#fff;color:#000;padding:.2rem 0}main{padding:0}
 .grid{grid-template-columns:repeat(2,1fr);gap:2rem}.sec{margin-top:1.5rem;break-inside:auto}.card{break-inside:avoid;page-break-inside:avoid}.qr img,.qr canvas{width:210px!important;height:210px!important}}
</style></head><body>
<header><div><h1>Scanner test codes</h1><p>For <?= $h($personName) ?> · generated <?= $h(date('j M Y H:i')) ?></p></div><button class="btn" onclick="window.print()">Print this page</button></header>
<main>
<p class="note noprint">Open <strong>/scan</strong> on a phone, choose <?= $h($mainLabel) ?><?= $mainIsToday ? ' (today\'s test match)' : ' (not played today, so the scanner will not list it)' ?> (or the test event), then scan each code on this screen or a printout. Compare the result with the note on the card and tick Pass or Fail.</p>
<?php foreach ($sections as $key => [$heading, $intro]):
    $open = array_filter($cards[$key] ?? [], static fn($c) => !(($lastScan[$c['fp']] ?? null) !== null && ((int) $lastScan[$c['fp']]['accepted'] === 1) === (bool) $c['ok']));
    if (!$open) continue; ?>
<section class="sec">
<h2><?= $h($heading) ?></h2><p class="intro"><?= $h($intro) ?></p>
<div class="grid">
<?php foreach ($cards[$key] as $c): $isManual = $c['qr'] === 'MANUAL-CODE-ENTRY-TEST'; $firstResult = $lastScan[$c['fp']] ?? null; if ($firstResult !== null && ((int) $firstResult['accepted'] === 1) === (bool) $c['ok']) { continue; } $testNo++; ?>
  <div class="card <?= $c['ok'] ? 'ok' : 'no' ?>">
    <div class="num">Test <?= $testNo ?></div>
    <?php if ($isManual): ?><div class="qr nocode">Type this code<br>into the scanner</div><?php else: ?><div class="qr" data-qr="<?= $h($c['qr']) ?>"></div><?php endif; ?>
    <h3><?= $h($c['title']) ?></h3><div class="sub"><?= $h($c['sub']) ?></div>
    <?php if ($c['code'] !== ''): ?><div class="code"><?= $h($c['code']) ?></div><?php endif; ?>
    <div class="expect"><?= $h($c['expect']) ?></div>
    <?php
      $res = $lastScan[$c['fp']] ?? null;
      if (empty($c['match_gate'])) {
          echo '<div class="tick none">Tested at the event gate, not here</div>';
      } elseif ($res === null) {
          echo '<div class="tick none">&#9744; Pass &nbsp; &#9744; Fail<div class="got">Not scanned yet</div></div>';
      } else {
          $passed = ((int) $res['accepted'] === 1) === (bool) $c['ok'];
          echo '<div class="tick ' . ($passed ? 'pass' : 'fail') . '">' . ($passed ? '&#9745; Pass &nbsp; &#9744; Fail' : '&#9744; Pass &nbsp; &#9745; Fail')
             . '<div class="got">Scanner said: ' . $h($res['message']) . ' <span>(' . $h(date('H:i:s', strtotime((string) $res['scanned_at']))) . ')</span></div></div>';
      }
    ?>
  </div>
<?php endforeach; ?>
</div></section>
<?php endforeach; ?>
</main>
<script src="/admin/assets/js/vendor/qrcode.min.js"></script>
<script>document.querySelectorAll('[data-qr]').forEach(function(el){new QRCode(el,{text:el.dataset.qr,width:230,height:230,correctLevel:QRCode.CorrectLevel.H});});</script>
</body></html>
