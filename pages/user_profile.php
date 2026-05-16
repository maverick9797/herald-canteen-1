<?php
require_once "../includes/auth.php";
start_session();
session_security_check();

require_once "../config/db.php";
require_once "../includes/functions.php";
require_once "../includes/otp_helpers.php";

if (!isset($_SESSION['user_id'])) {
    session_write_close();
    header('Location: portal-login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'customer') {
    $role = $_SESSION['role'] ?? '';
    session_write_close();
    if ($role === 'chef') {
        header('Location: chef-control.php');
    } elseif ($role === 'staff') {
        header('Location: staff-control.php');
    } else {
        header('Location: portal-login.php');
    }
    exit;
}

$user_id = (int)$_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = $_SESSION['profile_success'] ?? '';
$error = $_SESSION['profile_error'] ?? '';
$open_edit = !empty($_SESSION['profile_open_edit']);
unset($_SESSION['profile_success'], $_SESSION['profile_error'], $_SESSION['profile_open_edit']);

function load_profile_user(mysqli $conn, int $user_id): ?array
{
    $stmt = $conn->prepare(
        "SELECT user_id, full_name, email, password, role, phone, created_at, is_active,
                COALESCE(mfa_enabled, 0) AS mfa_enabled,
                email_verified_at
         FROM users
         WHERE user_id = ? AND role = 'customer'
         LIMIT 1"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

$current_user_for_post = load_profile_user($conn, $user_id);
if (!$current_user_for_post) {
    session_unset();
    session_destroy();
    session_write_close();
    header('Location: portal-login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['profile_error'] = 'Invalid request token. Please try again.';
        $_SESSION['profile_open_edit'] = true;
        session_write_close();
        header('Location: user_profile.php');
        exit;
    }

    if (isset($_POST['update_profile_basic'])) {
        $new_name = trim($_POST['name'] ?? '');
        $new_phone = trim($_POST['phone'] ?? '');

        if ($new_name === '') {
            $_SESSION['profile_error'] = 'Full name cannot be empty.';
            $_SESSION['profile_open_edit'] = true;
        } elseif (strlen($new_name) > 100) {
            $_SESSION['profile_error'] = 'Full name is too long. Maximum 100 characters.';
            $_SESSION['profile_open_edit'] = true;
        } elseif ($new_phone !== '' && !preg_match('/^[0-9\+\-\s]{7,20}$/', $new_phone)) {
            $_SESSION['profile_error'] = 'Please enter a valid phone number.';
            $_SESSION['profile_open_edit'] = true;
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name = ?, phone = ? WHERE user_id = ? AND role = 'customer'");
            $stmt->bind_param("ssi", $new_name, $new_phone, $user_id);
            if ($stmt->execute()) {
                $_SESSION['full_name'] = $new_name;
                $_SESSION['profile_success'] = 'Profile updated successfully.';
            } else {
                $_SESSION['profile_error'] = 'Could not save changes. Please try again.';
                $_SESSION['profile_open_edit'] = true;
            }
            $stmt->close();
        }

        session_write_close();
        header('Location: user_profile.php');
        exit;
    }

    if (isset($_POST['request_email_change'])) {
        $new_email = trim($_POST['new_email'] ?? '');
        $current_password = $_POST['current_password'] ?? '';

        if ($new_email === '' || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['profile_error'] = 'Please enter a valid email address.';
        } elseif (strlen($new_email) > 150) {
            $_SESSION['profile_error'] = 'Email address is too long.';
        } elseif ($current_password === '' || !password_verify($current_password, $current_user_for_post['password'])) {
            $_SESSION['profile_error'] = 'Current password is required to change your email.';
        } elseif (strtolower($current_user_for_post['email']) === strtolower($new_email)) {
            $_SESSION['profile_error'] = 'The new email is the same as your current email.';
        } else {
            $check = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ? LIMIT 1");
            $check->bind_param("si", $new_email, $user_id);
            $check->execute();
            $taken = $check->get_result()->fetch_assoc();
            $check->close();

            if ($taken) {
                $_SESSION['profile_error'] = 'That email address is already in use by another account.';
            } else {
                $result = issue_otp($conn, $user_id, $new_email, $current_user_for_post['full_name'], 'email_change', $new_email);
                if ($result['ok']) {
                    $_SESSION['otp_pending'] = [
                        'purpose'   => 'email_change',
                        'email'     => $new_email,
                        'user_id'   => $user_id,
                        'full_name' => $current_user_for_post['full_name'],
                        'new_email' => $new_email,
                    ];
                    session_write_close();
                    header('Location: verify_otp.php');
                    exit;
                }
                $_SESSION['profile_error'] = $result['error'];
            }
        }

        $_SESSION['profile_open_edit'] = true;
        session_write_close();
        header('Location: user_profile.php');
        exit;
    }

    if (isset($_POST['enable_mfa'])) {
        $current_password = $_POST['mfa_current_password'] ?? '';

        if (empty($current_user_for_post['email_verified_at'])) {
            $_SESSION['profile_error'] = 'Please verify your email before enabling 2-Step Login.';
        } elseif ($current_password === '' || !password_verify($current_password, $current_user_for_post['password'])) {
            $_SESSION['profile_error'] = 'Current password is required to enable 2-Step Login.';
        } elseif ((int)$current_user_for_post['mfa_enabled'] === 1) {
            $_SESSION['profile_success'] = '2-Step Login is already enabled.';
        } else {
            $result = issue_otp($conn, $user_id, $current_user_for_post['email'], $current_user_for_post['full_name'], 'enable_mfa');
            if ($result['ok']) {
                $_SESSION['otp_pending'] = [
                    'purpose'   => 'enable_mfa',
                    'email'     => $current_user_for_post['email'],
                    'user_id'   => $user_id,
                    'full_name' => $current_user_for_post['full_name'],
                    'new_email' => null,
                ];
                session_write_close();
                header('Location: verify_otp.php');
                exit;
            }
            $_SESSION['profile_error'] = $result['error'];
        }

        session_write_close();
        header('Location: user_profile.php');
        exit;
    }

    if (isset($_POST['disable_mfa'])) {
        $current_password = $_POST['mfa_current_password'] ?? '';

        if ($current_password === '' || !password_verify($current_password, $current_user_for_post['password'])) {
            $_SESSION['profile_error'] = 'Current password is required to disable 2-Step Login.';
        } else {
            $stmt = $conn->prepare("UPDATE users SET mfa_enabled = 0 WHERE user_id = ? AND role = 'customer'");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['profile_success'] = '2-Step Login has been disabled.';
        }

        session_write_close();
        header('Location: user_profile.php');
        exit;
    }

    if (isset($_POST['change_password'])) {
        $current_password    = $_POST['pw_current'] ?? '';
        $new_password        = $_POST['pw_new'] ?? '';
        $confirm_new_password = $_POST['pw_confirm'] ?? '';

        if ($current_password === '' || !password_verify($current_password, $current_user_for_post['password'])) {
            $_SESSION['profile_error'] = 'Current password is incorrect.';
        } elseif ($new_password === '') {
            $_SESSION['profile_error'] = 'New password is required.';
        } elseif (strlen($new_password) < 10) {
            $_SESSION['profile_error'] = 'New password must be at least 10 characters.';
        } elseif (trim($new_password) === '') {
            $_SESSION['profile_error'] = 'New password cannot consist of whitespace only.';
        } elseif ($new_password !== $confirm_new_password) {
            $_SESSION['profile_error'] = 'New passwords do not match.';
        } elseif (password_verify($new_password, $current_user_for_post['password'])) {
            $_SESSION['profile_error'] = 'New password must be different from your current password.';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ? AND role = 'customer'");
            $stmt->bind_param("si", $hashed, $user_id);
            if ($stmt->execute()) {
                $_SESSION['profile_success'] = 'Password changed successfully.';
            } else {
                $_SESSION['profile_error'] = 'Could not update password. Please try again.';
            }
            $stmt->close();
        }

        session_write_close();
        header('Location: user_profile.php');
        exit;
    }
}

$user = load_profile_user($conn, $user_id);
if (!$user) {
    session_unset();
    session_destroy();
    session_write_close();
    header('Location: portal-login.php');
    exit;
}

$stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_orders = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$total_spent = (float)$stmt->get_result()->fetch_row()[0];
$stmt->close();

$stmt = $conn->prepare("SELECT COALESCE(SUM(quantity), 0) FROM cart WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$cart_items = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'delivered'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$delivered = (int)$stmt->get_result()->fetch_row()[0];
$stmt->close();

$clean_name = trim($user['full_name'] ?? 'Customer');
$name_parts = preg_split('/\s+/', $clean_name);
$first_initial = strtoupper(substr($name_parts[0] ?? 'C', 0, 1));
$last_initial = strtoupper(substr($name_parts[count($name_parts) - 1] ?? '', 0, 1));
$initials = $first_initial . (($last_initial && $last_initial !== $first_initial) ? $last_initial : '');
$member_since = !empty($user['created_at']) ? date('F Y', strtotime($user['created_at'])) : 'Recently';
$phone_display = trim($user['phone'] ?? '') !== '' ? $user['phone'] : '';
$email_verified = !empty($user['email_verified_at']);
$mfa_enabled = (int)($user['mfa_enabled'] ?? 0) === 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — Herald Canteen</title>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .security-grid{display:grid;gap:14px;margin-top:18px}.security-card{background:var(--card-bg-alt,#0d1117);border:1px solid var(--border-color,#30363d);border-radius:16px;padding:18px}.security-row{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}.security-row h4{margin:0 0 6px;color:var(--text-primary,#e6edf3)}.security-row p{margin:0;color:var(--text-muted,#8b949e);font-size:.85rem;line-height:1.5}.email-verified-badge,.mfa-on-badge{display:inline-flex;align-items:center;gap:4px;font-size:.75rem;padding:4px 10px;border-radius:20px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#22c55e;font-weight:800}.email-unverified-badge,.mfa-off-badge{display:inline-flex;align-items:center;gap:4px;font-size:.75rem;padding:4px 10px;border-radius:20px;background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:#f59e0b;font-weight:800}.profile-section-label{font-size:.78rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted,#8b949e);margin-bottom:16px;font-weight:700}.email-change-panel{background:var(--card-bg-alt,#0d1117);border:1px solid var(--border-color,#30363d);border-radius:16px;padding:18px}.mini-password-form{margin-top:14px;display:grid;gap:10px}.mini-password-form .profile-input{max-width:360px}.inline-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
    </style>
</head>
<body class="profile-page-body">
<nav class="navbar">
    <a class="navbar-brand" href="dashboard.php"><img src="../assets/images/Logo.PNG" alt="Herald Canteen" class="navbar-logo"><div class="navbar-title">Herald Canteen<span>Herald College Kathmandu</span></div></a>
    <ul class="navbar-nav"><li><a href="dashboard.php">Menu</a></li><li><a href="my_cart.php">🛒 Cart</a></li><li><a href="my_orders.php">My Orders</a></li><li><a href="user_profile.php" class="active">Profile</a></li></ul>
    <div class="navbar-user">
        <?php
        $nc_up = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $nc_up->bind_param("i", $user_id); $nc_up->execute();
        $notif_up_count = (int)$nc_up->get_result()->fetch_row()[0]; $nc_up->close();
        ?>
        <a href="notifications.php" class="notif-wrap" title="Notifications" style="position:relative;display:inline-flex;align-items:center;font-size:20px;text-decoration:none;margin-right:8px;">
            🔔<?php if ($notif_up_count > 0): ?><span class="notif-badge"><?= $notif_up_count ?></span><?php endif; ?>
        </a>
        <label class="theme-toggle" title="Toggle light/dark mode"><input type="checkbox" class="theme-checkbox"><span class="theme-slider"></span></label>
    </div>
</nav>

<main class="profile-page-shell">
    <section class="profile-hero"><div><p class="profile-eyebrow">Account Centre</p><h1>My Profile</h1><p>Manage your customer details and security settings.</p></div><a href="dashboard.php" class="profile-hero-link">← Back to Menu</a></section>

    <?php if ($success): ?><div class="profile-alert profile-alert-success" role="alert"><span>✔</span><p><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>
    <?php if ($error): ?><div class="profile-alert profile-alert-error" role="alert"><span>⚠</span><p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p></div><?php endif; ?>

    <section class="profile-stats-grid" aria-label="Customer activity summary">
        <article class="profile-stat-card"><span>Total Orders</span><strong><?= $total_orders ?></strong></article>
        <article class="profile-stat-card"><span>Delivered</span><strong><?= $delivered ?></strong></article>
        <article class="profile-stat-card"><span>Cart Items</span><strong><?= $cart_items ?></strong></article>
        <article class="profile-stat-card"><span>Total Spent</span><strong>Rs <?= number_format($total_spent, 0) ?></strong></article>
    </section>

    <section class="profile-card-modern">
        <aside class="profile-summary-panel">
            <div class="profile-avatar-modern" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></div>
            <h2><?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></p>
            <span class="profile-role-pill">Customer</span>
            <div class="profile-mini-list"><div><span>Phone</span><strong><?= $phone_display !== '' ? htmlspecialchars($phone_display, ENT_QUOTES, 'UTF-8') : 'Not set' ?></strong></div><div><span>Member Since</span><strong><?= htmlspecialchars($member_since, ENT_QUOTES, 'UTF-8') ?></strong></div></div>
        </aside>

        <div class="profile-content-panel">
            <div class="profile-panel-heading"><div><p class="profile-eyebrow">Personal Details</p><h3>Profile Information</h3></div><button type="button" class="profile-btn profile-btn-outline" id="editToggleBtn" data-editing="<?= $open_edit ? 'true' : 'false' ?>"><?= $open_edit ? 'Editing Profile' : '✏️ Edit Profile' ?></button></div>

            <form id="profileForm" class="profile-form-modern profile-locked-form <?= $open_edit ? 'is-editing' : '' ?>" method="POST" action="user_profile.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="update_profile_basic" value="1">
                <div class="profile-form-group"><label for="profileName">Full Name</label><input id="profileName" class="profile-input profile-edit-field" type="text" name="name" value="<?= htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8') ?>" maxlength="100" autocomplete="name" required <?= $open_edit ? '' : 'readonly' ?>></div>
                <div class="profile-form-group"><label for="profilePhone">Phone Number</label><input id="profilePhone" class="profile-input profile-edit-field" type="tel" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="20" autocomplete="tel" placeholder="e.g. 9800000001" <?= $open_edit ? '' : 'readonly' ?>></div>
                <div class="profile-form-actions" id="profileFormActions" <?= $open_edit ? '' : 'hidden' ?>><button type="submit" class="profile-btn profile-btn-primary" id="saveProfileBtn">Save Changes</button><button type="button" class="profile-btn profile-btn-muted" id="cancelEditBtn">Cancel</button></div>
            </form>

            <div style="margin-top:32px;">
                <p class="profile-section-label">📧 Email Address</p>
                <div class="email-change-panel">
                    <p style="margin:0 0 8px;color:var(--text-muted,#8b949e);font-size:.85rem;">Current email: <strong style="color:var(--text-primary,#e6edf3);"><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></strong> <?= $email_verified ? '<span class="email-verified-badge">✓ Verified</span>' : '<span class="email-unverified-badge">⚠ Unverified</span>' ?></p>
                    <p style="margin:0 0 16px;color:var(--text-muted,#8b949e);font-size:.82rem;">Changing email requires your current password and a 6-digit OTP sent to the new email address.</p>
                    <button type="button" class="profile-btn profile-btn-outline" id="showEmailChangeBtn">✉️ Change Email Address</button>
                    <div id="emailChangePanel" style="display:none;margin-top:16px;">
                        <form method="POST" action="user_profile.php" id="emailChangeForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>"><input type="hidden" name="request_email_change" value="1">
                            <div class="profile-form-group"><label for="newEmailInput">New Email Address</label><input id="newEmailInput" class="profile-input" type="email" name="new_email" placeholder="Enter your new email" maxlength="150" autocomplete="email" required></div>
                            <div class="profile-form-group"><label for="emailCurrentPassword">Current Password</label><input id="emailCurrentPassword" class="profile-input" type="password" name="current_password" placeholder="Enter current password" required></div>
                            <div class="inline-actions"><button type="submit" class="profile-btn profile-btn-primary" id="sendEmailOtpBtn">Send Verification Code</button><button type="button" class="profile-btn profile-btn-muted" id="cancelEmailChangeBtn">Cancel</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <div style="margin-top:32px;">
                <p class="profile-section-label">🔐 Security Settings</p>
                <div class="security-grid">
                    <div class="security-card"><div class="security-row"><div><h4>Email Status</h4><p>Your login email must be verified before you can use all account recovery features.</p></div><?= $email_verified ? '<span class="email-verified-badge">✓ Verified</span>' : '<span class="email-unverified-badge">⚠ Unverified</span>' ?></div></div>
                    <div class="security-card"><div class="security-row"><div><h4>2-Step Login</h4><p><?= $mfa_enabled ? 'An OTP code will be required each time you login.' : 'Login currently uses email and password only. Enable this for extra protection.' ?></p></div><?= $mfa_enabled ? '<span class="mfa-on-badge">Enabled</span>' : '<span class="mfa-off-badge">Disabled</span>' ?></div>
                        <form method="POST" action="user_profile.php" class="mini-password-form" id="mfaForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="<?= $mfa_enabled ? 'disable_mfa' : 'enable_mfa' ?>" value="1">
                            <label for="mfaPassword" style="color:var(--text-muted,#8b949e);font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;">Current Password</label>
                            <input id="mfaPassword" class="profile-input" type="password" name="mfa_current_password" placeholder="Enter current password" required>
                            <div><button type="submit" class="profile-btn <?= $mfa_enabled ? 'profile-btn-muted' : 'profile-btn-primary' ?>" id="mfaBtn"><?= $mfa_enabled ? 'Disable 2-Step Login' : 'Enable 2-Step Login' ?></button></div>
                        </form>
                    </div>
                    <div class="security-card">
                        <div class="security-row">
                            <div><h4>Change Password</h4><p>Update your account password. You will need to enter your current password to confirm.</p></div>
                            <span style="font-size:1.4rem;">🔑</span>
                        </div>
                        <form method="POST" action="user_profile.php" class="mini-password-form" id="changePasswordForm">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="change_password" value="1">
                            <label for="pwCurrent" style="color:var(--text-muted,#8b949e);font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;">Current Password</label>
                            <input id="pwCurrent" class="profile-input" type="password" name="pw_current" placeholder="Enter current password" required>
                            <label for="pwNew" style="color:var(--text-muted,#8b949e);font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;">New Password</label>
                            <input id="pwNew" class="profile-input" type="password" name="pw_new" placeholder="At least 10 characters" required>
                            <label for="pwConfirm" style="color:var(--text-muted,#8b949e);font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;">Confirm New Password</label>
                            <input id="pwConfirm" class="profile-input" type="password" name="pw_confirm" placeholder="Repeat new password" required>
                            <div><button type="submit" class="profile-btn profile-btn-primary" id="changePwBtn">Change Password</button></div>
                        </form>
                    </div>
            </div>
        </div>
    </section>
</main>

<script>
const editBtn=document.getElementById('editToggleBtn');const cancelBtn=document.getElementById('cancelEditBtn');const profileForm=document.getElementById('profileForm');const formActions=document.getElementById('profileFormActions');const editFields=document.querySelectorAll('.profile-edit-field');const saveBtn=document.getElementById('saveProfileBtn');const originalValues={};editFields.forEach(f=>{originalValues[f.name]=f.value;});function enableEditMode(){profileForm.classList.add('is-editing');editBtn.classList.replace('profile-btn-outline','profile-btn-editing');editBtn.textContent='Editing Profile';editBtn.setAttribute('data-editing','true');editFields.forEach(f=>f.removeAttribute('readonly'));formActions.hidden=false;document.getElementById('profileName')?.focus();}function disableEditMode(reset=true){profileForm.classList.remove('is-editing');editBtn.classList.replace('profile-btn-editing','profile-btn-outline');editBtn.textContent='✏️ Edit Profile';editBtn.setAttribute('data-editing','false');if(reset){editFields.forEach(f=>{if(Object.prototype.hasOwnProperty.call(originalValues,f.name)){f.value=originalValues[f.name];}});}editFields.forEach(f=>f.setAttribute('readonly','readonly'));formActions.hidden=true;}editBtn?.addEventListener('click',()=>{if(editBtn.getAttribute('data-editing')!=='true')enableEditMode();});cancelBtn?.addEventListener('click',()=>disableEditMode(true));profileForm?.addEventListener('submit',()=>{if(saveBtn){saveBtn.disabled=true;saveBtn.textContent='Saving…';}});
const showEmailChangeBtn=document.getElementById('showEmailChangeBtn');const emailChangePanel=document.getElementById('emailChangePanel');const cancelEmailChangeBtn=document.getElementById('cancelEmailChangeBtn');const emailChangeForm=document.getElementById('emailChangeForm');const sendEmailOtpBtn=document.getElementById('sendEmailOtpBtn');showEmailChangeBtn?.addEventListener('click',()=>{emailChangePanel.style.display='block';showEmailChangeBtn.style.display='none';document.getElementById('newEmailInput')?.focus();});cancelEmailChangeBtn?.addEventListener('click',()=>{emailChangePanel.style.display='none';showEmailChangeBtn.style.display='';document.getElementById('newEmailInput').value='';document.getElementById('emailCurrentPassword').value='';});emailChangeForm?.addEventListener('submit',()=>{if(sendEmailOtpBtn){sendEmailOtpBtn.disabled=true;sendEmailOtpBtn.textContent='Sending…';}});
const mfaForm=document.getElementById('mfaForm');const mfaBtn=document.getElementById('mfaBtn');mfaForm?.addEventListener('submit',()=>{if(mfaBtn){mfaBtn.disabled=true;mfaBtn.textContent='Processing…';}});
const changePwForm=document.getElementById('changePasswordForm');const changePwBtn=document.getElementById('changePwBtn');changePwForm?.addEventListener('submit',()=>{if(changePwBtn){changePwBtn.disabled=true;changePwBtn.textContent='Updating…';}});
</script>
<script src="../assets/js/notif_poller.js"></script>
</body>
</html>
