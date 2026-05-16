<?php
// my_cart.php — Herald Canteen (AJAX cart update — no page reload on qty/remove)
require_once "../includes/auth.php";
start_session();
session_security_check();

if (file_exists(__DIR__ . '/../config/db.php')) {
    require_once __DIR__ . '/../config/db.php';
} else {
    die('Database config not found.');
}
require_once __DIR__ . '/../includes/order_helpers.php';

if (empty($_SESSION['user_id'])) {
    header('Location: portal-login.php');
    exit;
}
// RBAC: Block chef and staff from the customer cart
if (isset($_SESSION['role']) && $_SESSION['role'] !== 'customer') {
    if ($_SESSION['role'] === 'chef') {
        header('Location: chef-control.php');
        exit;
    }
    header('Location: staff-control.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

define('FREE_DELIVERY_THRESHOLD', 500);
define('DELIVERY_FEE', 30);

// ── Detect AJAX (XMLHttpRequest header sent by our JS fetch calls) ────────────
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ── GET: Reorder fallback ─────────────────────────────────────────────────────
// Reorder is a state-changing action, so the real handler is POST + CSRF below.
// This fallback prevents old cached GET reorder links from silently changing cart.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['reorder'])) {
    $_SESSION['flash_error'] = 'Please use the Reorder button again from Order History.';
    session_write_close();
    header('Location: order_history.php');
    exit;
}

// ── POST handler (AJAX + normal form fallback) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
            exit;
        }
        $_SESSION['flash_error'] = 'Invalid request. Please try again.';
        session_write_close();
        header('Location: my_cart.php');
        exit;
    }

    $action  = $_POST['action'] ?? '';

    if ($action === 'reorder') {
        $order_id = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $result = hc_reorder_to_cart($conn, $user_id, (int)$order_id);

        if (!empty($result['ok'])) {
            $_SESSION['flash_success'] = $result['message'];
            if (!empty($result['warning'])) {
                $_SESSION['flash_error'] = $result['warning'];
            }
            session_write_close();
            header('Location: my_cart.php');
            exit;
        }

        $_SESSION['flash_error'] = $result['message'] ?? 'Unable to reorder right now. Please try again.';
        session_write_close();
        header('Location: order_history.php');
        exit;
    }

    $cart_id = (int) ($_POST['cart_id'] ?? 0);

    if ($action === 'increase' && $cart_id) {
        $stmt = $conn->prepare("SELECT c.quantity, m.price FROM cart c JOIN menu_items m ON c.item_id = m.item_id WHERE c.cart_id = ? AND c.user_id = ?");
        $stmt->bind_param("ii", $cart_id, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row && $row['quantity'] < 20) {
            $nq = $row['quantity'] + 1;
            $nt = $nq * $row['price'];
            $u  = $conn->prepare("UPDATE cart SET quantity = ?, total_price = ? WHERE cart_id = ? AND user_id = ?");
            $u->bind_param("idii", $nq, $nt, $cart_id, $user_id);
            $u->execute();
        }

    } elseif ($action === 'decrease' && $cart_id) {
        $stmt = $conn->prepare("SELECT c.quantity, m.price FROM cart c JOIN menu_items m ON c.item_id = m.item_id WHERE c.cart_id = ? AND c.user_id = ?");
        $stmt->bind_param("ii", $cart_id, $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            if ((int)$row['quantity'] <= 1) {
                $d = $conn->prepare("DELETE FROM cart WHERE cart_id = ? AND user_id = ?");
                $d->bind_param("ii", $cart_id, $user_id);
                $d->execute();
            } else {
                $nq = (int)$row['quantity'] - 1;
                $nt = $nq * $row['price'];
                $u  = $conn->prepare("UPDATE cart SET quantity = ?, total_price = ? WHERE cart_id = ? AND user_id = ?");
                $u->bind_param("idii", $nq, $nt, $cart_id, $user_id);
                $u->execute();
            }
        }

    } elseif ($action === 'remove' && $cart_id) {
        $d = $conn->prepare("DELETE FROM cart WHERE cart_id = ? AND user_id = ?");
        $d->bind_param("ii", $cart_id, $user_id);
        $d->execute();

    } elseif ($action === 'clear_all') {
        $d = $conn->prepare("DELETE FROM cart WHERE user_id = ?");
        $d->bind_param("i", $user_id);
        $d->execute();

    } elseif ($action === 'checkout') {
        header('Location: payment.php');
        exit;
    }

    // ── AJAX: return updated cart state as JSON ───────────────────────────────
    if ($is_ajax) {
        $stmt = $conn->prepare("SELECT c.cart_id, c.quantity, m.price FROM cart c JOIN menu_items m ON c.item_id = m.item_id WHERE c.user_id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $items_data = [];
        $subtotal   = 0;
        foreach ($rows as $r) {
            $line = $r['quantity'] * $r['price'];
            $subtotal += $line;
            $items_data[$r['cart_id']] = ['quantity' => $r['quantity'], 'line_total' => $line];
        }
        $delivery    = ($subtotal > 0 && $subtotal < FREE_DELIVERY_THRESHOLD) ? DELIVERY_FEE : 0;
        $grand_total = $subtotal + $delivery;

        header('Content-Type: application/json');
        echo json_encode([
            'ok'          => true,
            'clear_all'   => ($action === 'clear_all'),
            'removed_id'  => ($action === 'remove') ? $cart_id : null,
            'items'       => $items_data,
            'subtotal'    => $subtotal,
            'delivery'    => $delivery,
            'grand_total' => $grand_total,
            'cart_count'  => count($rows),
        ]);
        exit;
    }

    // Normal form POST fallback (clear_all or non-JS)
    header('Location: my_cart.php');
    exit;
}

// ── Render page ───────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT c.cart_id, c.quantity, c.total_price as cart_total_price,
           m.item_id, m.name AS item_name, m.description, m.price, m.is_available
    FROM cart c
    JOIN menu_items m ON c.item_id = m.item_id
    WHERE c.user_id = ?
    ORDER BY c.added_at DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$cart_items = [];
while ($row = $result->fetch_assoc()) {
    $row['total_price'] = $row['quantity'] * $row['price'];
    $cart_items[] = $row;
}

$subtotal    = array_sum(array_column($cart_items, 'total_price'));
$delivery    = ($subtotal > 0 && $subtotal < FREE_DELIVERY_THRESHOLD) ? DELIVERY_FEE : 0;
$grand_total = $subtotal + $delivery;

$flash_error   = $_SESSION['flash_error']   ?? '';
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_error'], $_SESSION['flash_success']);

function get_food_emoji(string $name): string {
    $map = ['momo'=>'🥟','burger'=>'🍔','pizza'=>'🍕','rice'=>'🍚',
            'noodle'=>'🍜','chicken'=>'🍗','tea'=>'☕','coffee'=>'☕',
            'cake'=>'🎂','soup'=>'🍲','pasta'=>'🍝','sandwich'=>'🥪',
            'roll'=>'🌯','drink'=>'🥤','dessert'=>'🍰'];
    $lower = strtolower($name);
    foreach ($map as $k => $e) { if (str_contains($lower, $k)) return $e; }
    return '🍱';
}

$full_name  = $_SESSION['full_name'] ?? 'User';
$name_parts = explode(' ', $full_name);
$initials   = strtoupper($name_parts[0][0] . ($name_parts[1][0] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Cart — Herald Canteen</title>
<script src="../assets/js/theme.js"></script>
<link rel="stylesheet" href="../assets/css/style.css">
<style>
.cart-row-removing { opacity: 0; transition: opacity .25s ease; }
.qty-btn-loading   { opacity: .45; pointer-events: none; }
</style>
</head>
<body>

<nav class="navbar">
    <div class="navbar-title">
        Herald Canteen
        <span>Herald College Kathmandu</span>
    </div>

    <ul class="navbar-nav">
        <li><a href="dashboard.php">Home</a></li>
        <li><a class="active" href="my_cart.php">Cart</a></li>
        <li><a href="my_orders.php">Orders</a></li>
    </ul>

    <div class="navbar-user">
        <div class="navbar-avatar"><?= htmlspecialchars($initials) ?></div>
        <span class="navbar-username"><?= htmlspecialchars($full_name) ?></span>

        <?php
        $notif_cart_count = 0;
        $nc_stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $nc_stmt->bind_param("i", $user_id);
        $nc_stmt->execute();
        $notif_cart_count = (int)$nc_stmt->get_result()->fetch_row()[0];
        $nc_stmt->close();
        ?>
        <a href="notifications.php" class="notif-wrap" title="Notifications" style="position:relative;display:inline-flex;align-items:center;font-size:20px;text-decoration:none;margin-right:6px;">
            🔔
            <?php if ($notif_cart_count > 0): ?>
            <span class="notif-badge"><?= $notif_cart_count ?></span>
            <?php endif; ?>
        </a>

        <form method="POST" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <button class="btn-logout">Logout</button>
        </form>
    </div>

    <!-- Theme Toggle -->
    <label class="theme-toggle" title="Toggle light/dark mode">
      <input type="checkbox" class="theme-checkbox">
      <span class="theme-slider"></span>
    </label>
</nav>

<div class="page-wrapper">

    <div class="page-heading">
        <h1>My Cart 🛒</h1>
        <p>Review items before checkout</p>
    </div>

    <?php if ($flash_error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($flash_error) ?></div>
    <?php endif; ?>
    <?php if ($flash_success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($flash_success) ?></div>
    <?php endif; ?>

    <?php if (empty($cart_items)): ?>

        <div class="card">
            <div class="empty-state">
                <div class="empty-icon">🛒</div>
                <h3>Your cart is empty</h3>
                <p>Add items from menu</p>
                <a href="dashboard.php" class="btn btn-primary">Browse Menu</a>
            </div>
        </div>

    <?php else: ?>

        <div class="card">
            <table class="cart-table" id="cart-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Price</th>
                        <th>Qty</th>
                        <th>Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="cart-body">
                <?php foreach ($cart_items as $item): ?>
                    <tr id="cart-row-<?= $item['cart_id'] ?>"
                        data-cart-id="<?= $item['cart_id'] ?>"
                        data-price="<?= (float)$item['price'] ?>">
                        <td>
                            <div class="item-cell">
                                <span class="item-emoji"><?= get_food_emoji($item['item_name']) ?></span>
                                <div>
                                    <div class="item-name"><?= htmlspecialchars($item['item_name']) ?></div>
                                    <div class="item-cuisine"><?= htmlspecialchars($item['description']) ?></div>
                                </div>
                            </div>
                        </td>

                        <td>Rs <?= number_format($item['price']) ?></td>

                        <td>
                            <div class="qty-stepper">
                                <button type="button" class="qty-btn"
                                    onclick="cartAction('decrease', <?= $item['cart_id'] ?>, this)">−</button>
                                <span id="qty-<?= $item['cart_id'] ?>"><?= $item['quantity'] ?></span>
                                <button type="button" class="qty-btn"
                                    onclick="cartAction('increase', <?= $item['cart_id'] ?>, this)">+</button>
                            </div>
                        </td>

                        <td class="item-price-strong" id="line-<?= $item['cart_id'] ?>">
                            Rs <?= number_format($item['total_price']) ?>
                        </td>

                        <td>
                            <button type="button" class="btn-danger"
                                onclick="cartAction('remove', <?= $item['cart_id'] ?>, this)">
                                🗑 Remove
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="summary-strip" id="summary-strip">
            <div class="summary-left">
                <div class="summary-line">
                    <span>Subtotal</span>
                    <span id="summary-subtotal">Rs <?= number_format($subtotal) ?></span>
                </div>
                <div class="summary-line">
                    <span>Delivery</span>
                    <span id="summary-delivery"><?= $delivery ? "Rs $delivery" : "FREE" ?></span>
                </div>
            </div>

            <div class="summary-right">
                <div class="total-label">Total</div>
                <div class="total-amount" id="summary-grand">Rs <?= number_format($grand_total) ?></div>
                <a href="payment.php" class="btn btn-primary"
                   style="display:block;text-align:center;margin-top:14px;">
                    Proceed to Checkout
                </a>
            </div>
        </div>

        <div class="clear-cart-wrap">
            <form method="POST" id="clear-form">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="clear_all">
                <button type="button" class="btn-danger btn-clear-cart"
                    onclick="clearAll()">Clear Cart</button>
            </form>
        </div>

    <?php endif; ?>

</div><!-- /.page-wrapper -->

<script>
const CSRF_TOKEN          = <?= json_encode($_SESSION['csrf_token']) ?>;
const FREE_THRESHOLD      = <?= FREE_DELIVERY_THRESHOLD ?>;
const DELIVERY_FEE_AMOUNT = <?= DELIVERY_FEE ?>;

function cartAction(action, cartId, triggerEl) {
    if (triggerEl) triggerEl.classList.add('qty-btn-loading');

    fetch('my_cart.php', {
        method:  'POST',
        headers: {
            'Content-Type':     'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: new URLSearchParams({
            csrf_token: CSRF_TOKEN,
            action:     action,
            cart_id:    cartId,
        }).toString(),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) { location.reload(); return; }

        if (data.clear_all) { location.reload(); return; }

        // If this row was removed (remove action, or decrease-to-zero)
        const stillExists = data.items && data.items[cartId];
        if (!stillExists) {
            const row = document.getElementById('cart-row-' + cartId);
            if (row) {
                row.classList.add('cart-row-removing');
                setTimeout(() => { row.remove(); updateSummary(data); }, 260);
            }
        } else {
            // Update qty and line total
            const qtyEl  = document.getElementById('qty-'  + cartId);
            const lineEl = document.getElementById('line-' + cartId);
            const item   = data.items[cartId];
            if (qtyEl)  qtyEl.textContent  = item.quantity;
            if (lineEl) lineEl.textContent = 'Rs\u00a0' + fmt(item.line_total);
            updateSummary(data);
        }

        // Empty cart → reload to show empty state
        if (data.cart_count === 0) setTimeout(() => location.reload(), 300);
    })
    .catch(() => location.reload())
    .finally(() => {
        if (triggerEl) triggerEl.classList.remove('qty-btn-loading');
    });
}

function updateSummary(data) {
    const subEl   = document.getElementById('summary-subtotal');
    const delEl   = document.getElementById('summary-delivery');
    const grandEl = document.getElementById('summary-grand');
    if (subEl)   subEl.textContent   = 'Rs\u00a0' + fmt(data.subtotal);
    if (delEl)   delEl.textContent   = data.delivery > 0 ? 'Rs\u00a0' + data.delivery : 'FREE';
    if (grandEl) grandEl.textContent = 'Rs\u00a0' + fmt(data.grand_total);
}

function clearAll() {
    if (!confirm('Clear entire cart?')) return;
    document.getElementById('clear-form').submit();
}

function fmt(n) {
    return Math.round(n).toLocaleString('en-IN');
}
</script>
<script src="../assets/js/notif_poller.js"></script>
</body>
</html>
