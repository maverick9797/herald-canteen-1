<?php
require_once "../includes/auth.php";
start_session();
session_security_check();
require_once "../config/db.php";
require_once "../includes/functions.php";
require_once "../includes/otp_helpers.php";


$field_errors = [];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!empty($_SESSION['register_error'])) {
    $field_errors['general'] = $_SESSION['register_error'];
    unset($_SESSION['register_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name        = trim($_POST['full_name'] ?? '');
    $email            = strtolower(trim($_POST['email'] ?? ''));
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $field_errors['general'] = 'Invalid request token. Please refresh and try again.';
    }

    if (empty($field_errors)) {
        if ($full_name === '') {
            $field_errors['full_name'] = 'Full name is required.';
        } elseif (strlen($full_name) < 2) {
            $field_errors['full_name'] = 'Full name must be at least 2 characters.';
        } elseif (strlen($full_name) > 100) {
            $field_errors['full_name'] = 'Full name must be 100 characters or fewer.';
        }

        if ($email === '') {
            $field_errors['email'] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $field_errors['email'] = 'Please enter a valid email address.';
        } elseif (strlen($email) > 150) {
            $field_errors['email'] = 'Email address is too long.';
        }

        if ($password === '') {
            $field_errors['password'] = 'Password is required.';
        } elseif (trim($password) === '') {
            $field_errors['password'] = 'Password cannot consist of whitespace only.';
        } elseif (strlen($password) < 10) {
            $field_errors['password'] = 'Password must be at least 10 characters.';
        }

        if ($confirm_password === '') {
            $field_errors['confirm_password'] = 'Please confirm your password.';
        } elseif ($password !== $confirm_password) {
            $field_errors['confirm_password'] = 'Passwords do not match.';
        }
    }

    if (empty($field_errors) && email_exists($conn, $email)) {
        $field_errors['email'] = 'This email is already registered.';
    }

    if (empty($field_errors)) {
        if (!save_pending_registration($conn, $full_name, $email, $password, null)) {
            $field_errors['general'] = 'Registration could not start. Please try again.';
        } else {
            $otp_result = issue_otp($conn, null, $email, $full_name, 'register');

            if ($otp_result['ok']) {
                $_SESSION['otp_pending'] = [
                    'purpose'   => 'register',
                    'email'     => $email,
                    'user_id'   => null,
                    'full_name' => $full_name,
                    'new_email' => null,
                ];
                session_write_close();
                header('Location: verify_otp.php');
                exit;
            }

            $field_errors['general'] = 'Verification email could not be sent: ' . $otp_result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — Herald Canteen</title>
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
            <p>Create Your Account</p>
        </div>

        <?php if (!empty($field_errors['general'])): ?>
            <div class="alert error">⚠️ <?= htmlspecialchars($field_errors['general'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST" class="login-form" id="registerForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="input-group <?= isset($field_errors['full_name']) ? 'has-error' : '' ?>">
                <label>Full Name</label>
                <div class="input-wrapper">
                    <span class="input-icon">👤</span>
                    <input type="text" name="full_name" placeholder="Enter your full name" value="<?= htmlspecialchars($_POST['full_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <?php if (isset($field_errors['full_name'])): ?><span class="field-error">⚠️ <?= htmlspecialchars($field_errors['full_name'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
            </div>

            <div class="input-group <?= isset($field_errors['email']) ? 'has-error' : '' ?>">
                <label>Email Address</label>
                <div class="input-wrapper">
                    <span class="input-icon">📧</span>
                    <input type="email" name="email" placeholder="Enter your email" value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <?php if (isset($field_errors['email'])): ?><span class="field-error">⚠️ <?= htmlspecialchars($field_errors['email'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
            </div>

            <div class="input-group <?= isset($field_errors['password']) ? 'has-error' : '' ?>">
                <label>Password</label>
                <div class="input-wrapper">
                    <span class="input-icon">🔒</span>
                    <input type="password" name="password" placeholder="At least 10 characters" required>
                </div>
                <?php if (isset($field_errors['password'])): ?><span class="field-error">⚠️ <?= htmlspecialchars($field_errors['password'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
            </div>

            <div class="input-group <?= isset($field_errors['confirm_password']) ? 'has-error' : '' ?>">
                <label>Confirm Password</label>
                <div class="input-wrapper">
                    <span class="input-icon">🔐</span>
                    <input type="password" name="confirm_password" placeholder="Confirm your password" required>
                </div>
                <?php if (isset($field_errors['confirm_password'])): ?><span class="field-error">⚠️ <?= htmlspecialchars($field_errors['confirm_password'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
            </div>

            <button type="submit" class="login-btn" id="registerBtn">Send Verification Code</button>
        </form>

        <div class="register-link">
            <p>Already have an account? <a href="portal-login.php">Login here</a></p>
        </div>
    </div>
</div>
<script>
const registerForm = document.getElementById('registerForm');
const registerBtn = document.getElementById('registerBtn');
registerForm?.addEventListener('submit', () => {
    registerBtn.disabled = true;
    registerBtn.textContent = 'Sending code…';
});
</script>
</body>
</html>
