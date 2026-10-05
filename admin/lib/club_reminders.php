<?php
declare(strict_types=1);

require_once __DIR__ . '/secretary_tasks.php';
require_once __DIR__ . '/facility_maintenance.php';
require_once __DIR__ . '/sponsor_followups.php';
require_once __DIR__ . '/matchday_staffing.php';
require_once __DIR__ . '/player_availability.php';
require_once __DIR__ . '/pos.php';
require_once __DIR__ . '/pos_reconciliation.php';

function club_reminder_tone(string $severity): string
{
    return match ($severity) {
        'urgent' => 'danger',
        'warning' => 'warning',
        default => 'info',
    };
}

function club_reminder_priority_rank(string $severity): int
{
    return match ($severity) {
        'urgent' => 0,
        'warning' => 1,
        default => 2,
    };
}

/**
 * @param list<array<string,mixed>> $reminders
 * @return array{total:int,urgent:int,warning:int,info:int}
 */
function club_reminder_summary(array $reminders): array
{
    $summary = ['total' => count($reminders), 'urgent' => 0, 'warning' => 0, 'info' => 0];
    foreach ($reminders as $reminder) {
        $severity = (string) ($reminder['severity'] ?? 'info');
        if (!isset($summary[$severity])) {
            $severity = 'info';
        }
        $summary[$severity]++;
    }

    return $summary;
}

/**
 * @param list<array<string,mixed>> $reminders
 * @return list<array<string,mixed>>
 */
function club_reminders_sort(array $reminders): array
{
    usort($reminders, static function (array $a, array $b): int {
        $severity = club_reminder_priority_rank((string) ($a['severity'] ?? 'info')) <=> club_reminder_priority_rank((string) ($b['severity'] ?? 'info'));
        if ($severity !== 0) {
            return $severity;
        }
        $aDate = (string) ($a['due_at'] ?? '9999-12-31');
        $bDate = (string) ($b['due_at'] ?? '9999-12-31');
        return strcmp($aDate, $bDate) ?: strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });

    return $reminders;
}

/**
 * @return list<array<string,mixed>>
 */
function club_reminders_collect(PDO $pdo, int $withinDays = 14): array
{
    $today = date('Y-m-d');
    $reminders = [];

    foreach (secretary_task_upcoming($pdo, $withinDays) as $task) {
        $dueAt = (string) ($task['due_at'] ?? '');
        $reminders[] = [
            'area' => 'Secretary',
            'title' => (string) $task['title'],
            'detail' => ($task['owner'] ? 'Owner: ' . (string) $task['owner'] . '. ' : '') . 'Due ' . $dueAt,
            'due_at' => $dueAt,
            'severity' => $dueAt !== '' && $dueAt < $today ? 'urgent' : ((string) ($task['priority'] ?? 'normal') === 'urgent' ? 'urgent' : 'warning'),
            'href' => 'secretary_task_edit.php?id=' . (int) $task['id'],
            'icon' => 'fa-list-check',
        ];
    }

    foreach (facility_maintenance_list($pdo, 'open') as $job) {
        $dueAt = (string) ($job['due_at'] ?? '');
        if ($dueAt === '' || $dueAt > date('Y-m-d', strtotime('+' . max(0, $withinDays) . ' days'))) {
            continue;
        }
        $reminders[] = [
            'area' => 'Facilities',
            'title' => (string) $job['title'],
            'detail' => (($job['area'] ?? '') !== '' ? (string) $job['area'] . '. ' : '') . 'Due ' . $dueAt,
            'due_at' => $dueAt,
            'severity' => facility_maintenance_is_overdue($job, $today) ? 'urgent' : ((string) ($job['priority'] ?? 'normal') === 'urgent' ? 'urgent' : 'warning'),
            'href' => 'facilities.php',
            'icon' => 'fa-screwdriver-wrench',
        ];
    }

    foreach (sponsor_followup_list($pdo, 'open') as $followup) {
        $nextContact = (string) ($followup['next_contact_at'] ?? '');
        if ($nextContact === '' || $nextContact > date('Y-m-d', strtotime('+' . max(0, $withinDays) . ' days'))) {
            continue;
        }
        $reminders[] = [
            'area' => 'Sponsorship',
            'title' => (string) $followup['title'],
            'detail' => (string) $followup['sponsor_name'] . ' · next contact ' . $nextContact,
            'due_at' => $nextContact,
            'severity' => sponsor_followup_is_overdue($followup, $today) ? 'urgent' : ((string) ($followup['priority'] ?? 'normal') === 'urgent' ? 'urgent' : 'warning'),
            'href' => 'sponsor_followups.php',
            'icon' => 'fa-phone-volume',
        ];
    }

    pos_ensure_schema($pdo);
    foreach (pos_reconciliation_recent($pdo, $today, 50) as $cashup) {
        $variance = (float) ($cashup['variance_amount'] ?? 0);
        if (abs($variance) < 0.005) {
            continue;
        }
        $reminders[] = [
            'area' => 'POS',
            'title' => 'Cash-up variance at ' . (string) $cashup['location_name'],
            'detail' => pos_reconciliation_payment_label((string) $cashup['payment_method']) . ' variance ' . gbp($variance),
            'due_at' => $today,
            'severity' => 'urgent',
            'href' => 'pos/reports.php?date=' . $today,
            'icon' => 'fa-cash-register',
        ];
    }

    $fixtureStmt = $pdo->query("SELECT id, opponent, match_date
        FROM match_fixtures
        WHERE match_date >= CURDATE()
          AND match_date <= DATE_ADD(CURDATE(), INTERVAL " . max(0, $withinDays) . " DAY)
          AND status IN ('scheduled','postponed')
        ORDER BY match_date ASC, kickoff_time ASC, id ASC
        LIMIT 10");
    $fixtures = $fixtureStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $players = $pdo->query("SELECT id, name FROM players WHERE status IN ('current', 'trialist') AND active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($fixtures as $fixture) {
        $fixtureId = (int) $fixture['id'];
        $fixtureTitle = 'vs ' . (string) $fixture['opponent'];
        $assignments = matchday_staffing_assignments($pdo, $fixtureId);
        $staffingSummary = matchday_staffing_summary($assignments);
        if ((int) $staffingSummary['active'] === 0) {
            $reminders[] = [
                'area' => 'Matchday',
                'title' => 'No staffing planned for ' . $fixtureTitle,
                'detail' => 'Fixture date ' . (string) $fixture['match_date'],
                'due_at' => (string) $fixture['match_date'],
                'severity' => 'warning',
                'href' => 'match.php?id=' . $fixtureId,
                'icon' => 'fa-users-gear',
            ];
        } elseif ((int) $staffingSummary['confirmed'] < (int) $staffingSummary['active']) {
            $reminders[] = [
                'area' => 'Matchday',
                'title' => 'Staffing confirmations incomplete for ' . $fixtureTitle,
                'detail' => (int) $staffingSummary['confirmed'] . ' of ' . (int) $staffingSummary['active'] . ' confirmed',
                'due_at' => (string) $fixture['match_date'],
                'severity' => 'info',
                'href' => 'match.php?id=' . $fixtureId,
                'icon' => 'fa-users-gear',
            ];
        }

        if ($players !== []) {
            $availability = player_availability_merge_players($players, player_availability_by_fixture($pdo, $fixtureId));
            $availabilitySummary = player_availability_summary($availability);
            if ((int) $availabilitySummary['unknown'] > 0) {
                $reminders[] = [
                    'area' => 'Football',
                    'title' => 'Player availability still unknown for ' . $fixtureTitle,
                    'detail' => (int) $availabilitySummary['unknown'] . ' of ' . (int) $availabilitySummary['total'] . ' players need an availability status',
                    'due_at' => (string) $fixture['match_date'],
                    'severity' => (int) $availabilitySummary['unknown'] === (int) $availabilitySummary['total'] ? 'warning' : 'info',
                    'href' => 'fixture_starting_11.php?fixture_id=' . $fixtureId,
                    'icon' => 'fa-user-check',
                ];
            }
        }
    }

    return club_reminders_sort($reminders);
}
