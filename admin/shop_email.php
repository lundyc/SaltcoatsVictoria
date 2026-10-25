<?php

declare(strict_types=1);

// Club Shop — edit one email template. Hub admin only.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/shop.php';
require_once __DIR__ . '/account_auth.php';
hub_auth_require_capability('shop');
require_once __DIR__ . '/lib/audit.php';

shop_ensure_schema($pdo);
$isAdmin = hub_auth_is_admin();
$defs = shop_email_definitions();
$key = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
if (!isset($defs[$key])) {
    header('Location: /admin/shop_settings.php?tab=emails');
    exit;
}
$def = $defs[$key];
$self = '/admin/shop_email.php?key=' . rawurlencode($key);

if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_check()) {
        header('Location: ' . $self . '&e=' . rawurlencode('Session expired — try again.'));
        exit;
    }
    try {
        if ((string) ($_POST['action'] ?? '') === 'reset') {
            $pdo->prepare('DELETE FROM shop_email_templates WHERE email_key = :k')->execute([':k' => $key]);
            auditLog($pdo, 'shop_email_reset', 'Shop email "' . $def['name'] . '" reset to default');
            header('Location: ' . $self . '&m=' . rawurlencode('Reset to the default wording.'));
            exit;
        }
        $clean = static fn(string $f, int $max): string => mb_substr(trim(str_replace("\r", '', (string) ($_POST[$f] ?? ''))), 0, $max);
        $pdo->prepare('INSERT INTO shop_email_templates (email_key, enabled, subject, heading, subheading, intro, additional)
            VALUES (:k, :e, :s, :h, :sh, :i, :a)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), subject = VALUES(subject), heading = VALUES(heading),
                subheading = VALUES(subheading), intro = VALUES(intro), additional = VALUES(additional)')->execute([
            ':k' => $key, ':e' => !empty($_POST['enabled']) ? 1 : 0,
            ':s' => $clean('subject', 255), ':h' => $clean('heading', 255), ':sh' => $clean('subheading', 255),
            ':i' => $clean('intro', 5000), ':a' => $clean('additional', 5000),
        ]);
        auditLog($pdo, 'shop_email_saved', 'Shop email "' . $def['name'] . '" updated');
        header('Location: ' . $self . '&m=' . rawurlencode('Email saved.'));
        exit;
    } catch (Throwable $e) {
        header('Location: ' . $self . '&e=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$pageHero = [
    'eyebrow' => 'Shop · Emails',
    'title' => $def['name'],
    'subtitle' => $def['description'],
    'actions' => [],
];
require_once __DIR__ . '/header.php';

$cfg = shop_email_config($pdo, $key);
$help = shop_email_placeholder_help();
$recipients = $def['to'] === 'customer' ? 'The customer' : implode(', ', function_exists('stripe_notification_recipients') ? stripe_notification_recipients($pdo) : []);
?>
<div class="shop-admin-page">
    <nav class="hub-breadcrumb" aria-label="Breadcrumb"><a href="/admin/shop_overview.php">Shop</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <a href="/admin/shop_settings.php?tab=emails">Settings &rsaquo; Emails</a> <i class="fa-solid fa-chevron-right" aria-hidden="true"></i> <span aria-current="page"><?= h($def['name']) ?></span></nav>

    <?php if (isset($_GET['m'])): ?><div class="alert alert-success"><?= h((string) $_GET['m']) ?></div><?php endif; ?>
    <?php if (isset($_GET['e'])): ?><div class="alert alert-danger"><?= h((string) $_GET['e']) ?></div><?php endif; ?>

    <div class="row g-3">
        <div class="col-xl-5">
            <form method="post" id="emailForm" class="card hub-panel p-3">
                <?= csrf_field() ?>
                <input type="hidden" name="key" value="<?= h($key) ?>">
                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" id="e_enabled" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="e_enabled">Enable this email</label>
                </div>
                <div class="form-text mb-3">Recipient(s): <strong><?= h($recipients !== '' ? $recipients : 'none set') ?></strong>
                    <?php if ($def['to'] === 'admin'): ?> &middot; <a href="/admin/shop_settings.php?tab=notifications">change recipients</a><?php endif; ?></div>

                <div class="mb-3"><label class="form-label" for="e_subject">Subject</label>
                    <input class="form-control" id="e_subject" name="subject" maxlength="255" data-cfg="subject" value="<?= h($cfg['subject']) ?>"></div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6"><label class="form-label" for="e_heading">Email heading</label>
                        <input class="form-control" id="e_heading" name="heading" maxlength="255" data-cfg="heading" value="<?= h($cfg['heading']) ?>"></div>
                    <div class="col-md-6"><label class="form-label" for="e_sub">Sub-heading</label>
                        <input class="form-control" id="e_sub" name="subheading" maxlength="255" data-cfg="subheading" value="<?= h($cfg['subheading']) ?>"></div>
                </div>
                <div class="mb-3"><label class="form-label" for="e_intro">Main text</label>
                    <textarea class="form-control" id="e_intro" name="intro" rows="6" maxlength="5000" data-cfg="intro"><?= h($cfg['intro']) ?></textarea>
                    <div class="form-text">Shown above the order details. Leave a blank line between paragraphs; wrap words in <code>**double asterisks**</code> for bold.</div></div>
                <div class="mb-3"><label class="form-label" for="e_additional">Additional content</label>
                    <textarea class="form-control" id="e_additional" name="additional" rows="4" maxlength="5000" data-cfg="additional" placeholder="Optional — shown below the order details, e.g. opening hours or a sign-off"><?= h($cfg['additional']) ?></textarea></div>

                <div class="mb-3">
                    <div class="form-label mb-1">Placeholders you can use</div>
                    <div class="d-flex flex-wrap gap-1">
                        <?php foreach ($def['placeholders'] as $ph): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-insert="{<?= h($ph) ?>}" title="<?= h($help[$ph] ?? '') ?>">{<?= h($ph) ?>}</button>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text">Click one to add it where your cursor is. Hover for what it does.</div>
                </div>

                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <?php if ($isAdmin): ?><button class="btn btn-dark" type="submit">Save changes</button><?php endif; ?>
                    <button class="btn btn-outline-secondary" type="button" id="sendTest">Send a test email</button>
                    <a class="btn btn-link" href="/admin/shop_settings.php?tab=emails">Back</a>
                    <?php if ($isAdmin): ?>
                        <button class="btn btn-link text-danger ms-auto" type="submit" name="action" value="reset" formnovalidate
                                onclick="return confirm('Reset this email to the standard wording? Your changes to it will be lost.');">Reset to default</button>
                    <?php endif; ?>
                </div>
                <div class="small mt-2" id="testResult" aria-live="polite"></div>
            </form>
        </div>

        <div class="col-xl-7">
            <div class="pd-sticky" style="position:sticky;top:1rem">
                <section class="card hub-panel">
                    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                        <strong>Preview</strong>
                        <span class="small text-muted" id="previewState">Sample data</span>
                    </div>
                    <div class="px-3 py-2 border-bottom small"><span class="text-muted">Subject:</span> <strong id="previewSubject"></strong></div>
                    <iframe id="previewFrame" title="Email preview" style="width:100%;height:640px;border:0;background:#fff"></iframe>
                </section>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    var form = document.getElementById('emailForm'), frame = document.getElementById('previewFrame');
    var subj = document.getElementById('previewSubject'), state = document.getElementById('previewState'), timer = null, seq = 0, lastFocus = null;
    function payload(extra) {
        var fd = new FormData();
        fd.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
        fd.append('key', <?= json_encode($key) ?>);
        form.querySelectorAll('[data-cfg]').forEach(function (el) { fd.append('cfg[' + el.dataset.cfg + ']', el.value); });
        Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
        return fd;
    }
    function refresh() {
        var mine = ++seq; state.textContent = 'Updating…';
        fetch('/admin/shop_email_preview.php', { method: 'POST', body: payload(), credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (mine !== seq) return; if (d.ok) { frame.srcdoc = d.html; subj.textContent = d.subject; state.textContent = 'Sample data'; } else { state.textContent = d.error || 'Preview failed'; } })
            .catch(function () { if (mine === seq) state.textContent = 'Preview failed'; });
    }
    form.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(refresh, 400); });
    form.querySelectorAll('textarea,input[type=text],input:not([type])').forEach(function (el) { el.addEventListener('focus', function () { lastFocus = el; }); });
    form.querySelectorAll('[data-insert]').forEach(function (b) {
        b.addEventListener('click', function () {
            var el = lastFocus && lastFocus.dataset.cfg ? lastFocus : document.getElementById('e_intro');
            var s = el.selectionStart || 0, e = el.selectionEnd || 0;
            el.value = el.value.slice(0, s) + b.dataset.insert + el.value.slice(e);
            el.focus(); el.selectionStart = el.selectionEnd = s + b.dataset.insert.length;
            el.dispatchEvent(new Event('input', { bubbles: true }));
        });
    });
    document.getElementById('sendTest').addEventListener('click', function () {
        var out = document.getElementById('testResult'); out.className = 'small mt-2 text-muted'; out.textContent = 'Sending…';
        fetch('/admin/shop_email_preview.php', { method: 'POST', body: payload({ send_test: '1' }), credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { out.className = 'small mt-2 ' + (d.sent ? 'text-success' : 'text-danger'); out.textContent = d.sent ? 'Test email sent to ' + d.sent_to + '.' : (d.error || 'Could not send.'); })
            .catch(function () { out.className = 'small mt-2 text-danger'; out.textContent = 'Could not send.'; });
    });
    refresh();
})();
</script>
<?php require __DIR__ . '/footer.php'; ?>
