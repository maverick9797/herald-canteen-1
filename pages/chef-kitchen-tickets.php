<?php
require_once "../includes/auth.php";
start_session();
session_security_check();
require_once "../config/db.php";

require_role('chef');

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function chef_ticket_status_label(string $status): string
{
    return match ($status) {
        'pending' => 'Pending',
        'preparing' => 'Preparing',
        'ready' => 'Ready',
        'out_for_delivery' => 'On Delivery',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        default => ucwords(str_replace('_', ' ', $status)),
    };
}
function chef_ticket_delivery_label(?string $mode): string { return $mode === 'takeaway' ? 'Takeaway / Pickup' : 'Delivery'; }
function chef_ticket_age(?string $datetime): string
{
    if (!$datetime || !($timestamp = strtotime($datetime))) return 'Time unavailable';
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

$toast = $_SESSION['_toast'] ?? null;
unset($_SESSION['_toast']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_completed_kot_history'])) {
    $chef_id = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $conn->prepare("INSERT IGNORE INTO order_history_hidden (order_id, hidden_for_role, hidden_by)
        SELECT DISTINCT o.order_id, 'chef', ?
        FROM orders o
        INNER JOIN kitchen_order_tickets k ON k.order_id = o.order_id
        LEFT JOIN order_history_hidden h ON h.order_id = o.order_id AND h.hidden_for_role = 'chef'
        WHERE o.status IN ('ready', 'out_for_delivery', 'delivered', 'cancelled')
          AND h.hidden_id IS NULL");
    $stmt->bind_param('i', $chef_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    $_SESSION['_toast'] = [
        'type' => 'success',
        'text' => $affected > 0 ? "Cleared {$affected} completed KOT(s) from chef history." : 'No completed KOT history was available to clear.'
    ];
    session_write_close();
    header('Location: chef-kitchen-tickets.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
    $order_id = filter_var($_POST['order_id'] ?? 0, FILTER_VALIDATE_INT);
    $new_status = trim($_POST['new_status'] ?? '');
    if ($order_id > 0 && in_array($new_status, ['preparing', 'ready'], true)) {
        $stmt = $conn->prepare("SELECT status FROM orders WHERE order_id = ?");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $allowed = $row && (($row['status'] === 'pending' && $new_status === 'preparing') || ($row['status'] === 'preparing' && $new_status === 'ready'));
        if ($allowed) {
            $stmt = $conn->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
            $stmt->bind_param('si', $new_status, $order_id);
            $stmt->execute();
            $stmt->close();
            $_SESSION['_toast'] = ['type' => 'success', 'text' => 'Ticket status updated successfully.'];
        } else {
            $_SESSION['_toast'] = ['type' => 'danger', 'text' => 'Invalid ticket status transition.'];
        }
    } else {
        $_SESSION['_toast'] = ['type' => 'danger', 'text' => 'Invalid ticket status request.'];
    }
    session_write_close();
    header('Location: chef-kitchen-tickets.php');
    exit;
}

$summary = ['pending'=>0, 'preparing'=>0, 'completed'=>0, 'hidden'=>0];
$res = $conn->query("SELECT o.status, COUNT(*) AS total FROM orders o INNER JOIN kitchen_order_tickets k ON k.order_id=o.order_id LEFT JOIN order_history_hidden h ON h.order_id=o.order_id AND h.hidden_for_role='chef' WHERE h.hidden_id IS NULL GROUP BY o.status");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        if (isset($summary[$row['status']])) $summary[$row['status']] = (int)$row['total'];
        if (in_array($row['status'], ['ready','out_for_delivery','delivered','cancelled'], true)) $summary['completed'] += (int)$row['total'];
    }
}
$res = $conn->query("SELECT COUNT(*) AS total FROM order_history_hidden WHERE hidden_for_role='chef'");
$summary['hidden'] = (int)($res ? ($res->fetch_assoc()['total'] ?? 0) : 0);

$kots = $conn->query("SELECT
        k.kot_id,
        k.order_id,
        k.delivery_mode,
        k.special_notes,
        k.created_at AS kot_created_at,
        o.total_amount,
        o.status AS order_status,
        o.payment_method,
        o.created_at AS order_created_at,
        o.delivery_location_name,
        o.delivery_block_name,
        u.full_name AS customer_name
    FROM kitchen_order_tickets k
    INNER JOIN orders o ON k.order_id = o.order_id
    INNER JOIN users u ON o.user_id = u.user_id
    LEFT JOIN order_history_hidden h ON h.order_id = o.order_id AND h.hidden_for_role = 'chef'
    WHERE h.hidden_id IS NULL
    ORDER BY CASE o.status WHEN 'pending' THEN 1 WHEN 'preparing' THEN 2 WHEN 'ready' THEN 3 WHEN 'out_for_delivery' THEN 4 WHEN 'delivered' THEN 5 ELSE 6 END,
             k.kot_id DESC");
$kot_rows = $kots ? $kots->fetch_all(MYSQLI_ASSOC) : [];

$kot_items_map = [];
if (!empty($kot_rows)) {
    $ids = array_map(fn($row) => (int)$row['order_id'], $kot_rows);
    $id_list = implode(',', $ids);
    $items = $conn->query("SELECT oi.order_id, mi.name AS item_name, oi.quantity, oi.price
        FROM order_items oi
        INNER JOIN menu_items mi ON oi.item_id = mi.item_id
        WHERE oi.order_id IN ($id_list)
        ORDER BY oi.order_item_id");
    if ($items) {
        while ($row = $items->fetch_assoc()) $kot_items_map[(int)$row['order_id']][] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Tickets — Chef</title>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="chef-page ops-page">
<div class="layout">
    <div class="sidebar">
        <div class="navbar-title">Herald Canteen<span>Chef Portal</span></div>
        <nav>
            <a href="chef-control.php">👨‍🍳 Chef Dashboard</a>
            <a href="chef-kitchen-tickets.php" class="active">🎫 Kitchen Tickets</a>
            <a href="chef-categories.php">🖼️ Categories</a>
            <a href="chef-menu.php">🍽️ Manage Menu</a>
            <a href="manage-delivery-locations.php">📍 Delivery Locations</a>
            <a href="logout.php">🚪 Logout</a>
        </nav>
    </div>
    <div class="main">
        <div class="topbar">
            <div class="topbar-welcome">Welcome, <?php echo h($_SESSION['full_name'] ?? 'Chef'); ?></div>
            <label class="theme-toggle" title="Toggle light/dark mode"><input type="checkbox" class="theme-checkbox"><span class="theme-slider"></span></label>
        </div>
        <div class="content">
            <section class="ops-hero ops-hero-chef">
                <div><p class="ops-eyebrow">Kitchen Ticket Centre</p><h1>Kitchen Tickets</h1><p>View active and completed KOTs separately from menu and category management.</p></div>
                <div class="ops-hero-side"><span class="ops-role-pill">👨‍🍳 <?php echo h($_SESSION['full_name'] ?? 'Chef'); ?></span><a href="chef-control.php" class="ops-link-btn">Back to Dashboard</a></div>
            </section>

            <?php if ($toast): ?><div class="<?php echo ($toast['type'] ?? '') === 'danger' ? 'alert-error' : 'alert-success'; ?>"><?php echo h($toast['text'] ?? 'Action completed.'); ?></div><?php endif; ?>

            <section class="ops-stat-grid" aria-label="Kitchen ticket summary">
                <article class="ops-stat-card ops-stat-pending"><div class="ops-stat-icon">🆕</div><div><span>Pending</span><strong><?php echo (int)$summary['pending']; ?></strong></div></article>
                <article class="ops-stat-card ops-stat-blue"><div class="ops-stat-icon">🔥</div><div><span>Preparing</span><strong><?php echo (int)$summary['preparing']; ?></strong></div></article>
                <article class="ops-stat-card ops-stat-green"><div class="ops-stat-icon">✅</div><div><span>Completed Visible</span><strong><?php echo (int)$summary['completed']; ?></strong></div></article>
                <article class="ops-stat-card ops-stat-purple"><div class="ops-stat-icon">🙈</div><div><span>Cleared</span><strong><?php echo (int)$summary['hidden']; ?></strong></div></article>
            </section>

            <section class="ops-panel">
                <div class="ops-panel-heading">
                    <div><p class="ops-eyebrow">KOT Records</p><h2>Kitchen Ticket Board</h2><p>Active orders stay on the main dashboard. Completed tickets can be cleared from this history view without deleting records.</p></div>
                    <form method="POST" onsubmit="return confirm('Hide all completed KOTs from chef history? This does not delete database records.');">
                        <button type="submit" name="clear_completed_kot_history" class="ops-btn ops-btn-warning">🧹 Clear Completed KOT History</button>
                    </form>
                </div>
                <div class="ops-filter-tabs" data-filter-group="chef-history">
                    <button type="button" class="ops-filter-btn active" data-filter="all">All</button>
                    <button type="button" class="ops-filter-btn" data-filter="pending">Pending</button>
                    <button type="button" class="ops-filter-btn" data-filter="preparing">Preparing</button>
                    <button type="button" class="ops-filter-btn" data-filter="ready">Ready</button>
                    <button type="button" class="ops-filter-btn" data-filter="out_for_delivery">On Delivery</button>
                    <button type="button" class="ops-filter-btn" data-filter="delivered">Delivered</button>
                </div>

                <?php if (!empty($kot_rows)): ?>
                    <div class="ops-card-grid" id="chefHistoryGrid">
                        <?php foreach ($kot_rows as $kot): ?>
                            <?php $status = $kot['order_status']; $items = $kot_items_map[(int)$kot['order_id']] ?? []; ?>
                            <article class="ops-order-card chef-kot-card" data-filter-status="<?php echo h($status); ?>">
                                <div class="ops-card-top"><div><h3>KOT #<?php echo (int)$kot['kot_id']; ?></h3><p>Order #<?php echo (int)$kot['order_id']; ?> · <?php echo h($kot['customer_name']); ?></p></div><span class="ops-status-badge status-<?php echo h($status); ?>"><?php echo h(chef_ticket_status_label($status)); ?></span></div>
                                <div class="ops-meta-grid">
                                    <div><span>Mode</span><strong><?php echo h(chef_ticket_delivery_label($kot['delivery_mode'])); ?></strong></div>
                                    <div><span>Payment</span><strong><?php echo h(strtoupper($kot['payment_method'])); ?></strong></div>
                                    <div><span>Ordered</span><strong><?php echo h(date('d M, g:i A', strtotime($kot['order_created_at']))); ?></strong></div>
                                    <div><span>Age</span><strong><?php echo h(chef_ticket_age($kot['order_created_at'])); ?></strong></div>
                                </div>
                                <?php if (!empty($kot['special_notes'])): ?><div class="ops-remark"><span>Customer Remark</span><?php echo h($kot['special_notes']); ?></div><?php endif; ?>
                                <?php
                                $kt_loc  = $kot['delivery_location_name'] ?? null;
                                $kt_bloc = $kot['delivery_block_name'] ?? null;
                                if (($kot['delivery_mode'] ?? 'delivery') === 'delivery'): ?>
                                <div class="ops-remark delivery-location-card" style="background:rgba(77,184,72,0.07);border-color:rgba(77,184,72,0.25);">
                                    <span>📍 Deliver To</span>
                                    <?php if ($kt_loc): ?>
                                        <strong><?php echo h($kt_loc); ?></strong><?php if ($kt_bloc): ?><em style="font-size:11px;color:rgba(255,255,255,0.45);display:block;margin-top:2px;"><?php echo h($kt_bloc); ?></em><?php endif; ?>
                                    <?php else: ?><span style="color:rgba(255,255,255,0.4);font-style:italic;">Not specified</span><?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <div class="ops-items-block"><div class="ops-items-title">Order Items</div><?php if ($items): ?><ul class="ops-item-list"><?php foreach ($items as $it): ?><li><span><?php echo (int)$it['quantity']; ?> × <?php echo h($it['item_name']); ?></span><strong>Rs. <?php echo number_format((float)$it['price'], 2); ?></strong></li><?php endforeach; ?></ul><?php else: ?><p class="ops-muted">No items found.</p><?php endif; ?></div>
                                <div class="ops-card-total"><span>Total</span><strong>Rs. <?php echo number_format((float)$kot['total_amount'], 2); ?></strong></div>
                                <div class="ops-action-row">
                                    <?php if ($status === 'pending'): ?>
                                        <form method="POST"><input type="hidden" name="order_id" value="<?php echo (int)$kot['order_id']; ?>"><input type="hidden" name="new_status" value="preparing"><button type="submit" name="update_order_status" class="ops-btn ops-btn-primary">🔥 Start Preparing</button></form>
                                    <?php elseif ($status === 'preparing'): ?>
                                        <form method="POST"><input type="hidden" name="order_id" value="<?php echo (int)$kot['order_id']; ?>"><input type="hidden" name="new_status" value="ready"><button type="submit" name="update_order_status" class="ops-btn ops-btn-primary">✅ Mark Ready</button></form>
                                    <?php endif; ?>
                                    <a href="chef_kot_print.php?kot_id=<?php echo (int)$kot['kot_id']; ?>" target="_blank" class="ops-btn ops-btn-ghost">🖨️ Print KOT</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <div class="ops-empty-state" id="chefHistoryFilterEmpty" hidden><div>🔎</div><h3>No tickets match this filter</h3><p>Try another status tab.</p></div>
                <?php else: ?>
                    <div class="ops-empty-state"><div>🎫</div><h3>No kitchen ticket history</h3><p>Tickets will appear here after customers place orders.</p></div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
<script>
document.querySelectorAll('[data-filter-group="chef-history"] .ops-filter-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('[data-filter-group="chef-history"] .ops-filter-btn').forEach(function(item) { item.classList.remove('active'); });
        btn.classList.add('active');
        var filter = btn.getAttribute('data-filter');
        var visible = 0;
        document.querySelectorAll('#chefHistoryGrid .chef-kot-card').forEach(function(card) {
            var show = filter === 'all' || card.getAttribute('data-filter-status') === filter;
            card.hidden = !show;
            if (show) visible++;
        });
        var empty = document.getElementById('chefHistoryFilterEmpty');
        if (empty) empty.hidden = visible !== 0;
    });
});
</script>
</body>
</html>
