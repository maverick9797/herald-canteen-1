<?php
require_once "../includes/auth.php";
start_session();
session_security_check();
require_once "../config/db.php";
require_once "../includes/functions.php";

require_role('staff');

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function staff_delivery_label_history(?string $mode): string { return $mode === 'takeaway' ? 'Takeaway / Pickup' : 'Delivery'; }
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

$toast = $_SESSION['_toast'] ?? null;
unset($_SESSION['_toast']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_paid_history'])) {
    $staff_id = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $conn->prepare("INSERT IGNORE INTO order_history_hidden (order_id, hidden_for_role, hidden_by)
        SELECT o.order_id, 'staff', ?
        FROM orders o
        INNER JOIN payments p ON p.order_id = o.order_id AND p.payment_status = 'successful'
        LEFT JOIN order_history_hidden h ON h.order_id = o.order_id AND h.hidden_for_role = 'staff'
        WHERE o.status = 'delivered'
          AND h.hidden_id IS NULL");
    $stmt->bind_param('i', $staff_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    $_SESSION['_toast'] = [
        'type' => 'success',
        'text' => $affected > 0 ? "Cleared {$affected} paid delivered order(s) from staff history." : 'No paid delivered history was available to clear.'
    ];
    session_write_close();
    header('Location: staff-order-history.php');
    exit;
}

$summary = ['paid_delivered' => 0, 'today' => 0, 'hidden' => 0];
$res = $conn->query("SELECT COUNT(*) AS total FROM orders o INNER JOIN payments p ON p.order_id=o.order_id AND p.payment_status='successful' LEFT JOIN order_history_hidden h ON h.order_id=o.order_id AND h.hidden_for_role='staff' WHERE o.status='delivered' AND h.hidden_id IS NULL");
$summary['paid_delivered'] = (int)($res ? ($res->fetch_assoc()['total'] ?? 0) : 0);
$res = $conn->query("SELECT COUNT(*) AS total FROM orders o INNER JOIN payments p ON p.order_id=o.order_id AND p.payment_status='successful' LEFT JOIN order_history_hidden h ON h.order_id=o.order_id AND h.hidden_for_role='staff' WHERE o.status='delivered' AND DATE(o.created_at)=CURDATE() AND h.hidden_id IS NULL");
$summary['today'] = (int)($res ? ($res->fetch_assoc()['total'] ?? 0) : 0);
$res = $conn->query("SELECT COUNT(*) AS total FROM order_history_hidden WHERE hidden_for_role='staff'");
$summary['hidden'] = (int)($res ? ($res->fetch_assoc()['total'] ?? 0) : 0);

$stmt = $conn->prepare("SELECT
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
        GROUP_CONCAT(CONCAT(mi.name, ' x', oi.quantity) ORDER BY mi.name SEPARATOR ', ') AS items_summary
    FROM orders o
    INNER JOIN users u ON o.user_id = u.user_id
    INNER JOIN payments p ON p.order_id = o.order_id AND p.payment_status = 'successful'
    LEFT JOIN kitchen_order_tickets k ON k.order_id = o.order_id
    LEFT JOIN order_items oi ON oi.order_id = o.order_id
    LEFT JOIN menu_items mi ON mi.item_id = oi.item_id
    LEFT JOIN order_history_hidden h ON h.order_id = o.order_id AND h.hidden_for_role = 'staff'
    WHERE o.status = 'delivered'
      AND h.hidden_id IS NULL
    GROUP BY o.order_id, u.full_name, o.total_amount, o.status, o.payment_method, o.created_at
    ORDER BY o.order_id DESC");
$stmt->execute();
$orders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paid Order History — Staff</title>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="staff-page ops-page">
<div class="layout">
    <div class="sidebar">
        <div class="navbar-title">Herald Canteen<span>Staff Portal</span></div>
        <nav>
            <a href="staff-control.php">🧾 Staff Home</a>
            <a href="staff-control.php#orders-section">📦 Active Orders</a>
            <a href="staff-order-history.php" class="active">🕘 Paid History</a>
            <a href="manage-delivery-locations.php">📍 Delivery Locations</a>
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>
    <div class="main">
        <div class="topbar">
            <div class="topbar-welcome">Welcome, <?php echo h($_SESSION['full_name'] ?? 'Staff'); ?></div>
            <label class="theme-toggle" title="Toggle light/dark mode"><input type="checkbox" class="theme-checkbox"><span class="theme-slider"></span></label>
        </div>
        <div class="content">
            <section class="ops-hero ops-hero-staff">
                <div>
                    <p class="ops-eyebrow">History Centre</p>
                    <h1>Paid Order History</h1>
                    <p>Review completed paid orders separately from the active dispatch dashboard.</p>
                </div>
                <div class="ops-hero-side">
                    <span class="ops-role-pill">🧾 <?php echo h($_SESSION['full_name'] ?? 'Staff'); ?></span>
                    <a href="staff-control.php" class="ops-link-btn">Back to Active Orders</a>
                </div>
            </section>

            <?php if ($toast): ?>
                <div class="alert-success">✓ <?php echo h($toast['text'] ?? 'Action completed.'); ?></div>
            <?php endif; ?>

            <section class="ops-stat-grid" aria-label="Paid history summary">
                <article class="ops-stat-card ops-stat-green"><div class="ops-stat-icon">✅</div><div><span>Visible Paid History</span><strong><?php echo (int)$summary['paid_delivered']; ?></strong></div></article>
                <article class="ops-stat-card ops-stat-total"><div class="ops-stat-icon">📅</div><div><span>Delivered Today</span><strong><?php echo (int)$summary['today']; ?></strong></div></article>
                <article class="ops-stat-card ops-stat-purple"><div class="ops-stat-icon">🙈</div><div><span>Cleared From View</span><strong><?php echo (int)$summary['hidden']; ?></strong></div></article>
                <article class="ops-stat-card ops-stat-pending"><div class="ops-stat-icon">💵</div><div><span>History Action</span><strong>Safe Hide</strong></div></article>
            </section>

            <section class="ops-panel">
                <div class="ops-panel-heading">
                    <div>
                        <p class="ops-eyebrow">Completed Orders</p>
                        <h2>Delivered & Paid Orders</h2>
                        <p>The clear button hides completed paid orders from this history view without deleting order, invoice, or payment records.</p>
                    </div>
                    <form method="POST" onsubmit="return confirm('Hide all visible paid delivered orders from staff history? This does not delete database records.');">
                        <button type="submit" name="clear_paid_history" class="ops-btn ops-btn-warning">🧹 Clear Paid History</button>
                    </form>
                </div>

                <?php if (!empty($orders)): ?>
                    <div class="ops-card-grid staff-card-grid">
                        <?php foreach ($orders as $order): ?>
                            <article class="ops-order-card staff-order-card">
                                <div class="ops-card-top"><div><h3>Order #<?php echo (int)$order['order_id']; ?></h3><p><?php echo h($order['full_name']); ?></p></div><span class="ops-status-badge status-delivered">Delivered</span></div>
                                <div class="ops-meta-grid">
                                    <div><span>Mode</span><strong><?php echo h(staff_delivery_label_history($order['delivery_mode'] ?? 'delivery')); ?></strong></div>
                                    <div><span>Payment</span><strong><?php echo h(strtoupper($order['payment_method'])); ?></strong></div>
                                    <div><span>Order Time</span><strong><?php echo h(date('d M, g:i A', strtotime($order['created_at']))); ?></strong></div>
                                    <div><span>Total</span><strong>Rs. <?php echo number_format((float)$order['total_amount'], 2); ?></strong></div>
                                </div>
                                <div class="ops-payment-line"><span class="ops-payment-badge payment-successful">Paid</span><strong>Completed</strong></div>
                                <?php if (!empty($order['special_notes'])): ?><div class="ops-remark"><span>Customer Remark</span><?php echo h($order['special_notes']); ?></div><?php endif; ?>
                                <?php if (($order['delivery_mode'] ?? 'delivery') === 'delivery'): ?>
                                    <?php if (!empty($order['delivery_location_name'])): ?>
                                        <div class="delivery-location-card" style="background:rgba(77,184,72,0.1);border:1px solid rgba(77,184,72,0.3);border-radius:8px;padding:8px 12px;margin-top:6px;">
                                            <span style="font-size:11px;color:rgba(255,255,255,0.5);">📍 </span>
                                            <strong style="color:#fff;font-size:13px;"><?php echo h($order['delivery_location_name']); ?></strong>
                                            <em style="color:rgba(255,255,255,0.5);font-size:12px;"> — <?php echo h($order['delivery_block_name'] ?? ''); ?></em>
                                        </div>
                                    <?php else: ?>
                                        <div style="font-size:12px;color:rgba(255,255,255,0.4);margin-top:4px;">📍 Delivery location: <em>Not specified</em></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div style="font-size:12px;color:rgba(255,255,255,0.4);margin-top:4px;">🥡 Takeaway</div>
                                <?php endif; ?>
                                <div class="ops-items-block"><div class="ops-items-title">Items</div><p class="ops-items-summary"><?php echo h($order['items_summary'] ?? 'No items found'); ?></p></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="ops-empty-state"><div>🧹</div><h3>No paid history to show</h3><p>Delivered paid orders will appear here until you clear them from this history view.</p></div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
</body>
</html>
