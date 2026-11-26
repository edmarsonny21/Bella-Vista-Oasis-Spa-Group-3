<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/_bootstrap.php';

$therapistId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
$username = trim($argv[2] ?? '');
if (!$therapistId || !preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    fwrite(STDERR, "Usage: php scripts/create_therapist.php <therapist-id> <username>\n");
    exit(1);
}

fwrite(STDOUT, 'New therapist password (minimum 12 characters): ');
$password = trim((string) fgets(STDIN));
if (strlen($password) < 12) {
    fwrite(STDERR, "Use a password with at least 12 characters.\n");
    exit(1);
}

$statement = database()->prepare(
    'UPDATE therapists SET username = ?, password_hash = ? WHERE id = ?'
);
$statement->execute([$username, password_hash($password, PASSWORD_DEFAULT), $therapistId]);
if ($statement->rowCount() !== 1) {
    fwrite(STDERR, "Therapist not found or account details were unchanged.\n");
    exit(1);
}

fwrite(STDOUT, "Therapist login saved.\n");
