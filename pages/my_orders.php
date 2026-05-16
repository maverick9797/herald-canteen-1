<?php
// my_orders.php — Herald Canteen (Active Orders tracking only)

require_once '../includes/auth.php';
start_session();
session_security_check();
require_once '../config/db.php';
require_once '../includes/functions.php';
require_once '../includes/payment_helpers.php';

if (empty($_SESSION['user_id'])) {
    header('Location: portal-login.php');
    exit;
}

if (isset($_SESSION['role']) && $_SESSION['role'] !== 'customer') {
    if ($_SESSION['role'] === 'chef') {
        header('Location: chef-control.php');
    } else {
        header('Location: staff-control.php');
    }
    exit;
}

$user_id = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ============================================================
   CANCEL ORDER — POST handler
   Policy: customer can cancel any order that is still
   pending or preparing (kitchen not yet done).
   eSewa orders also eligible — refund is manual/offline per policy.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'cancel_order'
) {
    // CSRF guard
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Invalid request. Please try again.';
        header('Location: my_orders.php');
        exit;
    }

    $cancel_id = (int)($_POST['order_id'] ?? 0);
    if ($cancel_id <= 0) {
        $_SESSION['flash_error'] = 'Invalid order.';
        header('Location: my_orders.php');
        exit;
    }

    // Fetch the order — must belong to this user
    $c_stmt = $conn->prepare(
        "SELECT order_id, status, payment_method, total_amount
         FROM orders
         WHERE order_id = ? AND user_id = ?
         LIMIT 1"
    );
    $c_stmt->bind_param('ii', $cancel_id, $user_id);
    $c_stmt->execute();
    $c_order = $c_stmt->get_result()->fetch_assoc();
    $c_stmt->close();

    if (!$c_order) {
        $_SESSION['flash_error'] = 'Order not found.';
        header('Location: my_orders.php');
        exit;
    }

    // Only pending/preparing can be cancelled by customer
    $cancellable = ['pending', 'preparing'];
    if (!in_array($c_order['status'], $cancellable, true)) {
        $_SESSION['flash_error'] = 'This order can no longer be cancelled — it is already being prepared or dispatched.';
        header('Location: my_orders.php');
        exit;
    }

    // Cancel the order in a transaction
    $conn->begin_transaction();
    try {
        $upd = $conn->prepare("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE order_id = ? AND user_id = ?");
        $upd->bind_param('ii', $cancel_id, $user_id);
        $upd->execute();
        $upd->close();

        // Notification for the customer
        $pay_method = strtoupper((string)$c_order['payment_method']);
        $amt        = number_format((float)$c_order['total_amount'], 2);
        $refund_msg = ($c_order['payment_method'] === 'esewa')
            ? "Your eSewa payment of Rs. {$amt} will be refunded to your eSewa wallet within 3–5 business days."
            : "No charge was made for this COD order.";

        $notif_title = "Order #" . str_pad((string)$cancel_id, 4, '0', STR_PAD_LEFT) . " Cancelled";
        $notif_body  = "Your order has been cancelled. {$refund_msg}";

        $n_stmt = $conn->prepare(
            "INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, 'order')"
        );
        $n_stmt->bind_param('iss', $user_id, $notif_title, $notif_body);
        $n_stmt->execute();
        $n_stmt->close();

        // Audit log — using existing log_user_event; event_type = 'order_cancelled'
        $ip  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $desc = "Customer cancelled order #{$cancel_id} (payment: {$pay_method}, amount: Rs.{$amt})";
        log_user_event($conn, 'order_cancelled', $ip, $desc, $user_id);

        $conn->commit();

        // Pass payment method to flash so we can show refund notice
        $_SESSION['cancel_payment_method'] = $c_order['payment_method'];
        $_SESSION['cancel_order_id']       = $cancel_id;
        $_SESSION['cancel_amount']         = $c_order['total_amount'];
        $_SESSION['flash_success']         = "Your order has been cancelled successfully.";

    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Order cancel failed: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Could not cancel the order. Please try again.';
    }

    header('Location: my_orders.php');
    exit;
}
/* ============================================================ */

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$status_map = [
    'pending'          => 'in_process',
    'preparing'        => 'in_process',
    'ready'            => 'ready_for_delivery',
    'out_for_delivery' => 'on_delivery',
];

$status_steps = [
    'in_process'         => ['label' => 'In Process', 'icon' => '👨‍🍳', 'step' => 1],
    'ready_for_delivery' => ['label' => 'Ready',      'icon' => '✅',   'step' => 2],
    'on_delivery'        => ['label' => 'On the Way', 'icon' => '🛵',   'step' => 3],
    'delivered'          => ['label' => 'Delivered',  'icon' => '🏠',   'step' => 4],
];

$all_steps = ['in_process', 'ready_for_delivery', 'on_delivery', 'delivered'];

function active_statuses_for_filter(string $filter): array {
    return match ($filter) {
        'in_process'         => ['pending', 'preparing'],
        'ready_for_delivery' => ['ready'],
        'on_delivery'        => ['out_for_delivery'],
        default              => ['pending', 'preparing', 'ready', 'out_for_delivery'],
    };
}

function order_badge_class(string $s): string {
    return match ($s) {
        'in_process'         => 'badge-yellow',
        'ready_for_delivery' => 'badge-blue',
        'on_delivery'        => 'badge-green',
        'delivered'          => 'badge-gray',
        default              => 'badge-gray',
    };
}

function order_status_label(string $s): string {
    return match ($s) {
        'active'             => 'All Active',
        'in_process'         => 'In Process',
        'ready_for_delivery' => 'Ready',
        'on_delivery'        => 'On Delivery',
        'delivered'          => 'Delivered',
        'cancelled'          => 'Cancelled',
        default              => ucfirst(str_replace('_', ' ', $s)),
    };
}

function delivery_label_for_order(?string $mode): string {
    return match ($mode ?? 'delivery') {
        'takeaway' => 'Takeaway 🥡',
        'delivery' => 'Delivery 🚚',
        'dine_in'  => 'Takeaway 🥡',  // legacy value — dine-in no longer offered, show as Takeaway
        default    => 'Delivery 🚚',
    };
}

$allowed_filters = ['active', 'in_process', 'ready_for_delivery', 'on_delivery'];
$filter = in_array($_GET['filter'] ?? '', $allowed_filters, true) ? $_GET['filter'] : 'active';
$db_statuses = active_statuses_for_filter($filter);
$placeholders = implode(',', array_fill(0, count($db_statuses), '?'));
$types = 'i' . str_repeat('s', count($db_statuses));
$params = array_merge([$user_id], $db_statuses);

$stmt = $conn->prepare("SELECT * FROM orders WHERE user_id = ? AND status IN ($placeholders) ORDER BY updated_at DESC, order_id DESC");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$count_stmt = $conn->prepare("SELECT status, COUNT(*) AS cnt FROM orders WHERE user_id = ? GROUP BY status");
$count_stmt->bind_param('i', $user_id);
$count_stmt->execute();
$count_rows = $count_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$count_stmt->close();

$counts = [
    'active' => 0,
    'in_process' => 0,
    'ready_for_delivery' => 0,
    'on_delivery' => 0,
    'delivered' => 0,
    'cancelled' => 0,
];

foreach ($count_rows as $row) {
    $cnt = (int)$row['cnt'];
    if ($row['status'] === 'pending' || $row['status'] === 'preparing') {
        $counts['in_process'] += $cnt;
    } elseif ($row['status'] === 'ready') {
        $counts['ready_for_delivery'] += $cnt;
    } elseif ($row['status'] === 'out_for_delivery') {
        $counts['on_delivery'] += $cnt;
    } elseif ($row['status'] === 'delivered') {
        $counts['delivered'] += $cnt;
    } elseif ($row['status'] === 'cancelled') {
        $counts['cancelled'] += $cnt;
    }
}
$counts['active'] = $counts['in_process'] + $counts['ready_for_delivery'] + $counts['on_delivery'];

$history_stmt = $conn->prepare("\n    SELECT COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = 'delivered' THEN total_amount ELSE 0 END), 0) AS spent\n    FROM orders\n    WHERE user_id = ?\n      AND status IN ('delivered', 'cancelled')\n      AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)\n");
$history_stmt->bind_param('i', $user_id);
$history_stmt->execute();
$history_summary = $history_stmt->get_result()->fetch_assoc() ?: ['total' => 0, 'spent' => 0];
$history_stmt->close();

$order_ids = array_column($orders, 'order_id');
$details_map = [];
$invoice_map = [];

if (!empty($order_ids)) {
    $item_placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $stmt = $conn->prepare("\n        SELECT oi.order_id, oi.quantity, oi.price, COALESCE(mi.name, 'Unavailable item') AS item_name\n        FROM order_items oi\n        LEFT JOIN menu_items mi ON oi.item_id = mi.item_id\n        WHERE oi.order_id IN ($item_placeholders)\n        ORDER BY oi.order_item_id ASC\n    ");
    $stmt->bind_param(str_repeat('i', count($order_ids)), ...$order_ids);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $d) {
        $details_map[(int)$d['order_id']][] = $d;
    }
    $stmt->close();

    if (hc_table_exists($conn, 'kot_invoices')) {
        $stmt = $conn->prepare("\n            SELECT order_id, invoice_token, is_paid\n            FROM kot_invoices\n            WHERE order_id IN ($item_placeholders)\n        ");
        $stmt->bind_param(str_repeat('i', count($order_ids)), ...$order_ids);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $inv) {
            $invoice_map[(int)$inv['order_id']] = $inv;
        }
        $stmt->close();
    }
}

$flash_error = $_SESSION['flash_error'] ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
$cancel_payment_method = $_SESSION['cancel_payment_method'] ?? '';
$cancel_order_id       = $_SESSION['cancel_order_id'] ?? 0;
$cancel_amount         = $_SESSION['cancel_amount'] ?? 0;
unset($_SESSION['flash_error'], $_SESSION['flash_success'],
      $_SESSION['cancel_payment_method'], $_SESSION['cancel_order_id'], $_SESSION['cancel_amount']);

$initials = strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['name'] ?? 'U', 0, 1));
$tab_labels = [
    'active'             => '🔥 All Active',
    'in_process'         => '👨‍🍳 In Process',
    'ready_for_delivery' => '✅ Ready',
    'on_delivery'        => '🛵 On Delivery',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders — Herald Canteen</title>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .order-section-switch { display:flex; gap:12px; flex-wrap:wrap; margin:18px 0 22px; }
        .order-section-switch a { padding:14px 18px; border-radius:14px; border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.035); color:rgba(255,255,255,.72); font-weight:800; text-decoration:none; }
        .order-section-switch a.active { border-color:rgba(77,184,72,.6); background:rgba(77,184,72,.12); color:#4db848; }
        .order-section-switch small { display:block; margin-top:4px; color:rgba(255,255,255,.42); font-weight:600; }
        .order-meta-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-top:12px; }
        .order-meta-pill { padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.06); background:rgba(255,255,255,.025); font-size:13px; color:rgba(255,255,255,.62); }
        .order-meta-pill strong { display:block; color:#fff; margin-top:3px; }

        /* Cancel button */
        .btn-cancel-order {
            background: transparent;
            border: 1px solid rgba(229,57,53,0.4);
            color: #e53935;
            padding: 7px 16px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-cancel-order:hover { background: rgba(229,57,53,0.12); border-color: #e53935; }

        /* Refund policy notice */
        .refund-notice {
            background: rgba(255,152,0,0.08);
            border: 1px solid rgba(255,152,0,0.3);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 16px;
        }
        .refund-notice .rn-title {
            font-size: 13px; font-weight: 700; color: #ff9800;
            display: flex; align-items: center; gap: 6px; margin-bottom: 6px;
        }
        .refund-notice .rn-body {
            font-size: 13px; color: rgba(255,255,255,0.7); line-height: 1.6;
        }
        .refund-notice .rn-body strong { color: #ff9800; font-weight: 700; }

        /* COD cancelled notice */
        .refund-notice.cod-notice {
            background: rgba(77,184,72,0.07);
            border-color: rgba(77,184,72,0.25);
        }
        .refund-notice.cod-notice .rn-title { color: #4db848; }
        .refund-notice.cod-notice .rn-body strong { color: #4db848; }

        /* Cancel confirm modal */
        .cancel-modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.72); z-index: 9999;
            align-items: center; justify-content: center;
        }
        .cancel-modal-overlay.open { display: flex; }
        .cancel-modal {
            background: #1a1a1a; border: 1px solid rgba(255,255,255,0.1);
            border-radius: 18px; padding: 28px 28px 22px;
            max-width: 420px; width: 92%; box-shadow: 0 20px 60px rgba(0,0,0,0.6);
        }
        .cancel-modal h3 { margin: 0 0 10px; font-size: 18px; color: #fff; }
        .cancel-modal p  { margin: 0 0 18px; font-size: 13px; color: rgba(255,255,255,0.6); line-height: 1.6; }
        .cancel-modal .modal-policy {
            background: rgba(255,152,0,0.08); border: 1px solid rgba(255,152,0,0.25);
            border-radius: 10px; padding: 14px 16px; margin-bottom: 20px;
            font-size: 13px; color: rgba(255,255,255,0.7); line-height: 1.7;
        }
        .cancel-modal .modal-policy .policy-title {
            color: #ff9800; font-weight: 700; display: flex; align-items: center;
            gap: 6px; margin-bottom: 6px; font-size: 13px;
        }
        .cancel-modal .modal-policy .policy-body { color: rgba(255,255,255,0.65); font-size: 13px; line-height: 1.6; }
        .cancel-modal .modal-policy .policy-body strong { color: #ff9800; font-weight: 700; }
        .cancel-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
        .btn-modal-confirm { background: #e53935; color: #fff; border: none; padding: 9px 22px; border-radius: 999px; font-size: 13px; font-weight: 700; cursor: pointer; }
        .btn-modal-confirm:hover { background: #c62828; }
        .btn-modal-dismiss { background: transparent; border: 1px solid rgba(255,255,255,0.15); color: rgba(255,255,255,0.5); padding: 9px 18px; border-radius: 999px; font-size: 13px; cursor: pointer; }
        .btn-modal-dismiss:hover { border-color: rgba(255,255,255,0.3); color: #fff; }
    </style>
</head>
<body>

<nav class="navbar">
    <a class="navbar-brand" href="dashboard.php">
        <img src="../assets/images/Logo.PNG" alt="Herald Canteen" class="navbar-logo">
        <div class="navbar-title">Herald Canteen <span>Herald College Kathmandu</span></div>
    </a>

    <ul class="navbar-nav">
        <li><a href="dashboard.php">Menu</a></li>
        <li><a href="my_cart.php">🛒 Cart</a></li>
        <li><a href="my_orders.php" class="active">My Orders</a></li>
        <li><a href="user_profile.php">Profile</a></li>
    </ul>

    <div class="navbar-user">
        <div class="navbar-avatar"><?= h($initials) ?></div>
        <?php
        $nc_mo = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $nc_mo->bind_param("i", $user_id); $nc_mo->execute();
        $notif_mo_count = (int)$nc_mo->get_result()->fetch_row()[0]; $nc_mo->close();
        ?>
        <a href="notifications.php" class="notif-wrap" title="Notifications" style="position:relative;display:inline-flex;align-items:center;font-size:20px;text-decoration:none;margin-right:6px;">
            🔔<?php if ($notif_mo_count > 0): ?><span class="notif-badge"><?= $notif_mo_count ?></span><?php endif; ?>
        </a>
        <form method="POST" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <button type="submit" class="btn-logout">Logout</button>
        </form>
    </div>

    <label class="theme-toggle" title="Toggle light/dark mode">
      <input type="checkbox" class="theme-checkbox">
      <span class="theme-slider"></span>
    </label>
</nav>

<div class="page-wrapper">
    <div class="page-heading">
        <h1>My Orders</h1>
        <p>Track your active food orders and their live status.</p>
    </div>

    <?php if ($flash_error): ?><div class="alert alert-error" style="margin-bottom:16px;">⚠️ <?= h($flash_error) ?></div><?php endif; ?>
    <?php if ($flash_success): ?><div class="alert alert-success" style="margin-bottom:16px;">✅ <?= h($flash_success) ?></div><?php endif; ?>

    <?php if ($cancel_payment_method === 'esewa' && $cancel_order_id): ?>
    <div class="refund-notice">
        <div class="rn-title">💳 eSewa Refund Policy</div>
        <div class="rn-body">
            Your eSewa payment of <strong>Rs <?= number_format((float)$cancel_amount, 0) ?></strong>
            will be refunded to your eSewa wallet within <strong>3–5 business days</strong>.
            If you do not receive the refund, please contact the canteen staff.
        </div>
    </div>
    <?php elseif ($cancel_payment_method && $cancel_order_id): ?>
    <div class="refund-notice cod-notice">
        <div class="rn-title">✅ No Charge Applied</div>
        <div class="rn-body">
            This was a Cash on Delivery order — <strong>no payment was charged</strong>.
        </div>
    </div>
    <?php endif; ?>

    <?php if (isset($_GET['payment']) && $_GET['payment'] === 'success'): ?>
    <div class="alert alert-success" style="margin-bottom:16px;">✅ Payment confirmed! Your order has been placed successfully.</div>
    <?php elseif (isset($_GET['payment']) && $_GET['payment'] === 'cod_placed'): ?>
    <div class="alert alert-info" style="margin-bottom:16px;">🧾 Order placed! Please pay <strong>Cash on Delivery</strong> when your order arrives.</div>
    <?php endif; ?>

    <div class="order-section-switch">
        <a href="my_orders.php" class="active">🔥 Active Orders <small>Track pending, preparing, ready, and delivery orders.</small></a>
        <a href="order_history.php">📜 Order History <small>Delivered/cancelled orders from the last 7 days.</small></a>
        <span class="ops-live-pill" style="align-self:center;"><span></span> Live Sync Active</span>
        <small id="myOrdersLastUpdated" style="align-self:center;color:rgba(255,255,255,.48);font-weight:700;">Last updated: just now</small>
    </div>

    <div id="myOrdersLiveRegion" data-live-region="my-orders">
    <div class="stats-grid">
        <a href="my_orders.php?filter=active" class="stat-card-link"><div class="stat-card <?= $filter === 'active' ? 'active-stat' : '' ?>"><div class="stat-val"><?= (int)$counts['active'] ?></div><div class="stat-lbl">Active Orders</div></div></a>
        <a href="my_orders.php?filter=in_process" class="stat-card-link"><div class="stat-card <?= $filter === 'in_process' ? 'active-stat' : '' ?>"><div class="stat-val"><?= (int)$counts['in_process'] ?></div><div class="stat-lbl">In Process</div></div></a>
        <a href="my_orders.php?filter=ready_for_delivery" class="stat-card-link"><div class="stat-card <?= $filter === 'ready_for_delivery' ? 'active-stat' : '' ?>"><div class="stat-val"><?= (int)$counts['ready_for_delivery'] ?></div><div class="stat-lbl">Ready</div></div></a>
        <a href="order_history.php" class="stat-card-link"><div class="stat-card"><div class="stat-val"><?= (int)($history_summary['total'] ?? 0) ?></div><div class="stat-lbl">7-Day History</div></div></a>
    </div>

    <div class="tabs">
        <?php foreach ($tab_labels as $key => $label): ?>
            <a href="my_orders.php?filter=<?= urlencode($key) ?>" class="tab-btn <?= $filter === $key ? 'active' : '' ?>">
                <?= h($label) ?>
                <?php if (!empty($counts[$key]) && $counts[$key] > 0): ?><span class="tab-count"><?= (int)$counts[$key] ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="page-heading" style="margin-top:22px;">
        <h2 style="font-size:28px;">Active Orders</h2>
        <p><?= h(order_status_label($filter)) ?> orders only. Completed orders are moved to Order History.</p>
    </div>

    <?php if (empty($orders)): ?>
        <div class="card">
            <div class="empty-state">
                <div class="empty-icon">🔥</div>
                <h3>No active orders found</h3>
                <p>You do not currently have any <?= h(strtolower(order_status_label($filter))) ?> orders.</p>
                <a href="dashboard.php" class="btn btn-primary">Browse Menu</a>
                <a href="order_history.php" class="btn btn-outline" style="margin-left:8px;">View Order History</a>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($orders as $order):
            $ui_status    = $status_map[$order['status']] ?? 'in_process';
            $current_step = $status_steps[$ui_status]['step'] ?? 1;
            $order_items  = $details_map[(int)$order['order_id']] ?? [];
            $order_date   = $order['updated_at'] ?? $order['created_at'] ?? date('Y-m-d H:i:s');
            $inv          = $invoice_map[(int)$order['order_id']] ?? null;
        ?>
            <div class="card order-card">
                <div class="order-header">
                    <div>
                        <p class="order-id-label">Order #<?= str_pad((string)$order['order_id'], 4, '0', STR_PAD_LEFT) ?></p>
                        <p class="order-date"><?= h(date('D, d M Y · g:i A', strtotime($order_date))) ?></p>
                    </div>
                    <div class="order-header-right">
                        <span class="badge <?= h(order_badge_class($ui_status)) ?>"><?= h($status_steps[$ui_status]['icon'] ?? '') ?> <?= h(order_status_label($ui_status)) ?></span>
                        <span class="order-total">Rs <?= number_format((float)$order['total_amount'], 0) ?></span>
                    </div>
                </div>

                <div class="status-track">
                    <?php foreach ($all_steps as $step_key):
                        $step_num  = $status_steps[$step_key]['step'];
                        $is_done   = $step_num < $current_step;
                        $is_active = $step_num === $current_step;
                        $css_class = $is_done ? 'done' : ($is_active ? 'active' : '');
                    ?>
                        <div class="status-step <?= h($css_class) ?>">
                            <div class="status-dot"><?= $is_done ? '✓' : ($is_active ? '●' : '○') ?></div>
                            <div class="status-label"><?= h($status_steps[$step_key]['icon']) ?><br><?= h($status_steps[$step_key]['label']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="order-meta-grid">
                    <div class="order-meta-pill">Payment<strong><?= h(strtoupper((string)($order['payment_method'] ?? 'cod'))) ?></strong></div>
                    <div class="order-meta-pill">Mode<strong><?= h(delivery_label_for_order($order['delivery_mode'] ?? 'delivery')) ?></strong></div>
                    <div class="order-meta-pill">Subtotal<strong>Rs <?= number_format((float)($order['subtotal_amount'] ?? $order['total_amount']), 0) ?></strong></div>
                    <div class="order-meta-pill">Delivery Fee<strong><?= ((float)($order['delivery_fee'] ?? 0) > 0) ? 'Rs ' . number_format((float)$order['delivery_fee'], 0) : 'Free' ?></strong></div>
                </div>

                <?php if (!empty($order_items)): ?>
                    <div class="order-items-box">
                        <p class="order-items-label">Items Ordered</p>
                        <?php foreach ($order_items as $di): ?>
                            <div class="order-item-row">
                                <span><?= h($di['item_name']) ?> <span class="order-item-qty">× <?= (int)$di['quantity'] ?></span></span>
                                <span class="order-item-price">Rs <?= number_format((float)$di['price'] * (int)$di['quantity'], 0) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (($order['delivery_mode'] ?? 'delivery') === 'delivery'): ?>
                <div class="order-delivery-location" style="margin-top:10px;padding:10px 12px;background:rgba(77,184,72,0.07);border:1px solid rgba(77,184,72,0.15);border-radius:8px;font-size:13px;">
                    <span style="color:rgba(255,255,255,0.5);font-size:11px;display:block;margin-bottom:3px;">📍 DELIVERY LOCATION</span>
                    <?php if (!empty($order['delivery_location_name'])): ?>
                        <strong style="color:#fff;"><?= h($order['delivery_location_name']) ?></strong>
                        <span style="color:rgba(255,255,255,0.45);font-size:12px;"> — <?= h($order['delivery_block_name'] ?? '') ?></span>
                    <?php else: ?>
                        <span style="color:rgba(255,255,255,0.4);font-style:italic;">Not specified</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="order-footer">
                    <?php if ($order['status'] === 'out_for_delivery'): ?>
                        <span class="order-onway-note">🛵 On Delivery / On the Way!</span>
                    <?php elseif ($order['status'] === 'ready'): ?>
                        <span class="order-onway-note">✅ Ready — awaiting pickup/delivery</span>
                    <?php else: ?>
                        <span class="order-pending-note">⏳ Your order is being prepared...</span>
                    <?php endif; ?>

                    <?php if ($inv): $tok = urlencode($inv['invoice_token']); ?>
                        <a href="kot_invoice.php?token=<?= $tok ?>" class="btn btn-outline btn-sm" target="_blank">👁 Preview Invoice</a>
                    <?php endif; ?>

                    <?php if (in_array($order['status'], ['pending', 'preparing'], true)): ?>
                        <button
                            type="button"
                            class="btn-cancel-order"
                            onclick="openCancelModal(<?= (int)$order['order_id'] ?>, '<?= h($order['payment_method']) ?>', <?= (float)$order['total_amount'] ?>)"
                        >✕ Cancel Order</button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;">
        <a href="dashboard.php" class="btn btn-secondary">Order More Food</a>
        <a href="order_history.php" class="btn btn-outline">View Order History</a>
    </div>
    </div>
</div>

<!-- Cancel Order Confirmation Modal -->
<div class="cancel-modal-overlay" id="cancelModalOverlay">
    <div class="cancel-modal">
        <h3>⚠️ Cancel Order?</h3>
        <p id="cancelModalDesc">Are you sure you want to cancel this order?</p>
        <div class="modal-policy" id="cancelModalPolicy"></div>
        <form method="POST" action="my_orders.php" id="cancelOrderForm">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="cancel_order">
            <input type="hidden" name="order_id" id="cancelOrderIdInput" value="">
            <div class="cancel-modal-actions">
                <button type="button" class="btn-modal-dismiss" onclick="closeCancelModal()">Keep Order</button>
                <button type="submit" class="btn-modal-confirm">Yes, Cancel It</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCancelModal(orderId, payMethod, amount) {
    document.getElementById('cancelOrderIdInput').value = orderId;
    var orderLabel = '#' + String(orderId).padStart(4, '0');
    document.getElementById('cancelModalDesc').textContent =
        'Are you sure you want to cancel Order ' + orderLabel + '?';

    var policy = document.getElementById('cancelModalPolicy');
    if (payMethod === 'esewa') {
        policy.innerHTML =
            '<div class="policy-title">💳 eSewa Refund Policy</div>'
            + '<div class="policy-body">Your eSewa payment of <strong>Rs ' + Math.round(amount).toLocaleString() + '</strong>'
            + ' will be refunded to your eSewa wallet within <strong>3–5 business days</strong> after cancellation.</div>';
    } else {
        policy.innerHTML =
            '<div class="policy-title">✅ No Charge</div>'
            + '<div class="policy-body">This is a Cash on Delivery order — <strong>no payment has been made</strong>, so no refund is needed.</div>';
    }

    document.getElementById('cancelModalOverlay').classList.add('open');
}
function closeCancelModal() {
    document.getElementById('cancelModalOverlay').classList.remove('open');
}
// Close on backdrop click
document.getElementById('cancelModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeCancelModal();
});
</script>

<script>
(function() {
    var regionId = 'myOrdersLiveRegion';
    var lastUpdatedId = 'myOrdersLastUpdated';
    var intervalMs = 4500;
    var inFlight = false;

    function setUpdatedLabel(text) {
        var el = document.getElementById(lastUpdatedId);
        if (el) el.textContent = text;
    }

    function refreshMyOrders() {
        if (inFlight || document.hidden) return;
        inFlight = true;
        fetch(window.location.href, {
            method: 'GET',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.text(); })
        .then(function(html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var fresh = doc.getElementById(regionId);
            var current = document.getElementById(regionId);
            if (fresh && current) {
                current.innerHTML = fresh.innerHTML;
                setUpdatedLabel('Last updated: ' + new Date().toLocaleTimeString([], {hour: '2-digit', minute: '2-digit', second: '2-digit'}));
            }
        })
        .catch(function() {
            setUpdatedLabel('Live sync paused — retrying...');
        })
        .finally(function() {
            inFlight = false;
        });
    }

    setInterval(refreshMyOrders, intervalMs);
    document.addEventListener('visibilitychange', function() { if (!document.hidden) refreshMyOrders(); });
})();

/* ── Real-time notification toast poller (handled by shared notif_poller.js) ─── */
</script>
<script src="../assets/js/notif_poller.js"></script>

</body>
</html>