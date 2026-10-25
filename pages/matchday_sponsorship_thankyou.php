<?php
/** Route: /matchday-sponsorship/thankyou?access=<token> — request received. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/match_sponsorship_shop.php';

$pdo = db();
match_sponsorship_ensure_schema($pdo);
$order = isset($_GET['access']) ? match_sponsorship_get_order_by_token($pdo, (string) $_GET['access']) : null;
$generic = isset($_GET['sent']) && !$order;
set_meta(['title' => 'Thank you', 'robots' => 'noindex, nofollow']);
if (!$order && !$generic) {
    partial('page_hero', ['title' => 'Request not found']);
    echo '<div class="page"><div class="container"><div class="emptystate"><p>We couldn\'t find that request.</p><p><a class="btn btn--sm" href="' . e(url('matchday-sponsorship')) . '">Matchday sponsorship</a></p></div></div></div>';
    return;
}
$status = $order ? (string) $order['status'] : 'enquiry';
$items = $order ? match_sponsorship_get_items($pdo, (int) $order['id']) : [];
$paid = in_array($status, ['paid', 'partially_conflicted'], true);
$title = $paid ? 'Thank you!' : ($status === 'approved' ? 'Your request is approved' : ($status === 'declined' ? 'About your request' : 'Request received'));
$sub = $paid ? 'A receipt is on its way to ' . $order['buyer_email'] . '.'
    : ($status === 'approved' ? 'Check your email for a secure link to pay.' : ($status === 'declined' ? 'The club was not able to go ahead with this request.' : 'We will be in touch within 3 working days. Nothing has been charged.'));
?>
<?php seo_breadcrumbs([['Commercial', url('commercial')], ['Matchday sponsorship', url('matchday-sponsorship')], ['Request received', url('matchday-sponsorship/thankyou')]]); ?>
<?php partial('page_hero', ['eyebrow' => 'Matchday & match ball sponsorship', 'title' => $title, 'sub' => $sub]); ?>
<div class="page">
  <div class="container">
    <?php if ($items): ?>
    <aside class="osummary psthanks">
      <h2>Your request</h2>
      <?php foreach ($items as $it): ?>
        <div class="osummary__row"><span><?= e((string) $it['label']) ?></span><span><?= e(gbp((float) $it['unit_amount'])) ?></span></div>
      <?php endforeach; ?>
      <div class="osummary__row osummary__row--total"><span><?= $status === 'enquiry' ? 'Guide total' : 'Total' ?></span><span><?= e(gbp((float) $order['total_amount'])) ?></span></div>
    </aside>
    <?php endif; ?>
    <?php if ($status === 'enquiry'): ?>
      <div class="notice notice--ok psthanks"><p>Thank you for your interest in sponsoring with <?= e(club('club_name')) ?>. The club checks every request, and we will email you within 3 working days. You will only be asked to pay once your request has been approved.</p></div>
    <?php elseif ($status === 'approved'): ?>
      <div class="notice notice--ok psthanks"><p>Good news &mdash; the club has approved your sponsorship. We have emailed you a link to pay securely by card.</p></div>
    <?php endif; ?>
    <p class="psthanks"><a class="btn btn--ghost" href="<?= e(url('matchday-sponsorship')) ?>">Back to matchday sponsorship</a></p>
  </div>
</div>
