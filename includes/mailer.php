<?php
// ============================================================
// includes/mailer.php — Herald Canteen
// ============================================================
// Wraps PHPMailer for all outbound email in this project.
//
// Usage:
//   require_once '../includes/mailer.php';
//   $result = send_otp_email('user@example.com', 'Jane', '482913', 'login');
//   if ($result['ok']) { /* success */ }
//   else               { /* $result['error'] */ }
//
// PHPMailer is loaded via Composer autoloader.
// Install once: composer require phpmailer/phpmailer
// Then the autoloader lives at:  vendor/autoload.php
//   (relative to the project root — one level above /pages/)
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Load Composer autoloader (path: project_root/vendor/autoload.php)
$_autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($_autoload)) {
    // PHPMailer not installed yet — callers must handle this gracefully
    if (!defined('PHPMAILER_MISSING')) {
        define('PHPMAILER_MISSING', true);
    }
} else {
    require_once $_autoload;
}

// ── Load mail config ─────────────────────────────────────────
// Uses config/mail_config.php (real credentials, gitignored).
// Falls back to mail_config.example.php so the app doesn't crash
// if the real file hasn't been created yet.
function _load_mail_config(): array
{
    $real    = __DIR__ . '/../config/mail_config.php';
    $example = __DIR__ . '/../config/mail_config.example.php';

    if (file_exists($real)) {
        return require $real;
    }
    if (file_exists($example)) {
        return require $example;
    }
    return [];
}

// ── OTP purpose labels ───────────────────────────────────────
function _otp_purpose_label(string $purpose): string
{
    return match ($purpose) {
        'register'        => 'Account Email Verification',
        'login'           => 'Login Verification',
        'forgot_password' => 'Password Reset',
        'email_change'    => 'Email Change Verification',
        'enable_mfa'      => '2-Step Login Setup',
        default           => 'Verification',
    };
}

// ── Build HTML email body ────────────────────────────────────
function _build_otp_email_html(string $full_name, string $otp, string $purpose): string
{
    $label   = htmlspecialchars(_otp_purpose_label($purpose), ENT_QUOTES, 'UTF-8');
    $name    = htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8');
    $otp_esc = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Herald Canteen — {$label}</title>
</head>
<body style="margin:0;padding:0;background:#0d1117;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#0d1117;padding:40px 0;">
  <tr>
    <td align="center">
      <table width="480" cellpadding="0" cellspacing="0"
             style="background:#161b22;border-radius:12px;
                    border:1px solid #30363d;overflow:hidden;">

        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#1a5c38,#22c55e);
                     padding:32px 40px;text-align:center;">
            <h1 style="margin:0;color:#ffffff;font-size:22px;
                        font-weight:700;letter-spacing:0.5px;">
              🍽️ Herald Canteen
            </h1>
            <p style="margin:6px 0 0;color:#d1fae5;font-size:13px;">
              Herald College Kathmandu
            </p>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:36px 40px;">
            <p style="margin:0 0 8px;color:#8b949e;font-size:13px;
                       text-transform:uppercase;letter-spacing:0.8px;">
              {$label}
            </p>
            <h2 style="margin:0 0 20px;color:#e6edf3;font-size:20px;">
              Hi {$name}, here is your verification code
            </h2>
            <p style="margin:0 0 24px;color:#8b949e;font-size:14px;
                       line-height:1.6;">
              Use the 6-digit code below to complete your request.
              This code expires in <strong style="color:#e6edf3;">10 minutes</strong>
              and can only be used once.
            </p>

            <!-- OTP Box -->
            <div style="background:#0d1117;border:2px solid #22c55e;
                         border-radius:10px;padding:24px;text-align:center;
                         margin:0 0 28px;">
              <span style="font-size:42px;font-weight:700;
                            letter-spacing:12px;color:#22c55e;
                            font-family:'Courier New',monospace;">
                {$otp_esc}
              </span>
            </div>

            <div style="background:#1c2128;border-left:3px solid #f59e0b;
                         border-radius:4px;padding:14px 16px;margin:0 0 24px;">
              <p style="margin:0;color:#fbbf24;font-size:13px;">
                ⚠️ <strong>Never share this code.</strong>
                Herald Canteen staff will never ask for your OTP.
              </p>
            </div>

            <p style="margin:0;color:#8b949e;font-size:13px;line-height:1.6;">
              If you did not request this code, you can safely ignore this email.
              Your account remains secure.
            </p>
          </td>
        </tr>

        <!-- Footer -->
        <tr>
          <td style="background:#0d1117;padding:20px 40px;
                     border-top:1px solid #21262d;text-align:center;">
            <p style="margin:0;color:#484f58;font-size:12px;">
              © Herald Canteen · Herald College Kathmandu<br>
              This is an automated message — please do not reply.
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>
HTML;
}

// ── Plain-text fallback body ─────────────────────────────────
function _build_otp_email_text(string $full_name, string $otp, string $purpose): string
{
    $label = _otp_purpose_label($purpose);
    return <<<TXT
Herald Canteen — {$label}
====================================

Hi {$full_name},

Your verification code is:

  {$otp}

This code expires in 10 minutes and can only be used once.

If you did not request this, please ignore this email.

— Herald Canteen, Herald College Kathmandu
TXT;
}

// ── Dev log fallback ─────────────────────────────────────────
function _write_dev_log(string $to_email, string $full_name, string $otp,
                        string $purpose, array $cfg): void
{
    $log_path = $cfg['dev_log_path'] ?? __DIR__ . '/../logs/mail_dev.log';
    $log_dir  = dirname($log_path);

    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0750, true);
    }

    // Never log to a publicly-reachable directory
    $realpath = realpath($log_dir);
    if ($realpath === false) {
        return; // can't determine; skip
    }

    $label = _otp_purpose_label($purpose);
    $line  = sprintf(
        "[%s] TO:%s | NAME:%s | PURPOSE:%s (%s) | OTP:%s\n",
        date('Y-m-d H:i:s'),
        $to_email,
        $full_name,
        $purpose,
        $label,
        $otp
    );

    // Append with locking to avoid garbled concurrent writes
    $fh = @fopen($log_path, 'ab');
    if ($fh) {
        flock($fh, LOCK_EX);
        fwrite($fh, $line);
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

// ── Main send function ───────────────────────────────────────
/**
 * send_otp_email()
 *
 * Sends a formatted OTP email via SMTP (PHPMailer).
 * Falls back to dev-log if configured and SMTP is unavailable.
 *
 * @param  string $to_email   Recipient address
 * @param  string $full_name  Recipient's display name
 * @param  string $otp        The plain 6-digit OTP (generated by otp_helpers.php)
 * @param  string $purpose    'login' | 'forgot_password' | 'email_change'
 * @return array{ok:bool, error:string}
 */
function send_otp_email(string $to_email, string $full_name,
                        string $otp, string $purpose): array
{
    $cfg = _load_mail_config();

    // Safety: never expose OTP in error responses or logs
    if (empty($cfg)) {
        return ['ok' => false, 'error' => 'Mail configuration not found.'];
    }

    // Check PHPMailer is available
    if (defined('PHPMAILER_MISSING') || !class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        // Attempt dev-log fallback
        if (!empty($cfg['dev_log_fallback'])) {
            _write_dev_log($to_email, $full_name, $otp, $purpose, $cfg);
            return ['ok' => true, 'error' => ''];
        }
        return [
            'ok'    => false,
            'error' => 'Email service is not configured. '
                      . 'Please run: composer require phpmailer/phpmailer'
        ];
    }

    // Validate inputs before touching SMTP
    if (!filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid recipient email address.'];
    }

    $has_smtp_config = !empty($cfg['smtp_host'])
                    && !empty($cfg['smtp_username'])
                    && !empty($cfg['smtp_password'])
                    && $cfg['smtp_password'] !== 'YOUR_SMTP_PASSWORD_HERE'
                    && $cfg['smtp_password'] !== 'APP_PASSWORD_HERE';

    if (!$has_smtp_config) {
        // No real SMTP configured
        if (!empty($cfg['dev_log_fallback'])) {
            _write_dev_log($to_email, $full_name, $otp, $purpose, $cfg);
            return ['ok' => true, 'error' => ''];
        }
        return [
            'ok'    => false,
            'error' => 'Email service is not configured. '
                      . 'Copy config/mail_config.example.php to config/mail_config.php '
                      . 'and fill in your SMTP credentials.',
        ];
    }

    try {
        $mail = new PHPMailer(true); // true = throw exceptions

        // Server settings
        $mail->isSMTP();
        $mail->Host        = $cfg['smtp_host'];
        $mail->SMTPAuth    = true;
        $mail->Username    = $cfg['smtp_username'];
        $mail->Password    = $cfg['smtp_password'];
        $mail->Port        = (int)($cfg['smtp_port'] ?? 587);
        $mail->SMTPSecure  = match (strtolower($cfg['smtp_encryption'] ?? 'tls')) {
            'ssl'   => PHPMailer::ENCRYPTION_SMTPS,
            default => PHPMailer::ENCRYPTION_STARTTLS,
        };

        // Never expose debug output — SMTPDebug 0 = silent
        $mail->SMTPDebug = SMTP::DEBUG_OFF;

        // Sender / recipient
        $mail->setFrom(
            $cfg['from_email'] ?? 'no-reply@heraldcanteen.com',
            $cfg['from_name']  ?? 'Herald Canteen'
        );
        $mail->addAddress($to_email, $full_name);
        $mail->addReplyTo(
            $cfg['from_email'] ?? 'no-reply@heraldcanteen.com',
            $cfg['from_name']  ?? 'Herald Canteen'
        );

        // Content
        $label = _otp_purpose_label($purpose);
        $mail->isHTML(true);
        $mail->Subject  = "Herald Canteen — Your {$label} Code";
        $mail->Body     = _build_otp_email_html($full_name, $otp, $purpose);
        $mail->AltBody  = _build_otp_email_text($full_name, $otp, $purpose);
        $mail->CharSet  = PHPMailer::CHARSET_UTF8;

        $mail->send();

        return ['ok' => true, 'error' => ''];

    } catch (PHPMailerException $e) {
        // Log the technical error server-side (not exposed to user)
        error_log('[Herald Canteen Mailer] PHPMailer error: ' . $e->getMessage());

        // Try dev-log fallback before giving up
        if (!empty($cfg['dev_log_fallback'])) {
            _write_dev_log($to_email, $full_name, $otp, $purpose, $cfg);
            return ['ok' => true, 'error' => ''];
        }

        return [
            'ok'    => false,
            'error' => 'We could not send the verification email. '
                      . 'Please check your email address or try again later.',
        ];
    }
}
