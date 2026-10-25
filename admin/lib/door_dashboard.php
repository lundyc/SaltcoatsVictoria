<?php
declare(strict_types=1);

/**
 * Live numbers for the multi-gate door dashboard: how many are in, how fast, which gates are working and which
 * phones have gone quiet or are holding scans they could not send. Reads the scan feed written by
 * scan_process_ticket() and the heartbeats the scanner phones send every 20 seconds.
 */

require_once __DIR__ . '/scan_processor.php';

/** Statuses that are a ticket for somewhere else, not a problem at this gate. */
const DOOR_NEUTRAL_STATUSES = ['wrong_fixture', 'wrong_event', 'wrong_ticket_type'];
/** A phone that has not reported for this long is shown as silent. */
const DOOR_SILENT_AFTER_SECONDS = 90;

/** Today's matches and events a door dashboard can show. @return list<array{kind:string,id:int,title:string,sub:string}> */
function door_targets(PDO $pdo): array
{
    ensureScanGateSchema($pdo);
    $out = [];
    $today = scan_business_date();
    $st = $pdo->prepare("SELECT id, opponent, kickoff_time FROM match_fixtures WHERE is_home = 1 AND match_date = :d AND status NOT IN ('cancelled', 'postponed') ORDER BY kickoff_time, id");
    $st->execute([':d' => $today]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $out[] = ['kind' => 'match', 'id' => (int) $f['id'], 'title' => 'vs ' . $f['opponent'], 'sub' => $f['kickoff_time'] ? 'KO ' . substr((string) $f['kickoff_time'], 0, 5) : 'Match'];
    }
    foreach (events_scannable_today($pdo) as $e) {
        $out[] = ['kind' => 'event', 'id' => (int) $e['id'], 'title' => (string) $e['title'], 'sub' => 'Event · ' . date('H:i', strtotime((string) $e['starts_at']))];
    }
    return $out;
}

/**
 * @return array<string,mixed>
 */
function door_dashboard_data(PDO $pdo, string $kind, int $id): array
{
    ensureScanGateSchema($pdo);
    $kind = $kind === 'event' ? 'event' : 'match';
    $q = static function (string $sql, array $p) use ($pdo) {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st;
    };
    $base = ['k' => $kind, 'id' => $id];
    $neutral = "'" . implode("','", DOOR_NEUTRAL_STATUSES) . "'";

    if ($kind === 'event') {
        $event = events_get($pdo, $id);
        if (!$event) {
            throw new RuntimeException('Event not found.');
        }
        $title = (string) $event['title'];
        $admitted = events_admitted_count($pdo, $id);
        $capacity = events_capacity($event);
        $expected = (int) $q("SELECT COALESCE(SUM(t.admits_count), 0) FROM event_tickets t JOIN entitlements e ON e.id = t.entitlement_id JOIN orders o ON o.id = t.order_id
            WHERE t.event_id = :e AND e.status = 'active' AND o.status IN ('paid', 'complete', 'partially_refunded')", [':e' => $id])->fetchColumn();
        $startsAt = (string) $event['starts_at'];
    } else {
        $fx = $q('SELECT id, opponent, match_date, kickoff_time, season_id FROM match_fixtures WHERE id = :id', [':id' => $id])->fetch(PDO::FETCH_ASSOC);
        if (!$fx) {
            throw new RuntimeException('Match not found.');
        }
        $title = 'vs ' . $fx['opponent'];
        $admitted = admissionsCount($pdo, $id);
        $capacity = 0;
        // Context only: match tickets sold for this game and active season tickets this season.
        $expected = (int) $q("SELECT COUNT(*) FROM match_tickets mt JOIN entitlements e ON e.id = mt.entitlement_id JOIN order_items oi ON oi.id = e.order_item_id JOIN orders o ON o.id = oi.order_id
            WHERE mt.fixture_id = :f AND e.status = 'active' AND o.status = 'paid'", [':f' => $id])->fetchColumn();
        try {
            $expected += (int) $q("SELECT COUNT(*) FROM season_passes sp JOIN entitlements e ON e.id = sp.entitlement_id WHERE sp.season_id = :s AND sp.status = 'active' AND e.status = 'active'", [':s' => (int) $fx['season_id']])->fetchColumn();
        } catch (Throwable) {
        }
        $startsAt = $fx['match_date'] . ' ' . ($fx['kickoff_time'] ?: '15:00:00');
    }

    $where = 'target_kind = :k AND target_id = :id';
    $gates = $q("SELECT COALESCE(NULLIF(gate, ''), '(no gate name)') AS gate, COUNT(DISTINCT device_id) AS devices, COALESCE(SUM(accepted), 0) AS admitted,
            COALESCE(SUM(accepted = 0 AND status NOT IN ($neutral)), 0) AS refused, COALESCE(SUM(offline), 0) AS offline_scans, MAX(scanned_at) AS last_scan
        FROM scan_gate_activity WHERE $where GROUP BY COALESCE(NULLIF(gate, ''), '(no gate name)') ORDER BY admitted DESC", $base)->fetchAll(PDO::FETCH_ASSOC);
    $last10 = (int) $q("SELECT COUNT(*) FROM scan_gate_activity WHERE $where AND accepted = 1 AND scanned_at > (NOW() - INTERVAL 10 MINUTE)", $base)->fetchColumn();
    $buckets = array_fill(0, 12, 0);
    foreach ($q("SELECT FLOOR(TIMESTAMPDIFF(MINUTE, scanned_at, NOW()) / 5) AS b, COUNT(*) AS n FROM scan_gate_activity WHERE $where AND accepted = 1 AND scanned_at > (NOW() - INTERVAL 60 MINUTE) GROUP BY b", $base)->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $i = (int) $b['b'];
        if ($i >= 0 && $i < 12) {
            $buckets[11 - $i] = (int) $b['n'];
        }
    }
    $recent = $q("SELECT scanned_at, COALESCE(NULLIF(gate, ''), '(no gate name)') AS gate, status, accepted, holder_name, ticket_label, offline FROM scan_gate_activity WHERE $where ORDER BY scanned_at DESC, id DESC LIMIT 25", $base)->fetchAll(PDO::FETCH_ASSOC);
    $devices = $q("SELECT device_id, COALESCE(NULLIF(gate, ''), '(no gate name)') AS gate, queued, online, last_seen, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS ago
        FROM scan_gate_heartbeats WHERE target_kind = :k AND target_id = :id AND last_seen > (NOW() - INTERVAL 6 HOUR) ORDER BY gate, last_seen DESC", $base)->fetchAll(PDO::FETCH_ASSOC);
    $dupes = (int) $q("SELECT COUNT(*) FROM scan_gate_activity WHERE $where AND offline = 1 AND status = 'already_scanned' AND scanned_at > (NOW() - INTERVAL 6 HOUR)", $base)->fetchColumn();
    $refusedTotal = (int) $q("SELECT COUNT(*) FROM scan_gate_activity WHERE $where AND accepted = 0 AND status NOT IN ($neutral) AND status <> 'already_scanned'", $base)->fetchColumn();

    $scansTotal = (int) $q("SELECT COUNT(*) FROM scan_gate_activity WHERE $where AND status NOT IN ($neutral)", $base)->fetchColumn();
    $scansValid = (int) $q("SELECT COUNT(*) FROM scan_gate_activity WHERE $where AND accepted = 1", $base)->fetchColumn();

    $alerts = [];
    foreach ($devices as $d) {
        $ago = (int) $d['ago'];
        if ($ago > DOOR_SILENT_AFTER_SECONDS) {
            $alerts[] = ['level' => 'danger', 'text' => $d['gate'] . ' has not reported for ' . ($ago >= 120 ? intdiv($ago, 60) . ' minutes' : $ago . ' seconds') . ($d['queued'] > 0 ? ' and is holding ' . (int) $d['queued'] . ' unsent scan' . ((int) $d['queued'] === 1 ? '' : 's') : '') . '. The phone may be offline or switched off.'];
        } elseif ((int) $d['queued'] > 0) {
            $alerts[] = ['level' => 'warning', 'text' => $d['gate'] . ' is sending ' . (int) $d['queued'] . ' saved scan' . ((int) $d['queued'] === 1 ? '' : 's') . ' from when it had no signal.'];
        }
    }
    if ($dupes > 0) {
        $alerts[] = ['level' => 'warning', 'text' => $dupes . ' ticket' . ($dupes === 1 ? ' was' : 's were') . ' scanned at a gate that was offline after already being admitted elsewhere. Check the scan problems list.'];
    }

    return [
        'ok' => true,
        'target' => ['kind' => $kind, 'id' => $id, 'title' => $title, 'starts_at' => $startsAt],
        'admitted' => $admitted, 'expected' => $expected, 'capacity' => $capacity,
        'scans_total' => $scansTotal, 'scans_valid' => $scansValid,
        'rate10' => $last10, 'per_minute' => round($last10 / 10, 1), 'buckets' => $buckets, 'refused' => $refusedTotal,
        'gates' => array_map(static fn (array $g): array => [
            'gate' => $g['gate'], 'devices' => (int) $g['devices'], 'admitted' => (int) $g['admitted'], 'refused' => (int) $g['refused'], 'offline_scans' => (int) $g['offline_scans'], 'last_scan' => $g['last_scan'],
        ], $gates),
        'devices' => array_map(static fn (array $d): array => [
            'gate' => $d['gate'], 'queued' => (int) $d['queued'], 'ago' => (int) $d['ago'], 'online' => (int) $d['online'] === 1, 'silent' => (int) $d['ago'] > DOOR_SILENT_AFTER_SECONDS,
        ], $devices),
        'recent' => array_map(static fn (array $r): array => [
            'at' => substr((string) $r['scanned_at'], 11, 8), 'gate' => $r['gate'], 'status' => $r['status'], 'accepted' => (int) $r['accepted'] === 1, 'name' => (string) $r['holder_name'], 'label' => (string) $r['ticket_label'], 'offline' => (int) $r['offline'] === 1,
        ], $recent),
        'alerts' => $alerts,
        'server_time' => date('H:i:s'),
    ];
}
