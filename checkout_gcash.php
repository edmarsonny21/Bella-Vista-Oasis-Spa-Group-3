<?php
declare(strict_types=1);
require_once __DIR__ . '/gcash_helpers.php';

$guest = gcashRequireGuest();
$gcashQrUrl = 'assets/images/gcash-qr-code.jpg?v=' . filemtime(__DIR__ . '/assets/images/gcash-qr-code.jpg');
$orderIdValue = $_GET['order_id'] ?? '';
$orderId = is_string($orderIdValue) ? trim($orderIdValue) : '';
$appointment = $orderId !== '' ? gcashFindGuestAppointment($orderId, (int) $guest['id']) : null;
$appointmentsStatement = database()->prepare(
    "SELECT booking_code, booking_date, total_amount
     FROM appointments
     WHERE guest_user_id = :guest_id AND status <> 'cancelled'
     ORDER BY booking_date DESC, created_at DESC"
);
$appointmentsStatement->execute(['guest_id' => $guest['id']]);
$appointments = $appointmentsStatement->fetchAll();
$noticeValue = $_GET['notice'] ?? '';
$notice = is_string($noticeValue) ? $noticeValue : '';
$notices = [
    'submitted' => 'Your payment details were submitted and are pending verification.',
    'duplicate' => 'That GCash reference number has already been submitted. Check your payment status or enter the correct reference number.',
    'pending' => 'A payment for this booking is already awaiting verification.',
    'invalid' => 'We could not submit that payment. Check the booking and receipt details, then try again.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pay by GCash | Bella Vista Oasis Spa</title>
  <link rel="stylesheet" href="assets/css/gcash.css?v=<?= filemtime(__DIR__ . '/assets/css/gcash.css') ?>">
</head>
<body>
  <main class="gcash-shell">
    <header class="gcash-header">
      <a class="brand-link" href="./">Bella Vista Oasis Spa</a>
      <nav><a href="order_status.php">View payment status</a><a href="./">Back to home</a></nav>
    </header>
    <section class="gcash-card">
      <p class="eyebrow">Secure manual payment</p>
      <h1>Pay with GCash</h1>
      <p class="intro">Scan the QR code in your GCash app, pay the exact amount, then submit your reference number and receipt for staff verification.</p>
      <?php if (isset($notices[$notice])): ?>
        <p class="notice <?= $notice === 'submitted' ? 'success' : 'error' ?>" role="status"><?= gcashEscape($notices[$notice]) ?></p>
      <?php endif; ?>

      <?php if (!$appointments): ?>
        <p class="notice">There are no active bookings available for payment on this account.</p>
      <?php elseif (!$appointment): ?>
        <form class="select-booking" method="get" action="checkout_gcash.php">
          <label for="order-id">Choose your booking</label>
          <select id="order-id" name="order_id" required>
            <option value="">Select a booking</option>
            <?php foreach ($appointments as $item): ?>
              <option value="<?= gcashEscape($item['booking_code']) ?>"><?= gcashEscape($item['booking_code']) ?> · <?= gcashEscape($item['booking_date']) ?> · <?= gcashEscape(gcashMoney($item['total_amount'])) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="button" type="submit">Continue to payment</button>
        </form>
      <?php else: ?>
        <?php
        $latestPaymentStatement = database()->prepare(
            'SELECT payment_status FROM payments
             WHERE appointment_id = :appointment_id
             ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $latestPaymentStatement->execute(['appointment_id' => $appointment['id']]);
        $latestPaymentStatus = $latestPaymentStatement->fetchColumn() ?: null;
        ?>
        <div class="order-summary">
          <span>Booking</span><strong><?= gcashEscape($appointment['booking_code']) ?></strong>
          <span>Guest</span><strong><?= gcashEscape($appointment['guest_name']) ?></strong>
          <span>Amount due</span><strong class="amount"><?= gcashEscape(gcashMoney($appointment['total_amount'])) ?></strong>
        </div>
        <?php if ($latestPaymentStatus === 'Verified'): ?>
          <p class="notice success">This booking has already been paid and verified. View your <a href="order_status.php?order_id=<?= rawurlencode($appointment['booking_code']) ?>">payment status</a>.</p>
        <?php elseif ($latestPaymentStatus === 'Pending Verification'): ?>
          <p class="notice">A payment for this booking is already awaiting verification. Check your <a href="order_status.php?order_id=<?= rawurlencode($appointment['booking_code']) ?>">payment status</a>.</p>
        <?php else: ?>
          <div class="payment-instructions">
            <img class="qr-code" src="<?= gcashEscape($gcashQrUrl) ?>" alt="Bella Vista GCash QR code">
            <div class="account-details">
              <h2>Verify the recipient</h2>
              <p><span>Account Name</span><strong>ED**R SO**Y P.</strong></p>
              <p><span>Mobile No.</span><strong>+63 997 695 ••••</strong></p>
              <p><span>User ID</span><strong>••••••••••WP8W1O</strong></p>
            </div>
          </div>
          <form class="payment-form" method="post" action="submit_gcash.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= gcashEscape(gcashCsrfToken()) ?>">
            <input type="hidden" name="order_id" value="<?= gcashEscape($appointment['booking_code']) ?>">
            <label for="reference">13-digit GCash Reference Number</label>
            <input id="reference" name="gcash_ref_number" type="text" inputmode="numeric" pattern="[0-9]{13}" minlength="13" maxlength="13" autocomplete="off" placeholder="Enter 13 digits" required>
            <label for="receipt">GCash receipt screenshot <small>JPG or PNG, up to 5 MB</small></label>
            <input id="receipt" name="receipt_image" type="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
            <button class="button" type="submit">Submit for verification</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>
