<?php
require_once "../includes/auth.php";
start_session();
session_security_check();
require_once "../config/db.php";
require_once "../includes/functions.php";
require_once "../includes/otp_helpers.php";

// Generate CSRF token before any POST handling so it's always available
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$ip_address  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rate_status = is_rate_limited($conn, $ip_address);
$field_errors = [];

if ($rate_status['blocked']) {
    $field_errors['general'] = "Too many failed login attempts. Please try again in {$rate_status['remaining']} minute(s).";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($field_errors['general'])) {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';

    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $field_errors['general'] = 'Invalid request token. Please refresh and try again.';
    }

    if (empty($field_errors)) {
        if ($email === '') {
            $field_errors['email'] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $field_errors['email'] = 'Please enter a valid email address.';
        }
    }

    if (empty($field_errors)) {
        if ($password === '') {
            $field_errors['password'] = 'Password is required.';
        } elseif (trim($password) === '') {
            $field_errors['password'] = 'Password cannot consist of whitespace only.';
        }
    }

    if (empty($field_errors)) {
        $user = find_user_by_email($conn, $email);

        if (!$user) {
            log_failed_attempt($conn, $ip_address);
            log_user_event($conn, 'login_failed', $ip_address, "Login failed: no account for email '{$email}'");
            $field_errors['email'] = 'No account found with this email.';
        } elseif ((int)$user['is_active'] !== 1) {
            log_failed_attempt($conn, $ip_address);
            log_user_event($conn, 'login_failed', $ip_address, "Login failed: account inactive for '{$email}'", (int)$user['user_id']);
            $field_errors['general'] = 'This account is inactive.';
        } elseif (!in_array($user['role'], ['customer', 'chef', 'staff'], true)) {
            log_failed_attempt($conn, $ip_address);
            log_user_event($conn, 'login_failed', $ip_address, "Login failed: disallowed role '{$user['role']}' for '{$email}'", (int)$user['user_id']);
            $field_errors['general'] = 'This account role is not allowed to log in.';
        } elseif (!password_verify($password, $user['password'])) {
            log_failed_attempt($conn, $ip_address);
            log_user_event($conn, 'login_failed', $ip_address, "Login failed: wrong password for '{$email}'", (int)$user['user_id']);
            $field_errors['password'] = 'Incorrect password.';
        } else {
            clear_failed_attempts($conn, $ip_address);

            if (in_array($user['role'], ['chef', 'staff'], true)) {
                unset($_SESSION['otp_pending']);
                $_SESSION['role_secret_pending'] = [
                    'user_id'    => (int)$user['user_id'],
                    'email'      => $user['email'],
                    'role'       => $user['role'],
                    'full_name'  => $user['full_name'],
                    'started_at' => time(),
                    'attempts'   => 0,
                ];
                session_write_close();
                header('Location: verify_role_secret.php');
                exit;
            }

            if (empty($user['email_verified_at'])) {
                $otp_result = issue_otp($conn, (int)$user['user_id'], $user['email'], $user['full_name'], 'register');
                if ($otp_result['ok']) {
                    $_SESSION['otp_pending'] = [
                        'purpose'   => 'register',
                        'email'     => $user['email'],
                        'user_id'   => (int)$user['user_id'],
                        'full_name' => $user['full_name'],
                        'new_email' => null,
                    ];
                    session_write_close();
                    header('Location: verify_otp.php');
                    exit;
                }
                $field_errors['general'] = 'Please verify your email before logging in. Verification email could not be sent: ' . $otp_result['error'];
            } elseif ((int)($user['mfa_enabled'] ?? 0) === 1) {
                $otp_result = issue_otp($conn, (int)$user['user_id'], $user['email'], $user['full_name'], 'login');
                if ($otp_result['ok']) {
                    $_SESSION['otp_pending'] = [
                        'purpose'   => 'login',
                        'email'     => $user['email'],
                        'user_id'   => (int)$user['user_id'],
                        'full_name' => $user['full_name'],
                        'new_email' => null,
                    ];
                    session_write_close();
                    header('Location: verify_otp.php');
                    exit;
                }
                $field_errors['general'] = $otp_result['error'];
            } else {
                log_user_event($conn, 'login_success', $ip_address, "Login successful for '{$user['email']}'", (int)$user['user_id']);
                login_user($user);
                redirect_user_by_role($user['role']);
            }
        }

        // Second rate-limit check: necessary because log_failed_attempt() was
        // called inside the validation block above (after the first check passed).
        // This catch ensures that if THIS attempt pushed the IP over the threshold,
        // the lockout message overrides all other field errors before rendering.
        // Without this, the user would see "wrong password" on the 5th attempt
        // instead of the lockout notice, and the form would still be enabled.
        if (!empty($field_errors)) {
            $rate_status = is_rate_limited($conn, $ip_address);
            if ($rate_status['blocked']) {
                $field_errors = ['general' => "Too many failed login attempts. Please try again in {$rate_status['remaining']} minute(s)."];
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Login — Herald Canteen</title>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
<div class="container">
    <div class="login-box">
        <div style="position:absolute;top:18px;right:18px;">
          <label class="theme-toggle" title="Toggle light/dark mode">
            <input type="checkbox" class="theme-checkbox">
            <span class="theme-slider"></span>
          </label>
        </div>

        <div class="logo">
            <h1>Herald <span>Canteen</span></h1>
            <p>Login to Your Account</p>
        </div>

        <?php if (isset($_GET['timeout'])): ?><div class="alert error">⏳ Your session has expired. Please log in again.</div><?php endif; ?>
        <?php if (isset($_GET['reason']) && $_GET['reason'] === 'security'): ?><div class="alert error">🔒 Your session was reset for security reasons. Please log in again.</div><?php endif; ?>
        <?php if (isset($_GET['registered'])): ?><div class="alert success">✅ Email verified successfully. Please log in.</div><?php endif; ?>
        <?php if (isset($_GET['pw_reset'])): ?><div class="alert success">✅ Password reset successfully. Please log in with your new password.</div><?php endif; ?>
        <?php if (!empty($_SESSION['login_error'])): ?><div class="alert error">⚠️ <?= htmlspecialchars($_SESSION['login_error'], ENT_QUOTES, 'UTF-8') ?></div><?php unset($_SESSION['login_error']); endif; ?>
        <?php if (!empty($field_errors['general'])): ?><div class="alert error">⚠️ <?= htmlspecialchars($field_errors['general'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <form method="POST" class="login-form" id="loginForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="input-group <?= isset($field_errors['email']) ? 'has-error' : '' ?>">
                <label>Email Address</label>
                <div class="input-wrapper">
                    <span class="input-icon">📧</span>
                    <input type="email" name="email" placeholder="Enter your email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required <?= $rate_status['blocked'] ? 'disabled' : '' ?>>
                </div>
                <?php if (isset($field_errors['email'])): ?><span class="field-error">⚠️ <?= htmlspecialchars($field_errors['email'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
            </div>

            <div class="input-group <?= isset($field_errors['password']) ? 'has-error' : '' ?>">
                <label>Password</label>
                <div class="input-wrapper">
                    <span class="input-icon">🔒</span>
                    <input type="password" name="password" placeholder="Enter your password" required <?= $rate_status['blocked'] ? 'disabled' : '' ?>>
                </div>
                <?php if (isset($field_errors['password'])): ?><span class="field-error">⚠️ <?= htmlspecialchars($field_errors['password'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                <div style="text-align:right;margin-top:6px;"><a href="forgot_password.php" style="font-size:0.82rem;color:#22c55e;text-decoration:none;">Forgot password?</a></div>
            </div>

            <button type="submit" class="login-btn" id="loginBtn" <?= $rate_status['blocked'] ? 'disabled' : '' ?>><?= $rate_status['blocked'] ? '🔒 Account Locked' : 'Login' ?></button>
        </form>

        <div class="register-link">
            <p>Don't have an account? <a href="register.php">Create Account</a></p>
        </div>
    </div>
</div>
<script>
const loginForm = document.getElementById('loginForm');
const loginBtn = document.getElementById('loginBtn');
loginForm?.addEventListener('submit', () => {
    if (loginBtn) {
        loginBtn.disabled = true;
        loginBtn.textContent = 'Checking…';
    }
});
</script>
</body>
</html>
