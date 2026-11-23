<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

requireMethod('GET');
$date = $_GET['date'] ?? '';
$roomCode = $_GET['roomCode'] ?? '';

$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date || !preg_match('/^[A-Z]{2}-\d+$/', $roomCode)) {
    sendJson(['message' => 'Choose a valid date and room.'], 400);
}

$statement = database()->prepare(
    "SELECT TIME_FORMAT(start_time, '%H:%i') AS start_time,
            TIME_FORMAT(end_time, '%H:%i') AS end_time,
            TIME_FORMAT(cleaning_end_time, '%H:%i') AS cleaning_end_time
     FROM appointments
     WHERE booking_date = :booking_date AND room_id = (
         SELECT id FROM rooms WHERE room_code = :room_code LIMIT 1
     ) AND status <> 'cancelled'"
);
$statement->execute(['booking_date' => $date, 'room_code' => $roomCode]);
$bookings = array_map(static fn (array $booking): array => [
    'startTime' => $booking['start_time'],
    'endTime' => $booking['end_time'],
    'cleaningEndTime' => $booking['cleaning_end_time'],
], $statement->fetchAll());
sendJson(['bookings' => $bookings]);
