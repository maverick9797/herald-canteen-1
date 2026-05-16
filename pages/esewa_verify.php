<?php
// esewa_verify.php — eSewa v2 callback handler
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
    if ($_SESSION['role'] === 'chef') { header('Location: chef-control.php'); } else { header('Location: staff-control.php'); }
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$pending = $_SESSION['pending_payment'] ?? [];

if (!isset($_GET['data'])) {
    header('Location: payment.php?status=failed&err=missing_esewa_data');
    exit;
}

// Some gateways/browsers may turn + into spaces. Restore before strict Base64 decode.
$raw_data = str_replace(' ', '+', (string)$_GET['data']);
$decoded_json = base64_decode($raw_data, true);
$decoded = $decoded_json !== false ? json_decode($decoded_json, true) : null;
if (!is_array($decoded)) {
    error_log('eSewa invalid callback data: ' . substr($raw_data, 0, 120));
    header('Location: payment.php?status=failed&err=invalid_esewa_data');
    exit;
}

// Verify eSewa response signature.
$esewa_secret_key    = '8gBm/:&EnhH.1/q';
$esewa_merchant_code = 'EPAYTEST';

$signed_field_names = $decoded['signed_field_names'] ?? '';
$fields = array_filter(array_map('trim', explode(',', $signed_field_names)));
$sign_parts = [];
foreach ($fields as $field) {
    if ($field === 'signature') {
        continue;
    }
    if (!array_key_exists($field, $decoded)) {
        error_log("eSewa callback missing signed field: {$field}");
        header('Location: payment.php?status=failed&err=bad_esewa_signature');
        exit;
    }
    $sign_parts[] = "{$field}={$decoded[$field]}";
}

$sign_string  = implode(',', $sign_parts);
$expected_sig = base64_encode(hash_hmac('sha256', $sign_string, $esewa_secret_key, true));
$received_sig = $decoded['signature'] ?? '';

if (empty($received_sig) || !hash_equals($expected_sig, $received_sig)) {
    error_log('eSewa signature mismatch. String: ' . $sign_string);
    header('Location: payment.php?status=failed&err=bad_esewa_signature');
    exit;
}

$status           = $decoded['status'] ?? '';
$transaction_uuid = (string)($decoded['transaction_uuid'] ?? '');
$product_code     = (string)($decoded['product_code'] ?? '');
$total_amount     = hc_normalize_amount($decoded['total_amount'] ?? 0);

if ($status !== 'COMPLETE') {
    header('Location: payment.php?status=failed&err=esewa_not_complete');
    exit;
}

if ($product_code !== $esewa_merchant_code) {
    error_log("eSewa product code mismatch: {$product_code}");
    header('Location: payment.php?status=failed&err=bad_esewa_product');
    exit;
}

if (empty($transaction_uuid) || !preg_match('/^[A-Za-z0-9-]+$/', $transaction_uuid)) {
    error_log('eSewa returned invalid transaction UUID: ' . $transaction_uuid);
    header('Location: payment.php?status=failed&err=bad_esewa_uuid');
    exit;
}

// Idempotency: if eSewa redirects twice, do not create a second order.
$existing_order_id = hc_order_exists_for_transaction($conn, 'esewa', $transaction_uuid);
if ($existing_order_id) {
    unset($_SESSION['pending_payment']);
    session_write_close();
    header("Location: my_orders.php?payment=success&order_id=$existing_order_id");
    exit;
}

if (empty($pending['transaction_uuid']) || !hash_equals((string)$pending['transaction_uuid'], $transaction_uuid)) {
    error_log('eSewa transaction UUID mismatch. Pending=' . ($pending['transaction_uuid'] ?? 'null') . ' Received=' . $transaction_uuid);
    header('Location: payment.php?status=failed&err=bad_esewa_uuid');
    exit;
}

// Delivery mode & notes from the server-side pending checkout snapshot.
$allowed_modes = ['delivery', 'takeaway'];
$delivery_mode = in_array($pending['delivery_mode'] ?? '', $allowed_modes, true)
                   ? $pending['delivery_mode'] : 'delivery';
$special_notes = (!empty($pending['special_notes']))
                   ? substr(strip_tags($pending['special_notes']), 0, 500) : null;

// Use the same snapshot that was signed immediately before eSewa redirect.
$cart_items = $pending['cart_items'] ?? [];
if (empty($cart_items)) {
    // Fallback if the session cart snapshot is missing but DB cart still exists.
    $cart_items = hc_fetch_cart_items($conn, $user_id);
}
if (empty($cart_items)) {
    error_log('eSewa callback has no cart items for user ' . $user_id);
    header('Location: payment.php?status=failed&err=empty_esewa_cart');
    exit;
}

$totals = hc_calculate_totals($cart_items, $delivery_mode);
$expected_total = hc_normalize_amount($pending['total'] ?? $totals['total']);

if (abs($total_amount - $expected_total) > 0.009 || abs($total_amount - $totals['total']) > 0.009) {
    error_log('eSewa amount mismatch. Expected=' . $expected_total . ' Calculated=' . $totals['total'] . ' Received=' . $total_amount);
    header('Location: payment.php?status=failed&err=bad_esewa_amount');
    exit;
}

$delivery_location_id   = null;
$delivery_location_name = null;
$delivery_block_name    = null;
$pending_loc_id = (int)($pending['delivery_location_id'] ?? 0);
if ($delivery_mode === 'delivery') {
    try {
        [$delivery_location_id, $delivery_location_name, $delivery_block_name] = hc_validate_delivery_location(
            $conn,
            $delivery_mode,
            $pending_loc_id > 0 ? $pending_loc_id : null
        );
    } catch (Throwable $e) {
        // Payment is already confirmed. Save the order and let staff contact customer if location is missing.
        error_log('eSewa paid order missing/invalid delivery location: ' . $e->getMessage());
        $delivery_location_id = $delivery_location_name = $delivery_block_name = null;
    }
}

$conn->begin_transaction();
try {
    // 1. Create order with full subtotal/delivery-fee breakdown.
    $checkout_token = $pending['checkout_token'] ?? null;
    $order_id = hc_insert_order(
        $conn,
        $user_id,
        $totals,
        'esewa',
        $delivery_mode,
        $special_notes,
        $delivery_location_id,
        $delivery_location_name,
        $delivery_block_name,
        $checkout_token
    );

    // 2. Order items.
    hc_insert_order_items($conn, $order_id, $cart_items);

    // 3. Clear cart.
    hc_clear_cart($conn, $user_id);

    // 4. KOT + invoice.
    hc_create_kot_and_invoice($conn, $order_id, $user_id, $delivery_mode, $special_notes);

    // Mark invoice paid immediately because eSewa has confirmed payment.
    $stmt = $conn->prepare("UPDATE kot_invoices SET is_paid = 1, paid_at = NOW() WHERE order_id = ?");
    if (!$stmt) {
        throw new RuntimeException('Invoice update prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $stmt->close();

    // 5. Payment record — eSewa is immediately successful.
    $gateway_ref = $decoded['transaction_code'] ?? $transaction_uuid;
    $stmt = $conn->prepare(
        "INSERT INTO payments (order_id, transaction_uuid, payment_method, payment_status, amount, gateway_ref, paid_at)
         VALUES (?, ?, 'esewa', 'successful', ?, ?, NOW())"
    );
    if (!$stmt) {
        throw new RuntimeException('Payment prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('isds', $order_id, $transaction_uuid, $totals['total'], $gateway_ref);
    $stmt->execute();
    $stmt->close();

    // 6. Notification.
    $title   = 'Order Placed — eSewa Payment Confirmed';
    $message = "Your payment via eSewa was confirmed. Total: Rs. " . number_format($totals['total'], 2);
    $stmt    = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'order')");
    if (!$stmt) {
        throw new RuntimeException('Notification prepare failed: ' . $conn->error);
    }
    $stmt->bind_param('iss', $user_id, $title, $message);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    unset($_SESSION['pending_payment']);
    session_write_close();
    header("Location: my_orders.php?payment=success&order_id=$order_id");
    exit;

} catch (Throwable $e) {
    $conn->rollback();

    // If insert failed because the payment was already recorded by a repeated callback,
    // redirect to that order rather than showing a false failure.
    $existing_order_id = hc_order_exists_for_transaction($conn, 'esewa', $transaction_uuid);
    if ($existing_order_id) {
        unset($_SESSION['pending_payment']);
        session_write_close();
        header("Location: my_orders.php?payment=success&order_id=$existing_order_id");
        exit;
    }

    error_log('eSewa order save failed: ' . $e->getMessage());
    header('Location: payment.php?status=failed&err=esewa_save');
    exit;
}
