# Payment Core Fix Summary

This build fixes the checkout/payment failure after testing the real database dump.

## Main database problems found

1. The live database did not contain the stored procedure `create_kot_and_invoice`, but COD/eSewa/Khalti order saving called it.
2. The previous payment migration could create a procedure that inserted `user_id` into `kitchen_order_tickets`, while the live `kitchen_order_tickets` table did not have a `user_id` column. This caused order saving to fail after payment selection.
3. Old COD payment rows had eSewa-style `transaction_uuid` values. COD should not depend on a gateway transaction UUID.
4. New payment order inserts were not consistently saving `subtotal_amount` and `delivery_fee`, only `total_amount`.
5. The checkout needed an idempotency token to avoid duplicate COD orders if the form is resubmitted.

## Code fixes made

- `includes/payment_helpers.php`
  - Added resilient database compatibility helpers.
  - Added fallback KOT/invoice creation when the stored procedure is missing or incompatible.
  - Added shared order insertion with subtotal, delivery fee, total, delivery mode, location, notes and checkout token.
  - Added cart clearing and order item helper functions.

- `pages/cod_confirm.php`
  - COD now uses only current POST data + live cart data.
  - COD no longer depends on eSewa transaction state.
  - COD creates pending payment rows with `transaction_uuid = NULL`.
  - COD now creates order, items, KOT, invoice, payment and notification inside one transaction.

- `pages/esewa_verify.php`
  - eSewa success callback now stores subtotal/delivery fee correctly.
  - eSewa uses the server-side checkout snapshot that was signed before redirect.
  - eSewa duplicate callback handling is improved.
  - KOT/invoice creation uses the safe helper fallback.

- `pages/khalti_verify.php`
  - Updated to use the same safe checkout/order creation helpers.

- `pages/payment.php`
  - Added `checkout_token` to prevent duplicate COD submissions.
  - Keeps eSewa UUID/signature sync before redirect.

## Database file added

Run this once after uploading the fixed project:

```bash
mysql -u root -p herald_canteen < database/payment_core_fix_migration.sql
```

This migration is designed for the database dump provided by the user and also supports older versions of the project database.
