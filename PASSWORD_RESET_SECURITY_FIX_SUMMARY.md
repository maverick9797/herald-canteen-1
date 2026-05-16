# Password Reset Security Fix Summary

## Issue fixed
The forgot-password reset flow allowed a customer to set the new password to the same value as the current password. The user profile password-change flow already blocked this, so the behaviour was inconsistent.

## Files changed
- `pages/reset_password.php`

## What changed
- Added a safe lookup for the reset user using both `user_id` and `email` from the verified reset session.
- Added server-side validation using `password_verify($new_password, $current_hash)`.
- Forgot-password reset now shows:
  - `New password must be different from your current password.`
- Added a safer update condition:
  - password is updated only when `user_id`, `email`, and `is_active = 1` match.
- If the reset session becomes invalid, the reset session data is cleared and the user is asked to request a new reset code.

## Database migration
No database migration is required.

## Regression notes
Existing functionality should remain unchanged:
- forgot password OTP flow still works
- profile password change still works
- payment fixes remain untouched
- reorder/history fixes remain untouched
- live sync fixes remain untouched

## Testing checklist
1. From login page, use Forgot Password.
2. Verify OTP.
3. Enter the same current password as the new password.
4. Expected: application rejects it with `New password must be different from your current password.`
5. Enter a different valid password.
6. Expected: password resets successfully and redirects to login.
7. Login with the new password.
8. Confirm profile password change still rejects reusing the current password.
