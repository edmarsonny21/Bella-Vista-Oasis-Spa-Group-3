<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

$operation = $_GET['operation'] ?? '';
if ($operation === 'public_config') {
    requireMethod('GET');
    $recaptcha = appConfiguration()['recaptcha'] ?? [];
    $siteKey = $recaptcha['site_key'] ?? '';
    $secretKey = $recaptcha['secret_key'] ?? '';
    $configured = is_string($siteKey) && $siteKey !== '' && !str_starts_with($siteKey, 'YOUR_') &&
        is_string($secretKey) && $secretKey !== '' && !str_starts_with($secretKey, 'YOUR_');
    $siteKey = $configured ? $siteKey : '';
    sendJson([
        'recaptchaSiteKey' => $siteKey,
        'emailConfigured' => smtpConfigurationIsComplete(),
    ]);
}

if ($operation === 'session') {
    requireMethod('GET');
    startManagerSession();
    $authenticated = isset($_SESSION['manager_id']);
    sendJson([
        'authenticated' => $authenticated,
        'csrfToken' => $authenticated ? managerCsrfToken() : null,
    ]);
}

if ($operation === 'guest_session') {
    requireMethod('GET');
    startManagerSession();
    sendJson([
        'authenticated' => isset($_SESSION['guest_id']),
        'name' => $_SESSION['guest_name'] ?? null,
        'email' => $_SESSION['guest_email'] ?? null,
    ]);
}

if ($operation === 'guest_profile') {
    requireMethod('GET');
    $guest = requireGuest();
    $pdo = database();
    $bookings = $pdo->prepare(
        "SELECT a.booking_code, s.name AS service_name, r.room_code, t.full_name AS therapist_name,
                a.booking_date, a.start_time, a.guest_count, a.total_amount, a.status
         FROM appointments a
         JOIN services s ON s.id = a.service_id
         JOIN rooms r ON r.id = a.room_id
         LEFT JOIN therapists t ON t.id = a.therapist_id
         WHERE a.guest_user_id = :guest_id
            OR (a.guest_user_id IS NULL AND a.guest_email = :email AND a.guest_name = :name)
         ORDER BY a.booking_date DESC, a.start_time DESC
         LIMIT 50"
    );
    $bookings->execute([
        'guest_id' => $guest['id'],
        'email' => $guest['email'],
        'name' => $guest['full_name'],
    ]);
    $pending = $pdo->prepare(
        'SELECT pending_email, verification_stage FROM guest_email_changes
         WHERE guest_user_id = :guest_id LIMIT 1'
    );
    $pending->execute(['guest_id' => $guest['id']]);
    $pendingChange = $pending->fetch();
    $passwordChange = $pdo->prepare(
        'SELECT guest_user_id FROM guest_password_changes WHERE guest_user_id = :guest_id LIMIT 1'
    );
    $passwordChange->execute(['guest_id' => $guest['id']]);
    sendJson([
        'name' => $guest['full_name'],
        'email' => $guest['email'],
        'pendingEmail' => $pendingChange['pending_email'] ?? null,
        'emailChangeStage' => $pendingChange['verification_stage'] ?? null,
        'passwordChangePending' => (bool) $passwordChange->fetchColumn(),
        'bookings' => array_map(static fn(array $booking): array => [
            'bookingCode' => $booking['booking_code'],
            'serviceName' => $booking['service_name'],
            'roomCode' => $booking['room_code'],
            'therapistName' => $booking['therapist_name'],
            'bookingDate' => $booking['booking_date'],
            'startTime' => substr((string) $booking['start_time'], 0, 5),
            'guestCount' => (int) $booking['guest_count'],
            'totalAmount' => (float) $booking['total_amount'],
            'status' => $booking['status'],
        ], $bookings->fetchAll()),
    ]);
}

if ($operation === 'guest_logout') {
    requireMethod('POST');
    startManagerSession();
    unset($_SESSION['guest_id'], $_SESSION['guest_name'], $_SESSION['guest_email']);
    sendJson(['authenticated' => false]);
}

if ($operation === 'logout') {
    requireMethod('POST');
    startManagerSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
    }
    session_destroy();
    sendJson(['authenticated' => false]);
}

requireMethod('POST');
$input = requestJson();
startManagerSession();

if ($operation === 'guest_password_change_request') {
    $guest = requireGuest();
    $currentPassword = (string) ($input['currentPassword'] ?? '');
    $newPassword = (string) ($input['newPassword'] ?? '');
    if ($currentPassword === '' || strlen($newPassword) < 12 || strlen($newPassword) > 50) {
        sendJson(['message' => 'Enter your current password and a new password with 12 to 50 characters.'], 422);
    }

    $pdo = database();
    if (!smtpConfigurationIsComplete()) {
        sendJson(['message' => 'Email delivery is not configured, so a password verification code cannot be sent.'], 503);
    }
    $statement = $pdo->prepare(
        'SELECT full_name, email, password_hash FROM guest_users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $guest['id']]);
    $account = $statement->fetch();
    if (!$account || !password_verify($currentPassword, $account['password_hash'])) {
        sendJson(['message' => 'Your current password is incorrect.'], 422);
    }
    $existing = $pdo->prepare(
        'SELECT last_sent_at FROM guest_password_changes WHERE guest_user_id = :guest_id'
    );
    $existing->execute(['guest_id' => $guest['id']]);
    $lastSentAt = $existing->fetchColumn();
    if (is_string($lastSentAt) && strtotime($lastSentAt) > time() - 60) {
        sendJson(['message' => 'Please wait 60 seconds before requesting another password code.'], 429);
    }

    $code = (string) random_int(100000, 999999);
    $saveCode = $pdo->prepare(
        'INSERT INTO guest_password_changes
         (guest_user_id, password_hash, code_hash, expires_at, attempts, last_sent_at)
         VALUES (:guest_id, :password_hash, :code_hash, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, NOW())
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash),
           code_hash = VALUES(code_hash), expires_at = VALUES(expires_at),
           attempts = 0, last_sent_at = NOW()'
    );
    $saveCode->execute([
        'guest_id' => $guest['id'],
        'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
    ]);
    try {
        sendSmtpEmail(
            $account['email'],
            'Verify your Bella Vista Oasis Spa password change',
            "Hello {$account['full_name']},\n\nYour password-change verification code is {$code}. It expires in 15 minutes and can be used once. Your password will not change until you enter this code. If you did not request this change, you can ignore this message."
        );
    } catch (RuntimeException $exception) {
        error_log($exception->getMessage());
        $deleteCode = $pdo->prepare(
            'DELETE FROM guest_password_changes WHERE guest_user_id = :guest_id'
        );
        $deleteCode->execute(['guest_id' => $guest['id']]);
        sendJson(['message' => 'The verification email could not be sent. Check SMTP settings and try again.'], 503);
    }
    sendJson([
        'passwordChangePending' => true,
        'message' => 'A six-digit password-change code was sent to your current email address. It expires in 15 minutes.',
    ]);
}

if ($operation === 'guest_password_change_verify') {
    $guest = requireGuest();
    $code = trim((string) ($input['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) {
        sendJson(['message' => 'Enter the six-digit password verification code.'], 422);
    }

    $pdo = database();
    $pdo->beginTransaction();
    try {
        $pending = $pdo->prepare(
            'SELECT password_hash, code_hash, expires_at, attempts
             FROM guest_password_changes WHERE guest_user_id = :guest_id LIMIT 1 FOR UPDATE'
        );
        $pending->execute(['guest_id' => $guest['id']]);
        $change = $pending->fetch();
        if (!$change || strtotime($change['expires_at']) < time() || (int) $change['attempts'] >= 5) {
            $pdo->rollBack();
            sendJson(['message' => 'The password-change code is invalid or expired. Request a new code and try again.'], 422);
        }
        if (!password_verify($code, $change['code_hash'])) {
            $increment = $pdo->prepare(
                'UPDATE guest_password_changes SET attempts = attempts + 1 WHERE guest_user_id = :guest_id'
            );
            $increment->execute(['guest_id' => $guest['id']]);
            $pdo->commit();
            sendJson(['message' => 'The password-change code is incorrect.'], 422);
        }

        $updatePassword = $pdo->prepare(
            'UPDATE guest_users SET password_hash = :password_hash WHERE id = :guest_id'
        );
        $updatePassword->execute([
            'password_hash' => $change['password_hash'],
            'guest_id' => $guest['id'],
        ]);
        $deleteCode = $pdo->prepare(
            'DELETE FROM guest_password_changes WHERE guest_user_id = :guest_id'
        );
        $deleteCode->execute(['guest_id' => $guest['id']]);
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        sendJson(['message' => 'Your password could not be changed. Please try again.'], 500);
    }
    sendJson(['passwordChangePending' => false, 'message' => 'Your password has been verified and changed.']);
}

if ($operation === 'guest_password_change_resend') {
    $guest = requireGuest();
    $currentPassword = (string) ($input['currentPassword'] ?? '');
    if ($currentPassword === '') {
        sendJson(['message' => 'Enter your current password to request a new code.'], 422);
    }
    if (!smtpConfigurationIsComplete()) {
        sendJson(['message' => 'Email delivery is not configured, so a verification code cannot be sent.'], 503);
    }

    $pdo = database();
    $accountStatement = $pdo->prepare(
        'SELECT full_name, email, password_hash FROM guest_users WHERE id = :guest_id LIMIT 1'
    );
    $accountStatement->execute(['guest_id' => $guest['id']]);
    $account = $accountStatement->fetch();
    if (!$account || !password_verify($currentPassword, $account['password_hash'])) {
        sendJson(['message' => 'Your current password is incorrect.'], 422);
    }
    $pendingStatement = $pdo->prepare(
        'SELECT last_sent_at FROM guest_password_changes WHERE guest_user_id = :guest_id LIMIT 1'
    );
    $pendingStatement->execute(['guest_id' => $guest['id']]);
    $lastSentAt = $pendingStatement->fetchColumn();
    if (!is_string($lastSentAt)) {
        sendJson(['message' => 'There is no password change waiting for verification. Start the change again.'], 409);
    }
    if (strtotime($lastSentAt) > time() - 60) {
        sendJson(['message' => 'Please wait 60 seconds before requesting another password code.'], 429);
    }

    $code = (string) random_int(100000, 999999);
    $updateCode = $pdo->prepare(
        'UPDATE guest_password_changes
         SET code_hash = :code_hash, expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
             attempts = 0, last_sent_at = NOW()
         WHERE guest_user_id = :guest_id'
    );
    $updateCode->execute([
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'guest_id' => $guest['id'],
    ]);
    try {
        sendSmtpEmail(
            $account['email'],
            'Verify your Bella Vista Oasis Spa password change',
            "Hello {$account['full_name']},\n\nYour new password-change verification code is {$code}. It expires in 15 minutes. Your password will not change until you enter this code."
        );
    } catch (RuntimeException $exception) {
        error_log($exception->getMessage());
        $deleteCode = $pdo->prepare(
            'DELETE FROM guest_password_changes WHERE guest_user_id = :guest_id'
        );
        $deleteCode->execute(['guest_id' => $guest['id']]);
        sendJson(['message' => 'The verification email could not be sent. Start the password change again after checking SMTP settings.'], 503);
    }
    sendJson(['message' => 'A new verification code was sent to your current email address.']);
}

if ($operation === 'guest_email_change_request') {
    $guest = requireGuest();
    $currentPassword = (string) ($input['currentPassword'] ?? '');
    $newEmail = strtolower(trim((string) ($input['newEmail'] ?? '')));
    if ($currentPassword === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 254) {
        sendJson(['message' => 'Enter your current password and a valid new email address.'], 422);
    }
    if (strcasecmp($newEmail, $guest['email']) === 0) {
        sendJson(['message' => 'That is already the email address on your account.'], 422);
    }
    if (!smtpConfigurationIsComplete()) {
        sendJson(['message' => 'Email delivery is not configured, so a verification code cannot be sent.'], 503);
    }

    $pdo = database();
    $pdo->beginTransaction();
    try {
        $lockedGuest = $pdo->prepare(
            'SELECT full_name, email, password_hash FROM guest_users WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $lockedGuest->execute(['id' => $guest['id']]);
        $current = $lockedGuest->fetch();
        if (!$current || !password_verify($currentPassword, $current['password_hash'])) {
            $pdo->rollBack();
            sendJson(['message' => 'Your current password is incorrect.'], 422);
        }

        $duplicate = $pdo->prepare('SELECT id FROM guest_users WHERE email = :email LIMIT 1');
        $duplicate->execute(['email' => $newEmail]);
        if ($duplicate->fetch()) {
            $pdo->rollBack();
            sendJson(['message' => 'An account already uses that email address.'], 409);
        }

        $existing = $pdo->prepare(
            'SELECT last_sent_at FROM guest_email_changes WHERE guest_user_id = :guest_id LIMIT 1 FOR UPDATE'
        );
        $existing->execute(['guest_id' => $guest['id']]);
        $lastSentAt = $existing->fetchColumn();
        if (is_string($lastSentAt) && strtotime($lastSentAt) > time() - 60) {
            $pdo->rollBack();
            sendJson(['message' => 'Please wait 60 seconds before requesting another email-change code.'], 429);
        }

        $code = (string) random_int(100000, 999999);
        $saveCode = $pdo->prepare(
            'INSERT INTO guest_email_changes
             (guest_user_id, pending_email, code_hash, verification_stage, expires_at, attempts, last_sent_at)
             VALUES (:guest_id, :email, :code_hash, \'current\', DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, NOW())
             ON DUPLICATE KEY UPDATE pending_email = VALUES(pending_email),
               code_hash = VALUES(code_hash), verification_stage = \'current\',
               expires_at = VALUES(expires_at),
               attempts = 0, last_sent_at = NOW()'
        );
        $saveCode->execute([
            'guest_id' => $guest['id'],
            'email' => $newEmail,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        ]);
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        sendJson(['message' => 'The email-change request could not be saved. Please try again.'], 500);
    }

    try {
        sendSmtpEmail(
            $current['email'],
            'Confirm your Bella Vista Oasis Spa email change',
            "Hello {$current['full_name']},\n\nYour email-change confirmation code is {$code}. It expires in 15 minutes and can be used once. After confirming this request, a second code will be sent to your new email address. Your email will not change until both codes are verified. If you did not request this change, you can ignore this message."
        );
    } catch (RuntimeException $exception) {
        error_log($exception->getMessage());
        $removeCode = $pdo->prepare(
            'DELETE FROM guest_email_changes WHERE guest_user_id = :guest_id AND pending_email = :email'
        );
        $removeCode->execute(['guest_id' => $guest['id'], 'email' => $newEmail]);
        sendJson(['message' => 'The verification email could not be sent. Check the email address and SMTP settings, then try again.'], 503);
    }

    sendJson([
        'pendingEmail' => $newEmail,
        'verificationStage' => 'current',
        'message' => 'A six-digit confirmation code was sent to your current email address. Enter it to continue the email change.',
    ]);
}

if ($operation === 'guest_email_change_verify') {
    $guest = requireGuest();
    $code = trim((string) ($input['code'] ?? ''));
    if (!preg_match('/^\d{6}$/', $code)) {
        sendJson(['message' => 'Enter the six-digit verification code.'], 422);
    }

    $pdo = database();
    $pdo->beginTransaction();
    try {
        $lockedGuest = $pdo->prepare(
            'SELECT id, full_name, email FROM guest_users WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $lockedGuest->execute(['id' => $guest['id']]);
        $current = $lockedGuest->fetch();
        $codeStatement = $pdo->prepare(
            'SELECT pending_email, code_hash, verification_stage, expires_at, attempts
             FROM guest_email_changes WHERE guest_user_id = :guest_id LIMIT 1 FOR UPDATE'
        );
        $codeStatement->execute(['guest_id' => $guest['id']]);
        $change = $codeStatement->fetch();
        if (!$current || !$change || strtotime($change['expires_at']) < time() || (int) $change['attempts'] >= 5) {
            $pdo->rollBack();
            sendJson(['message' => 'The email-change code is invalid or expired. Request a new code and try again.'], 422);
        }
        if (!password_verify($code, $change['code_hash'])) {
            $increment = $pdo->prepare(
                'UPDATE guest_email_changes SET attempts = attempts + 1 WHERE guest_user_id = :guest_id'
            );
            $increment->execute(['guest_id' => $guest['id']]);
            $pdo->commit();
            sendJson(['message' => 'The email-change code is incorrect.'], 422);
        }

        if ($change['verification_stage'] === 'current') {
            $newCode = (string) random_int(100000, 999999);
            $advance = $pdo->prepare(
                'UPDATE guest_email_changes
                 SET code_hash = :code_hash, verification_stage = \'new\',
                     expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                     attempts = 0, last_sent_at = NOW()
                 WHERE guest_user_id = :guest_id'
            );
            $advance->execute([
                'code_hash' => password_hash($newCode, PASSWORD_DEFAULT),
                'guest_id' => $guest['id'],
            ]);
            $pdo->commit();

            try {
                sendSmtpEmail(
                    $change['pending_email'],
                    'Verify your new Bella Vista Oasis Spa email',
                    "Hello {$current['full_name']},\n\nYour new email verification code is {$newCode}. It expires in 15 minutes and can be used once. Your account email will update only after this code is verified."
                );
            } catch (RuntimeException $exception) {
                error_log($exception->getMessage());
                $removeCode = $pdo->prepare(
                    'DELETE FROM guest_email_changes WHERE guest_user_id = :guest_id'
                );
                $removeCode->execute(['guest_id' => $guest['id']]);
                sendJson(['message' => 'Your current email was verified, but a code could not be sent to the new address. Check the address and SMTP settings, then start the change again.'], 503);
            }

            sendJson([
                'verificationStage' => 'new',
                'pendingEmail' => $change['pending_email'],
                'message' => 'Your current email is verified. A second code was sent to the new address; enter it to finish the change.',
            ]);
        }

        $duplicate = $pdo->prepare(
            'SELECT id FROM guest_users WHERE email = :email AND id <> :guest_id LIMIT 1'
        );
        $duplicate->execute(['email' => $change['pending_email'], 'guest_id' => $guest['id']]);
        if ($duplicate->fetch()) {
            $pdo->rollBack();
            sendJson(['message' => 'That email address is now in use. Request a new code for another address.'], 409);
        }

        $updateEmail = $pdo->prepare('UPDATE guest_users SET email = :email WHERE id = :guest_id');
        $updateEmail->execute(['email' => $change['pending_email'], 'guest_id' => $guest['id']]);
        $attachBookings = $pdo->prepare(
            'UPDATE appointments SET guest_user_id = :guest_id, guest_email = :new_email
             WHERE guest_user_id = :linked_guest_id
                OR (guest_user_id IS NULL AND guest_email = :old_email AND guest_name = :guest_name)'
        );
        $attachBookings->execute([
            'guest_id' => $guest['id'],
            'linked_guest_id' => $guest['id'],
            'new_email' => $change['pending_email'],
            'old_email' => $current['email'],
            'guest_name' => $current['full_name'],
        ]);
        $deleteCode = $pdo->prepare('DELETE FROM guest_email_changes WHERE guest_user_id = :guest_id');
        $deleteCode->execute(['guest_id' => $guest['id']]);
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception->getCode() === '23000') {
            sendJson(['message' => 'That email address is already in use. Request a code for another address.'], 409);
        }
        error_log($exception->getMessage());
        sendJson(['message' => 'Your email address could not be changed. Please try again.'], 500);
    }

    $_SESSION['guest_email'] = $change['pending_email'];
    sendJson([
        'email' => $change['pending_email'],
        'verificationStage' => 'complete',
        'message' => 'Both email verification codes were accepted. Your email address has been updated.',
    ]);
}

if ($operation === 'guest_email_change_resend') {
    $guest = requireGuest();
    $currentPassword = (string) ($input['currentPassword'] ?? '');
    if ($currentPassword === '') {
        sendJson(['message' => 'Enter your current password to request a new code.'], 422);
    }
    if (!smtpConfigurationIsComplete()) {
        sendJson(['message' => 'Email delivery is not configured, so a verification code cannot be sent.'], 503);
    }

    $pdo = database();
    $accountStatement = $pdo->prepare(
        'SELECT full_name, email, password_hash FROM guest_users WHERE id = :guest_id LIMIT 1'
    );
    $accountStatement->execute(['guest_id' => $guest['id']]);
    $account = $accountStatement->fetch();
    if (!$account || !password_verify($currentPassword, $account['password_hash'])) {
        sendJson(['message' => 'Your current password is incorrect.'], 422);
    }
    $pendingStatement = $pdo->prepare(
        'SELECT pending_email, verification_stage, last_sent_at
         FROM guest_email_changes WHERE guest_user_id = :guest_id LIMIT 1'
    );
    $pendingStatement->execute(['guest_id' => $guest['id']]);
    $pending = $pendingStatement->fetch();
    if (!$pending) {
        sendJson(['message' => 'There is no email change waiting for verification. Start the change again.'], 409);
    }
    if (strtotime($pending['last_sent_at']) > time() - 60) {
        sendJson(['message' => 'Please wait 60 seconds before requesting another email-change code.'], 429);
    }

    $recipient = $pending['verification_stage'] === 'new'
        ? $pending['pending_email']
        : $account['email'];
    $code = (string) random_int(100000, 999999);
    $updateCode = $pdo->prepare(
        'UPDATE guest_email_changes
         SET code_hash = :code_hash, expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
             attempts = 0, last_sent_at = NOW()
         WHERE guest_user_id = :guest_id'
    );
    $updateCode->execute([
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'guest_id' => $guest['id'],
    ]);
    try {
        sendSmtpEmail(
            $recipient,
            $pending['verification_stage'] === 'new'
                ? 'Verify your new Bella Vista Oasis Spa email'
                : 'Confirm your Bella Vista Oasis Spa email change',
            "Hello {$account['full_name']},\n\nYour new email-change verification code is {$code}. It expires in 15 minutes and can be used once. If this is the current-email confirmation, verifying it will send a separate code to the new address. Your account email will not change until both steps are complete."
        );
    } catch (RuntimeException $exception) {
        error_log($exception->getMessage());
        $deleteCode = $pdo->prepare(
            'DELETE FROM guest_email_changes WHERE guest_user_id = :guest_id'
        );
        $deleteCode->execute(['guest_id' => $guest['id']]);
        sendJson(['message' => 'The verification email could not be sent. Start the email change again after checking SMTP settings.'], 503);
    }
    sendJson([
        'verificationStage' => $pending['verification_stage'],
        'pendingEmail' => $pending['pending_email'],
        'message' => 'A new verification code was sent to the address for the current verification step.',
    ]);
}

if ($operation === 'register') {
    verifyRecaptchaToken($input['recaptchaToken'] ?? null);
    $fullName = trim((string) ($input['fullName'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    if ($fullName === '' || preg_match('/^\p{L}+(?:[ ]+\p{L}+)*$/u', $fullName) !== 1 ||
        mb_strlen($fullName) > 35 || !filter_var($email, FILTER_VALIDATE_EMAIL) ||
        strlen($email) > 254 || strlen($password) < 12 || strlen($password) > 50) {
        sendJson(['message' => 'Enter a 35-character name using letters and spaces, a valid email address, and a password with 12 to 50 characters.'], 422);
    }

    $pdo = database();
    $verificationCode = (string) random_int(100000, 999999);
    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            'INSERT INTO guest_users (full_name, email, password_hash)
             VALUES (:full_name, :email, :password_hash)'
        );
        $insert->execute([
            'full_name' => $fullName,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $guestId = (int) $pdo->lastInsertId();
        $verification = $pdo->prepare(
            'INSERT INTO guest_email_verifications
             (guest_user_id, code_hash, expires_at, attempts, last_sent_at)
             VALUES (:guest_user_id, :code_hash, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, NOW())'
        );
        $verification->execute([
            'guest_user_id' => $guestId,
            'code_hash' => password_hash($verificationCode, PASSWORD_DEFAULT),
        ]);
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($exception->getCode() === '23000') {
            sendJson(['message' => 'An account already uses this email address. Sign in or request a new verification code.'], 409);
        }
        error_log($exception->getMessage());
        sendJson(['message' => 'The account could not be created. Please try again.'], 500);
    }

    try {
        sendSmtpEmail(
            $email,
            'Verify your Bella Vista Oasis Spa account',
            "Hello {$fullName},\n\nYour email verification code is {$verificationCode}. It expires in 15 minutes and can be used once.\n\nIf you did not create this account, you can ignore this message."
        );
    } catch (RuntimeException $exception) {
        error_log($exception->getMessage());
        sendJson(['message' => 'Your account was created, but the verification email could not be sent. Check SMTP settings, then request a new code.'], 503);
    }

    sendJson(['verificationRequired' => true, 'email' => $email], 201);
}

if ($operation === 'resend_verification') {
    verifyRecaptchaToken($input['recaptchaToken'] ?? null);
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendJson(['message' => 'Enter the email address used to register.'], 422);
    }

    $pdo = database();
    $guestStatement = $pdo->prepare(
        'SELECT id, full_name, email_verified_at FROM guest_users WHERE email = :email LIMIT 1'
    );
    $guestStatement->execute(['email' => $email]);
    $guest = $guestStatement->fetch();
    if ($guest && $guest['email_verified_at'] === null) {
        $existingCode = $pdo->prepare(
            'SELECT last_sent_at FROM guest_email_verifications WHERE guest_user_id = :guest_user_id'
        );
        $existingCode->execute(['guest_user_id' => $guest['id']]);
        $verification = $existingCode->fetch();
        if ($verification && strtotime($verification['last_sent_at']) > time() - 60) {
            sendJson(['message' => 'Wait at least 60 seconds before requesting another verification code.'], 429);
        }

        $verificationCode = (string) random_int(100000, 999999);
        $saveCode = $pdo->prepare(
            'INSERT INTO guest_email_verifications
             (guest_user_id, code_hash, expires_at, attempts, last_sent_at)
             VALUES (:guest_user_id, :code_hash, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, NOW())
             ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash),
               expires_at = VALUES(expires_at), attempts = 0, last_sent_at = NOW()'
        );
        $saveCode->execute([
            'guest_user_id' => $guest['id'],
            'code_hash' => password_hash($verificationCode, PASSWORD_DEFAULT),
        ]);
        try {
            sendSmtpEmail(
                $email,
                'Your Bella Vista Oasis Spa verification code',
                "Hello {$guest['full_name']},\n\nYour email verification code is {$verificationCode}. It expires in 15 minutes and can be used once."
            );
        } catch (RuntimeException $exception) {
            error_log($exception->getMessage());
            sendJson(['message' => 'The verification email could not be sent. Check SMTP settings and try again.'], 503);
        }
    }

    sendJson(['sent' => true, 'message' => 'If this address has an unverified account, a code has been sent.']);
}

if ($operation === 'password_reset_request') {
    verifyRecaptchaToken($input['recaptchaToken'] ?? null);
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        sendJson(['message' => 'Enter a valid email address.'], 422);
    }

    $pdo = database();
    $guestStatement = $pdo->prepare(
        'SELECT id, full_name FROM guest_users
         WHERE email = :email AND email_verified_at IS NOT NULL LIMIT 1'
    );
    $guestStatement->execute(['email' => $email]);
    $guest = $guestStatement->fetch();
    if ($guest) {
        $existingCode = $pdo->prepare(
            'SELECT last_sent_at FROM guest_password_resets WHERE guest_user_id = :guest_user_id'
        );
        $existingCode->execute(['guest_user_id' => $guest['id']]);
        $reset = $existingCode->fetch();
        if (!$reset || strtotime($reset['last_sent_at']) <= time() - 60) {
            $resetCode = (string) random_int(100000, 999999);
            $saveCode = $pdo->prepare(
                'INSERT INTO guest_password_resets
                 (guest_user_id, code_hash, expires_at, attempts, last_sent_at)
                 VALUES (:guest_user_id, :code_hash, DATE_ADD(NOW(), INTERVAL 15 MINUTE), 0, NOW())
                 ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash),
                   expires_at = VALUES(expires_at), attempts = 0, last_sent_at = NOW()'
            );
            $saveCode->execute([
                'guest_user_id' => $guest['id'],
                'code_hash' => password_hash($resetCode, PASSWORD_DEFAULT),
            ]);
            try {
                sendSmtpEmail(
                    $email,
                    'Your Bella Vista Oasis Spa password reset code',
                    "Hello {$guest['full_name']},\n\nYour password reset code is {$resetCode}. It expires in 15 minutes and can be used once. If you did not request a password reset, you can ignore this message."
                );
            } catch (RuntimeException $exception) {
                error_log('Password reset email delivery failed: ' . $exception->getMessage());
                $deleteReset = $pdo->prepare(
                    'DELETE FROM guest_password_resets WHERE guest_user_id = :guest_user_id'
                );
                $deleteReset->execute(['guest_user_id' => $guest['id']]);
            }
        }
    }

    sendJson([
        'sent' => true,
        'message' => 'If a verified account uses that email, we’ll send a password reset code. Check your inbox and spam folder. Codes expire after 15 minutes; wait 60 seconds before requesting another.',
    ]);
}

if ($operation === 'password_reset') {
    verifyRecaptchaToken($input['recaptchaToken'] ?? null);
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $code = trim((string) ($input['code'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code) ||
        strlen($password) < 12 || strlen($password) > 50) {
        sendJson(['message' => 'Enter your email, six-digit reset code, and a password with 12 to 50 characters.'], 422);
    }

    $pdo = database();
    $pdo->beginTransaction();
    try {
        $guestStatement = $pdo->prepare(
            'SELECT id FROM guest_users
             WHERE email = :email AND email_verified_at IS NOT NULL LIMIT 1 FOR UPDATE'
        );
        $guestStatement->execute(['email' => $email]);
        $guest = $guestStatement->fetch();
        if (!$guest) {
            $pdo->rollBack();
            sendJson(['message' => 'The reset code is invalid or expired. Request a new code and try again.'], 422);
        }

        $codeStatement = $pdo->prepare(
            'SELECT code_hash, expires_at, attempts FROM guest_password_resets
             WHERE guest_user_id = :guest_user_id LIMIT 1 FOR UPDATE'
        );
        $codeStatement->execute(['guest_user_id' => $guest['id']]);
        $reset = $codeStatement->fetch();
        if (!$reset || strtotime($reset['expires_at']) < time() || (int) $reset['attempts'] >= 5) {
            $pdo->rollBack();
            sendJson(['message' => 'The reset code is invalid or expired. Request a new code and try again.'], 422);
        }
        if (!password_verify($code, $reset['code_hash'])) {
            $incrementAttempts = $pdo->prepare(
                'UPDATE guest_password_resets SET attempts = attempts + 1
                 WHERE guest_user_id = :guest_user_id'
            );
            $incrementAttempts->execute(['guest_user_id' => $guest['id']]);
            $pdo->commit();
            sendJson(['message' => 'The reset code is incorrect.'], 422);
        }

        $updatePassword = $pdo->prepare(
            'UPDATE guest_users SET password_hash = :password_hash WHERE id = :id'
        );
        $updatePassword->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $guest['id'],
        ]);
        $deleteReset = $pdo->prepare(
            'DELETE FROM guest_password_resets WHERE guest_user_id = :guest_user_id'
        );
        $deleteReset->execute(['guest_user_id' => $guest['id']]);
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        sendJson(['message' => 'Your password could not be reset. Please try again.'], 500);
    }

    sendJson(['reset' => true, 'message' => 'Your password has been updated. Sign in with your new password.']);
}

if ($operation === 'verify_email') {
    verifyRecaptchaToken($input['recaptchaToken'] ?? null);
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $code = trim((string) ($input['code'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
        sendJson(['message' => 'Enter the email address and six-digit verification code.'], 422);
    }

    $pdo = database();
    $pdo->beginTransaction();
    try {
        $guestStatement = $pdo->prepare(
            'SELECT id, full_name, email, email_verified_at FROM guest_users
             WHERE email = :email LIMIT 1 FOR UPDATE'
        );
        $guestStatement->execute(['email' => $email]);
        $guest = $guestStatement->fetch();
        if (!$guest) {
            $pdo->rollBack();
            sendJson(['message' => 'The verification code is invalid or expired.'], 422);
        }
        if ($guest['email_verified_at'] !== null) {
            $pdo->commit();
            sendJson(['message' => 'This email is already verified. You can sign in.'], 409);
        }

        $codeStatement = $pdo->prepare(
            'SELECT code_hash, expires_at, attempts FROM guest_email_verifications
             WHERE guest_user_id = :guest_user_id LIMIT 1 FOR UPDATE'
        );
        $codeStatement->execute(['guest_user_id' => $guest['id']]);
        $verification = $codeStatement->fetch();
        if (!$verification || strtotime($verification['expires_at']) < time() || (int) $verification['attempts'] >= 5) {
            $pdo->rollBack();
            sendJson(['message' => 'The verification code is invalid or expired. Request a new code and try again.'], 422);
        }
        if (!password_verify($code, $verification['code_hash'])) {
            $incrementAttempts = $pdo->prepare(
                'UPDATE guest_email_verifications SET attempts = attempts + 1
                 WHERE guest_user_id = :guest_user_id'
            );
            $incrementAttempts->execute(['guest_user_id' => $guest['id']]);
            $pdo->commit();
            sendJson(['message' => 'The verification code is incorrect.'], 422);
        }

        $markVerified = $pdo->prepare(
            'UPDATE guest_users SET email_verified_at = NOW() WHERE id = :id'
        );
        $markVerified->execute(['id' => $guest['id']]);
        $deleteCode = $pdo->prepare(
            'DELETE FROM guest_email_verifications WHERE guest_user_id = :guest_user_id'
        );
        $deleteCode->execute(['guest_user_id' => $guest['id']]);
        $pdo->commit();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($exception->getMessage());
        sendJson(['message' => 'Email verification could not be completed. Please try again.'], 500);
    }

    session_regenerate_id(true);
    $_SESSION['guest_id'] = (int) $guest['id'];
    $_SESSION['guest_name'] = $guest['full_name'];
    $_SESSION['guest_email'] = $guest['email'];
    sendJson(['authenticated' => true, 'name' => $guest['full_name']]);
}

if ($operation === 'guest_login') {
    verifyRecaptchaToken($input['recaptchaToken'] ?? null);
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $password = (string) ($input['password'] ?? '');
    if (strlen($password) > 50) {
        sendJson(['message' => 'Password cannot exceed 50 characters.'], 422);
    }
    $statement = database()->prepare(
        'SELECT id, full_name, email, password_hash, email_verified_at
         FROM guest_users WHERE email = :email LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $guest = $statement->fetch();
    if (!$guest || !password_verify($password, $guest['password_hash'])) {
        sendJson(['message' => 'The email or password is incorrect.'], 401);
    }
    if ($guest['email_verified_at'] === null) {
        sendJson(['message' => 'Verify your email address before signing in.'], 403);
    }

    session_regenerate_id(true);
    $_SESSION['guest_id'] = (int) $guest['id'];
    $_SESSION['guest_name'] = $guest['full_name'];
    $_SESSION['guest_email'] = $guest['email'];
    sendJson(['authenticated' => true, 'name' => $guest['full_name']]);
}

if ($operation === 'login') {
    verifyRecaptchaToken($input['recaptchaToken'] ?? null);
    $username = trim((string) ($input['username'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    if (strlen($password) > 50) {
        sendJson(['message' => 'Password cannot exceed 50 characters.'], 422);
    }
    $statement = database()->prepare('SELECT id, username, full_name, password_hash FROM admin_users WHERE username = ?');
    $statement->execute([$username]);
    $manager = $statement->fetch();

    if (!$manager || !password_verify($password, $manager['password_hash'])) {
        sendJson(['message' => 'The username or password is incorrect.'], 401);
    }

    session_regenerate_id(true);
    $_SESSION['manager_id'] = (int) $manager['id'];
    $_SESSION['manager_name'] = $manager['full_name'];
    sendJson(['authenticated' => true, 'name' => $manager['full_name']]);
}

sendJson(['message' => 'Unknown authentication operation.'], 404);
