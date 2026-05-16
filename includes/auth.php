<?php

/* ============================================================
   SESSION CONFIGURATION
   ============================================================
   Rules:
   - configure_secure_session() must be called BEFORE session_start()
   - session_start() must be called exactly ONCE per request
   - Never call session_regenerate_id() with true on Windows XAMPP
     (it deletes the old file before the new one is flushed — race condition)
   ============================================================ */

function configure_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Already started — nothing to configure
        return;
    }

    // Do NOT rename the session cookie away from PHPSESSID.
    // Changing session_name() mid-deployment means every existing browser
    // still sends the old cookie name and gets a blank session every reload.
    // session_name('HC_SESSION');  <-- removed, was causing logout-on-every-reload

    ini_set('session.cookie_httponly', '1');

    // cookie_secure must only be on for HTTPS. On plain HTTP XAMPP localhost
    // a secure cookie is silently dropped by the browser — session lost.
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    } else {
        ini_set('session.cookie_secure', '0');
    }

    // Lax (not Strict) — Strict blocks the cookie on POST→redirect flows,
    // which is exactly what happens after updating an order status.
    ini_set('session.cookie_samesite', 'Lax');

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid',    '0');

    // 2-hour lifetime so session files aren't garbage-collected mid-shift
    ini_set('session.gc_maxlifetime', '7200');

    // Make cookie last 2 hours in the browser too (default is session-only)
    session_set_cookie_params([
        'lifetime' => 7200,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/* ============================================================
   START SESSION (safe — idempotent)
   ============================================================ */

function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        configure_secure_session();
        session_start();
    }
}

/* ============================================================
   SESSION SECURITY CHECK
   — idle timeout only; fingerprinting removed.

   Fingerprinting (User-Agent + Accept-Language hashing) was the
   single biggest cause of logouts: Accept-Language changes when
   browser language packs update, and some XAMPP/Windows PHP builds
   normalise header capitalisation differently per request.
   For a local canteen system the security trade-off is not worth it.
   ============================================================ */

function session_security_check(): void
{
    $now = time();

    // Initialise timestamp on first visit
    if (!isset($_SESSION['_last_activity'])) {
        $_SESSION['_last_activity'] = $now;
        $_SESSION['_last_regen']    = $now;
        return;
    }

    // Idle timeout: 2 hours
    $idle_timeout = 2 * 60 * 60;
    if ($now - $_SESSION['_last_activity'] > $idle_timeout) {
        $was_logged_in = isset($_SESSION['user_id']);
        session_unset();
        session_destroy();

        // FIX: Re-configure BEFORE re-starting so cookie params are preserved.
        configure_secure_session();
        session_start();

        if ($was_logged_in) {
            // FIX: Flush new (empty) session to disk before the redirect,
            // so the login page receives a clean session — not a half-written one.
            session_write_close();
            header('Location: portal-login.php?timeout=1');
            exit;
        }
        return;
    }

    $_SESSION['_last_activity'] = $now;

    // Regenerate session ID every 30 minutes.
    // Do NOT pass true on Windows — deleting the old file before the new
    // one is fully written causes a race condition where the very next
    // request finds no session data and logs the user out.
    $regen_interval = 30 * 60;
    if ($now - ($_SESSION['_last_regen'] ?? $now) > $regen_interval) {
        $data = $_SESSION;           // snapshot all data
        session_regenerate_id();     // false/omitted = keep old file until GC
        $_SESSION = $data;           // restore data into new session
        $_SESSION['_last_regen'] = $now;
        // NOTE: No session_write_close() here — the page continues to run
        // and may write more data. The lock is released automatically at
        // end-of-request. Only add session_write_close() directly before
        // a header() redirect where nothing else needs the session.
    }
}

/* ============================================================
   ROLE-BASED ACCESS GUARDS
   ============================================================ */

function require_login(): void
{
    if (!isset($_SESSION['user_id'])) {
        // FIX: Write session before redirect so no partial-write race.
        session_write_close();
        header('Location: portal-login.php');
        exit;
    }
}

function require_role(string $required_role): void
{
    if (!isset($_SESSION['user_id'])) {
        session_write_close();
        header('Location: portal-login.php');
        exit;
    }
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $required_role) {
        // Log the unauthorised access attempt before redirecting
        $page = basename($_SERVER['PHP_SELF'] ?? 'unknown');
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $uid  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $role = $_SESSION['role'] ?? 'unknown';
        try {
            require_once __DIR__ . '/../config/db.php';
            require_once __DIR__ . '/../includes/functions.php';
            log_user_event(
                $conn,
                'access_denied',
                $ip,
                "Access denied to {$page} — user_id " . ($uid ?? 'unknown') . " has role '{$role}', required '{$required_role}'",
                $uid
            );
        } catch (\Throwable $e) { /* fail silently — never block the redirect */ }
        session_write_close();
        header('Location: portal-login.php');
        exit;
    }
}

function require_any_role(array $roles): void
{
    if (!isset($_SESSION['user_id'])) {
        session_write_close();
        header('Location: portal-login.php');
        exit;
    }
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $roles, true)) {
        $page = basename($_SERVER['PHP_SELF'] ?? 'unknown');
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $uid  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $role = $_SESSION['role'] ?? 'unknown';
        try {
            require_once __DIR__ . '/../config/db.php';
            require_once __DIR__ . '/../includes/functions.php';
            log_user_event(
                $conn,
                'access_denied',
                $ip,
                "Access denied to {$page} — user_id " . ($uid ?? 'unknown') . " has role '{$role}', required one of [" . implode(',', $roles) . "]",
                $uid
            );
        } catch (\Throwable $e) { /* fail silently */ }
        session_write_close();
        header('Location: portal-login.php');
        exit;
    }
}

/* ============================================================
   LOGOUT
   ============================================================ */

function logout_user(): void
{
    session_unset();
    session_destroy();

    // FIX: After destroy, start a fresh session so the cookie is reset
    // properly (prevents browser from holding a dead session cookie).
    configure_secure_session();
    session_start();
    session_regenerate_id(); // new clean ID for the post-logout anonymous session
    session_write_close();   // flush immediately — logout.php redirects right after
}