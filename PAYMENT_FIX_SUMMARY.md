# Payment Fix Summary — eSewa + COD

## Main fixes made

1. **Fixed eSewa transaction UUID format**
   - Old code used `uniqid('hc_', true)`, which creates values containing `_` and sometimes `.`.
   - eSewa v2 accepts alphanumeric characters and hyphen only.
   - New IDs now look like: `HC-20260514165020-A1B2C3D4E5F6`.

2. **Fresh eSewa UUID after failed/cancelled attempt**
   - If a user cancels or returns from eSewa and retries, the app now creates a new eSewa-safe transaction UUID.
   - This avoids reusing the same `transaction_uuid`, which eSewa may reject.

3. **eSewa signature now always matches the submitted amount**
   - Amounts are formatted consistently using `0.00` decimal style.
   - The latest delivery mode, delivery location and customer remark are synced immediately before redirecting to eSewa.

4. **COD no longer fails after selecting eSewa then going back**
   - COD now uses CSRF + the live cart from the database.
   - It no longer depends on the eSewa `transaction_uuid`, which could be stale after browser back/forward behaviour.

5. **COD recalculates total from live cart**
   - Prevents stale session totals from causing failed or incorrect orders.

6. **Improved eSewa callback validation**
   - Verifies callback signature.
   - Verifies product code.
   - Verifies paid amount matches the expected checkout amount.
   - Avoids duplicate order creation if eSewa redirects twice.

7. **Database migration added**
   - Added `database/payment_checkout_fix_migration.sql`.
   - It creates/fixes delivery location columns, KOT tables, invoice tables and the `create_kot_and_invoice` procedure.
   - It avoids `ADD COLUMN IF NOT EXISTS`, which can fail on some MySQL versions.

## Files changed

- `includes/payment_helpers.php` — new shared payment helper functions.
- `pages/payment.php` — fixed eSewa UUID/signature/session sync and browser-back handling.
- `pages/cod_confirm.php` — rewrote COD flow to avoid stale eSewa session dependency.
- `pages/esewa_verify.php` — strengthened eSewa callback verification and duplicate prevention.
- `database/payment_checkout_fix_migration.sql` — new MySQL-safe checkout/payment migration.
- `database/delivery_locations_migration.sql` — replaced with the safer checkout/payment migration content.
- `database/herald_canteen.sql` — patched for fresh install compatibility and appended checkout/payment migration.
- `database/otp_migration.sql` — patched to avoid unsupported `ADD COLUMN IF NOT EXISTS` syntax.

## After uploading to server

Run this once on your existing database:

```bash
mysql -u root -p herald_canteen < database/payment_checkout_fix_migration.sql
```

Then test:

1. Add items to cart.
2. Select delivery location.
3. Choose eSewa and cancel/go back.
4. Switch to COD and place order.
5. Try eSewa again and confirm the redirect opens correctly.

