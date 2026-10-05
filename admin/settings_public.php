<?php

declare(strict_types=1);

/**
 * Public website settings — club identity, brand colours, contact details and
 * social links used by the public site (public/*). Stored in site_settings.
 */

$pageHero = [
    'eyebrow' => 'Public website',
    'title' => 'Website settings',
    'subtitle' => 'Identity, brand colours, contact details and social links for the club website.',
    'actions' => [
        ['label' => 'Open website', 'href' => '/', 'class' => 'btn btn-outline-secondary btn-sm'],
    ],
];

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/lib/site_settings.php';
require_once __DIR__ . '/lib/audit.php';

site_settings_ensure_schema($pdo);

$saved = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $incoming = [];
        foreach (array_keys(site_settings_defaults()) as $key) {
            if (array_key_exists($key, $_POST)) {
                $incoming[$key] = (string) $_POST[$key];
            }
        }
        // Validate colour fields — must be #rgb / #rrggbb.
        foreach (['brand_primary', 'brand_primary_ink', 'brand_secondary', 'brand_on_primary', 'brand_accent', 'brand_accent_ink'] as $c) {
            if (isset($incoming[$c]) && $incoming[$c] !== '' && !preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', trim($incoming[$c]))) {
                $errors[] = ucfirst(str_replace('_', ' ', $c)) . ' must be a hex colour like #d3122a.';
            }
        }
        foreach (['contact_email'] as $m) {
            if (!empty($incoming[$m]) && !filter_var($incoming[$m], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Contact email is not a valid address.';
            }
        }
        if (!$errors) {
            site_settings_save($pdo, $incoming);
            auditLog($pdo, 'site_settings_updated', 'Updated public website settings');
            $saved = true;
        }
    }
}

$s = site_settings_all($pdo);

/** Small helpers for the form. */
$text = static function (string $key, string $label, string $help = '', string $type = 'text') use ($s): void {
    echo '<div class="mb-3"><label class="form-label" for="f_' . h($key) . '">' . h($label) . '</label>';
    echo '<input class="form-control" type="' . h($type) . '" id="f_' . h($key) . '" name="' . h($key) . '" value="' . h((string) $s[$key]) . '">';
    if ($help !== '') {
        echo '<div class="form-text">' . h($help) . '</div>';
    }
    echo '</div>';
};
$color = static function (string $key, string $label) use ($s): void {
    $val = (string) $s[$key] ?: '#000000';
    echo '<div class="mb-3"><label class="form-label" for="f_' . h($key) . '">' . h($label) . '</label>';
    echo '<div class="input-group"><input type="color" class="form-control form-control-color" value="' . h($val) . '" oninput="this.nextElementSibling.value=this.value">';
    echo '<input class="form-control" id="f_' . h($key) . '" name="' . h($key) . '" value="' . h((string) $s[$key]) . '" oninput="this.previousElementSibling.value=this.value"></div></div>';
};
?>

<?php if ($saved): ?><div class="alert alert-success">Settings saved. Changes are live on the website immediately.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" class="row g-4">
  <?= csrf_field() ?>

  <div class="col-lg-6">
    <div class="card hub-section h-100"><div class="card-body">
      <h2 class="h6 mb-3">Identity</h2>
      <?php
      $text('club_name', 'Club name');
      $text('club_short_name', 'Short name', 'Used in the header and fixture lists');
      $text('club_nickname', 'Nickname');
      $text('club_tagline', 'Tagline', 'Small text under the crest');
      $text('club_founded', 'Founded (year)');
      $text('crest_url', 'Crest', 'Full-colour crest, used everywhere on the site');
      ?>
    </div></div>
  </div>

  <div class="col-lg-6">
    <div class="card hub-section h-100"><div class="card-body">
      <h2 class="h6 mb-3">Brand colours</h2>
      <?php
      $color('brand_primary', 'Primary — buttons, links, chips');
      $color('brand_primary_ink', 'Primary (hover / dark)');
      $color('brand_secondary', 'Secondary — headers / footer / hero');
      $color('brand_on_primary', 'Text on primary');
      $color('brand_accent', 'Accent — labels on dark surfaces (gold)');
      $color('brand_accent_ink', 'Accent (dark, readable on light)');
      ?>
    </div></div>
  </div>

  <div class="col-lg-6">
    <div class="card hub-section h-100"><div class="card-body">
      <h2 class="h6 mb-3">Ground &amp; contact</h2>
      <?php
      $text('ground_name', 'Ground name');
      $text('ground_address', 'Ground address');
      $text('ground_maps_url', 'Directions link', 'Google Maps URL');
      $text('ground_station', 'Nearest railway station');
      $text('ground_record_attendance', 'Record attendance');
      $text('contact_email', 'Contact email', 'Also the destination for the website contact form', 'email');
      $text('contact_phone', 'Contact phone');
      $text('contact_address', 'Postal address');
      ?>
    </div></div>
  </div>

  <div class="col-lg-6">
    <div class="card hub-section h-100"><div class="card-body">
      <h2 class="h6 mb-3">Invoicing</h2>
      <p class="text-muted small">Shown on the letterhead of every generated invoice (sponsorship agreements, shop and ticket orders). Leave blank to omit — the club has no VAT registration recorded in the system.</p>
      <?php
      $text('company_number', 'Company / charity number');
      $text('bank_details', 'Bank transfer details', 'Shown at the foot of the invoice, e.g. sort code and account number');
      ?>
    </div></div>
  </div>

  <div class="col-lg-6">
    <div class="card hub-section h-100"><div class="card-body">
      <h2 class="h6 mb-3">Social &amp; advanced</h2>
      <?php
      $text('social_facebook', 'Facebook URL');
      $text('social_twitter', 'X / Twitter URL');
      $text('social_instagram', 'Instagram URL');
      $text('social_youtube', 'YouTube URL');
      $text('league_table_url', 'League table URL', 'Overrides the WOSFL link; leave blank to use league-config.json');
      $text('ga_measurement_id', 'Google Analytics ID', 'e.g. G-XXXXXXX');
      ?>
    </div></div>
  </div>

  <div class="col-12">
    <button class="btn btn-brand btn-lg" type="submit">Save settings</button>
  </div>
</form>

<?php require_once __DIR__ . '/footer.php'; ?>
