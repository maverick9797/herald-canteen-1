# Reorder + Order History Fix Summary

## Files changed

- `includes/order_helpers.php` — new safe reorder helper.
- `includes/payment_helpers.php` — tightened delivery-fee calculation so empty carts do not produce a fee and the same rule is used by checkout handlers.
- `pages/my_cart.php` — replaced old GET reorder flow with POST + CSRF reorder handling. Reorder now replaces the cart instead of adding quantities.
- `pages/my_orders.php` — redesigned as the Active Orders page only.
- `pages/order_history.php` — new customer order history page for delivered/cancelled orders from the last 7 days.
- `pages/payment.php` — aligned frontend delivery-fee calculation with backend helper logic.
- `database/order_history_reorder_fix_migration.sql` — safe indexes and cart total repair migration.
- `database/herald_canteen.sql` — appended the same reorder/history safety migration for fresh installs.

## Core fixes

### 1. Reorder is now idempotent

Old behavior:

```sql
ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
```

This meant every repeated Reorder click increased cart quantity. If the subtotal crossed Rs. 500, delivery became free unintentionally.

New behavior:

- Verifies the selected order belongs to the logged-in customer.
- Reads the old order items.
- Checks current `menu_items` availability.
- Does not clear the cart if none of the old order items are available.
- Clears the current cart only after available reorder items are confirmed.
- Inserts the exact old order quantities once.
- Uses current menu item prices for `cart.total_price`.
- Uses a database transaction so cart replacement is all-or-nothing.

Repeatedly clicking Reorder now produces the same cart state instead of multiplying quantities.

### 2. Delivery fee cannot become free by accidental duplicate reorder

Delivery fee is recalculated from the live cart subtotal. The rule remains:

- Delivery mode + subtotal below Rs. 500 = Rs. 30 delivery fee.
- Delivery mode + subtotal Rs. 500 or above = free delivery.
- Takeaway = Rs. 0 delivery fee.

### 3. My Orders split into two customer sections

`pages/my_orders.php` now shows only active orders:

- `pending`
- `preparing`
- `ready`
- `out_for_delivery`

Delivered/cancelled orders are moved out of the active tracking page.

### 4. Dedicated Order History page

New page:

```text
pages/order_history.php
```

It shows only the logged-in customer’s delivered/cancelled orders from the last 7 days, with:

- order number
- date/time
- status
- total amount
- payment method
- delivery/takeaway mode
- delivery location where available
- item list
- invoice links where available
- safe POST reorder button

### 5. Customer-side delete/cross icon removed/avoided

Customer order cards do not include any delete, hide, clear, or cross icon for old orders. Staff/chef history-hide features are untouched.

## Migration to run

After replacing the project files, run:

```bash
mysql -u root -p herald_canteen < database/order_history_reorder_fix_migration.sql
```

For XAMPP Windows, example:

```bash
C:\xampp\mysql\bin\mysql.exe -u root -p herald_canteen < C:\xampp\htdocs\herald_canteen_part2\database\order_history_reorder_fix_migration.sql
```

If your root MySQL user has no password, press Enter when prompted.

## Testing checklist

1. Use a delivered order with subtotal below Rs. 500.
2. Click Reorder.
3. Confirm cart has exact item quantity once and delivery fee is Rs. 30.
4. Go back to Order History and click Reorder again.
5. Confirm cart quantity does not increase.
6. Test eSewa cancel/back then COD checkout still works.
7. Confirm `my_orders.php` shows active orders only.
8. Confirm `order_history.php` shows delivered/cancelled orders from the last 7 days only.
9. Confirm there is no customer-side delete/cross icon on order cards.

## PHP lint

All PHP files in `pages/`, `includes/`, and `config/` were checked with `php -l` and passed syntax validation.
