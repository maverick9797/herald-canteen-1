<?php
// ============================================================
// pages/forgot_password.php — Herald Canteen
// ============================================================
// Step 1 of the forgot-password flow: collect email, issue OTP.
//
// Security: We always show a generic success message whether or
// not the email exists in the database, to prevent enumeration.
// The OTP is only issued (and email only sent) when the user
// is found and active.
// ============================================================

require_once '../includes/auth.php';
start_session();
session_security_check();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/otp_helpers.php';

// NOTE: We intentionally allow logged-in users to visit this page.
// A logged-in staff/chef user may still need to reset their password.
// Redirecting them away was causing the "redirected to dashboard on first visit" bug.

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$field_errors = [];
$sent_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $field_errors['general'] = 'Invalid request token. Please refresh and try again.';
    } else {
        $email = trim($_POST['email'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $field_errors['email'] = 'Please enter a valid email address.';
        } else {
            // Generic message shown regardless of whether the account exists
            $sent_message = 'If an account with that email exists, '
                          . 'a password reset code has been sent. '
                          . 'Please check your inbox (and spam folder).';

            // Look up the user — but do NOT reveal the result in the UI
            $user = find_user_by_email($conn, $email);

            if ($user && (int)$user['is_active'] === 1) {
                // Issue OTP (cooldown is also silently respected — same message shown)
                $result = issue_otp(
                    $conn,
                    (int)$user['user_id'],
                    $email,
                    $user['full_name'],
                    'forgot_password'
                );

                if ($result['ok']) {
                    // Store minimal state for the OTP verify page
                    $_SESSION['otp_pending'] = [
                        'purpose'   => 'forgot_password',
                        'email'     => $email,
                        'user_id'   => (int)$user['user_id'],
                        'full_name' => $user['full_name'],
                        'new_email' => null,
                    ];
                    session_write_close();
                    header('Location: verify_otp.php');
                    exit;
                }
                // If issue_otp fails (e.g. cooldown), still show generic message
                // so we don't reveal whether the user exists.
            }
            // If user not found, we just show the generic message — no OTP, no reveal
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — Herald Canteen</title>
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
            <p>Forgot Your Password?</p>
        </div>

        <?php if (!empty($field_errors['general'])): ?>
            <div class="alert error">
                ⚠️ <?= htmlspecialchars($field_errors['general'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($sent_message): ?>
            <div class="alert success">
                ✅ <?= htmlspecialchars($sent_message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php else: ?>
            <p style="color:var(--text-muted,#8b949e);font-size:0.9rem;
                      margin-bottom:20px;text-align:center;line-height:1.6;">
                Enter the email address linked to your account and we'll
                send you a verification code to reset your password.
            </p>
        <?php endif; ?>

        <form method="POST" class="login-form">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="input-group <?= isset($field_errors['email']) ? 'has-error' : '' ?>">
                <label>Email Address</label>
                <div class="input-wrapper">
                    <span class="input-icon">📧</span>
                    <input
                        type="email"
                        name="email"
                        placeholder="Enter your registered email"
                        value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                        required
                        autofocus
                    >
                </div>
                <?php if (isset($field_errors['email'])): ?>
                    <span class="field-error">
                        ⚠️ <?= htmlspecialchars($field_errors['email'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
            </div>

            <button type="submit" class="login-btn">
                Send Reset Code
            </button>
        </form>

        <div class="register-link" style="margin-top:20px;">
            <p>
                Remembered it?
                <a href="portal-login.php">Back to Login</a>
            </p>
        </div>

    </div>
</div>
</body>
</html>
