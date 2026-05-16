<?php
require_once '../includes/auth.php';
start_session();
session_security_check();
require_once '../config/db.php';
require_once '../includes/payment_helpers.php';


// Must be logged in as customer
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

$user_id   = (int) $_SESSION['user_id'];
$full_name = htmlspecialchars($_SESSION['full_name'] ?? 'Customer', ENT_QUOTES, 'UTF-8');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Check cart is not empty ──────────────────────────────────────────────────
try {
    $cart_items = hc_fetch_cart_items($conn, $user_id);
} catch (Throwable $e) {
    error_log('Checkout cart load failed: ' . $e->getMessage());
    header('Location: my_cart.php?error=cart_load_failed');
    exit;
}

if (empty($cart_items)) {
    unset($_SESSION['pending_payment']);
    header('Location: my_cart.php?error=empty_cart');
    exit;
}

// ── Fetch active delivery locations grouped by block ───────────────────────
$dl_stmt = $conn->prepare("
    SELECT location_id, location_name, block_name
    FROM delivery_locations
    WHERE is_active = 1
    ORDER BY block_name ASC, sort_order ASC, location_name ASC
");
$dl_stmt->execute();
$dl_result = $dl_stmt->get_result();
$delivery_locations_by_block = [];
while ($dlrow = $dl_result->fetch_assoc()) {
    $delivery_locations_by_block[$dlrow['block_name']][] = $dlrow;
}
$dl_stmt->close();

// ── Calculate totals ──────────────────────────────────────────────────────────
$subtotal = hc_cart_subtotal($cart_items);

// ── Handle session-only AJAX update (delivery mode / notes/location change) ───
// eSewa signs the current total, so the browser asks this page for a fresh
// session snapshot + signature before redirecting to eSewa.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update'])) {
    $allowed_modes = ['delivery', 'takeaway'];
    $dm = in_array($_POST['delivery_mode'] ?? '', $allowed_modes, true)
        ? $_POST['delivery_mode']
        : ($_SESSION['pending_payment']['delivery_mode'] ?? 'delivery');

    $raw_notes = $_POST['special_notes'] ?? '';
    $notes = (trim($raw_notes) !== '') ? substr(strip_tags(trim($raw_notes)), 0, 500) : null;

    $dl_id = isset($_POST['delivery_location_id']) ? (int)$_POST['delivery_location_id'] : null;
    if ($dl_id !== null && $dl_id <= 0) {
        $dl_id = null;
    }

    $totals = hc_calculate_totals($cart_items, $dm);
    $gt_string = hc_money_string($totals['total']);

    // Keep the same UUID during one displayed checkout attempt, but make it
    // eSewa-safe if the session was created by an older version of the app.
    $transaction_uuid = $_SESSION['pending_payment']['transaction_uuid'] ?? hc_generate_payment_uuid();
    if (!preg_match('/^[A-Za-z0-9-]+$/', $transaction_uuid)) {
        $transaction_uuid = hc_generate_payment_uuid();
    }

    $_SESSION['pending_payment'] = [
        'transaction_uuid'     => $transaction_uuid,
        'subtotal'             => $totals['subtotal'],
        'delivery_fee'         => $totals['delivery_fee'],
        'total'                => $totals['total'],
        'cart_items'           => $cart_items,
        'delivery_mode'        => $dm,
        'special_notes'        => $notes,
        'delivery_location_id' => $dl_id,
        'checkout_token'       => $_SESSION['pending_payment']['checkout_token'] ?? hc_generate_checkout_token(),
    ];

    $esewa_merchant_code = "EPAYTEST";
    $esewa_secret_key    = "8gBm/:&EnhH.1/q";
    $sign_string  = "total_amount={$gt_string},transaction_uuid={$transaction_uuid},product_code={$esewa_merchant_code}";
    $new_signature = base64_encode(hash_hmac('sha256', $sign_string, $esewa_secret_key, true));

    session_write_close();
    header('Content-Type: application/json');
    echo json_encode([
        'ok'               => true,
        'grand_total'      => $gt_string,
        'grand_total_num'  => $totals['total'],
        'delivery_fee'     => hc_money_string($totals['delivery_fee']),
        'delivery_fee_num' => $totals['delivery_fee'],
        'transaction_uuid' => $transaction_uuid,
        'signature'        => $new_signature,
    ]);
    exit;
}

// ── Delivery mode — read from POST (form submit) or session (page reload) ─────
$allowed_modes = ['delivery', 'takeaway'];
$delivery_mode = in_array($_POST['delivery_mode'] ?? '', $allowed_modes, true)
                   ? $_POST['delivery_mode']
                   : ($_SESSION['pending_payment']['delivery_mode'] ?? 'delivery');

// Special notes — read from POST or session
$raw_notes   = $_POST['special_notes'] ?? $_SESSION['pending_payment']['special_notes'] ?? '';
$special_notes = ($raw_notes !== null && trim($raw_notes) !== '')
                   ? substr(strip_tags(trim($raw_notes)), 0, 500) : null;

// Delivery location — read from POST or session
$selected_location_id = (int)($_POST['delivery_location_id'] ?? $_SESSION['pending_payment']['delivery_location_id'] ?? 0);
if ($selected_location_id <= 0) $selected_location_id = null;

// Delivery fee: only for 'delivery' mode, waived above threshold
$totals = hc_calculate_totals($cart_items, $delivery_mode);
$subtotal     = $totals['subtotal'];
$delivery_fee = $totals['delivery_fee'];
$grand_total  = $totals['total'];
$grand_total_string = hc_money_string($grand_total);

// ── Gateway configs ───────────────────────────────────────────────────────────
$esewa_merchant_code = "EPAYTEST";
$esewa_url           = "https://rc-epay.esewa.com.np/api/epay/main/v2/form";
$esewa_secret_key    = "8gBm/:&EnhH.1/q";

// ── transaction_uuid for eSewa ───────────────────────────────────────────────
// eSewa requires alphanumeric + hyphen only, and the value must be unique per request.
// A failed/cancelled eSewa return gets a fresh UUID so retrying does not reuse one.
$reuse_uuid = $_SESSION['pending_payment']['transaction_uuid'] ?? null;
$must_refresh_uuid = isset($_GET['status']) && $_GET['status'] === 'failed';
if ($must_refresh_uuid || empty($reuse_uuid) || !preg_match('/^[A-Za-z0-9-]+$/', $reuse_uuid)) {
    $transaction_uuid = hc_generate_payment_uuid();
} else {
    $transaction_uuid = $reuse_uuid;
}

// ── Save to session BEFORE any gateway redirect ───────────────────────────────
$checkout_token = $_SESSION['pending_payment']['checkout_token'] ?? hc_generate_checkout_token();
$_SESSION['pending_payment'] = [
    'transaction_uuid'     => $transaction_uuid,
    'subtotal'             => $subtotal,
    'delivery_fee'         => $delivery_fee,
    'total'                => $grand_total,
    'cart_items'           => $cart_items,
    'delivery_mode'        => $delivery_mode,
    'special_notes'        => $special_notes,
    'delivery_location_id' => $selected_location_id,
    'checkout_token'       => $checkout_token,
];

// ── eSewa HMAC signature ──────────────────────────────────────────────────────
$esewa_signed_field_names = "total_amount,transaction_uuid,product_code";
$esewa_sign_string = "total_amount={$grand_total_string},transaction_uuid={$transaction_uuid},product_code={$esewa_merchant_code}";
$esewa_signature   = base64_encode(hash_hmac('sha256', $esewa_sign_string, $esewa_secret_key, true));

$success_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
             . "://" . $_SERVER['HTTP_HOST']
             . dirname($_SERVER['PHP_SELF']) . "/esewa_verify.php";
$failure_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
             . "://" . $_SERVER['HTTP_HOST']
             . dirname($_SERVER['PHP_SELF']) . "/payment.php?status=failed";

$csrf = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <script src="../assets/js/theme.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout — Herald Canteen</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", sans-serif; background: #181818; color: #ffffff; }
        a { text-decoration: none; }
        .layout { display: flex; min-height: 100vh; }
        .sidebar { width: 220px; background: #2e2e2e; padding: 30px 20px; display: flex; flex-direction: column; gap: 30px; position: fixed; top: 0; left: 0; height: 100vh; z-index: 10; border-right: 1px solid rgba(77,184,72,0.1); }
        .sidebar h3 { font-size: 22px; color: #4db848; }
        .sidebar nav { display: flex; flex-direction: column; gap: 6px; }
        .sidebar nav a { color: rgba(255,255,255,0.45); font-size: 14px; padding: 10px 14px; border-radius: 8px; transition: all 0.2s; display: flex; align-items: center; justify-content: space-between; }
        .sidebar nav a:hover, .sidebar nav a.active { background: rgba(77,184,72,0.1); color: #4db848; border: 1px solid rgba(77,184,72,0.15); }
        .main { margin-left: 220px; flex: 1; display: flex; flex-direction: column; }
        .topbar { padding: 28px 30px; background: rgba(24,24,24,0.85); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(77,184,72,0.1); position: sticky; top: 0; z-index: 5; display: flex; align-items: center; justify-content: space-between; }
        .topbar-left h2 { font-size: 28px; font-weight: 700; }
        .topbar-left p { font-size: 15px; font-weight: 500; color: #4db848; margin-top: 4px; }
        .topbar-amount { text-align: right; }
        .topbar-amount .label { font-size: 12px; color: rgba(255,255,255,0.4); text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
        .topbar-amount .amount { font-size: 26px; font-weight: 700; color: #4db848; margin-top: 2px; }
        .content { padding: 30px; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .alert.error { background: rgba(229,57,53,0.1); border: 1px solid rgba(229,57,53,0.3); color: #ef9a9a; }
        .checkout-grid { display: grid; grid-template-columns: 1fr 400px; gap: 20px; align-items: start; }
        .card { background: #2a2a2a; border: 1px solid rgba(255,255,255,0.08); border-radius: 16px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.3); }
        .card-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: rgba(77,184,72,0.7); margin-bottom: 16px; }
        .order-item { display: flex; justify-content: space-between; align-items: center; padding: 13px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .order-item:last-child { border-bottom: none; }
        .order-item-name { font-size: 14px; font-weight: 600; color: #ffffff; margin-bottom: 3px; }
        .order-item-qty { font-size: 12px; color: rgba(255,255,255,0.4); }
        .order-item-price { font-size: 14px; font-weight: 700; color: #4db848; }
        .summary-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 14px; color: rgba(255,255,255,0.65); border-bottom: 1px solid rgba(255,255,255,0.04); }
        .summary-row.grand { border-top: 1px solid rgba(77,184,72,0.25); border-bottom: none; padding-top: 14px; margin-top: 6px; font-size: 16px; font-weight: 700; color: #4db848; }
        .method-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 20px; }
        .method-card { position: relative; }
        .method-card input[type="radio"] { position: absolute; opacity: 0; pointer-events: none; }
        .method-label { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 16px 10px; border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; background: #212121; cursor: pointer; transition: all 0.2s; }
        .method-card input:checked + .method-label { border-color: rgba(77,184,72,0.5); background: rgba(77,184,72,0.08); box-shadow: 0 0 0 1px rgba(77,184,72,0.2); }
        .method-label:hover { border-color: rgba(77,184,72,0.25); background: rgba(77,184,72,0.05); }
        .method-icon { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.3rem; }
        .icon-cod { background: rgba(77,184,72,0.12); }
        .icon-esewa { background: rgba(96,187,70,0.12); }
        .method-name { font-size: 12px; font-weight: 700; color: #ffffff; text-align: center; }
        .method-sub { font-size: 11px; color: rgba(255,255,255,0.4); text-align: center; }
        #pay-btn { width: 100%; padding: 12px 20px; background: #4db848; color: #ffffff; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; transition: background 0.2s; box-shadow: 0 4px 12px rgba(77,184,72,0.25); }
        #pay-btn:hover { background: #3a9236; }
        #pay-btn:disabled { background: #3a3a3a; color: rgba(255,255,255,0.3); cursor: not-allowed; box-shadow: none; }
        #esewa-form, #cod-form { display: none; }
        textarea { width: 100%; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 10px; color: #fff; font-size: 13px; resize: vertical; font-family: inherit; }
        textarea::placeholder { color: rgba(255,255,255,0.3); }
        label.field-label { font-size: 13px; color: rgba(255,255,255,0.6); display: block; margin-bottom: 6px; }
        .delivery-note { font-size: 12px; color: rgba(255,255,255,0.4); margin-top: 8px; }
        .location-select { width: 100%; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; padding: 10px 12px; color: #fff; font-size: 13px; font-family: inherit; cursor: pointer; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%234db848' stroke-width='2' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; }
        .location-select:focus { outline: none; border-color: rgba(77,184,72,0.5); box-shadow: 0 0 0 2px rgba(77,184,72,0.1); }
        .location-select option, .location-select optgroup { background: #2a2a2a; color: #fff; }
        .location-select-error { border-color: rgba(229,57,53,0.5) !important; }
        @media (max-width: 900px) { .checkout-grid { grid-template-columns: 1fr; } .sidebar { display: none; } .main { margin-left: 0; } }
    </style>
</head>
<body>
<div class="layout">
    <div class="sidebar">
        <h3>Herald Canteen</h3>
        <nav>
            <a href="dashboard.php">Home</a>
            <a href="my_cart.php">My Cart</a>
            <a href="my_orders.php">My Orders</a>
            <a href="logout.php">Logout</a>
        </nav>
    </div>
    <div class="main">
        <div class="topbar">
            <div class="topbar-left">
                <h2>Checkout</h2>
                <p>👋 Hello, <?= $full_name ?> — review your order below</p>
            </div>
            <div class="topbar-amount">
                <div class="label">Order Total</div>
                <div class="amount" id="topbar-total">Rs. <?= number_format($grand_total, 2) ?></div>
            </div>
            <label class="theme-toggle" title="Toggle light/dark mode">
                <input type="checkbox" class="theme-checkbox">
                <span class="theme-slider"></span>
            </label>
        </div>
        <div class="content">
            <?php if (isset($_GET['status']) && $_GET['status'] === 'failed'): ?>
            <div class="alert error">⚠️ Payment failed or was cancelled. Please try again.<?php if (!empty($_GET['err'])): ?> <span style="opacity:.75;">Reason: <?= htmlspecialchars($_GET['err'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></div>
            <?php endif; ?>

            <div class="checkout-grid">
                <!-- Left: Order Summary -->
                <div class="card">
                    <div class="card-title">Order Summary</div>
                    <?php foreach ($cart_items as $item): ?>
                    <div class="order-item">
                        <div>
                            <div class="order-item-name"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="order-item-qty">Qty: <?= (int)$item['quantity'] ?></div>
                        </div>
                        <div class="order-item-price">
                            Rs. <?= number_format($item['price'] * $item['quantity'], 2) ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div style="margin-top:16px;">
                        <div class="summary-row">
                            <span>Subtotal</span>
                            <span>Rs. <?= number_format($subtotal, 2) ?></span>
                        </div>
                        <div class="summary-row" id="delivery-fee-row">
                            <span>Delivery Fee</span>
                            <span id="delivery-fee-display"><?= $delivery_fee > 0 ? 'Rs. '.$delivery_fee : 'FREE' ?></span>
                        </div>
                        <div class="summary-row grand">
                            <span>Total</span>
                            <span id="grand-total-display">Rs. <?= number_format($grand_total, 2) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Right: Options & Payment -->
                <div style="display:flex;flex-direction:column;gap:20px;">
                    <div class="card">
                        <div class="card-title">Delivery Mode</div>
                        <div class="method-grid" style="margin-bottom:16px;">
                            <label class="method-card">
                                <input type="radio" name="ui_delivery_mode" value="delivery"
                                    <?= $delivery_mode === 'delivery' ? 'checked' : '' ?>
                                    onchange="updateDeliveryMode(this.value)">
                                <div class="method-label">
                                    <div class="method-icon">🚚</div>
                                    <div class="method-name">Delivery</div>
                                    <div class="method-sub">Delivered to you</div>
                                </div>
                            </label>
                            <label class="method-card">
                                <input type="radio" name="ui_delivery_mode" value="takeaway"
                                    <?= $delivery_mode === 'takeaway' ? 'checked' : '' ?>
                                    onchange="updateDeliveryMode(this.value)">
                                <div class="method-label">
                                    <div class="method-icon">🥡</div>
                                    <div class="method-name">Takeaway</div>
                                    <div class="method-sub">Pick up at counter</div>
                                </div>
                            </label>
                        </div>
                        <p class="delivery-note" id="delivery-note">
                            <?= $delivery_mode === 'delivery'
                                ? ($delivery_fee > 0 ? "Delivery fee: Rs. $delivery_fee (free above Rs. ".FREE_DELIVERY_THRESHOLD.")" : "Free delivery (order above Rs. ".FREE_DELIVERY_THRESHOLD.")")
                                : "No delivery fee for takeaway." ?>
                        </p>

                        <!-- Delivery Location (shown only for Delivery mode) -->
                        <div class="location-select-wrap" id="delivery-location-wrap" style="margin-top:16px;<?= $delivery_mode !== 'delivery' ? 'display:none;' : '' ?>">
                            <label class="field-label" for="delivery_location_select">
                                📍 Delivery Location <span style="color:#ef9a9a;">*</span>
                            </label>
                            <select id="delivery_location_select" name="delivery_location_id"
                                    class="location-select"
                                    onchange="syncLocationHiddenFields(this.value)">
                                <option value="">— Select delivery location —</option>
                                <?php foreach ($delivery_locations_by_block as $block => $locs): ?>
                                <optgroup label="<?= htmlspecialchars($block, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php foreach ($locs as $loc): ?>
                                    <option value="<?= (int)$loc['location_id'] ?>"
                                        <?= $selected_location_id === (int)$loc['location_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($loc['location_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <div class="delivery-note" style="margin-top:6px;">Choose the department/office where the order should be delivered.</div>
                        </div>

                        <div style="margin-top:18px;">
                            <label class="field-label">Customer Remark (optional)</label>
                            <textarea id="special-notes-input" maxlength="500" rows="2"
                                placeholder="E.g. Less spicy, no onion, extra sauce…"><?= htmlspecialchars($special_notes ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                            <div style="font-size:11px;color:rgba(255,255,255,0.3);margin-top:4px;">Max 500 characters. Visible on your KOT and invoice.</div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-title">Payment Method</div>
                        <div class="method-grid">
                            <label class="method-card">
                                <input type="radio" name="payment_method" value="cod" checked>
                                <div class="method-label">
                                    <div class="method-icon icon-cod">💵</div>
                                    <div class="method-name">Cash on Delivery</div>
                                    <div class="method-sub">Pay when received</div>
                                </div>
                            </label>
                            <label class="method-card">
                                <input type="radio" name="payment_method" value="esewa">
                                <div class="method-label">
                                    <div class="method-icon icon-esewa">
                                        <img src="https://esewa.com.np/common/images/esewa_logo.png"
                                             alt="eSewa" style="width:30px;height:30px;object-fit:contain;">
                                    </div>
                                    <div class="method-name">eSewa</div>
                                    <div class="method-sub">Digital wallet</div>
                                </div>
                            </label>
                        </div>

                        <button id="pay-btn" onclick="handlePayment()">
                            Pay Rs. <span id="btn-amount"><?= number_format($grand_total, 2) ?></span>
                        </button>
                    </div>
                </div>
            </div><!-- /checkout-grid -->

            <!-- eSewa hidden form -->
            <form id="esewa-form" action="<?= htmlspecialchars($esewa_url, ENT_QUOTES, 'UTF-8') ?>" method="POST">
                <input type="hidden" name="amount"                  id="esewa-amount"      value="<?= htmlspecialchars($grand_total_string, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="tax_amount"                                     value="0">
                <input type="hidden" name="total_amount"            id="esewa-total-amount" value="<?= htmlspecialchars($grand_total_string, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="transaction_uuid"       id="esewa-transaction-uuid" value="<?= htmlspecialchars($transaction_uuid, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="product_code"                                   value="<?= htmlspecialchars($esewa_merchant_code, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="product_service_charge"                         value="0">
                <input type="hidden" name="product_delivery_charge"                        value="0">
                <input type="hidden" name="success_url"                                    value="<?= htmlspecialchars($success_url, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="failure_url"                                    value="<?= htmlspecialchars($failure_url, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="signed_field_names"                             value="<?= htmlspecialchars($esewa_signed_field_names, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="signature"               id="esewa-signature"   value="<?= htmlspecialchars($esewa_signature, ENT_QUOTES, 'UTF-8') ?>">
            </form>

            <!-- COD hidden form -->
            <form id="cod-form" action="cod_confirm.php" method="POST">
                <input type="hidden" name="csrf_token"           value="<?= $csrf ?>">
                <input type="hidden" name="transaction_uuid"     value="<?= htmlspecialchars($transaction_uuid, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="delivery_mode"        id="cod-delivery-mode" value="<?= htmlspecialchars($delivery_mode, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="special_notes"        id="cod-special-notes" value="">
                <input type="hidden" name="delivery_location_id" id="cod-location-id"   value="<?= (int)($selected_location_id ?? 0) ?>">
                <input type="hidden" name="checkout_token"      value="<?= htmlspecialchars($checkout_token, ENT_QUOTES, 'UTF-8') ?>">
            </form>

        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /layout -->

<script>
const SUBTOTAL       = <?= (float)$subtotal ?>;
const DELIVERY_FEE   = <?= (int)DELIVERY_FEE ?>;
const FREE_THRESHOLD = <?= (int)FREE_DELIVERY_THRESHOLD ?>;

// Track whether there is a pending session-sync in flight.
// handlePayment() will wait for it to finish before submitting.
let sessionSyncPromise = null;

function calcFee(mode) {
    return (mode === 'delivery' && SUBTOTAL > 0 && SUBTOTAL < FREE_THRESHOLD) ? DELIVERY_FEE : 0;
}

function syncLocationHiddenFields(val) {
    document.getElementById('cod-location-id').value = val;
}

function syncCheckoutSession(mode, notes, locationId) {
    sessionSyncPromise = fetch('payment.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'ajax_update=1'
            + '&delivery_mode=' + encodeURIComponent(mode)
            + '&special_notes=' + encodeURIComponent(notes || '')
            + '&delivery_location_id=' + encodeURIComponent(locationId || '')
    })
    .then(r => r.json())
    .then(data => {
        if (!data || !data.ok) throw new Error('Unable to sync checkout session');

        document.getElementById('esewa-amount').value = data.grand_total;
        document.getElementById('esewa-total-amount').value = data.grand_total;
        document.getElementById('esewa-transaction-uuid').value = data.transaction_uuid;
        document.getElementById('esewa-signature').value = data.signature;
        sessionSyncPromise = null;
        return data;
    })
    .catch(err => {
        sessionSyncPromise = null;
        throw err;
    });

    return sessionSyncPromise;
}

function updateDeliveryMode(val) {
    // Update COD hidden field immediately (no async needed for COD)
    document.getElementById('cod-delivery-mode').value = val;

    // Show/hide delivery location field
    const locationWrap = document.getElementById('delivery-location-wrap');
    if (val === 'delivery') {
        locationWrap.style.display = '';
    } else {
        locationWrap.style.display = 'none';
        document.getElementById('delivery_location_select').value = '';
        document.getElementById('cod-location-id').value = '';
    }

    const fee     = calcFee(val);
    const grand   = SUBTOTAL + fee;

    // Update all displayed totals
    document.getElementById('delivery-fee-display').textContent = fee > 0 ? 'Rs. ' + fee : 'FREE';
    document.getElementById('grand-total-display').textContent  = 'Rs. ' + grand.toFixed(2);
    document.getElementById('btn-amount').textContent           = grand.toFixed(2);
    document.getElementById('topbar-total').textContent         = 'Rs. ' + grand.toFixed(2);

    const noteEl = document.getElementById('delivery-note');
    if (val === 'delivery') {
        noteEl.textContent = fee > 0
            ? 'Delivery fee: Rs. ' + DELIVERY_FEE + ' (free above Rs. ' + FREE_THRESHOLD + ')'
            : 'Free delivery (order above Rs. ' + FREE_THRESHOLD + ')';
    } else {
        noteEl.textContent = 'No delivery fee for takeaway.';
    }

    const notes = document.getElementById('special-notes-input').value;
    const locId = document.getElementById('delivery_location_select').value;
    syncCheckoutSession(val, notes, locId).catch(() => {
        window.location.reload();
    });
}

function handlePayment() {
    const notes  = document.getElementById('special-notes-input').value;
    const dm     = document.querySelector('input[name="ui_delivery_mode"]:checked').value;
    const method = document.querySelector('input[name="payment_method"]:checked').value;
    const btn    = document.getElementById('pay-btn');
    const locSel = document.getElementById('delivery_location_select');

    // Client-side validation: location required for delivery
    if (dm === 'delivery') {
        if (!locSel.value || locSel.value === '') {
            locSel.classList.add('location-select-error');
            locSel.focus();
            // Show inline error
            let errEl = document.getElementById('location-error-msg');
            if (!errEl) {
                errEl = document.createElement('div');
                errEl.id = 'location-error-msg';
                errEl.style.cssText = 'color:#ef9a9a;font-size:12px;margin-top:5px;';
                errEl.textContent = '⚠️ Please select a delivery location before proceeding.';
                locSel.parentNode.appendChild(errEl);
            }
            return;
        } else {
            locSel.classList.remove('location-select-error');
            const errEl = document.getElementById('location-error-msg');
            if (errEl) errEl.remove();
        }
    }

    // Sync fields into COD form
    document.getElementById('cod-delivery-mode').value = dm;
    document.getElementById('cod-special-notes').value = notes;
    document.getElementById('cod-location-id').value   = locSel.value;

    if (method === 'cod') {
        document.getElementById('cod-form').submit();
        return;
    }

    if (method === 'esewa') {
        btn.disabled    = true;
        btn.textContent = 'Redirecting to eSewa…';

        // Always sync the latest notes/location/mode immediately before eSewa submit.
        // This prevents stale session data after selecting eSewa, going back, then switching method.
        const pending = sessionSyncPromise || Promise.resolve();
        pending
            .then(() => syncCheckoutSession(dm, notes, locSel.value))
            .then(() => {
                document.getElementById('esewa-form').submit();
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = 'Pay Rs. <span id="btn-amount">' + (SUBTOTAL + calcFee(dm)).toFixed(2) + '</span>';
                alert('Unable to prepare eSewa payment. Please try again.');
            });
        return;
    }
}
// Browser back from eSewa can restore an old hidden form from bfcache.
// Reloading gives a fresh eSewa UUID/signature while COD still remains usable.
window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>
<script src="../assets/js/notif_poller.js"></script>
</body>
</html>