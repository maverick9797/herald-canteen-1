<?php
require_once "../includes/auth.php";
start_session();
session_security_check();
require_once "../config/db.php";
require_once "../includes/functions.php";

require_role('staff');

$field_errors = [];

// Pick up flash toast from POST-redirect-GET pattern
$staff_toast = null;
if (isset($_SESSION['_toast'])) {
    $staff_toast = $_SESSION['_toast'];
    unset($_SESSION['_toast']);
}

// Helper function for consistent sanitization
function sanitize_output($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}


function staff_status_label(string $status): string
{
    return match ($status) {
        'ready' => 'Ready',
        'out_for_delivery' => 'On Delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function staff_payment_label(string $status): string
{
    return match ($status) {
        'successful' => 'Paid',
        'pending' => 'COD Pending',
        'missing' => 'Payment Pending',
        'failed' => 'Failed',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}

function staff_delivery_label(?string $mode): string
{
    return $mode === 'takeaway' ? 'Takeaway / Pickup' : 'Delivery';
}

function staff_age_label(?string $datetime): string
{
    if (!$datetime) {
        return 'Time unavailable';
    }
    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return 'Time unavailable';
    }
    $diff = max(0, time() - $timestamp);
    if ($diff < 60) return 'Just now';
    $minutes = floor($diff / 60);
    if ($minutes < 60) return $minutes . ' min ago';
    $hours = floor($minutes / 60);
    if ($hours < 24) return $hours . ' hr ago';
    $days = floor($hours / 24);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

function ensure_history_hidden_table(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS order_history_hidden (
        hidden_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id INT(10) UNSIGNED NOT NULL,
        hidden_for_role ENUM('chef','staff') NOT NULL,
        hidden_by INT(10) UNSIGNED NULL DEFAULT NULL,
        hidden_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (hidden_id),
        UNIQUE KEY uq_hidden_role_order (hidden_for_role, order_id),
        KEY idx_hidden_order (order_id),
        KEY idx_hidden_role (hidden_for_role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

ensure_history_hidden_table($conn);

/* ---------------------------
   HANDLE DELIVERY STATUS UPDATE
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_delivery_status'])) {
    $is_ajax_staff = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                     strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // Validate order_id with proper filtering
    $order_id_raw = $_POST['order_id'] ?? 0;
    $order_id = filter_var($order_id_raw, FILTER_VALIDATE_INT);
    
    if ($order_id === false || $order_id <= 0) {
        $field_errors['order_id'] = 'Valid order ID is required.';
    } elseif ($order_id > 9999999) {
        $field_errors['order_id'] = 'Invalid order ID value.';
    }
    
    $new_status = clean_input($_POST['new_status'] ?? '');

    // Trim and validate new_status with whitespace check
    $trimmed_status = trim($new_status);
    if ($new_status === '') {
        $field_errors['new_status'] = 'Delivery status is required.';
    } elseif ($trimmed_status === '') {
        $field_errors['new_status'] = 'Delivery status cannot consist of whitespace characters only.';
    } elseif (strlen($trimmed_status) > 50) {
        $field_errors['new_status'] = 'Delivery status value is too long.';
    } elseif (!in_array($trimmed_status, ['out_for_delivery', 'delivered'], true)) {
        $field_errors['new_status'] = 'Invalid delivery status value.';
    } else {
        $new_status = $trimmed_status;
    }

    if (empty($field_errors)) {
        $stmt = $conn->prepare("
            SELECT 
                o.status,
                o.payment_method,
                COALESCE(p.payment_status, 'missing') AS payment_status
            FROM orders o
            LEFT JOIN payments p ON p.order_id = o.order_id
            WHERE o.order_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $current_order = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($current_order) {
            $current_status = $current_order['status'];
            $payment_status = $current_order['payment_status'];

            $allowed_transition =
                ($current_status === 'ready' && $new_status === 'out_for_delivery') ||
                ($current_status === 'out_for_delivery' && $new_status === 'delivered');

            if (!$allowed_transition) {
                $field_errors['general'] = "Invalid delivery status transition for staff.";
                if (!empty($is_ajax_staff)) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Invalid delivery status transition.']); exit; }
            } elseif ($new_status === 'delivered' && $payment_status !== 'successful') {
                $field_errors['general'] = "Order cannot be marked as delivered until payment is successful.";
                if (!empty($is_ajax_staff)) { header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Payment must be confirmed before marking delivered.']); exit; }
            } else {
                $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
                $stmt->bind_param("si", $new_status, $order_id);

                if ($stmt->execute()) {
                    $stmt->close();

                    // Notify the customer about their delivery status change
                    $notif_data = match($new_status) {
                        'out_for_delivery' => ['🚚 Your Order is On the Way!', 'Your order has been picked up and is out for delivery. It will reach you shortly!', 'order'],
                        'delivered'        => ['🎉 Order Delivered!', 'Your order has been delivered. Enjoy your meal! Thank you for ordering from Herald Canteen.', 'order'],
                        default            => null,
                    };
                    if ($notif_data) {
                        $u_stmt = $conn->prepare("SELECT user_id FROM orders WHERE order_id = ? LIMIT 1");
                        $u_stmt->bind_param("i", $order_id);
                        $u_stmt->execute();
                        $order_user = $u_stmt->get_result()->fetch_assoc();
                        $u_stmt->close();
                        if ($order_user) {
                            $n_stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
                            $n_stmt->bind_param("isss", $order_user['user_id'], $notif_data[0], $notif_data[1], $notif_data[2]);
                            $n_stmt->execute();
                            $n_stmt->close();
                        }
                    }

                    if (!empty($is_ajax_staff)) {
                        header('Content-Type: application/json');
                        echo json_encode(['ok' => true, 'new_status' => $new_status, 'order_id' => $order_id]);
                        exit;
                    }
                    $_SESSION['_toast'] = ['text' => 'Delivery status updated successfully.', 'type' => 'success'];
                    session_write_close();
                    header('Location: staff-control.php');
                    exit;
                } else {
                    $field_errors['general'] = "Failed to update delivery status.";
                    $stmt->close();
                    if (!empty($is_ajax_staff)) {
                        header('Content-Type: application/json');
                        echo json_encode(['ok' => false, 'error' => 'Failed to update delivery status.']);
                        exit;
                    }
                }
            }
        } else {
            $field_errors['order_id'] = "Order not found.";
        }
    }
}

/* ---------------------------
   HANDLE COD PAYMENT CONFIRMATION
---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_cod_payment'])) {
    $is_ajax_staff = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                     strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    // Validate order_id with proper filtering
    $order_id_raw = $_POST['order_id'] ?? 0;
    $order_id = filter_var($order_id_raw, FILTER_VALIDATE_INT);

    if ($order_id === false || $order_id <= 0) {
        $field_errors['order_id'] = "Valid order ID is required.";
    } elseif ($order_id > 9999999) {
        $field_errors['order_id'] = "Invalid order ID value.";
    }

    if (empty($field_errors)) {
        $stmt = $conn->prepare("
            SELECT 
                o.status,
                o.payment_method,
                o.total_amount,
                p.payment_id,
                p.payment_status
            FROM orders o
            LEFT JOIN payments p ON p.order_id = o.order_id
            WHERE o.order_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $order_id);
        $stmt->execute();
        $payment_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$payment_row) {
            $field_errors['order_id'] = "Order not found.";
        } elseif ($payment_row['payment_method'] !== 'cod') {
            $field_errors['general'] = "Manual payment confirmation is only allowed for COD orders.";
        } elseif (!in_array($payment_row['status'], ['out_for_delivery', 'ready'], true)) {
            $field_errors['general'] = "COD payment can only be confirmed when the order is Ready or Out for Delivery.";
        } elseif (!empty($payment_row['payment_id']) && $payment_row['payment_status'] === 'successful') {
            $field_errors['general'] = "COD payment is already confirmed.";
        } else {
            $conn->begin_transaction();
            try {
                $amount = (float)$payment_row['total_amount'];

                if (empty($payment_row['payment_id'])) {
                    // Insert payment row
                    $stmt = $conn->prepare(
                        "INSERT INTO payments (order_id, payment_method, payment_status, amount, paid_at)
                         VALUES (?, 'cod', 'successful', ?, NOW())"
                    );
                    $stmt->bind_param("id", $order_id, $amount);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    // Update existing payment row
                    $stmt = $conn->prepare(
                        "UPDATE payments SET payment_status = 'successful', paid_at = NOW() WHERE order_id = ?"
                    );
                    $stmt->bind_param("i", $order_id);
                    $stmt->execute();
                    $stmt->close();
                }

                // Mark invoice as paid
                $stmt = $conn->prepare(
                    "UPDATE kot_invoices SET is_paid = 1, paid_at = NOW() WHERE order_id = ?"
                );
                $stmt->bind_param("i", $order_id);
                $stmt->execute();
                $stmt->close();

                // Notify customer that payment has been confirmed
                $u_stmt = $conn->prepare("SELECT user_id FROM orders WHERE order_id = ? LIMIT 1");
                $u_stmt->bind_param("i", $order_id);
                $u_stmt->execute();
                $cod_user = $u_stmt->get_result()->fetch_assoc();
                $u_stmt->close();
                if ($cod_user) {
                    $n_title = '💳 Payment Confirmed';
                    $n_msg   = 'Your cash payment has been received and confirmed. Your order is now fully paid.';
                    $n_stmt  = $conn->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'payment')");
                    $n_stmt->bind_param("iss", $cod_user['user_id'], $n_title, $n_msg);
                    $n_stmt->execute();
                    $n_stmt->close();
                }

                $conn->commit();
                if (!empty($is_ajax_staff)) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true, 'order_id' => $order_id]);
                    exit;
                }
                $_SESSION['_toast'] = ['text' => 'COD payment confirmed successfully. Invoice is now available to customer.', 'type' => 'success'];
                session_write_close();
                header('Location: staff-control.php');
                exit;

            } catch (Exception $e) {
                $conn->rollback();
                $field_errors['general'] = "Failed to confirm COD payment. Please try again.";
                error_log("COD confirm failed: " . $e->getMessage());
                if (!empty($is_ajax_staff)) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Failed to confirm COD payment. Please try again.']);
                    exit;
                }
            }
        } // end else (valid payment row checks)
    } // end if (empty($field_errors))
} // end if POST confirm_cod_payment

/* ---------------------------
   FETCH ORDER SUMMARY COUNTS
---------------------------- */
$summary = [
    'ready' => 0,
    'out_for_delivery' => 0,
    'delivered' => 0,
    'pending_payments' => 0
];

// Using prepared statement for consistency
$count_stmt = $conn->prepare("
    SELECT status, COUNT(*) AS total
    FROM orders
    WHERE status IN ('ready', 'out_for_delivery')
    GROUP BY status
");
$count_stmt->execute();
$count_result = $count_stmt->get_result();

if ($count_result) {
    while ($row = $count_result->fetch_assoc()) {
        $summary[$row['status']] = (int)$row['total'];
    }
}
$count_stmt->close();

$pending_stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM payments p
    INNER JOIN orders o ON o.order_id = p.order_id
    WHERE p.payment_status = 'pending'
      AND o.payment_method = 'cod'
      AND o.status IN ('ready', 'out_for_delivery')
");
$pending_stmt->execute();
$pending_payment_result = $pending_stmt->get_result();

if ($pending_payment_result) {
    $summary['pending_payments'] = (int)($pending_payment_result->fetch_assoc()['total'] ?? 0);
}
$pending_stmt->close();

$delivered_today_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM orders WHERE status = 'delivered' AND DATE(created_at) = CURDATE()");
$delivered_today_stmt->execute();
$delivered_today_result = $delivered_today_stmt->get_result();
$summary['delivered'] = (int)($delivered_today_result->fetch_assoc()['total'] ?? 0);
$delivered_today_stmt->close();

/* ---------------------------
   FETCH STAFF ORDERS
---------------------------- */
$orders_stmt = $conn->prepare("
    SELECT
        o.order_id,
        u.full_name,
        o.total_amount,
        o.status,
        o.payment_method,
        o.created_at,
        COALESCE(MAX(k.delivery_mode), 'delivery') AS delivery_mode,
        MAX(k.special_notes) AS special_notes,
        MAX(o.delivery_location_name) AS delivery_location_name,
        MAX(o.delivery_block_name)    AS delivery_block_name,
        COALESCE(MAX(p.payment_status), 'missing') AS payment_status,
        GROUP_CONCAT(
            CONCAT(mi.name, ' x', oi.quantity)
            ORDER BY mi.name SEPARATOR ', '
        ) AS items_summary
    FROM orders o
    INNER JOIN users u
        ON o.user_id = u.user_id
    LEFT JOIN payments p
        ON p.order_id = o.order_id
    LEFT JOIN kitchen_order_tickets k
        ON k.order_id = o.order_id
    LEFT JOIN order_items oi
        ON oi.order_id = o.order_id
    LEFT JOIN menu_items mi
        ON mi.item_id = oi.item_id
    WHERE o.status IN ('ready', 'out_for_delivery')
    GROUP BY
        o.order_id,
        u.full_name,
        o.total_amount,
        o.status,
        o.payment_method,
        o.created_at
    ORDER BY o.order_id DESC
");
$orders_stmt->execute();
$orders = $orders_stmt->get_result();
$orders_stmt->close();

$staff_order_rows = [];
if ($orders && $orders->num_rows > 0) {
    while ($staff_order_row = $orders->fetch_assoc()) {
        $staff_order_rows[] = $staff_order_row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Control Panel</title>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">

   
</head>
<body class="staff-page ops-page">

<div class="layout">

    <div class="sidebar">
        <div class="navbar-title">
            Herald Canteen
            <span>Staff Portal</span>
        </div>
        <nav>
            <a href="staff-control.php" class="active">🧾 Staff Home</a>
            <a href="#orders-section">📦 Active Orders</a>
            <a href="staff-order-history.php">🕘 Paid History</a>
            <a href="manage-delivery-locations.php">📍 Delivery Locations</a>
            <a href="user-logs.php">📋 User Logs</a>
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>

    <div class="main">

        <div class="topbar">
            <div class="topbar-welcome">
                Welcome, <?php echo sanitize_output($_SESSION['full_name']); ?>
            </div>
            <!-- Theme Toggle -->
            <label class="theme-toggle" title="Toggle light/dark mode">
              <input type="checkbox" class="theme-checkbox">
              <span class="theme-slider"></span>
            </label>
        </div>

        <div class="content">

            <section class="ops-hero ops-hero-staff">
                <div>
                    <p class="ops-eyebrow">Dispatch Dashboard</p>
                    <h1>Staff Dispatch Centre</h1>
                    <p>Track active ready and on-delivery orders from one operational view. Paid delivered history is kept on a separate page.</p>
                </div>
                <div class="ops-hero-side">
                    <span class="ops-role-pill">🧾 <?php echo sanitize_output($_SESSION['full_name']); ?></span>
                    <span class="ops-live-pill"><span></span> Live Sync Active</span>
                    <small id="staffLastUpdated">Last updated: just now</small>
                </div>
            </section>

            <?php if ($staff_toast): ?>
                <div class="alert-success">✓ <?php echo htmlspecialchars($staff_toast['text'], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (!empty($field_errors['general'])): ?>
                <div class="alert-error">⚠️ <?php echo sanitize_output($field_errors['general']); ?></div>
            <?php endif; ?>

            <?php if (!empty($field_errors['order_id'])): ?>
                <div class="alert-error">⚠️ <?php echo sanitize_output($field_errors['order_id']); ?></div>
            <?php endif; ?>

            <?php if (!empty($field_errors['new_status'])): ?>
                <div class="alert-error">⚠️ <?php echo sanitize_output($field_errors['new_status']); ?></div>
            <?php endif; ?>

            <div id="staffLiveRegion" data-live-region="staff-control">
            <section class="ops-stat-grid" aria-label="Staff delivery summary">
                <article class="ops-stat-card ops-stat-green">
                    <div class="ops-stat-icon">📦</div>
                    <div><span>Ready for Delivery</span><strong data-staff-stat="ready"><?php echo (int)$summary['ready']; ?></strong></div>
                </article>
                <article class="ops-stat-card ops-stat-purple">
                    <div class="ops-stat-icon">🛵</div>
                    <div><span>On Delivery</span><strong data-staff-stat="out_for_delivery"><?php echo (int)$summary['out_for_delivery']; ?></strong></div>
                </article>
                <article class="ops-stat-card ops-stat-total">
                    <div class="ops-stat-icon">✅</div>
                    <div><span>Delivered Today</span><strong data-staff-stat="delivered"><?php echo (int)$summary['delivered']; ?></strong></div>
                </article>
                <article class="ops-stat-card ops-stat-pending">
                    <div class="ops-stat-icon">💵</div>
                    <div><span>COD Pending</span><strong data-staff-stat="pending_payments"><?php echo (int)$summary['pending_payments']; ?></strong></div>
                </article>
            </section>

            <section class="ops-panel" id="orders-section">
                <div class="ops-panel-heading">
                    <div>
                        <p class="ops-eyebrow">Delivery Queue</p>
                        <h2>Active Delivery & Payment Orders</h2>
                        <p>Only active orders are shown here. Paid delivered orders are moved to the history page.</p>
                    </div>
                    <a href="staff-order-history.php" class="ops-link-btn">Paid History</a>
                </div>

                <div class="ops-filter-tabs" data-filter-group="staff">
                    <button type="button" class="ops-filter-btn active" data-filter="all">All</button>
                    <button type="button" class="ops-filter-btn" data-filter="ready">Ready</button>
                    <button type="button" class="ops-filter-btn" data-filter="out_for_delivery">On Delivery</button>
                    <button type="button" class="ops-filter-btn" data-filter="cod_pending">COD Pending</button>
                </div>

                <?php if (!empty($staff_order_rows)): ?>
                    <div class="ops-card-grid staff-card-grid" id="staffOrderGrid">
                        <?php foreach ($staff_order_rows as $order): ?>
                            <?php
                                $status = $order['status'];
                                $payment_status = $order['payment_status'];
                                $payment_method = $order['payment_method'];
                            ?>
                            <article class="ops-order-card staff-order-card"
                                     id="staff-order-<?php echo (int)$order['order_id']; ?>"
                                     data-order-id="<?php echo (int)$order['order_id']; ?>"
                                     data-status="<?php echo sanitize_output($status); ?>"
                                     data-filter-status="<?php echo sanitize_output($status); ?>"
                                     data-payment-status="<?php echo sanitize_output($payment_status); ?>"
                                     data-payment-method="<?php echo sanitize_output($payment_method); ?>">
                                <div class="ops-card-top">
                                    <div>
                                        <h3>Order #<?php echo (int)$order['order_id']; ?></h3>
                                        <p><?php echo sanitize_output($order['full_name']); ?></p>
                                    </div>
                                    <span class="ops-status-badge status-<?php echo sanitize_output($status); ?>"><?php echo sanitize_output(staff_status_label($status)); ?></span>
                                </div>

                                <div class="ops-meta-grid">
                                    <div><span>Mode</span><strong><?php echo sanitize_output(staff_delivery_label($order['delivery_mode'] ?? 'delivery')); ?></strong></div>
                                    <div><span>Payment</span><strong><?php echo sanitize_output(strtoupper($payment_method)); ?></strong></div>
                                    <div><span>Order Time</span><strong><?php echo sanitize_output(date('d M, g:i A', strtotime($order['created_at']))); ?></strong></div>
                                    <div><span>Age</span><strong><?php echo sanitize_output(staff_age_label($order['created_at'])); ?></strong></div>
                                </div>

                                <div class="ops-payment-line">
                                    <span class="ops-payment-badge payment-<?php echo sanitize_output($payment_status); ?>"><?php echo sanitize_output(staff_payment_label($payment_status)); ?></span>
                                    <strong>Rs. <?php echo number_format((float)$order['total_amount'], 2); ?></strong>
                                </div>

                                <?php if (!empty($order['special_notes'])): ?>
                                    <div class="ops-remark"><span>Customer Remark</span><?php echo sanitize_output($order['special_notes']); ?></div>
                                <?php endif; ?>

                                <?php if (($order['delivery_mode'] ?? 'delivery') === 'delivery'): ?>
                                    <?php if (!empty($order['delivery_location_name'])): ?>
                                        <div class="ops-remark delivery-location-card" style="background:rgba(77,184,72,0.1);border:1px solid rgba(77,184,72,0.3);border-radius:8px;padding:10px 14px;margin-top:8px;">
                                            <span style="font-size:11px;color:rgba(255,255,255,0.5);display:block;margin-bottom:4px;">📍 DELIVER TO</span>
                                            <strong style="font-size:15px;color:#fff;"><?php echo sanitize_output($order['delivery_location_name']); ?></strong>
                                            <em style="font-size:12px;color:rgba(255,255,255,0.5);display:block;"><?php echo sanitize_output($order['delivery_block_name'] ?? ''); ?></em>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size:12px;color:rgba(255,255,255,0.4);margin-top:6px;">📍 Delivery location: <em>Not specified</em></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div style="font-size:12px;color:rgba(255,255,255,0.4);margin-top:6px;">🥡 Collection / Takeaway — No delivery location required</div>
                                <?php endif; ?>

                                <div class="ops-items-block">
                                    <div class="ops-items-title">Items</div>
                                    <p class="ops-items-summary"><?php echo sanitize_output($order['items_summary'] ?? 'No items found'); ?></p>
                                </div>

                                <div class="ops-timeline" aria-label="Delivery timeline">
                                    <span class="<?php echo in_array($status, ['ready','out_for_delivery','delivered'], true) ? 'active' : ''; ?>">Ready</span>
                                    <span class="<?php echo in_array($status, ['out_for_delivery','delivered'], true) ? 'active' : ''; ?>">On Delivery</span>
                                    <span class="<?php echo $status === 'delivered' ? 'active' : ''; ?>">Delivered</span>
                                </div>

                                <div class="ops-action-row" id="action-<?php echo (int)$order['order_id']; ?>">
                                    <?php if ($status === 'ready'): ?>
                                        <button type="button" class="ops-btn ops-btn-primary" onclick="staffAction('delivery', <?php echo (int)$order['order_id']; ?>, 'out_for_delivery', this)">🛵 Mark On Delivery</button>
                                    <?php elseif ($status === 'out_for_delivery'): ?>
                                        <?php if ($payment_status === 'successful'): ?>
                                            <button type="button" class="ops-btn ops-btn-primary" onclick="staffAction('delivery', <?php echo (int)$order['order_id']; ?>, 'delivered', this)">✅ Mark Delivered</button>
                                        <?php else: ?>
                                            <button type="button" class="ops-btn ops-btn-disabled" disabled>Mark Delivered</button>
                                            <span class="ops-complete-note">Confirm payment first</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="ops-complete-note">Delivery complete</span>
                                    <?php endif; ?>

                                    <?php if ($payment_method === 'cod'): ?>
                                        <?php if ($status === 'out_for_delivery' && in_array($payment_status, ['pending', 'missing'], true)): ?>
                                            <button type="button" class="ops-btn ops-btn-warning" onclick="staffAction('cod', <?php echo (int)$order['order_id']; ?>, null, this)">💵 Confirm COD</button>
                                        <?php elseif ($payment_status === 'successful'): ?>
                                            <span class="ops-complete-note">COD payment confirmed</span>
                                        <?php else: ?>
                                            <span class="ops-complete-note">COD payment pending</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($payment_status === 'successful'): ?>
                                            <span class="ops-complete-note">Online payment completed</span>
                                        <?php else: ?>
                                            <span class="ops-complete-note">Waiting for online payment</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="ops-empty-state" id="staffFilterEmpty" hidden>
                        <div>🔎</div>
                        <h3>No orders match this filter</h3>
                        <p>Try another status tab or wait for the kitchen to mark orders ready.</p>
                    </div>
                <?php else: ?>
                    <div class="ops-empty-state">
                        <div>📦</div>
                        <h3>No active delivery orders</h3>
                        <p>Orders marked ready by the chef will appear here automatically. Delivered paid orders are available in Paid History.</p>
                    </div>
                <?php endif; ?>
            </section>
            </div>

        </div>
    </div>
</div>

<!-- Staff Toast -->
<div class="toast" id="staffToast" style="min-width:280px;display:none;">
    <div class="toast-icon">
        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" id="staffToastIcon">
            <circle cx="9" cy="9" r="9" fill="#4db848"/>
            <path d="M5 9.5L7.5 12L13 6.5" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </div>
    <div class="toast-body">
        <span class="toast-title" id="staffToastTitle"></span>
        <span class="toast-sub" id="staffToastSub"></span>
    </div>
    <button class="toast-close" onclick="this.closest('.toast').classList.add('toast-hide')">&#x2715;</button>
</div>

<script>
function showStaffToast(text, type) {
    var toast = document.getElementById('staffToast');
    var title = document.getElementById('staffToastTitle');
    var sub   = document.getElementById('staffToastSub');
    if (!toast) return;
    title.textContent = text;
    sub.textContent   = type === 'success' ? 'Updated successfully \u2713' : 'Action completed.';
    toast.classList.remove('toast-hide', 'toast-danger');
    if (type === 'danger') toast.classList.add('toast-danger');
    toast.style.display = '';
    clearTimeout(toast._t);
    toast._t = setTimeout(function() { toast.classList.add('toast-hide'); }, 3500);
}

function staffStatusLabel(status) {
    var labels = {
        ready: 'Ready',
        out_for_delivery: 'On Delivery',
        delivered: 'Delivered',
        cancelled: 'Cancelled'
    };
    return labels[status] || status.replace(/_/g, ' ');
}

function staffPaymentLabel(status) {
    var labels = {
        successful: 'Paid',
        pending: 'COD Pending',
        missing: 'Payment Pending',
        failed: 'Failed'
    };
    return labels[status] || status.replace(/_/g, ' ');
}

function updateStaffLastUpdated() {
    var el = document.getElementById('staffLastUpdated');
    if (el) el.textContent = 'Last updated: just now';
}

function updateSummaryCount(status, delta) {
    var el = document.querySelector('[data-staff-stat="' + status + '"]');
    if (!el) return;
    var cur = parseInt(el.textContent, 10) || 0;
    el.textContent = Math.max(0, cur + delta);
}

function applyStaffFilter() {
    var active = document.querySelector('[data-filter-group="staff"] .ops-filter-btn.active');
    var filter = active ? active.getAttribute('data-filter') : 'all';
    var cards = document.querySelectorAll('.staff-order-card');
    var visible = 0;
    cards.forEach(function(card) {
        var status = card.getAttribute('data-filter-status') || card.getAttribute('data-status') || '';
        var payment = card.getAttribute('data-payment-status') || '';
        var method = card.getAttribute('data-payment-method') || '';
        var show = filter === 'all' || status === filter || (filter === 'cod_pending' && method === 'cod' && payment !== 'successful');
        card.hidden = !show;
        if (show) visible++;
    });
    var empty = document.getElementById('staffFilterEmpty');
    if (empty) empty.hidden = visible !== 0;
}

document.addEventListener('click', function(event) {
    var btn = event.target.closest('[data-filter-group="staff"] .ops-filter-btn');
    if (!btn) return;
    document.querySelectorAll('[data-filter-group="staff"] .ops-filter-btn').forEach(function(item) { item.classList.remove('active'); });
    btn.classList.add('active');
    applyStaffFilter();
});

function updateTimeline(card, status) {
    var steps = card ? card.querySelectorAll('.ops-timeline span') : [];
    steps.forEach(function(step, index) {
        step.classList.remove('active');
        if ((status === 'ready' && index === 0) ||
            (status === 'out_for_delivery' && index <= 1) ||
            (status === 'delivered' && index <= 2)) {
            step.classList.add('active');
        }
    });
}

function rebuildStaffActions(orderId, card, status, paymentStatus, paymentMethod) {
    var actionDiv = document.getElementById('action-' + orderId);
    if (!actionDiv) return;
    var html = '';
    if (status === 'ready') {
        html += '<button type="button" class="ops-btn ops-btn-primary" onclick="staffAction(\'delivery\',' + orderId + ',\'out_for_delivery\',this)">🛵 Mark On Delivery</button>';
    } else if (status === 'out_for_delivery') {
        if (paymentStatus === 'successful') {
            html += '<button type="button" class="ops-btn ops-btn-primary" onclick="staffAction(\'delivery\',' + orderId + ',\'delivered\',this)">✅ Mark Delivered</button>';
        } else {
            html += '<button type="button" class="ops-btn ops-btn-disabled" disabled>Mark Delivered</button><span class="ops-complete-note">Confirm payment first</span>';
        }
    } else {
        html += '<span class="ops-complete-note">Delivery complete</span>';
    }

    if (paymentMethod === 'cod') {
        if (status === 'out_for_delivery' && paymentStatus !== 'successful') {
            html += '<button type="button" class="ops-btn ops-btn-warning" onclick="staffAction(\'cod\',' + orderId + ',null,this)">💵 Confirm COD</button>';
        } else if (paymentStatus === 'successful') {
            html += '<span class="ops-complete-note">COD payment confirmed</span>';
        } else {
            html += '<span class="ops-complete-note">COD payment pending</span>';
        }
    } else {
        html += paymentStatus === 'successful'
            ? '<span class="ops-complete-note">Online payment completed</span>'
            : '<span class="ops-complete-note">Waiting for online payment</span>';
    }
    actionDiv.innerHTML = html;
}

function staffAction(type, orderId, newStatus, btn) {
    window.__hcStaffActionBusy = true;
    btn.disabled = true;
    var origText = btn.textContent;
    btn.textContent = 'Processing...';

    var body = type === 'delivery'
        ? 'update_delivery_status=1&order_id=' + orderId + '&new_status=' + encodeURIComponent(newStatus)
        : 'confirm_cod_payment=1&order_id=' + orderId;

    fetch('staff-control.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: body
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.ok) {
            window.__hcStaffActionBusy = false;
            btn.disabled = false;
            btn.textContent = origText;
            alert(data.error || 'Action failed. Please try again.');
            return;
        }

        var card = btn.closest('.staff-order-card');
        if (!card) {
            window.__hcStaffActionBusy = false;
            showStaffToast('Action completed successfully.', 'success');
            return;
        }

        var previousStatus = card.getAttribute('data-status') || '';
        var paymentStatus = card.getAttribute('data-payment-status') || '';
        var paymentMethod = card.getAttribute('data-payment-method') || '';

        if (type === 'delivery') {
            card.setAttribute('data-status', data.new_status);
            card.setAttribute('data-filter-status', data.new_status);
            var badge = card.querySelector('.ops-status-badge');
            if (badge) {
                badge.className = 'ops-status-badge status-' + data.new_status;
                badge.textContent = staffStatusLabel(data.new_status);
            }
            if (previousStatus && previousStatus !== data.new_status) {
                updateSummaryCount(previousStatus, -1);
                updateSummaryCount(data.new_status, 1);
            }
            updateTimeline(card, data.new_status);
            if (data.new_status === 'delivered') {
                rebuildStaffActions(orderId, card, data.new_status, paymentStatus, paymentMethod);
                showStaffToast('Order delivered and moved to Paid History.', 'success');
                setTimeout(function() {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(-8px)';
                    setTimeout(function() { card.remove(); applyStaffFilter(); }, 260);
                }, 700);
            } else {
                rebuildStaffActions(orderId, card, data.new_status, paymentStatus, paymentMethod);
                showStaffToast('Delivery status updated successfully.', 'success');
            }
        } else if (type === 'cod') {
            card.setAttribute('data-payment-status', 'successful');
            var payBadge = card.querySelector('.ops-payment-badge');
            if (payBadge) {
                payBadge.className = 'ops-payment-badge payment-successful';
                payBadge.textContent = staffPaymentLabel('successful');
            }
            updateSummaryCount('pending_payments', -1);
            rebuildStaffActions(orderId, card, card.getAttribute('data-status') || 'out_for_delivery', 'successful', paymentMethod);
            showStaffToast('COD payment confirmed successfully.', 'success');
        }

        updateStaffLastUpdated();
        applyStaffFilter();
        window.__hcStaffActionBusy = false;
    })
    .catch(function() {
        window.__hcStaffActionBusy = false;
        btn.disabled = false;
        btn.textContent = origText;
        alert('Network error. Please try again.');
    });
}

(function() {
    var regionId = 'staffLiveRegion';
    var intervalMs = 4500;
    var inFlight = false;

    function rememberActiveFilter() {
        var active = document.querySelector('[data-filter-group="staff"] .ops-filter-btn.active');
        return active ? active.getAttribute('data-filter') : 'all';
    }

    function restoreActiveFilter(filter) {
        var buttons = document.querySelectorAll('[data-filter-group="staff"] .ops-filter-btn');
        if (!buttons.length) return;
        buttons.forEach(function(btn) { btn.classList.remove('active'); });
        var target = document.querySelector('[data-filter-group="staff"] .ops-filter-btn[data-filter="' + filter + '"]') ||
                     document.querySelector('[data-filter-group="staff"] .ops-filter-btn[data-filter="all"]');
        if (target) target.classList.add('active');
        applyStaffFilter();
    }

    function refreshStaffControl() {
        if (inFlight || document.hidden || window.__hcStaffActionBusy) return;
        var currentRegion = document.getElementById(regionId);
        if (!currentRegion) return;
        var activeFilter = rememberActiveFilter();
        inFlight = true;
        fetch('staff-control.php', {
            method: 'GET',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.text(); })
        .then(function(html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var freshRegion = doc.getElementById(regionId);
            if (freshRegion) {
                currentRegion.innerHTML = freshRegion.innerHTML;
                restoreActiveFilter(activeFilter);
                updateStaffLastUpdated();
            }
        })
        .catch(function() {
            var el = document.getElementById('staffLastUpdated');
            if (el) el.textContent = 'Live sync paused — retrying...';
        })
        .finally(function() { inFlight = false; });
    }

    setInterval(refreshStaffControl, intervalMs);
    document.addEventListener('visibilitychange', function() { if (!document.hidden) refreshStaffControl(); });
})();
</script>

</body>
</html>