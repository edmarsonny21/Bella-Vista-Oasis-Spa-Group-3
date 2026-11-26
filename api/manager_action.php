<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireManager();
requireMethod('POST');

$input = requestJson();
$action = $input['action'] ?? '';

if ($action === 'day_off_status') {
    requireManagerCsrf($input['csrfToken'] ?? null);
    $dayOffId = filter_var($input['dayOffId'] ?? null, FILTER_VALIDATE_INT);
    $status = $input['status'] ?? '';
    if (!$dayOffId || !in_array($status, ['approved', 'rejected'], true)) {
        sendJson(['message' => 'Choose a valid day-off request and decision.'], 422);
    }
    $statement = database()->prepare(
        "UPDATE therapist_day_offs SET status = ?
         WHERE id = ? AND status = 'requested'"
    );
    $statement->execute([$status, $dayOffId]);
    if ($statement->rowCount() !== 1) {
        sendJson(['message' => 'That day-off request has already been reviewed. Refresh and try again.'], 409);
    }
    sendJson(['saved' => true]);
}

if (in_array($action, ['delete_filtered', 'delete_all', 'delete_selected'], true)) {
    requireManagerCsrf($input['csrfToken'] ?? null);
    $filter = 'all';
    $selectedAppointmentIds = [];
    if ($action === 'delete_selected') {
        $requestedIds = $input['appointmentIds'] ?? null;
        if (!is_array($requestedIds) || $requestedIds === []) {
            sendJson(['message' => 'Select at least one booking to delete.'], 422);
        }
        foreach ($requestedIds as $requestedId) {
            if (!is_string($requestedId) || !preg_match('/^apt-(\d+)$/', $requestedId, $match)) {
                sendJson(['message' => 'The selected booking list is invalid. Refresh the dashboard and try again.'], 422);
            }
            $selectedAppointmentIds[(int) $match[1]] = true;
        }
    } elseif ($action === 'delete_filtered') {
        $filter = $input['filter'] ?? '';
    }

    $allowedFilters = ['on_progress', 'confirmed', 'in_session', 'cleaning', 'completed', 'cancelled'];
    if ($action === 'delete_filtered' && (!is_string($filter) || !in_array($filter, $allowedFilters, true))) {
        sendJson(['message' => 'Choose a specific booking status to delete.'], 422);
    }

    $pdo = database();
    try {
        $pdo->beginTransaction();
        $appointments = $pdo->query(
            'SELECT id, status, booking_date, start_time, end_time, cleaning_end_time
             FROM appointments FOR UPDATE'
        )->fetchAll();
        $now = new DateTimeImmutable('now');
        $appointmentIds = [];
        foreach ($appointments as $appointment) {
            $appointmentId = (int) $appointment['id'];
            if ($action === 'delete_all' ||
                ($action === 'delete_selected' && isset($selectedAppointmentIds[$appointmentId])) ||
                ($action === 'delete_filtered' && appointmentStatusForDashboard($appointment, $now) === $filter)) {
                $appointmentIds[] = $appointmentId;
            }
        }

        if ($appointmentIds === []) {
            $pdo->commit();
            sendJson(['deleted' => 0, 'filter' => $filter]);
        }

        $placeholders = implode(',', array_fill(0, count($appointmentIds), '?'));
        $receiptStatement = $pdo->prepare(
            "SELECT receipt_image FROM payments WHERE appointment_id IN ($placeholders)"
        );
        $receiptStatement->execute($appointmentIds);
        $receiptPaths = $receiptStatement->fetchAll(PDO::FETCH_COLUMN);

        $deleteStatement = $pdo->prepare("DELETE FROM appointments WHERE id IN ($placeholders)");
        $deleteStatement->execute($appointmentIds);
        $deleted = $deleteStatement->rowCount();
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Manager booking deletion failed: ' . $exception->getMessage());
        sendJson(['message' => 'Bookings could not be deleted. Please try again.'], 500);
    }

    $receiptDirectory = realpath(__DIR__ . '/../storage/gcash-receipts');
    $receiptCleanupFailed = false;
    foreach ($receiptPaths as $receiptPath) {
        if (!is_string($receiptPath) ||
            !preg_match('~^storage/gcash-receipts/[a-f0-9]{48}\.(?:jpg|jpeg|png)$~', $receiptPath) ||
            $receiptDirectory === false) {
            continue;
        }

        $absolutePath = realpath(__DIR__ . '/../' . $receiptPath);
        if ($absolutePath !== false &&
            str_starts_with($absolutePath, $receiptDirectory . DIRECTORY_SEPARATOR) &&
            is_file($absolutePath) &&
            !unlink($absolutePath)) {
            error_log('Could not remove a receipt file after deleting its booking.');
            $receiptCleanupFailed = true;
        }
    }

    sendJson([
        'deleted' => $deleted,
        'filter' => $filter,
        'receiptCleanupWarning' => $receiptCleanupFailed
            ? 'Some receipt files could not be removed. Check the server error log.'
            : null,
    ]);
}

if (in_array($action, ['payment_approve', 'payment_reject'], true)) {
    requireManagerCsrf($input['csrfToken'] ?? null);
    $paymentId = filter_var($input['paymentId'] ?? null, FILTER_VALIDATE_INT);
    $managerId = filter_var($_SESSION['manager_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$paymentId || $paymentId < 1 || !$managerId || $managerId < 1) {
        sendJson(['message' => 'Choose a valid payment.'], 422);
    }

    $pdo = database();
    if ($action === 'payment_approve') {
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
                sendJson(['message' => 'That payment was already reviewed or is no longer pending. Refresh and try again.'], 409);
            }
            if (!in_array($payment['appointment_status'], ['on_progress', 'confirmed'], true)) {
                $pdo->rollBack();
                sendJson(['message' => 'This reservation is cancelled or completed, so the payment cannot automatically confirm it. Resolve the booking before approving.'], 422);
            }

            $updatePayment = $pdo->prepare(
                "UPDATE payments
                 SET payment_status = 'Verified', rejection_reason = NULL,
                     verified_by = :manager_id, verified_at = NOW()
                 WHERE id = :payment_id AND payment_status = 'Pending Verification'"
            );
            $updatePayment->execute(['manager_id' => $managerId, 'payment_id' => $paymentId]);
            if ($updatePayment->rowCount() !== 1) {
                $pdo->rollBack();
                sendJson(['message' => 'That payment was already reviewed. Refresh and try again.'], 409);
            }

            if ($payment['appointment_status'] === 'on_progress') {
                $confirmAppointment = $pdo->prepare(
                    "UPDATE appointments SET status = 'confirmed'
                     WHERE id = :appointment_id AND status = 'on_progress'"
                );
                $confirmAppointment->execute(['appointment_id' => $payment['appointment_id']]);
                if ($confirmAppointment->rowCount() !== 1) {
                    $pdo->rollBack();
                    sendJson(['message' => 'The reservation changed while the payment was being approved. Refresh and try again.'], 409);
                }
            }
            $pdo->commit();
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Manager GCash approval failed: ' . $exception->getMessage());
            sendJson(['message' => 'The payment could not be approved and its reservation was not changed.'], 500);
        }

        sendJson(['saved' => true, 'status' => 'approved']);
    }

    $reasonValue = $input['rejectionReason'] ?? '';
    $reason = is_string($reasonValue) ? trim($reasonValue) : '';
    if ($reason === '' || mb_strlen($reason) > 255) {
        sendJson(['message' => 'Enter a rejection reason of 1 to 255 characters.'], 422);
    }
    try {
        $update = $pdo->prepare(
            "UPDATE payments
             SET payment_status = 'Rejected', rejection_reason = :reason,
                 verified_by = :manager_id, verified_at = NULL
             WHERE id = :payment_id AND payment_status = 'Pending Verification'"
        );
        $update->execute(['reason' => $reason, 'manager_id' => $managerId, 'payment_id' => $paymentId]);
    } catch (PDOException $exception) {
        error_log('Manager GCash rejection failed: ' . $exception->getMessage());
        sendJson(['message' => 'The payment rejection could not be saved. Please try again.'], 500);
    }
    if ($update->rowCount() !== 1) {
        sendJson(['message' => 'That payment was already reviewed or is no longer pending. Refresh and try again.'], 409);
    }
    sendJson(['saved' => true, 'status' => 'rejected']);
}

$appointmentId = $input['appointmentId'] ?? '';
if (!is_string($appointmentId) || !preg_match('/^apt-(\d+)$/', $appointmentId, $match)) {
    sendJson(['message' => 'Choose a valid booking.'], 422);
}

$pdo = database();
if (($input['action'] ?? '') === 'status') {
    $status = $input['status'] ?? '';
    $allowed = ['on_progress', 'confirmed', 'in_session', 'cleaning', 'completed', 'cancelled'];
    if (!in_array($status, $allowed, true)) {
        sendJson(['message' => 'Choose a valid booking status.'], 422);
    }

    $bookingStatement = $pdo->prepare(
        'SELECT status, guest_user_id, booking_date, start_time, end_time, cleaning_end_time
         FROM appointments WHERE id = ?'
    );
    $bookingStatement->execute([(int) $match[1]]);
    $booking = $bookingStatement->fetch();
    if (!$booking) {
        sendJson(['message' => 'Booking not found.'], 404);
    }

    if ($status === 'on_progress' && $booking['status'] !== 'on_progress') {
        if ($booking['status'] !== 'confirmed' || $booking['guest_user_id'] === null) {
            sendJson(['message' => 'Only a confirmed guest reservation can be moved to On Progress for payment review.'], 422);
        }
        $verifiedPayment = $pdo->prepare(
            "SELECT id FROM payments
             WHERE appointment_id = :appointment_id AND payment_status = 'Verified'
             LIMIT 1"
        );
        $verifiedPayment->execute(['appointment_id' => (int) $match[1]]);
        if ($verifiedPayment->fetchColumn()) {
            sendJson(['message' => 'A reservation with a verified payment cannot be moved back to On Progress.'], 422);
        }
    }
    if ($booking['status'] === 'on_progress' && !in_array($status, ['on_progress', 'confirmed', 'cancelled'], true)) {
        sendJson(['message' => 'Set this reservation to confirmed after verifying its GCash payment before updating its treatment status.'], 422);
    }
    if ($status === 'confirmed' && $booking['status'] !== 'confirmed' && $booking['guest_user_id'] !== null) {
        $paymentStatement = $pdo->prepare(
            "SELECT id FROM payments
             WHERE appointment_id = :appointment_id AND payment_status = 'Verified'
             LIMIT 1"
        );
        $paymentStatement->execute(['appointment_id' => (int) $match[1]]);
        if (!$paymentStatement->fetchColumn()) {
            sendJson(['message' => 'Verify this reservation’s GCash payment before setting its status to confirmed.'], 422);
        }
    }

    $today = date('Y-m-d');
    $now = new DateTimeImmutable('now');
    $sessionStart = new DateTimeImmutable($booking['booking_date'] . ' ' . $booking['start_time']);
    $sessionEnd = new DateTimeImmutable($booking['booking_date'] . ' ' . $booking['end_time']);
    $cleaningEnd = new DateTimeImmutable($booking['booking_date'] . ' ' . $booking['cleaning_end_time']);
    if ($status === 'in_session' &&
        ($booking['booking_date'] !== $today || $now < $sessionStart || $now >= $sessionEnd)) {
        sendJson(['message' => 'A booking can only be marked in session during its scheduled treatment time.'], 422);
    }
    if ($status === 'cleaning' &&
        ($booking['booking_date'] !== $today || $now < $sessionEnd || $now >= $cleaningEnd)) {
        sendJson(['message' => 'A booking can only be marked cleaning during its 30-minute post-treatment buffer.'], 422);
    }

    $statement = $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?');
    $statement->execute([$status, (int) $match[1]]);
} elseif (($input['action'] ?? '') === 'therapist') {
    $therapistId = $input['therapistId'] ?? '';
    $numericId = null;
    if ($therapistId !== '') {
        if (!is_string($therapistId) || !preg_match('/^th-(\d+)$/', $therapistId, $therapistMatch)) {
            sendJson(['message' => 'Choose a valid therapist.'], 422);
        }
        $numericId = (int) $therapistMatch[1];
    }
    try {
        $pdo->beginTransaction();
        $bookingStatement = $pdo->prepare(
            'SELECT id, booking_date, start_time, end_time
             FROM appointments WHERE id = ? FOR UPDATE'
        );
        $bookingStatement->execute([(int) $match[1]]);
        $booking = $bookingStatement->fetch();
        if (!$booking) {
            $pdo->rollBack();
            sendJson(['message' => 'Booking not found.'], 404);
        }

        if ($numericId !== null) {
            $therapistStatement = $pdo->prepare('SELECT id FROM therapists WHERE id = ? FOR UPDATE');
            $therapistStatement->execute([$numericId]);
            if (!$therapistStatement->fetch()) {
                $pdo->rollBack();
                sendJson(['message' => 'Therapist not found.'], 404);
            }

            if (!therapistIsAvailable(
                $pdo,
                $numericId,
                (string) $booking['booking_date'],
                (string) $booking['start_time'],
                (string) $booking['end_time']
            )) {
                $pdo->rollBack();
                sendJson(['message' => 'That therapist is off duty or unavailable for this reservation date and time. Choose another therapist.'], 409);
            }

            $conflictStatement = $pdo->prepare(
                "SELECT booking_code FROM appointments
                 WHERE therapist_id = :therapist_id AND booking_date = :booking_date
                   AND status <> 'cancelled' AND id <> :appointment_id
                   AND start_time < :end_time AND end_time > :start_time
                 LIMIT 1"
            );
            $conflictStatement->execute([
                'therapist_id' => $numericId,
                'booking_date' => $booking['booking_date'],
                'appointment_id' => (int) $match[1],
                'end_time' => $booking['end_time'],
                'start_time' => $booking['start_time'],
            ]);
            $conflictingBooking = $conflictStatement->fetch();
            if ($conflictingBooking) {
                $pdo->rollBack();
                sendJson(['message' => 'That therapist already has an overlapping booking (' . $conflictingBooking['booking_code'] . '). Choose another therapist.'], 409);
            }
        }

        $statement = $pdo->prepare('UPDATE appointments SET therapist_id = ? WHERE id = ?');
        $statement->execute([$numericId, (int) $match[1]]);
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        sendJson(['message' => 'The therapist assignment could not be saved. Please try again.'], 500);
    }
} else {
    sendJson(['message' => 'Unknown manager action.'], 422);
}

sendJson(['saved' => true]);
