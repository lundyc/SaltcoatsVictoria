<?php
/** Route: /hospitality/booking/{token} — confirmation page after paying. */
declare(strict_types=1);

require_once HUB_ROOT . '/lib/hospitality.php';

$pdo = db();
$booking = hospitality_booking_by_token($pdo, (string) ($token ?? ''));
set_meta(['title' => 'Your hospitality booking', 'robots' => 'noindex, nofollow']);
if (!$booking) {
    partial('page_hero', ['title' => 'Booking not found']);
    echo '<div class="page"><div class="container"><div class="emptystate"><p>We couldn\'t find that booking.</p><p><a class="btn btn--sm" href="' . e(url('hospitality')) . '">Big Js hospitality</a></p></div></div></div>';
    return;
}
$settings = hospitality_settings($pdo);
$paid = $booking['status'] === 'paid';
$arrive = hospitality_arrival_time($settings, (string) ($booking['kickoff_time'] ?? ''));
$ko = !empty($booking['kickoff_time']) ? format_time((string) $booking['kickoff_time']) : 'TBC';
if (!$paid && $booking['status'] === 'pending_payment' && isset($_GET['paid'])) {
    header('Refresh: 4'); // the payment confirmation can arrive a few seconds after the redirect
}
?>
<?php seo_breadcrumbs([['Commercial', url('commercial')], ['Hospitality', url('hospitality')], ['Your booking', url('hospitality')]]); ?>
<?php partial('page_hero', [
    'eyebrow' => 'Big Js hospitality',
    'title'   => $paid ? 'You\'re booked in!' : 'Confirming your booking…',
    'sub'     => $paid ? 'Thank you. A confirmation has been emailed to ' . $booking['contact_email'] . '.' : ($booking['status'] === 'pending_payment' ? 'Waiting for your payment to be confirmed. This page will refresh.' : 'This booking was not completed.'),
]); ?>
<div class="page">
  <div class="container">
    <aside class="osummary psthanks">
      <h2>Booking #<?= (int) $booking['id'] ?></h2>
      <div class="osummary__row"><span>Game</span><span>Saltcoats Victoria v <?= e((string) $booking['opponent']) ?></span></div>
      <div class="osummary__row"><span>Date</span><span><?= e(date('l j F Y', strtotime((string) $booking['match_date']))) ?></span></div>
      <div class="osummary__row"><span>Kick-off</span><span><?= e($ko) ?></span></div>
      <div class="osummary__row"><span>Arrive from</span><span><?= $arrive !== '' ? e($arrive) : '1 hour 30 minutes before kick-off' ?></span></div>
      <div class="osummary__row"><span>Places</span><span><?= (int) $booking['guests'] ?> (<?= e((string) $booking['booking_type']) ?>)</span></div>
      <div class="osummary__row osummary__row--total"><span>Total</span><span><?= e(gbp((float) $booking['total_amount'])) ?></span></div>
    </aside>
    <?php if ($paid): ?>
      <div class="notice notice--ok psthanks"><p>Find us at the <strong>Big Js hospitality suite</strong>, <?= e(club('ground_name', 'Campbell Park')) ?>. Bookings are non-refundable; if anything changes for your party, please <a href="<?= e(url('contact')) ?>">contact the club</a>.</p></div>
    <?php endif; ?>
    <p class="psthanks"><a class="btn btn--ghost" href="<?= e(url('hospitality')) ?>">Back to hospitality</a></p>
  </div>
</div>
