<?php
// ============================================================
// includes/otp_helpers.php — Herald Canteen
// ============================================================
// Reusable, secure OTP helper system.
//
// Security model:
//   - OTPs are 6-digit numeric codes generated via random_int().
//   - Only the bcrypt hash (password_hash) is stored in DB.
//   - Verification uses password_verify() — timing-safe.
//   - Each OTP is single-purpose (login / forgot_password / email_change).
//   - Max 5 attempts; expires in 10 minutes; 60-second resend cooldown.
//   - Issuing a new OTP for the same user+purpose invalidates all older ones.
//   - No OTP, hash, or internal token is ever returned to the browser.
//
// Requires:
//   - config/db.php  ($conn — mysqli connection)
//   - includes/mailer.php
//   - otp_tokens table (see database/otp_migration.sql)
// ============================================================

require_once __DIR__ . '/mailer.php';

// ── Constants ────────────────────────────────────────────────
define('OTP_LENGTH',          6);
define('OTP_EXPIRY_SECONDS',  10 * 60);   // 10 minutes
define('OTP_MAX_ATTEMPTS',    5);
define('OTP_RESEND_COOLDOWN', 60);         // seconds

// ── Generate a cryptographically-safe OTP ────────────────────
/**
 * Generates a zero-padded 6-digit OTP string.
 * e.g. "048291"
 *
 * @return string  Plain OTP (NEVER store this; only pass to hash + email)
 */
function generate_otp(): string
{
    $max = (int)str_pad('', OTP_LENGTH, '9') + 1; // 10^6 = 1000000
    $min = (int)str_pad('1', OTP_LENGTH, '0');     // 100000
    return str_pad((string)random_int($min, $max - 1), OTP_LENGTH, '0', STR_PAD_LEFT);
}

// ── Purge expired / used tokens ──────────────────────────────
/**
 * Cleans up stale OTP rows for this email+purpose.
 * Called every time a new OTP is issued to keep the table lean.
 */
function purge_old_otps(mysqli $conn, string $email, string $purpose): void
{
    $stmt = $conn->prepare(
        "DELETE FROM otp_tokens
         WHERE  email   = ?
           AND  purpose = ?
           AND  (expires_at < NOW() OR is_used = 1)"
    );
    $stmt->bind_param("ss", $email, $purpose);
    $stmt->execute();
    $stmt->close();
}

// ── Invalidate ALL active tokens for user+purpose ────────────
/**
 * Marks all active (unused, not-expired) tokens as used
 * so the new token is the only valid one.
 */
function invalidate_active_otps(mysqli $conn, string $email, string $purpose): void
{
    $stmt = $conn->prepare(
        "UPDATE otp_tokens
         SET    is_used = 1
         WHERE  email   = ?
           AND  purpose = ?
           AND  is_used = 0
           AND  expires_at >= NOW()"
    );
    $stmt->bind_param("ss", $email, $purpose);
    $stmt->execute();
    $stmt->close();
}

// ── Check resend cooldown ────────────────────────────────────
/**
 * Returns seconds remaining on the resend cooldown (0 = can resend now).
 */
function otp_resend_seconds_remaining(mysqli $conn, string $email,
                                       string $purpose): int
{
    $stmt = $conn->prepare(
        "SELECT GREATEST(0,
                 ? - TIMESTAMPDIFF(SECOND, created_at, NOW())
                ) AS secs_left
         FROM   otp_tokens
         WHERE  email     = ?
           AND  purpose   = ?
           AND  is_used   = 0
           AND  expires_at >= NOW()
         ORDER  BY created_at DESC
         LIMIT  1"
    );
    $cooldown = OTP_RESEND_COOLDOWN;
    $stmt->bind_param("iss", $cooldown, $email, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['secs_left'] ?? 0);
}

// ── Issue OTP ────────────────────────────────────────────────
/**
 * issue_otp()
 *
 * Generates a new OTP, stores the hash, and sends the email.
 *
 * @param  mysqli       $conn
 * @param  int|null     $user_id   NULL for forgot-password before user lookup
 * @param  string       $email     The address the OTP is sent to
 * @param  string       $full_name Recipient display name
 * @param  string       $purpose   'register' | 'login' | 'forgot_password' | 'email_change' | 'enable_mfa'
 * @param  string|null  $new_email For email_change: the new email. NULL otherwise.
 * @return array{ok:bool, error:string, cooldown_seconds:int}
 */
function issue_otp(mysqli $conn, ?int $user_id, string $email,
                   string $full_name, string $purpose,
                   ?string $new_email = null): array
{
    // 1. Resend cooldown check
    // Skip cooldown entirely for demo (@heraldcanteen.com) accounts — they use
    // a fixed code so there's no email cost, and staff/chef going Back and
    // re-submitting their email must not get silently stuck on the form.
    $is_demo_early = str_ends_with(strtolower($email), '@heraldcanteen.com');
    if (!$is_demo_early) {
        $secs = otp_resend_seconds_remaining($conn, $email, $purpose);
        if ($secs > 0) {
            return [
                'ok'               => false,
                'error'            => "Please wait {$secs} second(s) before requesting a new code.",
                'cooldown_seconds' => $secs,
            ];
        }
    }

    // 2. Purge old expired/used rows, then invalidate active ones
    purge_old_otps($conn, $email, $purpose);
    invalidate_active_otps($conn, $email, $purpose);

    // 3. Generate OTP and hash it
    // DEMO MODE: @heraldcanteen.com accounts always use 123456 so staff/chef
    // can log in and reset passwords without a live mail server.
    $is_demo_account = str_ends_with(strtolower($email), '@heraldcanteen.com');

    if ($is_demo_account) {
        $plain_otp = '123456';
        $otp_hash  = password_hash('123456', PASSWORD_DEFAULT);
    } else {
        $plain_otp = generate_otp();
        $otp_hash  = password_hash($plain_otp, PASSWORD_DEFAULT);
    }
    // Never log or return $plain_otp after this point — only use for email

    // 4. Insert new token row
    // Demo accounts get a very long expiry (24h) so staff/chef aren't
    // time-pressured when resetting their password with the fixed code.
    $expiry_seconds = ($is_demo_account && $purpose === 'forgot_password')
        ? 24 * 60 * 60   // 24 hours for demo password reset
        : OTP_EXPIRY_SECONDS;

    $expires_at = date('Y-m-d H:i:s', time() + $expiry_seconds);

    $stmt = $conn->prepare(
        "INSERT INTO otp_tokens
             (user_id, email, purpose, otp_hash, expires_at, new_email)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("isssss",
        $user_id, $email, $purpose, $otp_hash, $expires_at, $new_email
    );
    $stmt->execute();
    $stmt->close();

    // 5. Send email — skipped for demo accounts (they use the fixed code 123456)
    if (!$is_demo_account) {
        $mail_result = send_otp_email($email, $full_name, $plain_otp, $purpose);

        // Unset plain OTP immediately after use
        unset($plain_otp);

        if (!$mail_result['ok']) {
            // Invalidate the token we just created so it can't sit dangling
            invalidate_active_otps($conn, $email, $purpose);
            return [
                'ok'               => false,
                'error'            => $mail_result['error'],
                'cooldown_seconds' => 0,
            ];
        }
    } else {
        unset($plain_otp); // Clear even for demo accounts
    }

    return ['ok' => true, 'error' => '', 'cooldown_seconds' => 0];
}

// ── Verify OTP ───────────────────────────────────────────────
/**
 * verify_otp()
 *
 * Checks the submitted OTP against the stored hash.
 * Increments attempt counter; invalidates on success or max attempts.
 *
 * @param  mysqli   $conn
 * @param  string   $email
 * @param  string   $purpose
 * @param  string   $submitted_otp  Raw user input (sanitised by caller)
 * @return array{
 *   ok          : bool,
 *   error       : string,
 *   expired     : bool,
 *   max_attempts: bool,
 *   new_email   : string|null,   — populated for email_change purpose
 *   user_id     : int|null,
 * }
 */
function verify_otp(mysqli $conn, string $email, string $purpose,
                    string $submitted_otp): array
{
    $empty = [
        'ok'           => false,
        'error'        => '',
        'expired'      => false,
        'max_attempts' => false,
        'new_email'    => null,
        'user_id'      => null,
    ];

    // Input sanity: OTP must be exactly 6 digits
    if (!preg_match('/^\d{6}$/', $submitted_otp)) {
        return array_merge($empty, ['error' => 'Invalid code format. Please enter the 6-digit code.']);
    }

    // Fetch the most-recent active, non-expired token for this email+purpose.
    // expires_at filter is applied in SQL so expired rows are never fetched,
    // avoiding wasted round-trips to mark them used in PHP.
    // The PHP expiry check below is kept as a safety-net for clock skew.
    $stmt = $conn->prepare(
        "SELECT otp_id, otp_hash, expires_at, attempts, new_email, user_id
         FROM   otp_tokens
         WHERE  email      = ?
           AND  purpose    = ?
           AND  is_used    = 0
           AND  expires_at >= NOW()
         ORDER  BY created_at DESC
         LIMIT  1"
    );
    $stmt->bind_param("ss", $email, $purpose);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return array_merge($empty, [
            'error' => 'No active verification code found. Please request a new one.',
        ]);
    }

    // Check expiry
    if (strtotime($row['expires_at']) < time()) {
        // Mark as used so it's cleaned up
        $stmt = $conn->prepare(
            "UPDATE otp_tokens SET is_used = 1 WHERE otp_id = ?"
        );
        $stmt->bind_param("i", $row['otp_id']);
        $stmt->execute();
        $stmt->close();

        return array_merge($empty, [
            'expired' => true,
            'error'   => 'Your verification code has expired. Please request a new one.',
        ]);
    }

    // Check attempts before verifying (prevent brute-force even on valid tokens)
    if ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
        $stmt = $conn->prepare(
            "UPDATE otp_tokens SET is_used = 1 WHERE otp_id = ?"
        );
        $stmt->bind_param("i", $row['otp_id']);
        $stmt->execute();
        $stmt->close();

        return array_merge($empty, [
            'max_attempts' => true,
            'error'        => 'Too many incorrect attempts. Please request a new code.',
        ]);
    }

    // Increment attempt counter BEFORE verifying (prevents race condition
    // where two simultaneous requests both see attempts=0 and both pass)
    $stmt = $conn->prepare(
        "UPDATE otp_tokens SET attempts = attempts + 1 WHERE otp_id = ?"
    );
    $stmt->bind_param("i", $row['otp_id']);
    $stmt->execute();
    $stmt->close();

    // Timing-safe comparison via password_verify
    if (!password_verify($submitted_otp, $row['otp_hash'])) {
        $attempts_after = (int)$row['attempts'] + 1;
        $remaining      = OTP_MAX_ATTEMPTS - $attempts_after;

        if ($remaining <= 0) {
            // Invalidate
            $stmt = $conn->prepare(
                "UPDATE otp_tokens SET is_used = 1 WHERE otp_id = ?"
            );
            $stmt->bind_param("i", $row['otp_id']);
            $stmt->execute();
            $stmt->close();
            return array_merge($empty, [
                'max_attempts' => true,
                'error'        => 'Too many incorrect attempts. Please request a new code.',
            ]);
        }

        return array_merge($empty, [
            'error' => "Incorrect code. {$remaining} attempt(s) remaining.",
        ]);
    }

    // ✓ OTP is correct — mark as used
    $stmt = $conn->prepare(
        "UPDATE otp_tokens SET is_used = 1 WHERE otp_id = ?"
    );
    $stmt->bind_param("i", $row['otp_id']);
    $stmt->execute();
    $stmt->close();

    return [
        'ok'           => true,
        'error'        => '',
        'expired'      => false,
        'max_attempts' => false,
        'new_email'    => $row['new_email'],
        'user_id'      => $row['user_id'] !== null ? (int)$row['user_id'] : null,
    ];
}