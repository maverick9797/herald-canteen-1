<?php

/* ============================================================
   INPUT HELPERS
   ============================================================ */

function clean_input(string $data): string
{
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/* ============================================================
   REGISTRATION HELPERS
   ============================================================ */

function email_exists(mysqli $conn, string $email): bool
{
    $sql  = "SELECT user_id FROM users WHERE email = ? LIMIT 1";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function register_user(mysqli $conn, string $full_name, string $email, string $password): bool
{
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $sql  = "INSERT INTO users (full_name, email, password, role, is_active, mfa_enabled, email_verified_at) VALUES (?, ?, ?, 'customer', 1, 0, NOW())";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("sss", $full_name, $email, $hashed_password);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

function cleanup_pending_registrations(mysqli $conn): void
{
    $stmt = $conn->prepare("DELETE FROM pending_registrations WHERE expires_at < NOW()");

    if ($stmt) {
        $stmt->execute();
        $stmt->close();
    }
}

function save_pending_registration(mysqli $conn, string $full_name, string $email, string $password, ?string $phone = null): bool
{
    cleanup_pending_registrations($conn);

    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    $expires_at = date('Y-m-d H:i:s', time() + 30 * 60);

    $sql = "INSERT INTO pending_registrations
                (full_name, email, password_hash, phone, role, expires_at)
            VALUES (?, ?, ?, ?, 'customer', ?)
            ON DUPLICATE KEY UPDATE
                full_name = VALUES(full_name),
                password_hash = VALUES(password_hash),
                phone = VALUES(phone),
                expires_at = VALUES(expires_at),
                created_at = CURRENT_TIMESTAMP";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("sssss", $full_name, $email, $password_hash, $phone, $expires_at);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

function get_pending_registration_by_email(mysqli $conn, string $email): ?array
{
    cleanup_pending_registrations($conn);

    $stmt = $conn->prepare(
        "SELECT pending_id, full_name, email, password_hash, phone, role, expires_at, created_at
         FROM pending_registrations
         WHERE email = ? AND expires_at >= NOW()
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function delete_pending_registration(mysqli $conn, string $email): void
{
    $stmt = $conn->prepare("DELETE FROM pending_registrations WHERE email = ?");

    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->close();
    }
}

function create_user_from_pending_registration(mysqli $conn, array $pending): ?int
{
    $role = $pending['role'] ?? 'customer';
    if ($role !== 'customer') {
        return null;
    }

    $phone = $pending['phone'] ?? null;

    $stmt = $conn->prepare(
        "INSERT INTO users
            (full_name, email, password, role, phone, is_active, mfa_enabled, email_verified_at)
         VALUES (?, ?, ?, 'customer', ?, 1, 0, NOW())"
    );

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        "ssss",
        $pending['full_name'],
        $pending['email'],
        $pending['password_hash'],
        $phone
    );

    $success = $stmt->execute();
    $new_user_id = $success ? (int)$stmt->insert_id : null;
    $stmt->close();

    return $new_user_id ?: null;
}

/* ============================================================
   LOGIN HELPERS
   ============================================================ */

function find_user_by_email(mysqli $conn, string $email): ?array
{
    // mfa_enabled / email_verified_at added by otp_migration.sql.
    // COALESCE guards against old installs where the column may not yet exist.
    $sql = "SELECT user_id, full_name, email, password, role, is_active,
                   COALESCE(mfa_enabled, 0)              AS mfa_enabled,
                   email_verified_at
            FROM users
            WHERE email = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();
    $user   = $result->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

function find_user_by_id(mysqli $conn, int $user_id): ?array
{
    $sql = "SELECT user_id, full_name, email, password, role, is_active,
                   COALESCE(mfa_enabled, 0) AS mfa_enabled,
                   email_verified_at
            FROM users
            WHERE user_id = ?
            LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $user ?: null;
}

function login_user(array $user): void
{
    // FIX: Snapshot existing flash data BEFORE regenerate so it isn't lost.
    // Then write all session data, then call session_write_close() so the
    // session file is fully flushed to disk BEFORE the redirect fires.
    // Without session_write_close(), PHP keeps the file locked; the next
    // page request opens the OLD session file (race condition on Windows/XAMPP)
    // and sees an empty session, instantly logging the user out.
    $flash = $_SESSION; // preserve any existing flash/CSRF data

    session_regenerate_id(); // keep old file until GC — safe on Windows XAMPP

    $_SESSION = $flash; // restore flash into new session ID

    $_SESSION['user_id']        = $user['user_id'];
    $_SESSION['full_name']      = $user['full_name'];
    $_SESSION['email']          = $user['email'];
    $_SESSION['role']           = $user['role'];

    $now = time();
    $_SESSION['_last_activity'] = $now;
    $_SESSION['_last_regen']    = $now;

    // CRITICAL FIX: flush session to disk NOW, before the header() redirect.
    // If this is omitted, the session file may still be locked/buffered when
    // the browser lands on the next page, causing an empty session read.
    session_write_close();
}

function redirect_user_by_role(string $role): void
{
    if ($role === 'chef') {
        header("Location: chef-control.php");
        exit;
    }

    if ($role === 'staff') {
        header("Location: staff-control.php");
        exit;
    }

    if ($role === 'customer') {
        header("Location: dashboard.php");
        exit;
    }

    header("Location: portal-login.php");
    exit;
}

/* ============================================================
   DELIVERY MODE HELPERS
   ============================================================ */

/**
 * Returns a human-readable label for a delivery_mode value.
 * Handles both current ('delivery','takeaway') and legacy ('dine_in') values.
 */
function delivery_mode_label(string $mode): string
{
    return match($mode) {
        'delivery' => 'Delivery 🚚',
        'takeaway' => 'Takeaway 🥡',
        'dine_in'  => 'Takeaway 🥡',   // legacy value — dine-in no longer offered, mapped to Takeaway
        default    => ucfirst(str_replace('_', ' ', $mode)),
    };
}

/* ============================================================
   RATE LIMITING — SCRUM-53
   ─────────────────────────────────────────────────────────────
   All three functions below work together to implement
   database-backed rate limiting on the login page.

   Why database-backed instead of session-based?
   - Session counters reset when a user clears cookies or opens
     a new browser/incognito tab — completely bypassing the lock.
   - Storing attempts in the login_attempts table means the
     block is enforced by IP address regardless of session state.

   Constants:
     RATE_LIMIT_MAX      — max failed attempts before lockout (5)
     RATE_LIMIT_WINDOW   — rolling time window in seconds (5 min)
   ============================================================ */

define('RATE_LIMIT_MAX',    5);
define('RATE_LIMIT_WINDOW', 5 * 60); // 5 minutes in seconds

/**
 * is_rate_limited()
 *
 * Checks whether the given IP address has exceeded the allowed
 * number of failed login attempts within the rolling time window.
 *
 * It also purges expired attempts for this IP on every call so
 * the table stays clean without needing a cron job.
 *
 * Returns an array with:
 *   'blocked'   => bool   — true if the IP is currently locked out
 *   'remaining' => int    — minutes left on the lockout (0 if not blocked)
 *   'attempts'  => int    — current failed attempt count in the window
 */
function is_rate_limited(mysqli $conn, string $ip): array
{
    // 1. Delete attempts older than the rolling window for this IP.
    $window = RATE_LIMIT_WINDOW;
    $purge = $conn->prepare(
        "DELETE FROM login_attempts
         WHERE ip_address = ?
           AND attempted_at < DATE_SUB(NOW(), INTERVAL ? SECOND)"
    );
    $purge->bind_param("si", $ip, $window);
    $purge->execute();
    $purge->close();

    // 2. Count remaining attempts AND get remaining seconds — all inside MySQL
    //    so there is no PHP time() vs MySQL NOW() timezone mismatch.
    $count_stmt = $conn->prepare(
        "SELECT
             COUNT(*) AS attempt_count,
             GREATEST(0, ? - TIMESTAMPDIFF(SECOND, MIN(attempted_at), NOW())) AS remaining_seconds
         FROM login_attempts
         WHERE ip_address = ?"
    );
    $count_stmt->bind_param("is", $window, $ip);
    $count_stmt->execute();
    $row = $count_stmt->get_result()->fetch_assoc();
    $count_stmt->close();

    $attempt_count     = (int)($row['attempt_count'] ?? 0);
    $remaining_seconds = (int)($row['remaining_seconds'] ?? 0);
    $is_blocked        = $attempt_count >= RATE_LIMIT_MAX;

    // Only compute a non-zero remaining time when the IP is actually blocked
    // and the window hasn't fully elapsed. max(1,...) is applied here — inside
    // the blocked branch — so it never inflates the value for unblocked IPs.
    $remaining_minutes = ($is_blocked && $remaining_seconds > 0)
                         ? max(1, (int)ceil($remaining_seconds / 60))
                         : 0;

    return [
        'blocked'   => $is_blocked,
        'remaining' => $remaining_minutes,
        'attempts'  => $attempt_count,
    ];
}

/**
 * log_failed_attempt()
 *
 * Inserts one row into login_attempts for the given IP address.
 * Called every time a login attempt fails for any reason
 * (wrong email, wrong password, inactive account, etc.).
 */
function log_failed_attempt(mysqli $conn, string $ip): void
{
    $stmt = $conn->prepare(
        "INSERT INTO login_attempts (ip_address) VALUES (?)"
    );
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $stmt->close();
}

/**
 * clear_failed_attempts()
 *
 * Deletes ALL login_attempts rows for the given IP address.
 * Called immediately after a successful login so the user
 * starts fresh on their next visit.
 */
function clear_failed_attempts(mysqli $conn, string $ip): void
{
    $stmt = $conn->prepare(
        "DELETE FROM login_attempts WHERE ip_address = ?"
    );
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $stmt->close();
}
/* ============================================================
   USER EVENT LOGGING
   ============================================================ */

/**
 * log_user_event()
 *
 * Inserts one row into user_logs for auditing login/logout/access events.
 *
 * @param mysqli      $conn        DB connection
 * @param string      $event_type  One of: login_success, login_failed, logout, access_denied
 * @param string      $ip          Requester's IP address
 * @param string      $description Human-readable detail string
 * @param int|null    $user_id     NULL for anonymous events (e.g. login_failed before lookup)
 */
function log_user_event(
    mysqli  $conn,
    string  $event_type,
    string  $ip,
    string  $description = '',
    ?int    $user_id     = null
): void {
    $stmt = $conn->prepare(
        "INSERT INTO user_logs (user_id, event_type, ip_address, description) VALUES (?, ?, ?, ?)"
    );
    if (!$stmt) {
        return; // silently skip if table not yet created
    }
    $stmt->bind_param("isss", $user_id, $event_type, $ip, $description);
    $stmt->execute();
    $stmt->close();
}
