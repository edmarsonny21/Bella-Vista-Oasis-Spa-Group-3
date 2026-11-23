<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/_bootstrap.php';

$username = trim($argv[1] ?? '');
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    fwrite(STDERR, "Usage: php scripts/create_manager.php <username>\n");
    exit(1);
}

fwrite(STDOUT, 'New manager password (minimum 12 characters): ');
$password = trim((string) fgets(STDIN));
if (strlen($password) < 12) {
    fwrite(STDERR, "Use a password with at least 12 characters.\n");
    exit(1);
}

fwrite(STDOUT, 'Manager name: ');
$fullName = trim((string) fgets(STDIN)) ?: $username;
$statement = database()->prepare(
    'INSERT INTO admin_users (username, password_hash, full_name)
     VALUES (:username, :password_hash, :full_name)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), full_name = VALUES(full_name)'
);
$statement->execute([
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'full_name' => $fullName,
]);
fwrite(STDOUT, "Manager account saved.\n");
