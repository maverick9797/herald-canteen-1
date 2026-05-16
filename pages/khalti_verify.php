<?php
// khalti_verify.php — Khalti return URL handler
require_once '../includes/auth.php';
start_session();
session_security_check();
require_once '../config/db.php';
require_once '../includes/payment_helpers.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['pending_payment'])) {
    header('Location: dashboard.php');
    exit;
}
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'customer') {
    if ($_SESSION["role"] === "chef") { header("Location: chef-control.php"); } else { header("Location: staff-control.php"); }
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$pending = $_SESSION['pending_payment'];

$pidx   = $_GET['pidx']   ?? '';
$status = $_GET['status'] ?? '';

if ($status !== 'Completed' || empty($pidx)) {
    header('Location: payment.php?status=failed&err=khalti_failed');
    exit;
}

// Verify with Khalti lookup API
$khalti_secret_key = "test_secret_key_f59e8b7d18b4499ca40f68195a846e9b";
$khalti_lookup_url = "https://a.khalti.com/api/v2/epayment/lookup/";

$ch = curl_init($khalti_lookup_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['pidx' => $pidx]),
    CURLOPT_HTTPHEADER     => [
        "Authorization: Key $khalti_secret_key",
        "Content-Type: application/json",
    ],
]);
$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$result = json_decode($response, true);

if ($http_code !== 200 || ($result['status'] ?? '') !== 'Completed') {
    error_log("Khalti lookup failed: " . $response);
    header('Location: payment.php?status=failed&err=khalti_failed');
    exit;
}

// Validate amount
$expected_paisa = (int)(($pending['total'] ?? 0) * 100);
$received_paisa = (int)($result['total_amount'] ?? 0);

if ($received_paisa !== $expected_paisa) {
    error_log("Khalti amount mismatch: expected $expected_paisa got $received_paisa");
    header('Location: payment.php?status=failed&err=khalti_failed');
    exit;
}

// Delivery mode & notes from session
$allowed_modes = ['delivery', 'takeaway'];
$delivery_mode = in_array($pending['delivery_mode'] ?? '', $allowed_modes, true)
                   ? $pending['delivery_mode'] : 'delivery';
$special_notes = (!empty($pending['special_notes']))
                   ? substr(strip_tags($pending['special_notes']), 0, 500) : null;

$cart_items = $pending['cart_items'] ?? [];
if (empty($cart_items)) {
    $cart_items = hc_fetch_cart_items($conn, $user_id);
}
if (empty($cart_items)) {
    header('Location: payment.php?status=failed&err=empty_khalti_cart');
    exit;
}

$totals = hc_calculate_totals($cart_items, $delivery_mode);
$total = (float)$totals['total'];

// Delivery location
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
        error_log('Khalti paid order missing/invalid delivery location: ' . $e->getMessage());
        $delivery_location_id = $delivery_location_name = $delivery_block_name = null;
    }
}

$checkout_token = $pending['checkout_token'] ?? null;
$transaction_uuid = $pending['transaction_uuid'] ?? null;
if (!empty($transaction_uuid)) {
    $existing_order_id = hc_order_exists_for_transaction($conn, 'khalti', $transaction_uuid);
    if ($existing_order_id) {
        unset($_SESSION['pending_payment']);
        session_write_close();
        header("Location: my_orders.php?payment=success&order_id=$existing_order_id");
        exit;
    }
}

$conn->begin_transaction();
try {
    // 1. Create order with full subtotal/delivery-fee breakdown.
    $order_id = hc_insert_order(
        $conn,
        $user_id,
        $totals,
        'khalti',
        $delivery_mode,
        $special_notes,
        $delivery_location_id,
        $delivery_location_name,
        $delivery_block_name,
        $checkout_token
    );

    // 2. Order items
    hc_insert_order_items($conn, $order_id, $cart_items);

    // 3. Clear cart
    hc_clear_cart($conn, $user_id);

    // 4. KOT + invoice
    hc_create_kot_and_invoice($conn, $order_id, $user_id, $delivery_mode, $special_notes);

    // Mark invoice paid immediately (Khalti confirmed)
    $stmt = $conn->prepare("UPDATE kot_invoices SET is_paid = 1, paid_at = NOW() WHERE order_id = ?");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $stmt->close();

    // 5. Payment record
    $gateway_ref = $result['transaction_id'] ?? $pidx;
    $stmt = $conn->prepare(
        "INSERT INTO payments (order_id, transaction_uuid, payment_method, payment_status, amount, gateway_ref, paid_at)
         VALUES (?, ?, 'khalti', 'successful', ?, ?, NOW())"
    );
    $stmt->bind_param('isds', $order_id, $transaction_uuid, $total, $gateway_ref);
    $stmt->execute();
    $stmt->close();

    // 6. Notification
    $title   = "Order Placed — Khalti Payment Confirmed";
    $message = "Your payment via Khalti was confirmed. Total: Rs. " . number_format($total, 2);
    $stmt    = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'order')");
    $stmt->bind_param('iss', $user_id, $title, $message);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    unset($_SESSION['pending_payment']);
    session_write_close();
    header("Location: my_orders.php?payment=success&order_id=$order_id");
    exit;

} catch (Exception $e) {
    $conn->rollback();
    error_log("Khalti order save failed: " . $e->getMessage());
    header('Location: payment.php?status=failed&err=khalti_failed');
    exit;
}
