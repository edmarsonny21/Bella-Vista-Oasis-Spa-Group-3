<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

function appConfiguration(): array
{
    static $configuration;
    if ($configuration !== null) {
        return $configuration;
    }

    $configurationPath = dirname(__DIR__) . '/config/local.php';
    if (!is_file($configurationPath)) {
        $configuration = [];
        return $configuration;
    }

    $loadedConfiguration = require $configurationPath;
    if (!is_array($loadedConfiguration)) {
        throw new RuntimeException('The local configuration file must return an array.');
    }

    $configuration = $loadedConfiguration;
    return $configuration;
}

function sendJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestJson(): array
{
    $body = file_get_contents('php://input');
    $data = json_decode($body === false ? '' : $body, true);

    if (!is_array($data)) {
        sendJson(['message' => 'Invalid JSON request.'], 400);
    }

    return $data;
}

function database(): PDO
{
    static $connection;
    if ($connection instanceof PDO) {
        return $connection;
    }

    try {
        $connection = new PDO(
            'mysql:host=127.0.0.1;dbname=bellavista_oasis_app;charset=utf8mb4',
            'root',
            '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        sendJson(['message' => 'Could not connect to MySQL. Start MySQL in XAMPP and import database/schema.sql.'], 503);
    }

    return $connection;
}

function smtpConfigurationIsComplete(): bool
{
    $configuration = appConfiguration()['smtp'] ?? [];
    $host = $configuration['host'] ?? null;
    $port = $configuration['port'] ?? null;
    $encryption = $configuration['encryption'] ?? null;
    $username = $configuration['username'] ?? null;
    $password = $configuration['password'] ?? null;
    $fromEmail = $configuration['from_email'] ?? null;
    $fromName = $configuration['from_name'] ?? null;

    return is_string($host) && $host !== '' && $host !== 'smtp.example.com' &&
        !str_starts_with($host, 'YOUR_') &&
        is_int($port) && $port >= 1 && $port <= 65535 &&
        is_string($encryption) && in_array($encryption, ['tls', 'ssl'], true) &&
        is_string($username) && $username !== '' && !str_starts_with($username, 'your-') &&
        is_string($password) && $password !== '' && !str_starts_with($password, 'your-') &&
        is_string($fromEmail) && filter_var($fromEmail, FILTER_VALIDATE_EMAIL) &&
        $fromEmail !== 'spa@example.com' &&
        is_string($fromName) && $fromName !== '';
}

function startManagerSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Strict',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

function requireManager(): void
{
    startManagerSession();
    if (!isset($_SESSION['manager_id'])) {
        sendJson(['message' => 'Please sign in to the manager console.'], 401);
    }
}

function requireTherapist(): array
{
    startManagerSession();
    $therapistId = $_SESSION['therapist_id'] ?? null;
    if (!is_int($therapistId)) {
        sendJson(['message' => 'Please sign in to the therapist portal.'], 401);
    }

    $statement = database()->prepare(
        'SELECT id, full_name, first_name, middle_name, last_name, is_available
         FROM therapists WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $therapistId]);
    $therapist = $statement->fetch();
    if (!$therapist) {
        unset($_SESSION['therapist_id'], $_SESSION['therapist_name']);
        sendJson(['message' => 'Please sign in to the therapist portal.'], 401);
    }

    return $therapist;
}

function therapistCsrfToken(): string
{
    startManagerSession();
    if (!isset($_SESSION['therapist_csrf_token']) ||
        !is_string($_SESSION['therapist_csrf_token']) ||
        !preg_match('/^[a-f0-9]{64}$/', $_SESSION['therapist_csrf_token'])) {
        $_SESSION['therapist_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['therapist_csrf_token'];
}

function requireTherapistCsrf(mixed $token): void
{
    startManagerSession();
    $expected = $_SESSION['therapist_csrf_token'] ?? '';
    if (!is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
        sendJson(['message' => 'Your session expired. Reload the therapist portal and try again.'], 403);
    }
}

function therapistIsAvailable(PDO $pdo, int $therapistId, string $date, string $startTime, string $endTime): bool
{
    $therapist = $pdo->prepare('SELECT is_available FROM therapists WHERE id = ?');
    $therapist->execute([$therapistId]);
    if ((int) $therapist->fetchColumn() !== 1) {
        return false;
    }

    $dayOff = $pdo->prepare(
        "SELECT id FROM therapist_day_offs
         WHERE therapist_id = ? AND off_date = ? AND status IN ('requested', 'approved') LIMIT 1"
    );
    $dayOff->execute([$therapistId, $date]);
    if ($dayOff->fetchColumn()) {
        return false;
    }

    $weekday = (int) (new DateTimeImmutable($date))->format('N');
    $schedule = $pdo->prepare(
        'SELECT start_time, end_time, is_day_off FROM therapist_weekly_schedule
         WHERE therapist_id = ? AND weekday = ? LIMIT 1'
    );
    $schedule->execute([$therapistId, $weekday]);
    $workday = $schedule->fetch();
    if ($workday && (
        (bool) $workday['is_day_off'] ||
        $workday['start_time'] === null ||
        $workday['end_time'] === null ||
        $startTime < $workday['start_time'] ||
        $endTime > $workday['end_time']
    )) {
        return false;
    }

    $unavailable = $pdo->prepare(
        'SELECT id FROM therapist_unavailable_periods
         WHERE therapist_id = ? AND unavailable_date = ?
           AND start_time < ? AND end_time > ? LIMIT 1'
    );
    $unavailable->execute([$therapistId, $date, $endTime, $startTime]);
    return !$unavailable->fetchColumn();
}

function managerCsrfToken(): string
{
    startManagerSession();
    if (!isset($_SESSION['manager_csrf_token']) ||
        !is_string($_SESSION['manager_csrf_token']) ||
        !preg_match('/^[a-f0-9]{64}$/', $_SESSION['manager_csrf_token'])) {
        $_SESSION['manager_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['manager_csrf_token'];
}

function requireManagerCsrf(mixed $token): void
{
    startManagerSession();
    $expected = $_SESSION['manager_csrf_token'] ?? '';
    if (!is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
        sendJson(['message' => 'Your session expired. Reload the manager dashboard and try again.'], 403);
    }
}

function appointmentStatusForDashboard(array $appointment, DateTimeImmutable $now): string
{
    $status = (string) $appointment['status'];
    if (in_array($status, ['on_progress', 'cancelled', 'completed'], true)) {
        return $status;
    }

    $appointmentDate = (string) $appointment['booking_date'];
    $today = $now->format('Y-m-d');
    if ($appointmentDate < $today) {
        return 'completed';
    }
    if ($appointmentDate > $today) {
        return 'confirmed';
    }

    $sessionStart = new DateTimeImmutable($appointmentDate . ' ' . $appointment['start_time']);
    $sessionEnd = new DateTimeImmutable($appointmentDate . ' ' . $appointment['end_time']);
    $cleaningEnd = new DateTimeImmutable($appointmentDate . ' ' . $appointment['cleaning_end_time']);
    if ($now < $sessionStart) {
        return 'confirmed';
    }
    if ($now < $sessionEnd) {
        return 'in_session';
    }
    if ($now < $cleaningEnd) {
        return 'cleaning';
    }

    return 'completed';
}

function requireGuest(): array
{
    startManagerSession();
    $guestId = $_SESSION['guest_id'] ?? null;
    if (!is_int($guestId)) {
        sendJson(['message' => 'Sign in with a verified guest account before booking.'], 401);
    }

    $statement = database()->prepare(
        'SELECT id, full_name, first_name, middle_name, last_name, email FROM guest_users
         WHERE id = :id AND email_verified_at IS NOT NULL LIMIT 1'
    );
    $statement->execute(['id' => $guestId]);
    $guest = $statement->fetch();
    if (!$guest) {
        unset($_SESSION['guest_id'], $_SESSION['guest_name'], $_SESSION['guest_email']);
        sendJson(['message' => 'Sign in with a verified guest account before booking.'], 401);
    }

    return $guest;
}

function verifyRecaptchaToken(mixed $token): void
{
    $configuration = appConfiguration();
    $secret = $configuration['recaptcha']['secret_key'] ?? '';
    if (!is_string($secret) || $secret === '' || str_starts_with($secret, 'YOUR_')) {
        sendJson(['message' => 'reCAPTCHA is not configured. Add the site and secret keys to config/local.php.'], 503);
    }
    if (!is_string($token) || $token === '') {
        sendJson(['message' => 'Complete the reCAPTCHA challenge and try again.'], 422);
    }

    $postFields = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
    if (function_exists('curl_init')) {
        $curl = curl_init('https://www.google.com/recaptcha/api/siteverify');
        if ($curl === false) {
            throw new RuntimeException('Could not initialize the reCAPTCHA verification request.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $postFields,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        error_clear_last();
        $response = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
        $error = $response === false ? (error_get_last()['message'] ?? 'HTTPS request failed.') : '';
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }
    }

    if (!is_string($response) || $status !== 200) {
        error_log('reCAPTCHA verification failed: ' . ($error !== '' ? $error : 'HTTP ' . $status));
        sendJson(['message' => 'Could not verify reCAPTCHA. Please try again.'], 503);
    }

    $result = json_decode($response, true);
    if (!is_array($result) || ($result['success'] ?? false) !== true) {
        sendJson(['message' => 'reCAPTCHA verification failed. Complete the challenge and try again.'], 422);
    }
}

function sendSmtpEmail(string $recipient, string $subject, string $body): void
{
    $configuration = appConfiguration()['smtp'] ?? [];
    $host = $configuration['host'] ?? '';
    $port = $configuration['port'] ?? 587;
    $encryption = $configuration['encryption'] ?? 'tls';
    $username = $configuration['username'] ?? '';
    $password = $configuration['password'] ?? '';
    $fromEmail = $configuration['from_email'] ?? '';
    $fromName = $configuration['from_name'] ?? 'Bella Vista Oasis Spa';

    if (!smtpConfigurationIsComplete()) {
        throw new RuntimeException('SMTP is not configured. Add valid SMTP settings to config/local.php.');
    }

    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $socket = stream_socket_client(
        $transport . $host . ':' . $port,
        $errorCode,
        $errorMessage,
        10,
        STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]])
    );
    if ($socket === false) {
        throw new RuntimeException('Could not connect to the configured SMTP server: ' . $errorMessage, $errorCode);
    }
    stream_set_timeout($socket, 10);

    try {
        smtpExpect($socket, [220]);
        smtpCommand($socket, 'EHLO bellavista.local', [250]);
        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('Could not establish a secure SMTP connection.');
            }
            smtpCommand($socket, 'EHLO bellavista.local', [250]);
        }
        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode($username), [334]);
        smtpCommand($socket, base64_encode($password), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $safeSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $safeFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
        $headers = [
            'From: ' . $safeFromName . ' <' . $fromEmail . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . $safeSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $message = implode("\n", $headers) . "\n\n" . str_replace(["\r\n", "\r"], "\n", $body);
        $message = preg_replace('/(?m)^\./', '..', $message);
        if (!is_string($message) || fwrite($socket, str_replace("\n", "\r\n", $message) . "\r\n.\r\n") === false) {
            throw new RuntimeException('Could not send the verification email through SMTP.');
        }
        smtpExpect($socket, [250]);
        smtpCommand($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

function smtpCommand($socket, string $command, array $expectedCodes): string
{
    if (fwrite($socket, $command . "\r\n") === false) {
        throw new RuntimeException('Could not write to the SMTP connection.');
    }
    return smtpExpect($socket, $expectedCodes);
}

function smtpExpect($socket, array $expectedCodes): string
{
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            throw new RuntimeException('The SMTP server closed the connection unexpectedly.');
        }
        $response .= $line;
    } while (strlen($line) >= 4 && $line[3] === '-');

    $code = (int) substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('The SMTP server rejected a mail operation (' . $code . ').');
    }
    return $response;
}

function requireMethod(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        header('Allow: ' . $method);
        sendJson(['message' => 'Method not allowed.'], 405);
    }

    if (basename($_SERVER['SCRIPT_NAME'] ?? '') === '_bootstrap.php') {
        sendJson(['message' => 'Not found.'], 404);
    }
}

function appointmentToClient(array $row): array
{
    return [
        'id' => 'apt-' . $row['id'],
        'bookingCode' => $row['booking_code'],
        'serviceId' => $row['service_id'],
        'serviceName' => $row['service_name'],
        'roomCode' => $row['room_code'],
        'therapistId' => $row['therapist_id'] === null ? null : 'th-' . $row['therapist_id'],
        'therapistName' => $row['therapist_name'],
        'availableTherapistIds' => $row['available_therapist_ids'] ?? [],
        'guestName' => $row['guest_name'],
        'guestFirstName' => $row['guest_first_name'] ?? null,
        'guestMiddleName' => $row['guest_middle_name'] ?? null,
        'guestLastName' => $row['guest_last_name'] ?? null,
        'guestEmail' => $row['guest_email'],
        'guestPhone' => $row['guest_phone'],
        'bookingDate' => $row['booking_date'],
        'startTime' => substr((string) $row['start_time'], 0, 5),
        'endTime' => substr((string) $row['end_time'], 0, 5),
        'cleaningEndTime' => substr((string) $row['cleaning_end_time'], 0, 5),
        'guestCount' => (int) $row['guest_count'],
        'extraPaxCount' => (int) $row['extra_pax_count'],
        'baseRate' => (float) $row['base_rate'],
        'extraPaxFeeTotal' => (float) $row['extra_pax_fee_total'],
        'totalAmount' => (float) $row['total_amount'],
        'specialRequests' => $row['special_requests'],
        'healthOthers' => $row['health_others'] ?? null,
        'healthDeclared' => (bool) $row['health_declared'],
        'termsAccepted' => (bool) $row['terms_accepted'],
        'status' => $row['status'],
        'paymentStatus' => $row['payment_status'] ?? null,
        'createdAt' => $row['created_at'],
    ];
}
