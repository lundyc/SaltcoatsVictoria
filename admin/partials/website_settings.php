<?php
declare(strict_types=1);

/**
 * Public website settings (identity, brand colours, contact, invoicing, social
 * links), rendered inside Settings > General. Saved by settings.php.
 * Expects $websiteSettings (site_settings_all()).
 */
$s = $websiteSettings;

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
<h2 class="settings-group-title">Club website</h2>
<p class="text-muted small mb-3">Identity, brand colours, contact details and social links for the public website. Changes go live on the website when saved.</p>
<div class="row g-4 mb-4" id="website-settings-section">
  <div class="col-lg-6">
    <div class="card hub-section h-100"><div class="card-body">
      <h3 class="h6 mb-3">Identity</h3>
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
      <h3 class="h6 mb-3">Brand colours</h3>
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
      <h3 class="h6 mb-3">Ground &amp; contact</h3>
      <?php
      $text('ground_name', 'Ground name');
      $text('ground_address', 'Ground address');
      $text('ground_maps_url', 'Directions link', 'Google Maps URL');
      $text('ground_station', 'Nearest railway station');
      $text('ground_record_attendance', 'Record attendance');
      $text('contact_email', 'Contact email', 'Also the destination for the website contact form', 'email');
      $text('contact_phone', 'Contact phone');
      $text('enquiry_email_commercial', 'Sponsorship & advertising enquiries to', 'Leave blank to use the contact email', 'email');
      $text('enquiry_email_hire', 'Ground hire enquiries to', 'Leave blank to use the contact email', 'email');
      $text('enquiry_email_media', 'Media enquiries to', 'Leave blank to use the contact email', 'email');
      $text('enquiry_email_supporters', 'Supporter services enquiries to', 'Leave blank to use the contact email', 'email');
      $text('enquiry_email_volunteers', 'Volunteer & vacancy enquiries to', 'Leave blank to use the contact email', 'email');
      $text('contact_address', 'Postal address');
      ?>
    </div></div>
  </div>

  <div class="col-lg-6">
    <div class="card hub-section h-100"><div class="card-body">
      <h3 class="h6 mb-3">Invoicing</h3>
      <p class="text-muted small">Shown on the letterhead of every generated invoice (sponsorship agreements, shop and ticket orders). Leave blank to omit — the club has no VAT registration recorded in the system.</p>
      <?php
      $text('company_number', 'Company / charity number');
      $text('bank_details', 'Bank transfer details', 'Shown at the foot of the invoice, e.g. sort code and account number');
      ?>
    </div></div>
  </div>

  <div class="col-lg-6">
    <div class="card hub-section h-100"><div class="card-body">
      <h3 class="h6 mb-3">Player profiles</h3>
      <p class="text-muted small">Choose which statistics appear on each player's public page. Appearances, starts, substitute appearances and minutes are always shown.</p>
      <?php
      $toggle = static function (string $key, string $label, string $help) use ($s): void {
          echo '<div class="form-check form-switch mb-3">';
          echo '<input type="hidden" name="' . h($key) . '" value="0">';
          echo '<input class="form-check-input" type="checkbox" role="switch" id="f_' . h($key) . '" name="' . h($key) . '" value="1"' . (($s[$key] ?? '0') === '1' ? ' checked' : '') . '>';
          echo '<label class="form-check-label" for="f_' . h($key) . '"><strong>' . h($label) . '</strong></label>';
          echo '<div class="form-text">' . h($help) . '</div></div>';
      };
      $toggle('player_show_goals', 'Show goals', 'Goals scored, on the stat rings and the Stats tab.');
      $toggle('player_show_cards', 'Show yellow and red cards', 'Disciplinary record on the Stats tab.');
      $toggle('player_show_shootout', 'Show penalty-shootout record', 'Shootout kicks scored from taken, on the Stats tab.');
      ?>
    </div></div>
  </div>

  <div class="col-lg-6">
    <div class="card hub-section h-100"><div class="card-body">
      <h3 class="h6 mb-3">Social &amp; advanced</h3>
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
</div>
