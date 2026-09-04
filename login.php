<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => (getenv('SESSION_COOKIE_SECURE') === 'true') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    'samesite' => 'Lax',
]);
session_start();
require_once __DIR__ . '/includes/database.php';

// If already logged in, redirect to index
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
$csrfToken = $_SESSION['login_csrf'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf_token'] ?? '');
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);

    if (!hash_equals($csrfToken, $submittedToken)) {
        $error = 'Your session expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email or password.';
    } else {
        try {
            $database = app_database();
            $rateLimit = $database->prepare(
                'SELECT COUNT(*) FROM admin_login_attempts
                 WHERE email = :email AND ip_address = :ip_address
                 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)'
            );
            $rateLimit->execute(['email' => $email, 'ip_address' => $ipAddress]);
            if ((int) $rateLimit->fetchColumn() >= 5) {
                $error = 'Too many failed attempts. Please try again in 15 minutes.';
                throw new RuntimeException('Login rate limit reached.');
            }

            $statement = $database->prepare('SELECT id, email, password_hash FROM admin_users WHERE email = :email LIMIT 1');
            $statement->execute(['email' => $email]);
            $admin = $statement->fetch();

            if (is_array($admin) && password_verify($password, $admin['password_hash'])) {
                if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
                    $database->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id')
                        ->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'id' => $admin['id']]);
                }
                $database->prepare('DELETE FROM admin_login_attempts WHERE email = :email AND ip_address = :ip_address')
                    ->execute(['email' => $email, 'ip_address' => $ipAddress]);
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = (int) $admin['id'];
                $_SESSION['admin_email'] = $admin['email'];
                unset($_SESSION['login_csrf']);
                header('Location: index.php');
                exit;
            }
            $database->prepare('INSERT INTO admin_login_attempts (email, ip_address) VALUES (:email, :ip_address)')
                ->execute(['email' => $email, 'ip_address' => $ipAddress]);
            $error = 'Invalid email or password.';
        } catch (Throwable $exception) {
            if ($error === '') {
                error_log('Admin login database error: ' . $exception->getMessage());
                $error = 'Login is temporarily unavailable. Please try again later.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login</title>
    <style>

        *{
            box-sizing: border-box;
            transition: all 0.3s ease;
        }
        
        body {
            background: linear-gradient(to top left,rgba(46, 46, 255, 0.5), rgba(27, 255, 255, 0.5));
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            overflow: hidden;
        }
        .login-box {
            background: rgba(255, 255, 255, 0.5);
            padding: 32px 28px;
            border-radius: 18px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            width: 500px;
            text-align: center;
            min-height: 400px;
            box-shadow: 8px 8px 20px rgba(0, 0, 0, 0.3),8px 8px 20px rgba(0, 0, 0, 0.3),8px 8px 20px rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(8px);
        }
        .login-box h1 {
            margin-bottom: 10px;
        }
        .login-box h3 {
            margin-top: 0;
            margin-bottom: 24px;
            font-weight: 500;
            color: #1b2a49;
        }
        .login-box form {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .login-box label {
            display: block;
            margin-bottom: 5px;
            text-align: left;
            width: 80%;
            margin-left: 0;
            font-weight: 600;
            color: #1b2a49;
        }
        .login-box input {
            width: 80%;
            padding: 11px 14px;
            margin-bottom: 15px;
            border: 1px solid rgba(87, 54, 249, 0.25);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.85);
            outline: none;
        }
        .login-box input:focus {
            border-color: #5736f9;
            box-shadow: 0 0 0 3px rgba(87, 54, 249, 0.12);
        }
        .password-field {
            position: relative;
            width: 80%;
            margin: 0 auto 15px;
        }
        .password-field input {
            width: 100%;
            margin-bottom: 0;
            padding-right: 42px;
        }
        .password-toggle {
            position: absolute;
            top: 50%;
            right: 10px;
            transform: translateY(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            border: none;
            background: transparent;
            cursor: pointer;
            color: #555;
            width: 24px;
            height: 24px;
            margin: 0;
            box-shadow: none;
            border-radius: 0;
            z-index: 2;
        }
        .password-toggle svg {
            width: 20px;
            height: 20px;
        }
        .password-toggle:hover {
            color: #5736f9;
            background: transparent;
        }
        .password-toggle .eye-off {
            display: none;
        }
        .password-toggle.is-visible .eye {
            display: none;
        }
        .password-toggle.is-visible .eye-off {
            display: block;
        }
        .login-submit {
            width: 30%;
            height: 40px;
            padding: 10px;
            margin-top: 18px;
            background-color: #5736f9;
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            box-shadow: 4px 4px 15px rgba(0, 0, 0, 0.2),4px 4px 15px rgba(0, 0, 0, 0.2),4px 4px 15px rgba(0, 0, 0, 0.2);
            font-weight: 600;
        }
        .login-submit:hover {
            background-color: #1bf8e9;
            color: black;
        }
        .error {
            color: red;
            margin-bottom: 18px;
            min-height: 20px;
            font-size: 14px;
        }
        .bubble {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            animation: float 6s infinite ease-in-out;
        }
        @keyframes float {
            0% { transform: translateY(150vh) scale(0); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { transform: translateY(-100vh) scale(1); opacity: 0; }
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>Admin Login</h1>
        <h3>Smart Iba Admin Panel</h3>
        <p class="error"><?php if ($error) echo htmlspecialchars($error); ?></p>
        <form method="post" action="login.php">
            <label for="email">Email:</label>
            <input type="email" id="email" name="email" placeholder="Enter email" autocomplete="email" required>
            <label for="password">Password:</label>
            <div class="password-field">
                <input type="password" id="password" name="password" placeholder="Enter password" autocomplete="current-password" required>
                <button type="button" class="password-toggle" id="passwordToggle" aria-label="Show password" aria-controls="password" aria-pressed="false">
                    <svg class="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                    <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M10.58 10.58a2 2 0 1 0 2.83 2.83"></path>
                        <path d="M9.88 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a17.56 17.56 0 0 1-2.16 3.19"></path>
                        <path d="M6.71 6.72A17.08 17.08 0 0 0 1 12s4 8 11 8a10.94 10.94 0 0 0 5.29-1.28"></path>
                        <path d="M3 3l18 18"></path>
                    </svg>
                </button>
            </div>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="login-submit">Login</button>
        </form>
    </div>
    <script>
        const passwordInput = document.getElementById('password');
        const passwordToggle = document.getElementById('passwordToggle');

        passwordToggle.addEventListener('click', () => {
            const isVisible = passwordInput.type === 'text';
            passwordInput.type = isVisible ? 'password' : 'text';
            passwordToggle.classList.toggle('is-visible', !isVisible);
            passwordToggle.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');
            passwordToggle.setAttribute('aria-pressed', String(!isVisible));
        });

        function createBubble() {
            const bubble = document.createElement('div');
            bubble.className = 'bubble';
            const size = Math.random() * 30 + 10; // Random size 10-40px
            bubble.style.width = size + 'px';
            bubble.style.height = size + 'px';
            bubble.style.left = Math.random() * 100 + '%'; // Random horizontal position
            bubble.style.animationDuration = (Math.random() * 5 + 5) + 's'; // 5-10s duration
            bubble.style.animationDelay = Math.random() * 2 + 's'; // Random delay
            document.body.appendChild(bubble);
            // Remove bubble after animation
            setTimeout(() => {
                if (bubble.parentNode) {
                    bubble.parentNode.removeChild(bubble);
                }
            }, 12000); // Slightly longer than max duration
        }
        // Create bubbles at random intervals
        setInterval(createBubble, 500); // Every 1-3 seconds
    </script>
</body>
</html>
