<?php
require_once '../includes/auth.php';
start_session();
session_security_check();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/otp_helpers.php';

if (empty($_SESSION['otp_pending'])) {
    session_write_close();
    header('Location: portal-login.php');
    exit;
}

$pending   = $_SESSION['otp_pending'];
$purpose   = $pending['purpose']   ?? '';
$otp_email = $pending['email']     ?? '';
$full_name = $pending['full_name'] ?? 'User';
$user_id   = isset($pending['user_id']) ? (int)$pending['user_id'] : null;
$new_email = $pending['new_email'] ?? null;

$allowed_purposes = ['register', 'login', 'forgot_password', 'email_change', 'enable_mfa'];
if (!in_array($purpose, $allowed_purposes, true) || $otp_email === '') {
    unset($_SESSION['otp_pending']);
    session_write_close();
    header('Location: portal-login.php');
    exit;
}

if (in_array($purpose, ['email_change', 'enable_mfa'], true) && !isset($_SESSION['user_id'])) {
    unset($_SESSION['otp_pending']);
    session_write_close();
    header('Location: portal-login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$field_errors  = [];
$info_msg      = '';
$resend_secs   = otp_resend_seconds_remaining($conn, $otp_email, $purpose);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $field_errors['general'] = 'Invalid request token. Please refresh and try again.';
    } elseif (isset($_POST['resend_otp'])) {
        if ($purpose === 'register') {
            $pending_registration = get_pending_registration_by_email($conn, $otp_email);

            if (!$pending_registration) {
                unset($_SESSION['otp_pending']);
                $_SESSION['register_error'] = 'Registration verification expired. Please register again.';
                session_write_close();
                header('Location: register.php');
                exit;
            }

            $full_name = $pending_registration['full_name'];
        }

        $result = issue_otp($conn, $user_id, $otp_email, $full_name, $purpose, $new_email);

        if ($result['ok']) {
            $info_msg = 'A new verification code has been sent to ' . htmlspecialchars($otp_email, ENT_QUOTES, 'UTF-8') . '.';
            $resend_secs = OTP_RESEND_COOLDOWN;
        } else {
            $field_errors['general'] = $result['error'];
            $resend_secs = $result['cooldown_seconds'];
        }
    } elseif (isset($_POST['verify_otp'])) {
        $submitted = trim($_POST['otp_code'] ?? '');

        if ($submitted === '') {
            $field_errors['otp'] = 'Please enter the 6-digit code.';
        } else {
            $result = verify_otp($conn, $otp_email, $purpose, $submitted);

            if (!$result['ok']) {
                $field_errors['otp'] = $result['error'];
            } else {
                unset($_SESSION['otp_pending']);

                if ($purpose === 'register') {
                    $pending_registration = get_pending_registration_by_email($conn, $otp_email);

                    if (!$pending_registration) {
                        if ($user_id) {
                            $legacy_user = find_user_by_id($conn, (int)$user_id);

                            if ($legacy_user && strtolower($legacy_user['email']) === strtolower($otp_email)) {
                                $stmt = $conn->prepare("UPDATE users SET email_verified_at = NOW(), mfa_enabled = COALESCE(mfa_enabled, 0) WHERE user_id = ?");
                                $stmt->bind_param("i", $user_id);
                                $stmt->execute();
                                $stmt->close();

                                $_SESSION['login_success'] = 'Email verified successfully. You can now log in.';
                                session_write_close();
                                header('Location: portal-login.php?registered=1');
                                exit;
                            }
                        }

                        $_SESSION['register_error'] = 'Registration verification expired. Please register again.';
                        session_write_close();
                        header('Location: register.php');
                        exit;
                    }

                    if (email_exists($conn, $otp_email)) {
                        delete_pending_registration($conn, $otp_email);
                        $_SESSION['login_error'] = 'This email is already registered. Please log in instead.';
                        session_write_close();
                        header('Location: portal-login.php');
                        exit;
                    }

                    $new_user_id = create_user_from_pending_registration($conn, $pending_registration);

                    if (!$new_user_id) {
                        $_SESSION['register_error'] = 'Account could not be activated. Please register again.';
                        session_write_close();
                        header('Location: register.php');
                        exit;
                    }

                    delete_pending_registration($conn, $otp_email);
                    $_SESSION['login_success'] = 'Email verified successfully. You can now log in.';
                    session_write_close();
                    header('Location: portal-login.php?registered=1');
                    exit;
                }

                if ($purpose === 'login') {
                    $user = find_user_by_email($conn, $otp_email);
                    if (!$user || (int)$user['is_active'] !== 1 || empty($user['email_verified_at'])) {
                        $_SESSION['login_error'] = 'Account is not active or email is not verified.';
                        session_write_close();
                        header('Location: portal-login.php');
                        exit;
                    }

                    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                    clear_failed_attempts($conn, $ip);

                    login_user($user);
                    redirect_user_by_role($user['role']);
                }

                if ($purpose === 'forgot_password') {
                    // Guard: user_id must be present — if null, the OTP token was
                    // inserted incorrectly and reset_password.php would UPDATE 0 rows
                    // silently appearing to succeed while changing nothing.
                    if (empty($result['user_id'])) {
                        $_SESSION['login_error'] = 'Password reset session is invalid. Please start again.';
                        session_write_close();
                        header('Location: forgot_password.php');
                        exit;
                    }
                    $_SESSION['pw_reset_user_id']  = (int)$result['user_id'];
                    $_SESSION['pw_reset_email']    = $otp_email;
                    $_SESSION['pw_reset_verified'] = time();
                    session_write_close();
                    header('Location: reset_password.php');
                    exit;
                }

                if ($purpose === 'email_change') {
                    $confirmed_new_email = $result['new_email'] ?? $new_email;
                    $current_user_id = (int)$_SESSION['user_id'];

                    $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1");
                    $check->bind_param("si", $confirmed_new_email, $current_user_id);
                    $check->execute();
                    $taken = $check->get_result()->fetch_assoc();
                    $check->close();

                    if ($taken) {
                        $_SESSION['profile_error'] = 'That email address is already in use by another account.';
                        $_SESSION['profile_open_edit'] = true;
                        session_write_close();
                        header('Location: user_profile.php');
                        exit;
                    }

                    $upd = $conn->prepare("UPDATE users SET email = ?, email_verified_at = NOW() WHERE user_id = ? AND role = 'customer'");
                    $upd->bind_param("si", $confirmed_new_email, $current_user_id);
                    $upd->execute();
                    $upd->close();

                    $_SESSION['email'] = $confirmed_new_email;
                    $_SESSION['profile_success'] = 'Email address updated and verified successfully.';
                    session_write_close();
                    header('Location: user_profile.php');
                    exit;
                }

                if ($purpose === 'enable_mfa') {
                    $current_user_id = (int)$_SESSION['user_id'];
                    $upd = $conn->prepare("UPDATE users SET mfa_enabled = 1 WHERE user_id = ? AND role = 'customer'");
                    $upd->bind_param("i", $current_user_id);
                    $upd->execute();
                    $upd->close();

                    $_SESSION['profile_success'] = '2-Step Login has been enabled successfully.';
                    session_write_close();
                    header('Location: user_profile.php');
                    exit;
                }
            }
        }
    }
}

$purpose_title = match ($purpose) {
    'register'        => 'Verify Your Email',
    'login'           => 'Login Verification',
    'forgot_password' => 'Password Reset',
    'email_change'    => 'Email Verification',
    'enable_mfa'      => 'Enable 2-Step Login',
    default           => 'Verification',
};

$purpose_desc = match ($purpose) {
    'register'        => 'Enter the 6-digit code sent to your email to activate your account.',
    'login'           => 'Enter the 6-digit code sent to your email to complete login.',
    'forgot_password' => str_ends_with(strtolower($otp_email), '@heraldcanteen.com')
        ? 'Enter the 6-digit secret code to reset your password.'
        : 'Enter the 6-digit code sent to your email to reset your password.',
    'email_change'    => 'Enter the 6-digit code sent to your new email address to confirm the change.',
    'enable_mfa'      => 'Enter the 6-digit code sent to your email to enable 2-Step Login.',
    default           => 'Enter the 6-digit code sent to your email.',
};

$masked_email = (function(string $e): string {
    $at = strpos($e, '@');
    if ($at === false) return $e;
    $local  = substr($e, 0, $at);
    $domain = substr($e, $at);
    if (strlen($local) <= 2) {
        return str_repeat('*', max(1, strlen($local))) . $domain;
    }
    return substr($local, 0, 2) . str_repeat('*', strlen($local) - 2) . $domain;
})($otp_email);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($purpose_title) ?> — Herald Canteen</title>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .otp-box{max-width:440px;width:100%;margin:0 auto;background:var(--card-bg,#161b22);border:1px solid var(--border-color,#30363d);border-radius:14px;padding:40px 36px 36px;position:relative}.otp-logo{text-align:center;margin-bottom:28px}.otp-logo h1{font-size:1.6rem;font-weight:700;margin:0 0 4px;color:var(--text-primary,#e6edf3)}.otp-logo h1 span{color:#22c55e}.otp-logo p{margin:0;color:var(--text-muted,#8b949e);font-size:.85rem}.otp-badge{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);border-radius:8px;padding:14px 16px;margin-bottom:24px;text-align:center}.otp-badge .otp-badge-icon{font-size:2rem;display:block;margin-bottom:6px}.otp-badge strong{display:block;color:var(--text-primary,#e6edf3);font-size:.95rem;margin-bottom:4px}.otp-badge span{color:var(--text-muted,#8b949e);font-size:.82rem}.otp-sent-to{text-align:center;font-size:.85rem;color:var(--text-muted,#8b949e);margin-bottom:24px}.otp-sent-to em{color:var(--text-primary,#e6edf3);font-style:normal;font-weight:600}.otp-input-wrapper input[type="text"]{width:100%;font-size:2.2rem;font-weight:700;letter-spacing:.6rem;text-align:center;padding:16px 12px;border-radius:10px;border:2px solid var(--border-color,#30363d);background:var(--input-bg,#0d1117);color:#22c55e;font-family:'Courier New',monospace;box-sizing:border-box}.otp-input-wrapper input[type="text"]:focus{outline:none;border-color:#22c55e}.otp-input-wrapper input.has-error{border-color:#f85149}.otp-timer{text-align:center;font-size:.8rem;color:var(--text-muted,#8b949e);margin-top:8px}.otp-actions{display:flex;flex-direction:column;gap:10px;margin-top:20px}.btn-otp-verify{width:100%;padding:14px;border:none;border-radius:8px;background:linear-gradient(135deg,#1a5c38,#22c55e);color:#fff;font-size:1rem;font-weight:600;cursor:pointer}.btn-otp-verify:disabled{opacity:.5;cursor:not-allowed}.btn-otp-resend{width:100%;padding:11px;border:1px solid var(--border-color,#30363d);border-radius:8px;background:transparent;color:var(--text-muted,#8b949e);font-size:.88rem;cursor:pointer}.btn-otp-resend:hover:not(:disabled){border-color:#22c55e;color:#22c55e}.btn-otp-resend:disabled{opacity:.45;cursor:not-allowed}.otp-back-link{text-align:center;margin-top:20px;font-size:.84rem}.otp-back-link a{color:#22c55e;text-decoration:none}.otp-field-error{display:block;color:#f85149;font-size:.82rem;margin-top:6px;text-align:center}.otp-alert-info{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.25);border-radius:8px;padding:12px 14px;color:#4ade80;font-size:.87rem;margin-bottom:16px;text-align:center}
    </style>
</head>
<body>
<div class="container">
    <div class="otp-box">
        <div style="position:absolute;top:16px;right:16px;">
            <label class="theme-toggle" title="Toggle light/dark mode"><input type="checkbox" class="theme-checkbox"><span class="theme-slider"></span></label>
        </div>

        <div class="otp-logo"><h1>Herald <span>Canteen</span></h1><p><?= htmlspecialchars($purpose_title) ?></p></div>

        <?php if (!empty($field_errors['general'])): ?><div class="alert error">⚠️ <?= htmlspecialchars($field_errors['general'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($info_msg): ?><div class="otp-alert-info">✅ <?= htmlspecialchars($info_msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <div class="otp-badge">
            <span class="otp-badge-icon">📧</span>
            <strong><?= htmlspecialchars($purpose_desc, ENT_QUOTES, 'UTF-8') ?></strong>
            <span>Do not share this code with anyone.</span>
        </div>

        <?php if ($purpose !== 'forgot_password' || !str_ends_with(strtolower($otp_email), '@heraldcanteen.com')): ?>
        <p class="otp-sent-to">Code sent to <em><?= htmlspecialchars($masked_email, ENT_QUOTES, 'UTF-8') ?></em></p>
        <?php endif; ?>

        <form method="POST" id="otpVerifyForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="verify_otp" value="1">
            <div class="otp-input-wrapper">
                <input type="text" id="otp_code" name="otp_code" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="000000" class="<?= !empty($field_errors['otp']) ? 'has-error' : '' ?>" autofocus autocomplete="one-time-code" aria-label="6-digit verification code">
            </div>
            <?php if (!empty($field_errors['otp'])): ?><span class="otp-field-error">⚠️ <?= htmlspecialchars($field_errors['otp'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
            <div class="otp-actions"><button type="submit" class="btn-otp-verify" id="verifyBtn">Verify Code</button></div>
        </form>

        <?php
        // For staff/chef demo accounts doing a password reset, hide the resend
        // button entirely — they use the fixed secret code, so resend is pointless.
        $is_demo_reset = ($purpose === 'forgot_password')
                      && str_ends_with(strtolower($otp_email), '@heraldcanteen.com');
        ?>
        <?php if (!$is_demo_reset): ?>
        <form method="POST" id="otpResendForm" style="margin-top:10px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="resend_otp" value="1">
            <button type="submit" class="btn-otp-resend" id="resendBtn" <?= $resend_secs > 0 ? 'disabled' : '' ?>>
                <?php if ($resend_secs > 0): ?>Resend code in <span id="resendCountdown"><?= $resend_secs ?></span>s<?php else: ?>Resend code<?php endif; ?>
            </button>
        </form>
        <?php endif; ?>

        <div class="otp-back-link">
            <?php if (in_array($purpose, ['login', 'register'], true)): ?><a href="portal-login.php">← Back to Login</a><?php endif; ?>
            <?php if ($purpose === 'forgot_password'): ?><a href="forgot_password.php">← Back</a><?php endif; ?>
            <?php if (in_array($purpose, ['email_change', 'enable_mfa'], true)): ?><a href="user_profile.php">← Cancel</a><?php endif; ?>
        </div>
    </div>
</div>
<script>
const otpInput=document.getElementById('otp_code');const verifyBtn=document.getElementById('verifyBtn');const resendBtn=document.getElementById('resendBtn');if(otpInput){otpInput.addEventListener('input',()=>{otpInput.value=otpInput.value.replace(/\D/g,'').slice(0,6);});}
const resendCountdown=document.getElementById('resendCountdown');let resendSecs=<?= (int)$resend_secs ?>;if(!<?= $is_demo_reset ? 'true' : 'false' ?>&&resendSecs>0&&resendBtn&&resendCountdown){const resendTimer=setInterval(()=>{resendSecs--;resendCountdown.textContent=resendSecs;if(resendSecs<=0){clearInterval(resendTimer);resendBtn.disabled=false;resendBtn.textContent='Resend code';}},1000);}
const verifyForm=document.getElementById('otpVerifyForm');if(verifyForm&&verifyBtn){verifyForm.addEventListener('submit',()=>{verifyBtn.disabled=true;verifyBtn.textContent='Verifying…';});}
</script>
</body>
</html>
