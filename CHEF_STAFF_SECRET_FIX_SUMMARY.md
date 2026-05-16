# Chef/Staff Secret Code Login Fix

## What changed

Chef and staff accounts no longer open their control pages immediately after entering only email and password.

New flow:

1. Chef/staff enters email and password on `portal-login.php`.
2. If the password is correct, the app stores a temporary pending staff-secret session.
3. The app redirects to `verify_role_secret.php`.
4. Chef/staff enters the demo secret code: `123456`.
5. Only after the correct code is entered, the real login session is created and the user is redirected to the correct page.

Customer login remains unchanged:

- Verified customer with 2-Step Login OFF logs in directly.
- Customer with 2-Step Login ON still uses email OTP.
- Registration OTP, forgot password OTP, and email-change OTP are not changed.

## Files changed/added

- `pages/portal-login.php`
  - Added chef/staff password success step that redirects to `verify_role_secret.php`.
  - Chef/staff no longer enter directly after password.

- `pages/verify_role_secret.php`
  - New page for chef/staff secret code verification.
  - Code: `123456`.
  - Includes CSRF protection, 10-minute expiry, and 5-attempt limit.

## Manual test steps

1. Open `pages/portal-login.php`.
2. Login as `chef@heraldcanteen.com` with password `password`.
3. App should redirect to the staff/chef secret code page.
4. Enter wrong code.
5. Error should show and attempts should reduce.
6. Enter `123456`.
7. App should redirect to `chef-control.php`.
8. Logout.
9. Login as `staff@heraldcanteen.com` with password `password`.
10. App should ask for the secret code.
11. Enter `123456`.
12. App should redirect to `staff-control.php`.
13. Confirm customer login still works normally.
