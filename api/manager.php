<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
requireManager();
requireMethod('GET');

$pdo = database();
$reportDate = $_GET['date'] ?? date('Y-m-d');
$parsedReportDate = DateTimeImmutable::createFromFormat('!Y-m-d', $reportDate);
if (!$parsedReportDate || $parsedReportDate->format('Y-m-d') !== $reportDate) {
    sendJson(['message' => 'Choose a valid report date.'], 422);
}

$appointments = $pdo->query(
    "SELECT a.*, s.name AS service_name, r.room_code, t.full_name AS therapist_name,
            (SELECT p.payment_status FROM payments p
             WHERE p.appointment_id = a.id ORDER BY p.created_at DESC, p.id DESC LIMIT 1) AS payment_status
     FROM appointments a
     JOIN services s ON s.id = a.service_id
     JOIN rooms r ON r.id = a.room_id
     LEFT JOIN therapists t ON t.id = a.therapist_id
     ORDER BY a.booking_date DESC, a.start_time DESC"
)->fetchAll();
$now = new DateTimeImmutable('now');
foreach ($appointments as &$appointment) {
    $appointment['status'] = appointmentStatusForDashboard($appointment, $now);
}
unset($appointment);

$rooms = $pdo->query(
    "SELECT r.room_code AS code, r.name, s.id AS service_id, r.capacity
     FROM rooms r
     JOIN services s ON s.id = r.service_id
     ORDER BY r.id"
)->fetchAll();
foreach ($rooms as &$room) {
    $activeAppointment = null;
    foreach ($appointments as $appointment) {
        if ($appointment['room_code'] === $room['code'] &&
            $appointment['booking_date'] === $reportDate &&
            in_array($appointment['status'], ['in_session', 'cleaning'], true)) {
            $activeAppointment = $appointment;
            break;
        }
    }
    $room['booking_status'] = $activeAppointment['status'] ?? null;
    $room['current_booking_id'] = $activeAppointment['booking_code'] ?? null;
    $room['cleaning_until'] = $activeAppointment
        ? substr((string) $activeAppointment['cleaning_end_time'], 0, 5)
        : null;
}
unset($room);

$therapists = $pdo->query(
    'SELECT id, full_name, specialties FROM therapists ORDER BY id'
)->fetchAll();
foreach ($therapists as &$therapist) {
    $therapist['status'] = 'available';
    $therapist['assigned_booking'] = null;
    $therapist['assigned_booking_count'] = 0;
    $nextAssignment = null;
    foreach ($appointments as $appointment) {
        if ($appointment['booking_date'] !== $reportDate ||
            $appointment['therapist_id'] === null ||
            (int) $appointment['therapist_id'] !== (int) $therapist['id'] ||
            !in_array($appointment['status'], ['confirmed', 'in_session', 'cleaning'], true)) {
            continue;
        }

        $therapist['assigned_booking_count']++;
        if ($appointment['status'] === 'in_session') {
            $therapist['status'] = 'in_session';
            $therapist['assigned_booking'] = $appointment;
            continue;
        }
        if ($nextAssignment === null ||
            $appointment['start_time'] < $nextAssignment['start_time']) {
            $nextAssignment = $appointment;
        }
    }
    if ($therapist['status'] !== 'in_session' && $nextAssignment !== null) {
        $therapist['status'] = 'assigned';
        $therapist['assigned_booking'] = $nextAssignment;
    }
}
unset($therapist);

$roomPayload = array_map(static function (array $room): array {
    return [
        'id' => 'room-' . $room['code'],
        'code' => $room['code'],
        'name' => $room['name'],
        'serviceId' => $room['service_id'],
        'capacity' => (int) $room['capacity'],
        'status' => $room['booking_status'] ?: 'available',
        'currentBookingId' => $room['current_booking_id'],
        'cleaningUntil' => $room['cleaning_until'],
    ];
}, $rooms);
$therapistPayload = array_map(static function (array $therapist): array {
    return [
        'id' => 'th-' . $therapist['id'],
        'name' => $therapist['full_name'],
        'specialties' => array_map('trim', explode(',', $therapist['specialties'])),
        'status' => $therapist['status'],
        'assignedBookingCode' => $therapist['assigned_booking']['booking_code'] ?? null,
        'assignedBookingStartTime' => isset($therapist['assigned_booking']['start_time'])
            ? substr((string) $therapist['assigned_booking']['start_time'], 0, 5)
            : null,
        'assignedBookingCount' => $therapist['assigned_booking_count'],
        'avatarUrl' => '',
    ];
}, $therapists);

sendJson([
    'serverNow' => $now->format(DateTimeInterface::ATOM),
    'appointments' => array_map('appointmentToClient', $appointments),
    'rooms' => $roomPayload,
    'therapists' => $therapistPayload,
]);
