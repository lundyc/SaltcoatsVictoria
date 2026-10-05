<?php
/** Route: /team/{slug} — player profile. */
declare(strict_types=1);

$player = squad_public_find(db(), (string) ($slug ?? ''));
if ($player === null) {
    http_response_code(404);
    set_meta(['title' => 'Player not found']);
    echo '<div class="page"><div class="container"><span class="eyebrow">Error 404</span>'
        . '<h1>Player not found</h1><p><a class="linkarrow" href="' . e(url('team')) . '">Back to the squad</a></p></div></div>';
    return;
}

$avatar = trim((string) ($player['avatar'] ?? ''));
$photo  = $avatar !== '' ? uploads('players/' . $avatar) : '';
$number = $player['squad_number'] !== null ? (int) $player['squad_number'] : null;
$age    = squad_age($player['date_of_birth'] ?? null);
if ($age !== null && ($age < 14 || $age > 60)) { $age = null; } // implausible => treat as unknown
$bio    = trim((string) ($player['bio'] ?? ''));
$nat    = trim((string) ($player['nationality'] ?? ''));
$dob    = trim((string) ($player['date_of_birth'] ?? ''));
$joined = trim((string) ($player['joined_at'] ?? ''));
$hasDob    = $dob !== '' && $dob !== '0000-00-00' && $age !== null;
$hasJoined = $joined !== '' && $joined !== '0000-00-00';

$initials = '';
foreach (preg_split('/\s+/', trim((string) $player['name'])) ?: [] as $part) {
    $initials .= mb_substr($part, 0, 1);
}
$initials = mb_strtoupper(mb_substr($initials, 0, 2));

// Facts shown as cards below the hero (only the ones we actually hold).
$facts = [];
$facts[] = ['Position', $player['position_label']];
if ($number !== null)  { $facts[] = ['Squad number', '#' . $number]; }
if ($age !== null)     { $facts[] = ['Age', (string) $age]; }
if ($nat !== '')       { $facts[] = ['Nationality', $nat]; }
if ($hasJoined)        { $facts[] = ['Joined', format_date($joined, 'M Y')]; }

$sponsorSlots = pub_player_sponsors((int) $player['id']);

// Rest of the squad, grouped by position, current player removed.
$groups = squad_public_grouped(db());
foreach ($groups as $k => $list) {
    $groups[$k] = array_values(array_filter(
        $list,
        static fn (array $p): bool => (int) $p['id'] !== (int) $player['id']
    ));
}
$squadLabels = ['GK' => 'Goalkeepers', 'DEF' => 'Defenders', 'MID' => 'Midfielders', 'FWD' => 'Forwards'];
$contactEmail = club('contact_email');

$clubNm = club('club_name');
$descBits = [$player['name'] . ' — ' . strtolower((string) $player['position_label']) . ($number !== null ? ' (No. ' . $number . ')' : '') . ' for ' . $clubNm . '.'];
if ($nat !== '') { $descBits[] = 'Nationality: ' . $nat . '.'; }
if ($hasJoined) { $descBits[] = 'Joined ' . format_date($joined, 'F Y') . '.'; }
$descBits[] = $bio !== '' ? excerpt(strip_tags($bio), 90) : 'Player profile, squad information and more.';
set_meta([
    'title' => $player['name'] . ' | ' . $clubNm,
    'title_full' => '1',
    'description' => implode(' ', $descBits),
    'image' => $photo !== '' ? (current_url_origin() . $photo) : '',
    'og_type' => 'profile',
]);
seo_breadcrumbs([['First team', url('team')], [(string) $player['name'], url('team/' . $player['slug'])]]);
pub_jsonld([
    '@type' => 'Person',
    'name' => (string) $player['name'],
    'url' => seo_canonical(),
    'image' => $photo !== '' ? current_url_origin() . $photo : null,
    'jobTitle' => 'Footballer — ' . $player['position_label'],
    'nationality' => $nat,
    'birthDate' => $hasDob ? $dob : null,
    'memberOf' => ['@id' => current_url_origin() . '/#club'],
]);
?>
<section class="pp-hero">
  <div class="container pp-hero__inner">
    <div class="pp-hero__photo">
      <?php if ($photo !== ''): ?>
        <img src="<?= e($photo) ?>" alt="<?= e($player['name']) ?>">
      <?php else: ?>
        <span class="pp-hero__initials" aria-hidden="true"><?= e($initials) ?></span>
      <?php endif; ?>
      <?php if ($number !== null): ?><span class="pp-hero__num"><?= $number ?></span><?php endif; ?>
    </div>
    <div class="pp-hero__id">
      <a class="pp-hero__crumb" href="<?= e(url('team')) ?>">First-team squad</a>
      <span class="pp-hero__pos"><?= e($player['position_label']) ?></span>
      <h1><?= e($player['name']) ?></h1>
      <p class="pp-hero__line">
        <?php if ($number !== null): ?>No.&nbsp;<?= $number ?> &middot; <?php endif; ?>
        <?= e(club('club_short_name', club('club_name'))) ?> first team<?php if ($nat !== ''): ?> &middot; <?= e($nat) ?><?php endif; ?>
      </p>
    </div>
  </div>
</section>

<div class="container pp-body">
  <?php if ($facts): ?>
    <ul class="pp-stats">
      <?php foreach ($facts as [$k, $v]): ?>
        <li><span class="pp-stats__k"><?= e($k) ?></span><span class="pp-stats__v"><?= e($v) ?></span></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <section class="pp-section pp-profile<?= $bio === '' ? ' pp-profile--nobio' : '' ?>">
    <?php if ($bio !== ''): ?>
    <div class="pp-profile__bio">
      <h2>Profile</h2>
      <?php if ($bio !== ''): ?>
        <div class="prose">
          <?php foreach (preg_split('/\n\s*\n/', $bio) as $para): ?>
            <?php $para = trim((string) $para); ?>
            <?php if ($para !== ''): ?><p><?= nl2br(e($para)) ?></p><?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <aside class="pp-profile__data">
      <h3>Player details</h3>
      <dl class="pp-datalist">
        <div><dt>Position</dt><dd><?= e($player['position_label']) ?></dd></div>
        <?php if ($number !== null): ?><div><dt>Squad number</dt><dd><?= $number ?></dd></div><?php endif; ?>
        <?php if ($hasDob): ?><div><dt>Date of birth</dt><dd><?= e(format_date($dob, 'j F Y')) ?><?= $age !== null ? ' (' . $age . ')' : '' ?></dd></div><?php endif; ?>
        <?php if ($nat !== ''): ?><div><dt>Nationality</dt><dd><?= e($nat) ?></dd></div><?php endif; ?>
        <?php if ($hasJoined): ?><div><dt>Joined club</dt><dd><?= e(format_date($joined, 'F Y')) ?></dd></div><?php endif; ?>
      </dl>
    </aside>
  </section>

  <section class="pp-section pp-sponsors">
    <h2>Player sponsors</h2>
    <p class="pp-sponsors__intro">The businesses and supporters backing <?= e($player['name']) ?> this season.</p>
    <div class="pp-sponsors__grid">
      <?php foreach ($sponsorSlots as $slot): ?>
        <?php if (!empty($slot['sponsors'])): ?>
          <?php foreach ($slot['sponsors'] as $sp): ?>
            <?php
            $tag = 'div'; $attrs = '';
            if ($sp['link'] !== '') {
                $tag = 'a';
                $attrs = ' href="' . e($sp['link']) . '" target="_blank" rel="noopener"';
            }
            ?>
            <<?= $tag ?> class="pp-sponsor"<?= $attrs ?>>
              <span class="pp-sponsor__slot"><?= e($slot['label']) ?></span>
              <span class="pp-sponsor__logo<?= $sp['logo'] === '' ? ' is-text' : '' ?>">
                <?php if ($sp['logo'] !== ''): ?>
                  <img src="<?= e(uploads('sponsors/' . $sp['logo'])) ?>" alt="<?= e($sp['name']) ?>" loading="lazy">
                <?php else: ?>
                  <?= e($sp['name']) ?>
                <?php endif; ?>
              </span>
              <?php if ($sp['logo'] !== ''): ?><span class="pp-sponsor__name"><?= e($sp['name']) ?></span><?php endif; ?>
            </<?= $tag ?>>
          <?php endforeach; ?>
        <?php else: ?>
          <a class="pp-sponsor pp-sponsor--open" href="<?= e(url('partners')) ?>">
            <span class="pp-sponsor__slot"><?= e($slot['label']) ?></span>
            <span class="pp-sponsor__cta">Available<br><small>Sponsor this player</small></span>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
    <?php if ($contactEmail !== ''): ?>
      <p class="pp-sponsors__cta">
        <a class="btn btn--sm" href="mailto:<?= e($contactEmail) ?>?subject=<?= rawurlencode('Player sponsorship — ' . $player['name']) ?>">Sponsor <?= e($player['name']) ?></a>
      </p>
    <?php endif; ?>
  </section>

  <?php if (array_sum(array_map('count', $groups)) > 0): ?>
    <section class="pp-section pp-more">
      <h2>The squad</h2>
      <?php foreach ($squadLabels as $key => $label): ?>
        <?php if (empty($groups[$key])) { continue; } ?>
        <h3 class="squad-heading"><?= e($label) ?></h3>
        <div class="squad-grid">
          <?php foreach ($groups[$key] as $p): ?>
            <?php partial('player_card', ['player' => $p]); ?>
          <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <p class="pp-back"><a class="linkarrow" href="<?= e(url('team')) ?>">Back to the squad</a></p>
</div>
