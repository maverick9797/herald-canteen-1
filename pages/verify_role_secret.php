<?php
require_once '../includes/auth.php';
start_session();
session_security_check();
require_once '../config/db.php';
require_once '../includes/functions.php';

// ROLE_SECRET_CODE must be set in config/secrets.php as a bcrypt hash.
// Generate with: password_hash('your-secret', PASSWORD_DEFAULT)
// NEVER hardcode the plain-text secret here.
require_once '../config/secrets.php'; // defines ROLE_SECRET_HASH

const ROLE_SECRET_MAX_ATTEMPTS    = 5;
const ROLE_SECRET_EXPIRY_SECONDS  = 10 * 60;

if (empty($_SESSION['role_secret_pending']) || !is_array($_SESSION['role_secret_pending'])) {
    session_write_close();
    header('Location: portal-login.php');
    exit;
}

$pending = $_SESSION['role_secret_pending'];
$user_id = isset($pending['user_id']) ? (int)$pending['user_id'] : 0;
$role = $pending['role'] ?? '';
$full_name = $pending['full_name'] ?? 'User';
$email = $pending['email'] ?? '';
$started_at = isset($pending['started_at']) ? (int)$pending['started_at'] : 0;

if ($user_id <= 0 || !in_array($role, ['chef', 'staff'], true) || $started_at <= 0) {
    unset($_SESSION['role_secret_pending']);
    session_write_close();
    header('Location: portal-login.php');
    exit;
}

if (time() - $started_at > ROLE_SECRET_EXPIRY_SECONDS) {
    unset($_SESSION['role_secret_pending']);
    $_SESSION['login_error'] = 'Secret code session expired. Please log in again.';
    session_write_close();
    header('Location: portal-login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$field_errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $field_errors['general'] = 'Invalid request token. Please refresh and try again.';
    } elseif (isset($_POST['cancel_secret'])) {
        unset($_SESSION['role_secret_pending']);
        session_write_close();
        header('Location: portal-login.php');
        exit;
    } elseif (isset($_POST['verify_secret'])) {
        $ip_rs = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // IP-level rate-limit check — same DB-backed limiter used on the login page.
        // Session counters alone are bypassable by clearing cookies / incognito.
        $rate_rs = is_rate_limited($conn, $ip_rs);
        if ($rate_rs['blocked']) {
            unset($_SESSION['role_secret_pending']);
            $_SESSION['login_error'] = "Too many failed attempts. Please try again in {$rate_rs['remaining']} minute(s).";
            session_write_close();
            header('Location: portal-login.php');
            exit;
        }

        $secret_code = trim($_POST['secret_code'] ?? '');

        if (!preg_match('/^\d{6}$/', $secret_code)) {
            $field_errors['secret_code'] = 'Please enter the 6-digit secret code.';
        } elseif (!password_verify($secret_code, ROLE_SECRET_HASH)) {
            log_failed_attempt($conn, $ip_rs);
            $_SESSION['role_secret_pending']['attempts'] = (int)($_SESSION['role_secret_pending']['attempts'] ?? 0) + 1;
            $remaining = ROLE_SECRET_MAX_ATTEMPTS - (int)$_SESSION['role_secret_pending']['attempts'];

            if ($remaining <= 0) {
                unset($_SESSION['role_secret_pending']);
                $_SESSION['login_error'] = 'Too many incorrect secret code attempts. Please log in again.';
                session_write_close();
                header('Location: portal-login.php');
                exit;
            }

            $field_errors['secret_code'] = "Incorrect secret code. {$remaining} attempt(s) remaining.";
        } else {
            $user = find_user_by_id($conn, $user_id);

            if (!$user || (int)$user['is_active'] !== 1 || !in_array($user['role'], ['chef', 'staff'], true)) {
                unset($_SESSION['role_secret_pending']);
                $_SESSION['login_error'] = 'This staff account is no longer active.';
                session_write_close();
                header('Location: portal-login.php');
                exit;
            }

            // Clear IP-level failed attempts on successful secret verification
            clear_failed_attempts($conn, $ip_rs);
            unset($_SESSION['role_secret_pending'], $_SESSION['otp_pending']);
            log_user_event($conn, 'login_success', $ip_rs, "Role-verified login successful for '{$user['email']}' ({$user['role']})", (int)$user['user_id']);
            login_user($user);
            redirect_user_by_role($user['role']);
        }
    }
}

$role_label = $role === 'chef' ? 'Chef' : 'Staff';
$masked_email = (function(string $e): string {
    $at = strpos($e, '@');
    if ($at === false) return $e;
    $local  = substr($e, 0, $at);
    $domain = substr($e, $at);
    if (strlen($local) <= 2) {
        return str_repeat('*', max(1, strlen($local))) . $domain;
    }
    return substr($local, 0, 2) . str_repeat('*', strlen($local) - 2) . $domain;
})($email);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($role_label) ?> Secret Code — Herald Canteen</title>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .secret-box{max-width:440px;width:100%;margin:0 auto;background:var(--card-bg,#161b22);border:1px solid var(--border-color,#30363d);border-radius:14px;padding:40px 36px 36px;position:relative}.secret-logo{text-align:center;margin-bottom:28px}.secret-logo h1{font-size:1.6rem;font-weight:700;margin:0 0 4px;color:var(--text-primary,#e6edf3)}.secret-logo h1 span{color:#22c55e}.secret-logo p{margin:0;color:var(--text-muted,#8b949e);font-size:.85rem}.secret-badge{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);border-radius:8px;padding:14px 16px;margin-bottom:24px;text-align:center}.secret-badge .secret-badge-icon{font-size:2rem;display:block;margin-bottom:6px}.secret-badge strong{display:block;color:var(--text-primary,#e6edf3);font-size:.95rem;margin-bottom:4px}.secret-badge span{color:var(--text-muted,#8b949e);font-size:.82rem}.secret-user{text-align:center;font-size:.85rem;color:var(--text-muted,#8b949e);margin-bottom:24px}.secret-user em{color:var(--text-primary,#e6edf3);font-style:normal;font-weight:600}.secret-input-wrapper input[type="text"]{width:100%;font-size:2.2rem;font-weight:700;letter-spacing:.6rem;text-align:center;padding:16px 12px;border-radius:10px;border:2px solid var(--border-color,#30363d);background:var(--input-bg,#0d1117);color:#22c55e;font-family:'Courier New',monospace;box-sizing:border-box}.secret-input-wrapper input[type="text"]:focus{outline:none;border-color:#22c55e}.secret-input-wrapper input.has-error{border-color:#f85149}.secret-actions{display:flex;flex-direction:column;gap:10px;margin-top:20px}.btn-secret-verify{width:100%;padding:14px;border:none;border-radius:8px;background:linear-gradient(135deg,#1a5c38,#22c55e);color:#fff;font-size:1rem;font-weight:600;cursor:pointer}.btn-secret-verify:disabled{opacity:.5;cursor:not-allowed}.btn-secret-cancel{width:100%;padding:11px;border:1px solid var(--border-color,#30363d);border-radius:8px;background:transparent;color:var(--text-muted,#8b949e);font-size:.88rem;cursor:pointer}.btn-secret-cancel:hover{border-color:#22c55e;color:#22c55e}.secret-field-error{display:block;color:#f85149;font-size:.82rem;margin-top:6px;text-align:center}.secret-demo-note{text-align:center;color:#8b949e;font-size:.78rem;margin-top:-12px;margin-bottom:16px;line-height:1.5}
    </style>
</head>
<body>
<div class="container">
    <div class="secret-box">
        <div style="position:absolute;top:16px;right:16px;">
            <label class="theme-toggle" title="Toggle light/dark mode"><input type="checkbox" class="theme-checkbox"><span class="theme-slider"></span></label>
        </div>

        <div class="secret-logo"><h1>Herald <span>Canteen</span></h1><p><?= htmlspecialchars($role_label) ?> Security Check</p></div>

        <?php if (!empty($field_errors['general'])): ?><div class="alert error">⚠️ <?= htmlspecialchars($field_errors['general'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <div class="secret-badge">
            <span class="secret-badge-icon">🔐</span>
            <strong>Enter the 6-digit staff secret code to continue.</strong>
            <span>This extra step protects chef and staff control pages.</span>
        </div>

        <p class="secret-user">Signing in as <em><?= htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8') ?></em><br><span><?= htmlspecialchars($masked_email, ENT_QUOTES, 'UTF-8') ?></span></p>

        <form method="POST" id="secretVerifyForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="verify_secret" value="1">
            <div class="secret-input-wrapper">
                <input type="text" id="secret_code" name="secret_code" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="000000" class="<?= !empty($field_errors['secret_code']) ? 'has-error' : '' ?>" autofocus aria-label="6-digit staff secret code">
            </div>
            <?php if (!empty($field_errors['secret_code'])): ?><span class="secret-field-error">⚠️ <?= htmlspecialchars($field_errors['secret_code'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
            <div class="secret-actions"><button type="submit" class="btn-secret-verify" id="verifySecretBtn">Verify Secret Code</button></div>
        </form>

        <form method="POST" style="margin-top:10px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="cancel_secret" value="1">
            <button type="submit" class="btn-secret-cancel">← Back to Login</button>
        </form>
    </div>
</div>
<script>
const secretInput=document.getElementById('secret_code');const verifySecretBtn=document.getElementById('verifySecretBtn');const secretForm=document.getElementById('secretVerifyForm');if(secretInput){secretInput.addEventListener('input',()=>{secretInput.value=secretInput.value.replace(/\D/g,'').slice(0,6);});}if(secretForm&&verifySecretBtn){secretForm.addEventListener('submit',()=>{verifySecretBtn.disabled=true;verifySecretBtn.textContent='Verifying…';});}
</script>
</body>
</html>
