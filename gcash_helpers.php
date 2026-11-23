<?php
declare(strict_types=1);
require_once __DIR__ . '/api/_bootstrap.php';

function gcashEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function gcashRequireGuest(): array
{
    startManagerSession();
    $guestId = $_SESSION['guest_id'] ?? null;
    if (!is_int($guestId)) {
        http_response_code(401);
        exit('<!doctype html><html lang="en"><meta charset="utf-8"><title>Sign in required</title><p>Please <a href="./">sign in with your verified guest account</a> to continue.</p></html>');
    }

    $statement = database()->prepare(
        'SELECT id, full_name, email FROM guest_users
         WHERE id = :id AND email_verified_at IS NOT NULL LIMIT 1'
    );
    $statement->execute(['id' => $guestId]);
    $guest = $statement->fetch();
    if (!$guest) {
        unset($_SESSION['guest_id'], $_SESSION['guest_name'], $_SESSION['guest_email']);
        http_response_code(401);
        exit('<!doctype html><html lang="en"><meta charset="utf-8"><title>Sign in required</title><p>Please <a href="./">sign in with your verified guest account</a> to continue.</p></html>');
    }

    return $guest;
}

function gcashRequireManager(): int
{
    startManagerSession();
    $managerId = $_SESSION['manager_id'] ?? null;
    if (!is_int($managerId)) {
        http_response_code(401);
        exit('<!doctype html><html lang="en"><meta charset="utf-8"><title>Manager sign in required</title><p>Please <a href="manager">sign in to the manager console</a> to continue.</p></html>');
    }

    return $managerId;
}

function gcashCsrfToken(): string
{
    startManagerSession();
    if (!isset($_SESSION['gcash_csrf_token'])) {
        $_SESSION['gcash_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['gcash_csrf_token'];
}

function gcashRequireCsrf(mixed $token): void
{
    startManagerSession();
    $expected = $_SESSION['gcash_csrf_token'] ?? '';
    if (!is_string($token) || !is_string($expected) || $expected === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        exit('The form expired or could not be verified. Reload the page and try again.');
    }
}

function gcashFindGuestAppointment(string $orderId, int $guestId): ?array
{
    $statement = database()->prepare(
        "SELECT id, booking_code, guest_name, guest_email, total_amount, booking_date, status
         FROM appointments
         WHERE booking_code = :booking_code AND guest_user_id = :guest_id
           AND status <> 'cancelled'
         LIMIT 1"
    );
    $statement->execute(['booking_code' => $orderId, 'guest_id' => $guestId]);
    $appointment = $statement->fetch();

    return $appointment ?: null;
}

function gcashMoney(mixed $amount): string
{
    return '₱' . number_format((float) $amount, 2);
}
