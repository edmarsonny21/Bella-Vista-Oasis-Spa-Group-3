<?php
declare(strict_types=1);
require_once __DIR__ . '/gcash_helpers.php';

$managerId = gcashRequireManager();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    gcashRequireCsrf($_POST['csrf_token'] ?? null);
    $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
    $actionValue = $_POST['action'] ?? '';
    $action = is_string($actionValue) ? $actionValue : '';
    if (!$paymentId || !in_array($action, ['approve', 'reject'], true)) {
        http_response_code(422);
        exit('Choose a valid payment and action.');
    }

    $pdo = database();
    if ($action === 'approve') {
        try {
            $pdo->beginTransaction();
            $paymentStatement = $pdo->prepare(
                "SELECT p.appointment_id, a.status AS appointment_status
                 FROM payments p
                 JOIN appointments a ON a.id = p.appointment_id
                 WHERE p.id = :payment_id AND p.payment_status = 'Pending Verification'
                 LIMIT 1 FOR UPDATE"
            );
            $paymentStatement->execute(['payment_id' => $paymentId]);
            $payment = $paymentStatement->fetch();
            if (!$payment) {
                $pdo->rollBack();
                $result = 'already-reviewed';
            } elseif (!in_array($payment['appointment_status'], ['on_progress', 'confirmed'], true)) {
                $pdo->rollBack();
                $result = 'booking-not-eligible';
            } else {
                $updatePayment = $pdo->prepare(
                    "UPDATE payments
                     SET payment_status = 'Verified', rejection_reason = NULL,
                         verified_by = :manager_id, verified_at = NOW()
                     WHERE id = :payment_id AND payment_status = 'Pending Verification'"
                );
                $updatePayment->execute(['manager_id' => $managerId, 'payment_id' => $paymentId]);
                if ($payment['appointment_status'] === 'on_progress') {
                    $confirmAppointment = $pdo->prepare(
                        "UPDATE appointments SET status = 'confirmed'
                         WHERE id = :appointment_id AND status = 'on_progress'"
                    );
                    $confirmAppointment->execute(['appointment_id' => $payment['appointment_id']]);
                    if ($confirmAppointment->rowCount() !== 1) {
                        $pdo->rollBack();
                        error_log('A reservation changed status during GCash payment approval.');
                        http_response_code(409);
                        exit('The reservation changed while the payment was being approved. Refresh and try again.');
                    }
                }
                $pdo->commit();
                $result = 'approved';
            }
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($exception->getMessage());
            http_response_code(500);
            exit('The payment could not be approved and its reservation was not changed.');
        }
    } else {
        $reasonValue = $_POST['rejection_reason'] ?? '';
        $reason = is_string($reasonValue) ? trim($reasonValue) : '';
        if ($reason === '' || mb_strlen($reason) > 255) {
            http_response_code(422);
            exit('Enter a rejection reason of 1 to 255 characters.');
        }
        $update = $pdo->prepare(
            "UPDATE payments
             SET payment_status = 'Rejected', rejection_reason = :reason,
                 verified_by = :manager_id, verified_at = NULL
             WHERE id = :payment_id AND payment_status = 'Pending Verification'"
        );
        $update->execute(['reason' => $reason, 'manager_id' => $managerId, 'payment_id' => $paymentId]);
        $result = $update->rowCount() === 1 ? 'updated' : 'already-reviewed';
    }

    header('Location: admin_payments.php?result=' . $result, true, 303);
    exit;
}

$payments = database()->query(
    "SELECT id, order_id, customer_name, amount, gcash_ref_number, receipt_image, created_at
     FROM payments
     WHERE payment_status = 'Pending Verification'
     ORDER BY created_at ASC"
)->fetchAll();
$result = (string) ($_GET['result'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>GCash Payment Review | Bella Vista Oasis Spa</title>
  <link rel="stylesheet" href="assets/css/gcash.css?v=<?= filemtime(__DIR__ . '/assets/css/gcash.css') ?>">
</head>
<body>
  <main class="gcash-shell admin-shell">
    <header class="gcash-header">
      <a class="brand-link" href="manager">Bella Vista Oasis Spa · Manager</a>
      <nav><a href="manager">Manager dashboard</a></nav>
    </header>
    <section class="gcash-card admin-card">
      <p class="eyebrow">Manual GCash reconciliation</p>
      <h1>Payment verification</h1>
      <?php if ($result === 'approved'): ?><p class="notice success" role="status">Payment approved and reservation confirmed.</p><?php endif; ?>
      <?php if ($result === 'updated'): ?><p class="notice success" role="status">Payment rejection saved.</p><?php endif; ?>
      <?php if ($result === 'already-reviewed'): ?><p class="notice" role="status">That payment was already reviewed or is no longer pending.</p><?php endif; ?>
      <?php if ($result === 'booking-not-eligible'): ?><p class="notice error" role="alert">This reservation is cancelled or already completed, so the payment cannot automatically confirm it. Reject the payment or resolve the booking first.</p><?php endif; ?>
      <?php if (!$payments): ?>
        <p class="empty-state">There are no payments waiting for verification.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Order ID</th><th>Customer</th><th>Amount due</th><th>GCash reference</th><th>Submitted</th><th>Receipt</th><th>Review</th></tr></thead>
            <tbody>
              <?php foreach ($payments as $payment): ?>
                <tr>
                  <td data-label="Order ID"><strong><?= gcashEscape($payment['order_id']) ?></strong></td>
                  <td data-label="Customer"><?= gcashEscape($payment['customer_name']) ?></td>
                  <td data-label="Amount due"><?= gcashEscape(gcashMoney($payment['amount'])) ?></td>
                  <td data-label="GCash reference"><code><?= gcashEscape($payment['gcash_ref_number']) ?></code></td>
                  <td data-label="Submitted"><?= gcashEscape($payment['created_at']) ?></td>
                  <td data-label="Receipt">
                    <?php if ($payment['receipt_image']): ?>
                      <button class="receipt-button" type="button" data-receipt-url="receipt_image.php?id=<?= (int) $payment['id'] ?>" aria-label="Inspect receipt for <?= gcashEscape($payment['order_id']) ?>"><img src="receipt_image.php?id=<?= (int) $payment['id'] ?>" alt="Uploaded receipt thumbnail"></button>
                    <?php else: ?><span class="muted">No receipt</span><?php endif; ?>
                  </td>
                  <td data-label="Review">
                    <div class="review-actions">
                      <form method="post" action="admin_payments.php">
                        <input type="hidden" name="csrf_token" value="<?= gcashEscape(gcashCsrfToken()) ?>">
                        <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                        <input type="hidden" name="action" value="approve">
                        <button class="button approve" type="submit">Approve</button>
                      </form>
                      <form method="post" action="admin_payments.php" data-reject-form>
                        <input type="hidden" name="csrf_token" value="<?= gcashEscape(gcashCsrfToken()) ?>">
                        <input type="hidden" name="payment_id" value="<?= (int) $payment['id'] ?>">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="rejection_reason" value="">
                        <button class="button reject" type="button" data-reject>Reject</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </main>
  <dialog class="receipt-dialog" aria-label="GCash receipt image">
    <button class="dialog-close" type="button" data-close-receipt aria-label="Close receipt">Close</button>
    <img src="" alt="GCash receipt screenshot">
  </dialog>
  <script>
    document.addEventListener("click", (event) => {
      const receiptButton = event.target.closest("[data-receipt-url]");
      if (receiptButton) {
        const dialog = document.querySelector(".receipt-dialog");
        dialog.querySelector("img").src = receiptButton.dataset.receiptUrl;
        dialog.showModal();
      }
      if (event.target.closest("[data-close-receipt]")) {
        document.querySelector(".receipt-dialog").close();
      }
      const rejectButton = event.target.closest("[data-reject]");
      if (rejectButton) {
        const reason = window.prompt("Enter a short rejection reason (for example, Reference number not found or Amount mismatch):");
        if (reason === null || !reason.trim() || reason.trim().length > 255) {
          if (reason !== null) window.alert("Enter a reason of 1 to 255 characters.");
          return;
        }
        const form = rejectButton.closest("form");
        form.querySelector('[name="rejection_reason"]').value = reason.trim();
        form.submit();
      }
    });
    document.querySelector(".receipt-dialog").addEventListener("click", (event) => {
      if (event.target === event.currentTarget) event.currentTarget.close();
    });
  </script>
</body>
</html>
