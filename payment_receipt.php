<?php
declare(strict_types=1);
require_once __DIR__ . '/gcash_helpers.php';

startManagerSession();
$guest = null;
$manager = isset($_SESSION['manager_id']);
if (!$manager) {
    $guest = gcashRequireGuest();
} else {
    gcashRequireManager();
}

$orderIdValue = $_GET['order_id'] ?? '';
$orderId = is_string($orderIdValue) ? trim($orderIdValue) : '';
if (!preg_match('/^[A-Za-z0-9-]{1,30}$/', $orderId)) {
    http_response_code(404);
    exit('Payment receipt not found.');
}
$query = "SELECT a.booking_code, a.guest_name, a.booking_date, a.start_time, a.end_time,
                 s.name AS service_name, p.amount, p.payment_status, p.verified_at
          FROM appointments a
          JOIN services s ON s.id = a.service_id
          JOIN payments p ON p.appointment_id = a.id
          WHERE a.booking_code = :booking_code AND p.payment_status = 'Verified'";
$parameters = ['booking_code' => $orderId];
if (!$manager) {
    $query .= ' AND a.guest_user_id = :guest_id';
    $parameters['guest_id'] = (int) $guest['id'];
}
$query .= ' ORDER BY p.verified_at DESC, p.id DESC LIMIT 1';
$statement = database()->prepare($query);
$statement->execute($parameters);
$receipt = $statement->fetch();
if (!$receipt) {
    http_response_code(404);
    exit('Verified payment receipt not found.');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Payment Confirmation | Bella Vista Oasis Spa</title>
  <link rel="stylesheet" href="assets/css/gcash.css?v=<?= filemtime(__DIR__ . '/assets/css/gcash.css') ?>">
</head>
<body class="payment-receipt-page">
  <main class="gcash-shell">
    <article class="gcash-card payment-receipt">
      <p class="eyebrow">Official payment confirmation</p>
      <h1>Bella Vista Oasis Spa</h1>
      <p class="receipt-confirmation">Payment confirmed. Thank you for choosing Bella Vista Oasis Spa.</p>
      <div class="order-summary">
        <span>Booking reference</span><strong><?= gcashEscape($receipt['booking_code']) ?></strong>
        <span>Guest name</span><strong><?= gcashEscape($receipt['guest_name']) ?></strong>
        <span>Treatment</span><strong><?= gcashEscape($receipt['service_name']) ?></strong>
        <span>Date &amp; time</span><strong><?= gcashEscape($receipt['booking_date']) ?> · <?= gcashEscape(substr((string) $receipt['start_time'], 0, 5)) ?>–<?= gcashEscape(substr((string) $receipt['end_time'], 0, 5)) ?></strong>
        <span>Amount paid</span><strong class="amount"><?= gcashEscape(gcashMoney($receipt['amount'])) ?></strong>
        <span>GCash verification</span><strong><?= gcashEscape($receipt['payment_status']) ?></strong>
      </div>
      <p class="receipt-confirmation">This receipt confirms that your GCash payment has been verified.</p>
      <button class="button receipt-print-button" type="button" onclick="window.print()">Print confirmation</button>
    </article>
  </main>
</body>
</html>
