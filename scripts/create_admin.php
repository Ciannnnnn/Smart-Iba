<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/database.php';

$email = strtolower(trim((string) getenv('ADMIN_EMAIL')));
$password = (string) getenv('ADMIN_PASSWORD');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
    fwrite(STDERR, "Set ADMIN_EMAIL to a valid email and ADMIN_PASSWORD to at least 12 characters.\n");
    exit(1);
}

try {
    $database = app_database();
    ensure_admin_schema($database);
    $statement = $database->prepare(
        'INSERT INTO admin_users (email, password_hash) VALUES (:email, :password_hash)
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
    );
    $statement->execute([
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    fwrite(STDOUT, "Admin account created or updated.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Could not create the admin account: " . $exception->getMessage() . "\n");
    exit(1);
}
