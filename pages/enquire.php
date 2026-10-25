<?php
/** Route: /enquire/{dept} — a department-specific enquiry form (sponsorship, hire, media, supporters, volunteers). */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/mailer.php';
require_once HUB_ROOT . '/lib/enquiries.php';

$deps = enquiry_departments();
$dept = (string) ($dept ?? 'general');
if (!isset($deps[$dept]) || $dept === 'general') {
    header('Location: ' . url('contact'), true, 302);
    exit;
}
$def = $deps[$dept];

$sent = false;
$ref = '';
$errors = [];
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$src = $isPost ? $_POST : $_GET; // GET lets a button pre-select an option, e.g. ?interest=Dugout+sponsorship
$form = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
foreach ($def['fields'] as $f) {
    $form[$f['name']] = '';
}
foreach (array_keys($form) as $k) {
    $form[$k] = mb_substr(trim((string) ($src[$k] ?? '')), 0, $k === 'message' ? 1500 : 200);
}

if ($isPost) {
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Your session expired — please try again.';
    }
    $now = time();
    $hits = array_filter((array) ($_SESSION['contact_hits'] ?? []), static fn ($t) => $t > $now - 900);
    if (count($hits) >= 3) {
        $errors[] = 'You have sent several messages recently. Please try again later.';
    }
    $spamFake = false;
    if (!$errors) {
        $guard = enquiry_guard(db(), $_POST);
        if ($guard['status'] === 'limit') {
            $errors[] = $guard['message'];
        } elseif ($guard['status'] === 'spam') {
            enquiry_spam_log($guard['reason']);
            $spamFake = true;
            $sent = true;
            $ref = 'ENQ-' . strtoupper(bin2hex(random_bytes(3)));
        }
    }

    if (!$errors && !$spamFake) {
        $res = enquiry_submit(db(), $dept, $_POST);
        if ($res['ok']) {
            enquiry_guard_record(db(), $guard);
            $hits[] = $now;
            $_SESSION['contact_hits'] = array_values($hits);
            $sent = true;
            $ref = (string) $res['ref'];
        } else {
            $errors = $res['errors'];
        }
    }
}

set_meta(['title' => $def['label'] . ' enquiry', 'description' => $def['blurb'], 'robots' => 'noindex']);
?>
<?php seo_breadcrumbs([['Contact', url('contact')], [$def['label'], url('enquire/' . $dept)]]); ?>
<?php partial('page_hero', ['eyebrow' => 'Get in touch', 'title' => $def['label'], 'sub' => $def['blurb']]); ?>

<div class="page">
  <div class="container">
    <div class="contact-form" style="max-width:640px">
      <?php if ($sent): ?>
        <div class="notice notice--ok"><p>Thanks — your enquiry has been sent (reference <strong><?= e($ref) ?></strong>). The right person at the club will be in touch.</p></div>
        <p><a class="linkarrow" href="<?= e(url()) ?>">Back to home</a></p>
      <?php else: ?>
        <?php if ($errors): ?>
          <div class="notice notice--err"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
          <?= enquiry_spam_fields(false) ?>
          <p class="hp" aria-hidden="true"><label>Leave this blank <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>

          <label class="field"><span>Your name</span><input type="text" name="name" value="<?= e($form['name']) ?>" required></label>
          <label class="field"><span>Email</span><input type="email" name="email" value="<?= e($form['email']) ?>" required></label>

          <?php foreach ($def['fields'] as $f): $n = $f['name']; ?>
            <label class="field"><span><?= e($f['label']) ?></span>
              <?php if ($f['type'] === 'select'): ?>
                <select name="<?= e($n) ?>">
                  <option value="">Please choose…</option>
                  <?php foreach ($f['options'] as $o): ?>
                    <option value="<?= e($o) ?>"<?= $form[$n] === $o ? ' selected' : '' ?>><?= e($o) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php else: ?>
                <input type="<?= e($f['type']) ?>" name="<?= e($n) ?>" value="<?= e($form[$n]) ?>"<?= !empty($f['required']) ? ' required' : '' ?><?= $f['type'] === 'number' ? ' min="1"' : '' ?>>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>

          <label class="field"><span>Message</span><textarea name="message" rows="6" required><?= e($form['message']) ?></textarea></label>
          <?= enquiry_turnstile_widget() ?>
          <button class="btn" type="submit">Send enquiry</button>
        </form>
        <p class="small" style="margin-top:1rem">Prefer the general form? <a href="<?= e(url('contact')) ?>">Contact the club</a>.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
