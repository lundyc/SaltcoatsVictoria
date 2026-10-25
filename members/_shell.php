<?php
/** Members area shell: wraps a captured page body in the public-site chrome
 *  (head, header, nav, footer) and adds the "My Vics" sub-navigation bar. */
declare(strict_types=1);

/** Pull the page's own "member-hero" block out of the body so it can be re-rendered as the
 *  full-width site page hero. @return array{0:string,1:array{eyebrow:string,title:string,sub:string,actions:string}} */
function member_shell_extract_hero(string $body, string $fallbackTitle): array
{
    $hero = ['eyebrow' => '', 'title' => $fallbackTitle, 'sub' => '', 'actions' => ''];
    if (preg_match('#<section class="member-hero">(.*?)</section>#s', $body, $m, PREG_OFFSET_CAPTURE)) {
        $inner = $m[1][0];
        if (preg_match('#<div class="member-hero__eyebrow">(.*?)</div>#s', $inner, $x)) {
            $hero['eyebrow'] = html_entity_decode(trim(strip_tags($x[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('#<h1[^>]*>(.*?)</h1>#s', $inner, $x)) {
            $hero['title'] = html_entity_decode(trim(strip_tags($x[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('#<p[^>]*>(.*?)</p>#s', $inner, $x)) {
            $hero['sub'] = html_entity_decode(trim(strip_tags($x[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (preg_match('#<div class="member-hero__actions">(.*?)</div>#s', $inner, $x)) {
            $hero['actions'] = $x[1];
        }
        $body = substr($body, 0, $m[0][1]) . substr($body, $m[0][1] + strlen($m[0][0]));
    }
    return [$body, $hero];
}

function member_shell_render(string $body): void
{
    global $currentHolder, $memberHasSeasonTicket, $memberTier, $memberPageTitle, $currentScript;

    set_meta(['title' => $memberPageTitle, 'robots' => 'noindex, nofollow']);
    [$body, $hero] = member_shell_extract_hero($body, (string) $memberPageTitle);

    $is = static fn (array $pages): string => in_array($currentScript, $pages, true) ? ' is-active' : '';
    $memberName = (string) ($currentHolder['name'] ?? '');
    $roleLabel = ((int) ($memberTier ?? 0)) >= 3 ? 'Administrator' : (((int) ($memberTier ?? 0)) === 2 ? 'Club staff' : 'Season ticket holder');

    header('Cache-Control: no-store, no-cache, must-revalidate');
    ob_start();
    require PUBLIC_ROOT . '/partials/head.php';
    ?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.1/css/all.min.css" rel="stylesheet">
<section class="pagehero pagehero--members">
  <div class="container">
    <?php if ($hero['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($hero['eyebrow']) ?></span><?php endif; ?>
    <h1><?= e($hero['title']) ?></h1>
    <?php if ($hero['sub'] !== ''): ?><p class="pagehero__sub"><?= e($hero['sub']) ?></p><?php endif; ?>
    <?php if ($hero['actions'] !== ''): ?><div class="pagehero__actions"><?= $hero['actions'] ?></div><?php endif; ?>
  </div>
</section>

<div class="page mbr">
  <div class="container mbr__layout">
    <?php if ($currentHolder): ?>
    <aside class="mbrside" aria-label="Members area">
      <div class="mbrside__me">
        <span class="mbrside__avatar"><?= !empty($currentHolder['profile_image_path']) ? '<img src="/' . e((string) $currentHolder['profile_image_path']) . '" alt="">' : e(member_initials($memberName)) ?></span>
        <span><strong><?= e($memberName) ?></strong><small><?= e($roleLabel) ?></small></span>
      </div>
      <nav class="mbrside__nav">
        <span class="mbrside__label">My Vics</span>
        <a class="<?= trim($is(['index.php'])) ?>" href="/members/index.php"><i class="fa-solid fa-house" aria-hidden="true"></i>Account</a>
        <a class="<?= trim($is(['ticket.php'])) ?>" href="/members/ticket.php"><i class="fa-solid fa-qrcode" aria-hidden="true"></i>Digital ticket</a>
        <span class="mbrside__label">Club</span>
        <a class="<?= trim($is(['matches.php', 'match.php'])) ?>" href="/members/matches.php"><i class="fa-solid fa-futbol" aria-hidden="true"></i>Matches</a>
        <a class="<?= trim($is(['orders.php'])) ?>" href="/members/orders.php"><i class="fa-solid fa-ticket" aria-hidden="true"></i>Match tickets</a>
        <a class="<?= trim($is(['events.php'])) ?>" href="/members/events.php"><i class="fa-solid fa-champagne-glasses" aria-hidden="true"></i>Events</a>
        <a class="<?= trim($is(['table.php'])) ?>" href="/members/table.php"><i class="fa-solid fa-table" aria-hidden="true"></i>League table</a>
        <?php if ($memberHasSeasonTicket): ?>
          <a class="<?= trim($is(['announcements.php'])) ?>" href="/members/announcements.php"><i class="fa-solid fa-bullhorn" aria-hidden="true"></i>Announcements</a>
          <a class="<?= trim($is(['predictor.php', 'predictor_leaderboard.php'])) ?>" href="/members/predictor.php"><i class="fa-solid fa-bullseye" aria-hidden="true"></i>Score predictor</a>
          <a class="<?= trim($is(['sponsor.php', 'player.php'])) ?>" href="/members/sponsor.php"><i class="fa-solid fa-handshake" aria-hidden="true"></i>Sponsor</a>
          <?php if (defined('HIDDEN_TEAM_TEMPORARILY_DISABLED') && !HIDDEN_TEAM_TEMPORARILY_DISABLED): ?>
            <a class="<?= trim($is(['hidden_team.php'])) ?>" href="/members/hidden_team.php"><i class="fa-solid fa-futbol" aria-hidden="true"></i>Hidden team</a>
          <?php endif; ?>
        <?php endif; ?>
        <span class="mbrside__label">Account</span>
        <a class="<?= trim($is(['profile.php'])) ?>" href="/members/profile.php"><i class="fa-solid fa-user-gear" aria-hidden="true"></i>Profile</a>
        <a href="/members/logout.php?all=1"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>Log out</a>
      </nav>
    </aside>
    <?php endif; ?>
    <div class="mbr__content">
<?= $body ?>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<?php
    require PUBLIC_ROOT . '/partials/site_footer.php';
    echo imgopt_filter_html((string) ob_get_clean());
}

/** Shown to a signed-in account that isn't an admin, staff member or season ticket holder. */
function member_shell_locked(): never
{
    global $currentHolder;
    ob_start();
    ?>
<section class="member-hero"><div class="member-hero__content">
  <div class="member-hero__eyebrow">My Vics</div>
  <h1>For season ticket holders</h1>
  <p>This area is exclusive to Saltcoats Victoria season ticket holders.</p>
</div></section>
<div class="mbrlocked">
  <h2>Hi <?= e((string) ($currentHolder['name'] ?? '')) ?>, you&rsquo;re signed in &mdash; but there&rsquo;s no season ticket on this account yet.</h2>
  <p>Season ticket holders get their digital ticket, announcements, the Player of the Match vote, sponsorship options and more, all in one place.</p>
  <div class="mbrlocked__actions">
    <a class="btn btn--lg" href="/season-tickets">Buy a season ticket</a>
    <a class="btn btn--ghost btn--lg" href="/members/logout.php?all=1">Log out</a>
  </div>
  <p class="mbrlocked__note">Just bought one? Payments can take a minute to confirm &mdash; refresh this page shortly. Bought it for someone else or having trouble? <a href="/contact">Contact the club</a>.</p>
</div>
<?php
    member_shell_render((string) ob_get_clean());
    exit;
}

/** Log in / register / reset pages: same public chrome, with the form centred in a card. */
function member_auth_page_open(string $title): void
{
    $GLOBALS['memberAuthTitle'] = $title;
    ob_start();
}

function member_auth_page_close(): void
{
    $body = (string) ob_get_clean();
    set_meta(['title' => (string) ($GLOBALS['memberAuthTitle'] ?? 'My Vics'), 'robots' => 'noindex, nofollow']);
    header('Cache-Control: no-store, no-cache, must-revalidate');
    ob_start();
    require PUBLIC_ROOT . '/partials/head.php';
    ?>
<?php $authTitle = (string) ($GLOBALS['memberAuthTitle'] ?? 'My Vics'); ?>
<section class="pagehero pagehero--members">
  <div class="container">
    <span class="eyebrow">My Vics</span>
    <h1><?= e($authTitle) ?></h1>
    <p class="pagehero__sub">The members area for Saltcoats Victoria season ticket holders.</p>
  </div>
</section>
<div class="page mbr mbr--auth">
  <div class="container">
<?= $body ?>
  </div>
</div>
<?php
    require PUBLIC_ROOT . '/partials/site_footer.php';
    echo imgopt_filter_html((string) ob_get_clean());
}

/** Google "G" mark for the sign-in button. */
function member_google_logo_svg(): string
{
    return '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.5c0-1.6-.1-3.1-.4-4.5H24v9h12.4c-.5 2.9-2.2 5.4-4.6 7.1l7.2 5.6c4.3-4 6.8-9.9 6.8-17.2z"/><path fill="#FBBC05" d="M10.5 28.7a14.5 14.5 0 0 1 0-9.4l-7.9-6.1a24 24 0 0 0 0 21.6l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.2-5.6c-2 1.4-4.9 2.4-8.7 2.4-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>';
}
