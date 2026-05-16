# Herald Canteen — Fixed Release

## What Was Fixed

### 1. KOT Download (chef_kot_print.php — NEW FILE)
- Replaced unreliable JavaScript `window.open()` popup KOT with a server-side printable page at `pages/chef_kot_print.php?kot_id=N`
- Requires chef login; uses prepared statements
- Shows KOT ID, Order ID, customer name, order status, delivery mode, order date, all items, total, and **Customer Remark**
- KOT remains accessible after status becomes **Ready** (fetch query includes `archived` KOTs whose order is `ready` or `out_for_delivery`)
- Print button labelled "Print / Save PDF" (browser print → Save as PDF)

### 2. On Delivery in My Orders (my_orders.php)
- Added `out_for_delivery` → `on_delivery` mapping in `$status_map`
- `filter_to_db_status('on_delivery')` now returns `['out_for_delivery']`
- Active tab/filter/count includes `out_for_delivery`
- Count row for `on_delivery` populated correctly
- Footer note shows "🛵 On Delivery / On the Way!" for `out_for_delivery` orders
- Payment banners added: `?payment=success` and `?payment=cod_placed`

### 3. Dine In → Delivery (payment.php, cod_confirm.php, esewa_verify.php, khalti_verify.php, kot_invoice.php)
- `dine_in` removed from all customer-facing UI
- Delivery modes are now `delivery` (with fee) and `takeaway` (no fee)
- Payment page shows dynamic delivery fee (Rs 30, free above Rs 500)
- Grand total updates live in the UI when mode changes
- All verify/confirm pages use `['delivery','takeaway']` whitelist
- DB schema: `delivery_mode ENUM('delivery','takeaway')` — migration converts `dine_in` → `delivery`

### 4. Customer Remark (payment.php, cod_confirm.php, esewa_verify.php, khalti_verify.php, chef-control.php, chef_kot_print.php, kot_invoice.php)
- UI label changed to "Customer Remark"
- Remark saved to session **before** any gateway redirect
- Remark read from session in all payment handlers (COD form also sends it in hidden field as backup)
- Validates: `trim()`, `strip_tags()`, max 500 chars, stored as NULL if empty
- Displayed prominently (highlighted green) on Chef KOT card
- Displayed on Chef's printable KOT page
- Displayed as "📝 Customer Remark:" on customer invoice

### 5. Invoice Layout (kot_invoice.php)
- Print CSS: `@page { size: A4; margin: 15mm }`, dark/readable text in print, `min-height` removed
- All text forced to `#111` in print; accent colour `#2a7a27`
- `page-break-inside: avoid` on invoice box — no extra blank page
- Button labelled "🖨️ Print / Save PDF" (not fake download)
- Delivery mode shows correct label (Delivery/Takeaway; legacy `dine_in` → Delivery)
- `out_for_delivery` status shows as "On Delivery"

### 6. Cart/Payment Total Consistency (my_cart.php, payment.php)
- `my_cart.php` calculates: subtotal + delivery fee = grand total (unchanged)
- `payment.php` uses the same constants (`DELIVERY_FEE=30`, `FREE_DELIVERY_THRESHOLD=500`) and recalculates correctly
- Session stores `subtotal`, `delivery_fee`, `total` separately
- Gateway amount uses `grand_total` (includes delivery fee)
- Live JS in payment page recalculates grand total when delivery mode changes
- Topbar amount and Pay button amount both update

### 7. COD Payment Logic (cod_confirm.php, staff-control.php)
- COD order: payment record inserted as `payment_status = 'pending'`
- Invoice: `is_paid = 0` (preview only) until staff confirms
- Staff COD confirmation: now accepts orders in `ready` OR `out_for_delivery` status
- On staff confirmation: payment updated to `successful`, `paid_at = NOW()`, **invoice updated to `is_paid = 1`** (customer can now download)
- All wrapped in a transaction with proper rollback

### 8. Database Schema (herald_canteen.sql, kot_migration.sql)
- Fresh schema: `delivery_mode ENUM('delivery','takeaway')` — no `dine_in`
- Migration: `ADD COLUMN IF NOT EXISTS delivery_mode`, converts existing `dine_in` → `delivery`
- `kitchen_order_tickets.delivery_mode` uses same enum
- COD `payments` rows backfilled as `pending`; eSewa/Khalti as `successful`
- Stored procedure `create_kot_and_invoice` accepts `delivery`/`takeaway`
- Trigger `trg_archive_kot_on_ready` archives KOT when order hits `ready`
- View `v_active_kots` updated to match

### 9. Broken Links & Security (dashboard.php, payment_preview.php, functions.php)
- `login.php` → `portal-login.php` (dashboard.php)
- `cart.php` → `my_cart.php` (payment_preview.php)
- `user-login.php` → `portal-login.php` (functions.php redirect)
- Added `delivery_mode_label()` helper in `includes/functions.php`
- All payment pages require `$_SESSION['role'] === 'customer'`
- `chef_kot_print.php` requires `require_role('chef')`

---

## Files Changed

| File | Status | Summary |
|------|--------|---------|
| `pages/chef_kot_print.php` | **NEW** | Server-side printable KOT for chef |
| `pages/chef-control.php` | Modified | Server-side KOT link, delivery label, remark display, archive fix |
| `pages/payment.php` | Modified | Delivery→Takeaway modes, delivery fee, remark label, session fix |
| `pages/cod_confirm.php` | Modified | COD pending payment, delivery enum fix, remark from session |
| `pages/esewa_verify.php` | Modified | Delivery enum fix, auth check, remark from session |
| `pages/khalti_verify.php` | Modified | Delivery enum fix, auth check, remark from session |
| `pages/my_orders.php` | Modified | out_for_delivery support, counts, banners |
| `pages/kot_invoice.php` | Modified | Print CSS, delivery label, remark label, status fix |
| `pages/my_cart.php` | Modified | Checkout link (no form submit needed) |
| `pages/staff-control.php` | Modified | COD confirm: accepts ready/out_for_delivery, marks invoice paid |
| `pages/dashboard.php` | Modified | login.php → portal-login.php |
| `pages/payment_preview.php` | Modified | cart.php → my_cart.php |
| `includes/functions.php` | Modified | user-login.php → portal-login.php, delivery_mode_label() helper |
| `database/herald_canteen.sql` | Modified | New enum, COD pending, all tables |
| `database/kot_migration.sql` | Modified | Converts dine_in, COD payment backfill, updated procedures |

---

## Setup Instructions

### Fresh Install
```bash
mysql -u root -p < database/herald_canteen.sql
```

### Existing Install (migration)
```bash
mysql -u root -p herald_canteen < database/kot_migration.sql
```

---

## Manual Test Steps

1. **Customer selects Delivery or Takeaway**
   - Go to menu → add items → cart → Checkout
   - Payment page shows "Delivery 🚚" and "Takeaway 🥡" options (no Dine In)
   - Switching mode updates delivery fee and grand total in real time

2. **Customer enters remark**
   - Type "Less spicy, no onion" in "Customer Remark" field on payment page
   - Submit via COD, eSewa, or Khalti

3. **COD order**
   - Order appears in My Orders with status "In Process"
   - Invoice shows "⏳ Payment pending"
   - No "View Invoice" download link yet (only preview)

4. **Chef sees KOT with remark**
   - Log in as chef → chef panel → KOT Tickets section
   - Card shows "📝 Customer Remark: Less spicy, no onion" in green
   - Click "Print / Download KOT" → opens `chef_kot_print.php` in new tab
   - Page shows all fields including remark; "Print / Save PDF" button at top

5. **Chef marks Preparing → Ready**
   - Mark order as Preparing, then Ready
   - KOT card remains visible (archived but shown while order is ready)
   - "Print / Download KOT" link still works after Ready status

6. **Staff marks Ready → Out for Delivery**
   - Log in as staff → find order → mark as "Out for Delivery"
   - Customer My Orders → On Delivery tab shows the order
   - Status badge: "🛵 On the Way"

7. **Staff confirms COD payment**
   - Staff page → confirm COD payment for the order
   - Invoice `is_paid` becomes 1
   - Customer My Orders → order shows "View Invoice" and "Print / Save PDF" button

8. **Customer invoice**
   - Open invoice → shows correct total (with delivery fee), correct delivery mode, remark
   - Click "Print / Save PDF" → browser print dialog opens
   - No extra blank page; all text is dark and readable

9. **Delivered/Cancelled orders**
   - Appear in "Delivered" tab in My Orders
   - Reorder button available

10. **Fresh DB import**
    - `mysql -u root -p < database/herald_canteen.sql` — no errors
    - All tables, procedures, trigger, view created correctly

## OTP/MFA v7 setup notes

This build uses registration email OTP, optional 2-Step Login, forgot-password OTP, and OTP-gated email change.

### Gmail SMTP

Create a local-only file:

```text
config/mail_config.php
```

Copy from:

```text
config/mail_config.example.php
```

Then set:

```php
'smtp_username' => 'foodorderingdeliverysystem@gmail.com',
'smtp_password' => 'YOUR_NEW_16_DIGIT_GMAIL_APP_PASSWORD_WITHOUT_SPACES',
'from_email'    => 'foodorderingdeliverysystem@gmail.com',
'dev_log_fallback' => false,
```

Do not commit `config/mail_config.php`. It is already ignored by `.gitignore`.

### Existing database migration

Run:

```text
database/otp_migration.sql
```

This adds/updates:

- `users.mfa_enabled` default `0`
- `users.email_verified_at`
- `otp_tokens` purposes: `register`, `login`, `forgot_password`, `email_change`, `enable_mfa`

### Behaviour

- Registration requires OTP and verifies `email_verified_at`.
- Login OTP is only required when `mfa_enabled = 1`.
- Customers can enable/disable 2-Step Login in My Profile.
- Email change requires current password and OTP to the new email.
- Forgot password always requires OTP.
- Demo-only chef/staff fake emails ending with `@heraldcanteen.com` can use OTP code `123456` for login/forgot-password demo flows.

## Pending Registration Behaviour

New customer accounts are now created only after registration OTP verification.

During registration, the app stores submitted details in `pending_registrations` and sends an OTP. The real `users` row is created only after the OTP is verified. If the user closes the OTP page or never verifies the code, the real account is not created and the email is not blocked permanently.

For existing databases, run:

```sql
SOURCE database/otp_migration.sql;
```

Optional cleanup for abandoned unverified customer rows from older versions:

```sql
DELETE FROM users
WHERE role = 'customer'
AND email_verified_at IS NULL
AND created_at < (NOW() - INTERVAL 1 DAY);
```

## Chef/Staff Secret Code Login

Chef and staff accounts use an additional demo security step after password login.

Default chef/staff secret code:

```text
123456
```

Flow:

1. Enter chef/staff email and password on `pages/portal-login.php`.
2. The app redirects to `pages/verify_role_secret.php`.
3. Enter `123456`.
4. Chef redirects to `chef-control.php`; staff redirects to `staff-control.php`.

Customer login is not affected. Customers only receive login OTP when they enable 2-Step Login from profile.
