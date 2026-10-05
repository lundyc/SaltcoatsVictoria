<?php
declare(strict_types=1);

require_once __DIR__ . '/committee_meetings.php';
require_once __DIR__ . '/secretary_tasks.php';

/**
 * @return list<array{title: string, owner: string, due_at: string, priority: string}>
 */
function committee_meeting_extract_actions(string $minutes): array
{
    $actions = [];
    foreach (preg_split('/\R/', $minutes) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }

        $line = preg_replace('/^\s*(?:[-*]|\d+[.)])\s*/', '', $line) ?? $line;
        if (!preg_match('/^(?:\[\s*\]\s*)?(?:action|todo)\s*[:\-]\s*(.+)$/i', $line, $match)) {
            continue;
        }

        $rawTitle = trim((string) $match[1]);
        if ($rawTitle === '') {
            continue;
        }

        $owner = committee_meeting_extract_action_owner($rawTitle);
        $dueAt = committee_meeting_extract_action_due_date($rawTitle);
        $title = committee_meeting_clean_action_title($rawTitle);
        if ($title === '') {
            continue;
        }

        $actions[] = [
            'title' => mb_substr($title, 0, 255),
            'owner' => mb_substr($owner, 0, 190),
            'due_at' => $dueAt,
            'priority' => preg_match('/\burgent\b/i', $rawTitle) ? 'urgent' : 'normal',
        ];
    }

    return $actions;
}

function committee_meeting_clean_action_title(string $title): string
{
    $title = preg_replace('/[\[(]?\s*(?:owner|who)\s*:\s*.*?(?=\s+due\s*:|[)\]\.;]|$)[\])]?/i', '', $title) ?? $title;
    $title = preg_replace('/[\[(]?\s*due\s*:\s*(?:\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{4})[\])]?/i', '', $title) ?? $title;
    $title = preg_replace('/\s+/', ' ', $title) ?? $title;
    return trim($title, " \t\n\r\0\x0B-;,.");
}

function committee_meeting_extract_action_owner(string $title): string
{
    if (preg_match('/(?:owner|who)\s*:\s*(.*?)(?=\s+due\s*:|[)\]\.;]|$)/i', $title, $match)) {
        return trim((string) $match[1]);
    }

    return '';
}

function committee_meeting_extract_action_due_date(string $title): string
{
    if (!preg_match('/\bdue\s*:\s*(\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{4})\b/i', $title, $match)) {
        return '';
    }

    $date = (string) $match[1];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    $parsed = DateTimeImmutable::createFromFormat('!d/m/Y', $date);
    return $parsed ? $parsed->format('Y-m-d') : '';
}

function committee_meeting_action_key(string $title): string
{
    return mb_strtolower(trim(preg_replace('/\s+/', ' ', $title) ?? $title));
}

function committee_meeting_create_actions_from_minutes(PDO $pdo, int $meetingId, ?int $userId = null): int
{
    $meeting = committee_meeting_get($pdo, $meetingId);
    if (!$meeting) {
        throw new InvalidArgumentException('Meeting not found.');
    }

    $actions = committee_meeting_extract_actions((string) ($meeting['minutes'] ?? ''));
    if ($actions === []) {
        return 0;
    }

    $existing = [];
    foreach (secretary_tasks_for_meeting($pdo, $meetingId) as $task) {
        $existing[committee_meeting_action_key((string) $task['title'])] = true;
    }

    $created = 0;
    $source = 'Committee meeting ' . (string) ($meeting['meeting_date'] ?? '');
    foreach ($actions as $action) {
        $key = committee_meeting_action_key($action['title']);
        if (isset($existing[$key])) {
            continue;
        }

        secretary_task_save($pdo, null, [
            'category' => 'committee',
            'title' => $action['title'],
            'owner' => $action['owner'],
            'due_at' => $action['due_at'],
            'priority' => $action['priority'],
            'status' => 'open',
            'source' => $source,
            'notes' => '',
            'meeting_id' => $meetingId,
        ], $userId);
        $existing[$key] = true;
        $created++;
    }

    return $created;
}
