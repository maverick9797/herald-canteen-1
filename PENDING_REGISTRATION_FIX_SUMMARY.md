# Pending Registration Fix

## What changed

Registration no longer inserts a real row into the `users` table before email OTP verification.

The new flow is:

1. Customer submits the registration form.
2. The app validates the form and checks that the email is not already in `users`.
3. The registration data is stored in `pending_registrations` with a hashed password.
4. A registration OTP is sent to the email.
5. Only after the correct OTP is entered does the app create the real `users` row.
6. The real user is created with `email_verified_at = NOW()` and `mfa_enabled = 0`.
7. The pending registration row is deleted.

If the user leaves the OTP page, no real account is created.

## Files changed

- `pages/register.php`
  - Removed immediate user creation.
  - Saves registration data into `pending_registrations` instead.
  - Sends registration OTP using `user_id = NULL`.

- `pages/verify_otp.php`
  - Registration OTP now activates a pending registration by creating the real user only after OTP success.
  - Supports legacy unverified users if any exist from older builds.
  - Resend checks that the pending registration still exists.

- `includes/functions.php`
  - Added helpers for pending registration cleanup, save, fetch, delete, and final user creation.

- `database/otp_migration.sql`
  - Added `pending_registrations` table.
  - Added optional cleanup SQL for old abandoned unverified customer accounts.

- `database/herald_canteen.sql`
  - Added `pending_registrations` table for fresh installs.

## SQL to run on an existing database

Run `database/otp_migration.sql` once.

Optional cleanup for abandoned old-version unverified customer accounts:

```sql
DELETE FROM users
WHERE role = 'customer'
AND email_verified_at IS NULL
AND created_at < (NOW() - INTERVAL 1 DAY);
```

## Manual tests

1. Register with a new email and stop at the OTP page.
2. Check `users`: the email should not exist.
3. Check `pending_registrations`: the email should exist until expiry.
4. Enter the correct OTP.
5. Check `users`: the email should now exist with `email_verified_at` set.
6. Check `pending_registrations`: the email should be removed.
7. Log in normally with the new account.
8. Confirm forgot password, email change OTP, and optional MFA still work.
