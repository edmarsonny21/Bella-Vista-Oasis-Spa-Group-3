<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'GET') {
    $therapist = requireTherapist();
    $pdo = database();
    $appointments = $pdo->prepare(
        "SELECT a.id, a.booking_code, a.guest_name, a.service_id, a.guest_email, a.guest_phone,
                a.booking_date, a.start_time, a.end_time, a.status, a.special_requests, a.health_others,
                s.name AS service_name, r.room_code
         FROM appointments a
         JOIN services s ON s.id = a.service_id
         JOIN rooms r ON r.id = a.room_id
         WHERE a.therapist_id = ? AND a.booking_date >= CURRENT_DATE AND a.status <> 'cancelled'
         ORDER BY a.booking_date, a.start_time"
    );
    $appointments->execute([(int) $therapist['id']]);

    $schedule = $pdo->prepare(
        'SELECT weekday, start_time, end_time, is_day_off
         FROM therapist_weekly_schedule WHERE therapist_id = ? ORDER BY weekday'
    );
    $schedule->execute([(int) $therapist['id']]);
    $dayOffs = $pdo->prepare(
        "SELECT off_date, note, status FROM therapist_day_offs
         WHERE therapist_id = ? AND off_date >= CURRENT_DATE ORDER BY off_date"
    );
    $dayOffs->execute([(int) $therapist['id']]);

    sendJson([
        'therapist' => [
            'name' => $therapist['full_name'],
            'isAvailable' => (bool) $therapist['is_available'],
        ],
        'appointments' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'bookingCode' => $row['booking_code'],
            'guestName' => $row['guest_name'],
            'guestEmail' => $row['guest_email'],
            'guestPhone' => $row['guest_phone'],
            'serviceName' => $row['service_name'],
            'roomCode' => $row['room_code'],
            'bookingDate' => $row['booking_date'],
            'startTime' => substr((string) $row['start_time'], 0, 5),
            'endTime' => substr((string) $row['end_time'], 0, 5),
            'status' => $row['status'],
            'specialRequests' => $row['special_requests'],
            'healthOthers' => $row['health_others'],
        ], $appointments->fetchAll()),
        'weeklySchedule' => array_map(static fn (array $row): array => [
            'weekday' => (int) $row['weekday'],
            'startTime' => $row['start_time'] ? substr((string) $row['start_time'], 0, 5) : null,
            'endTime' => $row['end_time'] ? substr((string) $row['end_time'], 0, 5) : null,
            'isDayOff' => (bool) $row['is_day_off'],
        ], $schedule->fetchAll()),
        'dayOffs' => array_map(static fn (array $row): array => [
            'date' => $row['off_date'],
            'note' => $row['note'],
            'status' => $row['status'],
        ], $dayOffs->fetchAll()),
        'csrfToken' => therapistCsrfToken(),
    ]);
}

if ($method !== 'POST') {
    requireMethod('POST');
}
$input = requestJson();
$therapist = requireTherapist();
requireTherapistCsrf($input['csrfToken'] ?? null);
$pdo = database();
$action = $input['action'] ?? '';

if ($action === 'availability') {
    $available = $input['available'] ?? null;
    if (!is_bool($available)) {
        sendJson(['message' => 'Choose a valid availability status.'], 422);
    }
    $statement = $pdo->prepare('UPDATE therapists SET is_available = ? WHERE id = ?');
    $statement->execute([$available ? 1 : 0, (int) $therapist['id']]);
    sendJson(['saved' => true]);
}

if ($action === 'request_day_off') {
    $date = $input['date'] ?? '';
    $note = trim((string) ($input['note'] ?? ''));
    $parsedDate = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date ||
        $date < date('Y-m-d') || mb_strlen($note) > 500) {
        sendJson(['message' => 'Choose a future date and enter an optional note of up to 500 characters.'], 422);
    }
    try {
        $pdo->beginTransaction();
        $lock = $pdo->prepare('SELECT id FROM therapists WHERE id = ? FOR UPDATE');
        $lock->execute([(int) $therapist['id']]);
        if (!$lock->fetchColumn()) {
            $pdo->rollBack();
            sendJson(['message' => 'Your therapist account is no longer available. Sign in again.'], 401);
        }
        $statement = $pdo->prepare(
            "INSERT INTO therapist_day_offs (therapist_id, off_date, note, status)
             VALUES (:therapist_id, :off_date, :note, 'requested')
             ON DUPLICATE KEY UPDATE note = VALUES(note), status = 'requested'"
        );
        $statement->execute([
            'therapist_id' => (int) $therapist['id'],
            'off_date' => $date,
            'note' => $note !== '' ? $note : null,
        ]);
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Therapist day-off request failed: ' . $exception->getMessage());
        sendJson(['message' => 'Your day-off request could not be saved. Please try again.'], 500);
    }
    sendJson(['saved' => true]);
}

if ($action === 'status') {
    $appointmentId = filter_var($input['appointmentId'] ?? null, FILTER_VALIDATE_INT);
    $status = $input['status'] ?? '';
    if (!$appointmentId || !in_array($status, ['in_session', 'completed'], true)) {
        sendJson(['message' => 'Choose an assigned appointment and valid status.'], 422);
    }
    $statement = $pdo->prepare(
        'SELECT id, guest_user_id, status, booking_date, start_time, end_time
         FROM appointments WHERE id = ? AND therapist_id = ? LIMIT 1'
    );
    $statement->execute([$appointmentId, (int) $therapist['id']]);
    $appointment = $statement->fetch();
    if (!$appointment) {
        sendJson(['message' => 'That appointment is not assigned to your account.'], 404);
    }

    $today = date('Y-m-d');
    $now = new DateTimeImmutable('now');
    $start = new DateTimeImmutable($appointment['booking_date'] . ' ' . $appointment['start_time']);
    $end = new DateTimeImmutable($appointment['booking_date'] . ' ' . $appointment['end_time']);
    if ($status === 'in_session') {
        if ($appointment['booking_date'] !== $today ||
            !in_array($appointment['status'], ['confirmed', 'in_session'], true) ||
            $now < $start || $now >= $end) {
            sendJson(['message' => 'You can start a confirmed appointment only during its scheduled treatment time today.'], 422);
        }
        if ($appointment['guest_user_id'] !== null) {
            $payment = $pdo->prepare(
                "SELECT id FROM payments
                 WHERE appointment_id = ? AND payment_status = 'Verified' LIMIT 1"
            );
            $payment->execute([$appointmentId]);
            if (!$payment->fetchColumn()) {
                sendJson(['message' => 'The guest reservation must have a verified GCash payment before treatment starts.'], 422);
            }
        }
    } elseif (
        $appointment['booking_date'] !== $today ||
        !in_array($appointment['status'], ['in_session', 'cleaning'], true) ||
        $now < $end
    ) {
        sendJson(['message' => 'An appointment can be completed only after its scheduled treatment ends today.'], 422);
    }

    $update = $pdo->prepare(
        'UPDATE appointments SET status = ? WHERE id = ? AND therapist_id = ?'
    );
    $update->execute([$status, $appointmentId, (int) $therapist['id']]);
    sendJson(['saved' => true]);
}

sendJson(['message' => 'Unknown therapist portal action.'], 422);
