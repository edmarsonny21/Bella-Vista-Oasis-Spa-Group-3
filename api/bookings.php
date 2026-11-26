<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

requireMethod('POST');
$input = requestJson();
startManagerSession();
if (($input['bookingMode'] ?? '') === 'walkin') {
    requireManager();
    $walkInFirstName = trim((string) ($input['guestFirstName'] ?? ''));
    $walkInMiddleName = trim((string) ($input['guestMiddleName'] ?? ''));
    $walkInLastName = trim((string) ($input['guestLastName'] ?? ''));
    $namePattern = "/^\\p{L}+(?:[ '-]\\p{L}+)*$/u";
    $walkInName = trim($walkInFirstName . ' ' . ($walkInMiddleName !== '' ? $walkInMiddleName . ' ' : '') . $walkInLastName);
    $walkInEmail = strtolower(trim((string) ($input['guestEmail'] ?? '')));
    if ($walkInFirstName === '' || $walkInLastName === '' ||
        preg_match($namePattern, $walkInFirstName) !== 1 || preg_match($namePattern, $walkInLastName) !== 1 ||
        ($walkInMiddleName !== '' && preg_match($namePattern, $walkInMiddleName) !== 1) ||
        mb_strlen($walkInFirstName) > 35 || mb_strlen($walkInMiddleName) > 35 ||
        mb_strlen($walkInLastName) > 35 || mb_strlen($walkInName) > 150 || strlen($walkInEmail) > 254 ||
        !filter_var($walkInEmail, FILTER_VALIDATE_EMAIL)) {
        sendJson(['message' => 'Enter the walk-in guest’s first and last name, optional middle name, and a valid email address.'], 422);
    }
    $guest = [
        'full_name' => $walkInName,
        'first_name' => $walkInFirstName,
        'middle_name' => $walkInMiddleName ?: null,
        'last_name' => $walkInLastName,
        'email' => $walkInEmail,
    ];
} else {
    $guest = requireGuest();
}
verifyRecaptchaToken($input['recaptchaToken'] ?? null);
$required = ['serviceId', 'roomCode', 'bookingDate', 'startTime', 'guestPhone'];
foreach ($required as $field) {
    if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === '') {
        sendJson(['message' => 'Complete all required booking details.'], 422);
    }
    if (preg_match('/^\+63[0-9]{10}$/', trim($input['guestPhone'])) !== 1) {
        sendJson(['message' => 'Enter a Philippines phone number in +63 format with 10 digits, for example +639171234567.'], 422);
    }
}

$therapistId = $input['therapistId'] ?? '';
if (!is_string($therapistId) || !preg_match('/^th-([1-9]\d*)$/', $therapistId, $therapistMatch)) {
    sendJson(['message' => 'Choose a therapist before confirming your reservation.'], 422);
}

if (empty($input['healthDeclared']) || empty($input['termsAccepted'])) {
    sendJson(['message' => 'Accept the health declaration and booking policies to continue.'], 422);
}
$healthOthers = trim((string) ($input['healthOthers'] ?? ''));
if (!empty($input['hasHealthOthers']) &&
    ($healthOthers === '' || mb_strlen($healthOthers) > 500)) {
    sendJson(['message' => 'Describe the other health condition in 1 to 500 characters.'], 422);
}
$parsedBookingDate = DateTimeImmutable::createFromFormat('!Y-m-d', $input['bookingDate']);
if (!$parsedBookingDate || $parsedBookingDate->format('Y-m-d') !== $input['bookingDate'] ||
    !preg_match('/^\d{2}:\d{2}$/', $input['startTime']) ||
    $input['bookingDate'] < date('Y-m-d')) {
    sendJson(['message' => 'Choose a valid upcoming date and time.'], 422);
}

$pdo = database();
$pdo->beginTransaction();
try {
    $roomStatement = $pdo->prepare(
        'SELECT r.id, r.room_code, s.id AS service_id, s.name AS service_name,
                s.duration_minutes, s.base_rate, s.base_pax, s.max_pax, s.extra_pax_fee
         FROM rooms r JOIN services s ON s.id = r.service_id
         WHERE r.room_code = :room_code AND s.id = :service_id FOR UPDATE'
    );
    $roomStatement->execute(['room_code' => $input['roomCode'], 'service_id' => $input['serviceId']]);
    $room = $roomStatement->fetch();
    if (!$room) {
        $pdo->rollBack();
        sendJson(['message' => 'The selected room is not available for this treatment.'], 422);
    }

    $guestCount = filter_var($input['guestCount'] ?? null, FILTER_VALIDATE_INT);
    if ($guestCount === false || $guestCount < (int) $room['base_pax'] || $guestCount > (int) $room['max_pax']) {
        $pdo->rollBack();
        sendJson(['message' => 'The guest count is outside this treatment’s allowed capacity.'], 422);
    }

    $start = DateTimeImmutable::createFromFormat('!H:i', $input['startTime']);
    $scheduleStart = new DateTimeImmutable($input['bookingDate'] . ' 13:00');
    $scheduleEnd = new DateTimeImmutable($input['bookingDate'] . ' 21:00');
    if (!$start || $start->format('H:i') !== $input['startTime']) {
        $pdo->rollBack();
        sendJson(['message' => 'Choose a valid treatment time.'], 422);
    }
    $start = new DateTimeImmutable($input['bookingDate'] . ' ' . $input['startTime']);
    $end = $start->modify('+' . (int) $room['duration_minutes'] . ' minutes');
    $cleaningEnd = $end->modify('+30 minutes');
    $slotBlockMinutes = (int) $room['duration_minutes'] + 30;
    $minutesAfterOpening = ((int) $input['startTime'][0] * 10 + (int) $input['startTime'][1]) * 60
        + (int) substr($input['startTime'], 3, 2) - 13 * 60;
    if ($start < $scheduleStart || $end > $scheduleEnd ||
        $minutesAfterOpening % $slotBlockMinutes !== 0 ||
        ($input['bookingDate'] === date('Y-m-d') && $start <= new DateTimeImmutable('now'))) {
        $pdo->rollBack();
        sendJson(['message' => 'Choose a valid unelapsed session time on the fixed schedule.'], 422);
    }

    $selectedTherapistId = (int) $therapistMatch[1];
    $therapistStatement = $pdo->prepare('SELECT id FROM therapists WHERE id = ? FOR UPDATE');
    $therapistStatement->execute([$selectedTherapistId]);
    if (!$therapistStatement->fetch()) {
        $pdo->rollBack();
        sendJson(['message' => 'The selected therapist is no longer available. Choose another therapist.'], 409);
    }
    if (!therapistIsAvailable(
        $pdo,
        $selectedTherapistId,
        $input['bookingDate'],
        $start->format('H:i:s'),
        $end->format('H:i:s')
    )) {
        $pdo->rollBack();
        sendJson(['message' => 'The selected therapist is off duty or unavailable for that date and time. Choose another therapist.'], 409);
    }

    $therapistConflict = $pdo->prepare(
        "SELECT booking_code FROM appointments
         WHERE therapist_id = :therapist_id AND booking_date = :booking_date
           AND status <> 'cancelled' AND start_time < :end_time
           AND end_time > :start_time LIMIT 1"
    );
    $therapistConflict->execute([
        'therapist_id' => $selectedTherapistId,
        'booking_date' => $input['bookingDate'],
        'end_time' => $end->format('H:i:s'),
        'start_time' => $start->format('H:i:s'),
    ]);
    if ($therapistConflict->fetch()) {
        $pdo->rollBack();
        sendJson(['message' => 'That therapist already has an overlapping booking. Choose another therapist or time.'], 409);
    }

    $conflict = $pdo->prepare(
        "SELECT id FROM appointments
         WHERE room_id = :room_id AND booking_date = :booking_date
           AND status <> 'cancelled' AND start_time < :cleaning_end
           AND cleaning_end_time > :start_time LIMIT 1"
    );
    $conflict->execute([
        'room_id' => $room['id'],
        'booking_date' => $input['bookingDate'],
        'cleaning_end' => $cleaningEnd->format('H:i:s'),
        'start_time' => $start->format('H:i:s'),
    ]);
    if ($conflict->fetch()) {
        $pdo->rollBack();
        sendJson(['message' => 'This appointment time is already booked. Please choose another available time.'], 409);
    }

    $extraGuests = max(0, $guestCount - (int) $room['base_pax']);
    $baseRate = (float) $room['base_rate'];
    $extraFee = $extraGuests * (float) $room['extra_pax_fee'];
    $initialStatus = isset($guest['id']) ? 'on_progress' : 'confirmed';
    $bookingCode = 'BVO-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $insert = $pdo->prepare(
        "INSERT INTO appointments
         (booking_code, guest_user_id, service_id, room_id, therapist_id, guest_name,
          guest_first_name, guest_middle_name, guest_last_name, guest_email, guest_phone,
          booking_date, start_time, end_time, cleaning_end_time, guest_count,
          extra_pax_count, base_rate, extra_pax_fee_total, total_amount,
          special_requests, health_others, health_declared, terms_accepted, status)
         VALUES
         (:booking_code, :guest_user_id, :service_id, :room_id, :therapist_id, :guest_name,
          :guest_first_name, :guest_middle_name, :guest_last_name, :guest_email, :guest_phone,
          :booking_date, :start_time, :end_time, :cleaning_end_time, :guest_count,
          :extra_pax_count, :base_rate, :extra_pax_fee_total, :total_amount,
          :special_requests, :health_others, 1, 1, :status)"
    );
    $insert->execute([
        'booking_code' => $bookingCode,
        'guest_user_id' => isset($guest['id']) ? (int) $guest['id'] : null,
        'service_id' => $room['service_id'],
        'room_id' => $room['id'],
        'therapist_id' => $selectedTherapistId,
        'guest_name' => $guest['full_name'],
        'guest_first_name' => $guest['first_name'] ?? null,
        'guest_middle_name' => $guest['middle_name'] ?? null,
        'guest_last_name' => $guest['last_name'] ?? null,
        'guest_email' => $guest['email'],
        'guest_phone' => trim($input['guestPhone']),
        'booking_date' => $input['bookingDate'],
        'start_time' => $start->format('H:i:s'),
        'end_time' => $end->format('H:i:s'),
        'cleaning_end_time' => $cleaningEnd->format('H:i:s'),
        'guest_count' => $guestCount,
        'extra_pax_count' => $extraGuests,
        'base_rate' => $baseRate,
        'extra_pax_fee_total' => $extraFee,
        'total_amount' => $baseRate + $extraFee,
        'special_requests' => trim((string) ($input['specialRequests'] ?? '')),
        'health_others' => !empty($input['hasHealthOthers']) ? $healthOthers : null,
        'status' => $initialStatus,
    ]);
    $bookingId = (int) $pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($exception->getMessage());
    sendJson(['message' => 'The booking could not be saved. Please try again.'], 500);
}

$lookup = $pdo->prepare(
    "SELECT a.*, s.name AS service_name, r.room_code, t.full_name AS therapist_name
     FROM appointments a JOIN services s ON s.id = a.service_id
     JOIN rooms r ON r.id = a.room_id
     LEFT JOIN therapists t ON t.id = a.therapist_id WHERE a.id = ?"
);
$lookup->execute([$bookingId]);
sendJson(['booking' => appointmentToClient($lookup->fetch())], 201);
