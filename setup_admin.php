<?php

declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => (getenv('SESSION_COOKIE_SECURE') === 'true') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/includes/database.php';

$error = '';
$setupToken = (string) getenv('ADMIN_SETUP_TOKEN');
$csrfToken = $_SESSION['admin_setup_csrf'] ??= bin2hex(random_bytes(32));

try {
    if ($setupToken === '') {
        throw new RuntimeException('Admin setup is not enabled.');
    }

    $database = app_database();
    ensure_admin_schema($database);
    $adminCount = (int) $database->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();

    if ($adminCount > 0) {
        throw new RuntimeException('An admin account already exists. Use the login page.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        $submittedSetupToken = (string) ($_POST['setup_token'] ?? '');
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        if (!hash_equals($csrfToken, $submittedToken) || !hash_equals($setupToken, $submittedSetupToken)) {
            $error = 'The setup token is invalid.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (strlen($password) < 12) {
            $error = 'Use a password with at least 12 characters.';
        } elseif (!hash_equals($password, $confirmation)) {
            $error = 'The passwords do not match.';
        } else {
            $statement = $database->prepare('INSERT INTO admin_users (email, password_hash) VALUES (:email, :password_hash)');
            $statement->execute([
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = (int) $database->lastInsertId();
            $_SESSION['admin_email'] = $email;
            unset($_SESSION['admin_setup_csrf']);
            header('Location: index.php');
            exit;
        }
    }
} catch (Throwable $exception) {
    error_log('Admin setup error: ' . $exception->getMessage());
    $error = $exception->getMessage() === 'An admin account already exists. Use the login page.'
        ? $exception->getMessage()
        : 'Admin setup is unavailable. Check the Railway database variables.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create Admin Account</title>
    <style>body{font-family:Arial,sans-serif;max-width:420px;margin:5rem auto;padding:0 1rem}.field{display:block;margin:1rem 0}.field input{box-sizing:border-box;width:100%;padding:.7rem;margin-top:.35rem}.error{color:#b00020}.button{padding:.7rem 1rem;cursor:pointer}</style>
</head>
<body>
    <h1>Create admin account</h1>
    <?php if ($error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php else: ?>
        <form method="post">
            <label class="field">Setup token<input type="password" name="setup_token" required autocomplete="off"></label>
            <label class="field">Email<input type="email" name="email" required autocomplete="email"></label>
            <label class="field">Password<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
            <label class="field">Confirm password<input type="password" name="password_confirmation" required minlength="12" autocomplete="new-password"></label>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <button class="button" type="submit">Create admin account</button>
        </form>
    <?php endif; ?>
</body>
</html>
