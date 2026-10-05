<?php

declare(strict_types=1);

const SPONSOR_GRAPHIC_DEFAULT_LOGO_SIZE = 60;
const SPONSOR_GRAPHIC_MIN_LOGO_SIZE = 20;
const SPONSOR_GRAPHIC_MAX_LOGO_SIZE = 300;

const SPONSOR_GRAPHIC_DEFAULT_NAME_SIZE = 29;
const SPONSOR_GRAPHIC_MIN_NAME_SIZE = 14;
const SPONSOR_GRAPHIC_MAX_NAME_SIZE = 60;

const SPONSOR_GRAPHIC_DEFAULT_TEXT_SIZE = 12;
const SPONSOR_GRAPHIC_MIN_TEXT_SIZE = 8;
const SPONSOR_GRAPHIC_MAX_TEXT_SIZE = 28;

function e($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function playerInitials(string $name): string
{
  $name = trim($name);
  if ($name === '') {
    return '?';
  }
  $parts = preg_split('/\s+/', $name) ?: [];
  $initials = '';
  foreach (array_slice($parts, 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
  }
  return $initials !== '' ? $initials : '?';
}

function splitPlayerName(string $name): array
{
  $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
  if ($name === '') {
    return ['', 'Player'];
  }
  $parts = explode(' ', $name, 2);
  $firstName = $parts[0];
  $lastName = $parts[1] ?? '';
  if ($lastName === '') {
    return ['', $firstName];
  }
  return [$firstName, $lastName];
}

// The sponsor logo's on-graphic size and visibility are properties of the
// sponsor itself (sponsors.graphic_logo_size / graphic_logo_hidden), not the
// player/slot it's currently assigned to — so changing them here keeps the
// setting if the sponsor is later moved to a different player or slot. This
// matters for sponsors who are individuals rather than a business: they may
// want their name shown with no logo image at all.
function ensureSponsorGraphicLogoSizeColumn(PDO $pdo): void
{
  static $done = false;
  if ($done) {
    return;
  }
  $column = $pdo->query("SHOW COLUMNS FROM sponsors LIKE 'graphic_logo_size'")->fetch(PDO::FETCH_ASSOC);
  if (!$column) {
    $pdo->exec('ALTER TABLE sponsors ADD COLUMN graphic_logo_size SMALLINT UNSIGNED NULL AFTER logo_path');
  }
  $hiddenColumn = $pdo->query("SHOW COLUMNS FROM sponsors LIKE 'graphic_logo_hidden'")->fetch(PDO::FETCH_ASSOC);
  if (!$hiddenColumn) {
    $pdo->exec('ALTER TABLE sponsors ADD COLUMN graphic_logo_hidden TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER graphic_logo_size');
  }
  // Business sponsors (as opposed to individuals) — see sponsor.php's "This
  // sponsor is a business" toggle. Also ensured here (not just sponsor.php)
  // since buildPlayerSponsorGraphic() below reads these columns directly.
  $businessColumns = [
    'is_business' => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
    'address' => "VARCHAR(255) NULL",
    'website_url' => "VARCHAR(255) NULL",
    'contact_phone' => "VARCHAR(50) NULL",
    'graphic_name_hidden' => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
    'graphic_name_size' => "SMALLINT UNSIGNED NULL",
    'graphic_address_hidden' => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
    'graphic_address_size' => "SMALLINT UNSIGNED NULL",
    'graphic_contact_hidden' => "TINYINT(1) UNSIGNED NOT NULL DEFAULT 0",
    'graphic_contact_size' => "SMALLINT UNSIGNED NULL",
  ];
  foreach ($businessColumns as $column => $definition) {
    $columnStmt = $pdo->query("SHOW COLUMNS FROM sponsors LIKE " . $pdo->quote($column));
    if (!$columnStmt->fetch()) {
      $pdo->exec("ALTER TABLE sponsors ADD COLUMN {$column} {$definition}");
    }
  }
  $done = true;
}

function clampSponsorLogoSize(int $size): int
{
  return max(SPONSOR_GRAPHIC_MIN_LOGO_SIZE, min(SPONSOR_GRAPHIC_MAX_LOGO_SIZE, $size));
}

function clampSponsorNameSize(int $size): int
{
  return max(SPONSOR_GRAPHIC_MIN_NAME_SIZE, min(SPONSOR_GRAPHIC_MAX_NAME_SIZE, $size));
}

function clampSponsorTextSize(int $size): int
{
  return max(SPONSOR_GRAPHIC_MIN_TEXT_SIZE, min(SPONSOR_GRAPHIC_MAX_TEXT_SIZE, $size));
}

// Many sponsor logos are supplied as white-ink-on-transparent artwork, made
// for a dark background. With no coloured row behind them any more, they'd
// be invisible on the cream panel — so we sample the logo's average opaque
// pixel brightness and give light-ink logos a dark card behind them.
const SPONSOR_GRAPHIC_MAX_DARK_CHIP_PIXELS = 4000 * 4000;

function sponsorLogoNeedsDarkChip(string $absoluteLogoPath): bool
{
  if (!is_file($absoluteLogoPath)) {
    return false;
  }

  // imagecreatefromstring() decodes the full bitmap into memory (roughly
  // width * height * 4 bytes for RGBA), which can exceed the PHP memory
  // limit for an unusually large upload and crash the whole page. A quick
  // getimagesize() only reads the header, so bail out cheaply first.
  $dimensions = @getimagesize($absoluteLogoPath);
  if ($dimensions === false || ($dimensions[0] * $dimensions[1]) > SPONSOR_GRAPHIC_MAX_DARK_CHIP_PIXELS) {
    return false;
  }

  $raw = @file_get_contents($absoluteLogoPath);
  $src = $raw !== false ? @imagecreatefromstring($raw) : false;
  if (!$src) {
    return false;
  }

  $width = imagesx($src);
  $height = imagesy($src);
  $stepX = max(1, (int) floor($width / 40));
  $stepY = max(1, (int) floor($height / 40));
  $sum = 0.0;
  $count = 0;
  for ($y = 0; $y < $height; $y += $stepY) {
    for ($x = 0; $x < $width; $x += $stepX) {
      $rgba = imagecolorat($src, $x, $y);
      $alpha = ($rgba >> 24) & 0x7F;
      if ($alpha > 40) {
        continue;
      }
      $r = ($rgba >> 16) & 0xFF;
      $g = ($rgba >> 8) & 0xFF;
      $b = $rgba & 0xFF;
      $sum += (0.299 * $r + 0.587 * $g + 0.114 * $b);
      $count++;
    }
  }
  imagedestroy($src);

  $brightness = $count > 0 ? ($sum / $count) : 128.0;
  return $brightness > 150;
}

function sponsorRowLogo(?string $sponsorName, ?string $logoUrl, ?string $logoAbsolutePath, int $logoSize = SPONSOR_GRAPHIC_DEFAULT_LOGO_SIZE): string
{
  if (!$sponsorName || !$logoUrl) {
    return '';
  }
  $chipClass = 'sponsor-graphic__logo-chip';
  if ($logoAbsolutePath && sponsorLogoNeedsDarkChip($logoAbsolutePath)) {
    $chipClass .= ' sponsor-graphic__logo-chip--dark';
  }
  return '<span class="' . $chipClass . '" style="--logo-size:' . clampSponsorLogoSize($logoSize) . 'px"><img src="' . e($logoUrl) . '" alt="' . e($sponsorName) . '"></span>';
}

// Business sponsors (as opposed to individuals) show their address and
// contact number under their name — see sponsor.php's "This sponsor is a
// business" toggle. The name/address/contact lines can each be independently
// hidden and resized per sponsor — see buildPlayerSponsorGraphic()'s
// $textSettings and the "2. Sponsor image" controls on player_graphics.php.
function sponsorRowDetails(?string $sponsorName, float $price, bool $isBusiness, ?string $address, ?string $contactPhone, array $textSettings): string
{
  if ($sponsorName) {
    $html = '';

    if (empty($textSettings['name_hidden'])) {
      $nameSize = $textSettings['name_size'] ?? null;
      $nameStyle = $nameSize !== null ? ' style="--name-font-size:' . clampSponsorNameSize((int) $nameSize) . 'px"' : '';
      $html .= '<span class="sponsor-graphic__value"' . $nameStyle . '>' . e(mb_strtoupper($sponsorName)) . '</span>';
    }

    if ($isBusiness) {
      $address = trim((string) $address);
      $contactPhone = trim((string) $contactPhone);

      if ($address !== '' && empty($textSettings['address_hidden'])) {
        $addressSize = $textSettings['address_size'] ?? null;
        $addressStyle = $addressSize !== null ? ' style="--address-font-size:' . clampSponsorTextSize((int) $addressSize) . 'px"' : '';
        $html .= '<span class="sponsor-graphic__business-line sponsor-graphic__business-line--address"' . $addressStyle . '>' . e($address) . '</span>';
      }

      if ($contactPhone !== '' && empty($textSettings['contact_hidden'])) {
        $contactSize = $textSettings['contact_size'] ?? null;
        $contactStyle = $contactSize !== null ? ' style="--contact-font-size:' . clampSponsorTextSize((int) $contactSize) . 'px"' : '';
        $html .= '<span class="sponsor-graphic__business-line sponsor-graphic__business-line--contact"' . $contactStyle . '>' . e($contactPhone) . '</span>';
      }
    }

    return $html;
  }
  $priceText = $price > 0 ? gbp($price) : 'Enquire';
  return '<span class="sponsor-graphic__available">Available for sponsorship</span>'
    . '<span class="sponsor-graphic__price">' . e($priceText) . '</span>';
}

// Home/Away sponsor row: logo on the left + details on the right, but only
// when there's actually a logo image to show — with no logo (or the file
// missing from disk) the row collapses to a single centred column so the
// sponsor name isn't left stranded next to empty space.
function sponsorRowMarkup(string $label, string $modifierClass, ?string $sponsorName, ?string $logoUrl, ?string $logoAbsolutePath, float $price, int $logoSize, bool $isBusiness, ?string $address, ?string $contactPhone, array $textSettings): string
{
  $hasLogo = $sponsorName !== null && $logoUrl !== null;
  $rowClass = 'sponsor-graphic__row ' . $modifierClass . ($hasLogo ? '' : ' sponsor-graphic__row--no-logo');

  $html = '<div class="' . $rowClass . '">'
    . '<span class="sponsor-graphic__row-label">' . e($label) . '</span>';
  if ($hasLogo) {
    $html .= '<div class="sponsor-graphic__row-logo">' . sponsorRowLogo($sponsorName, $logoUrl, $logoAbsolutePath, $logoSize) . '</div>';
  }
  $html .= '<div class="sponsor-graphic__row-details">' . sponsorRowDetails($sponsorName, $price, $isBusiness, $address, $contactPhone, $textSettings) . '</div>'
    . '</div>';

  return $html;
}

// Builds the player photo + sponsor panel markup shared by the live editor
// preview and the fragment endpoint used for PNG/ZIP export.
function buildPlayerSponsorGraphic(PDO $pdo, int $playerId, int $seasonId): ?array
{
  ensureSponsorGraphicLogoSizeColumn($pdo);

  $sql = "
  SELECT
    p.id,
    p.name,
    p.avatar,
    s1.id                 AS home_sponsor_id,
    s1.name                AS home_name,
    s1.logo_path            AS home_logo,
    s1.graphic_logo_size    AS home_logo_size,
    s1.graphic_logo_hidden  AS home_logo_hidden,
    s1.is_business          AS home_is_business,
    s1.address              AS home_address,
    s1.contact_phone        AS home_contact_phone,
    s1.graphic_name_hidden    AS home_name_hidden,
    s1.graphic_name_size      AS home_name_size,
    s1.graphic_address_hidden AS home_address_hidden,
    s1.graphic_address_size   AS home_address_size,
    s1.graphic_contact_hidden AS home_contact_hidden,
    s1.graphic_contact_size   AS home_contact_size,
    s2.id                 AS away_sponsor_id,
    s2.name                AS away_name,
    s2.logo_path            AS away_logo,
    s2.graphic_logo_size    AS away_logo_size,
    s2.graphic_logo_hidden  AS away_logo_hidden,
    s2.is_business          AS away_is_business,
    s2.address              AS away_address,
    s2.contact_phone        AS away_contact_phone,
    s2.graphic_name_hidden    AS away_name_hidden,
    s2.graphic_name_size      AS away_name_size,
    s2.graphic_address_hidden AS away_address_hidden,
    s2.graphic_address_size   AS away_address_size,
    s2.graphic_contact_hidden AS away_contact_hidden,
    s2.graphic_contact_size   AS away_contact_size
  FROM players p
  LEFT JOIN sponsorships sp1 ON sp1.player_id = p.id AND sp1.slot = 'home' AND sp1.season_id = :season_id AND sp1.ended_at IS NULL
  LEFT JOIN sponsors s1      ON s1.id = sp1.sponsor_id
  LEFT JOIN sponsorships sp2 ON sp2.player_id = p.id AND sp2.slot = 'away' AND sp2.season_id = :season_id AND sp2.ended_at IS NULL
  LEFT JOIN sponsors s2      ON s2.id = sp2.sponsor_id
  WHERE p.id = :id
  LIMIT 1;
  ";
  $st = $pdo->prepare($sql);
  $st->execute([':id' => $playerId, ':season_id' => $seasonId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    return null;
  }

  $playerName = $row['name'] ?? 'Player';

  $avatarFile = !empty($row['avatar']) ? basename((string)$row['avatar']) : '';
  $hasPhoto = $avatarFile !== '' && is_file(__DIR__ . '/../uploads/players/' . $avatarFile);
  $photoUrl = $hasPhoto ? '/uploads/players/' . rawurlencode($avatarFile) : null;

  $homeSponsorId = !empty($row['home_sponsor_id']) ? (int)$row['home_sponsor_id'] : null;
  $awaySponsorId = !empty($row['away_sponsor_id']) ? (int)$row['away_sponsor_id'] : null;
  $homeSponsorName = $row['home_name'] ?: null;
  $awaySponsorName = $row['away_name'] ?: null;
  $homeLogoSize = $row['home_logo_size'] !== null ? (int)$row['home_logo_size'] : SPONSOR_GRAPHIC_DEFAULT_LOGO_SIZE;
  $awayLogoSize = $row['away_logo_size'] !== null ? (int)$row['away_logo_size'] : SPONSOR_GRAPHIC_DEFAULT_LOGO_SIZE;
  $homeLogoHidden = !empty($row['home_logo_hidden']);
  $awayLogoHidden = !empty($row['away_logo_hidden']);
  $homeIsBusiness = !empty($row['home_is_business']);
  $awayIsBusiness = !empty($row['away_is_business']);
  $homeAddress = $row['home_address'] ?? null;
  $awayAddress = $row['away_address'] ?? null;
  $homeContactPhone = $row['home_contact_phone'] ?? null;
  $awayContactPhone = $row['away_contact_phone'] ?? null;

  $homeTextSettings = [
    'name_hidden' => !empty($row['home_name_hidden']),
    'name_size' => $row['home_name_size'] !== null ? (int)$row['home_name_size'] : null,
    'address_hidden' => !empty($row['home_address_hidden']),
    'address_size' => $row['home_address_size'] !== null ? (int)$row['home_address_size'] : null,
    'contact_hidden' => !empty($row['home_contact_hidden']),
    'contact_size' => $row['home_contact_size'] !== null ? (int)$row['home_contact_size'] : null,
  ];
  $awayTextSettings = [
    'name_hidden' => !empty($row['away_name_hidden']),
    'name_size' => $row['away_name_size'] !== null ? (int)$row['away_name_size'] : null,
    'address_hidden' => !empty($row['away_address_hidden']),
    'address_size' => $row['away_address_size'] !== null ? (int)$row['away_address_size'] : null,
    'contact_hidden' => !empty($row['away_contact_hidden']),
    'contact_size' => $row['away_contact_size'] !== null ? (int)$row['away_contact_size'] : null,
  ];

  $homeLogoFile = !empty($row['home_logo']) ? basename((string)$row['home_logo']) : '';
  $awayLogoFile = !empty($row['away_logo']) ? basename((string)$row['away_logo']) : '';
  $homeLogoPath = $homeLogoFile !== '' ? __DIR__ . '/../uploads/sponsors/' . $homeLogoFile : null;
  $awayLogoPath = $awayLogoFile !== '' ? __DIR__ . '/../uploads/sponsors/' . $awayLogoFile : null;
  $homeLogoUrl = ($homeLogoPath && is_file($homeLogoPath) && !$homeLogoHidden) ? '/uploads/sponsors/' . rawurlencode($homeLogoFile) : null;
  $awayLogoUrl = ($awayLogoPath && is_file($awayLogoPath) && !$awayLogoHidden) ? '/uploads/sponsors/' . rawurlencode($awayLogoFile) : null;
  $homeHasLogo = $homeLogoPath !== null && is_file($homeLogoPath);
  $awayHasLogo = $awayLogoPath !== null && is_file($awayLogoPath);

  $homePackage = getSponsorshipPackageByCode($pdo, 'player_home');
  $awayPackage = getSponsorshipPackageByCode($pdo, 'player_away');
  $homePrice = $homePackage ? (float)$homePackage['amount'] : 0.0;
  $awayPrice = $awayPackage ? (float)$awayPackage['amount'] : 0.0;

  // When the same sponsor holds both slots, show them once — a single row
  // on the right-hand side that fills the combined space of the separate
  // home/away rows. The name block above stays the same size.
  $isMergedSponsor = $homeSponsorId !== null && $homeSponsorId === $awaySponsorId;

  [$firstName, $lastName] = splitPlayerName($playerName);

  $photoInner = $photoUrl ? '' : '<span class="sponsor-graphic__initials">' . e(playerInitials($playerName)) . '</span>';

  $nameRowClass = 'sponsor-graphic__row sponsor-graphic__row--name';

  $panelSponsorRows = $isMergedSponsor
    ? sponsorRowMarkup('Proudly sponsored by', 'sponsor-graphic__row--merged', $homeSponsorName, $homeLogoUrl, $homeLogoPath, $homePrice, $homeLogoSize, $homeIsBusiness, $homeAddress, $homeContactPhone, $homeTextSettings)
    : sponsorRowMarkup('Home Sponsor', 'sponsor-graphic__row--home', $homeSponsorName, $homeLogoUrl, $homeLogoPath, $homePrice, $homeLogoSize, $homeIsBusiness, $homeAddress, $homeContactPhone, $homeTextSettings)
      . sponsorRowMarkup('Away Sponsor', 'sponsor-graphic__row--away', $awaySponsorName, $awayLogoUrl, $awayLogoPath, $awayPrice, $awayLogoSize, $awayIsBusiness, $awayAddress, $awayContactPhone, $awayTextSettings);

  $html = '<div class="sponsor-graphic__photo"' . ($photoUrl ? ' style="background-image:url(\'' . e($photoUrl) . '\')"' : '') . '>'
    . $photoInner
    . '</div>'
    . '<div class="sponsor-graphic__panel">'
    . '<div class="' . $nameRowClass . '">'
    . '<img class="sponsor-graphic__badge" src="/Saltcoats Victoria FC -White_Transparent.png" alt="Club badge">'
    . '<div class="sponsor-graphic__name">'
    . ($firstName !== '' ? '<span class="sponsor-graphic__firstname">' . e($firstName) . '</span>' : '')
    . '<span class="sponsor-graphic__surname">' . e($lastName) . '</span>'
    . '</div>'
    . '</div>'
    . $panelSponsorRows
    . '</div>';

  return [
    'name' => $playerName,
    'hasPhoto' => $hasPhoto,
    'homeSponsorId' => $homeSponsorId,
    'awaySponsorId' => $awaySponsorId,
    'homeSponsorName' => $homeSponsorName,
    'awaySponsorName' => $awaySponsorName,
    'homeLogoSize' => $homeLogoSize,
    'awayLogoSize' => $awayLogoSize,
    'homeLogoHidden' => $homeLogoHidden,
    'awayLogoHidden' => $awayLogoHidden,
    'homeHasLogo' => $homeHasLogo,
    'awayHasLogo' => $awayHasLogo,
    'homeIsBusiness' => $homeIsBusiness,
    'awayIsBusiness' => $awayIsBusiness,
    'homeAddress' => $homeAddress,
    'awayAddress' => $awayAddress,
    'homeContactPhone' => $homeContactPhone,
    'awayContactPhone' => $awayContactPhone,
    'homeTextSettings' => $homeTextSettings,
    'awayTextSettings' => $awayTextSettings,
    'html' => $html,
  ];
}
