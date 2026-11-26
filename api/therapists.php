<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

requireMethod('GET');

$date = $_GET['date'] ?? '';
$startTime = $_GET['startTime'] ?? '';
$endTime = $_GET['endTime'] ?? '';
$filterByDate = $date !== '';
if ($filterByDate) {
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date ||
        ($startTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $startTime)) ||
        ($endTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $endTime)) ||
        (($startTime === '') !== ($endTime === ''))) {
        sendJson(['message' => 'Choose a valid therapist availability date and time.'], 400);
    }
}

$pdo = database();
$therapists = $pdo->query(
    'SELECT id, full_name, specialties, is_available FROM therapists ORDER BY full_name'
)->fetchAll();
$availabilityById = [];
if ($filterByDate) {
    $dayOffQuery = $pdo->prepare(
        "SELECT therapist_id FROM therapist_day_offs
         WHERE off_date = ? AND status IN ('requested', 'approved')"
    );
    $dayOffQuery->execute([$date]);
    $dayOffIds = array_fill_keys(array_map('intval', $dayOffQuery->fetchAll(PDO::FETCH_COLUMN)), true);

    $weekday = (int) (new DateTimeImmutable($date))->format('N');
    $scheduleQuery = $pdo->prepare(
        'SELECT therapist_id, start_time, end_time, is_day_off
         FROM therapist_weekly_schedule WHERE weekday = ?'
    );
    $scheduleQuery->execute([$weekday]);
    $scheduleById = [];
    foreach ($scheduleQuery->fetchAll() as $schedule) {
        $scheduleById[(int) $schedule['therapist_id']] = $schedule;
    }

    $unavailableIds = [];
    $conflictIds = [];
    if ($startTime !== '') {
        $unavailableQuery = $pdo->prepare(
            'SELECT therapist_id FROM therapist_unavailable_periods
             WHERE unavailable_date = ? AND start_time < ? AND end_time > ?'
        );
        $unavailableQuery->execute([$date, $endTime, $startTime]);
        $unavailableIds = array_fill_keys(array_map('intval', $unavailableQuery->fetchAll(PDO::FETCH_COLUMN)), true);

        $conflictQuery = $pdo->prepare(
            "SELECT therapist_id FROM appointments
             WHERE booking_date = ? AND status <> 'cancelled'
               AND start_time < ? AND end_time > ?"
        );
        $conflictQuery->execute([$date, $endTime, $startTime]);
        $conflictIds = array_fill_keys(array_map('intval', $conflictQuery->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    foreach ($therapists as $therapist) {
        $id = (int) $therapist['id'];
        $weekly = $scheduleById[$id] ?? null;
        $isAvailable = false;
        $status = 'Select a time';

        if (isset($dayOffIds[$id])) {
            $status = 'Day off';
        } elseif ($weekly && (bool) $weekly['is_day_off']) {
            $status = 'Scheduled day off';
        } elseif ((int) $therapist['is_available'] !== 1) {
            $status = 'Unavailable';
        } elseif ($weekly && (
            $weekly['start_time'] === null ||
            $weekly['end_time'] === null ||
            ($startTime !== '' && (
                $startTime < substr((string) $weekly['start_time'], 0, 5) ||
                $endTime > substr((string) $weekly['end_time'], 0, 5)
            ))
        )) {
            $status = 'Off duty';
        } elseif ($startTime !== '' && isset($unavailableIds[$id])) {
            $status = 'Unavailable at this time';
        } elseif ($startTime !== '' && isset($conflictIds[$id])) {
            $status = 'Already booked';
        } elseif ($startTime !== '') {
            $isAvailable = true;
            $status = 'Available';
        }

        $availabilityById[$id] = [
            'isAvailable' => $isAvailable,
            'availabilityStatus' => $status,
        ];
    }
}

sendJson([
    'therapists' => array_map(static function (array $therapist) use ($availabilityById): array {
        $id = (int) $therapist['id'];
        $available = (int) $therapist['is_available'] === 1;
        $availability = $availabilityById[$id] ?? [
            'isAvailable' => $available,
            'availabilityStatus' => $available ? 'Available' : 'Unavailable',
        ];
        return [
            'id' => 'th-' . $id,
            'name' => $therapist['full_name'],
            'specialties' => array_values(array_filter(array_map('trim', explode(',', $therapist['specialties'])))),
            'isAvailable' => $availability['isAvailable'],
            'availabilityStatus' => $availability['availabilityStatus'],
        ];
    }, $therapists),
]);
