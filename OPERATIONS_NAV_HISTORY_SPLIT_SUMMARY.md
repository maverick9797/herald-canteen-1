# Herald Canteen v12 — Operations Navigation + History Cleanup

## What changed

### Chef portal
- `chef-control.php` is now focused on the active kitchen dashboard only.
- Main chef dashboard shows only new active KOT work: `pending` and `preparing`.
- Completed tickets no longer clutter the dashboard after being marked ready.
- Added dedicated pages:
  - `pages/chef-kitchen-tickets.php`
  - `pages/chef-categories.php`
  - `pages/chef-menu.php`
- Chef sidebar now navigates to separate pages instead of anchor sections.
- Added a safe “Clear Completed KOT History” button on the Kitchen Tickets page.
- Clearing chef history hides completed tickets from chef history without deleting orders, payments, invoices, or KOT records.

### Staff portal
- `staff-control.php` now shows only active dispatch orders: `ready` and `out_for_delivery`.
- Delivered paid orders are moved out of the main dashboard.
- Added `pages/staff-order-history.php` for delivered paid order history.
- Added a safe “Clear Paid History” button.
- Clearing staff history hides delivered paid orders from staff history without deleting any original database records.

### Database support
- Added non-destructive table `order_history_hidden` through:
  - `database/otp_migration.sql`
  - `database/herald_canteen.sql`
- The new table stores which orders have been hidden from chef/staff history views.
- Runtime pages also create the table if missing, so existing local databases are less likely to break.

### Styling
- Added CSS for:
  - quick navigation cards
  - responsive operation page links
  - table wrappers
  - smooth card removal animation

## Files changed/added
- `pages/chef-control.php`
- `pages/staff-control.php`
- `pages/chef-kitchen-tickets.php`
- `pages/chef-categories.php`
- `pages/chef-menu.php`
- `pages/staff-order-history.php`
- `assets/css/style.css`
- `database/otp_migration.sql`
- `database/herald_canteen.sql`

## Manual tests

### Chef
1. Login as chef and enter secret code `123456`.
2. Open `chef-control.php`.
3. Confirm only pending/preparing KOTs appear.
4. Click Start Preparing.
5. Click Mark Ready.
6. Confirm the card leaves the dashboard after ready.
7. Open Kitchen Tickets page.
8. Confirm completed tickets are visible there.
9. Click Clear Completed KOT History.
10. Confirm completed tickets disappear from the chef history page but are not deleted from database.
11. Open Categories and Manage Menu pages and test add/edit/delete.

### Staff
1. Login as staff and enter secret code `123456`.
2. Open `staff-control.php`.
3. Confirm only Ready and On Delivery orders appear.
4. Mark order On Delivery.
5. Confirm COD payment if required.
6. Mark Delivered.
7. Confirm card leaves the active dashboard.
8. Open Paid History.
9. Confirm delivered paid orders appear there.
10. Click Clear Paid History.
11. Confirm old paid history disappears without deleting orders/payments.

## Note
The history clearing is intentionally non-destructive. It hides records from chef/staff history screens but keeps order, payment, invoice, and KOT records intact for customer invoices and future audit/reporting.
