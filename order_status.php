<?php
declare(strict_types=1);
require_once __DIR__ . '/gcash_helpers.php';

$guest = gcashRequireGuest();
$orderIdValue = $_GET['order_id'] ?? '';
$orderId = is_string($orderIdValue) ? trim($orderIdValue) : '';
$appointmentsStatement = database()->prepare(
    "SELECT a.booking_code, a.booking_date, a.total_amount, a.status
     FROM appointments a
     WHERE a.guest_user_id = :guest_id AND a.status <> 'cancelled'
     ORDER BY a.booking_date DESC, a.created_at DESC"
);
$appointmentsStatement->execute(['guest_id' => $guest['id']]);
$appointments = $appointmentsStatement->fetchAll();
$selected = null;
if ($orderId !== '') {
    $selected = gcashFindGuestAppointment($orderId, (int) $guest['id']);
}
$payment = null;
if ($selected) {
    $paymentStatement = database()->prepare(
        'SELECT payment_status, amount, gcash_ref_number, rejection_reason, created_at, verified_at
         FROM payments WHERE appointment_id = :appointment_id
         ORDER BY created_at DESC, id DESC LIMIT 1'
    );
    $paymentStatement->execute(['appointment_id' => $selected['id']]);
    $payment = $paymentStatement->fetch() ?: null;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Payment Status | Bella Vista Oasis Spa</title>
  <link rel="stylesheet" href="assets/css/gcash.css?v=<?= filemtime(__DIR__ . '/assets/css/gcash.css') ?>">
</head>
<body>
  <main class="gcash-shell">
    <header class="gcash-header">
      <a class="brand-link" href="./">Bella Vista Oasis Spa</a>
      <nav><a href="checkout_gcash.php">Pay by GCash</a><a href="./">Back to home</a></nav>
    </header>
    <section class="gcash-card status-card">
      <p class="eyebrow">Guest account</p>
      <h1>Payment status</h1>
      <?php if (!$appointments): ?>
        <p class="empty-state">There are no active bookings linked to your account.</p>
      <?php else: ?>
        <form class="select-booking" method="get" action="order_status.php">
          <label for="order-id">Choose your booking</label>
          <select id="order-id" name="order_id" required>
            <option value="">Select a booking</option>
            <?php foreach ($appointments as $item): ?>
              <option value="<?= gcashEscape($item['booking_code']) ?>" <?= $orderId === $item['booking_code'] ? 'selected' : '' ?>><?= gcashEscape($item['booking_code']) ?> · <?= gcashEscape($item['booking_date']) ?> · <?= gcashEscape(gcashMoney($item['total_amount'])) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="button" type="submit">View status</button>
        </form>
        <?php if ($selected): ?>
          <div class="status-panel">
            <div class="order-summary">
              <span>Booking</span><strong><?= gcashEscape($selected['booking_code']) ?></strong>
              <span>Reservation status</span><strong><?= gcashEscape($selected['status'] === 'on_progress' ? 'On Progress' : ucwords(str_replace('_', ' ', $selected['status']))) ?></strong>
              <span>Amount due</span><strong><?= gcashEscape(gcashMoney($selected['total_amount'])) ?></strong>
            </div>
            <?php if (!$payment): ?>
              <p class="status-badge not-submitted">No payment submitted</p>
              <p>Submit your GCash payment details to begin verification.</p>
              <a class="button" href="checkout_gcash.php?order_id=<?= rawurlencode($selected['booking_code']) ?>">Continue to GCash payment</a>
            <?php else: ?>
              <p class="status-badge <?= $payment['payment_status'] === 'Verified' ? 'verified' : ($payment['payment_status'] === 'Rejected' ? 'rejected' : 'pending') ?>"><?= gcashEscape($payment['payment_status']) ?></p>
              <p>Reference number: <strong><?= gcashEscape($payment['gcash_ref_number']) ?></strong></p>
              <?php if ($payment['payment_status'] === 'Rejected'): ?>
                <p class="notice error">Reason: <?= gcashEscape($payment['rejection_reason'] ?: 'Please contact the spa for details.') ?></p>
                <a class="button" href="checkout_gcash.php?order_id=<?= rawurlencode($selected['booking_code']) ?>">Submit payment again</a>
              <?php elseif ($payment['payment_status'] === 'Verified'): ?>
                <p class="notice success">Your payment was verified. Thank you.</p>
                <a class="button" href="payment_receipt.php?order_id=<?= rawurlencode($selected['booking_code']) ?>">Print payment confirmation</a>
              <?php else: ?>
                <p>Your payment receipt is being reviewed by our staff. This page will show the result once it has been checked.</p>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
