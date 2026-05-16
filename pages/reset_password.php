<?php
// ============================================================
// pages/reset_password.php — Herald Canteen
// ============================================================
// Step 3 of the forgot-password flow (after OTP verified).
// Allows the user to set a new password.
//
// Only accessible if:
//   - $_SESSION['pw_reset_verified'] is set (timestamp from verify_otp.php)
//   - That timestamp is less than 15 minutes old (extra safety net)
// ============================================================

require_once '../includes/auth.php';
start_session();
session_security_check();
require_once '../config/db.php';
require_once '../includes/functions.php';

// ── Gate: must have a verified reset token in session ────────
$reset_user_id  = isset($_SESSION['pw_reset_user_id'])  ? (int)$_SESSION['pw_reset_user_id']  : null;
$reset_email    = $_SESSION['pw_reset_email']    ?? '';
$reset_verified = $_SESSION['pw_reset_verified'] ?? 0;

$token_window = 15 * 60; // 15 minutes

if (!$reset_user_id || $reset_email === '' || !$reset_verified ||
    (time() - $reset_verified) > $token_window) {
    unset($_SESSION['pw_reset_user_id'], $_SESSION['pw_reset_email'],
          $_SESSION['pw_reset_verified']);
    session_write_close();
    header('Location: forgot_password.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function load_password_reset_user(mysqli $conn, int $user_id, string $email): ?array
{
    $stmt = $conn->prepare(
        "SELECT user_id, email, password, is_active
         FROM users
         WHERE user_id = ? AND email = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("is", $user_id, $email);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

$field_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF
    if (empty($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $field_errors['general'] = 'Invalid request token. Please refresh and try again.';
    } else {
        $new_password     = $_POST['new_password']     ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($new_password === '') {
            $field_errors['new_password'] = 'Password is required.';
        } elseif (strlen($new_password) < 10) {
            $field_errors['new_password'] = 'Password must be at least 10 characters long.';
        } elseif (trim($new_password) === '') {
            $field_errors['new_password'] = 'Password cannot consist of whitespace only.';
        }

        if ($confirm_password === '') {
            $field_errors['confirm_password'] = 'Please confirm your new password.';
        } elseif ($new_password !== $confirm_password) {
            $field_errors['confirm_password'] = 'Passwords do not match.';
        }

        $reset_user = null;
        if (empty($field_errors)) {
            $reset_user = load_password_reset_user($conn, $reset_user_id, $reset_email);

            if (!$reset_user || (int)$reset_user['is_active'] !== 1) {
                $field_errors['general'] = 'This password reset session is no longer valid. Please request a new reset code.';
                unset($_SESSION['pw_reset_user_id'], $_SESSION['pw_reset_email'], $_SESSION['pw_reset_verified']);
            } elseif (password_verify($new_password, $reset_user['password'])) {
                $field_errors['new_password'] = 'New password must be different from your current password.';
            }
        }

        if (empty($field_errors) && $reset_user) {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare(
                "UPDATE users SET password = ? WHERE user_id = ? AND email = ? AND is_active = 1"
            );
            $stmt->bind_param("sis", $hashed, $reset_user_id, $reset_email);
            $stmt->execute();
            $updated_rows = $stmt->affected_rows;
            $stmt->close();

            if ($updated_rows < 1) {
                $field_errors['general'] = 'Could not update password. Please request a new reset code and try again.';
            } else {
                // Clear all reset session data
                unset($_SESSION['pw_reset_user_id'], $_SESSION['pw_reset_email'],
                      $_SESSION['pw_reset_verified']);

                // Redirect to login with success flag
                session_write_close();
                header('Location: portal-login.php?pw_reset=1');
                exit;
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
    <title>Reset Password — Herald Canteen</title>
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
            <p>Set New Password</p>
        </div>

        <?php if (!empty($field_errors['general'])): ?>
            <div class="alert error">
                ⚠️ <?= htmlspecialchars($field_errors['general'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <p style="color:var(--text-muted,#8b949e);font-size:0.9rem;
                  text-align:center;margin-bottom:20px;line-height:1.5;">
            Choose a strong new password that is different from your current password for<br>
            <strong style="color:var(--text-primary,#e6edf3);">
                <?= htmlspecialchars($reset_email, ENT_QUOTES, 'UTF-8') ?>
            </strong>
        </p>

        <form method="POST" class="login-form" id="resetForm">
            <input type="hidden" name="csrf_token"
                   value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <div class="input-group <?= isset($field_errors['new_password']) ? 'has-error' : '' ?>">
                <label>New Password</label>
                <div class="input-wrapper">
                    <span class="input-icon">🔒</span>
                    <input
                        type="password"
                        name="new_password"
                        id="newPassword"
                        placeholder="At least 10 characters"
                        required
                        autofocus
                    >
                </div>
                <?php if (isset($field_errors['new_password'])): ?>
                    <span class="field-error">
                        ⚠️ <?= htmlspecialchars($field_errors['new_password'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="input-group <?= isset($field_errors['confirm_password']) ? 'has-error' : '' ?>">
                <label>Confirm New Password</label>
                <div class="input-wrapper">
                    <span class="input-icon">🔒</span>
                    <input
                        type="password"
                        name="confirm_password"
                        id="confirmPassword"
                        placeholder="Repeat your new password"
                        required
                    >
                </div>
                <?php if (isset($field_errors['confirm_password'])): ?>
                    <span class="field-error">
                        ⚠️ <?= htmlspecialchars($field_errors['confirm_password'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Password strength indicator -->
            <div style="height:4px;border-radius:2px;background:var(--border-color,#30363d);
                         margin-bottom:18px;overflow:hidden;">
                <div id="strengthBar"
                     style="height:100%;width:0%;transition:width 0.3s,background 0.3s;
                             border-radius:2px;background:#f85149;"></div>
            </div>
            <p id="strengthLabel"
               style="font-size:0.78rem;color:var(--text-muted,#8b949e);
                       margin-top:-14px;margin-bottom:14px;text-align:right;"></p>

            <button type="submit" class="login-btn" id="submitBtn">
                Reset Password
            </button>
        </form>

    </div>
</div>

<script>
const pw  = document.getElementById('newPassword');
const bar = document.getElementById('strengthBar');
const lbl = document.getElementById('strengthLabel');

function passwordStrength(val) {
    let score = 0;
    if (val.length >= 10) score++;
    if (val.length >= 14) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    return score;
}

if (pw) {
    pw.addEventListener('input', () => {
        const score = passwordStrength(pw.value);
        const widths = ['0%','20%','40%','60%','80%','100%'];
        const colors = ['#f85149','#f85149','#f59e0b','#f59e0b','#22c55e','#22c55e'];
        const labels = ['','Too short','Weak','Fair','Good','Strong'];
        bar.style.width    = widths[score];
        bar.style.background = colors[score];
        lbl.textContent    = labels[score];
        lbl.style.color    = colors[score];
    });
}

const form = document.getElementById('resetForm');
const btn  = document.getElementById('submitBtn');
if (form && btn) {
    form.addEventListener('submit', () => {
        btn.disabled = true;
        btn.textContent = 'Saving…';
    });
}
</script>
</body>
</html>
