<?php
$pageHero = [
  'class' => 'players-page-hero',
  'eyebrow' => 'Club',
  'title' => 'Player Reference',
  'subtitle' => 'A quick visual reference for player photos and names.',
];
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/players_lib.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS player_photo_reviews (
  player_id INT UNSIGNED NOT NULL,
  status ENUM('accepted','rejected') NOT NULL,
  reviewed_by INT UNSIGNED NULL,
  reviewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id),
  CONSTRAINT fk_player_photo_reviews_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS player_website_photos (
  player_id INT UNSIGNED NOT NULL,
  uploaded_to_website TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id),
  CONSTRAINT fk_player_website_photos_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$positionColumn = $pdo->query("SHOW COLUMNS FROM players LIKE 'position'")->fetch(PDO::FETCH_ASSOC);
if (!$positionColumn) {
  $pdo->exec("ALTER TABLE players ADD COLUMN position VARCHAR(80) NULL AFTER name");
}

$players = $pdo->query("
  SELECT p.id, p.name, p.position, p.avatar, p.status, p.active, pr.status AS photo_review_status,
    COALESCE(wp.uploaded_to_website, 0) AS uploaded_to_website,
    CASE
      WHEN p.status = 'current' AND p.active = 1 THEN 1
      WHEN p.status = 'trialist' AND p.active = 1 THEN 2
      WHEN p.status = 'injured' THEN 3
      WHEN p.status = 'loan' THEN 4
      WHEN p.status = 'left' OR p.active = 0 THEN 5
      WHEN p.status = 'retired' THEN 6
      ELSE 7
    END AS status_order
  FROM players p
  LEFT JOIN player_photo_reviews pr ON pr.player_id = p.id
  LEFT JOIN player_website_photos wp ON wp.player_id = p.id
  WHERE p.active = 1 AND p.status IN ('current', 'trialist', 'injured', 'loan')
  ORDER BY
    CASE WHEN p.position IS NULL OR TRIM(p.position) = '' THEN 999 ELSE CAST(SUBSTRING_INDEX(p.position, ' ', 1) AS UNSIGNED) END ASC,
    p.position ASC,
    p.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$positionOptions = players_position_options();

$sponsorsByPlayer = [];
$sponsorInfoById = [];
if ($players) {
  $seasonId = getSelectedSeasonId($pdo);
  $ids = implode(',', array_map('intval', array_column($players, 'id')));
  $sponsorStmt = $pdo->prepare("
    SELECT s.player_id, s.slot,
           sp.id AS sponsor_id, sp.name AS sponsor_name, sp.logo_path,
           sp.address, sp.website_url, sp.contact_phone, sp.contact_email,
           sp.facebook_page_url, sp.instagram_url, sp.twitter_url
    FROM sponsorships s
    JOIN sponsors sp ON sp.id = s.sponsor_id
    WHERE s.player_id IN ($ids)
      AND s.season_id = :season_id
      AND s.ended_at IS NULL
  ");
  $sponsorStmt->execute([':season_id' => $seasonId]);
  foreach ($sponsorStmt as $row) {
    $sponsorId = (int) $row['sponsor_id'];
    $sponsorsByPlayer[(int) $row['player_id']][(string) $row['slot']] = [
      'id' => $sponsorId,
      'name' => (string) $row['sponsor_name'],
    ];
    if (!isset($sponsorInfoById[$sponsorId])) {
      $logoPath = trim((string) ($row['logo_path'] ?? ''));
      $sponsorInfoById[$sponsorId] = [
        'name' => (string) $row['sponsor_name'],
        'logo_url' => $logoPath !== '' ? '/uploads/sponsors/' . rawurlencode(basename($logoPath)) : '',
        'address' => trim((string) ($row['address'] ?? '')),
        'website_url' => trim((string) ($row['website_url'] ?? '')),
        'contact_phone' => trim((string) ($row['contact_phone'] ?? '')),
        'contact_email' => trim((string) ($row['contact_email'] ?? '')),
        'facebook_url' => trim((string) ($row['facebook_page_url'] ?? '')),
        'instagram_url' => trim((string) ($row['instagram_url'] ?? '')),
        'twitter_url' => trim((string) ($row['twitter_url'] ?? '')),
      ];
    }
  }
}

function playerReferenceAvatarUrl(?string $avatar): string
{
  $avatar = trim((string) $avatar);
  return $avatar !== '' ? '/uploads/players/' . rawurlencode($avatar) : '';
}
?>

<style>
  .player-reference-page { display:grid; gap:1rem; }
  .player-reference-section { display:grid; gap:1rem; }
  .player-reference-section__header { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:.85rem 1rem; border:1px solid #eadfdf; border-radius:14px; background:#fff; }
  .player-reference-section__header h2 { margin:0; color:#4b0818; font-size:1.15rem; font-weight:900; }
  .player-reference-section__header span { color:#6f6470; font-weight:800; }
  .player-reference-section__header-actions { display:flex; align-items:center; gap:.85rem; }
  .player-reference-grid { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:.75rem; }
  .player-reference-card { position:relative; display:grid; align-content:start; border:2px solid #eadfdf; border-radius:0; background:#fff; text-align:center; color:#21141a; transition:background-color .15s ease, border-color .15s ease, box-shadow .15s ease; }
  .player-reference-card.is-accepted { border-color:#1f9d55; background:#e6f7ed; }
  .player-reference-card.is-rejected { border-color:#c92a2a; background:#fdeaea; }
  .player-reference-card.is-dragging { border-color:#e0b42a; background:#fff7d6; box-shadow:0 16px 32px rgba(75,8,24,.16); }
  .player-reference-card.is-uploading { opacity:.72; pointer-events:none; }
  .player-reference-card:hover { border-color:rgba(75,8,24,.28); color:#21141a; box-shadow:0 12px 28px rgba(75,8,24,.1); }
  .player-reference-card.is-accepted:hover { border-color:#1f9d55; }
  .player-reference-card.is-rejected:hover { border-color:#c92a2a; }
  .player-reference-identity { display:grid; color:inherit; }
  .player-reference-photo { display:grid; place-items:center; justify-self:center; width:50%; aspect-ratio:1/1; overflow:hidden; color:#fff; font-size:1.2rem; font-weight:900; }
  .player-reference-photo img { width:100%; height:100%; object-fit:cover; display:block; }
  .player-reference-name { display:grid; gap:.18rem; min-height:2rem; place-items:center; padding-top:.35rem; font-size:.92rem; font-weight:850; line-height:1.1; overflow-wrap:anywhere; }
  .player-reference-position { margin-top:0; justify-self:center; appearance:none; border:0; background:transparent; color:#6f6470; font-size:.76rem; font-style:italic; font-weight:600; text-decoration:underline; text-decoration-style:dotted; text-underline-offset:.18rem; }
  .player-reference-position:hover { color:#4b0818; }
  .player-reference-position-select { width:100%; max-width:9rem; justify-self:center; font-size:.78rem; }
  .player-reference-sponsors { display:grid; gap:.08rem; margin-top:.35rem; padding-top:.35rem; border-top:1px dashed #eadfdf; font-size:.72rem; line-height:1.2; }
  .player-reference-sponsor-row { display:flex; align-items:baseline; justify-content:center; gap:.35rem; }
  .player-reference-sponsor-row strong { color:#6f6470; font-weight:800; }
  .player-reference-sponsor-row .is-available { color:#c98a12; font-weight:800; }
  .player-reference-sponsor-link { appearance:none; border:0; background:transparent; padding:0; margin:0; color:#21141a; font-weight:700; font-size:inherit; font-family:inherit; text-decoration:underline; text-decoration-style:dotted; text-underline-offset:.15rem; cursor:pointer; overflow-wrap:anywhere; }
  .player-reference-sponsor-link:hover { color:#4b0818; }
  .player-reference-sponsor-modal-logo { max-width:220px; max-height:140px; object-fit:contain; }
  .player-reference-sponsor-modal-details { list-style:none; margin:0; padding:0; text-align:left; }
  .player-reference-sponsor-modal-details li { display:flex; gap:.5rem; padding:.4rem 0; border-bottom:1px solid #eadfdf; font-size:.88rem; }
  .player-reference-sponsor-modal-details li:last-child { border-bottom:0; }
  .player-reference-sponsor-modal-details li i { width:1.1rem; color:#6f6470; }
  .player-reference-sponsor-modal-details li a { color:#4b0818; font-weight:700; }
  .player-reference-actions { display:flex; align-items:center; justify-content:center; gap:.45rem; margin-top:.45rem; }
  .player-reference-upload-status { min-height:.85rem; color:#6f6470; font-size:.7rem; font-weight:700; line-height:1.15; }
  .player-reference-accept { margin:0; padding:.3rem .55rem; border:1px solid #1f9d55; border-radius:7px; background:#fff; color:#1f9d55; font-size:.74rem; font-weight:800; cursor:pointer; transition:background-color .15s ease, color .15s ease; }
  .player-reference-accept:hover { background:#1f9d55; color:#fff; }
  .player-reference-accept:disabled { opacity:.6; cursor:default; }
  .player-reference-website { display:flex; align-items:center; justify-content:center; gap:.45rem; margin:0; padding:.25rem .35rem; border:1px solid #d8dfe6; border-radius:999px; background:#f3f5f7; color:#5b6672; font-size:.76rem; font-weight:800; cursor:pointer; user-select:none; transition:background-color .15s ease, border-color .15s ease, color .15s ease; }
  .player-reference-website:hover { border-color:#1c7ed6; }
  .player-reference-website.is-on { border-color:#1c7ed6; background:#e7f3fc; color:#1461a3; }
  .player-reference-website input { position:absolute; width:1px; height:1px; margin:-1px; padding:0; border:0; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; }
  .player-reference-website__track { position:relative; flex:0 0 auto; width:30px; height:17px; border-radius:999px; background:#c7ced4; transition:background-color .15s ease; }
  .player-reference-website__thumb { position:absolute; top:2px; left:2px; width:13px; height:13px; border-radius:50%; background:#fff; box-shadow:0 1px 2px rgba(33,20,26,.35); transition:transform .15s ease; }
  .player-reference-website__label { display:none; }
  .player-reference-website.is-on .player-reference-website__track { background:#1c7ed6; }
  .player-reference-website.is-on .player-reference-website__thumb { transform:translateX(13px); }
  .player-reference-website input:focus-visible ~ .player-reference-website__track { outline:2px solid #1c7ed6; outline-offset:2px; }
  .player-reference-empty { display:none; }
  .player-reference-empty.is-visible { display:block; }
  @media (max-width: 1399.98px) {
    .player-reference-grid { grid-template-columns:repeat(5,minmax(0,1fr)); }
  }
  @media (max-width: 1199.98px) {
    .player-reference-grid { grid-template-columns:repeat(4,minmax(0,1fr)); }
  }
  @media (max-width: 767.98px) {
    .player-reference-grid { grid-template-columns:repeat(2,minmax(0,1fr)); gap:.85rem; }
    .player-reference-card { padding:.75rem; }
  }
  @media (max-width: 420px) {
    .player-reference-grid { grid-template-columns:1fr; }
  }
  @media print {
    .hub-topbar, .hub-sidebar, .page-hero { display:none !important; }
    .player-reference-grid { grid-template-columns:repeat(6,1fr); gap:.45rem; }
    .player-reference-card { box-shadow:none; break-inside:avoid; }
  }
</style>

<div class="player-reference-page">
  <?php if (!$players): ?>
    <div class="hub-empty-state"><p class="text-muted mb-0">No active players found.</p></div>
  <?php else: ?>
      <section class="player-reference-section" data-reference-section="squad">
        <header class="player-reference-section__header">
          <h2>Squad Players</h2>
          <div class="player-reference-section__header-actions">
            <span><span data-section-count><?= count($players) ?></span> player<span data-section-plural><?= count($players) === 1 ? '' : 's' ?></span></span>
            <a href="/admin/player_reference_pdf.php" class="btn btn-brand btn-sm"><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>Export PDF</a>
          </div>
        </header>
        <div class="hub-empty-state player-reference-empty<?= !$players ? ' is-visible' : '' ?>" data-empty-state><p class="text-muted mb-0">No squad players found.</p></div>
        <div class="player-reference-grid" data-reference-grid>
          <?php foreach ($players as $player): ?>
            <?php
              $avatarUrl = playerReferenceAvatarUrl((string) ($player['avatar'] ?? ''));
              $reviewStatus = (string) ($player['photo_review_status'] ?? '');
              $position = players_normalize_position((string) ($player['position'] ?? ''));
              $positionLabel = players_position_label($position);
              $onWebsite = (bool) ($player['uploaded_to_website'] ?? false);
            ?>
            <div class="player-reference-card<?= $reviewStatus === 'accepted' ? ' is-accepted' : ($reviewStatus === 'rejected' ? ' is-rejected' : '') ?>" data-player-card data-player-id="<?= (int) $player['id'] ?>">
              <div class="player-reference-identity">
                <div class="player-reference-photo" data-player-photo>
                  <?php if ($avatarUrl !== ''): ?>
                    <img src="<?= h($avatarUrl) ?>" alt="<?= h((string) $player['name']) ?>" loading="lazy">
                  <?php else: ?>
                    <span><?= h(strtoupper(substr((string) $player['name'], 0, 1))) ?></span>
                  <?php endif; ?>
                </div>
                <div class="player-reference-name"><span><?= h((string) $player['name']) ?></span></div>
              </div>
              <button type="button" class="player-reference-position<?= $position === '' ? ' d-none' : '' ?>" data-position-display><?= h($positionLabel) ?></button>
              <select class="form-select form-select-sm player-reference-position-select<?= $position !== '' ? ' d-none' : '' ?>" data-position-select aria-label="Set position for <?= h((string) $player['name']) ?>">
                <option value="">Position not set</option>
                <?php foreach ($positionOptions as $value => $label): ?>
                  <option value="<?= h($value) ?>" <?= $position === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
              <?php
                $playerSlots = $sponsorsByPlayer[(int) $player['id']] ?? [];
                $sponsorSlots = ['home' => 'Home', 'away' => 'Away'];
                if (!empty($playerSlots['third'])) {
                  $sponsorSlots['third'] = 'Third';
                }
              ?>
              <div class="player-reference-sponsors">
                <?php foreach ($sponsorSlots as $slotKey => $slotLabel): ?>
                  <?php
                    $slotSponsor = $playerSlots[$slotKey] ?? null;
                    $sponsorName = trim((string) ($slotSponsor['name'] ?? ''));
                    $sponsorId = (int) ($slotSponsor['id'] ?? 0);
                  ?>
                  <div class="player-reference-sponsor-row">
                    <strong><?= h($slotLabel) ?>:</strong>
                    <?php if ($sponsorName !== '' && $sponsorId > 0): ?>
                      <button type="button" class="player-reference-sponsor-link" data-sponsor-id="<?= $sponsorId ?>"><?= h($sponsorName) ?></button>
                    <?php else: ?>
                      <span class="is-available">Available</span>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="player-reference-actions">
                <?php if ($avatarUrl !== '' && $reviewStatus !== 'accepted'): ?>
                  <button type="button" class="player-reference-accept" data-accept-photo>
                    <i class="fa-solid fa-check me-1" aria-hidden="true"></i>Accept
                  </button>
                <?php endif; ?>
                <label class="player-reference-website<?= $onWebsite ? ' is-on' : '' ?>" data-website-toggle title="<?= $onWebsite ? 'On website' : 'Not on website' ?>" aria-label="<?= $onWebsite ? 'On website' : 'Not on website' ?>">
                  <input type="checkbox" data-website-checkbox <?= $onWebsite ? 'checked' : '' ?>>
                  <span class="player-reference-website__track" aria-hidden="true"><span class="player-reference-website__thumb"></span></span>
                  <span class="player-reference-website__label" data-website-label><?= $onWebsite ? 'On website' : 'Not on website' ?></span>
                </label>
              </div>
              <div class="player-reference-upload-status" data-upload-status></div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
  <?php endif; ?>
</div>

<div class="modal fade" id="sponsorInfoModal" tabindex="-1" aria-hidden="true" aria-labelledby="sponsorInfoModalLabel">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="sponsorInfoModalLabel">Sponsor</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body text-center">
        <img class="player-reference-sponsor-modal-logo mb-3 d-none" id="sponsorInfoLogo" alt="">
        <h4 class="mb-3" id="sponsorInfoName"></h4>
        <ul class="player-reference-sponsor-modal-details" id="sponsorInfoDetails"></ul>
        <p class="text-muted small mb-0 d-none" id="sponsorInfoEmpty">No further information recorded for this sponsor.</p>
      </div>
    </div>
  </div>
</div>

<script>
  const sponsorReferenceData = <?= json_encode($sponsorInfoById, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script>
(() => {
  const csrfToken = <?= json_encode((string) ($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
  const squadSection = document.querySelector('[data-reference-section="squad"]');

  const sponsorModalEl = document.getElementById('sponsorInfoModal');
  let sponsorModal = null;
  const sponsorNameNode = document.getElementById('sponsorInfoName');
  const sponsorLogoNode = document.getElementById('sponsorInfoLogo');
  const sponsorDetailsNode = document.getElementById('sponsorInfoDetails');
  const sponsorEmptyNode = document.getElementById('sponsorInfoEmpty');

  function addSponsorDetailRow(list, iconClasses, text, href) {
    if (!text) return false;
    const li = document.createElement('li');
    const icon = document.createElement('i');
    iconClasses.split(' ').forEach((cls) => icon.classList.add(cls));
    icon.setAttribute('aria-hidden', 'true');
    li.appendChild(icon);
    if (href) {
      const a = document.createElement('a');
      a.href = href;
      a.target = '_blank';
      a.rel = 'noopener';
      a.textContent = text;
      li.appendChild(a);
    } else {
      const span = document.createElement('span');
      span.textContent = text;
      li.appendChild(span);
    }
    list.appendChild(li);
    return true;
  }

  document.addEventListener('click', (event) => {
    const link = event.target.closest('.player-reference-sponsor-link');
    if (!link) return;

    const info = sponsorReferenceData[link.dataset.sponsorId];
    if (!info) return;

    sponsorNameNode.textContent = info.name || 'Sponsor';
    sponsorDetailsNode.innerHTML = '';

    if (info.logo_url) {
      sponsorLogoNode.src = info.logo_url;
      sponsorLogoNode.alt = info.name || 'Sponsor logo';
      sponsorLogoNode.classList.remove('d-none');
    } else {
      sponsorLogoNode.classList.add('d-none');
      sponsorLogoNode.removeAttribute('src');
    }

    let hasDetails = false;
    if (addSponsorDetailRow(sponsorDetailsNode, 'fa-solid fa-location-dot', info.address)) hasDetails = true;
    if (addSponsorDetailRow(sponsorDetailsNode, 'fa-solid fa-globe', info.website_url, info.website_url)) hasDetails = true;
    if (addSponsorDetailRow(sponsorDetailsNode, 'fa-solid fa-phone', info.contact_phone, info.contact_phone ? 'tel:' + info.contact_phone : '')) hasDetails = true;
    if (addSponsorDetailRow(sponsorDetailsNode, 'fa-solid fa-envelope', info.contact_email, info.contact_email ? 'mailto:' + info.contact_email : '')) hasDetails = true;
    if (addSponsorDetailRow(sponsorDetailsNode, 'fa-brands fa-facebook', info.facebook_url ? 'Facebook' : '', info.facebook_url)) hasDetails = true;
    if (addSponsorDetailRow(sponsorDetailsNode, 'fa-brands fa-instagram', info.instagram_url ? 'Instagram' : '', info.instagram_url)) hasDetails = true;
    if (addSponsorDetailRow(sponsorDetailsNode, 'fa-brands fa-x-twitter', info.twitter_url ? 'Twitter / X' : '', info.twitter_url)) hasDetails = true;

    sponsorEmptyNode.classList.toggle('d-none', hasDetails);
    if (!sponsorModal) sponsorModal = new bootstrap.Modal(sponsorModalEl);
    sponsorModal.show();
  });

  function updateSection(section) {
    if (!section) return;
    const count = section.querySelectorAll('[data-player-card]').length;
    const countNode = section.querySelector('[data-section-count]');
    const pluralNode = section.querySelector('[data-section-plural]');
    const emptyState = section.querySelector('[data-empty-state]');
    if (countNode) countNode.textContent = String(count);
    if (pluralNode) pluralNode.textContent = count === 1 ? '' : 's';
    if (emptyState) emptyState.classList.toggle('is-visible', count === 0);
  }

  function updateCounts() {
    updateSection(squadSection);
  }

  function setStatus(card, message, isError = false) {
    const status = card.querySelector('[data-upload-status]');
    if (!status) return;
    status.textContent = message;
    status.style.color = isError ? '#c92a2a' : '#1f6f3a';
  }

  function updateCardPhoto(card, avatarUrl) {
    const photo = card.querySelector('[data-player-photo]');
    if (!photo) return;
    const name = card.querySelector('.player-reference-name span')?.textContent || 'Player';
    photo.innerHTML = '';
    const img = document.createElement('img');
    img.src = `${avatarUrl}${avatarUrl.includes('?') ? '&' : '?'}v=${Date.now()}`;
    img.alt = name;
    img.loading = 'lazy';
    photo.appendChild(img);
  }

  function markCardAccepted(card) {
    card.classList.remove('is-rejected');
    card.classList.add('is-accepted');

    const acceptButton = card.querySelector('[data-accept-photo]');
    if (acceptButton) acceptButton.remove();

    updateCounts();
  }

  async function uploadPlayerPhoto(card, file) {
    if (!file || !file.type.startsWith('image/')) {
      setStatus(card, 'Drop an image file.', true);
      return;
    }

    const body = new FormData();
    body.append('csrf_token', csrfToken);
    body.append('player_id', card.dataset.playerId || '');
    body.append('avatar', file);

    card.classList.add('is-uploading');
    setStatus(card, 'Uploading...');

    try {
      const response = await fetch('/admin/player_reference_avatar_upload.php', {
        method: 'POST',
        credentials: 'same-origin',
        body
      });
      const result = await response.json().catch(() => null);
      if (!response.ok || !result || !result.success) {
        throw new Error((result && result.error) || 'Upload failed.');
      }

      updateCardPhoto(card, result.avatar_url);
      setStatus(card, 'Profile picture done.');
      markCardAccepted(card);
    } catch (error) {
      setStatus(card, error.message || 'Upload failed.', true);
    } finally {
      card.classList.remove('is-uploading', 'is-dragging');
    }
  }

  async function acceptPlayerPhoto(card, button) {
    button.disabled = true;
    setStatus(card, 'Saving...');

    try {
      const body = new URLSearchParams({ csrf_token: csrfToken, player_id: card.dataset.playerId || '', status: 'accepted' });
      const response = await fetch('/admin/player_photo_review_save.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body
      });
      const result = await response.json().catch(() => null);
      if (!response.ok || !result || !result.success) {
        throw new Error((result && result.error) || 'Could not accept photo.');
      }

      setStatus(card, 'Profile picture accepted.');
      markCardAccepted(card);
    } catch (error) {
      setStatus(card, error.message || 'Could not accept photo.', true);
      button.disabled = false;
    }
  }

  async function setWebsiteStatus(card, toggle, checkbox, uploaded) {
    checkbox.disabled = true;

    try {
      const body = new URLSearchParams({ csrf_token: csrfToken, player_id: card.dataset.playerId || '', uploaded: uploaded ? '1' : '0' });
      const response = await fetch('/admin/player_website_photo_save.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body
      });
      const result = await response.json().catch(() => null);
      if (!response.ok || !result || !result.success) {
        throw new Error((result && result.error) || 'Could not save website status.');
      }

      toggle.classList.toggle('is-on', uploaded);
      const label = toggle.querySelector('[data-website-label]');
      const labelText = uploaded ? 'On website' : 'Not on website';
      if (label) label.textContent = labelText;
      toggle.title = labelText;
      toggle.setAttribute('aria-label', labelText);
    } catch (error) {
      checkbox.checked = !uploaded;
      window.hubToast(error.message || 'Could not save website status.', 'danger');
    } finally {
      checkbox.disabled = false;
    }
  }

  document.querySelectorAll('[data-player-card]').forEach((card) => {
    const display = card.querySelector('[data-position-display]');
    const select = card.querySelector('[data-position-select]');
    const acceptButton = card.querySelector('[data-accept-photo]');
    const websiteToggle = card.querySelector('[data-website-toggle]');
    const websiteCheckbox = card.querySelector('[data-website-checkbox]');

    if (acceptButton) {
      acceptButton.addEventListener('click', () => acceptPlayerPhoto(card, acceptButton));
    }

    if (websiteToggle && websiteCheckbox) {
      websiteCheckbox.addEventListener('change', () => {
        setWebsiteStatus(card, websiteToggle, websiteCheckbox, websiteCheckbox.checked);
      });
    }

    card.addEventListener('dragenter', (event) => {
      event.preventDefault();
      card.classList.add('is-dragging');
    });

    card.addEventListener('dragover', (event) => {
      event.preventDefault();
    });

    card.addEventListener('dragleave', (event) => {
      if (!card.contains(event.relatedTarget)) {
        card.classList.remove('is-dragging');
      }
    });

    card.addEventListener('drop', (event) => {
      event.preventDefault();
      card.classList.remove('is-dragging');
      const file = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files[0] : null;
      uploadPlayerPhoto(card, file);
    });

    if (!display || !select) return;

    display.addEventListener('click', () => {
      display.classList.add('d-none');
      select.classList.remove('d-none');
      select.focus();
    });

    select.addEventListener('change', async () => {
      const position = select.value;
      if (!position) return;
      select.disabled = true;

      try {
        const body = new URLSearchParams({ csrf_token: csrfToken, player_id: card.dataset.playerId || '', position });
        const response = await fetch('/admin/player_position_save.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body
        });
        const result = await response.json().catch(() => null);
        if (!response.ok || !result || !result.success) {
          throw new Error((result && result.error) || 'Could not save position.');
        }
        display.textContent = result.label || position;
        display.classList.remove('d-none');
        select.classList.add('d-none');
      } catch (error) {
        window.hubToast(error.message || 'Could not save position.', 'danger');
      } finally {
        select.disabled = false;
      }
    });
  });
})();
</script>

<?php require __DIR__ . '/footer.php'; ?>
