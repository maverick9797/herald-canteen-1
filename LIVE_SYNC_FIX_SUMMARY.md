# Herald Canteen v15 — Live Sync Fix Summary

## Goal
Add live status syncing so users, chefs, and staff can see order-status changes without manually refreshing the page.

## Files changed

### `pages/my_orders.php`
- Added a live-sync wrapper around the active-orders content.
- Added JavaScript polling every 4.5 seconds.
- The page now fetches the latest active-order HTML in the background and updates the order cards, stats, tabs, and empty states without a full browser refresh.
- Completed orders automatically disappear from Active Orders and can be viewed from Order History.

### `pages/chef-control.php`
- Added a live-sync wrapper around kitchen summary cards and the Kitchen Order Tickets queue.
- Added JavaScript polling every 4.5 seconds.
- New customer orders automatically appear in the chef queue.
- Orders moved by another chef/session are reflected automatically.
- Preserves the currently selected filter tab after each live update.
- Existing AJAX status buttons still work.

### `pages/staff-control.php`
- Added a live-sync wrapper around dispatch summary cards and the Active Delivery & Payment Orders queue.
- Added JavaScript polling every 4.5 seconds.
- Orders marked Ready by the chef automatically appear in the staff queue.
- Staff status/payment changes from another session are reflected automatically.
- Preserves the currently selected filter tab after each live update.
- Existing AJAX delivery/COD buttons still work.

## Database changes
No database migration is required for this fix.

## How the live sync works
This implementation uses lightweight AJAX polling. Every 4.5 seconds, the page fetches its own latest server-rendered HTML in the background, extracts the live region, and replaces only that live section in the current page.

This avoids a full page reload and keeps the existing PHP rendering, database queries, card designs, role checks, and payment/order logic intact.

## Preserved functionality
- COD/eSewa/Khalti payment fixes remain untouched.
- Reorder duplicate prevention remains untouched.
- Customer Order History remains untouched.
- Chef and staff AJAX action buttons remain working.
- Role separation remains unchanged.

## Testing checklist

### Customer My Orders
1. Open `pages/my_orders.php` as a customer.
2. In another browser/session, change the same order status from chef/staff.
3. The customer order card should update automatically within a few seconds.
4. When status becomes delivered, it should leave Active Orders and appear in Order History.

### Chef Control
1. Open `pages/chef-control.php` as chef.
2. Place a new order as customer.
3. The new KOT/order should appear automatically without refreshing.
4. Mark an order Preparing or Ready and confirm the UI still updates.

### Staff Control
1. Open `pages/staff-control.php` as staff.
2. Mark an order Ready from chef page.
3. The order should appear automatically in staff page without refreshing.
4. Mark On Delivery / Confirm COD / Mark Delivered and confirm the UI still updates correctly.

## Syntax check
PHP syntax check passed for all files in:
- `pages/`
- `includes/`
- `config/`
