<?php
declare(strict_types=1);

$pageHero = [
    'eyebrow' => 'Events',
    'title' => 'Message Ticket Holders',
    'subtitle' => 'Tell everyone with a ticket about a change, such as a new time or a cancellation.',
    'actions' => [],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/events_admin.php';
require_once __DIR__ . '/lib/audit.php';
ensureEventOrdersSchema($pdo);

$canManage = hub_auth_has_capability('events_manage');
$eventId = (int) ($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$event = $eventId > 0 ? events_get($pdo, $eventId) : null;
if (!$event) {
    http_response_code(404);
    echo '<div class="alert alert-danger">That event was not found. <a href="events.php">Back to events</a></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$errors = [];
$success = '';
$acct = hub_auth_current_user();
$myEmail = (string) ($acct['email'] ?? '');
$subject = trim((string) ($_POST['subject'] ?? ''));
$body = trim((string) ($_POST['body'] ?? ''));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!csrf_check()) {
            throw new RuntimeException('Your session expired. Please reload and try again.');
        }
        if (!$canManage) {
            throw new RuntimeException('You do not have permission to send messages.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'test') {
            if (!filter_var($myEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Your account has no email address to send a test to.');
            }
            $r = events_send_message($pdo, $eventId, $subject, $body, null, $myEmail);
            $success = $r['sent'] > 0 ? 'A test copy was sent to ' . $myEmail . '.' : 'The test email could not be sent.';
        } elseif ($action === 'send') {
            if (empty($_POST['confirm'])) {
                throw new RuntimeException('Tick the box to confirm you want to send this to everyone.');
            }
            $r = events_send_message($pdo, $eventId, $subject, $body, !empty($acct['account_id']) ? (int) $acct['account_id'] : null);
            auditLog($pdo, 'event_message_sent', 'Messaged ' . $r['sent'] . ' ticket holder(s) for event #' . $eventId . ': ' . $subject);
            $success = 'Sent to ' . $r['sent'] . ' ticket holder' . ($r['sent'] === 1 ? '' : 's') . ($r['failed'] > 0 ? ', and ' . $r['failed'] . ' could not be sent' : '') . '.';
            $subject = '';
            $body = '';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$holders = events_holder_emails($pdo, $eventId);
$history = $pdo->prepare('SELECT m.*, p.display_name AS sender FROM event_messages m LEFT JOIN accounts a ON a.id = m.sent_by_account_id LEFT JOIN people p ON p.id = a.person_id WHERE m.event_id = :e ORDER BY m.id DESC LIMIT 20');
try {
    $history->execute([':e' => $eventId]);
    $past = $history->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $past = [];
}
$templates = [
    'Postponed' => ['Update: ' . $event['title'] . ' has been postponed', "We're sorry, but " . $event['title'] . " has been postponed. We will confirm the new date as soon as we can.\n\nYour tickets will stay valid for the new date. If it does not suit you, reply to this email within 28 days of the new date being announced and we will refund you."],
    'Cancelled' => ['Cancelled: ' . $event['title'], "We're very sorry, but " . $event['title'] . " has been cancelled.\n\nYou will receive a full refund, including the booking fee, to the card you paid with. It can take 5 to 10 working days to appear.\n\nThank you for your understanding."],
    'Time change' => ['Time change: ' . $event['title'], "A quick update: the start time for " . $event['title'] . " has changed. Doors now open at [TIME]. Your tickets are still valid."],
];
$eventNavActive = 'messages';
?>

<?php hub_render_breadcrumbs([['label' => 'Events', 'href' => 'events.php'], ['label' => (string) $event['title'], 'href' => 'event_edit.php?id=' . $eventId], ['label' => 'Messages']]); ?>
<div class="hub-section-commandbar">
    <div><h2><?= h((string) $event['title']) ?></h2><p><?= count($holders) ?> ticket holder<?= count($holders) === 1 ? '' : 's' ?> with an email address will receive this.</p></div>
</div>
<?php require __DIR__ . '/partials/event_subnav.php'; ?>

<?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="hub-panel" style="padding:1.15rem 1.25rem">
            <h3 class="h5 mb-3">New message</h3>
            <?php if ($canManage): ?>
            <div class="mb-3 d-flex flex-wrap gap-2 align-items-center"><span class="small text-muted">Start from:</span>
                <?php foreach ($templates as $label => [$tSubject, $tBody]): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-template="<?= h(json_encode(['s' => $tSubject, 'b' => $tBody], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"><?= h($label) ?></button>
                <?php endforeach; ?>
            </div>
            <form method="post" id="msgForm">
                <?= csrf_field() ?>
                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                <div class="mb-3"><label class="form-label" for="msgSubject">Subject</label><input class="form-control" id="msgSubject" name="subject" value="<?= h($subject) ?>" maxlength="200" required></div>
                <div class="mb-3"><label class="form-label" for="msgBody">Message</label><textarea class="form-control" id="msgBody" name="body" rows="9" required><?= h($body) ?></textarea><div class="form-text">Sent from the club's noreply address. Replies go to <?= h(events_contact_email($pdo, $event) ?: 'nobody (no reply-to address is set)') ?>.</div></div>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="msgConfirm" name="confirm" value="1"><label class="form-check-label" for="msgConfirm">I have checked this and want to send it to all <?= count($holders) ?> ticket holder<?= count($holders) === 1 ? '' : 's' ?></label></div>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-secondary" type="submit" name="action" value="test">Send a test to me</button>
                    <button class="btn btn-brand" type="submit" name="action" value="send">Send to ticket holders</button>
                </div>
            </form>
            <?php else: ?><p class="text-muted mb-0">You can view messages but not send them.</p><?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="hub-panel" style="padding:1.15rem 1.25rem">
            <h3 class="h5 mb-3">Already sent</h3>
            <?php if (!$past): ?><p class="text-muted mb-0">Nothing sent yet.</p><?php endif; ?>
            <?php foreach ($past as $m): ?>
                <div class="py-2 border-bottom">
                    <strong><?= h((string) $m['subject']) ?></strong>
                    <div class="small text-muted"><?= h(date('D j M, H:i', strtotime((string) $m['created_at']))) ?> · <?= (int) $m['recipients'] ?> sent<?= (int) $m['failed'] > 0 ? ', ' . (int) $m['failed'] . ' failed' : '' ?><?= !empty($m['sender']) ? ' · ' . h((string) $m['sender']) : '' ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('[data-template]').forEach(function (b) {
    b.addEventListener('click', function () {
        var t = JSON.parse(b.getAttribute('data-template'));
        document.getElementById('msgSubject').value = t.s;
        document.getElementById('msgBody').value = t.b;
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
