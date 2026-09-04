<?php

declare(strict_types=1);

function app_database(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $databaseUrl = trim((string) getenv('DATABASE_URL'));
    if ($databaseUrl !== '') {
        $parts = parse_url($databaseUrl);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            throw new RuntimeException('DATABASE_URL is invalid.');
        }

        $host = $parts['host'];
        $port = (int) ($parts['port'] ?? 3306);
        $database = ltrim($parts['path'], '/');
        $username = urldecode((string) ($parts['user'] ?? ''));
        $password = urldecode((string) ($parts['pass'] ?? ''));
    } else {
        $host = trim((string) (getenv('MYSQLHOST') ?: getenv('MYSQL_HOST')));
        $port = (int) (getenv('MYSQLPORT') ?: getenv('MYSQL_PORT') ?: 3306);
        $database = trim((string) (getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE')));
        $username = trim((string) (getenv('MYSQLUSER') ?: getenv('MYSQL_USER')));
        $password = (string) (getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD'));
    }

    if ($host === '' || $database === '' || $username === '') {
        throw new RuntimeException('Database variables are not configured.');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function ensure_admin_schema(PDO $database): void
{
    $database->exec(
        'CREATE TABLE IF NOT EXISTS admin_users (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $database->exec(
        'CREATE TABLE IF NOT EXISTS admin_login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX admin_login_attempts_email_ip_time (email, ip_address, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}
