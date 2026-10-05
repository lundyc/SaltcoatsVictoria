<?php
/** Route: /contact — club contact details + enquiry form. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/mailer.php';

$sent = false;
$errors = [];
$form = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // CSRF (session token is created by the Hub's config.php on bootstrap)
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        $errors[] = 'Your session expired — please try again.';
    }

    // Honeypot: real users leave this empty.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        $errors[] = 'Your message could not be sent.';
    }

    // Rate limit: 3 messages / 15 minutes / session.
    $now = time();
    $hits = array_filter((array) ($_SESSION['contact_hits'] ?? []), static fn ($t) => $t > $now - 900);
    if (count($hits) >= 3) {
        $errors[] = 'You have sent several messages recently. Please try again later.';
    }

    foreach (['name', 'email', 'subject', 'message'] as $f) {
        $form[$f] = trim((string) ($_POST[$f] ?? ''));
    }
    if ($form['name'] === '' || $form['message'] === '') {
        $errors[] = 'Please add your name and a message.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    $to = club('contact_email');
    if (!$errors && $to === '') {
        $errors[] = 'The contact form is not configured yet — please use the details on this page.';
    }

    if (!$errors) {
        $subject = 'Website enquiry: ' . ($form['subject'] !== '' ? $form['subject'] : 'No subject');
        $body = "New enquiry from the club website\n\n"
            . 'Name:    ' . $form['name'] . "\n"
            . 'Email:   ' . $form['email'] . "\n"
            . 'Subject: ' . $form['subject'] . "\n\n"
            . $form['message'] . "\n";
        $ok = hub_send_mail($to, $subject, $body, false, $form['email']);
        if ($ok) {
            $hits[] = $now;
            $_SESSION['contact_hits'] = array_values($hits);
            $sent = true;
            $form = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
        } else {
            $errors[] = 'Sorry — the message could not be sent right now. Please email us directly.';
        }
    }
}

// Ground / directions — merged in from the former /club/stadium page.
$groundPage = club_page_by_slug(db(), 'stadium');
$groundBody = $groundPage ? club_page_render($groundPage) : '';
$groundName = club('ground_name', 'Campbell Park');
$groundAddress = club('ground_address');
$groundStation = club('ground_station');
$groundRecord = club('ground_record_attendance');
$mapQuery = $groundAddress !== '' ? $groundAddress : $groundName . ', Saltcoats';
$mapEmbed = 'https://www.google.com/maps?q=' . rawurlencode($mapQuery) . '&output=embed';
$mapsUrl = club('ground_maps_url');
if ($mapsUrl === '') {
    $mapsUrl = 'https://www.google.com/maps?q=' . rawurlencode($mapQuery);
}

set_meta(['title' => 'Contact & find us', 'description' => 'Get in touch with ' . club('club_name') . ' and how to find ' . $groundName . '.']);
?>
<?php partial('page_hero', ['eyebrow' => 'Get in touch', 'title' => 'Contact & find us']); ?>

<div class="page">
  <div class="container contact-grid">
    <div class="contact-details">
      <h2 class="contact-details__title">Get in touch</h2>
      <ul class="contact-list">
        <?php if (club('contact_email') !== ''): ?>
          <li><span>Email</span><a href="mailto:<?= e(club('contact_email')) ?>"><?= e(club('contact_email')) ?></a></li>
        <?php endif; ?>
        <?php if (club('contact_phone') !== ''): ?>
          <li><span>Phone</span><?= e(club('contact_phone')) ?></li>
        <?php endif; ?>
        <li><span>Ground</span><?= e($groundName) ?><?= $groundAddress !== '' ? ', ' . e($groundAddress) : '' ?></li>
        <?php if ($groundStation !== ''): ?><li><span>Nearest station</span><?= e($groundStation) ?></li><?php endif; ?>
        <?php if (club('contact_address') !== ''): ?>
          <li><span>Post</span><?= nl2br(e(club('contact_address'))) ?></li>
        <?php endif; ?>
      </ul>
      <a class="btn btn--sm" href="<?= e($mapsUrl) ?>" target="_blank" rel="noopener">Get directions</a>
      <div class="mapembed mapembed--side">
        <iframe src="<?= e($mapEmbed) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Map of <?= e($groundName) ?>"></iframe>
      </div>
    </div>

    <div class="contact-form">
      <?php if ($sent): ?>
        <div class="notice notice--ok"><p>Thanks — your message has been sent. We'll be in touch.</p></div>
      <?php endif; ?>
      <?php if ($errors): ?>
        <div class="notice notice--err"><ul><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <form method="post" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token'] ?? '') ?>">
        <p class="hp" aria-hidden="true"><label>Leave this blank <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>

        <label class="field"><span>Your name</span>
          <input type="text" name="name" value="<?= e($form['name']) ?>" required>
        </label>
        <label class="field"><span>Email</span>
          <input type="email" name="email" value="<?= e($form['email']) ?>" required>
        </label>
        <label class="field"><span>Subject</span>
          <input type="text" name="subject" value="<?= e($form['subject']) ?>">
        </label>
        <label class="field"><span>Message</span>
          <textarea name="message" rows="6" required><?= e($form['message']) ?></textarea>
        </label>
        <button class="btn" type="submit">Send message</button>
      </form>
    </div>
  </div>

  <div class="container contact-find">
    <div class="prose prose--lead" id="find-us">
      <span class="eyebrow">Visiting us</span>
      <h2 class="find-heading">Plan your visit to <?= e($groundName) ?></h2>
      <?= $groundBody !== '' ? $groundBody : '<p>Information about ' . e($groundName) . ' is coming soon.</p>' ?>
    </div>
  </div>
</div>
