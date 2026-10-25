<?php
declare(strict_types=1);

// One website enquiry as a conversation: the original message, replies sent from the Hub, customer replies
// logged by hand, internal notes and status changes, with the person's other enquiries alongside.
// Opening this page also marks the Enquiries section as read (ORDER_ALERT_PAGES).

$pageHero = [
    'eyebrow' => 'Inbox',
    'title' => 'Enquiry',
    'subtitle' => 'A message sent through the club website.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/enquiries.php';
require_once __DIR__ . '/lib/sponsorship_requests.php';

enquiry_ensure_schema($pdo);
enquiry_mail_pull_throttled($pdo);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM enquiries WHERE id = :i');
$stmt->execute([':i' => $id]);
$enq = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$enq) {
    http_response_code(404);
    echo '<div class="alert alert-warning">Enquiry not found. <a href="/admin/inbox.php?type=enquiries">Back to the Inbox</a></div>';
    require __DIR__ . '/footer.php';
    exit;
}

$deps = enquiry_departments();
$deptLabel = (string) ($deps[$enq['department']]['label'] ?? $enq['department']);
$statuses = ['new' => 'New', 'replied' => 'Replied', 'done' => 'Done'];
$priorities = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];
$user = hub_auth_current_user();
$who = (string) (($user['display_name'] ?? '') !== '' ? $user['display_name'] : ($user['username'] ?? $user['email'] ?? 'Hub user'));
$firstName = trim(explode(' ', trim((string) $enq['name']))[0] ?? '');
$clubName = enquiry_club('club_name');
$subjectLine = (string) $enq['subject'] !== '' ? (string) $enq['subject'] : 'your enquiry to ' . $deptLabel;
$replySubject = 'Re: ' . $subjectLine . ' [' . $enq['ref'] . ']';

$notice = '';
$noticeType = 'danger';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $go = static function (string $flag) use ($id): void {
        header('Location: /admin/enquiry.php?id=' . $id . '&saved=' . $flag . '#thread-end');
        exit;
    };
    $setStatus = static function (string $new) use ($pdo, $id, $enq, $who, $statuses): void {
        if ($new === (string) $enq['status'] || !isset($statuses[$new])) {
            return;
        }
        $pdo->prepare('UPDATE enquiries SET status = :s, handled_by = :h WHERE id = :i')
            ->execute([':s' => $new, ':h' => $new === 'new' ? '' : $who, ':i' => $id]);
        enquiry_add_message($pdo, $id, 'event', $who, 'Status changed from ' . $statuses[(string) $enq['status']] . ' to ' . $statuses[$new]);
    };

    if (!hub_auth_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $notice = 'Your session expired — please try again.';
    } elseif ($do === 'reply') {
        $body = trim((string) ($_POST['body'] ?? ''));
        $after = (string) ($_POST['after'] ?? 'replied');
        if ($body === '') {
            $notice = 'Write a reply first.';
        } else {
            $quoted = "\n\n--------\nOn " . (new DateTimeImmutable((string) $enq['created_at']))->format('j M Y \a\t H:i') . ', ' . $enq['name'] . " wrote:\n> "
                . str_replace("\n", "\n> ", trim((string) $enq['message']));
            $replyTo = enquiry_recipient((string) $enq['department']);
            $sent = enquiry_send_mail((string) $enq['email'], $replySubject, $body . $quoted, $replyTo !== '' ? $replyTo : null);
            enquiry_add_message($pdo, $id, 'reply_out', $who, $body, $sent);
            if ($sent) {
                $setStatus($after === 'done' ? 'done' : 'replied');
                $go('sent');
            }
            $go('notsent');
        }
    } elseif ($do === 'log_reply') {
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            $notice = 'Paste what the customer said first.';
        } else {
            enquiry_add_message($pdo, $id, 'reply_in', (string) $enq['name'], $body);
            $setStatus('new');
            $go('logged');
        }
    } elseif ($do === 'note') {
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            $notice = 'Write the note first.';
        } else {
            enquiry_add_message($pdo, $id, 'note', $who, $body);
            $go('note');
        }
    } elseif ($do === 'manage') {
        $priority = isset($priorities[(string) ($_POST['priority'] ?? '')]) ? (string) $_POST['priority'] : (string) $enq['priority'];
        $follow = array_key_exists('follow_up_on', $_POST) ? trim((string) $_POST['follow_up_on']) : (string) ($enq['follow_up_on'] ?? '');
        $follow = preg_match('/^\d{4}-\d{2}-\d{2}$/', $follow) ? $follow : null;
        if ($priority !== (string) $enq['priority']) {
            enquiry_add_message($pdo, $id, 'event', $who, 'Priority set to ' . $priorities[$priority]);
        }
        if ($follow !== ($enq['follow_up_on'] ?: null)) {
            enquiry_add_message($pdo, $id, 'event', $who, $follow ? 'Follow-up set for ' . (new DateTimeImmutable($follow))->format('j M Y') : 'Follow-up cleared');
        }
        $pdo->prepare('UPDATE enquiries SET priority = :p, follow_up_on = :f WHERE id = :i')->execute([':p' => $priority, ':f' => $follow, ':i' => $id]);
        $setStatus((string) ($_POST['status'] ?? $enq['status']));
        $go('manage');
    } elseif ($do === 'sponsorship_approve' || $do === 'sponsorship_decline') {
        $req = sponsorship_request_for_enquiry($pdo, $enq);
        if (!hub_auth_has_any_capability(['sponsorship', 'finance_manage'])) {
            $notice = 'You need the commercial or finance permission to approve sponsorship requests.';
        } elseif ($req === null) {
            $notice = 'There is no sponsorship request attached to this enquiry.';
        } elseif ($do === 'sponsorship_approve') {
            $amounts = [];
            foreach ((array) ($_POST['amount'] ?? []) as $itemId => $val) {
                $amounts[(int) $itemId] = (float) str_replace([',', '£'], '', (string) $val);
            }
            $res = sponsorship_request_approve($pdo, $req['kind'], (int) $req['order']['id'], $amounts, trim((string) ($_POST['note'] ?? '')));
            if ($res['ok']) {
                enquiry_add_message($pdo, $id, 'event', $who, 'Sponsorship approved at ' . gbp((float) $res['total']) . ($res['sent'] ? ' and payment link emailed' : ' (email failed)'));
                $setStatus('replied');
                $go($res['sent'] ? 'sp_approved' : 'sp_approved_nomail');
            }
            $notice = $res['message'];
        } else {
            $res = sponsorship_request_decline($pdo, $req['kind'], (int) $req['order']['id'], trim((string) ($_POST['reason'] ?? '')));
            if ($res['ok']) {
                enquiry_add_message($pdo, $id, 'event', $who, 'Sponsorship request declined' . ($res['sent'] ? ' and they were told by email' : ' (email failed)'));
                $setStatus('done');
                $go('sp_declined');
            }
            $notice = $res['message'];
        }
    } elseif ($do === 'assign') {
        $to = ($_POST['to'] ?? '') === 'me' ? $who : '';
        $pdo->prepare('UPDATE enquiries SET assigned_to = :a WHERE id = :i')->execute([':a' => $to, ':i' => $id]);
        enquiry_add_message($pdo, $id, 'event', $who, $to !== '' ? 'Assigned to ' . $to : 'Unassigned');
        $go('assign');
    }
}

$sponsorshipRequest = sponsorship_request_for_enquiry($pdo, $enq);
$sponsorshipConflicts = $sponsorshipRequest && $sponsorshipRequest['order']['status'] === 'enquiry'
    ? sponsorship_request_conflicts($pdo, $sponsorshipRequest['kind'], $sponsorshipRequest['order'], $sponsorshipRequest['items']) : [];
$canApproveSponsorship = hub_auth_has_any_capability(['sponsorship', 'finance_manage']);
$messages = $pdo->prepare('SELECT * FROM enquiry_messages WHERE enquiry_id = :i ORDER BY created_at ASC, id ASC');
$messages->execute([':i' => $id]);
$messages = $messages->fetchAll(PDO::FETCH_ASSOC);

$related = $pdo->prepare('SELECT id, ref, subject, department, status, created_at FROM enquiries WHERE LOWER(email) = LOWER(:e) ORDER BY created_at ASC, id ASC');
$related->execute([':e' => (string) $enq['email']]);
$related = $related->fetchAll(PDO::FETCH_ASSOC);

$neighbour = static function (string $sql) use ($pdo, $id): ?int {
    $q = $pdo->prepare($sql);
    $q->execute([':i' => $id]);
    $v = $q->fetchColumn();
    return $v === false ? null : (int) $v;
};
$prevId = $neighbour('SELECT MAX(id) FROM enquiries WHERE id < :i');
$nextId = $neighbour('SELECT MIN(id) FROM enquiries WHERE id > :i');

$details = $enq['details'] ? (json_decode((string) $enq['details'], true) ?: []) : [];
$templates = enquiry_reply_templates((string) $enq['department']);
$fmt = static fn(string $dt): string => (new DateTimeImmutable($dt))->format('j M Y, H:i');
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $out .= mb_strtoupper(mb_substr($part, 0, 1));
    }
    return $out !== '' ? $out : '?';
};
$tone = ['new' => 'info', 'replied' => 'warning', 'done' => 'success'][(string) $enq['status']] ?? 'muted';
$followOverdue = !empty($enq['follow_up_on']) && (string) $enq['status'] !== 'done' && (string) $enq['follow_up_on'] < date('Y-m-d');
$flash = [
    'sent' => ['success', 'Reply emailed to ' . (string) $enq['email'] . '.'],
    'sp_approved' => ['success', 'Approved. The payment link has been emailed to them.'],
    'sp_approved_nomail' => ['warning', 'Approved, but the email could not be sent. Please contact them directly with the payment link.'],
    'sp_declined' => ['success', 'Declined. They have been told by email.'],
    'notsent' => ['danger', 'The email could not be sent, so the status was not changed. The reply is saved below, marked as not delivered. Try emailing them directly.'],
    'logged' => ['success', 'Customer reply added. The enquiry is back to New.'],
    'note' => ['success', 'Note added.'],
    'manage' => ['success', 'Saved.'],
    'assign' => ['success', 'Saved.'],
    'saved' => ['success', 'Saved.'],
][(string) ($_GET['saved'] ?? '')] ?? null;
$csrf = hub_auth_csrf_token();
$count = static fn(string $k): int => count(array_filter($messages, static fn(array $m): bool => $m['kind'] === $k));
?>
<style>
    .enq-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 1rem; }
    .enq-title { font-size: 1.3rem; margin: 0 0 0.4rem; }
    .enq-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem; color: var(--hub-muted, #70676b); font-size: 0.85rem; margin-top: 0.3rem; }
    .enq-pill { display: inline-block; padding: 0.18rem 0.6rem; border-radius: 999px; font-size: 0.78rem; font-weight: 700; border: 1px solid transparent; }
    .enq-pill--info { background: #d6e8ff; color: #0a4a9e; border-color: #aacbf7; }
    .enq-pill--warning { background: #fff0c2; color: #7a5600; border-color: #f2d98a; }
    .enq-pill--success { background: #d1f0df; color: #0b5c33; border-color: #a6dfc0; }
    .enq-pill--muted { background: #e9e6e7; color: #4d4548; border-color: #d3cfd1; }
    .enq-pill--high { background: #fadadd; color: #8f1f2f; border-color: #f0b0b8; }
    .enq-pill--low { background: #e9e6e7; color: #4d4548; border-color: #d3cfd1; }
    .enq-pill--dept { background: #f1e8f8; color: #5a2a86; border-color: #d9c4ee; }
    .enq-nav a { margin-left: 0.25rem; }

    .enq-thread { display: flex; flex-direction: column; gap: 1rem; }
    .enq-msg { display: flex; gap: 0.75rem; max-width: 92%; }
    .enq-msg--out { align-self: flex-end; flex-direction: row-reverse; }
    .enq-avatar { flex: none; width: 2.4rem; height: 2.4rem; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: 0.85rem; color: #fff; background: #6c757d; }
    .enq-msg--in .enq-avatar { background: #0aa2c0; }
    .enq-msg--out .enq-avatar { background: var(--brand-primary, #6a2036); }
    .enq-bubble { border: 1px solid rgba(31, 26, 29, 0.12); border-radius: 0.9rem; padding: 0.75rem 1rem; background: #fff; min-width: 0; }
    .enq-msg--out .enq-bubble { background: #f7ecef; border-color: #e7c9d1; }
    .enq-bubble__top { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 0.5rem; font-size: 0.8rem; color: var(--hub-muted, #70676b); margin-bottom: 0.35rem; }
    .enq-bubble__top strong { color: var(--brand-darkest, #1f1a1d); }
    .enq-text { white-space: pre-wrap; overflow-wrap: anywhere; }
    .enq-note { align-self: stretch; border: 1px dashed #d9b84a; background: #fff8dc; border-radius: 0.6rem; padding: 0.6rem 0.9rem; max-width: none; }
    .enq-note .enq-bubble__top { color: #7a5600; }
    .enq-event { align-self: center; font-size: 0.78rem; color: var(--hub-muted, #70676b); display: flex; align-items: center; gap: 0.5rem; }
    .enq-event::before, .enq-event::after { content: ""; width: 2rem; height: 1px; background: rgba(31, 26, 29, 0.2); }
    .enq-failed { color: #8f1f2f; font-weight: 700; }
    .enq-tags { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 0.75rem; }

    .enq-side dl { margin: 0; }
    .enq-side dt { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--hub-muted, #70676b); font-weight: 700; }
    .enq-side dd { margin: 0 0 0.6rem; overflow-wrap: anywhere; }

    .enq-tree { list-style: none; margin: 0; padding: 0 0 0 0.9rem; position: relative; }
    .enq-tree::before { content: ""; position: absolute; left: 0.28rem; top: 0.4rem; bottom: 0.9rem; width: 2px; background: rgba(31, 26, 29, 0.15); }
    .enq-tree li { position: relative; padding: 0.35rem 0 0.35rem 0.6rem; }
    .enq-tree li::before { content: ""; position: absolute; left: -0.9rem; top: 0.95rem; width: 0.65rem; height: 0.65rem; border-radius: 50%; background: #fff; border: 2px solid #9b9094; }
    .enq-tree li.is-current::before { background: var(--brand-primary, #6a2036); border-color: var(--brand-primary, #6a2036); }
    .enq-tree a { text-decoration: none; font-weight: 650; }
    .enq-tree .small { display: block; color: var(--hub-muted, #70676b); }
    .enq-avatar--lg { width: 2.9rem; height: 2.9rem; background: #0aa2c0; font-size: 1rem; }
    .enq-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 0.3rem; padding: 0.25rem; border: 1px solid rgba(31, 26, 29, 0.12); border-radius: 0.6rem; background: #faf7f5; }
    .enq-tool { width: 2.2rem; height: 2.2rem; display: inline-grid; place-items: center; padding: 0; border: 1px solid transparent; border-radius: 0.45rem; color: #4d4548; background: transparent; }
    .enq-tool:hover, .enq-tool:focus-visible { background: rgba(75, 8, 24, 0.1); color: var(--brand-dark, #4b0818); }
    .enq-tool--info { background: #d6e8ff; color: #0a4a9e; } .enq-tool--warning { background: #fff0c2; color: #7a5600; }
    .enq-tool--success { background: #d1f0df; color: #0b5c33; } .enq-tool--danger { background: #fadadd; color: #8f1f2f; }
    .enq-tool--on { background: var(--brand-primary, #6a2036); color: #fff; }
    .enq-tool-sep { width: 1px; height: 1.4rem; background: rgba(31, 26, 29, 0.18); margin: 0 0.15rem; }
    .enq-chip { display: inline-flex; align-items: center; gap: 0.35rem; margin: 0.1rem 0.25rem 0.1rem 0; padding: 0.15rem 0.6rem; border-radius: 999px; border: 1px solid rgba(31, 26, 29, 0.15); background: #fff; font-size: 0.78rem; text-decoration: none; color: inherit; }
    a.enq-chip:hover { background: rgba(75, 8, 24, 0.06); }
    .enq-chip.is-current { background: var(--brand-primary, #6a2036); border-color: var(--brand-primary, #6a2036); color: #fff; font-weight: 700; }
    .enq-dot { width: 0.5rem; height: 0.5rem; border-radius: 50%; background: #9b9094; }
    .enq-dot--info { background: #0d6efd; } .enq-dot--warning { background: #e0a800; } .enq-dot--success { background: #198754; }
    @media (min-width: 992px) { .enq-sticky { position: sticky; top: 0.75rem; max-height: calc(100vh - 1.5rem); overflow-y: auto; } }
    @media (max-width: 575.98px) { .enq-msg { max-width: 100%; } }

    /* Phones */
    @media (max-width: 767.98px) {
        .enq-top .btn { min-height: 44px; min-width: 44px; display: inline-flex; align-items: center; justify-content: center; }
        .enq-title { font-size: 1.15rem; overflow-wrap: anywhere; }
        .enq-meta { gap: 0.4rem; }
        .enq-meta > span:not(.enq-pill) { flex-basis: auto; }
        /* contact block: name + links stack, toolbar becomes a full-width row of big buttons */
        .enq-contact .d-flex.flex-wrap.align-items-center.gap-3 { gap: 0.75rem !important; }
        .enq-contact .me-auto { flex: 1 1 calc(100% - 4rem); min-width: 0; margin-right: 0 !important; }
        .enq-contact .me-auto .small { flex-direction: column; gap: 0.35rem !important; }
        .enq-contact .me-auto .small a, .enq-contact .me-auto .small span { overflow-wrap: anywhere; min-height: 32px; display: flex; align-items: center; }
        .enq-toolbar { flex: 1 1 100%; justify-content: space-between; gap: 0.25rem; padding: 0.35rem; }
        .enq-toolbar > .dropdown, .enq-toolbar > form, .enq-toolbar > a { flex: 1 1 0; display: flex; }
        .enq-tool { width: 100%; min-width: 44px; height: 46px; font-size: 1.05rem; }
        .enq-tool-sep { display: none; }
        .enq-toolbar .dropdown-menu { max-width: calc(100vw - 2rem); }
        .enq-toolbar .dropdown-item { padding: 0.75rem 1rem; }
        .enq-chip { padding: 0.4rem 0.75rem; margin-bottom: 0.3rem; }
        /* conversation: keep the chat look - theirs on the left, ours on the right, small avatars */
        .enq-thread { gap: 0.75rem; }
        .enq-msg { gap: 0.45rem; max-width: 94%; align-items: flex-end; }
        .enq-msg--in { align-self: flex-start; }
        .enq-msg--out { align-self: flex-end; }
        .enq-avatar:not(.enq-avatar--lg) { width: 1.9rem; height: 1.9rem; font-size: 0.7rem; }
        .enq-bubble { padding: 0.65rem 0.8rem; }
        .enq-msg--in .enq-bubble { border-bottom-left-radius: 0.25rem; }
        .enq-msg--out .enq-bubble { border-bottom-right-radius: 0.25rem; }
        .enq-bubble__top { flex-direction: column; gap: 0.1rem; }
        .enq-note { padding: 0.6rem 0.75rem; }
        .enq-event { text-align: center; }
        .enq-event::before, .enq-event::after { display: none; }
        /* reply box: no iOS zoom on focus, big tap targets */
        .enq-composer textarea, .enq-composer input, .enq-composer select { font-size: 16px; }
        .enq-composer .form-control-sm, .enq-composer .form-select-sm { min-height: 44px; }
        .enq-composer .btn { min-height: 46px; }
        .enq-composer .d-flex.flex-wrap.gap-2 > .btn-group { width: 100%; }
        .enq-composer .btn-group > .btn-brand:first-child { flex: 1 1 auto; }
        .enq-composer .hub-section-tabs .nav-link { padding: 0.6rem 0.85rem; }
        .enq-composer #paneNote .btn, .enq-composer #paneLog .btn { width: 100%; }
        .hub-panel .card-body { padding: 0.9rem; }
    }
</style>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-center mb-2 gap-2 enq-top">
        <a class="small text-decoration-none d-inline-flex align-items-center" style="min-height:44px" href="/admin/inbox.php?type=enquiries"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Back to the Inbox</a>
        <div class="d-flex gap-1">
            <?php if ($prevId): ?><a class="btn btn-sm btn-outline-secondary" href="?id=<?= $prevId ?>"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Older</a><?php else: ?><span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true"><i class="fa-solid fa-arrow-left me-1" aria-hidden="true"></i>Older</span><?php endif; ?>
            <?php if ($nextId): ?><a class="btn btn-sm btn-outline-secondary" href="?id=<?= $nextId ?>">Newer<i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></a><?php else: ?><span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true">Newer<i class="fa-solid fa-arrow-right ms-1" aria-hidden="true"></i></span><?php endif; ?>
        </div>
    </div>

    <?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> py-2"><?= h($flash[1]) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="alert alert-<?= $noticeType ?> py-2"><?= h($notice) ?></div><?php endif; ?>
    <?php if ((int) $enq['email_sent'] !== 1): ?>
        <div class="alert alert-warning py-2">
            The notification email for this enquiry
            <?= (string) $enq['emailed_to'] === '' ? 'was not sent because no recipient is set for this department (Settings &rarr; Website &rarr; Ground &amp; contact).' : 'to ' . h((string) $enq['emailed_to']) . ' could not be sent.' ?>
            It is saved here, so nothing has been lost.
        </div>
    <?php endif; ?>

    <section class="card hub-panel mb-3 enq-contact"><div class="card-body py-3">
        <h2 class="enq-title"><?= h(ucfirst($subjectLine)) ?></h2>
        <div class="enq-meta mb-3 pb-3 border-bottom">
            <span class="enq-pill enq-pill--<?= $tone ?>"><?= h($statuses[(string) $enq['status']] ?? (string) $enq['status']) ?></span>
            <span class="enq-pill enq-pill--dept"><?= h($deptLabel) ?></span>
            <?php if ((string) $enq['priority'] === 'high'): ?><span class="enq-pill enq-pill--high"><i class="fa-solid fa-flag me-1" aria-hidden="true"></i>High priority</span><?php endif; ?>
            <?php if ($followOverdue): ?><span class="enq-pill enq-pill--high"><i class="fa-solid fa-clock me-1" aria-hidden="true"></i>Follow-up overdue</span><?php endif; ?>
            <span><?= h((string) $enq['ref']) ?></span>
            <span>&middot; received <?= h($fmt((string) $enq['created_at'])) ?></span>
            <span>&middot; <?= $count('reply_out') ?> repl<?= $count('reply_out') === 1 ? 'y' : 'ies' ?> sent</span>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <span class="enq-avatar enq-avatar--lg" aria-hidden="true"><?= h($initials((string) $enq['name'])) ?></span>
            <div class="me-auto">
                <div class="fw-bold"><?= h((string) $enq['name']) ?></div>
                <div class="small d-flex flex-wrap gap-3">
                    <a href="mailto:<?= h((string) $enq['email']) ?>"><i class="fa-solid fa-envelope me-1" aria-hidden="true"></i><?= h((string) $enq['email']) ?></a>
                    <?php if ((string) $enq['phone'] !== ''): ?><a href="tel:<?= h((string) $enq['phone']) ?>"><i class="fa-solid fa-phone me-1" aria-hidden="true"></i><?= h((string) $enq['phone']) ?></a><?php endif; ?>
                    <span class="text-muted"><i class="fa-solid fa-user-check me-1" aria-hidden="true"></i><?= (string) $enq['assigned_to'] !== '' ? h((string) $enq['assigned_to']) : 'Unassigned' ?></span>
                    <?php if ((string) $enq['handled_by'] !== ''): ?><span class="text-muted"><i class="fa-solid fa-clock-rotate-left me-1" aria-hidden="true"></i>Last handled by <?= h((string) $enq['handled_by']) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="enq-toolbar" role="toolbar" aria-label="Enquiry actions">
                <?php $tb = static function (string $do, array $fields) use ($csrf, $id): string {
                    $out = '<input type="hidden" name="csrf_token" value="' . h($csrf) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="do" value="' . $do . '">';
                    foreach ($fields as $k => $v) { $out .= '<input type="hidden" name="' . $k . '" value="' . h($v) . '">'; }
                    return $out;
                }; ?>
                <div class="dropdown">
                    <button class="btn btn-sm enq-tool enq-tool--<?= $tone ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Status: <?= h($statuses[(string) $enq['status']] ?? '') ?>" aria-label="Status: <?= h($statuses[(string) $enq['status']] ?? '') ?>"><i class="fa-solid <?= ['new' => 'fa-circle-dot', 'replied' => 'fa-reply', 'done' => 'fa-circle-check'][(string) $enq['status']] ?? 'fa-circle' ?>" aria-hidden="true"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="dropdown-header">Status</li>
                        <?php foreach ($statuses as $k => $lbl): ?>
                            <li><form method="post" class="m-0"><?= $tb('manage', []) ?><button class="dropdown-item<?= $enq['status'] === $k ? ' active' : '' ?>" name="status" value="<?= $k ?>"><?= $lbl ?></button></form></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="dropdown">
                    <button class="btn btn-sm enq-tool<?= (string) $enq['priority'] === 'high' ? ' enq-tool--danger' : '' ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Priority: <?= h($priorities[(string) $enq['priority']] ?? '') ?>" aria-label="Priority: <?= h($priorities[(string) $enq['priority']] ?? '') ?>"><i class="fa-solid fa-flag" aria-hidden="true"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="dropdown-header">Priority</li>
                        <?php foreach ($priorities as $k => $lbl): ?>
                            <li><form method="post" class="m-0"><?= $tb('manage', []) ?><button class="dropdown-item<?= $enq['priority'] === $k ? ' active' : '' ?>" name="priority" value="<?= $k ?>"><?= $lbl ?></button></form></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="dropdown">
                    <?php $fu = (string) ($enq['follow_up_on'] ?? ''); $fuTitle = $fu !== '' ? 'Follow up on ' . (new DateTimeImmutable($fu))->format('j M Y') : 'Set a follow-up date'; ?>
                    <button class="btn btn-sm enq-tool<?= $followOverdue ? ' enq-tool--danger' : ($fu !== '' ? ' enq-tool--warning' : '') ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="<?= h($fuTitle) ?>" aria-label="<?= h($fuTitle) ?>"><i class="fa-solid fa-calendar-day" aria-hidden="true"></i></button>
                    <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 15rem">
                        <form method="post" class="m-0">
                            <?= $tb('manage', []) ?>
                            <label class="form-label small mb-1" for="enqFollow">Follow up on</label>
                            <input class="form-control form-control-sm mb-2" type="date" id="enqFollow" name="follow_up_on" value="<?= h($fu) ?>">
                            <div class="d-flex gap-2"><button class="btn btn-sm btn-brand" type="submit">Save</button><?php if ($fu !== ''): ?><button class="btn btn-sm btn-outline-secondary" type="submit" name="follow_up_on" value="">Clear</button><?php endif; ?></div>
                        </form>
                    </div>
                </div>
                <form method="post" class="m-0">
                    <?= $tb('assign', []) ?>
                    <?php if ((string) $enq['assigned_to'] === $who): ?>
                        <button class="btn btn-sm enq-tool enq-tool--on" name="to" value="" title="Assigned to you - click to unassign" aria-label="Unassign"><i class="fa-solid fa-user-check" aria-hidden="true"></i></button>
                    <?php else: ?>
                        <button class="btn btn-sm enq-tool" name="to" value="me" title="Assign to me" aria-label="Assign to me"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></button>
                    <?php endif; ?>
                </form>
                <span class="enq-tool-sep" aria-hidden="true"></span>
                <a class="btn btn-sm enq-tool" href="mailto:<?= h((string) $enq['email']) ?>?subject=<?= rawurlencode($replySubject) ?>" title="Open in your email app" aria-label="Open in your email app"><i class="fa-solid fa-envelope" aria-hidden="true"></i></a>
                <?php if ((string) $enq['phone'] !== ''): ?><a class="btn btn-sm enq-tool" href="tel:<?= h((string) $enq['phone']) ?>" title="Call <?= h((string) $enq['phone']) ?>" aria-label="Call"><i class="fa-solid fa-phone" aria-hidden="true"></i></a><?php endif; ?>
            </div>
        </div>
        <?php if (count($related) > 1): ?>
            <div class="enq-chain mt-2 pt-2 border-top">
                <span class="small text-muted me-1">Also from them:</span>
                <?php foreach ($related as $r): $rt = ['new' => 'info', 'replied' => 'warning', 'done' => 'success'][(string) $r['status']] ?? 'muted'; $cur = (int) $r['id'] === $id; ?>
                    <a class="enq-chip<?= $cur ? ' is-current' : '' ?>" <?= $cur ? 'aria-current="page"' : 'href="?id=' . (int) $r['id'] . '"' ?> title="<?= h((string) $r['ref']) ?> &middot; <?= h($statuses[(string) $r['status']] ?? (string) $r['status']) ?>"><span class="enq-dot enq-dot--<?= $rt ?>"></span><?= h((new DateTimeImmutable((string) $r['created_at']))->format('j M')) ?> &middot; <?= h(mb_strimwidth((string) $r['subject'] !== '' ? (string) $r['subject'] : ($deps[$r['department']]['label'] ?? 'Enquiry'), 0, 28, '…')) ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div></section>

    <?php if ($sponsorshipRequest): $spOrder = $sponsorshipRequest['order']; $spStatus = (string) $spOrder['status']; ?>
    <section class="card hub-panel mb-3 border-warning"><div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <h3 class="h6 text-uppercase text-muted mb-0">Sponsorship request</h3>
            <?php $spTone = ['enquiry' => 'warning text-dark', 'approved' => 'info text-dark', 'paid' => 'success', 'partially_conflicted' => 'danger', 'declined' => 'secondary'][$spStatus] ?? 'secondary'; ?>
            <span class="badge text-bg-<?= $spTone ?>"><?= h(['enquiry' => 'Awaiting your decision', 'approved' => 'Approved, waiting for payment', 'paid' => 'Paid', 'partially_conflicted' => 'Paid, needs attention', 'declined' => 'Declined'][$spStatus] ?? $spStatus) ?></span>
        </div>
        <?php if ($spStatus === 'enquiry' || $spStatus === 'approved'): ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h(hub_auth_csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">
            <div class="table-responsive mb-2"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>Sponsorship</th><th class="text-end" style="width:9rem">Price (£)</th></tr></thead>
                <tbody>
                <?php foreach ($sponsorshipRequest['items'] as $spItem): $spProblem = $sponsorshipConflicts[(int) $spItem['id']] ?? ''; ?>
                    <tr<?= $spProblem !== '' ? ' class="table-danger"' : '' ?>>
                        <td><?= h((string) $spItem['label']) ?><?php if ($spProblem !== ''): ?><div class="small text-danger"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i><?= h($spProblem) ?></div><?php endif; ?></td>
                        <td class="text-end"><input class="form-control form-control-sm text-end" type="text" inputmode="decimal" name="amount[<?= (int) $spItem['id'] ?>]" value="<?= h(number_format((float) $spItem['unit_amount'], 2, '.', '')) ?>"<?= $canApproveSponsorship ? '' : ' disabled' ?>></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <p class="small text-muted">These are the website guide prices. Change a price here if you have agreed something different; it is locked in when you approve. No game is held while a request is open, so approving checks the game is still free.</p>
            <?php if ($canApproveSponsorship): ?>
            <label class="form-label small fw-semibold" for="spNote">Message to include with the payment link <span class="text-muted fw-normal">(optional)</span></label>
            <textarea class="form-control mb-3" id="spNote" name="note" rows="2" placeholder="e.g. Please send us your logo once you have paid."></textarea>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" name="do" value="sponsorship_approve" class="btn btn-success"><i class="fa-solid fa-circle-check me-1" aria-hidden="true"></i><?= $spStatus === 'approved' ? 'Update and resend payment link' : 'Approve and email payment link' ?></button>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#spDeclineBox"><i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i>Decline</button>
            </div>
            <div class="collapse mt-3" id="spDeclineBox">
                <label class="form-label small fw-semibold" for="spReason">Reason to give them <span class="text-muted fw-normal">(optional, included in the email)</span></label>
                <textarea class="form-control mb-2" id="spReason" name="reason" rows="2"></textarea>
                <button type="submit" name="do" value="sponsorship_decline" class="btn btn-danger">Decline and tell them</button>
            </div>
            <?php else: ?>
            <div class="alert alert-info py-2 mb-0">You need the commercial or finance permission to approve or decline sponsorship requests.</div>
            <?php endif; ?>
        </form>
        <?php else: ?>
            <ul class="mb-0"><?php foreach ($sponsorshipRequest['items'] as $spItem): ?><li><?= h((string) $spItem['label']) ?> &middot; <?= h(gbp((float) $spItem['unit_amount'])) ?></li><?php endforeach; ?></ul>
            <?php if (in_array($spStatus, ['paid', 'partially_conflicted'], true)): ?><p class="small mt-2 mb-0"><a href="/admin/sponsor_orders.php">See it in Sponsor orders</a></p><?php endif; ?>
        <?php endif; ?>
    </div></section>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <section class="card hub-panel mb-3"><div class="card-body">
                <h3 class="h6 text-uppercase text-muted mb-3">Conversation</h3>
                <div class="enq-thread" id="thread">
                    <div class="enq-msg enq-msg--in">
                        <span class="enq-avatar" aria-hidden="true"><?= h($initials((string) $enq['name'])) ?></span>
                        <div class="enq-bubble">
                            <div class="enq-bubble__top"><span><strong><?= h((string) $enq['name']) ?></strong> &middot; original enquiry</span><span><?= h($fmt((string) $enq['created_at'])) ?></span></div>
                            <?php if ($details !== []): ?>
                                <div class="enq-tags">
                                    <?php foreach ($details as $label => $val): ?><span class="badge text-bg-light border" title="<?= h((string) $label) ?>"><?= h((string) $label) ?>: <?= h((string) $val) ?></span><?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="enq-text"><?= h((string) $enq['message']) ?></div>
                        </div>
                    </div>

                    <?php if (trim((string) ($enq['internal_note'] ?? '')) !== ''): ?>
                        <div class="enq-msg enq-note"><div class="enq-bubble" style="background:none;border:0;padding:0">
                            <div class="enq-bubble__top"><span><i class="fa-solid fa-lock me-1" aria-hidden="true"></i><strong>Earlier internal note</strong> (only visible in the Hub)</span></div>
                            <div class="enq-text"><?= h((string) $enq['internal_note']) ?></div>
                        </div></div>
                    <?php endif; ?>

                    <?php foreach ($messages as $m): ?>
                        <?php if ($m['kind'] === 'event'): ?>
                            <div class="enq-event"><span><?= h((string) $m['body']) ?> &middot; <?= h((string) $m['author']) ?> &middot; <?= h($fmt((string) $m['created_at'])) ?></span></div>
                        <?php elseif ($m['kind'] === 'note'): ?>
                            <div class="enq-msg enq-note"><div class="enq-bubble" style="background:none;border:0;padding:0">
                                <div class="enq-bubble__top"><span><i class="fa-solid fa-lock me-1" aria-hidden="true"></i><strong><?= h((string) $m['author']) ?></strong> &middot; internal note</span><span><?= h($fmt((string) $m['created_at'])) ?></span></div>
                                <div class="enq-text"><?= h((string) $m['body']) ?></div>
                            </div></div>
                        <?php elseif ($m['kind'] === 'reply_out'): ?>
                            <div class="enq-msg enq-msg--out">
                                <span class="enq-avatar" aria-hidden="true"><?= h($initials((string) $m['author'])) ?></span>
                                <div class="enq-bubble">
                                    <div class="enq-bubble__top"><span><strong><?= h((string) $m['author']) ?></strong> &middot; emailed to <?= h((string) $enq['email']) ?><?php if (!(int) $m['email_sent']): ?> &middot; <span class="enq-failed"><i class="fa-solid fa-triangle-exclamation me-1" aria-hidden="true"></i>not delivered</span><?php endif; ?></span><span><?= h($fmt((string) $m['created_at'])) ?></span></div>
                                    <div class="enq-text"><?= h((string) $m['body']) ?></div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="enq-msg enq-msg--in">
                                <span class="enq-avatar" aria-hidden="true"><?= h($initials((string) $m['author'])) ?></span>
                                <div class="enq-bubble">
                                    <div class="enq-bubble__top"><span><strong><?= h((string) $m['author']) ?></strong> &middot; replied (added by hand)</span><span><?= h($fmt((string) $m['created_at'])) ?></span></div>
                                    <div class="enq-text"><?= h((string) $m['body']) ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div id="thread-end"></div>
                </div>
            </div></section>
        </div>
        <div class="col-lg-5 enq-side">
            <div class="enq-sticky">
            <section class="card hub-panel enq-composer" id="composer"><div class="card-body">
                <nav class="hub-section-tabs" aria-label="Reply options"><ul class="nav" role="tablist">
                    <li class="nav-item" role="presentation"><button class="nav-link active" aria-selected="true" data-bs-toggle="tab" data-bs-target="#paneReply" type="button" role="tab"><i class="fa-solid fa-reply me-1" aria-hidden="true"></i>Reply to <?= h($firstName !== '' ? $firstName : 'customer') ?></button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#paneNote" type="button" role="tab"><i class="fa-solid fa-lock me-1" aria-hidden="true"></i>Internal note</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#paneLog" type="button" role="tab"><i class="fa-solid fa-inbox me-1" aria-hidden="true"></i>Add their reply</button></li>
                </ul></nav>
                <div class="tab-content pt-3">
                    <div class="tab-pane fade show active" id="paneReply" role="tabpanel">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="reply">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6"><label class="form-label small mb-1">To</label><input class="form-control form-control-sm" value="<?= h((string) $enq['email']) ?>" readonly></div>
                                <div class="col-md-6"><label class="form-label small mb-1" for="enqTemplate">Start from a template</label>
                                    <select class="form-select form-select-sm" id="enqTemplate"><option value="">No template</option>
                                        <?php foreach ($templates as $key => $tpl): ?><option value="<?= h($key) ?>"><?= h($tpl['label']) ?></option><?php endforeach; ?>
                                    </select></div>
                            </div>
                            <label class="form-label small mb-1">Subject</label>
                            <input class="form-control form-control-sm mb-2" value="<?= h($replySubject) ?>" readonly>
                            <textarea class="form-control mb-2" id="enqBody" name="body" rows="6" placeholder="Write your reply. The original message is quoted underneath automatically."></textarea>
                            <div class="d-flex flex-wrap gap-2 align-items-center">
<div class="btn-group">
                                    <button class="btn btn-brand" type="submit" name="after" value="replied"><i class="fa-solid fa-paper-plane me-1" aria-hidden="true"></i>Send reply</button>
                                    <button type="button" class="btn btn-brand dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false"><span class="visually-hidden">More send options</span></button>
                                    <ul class="dropdown-menu">
                                        <li><button class="dropdown-item" type="submit" name="after" value="replied"><i class="fa-solid fa-paper-plane me-2" aria-hidden="true"></i>Send reply</button></li>
                                        <li><button class="dropdown-item" type="submit" name="after" value="done"><i class="fa-solid fa-circle-check me-2 text-success" aria-hidden="true"></i>Send &amp; mark done</button></li>
                                    </ul>
                                </div>
                                <span class="small text-muted"><?= enquiry_mailbox_enabled() ? 'Sent from ' . h(ENQUIRY_MAILBOX) . '. Their reply comes back here automatically.' : 'Replies to this email go to ' . h(enquiry_recipient((string) $enq['department']) ?: 'the club mailbox') . '.' ?></span>
                            </div>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="paneNote" role="tabpanel">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="note">
                            <textarea class="form-control mb-2" name="body" rows="4" placeholder="Only visible in the Hub. Not emailed to anyone."></textarea>
                            <button class="btn btn-outline-secondary" type="submit"><i class="fa-solid fa-lock me-1" aria-hidden="true"></i>Add note</button>
                        </form>
                    </div>
                    <div class="tab-pane fade" id="paneLog" role="tabpanel">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="do" value="log_reply">
                            <p class="small text-muted"><?= enquiry_mailbox_enabled() ? 'Customer replies to our emails appear in the conversation by themselves. Use this only for something they told you another way, such as a phone call.' : 'Customer replies arrive in the club mailbox, not here. Paste what they said so the whole conversation is in one place.' ?> The enquiry goes back to New.</p>
                            <textarea class="form-control mb-2" name="body" rows="5" placeholder="Paste their reply"></textarea>
                            <button class="btn btn-outline-secondary" type="submit"><i class="fa-solid fa-plus me-1" aria-hidden="true"></i>Add their reply</button>
                        </form>
                    </div>
                </div>
            </div></section>

            </div>
        </div>
    </div>
</div>
<script>
    (function () {
        var templates = <?= json_encode(array_map(static fn(array $t): string => $t['body'], $templates), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        var name = <?= json_encode($firstName !== '' ? $firstName : 'there', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        var club = <?= json_encode($clubName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
        var select = document.getElementById('enqTemplate');
        var body = document.getElementById('enqBody');
        if (select && body) {
            select.addEventListener('change', function () {
                var text = templates[select.value];
                if (text === undefined) return;
                if (body.value.trim() !== '' && !window.confirm('Replace what you have written with this template?')) { select.value = ''; return; }
                body.value = text.replace(/\{name\}/g, name).replace(/\{club\}/g, club);
                body.focus();
            });
        }
    })();
</script>
<?php require __DIR__ . '/footer.php'; ?>
