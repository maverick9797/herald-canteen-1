# Herald Canteen v7 — Registration OTP + Optional 2-Step Login

## What changed

### Registration OTP
- New customers are created with `email_verified_at = NULL` and `mfa_enabled = 0`.
- After registration, a 6-digit OTP is sent to the registered email.
- Correct OTP sets `email_verified_at = NOW()`.
- User is redirected to login after email verification.

### Login behaviour
- Login OTP is no longer forced for every user.
- Verified users with `mfa_enabled = 0` log in with email + password only.
- Verified users with `mfa_enabled = 1` must enter an email OTP after password.
- Unverified users are blocked from logging in and are sent a registration verification OTP.

### Profile security settings
- Customer profile now shows email verification status.
- Customer profile now has a 2-Step Login security section.
- Enabling 2-Step Login requires current password and an OTP to the current verified email.
- Disabling 2-Step Login requires current password.

### Email change
- Changing email now requires current password.
- The database email is not changed until the OTP sent to the new email is verified.
- Correct email-change OTP updates `users.email` and `email_verified_at = NOW()`.

### Forgot password
- Forgot password still always uses OTP.
- Password reset does not automatically log the user in.

### Demo OTP for fake chef/staff emails
- For chef/staff accounts whose email ends with `@heraldcanteen.com`, the OTP verification page accepts `123456` for these purposes only:
  - login
  - forgot password
  - enable_mfa
- This is only for demo/testing of fake chef/staff emails.
- Customer registration and customer email-change still require the real emailed OTP.

## Important Gmail setup

Do not commit your real Gmail App Password. Create this file locally:

```php
config/mail_config.php
```

Use:

```php
<?php
return [
    'smtp_host'       => 'smtp.gmail.com',
    'smtp_port'       => 587,
    'smtp_username'   => 'foodorderingdeliverysystem@gmail.com',
    'smtp_password'   => 'YOUR_NEW_16_DIGIT_APP_PASSWORD_WITHOUT_SPACES',
    'smtp_encryption' => 'tls',
    'from_email'      => 'foodorderingdeliverysystem@gmail.com',
    'from_name'       => 'Herald Canteen',
    'dev_log_fallback' => false,
    'dev_log_path'     => __DIR__ . '/../logs/mail_dev.log',
];
```

Because the app password was shared in chat, revoke it and create a new app password before using the project.

## Migration for existing database

Run:

```sql
database/otp_migration.sql
```

This keeps MFA optional by default and adds the new OTP purposes.

## Test checklist

1. Register with a real email.
2. Confirm OTP is sent.
3. Enter wrong OTP and confirm it fails.
4. Enter correct OTP and confirm login page opens.
5. Login with verified account and MFA disabled: no login OTP should appear.
6. Go to profile and enable 2-Step Login with current password.
7. Enter OTP and confirm MFA is enabled.
8. Logout and login again: OTP should now be required.
9. Disable 2-Step Login with current password.
10. Change email and verify OTP sent to the new email.
11. Use forgot password and reset password through OTP.
12. For fake chef/staff `@heraldcanteen.com` OTP screens, use `123456` only for demo.
