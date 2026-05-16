<?php
// cod_confirm.php — Cash on Delivery order handler
require_once '../includes/auth.php';
start_session();
session_security_check();
require_once '../config/db.php';
require_once '../includes/payment_helpers.php';

if (empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'customer') {
    header('Location: dashboard.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: payment.php');
    exit;
}

// CSRF check. COD must not depend on any eSewa transaction UUID because users may
// choose eSewa, press back/cancel, then switch to COD from the same checkout page.
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    header('Location: payment.php?status=failed&err=csrf');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

// If the browser resubmits the COD form, avoid duplicate orders.
$checkout_token = $_POST['checkout_token'] ?? ($_SESSION['pending_payment']['checkout_token'] ?? null);
if (!empty($checkout_token)) {
    $existing_order_id = hc_order_exists_for_checkout_token($conn, $user_id, $checkout_token);
    if ($existing_order_id) {
        unset($_SESSION['pending_payment']);
        session_write_close();
        header("Location: my_orders.php?payment=cod_placed&order_id=$existing_order_id");
        exit;
    }
}

try {
    $cart_items = hc_fetch_cart_items($conn, $user_id);
} catch (Throwable $e) {
    error_log('COD cart load failed: ' . $e->getMessage());
    header('Location: payment.php?status=failed&err=cart_load');
    exit;
}

if (empty($cart_items)) {
    unset($_SESSION['pending_payment']);
    header('Location: my_cart.php?error=empty_cart');
    exit;
}

// Delivery mode & notes — trust the current submitted form, not an old eSewa session.
$allowed_modes = ['delivery', 'takeaway'];
$delivery_mode = in_array($_POST['delivery_mode'] ?? '', $allowed_modes, true)
    ? $_POST['delivery_mode']
    : 'delivery';

$raw_notes = $_POST['special_notes'] ?? '';
$special_notes = (trim($raw_notes) !== '')
    ? substr(strip_tags(trim($raw_notes)), 0, 500)
    : null;

$posted_loc_id = (int)($_POST['delivery_location_id'] ?? 0);
try {
    [$delivery_location_id, $delivery_location_name, $delivery_block_name] = hc_validate_delivery_location(
        $conn,
        $delivery_mode,
        $posted_loc_id > 0 ? $posted_loc_id : null
    );
} catch (InvalidArgumentException $e) {
    header('Location: payment.php?status=failed&err=' . urlencode($e->getMessage()));
    exit;
} catch (Throwable $e) {
    error_log('COD location validation failed: ' . $e->getMessage());
    header('Location: payment.php?status=failed&err=location_check');
    exit;
}

$totals = hc_calculate_totals($cart_items, $delivery_mode);
$total  = $totals['total'];

$conn->begin_transaction();
try {
    // 1. Create order with full subtotal/delivery-fee breakdown.
    $order_id = hc_insert_order(
        $conn,
        $user_id,
        $totals,
        'cod',
        $delivery_mode,
        $special_notes,
        $delivery_location_id,
        $delivery_location_name,
        $delivery_block_name,
        $checkout_token
    );

    // 2. Order items.
    hc_insert_order_items($conn, $order_id, $cart_items);

    // 3. Clear cart immediately after order creation.
    hc_clear_cart($conn, $user_id);

    // 4. Create KOT + invoice. The invoice remains unpaid for COD until staff confirms.
    hc_create_kot_and_invoice($conn, $order_id, $user_id, $delivery_mode, $special_notes);

    // 5. Payment record — COD starts as PENDING and has no eSewa transaction_uuid.
    $stmt = $conn->prepare(
        "INSERT INTO payments (order_id, transaction_uuid, payment_method, payment_status, amount)
         VALUES (?, NULL, 'cod', 'pending', ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Payment prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('id', $order_id, $total);
    $stmt->execute();
    $stmt->close();

    // 6. Notification.
    $title = 'Order Placed — Cash on Delivery';
    $msg   = "Your order has been placed. Please pay Rs. " . number_format($total, 2) . ' on delivery.';
    $stmt  = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'order')");
    if (!$stmt) {
        throw new RuntimeException('Notification prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('iss', $user_id, $title, $msg);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    unset($_SESSION['pending_payment']);
    session_write_close();
    header("Location: my_orders.php?payment=cod_placed&order_id=$order_id");
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    error_log('COD order failed: ' . $e->getMessage());
    header('Location: payment.php?status=failed&err=cod_save');
    exit;
}
