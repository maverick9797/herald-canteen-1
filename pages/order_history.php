<?php
// order_history.php — Herald Canteen (Customer 7-day order history)

require_once '../includes/auth.php';
start_session();
session_security_check();
require_once '../config/db.php';
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

if (!function_exists('h')) {
    function h($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function history_badge_class(string $s): string {
    return match ($s) {
        'delivered' => 'badge-gray',
        'cancelled' => 'badge-yellow',
        default => 'badge-gray',
    };
}

function history_status_label(string $s): string {
    return match ($s) {
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $s)),
    };
}

function history_delivery_label(?string $mode): string {
    return match ($mode ?? 'delivery') {
        'takeaway' => 'Takeaway 🥡',
        'delivery' => 'Delivery 🚚',
        'dine_in'  => 'Takeaway 🥡',  // legacy value — dine-in no longer offered, show as Takeaway
        default    => 'Delivery 🚚',
    };
}

$stmt = $conn->prepare("\n    SELECT *\n    FROM orders\n    WHERE user_id = ?\n      AND status IN ('delivered', 'cancelled')\n      AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)\n    ORDER BY updated_at DESC, order_id DESC\n");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$summary_stmt = $conn->prepare("\n    SELECT\n        COUNT(*) AS total_history,\n        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,\n        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,\n        COALESCE(SUM(CASE WHEN status = 'delivered' THEN total_amount ELSE 0 END), 0) AS total_spent\n    FROM orders\n    WHERE user_id = ?\n      AND status IN ('delivered', 'cancelled')\n      AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)\n");
$summary_stmt->bind_param('i', $user_id);
$summary_stmt->execute();
$summary = $summary_stmt->get_result()->fetch_assoc() ?: [];
$summary_stmt->close();

$order_ids = array_column($orders, 'order_id');
$details_map = [];
$invoice_map = [];

if (!empty($order_ids)) {
    $placeholders = implode(',', array_fill(0, count($order_ids), '?'));
    $stmt = $conn->prepare("\n        SELECT oi.order_id, oi.quantity, oi.price, COALESCE(mi.name, 'Unavailable item') AS item_name\n        FROM order_items oi\n        LEFT JOIN menu_items mi ON oi.item_id = mi.item_id\n        WHERE oi.order_id IN ($placeholders)\n        ORDER BY oi.order_item_id ASC\n    ");
    $stmt->bind_param(str_repeat('i', count($order_ids)), ...$order_ids);
    $stmt->execute();
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $d) {
        $details_map[(int)$d['order_id']][] = $d;
    }
    $stmt->close();

    if (hc_table_exists($conn, 'kot_invoices')) {
        $stmt = $conn->prepare("\n            SELECT order_id, invoice_token, is_paid\n            FROM kot_invoices\n            WHERE order_id IN ($placeholders)\n        ");
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
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

$initials = strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['name'] ?? 'U', 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order History — Herald Canteen</title>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .order-section-switch { display:flex; gap:12px; flex-wrap:wrap; margin:18px 0 22px; }
        .order-section-switch a { padding:14px 18px; border-radius:14px; border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.035); color:rgba(255,255,255,.72); font-weight:800; text-decoration:none; }
        .order-section-switch a.active { border-color:rgba(77,184,72,.6); background:rgba(77,184,72,.12); color:#4db848; }
        .order-section-switch small { display:block; margin-top:4px; color:rgba(255,255,255,.42); font-weight:600; }
        .history-note { padding:12px 14px; border-radius:12px; border:1px solid rgba(77,184,72,.18); background:rgba(77,184,72,.07); color:rgba(255,255,255,.72); margin-bottom:18px; }
        .order-meta-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(145px,1fr)); gap:10px; margin-top:12px; }
        .order-meta-pill { padding:10px 12px; border-radius:10px; border:1px solid rgba(255,255,255,.06); background:rgba(255,255,255,.025); font-size:13px; color:rgba(255,255,255,.62); }
        .order-meta-pill strong { display:block; color:#fff; margin-top:3px; }
        .inline-reorder-form { display:inline-flex; margin:0; }
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
        $nc_stmt2 = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $nc_stmt2->bind_param("i", $user_id);
        $nc_stmt2->execute();
        $notif_oh_count = (int)$nc_stmt2->get_result()->fetch_row()[0];
        $nc_stmt2->close();
        ?>
        <a href="notifications.php" class="notif-wrap" title="Notifications" style="position:relative;display:inline-flex;align-items:center;font-size:20px;text-decoration:none;margin-right:6px;">
            🔔
            <?php if ($notif_oh_count > 0): ?><span class="notif-badge"><?= $notif_oh_count ?></span><?php endif; ?>
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
        <h1>Order History</h1>
        <p>Review delivered and cancelled orders from the last 7 days.</p>
    </div>

    <?php if ($flash_error): ?><div class="alert alert-error" style="margin-bottom:16px;">⚠️ <?= h($flash_error) ?></div><?php endif; ?>
    <?php if ($flash_success): ?><div class="alert alert-success" style="margin-bottom:16px;">✅ <?= h($flash_success) ?></div><?php endif; ?>

    <div class="order-section-switch">
        <a href="my_orders.php">🔥 Active Orders <small>Track current orders.</small></a>
        <a href="order_history.php" class="active">📜 Order History <small>Delivered/cancelled orders from the last 7 days.</small></a>
    </div>

    <div class="history-note">📌 Showing orders from the last 7 days. Older records remain in the database but are hidden from this customer history page.</div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-val"><?= (int)($summary['total_history'] ?? 0) ?></div><div class="stat-lbl">History Orders</div></div>
        <div class="stat-card"><div class="stat-val"><?= (int)($summary['delivered_count'] ?? 0) ?></div><div class="stat-lbl">Delivered</div></div>
        <div class="stat-card"><div class="stat-val"><?= (int)($summary['cancelled_count'] ?? 0) ?></div><div class="stat-lbl">Cancelled</div></div>
        <div class="stat-card"><div class="stat-val">Rs <?= number_format((float)($summary['total_spent'] ?? 0), 0) ?></div><div class="stat-lbl">7-Day Spent</div></div>
    </div>

    <?php if (empty($orders)): ?>
        <div class="card" style="margin-top:22px;">
            <div class="empty-state">
                <div class="empty-icon">📜</div>
                <h3>No recent order history</h3>
                <p>You have no delivered or cancelled orders from the last 7 days.</p>
                <a href="my_orders.php" class="btn btn-outline">Back to Active Orders</a>
                <a href="dashboard.php" class="btn btn-primary" style="margin-left:8px;">Browse Menu</a>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($orders as $order):
            $order_items = $details_map[(int)$order['order_id']] ?? [];
            $order_date = $order['updated_at'] ?? $order['created_at'] ?? date('Y-m-d H:i:s');
            $inv = $invoice_map[(int)$order['order_id']] ?? null;
        ?>
            <div class="card order-card">
                <div class="order-header">
                    <div>
                        <p class="order-id-label">Order #<?= str_pad((string)$order['order_id'], 4, '0', STR_PAD_LEFT) ?></p>
                        <p class="order-date"><?= h(date('D, d M Y · g:i A', strtotime($order_date))) ?></p>
                    </div>
                    <div class="order-header-right">
                        <span class="badge <?= h(history_badge_class($order['status'])) ?>"><?= $order['status'] === 'cancelled' ? '⚠️' : '🏠' ?> <?= h(history_status_label($order['status'])) ?></span>
                        <span class="order-total">Rs <?= number_format((float)$order['total_amount'], 0) ?></span>
                    </div>
                </div>

                <div class="order-meta-grid">
                    <div class="order-meta-pill">Payment Method<strong><?= h(strtoupper((string)($order['payment_method'] ?? 'cod'))) ?></strong></div>
                    <div class="order-meta-pill">Delivery Mode<strong><?= h(history_delivery_label($order['delivery_mode'] ?? 'delivery')) ?></strong></div>
                    <div class="order-meta-pill">Subtotal<strong>Rs <?= number_format((float)($order['subtotal_amount'] ?? $order['total_amount']), 0) ?></strong></div>
                    <div class="order-meta-pill">Delivery Fee<strong><?= ((float)($order['delivery_fee'] ?? 0) > 0) ? 'Rs ' . number_format((float)$order['delivery_fee'], 0) : 'Free' ?></strong></div>
                </div>

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

                <?php if (!empty($order['special_notes'])): ?>
                    <div class="order-meta-pill" style="margin-top:12px;">Customer Remark<strong><?= h($order['special_notes']) ?></strong></div>
                <?php endif; ?>

                <div class="order-footer">
                    <form method="POST" action="my_cart.php" class="inline-reorder-form">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="reorder">
                        <input type="hidden" name="order_id" value="<?= (int)$order['order_id'] ?>">
                        <button type="submit" class="btn btn-outline btn-sm">🔄 Reorder</button>
                    </form>

                    <?php if ($inv): $tok = urlencode($inv['invoice_token']); ?>
                        <a href="kot_invoice.php?token=<?= $tok ?>" class="btn btn-outline btn-sm" target="_blank">🧾 View Invoice</a>
                        <?php if (!empty($inv['is_paid'])): ?>
                            <a href="kot_invoice.php?token=<?= $tok ?>&download=1" class="btn btn-outline btn-sm" target="_blank">⬇ Download</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap;">
        <a href="my_orders.php" class="btn btn-secondary">Back to Active Orders</a>
        <a href="dashboard.php" class="btn btn-outline">Order More Food</a>
    </div>
</div>

<script src="../assets/js/notif_poller.js"></script>
</body>
</html>
